<?php
/**
 * schema_version scope guard (#122).
 *
 * `schema_get_module_dump_all` returns a `schema_version` hash that consumers use to
 * short-circuit regeneration. Two facts about that hash's scope are load-bearing, and
 * both were stated wrongly (or not at all) in the docs consumers actually read:
 *
 * 1. It covers every `*PresetAttrsMap.php` under `Packages/`, not just the per-module
 *    maps under `Packages/ModuleLibrary/`. The shared option maps in
 *    `Packages/Module/Options/` are imported by the per-module ones, so narrowing the
 *    walk would let a real canonical-path change slip through unhashed.
 * 2. It roots at `get_theme_file_path()`, so it covers Divi core only. A third-party
 *    module plugin cannot move it, which makes it wrong to treat as a general "some
 *    module's schema changed" signal.
 *
 * The narrower claim propagated from the docblock into the MCP tool description and
 * from there into a design spec that reasoned from it. This guard derives the real
 * scope from `schema_preset_attrs_map_hash()` itself and asserts the two documentation
 * surfaces agree with it, so the claim cannot silently rot back.
 *
 * @package DiviOps
 */

$schema_trait = dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-module-schema.php';
$index_ts     = dirname( __DIR__ ) . '/diviops-server/src/index.ts';

assert_true( is_file( $schema_trait ), 'trait-module-schema.php exists where this test expects it' );
assert_true( is_file( $index_ts ), 'diviops-server/src/index.ts exists where this test expects it' );

$trait_src = (string) file_get_contents( $schema_trait );

/*
 * Ground truth: what schema_preset_attrs_map_hash() actually walks. Extract the
 * function body rather than grepping the whole file, so a ModuleLibrary mention
 * elsewhere in the trait cannot satisfy or defeat these assertions.
 */
$hash_fn_start = strpos( $trait_src, 'private static function schema_preset_attrs_map_hash()' );
assert_true( false !== $hash_fn_start, 'schema_preset_attrs_map_hash() was located in trait-module-schema.php' );

$hash_fn_end = false !== $hash_fn_start ? strpos( $trait_src, "\n\t}", $hash_fn_start ) : false;
assert_true( false !== $hash_fn_end, 'schema_preset_attrs_map_hash() has a locatable closing brace' );

$hash_fn_body = false !== $hash_fn_start && false !== $hash_fn_end
	? substr( $trait_src, $hash_fn_start, $hash_fn_end - $hash_fn_start )
	: '';

$root_found = preg_match( "/get_theme_file_path\(\s*'([^']+)'\s*\)/", $hash_fn_body, $root_match );
assert_true( 1 === $root_found, 'schema_preset_attrs_map_hash() resolves its walk root via get_theme_file_path()' );

$walk_root = 1 === $root_found ? $root_match[1] : '';
assert_same(
	'includes/builder-5/server/Packages',
	$walk_root,
	'the hash walks all of Packages/, so any doc claiming a narrower scope is wrong'
);

// The walk must not be narrowed to one subtree anywhere in the body — the filter is a
// bare `PresetAttrsMap.php` suffix test, applied to everything under the root.
assert_true(
	false === strpos( $hash_fn_body, 'ModuleLibrary' ),
	'schema_preset_attrs_map_hash() applies no ModuleLibrary-only restriction'
);
assert_true(
	false !== strpos( $hash_fn_body, "'PresetAttrsMap.php'" ),
	'schema_preset_attrs_map_hash() filters on the bare *PresetAttrsMap.php suffix'
);

/*
 * Surface 1: the docblock that defines `schema_version` for readers of the plugin.
 * Anchored on its own defining sentence so a rename or a reflow fails loudly instead
 * of vacuously passing.
 */
$doc_start = strpos( $trait_src, '`schema_version` is SHA-1' );
assert_true( false !== $doc_start, 'the docblock defining `schema_version` was located' );

$doc_end = false !== $doc_start ? strpos( $trait_src, "\t */", $doc_start ) : false;
assert_true( false !== $doc_end, 'the `schema_version` docblock has a locatable terminator' );

$doc_block = false !== $doc_start && false !== $doc_end
	? substr( $trait_src, $doc_start, $doc_end - $doc_start )
	: '';

// The original defect verbatim: "every `*PresetAttrsMap.php` file in
// `Packages/ModuleLibrary/`". Naming ModuleLibrary is correct and required (it is one
// of the two hashed subtrees); naming it as the container of every hashed file is the
// wrong claim, so the anti-regression check targets that phrasing, not the substring.
assert_true(
	false === strpos( $doc_block, 'file in `Packages/ModuleLibrary/`' ),
	'the `schema_version` docblock does not state ModuleLibrary/ as the sole hash scope'
);
assert_true(
	false !== strpos( $doc_block, 'under `Packages/`' ),
	'the `schema_version` docblock states the real scope: every map under Packages/'
);
assert_true(
	false !== strpos( $doc_block, 'Packages/Module/Options/' )
		&& false !== strpos( $doc_block, 'Packages/ModuleLibrary/' ),
	'the `schema_version` docblock names both hashed subtrees'
);
assert_true(
	false !== strpos( $doc_block, 'Divi core only' ),
	'the `schema_version` docblock states the hash covers Divi core only'
);
assert_true(
	false !== strpos( $doc_block, 'get_theme_file_path()' )
		&& false !== strpos( $doc_block, 'wp-content/plugins/' ),
	'the `schema_version` docblock explains why a third-party module plugin cannot move the hash'
);

/*
 * Surface 2: the MCP tool description, which is what an agent calling
 * diviops_schema_get_module actually reads. It carried the same claim in shorter form.
 */
$index_src = (string) file_get_contents( $index_ts );

$desc_found = preg_match( '/^\s*"([^"]*`schema_version` hash[^"]*)"/m', $index_src, $desc_match );
assert_true( 1 === $desc_found, 'the diviops_schema_get_module tool description mentioning `schema_version` was located' );

$tool_desc = 1 === $desc_found ? $desc_match[1] : '';
assert_true(
	false !== strpos( $tool_desc, 'Module/Options/' )
		&& false !== strpos( $tool_desc, 'ModuleLibrary/' ),
	'the MCP tool description names both hashed subtrees rather than leaving the scope open'
);
assert_true(
	false !== strpos( $tool_desc, 'Divi core only' ),
	'the MCP tool description states the hash covers Divi core only'
);
assert_true(
	false !== strpos( $tool_desc, 'third-party module plugin' ),
	'the MCP tool description warns that a third-party module plugin update does not move the hash'
);
