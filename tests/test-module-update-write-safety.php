<?php
/**
 * `module_update` write safety (#206) — two independent defects on one call path.
 *
 * DEFECT 1 — non-canonical re-encode trips the integrity guard.
 *
 * `module_update` rebuilt the block comment with a plain
 * `wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )` and
 * spliced it straight into `post_content`. Raw `<`, `>`, `&`, `"` therefore
 * survived into the attr JSON. WordPress re-serializes on save through
 * `serialize_blocks()` -> `serialize_block_attributes()`, which hex-escapes exactly
 * those bytes, so the integrity guard compared its own non-canonical expectation
 * against WP's canonical storage, read the difference as corruption, and reverted a
 * write that was correct.
 *
 * That made the tool unusable on most real Divi 5 modules, because `$variable()`
 * design tokens carry `"` and prose carries HTML — and, as the sharpest reported
 * repro showed, the offending bytes need not be in the attribute being edited at
 * all. Re-encoding the whole block comment drags every sibling attribute through
 * the same encoder.
 *
 * Every other write path was immune because it runs
 * `normalize_divi_full_content_for_write()` first (trait-page.php 208/1097/1245/
 * 1374/1504, trait-theme-builder.php 1065/1298/1419). `module_update` was the sole
 * omission. These tests pin the property that asymmetry violated: canonical content
 * is a FIXED POINT of the normalizer, and plain-encoded content is not.
 *
 * DEFECT 2 — nested-object attrs silently replaced whole top-level keys.
 *
 * The applier treated every `$attrs` key as a dot path. A nested object arrives
 * under a dotless key, so `split_dot_path( 'module' )` returned `['module']`, the
 * loop hit its leaf branch immediately, and assigned over the entire subtree —
 * destroying `module.meta.adminLabel`, `module.decoration.layout`, and
 * `module.decoration.spacing` while reporting success.
 *
 * The merge is deliberately NOT `_deep_merge()` (trait-core.php:1822), despite that
 * helper existing and looking like the obvious reuse. It merges by key with no list
 * awareness, so overriding `["class-a","class-b"]` with `["class-c"]` yields
 * `["class-c","class-b"]` — silently wrong for every list-valued attr, including the
 * CSS-classes array at `module.decoration.attributes.desktop.value.attributes`.
 * Lists must replace wholesale; only associative maps merge. That distinction is
 * asserted below, because it is invisible until a list-valued attr is edited.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/* ── Defect 1: canonical serialization is the normalizer's fixed point ── */

// Attrs carrying every byte class core hex-escapes: `<`, `>`, `&`, and the `"`
// that every $variable() design token embeds.
$html_attrs = array(
	'module'         => array(
		'innerContent' => array(
			'desktop' => array(
				'value' => '<a href="/x?a=1&b=2">link</a>',
			),
		),
	),
	'builderVersion' => '5.1.1',
);

