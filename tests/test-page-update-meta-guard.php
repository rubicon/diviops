<?php
// SPDX-License-Identifier: MIT
/**
 * page_update_content Divi-meta re-init guard (#45).
 *
 * page_update_content called initialize_divi_page_meta() on EVERY write whose
 * content is Divi content, with no post_type. That re-stamped the page meta each
 * time: _et_pb_page_layout back to full-width (clobbering a custom layout) and
 * _et_pb_built_for_post_type back to 'page' (mis-keying a non-page post that #31
 * now lets you author with Divi content). The fix only initializes the FIRST time
 * a post becomes a Divi page, and stamps the post's real type.
 *
 * These cover the pure guard (should_init_divi_page_meta_on_write) and the wiring.
 * The full page_update_content write path is live-only (same boundary the other
 * write-handler suites draw).
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$GLOBALS['diviops_test_posts']     = array();
$GLOBALS['diviops_test_post_meta'] = array();

$divi  = '<!-- wp:divi/section --><!-- wp:divi/text --><!-- /wp:divi/text --><!-- /wp:divi/section -->';
$plain = '<p>hello world</p>';

// (1) First Divi content on a post with no Divi meta yet -> initialize.
diviops_test_register_post( 700, '', 'post', 'A Post' );
assert_true(
	diviops_call( 'should_init_divi_page_meta_on_write', array( 700, $divi ) ),
	'the first Divi-content write on a fresh post initializes the Divi meta'
);

// (2) Divi content on a post ALREADY set up as a Divi page -> do NOT re-init.
// This is the fix: no re-stamp of _et_pb_page_layout / _et_pb_built_for_post_type.
diviops_test_register_post( 701, '', 'post', 'Already Divi' );
update_post_meta( 701, '_et_pb_use_builder', 'on' );
assert_true(
	! diviops_call( 'should_init_divi_page_meta_on_write', array( 701, $divi ) ),
	'an already-initialized Divi post is not re-initialized on a later write (no meta re-stamp)'
);

// (3) Non-Divi content never initializes Divi meta.
diviops_test_register_post( 702, '', 'page', 'Plain' );
assert_true(
	! diviops_call( 'should_init_divi_page_meta_on_write', array( 702, $plain ) ),
	'a non-Divi content write does not initialize Divi meta'
);

// ── wiring: page_update_content uses the guard and stamps the real type ────
$src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-page.php' );
assert_true(
	1 === preg_match( '/should_init_divi_page_meta_on_write\(\s*\$post_id,\s*\$content\s*\)/', $src ),
	'page_update_content gates the Divi-meta init behind the new guard'
);
assert_true(
	1 === preg_match( '/initialize_divi_page_meta\(\s*\$post_id,\s*\(string\)\s*\$post->post_type\s*\)/', $src ),
	're-init passes the post real post_type, not a hardcoded page'
);
assert_true(
	method_exists( 'DiviOps_Agent', 'should_init_divi_page_meta_on_write' ),
	'the guard helper is mixed into DiviOps_Agent'
);
