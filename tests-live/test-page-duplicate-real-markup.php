<?php
/**
 * #35/#98 regression, end-to-end over real HTTP + real parse_blocks(): page
 * duplication must not materialize a divi/global-layout wrapper into its
 * resolved content, and third-party (difl/*) markup must survive byte-for-byte.
 *
 * tests/test-page-duplicate.php already covers page_duplicate's byte-copy
 * implementation at the unit level, but its own comment says exactly why
 * that is not the full story: "the full module_lock/clone etc. round trip
 * cannot be exercised in this harness: it calls parse_blocks(), which is
 * deliberately unshimmed... this suite does not fake that behavior." This
 * file is that live verification — real WordPress' real block parser,
 * called through the real REST route, against markup shaped like what a
 * real Divi 5 site with third-party modules actually stores.
 *
 * globalModule id 900296 is a real global module id observed live in page
 * 900390's own content during #97's investigation — using a real id here
 * rather than a plausible-looking placeholder matters, because parse_blocks()
 * materializing a global-layout wrapper is a *live* WordPress/Divi behavior
 * (see #99), not something a synthetic id would necessarily trigger the same
 * way a real one does.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/harness.php';

$fixture = implode(
	'',
	array(
		'<!-- wp:divi/section {"builderVersion":"5.9.0"} -->',
		'<!-- wp:divi/global-layout {"globalModule":"900296","blockName":"divi/section","builderVersion":"5.9.0"} /-->',
		'<!-- wp:divi/row {"builderVersion":"5.9.0"} -->',
		'<!-- wp:divi/column {"builderVersion":"5.9.0"} -->',
		'<!-- wp:difl/faq {"module":{"meta":{"adminLabel":{"desktop":{"value":"Live Suite FAQ"}}}},"builderVersion":"5.9.0"} -->',
		'<!-- wp:difl/faqitem {"builderVersion":"5.9.0"} /-->',
		'<!-- wp:difl/faqitem {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:difl/faq -->',
		'<!-- /wp:divi/column -->',
		'<!-- /wp:divi/row -->',
		'<!-- /wp:divi/section -->',
	)
);

$source_id = live_create_scratch_page( $fixture, 'DiviOps live-suite #35 duplicate source' );

$before = live_get_post_content( $source_id );
assert_same( $fixture, $before, 'the fixture landed on the scratch source page byte-for-byte before duplication even starts' );

$resp = live_rest_call( 'POST', 'diviops/v1/page/duplicate/' . $source_id, array() );
assert_same( 200, $resp['status'], 'page/duplicate on real global-layout + difl markup returns HTTP 200' );
assert_true( ! empty( $resp['body']['ok'] ), 'duplicate response envelope reports ok:true' );

$new_id = (int) ( $resp['body']['data']['page_id'] ?? $resp['body']['data']['new_page_id'] ?? 0 );
assert_true( $new_id > 0 && $new_id !== $source_id, 'a distinct new page id was returned' );
if ( $new_id > 0 ) {
	live_assert_not_forbidden_post_id( $new_id );
	$GLOBALS['diviops_live_scratch_post_ids'][] = $new_id;
}

$after = live_get_post_content( $new_id );

assert_same(
	$fixture,
	$after,
	'the duplicate is byte-for-byte identical to the source — divi/global-layout was not materialized into its resolved content'
);
assert_true(
	false !== strpos( $after, 'globalModule":"900296"' ),
	'the specific real globalModule id survived, not just a wrapper-shaped string'
);
assert_true(
	false !== strpos( $after, 'wp:difl/faq' ) && false !== strpos( $after, 'wp:difl/faqitem' ),
	'third-party difl/* markup survived the real parse/duplicate path'
);
assert_same(
	substr_count( $before, 'wp:divi/' ) + substr_count( $before, 'wp:difl/' ),
	substr_count( $after, 'wp:divi/' ) + substr_count( $after, 'wp:difl/' ),
	'block-comment opener count is unchanged — no block was silently expanded or dropped'
);

echo "PASS: page-duplicate-real-markup (8 assertions)\n";
