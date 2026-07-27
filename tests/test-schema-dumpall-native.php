<?php
/**
 * Native modules in schema_get_module_dump_all (#61, part of the skills epic #50).
 *
 * schema_get_module_dump_all is the build-time bulk source the skill-regen pipeline
 * reads. It walked only WP_Block_Type_Registry, so native Divi 5 core modules —
 * made introspectable one-at-a-time by #42 — were still missing from the bulk dump.
 * This covers the bulk-native helper that closes that gap: it reads every
 * module.json in a components directory and returns each module's FULL schema
 * (with its attributes tree), keyed by name.
 *
 * The live path resolution (get_theme_file_path) and the dump_all registry walk are
 * inspected + verified on a real Divi 5 install; here we test the pure from-dir
 * bulk read against the synthetic fixture, and the wiring.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$fixtures_dir = __DIR__ . '/fixtures/divi-module-library';

$all = diviops_call( 'native_module_schemas_all_from_dir', array( $fixtures_dir ) );
assert_true( is_array( $all ), 'the bulk read returns an array keyed by module name' );
assert_true( isset( $all['divi/text'] ), 'the fixture native module is present in the bulk dump' );
assert_same( 'divi/text', $all['divi/text']['name'], 'each entry carries the block name' );
assert_true( isset( $all['divi/text']['attributes']['module'] ), 'each entry carries the full attributes tree (the point of dump_all)' );
assert_same( 'divi_module_json', $all['divi/text']['source'], 'native dump entries are tagged with their source' );

// A missing / non-directory path yields an empty map, never a fatal.
assert_same( array(), diviops_call( 'native_module_schemas_all_from_dir', array( $fixtures_dir . '/does-not-exist' ) ), 'a missing components dir yields an empty bulk map' );
assert_same( array(), diviops_call( 'native_module_schemas_all_from_dir', array( '' ) ), 'an empty dir string yields an empty bulk map' );

// ── wiring: dump_all merges native, helper is mixed in ────────────────────
$trait_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-module-schema.php' );
assert_true(
	1 === preg_match( '/native_module_schemas_all\s*\(/', $trait_src ),
	'schema_get_module_dump_all merges the native module schemas'
);
assert_true(
	method_exists( 'DiviOps_Agent', 'native_module_schemas_all_from_dir' ),
	'the bulk-native helper is mixed into DiviOps_Agent'
);
