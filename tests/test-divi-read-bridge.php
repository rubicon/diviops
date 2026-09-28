<?php
// SPDX-License-Identifier: MIT
/**
 * The read-only bridge to Divi's own REST surface (#504).
 *
 * Divi ships 165 `divi/v1` routes behind a CSRF gate that checks an `X-ET-Nonce`
 * header against a deterministic action string. The gate is satisfiable from a
 * session-less authenticated context using `RESTController::create_nonce()`,
 * which Divi declares `public static`. Measured on staging at Divi 5.13.1 on
 * 2026-09-27: with a minted nonce `GET /divi/v1/loop/query-types` and
 * `GET /divi/v1/option-data/conditions/post-type` both returned HTTP 200; with no
 * nonce and with a wrong nonce both returned HTTP 400 `invalid_nonce`, so the
 * gate verifies rather than merely existing.
 *
 * This bridge is deliberately NOT a general proxy. Two read-only families are
 * allowlisted by name — `loop/*` (9 routes) and `option-data/conditions/*` (7) —
 * every one of them a GET with no browser-prepared state. Writes, uploads and the
 * conversion routes are declined: the standing objection to driving Divi's REST
 * surface is semantic drift in undocumented endpoints returning 200 while
 * behaving differently, and that objection applies to writes and to output we
 * would reinterpret, not to forwarding a read.
 *
 * Dispatch happens IN-PROCESS via `rest_do_request()`, not by handing a nonce to
 * the MCP client. The issue proposed the latter; this is the deliberate
 * departure. In-process means the CSRF token never leaves the server, the caller
 * makes one request instead of two, and the answer arrives inside this plugin's
 * own envelope rather than as Divi's raw response.
 *
 * Covered here:
 *
 *   - An allowlisted subroute dispatches and returns Divi's payload in our
 *     envelope, under `data.divi`, with the resolved route echoed.
 *   - The dispatched request is inspected directly: right route, right method,
 *     and a nonce that verifies against the REAL action shape
 *     (`/divi/v1/loop/query-types--GET`), not a shape of our own invention.
 *   - A subroute outside the allowlist is refused as invalid_input, names the
 *     allowed set, and dispatches NOTHING — asserted by request count, so a
 *     refusal that still called Divi would fail.
 *   - The two allowlists are per-family: a conditions subroute handed to the loop
 *     handler is refused and vice versa. A shared allowlist would pass the happy
 *     path and this is what catches it.
 *   - A Divi 4xx is reported as a failure carrying Divi's own status and code,
 *     never flattened into a 200.
 *   - `args` are forwarded to Divi; a non-scalar arg is refused before dispatch.
 *   - Both capability keys are advertised and both routes are registered.
 *
 * NOT covered, and each for a stated reason:
 *
 *   - **That a minted nonce really satisfies Divi's gate.** That is a property of
 *     Divi, not of this code, and no stub can establish it. It was established by
 *     the live probe above. `tests/divi-read-bridge-stubs.php` models the gate's
 *     shape so a bridge that forgot the header fails here, which is the most a
 *     harness can honestly do.
 *   - **The refusal when Divi is absent** (`class_exists` false on
 *     `RESTController`). A class cannot be un-declared mid-process, and the
 *     fixture that makes every other assertion here possible is what declares it.
 *     `tests/test-divi-compatibility.php` establishes the child-process precedent
 *     if this branch ever needs real coverage; reported as a gap rather than
 *     faked, per CONTRIBUTING's shim contract.
 *   - **Application Password transport.** `rest_do_request()` dispatches
 *     in-process, so no test here exercises Basic auth. The live probe did not
 *     either — it ran under WP-CLI `--user=1`. Stated so nobody reads this suite
 *     as proof of the HTTP path.
 *   - **Argument type coercion over the wire.** These tests hand the handler a
 *     real PHP array, so an integer stays an integer. Over HTTP the same argument
 *     arrives as a string and Divi's own arg schema coerces it. The assertion
 *     below pins that the bridge forwards values unchanged, which is its whole
 *     job; it is not an end-to-end type guarantee and is worded so as not to read
 *     like one.
 *
 * @package DiviOps
 */

// Stubs FIRST: they declare a header-carrying WP_REST_Request that the shim's
// own class_exists guard then yields to. See that file for why.
require_once __DIR__ . '/divi-read-bridge-stubs.php';
require_once __DIR__ . '/wp-shim.php';

/**
 * Call a bridge handler with the given params.
 *
 * @param string $method Handler name on DiviOps_Agent.
 * @param array  $params Request params.
 * @return array Decoded envelope.
 */
function diviops_drbt_call( string $method, array $params ) {
	return diviops_call( $method, array( new DiviOps_Test_Request( $params ) ) )->get_data();
}

/** The response object rather than its body. @param string $m @param array $p @return mixed */
function diviops_drbt_resp( string $m, array $p ) {
	return diviops_call( $m, array( new DiviOps_Test_Request( $p ) ) );
}

// ══ Fixtures: what Divi answers ═══════════════════════════════════════════

