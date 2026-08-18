<?php
// SPDX-License-Identifier: MIT
/**
 * library_delete() — programmatic removal for Divi Library items (#26).
 *
 * The `library` domain had list/get/save but no delete: removing a saved
 * et_pb_layout meant wp-admin or a raw `wp post delete`, leaving every other
 * content-holding domain (page, canvas, preset, variable, menu…) with a removal
 * path and this one without. library_delete closes that asymmetry, mirroring the
 * sibling page_trash: soft-trash by default (reversible, and post_status=>'any'
 * in the library title-uniqueness query excludes trash, so a trashed item does
 * not block re-saving the same title), an opt-in `force` for permanent deletion,
 * dry-run planning, and idempotent no-op semantics on an already-trashed item.
 *
 * These tests exercise the real handler against the harness's post registry and
 * the WP-core removal primitives (get_post / wp_trash_post / wp_delete_post) it
 * shims — the same category of stub the suite already relies on for get_post,
 * not the Divi-proprietary option storage the variable_update suite declined to
 * fake. current_user_can is a fixed-true stub here, so the per-object delete_post
 * forbidden branch is inspected-only (as it is for page_trash); everything else
 * — the not-found guards, the type guard that stops it trashing a non-library
 * post, the trash/force/no-op plan selection, and the actual mutations — runs
 * against real plugin code.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$GLOBALS['diviops_test_posts'] = array();

function diviops_libdel_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

// ── not-found guards ──────────────────────────────────────────────────────

// (1) An id with no post behind it is a not_found, not a fatal.
$resp = diviops_call( 'library_delete', array( diviops_libdel_request( array( 'id' => 4242 ) ) ) );
$data = $resp->get_data();
assert_true( false === $data['ok'], 'a missing id returns an error envelope' );
assert_same( 'not_found', $data['error']['code'], 'a missing library item is not_found' );
assert_same( 404, $resp->get_status(), 'not_found carries HTTP 404' );

// (2) A real post of the wrong type must NOT be deletable through the library
// endpoint — this is the guard that keeps library_delete from trashing an
// arbitrary page or post that happens to share an id space.
diviops_test_register_post( 500, 'x', 'page', 'A Normal Page' );
$resp = diviops_call( 'library_delete', array( diviops_libdel_request( array( 'id' => 500 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'a non-et_pb_layout post is rejected as not a library item' );
assert_true( isset( $GLOBALS['diviops_test_posts'][500] ), 'the wrong-type post is left untouched' );

// ── dry-run plans (no mutation) ───────────────────────────────────────────

// (3) Default (no force): plan to move to trash, and prove nothing mutated.
$p600 = diviops_test_register_post( 600, 'x', 'et_pb_layout', 'Hero Block' );
$p600->post_status = 'publish';
$resp = diviops_call( 'library_delete', array( diviops_libdel_request( array( 'id' => 600, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_true( true === $data['data']['dry_run'], 'dry_run response carries the dry_run flag' );
assert_same( 'trash', $data['data']['plan']['changes'][0]['kind'], 'default plan kind is trash' );
assert_same( 'trash', $data['data']['plan']['changes'][0]['after'], 'default plan end-state is trash' );
assert_same( 'publish', $GLOBALS['diviops_test_posts'][600]->post_status, 'dry_run did not change the post status' );

// (4) force: plan to permanently delete.
$resp = diviops_call( 'library_delete', array( diviops_libdel_request( array( 'id' => 600, 'force' => true, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_same( 'delete', $data['data']['plan']['changes'][0]['kind'], 'force plan kind is delete' );
assert_same( 'deleted', $data['data']['plan']['changes'][0]['after'], 'force plan end-state is deleted' );

// (5) Already-trashed, no force: the plan is a no-op.
$p700 = diviops_test_register_post( 700, 'x', 'et_pb_layout', 'Stale Block' );
$p700->post_status = 'trash';
$resp = diviops_call( 'library_delete', array( diviops_libdel_request( array( 'id' => 700, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_same( 'noop', $data['data']['plan']['changes'][0]['kind'], 'already-trashed non-force plan is a no-op' );

// ── real mutations ────────────────────────────────────────────────────────

// (6) Default delete moves the item to trash and reports it.
$p800 = diviops_test_register_post( 800, 'x', 'et_pb_layout', 'Card' );
$p800->post_status = 'publish';
$resp = diviops_call( 'library_delete', array( diviops_libdel_request( array( 'id' => 800 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a default delete succeeds' );
assert_same( 'trash', $data['data']['status'], 'default delete reports status trash' );
assert_same( 800, $data['data']['id'], 'the response echoes the id' );
assert_same( 'trash', $GLOBALS['diviops_test_posts'][800]->post_status, 'the post is actually moved to trash in the store' );

// (7) force delete removes the item entirely.
$p900 = diviops_test_register_post( 900, 'x', 'et_pb_layout', 'Doomed' );
$p900->post_status = 'publish';
$resp = diviops_call( 'library_delete', array( diviops_libdel_request( array( 'id' => 900, 'force' => true ) ) ) );
$data = $resp->get_data();
assert_same( 'deleted', $data['data']['status'], 'force delete reports status deleted' );
assert_true( ! isset( $GLOBALS['diviops_test_posts'][900] ), 'force delete removes the post from the store' );

// (8) Already-trashed, no force: idempotent success, not an error, and NOT a
// hard delete — the item is still there for a later restore.
$p1000 = diviops_test_register_post( 1000, 'x', 'et_pb_layout', 'Already Gone' );
$p1000->post_status = 'trash';
$resp = diviops_call( 'library_delete', array( diviops_libdel_request( array( 'id' => 1000 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'deleting an already-trashed item is a success, not an error' );
assert_same( 'trash', $data['data']['status'], 'already-trashed reports trash status' );
assert_true( ! empty( $data['data']['already_trashed'] ), 'the already_trashed no-op signal is preserved' );
assert_true( isset( $GLOBALS['diviops_test_posts'][1000] ), 'a non-force no-op does not hard-delete the trashed item' );

// ── structural regressions: route wiring + capability key ─────────────────

$plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );

assert_true(
	1 === preg_match(
		"/register_rest_route\\(\\s*self::REST_NAMESPACE,\\s*'\\/library\\/delete[^']*',\\s*\\[\\s*'methods'\\s*=>\\s*'POST',\\s*'callback'\\s*=>\\s*\\[\\s*__CLASS__,\\s*'library_delete'\\s*\\]/s",
		$plugin_src
	),
	'/library/delete/{id} is registered as a POST route dispatching to library_delete'
);
assert_true(
	1 === preg_match( "/'library_delete'/", $plugin_src ),
	"the 'library_delete' capability key is present in CAPABILITIES"
);
assert_true(
	method_exists( 'DiviOps_Agent', 'library_delete' ),
	'DiviOps_Agent::library_delete exists once the trait is mixed in'
);
