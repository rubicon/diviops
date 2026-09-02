<?php
// SPDX-License-Identifier: MIT
/**
 * HTTP status of the three hand-built `rest_forbidden` gates (#365).
 *
 * `diviops-agent.php` builds a `rest_forbidden` WP_Error by hand in exactly three
 * places — `fixed_publish_route_permission()` (every fixed-publish route),
 * `page_create_permission_result()` (`/page/create`), and
 * `page_update_status_permission_result()` (`/page/update-status/{id}`). All three
 * hardcoded `'status' => 403`, where WordPress's own convention for an
 * authorization refusal is `rest_authorization_required_code()`:
 *
 *     // wp-includes/rest-api.php:1438-1440
 *     function rest_authorization_required_code() {
 *         return is_user_logged_in() ? 403 : 401;
 *     }
 *
 * 403 to an anonymous caller says "your credentials were rejected" to a client that
 * sent none. 401 is the answer that tells it to authenticate and retry, and it is
 * the distinction a client's auth-retry logic branches on.
 *
 * This file is a characterization net first and a regression net second. It was
 * committed asserting the pre-fix behaviour (403 in both auth states, everywhere)
 * and run green before the gates changed, so the behaviour delta is visible in the
 * suite's own diff rather than inferred from the plugin diff.
 *
 * **Scope is three sites, not five.** The sibling refusals in the same two
 * functions — `rest_cannot_create` when a post type exposes no `create_posts`
 * capability, `rest_cannot_edit` when the target post does not resolve — fire on
 * facts about the *content*, not about the caller's credentials, so they are a
 * genuine 403 no matter who is asking. Case (E) pins one of those against an
 * anonymous caller precisely so a future sweep of "make the 403s use core's
 * helper" fails here instead of landing silently, and the structural assertion at
 * the end pins the call-site count at three for the same reason.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Reduce a permission-callback result to the two facts under test.
 *
 * Returned as one array so a failure prints the code and the status together —
 * a wrong code with a right status and the reverse are different bugs.
 *
 * @param mixed $result Whatever the permission callback returned.
 * @return array{code: string, status: mixed}
 */
function diviops_auth_code_refusal( $result ): array {
	if ( ! ( $result instanceof WP_Error ) ) {
		return array( 'code' => 'not-a-WP_Error(' . gettype( $result ) . ')', 'status' => null );
	}
	$data = $result->get_error_data();
	return array(
		'code'   => $result->get_error_code(),
		'status' => ( is_array( $data ) && array_key_exists( 'status', $data ) ) ? $data['status'] : null,
	);
}

/**
 * Model a caller: which capabilities it lacks, and whether it is logged in at all.
 *
 * The two seams are independent by design (see is_user_logged_in() in wp-shim.php).
 * That independence is what lets case (E) put an authenticated-enough caller in an
 * anonymous request, which is the only way to prove a refusal ignores auth state.
 *
 * @param string[] $denied_caps Capabilities current_user_can() must refuse.
 * @param bool     $anonymous   Whether the caller sent no credentials.
 */
function diviops_auth_code_caller( array $denied_caps, bool $anonymous ): void {
	$GLOBALS['diviops_test_denied_caps'] = $denied_caps;
	$GLOBALS['diviops_test_anonymous']   = $anonymous;
}

$forbidden_403 = array( 'code' => 'rest_forbidden', 'status' => 403 );

// ── (A) fixed_publish_route_permission(), via /canvas/create ──────────────

diviops_auth_code_caller( array( 'edit_pages' ), false );
assert_same(
	$forbidden_403,
	diviops_auth_code_refusal( DiviOps_Agent::check_canvas_create_permission() ),
	'(A) canvas create, logged in without edit_pages: rest_forbidden 403'
);

diviops_auth_code_caller( array( 'edit_pages' ), true );
assert_same(
	$forbidden_403,
	diviops_auth_code_refusal( DiviOps_Agent::check_canvas_create_permission() ),
	'(A) canvas create, anonymous: rest_forbidden 403'
);

// ── (B) the same helper reached through a different base capability ───────
//
// /library/save gates on manage_options rather than edit_pages. Asserting both
// routes proves the status belongs to the shared helper, not to one registration.

