<?php
/**
 * global_layout_wrapper_drift() — the Layer 2 backstop for #11.
 *
 * module_lock/unlock/clone, module_move's parser fallback, tb_layout_block_insert,
 * preset_reassign, normalize_and_validate_divi_markup_before_write, and
 * validate_blocks all round-trip or walk page content through parse_blocks() ->
 * mutate -> serialize_blocks() (the latter two validate-only, no serialize, but
 * the same materialization risk applies to what they walk). On a page carrying
 * a divi/global-layout wrapper, Divi's own block parser expands that wrapper
 * into the resolved content of the layout it references unless a skip condition
 * holds. One of those conditions is _is_rest_update_request() -- confirmed by
 * reading the real method on the reference Divi install to be
 * Conditions::is_rest_api_request() plus a REQUEST_METHOD check, not the fragile
 * $_SERVER['REQUEST_URI'] string match an earlier version of this comment
 * claimed -- which reliably holds for genuine REST dispatch but fails open for
 * wp eval/CLI invocation (#99). parse_blocks_for_write() (trait-core.php) is
 * Layer 1 — it makes the skip unconditional by routing through Divi's own
 * save-context parser when available. global_layout_wrapper_drift() is Layer 2 —
 * the backstop for when Layer 1 could not apply (Divi's class absent) and the
 * wrapper got expanded anyway: it detects the loss before the write persists,
 * so update_post_content_with_integrity_guard() and preset_reassign() can
 * refuse the write instead of silently detaching the page from its referenced
 * global layout.
 *
 * global_layout_wrapper_drift() is identity-aware (compares each wrapper's
 * globalModule id, not just an overall opener count) and fails CLOSED: either
 * side's scan being unreliable (a malformed/unterminated block comment
 * anywhere in the document) is itself treated as drift, refusing the write
 * rather than risking a masked loss. Both properties were added after
 * adversarial review of the first version of this fix found a count-only
 * comparison misses an id-swap (F3) and a count that silently truncates on
 * malformed markup can mask a real removal (F4).
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

// ── (a) a wrapper is fully expanded away → drift true ────────────────────

$original_a   = $real_wrapper . $plain_section;
$serialized_a = $expanded_in_place . $plain_section;
assert_true(
	diviops_call( 'global_layout_wrapper_drift', array( $original_a, $serialized_a ) ),
	'drift is true when the only wrapper on the page is expanded away'
);

// ── (b) benign reserialize with the SAME globalModule id, attrs reordered
// → drift false. Also stands in for the "same ids preserved through a
// benign reserialize" case from adversarial review. ─────────────────────

$original_b   = '<!-- wp:divi/global-layout {"globalModule":"900296","blockName":"divi/section"} /-->';
$serialized_b = '<!-- wp:divi/global-layout {"blockName":"divi/section","globalModule":"900296"} /-->';
assert_true(
	! diviops_call( 'global_layout_wrapper_drift', array( $original_b, $serialized_b ) ),
	'no drift when the wrapper survives a benign reserialize with reordered attrs and the same globalModule id'
);

// ── (c) neither side has a wrapper → drift false ─────────────────────────

assert_true(
	! diviops_call( 'global_layout_wrapper_drift', array( $plain_section, $plain_section ) ),
	'no drift when neither original nor serialized content has a wrapper'
);

// ── (d) two wrappers present originally, one lost → drift true ──────────

$original_d   = $real_wrapper . $real_wrapper_second;
$serialized_d = $real_wrapper . $expanded_in_place;
assert_true(
	diviops_call( 'global_layout_wrapper_drift', array( $original_d, $serialized_d ) ),
	'drift is true when one of two wrappers is lost, even though the other survives'
);

// ── (e) IDENTITY-AWARE: a wrapper survives (opener count unchanged) but its
// globalModule id changed → drift true. Divi's non-recursive expansion can
// swap layout A for layout B in exactly this shape for nested/chained
// global layouts — a count-only comparison would miss that reference A is
// gone. This replaces an earlier version of this suite that asserted the
// opposite (count-only) outcome for this exact scenario; that assertion was
// wrong and adversarial review caught it (F3). ──────────────────────────

$original_idswap   = '<!-- wp:divi/global-layout {"globalModule":"100","blockName":"divi/section"} /-->';
$serialized_idswap = '<!-- wp:divi/global-layout {"globalModule":"200","blockName":"divi/section"} /-->';
assert_true(
	diviops_call( 'global_layout_wrapper_drift', array( $original_idswap, $serialized_idswap ) ),
	'drift is true when a wrapper is replaced by a different one — the opener count is unchanged but globalModule id 100 is gone'
);

// Mutation/behavior-coupling proof: a naive COUNT-only comparison (how many
// wrapper openers appear on each side, not which ids they carry) would see
// one wrapper before and one wrapper after and call that "no drift" — it is
// blind to the id swap. Asserting both the naive count's verdict AND the real
// verdict pins identity-awareness as load-bearing rather than incidental,
// mirroring the decoy proof below for the JSON-aware scan.
$naive_count_idswap_before = count( diviops_call( 'global_layout_wrapper_identities', array( $original_idswap ) ) );
$naive_count_idswap_after  = count( diviops_call( 'global_layout_wrapper_identities', array( $serialized_idswap ) ) );
assert_true(
	$naive_count_idswap_before === $naive_count_idswap_after,
	'sanity check on the fixture: a naive count-only comparison sees the same wrapper count on both sides and would wrongly report no drift'
);

// ── (f) FAIL CLOSED: an unterminated opener ahead of a removed wrapper is
// refused rather than silently masked ────────────────────────────────────
//
// The opening comment below never closes: its JSON attrs contain an
// unterminated string value (no closing quote, no closing brace, no -->
// anywhere afterward). block_opening_comment_end() must scan to the end of
// the document without finding a real terminator and report the scan as
// unreliable. Anything appended after this point is unreachable either way
// — which is exactly the hazard: a naive implementation that counts only
// wrappers found BEFORE the point it gives up would report the same count
// (0) whether or not a real wrapper existed further down, silently masking
// its removal. The fix must refuse both sides rather than compare partial
// counts that happen to match.
$malformed_prefix = '<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Unterminated';

$original_malformed_with_wrapper    = $malformed_prefix . $real_wrapper;
$serialized_malformed_wrapper_gone  = $malformed_prefix . $plain_section;
assert_true(
	diviops_call( 'global_layout_wrapper_drift', array( $original_malformed_with_wrapper, $serialized_malformed_wrapper_gone ) ),
	'fail closed: an unterminated opener ahead of a removed wrapper is refused rather than silently masked'
);

// Mutation/behavior-coupling proof: a naive implementation that treats an
// unreliable scan (null) as "no drift" instead of refusing would compare two
// empty results — both scans give up at the same unterminated opener before
// ever reaching the wrapper — and wrongly ALLOW this write. Asserting both
// the naive verdict AND the real fail-closed verdict pins "null scan is
// itself drift" as load-bearing rather than incidental.
$naive_before_f     = diviops_call( 'global_layout_wrapper_identities', array( $original_malformed_with_wrapper ) );
$naive_after_f      = diviops_call( 'global_layout_wrapper_identities', array( $serialized_malformed_wrapper_gone ) );
$naive_allows_write = ( null === $naive_before_f || null === $naive_after_f )
	? true // naive: an unreliable scan defaults to "no drift", the opposite of fail-closed
	: array() === array_diff( $naive_before_f, $naive_after_f );
assert_true(
	$naive_allows_write,
	'sanity check on the fixture: a naive "unscannable = no drift" comparison would have wrongly allowed this write'
);

// ── (g) FAIL CLOSED, documented tradeoff: malformed markup but nothing was
// actually removed (original and serialized are byte-identical) still
// refuses ──────────────────────────────────────────────────────────────
//
// This is a deliberate, accepted over-refusal: an unreliable scan cannot
// prove that nothing was lost, so it does not get to claim "no drift" by
// default. The alternative — treating an unreadable scan as safe — is
// exactly the fail-open failure mode this fix exists to close.
assert_true(
	diviops_call( 'global_layout_wrapper_drift', array( $malformed_prefix, $malformed_prefix ) ),
	'documented tradeoff: fail-closed refuses even when original and serialized are identical, because malformed markup cannot be scanned reliably enough to prove nothing was lost'
);

// ── (h) a `<!-- wp:divi/global-layout` substring inside another block's own
// JSON attribute value (not a real block boundary) must not be miscounted.
// The decoy text sits inside divi/section's own adminLabel string, with its
// JSON quotes escaped exactly as WordPress attribute serialization requires.

$decoy_section =
	'<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Fake: <!-- wp:divi/global-layout {\"globalModule\":\"999999\"} /--> embedded"}}}}} -->'
	. '<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->'
	. '<!-- /wp:divi/section -->';

$original_h   = $real_wrapper . $decoy_section;
$serialized_h = $real_wrapper . $decoy_section;
assert_true(
	! diviops_call( 'global_layout_wrapper_drift', array( $original_h, $serialized_h ) ),
	'no drift when content is unchanged, including a decoy string shaped like a wrapper opener'
);

// The JSON-aware scan must find exactly the one real wrapper's identity, not
// the decoy text embedded in the section's own attribute JSON.
$identities_h = diviops_call( 'global_layout_wrapper_identities', array( $original_h ) );
assert_same(
	array( '900296' ),
	$identities_h,
	'the JSON-aware scan reads only the real wrapper\'s globalModule id, not the embedded decoy text'
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
$serialized_h2 = $real_wrapper;
assert_true(
	! diviops_call( 'global_layout_wrapper_drift', array( $original_h, $serialized_h2 ) ),
	'no drift when only the decoy section (and its embedded lookalike text) is removed — the real wrapper survives untouched'
);
$naive_before = substr_count( $original_h, '<!-- wp:divi/global-layout' );
$naive_after  = substr_count( $serialized_h2, '<!-- wp:divi/global-layout' );
assert_true(
	$naive_before > $naive_after,
	'sanity check on the fixture: a naive substr_count comparison would have seen a drop here and wrongly flagged drift'
);

// ── (i) global_layout_write_refusal_reason() distinguishes WHY a write is
// refused: an actual identity loss vs an unreliable scan (#23). Both reasons
// still refuse the write (fail-closed behavior is unchanged) — only the
// diagnostic differs, so a caller can tell "your markup is malformed, I
// couldn't verify this" apart from "this write would have detached a global
// layout". global_layout_wrapper_drift() stays a thin bool wrapper around
// this so trait-preset.php's direct caller is unaffected.

assert_same(
	null,
	diviops_call( 'global_layout_write_refusal_reason', array( $plain_section, $plain_section ) ),
	'refusal reason is null (write allowed) when neither side has a wrapper and both scan reliably'
);

assert_same(
	'identity_lost',
	diviops_call( 'global_layout_write_refusal_reason', array( $original_a, $serialized_a ) ),
	'refusal reason is identity_lost when a wrapper id present in the original is missing from the serialized output'
);

assert_same(
	'scan_unreliable',
	diviops_call( 'global_layout_write_refusal_reason', array( $original_malformed_with_wrapper, $serialized_malformed_wrapper_gone ) ),
	'refusal reason is scan_unreliable when the content cannot be scanned reliably, even though a wrapper is also present'
);

assert_same(
	'scan_unreliable',
	diviops_call( 'global_layout_write_refusal_reason', array( $malformed_prefix, $plain_section ) ),
	'refusal reason is scan_unreliable, not identity_lost, on a page with NO wrapper at all whose markup cannot be scanned reliably — the false-attribution case #23 fixes'
);

// ── (j) update_post_content_with_integrity_guard() surfaces a distinct error
// code and message per refusal reason (#23). Both fixtures pass the earlier
// preflight balance check (assert_divi_full_content_safe_for_write() only
// inspects the NEW content being written, never $previous_content), so each
// call reaches the drift branch under test.

$identity_lost_result = diviops_call(
	'update_post_content_with_integrity_guard',
	array( 123, $serialized_a, 'test_ns', 'test target', $original_a, true )
);
assert_true(
	is_wp_error( $identity_lost_result ),
	'update_post_content_with_integrity_guard refuses a write that actually lost a wrapper identity'
);
assert_same(
	'test_ns.global_layout_drift',
	$identity_lost_result->get_error_code(),
	'identity_lost keeps the existing global_layout_drift error code'
);
assert_true(
	false !== strpos( $identity_lost_result->get_error_message(), "materialize a divi/global-layout wrapper's resolved content" ),
	'identity_lost keeps the existing wrapper-materialization message, which is accurate here'
);

$scan_unreliable_result = diviops_call(
	'update_post_content_with_integrity_guard',
	array( 456, $plain_section, 'test_ns', 'test target', $malformed_prefix, true )
);
assert_true(
	is_wp_error( $scan_unreliable_result ),
	'update_post_content_with_integrity_guard refuses a write when the original content cannot be scanned reliably'
);
assert_same(
	'test_ns.global_layout_scan_unreliable',
	$scan_unreliable_result->get_error_code(),
	'scan_unreliable gets its own distinct error code, separate from an actual identity loss'
);
assert_true(
	false === strpos( $scan_unreliable_result->get_error_message(), 'materialize' ),
	'scan_unreliable message does not claim a wrapper would be materialized — nothing was necessarily lost, the scan just could not verify it'
);
