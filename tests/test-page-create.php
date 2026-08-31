<?php
// SPDX-License-Identifier: MIT
/**
 * page_create() post_type support (#31 / G2).
 *
 * page_create hardcoded post_type => 'page' in wp_insert_post and never read a
 * post_type param, so the one Divi-authoring domain that could create content
 * was locked to pages even though page_list already validates a post_type and
 * the read/edit paths are post-type-agnostic. This adds an optional validated
 * post_type param (default 'page').
 *
 * Design divergence from page_list, asserted here: page_list SILENTLY falls back
 * to 'page' on an unknown post_type — harmless for a read. page_create is a
 * write, where silently retargeting a create to the wrong post type is a footgun
 * (a caller asking for a 'product' would get a 'page' with no signal), so an
 * unknown post_type is a hard invalid_input (400) instead.
 *
 * Coverage runs the real handler: the dry-run path proves the resolved post_type
 * flows into the plan without mutating, and the non-dry-run path asserts the
 * value the handler hands to wp_insert_post (recorded by the harness shim) — the
 * exact spot the hardcoded 'page' used to live. get_post_stati / post_type_exists
 * / wp_insert_post are modeled by faithful WP-core shims, the same category as
 * the suite's existing get_post shim.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$GLOBALS['diviops_test_posts']       = array();
$GLOBALS['diviops_test_next_id']     = 9000;

// 'page' and 'post' come from the shim's built-in registry; a site custom post type
// is registered the way a site registers one, so post_type_exists() and the
// `'any'` resolution answer from the same place.
diviops_test_register_post_type( 'product', array( 'public' => true ) );

function diviops_pc_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

// ── dry-run plan reflects the resolved post_type (no mutation) ─────────────

// (1) Omitting post_type defaults to 'page' — backward compatible.
$resp = diviops_call( 'page_create', array( diviops_pc_request( array( 'title' => 'Home', 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_true( true === $data['data']['dry_run'], 'dry_run response carries the dry_run flag' );
assert_same( 'page', $data['data']['plan']['changes'][0]['after']['post_type'], 'default post_type in the plan is page' );

// (2) A supplied, registered post_type flows into the plan.
$resp = diviops_call( 'page_create', array( diviops_pc_request( array( 'title' => 'Blog Post', 'post_type' => 'post', 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_same( 'post', $data['data']['plan']['changes'][0]['after']['post_type'], 'a supplied post_type is reflected in the plan' );

// ── strict validation: unknown post_type is rejected, not silently retargeted ─

// (3) The write-safety divergence from page_list.
$resp = diviops_call( 'page_create', array( diviops_pc_request( array( 'title' => 'X', 'post_type' => 'no_such_type', 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_true( false === $data['ok'], 'an unknown post_type returns an error envelope' );
assert_same( 'invalid_input', $data['error']['code'], 'an unknown post_type is invalid_input, not a silent fallback' );
assert_same( 400, $resp->get_status(), 'invalid post_type is HTTP 400' );
assert_same( 'post_type', $data['error']['data']['field'], 'the error names the offending field' );

// ── the real create hands the resolved post_type to wp_insert_post ────────

// (4) The fix's core: the value that used to be a hardcoded 'page'.
unset( $GLOBALS['diviops_test_last_insert'] );
$resp = diviops_call( 'page_create', array( diviops_pc_request( array( 'title' => 'A Post', 'post_type' => 'post', 'status' => 'draft' ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a real create with a valid post_type succeeds' );
assert_true( is_int( $data['data']['page_id'] ), 'the response carries the new post id' );
assert_same( 'post', $GLOBALS['diviops_test_last_insert']['post_type'], 'wp_insert_post received the resolved post_type, not a hardcoded page' );
// The Divi builder keys off _et_pb_built_for_post_type; it must reflect the real
// created type, not a hardcoded 'page'. This locks the initialize_divi_page_meta
// threading so dropping the $post_type arg (reverting to the old hardcoded 'page')
// fails a test instead of silently regressing.
$new_post_id = $data['data']['page_id'];
assert_same(
	'post',
	$GLOBALS['diviops_test_post_meta'][ $new_post_id ]['_et_pb_built_for_post_type'] ?? null,
	'_et_pb_built_for_post_type meta reflects the created post_type, not a hardcoded page'
);

// (5) Default create still asks WordPress for a page.
unset( $GLOBALS['diviops_test_last_insert'] );
$resp = diviops_call( 'page_create', array( diviops_pc_request( array( 'title' => 'A Page', 'status' => 'draft' ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a real create with no post_type succeeds' );
assert_same( 'page', $GLOBALS['diviops_test_last_insert']['post_type'], 'the default create still targets post_type page' );

// ── structural regression: route advertises the post_type arg ─────────────

$plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );
assert_true(
	1 === preg_match(
		"/'\\/page\\/create',\\s*\\[.*?'args'\\s*=>\\s*\\[.*?'post_type'\\s*=>/s",
		$plugin_src
	),
	'the /page/create route declares a post_type arg'
);
