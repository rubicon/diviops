<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Trait DiviOps_Agent_DesignSystem
 *
 * Applies a validated design-token set to a live Divi 5 site in one call (#392).
 *
 * Mixed into DiviOps_Agent via `use` in diviops-agent.php — `self::` calls and class
 * constants resolve as if these methods lived directly on the class.
 *
 * ## Why the parse/apply boundary is here
 *
 * The parsing half — prose brand guidelines, markdown token tables, CSV — lives in the
 * divi-5-builder skill, deliberately. Reading free-form text is model work, and an
 * unbounded parser has no business in a write path.
 *
 * What lives here is the half that has to be enforceable. This trait REFUSES a token that
 * carries no value, which turns "never invent a token" from a prompt instruction into a
 * contract a test can hold. A skill that hallucinates a colour gets a loud error rather
 * than a quietly wrong palette.
 *
 * ## Shape
 *
 * Follows variable_create_fluid_system(): validate the whole payload, build a plan, then
 * write. Nothing is written unless every token validates — a partial apply is worse than a
 * refusal, because the caller cannot tell which half landed.
 *
 * Scoped to colours for v1. That is where all 99 live tokens on the reference install are,
 * and where the `$variable()` reference problem lives (47 of those 99).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_DesignSystem {

	/**
	 * The settings a derived colour may carry.
	 *
	 * Measured from the live staging palette (Divi 5.12.0), where 47 of 99 colours are
	 * references. Every observed entry uses one or more of these three keys with integer
	 * values, e.g.
	 *   $variable({"type":"color","value":{"name":"gcid-primary-color",
	 *              "settings":{"lightness":34,"opacity":20}}})$
	 *
	 * A private static function rather than a constant: traits carry no constants below
	 * PHP 8.2 and this plugin's floor is 7.4.
	 */
	private static function design_system_color_settings_keys(): array {
		return [ 'lightness', 'saturation', 'opacity' ];
	}

	/**
	 * Build a refusal.
	 *
	 * Every rejection names the token AND its index, because a caller applying sixty
	 * tokens cannot act on "one of them is wrong" — the same reasoning behind
	 * `colors_index` in global_color_upsert.
	 */
	private static function design_system_reject( string $field, ?string $token, int $index, string $message ): array {
		return [
			'ok'      => false,
			'code'    => 'invalid_input',
			'message' => $message,
			'data'    => [ 'field' => $field, 'token' => $token, 'index' => $index ],
		];
	}

	/**
	 * Derive a slug from a token name, or refuse.
	 *
	 * Lowercasing and collapsing whitespace to hyphens is lossless. Anything else is not:
	 * `sanitize_key()` silently rewriting "brand!" to "brand" would alias one token onto
	 * another, and under overwrite=true that means updating a token the caller never named.
	 * `validate_name_prefix()` already refuses for exactly this reason; this follows it
	 * rather than inventing a second policy.
	 *
	 * @return string|null Null when the name cannot become a slug without rewriting.
	 */
	private static function design_system_slug( string $name ): ?string {
		$slug = strtolower( trim( $name ) );
		$slug = preg_replace( '/\s+/', '-', $slug );
		if ( ! is_string( $slug ) || '' === $slug ) {
			return null;
		}
		// Anything left outside [a-z0-9-] would have to be dropped. Refuse instead.
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return null;
		}
		// All-hyphens slugs to nothing meaningful and would collide with any other such name.
		if ( '' === trim( $slug, '-' ) ) {
			return null;
		}
		return $slug;
	}

	/**
	 * Deterministic token id: `<prefix>-<namespace>-<slug>`.
	 *
	 * Readable in the Visual Builder's picker and greppable inside a stored `$variable()$`
	 * token, neither of which a hash would be. Determinism is what makes a re-import an
	 * update rather than a duplicate.
	 *
	 * @return string|null Null when the name is unslugifiable.
	 */
	private static function design_system_token_id( string $prefix, string $namespace, string $name ): ?string {
		$slug = self::design_system_slug( $name );
		if ( null === $slug ) {
			return null;
		}
		return $prefix . '-' . $namespace . '-' . $slug;
	}

	/**
	 * Order colour tokens so a derived colour is written after the colour it references.
	 *
	 * Divi resolves a reference by id at render time, so a reference written before its
	 * target is not an error at write time — it renders wrong later, silently. Ordering
	 * here is what stops that being possible.
	 *
	 * Depth-first with three marks: unvisited, open, done. Re-entering an open node is a
	 * cycle.
	 *
	 * @param array $tokens       Validated colour tokens.
	 * @param array $existing_ids Ids already on the site, keyed by id.
	 * @return array
	 */
	private static function design_system_order_colors( array $tokens, array $existing_ids = [] ): array {
		$by_name = [];
		foreach ( $tokens as $t ) {
			$by_name[ $t['name'] ] = $t;
		}

		$state   = [];
		$ordered = [];
		$error   = null;

		$visit = function ( $name ) use ( &$visit, &$state, &$ordered, &$error, $by_name, $existing_ids ) {
			if ( null !== $error ) {
				return;
			}
			if ( isset( $state[ $name ] ) && 'done' === $state[ $name ] ) {
				return;
			}
			if ( isset( $state[ $name ] ) && 'open' === $state[ $name ] ) {
				$token = $by_name[ $name ];
				$error = self::design_system_reject(
					'derived_from',
					$name,
					$token['index'],
					sprintf( "colour '%s' is part of a derived_from cycle. Divi cannot resolve one.", $name )
				);
				return;
			}

			$state[ $name ] = 'open';
			$token          = $by_name[ $name ];
			$target         = $token['derived_from'];

			if ( null !== $target ) {
				if ( isset( $by_name[ $target ] ) ) {
					$visit( $target );
					if ( null !== $error ) {
						return;
					}
				} elseif ( ! isset( $existing_ids[ $target ] ) ) {
					$error = self::design_system_reject(
						'derived_from',
						$name,
						$token['index'],
						sprintf(
							"colour '%s' derives from '%s', which is neither in this payload nor on the site.",
							$name,
							$target
						)
					);
					return;
				}
			}

			$state[ $name ] = 'done';
			$ordered[]      = $token;
		};

		foreach ( $tokens as $t ) {
			$visit( $t['name'] );
			if ( null !== $error ) {
				return $error;
			}
		}

		return [ 'ok' => true, 'tokens' => $ordered ];
	}

	/**
	 * Build Divi's `$variable()$` colour-reference token.
	 *
	 * Grammar transcribed from a live entry on staging (Divi 5.12.0), not inferred:
	 *   $variable({"type":"color","value":{"name":"gcid-primary-color","settings":{"lightness":7}}})$
	 *
	 * The cast to object is load-bearing: PHP serializes an empty array as `[]`, where Divi
	 * writes `{}`, and a byte-different token is a different string to every scanner that
	 * greps for it. Mutation-tested — removing the cast reddens the grammar assertions.
	 *
	 * JSON_UNESCAPED_SLASHES is deliberately NOT passed. It would be inert: no slash can
	 * reach this payload, because an id is `[a-z0-9-]` and every setting is an integer.
	 * A first draft carried it with a comment claiming it mattered; the mutation that
	 * removed it survived, which is what proved the claim false. A flag credited with
	 * preventing a failure it cannot reach is the trap this codebase already records once.
	 */
	private static function design_system_reference_token( string $target_id, array $settings ): string {
		$payload = [
			'type'  => 'color',
			'value' => [
				'name'     => $target_id,
				'settings' => (object) $settings,
			],
		];
		return '$variable(' . wp_json_encode( $payload ) . ')$';
	}

	/**
	 * Validate the colour half of a token set.
	 *
	 * Returns a plain array rather than an envelope so the caller can aggregate across
	 * surfaces and emit one refusal for the whole payload.
	 *
	 * @param array $colors Raw colour entries from the request.
	 * @return array
	 */
	private static function design_system_validate_colors( array $colors ): array {
		$tokens = [];

		foreach ( array_values( $colors ) as $idx => $c ) {
			if ( ! is_array( $c ) ) {
				return self::design_system_reject( 'colors', null, $idx, 'each colour must be an object.' );
			}

			$name = isset( $c['name'] ) && is_string( $c['name'] ) ? trim( $c['name'] ) : '';
			if ( '' === $name ) {
				return self::design_system_reject( 'name', null, $idx, 'each colour needs a name.' );
			}

			$has_value   = array_key_exists( 'value', $c ) && '' !== (string) $c['value'];
			$has_derived = array_key_exists( 'derived_from', $c ) && '' !== (string) $c['derived_from'];

			// The never-invent rule. Neither means the caller — or a skill upstream of it —
			// does not actually know this token's value, and guessing one is precisely the
			// failure this boundary exists to prevent.
			if ( ! $has_value && ! $has_derived ) {
				return self::design_system_reject(
					'value',
					$name,
					$idx,
					sprintf(
						"colour '%s' has neither a value nor a derived_from. This tool never invents a token value.",
						$name
					)
				);
			}
			if ( $has_value && $has_derived ) {
				return self::design_system_reject(
					'value',
					$name,
					$idx,
					sprintf( "colour '%s' carries both value and derived_from. Supply exactly one.", $name )
				);
			}

			$settings = [];
			if ( array_key_exists( 'settings', $c ) ) {
				if ( ! $has_derived ) {
					return self::design_system_reject(
						'settings',
						$name,
						$idx,
						sprintf(
							"colour '%s' carries settings without derived_from; settings only apply to a derived colour.",
							$name
						)
					);
				}
				if ( ! is_array( $c['settings'] ) ) {
					return self::design_system_reject( 'settings', $name, $idx, 'settings must be an object.' );
				}
				foreach ( $c['settings'] as $key => $val ) {
					if ( ! in_array( $key, self::design_system_color_settings_keys(), true ) ) {
						return self::design_system_reject(
							(string) $key,
							$name,
							$idx,
							sprintf(
								"colour '%s' carries an unrecognised setting '%s'. Allowed: %s.",
								$name,
								(string) $key,
								implode( ', ', self::design_system_color_settings_keys() )
							)
						);
					}
					// A float would be silently truncated by the cast below, and a silently
					// different number is a silently different colour.
					$is_intish = is_int( $val )
						|| ( is_string( $val ) && '' !== $val && ctype_digit( ltrim( $val, '-' ) ) );
					if ( ! $is_intish ) {
						return self::design_system_reject(
							(string) $key,
							$name,
							$idx,
							sprintf( "colour '%s' setting '%s' must be an integer.", $name, (string) $key )
						);
					}
					$settings[ $key ] = (int) $val;
				}
			}

			// Status vocabulary is per surface (#393). Reuse the colour list rather than
			// restating it — a second copy of that list is how #393 happened.
			$status = null;
			if ( array_key_exists( 'status', $c ) ) {
				$status = (string) $c['status'];
				if ( ! in_array( $status, self::valid_global_color_statuses(), true ) ) {
					return self::design_system_reject(
						'status',
						$name,
						$idx,
						sprintf(
							"colour '%s' status must be one of: %s.",
							$name,
							implode( ', ', self::valid_global_color_statuses() )
						)
					);
				}
			}

			$tokens[] = [
				'name'         => $name,
				'value'        => $has_value ? (string) $c['value'] : null,
				'derived_from' => $has_derived ? (string) $c['derived_from'] : null,
				'settings'     => $settings,
				'label'        => isset( $c['label'] ) && is_string( $c['label'] ) ? $c['label'] : $name,
				'status'       => $status,
				'index'        => $idx,
			];
		}

		return [ 'ok' => true, 'tokens' => $tokens ];
	}

	/**
	 * Apply a design-token set to the site.
	 *
	 * Whole-payload validation up front, then a plan, then writes. Nothing is written
	 * unless every token validates and every id resolves — a partial apply is worse than a
	 * refusal, because the caller cannot tell which half landed.
	 */
	public static function design_system_apply( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires admin capability', [ 'status' => 403 ] );
		}

		$dry_run   = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );
		$overwrite = rest_sanitize_boolean( $request->get_param( 'overwrite' ) ?? false );
		$colors    = $request->get_param( 'colors' );
		$colors    = is_array( $colors ) ? $colors : [];

		try {
			$namespace = self::validate_name_prefix( $request->get_param( 'namespace' ), 'namespace', 'ds' );
		} catch ( \Exception $e ) {
			return self::envelope_error( 'invalid_input', $e->getMessage(), null, 400, [ 'field' => 'namespace' ] );
		}

		if ( empty( $colors ) ) {
			return self::envelope_error(
				'invalid_input',
				'colors must be a non-empty array. A run that would write nothing is a mistake worth reporting, not a no-op.',
				null,
				400,
				[ 'field' => 'colors' ]
			);
		}

		$validated = self::design_system_validate_colors( $colors );
		if ( true !== ( $validated['ok'] ?? false ) ) {
			return self::envelope_error( $validated['code'], $validated['message'], null, 400, $validated['data'] );
		}

		$raw         = et_get_option( 'et_global_data' );
		$global_data = is_array( $raw ) ? $raw : [];
		$color_map   = isset( $global_data['global_colors'] ) && is_array( $global_data['global_colors'] )
			? $global_data['global_colors']
			: [];

		$existing_ids = [];
		foreach ( array_keys( $color_map ) as $existing_id ) {
			$existing_ids[ $existing_id ] = true;
		}

		$ordered = self::design_system_order_colors( $validated['tokens'], $existing_ids );
		if ( true !== ( $ordered['ok'] ?? false ) ) {
			return self::envelope_error( $ordered['code'], $ordered['message'], null, 400, $ordered['data'] );
		}

		// Resolve every id BEFORE writing anything, so an unslugifiable name late in the
		// payload cannot leave the earlier half applied.
		$ids = [];
		foreach ( $ordered['tokens'] as $t ) {
			$id = self::design_system_token_id( 'gcid', $namespace, $t['name'] );
			if ( null === $id ) {
				return self::envelope_error(
					'invalid_input',
					sprintf(
						"colour '%s' cannot become an id without rewriting its name. Use only letters, digits, spaces and hyphens.",
						$t['name']
					),
					'A silently rewritten name would alias this token onto a different one, and under overwrite=true would update the wrong entry.',
					400,
					[ 'field' => 'name', 'token' => $t['name'], 'index' => $t['index'] ]
				);
			}
			$ids[ $t['name'] ] = $id;
		}

		// Offset past Divi's customizer-bound colours, as the other writers do.
		$max_order = self::get_customizer_color_count();
		foreach ( $color_map as $entry ) {
			if ( is_array( $entry ) && isset( $entry['order'] ) ) {
				$max_order = max( $max_order, (int) $entry['order'] );
			}
		}

		$created = [];
		$updated = [];
		$skipped = [];
		$changes = [];

		foreach ( $ordered['tokens'] as $t ) {
			$id     = $ids[ $t['name'] ];
			$exists = isset( $color_map[ $id ] ) && is_array( $color_map[ $id ] );

			if ( $exists && ! $overwrite ) {
				$skipped[] = [ 'id' => $id, 'name' => $t['name'], 'reason' => 'exists' ];
				continue;
			}

			$existing = $exists ? $color_map[ $id ] : [];

			if ( null !== $t['derived_from'] ) {
				// A derived_from naming another token in this payload resolves to that
				// token's minted id; one naming an id already on the site passes through.
				$target = isset( $ids[ $t['derived_from'] ] ) ? $ids[ $t['derived_from'] ] : $t['derived_from'];
				$value  = self::design_system_reference_token( $target, $t['settings'] );
			} else {
				$value = $t['value'];
			}

			// Status follows #393: an omitted status keeps the stored one, and defaults to
			// active only for a genuinely new entry.
			$status = null !== $t['status'] ? $t['status'] : ( $existing['status'] ?? 'active' );

			// Preserve order on update, assign fresh on create — the fluid_system rule.
			$order = $exists && isset( $existing['order'] ) ? $existing['order'] : (string) ( ++$max_order );

			// MERGE, never replace (#380): Divi stores keys this writer does not enumerate.
			$record = array_merge(
				$existing,
				[
					'id'          => $id,
					'color'       => $value,
					'label'       => $t['label'],
					'status'      => $status,
					'order'       => $order,
					'lastUpdated' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				]
			);
			if ( ! isset( $record['folder'] ) ) {
				$record['folder'] = '';
			}
			if ( ! isset( $record['usedInPosts'] ) || ! is_array( $record['usedInPosts'] ) ) {
				$record['usedInPosts'] = [];
			}

			$changes[] = [
				'kind'   => $exists ? 'design_system.update' : 'design_system.create',
				'target' => 'global_colors/' . $id,
				'after'  => [ 'id' => $id, 'color' => $value, 'label' => $t['label'], 'status' => $status ],
			];

			if ( $exists ) {
				$updated[] = [ 'id' => $id, 'name' => $t['name'] ];
			} else {
				$created[] = [ 'id' => $id, 'name' => $t['name'] ];
			}

			if ( ! $dry_run ) {
				$color_map[ $id ] = $record;
			}
		}

		if ( $dry_run ) {
			return self::dry_run_response(
				sprintf(
					'Would apply %d colour token(s): %d create, %d update, %d skipped.',
					count( $created ) + count( $updated ),
					count( $created ),
					count( $updated ),
					count( $skipped )
				),
				$changes,
				[],
				[
					'namespace'     => $namespace,
					'created'       => $created,
					'updated'       => $updated,
					'skipped'       => $skipped,
					'created_count' => count( $created ),
					'updated_count' => count( $updated ),
					'skipped_count' => count( $skipped ),
				]
			);
		}

		$cache = null;
		if ( ! empty( $created ) || ! empty( $updated ) ) {
			$global_data['global_colors'] = $color_map;
			et_update_option( 'et_global_data', $global_data );
			// Once for the whole run, never once per token (#381). A per-token call would
			// mean N full-site CSS sweeps for a single authoring pass.
			$cache = self::invalidate_divi_cache_sitewide();
		}

		return self::attach_meta(
			self::envelope_success( [
				'namespace'     => $namespace,
				'created'       => $created,
				'updated'       => $updated,
				'skipped'       => $skipped,
				'created_count' => count( $created ),
				'updated_count' => count( $updated ),
				'skipped_count' => count( $skipped ),
				'cache'         => $cache,
			] ),
			[ 'canonical_path' => 'et_divi.et_global_data.global_colors' ]
		);
	}
}
