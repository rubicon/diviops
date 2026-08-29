<?php
// SPDX-License-Identifier: MIT
/**
 * #314: a preset referenced only from a Theme Builder layout must not report as
 * unreferenced, over real HTTP against a real Divi install.
 *
 * `tests/test-preset-ref-scan-post-types.php` pins the post-type scope in the
 * hermetic suite, and it is a good test, but it cannot answer the question the
 * issue actually asks. That suite deliberately ships no `parse_blocks()` (see
 * tests/wp-shim.php's docblock), so nothing in it observes a real Divi block
 * comment being parsed, a real preset UUID being recognized in
 * `attrs.modulePreset`, or the reference count arriving through the REST
 * envelope an operator reads before authorizing a delete. That is the gap this
 * suite exists for — three bugs (#28, #36, #35/#97) passed the whole shimmed
 * suite and failed on first contact with real WordPress.
 *
 * What this file proves end to end:
 *
 *   1. A preset referenced ONLY from an `et_footer_layout` post reports
 *      `references.total >= 1` from `preset_inspect`, and names that post in
 *      `sample_consumers` with its real `post_type`. Before #314 the same call
 *      returned `total: 0` with an empty `sample_consumers` — observed on this
 *      install for preset `llgpo8h7zc`, whose three live consumers are all
 *      `et_footer_layout` rows and not one of which is a `page` or a `post`.
 *
 *   2. `preset_scan_orphans` does not report a registry-resident preset that is
 *      referenced only from a Theme Builder layout. Widening the scan makes
 *      more UUIDs visible to that endpoint, and a registered one appearing
 *      there would be a new false positive introduced by the fix.
 *
 *   3. `references.revision_ref_count` reports revision-only occurrences
 *      separately from live references, so "referenced only in history" and
 *      "referenced nowhere" do not render identically.
 *
 * NOTHING here writes to an existing post. The only write is one throwaway
 * draft `et_footer_layout` created through `live_create_scratch_page()` and
 * force-deleted by the harness at shutdown, pass or fail. `preset_cleanup`,
 * `preset_delete`, and `preset_reassign` are never called: those are the
 * destructive operations this bug misinforms, and exercising them is not how
 * the fix is verified. Post 396 (the published Global Footer Layout) and page
 * 900390 are read only, and this file does not target either.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/harness.php';

/**
 * Fetch one preset's inspect envelope.
 *
 * @param string $preset_id Preset UUID.
 * @return array{status:int, body:mixed, raw:string}
 */
function live314_inspect( string $preset_id ): array {
	return live_rest_call( 'GET', 'diviops/v1/preset/inspect/' . rawurlencode( $preset_id ) );
}

/*
 * Pick a real registered preset rather than hard-coding one: a UUID that is not
 * in the registry returns not_found, and this file would then be asserting
 * against an error envelope while looking like it passed a reference check.
 * preset_audit lists every registered module preset; the first descriptive one
 * with attrs is enough — the assertion is about post types, not about which
 * preset was chosen.
 */
$live314_audit = live_rest_call( 'GET', 'diviops/v1/preset/audit' );
assert_same( 200, $live314_audit['status'], 'preset_audit is reachable, so a real preset UUID can be selected' );

$live314_preset_id = '';
foreach ( array( 'descriptive', 'spam_referenced', 'spam_unreferenced' ) as $bucket ) {
	foreach ( (array) ( $live314_audit['body']['data'][ $bucket ] ?? array() ) as $entry ) {
		if ( 'module' === ( $entry['type'] ?? '' ) && ! empty( $entry['id'] ) ) {
			$live314_preset_id = (string) $entry['id'];
			break 2;
		}
	}
}
assert_true(
	'' !== $live314_preset_id,
	'a registered module preset UUID was found to reference from the fixture — without one the assertions below would inspect nothing'
);

$live314_before = live314_inspect( $live314_preset_id );
assert_same( 200, $live314_before['status'], 'the chosen preset inspects cleanly before the fixture exists' );
$live314_total_before = (int) ( $live314_before['body']['data']['references']['total'] ?? -1 );
assert_true(
	$live314_total_before >= 0,
	'the baseline reference total is a real number: ' . $live314_total_before
);

/*
 * The fixture: one real Divi module block carrying the preset in
 * attrs.modulePreset, stored in an et_footer_layout post. This is the shape the
 * issue documents on post 396 — a difl/vertical-menu block whose only styling
 * source is `"modulePreset":["llgpo8h7zc"]`.
 */
