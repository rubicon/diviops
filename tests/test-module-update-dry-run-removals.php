<?php
// SPDX-License-Identifier: MIT
/**
 * `module_update` dry_run must surface removals, not only survivors (#219).
 *
 * Before #207 the plan's `after` block echoed the submitted keys; the field
 * reporter read that as a diff, proceeded, and lost three sibling attributes on a
 * live page. #207 made `after` read back out of the mutated tree, which fixed the
 * accuracy of what SURVIVES — and left the inverse hole: a plan can show what a
 * path will contain, but nothing in it can express what a path will stop
 * containing.
 *
 * Two removals stay reachable through the merge semantics, both caller-intended
 * and both invisible in the old plan shape:
 *
 *   - explicit null — `{"a.b.c": null}` assigns null, which is how a targeted
 *     reset is spelled;
 *   - list replacement — a list value replaces wholesale rather than merging
 *     index-wise (deliberate; see merge_module_attr_value()), so replacing
 *     ["class-a","class-b"] with ["class-c"] drops two entries.
 *
 * A destructive payload therefore previewed identically to a safe one, which is
 * the whole failure mode dry_run exists to prevent. These tests pin that a plan
 * entry carries a `removed` list, that it names both dropped list entries rather
 * than only the trailing index, that a null assignment reports as a removal
 * rather than as a plain value change, and that the list is always present so a
 * skimming reader can tell "nothing is removed" from "removals are not reported".
 *
 * REPORTING ONLY. Every assertion below drives the same three calls the handler
 * makes — read before, apply, read after — through the real
 * apply_module_attr_updates(), so the merge semantics these tests observe are the
 * merge semantics the write path uses. Nothing here asks the applier to behave
 * differently.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Run one payload through the handler's own three steps and return its plan entry.
 *
 * @param array  $block_attrs Starting attribute tree.
 * @param string $path        The single dot path the payload writes.
 * @param mixed  $value       Value submitted at that path.
 * @return array{entry: array, applied: array} The plan entry plus the mutated tree.
 */
function diviops_test_plan_entry( array $block_attrs, string $path, $value ): array {
	$before  = diviops_call( 'read_module_attr_path', array( $block_attrs, $path ) );
	$applied = diviops_call( 'apply_module_attr_updates', array( $block_attrs, array( $path => $value ) ) );
	$after   = diviops_call( 'read_module_attr_path', array( $applied, $path ) );

	return array(
		'entry'   => diviops_call(
			'module_update_plan_change',
			array( 'page#900/divi/text/hero', $path, $before, $after )
		),
		'applied' => $applied,
	);
}

/* ── List replacement names every dropped entry ─────────────────────────── */

$classes_path = 'module.decoration.attributes.desktop.value.attributes';
$list_tree    = array(
	'module' => array(
		'meta'       => array( 'adminLabel' => 'Hero' ),
		'decoration' => array(
			'attributes' => array(
				'desktop' => array(
					'value' => array(
						'attributes' => array( 'class-a', 'class-b' ),
					),
				),
			),
		),
	),
);

$list_plan  = diviops_test_plan_entry( $list_tree, $classes_path, array( 'class-c' ) );
$list_entry = $list_plan['entry'];

assert_same(
	array( 'class-c' ),
	$list_entry['after'],
	'list replacement: `after` reports the surviving list'
);
assert_same(
	array(
		array( 'path' => $classes_path, 'value' => 'class-a' ),
		array( 'path' => $classes_path, 'value' => 'class-b' ),
	),
	$list_entry['removed'],
	'list replacement: the plan names BOTH dropped entries, not just the trailing index'
);

/* ── Explicit null is a removal, not a value change ─────────────────────── */

$color_path = 'module.decoration.background.desktop.value.color';
$color_tree = array(
	'module' => array(
		'decoration' => array(
			'background' => array(
				'desktop' => array(
					'value' => array( 'color' => '#ff0000' ),
				),
			),
		),
	),
);