diviops_auth_code_caller( array( 'manage_options' ), false );
assert_same(
	$forbidden_403,
	diviops_auth_code_refusal( DiviOps_Agent::check_library_save_permission() ),
	'(B) library save, logged in without manage_options: rest_forbidden 403'
);

diviops_auth_code_caller( array( 'manage_options' ), true );
assert_same(
	$forbidden_403,
	diviops_auth_code_refusal( DiviOps_Agent::check_library_save_permission() ),
	'(B) library save, anonymous: rest_forbidden 403'
);

// ── (C) page_create_permission_result(), via /page/create ─────────────────
//
// The edit_pages refusal is the first statement in the function, so it returns
// before any get_post_type_object() lookup — which is why this file needs no
// post-type stub to reach it.

$create_request = new DiviOps_Test_Request( array( 'title' => 'Draft', 'status' => 'draft' ) );

diviops_auth_code_caller( array( 'edit_pages' ), false );
assert_same(
	$forbidden_403,
	diviops_auth_code_refusal( DiviOps_Agent::check_page_create_permission( $create_request ) ),
	'(C) page create, logged in without edit_pages: rest_forbidden 403'
);

diviops_auth_code_caller( array( 'edit_pages' ), true );
assert_same(
	$forbidden_403,
	diviops_auth_code_refusal( DiviOps_Agent::check_page_create_permission( $create_request ) ),
	'(C) page create, anonymous: rest_forbidden 403'
);

// ── (D) page_update_status_permission_result(), via /page/update-status ───

$status_request = new DiviOps_Test_Request( array( 'id' => 4242, 'status' => 'draft' ) );

diviops_auth_code_caller( array( 'edit_pages' ), false );
assert_same(
	$forbidden_403,
	diviops_auth_code_refusal( DiviOps_Agent::check_page_update_status_permission( $status_request ) ),
	'(D) page update-status, logged in without edit_pages: rest_forbidden 403'
);

diviops_auth_code_caller( array( 'edit_pages' ), true );
assert_same(
	$forbidden_403,
	diviops_auth_code_refusal( DiviOps_Agent::check_page_update_status_permission( $status_request ) ),
	'(D) page update-status, anonymous: rest_forbidden 403'
);

// ── (E) out of scope: a refusal about the content, not the caller ─────────
//
// edit_pages granted, request anonymous, post id 4242 does not resolve. The
// refusal is `rest_cannot_edit`, and it is 403 for everyone: there is no
// credential a caller could present that would make a missing post editable.
// This assertion must survive the fix unchanged.

diviops_auth_code_caller( array(), true );
assert_same(
	array( 'code' => 'rest_cannot_edit', 'status' => 403 ),
	diviops_auth_code_refusal( DiviOps_Agent::check_page_update_status_permission( $status_request ) ),
	'(E) unresolvable post, anonymous: rest_cannot_edit stays a flat 403'
);

// ── (F) structural: the fix landed in three places and stopped there ──────

$plugin_source = file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );
assert_true( is_string( $plugin_source ) && '' !== $plugin_source, '(F) the plugin source was read' );

/*
 * Positive control for the counts below. A count of zero is the same output
 * whether nothing matched or the pattern cannot match the shape it is looking
 * for, so anchor them on a substring known to be present in this file.
 */
assert_true(
	substr_count( $plugin_source, "'status' => 403" ) > 0,
	"(F) control: the literal \"'status' => 403\" form is present in the file, so counting it is meaningful"
);

assert_same(
	3,
	substr_count( $plugin_source, "new WP_Error( 'rest_forbidden'" ),
	'(F) exactly three hand-built rest_forbidden refusals exist'
);

/*
 * The call site, not the identifier: `wrap_rest_framework_validation_errors()`'s
 * docblock names rest_authorization_required_code() in prose (#357 shipped that
 * explanation before this fix existed), so counting the bare name reports one
 * match against a comment. Counting the array-value form cannot.
 */
assert_same(
	0,
	substr_count( $plugin_source, "'status' => rest_authorization_required_code()" ),
	'(F) characterization: no gate consults core\'s helper yet'
);

// ── Restore the harness default for the files that run after this one ─────

unset( $GLOBALS['diviops_test_anonymous'] );
$GLOBALS['diviops_test_denied_caps'] = array();