$live314_markup = '<!-- wp:divi/section {"builderVersion":"5.9.0"} -->'
	. '<!-- wp:divi/row {"builderVersion":"5.9.0"} -->'
	. '<!-- wp:divi/column {"builderVersion":"5.9.0"} -->'
	. '<!-- wp:divi/text {"builderVersion":"5.9.0","modulePreset":["' . $live314_preset_id . '"]} /-->'
	. '<!-- /wp:divi/column -->'
	. '<!-- /wp:divi/row -->'
	. '<!-- /wp:divi/section -->';

$live314_footer_id = live_create_scratch_page(
	$live314_markup,
	'DiviOps #314 scratch footer layout ' . date( 'Y-m-d H:i:s' ),
	'et_footer_layout'
);

assert_same(
	$live314_markup,
	live_get_post_content( $live314_footer_id ),
	'the fixture landed byte-for-byte, so a failed assertion below is about the scan and not about a mangled fixture'
);

/* -- 1. the reference is seen ------------------------------------------- */

$live314_after       = live314_inspect( $live314_preset_id );
$live314_total_after = (int) ( $live314_after['body']['data']['references']['total'] ?? -1 );

assert_same( 200, $live314_after['status'], 'preset_inspect still answers with the fixture in place' );
assert_true(
	$live314_total_after >= 1,
	'a preset referenced from an et_footer_layout post reports at least one reference (#314): total=' . $live314_total_after
);
assert_same(
	$live314_total_before + 1,
	$live314_total_after,
	'the Theme Builder fixture added exactly one reference — before=' . $live314_total_before . ' after=' . $live314_total_after
);

$live314_consumer_types = array();
$live314_found_fixture  = false;
foreach ( (array) ( $live314_after['body']['data']['references']['sample_consumers'] ?? array() ) as $consumer ) {
	if ( isset( $consumer['post_type'] ) ) {
		$live314_consumer_types[] = (string) $consumer['post_type'];
	}
	if ( (int) ( $consumer['post_id'] ?? 0 ) === $live314_footer_id ) {
		$live314_found_fixture = true;
		assert_same(
			'et_footer_layout',
			(string) ( $consumer['post_type'] ?? '' ),
			'sample_consumers reports the consumer post_type alongside id and title, so a surprising count is traceable to where it came from'
		);
	}
}
assert_true(
	$live314_found_fixture,
	'the fixture post itself appears in sample_consumers, not just in the count: types seen = ' . implode( ',', $live314_consumer_types )
);

assert_true(
	in_array( 'et_footer_layout', (array) ( $live314_after['body']['data']['references']['scanned_post_types'] ?? array() ), true ),
	'the response states the post types it scanned, so a zero can be read as scoped rather than absolute'
);

/* -- 2. no new false orphan -------------------------------------------- */

$live314_orphans = live_rest_call( 'GET', 'diviops/v1/preset/scan-orphans' );
assert_same( 200, $live314_orphans['status'], 'preset_scan_orphans is reachable' );

$live314_orphan_uuids = array();
foreach ( (array) ( $live314_orphans['body']['data']['orphans'] ?? array() ) as $orphan ) {
	$live314_orphan_uuids[] = (string) ( $orphan['uuid'] ?? '' );
}
assert_same(
	false,
	in_array( $live314_preset_id, $live314_orphan_uuids, true ),
	'a registry-resident preset referenced only from an et_footer_layout post is NOT reported as an orphan'
);
assert_true(
	(int) ( $live314_orphans['body']['data']['total_referenced'] ?? 0 ) > 0,
	'scan_orphans inspected a non-empty reference set — a zero here would make the assertion above vacuous'
);

/* -- 3. revisions are reported, and reported separately ----------------- */

$live314_refs = (array) ( $live314_after['body']['data']['references'] ?? array() );
assert_true(
	array_key_exists( 'revision_ref_count', $live314_refs ) && is_int( $live314_refs['revision_ref_count'] ),
	'references carries an integer revision_ref_count'
);
assert_same(
	false,
	$live314_refs['revision_only'] ?? null,
	'revision_only is false while a live reference exists — it flags the case where history is the ONLY thing holding a preset'
);
assert_true(
	$live314_total_after > $live314_refs['revision_ref_count'] || 0 === $live314_refs['revision_ref_count'],
	'revision matches are excluded from total rather than folded into it'
);