$null_entry = diviops_test_plan_entry( $color_tree, $color_path, null )['entry'];

assert_same(
	null,
	$null_entry['after'],
	'explicit null: `after` is null, which on its own reads as an ordinary value change'
);
assert_same(
	array( array( 'path' => $color_path, 'value' => '#ff0000' ) ),
	$null_entry['removed'],
	'explicit null: the plan reports the cleared value as a removal, naming what is lost'
);

/* ── Nulling a subtree enumerates the leaves that disappear ─────────────── */

$subtree_entry = diviops_test_plan_entry(
	$color_tree,
	'module.decoration.background.desktop.value',
	null
)['entry'];

assert_same(
	array( array( 'path' => 'module.decoration.background.desktop.value.color', 'value' => '#ff0000' ) ),
	$subtree_entry['removed'],
	'subtree reset: removals name the leaf paths under the cleared branch, not the branch alone'
);

/* ── A safe payload must preview DIFFERENTLY from a destructive one ─────── */

$merge_entry = diviops_test_plan_entry(
	$list_tree,
	'module.meta',
	array( 'moduleName' => 'Hero section' )
)['entry'];

assert_same(
	array( 'adminLabel' => 'Hero', 'moduleName' => 'Hero section' ),
	$merge_entry['after'],
	'merging payload: `after` reports the merged map, siblings intact'
);
assert_same(
	array(),
	$merge_entry['removed'],
	'merging payload: `removed` is empty — the plan distinguishes a safe write from a destructive one'
);
assert_true(
	array_key_exists( 'removed', $merge_entry ),
	'`removed` is always present, so an empty list cannot be misread as an unreported one'
);

/* ── A key vanishing from a merged map is still a removal ───────────────── */

$replaced_entry = diviops_test_plan_entry(
	$color_tree,
	'module.decoration.background.desktop',
	array( 'value' => array( 'color' => null ) )
)['entry'];

assert_same(
	array( array( 'path' => 'module.decoration.background.desktop.value.color', 'value' => '#ff0000' ) ),
	$replaced_entry['removed'],
	'nested null inside an object payload: the cleared leaf is reported through the merge'
);

/* ── Duplicate list entries are diffed as a multiset, not as membership ── */

/*
 * Codex review of PR #295 caught this: a plain `in_array()` membership test finds
 * the SURVIVING copy of a duplicated entry and reports nothing removed, even though
 * the wholesale list replacement dropped one. That is the same under-reporting this
 * whole issue exists to close, narrowed to duplicate values — so entries are matched
 * off one-for-one instead.
 */
$dup_tree = array(
	'module' => array(
		'decoration' => array(
			'attributes' => array(
				'desktop' => array(
					'value' => array(
						'attributes' => array( 'class-a', 'class-a', 'class-b' ),
					),
				),
			),
		),
	),
);

$dup_entry = diviops_test_plan_entry( $dup_tree, $classes_path, array( 'class-a', 'class-b' ) )['entry'];

assert_same(
	array( array( 'path' => $classes_path, 'value' => 'class-a' ) ),
	$dup_entry['removed'],
	'duplicate entries: dropping one of two identical entries is reported once, not swallowed by the survivor'
);

$dup_kept_entry = diviops_test_plan_entry(
	$dup_tree,
	$classes_path,
	array( 'class-a', 'class-a', 'class-b' )
)['entry'];

assert_same(
	array(),
	$dup_kept_entry['removed'],
	'duplicate entries: a replacement that keeps both copies reports no removal'
);

/* ── Reporting change only: the applied tree is untouched by any of this ── */

assert_same(
	'Hero',
	$list_plan['applied']['module']['meta']['adminLabel'],
	'write semantics unchanged: the list replacement still leaves sibling attrs alone'
);
assert_same(
	array( 'class-c' ),
	$list_plan['applied']['module']['decoration']['attributes']['desktop']['value']['attributes'],
	'write semantics unchanged: lists still replace wholesale rather than merging index-wise'
);
