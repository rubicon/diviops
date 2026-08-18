<?php
// SPDX-License-Identifier: MIT
/**
 * revision_list / revision_get / revision_restore / revision_diff (#34).
 *
 * WordPress stores post revisions natively: a revision is a post of type
 * `revision` whose `post_parent` is the edited post, listed via
 * wp_get_post_revisions() and rolled back via wp_restore_post_revision(). The
 * `revision` capability domain exposes WordPress's own revisions alongside the
 * plugin's separate `rollback` snapshot system — read (list/get), a simple
 * checksum/byte diff, and a restore.
 *
 * These tests exercise the real handlers against the harness post registry and
 * faithful WP-core primitives (get_post / wp_get_post_revisions /
 * wp_restore_post_revision) shimmed in wp-shim.php — the same category of stub the
 * suite already relies on for get_post / wp_trash_post / wp_insert_post, modeling
 * WordPress's documented revision contract, NOT Divi's proprietary storage.
 *
 * current_user_can is a fixed-true stub in the harness, so can_inspect_post_object
 * always allows and current_user_can('edit_post') always passes. Every 403 forbidden
 * branch (revision_list read-gate, revision_get parent gate, revision_restore
 * edit_post gate, revision_diff parent gate) is therefore INSPECTED-ONLY here: its
 * presence is asserted against the trait source at the end of this file, exactly as
 * library_delete's per-object forbidden branch is. Everything else — the not_found
 * and wrong-post_type guards, the list/get response shapes, the dry-run plan and its
 * no-mutation guarantee, the real restore mutating the parent's content, and the diff
 * computation (identical, byte_delta, different-parent rejection) — runs against real
 * plugin code.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$GLOBALS['diviops_test_posts'] = array();

function diviops_rev_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

// ── revision_list ──────────────────────────────────────────────────────────

// (1) A parent id with no post behind it is a not_found, not a fatal.
$resp = diviops_call( 'revision_list', array( diviops_rev_request( array( 'id' => 4242 ) ) ) );
$data = $resp->get_data();
assert_true( false === $data['ok'], 'revision_list on a missing post returns an error envelope' );
assert_same( 'not_found', $data['error']['code'], 'a missing post is not_found' );
assert_same( 404, $resp->get_status(), 'revision_list not_found carries HTTP 404' );

// (2) A post with revisions lists them newest-first with the documented row shape.
diviops_test_register_post( 100, 'CURRENT CONTENT', 'page', 'Parent Page' );
diviops_test_register_revision( array( 'ID' => 101, 'post_parent' => 100, 'post_content' => 'v1', 'post_author' => 7, 'post_date' => '2026-01-01 10:00:00', 'post_modified' => '2026-01-01 10:00:00' ) );
diviops_test_register_revision( array( 'ID' => 102, 'post_parent' => 100, 'post_content' => 'version two', 'post_author' => 7, 'post_date' => '2026-01-02 10:00:00', 'post_modified' => '2026-01-02 10:00:00' ) );
diviops_test_register_revision( array( 'ID' => 103, 'post_parent' => 100, 'post_content' => 'v-three-longer', 'post_author' => 9, 'post_date' => '2026-01-03 10:00:00', 'post_modified' => '2026-01-03 10:00:00' ) );
// A revision of a DIFFERENT post must not appear in this post's list.
diviops_test_register_post( 200, 'OTHER', 'page', 'Other Page' );
diviops_test_register_revision( array( 'ID' => 201, 'post_parent' => 200, 'post_content' => 'other-rev', 'post_author' => 1, 'post_date' => '2026-01-04 10:00:00' ) );

$resp = diviops_call( 'revision_list', array( diviops_rev_request( array( 'id' => 100 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'revision_list succeeds for a real post' );
assert_same( 100, $data['data']['post_id'], 'the response echoes the post id' );
assert_same( 3, count( $data['data']['revisions'] ), 'only the three revisions of post 100 are listed' );
assert_same( 103, $data['data']['revisions'][0]['id'], 'revisions are newest-first (highest date first)' );
assert_same( 101, $data['data']['revisions'][2]['id'], 'the oldest revision is last' );
assert_same( '2026-01-03 10:00:00', $data['data']['revisions'][0]['date'], 'the row date is post_modified' );
assert_same( 9, $data['data']['revisions'][0]['author'], 'the row author is the integer post_author' );
assert_same( strlen( 'v-three-longer' ), $data['data']['revisions'][0]['byte_length'], 'byte_length is strlen(post_content)' );

// (3) A post with no revisions lists an empty set, still a success.
diviops_test_register_post( 300, 'x', 'page', 'No Revisions' );
$resp = diviops_call( 'revision_list', array( diviops_rev_request( array( 'id' => 300 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a post with no revisions still succeeds' );
assert_same( 0, count( $data['data']['revisions'] ), 'no revisions yields an empty list' );

// ── revision_get ───────────────────────────────────────────────────────────

// (4) A missing revision id is not_found.
$resp = diviops_call( 'revision_get', array( diviops_rev_request( array( 'revision_id' => 9999 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'a missing revision id is not_found' );
assert_same( 404, $resp->get_status(), 'revision_get not_found carries HTTP 404' );

// (5) A real post that is NOT a revision must be rejected as not_found (the
// post_type guard that stops revision_get returning arbitrary post content).
$resp = diviops_call( 'revision_get', array( diviops_rev_request( array( 'revision_id' => 100 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'a non-revision post is rejected by revision_get' );

// (5b) Targeted post_type-guard coverage: a non-revision post whose parent IS a
// valid, readable post. Post 100's parent is 0 (the read-gate denies that on its
// own), so (5) would still fail if the post_type check were dropped — but only
// via the read-gate, coincidentally. Here the parent (100) is readable, so ONLY
// the post_type guard can produce not_found. This fails if that guard is removed.
$child_page = diviops_test_register_post( 110, 'CHILD PAGE CONTENT', 'page', 'Child Page' );
$child_page->post_parent = 100; // a real, inspectable parent
$resp = diviops_call( 'revision_get', array( diviops_rev_request( array( 'revision_id' => 110 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'a non-revision post with a valid readable parent is still rejected by the post_type guard, not merely the parent read-gate' );

// (6) A real revision returns the full read shape including raw content.
$resp = diviops_call( 'revision_get', array( diviops_rev_request( array( 'revision_id' => 102 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'revision_get succeeds for a real revision' );
assert_same( 102, $data['data']['id'], 'the revision id is echoed' );
assert_same( 100, $data['data']['parent'], 'the parent post id is reported' );
assert_same( 'version two', $data['data']['content_raw'], 'content_raw is the revision post_content' );
assert_same( strlen( 'version two' ), $data['data']['byte_length'], 'byte_length matches the content' );
assert_same( 7, $data['data']['author'], 'the author is the integer post_author' );

// ── revision_restore: dry-run plan (no mutation) ───────────────────────────

// (7) dry_run returns a plan and does NOT touch the parent content.
$resp = diviops_call( 'revision_restore', array( diviops_rev_request( array( 'revision_id' => 101, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_true( true === $data['data']['dry_run'], 'dry_run response carries the dry_run flag' );
assert_same( 'revision.restore', $data['data']['plan']['changes'][0]['kind'], 'the plan change kind is revision.restore' );
assert_same( 'post#100', $data['data']['plan']['changes'][0]['target'], 'the plan targets the parent post' );
assert_same( 'revision#101', $data['data']['plan']['changes'][0]['after'], 'the plan after-state names the revision' );
assert_same( 101, $data['data']['revision_id'], 'the plan extra carries revision_id' );
assert_same( 100, $data['data']['parent'], 'the plan extra carries the parent id' );
assert_same( 'CURRENT CONTENT', $GLOBALS['diviops_test_posts'][100]->post_content, 'dry_run did NOT mutate the parent content' );

// (8) restore of a missing revision is not_found; a non-revision post is not_found.
$resp = diviops_call( 'revision_restore', array( diviops_rev_request( array( 'revision_id' => 9999 ) ) ) );
assert_same( 'not_found', $resp->get_data()['error']['code'], 'restore of a missing revision is not_found' );
$resp = diviops_call( 'revision_restore', array( diviops_rev_request( array( 'revision_id' => 100 ) ) ) );
assert_same( 'not_found', $resp->get_data()['error']['code'], 'restore targeting a non-revision post is not_found' );

// ── revision_restore: the real restore ─────────────────────────────────────

// (9) A live restore copies the revision's content onto the parent in the store.
$resp = diviops_call( 'revision_restore', array( diviops_rev_request( array( 'revision_id' => 101 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a live restore succeeds' );
assert_true( true === $data['data']['restored'], 'the response reports restored:true' );
assert_same( 100, $data['data']['parent'], 'the response reports the parent id' );
assert_same( 101, $data['data']['restored_from_revision'], 'the response reports the source revision id' );
assert_same( 'v1', $GLOBALS['diviops_test_posts'][100]->post_content, 'the parent content is now the revision content' );

// ── revision_diff ──────────────────────────────────────────────────────────

// Reset the parent content so the "against current" diff is deterministic.
$GLOBALS['diviops_test_posts'][100]->post_content = 'CURRENT CONTENT';

// (10) from is required.
$resp = diviops_call( 'revision_diff', array( diviops_rev_request( array() ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'revision_diff with no from is invalid_input' );

// (11) from must be a real revision.
$resp = diviops_call( 'revision_diff', array( diviops_rev_request( array( 'from' => 9999 ) ) ) );
assert_same( 'not_found', $resp->get_data()['error']['code'], 'revision_diff from a missing revision is not_found' );
$resp = diviops_call( 'revision_diff', array( diviops_rev_request( array( 'from' => 100 ) ) ) );
assert_same( 'not_found', $resp->get_data()['error']['code'], 'revision_diff from a non-revision post is not_found' );

// (12) two revisions of the same post: checksum + byte_delta.
$resp = diviops_call( 'revision_diff', array( diviops_rev_request( array( 'from' => 101, 'to' => 102 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'revision_diff of two same-post revisions succeeds' );
assert_same( 101, $data['data']['from']['id'], 'from id is reported' );
assert_same( 102, $data['data']['to']['id'], 'to id is reported' );
assert_same( strlen( 'v1' ), $data['data']['from']['bytes'], 'from bytes is strlen of the from content' );
assert_same( strlen( 'version two' ), $data['data']['to']['bytes'], 'to bytes is strlen of the to content' );
assert_same( strlen( 'version two' ) - strlen( 'v1' ), $data['data']['byte_delta'], 'byte_delta is to_bytes - from_bytes' );
assert_true( false === $data['data']['identical'], 'differing content is not identical' );
assert_same( 'sha256:' . hash( 'sha256', 'v1' ), $data['data']['from']['checksum'], 'from checksum is sha256 of its content' );
assert_same( 'sha256:' . hash( 'sha256', 'version two' ), $data['data']['to']['checksum'], 'to checksum is sha256 of its content' );

// (13) identical revisions report identical:true and a zero delta.
diviops_test_register_revision( array( 'ID' => 104, 'post_parent' => 100, 'post_content' => 'v1', 'post_author' => 7, 'post_date' => '2026-01-05 10:00:00' ) );
$resp = diviops_call( 'revision_diff', array( diviops_rev_request( array( 'from' => 101, 'to' => 104 ) ) ) );
$data = $resp->get_data();
assert_true( true === $data['data']['identical'], 'byte-identical revisions report identical:true' );
assert_same( 0, $data['data']['byte_delta'], 'identical content yields a zero byte_delta' );

// (14) omitting `to` compares against the parent post's CURRENT content, with to.id 0.
$resp = diviops_call( 'revision_diff', array( diviops_rev_request( array( 'from' => 101 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'revision_diff against current content succeeds' );
assert_same( 0, $data['data']['to']['id'], 'to.id is 0 when diffing against current content' );
assert_same( strlen( 'CURRENT CONTENT' ), $data['data']['to']['bytes'], 'to bytes is the parent current content length' );
assert_same( strlen( 'CURRENT CONTENT' ) - strlen( 'v1' ), $data['data']['byte_delta'], 'byte_delta is current - from' );

// (15) revisions of DIFFERENT posts cannot be diffed.
$resp = diviops_call( 'revision_diff', array( diviops_rev_request( array( 'from' => 101, 'to' => 201 ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'diffing revisions of different posts is invalid_input' );

// ── structural regressions: route wiring, capability keys, forbidden guards ─

$plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );
$trait_src  = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-revision.php' );

foreach ( array(
	'revision_list'    => "'/revision/list/(?P<id>\\d+)'",
	'revision_get'     => "'/revision/get/(?P<revision_id>\\d+)'",
	'revision_restore' => "'/revision/restore/(?P<revision_id>\\d+)'",
	'revision_diff'    => "'/revision/diff'",
) as $handler => $path_literal ) {
	assert_true(
		false !== strpos( $plugin_src, $path_literal ),
		"the {$handler} route is registered on its documented path"
	);
	assert_true(
		1 === preg_match( "#'callback'\\s*=>\\s*\\[\\s*__CLASS__,\\s*'{$handler}'\\s*\\]#", $plugin_src ),
		"the {$handler} route dispatches to the {$handler} handler"
	);
	assert_true(
		false !== strpos( $plugin_src, "'{$handler}'" ),
		"the '{$handler}' capability key is present in CAPABILITIES"
	);
	assert_true(
		method_exists( 'DiviOps_Agent', $handler ),
		"DiviOps_Agent::{$handler} exists once the trait is mixed in"
	);
}

// The read/write permission split: list/get/diff are read, restore is write.
assert_true(
	1 === preg_match(
		"#'callback'\\s*=>\\s*\\[\\s*__CLASS__,\\s*'revision_restore'\\s*\\],\\s*'permission_callback'\\s*=>\\s*\\[\\s*__CLASS__,\\s*'check_write_permission'\\s*\\]#s",
		$plugin_src
	),
	'revision_restore is write-gated (check_write_permission)'
);

// The 403 forbidden branches are inspected-only (current_user_can is fixed-true in
// the harness), so assert each guard exists in the trait source.
assert_true(
	2 <= preg_match_all( "/envelope_object_read_forbidden/", $trait_src ),
	'the read handlers include the row-level read-forbidden guard'
);
assert_true(
	1 === preg_match( "/current_user_can\\(\\s*'edit_post'/", $trait_src ),
	'revision_restore gates the write on current_user_can( edit_post )'
);
