<?php
/**
 * Trait DiviOps_Agent_DynamicContent
 *
 * Dynamic-content introspection, a builder, and validation (#36).
 *
 * Divi 5 dynamic content is a `$variable({"type":"content","value":{"name":...,
 * "settings":{...}}})$` payload — Packages/Conversion/Conversion.php:668-676
 * `formatDynamicContent()` in Divi's own server code does exactly:
 *
 *     return '$variable(' . json_encode( [
 *         'type'  => $type,
 *         'value' => [ 'name' => $name, 'settings' => (object) $settings ],
 *     ], JSON_UNESCAPED_UNICODE ) . ')$';
 *
 * dynamic_content_format_token() below reproduces that exact encoding (including
 * the `(object)` cast, so empty settings serialize as `{}` not `[]`).
 *
 * The legacy D4 form is `@ET-DC@<base64>@` (Conversion.php:634-655
 * `convertDynamicContent()` / `DYNAMIC_CONTENT_REGEX = '/@ET-DC@([^@]+)@/'`), whose
 * decoded base64 payload is `{dynamic, content, settings}`. This fork never emits
 * that form, but dynamic_content_validate recognizes it (a real D4-migrated site
 * can still carry it) and reports the modern equivalent.
 *
 * The set of valid `name`s and each option's `settings` schema is NOT parsed from
 * the 55 `DynamicContentOption*` classes that ship with Divi — every one of them
 * registers through the WordPress filter
 * `apply_filters( 'divi_module_dynamic_content_options', [], $post_id, $context )`
 * (DynamicContentOptionBaseInterface::register_option_callback(), confirmed against
 * DynamicContentOptions::get_options() and DynamicContentOptionPostTitle on the
 * reference site), which is the live, site-specific registry — including ACF/SCF
 * fields that actually exist here. dynamic_content_get_registry() calls that filter
 * directly rather than re-deriving it, and is the single source of truth every
 * handler in this trait validates against. It is memoized per (post_id, context)
 * for the request, since module_update()'s write-path guard can look it up once
 * per candidate value scanned.
 *
 * IMPORTANT: `$variable(...)$` is Divi's SHARED variable wrapper, not exclusive
 * to dynamic content. Global colors (`gcid-*`), global variables (`gvid-*` —
 * spacing/sizes/fonts), and gradients use the identical syntax and are
 * deliberately never registered in `divi_module_dynamic_content_options`
 * (`DynamicContentGlobalVariableOptions::register_option_callback()` returns
 * `$options` unchanged) — they resolve via the separate
 * `divi_module_dynamic_content_resolved_value` filter instead. `type` does NOT
 * discriminate: this fork's own canonical `gvid-` design-token form uses
 * `"type":"content"`, identical to a real dynamic-content binding (see
 * skills/divi-5-builder/references/presets.md and module-formats.md). Because of
 * this, module_update()'s write-path guard (dynamic_content_write_path_rejection())
 * is deliberately far more lenient than dynamic_content_validate: it fails OPEN on
 * anything malformed, on anything in the gcid-/gvid-/gfid- namespace or with a
 * non-'content' type, and on an empty/unavailable registry — the ONE thing it
 * rejects is a well-formed token naming an option that is definitively absent
 * from a non-empty registry. See that function's docblock for the full policy.
 *
 * Part of the diviops-agent monolith split (#220) pattern. Mixed into
 * DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and class
 * constants resolve as if these methods lived directly on the class.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DiviOps_Agent_DynamicContent {

	/**
	 * Per-request registry cache, keyed by `"$post_id:$context"` (#36 review
	 * fix 4). A single module_update() write-path scan can hit many candidate
	 * values (e.g. a page carrying 20 design-token attrs), each of which
	 * previously re-ran the DB-backed `divi_module_dynamic_content_options`
	 * filter from scratch. A plain static property is a safe per-REQUEST
	 * cache here: each WP REST call is its own PHP process/request, so this
	 * never leaks registry state across requests.
	 *
	 * @var array<string, array<string, array>>
	 */
	private static $dynamic_content_registry_cache = array();

	/**
	 * Query the live `divi_module_dynamic_content_options` registry for a given
	 * post/context, normalizing the `id` key exactly as Divi's own
	 * DynamicContentOptions::get_options() does: `id` is forced to the array key,
	 * overwriting whatever (if anything) a `register_option_callback()` set. This
	 * is NOT a copy of get_options()'s reentrancy guard/sort — we call
	 * apply_filters() directly and are never invoked reentrantly from within it,
	 * and sort order is a display concern this introspection surface doesn't need.
	 * Memoized per `(post_id, context)` for the lifetime of this request/process.
	 *
	 * @param int    $post_id Post id context passed to the filter (0 when none).
	 * @param string $context 'edit' or 'display'.
	 * @return array<string, array> Option name => registered option entry.
	 */
	private static function dynamic_content_get_registry( int $post_id, string $context ): array {
		$cache_key = $post_id . ':' . $context;
		if ( isset( self::$dynamic_content_registry_cache[ $cache_key ] ) ) {
			return self::$dynamic_content_registry_cache[ $cache_key ];
		}

		$options = apply_filters( 'divi_module_dynamic_content_options', array(), $post_id, $context );
		$options = is_array( $options ) ? $options : array();
		foreach ( $options as $id => $option ) {
			$options[ $id ]['id'] = $id;
		}

		self::$dynamic_content_registry_cache[ $cache_key ] = $options;
		return $options;
	}

	/**
	 * Validate context param ('edit'|'display') and, when post_id > 0, that the
	 * post exists and the caller may inspect it. Shared by list/build/validate so
	 * the three routes gate identically. Returns an error envelope, or null when
	 * the caller may proceed.
	 *
	 * @param int $post_id Post id (0 = no specific post context).
	 * @return WP_REST_Response|null
	 */
	private static function dynamic_content_check_post_access( int $post_id ) {
		if ( 0 === $post_id ) {
			return null;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Post #{$post_id} not found.",
				'Verify the post id via diviops_page_list.',
				404,
				array( 'post_id' => $post_id )
			);
		}
		if ( ! self::can_inspect_post_object( $post ) ) {
			return self::envelope_object_read_forbidden( $post_id );
		}
		return null;
	}

	/**
	 * Build the exact `$variable({...})$` token Divi's own
	 * Conversion::formatDynamicContent() would emit for the same inputs.
	 *
	 * @param string $name     Registered dynamic-content option name.
	 * @param array  $settings Settings map (cast to a JSON object even when empty).
	 * @param string $type     Dynamic-content type, e.g. 'content'.
	 * @return string|null The token, or null when `$settings` cannot be
	 *                      JSON-encoded (#36 review fix 6 — wp_json_encode()
	 *                      returns false on e.g. invalid UTF-8; a bare
	 *                      concatenation would otherwise silently coerce that
	 *                      to the literal string '$variable()$').
	 */
	private static function dynamic_content_format_token( string $name, array $settings, string $type ): ?string {
		$encoded = wp_json_encode(
			array(
				'type'  => $type,
				'value' => array(
					'name'     => $name,
					'settings' => (object) $settings,
				),
			),
			JSON_UNESCAPED_UNICODE
		);
		if ( false === $encoded ) {
			return null;
		}
		return '$variable(' . $encoded . ')$';
	}

	/**
	 * Confirm `$name` exists in the live registry and every `$settings` key is
	 * valid for that option's registered `fields` schema. This is the shared "C"
	 * validator both dynamic_content_build and dynamic_content_validate use, and
	 * that module_update()'s write-path scan uses via
	 * dynamic_content_parse_and_validate_string().
	 *
	 * @param string $name     Dynamic-content option name to look up.
	 * @param array  $settings Settings map to check against the option's fields.
	 * @param int    $post_id  Post id context for the registry lookup.
	 * @param string $context  'edit' or 'display'.
	 * @return array{valid: bool, errors: array<int, array<string, mixed>>}
	 */
	private static function dynamic_content_validate_binding( string $name, array $settings, int $post_id, string $context ): array {
		$registry = self::dynamic_content_get_registry( $post_id, $context );

		if ( ! isset( $registry[ $name ] ) ) {
			return array(
				'valid'  => false,
				'errors' => array(
					array(
						'code'    => 'unknown_option',
						'message' => "'{$name}' is not a registered dynamic content option (post_id: {$post_id}, context: {$context}).",
					),
				),
			);
		}

		$option         = $registry[ $name ];
		$allowed_fields = is_array( $option['fields'] ?? null ) ? array_keys( $option['fields'] ) : array();

		$errors = array();
		foreach ( $settings as $key => $_value ) {
			if ( ! in_array( $key, $allowed_fields, true ) ) {
				$errors[] = array(
					'code'    => 'unknown_setting',
					'message' => "'{$key}' is not a valid setting for dynamic content option '{$name}'.",
					'field'   => (string) $key,
					'allowed' => $allowed_fields,
				);
			}
		}

		return array( 'valid' => empty( $errors ), 'errors' => $errors );
	}

	/**
	 * Parse a legacy `@ET-DC@<base64>@` token (Conversion::DYNAMIC_CONTENT_REGEX /
	 * convertDynamicContent()) and validate the decoded name/settings against the
	 * live registry.
	 *
	 * @param string $value   Raw legacy token string.
	 * @param int    $post_id Post id context for the registry lookup.
	 * @param string $context 'edit' or 'display'.
	 * @return array Validation result; see dynamic_content_parse_and_validate_string().
	 */
	private static function dynamic_content_parse_legacy( string $value, int $post_id, string $context ): array {
		if ( 1 !== preg_match( '/@ET-DC@([^@]+)@/', $value, $matches ) ) {
			return array(
				'valid'         => false,
				'errors'        => array(
					array(
						'code'    => 'malformed_token',
						'message' => 'Value contains @ET-DC@ but is not a well-formed legacy dynamic-content token.',
					),
				),
				'legacy_format' => true,
			);
		}

		$encoded = $matches[1];
		$decoded_raw = base64_decode( $encoded, true );
		if ( false === $decoded_raw || $encoded !== base64_encode( $decoded_raw ) ) {
			return array(
				'valid'         => false,
				'errors'        => array(
					array( 'code' => 'malformed_token', 'message' => 'Legacy @ET-DC@...@ payload is not valid base64.' ),
				),
				'legacy_format' => true,
			);
		}

		$parsed = json_decode( $decoded_raw, true );
		if ( ! is_array( $parsed ) || ! isset( $parsed['dynamic'], $parsed['content'], $parsed['settings'] ) ) {
			return array(
				'valid'         => false,
				'errors'        => array(
					array( 'code' => 'malformed_token', 'message' => 'Legacy @ET-DC@...@ payload does not decode to {dynamic, content, settings}.' ),
				),
				'legacy_format' => true,
			);
		}

		$name     = (string) $parsed['content'];
		$settings = is_array( $parsed['settings'] ) ? $parsed['settings'] : array();

		$result                      = self::dynamic_content_validate_binding( $name, $settings, $post_id, $context );
		$result['name']              = $name;
		$result['settings']          = $settings;
		$result['type']              = 'content';
		$result['legacy_format']     = true;
		$result['modern_equivalent'] = self::dynamic_content_format_token( $name, $settings, 'content' );
		return $result;
	}

	/**
	 * Parse a modern `$variable({...})$` token and validate the decoded
	 * name/settings against the live registry.
	 *
	 * @param string $value   Raw token string.
	 * @param int    $post_id Post id context for the registry lookup.
	 * @param string $context 'edit' or 'display'.
	 * @return array Validation result; see dynamic_content_parse_and_validate_string().
	 */
	private static function dynamic_content_parse_modern( string $value, int $post_id, string $context ): array {
		if ( 1 !== preg_match( '/^\$variable\((.*)\)\$$/s', $value, $matches ) ) {
			return array(
				'valid'         => false,
				'errors'        => array(
					array( 'code' => 'malformed_token', 'message' => 'Value starts with $variable( but is not a well-formed $variable({...})$ token.' ),
				),
				'legacy_format' => false,
			);
		}

		$decoded = json_decode( $matches[1], true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['value']['name'] ) ) {
			return array(
				'valid'         => false,
				'errors'        => array(
					array( 'code' => 'malformed_token', 'message' => 'Could not parse the $variable(...)$ payload as {type, value:{name, settings}}.' ),
				),
				'legacy_format' => false,
			);
		}

		$name     = (string) $decoded['value']['name'];
		$settings = is_array( $decoded['value']['settings'] ?? null ) ? $decoded['value']['settings'] : array();
		$type     = (string) ( $decoded['type'] ?? 'content' );

		$result                  = self::dynamic_content_validate_binding( $name, $settings, $post_id, $context );
		$result['name']          = $name;
		$result['settings']      = $settings;
		$result['type']          = $type;
		$result['legacy_format'] = false;
		return $result;
	}

	/**
	 * Parse a value string that looks like dynamic content (either encoding) and
	 * validate it, or report that it does not look like dynamic content at all.
	 * The modern `$variable(` prefix is checked FIRST (#36 review fix 5): a
	 * legacy token can never itself start with `$variable(`, but a well-formed
	 * MODERN token's JSON `settings` payload could legitimately contain the
	 * literal substring `@ET-DC@` (e.g. a text setting describing D4 tokens) —
	 * checking `@ET-DC@` first would misroute that string to the legacy parser,
	 * which would then fail to base64-decode it and report a spurious
	 * `malformed_token` for a perfectly well-formed modern token.
	 *
	 * @param string $value   Raw attr/token value to inspect.
	 * @param int    $post_id Post id context for the registry lookup.
	 * @param string $context 'edit' or 'display'.
	 * @return array{valid: bool, errors: array, name?: string, settings?: array,
	 *               type?: string, legacy_format?: bool, modern_equivalent?: string}
	 */
	private static function dynamic_content_parse_and_validate_string( string $value, int $post_id, string $context ): array {
		if ( 0 === strpos( $value, '$variable(' ) ) {
			return self::dynamic_content_parse_modern( $value, $post_id, $context );
		}

		if ( false !== strpos( $value, '@ET-DC@' ) ) {
			return self::dynamic_content_parse_legacy( $value, $post_id, $context );
		}

		return array(
			'valid'  => false,
			'errors' => array(
				array(
					'code'    => 'not_dynamic_content',
					'message' => 'Value does not look like a $variable(...)$ or legacy @ET-DC@...@ dynamic content token.',
				),
			),
		);
	}

	/**
	 * Recursively collect every string leaf value in `$attrs` that LOOKS like a
	 * dynamic-content token (`$variable(` prefix or an `@ET-DC@` substring) —
	 * deliberately conservative so an ordinary plain string is never touched.
	 * Walks into nested arrays because a single dot-path in module_update can set
	 * a whole sub-tree at once.
	 *
	 * @param mixed  $value Current value (scalar or array) being scanned.
	 * @param string $path  Dot-path accumulated so far, for error reporting.
	 * @param array  $hits  Accumulator: path => candidate dynamic-content string.
	 */
	private static function dynamic_content_scan_attrs( $value, string $path, array &$hits ): void {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$child_path = '' === $path ? (string) $key : $path . '.' . $key;
				self::dynamic_content_scan_attrs( $child, $child_path, $hits );
			}
			return;
		}
		if ( is_string( $value ) && ( 0 === strpos( $value, '$variable(' ) || false !== strpos( $value, '@ET-DC@' ) ) ) {
			$hits[ $path ] = $value;
		}
	}

	/**
	 * module_update()'s write-path decision for ONE candidate value already
	 * matched by dynamic_content_scan_attrs()'s conservative heuristic (#36
	 * review — Critical fix). This is deliberately a much more lenient policy
	 * than the standalone `/dynamic-content/validate` endpoint's, because
	 * `$variable(...)$` is Divi's SHARED variable wrapper, not exclusive to
	 * dynamic content: global colors (`gcid-*`), global variables (`gvid-*` —
	 * spacing/sizes/fonts), and gradients (`gvid-*`) use the identical syntax
	 * and are deliberately never registered in
	 * `divi_module_dynamic_content_options` —
	 * `DynamicContentGlobalVariableOptions::register_option_callback()`
	 * returns `$options` unchanged, because those resolve via the separate
	 * `divi_module_dynamic_content_resolved_value` filter instead. `type`
	 * does NOT discriminate: this fork's own canonical `gvid-` design-token
	 * form uses `"type":"content"`, identical to a real dynamic-content
	 * binding (skills/divi-5-builder/references/presets.md,
	 * module-formats.md, diviops-server/src/validate-attrs.ts). This is the
	 * plugin's primary documented design-token write path, so rejecting it
	 * here would break real page editing.
	 *
	 * The guard therefore fails OPEN in every case except one: a well-formed
	 * token naming an option that is DEFINITIVELY absent from a non-empty
	 * live registry, and is not itself a global-variable/color/gradient id.
	 * Specifically, this returns null (allow) when:
	 *   - the value doesn't even parse as a token (`malformed_token` /
	 *     `not_dynamic_content`) — this guard is not a general-purpose
	 *     linter, `diviops_dynamic_content_validate` is;
	 *   - the name matches the `gcid-`/`gvid-`/`gfid-` global-variable
	 *     namespace, or `type` isn't `content` at all (color/gradient tokens);
	 *   - the live registry is empty/unavailable (a D4-only site, an
	 *     older/deactivated Divi, or a non-REST invocation) — we cannot
	 *     confirm the name is bad, so we must not block a legitimate write;
	 *   - the binding is fully valid, or invalid only on a settings-schema
	 *     nuance (`unknown_setting`) rather than the name itself being
	 *     unregistered — that nuance is the validate endpoint's job, not this
	 *     guard's.
	 *
	 * @param string $value   Candidate dynamic-content-shaped string.
	 * @param int    $post_id Target page id, used as registry context.
	 * @return array{code: string, message: string}|null Rejection reason, or
	 *                                                    null to allow the write.
	 */
	private static function dynamic_content_write_path_rejection( string $value, int $post_id ): ?array {
		$result       = self::dynamic_content_parse_and_validate_string( $value, $post_id, 'edit' );
		$error_codes  = array_column( $result['errors'] ?? array(), 'code' );

		if ( in_array( 'malformed_token', $error_codes, true ) || in_array( 'not_dynamic_content', $error_codes, true ) ) {
			return null;
		}

		$name = $result['name'] ?? null;
		$type = $result['type'] ?? null;
		if ( null === $name ) {
			return null; // Defensive: nothing to check against the registry.
		}

		if ( 'content' !== $type || 1 === preg_match( '/^(?:gcid|gvid|gfid)-/', $name ) ) {
			return null; // Global variable / color / gradient namespace — never registered by design.
		}

		if ( ! in_array( 'unknown_option', $error_codes, true ) ) {
			return null; // Valid, or invalid only on a settings-schema nuance — not this guard's job.
		}

		$registry = self::dynamic_content_get_registry( $post_id, 'edit' );
		if ( empty( $registry ) ) {
			return null; // Registry unavailable/empty — cannot confirm the name is bad. Fail open.
		}

		return array(
			'code'    => 'unknown_option',
			'message' => "'{$name}' is not a registered dynamic content option and does not match the gcid-/gvid-/gfid- global-variable namespace.",
		);
	}

	/**
	 * module_update()'s write-path guard: scan `$attrs` for anything that
	 * looks like a dynamic-content binding and, if found, run it through
	 * dynamic_content_write_path_rejection(). Never rejects a plain string;
	 * only values matching the `$variable(`/`@ET-DC@` heuristic are inspected
	 * at all, and even among those, only a confirmed-unregistered name is
	 * rejected — see dynamic_content_write_path_rejection()'s docblock.
	 *
	 * @param array $attrs   The attrs map from the module_update request.
	 * @param int   $post_id Target page id, used as registry context.
	 * @return WP_REST_Response|null Error envelope to return immediately, or
	 *                               null when nothing was confirmed-rejected.
	 */
	private static function dynamic_content_validate_module_update_attrs( array $attrs, int $post_id ) {
		$hits = array();
		foreach ( $attrs as $path => $value ) {
			self::dynamic_content_scan_attrs( $value, (string) $path, $hits );
		}
		if ( empty( $hits ) ) {
			return null;
		}

		foreach ( $hits as $path => $value ) {
			$rejection = self::dynamic_content_write_path_rejection( $value, $post_id );
			if ( null !== $rejection ) {
				return self::envelope_error(
					'invalid_input',
					"Attr path '{$path}' contains an invalid dynamic-content binding: {$rejection['message']}",
					'Use diviops_dynamic_content_validate to inspect the binding, or diviops_dynamic_content_list to see valid option names.',
					400,
					array( 'field' => $path, 'code' => $rejection['code'] )
				);
			}
		}

		return null;
	}

	/**
	 * GET /dynamic-content/list — the live divi_module_dynamic_content_options
	 * registry for a given post/context. post_id defaults to 0 (no specific post
	 * bound — some registered options that gate on post/Theme-Builder context,
	 * e.g. term-description options, may register fewer entries than they would
	 * for a real post id); context defaults to 'edit'. Read-only.
	 *
	 * @param mixed $request REST request-like object (get_param()).
	 * @return WP_REST_Response
	 */
	public static function dynamic_content_list( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) ?? 0 );
		$context = sanitize_key( (string) ( $request->get_param( 'context' ) ?? 'edit' ) );

		if ( ! in_array( $context, array( 'edit', 'display' ), true ) ) {
			return self::envelope_error(
				'invalid_input',
				"context must be 'edit' or 'display'.",
				null,
				400,
				array( 'field' => 'context', 'received' => $context )
			);
		}

		$access_error = self::dynamic_content_check_post_access( $post_id );
		if ( null !== $access_error ) {
			return $access_error;
		}

		$registry = self::dynamic_content_get_registry( $post_id, $context );

		return self::envelope_success(
			array(
				'options' => $registry,
				'count'   => count( $registry ),
				'post_id' => $post_id,
				'context' => $context,
			)
		);
	}

	/**
	 * POST /dynamic-content/build — validate `name`/`settings` against the live
	 * registry, then return the exact `$variable({...})$` token
	 * Conversion::formatDynamicContent() would emit for the same inputs. Does NOT
	 * write anything; purely a builder.
	 *
	 * @param mixed $request REST request-like object (get_param()).
	 * @return WP_REST_Response
	 */
	public static function dynamic_content_build( $request ) {
		$name = (string) ( $request->get_param( 'name' ) ?? '' );
		if ( '' === $name ) {
			return self::envelope_error( 'invalid_input', 'name is required.', null, 400, array( 'field' => 'name' ) );
		}

		$settings_param = $request->get_param( 'settings' );
		$settings       = null === $settings_param ? array() : $settings_param;
		if ( ! is_array( $settings ) ) {
			return self::envelope_error(
				'invalid_input',
				'settings must be an object.',
				null,
				400,
				array( 'field' => 'settings', 'received_type' => gettype( $settings ) )
			);
		}

		$type    = (string) ( $request->get_param( 'type' ) ?? 'content' );
		$type    = '' === $type ? 'content' : $type;
		$post_id = absint( $request->get_param( 'post_id' ) ?? 0 );
		$context = sanitize_key( (string) ( $request->get_param( 'context' ) ?? 'edit' ) );

		if ( ! in_array( $context, array( 'edit', 'display' ), true ) ) {
			return self::envelope_error(
				'invalid_input',
				"context must be 'edit' or 'display'.",
				null,
				400,
				array( 'field' => 'context', 'received' => $context )
			);
		}

		$access_error = self::dynamic_content_check_post_access( $post_id );
		if ( null !== $access_error ) {
			return $access_error;
		}

		$validation = self::dynamic_content_validate_binding( $name, $settings, $post_id, $context );
		if ( ! $validation['valid'] ) {
			return self::envelope_error(
				'invalid_input',
				"'{$name}' is not a valid dynamic content binding.",
				'Use diviops_dynamic_content_list to see registered option names and their settings schema.',
				400,
				array( 'name' => $name, 'errors' => $validation['errors'] )
			);
		}

		$token = self::dynamic_content_format_token( $name, $settings, $type );
		if ( null === $token ) {
			return self::envelope_error(
				'divi_error',
				'settings could not be JSON-encoded into a dynamic-content token.',
				'Check for invalid UTF-8 or non-JSON-serializable values in settings.',
				500,
				array( 'name' => $name )
			);
		}

		return self::envelope_success(
			array(
				'token'    => $token,
				'name'     => $name,
				'settings' => $settings,
				'type'     => $type,
			)
		);
	}

	/**
	 * POST /dynamic-content/validate — validate either a `name` (+ optional
	 * `settings`) pair, or a raw dynamic-content `value` string (modern
	 * `$variable(...)$` or legacy `@ET-DC@...@`, auto-detected and parsed). A
	 * legacy value additionally reports `modern_equivalent`. The request itself
	 * succeeds (ok:true) even when the binding is invalid — `data.valid`/
	 * `data.errors` carry the finding, mirroring validate_blocks' "findings are
	 * the success payload" convention. Only a malformed CALL shape (neither/both
	 * of name/value, or a non-object settings) is an envelope error.
	 *
	 * @param mixed $request REST request-like object (get_param()).
	 * @return WP_REST_Response
	 */
	public static function dynamic_content_validate( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) ?? 0 );
		$context = sanitize_key( (string) ( $request->get_param( 'context' ) ?? 'edit' ) );

		if ( ! in_array( $context, array( 'edit', 'display' ), true ) ) {
			return self::envelope_error(
				'invalid_input',
				"context must be 'edit' or 'display'.",
				null,
				400,
				array( 'field' => 'context', 'received' => $context )
			);
		}

		$access_error = self::dynamic_content_check_post_access( $post_id );
		if ( null !== $access_error ) {
			return $access_error;
		}

		$name  = $request->get_param( 'name' );
		$value = $request->get_param( 'value' );
		$has_name  = null !== $name && '' !== $name;
		$has_value = null !== $value && '' !== $value;

		if ( $has_name === $has_value ) {
			return self::envelope_error(
				'invalid_input',
				'Provide exactly one of name (with optional settings) or value (a $variable(...)$ or legacy @ET-DC@...@ string).',
				null,
				400
			);
		}

		if ( $has_name ) {
			$settings_param = $request->get_param( 'settings' );
			$settings       = null === $settings_param ? array() : $settings_param;
			if ( ! is_array( $settings ) ) {
				return self::envelope_error(
					'invalid_input',
					'settings must be an object.',
					null,
					400,
					array( 'field' => 'settings', 'received_type' => gettype( $settings ) )
				);
			}
			$result             = self::dynamic_content_validate_binding( (string) $name, $settings, $post_id, $context );
			$result['name']     = (string) $name;
			$result['settings'] = $settings;
		} else {
			$result = self::dynamic_content_parse_and_validate_string( (string) $value, $post_id, $context );
		}

		// Normalize the response shape regardless of which path ran, so a
		// consumer never has to branch on which of name/value it sent.
		$result += array(
			'name'              => null,
			'settings'          => (object) array(),
			'type'              => null,
			'legacy_format'     => false,
			'modern_equivalent' => null,
		);

		return self::envelope_success( $result );
	}
}
