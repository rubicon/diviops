<?php
// SPDX-License-Identifier: MIT
/**
 * Framework-error envelope coverage for `/diviops/v1/*` (#357).
 *
 * `wrap_rest_framework_validation_errors()` is hooked on `rest_post_dispatch` and
 * converts errors WordPress itself emits — the ones no handler ever sees — into the
 * DiviOps envelope. It allowlisted two parameter-validation codes, so a capability
 * refusal and an unknown route still returned a raw `{code, message, data:{status}}`
 * body while every other refusal on the namespace returned `{ok:false, error:{...}}`.
 * Two response shapes for the same class of failure on the same namespace.
 *
 * The scope is every route, not the handful of gates that emit a `WP_Error`
 * explicitly. WordPress synthesizes `rest_forbidden` on its own whenever a
 * `permission_callback` returns false (`WP_REST_Server::dispatch()`, verified in
 * core 7.1 at `class-wp-rest-server.php:1259-1271`), and this plugin's five generic
 * gates all return bools — so the raw shape reached callers of all 110
 * `permission_callback` registrations, not four of them. That premise is asserted
 * below rather than assumed, so it cannot rot silently if a gate's return type
 * changes.
 *
 * Upstream's answer was to repeat the capability check inside each handler and
 * convert it there. Rejected in PR #351: the in-handler copy is unreachable behind
 * the route callback, and it puts the capability policy in a second place that can
 * drift from the gate that actually runs.
 *
 * The granular slug is preserved at `error.data.wp_error_code` rather than surfaced
 * as `error.code`, matching what the two parameter-validation codes have always
 * done and what `skills/divi-5-builder/references/tools.md` already documents.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Run the real filter over a raw framework-error body.
 *
 * @param string $route  Route the request was dispatched against.
 * @param array  $body   Raw WP_Error-shaped body as `error_to_response()` renders it.
 * @param int    $status HTTP status the response object carries.
 * @return WP_REST_Response
 */
function diviops_rfe_wrap( string $route, array $body, int $status = 400 ) {
	$response = new WP_REST_Response( $body, $status );
	return DiviOps_Agent::wrap_rest_framework_validation_errors(
		$response,
		null,
		new WP_REST_Request( 'POST', $route )
	);
}

/**
 * Build the raw body WordPress renders for a WP_Error.
 *
 * @param string $code Error code.
 * @param array  $data Error data, including `status`.
 * @return array
 */
function diviops_rfe_body( string $code, array $data = array() ) {
	return array(
		'code'    => $code,
		'message' => 'Sorry, you are not allowed to do that.',
		'data'    => $data,
	);
}

/* -------------------------------------------------------------------------
 * The harness reaches the real filter.
 *
 * Both codes below already worked before #357. If either of these fails, every
 * other assertion in this file is meaningless — a request object whose route the
 * filter cannot read takes the `method_exists()` early return and hands the body
 * back untouched, which is indistinguishable from "the new codes are unhandled".
 * ---------------------------------------------------------------------- */

$data = diviops_rfe_wrap( '/diviops/v1/page/create', diviops_rfe_body( 'rest_invalid_param', array( 'status' => 400 ) ) )->get_data();
assert_true( false === $data['ok'], 'rest_invalid_param is enveloped, so the filter is reachable from this harness' );
assert_same( 'invalid_input', $data['error']['code'], 'rest_invalid_param keeps mapping to invalid_input' );
assert_same( 'rest_invalid_param', $data['error']['data']['wp_error_code'], 'the originating slug is preserved under error.data' );

$data = diviops_rfe_wrap( '/diviops/v1/page/create', diviops_rfe_body( 'rest_missing_callback_param', array( 'status' => 400 ) ) )->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'rest_missing_callback_param keeps mapping to invalid_input' );

/* -------------------------------------------------------------------------
 * Capability refusals envelope as `forbidden`.
 * ---------------------------------------------------------------------- */

