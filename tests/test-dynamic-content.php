<?php
/**
 * Dynamic-content introspection, builder, and validation (#36).
 *
 * Divi 5 dynamic content is a `$variable({"type":...,"value":{"name":...,"settings":{...}}})$`
 * payload (Packages/Conversion/Conversion.php:668-676 formatDynamicContent()), and the
 * legacy D4 form is `@ET-DC@<base64>@` decoding to `{dynamic, content, settings}`
 * (Conversion.php:634-655, DYNAMIC_CONTENT_REGEX). The set of valid option `name`s
 * and their per-option `settings` schema is NOT parsed from the 55
 * `DynamicContentOption*` classes — it is the live result of the
 * `divi_module_dynamic_content_options` filter (WordPress core filter contract:
 * `apply_filters( 'divi_module_dynamic_content_options', [], $post_id, $context )`),
 * confirmed against DynamicContentOptions::get_options() and
 * DynamicContentOptionPostTitle::register_option_callback() on the reference site
 * (registered entry shape: id/label/type/custom/group/fields, where `fields` keys
 * are the valid `settings` keys).
 *
 * These tests inject a fake registry via add_filter() (the same real filter
 * add_filter()/apply_filters() seam trait-media.php's tests use for
 * diviops_media_host_resolver) rather than fabricating Divi's own option classes —
 * this fork's own dynamic_content_get_registry()/dynamic_content_validate_binding()/
 * dynamic_content_format_token()/module_update() dynamic-content scan are what's
 * under test, not Divi's option registration.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

// ── Route/capability wiring: the three dynamic-content capability keys are ─
// registered on DiviOps_Agent::CAPABILITIES so the handshake advertises them
// and REST clients (incl. the MCP server) can gate on them. ────────────────

foreach ( array( 'dynamic_content_list', 'dynamic_content_build', 'dynamic_content_validate' ) as $capability ) {
	assert_true(
		in_array( $capability, DiviOps_Agent::CAPABILITIES, true ),
		"CAPABILITIES includes $capability"
	);
}

/**
 * Register a fake `divi_module_dynamic_content_options` registry, mirroring the
 * real shape confirmed against DynamicContentOptionPostTitle::register_option_callback()
 * (id/label/type/custom/group/fields) — `post_title` accepts `before`/`after` text
 * settings, `post_excerpt` accepts no settings at all (empty `fields`), matching
 * how a real option with no configurable settings registers.
 */
function diviops_test_register_fake_dynamic_content_registry() {
	remove_all_filters( 'divi_module_dynamic_content_options' );
	add_filter(
		'divi_module_dynamic_content_options',
		function ( array $options, int $post_id, string $context ): array {
			$options['post_title'] = array(
				// Deliberately NOT set to 'post_title' here — real Divi option
				// classes don't always set `id` themselves either (several
				// callbacks omit it entirely); DynamicContentOptions::get_options()
				// forces `id` = the array key regardless, and
				// dynamic_content_get_registry() must reproduce that, not just
				// pass whatever the callback happened to set.
				'id'     => 'wrong-id-should-be-overwritten',
				'label'  => 'Post Title',
				'type'   => 'text',
				'custom' => false,
				'group'  => 'Default',
				'fields' => array(
					'before' => array( 'label' => 'Before', 'type' => 'text', 'default' => '' ),
					'after'  => array( 'label' => 'After', 'type' => 'text', 'default' => '' ),
				),
			);
			$options['post_excerpt'] = array(
				'label'  => 'Post Excerpt',
				'type'   => 'text',
				'custom' => false,
				'group'  => 'Default',
				'fields' => array(),
			);
			return $options;
		},
		10,
		3
	);
}

diviops_test_register_fake_dynamic_content_registry();

// ── dynamic_content_list ────────────────────────────────────────────────

$list_request  = new DiviOps_Test_Request( array() );
$list_response = diviops_call( 'dynamic_content_list', array( $list_request ) );
$list_data     = $list_response->get_data();

