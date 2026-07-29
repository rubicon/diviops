<?php
/**
 * page_duplicate() — same-site whole-page duplication (#35 / G4, owner-split
 * comment 2026-07-29).
 *
 * Scope, per the split: read a page's layout (reusing page_get_layout's raw
 * content) and write it, unchanged, into a fresh post (reusing page_create's
 * created-post response shape). NO reference remapping — attachment ids,
 * internal links, and global color/font/variable refs are already valid
 * because the target IS the source site. That is explicitly out of scope
 * (cross-site remapping is #96) and the response says so via
 * `references_remapped: false` rather than leaving a caller to assume it
 * happened.
 *
 * SAFETY WIRING. Being a parse/serialize round trip on the source page's
 * content, the write path routes through parse_blocks_for_write() (not bare
 * parse_blocks()) and update_post_content_with_integrity_guard() with
 * $check_global_layout_drift = true, so a source page carrying a
 * divi/global-layout wrapper cannot have that wrapper materialized into the
 * new page by the copy (#11) — the identical wiring page_block_insert (#32)
 * uses for the same reason.
 *
 * WHAT THIS FILE CAN AND CANNOT COVER, and why (mirrors
 * tests/test-page-block-insert.php's documented limitation exactly).
 * parse_blocks() and serialize_blocks() (plural) are deliberately NOT shimmed
 * in tests/wp-shim.php — see that file's docblock and #17's scoping note: a
 * faithful reimplementation is a large parser state machine, and a partial
 * one built only for the shapes a test needs would be mocking the exact
 * behavior under test rather than testing it. page_duplicate()'s non-dry-run
 * path calls parse_blocks_for_write() (which falls back to bare parse_blocks()
 * outside a real Divi install) then serialize_blocks(), so that path cannot be
 * driven end-to-end in this harness. What IS unit-tested here, honestly and
 * against real plugin code:
 *   1. Every pre-parse guard/validation branch (source not_found, invalid
 *      title/status/post_type) — all run before any parse machinery.
 *   2. The dry_run path in full, including its defaulting logic (title,
 *      status, post_type) and its explicit reference-remapping disclosure —
 *      dry_run deliberately does NOT invoke the round trip (see the code
 *      comment on the branch), so it is fully exercisable here and doubles
 *      as this suite's coverage of "default status is draft", "title
 *      defaulting", and "post_type inheritance".
 *   3. Non-Divi source handling: this build's chosen behavior is to allow the
 *      duplication (a plain WordPress page has no global-layout hazard) and
 *      report `source_uses_divi: false` rather than blocking a legitimate
 *      copy.
 *   4. Structural regression: route wiring, the `page_duplicate` capability
 *      key, and (via source inspection, the same technique
 *      test-page-block-insert.php uses for page_block_insert) that the
 *      handler actually calls parse_blocks_for_write() and passes
 *      $check_global_layout_drift = true to update_post_content_with_integrity_guard().
 * What remains uncovered here and why: the happy-path "new page created,
 * content matches source" case and the divi/global-layout survival case both
 * require a real parse_blocks()/serialize_blocks() round trip. Per the same
 * precedent as #32, that is verified LIVE on the colleyvillelions site (a
 * scratch source page carrying a divi/global-layout wrapper, duplicate it,
 * confirm the new page's wrapper is preserved not materialized), not here.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

function diviops_pd_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

// ── not_found ───────────────────────────────────────────────────────────

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 8888 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'an unknown source page id is not_found' );
assert_same( 404, $resp->get_status(), 'not_found is HTTP 404' );

// From here on, real source pages exist so the guards past not_found are reachable.

diviops_test_register_post(
	5100,
	'<!-- wp:divi/section --><!-- wp:divi/text --><!-- /wp:divi/text --><!-- /wp:divi/section -->',
	'page',
	'Landing Page'
);

diviops_test_register_post(
	5101,
	'<p>Just some classic-editor HTML, no Divi markup at all.</p>',
	'page',
	'Plain Page'
);

// ── invalid title / status / post_type (pre-parse guards) ────────────────

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100, 'title' => array( 'not', 'a', 'string' ) ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a non-string title is invalid_input' );
assert_same( 'title', $data['error']['data']['field'], 'the title error names the field' );

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100, 'status' => 'not-a-real-status' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an unrecognized status is invalid_input' );
assert_same( 'status', $data['error']['data']['field'], 'the status error names the field' );

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100, 'post_type' => 'not_a_registered_type' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an unregistered post_type is invalid_input' );
assert_same( 'post_type', $data['error']['data']['field'], 'the post_type error names the field' );

// ── dry_run: writes nothing, and reports the full plan honestly ──────────
//
// dry_run deliberately short-circuits before parse_blocks_for_write() (see
// the code comment on that branch in page_duplicate()), so it is fully
// exercisable in this harness and is this suite's vehicle for the
// defaulting-logic assertions below.

$posts_before = count( $GLOBALS['diviops_test_posts'] );

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'dry_run on a valid source succeeds' );
assert_true( $data['data']['dry_run'], 'the response is marked dry_run' );
assert_same( $posts_before, count( $GLOBALS['diviops_test_posts'] ), 'dry_run creates no post — the registry is unchanged' );

$change = $data['data']['plan']['changes'][0];
assert_same( 'page.duplicate', $change['kind'], 'the plan change is kinded page.duplicate' );
assert_same( 'Landing Page (Copy)', $change['after']['title'], 'title defaults to "<source title> (Copy)" when omitted' );
assert_same( 'draft', $change['after']['status'], 'status defaults to draft when omitted' );
assert_same( 'page', $change['after']['post_type'], 'post_type defaults to inheriting the source post_type (page) when omitted' );
assert_same( 5100, $change['after']['source_id'], 'the plan names the source page id' );

// references_remapped: false, disclosed explicitly rather than left implicit —
// the core scope decision from the #35 split comment.
assert_true(
	array_key_exists( 'references_remapped', $data['data'] ) && false === $data['data']['references_remapped'],
	'the response explicitly discloses references_remapped: false, not silence'
);
assert_true( $data['data']['source_uses_divi'], 'a source page with divi/* block markup is reported as source_uses_divi: true' );

// Explicit title is honored verbatim (no auto-suffix, unlike canvas_duplicate —
// page titles are not a WordPress uniqueness key, so no collision handling is
// needed; see this PR's report for the rationale).
$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100, 'dry_run' => true, 'title' => 'Custom Duplicate Title' ) ) ) );
$data = $resp->get_data();
assert_same( 'Custom Duplicate Title', $data['data']['plan']['changes'][0]['after']['title'], 'an explicit title is used as-is' );

// Explicit status/post_type override the defaults.
$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100, 'dry_run' => true, 'status' => 'publish', 'post_type' => 'post' ) ) ) );
$data = $resp->get_data();
assert_same( 'publish', $data['data']['plan']['changes'][0]['after']['status'], 'an explicit status overrides the draft default' );
assert_same( 'post', $data['data']['plan']['changes'][0]['after']['post_type'], 'an explicit post_type overrides source-type inheritance' );

// ── non-Divi source: allowed, disclosed, not blocked ──────────────────────

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5101, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'duplicating a non-Divi (classic content) source page is allowed, not refused' );
assert_true( ! $data['data']['source_uses_divi'], 'a non-Divi source is reported as source_uses_divi: false' );
assert_same( 'Plain Page (Copy)', $data['data']['plan']['changes'][0]['after']['title'], 'title defaulting works the same for a non-Divi source' );

// ── structural regression: route wiring + capability key + write wiring ───

$plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );
assert_true(
	1 === preg_match(
		"/register_rest_route\\(\\s*self::REST_NAMESPACE,\\s*'\\/page\\/duplicate[^']*',\\s*\\[\\s*'methods'\\s*=>\\s*'POST',\\s*'callback'\\s*=>\\s*\\[\\s*__CLASS__,\\s*'page_duplicate'\\s*\\]/s",
		$plugin_src
	),
	'/page/duplicate is registered as a POST route dispatching to page_duplicate'
);
assert_true(
	1 === preg_match( "/'page_duplicate'/", $plugin_src ),
	"the 'page_duplicate' capability key is present in CAPABILITIES"
);
assert_true(
	method_exists( 'DiviOps_Agent', 'page_duplicate' ),
	'DiviOps_Agent::page_duplicate exists once the trait is mixed in'
);

// The safety-critical wiring itself (#11): page_duplicate's write path must
// route through parse_blocks_for_write() and pass $check_global_layout_drift
// = true to update_post_content_with_integrity_guard(), the same discipline
// page_block_insert (#32) uses, so a divi/global-layout wrapper on the source
// cannot be materialized into the new page by the round trip. This is
// verified by source inspection (the same technique used above for route
// wiring) because, per this file's docblock, the round trip itself cannot be
// driven in this harness — parse_blocks()/serialize_blocks() are deliberately
// unshimmed.
$trait_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-page.php' );
$page_duplicate_start = strpos( $trait_src, 'function page_duplicate(' );
assert_true( false !== $page_duplicate_start, 'page_duplicate() is defined in trait-page.php' );
$page_duplicate_body = substr( $trait_src, $page_duplicate_start, 6000 );
assert_true(
	false !== strpos( $page_duplicate_body, 'parse_blocks_for_write(' ),
	'page_duplicate() parses the source content via parse_blocks_for_write(), not bare parse_blocks()'
);
assert_true(
	1 === preg_match( '/update_post_content_with_integrity_guard\\(.*?,\\s*true\\s*\\)/s', $page_duplicate_body ),
	'page_duplicate() passes $check_global_layout_drift = true to update_post_content_with_integrity_guard()'
);
