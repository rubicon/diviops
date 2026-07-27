<?php
/**
 * Native Divi 5 module schema introspection (#42).
 *
 * schema_get_module resolved a module's attributes from WP_Block_Type_Registry.
 * On a live Divi 5 site the native core modules (divi/text, divi/section, …) are
 * largely NOT registered there — only third-party difl/* are — so introspecting a
 * native module returned not_found (see [[block-registry-is-not-a-divi-oracle]]).
 *
 * Divi 5 ships its own module definitions as `module.json` files under
 * `includes/builder-5/visual-builder/packages/module-library/src/components/<slug>/`,
 * carrying each module's `name` (divi/<slug>), `title`, `category`, and full
 * `attributes` tree. Those files are Divi's own generated interoperability data —
 * a legitimate primary source, and independent of the commercial Pro package. This
 * adds a fallback that reads them so native modules become introspectable.
 *
 * These tests cover the pure, filesystem-testable core: the slug-mapping guard
 * (which is also the path-traversal defense), the module.json parse/shape, and the
 * from-dir read against a synthetic fixture. The live path resolution
 * (`get_theme_file_path(...)`) and the schema_get_module registry-miss wiring are
 * inspected + verified live on a real Divi 5 install.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$fixtures_dir = __DIR__ . '/fixtures/divi-module-library';

// ── native_module_slug_from_name: mapping + hard path-traversal guard ──────

assert_same( 'text', diviops_call( 'native_module_slug_from_name', array( 'divi/text' ) ), 'divi/text maps to slug text' );
assert_same( 'fullwidth-menu', diviops_call( 'native_module_slug_from_name', array( 'divi/fullwidth-menu' ) ), 'a hyphenated native slug maps through' );
assert_same( null, diviops_call( 'native_module_slug_from_name', array( 'difl/faq' ) ), 'a non-divi namespace is not a native module' );
assert_same( null, diviops_call( 'native_module_slug_from_name', array( 'divi/' ) ), 'an empty slug is rejected' );
assert_same( null, diviops_call( 'native_module_slug_from_name', array( 'divi/Text' ) ), 'uppercase is rejected (slugs are lowercase)' );
assert_same( null, diviops_call( 'native_module_slug_from_name', array( 'divi/a_b' ) ), 'underscore is rejected (Divi slugs use hyphens)' );
// The security-critical cases: nothing that could escape the components dir survives.
assert_same( null, diviops_call( 'native_module_slug_from_name', array( 'divi/../../../etc/passwd' ) ), 'a path-traversal name is rejected by the slug guard' );
assert_same( null, diviops_call( 'native_module_slug_from_name', array( 'divi/..' ) ), 'a bare .. is rejected' );
assert_same( null, diviops_call( 'native_module_slug_from_name', array( 'divi/text/../section' ) ), 'an embedded slash is rejected' );

// ── parse_native_module_json: shape a module.json string ──────────────────

$json  = (string) file_get_contents( $fixtures_dir . '/text/module.json' );
$shape = diviops_call( 'parse_native_module_json', array( $json, 'divi/text' ) );
assert_true( is_array( $shape ), 'a valid module.json parses to an array' );
assert_same( 'divi/text', $shape['name'], 'the parsed name is the block name' );
assert_same( 'Text', $shape['title'], 'the title is surfaced' );
assert_same( 'module', $shape['category'], 'the category is surfaced' );
assert_true( isset( $shape['attributes']['module'] ) && isset( $shape['attributes']['content'] ), 'the attributes tree is surfaced (the whole point)' );
assert_same( 'divi_module_json', $shape['source'], 'the response is tagged with its source' );
assert_same( 'et_pb_text', $shape['module_class'], 'the D4 module class is surfaced for mapping' );

// name mismatch (requested name does not match the file's declared name) is rejected.
assert_same( null, diviops_call( 'parse_native_module_json', array( $json, 'divi/section' ) ), 'a name/file mismatch is rejected' );
// malformed json is rejected, not fatal.
assert_same( null, diviops_call( 'parse_native_module_json', array( '{not json', 'divi/text' ) ), 'malformed json returns null' );

// ── native_module_schema_from_dir: read against the fixture dir ────────────

$schema = diviops_call( 'native_module_schema_from_dir', array( 'divi/text', $fixtures_dir ) );
assert_true( is_array( $schema ), 'a known native module resolves from the components dir' );
assert_same( 'divi/text', $schema['name'], 'the resolved schema carries the block name' );

assert_same( null, diviops_call( 'native_module_schema_from_dir', array( 'divi/does-not-exist', $fixtures_dir ) ), 'a module with no module.json returns null (becomes not_found upstream)' );
assert_same( null, diviops_call( 'native_module_schema_from_dir', array( 'divi/../../../etc/passwd', $fixtures_dir ) ), 'a traversal name never reads outside the components dir' );

// ── structural wiring: schema_get_module falls back, list augments ─────────

$trait_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-module-schema.php' );
assert_true(
	1 === preg_match( '/native_module_schema\s*\(/', $trait_src ),
	'schema_get_module (or a helper it calls) invokes the native_module_schema fallback'
);
assert_true(
	method_exists( 'DiviOps_Agent', 'native_module_schema_from_dir' )
		&& method_exists( 'DiviOps_Agent', 'native_module_slug_from_name' )
		&& method_exists( 'DiviOps_Agent', 'parse_native_module_json' ),
	'the native-schema helpers are mixed into DiviOps_Agent'
);
