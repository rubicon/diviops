<?php
// SPDX-License-Identifier: MIT
/**
 * bulk_find_replace -- the corruption cases are the point (#496).
 *
 * Phase 3 of #38, on the harness phase 2 proved. This file does NOT re-test the
 * harness: the plan token, the cap, the whole-set gate, the loop contract, the
 * manifest and the partial-run envelope are covered by
 * `tests/test-bulk-write-harness.php` and are shared code. What is new here is
 * the replacement engine, and every assertion below is about a way it could
 * corrupt a page.
 *
 * -- The one that matters most ------------------------------------------
 *
 * Replacing `Acme Co` with `Tom's "Best" Deals` by splicing raw bytes into
 * attribute JSON emits `"value":"Tom's "Best" Deals"` -- invalid JSON that
 * EVERY existing guard passes. `divi_content_marker_counts()` counts comment
 * markers, not quotes. `assert_divi_full_content_safe_for_write()` checks marker
 * balance only. `find_malformed_block_attr_escape()` never calls `json_decode`.
 * `global_layout_write_refusal_reason()` decodes only `divi/global-layout`
 * openers. And the readback matches, because WordPress stored exactly what was
 * requested. Divi then parses the block with empty attrs and the module's
 * content is gone -- across up to 25 pages, silently.
 *
 * Section 2 drives exactly that input and requires the round trip to survive it.
 *
 * -- The one an adversarial review found ---------------------------------
 *
 * `extract_attrs_from_block_markup()` decodes with the assoc flag, which turns
 * an empty JSON OBJECT into an empty PHP ARRAY, so re-encoding emits `[]` rather
 * than `{}`. An attribute the caller never named changes type, and again every
 * guard passes: the JSON is valid, the markers are unchanged, the readback
 * matches. Section 3 pins it with an untouched `{}` in the same opener.
 *
 * -- What is NOT covered -------------------------------------------------
 *
 * Whether the replacement is CORRECT. A syntactically valid replacement
 * containing the wrong words is undetectable by every check in this file and in
 * the plugin. That judgement lives in a human reading the plan, which is why the
 * plan reports per-target match counts and why the target cap matters more than
 * it looks.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/bulk-write-harness-stubs.php';

/**
 * Call bulk_find_replace and return the decoded body.
 *
 * @param array $params REST parameters.
 * @return array
 */
function diviops_bfr_call( array $params ): array {
	return diviops_call( 'bulk_find_replace', array( new DiviOps_Test_Request( $params ) ) )->get_data();
}

/**
 * Plan, then apply with the plan's own token.
 *
 * @param array $params REST parameters (dry_run and plan_token are supplied).
 * @return array
 */
function diviops_bfr_apply( array $params ): array {
	$plan = diviops_bfr_call( $params );
	assert_true(
		isset( $plan['data']['plan_token'] ),
		'a dry run returns a plan carrying a token (' . wp_json_encode( $plan['error'] ?? null ) . ')'
	);
	return diviops_bfr_call(
		array_merge( $params, array( 'dry_run' => false, 'plan_token' => (string) ( $plan['data']['plan_token'] ?? '' ) ) )
	);
}

/**
 * A Divi text module whose content attribute carries $text, escaped as core does.
 *
 * Built through the plugin's own canonical serializer rather than by hand: a
 * hand-written fixture pins whatever the author believed the escaping to be, and
 * the assertion then passes against code that shares the same mistake.
 *
 * @param string $text  Text for the content attribute.
 * @param array  $extra Additional top-level attrs, values passed through as-is.
 * @return string
 */
function diviops_bfr_block( string $text, array $extra = array() ): string {
	$attrs = new stdClass();
	foreach ( $extra as $key => $value ) {
		$attrs->$key = $value;
	}
	$attrs->content = (object) array( 'desktop' => (object) array( 'value' => $text ) );
	return '<!-- wp:divi/text ' . diviops_call( 'serialize_block_attrs_canonical', array( $attrs ) ) . ' /-->';
}

/**
 * Register a post the bulk writer will accept.
 *
 * @param int    $id      Post id.
 * @param string $content post_content.
 * @return object
 */
function diviops_bfr_post( int $id, string $content ) {
	$post                    = diviops_test_register_post( $id, $content, 'page', "Page {$id}" );
	$post->post_status       = 'publish';
	$post->post_modified_gmt = '2026-09-01 12:00:00';
	$post->post_date         = '2026-08-01 09:00:00';
	$post->post_date_gmt     = '2026-08-01 14:00:00';
	return $post;
}

