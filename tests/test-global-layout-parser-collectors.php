<?php
// SPDX-License-Identifier: MIT
/**
 * divi/global-layout wrapper counting parity for the parser-backed collectors.
 *
 * #13 fixed the divi/global-layout wrapper index divergence for find_block(),
 * module_update()'s inline scanner, find_all_sections(), parse_block_tree(), and
 * walk_and_mutate() by resolving the wrapper's counted type from its own
 * attrs.blockName, but left two parser-backed collectors out of scope:
 *
 * - collect_readable_divi_blocks(), used by find_block_for_read_with_parser(),
 *   the fallback module_get() reaches only when the raw find_block() scanner
 *   hits a parse_error on malformed markup.
 * - collect_parser_move_blocks(), used by move_block_with_parser(), the fallback
 *   module_move() reaches under the same condition.
 *
 * Both still counted a global-layout wrapper literally as global-layout:N instead
 * of resolving it to the type its own attrs.blockName names, exactly the bug #13
 * fixed everywhere else. These tests pin the #14 fix: both collectors resolve the
 * wrapper's counted type via counted_block_identifier(), with a fallback to
 * literal global-layout:N counting when attrs.blockName is absent.
 *
 * Both collectors take a parse_blocks()-shaped array and mutate their output
 * arguments by reference (no return value), so they are called through
 * diviops_call_ref(), the same way tests/test-global-layout-index.php and
 * tests/test-namespace-agnostic-targeting.php call parse_block_tree() and
 * walk_and_mutate(). Fixture shapes mirror those same two files: a placeholder,
 * a divi/global-layout wrapper carrying {"globalModule":"900296",
 * "blockName":"divi/section",...}, and two real divi/section blocks.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

// ── Shared fixture: placeholder, global-layout wrapper (resolves to
// divi/section), then two real sections ──────────────────────────────────

$tree_blocks = array(
	array(
		'blockName'    => 'divi/placeholder',
		'attrs'        => array( 'builderVersion' => '5.9.0' ),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/global-layout',
		'attrs'        => array(
			'globalModule'   => '900296',
			'blockName'      => 'divi/section',
			'builderVersion' => '5.9.0',
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array(
			'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Second Section' ) ) ) ),
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array(
			'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Third Section' ) ) ) ),
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
);

// Negative fallback fixture: a wrapper with no blockName attr, followed by one
// real section.
$tree_blocks_unresolved = array(
	array(
		'blockName'    => 'divi/global-layout',
		'attrs'        => array( 'globalModule' => '900297' ),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array(
			'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Only Section' ) ) ) ),
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
);

// ── collect_readable_divi_blocks(): find_block_for_read_with_parser() /
// module_get()'s parser fallback ──────────────────────────────────────────

$flat_modules = array();
$type_counts  = array();
$args         = array( $tree_blocks, &$flat_modules, &$type_counts );
diviops_call_ref( 'collect_readable_divi_blocks', $args );

assert_same( 'placeholder', $flat_modules[0]['type'], 'a preceding placeholder counts under its own type, unaffected' );
assert_same( 'placeholder:1', $flat_modules[0]['auto_index'], 'a preceding placeholder counts under its own type, unaffected' );
assert_same( 'section', $flat_modules[1]['type'], 'collect_readable_divi_blocks resolves the wrapper to section, not global-layout' );
assert_same( 'section:1', $flat_modules[1]['auto_index'], 'collect_readable_divi_blocks resolves the wrapper to section:1' );
assert_same( 'section:2', $flat_modules[2]['auto_index'], 'the first real section shifts to section:2' );
assert_same( 'section:3', $flat_modules[3]['auto_index'], 'the second real section shifts to section:3' );
assert_same(
	array(
		'placeholder' => 1,
		'section'     => 3,
	),
	$type_counts,
	'the wrapper is folded into the section counter, not tracked as its own global-layout type'
);

// Negative: a wrapper with no blockName attr falls back to counting as
// global-layout, and does not shift the section that follows it.
$flat_modules_unresolved = array();
$type_counts_unresolved  = array();
$args_unresolved         = array( $tree_blocks_unresolved, &$flat_modules_unresolved, &$type_counts_unresolved );
diviops_call_ref( 'collect_readable_divi_blocks', $args_unresolved );

assert_same(
	'global-layout',
	$flat_modules_unresolved[0]['type'],
	'a wrapper with no blockName attr falls back to counting as global-layout'
);
assert_same(
	'global-layout:1',
	$flat_modules_unresolved[0]['auto_index'],
	'a wrapper with no blockName attr falls back to counting as global-layout:1'
);
assert_same(
	'section:1',
	$flat_modules_unresolved[1]['auto_index'],
	'without a resolvable blockName the following real section is not shifted'
);

// ── collect_parser_move_blocks(): move_block_with_parser() / module_move()'s
// parser fallback ──────────────────────────────────────────────────────────

$move_modules = array();
$move_counts  = array();
$move_args    = array( $tree_blocks, &$move_modules, &$move_counts, array() );
diviops_call_ref( 'collect_parser_move_blocks', $move_args );

assert_same( 'placeholder:1', $move_modules[0]['auto_index'], 'a preceding placeholder counts under its own type, unaffected' );
assert_same( 'section', $move_modules[1]['type'], 'collect_parser_move_blocks resolves the wrapper to section, not global-layout' );
assert_same( 'section:1', $move_modules[1]['auto_index'], 'collect_parser_move_blocks resolves the wrapper to section:1' );
assert_same( array( 1 ), $move_modules[1]['path'], "the wrapper's own tree path is untouched by resolving its counted type" );
assert_same( 'section:2', $move_modules[2]['auto_index'], 'the first real section shifts to section:2' );
assert_same( 'section:3', $move_modules[3]['auto_index'], 'the second real section shifts to section:3' );
assert_same(
	array(
		'placeholder' => 1,
		'section'     => 3,
	),
	$move_counts,
	'the wrapper is folded into the section counter, not tracked as its own global-layout type'
);

// Negative: a wrapper with no blockName attr falls back to counting as
// global-layout, and does not shift the section that follows it.
$move_modules_unresolved = array();
$move_counts_unresolved  = array();
$move_args_unresolved    = array( $tree_blocks_unresolved, &$move_modules_unresolved, &$move_counts_unresolved, array() );
diviops_call_ref( 'collect_parser_move_blocks', $move_args_unresolved );

assert_same(
	'global-layout',
	$move_modules_unresolved[0]['type'],
	'a wrapper with no blockName attr falls back to counting as global-layout'
);
assert_same(
	'global-layout:1',
	$move_modules_unresolved[0]['auto_index'],
	'a wrapper with no blockName attr falls back to counting as global-layout:1'
);
assert_same(
	'section:1',
	$move_modules_unresolved[1]['auto_index'],
	'without a resolvable blockName the following real section is not shifted'
);

// Third-party modules nested under a real section, alongside the wrapper: global-
// layout resolution must not perturb an unrelated namespaced counter, for either
// collector.
$mixed_tree_blocks = array(
	array(
		'blockName'    => 'divi/global-layout',
		'attrs'        => array(
			'globalModule'   => '900296',
			'blockName'      => 'divi/section',
			'builderVersion' => '5.9.0',
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array( 'builderVersion' => '5.9.0' ),
		'innerContent' => array( null ),
		'innerBlocks'  => array(
			array(
				'blockName'    => 'difl/faq',
				'attrs'        => array( 'builderVersion' => '5.9.0' ),
				'innerBlocks'  => array(),
				'innerContent' => array(),
			),
		),
	),
);

$mixed_flat_modules = array();
$mixed_type_counts  = array();
$mixed_args         = array( $mixed_tree_blocks, &$mixed_flat_modules, &$mixed_type_counts );
diviops_call_ref( 'collect_readable_divi_blocks', $mixed_args );

assert_same( 'section:1', $mixed_flat_modules[0]['auto_index'], 'the wrapper still resolves to section:1 alongside a third-party descendant' );
assert_same( 'section:2', $mixed_flat_modules[1]['auto_index'], 'the real section is still section:2' );
assert_same(
	'difl/faq:1',
	$mixed_flat_modules[2]['auto_index'],
	'the nested third-party module keeps its own namespaced counter, unperturbed by the wrapper'
);
assert_same(
	array(
		'section'   => 2,
		'difl/faq'  => 1,
	),
	$mixed_type_counts,
	'global-layout resolution does not perturb the third-party counter'
);

$mixed_move_modules = array();
$mixed_move_counts  = array();
$mixed_move_args    = array( $mixed_tree_blocks, &$mixed_move_modules, &$mixed_move_counts, array() );
diviops_call_ref( 'collect_parser_move_blocks', $mixed_move_args );

assert_same( 'section:1', $mixed_move_modules[0]['auto_index'], 'the wrapper still resolves to section:1 alongside a third-party descendant' );
assert_same( 'section:2', $mixed_move_modules[1]['auto_index'], 'the real section is still section:2' );
assert_same(
	'difl/faq:1',
	$mixed_move_modules[2]['auto_index'],
	'the nested third-party module keeps its own namespaced counter, unperturbed by the wrapper'
);
assert_same(
	array(
		'section'   => 2,
		'difl/faq'  => 1,
	),
	$mixed_move_counts,
	'global-layout resolution does not perturb the third-party counter'
);