$drbt_loop_payload = array(
	'post_types'      => array( 'post', 'page' ),
	'user_roles'      => array( 'administrator' ),
	'post_taxonomies' => array( 'category' ),
	'menus'           => array(),
	'repeater_fields' => array(),
);
diviops_drb_route( 'GET', '/divi/v1/loop/query-types', $drbt_loop_payload );
diviops_drb_route( 'GET', '/divi/v1/option-data/conditions/post-type', array( 'post', 'page', 'attachment' ) );
diviops_drb_route( 'GET', '/divi/v1/loop/query-results', array( 'code' => 'rest_forbidden' ), 403 );

// ══ 1. An allowlisted loop read forwards and is wrapped ═══════════════════

diviops_drb_reset_requests();
$drbt_ok = diviops_drbt_call( 'divi_loop_read', array( 'subroute' => 'query-types' ) );

assert_true( true === ( $drbt_ok['ok'] ?? null ), '#504: an allowlisted loop subroute succeeds' );
assert_same( '/divi/v1/loop/query-types', $drbt_ok['data']['route'] ?? null, '#504: and echoes the route it resolved, so a caller can see what was actually called rather than trusting the subroute it sent' );
assert_same( $drbt_loop_payload, $drbt_ok['data']['divi'] ?? null, '#504: Divi\'s payload is returned verbatim under data.divi, not merged into our own keys where it could collide' );

$drbt_req = diviops_drb_last_request();
assert_same( '/divi/v1/loop/query-types', $drbt_req['route'] ?? null, '#504: the dispatched request targets the full Divi route' );
assert_same( 'GET', $drbt_req['method'] ?? null, '#504: as a GET, because every allowlisted route is a read' );
assert_same( 1, wp_verify_nonce( (string) ( $drbt_req['nonce'] ?? '' ), '/divi/v1/loop/query-types--GET' ), '#504: carrying a nonce that verifies against the REAL action shape read off the live install, not a shape of our own invention' );
assert_true( 1 !== wp_verify_nonce( (string) ( $drbt_req['nonce'] ?? '' ), '/divi/v1/loop/query-types--POST' ), '#504: control: that same nonce does NOT verify for a different method, so the action string is genuinely method-bound' );

// ══ 2. A conditions read works the same way ═══════════════════════════════

diviops_drb_reset_requests();
$drbt_cond = diviops_drbt_call( 'divi_conditions_read', array( 'subroute' => 'post-type' ) );
assert_true( true === ( $drbt_cond['ok'] ?? null ), '#504: an allowlisted conditions subroute succeeds' );
assert_same( '/divi/v1/option-data/conditions/post-type', $drbt_cond['data']['route'] ?? null, '#504: under the option-data/conditions prefix, which is a different family from loop' );
assert_same( array( 'post', 'page', 'attachment' ), $drbt_cond['data']['divi'] ?? null, '#504: returning Divi\'s list verbatim' );

// ══ 3. Anything outside the allowlist is refused, and calls nothing ═══════

diviops_drb_reset_requests();
$drbt_deny_resp = diviops_drbt_resp( 'divi_loop_read', array( 'subroute' => 'query-types/../../outside-vb/export-layout' ) );
$drbt_deny      = $drbt_deny_resp->get_data();
assert_true( false === ( $drbt_deny['ok'] ?? null ), '#504: a subroute outside the allowlist is refused' );
assert_same( 'invalid_input', $drbt_deny['error']['code'] ?? null, '#504: as invalid_input' );
assert_same( 400, $drbt_deny_resp->get_status(), '#504: at HTTP 400' );
assert_same( 0, count( $GLOBALS['diviops_drb_requests'] ), '#504: and dispatches NOTHING — a refusal that still called Divi would be worse than no allowlist, because it would look safe' );
assert_true( is_array( $drbt_deny['error']['data']['allowed'] ?? null ), '#504: the refusal names the allowed subroutes, so a caller can correct itself without reading our source' );
assert_true( in_array( 'query-types', $drbt_deny['error']['data']['allowed'] ?? array(), true ), '#504: and that list really contains a valid subroute (control: the allowed list is populated, not an empty array that would satisfy is_array)' );

diviops_drb_reset_requests();
$drbt_empty = diviops_drbt_call( 'divi_loop_read', array() );
assert_same( 'invalid_input', $drbt_empty['error']['code'] ?? null, '#504: a missing subroute is refused too, rather than defaulting to some route nobody asked for' );
assert_same( 0, count( $GLOBALS['diviops_drb_requests'] ), '#504: and dispatches nothing' );

// ══ 4. The allowlists are per-family, not one shared set ══════════════════

diviops_drb_reset_requests();
$drbt_cross1 = diviops_drbt_call( 'divi_loop_read', array( 'subroute' => 'post-type' ) );
assert_same( 'invalid_input', $drbt_cross1['error']['code'] ?? null, '#504: a conditions subroute handed to the loop handler is refused — a single shared allowlist would pass the happy path and this is what catches it' );