$plain_json = wp_json_encode( $html_attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
$canonical  = diviops_call( 'serialize_block_attrs_canonical', array( json_decode( (string) $plain_json ) ) );

assert_true(
	is_string( $plain_json ) && '' !== $plain_json,
	'fixture sanity: the plain encoder produced JSON'
);
assert_true(
	is_string( $canonical ) && '' !== $canonical,
	'serialize_block_attrs_canonical() returns a string for ordinary attrs'
);

// The bug in one assertion: what module_update wrote is not what WordPress stores.
assert_true(
	$plain_json !== $canonical,
	'plain wp_json_encode output differs from canonical serialization when attrs carry HTML-ish bytes'
);
assert_true(
	false !== strpos( (string) $plain_json, '<' ),
	'the plain encoder leaves a raw `<` in the attr JSON — the byte WordPress will hex-escape on save'
);
assert_true(
	false === strpos( (string) $canonical, '<' ),
	'canonical serialization leaves no raw `<` for WordPress to rewrite'
);

$plain_block     = '<!-- wp:divi/text ' . $plain_json . ' /-->';
$canonical_block = '<!-- wp:divi/text ' . $canonical . ' /-->';

$normalized_plain = diviops_call( 'normalize_divi_full_content_for_write', array( $plain_block ) );
assert_true(
	! empty( $normalized_plain['ok'] ),
	'the normalizer accepts a plain-encoded Divi block'
);
// This is precisely the drift the integrity guard reported as corruption.
assert_true(
	$normalized_plain['content'] !== $plain_block,
	'plain-encoded content is NOT a fixed point of the normalizer — this is the guard-tripping condition'
);

$normalized_canonical = diviops_call( 'normalize_divi_full_content_for_write', array( $canonical_block ) );
assert_true(
	! empty( $normalized_canonical['ok'] ),
	'the normalizer accepts a canonically-encoded Divi block'
);
// The property the fix depends on: normalizing already-canonical content is a no-op,
// so routing module_update through the normalizer cannot itself introduce drift.
assert_same(
	$canonical_block,
	$normalized_canonical['content'],
	'canonically-encoded content IS a fixed point of the normalizer (idempotent)'
);

// Empty objects must survive as `{}` — `[]` would change Divi's semantics. This is
// why restore_empty_objects() has to keep running before serialization.
$empty_obj_json  = '{"module":{"decoration":{}},"builderVersion":"5.1.1"}';
$empty_obj_block = '<!-- wp:divi/text ' . $empty_obj_json . ' /-->';
$normalized_empty = diviops_call( 'normalize_divi_full_content_for_write', array( $empty_obj_block ) );
assert_true(
	! empty( $normalized_empty['ok'] )
		&& false !== strpos( $normalized_empty['content'], '"decoration":{}' ),
	'an empty object stays `{}` through normalization and does not degrade to `[]`'
);

/* ── Defect 2: attr application preserves untouched siblings ── */

// A module shaped like the reported casualty: the edit targets sizing, while
// adminLabel / layout / spacing sit alongside it and must survive.
$base_attrs = array(
	'module'         => array(
		'meta'       => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Hero Group' ) ) ),
		'decoration' => array(
			'layout'  => array( 'desktop' => array( 'value' => array( 'display' => 'flex' ) ) ),
			'spacing' => array( 'desktop' => array( 'value' => array( 'padding' => '2rem' ) ) ),
			'sizing'  => array( 'desktop' => array( 'value' => array( 'flexType' => '18_24' ) ) ),
		),
	),
	'builderVersion' => '5.1.1',
);

// The dot-path form was always correct. Pin it so the fix cannot regress it.
$dot_result = diviops_call(
	'apply_module_attr_updates',
	array( $base_attrs, array( 'module.decoration.sizing.desktop.value.flexType' => '3_5' ) )
);
assert_same(
	'3_5',
	$dot_result['module']['decoration']['sizing']['desktop']['value']['flexType'],
	'dot-path form writes the targeted leaf'
);
assert_same(
	'Hero Group',
	$dot_result['module']['meta']['adminLabel']['desktop']['value'],
	'dot-path form leaves adminLabel intact'
);
assert_same(
	'2rem',
	$dot_result['module']['decoration']['spacing']['desktop']['value']['padding'],
	'dot-path form leaves sibling spacing intact'
);

// The defect: the same edit expressed as a nested object destroyed three siblings.
$nested_result = diviops_call(
	'apply_module_attr_updates',
	array(
		$base_attrs,
		array(
			'module' => array(
				'decoration' => array(
					'sizing' => array( 'desktop' => array( 'value' => array( 'flexType' => '3_5' ) ) ),
				),
			),
		),
	)
);
assert_same(
	'3_5',
	$nested_result['module']['decoration']['sizing']['desktop']['value']['flexType'],
	'nested-object form writes the targeted leaf'
);
assert_same(
	'Hero Group',
	$nested_result['module']['meta']['adminLabel']['desktop']['value'],
	'nested-object form preserves adminLabel — the key whose loss made the module unfindable by label'
);
assert_same(
	'flex',
	$nested_result['module']['decoration']['layout']['desktop']['value']['display'],
	'nested-object form preserves sibling layout'
);
assert_same(
	'2rem',
	$nested_result['module']['decoration']['spacing']['desktop']['value']['padding'],
	'nested-object form preserves sibling spacing'
);
assert_same(
	'5.1.1',
	$nested_result['builderVersion'],
	'nested-object form preserves untouched top-level siblings'
);

// Lists replace wholesale. _deep_merge() would have produced
// ["new-class","keep-me"] by merging index-wise — wrong, and invisible until a
// list-valued attr is edited.
$list_base = array(
	'module' => array(
		'decoration' => array(
			'attributes' => array( 'desktop' => array( 'value' => array( 'attributes' => array( 'old-a', 'old-b' ) ) ) ),
		),
	),
);
$list_result = diviops_call(
	'apply_module_attr_updates',
	array(
		$list_base,
		array(
			'module' => array(
				'decoration' => array(
					'attributes' => array( 'desktop' => array( 'value' => array( 'attributes' => array( 'new-only' ) ) ) ),
				),
			),
		),
	)
);
assert_same(
	array( 'new-only' ),
	$list_result['module']['decoration']['attributes']['desktop']['value']['attributes'],
	'a list value REPLACES wholesale rather than merging index-wise'
);

// Scalars at a dotless key still assign — merging only applies to maps.
$scalar_result = diviops_call(
	'apply_module_attr_updates',
	array( $base_attrs, array( 'builderVersion' => '5.11.0' ) )
);
assert_same(
	'5.11.0',
	$scalar_result['builderVersion'],
	'a scalar at a top-level key still replaces'
);
assert_same(
	'Hero Group',
	$scalar_result['module']['meta']['adminLabel']['desktop']['value'],
	'setting a top-level scalar does not disturb other keys'
);

// null must still assign, or targeted reset/deletion via dot-path stops working.
$null_result = diviops_call(
	'apply_module_attr_updates',
	array( $base_attrs, array( 'module.decoration.spacing.desktop.value.padding' => null ) )
);
assert_true(
	array_key_exists( 'padding', $null_result['module']['decoration']['spacing']['desktop']['value'] )
		&& null === $null_result['module']['decoration']['spacing']['desktop']['value']['padding'],
	'null assigns rather than merging, preserving targeted reset via dot-path'
);

// A dotted path whose value is an object merges too — the same destruction was
// reachable one level down (e.g. `module.decoration` with an object value).
$dotted_obj_result = diviops_call(
	'apply_module_attr_updates',
	array(
		$base_attrs,
		array( 'module.decoration' => array( 'sizing' => array( 'desktop' => array( 'value' => array( 'flexType' => '6_24' ) ) ) ) ),
	)
);
assert_same(
	'6_24',
	$dotted_obj_result['module']['decoration']['sizing']['desktop']['value']['flexType'],
	'a dotted path with an object value writes the nested leaf'
);
assert_same(
	'2rem',
	$dotted_obj_result['module']['decoration']['spacing']['desktop']['value']['padding'],
	'a dotted path with an object value merges rather than replacing its subtree'
);

// Creating a genuinely new branch must still work.
$new_branch = diviops_call(
	'apply_module_attr_updates',
	array( $base_attrs, array( 'module.decoration.border.desktop.value.radius' => '12px' ) )
);
assert_same(
	'12px',
	$new_branch['module']['decoration']['border']['desktop']['value']['radius'],
	'a dot path may still create a branch that did not exist'
);

/* ── The structural invariant, which is what actually prevents recurrence ── */

/*
 * The assertions above pin the BEHAVIOUR of the helpers, but every one of them
 * would still pass if `module_update` itself never called the normalizer — which
 * is exactly the state that shipped. Defect 1 was not a broken function; it was
 * one write path forgetting to call a normalizer that eight others remembered.
 * So the guard that matters is the invariant across paths, not another unit test:
 *
 *   every function that writes post_content through the integrity guard must
 *   first canonicalize that content.
 *
 * Function spans come from token_get_all() brace tracking rather than a regex,
 * because "the enclosing function of this call" is a nesting question and a
 * regex cannot answer it — closures inside a function body would fool any
 * line-based heuristic.
 */
$guard_call   = 'update_post_content_with_integrity_guard';
$includes_dir = dirname( __DIR__ ) . '/plugins/diviops-agent/includes';
$trait_files  = glob( $includes_dir . '/trait-*.php' );

/*
 * "Canonical" means either normalizing the whole document, or building it with
 * WordPress's own serializers — `serialize_blocks()` / `serialize_block()` call
 * `serialize_block_attributes()` internally, so their output already matches what
 * WP stores. Requiring the normalizer specifically would flag those paths as
 * broken when they are correct by construction; that over-strict version of this
 * check produced four false positives on the first run.
 */
$canonical_markers = array(
	'normalize_divi_full_content_for_write(',
	'serialize_blocks(',
	'serialize_block(',
	'serialize_block_attrs_canonical(',
	'save_mutated_blocks(',
);

/*
 * Two paths write content they did not re-encode — they relocate or restore bytes
 * that were already stored. They cannot introduce non-canonical JSON themselves,
 * but they will faithfully re-write non-canonical bytes that an earlier version
 * stored, and the guard will then trip on content they never authored. That is a
 * real but different exposure from #206, with its own open question for
 * rollback_snapshot_restore (byte-exact restore vs canonicalized restore), so it
 * is tracked separately rather than bundled into this fix.
 *
 * This list is a ratchet: a NEW unnormalized write path fails the test, and fixing
 * one of these fails it too, as a prompt to shrink the list.
 */
$known_unnormalized = array(
	'trait-page.php::module_move',
	'trait-rollback.php::rollback_snapshot_restore',
);

assert_true(
	is_array( $trait_files ) && count( $trait_files ) > 0,
	'the trait scan found source files to inspect (a scan of nothing must not pass)'
);

$writers_found  = 0;
$unnormalized   = array();

foreach ( (array) $trait_files as $trait_file ) {
	$tokens = token_get_all( (string) file_get_contents( $trait_file ) );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
			continue;
		}

		// Name the function (skip closures, which have `(` next).
		$name = null;
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				continue;
			}
			if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
				$name = $tokens[ $j ][1];
			}
			break;
		}
		if ( null === $name ) {
			continue;
		}

		// Walk to the body's opening brace, then to its matching close.
		$depth = 0;
		$body  = '';
		$open  = false;
		for ( $k = $i; $k < $count; $k++ ) {
			$text = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
			if ( '{' === $text ) {
				$depth++;
				$open = true;
			} elseif ( '}' === $text ) {
				$depth--;
			}
			if ( $open ) {
				$body .= $text;
			}
			if ( $open && 0 === $depth ) {
				$i = $k;
				break;
			}
		}

		if ( false === strpos( $body, $guard_call . '(' ) ) {
			continue;
		}
		$writers_found++;

		$is_canonical = false;
		foreach ( $canonical_markers as $marker ) {
			if ( false !== strpos( $body, $marker ) ) {
				$is_canonical = true;
				break;
			}
		}
		if ( ! $is_canonical ) {
			$unnormalized[] = basename( $trait_file ) . '::' . $name;
		}
	}
}

sort( $unnormalized );
sort( $known_unnormalized );

// Fail loudly if the scan inspected nothing — a vacuous pass here would hide the
// very regression this test exists to catch.
assert_true(
	$writers_found > 0,
	sprintf( 'the scan actually found functions calling %s() (found %d)', $guard_call, $writers_found )
);
assert_true(
	$writers_found >= 12,
	sprintf( 'the scan reached every known write path, not a truncated subset (found %d, expected >= 12)', $writers_found )
);
assert_same(
	$known_unnormalized,
	$unnormalized,
	'exactly the two known byte-relocating write paths lack canonical serialization — a new one, or a fixed one, must update this list'
);