function diviops_bfr_reset(): void {
	$GLOBALS['diviops_test_posts']          = array();
	$GLOBALS['diviops_test_uneditable_ids'] = array();
	$GLOBALS['diviops_test_denied_caps']    = array();
	$GLOBALS['diviops_test_transients']     = array();
}

diviops_bfr_reset();

// -- 0. The fixtures carry what this file believes they carry -------------

$bfr_escaped = diviops_bfr_block( 'Acme & Co' );
assert_true(
	false !== strpos( $bfr_escaped, '\u0026' ),
	'the fixture stores & escaped, which is why a body-only replace cannot reach Divi module text'
);
assert_true(
	false === strpos( $bfr_escaped, 'Acme & Co' ),
	'and the literal phrase is absent from the stored bytes'
);

// -- 1. An attribute replacement round-trips through decode/re-encode -----

diviops_bfr_reset();
diviops_bfr_post( 801, $bfr_escaped );

$bfr_plan = diviops_bfr_call( array( 'targets' => array( 801 ), 'search' => 'Acme & Co', 'replace' => 'Beta Ltd' ) );
assert_true( $bfr_plan['ok'], 'a well-formed plan succeeds' );
$bfr_change = $bfr_plan['data']['plan']['changes'][0] ?? array();
assert_same( 'will_apply', $bfr_change['verdict'] ?? null, 'the target will apply' );
assert_same( 1, $bfr_change['matches']['block_attrs'] ?? null, 'the match is reported as a block-attribute match' );
assert_same( 0, $bfr_change['matches']['body'] ?? null, 'and not as a body match, because the literal bytes are not in the body' );
assert_true(
	isset( $bfr_change['predicted_checksum'] ),
	'the plan predicts the resulting checksum, which turns it from a description into a contract the apply re-checks'
);

$bfr_applied = diviops_bfr_apply( array( 'targets' => array( 801 ), 'search' => 'Acme & Co', 'replace' => 'Beta Ltd' ) );
assert_true( $bfr_applied['ok'], 'the apply succeeds' );

$bfr_after = (string) $GLOBALS['diviops_test_posts'][801]->post_content;
assert_true( false !== strpos( $bfr_after, 'Beta Ltd' ), 'the replacement landed in the stored bytes' );
assert_true( false === strpos( $bfr_after, 'Acme' ), 'and the original text is gone' );
// The written page must still parse as block attrs, which is the property a raw
// splice destroys.
$bfr_attrs = diviops_call( 'extract_attrs_from_block_markup', array( $bfr_after ) );
assert_true( is_array( $bfr_attrs ), 'the rewritten opener still decodes as valid block attributes' );
assert_same(
	'Beta Ltd',
	$bfr_attrs['content']['desktop']['value'] ?? null,
	'and its decoded content value reads back as the replacement a human asked for'
);

// -- 2. A replacement containing a quote and a backslash ------------------
//
// The corruption path this whole design exists to close. A raw splice emits
// invalid JSON here and every existing guard passes it.

diviops_bfr_reset();
diviops_bfr_post( 802, diviops_bfr_block( 'Acme Co' ) );

$bfr_hostile = 'Tom' . chr( 39 ) . 's "Best" ' . chr( 92 ) . 'Deals' . chr( 92 );
$bfr_quotes  = diviops_bfr_apply( array( 'targets' => array( 802 ), 'search' => 'Acme Co', 'replace' => $bfr_hostile ) );

assert_true( $bfr_quotes['ok'], 'a replacement containing a quote and a backslash is APPLIED, not refused -- the decode/re-encode handles it' );

$bfr_after2 = (string) $GLOBALS['diviops_test_posts'][802]->post_content;
$bfr_attrs2 = diviops_call( 'extract_attrs_from_block_markup', array( $bfr_after2 ) );
assert_true(
	is_array( $bfr_attrs2 ),
	'and the result is still valid block-attribute JSON, which a raw splice would have destroyed while passing every existing guard'
);
assert_same(
	$bfr_hostile,
	$bfr_attrs2['content']['desktop']['value'] ?? null,
	'with the hostile string surviving the round trip byte-for-byte'
);

// -- 3. An untouched empty object stays an object -------------------------
//
// Found by adversarial review. json_decode with the assoc flag turns {} into [],
// and re-encoding emits [] -- an attribute the caller never named changes type,
// silently, on every target.