assert_true( ! empty( $list_data['ok'] ), 'dynamic_content_list succeeds with no post_id/context' );
assert_same( 2, $list_data['data']['count'] ?? null, 'dynamic_content_list reports both fake registry entries' );
assert_same(
	'post_title',
	$list_data['data']['options']['post_title']['id'] ?? null,
	'dynamic_content_list forces id = array key, exactly like DynamicContentOptions::get_options()'
);
assert_same(
	'Post Title',
	$list_data['data']['options']['post_title']['label'] ?? null,
	'dynamic_content_list surfaces the registered label'
);
assert_same( 'edit', $list_data['data']['context'] ?? null, 'dynamic_content_list defaults context to edit' );
assert_same( 0, $list_data['data']['post_id'] ?? null, 'dynamic_content_list defaults post_id to 0' );

$list_bad_context_request  = new DiviOps_Test_Request( array( 'context' => 'bogus' ) );
$list_bad_context_response = diviops_call( 'dynamic_content_list', array( $list_bad_context_request ) );
$list_bad_context_data     = $list_bad_context_response->get_data();
assert_true( empty( $list_bad_context_data['ok'] ), 'dynamic_content_list rejects an invalid context value' );
assert_same( 'invalid_input', $list_bad_context_data['error']['code'] ?? null, 'invalid context -> invalid_input' );

diviops_test_register_post( 9500, '<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->' );

$list_missing_post_request  = new DiviOps_Test_Request( array( 'post_id' => 999999 ) );
$list_missing_post_response = diviops_call( 'dynamic_content_list', array( $list_missing_post_request ) );
$list_missing_post_data     = $list_missing_post_response->get_data();
assert_true( empty( $list_missing_post_data['ok'] ), 'dynamic_content_list rejects an unknown post_id' );
assert_same( 'not_found', $list_missing_post_data['error']['code'] ?? null, 'unknown post_id -> not_found' );

