<?php
// SPDX-License-Identifier: MIT
/**
 * Client-runtime reporting — the real WP-CLI indicator (#123).
 *
 * The dashboard used to guess at WP-CLI from PHP and got it wrong, because
 * the process that runs WP-CLI is the Node MCP server, not PHP. Removing the
 * row stopped the lie but answered nobody's actual question ("does my WP-CLI
 * work?"). This is the answer: the side that *can* observe WP-CLI reports it
 * over the handshake it already performs, and the dashboard renders what it
 * was told, with provenance.
 *
 * Three states, not two — the distinction the old binary row could not express:
 *
 *   available    a client connected and reported WP-CLI working
 *   unavailable  a client connected and reported it NOT working
 *   unknown      no client has reported yet (fresh install, or a server too
 *                old to send the field). NOT the same as "broken", which is
 *                exactly the conflation that produced the original red ✗.
 *
 * Backward compatibility is load-bearing: the field is optional, so an older
 * MCP server that never sends it must leave the stored report untouched
 * rather than being recorded as "unavailable".
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/** Fresh state per case — these all read and write the same transient. */
function reset_client_runtime() {
	delete_transient( DiviOps_Agent::CLIENT_RUNTIME_TRANSIENT );
}

/** Minimal WP_REST_Request stand-in carrying handshake params. */
function handshake_request( array $params ) {
	$params += [ 'mcp_server_version' => '99.0.0' ];
	return new DiviOps_Test_Request( $params );
}

// ── unknown: nothing has reported ────────────────────────────────────────

reset_client_runtime();
$state = DiviOps_Agent::dashboard_client_runtime();

assert_same(
	'unknown',
	$state['wp_cli']['state'],
	'WP-CLI is "unknown" before any client reports — a fresh install has not been told anything, which is not the same as broken'
);
assert_same(
	null,
	$state['wp_cli']['reported_at'],
	'an unknown state carries no report timestamp'
);

// ── available: a client reported it working ──────────────────────────────

reset_client_runtime();
DiviOps_Agent::handshake( handshake_request( [
	'client_runtime'     => [ 'wp_cli' => true ],
	'mcp_server_version' => '1.5.39',
] ) );
$state = DiviOps_Agent::dashboard_client_runtime();

assert_same(
	'available',
	$state['wp_cli']['state'],
	'WP-CLI is "available" after a client reports it working'
);
assert_same(
	'1.5.39',
	$state['wp_cli']['mcp_server_version'],
	'the report records which MCP server said so — provenance, so a stale or unexpected reporter is visible'
);
assert_true(
	is_int( $state['wp_cli']['reported_at'] ) && $state['wp_cli']['reported_at'] > 0,
	'the report records when it was made, so the dashboard can show staleness rather than implying it is live'
);

// ── unavailable: a client reported it NOT working ────────────────────────

reset_client_runtime();
DiviOps_Agent::handshake( handshake_request( [ 'client_runtime' => [ 'wp_cli' => false ] ] ) );

assert_same(
	'unavailable',
	DiviOps_Agent::dashboard_client_runtime()['wp_cli']['state'],
	'WP-CLI is "unavailable" when a client reports it not working — a real negative, unlike the old guess'
);

// ── backward compatibility: older servers omit the field ─────────────────

reset_client_runtime();
DiviOps_Agent::handshake( handshake_request( [] ) );

assert_same(
	'unknown',
	DiviOps_Agent::dashboard_client_runtime()['wp_cli']['state'],
	'a handshake without client_runtime leaves the state unknown — an older MCP server must never be recorded as "unavailable" merely for not speaking the newer contract'
);

// A newer client's good report must not be erased by an older client
// connecting afterwards; silence is not a retraction.
reset_client_runtime();
DiviOps_Agent::handshake( handshake_request( [ 'client_runtime' => [ 'wp_cli' => true ] ] ) );
DiviOps_Agent::handshake( handshake_request( [] ) );

assert_same(
	'available',
	DiviOps_Agent::dashboard_client_runtime()['wp_cli']['state'],
	'a later handshake that omits client_runtime does not clobber an earlier real report'
);

// ── a later report does supersede an earlier one ─────────────────────────

reset_client_runtime();
DiviOps_Agent::handshake( handshake_request( [ 'client_runtime' => [ 'wp_cli' => true ] ] ) );
DiviOps_Agent::handshake( handshake_request( [ 'client_runtime' => [ 'wp_cli' => false ] ] ) );

assert_same(
	'unavailable',
	DiviOps_Agent::dashboard_client_runtime()['wp_cli']['state'],
	'an explicit later report replaces the earlier one — the dashboard reflects the most recent truth'
);

// ── handshake still returns its normal payload ───────────────────────────

reset_client_runtime();
$response = DiviOps_Agent::handshake( handshake_request( [ 'client_runtime' => [ 'wp_cli' => true ] ] ) );
$payload  = $response instanceof WP_REST_Response ? $response->get_data() : $response;
$payload  = isset( $payload['data'] ) ? $payload['data'] : $payload;

assert_true(
	isset( $payload['capabilities'] ) && isset( $payload['plugin_version'] ),
	'accepting the new optional field does not disturb the handshake response contract Pro and the MCP server depend on'
);

// ── the plugin-provided capability list stays separate ───────────────────
// These are different kinds of claim: what the plugin provides vs what a
// client told us. Merging them is what produced the original bug.

assert_true(
	! array_key_exists( 'WP-CLI', DiviOps_Agent::dashboard_capabilities( true ) ),
	'WP-CLI is not in dashboard_capabilities() — that list is what the plugin itself provides, and it does not provide WP-CLI'
);

echo "PASS: client-runtime-report (11 assertions)\n";
