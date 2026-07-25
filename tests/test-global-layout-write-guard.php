<?php
/**
 * global_layout_wrapper_drift() — the Layer 2 backstop for #11.
 *
 * module_lock/unlock/clone, module_move's parser fallback, tb_layout_block_insert,
 * and preset_reassign all round-trip page content through parse_blocks() ->
 * mutate -> serialize_blocks(). On a page carrying a divi/global-layout wrapper,
 * Divi's own block parser expands that wrapper into the resolved content of the
 * layout it references unless a skip condition holds. One of those conditions is
 * a fragile $_SERVER['REQUEST_URI'] string match that fails open outside a
 * genuine REST dispatch (e.g. wp eval). parse_blocks_for_write() (trait-core.php)
 * is Layer 1 — it makes the skip unconditional by routing through Divi's own
 * save-context parser when available. global_layout_wrapper_drift() is Layer 2 —
 * the backstop for when Layer 1 could not apply (Divi's class absent) and the
 * wrapper got expanded anyway: it detects the loss before the write persists,
 * so update_post_content_with_integrity_guard() and preset_reassign() can
 * refuse the write instead of silently detaching the page from its referenced
 * global layout.
 *
 * These tests cover the pure detection helper only. The full module_lock/clone
 * etc. round-trip cannot be exercised in this harness: it calls parse_blocks(),
 * which is deliberately unshimmed (same wall issue #17 hit), so this suite does
 * not fake that behavior. Layer 1 and the wired call sites are verified live,
 * read-only, against the reference Divi install instead (see FORK.md / the #11
 * PR description).
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$real_wrapper = '<!-- wp:divi/global-layout {"globalModule":"900296","blockName":"divi/section","builderVersion":"5.9.0"} /-->';

$real_wrapper_second = '<!-- wp:divi/global-layout {"globalModule":"900297","blockName":"divi/section","builderVersion":"5.9.0"} /-->';

// What $real_wrapper resolves to once Divi's parser expands it: the same
// section content, but with no divi/global-layout opener of its own left in
// the markup at all — this is what a failed-open expansion writes back.
$expanded_in_place =
	'<!-- wp:divi/section {"builderVersion":"5.9.0"} -->'
	. '<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->'
	. '<!-- /wp:divi/section -->';

$plain_section =
	'<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Plain Section"}}}}} -->'
	. '<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->'
	. '<!-- /wp:divi/section -->';

// (a) — original has a wrapper, serialized lost it → drift true.
$original_a   = $real_wrapper . $plain_section;
$serialized_a = $expanded_in_place . $plain_section;
assert_true(
	diviops_call( 'global_layout_wrapper_drift', array( $original_a, $serialized_a ) ),
	'drift is true when the only wrapper on the page is expanded away'
);

// (b) — both retain the wrapper, only attr key order changed (benign
// reserialize) → false.
$original_b   = '<!-- wp:divi/global-layout {"globalModule":"900296","blockName":"divi/section"} /-->';
$serialized_b = '<!-- wp:divi/global-layout {"blockName":"divi/section","globalModule":"900296"} /-->';
assert_true(
	! diviops_call( 'global_layout_wrapper_drift', array( $original_b, $serialized_b ) ),
	'no drift when the wrapper survives a benign reserialize with reordered attrs'
);

// (c) — neither side has a wrapper → false.
assert_true(
	! diviops_call( 'global_layout_wrapper_drift', array( $plain_section, $plain_section ) ),
	'no drift when neither original nor serialized content has a wrapper'
);

// (d) — two wrappers present originally, one lost → true.
$original_d   = $real_wrapper . $real_wrapper_second;
$serialized_d = $real_wrapper . $expanded_in_place;
assert_true(
	diviops_call( 'global_layout_wrapper_drift', array( $original_d, $serialized_d ) ),
	'drift is true when one of two wrappers is lost, even though the other survives'
);

// (e) — the wrapper survives but now references a different globalModule id
// (a legitimate content edit, not expansion) → false. Drift is about the
// wrapper's presence, not which layout it points at.
$original_e   = '<!-- wp:divi/global-layout {"globalModule":"900296","blockName":"divi/section"} /-->';
$serialized_e = '<!-- wp:divi/global-layout {"globalModule":"900299","blockName":"divi/section"} /-->';
assert_true(
	! diviops_call( 'global_layout_wrapper_drift', array( $original_e, $serialized_e ) ),
	'no drift when the wrapper is preserved but its globalModule id changed'
);

// (f) — a `<!-- wp:divi/global-layout` substring inside another block's own
// JSON attribute value (not a real block boundary) must not be miscounted.
// The decoy text sits inside divi/section's own adminLabel string, with its
// JSON quotes escaped exactly as WordPress attribute serialization requires.
$decoy_section =
	'<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Fake: <!-- wp:divi/global-layout {\"globalModule\":\"999999\"} /--> embedded"}}}}} -->'
	. '<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->'
	. '<!-- /wp:divi/section -->';

$original_f   = $real_wrapper . $decoy_section;
$serialized_f = $real_wrapper . $decoy_section;
assert_true(
	! diviops_call( 'global_layout_wrapper_drift', array( $original_f, $serialized_f ) ),
	'no drift when content is unchanged, including a decoy string shaped like a wrapper opener'
);

// The JSON-aware scan must count exactly the one real wrapper, not the decoy
// text embedded in the section's own attribute JSON.
assert_same(
	1,
	diviops_call( 'count_global_layout_wrapper_openers', array( $original_f ) ),
	'the JSON-aware scan counts only the real wrapper, not the embedded decoy text'
);

// Mutation/behavior-coupling proof: removing the decoy section entirely (its
// embedded lookalike text goes with it) must NOT read as drift, because the
// real wrapper is untouched — only decoy data disappeared, not a block. A
// naive substr_count-based reimplementation would get this wrong: raw
// occurrences of the literal string drop from 2 (real wrapper + embedded
// decoy) to 1 (real wrapper only), which a byte-count comparison would
// misreport as a lost wrapper. Asserting both the correct answer AND the
// naive count it would be compared against pins the JSON-aware scan as
// load-bearing, not incidental.
$serialized_f2 = $real_wrapper;
assert_true(
	! diviops_call( 'global_layout_wrapper_drift', array( $original_f, $serialized_f2 ) ),
	'no drift when only the decoy section (and its embedded lookalike text) is removed — the real wrapper survives untouched'
);
$naive_before = substr_count( $original_f, '<!-- wp:divi/global-layout' );
$naive_after  = substr_count( $serialized_f2, '<!-- wp:divi/global-layout' );
assert_true(
	$naive_before > $naive_after,
	'sanity check on the fixture: a naive substr_count comparison would have seen a drop here and wrongly flagged drift'
);