$GLOBALS['diviops_test_uneditable_ids'] = array( 9500 );
$list_forbidden_request  = new DiviOps_Test_Request( array( 'post_id' => 9500 ) );
$list_forbidden_response = diviops_call( 'dynamic_content_list', array( $list_forbidden_request ) );
$list_forbidden_data     = $list_forbidden_response->get_data();
assert_true( empty( $list_forbidden_data['ok'] ), 'dynamic_content_list rejects a post_id the caller cannot edit' );
assert_same( 'forbidden', $list_forbidden_data['error']['code'] ?? null, 'uneditable post_id -> forbidden' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// ── dynamic_content_build ───────────────────────────────────────────────

$build_request  = new DiviOps_Test_Request(
	array(
		'name'     => 'post_title',
		'settings' => array( 'before' => 'Hi ' ),
	)
);
$build_response = diviops_call( 'dynamic_content_build', array( $build_request ) );
$build_data     = $build_response->get_data();
assert_true( ! empty( $build_data['ok'] ), 'dynamic_content_build succeeds for a known option + valid settings' );
assert_same(
	'$variable({"type":"content","value":{"name":"post_title","settings":{"before":"Hi "}}})$',
	$build_data['data']['token'] ?? null,
	'dynamic_content_build emits the exact formatDynamicContent() encoding'
);

$build_empty_settings_request  = new DiviOps_Test_Request( array( 'name' => 'post_excerpt' ) );
$build_empty_settings_response = diviops_call( 'dynamic_content_build', array( $build_empty_settings_request ) );
$build_empty_settings_data     = $build_empty_settings_response->get_data();
assert_true( ! empty( $build_empty_settings_data['ok'] ), 'dynamic_content_build succeeds with omitted settings (defaults to {})' );
assert_same(
	'$variable({"type":"content","value":{"name":"post_excerpt","settings":{}}})$',
	$build_empty_settings_data['data']['token'] ?? null,
	'omitted/empty settings serialize as {} not [] — the (object) cast in formatDynamicContent()'
);

$build_unknown_request  = new DiviOps_Test_Request( array( 'name' => 'no_such_option' ) );
$build_unknown_response = diviops_call( 'dynamic_content_build', array( $build_unknown_request ) );
$build_unknown_data     = $build_unknown_response->get_data();
assert_true( empty( $build_unknown_data['ok'] ), 'dynamic_content_build rejects an unregistered option name' );
assert_same( 'invalid_input', $build_unknown_data['error']['code'] ?? null, 'unknown option name -> invalid_input' );

$build_bad_setting_request  = new DiviOps_Test_Request(
	array(
		'name'     => 'post_title',
		'settings' => array( 'nonexistent_field' => 'x' ),
	)
);
$build_bad_setting_response = diviops_call( 'dynamic_content_build', array( $build_bad_setting_request ) );
$build_bad_setting_data     = $build_bad_setting_response->get_data();
assert_true( empty( $build_bad_setting_data['ok'] ), 'dynamic_content_build rejects a settings key outside the registered schema' );
assert_same( 'invalid_input', $build_bad_setting_data['error']['code'] ?? null, 'unknown settings key -> invalid_input' );

$build_no_settings_allowed_request  = new DiviOps_Test_Request(
	array(
		'name'     => 'post_excerpt',
		'settings' => array( 'anything' => 'x' ),
	)
);
$build_no_settings_allowed_response = diviops_call( 'dynamic_content_build', array( $build_no_settings_allowed_request ) );
$build_no_settings_allowed_data     = $build_no_settings_allowed_response->get_data();
assert_true(
	empty( $build_no_settings_allowed_data['ok'] ),
	'dynamic_content_build rejects any settings key for an option that registers an empty fields schema'
);

$build_missing_name_request  = new DiviOps_Test_Request( array() );
$build_missing_name_response = diviops_call( 'dynamic_content_build', array( $build_missing_name_request ) );
$build_missing_name_data     = $build_missing_name_response->get_data();
assert_true( empty( $build_missing_name_data['ok'] ), 'dynamic_content_build requires name' );
assert_same( 'invalid_input', $build_missing_name_data['error']['code'] ?? null, 'missing name -> invalid_input' );

// ── dynamic_content_validate: name + settings form ──────────────────────

$validate_ns_request  = new DiviOps_Test_Request(
	array(
		'name'     => 'post_title',
		'settings' => array( 'before' => 'Hi ' ),
	)
);
$validate_ns_response = diviops_call( 'dynamic_content_validate', array( $validate_ns_request ) );
$validate_ns_data     = $validate_ns_response->get_data();
assert_true( ! empty( $validate_ns_data['ok'] ), 'dynamic_content_validate (name+settings) request succeeds' );
assert_true( true === ( $validate_ns_data['data']['valid'] ?? null ), 'a known option + valid settings validates true' );
assert_same( array(), $validate_ns_data['data']['errors'] ?? null, 'a valid binding reports no errors' );

$validate_ns_bad_request  = new DiviOps_Test_Request( array( 'name' => 'no_such_option' ) );
$validate_ns_bad_response = diviops_call( 'dynamic_content_validate', array( $validate_ns_bad_request ) );
$validate_ns_bad_data     = $validate_ns_bad_response->get_data();
assert_true( ! empty( $validate_ns_bad_data['ok'] ), 'dynamic_content_validate request itself still succeeds for an invalid binding' );
assert_true( false === ( $validate_ns_bad_data['data']['valid'] ?? null ), 'an unregistered option name validates false' );
assert_true( ! empty( $validate_ns_bad_data['data']['errors'] ), 'an invalid binding reports at least one structured error' );

// ── dynamic_content_validate: raw $variable(...)$ string form ───────────

$modern_token = '$variable({"type":"content","value":{"name":"post_title","settings":{"before":"Hi "}}})$';

$validate_value_request  = new DiviOps_Test_Request( array( 'value' => $modern_token ) );
$validate_value_response = diviops_call( 'dynamic_content_validate', array( $validate_value_request ) );
$validate_value_data     = $validate_value_response->get_data();
assert_true( ! empty( $validate_value_data['ok'] ), 'dynamic_content_validate (value form) request succeeds' );
assert_true( true === ( $validate_value_data['data']['valid'] ?? null ), 'a well-formed, registered $variable() token validates true' );
assert_same( 'post_title', $validate_value_data['data']['name'] ?? null, 'the value form parses the name out of the token' );
assert_same( array( 'before' => 'Hi ' ), $validate_value_data['data']['settings'] ?? null, 'the value form parses the settings out of the token' );
assert_true( false === ( $validate_value_data['data']['legacy_format'] ?? null ), 'a modern $variable() token is not flagged legacy' );

$validate_malformed_request  = new DiviOps_Test_Request( array( 'value' => '$variable(not json)$' ) );
$validate_malformed_response = diviops_call( 'dynamic_content_validate', array( $validate_malformed_request ) );
$validate_malformed_data     = $validate_malformed_response->get_data();
assert_true( ! empty( $validate_malformed_data['ok'] ), 'dynamic_content_validate request succeeds even for malformed token content' );
assert_true( false === ( $validate_malformed_data['data']['valid'] ?? null ), 'malformed $variable() JSON payload validates false' );

$validate_not_dc_request  = new DiviOps_Test_Request( array( 'value' => 'just a plain string' ) );
$validate_not_dc_response = diviops_call( 'dynamic_content_validate', array( $validate_not_dc_request ) );
$validate_not_dc_data     = $validate_not_dc_response->get_data();
assert_true( ! empty( $validate_not_dc_data['ok'] ), 'dynamic_content_validate request succeeds for a plain non-dynamic-content string' );
assert_true( false === ( $validate_not_dc_data['data']['valid'] ?? null ), 'a plain string that is not dynamic content validates false' );

// ── dynamic_content_validate: legacy @ET-DC@...@ (D4) form ──────────────

$legacy_payload = wp_json_encode( array( 'dynamic' => true, 'content' => 'post_title', 'settings' => array( 'before' => 'Hi ' ) ) );
$legacy_token    = '@ET-DC@' . base64_encode( $legacy_payload ) . '@';

$validate_legacy_request  = new DiviOps_Test_Request( array( 'value' => $legacy_token ) );
$validate_legacy_response = diviops_call( 'dynamic_content_validate', array( $validate_legacy_request ) );
$validate_legacy_data     = $validate_legacy_response->get_data();
assert_true( ! empty( $validate_legacy_data['ok'] ), 'dynamic_content_validate (legacy value) request succeeds' );
assert_true( true === ( $validate_legacy_data['data']['valid'] ?? null ), 'a well-formed, registered legacy @ET-DC@ token validates true' );
assert_true( true === ( $validate_legacy_data['data']['legacy_format'] ?? null ), 'a legacy @ET-DC@ token is flagged legacy_format' );
assert_same(
	'$variable({"type":"content","value":{"name":"post_title","settings":{"before":"Hi "}}})$',
	$validate_legacy_data['data']['modern_equivalent'] ?? null,
	'a legacy token reports the exact modern $variable() equivalent'
);

$validate_legacy_unknown_payload = wp_json_encode( array( 'dynamic' => true, 'content' => 'no_such_option', 'settings' => array() ) );
$validate_legacy_unknown_token   = '@ET-DC@' . base64_encode( $validate_legacy_unknown_payload ) . '@';
$validate_legacy_unknown_request  = new DiviOps_Test_Request( array( 'value' => $validate_legacy_unknown_token ) );
$validate_legacy_unknown_response = diviops_call( 'dynamic_content_validate', array( $validate_legacy_unknown_request ) );
$validate_legacy_unknown_data     = $validate_legacy_unknown_response->get_data();
assert_true( false === ( $validate_legacy_unknown_data['data']['valid'] ?? null ), 'a legacy token naming an unregistered option validates false' );
assert_true( true === ( $validate_legacy_unknown_data['data']['legacy_format'] ?? null ), 'still flagged legacy_format even when invalid' );

// ── dynamic_content_validate: input-shape errors ─────────────────────────

$validate_neither_request  = new DiviOps_Test_Request( array() );
$validate_neither_response = diviops_call( 'dynamic_content_validate', array( $validate_neither_request ) );
$validate_neither_data     = $validate_neither_response->get_data();
assert_true( empty( $validate_neither_data['ok'] ), 'dynamic_content_validate requires exactly one of name/value (neither provided)' );
assert_same( 'invalid_input', $validate_neither_data['error']['code'] ?? null, 'neither name nor value -> invalid_input' );

$validate_both_request  = new DiviOps_Test_Request( array( 'name' => 'post_title', 'value' => $modern_token ) );
$validate_both_response = diviops_call( 'dynamic_content_validate', array( $validate_both_request ) );
$validate_both_data     = $validate_both_response->get_data();
assert_true( empty( $validate_both_data['ok'] ), 'dynamic_content_validate requires exactly one of name/value (both provided)' );
assert_same( 'invalid_input', $validate_both_data['error']['code'] ?? null, 'both name and value -> invalid_input' );

// ── module_update(): write-path validation of dynamic-content bindings ──

$dc_page_content = '<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->';
diviops_test_register_post( 9510, $dc_page_content );

$mu_valid_request  = new DiviOps_Test_Request(
	array(
		'id'         => 9510,
		'auto_index' => 'text:1',
		'attrs'      => array( 'content.dynamicContentValue' => $modern_token ),
	)
);
$mu_valid_response = diviops_call( 'module_update', array( $mu_valid_request ) );
$mu_valid_data     = $mu_valid_response->get_data();
assert_true( ! empty( $mu_valid_data['ok'] ), 'module_update accepts attrs carrying a well-formed, registered $variable() token' );

// #36 review fix 1: the write-path guard is not a general-purpose linter —
// diviops_dynamic_content_validate is. A malformed $variable(...)$ payload
// must ALLOW the write (fail open), not reject it.
diviops_test_register_post( 9511, $dc_page_content );
$mu_malformed_request  = new DiviOps_Test_Request(
	array(
		'id'         => 9511,
		'auto_index' => 'text:1',
		'attrs'      => array( 'content.dynamicContentValue' => '$variable(not json)$' ),
	)
);
$mu_malformed_response = diviops_call( 'module_update', array( $mu_malformed_request ) );
$mu_malformed_data     = $mu_malformed_response->get_data();
assert_true( ! empty( $mu_malformed_data['ok'] ), 'module_update ALLOWS attrs carrying a malformed $variable() token (guard fails open, not the standalone linter)' );

diviops_test_register_post( 9512, $dc_page_content );
$mu_unknown_request  = new DiviOps_Test_Request(
	array(
		'id'         => 9512,
		'auto_index' => 'text:1',
		'attrs'      => array( 'content.dynamicContentValue' => '$variable({"type":"content","value":{"name":"no_such_option","settings":{}}})$' ),
	)
);
$mu_unknown_response = diviops_call( 'module_update', array( $mu_unknown_request ) );
$mu_unknown_data     = $mu_unknown_response->get_data();
assert_true( empty( $mu_unknown_data['ok'] ), 'module_update rejects attrs binding an unregistered dynamic-content option name' );
assert_same( 'invalid_input', $mu_unknown_data['error']['code'] ?? null, 'unregistered dynamic-content option -> invalid_input' );
assert_same(
	$dc_page_content,
	$GLOBALS['diviops_test_posts'][9512]->post_content,
	'a rejected unregistered dynamic-content write leaves post_content untouched'
);

diviops_test_register_post( 9513, $dc_page_content );
$mu_plain_request  = new DiviOps_Test_Request(
	array(
		'id'         => 9513,
		'auto_index' => 'text:1',
		'attrs'      => array( 'module.meta.adminLabel.desktop.value' => 'Just a plain label, not dynamic content' ),
	)
);
$mu_plain_response = diviops_call( 'module_update', array( $mu_plain_request ) );
$mu_plain_data     = $mu_plain_response->get_data();
assert_true(
	! empty( $mu_plain_data['ok'] ),
	'module_update never runs dynamic-content validation against an ordinary plain-string attr value (conservative $variable(/@ET-DC@ prefix heuristic)'
);

// Nested value: the dynamic-content scan must walk into nested arrays, not
// just top-level attrs values, since a single dot-path can set a whole
// sub-tree at once.
diviops_test_register_post( 9514, $dc_page_content );
$mu_nested_request  = new DiviOps_Test_Request(
	array(
		'id'         => 9514,
		'auto_index' => 'text:1',
		'attrs'      => array(
			'content' => array(
				'dynamicContentValue' => '$variable({"type":"content","value":{"name":"no_such_option","settings":{}}})$',
			),
		),
	)
);
$mu_nested_response = diviops_call( 'module_update', array( $mu_nested_request ) );
$mu_nested_data     = $mu_nested_response->get_data();
assert_true( empty( $mu_nested_data['ok'] ), 'module_update walks nested attrs values to find a buried dynamic-content binding' );
assert_same( 'invalid_input', $mu_nested_data['error']['code'] ?? null, 'buried unregistered dynamic-content option -> invalid_input' );

// ── module_update() write-path guard: mandatory regressions (#36 review) ─
//
// $variable(...)$ is Divi's SHARED variable wrapper, not exclusive to dynamic
// content — global colors (gcid-*), global variables (gvid-*: spacing/sizes/
// fonts), and gradients use the identical syntax and are deliberately never
// registered in divi_module_dynamic_content_options
// (DynamicContentGlobalVariableOptions::register_option_callback() returns
// $options unchanged) since they resolve via the separate
// divi_module_dynamic_content_resolved_value filter instead. `type` does not
// discriminate: the fork's own canonical gvid- form uses "type":"content",
// identical to a real dynamic-content binding. The fake registry active for
// this whole file (post_title/post_excerpt only) never contains a gvid-/
// gcid- entry, so these prove the exemption is by NAME PATTERN / type, not
// by registry membership — exactly the gap the live-registry review caught.

// Canonical gvid- form taken verbatim from
// skills/divi-5-builder/references/presets.md:489 (unescaped for a PHP
// string literal — the reference doc shows it embedded inside a JSON string
// for documentation purposes).
$gvid_token = '$variable({"type":"content","value":{"name":"gvid-oa-space-2","settings":{}}})$';
diviops_test_register_post( 9515, $dc_page_content );
$mu_gvid_request  = new DiviOps_Test_Request(
	array(
		'id'         => 9515,
		'auto_index' => 'text:1',
		'attrs'      => array( 'content.dynamicContentValue' => $gvid_token ),
	)
);
$mu_gvid_response = diviops_call( 'module_update', array( $mu_gvid_request ) );
$mu_gvid_data     = $mu_gvid_response->get_data();
assert_true(
	! empty( $mu_gvid_data['ok'] ),
	'module_update ALLOWS a gvid- global-variable token even though it is absent from the (non-empty) dynamic-content registry'
);

// Divi core's own Conversion.php calls formatDynamicContent($globalColorId,
// [], 'color') for global colors — type:"color", not "content".
$gcid_token = '$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{}}})$';
diviops_test_register_post( 9516, $dc_page_content );
$mu_gcid_request  = new DiviOps_Test_Request(
	array(
		'id'         => 9516,
		'auto_index' => 'text:1',
		'attrs'      => array( 'content.dynamicContentValue' => $gcid_token ),
	)
);
$mu_gcid_response = diviops_call( 'module_update', array( $mu_gcid_request ) );
$mu_gcid_data     = $mu_gcid_response->get_data();
assert_true(
	! empty( $mu_gcid_data['ok'] ),
	'module_update ALLOWS a gcid- global-color token (type:"color", not "content") even though it is absent from the registry'
);

diviops_test_register_post( 9517, $dc_page_content );
$mu_trailing_text_request  = new DiviOps_Test_Request(
	array(
		'id'         => 9517,
		'auto_index' => 'text:1',
		'attrs'      => array( 'content.dynamicContentValue' => $modern_token . ' trailing text' ),
	)
);
$mu_trailing_text_response = diviops_call( 'module_update', array( $mu_trailing_text_request ) );
$mu_trailing_text_data     = $mu_trailing_text_response->get_data();
assert_true(
	! empty( $mu_trailing_text_data['ok'] ),
	'module_update ALLOWS a $variable() token followed by literal text (fails the anchored regex -> malformed_token -> guard fails open)'
);

diviops_test_register_post( 9518, $dc_page_content );
$mu_two_tokens_request  = new DiviOps_Test_Request(
	array(
		'id'         => 9518,
		'auto_index' => 'text:1',
		'attrs'      => array( 'content.dynamicContentValue' => $modern_token . $modern_token ),
	)
);
$mu_two_tokens_response = diviops_call( 'module_update', array( $mu_two_tokens_request ) );
$mu_two_tokens_data     = $mu_two_tokens_response->get_data();
assert_true(
	! empty( $mu_two_tokens_data['ok'] ),
	'module_update ALLOWS two adjacent $variable() tokens in one attr value (invalid JSON between them -> malformed_token -> guard fails open)'
);

diviops_test_register_post( 9519, $dc_page_content );
$mu_et_dc_prose_request  = new DiviOps_Test_Request(
	array(
		'id'         => 9519,
		'auto_index' => 'text:1',
		'attrs'      => array( 'content.innerContent' => 'Divi 4 used @ET-DC@ tokens for dynamic content.' ),
	)
);
$mu_et_dc_prose_response = diviops_call( 'module_update', array( $mu_et_dc_prose_request ) );
$mu_et_dc_prose_data     = $mu_et_dc_prose_response->get_data();
assert_true(
	! empty( $mu_et_dc_prose_data['ok'] ),
	'module_update ALLOWS ordinary prose that merely contains the substring @ET-DC@ (no closing @ -> malformed_token -> guard fails open)'
);

// Empty/unavailable registry (a D4-only site, an older/deactivated Divi, or
// a non-REST invocation): a real, well-formed, otherwise-known-good token
// must still be ALLOWED — we cannot confirm the name is bad without a
// registry to check it against, so failing closed here would make every
// existing dynamic-content page uneditable.
remove_all_filters( 'divi_module_dynamic_content_options' );
diviops_test_register_post( 9521, $dc_page_content );
$mu_empty_registry_request  = new DiviOps_Test_Request(
	array(
		'id'         => 9521,
		'auto_index' => 'text:1',
		'attrs'      => array( 'content.dynamicContentValue' => $modern_token ),
	)
);
$mu_empty_registry_response = diviops_call( 'module_update', array( $mu_empty_registry_request ) );
$mu_empty_registry_data     = $mu_empty_registry_response->get_data();
assert_true(
	! empty( $mu_empty_registry_data['ok'] ),
	'module_update ALLOWS a well-formed, well-known dynamic-content token when the live registry is empty/unavailable (fails open)'
);
diviops_test_register_fake_dynamic_content_registry(); // restore for anything after this point

// The one true positive: registry non-empty, token well-formed, name is
// NOT a global-variable id, and is genuinely absent from the registry ->
// the guard must still reject. (Already proven above at post 9512/9514;
// re-asserted here, immediately after the empty-registry fail-open case, so
// a regression that accidentally makes the guard fail open UNCONDITIONALLY
// would be caught right where it's most likely to be introduced.)
diviops_test_register_post( 9522, $dc_page_content );
$mu_true_positive_request  = new DiviOps_Test_Request(
	array(
		'id'         => 9522,
		'auto_index' => 'text:1',
		'attrs'      => array( 'content.dynamicContentValue' => '$variable({"type":"content","value":{"name":"definitely_not_registered","settings":{}}})$' ),
	)
);
$mu_true_positive_response = diviops_call( 'module_update', array( $mu_true_positive_request ) );
$mu_true_positive_data     = $mu_true_positive_response->get_data();
assert_true(
	empty( $mu_true_positive_data['ok'] ),
	'module_update still REJECTS a well-formed token naming a genuinely unregistered option when the registry is non-empty'
);
assert_same(
	'invalid_input',
	$mu_true_positive_data['error']['code'] ?? null,
	'the one true positive still surfaces invalid_input'
);
assert_same(
	$dc_page_content,
	$GLOBALS['diviops_test_posts'][9522]->post_content,
	'the one true positive still leaves post_content untouched'
);

// ── dynamic_content_parse_and_validate_string(): modern-first reorder (#36
// review fix 5) — a well-formed MODERN token whose settings happen to
// contain the literal substring "@ET-DC@" must still be parsed as modern,
// not misrouted to the legacy @ET-DC@ parser (which would fail to
// base64-decode it and report a spurious malformed_token).

$modern_token_containing_et_dc_substring =
	'$variable({"type":"content","value":{"name":"post_title","settings":{"before":"@ET-DC@literal@"}}})$';

$validate_reorder_request  = new DiviOps_Test_Request( array( 'value' => $modern_token_containing_et_dc_substring ) );
$validate_reorder_response = diviops_call( 'dynamic_content_validate', array( $validate_reorder_request ) );
$validate_reorder_data     = $validate_reorder_response->get_data();
assert_true(
	true === ( $validate_reorder_data['data']['valid'] ?? null ),
	'a modern token whose settings contain the literal substring @ET-DC@ still validates as a modern token, not a malformed legacy one'
);
assert_true(
	false === ( $validate_reorder_data['data']['legacy_format'] ?? null ),
	'a modern token whose settings contain @ET-DC@ is not misrouted to the legacy parser'
);
assert_same(
	'post_title',
	$validate_reorder_data['data']['name'] ?? null,
	'the modern-first reorder still parses out the correct name'
);

// ── dynamic_content_get_registry(): per-request memoization (#36 review
// fix 4) — the DB-backed filter must be invoked at most once per distinct
// (post_id, context), not once per candidate value scanned.
//
// Isolation note for whoever adds the next test here: DiviOps_Agent's
// $dynamic_content_registry_cache is a real per-request cache, but this test
// runner loads the whole suite into ONE PHP process (tests/run.php requires
// every test-*.php file in sequence), so it persists across every assertion
// in this file, not just this block. Correct in production (one WP REST call
// = one process = a fresh cache), but it means a test that mutates the
// divi_module_dynamic_content_options filter must use a post_id that has
// never been passed to dynamic_content_get_registry() before, or its result
// will read a STALE cached registry from an earlier assertion instead of the
// one it just registered. Every registry-mutating block in this file (list,
// build, validate, and each module_update write-path case) already follows
// that rule by using a fresh, never-before-used post_id — keep doing that
// rather than reusing one.

$memo_call_count = 0;
remove_all_filters( 'divi_module_dynamic_content_options' );
add_filter(
	'divi_module_dynamic_content_options',
	function ( array $options, int $post_id, string $context ) use ( &$memo_call_count ): array {
		$memo_call_count++;
		$options['memo_probe'] = array(
			'label'  => 'Memo Probe',
			'type'   => 'text',
			'custom' => false,
			'group'  => 'Default',
			'fields' => array(),
		);
		return $options;
	},
	10,
	3
);

diviops_test_register_post( 9530, $dc_page_content );
diviops_test_register_post( 9531, $dc_page_content );

diviops_call( 'dynamic_content_list', array( new DiviOps_Test_Request( array( 'post_id' => 9530 ) ) ) );
diviops_call( 'dynamic_content_list', array( new DiviOps_Test_Request( array( 'post_id' => 9530 ) ) ) );
diviops_call( 'dynamic_content_list', array( new DiviOps_Test_Request( array( 'post_id' => 9530 ) ) ) );
assert_same( 1, $memo_call_count, 'dynamic_content_get_registry() memoizes: 3 calls for the same (post_id, context) invoke the underlying filter once' );

diviops_call( 'dynamic_content_list', array( new DiviOps_Test_Request( array( 'post_id' => 9531 ) ) ) );
assert_same( 2, $memo_call_count, 'a different post_id is a different cache key and re-invokes the filter' );

diviops_test_register_fake_dynamic_content_registry(); // restore for anything after this point

// ── dynamic_content_build(): wp_json_encode() failure is handled, not
// silently coerced (#36 review fix 6) — an invalid-UTF-8 settings value
// makes json_encode()/wp_json_encode() return false; dynamic_content_build
// must surface an error envelope, not the literal string '$variable()$'.

$invalid_utf8 = "\xB1\x31"; // a lone continuation byte: not valid UTF-8.
$build_bad_utf8_request  = new DiviOps_Test_Request(
	array(
		'name'     => 'post_title',
		'settings' => array( 'before' => $invalid_utf8 ),
	)
);
$build_bad_utf8_response = diviops_call( 'dynamic_content_build', array( $build_bad_utf8_request ) );
$build_bad_utf8_data     = $build_bad_utf8_response->get_data();
assert_true( empty( $build_bad_utf8_data['ok'] ), 'dynamic_content_build surfaces an error when settings cannot be JSON-encoded (invalid UTF-8), instead of silently emitting a corrupted token' );