diviops_bfr_reset();
$bfr_with_empty = diviops_bfr_block( 'Acme Co', array( 'decoration' => new stdClass() ) );
assert_true(
	false !== strpos( $bfr_with_empty, '"decoration":{}' ),
	'the fixture really does carry an untouched empty OBJECT, without which this section proves nothing'
);
diviops_bfr_post( 803, $bfr_with_empty );

$bfr_empty_run = diviops_bfr_apply( array( 'targets' => array( 803 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd' ) );
assert_true( $bfr_empty_run['ok'], 'the run applies' );

$bfr_after3 = (string) $GLOBALS['diviops_test_posts'][803]->post_content;
assert_true(
	false !== strpos( $bfr_after3, '"decoration":{}' ),
	'an untouched empty object is still an object after the rewrite -- an assoc decode would have emitted [] here, valid JSON that every guard passes'
);
assert_true( false === strpos( $bfr_after3, '"decoration":[]' ), 'and specifically not an array' );

// -- 4. Body text, and the block-boundary refusal -------------------------

diviops_bfr_reset();
diviops_bfr_post( 804, '<p>Call Acme Co today.</p>' . diviops_bfr_block( 'Acme Co' ) );

$bfr_both = diviops_bfr_call( array( 'targets' => array( 804 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd' ) );
$bfr_m    = $bfr_both['data']['plan']['changes'][0]['matches'] ?? array();
assert_same( 1, $bfr_m['body'] ?? null, 'scope=both finds the body occurrence' );
assert_same( 1, $bfr_m['block_attrs'] ?? null, 'and the attribute occurrence' );

$bfr_body_only = diviops_bfr_call( array( 'targets' => array( 804 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd', 'scope' => 'body' ) );
$bfr_mb        = $bfr_body_only['data']['plan']['changes'][0]['matches'] ?? array();
assert_same( 0, $bfr_mb['block_attrs'] ?? null, 'scope=body leaves attribute matches alone' );
assert_same( 1, $bfr_mb['body'] ?? null, 'and touches only the body occurrence' );

$bfr_attrs_only = diviops_bfr_call( array( 'targets' => array( 804 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd', 'scope' => 'attrs' ) );
$bfr_ma         = $bfr_attrs_only['data']['plan']['changes'][0]['matches'] ?? array();
assert_same( 0, $bfr_ma['body'] ?? null, 'and scope=attrs leaves the body alone' );

// A replacement carrying a comment delimiter would splice a block boundary into
// the document. Refused rather than guarded after the fact.
$bfr_delim = diviops_bfr_call(
	array( 'targets' => array( 804 ), 'search' => 'Acme Co', 'replace' => 'Beta --> Ltd' )
);
$bfr_dv    = $bfr_delim['data']['plan']['changes'][0]['verdict'] ?? '';
assert_same(
	'will_refuse:bulk.replacement_contains_block_delimiter',
	$bfr_dv,
	'a replacement containing a block-comment delimiter is refused, and refused IN THE PLAN rather than at apply time'
);

// -- 5. Locked modules refuse the whole run -------------------------------

diviops_bfr_reset();
$bfr_locked_block = diviops_bfr_block( 'Acme Co', array( 'locked' => (object) array( 'desktop' => (object) array( 'value' => 'on' ) ) ) );
diviops_bfr_post( 805, $bfr_locked_block );
diviops_bfr_post( 806, diviops_bfr_block( 'Acme Co' ) );

$bfr_lock_plan = diviops_bfr_call( array( 'targets' => array( 805, 806 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd' ) );
$bfr_lv        = array();
foreach ( $bfr_lock_plan['data']['plan']['changes'] as $change ) {
	$bfr_lv[ $change['id'] ] = $change['verdict'];
}
assert_same( 'will_refuse:bulk.module_locked', $bfr_lv[805] ?? null, 'a page carrying a locked module is refused' );
assert_same( 'will_apply', $bfr_lv[806] ?? null, 'while its clean neighbour reads will_apply in the plan' );

$bfr_lock_apply = diviops_bfr_apply( array( 'targets' => array( 805, 806 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd' ) );
assert_same(
	'bulk.preflight_refused',
	$bfr_lock_apply['error']['code'] ?? null,
	'and the apply refuses the WHOLE run -- a partial application that quietly skipped the locked page is what this default exists to prevent'
);
assert_true(
	false !== strpos( (string) $GLOBALS['diviops_test_posts'][806]->post_content, 'Acme Co' ),
	'so the clean neighbour was not written either'
);

$bfr_lock_forced = diviops_bfr_apply(
	array( 'targets' => array( 805 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd', 'include_locked' => true )
);
assert_true( $bfr_lock_forced['ok'], 'include_locked writes it, because the caller has now named the lock explicitly' );
assert_true(
	false !== strpos( (string) $GLOBALS['diviops_test_posts'][805]->post_content, 'Beta Ltd' ),
	'and the locked page really was rewritten'
);

// The lock must be found by DECODING, not by a strpos for the word. A page whose
// text merely contains "locked" is not a locked page.
diviops_bfr_reset();
diviops_bfr_post( 807, diviops_bfr_block( 'The door is locked. Acme Co' ) );
$bfr_word = diviops_bfr_call( array( 'targets' => array( 807 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd' ) );
assert_same(
	'will_apply',
	$bfr_word['data']['plan']['changes'][0]['verdict'] ?? null,
	'a page whose CONTENT contains the word locked is not treated as carrying a locked module -- strpos would be wrong in this direction'
);

// -- 6. Idempotence, and the refusals ------------------------------------

diviops_bfr_reset();
diviops_bfr_post( 808, diviops_bfr_block( 'Acme Co' ) );
diviops_bfr_apply( array( 'targets' => array( 808 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd' ) );
$bfr_again = diviops_bfr_call( array( 'targets' => array( 808 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd' ) );
assert_same(
	'will_skip:already_applied',
	$bfr_again['data']['plan']['changes'][0]['verdict'] ?? null,
	'a re-run whose search string no longer occurs skips as already_applied rather than erroring'
);

$bfr_same = diviops_bfr_call( array( 'targets' => array( 808 ), 'search' => 'x', 'replace' => 'x' ) );
assert_same( 'invalid_input', $bfr_same['error']['code'] ?? null, 'search identical to replace is refused: it would write every target for no change' );

$bfr_nosearch = diviops_bfr_call( array( 'targets' => array( 808 ), 'search' => '' ) );
assert_same( 'invalid_input', $bfr_nosearch['error']['code'] ?? null, 'an empty search is refused rather than matching everywhere' );

$bfr_badscope = diviops_bfr_call( array( 'targets' => array( 808 ), 'search' => 'a', 'replace' => 'b', 'scope' => 'regex' ) );
assert_same( 'invalid_input', $bfr_badscope['error']['code'] ?? null, 'an unrecognised scope is refused rather than defaulted' );

// -- 7. The guarded write, and what recovery means here -------------------

diviops_bfr_reset();
diviops_bfr_post( 809, diviops_bfr_block( 'Acme Co' ) );
$bfr_before_bytes = (string) $GLOBALS['diviops_test_posts'][809]->post_content;
$bfr_run          = diviops_bfr_apply( array( 'targets' => array( 809 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd' ) );

assert_true( $bfr_run['ok'], 'the run applies' );
assert_true(
	isset( $bfr_run['data']['snapshot_chunks'] ) && count( (array) $bfr_run['data']['snapshot_chunks'] ) > 0,
	'and reports the snapshot chunk it wrote'
);

$bfr_chunk = diviops_call(
	'rollback_snapshot_get',
	array( new DiviOps_Test_Request( array( 'snapshot_id' => (string) ( $bfr_run['data']['snapshot_chunks'][0] ?? 'run_00000000000000_0000000000000000' ) ) ) )
)->get_data();
assert_true( $bfr_chunk['ok'], 'the chunk reads back by the id the run reported' );

$bfr_entry = null;
foreach ( (array) ( $bfr_chunk['data']['targets'] ?? array() ) as $entry ) {
	if ( 809 === (int) $entry['id'] ) {
		$bfr_entry = $entry;
	}
}
assert_true( null !== $bfr_entry, 'the written target has a snapshot entry' );
$bfr_entry = is_array( $bfr_entry ) ? $bfr_entry : array( 'after' => array( 'checksum' => null ), 'restorable' => false );
assert_true(
	true === ( $bfr_entry['restorable'] ?? false ),
	'which is RESTORABLE -- unlike bulk_status_change, this operation really does change post_content, so the snapshot is a genuine recovery record'
);

// -- 8. Marker census equality -------------------------------------------
//
// The invariant a bad splice violates, and the reason the census guard earns its
// place: a replacement that swallowed a comment delimiter changes the counts.

$bfr_counts_before = diviops_call( 'divi_content_marker_counts', array( $bfr_before_bytes ) );
$bfr_counts_after  = diviops_call( 'divi_content_marker_counts', array( (string) $GLOBALS['diviops_test_posts'][809]->post_content ) );
assert_same( $bfr_counts_before, $bfr_counts_after, 'the block-comment marker census is identical before and after a correct replacement' );

// -- 9. A page whose opener will not decode is refused, not guessed at ----

diviops_bfr_reset();
diviops_bfr_post( 810, '<!-- wp:divi/text {"content":"Acme Co" /-->' );
$bfr_broken = diviops_bfr_call( array( 'targets' => array( 810 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd' ) );
assert_same(
	'will_refuse:bulk.attrs_decode_failed',
	$bfr_broken['data']['plan']['changes'][0]['verdict'] ?? null,
	'an opener whose attributes do not decode refuses that target rather than splicing into bytes nothing could parse'
);


// -- 10. Guards the public path cannot reach, driven directly -------------
//
// Six mutations to the replacement engine survived the first matrix run because
// no fixture reached the branch they broke. Four of those are fixed below by
// fixtures that do reach them. Two are recorded here as genuinely unreachable
// through the public API, with the reason, rather than left looking covered:
//
//   * the marker-census equality check. A body replacement cannot change the
//     census, because the block-delimiter refusal already rejects any search or
//     replacement containing `<!--` or `-->`; and the attrs pass rebuilds an
//     opener as prefix + re-encoded JSON + suffix, so the block name and the
//     self-closing form are carried through untouched. The helper itself is
//     asserted directly in section 8. The call is kept as defence in depth
//     against a future change to either pass.
//
//   * the re-encode validation inside the attrs pass. Every reachable way to
//     make `serialize_block_attrs_canonical()` return a non-string is now caught
//     earlier by the UTF-8 check in section 12, which had to be added because the
//     downstream failure is a fatal rather than a refusal. It is kept because it
//     is the last line before a splice, and a future encoder change could make it
//     reachable again.
//
//   * the canonical encoder inside the attrs pass, as distinct from the
//     validation after it. Swapping `serialize_block_attrs_canonical()` for a
//     plain `wp_json_encode()` produces BYTE-IDENTICAL stored content, measured
//     on a replacement carrying `"`, `\`, `&` and `<`: the whole document is
//     canonicalised again at the end of `bulk_replace_in_content()`, so the
//     per-opener encoder's escaping is not what puts core's escapes in the
//     stored bytes. That is worth knowing rather than covering -- it means the
//     tail canonicalisation is the load-bearing step, and a change that removed
//     IT would not be caught by anything in this section.
//
//   * the pre-write canonicality re-check. `bulk_replace_in_content()` already
//     normalises its own output, so re-running the normaliser is a no-op by
//     construction. It is kept because it is a real invariant assertion at the
//     moment it matters -- immediately before the write -- and because it is the
//     canonicalisation call `tests/test-module-update-write-safety.php` scans the
//     writing function for. A comment-only mention would satisfy that scan and is
//     the false negative that gate's own docblock warns about.

// R11: the predicted checksum IS re-checked at apply time. Unreachable through
// the public path -- the drift check fires first on any content change -- so it
// is driven directly, the same way phase 2 drives its loop contract.
diviops_bfr_reset();
diviops_bfr_post( 820, diviops_bfr_block( 'Acme Co' ) );

$bfr_planned = diviops_call( 'bulk_target_state', array( 820 ) );
$bfr_prep    = diviops_call(
	'bulk_replace_prepare',
	array( array( $bfr_planned ), 'Acme Co', 'Beta Ltd', 'both', false )
);
$bfr_run_h   = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_test', array( 'tool_operation' => 'test' ) ) );
$bfr_params  = array( 'search' => 'Acme Co', 'replace' => 'Beta Ltd', 'scope' => 'both', 'include_locked' => false );

$bfr_lying                       = $bfr_prep[0];
$bfr_lying['predicted_checksum'] = 'sha256:' . str_repeat( '0', 64 );
$bfr_args = array( &$bfr_run_h, $bfr_planned, $bfr_lying, $bfr_params );
$bfr_out  = diviops_call_ref( 'bulk_replace_apply_one', $bfr_args );
assert_same(
	'bulk.prediction_mismatch',
	$bfr_out['result']['code'] ?? null,
	'a target whose recomputed bytes do not hash to what the plan promised is refused -- the plan is a contract the apply re-checks, not a description'
);
assert_true(
	false !== strpos( (string) $GLOBALS['diviops_test_posts'][820]->post_content, 'Acme Co' ),
	'and it was not written'
);

// The undrifted control, without which the assertion above would be satisfied
// just as well by a guard that refused everything.
$bfr_args = array( &$bfr_run_h, $bfr_planned, $bfr_prep[0], $bfr_params );
$bfr_out  = diviops_call_ref( 'bulk_replace_apply_one', $bfr_args );
assert_same( 'applied', $bfr_out['bucket'] ?? null, 'while the honest prepared row applies' );

// -- 11. Several openers in one document ----------------------------------
//
// R12: openers are rewritten LAST TO FIRST, because a replacement almost always
// changes length and rewriting forwards invalidates every span after the first
// edit. A single-opener fixture cannot see that, and the first matrix run let
// the mutation survive.

diviops_bfr_reset();
diviops_bfr_post(
	821,
	diviops_bfr_block( 'Acme Co first' ) . diviops_bfr_block( 'middle' ) . diviops_bfr_block( 'Acme Co last' )
);

$bfr_multi = diviops_bfr_apply( array( 'targets' => array( 821 ), 'search' => 'Acme Co', 'replace' => 'A Rather Longer Company Name' ) );
assert_true( $bfr_multi['ok'], 'a document with several matching openers applies' );

$bfr_after_multi = (string) $GLOBALS['diviops_test_posts'][821]->post_content;
assert_same(
	2,
	substr_count( $bfr_after_multi, 'A Rather Longer Company Name' ),
	'BOTH matching openers were rewritten, with a replacement long enough that a forward rewrite would have corrupted the second span'
);
assert_true( false === strpos( $bfr_after_multi, 'Acme Co' ), 'and neither original survives' );
assert_same(
	3,
	substr_count( $bfr_after_multi, '<!-- wp:divi/text ' ),
	'all three openers are still present and well formed, including the one that matched nothing'
);
assert_true(
	false !== strpos( $bfr_after_multi, '"value":"middle"' ),
	'and the untouched opener between them is byte-intact'
);

// -- 12. Invalid UTF-8 is refused before anything is decoded ---------------
//
// Found while chasing a surviving mutant on the re-encode validation: the
// reachable way to make the encoder fail is invalid UTF-8, and following that
// path showed it does not fail gracefully at all. It FATALS, inside core.

diviops_bfr_reset();
diviops_bfr_post( 822, diviops_bfr_block( 'Acme Co' ) );

$bfr_bad_utf8 = "Beta " . chr( 0xFF ) . chr( 0xFE ) . " Ltd";
assert_true(
	false === mb_check_encoding( $bfr_bad_utf8, 'UTF-8' ),
	'the fixture replacement really is invalid UTF-8, without which this section proves nothing'
);

$bfr_utf8_plan = diviops_bfr_call( array( 'targets' => array( 822 ), 'search' => 'Acme Co', 'replace' => $bfr_bad_utf8 ) );
assert_same(
	'invalid_input',
	$bfr_utf8_plan['error']['code'] ?? null,
	'a replacement that is not valid UTF-8 is refused UP FRONT, before any target is decoded'
);
assert_same(
	'replace',
	$bfr_utf8_plan['error']['data']['field'] ?? null,
	'naming the offending field'
);
// Refusing up front is not tidiness. Core's serialize_block_attributes() passes
// wp_json_encode()'s return straight into a string operation without checking
// it, and wp_json_encode returns false on invalid UTF-8 -- so reaching the
// encoder with these bytes is a TypeError mid-run, after however many earlier
// targets had already been written, rather than a clean refusal before anything
// was touched. Asserted against core's own function so the reason cannot rot.
assert_true(
	false === json_encode( $bfr_bad_utf8 ),
	'and the reason is that json_encode itself fails on these bytes, which core does not check before using the result'
);
assert_true(
	false !== strpos( (string) $GLOBALS['diviops_test_posts'][822]->post_content, 'Acme Co' ),
	'and the page is untouched'
);

// -- 13. A pseudo-escape in the replacement is caught ----------------------
//
// R15: `find_malformed_block_attr_escape()` detects a bare `u00XX` payload with
// no backslash -- the shape Divi's own escaping produces WITH one. A replacement
// containing that literal text re-encodes to a bare sequence, which is exactly
// what the detector exists for.

diviops_bfr_reset();
diviops_bfr_post( 823, diviops_bfr_block( 'Acme Co' ) );

$bfr_pseudo      = 'Beta u003cscript u003e Ltd';
$bfr_pseudo_plan = diviops_bfr_call( array( 'targets' => array( 823 ), 'search' => 'Acme Co', 'replace' => $bfr_pseudo ) );
assert_same(
	'will_refuse:bulk.attrs_escape_malformed',
	$bfr_pseudo_plan['data']['plan']['changes'][0]['verdict'] ?? null,
	'a replacement re-encoding to a bare u00XX pseudo-escape is refused -- the shape Divi escaping produces WITH a backslash, and this one has none'
);

// -- 14. A global-layout wrapper is guarded -------------------------------
//
// R7: the guarded write passes $check_global_layout_drift = true. Its docblock
// says that parameter is for round-trip callers; a literal find/replace is not
// one, and the criterion is the caller's INTENT, not its mechanism -- a
// find/replace never means to remove a wrapper, so a write that removed one is a
// corrupted splice rather than an authored change.

diviops_bfr_reset();
// The wrapper's identity is the TOP-LEVEL `globalModule` attribute, which is
// what `global_layout_wrapper_identities()` reads and what a real page stores (a
// post id as a string, as `tests/test-global-layout-index.php` and
// `tests/test-page-duplicate.php` both carry). A nested shape here would leave
// the identity as the no-id sentinel, and the drift assertions below would be
// about a wrapper with no identity rather than about a real one.
$bfr_wrapped = '<!-- wp:divi/global-layout {"globalModule":"900296","builderVersion":"5.9.0"} -->'
	. diviops_bfr_block( 'Acme Co' )
	. '<!-- /wp:divi/global-layout -->';
diviops_bfr_post( 824, $bfr_wrapped );

assert_true(
	false !== strpos( (string) $GLOBALS['diviops_test_posts'][824]->post_content, 'divi/global-layout' ),
	'the fixture really carries a global-layout wrapper, without which the warning and the guard below are untested'
);

$bfr_wrap_plan = diviops_bfr_call( array( 'targets' => array( 824 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd' ) );
assert_true(
	true === ( $bfr_wrap_plan['data']['plan']['changes'][0]['has_global_layout'] ?? null ),
	'the plan reports that this target carries a wrapper, which is a signal a human needs and not a detail'
);
assert_true(
	count( $bfr_wrap_plan['data']['plan']['warnings'] ) > 0,
	'and warns, because a shared global layout shows this change everywhere that layout appears'
);

$bfr_wrap_apply = diviops_bfr_apply( array( 'targets' => array( 824 ), 'search' => 'Acme Co', 'replace' => 'Beta Ltd' ) );
assert_true( $bfr_wrap_apply['ok'], 'a correct replacement inside a wrapper applies' );
// Compared against the fixture's own count rather than a literal: a wrapper is
// an opener AND a closer, so the literal is 2, and writing 1 here would have
// been a wrong expectation that happened to be red for the right reason.
assert_same(
	substr_count( $bfr_wrapped, 'divi/global-layout' ),
	substr_count( (string) $GLOBALS['diviops_test_posts'][824]->post_content, 'divi/global-layout' ),
	'and the wrapper survives the write intact, opener and closer both'
);

// The guard itself, asserted on the seam the write passes through: content that
// LOST a wrapper must be refused. Driven directly because the replacement engine
// cannot produce that outcome, which is the point of guarding it.
assert_same(
	'identity_lost',
	diviops_call( 'global_layout_write_refusal_reason', array( $bfr_wrapped, diviops_bfr_block( 'Beta Ltd' ) ) ),
	'and a write that dropped the wrapper is refused by the guard bulk_find_replace passes true to'
);

// -- 15. An empty search is refused on its own merits ---------------------
//
// R14: the original assertion passed the mutant, because search='' with replace
// omitted also trips the search===replace guard -- so removing the empty-search
// check changed nothing observable. The needle and the replacement must differ
// for this assertion to be about the check it names.

diviops_bfr_reset();
diviops_bfr_post( 825, diviops_bfr_block( 'Acme Co' ) );
$bfr_empty_search = diviops_bfr_call( array( 'targets' => array( 825 ), 'search' => '', 'replace' => 'Beta Ltd' ) );
assert_same(
	'invalid_input',
	$bfr_empty_search['error']['code'] ?? null,
	'an empty search is refused by the empty-search check specifically, with a distinct replacement so the search===replace guard cannot mask it'
);
assert_same(
	'search',
	$bfr_empty_search['error']['data']['field'] ?? null,
	'and the refusal names the search field, not the replacement'
);
// -- 16. A replacement that would rewrite a wrapper's identity is refused --
//
// The end-to-end half of R7, and the only path by which a literal find/replace
// can detach a global layout: `globalModule` is stored as a STRING, so a search
// string that happens to occur in it -- a post id, an order number, a SKU, any
// numeric token a human would reasonably sweep site-wide -- is replaced like any
// other string leaf, and the page silently points at a different layout, or at
// none.
//
// The attrs pass rewrites it; every guard before the write passes, because the
// JSON is valid, the marker census is unchanged and the bytes are canonical. The
// drift check inside the guarded write is what stops it, which is why this
// caller passes true for a parameter whose docblock was written for round-trip
// callers.
//
// Refused at APPLY, not in the plan: the plan reports a change it will not make.
// That is safe -- nothing is written and the failure is named per target -- and
// it is not re-checked earlier on purpose, because the post can change between
// plan and apply, so the authoritative place for this check is immediately
// before the write.

diviops_bfr_reset();
$bfr_id_page = '<!-- wp:divi/global-layout {"globalModule":"900296","builderVersion":"5.9.0"} -->'
	. diviops_bfr_block( 'Case 900296 archive' )
	. '<!-- /wp:divi/global-layout -->';
diviops_bfr_post( 826, $bfr_id_page );

assert_same(
	array( '900296' ),
	diviops_call( 'global_layout_wrapper_identities', array( $bfr_id_page ) ),
	'the fixture carries one wrapper whose identity is the id the search string is about to match'
);

$bfr_id_plan = diviops_bfr_call( array( 'targets' => array( 826 ), 'search' => '900296', 'replace' => '900297' ) );
assert_true(
	( $bfr_id_plan['data']['plan']['changes'][0]['matches']['block_attrs'] ?? 0 ) >= 2,
	'the plan finds the id in the wrapper opener as well as in the module text -- the wrapper is not exempt from the replace'
);

$bfr_id_apply = diviops_bfr_apply( array( 'targets' => array( 826 ), 'search' => '900296', 'replace' => '900297' ) );
assert_same( 'bulk.partial_failure', $bfr_id_apply['error']['code'] ?? null, 'the run reports a failure rather than applying' );
assert_same( 1, $bfr_id_apply['error']['data']['counts']['failed'] ?? null, 'and the wrapper page is the target that failed' );
assert_same(
	'bulk.global_layout_drift',
	$bfr_id_apply['error']['data']['targets'][0]['code'] ?? null,
	'named as global-layout drift, so the human is told the layout reference is what stopped it'
);
assert_same(
	$bfr_id_page,
	(string) $GLOBALS['diviops_test_posts'][826]->post_content,
	'and the page is byte-identical to before the run: refused, not written and reverted'
);

// -- 17. A search string that straddles a block boundary ------------------
//
// R9: the body pass refuses a match whose own bytes carry `<!--` or `-->`. Only
// the REPLACEMENT was covered (section 4), so removing the match-side check
// changed nothing any fixture saw -- and the note in section 10 that calls the
// marker census unreachable rests on this check existing.
//
// Reachable because nothing rejects a search string for containing a delimiter:
// a human sweeping `5 --> 7` out of body copy is doing something ordinary. The
// census guard would catch the damage a step later, under a different name; this
// refusal tells them which of the two problems they actually have.

diviops_bfr_reset();
diviops_bfr_post( 827, '<p>Ship by 5 --> 7 days.</p>' . diviops_bfr_block( 'Acme Co' ) );

$bfr_straddle = diviops_bfr_call( array( 'targets' => array( 827 ), 'search' => '5 --> 7', 'replace' => '5 to 7' ) );
assert_same(
	'will_refuse:bulk.match_spans_block_boundary',
	$bfr_straddle['data']['plan']['changes'][0]['verdict'] ?? null,
	'a body match whose own bytes carry a block delimiter is refused as a boundary straddle, not left for the census guard to name differently'
);

// The control: the same body text, matched WITHOUT the delimiter, is an ordinary
// replacement. Without it, the assertion above would be satisfied by a guard
// that refused every body match.
$bfr_nostraddle = diviops_bfr_call( array( 'targets' => array( 827 ), 'search' => 'Ship by', 'replace' => 'Ships in' ) );
assert_same(
	'will_apply',
	$bfr_nostraddle['data']['plan']['changes'][0]['verdict'] ?? null,
	'while a body match in the same document that carries no delimiter applies normally'
);


diviops_bfr_reset();