$response = diviops_rfe_wrap( '/diviops/v1/library/save', diviops_rfe_body( 'rest_forbidden', array( 'status' => 403 ) ), 403 );
$data     = $response->get_data();
assert_true( false === $data['ok'], 'a capability refusal returns the DiviOps envelope' );
assert_same( 'forbidden', $data['error']['code'], 'rest_forbidden maps to the vocabulary code forbidden' );
assert_same( 'rest_forbidden', $data['error']['data']['wp_error_code'], 'rest_forbidden preserves its slug under error.data' );
assert_same( 403, $response->get_status(), 'a 403 refusal stays a 403' );
assert_true( isset( $data['error']['hint'] ) && '' !== $data['error']['hint'], 'the forbidden envelope carries an actionable hint' );

/* The anonymous case. `rest_authorization_required_code()` returns 401 when nobody
 * is logged in, and the status is preserved verbatim rather than normalized to the
 * 403 the vocabulary documents — inventing a 403 would tell an unauthenticated
 * caller its credentials were rejected when it never sent any. */
$response = diviops_rfe_wrap( '/diviops/v1/page/list', diviops_rfe_body( 'rest_forbidden', array( 'status' => 401 ) ), 401 );
assert_same( 'forbidden', $response->get_data()['error']['code'], 'an anonymous refusal is still forbidden' );
assert_same( 401, $response->get_status(), 'a 401 refusal is preserved, not rewritten to 403' );

/* -------------------------------------------------------------------------
 * The request-aware gates' structured data survives the conversion.
 * ---------------------------------------------------------------------- */

$data = diviops_rfe_wrap(
	'/diviops/v1/canvas/create',
	diviops_rfe_body( 'rest_cannot_create', array( 'status' => 403, 'post_type' => 'et_pb_canvas', 'required_capability' => 'edit_pages' ) ),
	403
)->get_data();
assert_same( 'forbidden', $data['error']['code'], 'rest_cannot_create maps to forbidden' );
assert_same( 'rest_cannot_create', $data['error']['data']['wp_error_code'], 'rest_cannot_create preserves its slug' );
assert_same( 'et_pb_canvas', $data['error']['data']['post_type'], 'post_type survives the conversion' );
assert_same( 'edit_pages', $data['error']['data']['required_capability'], 'required_capability survives the conversion' );

$data = diviops_rfe_wrap(
	'/diviops/v1/page/create',
	diviops_rfe_body( 'rest_cannot_publish', array( 'status' => 403, 'post_type' => 'page', 'requested_status' => 'publish' ) ),
	403
)->get_data();
assert_same( 'forbidden', $data['error']['code'], 'rest_cannot_publish maps to forbidden' );
assert_same( 'rest_cannot_publish', $data['error']['data']['wp_error_code'], 'rest_cannot_publish preserves its slug' );
assert_same( 'publish', $data['error']['data']['requested_status'], 'requested_status survives the conversion' );

$data = diviops_rfe_wrap( '/diviops/v1/page/update-status/9', diviops_rfe_body( 'rest_cannot_edit', array( 'status' => 403 ) ), 403 )->get_data();
assert_same( 'forbidden', $data['error']['code'], 'rest_cannot_edit maps to forbidden' );
assert_same( 'rest_cannot_edit', $data['error']['data']['wp_error_code'], 'rest_cannot_edit preserves its slug' );

/* -------------------------------------------------------------------------
 * An unmatched route in the namespace.
 *
 * `serve_request()` builds the request from the requested path before dispatch
 * (core 7.1, `class-wp-rest-server.php:376`), so `get_route()` still reports
 * `/diviops/v1/<whatever>` when no route matched and the namespace guard holds.
 * A wrong HTTP method on a real route produces the same code.
 * ---------------------------------------------------------------------- */

$response = diviops_rfe_wrap( '/diviops/v1/no-such-route', diviops_rfe_body( 'rest_no_route', array( 'status' => 404 ) ), 404 );
$data     = $response->get_data();
assert_same( 'not_found', $data['error']['code'], 'rest_no_route maps to not_found, not forbidden' );
assert_same( 'rest_no_route', $data['error']['data']['wp_error_code'], 'rest_no_route preserves its slug' );
assert_same( 404, $response->get_status(), 'an unmatched route stays a 404' );