$drbt_cross2 = diviops_drbt_call( 'divi_conditions_read', array( 'subroute' => 'query-types' ) );
assert_same( 'invalid_input', $drbt_cross2['error']['code'] ?? null, '#504: and a loop subroute handed to the conditions handler is refused as well, so the check is not merely non-empty' );
assert_same( 0, count( $GLOBALS['diviops_drb_requests'] ), '#504: neither cross-family attempt reached Divi' );

// ══ 5. A Divi failure stays a failure ═════════════════════════════════════

diviops_drb_reset_requests();
$drbt_fail_resp = diviops_drbt_resp( 'divi_loop_read', array( 'subroute' => 'query-results' ) );
$drbt_fail      = $drbt_fail_resp->get_data();
assert_true( false === ( $drbt_fail['ok'] ?? null ), '#504: a Divi route that refuses is reported as a failure, never flattened into ok:true with an error payload inside data' );
assert_same( 403, $drbt_fail['error']['data']['divi_status'] ?? null, '#504: carrying Divi\'s own HTTP status, because that is what a caller needs to distinguish a permission problem from a bad request' );
assert_same( 'rest_forbidden', $drbt_fail['error']['data']['divi_code'] ?? null, '#504: and Divi\'s own code' );

// ══ 6. Argument forwarding ════════════════════════════════════════════════

diviops_drb_reset_requests();
diviops_drbt_call( 'divi_loop_read', array( 'subroute' => 'query-types', 'args' => array( 'post_type' => 'page', 'per_page' => 5 ) ) );
$drbt_fwd = diviops_drb_last_request();
assert_same( 'page', $drbt_fwd['params']['post_type'] ?? null, '#504: a string arg reaches Divi, because several loop routes are useless without one' );
assert_same( 5, $drbt_fwd['params']['per_page'] ?? null, '#504: and the bridge forwards a value unchanged rather than coercing it — note this is the in-process shape; over HTTP a query parameter arrives as a string and Divi\'s own arg schema coerces it, so this pins the bridge\'s passthrough and NOT an end-to-end type guarantee' );

diviops_drb_reset_requests();
$drbt_bad_args = diviops_drbt_call( 'divi_loop_read', array( 'subroute' => 'query-types', 'args' => array( 'nested' => array( 'a' => 1 ) ) ) );
assert_same( 'invalid_input', $drbt_bad_args['error']['code'] ?? null, '#504: a non-scalar arg is refused before dispatch, so the bridge never forwards a shape it cannot describe' );
assert_same( 0, count( $GLOBALS['diviops_drb_requests'] ), '#504: and nothing is dispatched' );

diviops_drb_reset_requests();
$drbt_bad_key = diviops_drbt_call( 'divi_loop_read', array( 'subroute' => 'query-types', 'args' => array( 'Bad-Key!' => 1 ) ) );
assert_same( 'invalid_input', $drbt_bad_key['error']['code'] ?? null, '#504: and so is an arg name outside the conservative key shape' );

// ══ 7. Capability keys and route registration ═════════════════════════════

$drbt_caps = DiviOps_Agent::CAPABILITIES;
assert_true( in_array( 'divi_loop_read', $drbt_caps, true ), '#504: the loop family advertises its own capability key' );
assert_true( in_array( 'divi_conditions_read', $drbt_caps, true ), '#504: and the conditions family advertises a separate one, so a client can gate them independently' );
assert_true( ! in_array( 'divi_read_everything', $drbt_caps, true ), '#504: control: the membership test does not match a key that is absent' );

$drbt_src = (string) file_get_contents( __DIR__ . '/../plugins/diviops-agent/diviops-agent.php' );
assert_true( '' !== $drbt_src, '#504: the plugin file was actually read before anything is concluded from it' );
foreach ( array( '/divi/loop/', '/divi/conditions/', 'divi_loop_read', 'divi_conditions_read' ) as $drbt_needle ) {
	assert_true( false !== strpos( $drbt_src, $drbt_needle ), "#504: the plugin registers {$drbt_needle}" );
}

// ══ 8. The allowlist matches what the live install actually serves ════════

// Read off staging 2026-09-27 by enumerating rest_get_server()->get_routes().
// Pinned so a future edit that adds a route has to justify it against a real
// enumeration rather than adding a plausible-looking name.
$drbt_live_loop = array(
	'custom-field-options',
	'custom-field-value-options',
	'field-list-items',
	'product-price-range',
	'query-order-by',
	'query-posts',
	'query-results',
	'query-taxonomies',
	'query-types',
);
$drbt_live_cond = array(
	'author',
	'categories',
	'post-meta-fields',
	'post-type',
	'posts',
	'tags',
	'user-role',
);
$drbt_declared_loop = DiviOps_Divi_Read_Bridge::LOOP_SUBROUTES;
$drbt_declared_cond = DiviOps_Divi_Read_Bridge::CONDITIONS_SUBROUTES;
sort( $drbt_declared_loop );
sort( $drbt_declared_cond );
assert_same( $drbt_live_loop, $drbt_declared_loop, '#504: the declared loop allowlist is exactly the nine GET routes the live install serves, no invented entries and none missed' );
assert_same( $drbt_live_cond, $drbt_declared_cond, '#504: and the conditions allowlist is exactly the seven it serves' );