/* -------------------------------------------------------------------------
 * Per-code status fallback when the body carries no `data.status`.
 *
 * The single 400 fallback was correct while the allowlist held only
 * parameter-validation codes. It would have reported a capability refusal as a
 * client-input error.
 * ---------------------------------------------------------------------- */

assert_same( 400, diviops_rfe_wrap( '/diviops/v1/page/create', array( 'code' => 'rest_invalid_param', 'message' => 'x' ) )->get_status(), 'a validation code with no status falls back to 400' );
assert_same( 403, diviops_rfe_wrap( '/diviops/v1/page/create', array( 'code' => 'rest_forbidden', 'message' => 'x' ) )->get_status(), 'a permission code with no status falls back to 403, not 400' );
assert_same( 404, diviops_rfe_wrap( '/diviops/v1/nope', array( 'code' => 'rest_no_route', 'message' => 'x' ) )->get_status(), 'rest_no_route with no status falls back to 404' );

/* -------------------------------------------------------------------------
 * Everything else is passed through untouched.
 * ---------------------------------------------------------------------- */

$body     = diviops_rfe_body( 'rest_forbidden', array( 'status' => 403 ) );
$response = new WP_REST_Response( $body, 403 );

$same = DiviOps_Agent::wrap_rest_framework_validation_errors( $response, null, new WP_REST_Request( 'POST', '/wp/v2/posts' ) );
assert_true( $same === $response, 'a route outside the namespace is returned untouched' );

$same = DiviOps_Agent::wrap_rest_framework_validation_errors( $response, null, new WP_REST_Request( 'POST', '/diviopsx/v1/page/create' ) );
assert_true( $same === $response, 'a namespace that merely shares a prefix is not claimed' );

$other    = new WP_REST_Response( diviops_rfe_body( 'rest_cookie_invalid_nonce', array( 'status' => 403 ) ), 403 );
$same     = DiviOps_Agent::wrap_rest_framework_validation_errors( $other, null, new WP_REST_Request( 'POST', '/diviops/v1/page/create' ) );
assert_true( $same === $other, 'a framework code outside the allowlist is returned untouched' );

$success  = new WP_REST_Response( array( 'ok' => true, 'data' => array() ), 200 );
$same     = DiviOps_Agent::wrap_rest_framework_validation_errors( $success, null, new WP_REST_Request( 'POST', '/diviops/v1/page/create' ) );
assert_true( $same === $success, 'a body without code/message is returned untouched' );

/* -------------------------------------------------------------------------
 * Premise checks, so the reasoning above cannot rot.
 * ---------------------------------------------------------------------- */

/* WordPress only synthesizes `rest_forbidden` for a callback that returns false.
 * If one of these grew a WP_Error return, its refusal would carry a different code
 * and would need adding to the map. */
foreach ( array( 'check_read_permission', 'check_write_permission', 'check_admin_permission', 'check_authenticated_permission', 'check_menu_permission' ) as $gate ) {
	assert_true(
		is_bool( DiviOps_Agent::$gate() ),
		sprintf( '%s returns a bool, so WordPress synthesizes rest_forbidden for its refusal', $gate )
	);
}

/* Every `rest_*` code the plugin raises from a WP_Error is covered by the map. A
 * new gate emitting, say, `rest_cannot_delete` would otherwise reintroduce the raw
 * shape on one route with nothing to report it. */
$source = file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );
assert_true( is_string( $source ) && '' !== $source, 'the plugin main file is readable for the code scan' );

preg_match_all( "/new WP_Error\(\s*\n?\s*'(rest_[a-z_]+)'/", $source, $matches );
$raised = array_values( array_unique( $matches[1] ) );
sort( $raised );
assert_true( count( $raised ) >= 4, 'the scan actually found WP_Error rest_* codes to check' );

foreach ( $raised as $code ) {
	$data = diviops_rfe_wrap( '/diviops/v1/page/create', diviops_rfe_body( $code, array( 'status' => 403 ) ), 403 )->get_data();
	assert_true(
		is_array( $data ) && isset( $data['ok'] ) && false === $data['ok'],
		sprintf( '%s is raised by a permission gate and is enveloped', $code )
	);
}
