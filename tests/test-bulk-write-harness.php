<?php
// SPDX-License-Identifier: MIT
/**
 * The bulk write harness, proved independently of its payload (#495).
 *
 * Phase 2 of #38. `bulk_status_change` is the payload only because it never
 * reads or writes `post_content` — the real subject here is the harness: the
 * target cap, the keyed plan token and its three bindings, the upfront
 * whole-set gate, the per-target loop contract, the run manifest, `on_error`,
 * and the envelope being `ok: false` on a partial run.
 *
 * ── The assertion this file exists for ───────────────────────────────────
 *
 * The plan token binds each target's content checksum, `post_status` AND
 * `post_modified_gmt`. A content-only binding is **inert** for this payload,
 * because the whole mutation is the status: a target whose status drifted
 * between plan and apply would still produce a matching token, and that token
 * would stay replayable for its whole lifetime. Phase 2 exists to prove the
 * harness, so a harness whose drift detector cannot fire on phase 2's own
 * payload proves nothing. Section 4 fires it on each of the three bindings
 * separately, because any one of them alone would let the other two rot.
 *
 * ── What is NOT covered, and why ─────────────────────────────────────────
 *
 * The publish SIDE EFFECTS — `transition_post_status`, pingbacks, feeds, and
 * whatever notification plugin a client site runs — are the least reversible
 * thing this tool does and cannot be observed here at all: the shim fires no
 * hooks. That is stated rather than faked, and it is why the plan warns on
 * every transition into publish instead of relying on a test.
 *
 * Cross-request token replay is likewise out of reach: one PHP process is one
 * request. What IS proved is that the same inputs mint the same token, which
 * is the property replay-across-requests depends on, and `wp_salt()`'s
 * cross-process stability was measured on the reference install rather than
 * assumed (see tests/bulk-write-harness-stubs.php).
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/bulk-write-harness-stubs.php';

/**
 * A plan's change rows, or an empty list.
 *
 * Every read of the plan body goes through here so a mutant that turns a plan
 * into an error fails an assertion rather than fataling on a null array offset.
 * The runner reports a fatal as "died without a result", which reads as a
 * surviving mutant rather than a caught one.
 *
 * @param array $response Decoded response.
 * @return array
 */
function diviops_bwh_changes( array $response ): array {
	return isset( $response['data']['plan']['changes'] ) && is_array( $response['data']['plan']['changes'] )
		? $response['data']['plan']['changes']
		: array();
}

/**
 * A plan's warnings, or an empty list. Same reason as diviops_bwh_changes().
 *
 * @param array $response Decoded response.
 * @return array
 */
function diviops_bwh_warnings( array $response ): array {
	return isset( $response['data']['plan']['warnings'] ) && is_array( $response['data']['plan']['warnings'] )
		? $response['data']['plan']['warnings']
		: array();
}

/**
 * Read a plan token out of a response, asserting the response really is a plan.
 *
 * A mutant that turns a plan into an error would otherwise fatal on a null
 * array offset, and the runner reports a fatal as "died without a result" --
 * which reads as a surviving mutant rather than a caught one.
 *
 * @param array  $response Decoded response.
 * @param string $what     What was being planned, for the failure message.
 * @return string
 */
function diviops_bwh_token( array $response, string $what ): string {
	assert_true(
		isset( $response['data']['plan_token'] ),
		"a dry run for {$what} returns a plan carrying a token"
	);
	return (string) ( $response['data']['plan_token'] ?? '' );
}

/**
 * Call bulk_status_change and return the decoded body.
 *
 * @param array $params REST parameters.
 * @return array
 */
function diviops_bwh_call( array $params ): array {
	return diviops_call( 'bulk_status_change', array( new DiviOps_Test_Request( $params ) ) )->get_data();
}

/**
 * Reset every piece of global state this file touches.
 */
function diviops_bwh_reset(): void {
	$GLOBALS['diviops_test_posts']           = array();
	$GLOBALS['diviops_test_uneditable_ids']  = array();
	$GLOBALS['diviops_test_denied_caps']     = array();
	$GLOBALS['diviops_bwh_post_type_caps']   = array();
	$GLOBALS['diviops_test_transients']      = array();
}

/**
 * Register a post with an explicit status and modified time.
 *
 * @param int    $id      Post id.
 * @param string $status  post_status.
 * @param string $type    post_type.
 * @param string $content post_content.
 * @return object
 */
function diviops_bwh_post( int $id, string $status = 'draft', string $type = 'page', string $content = '<p>body</p>' ) {
	$post                    = diviops_test_register_post( $id, $content, $type, "Post {$id}" );
	$post->post_status       = $status;
	$post->post_modified_gmt = '2026-09-01 12:00:00';
	$post->post_date         = '2026-08-01 09:00:00';
	$post->post_date_gmt     = '2026-08-01 14:00:00';
	return $post;
}

diviops_bwh_reset();

// ── 0. The stubs behave as the harness assumes ───────────────────────────
//
// Asserted first. Every token assertion below is downstream of wp_salt() being
// keyed and stable, and every publish-gate assertion is downstream of the
// post-type object resolving a capability. A stub that silently returned an
// empty string would make the MAC unkeyed and take section 4 green anyway.

assert_true( '' !== wp_salt( 'diviops_bulk' ), 'the salt stub returns a non-empty key, so the MAC is actually keyed' );
assert_same( wp_salt( 'diviops_bulk' ), wp_salt( 'diviops_bulk' ), 'and a stable one, so a token verifies against a later call' );
assert_true(
	wp_salt( 'diviops_bulk' ) !== wp_salt( 'auth' ),
	'and a scheme-distinct one, so the bulk key is not the auth key'
);
assert_same( 'publish_pages', get_post_type_object( 'page' )->cap->publish_posts, 'the page post type resolves a publish capability' );

// The cap's VALUE, pinned. Every other assertion in this file derives its
// fixtures from the constant, so all of them adapt to whatever it says -- which
// means the number itself has no coverage. It is a blast-radius decision the
// owner made on 2026-09-23 against a measured snapshot-store baseline, so a
// change to it should have to be deliberate and reviewed, not silent.
assert_same( 25, DiviOps_Agent::BULK_MAX_TARGETS, 'the bulk target cap is 25' );
assert_same(
	array( 'page', 'post' ),
	DiviOps_Agent::BULK_WRITE_POST_TYPES,
	'and the bulk WRITE scope is page and post only -- narrower than the read scope, which reaches Theme Builder layouts'
);

// ── 1. Targets: explicit ids only, and a refusal above the cap ───────────

$bwh_over = diviops_bwh_call( array( 'targets' => range( 1, DiviOps_Agent::BULK_MAX_TARGETS + 1 ), 'status' => 'draft' ) );
assert_true( false === $bwh_over['ok'], 'more targets than the cap is refused' );
assert_same( 'bulk.too_many_targets', $bwh_over['error']['code'], 'with its own code, not a generic invalid_input' );
assert_same(
	DiviOps_Agent::BULK_MAX_TARGETS,
	$bwh_over['error']['data']['max_targets'],
	'and the refusal states the cap, so the caller can split the run without guessing'
);
assert_true(
	! isset( $bwh_over['data'] ),
	'a refusal carries no data payload -- an oversized request is refused outright, never truncated to fit'
);

$bwh_empty = diviops_bwh_call( array( 'targets' => array(), 'status' => 'draft' ) );
assert_same( 'invalid_input', $bwh_empty['error']['code'], 'an empty target list is refused rather than treated as "everything"' );

$bwh_bad = diviops_bwh_call( array( 'targets' => array( 'all' ), 'status' => 'draft' ) );
assert_same( 'invalid_input', $bwh_bad['error']['code'], 'a non-id target is refused: ids are values, never predicates' );

// ── 2. Dry-run is the default, and applying needs a token ────────────────

diviops_bwh_reset();
diviops_bwh_post( 101, 'draft' );
diviops_bwh_post( 102, 'draft' );

$bwh_plan = diviops_bwh_call( array( 'targets' => array( 101, 102 ), 'status' => 'publish' ) );
// Asserted before anything reads the plan. If dry_run stopped defaulting to
// true, every later token assertion would die on a missing plan_token, and the
// runner reports a fatal as "died without a result" -- which reads as a
// surviving mutant rather than a caught one.
assert_true(
	isset( $bwh_plan['data']['plan_token'] ),
	'omitting dry_run produces a PLAN, not an attempted write: dry_run defaults to true, inverting this plugin\'s usual convention'
);
assert_true( $bwh_plan['ok'], 'omitting dry_run produces a plan rather than a write' );
assert_true( true === $bwh_plan['data']['dry_run'], 'and says so' );
assert_same( 'draft', $GLOBALS['diviops_test_posts'][101]->post_status, 'and nothing was written' );
assert_true( isset( $bwh_plan['data']['plan_token'] ), 'the plan carries a token' );
assert_true(
	is_array( diviops_bwh_warnings( $bwh_plan ) ) && isset( $bwh_plan['data']['plan']['warnings'] ),
	'warnings is always present, even when empty -- dry_run_response() omits the key when empty and leaves callers branching on its absence'
);
assert_same( 2, count( diviops_bwh_warnings( $bwh_plan ) ), 'and a transition into publish warns for every target it touches' );
assert_true(
	false !== strpos( (string) ( diviops_bwh_warnings( $bwh_plan )[0] ?? '' ), 'does not recall' ),
	'naming the irreversible part: the outbound events publishing fires'
);

$bwh_no_token = diviops_bwh_call( array( 'targets' => array( 101 ), 'status' => 'publish', 'dry_run' => false ) );
assert_true( false === $bwh_no_token['ok'], 'dry_run:false with no token is refused' );
assert_same( 'invalid_input', $bwh_no_token['error']['code'], 'because "defaults to true" is not mandatory dry-run' );
assert_same( 'draft', $GLOBALS['diviops_test_posts'][101]->post_status, 'and still nothing was written' );

// ── 3. A forged or malformed token is rejected ───────────────────────────

$bwh_forged = diviops_bwh_call(
	array( 'targets' => array( 101, 102 ), 'status' => 'publish', 'dry_run' => false, 'plan_token' => time() . '.' . str_repeat( 'a', 64 ) )
);
assert_true( false === $bwh_forged['ok'], 'a token with a fabricated MAC is rejected' );
assert_same( 'bulk.plan_stale', $bwh_forged['error']['code'], 'as plan_stale' );

$bwh_malformed = diviops_bwh_call(
	array( 'targets' => array( 101 ), 'status' => 'publish', 'dry_run' => false, 'plan_token' => 'not-a-token' )
);
assert_same( 'bulk.plan_invalid', $bwh_malformed['error']['code'], 'a token that is not <issued_at>.<mac> is refused as malformed' );

// An unkeyed hash of the same inputs must NOT verify. Without this, replacing
// hash_hmac with hash() would leave every other token assertion green while
// making the token forgeable by anyone who can read this public repository.
$bwh_canonical = diviops_call(
	'bulk_plan_canonical',
	array( 'bulk_status_change', time(), get_current_user_id(), array( 'status' => 'publish' ),
		array( diviops_call( 'bulk_target_state', array( 101 ) ), diviops_call( 'bulk_target_state', array( 102 ) ) ) )
);
$bwh_unkeyed = diviops_bwh_call(
	array( 'targets' => array( 101, 102 ), 'status' => 'publish', 'dry_run' => false, 'plan_token' => time() . '.' . hash( 'sha256', $bwh_canonical ) )
);
assert_same(
	'bulk.plan_stale',
	$bwh_unkeyed['error']['code'],
	'an UNKEYED hash of the exact canonical string does not verify -- the token is a MAC, not a checksum a caller can compute'
);

// The other way to lose the key, and the one a careless edit actually produces:
// hash_hmac with an EMPTY key. That is still an HMAC, so the assertion above
// passes over it -- anyone holding the canonical string can compute it, which
// is exactly the property the key exists to deny.
$bwh_empty_key = diviops_bwh_call(
	array(
		'targets'    => array( 101, 102 ),
		'status'     => 'publish',
		'dry_run'    => false,
		'plan_token' => time() . '.' . hash_hmac( 'sha256', $bwh_canonical, '' ),
	)
);
assert_same(
	'bulk.plan_stale',
	$bwh_empty_key['error']['code'],
	'an HMAC computed with an EMPTY key does not verify either -- the site-local salt is load-bearing, not decoration'
);

// And the SCHEME is load-bearing too. Signing with wp_salt('auth') instead is
// still a keyed, unguessable MAC, so both assertions above pass over it -- but
// it reuses the site's authentication key for an unrelated purpose, and any
// other code path that signs with 'auth' could then mint a bulk plan. Asserted
// by minting the token with the WRONG scheme and requiring it to be rejected.
$bwh_wrong_scheme = diviops_bwh_call(
	array(
		'targets'    => array( 101, 102 ),
		'status'     => 'publish',
		'dry_run'    => false,
		'plan_token' => time() . '.' . hash_hmac( 'sha256', $bwh_canonical, wp_salt( 'auth' ) ),
	)
);
assert_same(
	'bulk.plan_stale',
	$bwh_wrong_scheme['error']['code'],
	'a token signed with the auth salt does not verify -- the bulk scheme is its own key, not a borrowed one'
);

$bwh_stale = diviops_bwh_call(
	array( 'targets' => array( 101 ), 'status' => 'publish', 'dry_run' => false, 'plan_token' => ( time() - DiviOps_Agent::BULK_PLAN_TTL_SECONDS - 60 ) . '.' . str_repeat( 'b', 64 ) )
);
assert_same( 'bulk.plan_stale', $bwh_stale['error']['code'], 'a token older than the TTL is rejected on its plaintext half' );
assert_true(
	isset( $bwh_stale['error']['data']['ttl'] ),
	'and the refusal reports the TTL it was measured against rather than only that it failed'
);

// ── 4. The three bindings, each fired separately ─────────────────────────
//
// Any one of these alone would let the other two rot unnoticed.

/**
 * Mint a real token for a target set, then mutate one field, then apply.
 *
 * @param array    $ids    Target ids.
 * @param string   $status Requested status.
 * @param callable $drift  Mutation applied between plan and apply.
 * @return array
 */
function diviops_bwh_drift( array $ids, string $status, callable $drift ): array {
	$plan  = diviops_bwh_call( array( 'targets' => $ids, 'status' => $status ) );
	$token = diviops_bwh_token( $plan, 'plan' );
	$drift();
	return diviops_bwh_call( array( 'targets' => $ids, 'status' => $status, 'dry_run' => false, 'plan_token' => $token ) );
}

diviops_bwh_reset();
diviops_bwh_post( 201, 'draft' );
$bwh_d1 = diviops_bwh_drift(
	array( 201 ),
	'publish',
	static function () {
		$GLOBALS['diviops_test_posts'][201]->post_content = '<p>edited by someone else</p>';
	}
);
assert_same( 'bulk.plan_stale', $bwh_d1['error']['code'], 'CONTENT drift between plan and apply refuses the run' );
assert_same( 'draft', $GLOBALS['diviops_test_posts'][201]->post_status, 'and nothing was written' );

diviops_bwh_reset();
diviops_bwh_post( 202, 'draft' );
$bwh_d2 = diviops_bwh_drift(
	array( 202 ),
	'publish',
	static function () {
		$GLOBALS['diviops_test_posts'][202]->post_status = 'pending';
	}
);
assert_same(
	'bulk.plan_stale',
	$bwh_d2['error']['code'],
	'STATUS drift refuses -- the binding a content-only token could not see, on the payload whose entire mutation is the status'
);

diviops_bwh_reset();
diviops_bwh_post( 203, 'draft' );
$bwh_d3 = diviops_bwh_drift(
	array( 203 ),
	'publish',
	static function () {
		$GLOBALS['diviops_test_posts'][203]->post_modified_gmt = '2026-09-02 08:00:00';
	}
);
assert_same( 'bulk.plan_stale', $bwh_d3['error']['code'], 'post_modified_gmt drift refuses, catching an edit that restored the same bytes' );

diviops_bwh_reset();
diviops_bwh_post( 204, 'draft' );
$bwh_d4 = diviops_bwh_drift(
	array( 204 ),
	'publish',
	static function () {
		unset( $GLOBALS['diviops_test_posts'][204] );
	}
);
assert_same(
	'bulk.plan_stale',
	$bwh_d4['error']['code'],
	'a target that VANISHED between plan and apply surfaces through the same mechanism -- one condition, one code'
);

// A reordered id list is a different canonical string, so the token must not
// carry over. Otherwise the ordered list in the plan is decorative.
diviops_bwh_reset();
diviops_bwh_post( 205, 'draft' );
diviops_bwh_post( 206, 'draft' );
$bwh_plan_order = diviops_bwh_call( array( 'targets' => array( 205, 206 ), 'status' => 'publish' ) );
$bwh_reordered  = diviops_bwh_call(
	array( 'targets' => array( 206, 205 ), 'status' => 'publish', 'dry_run' => false, 'plan_token' => diviops_bwh_token( $bwh_plan_order, 'bwh_plan_order' ) )
);
assert_same( 'bulk.plan_stale', $bwh_reordered['error']['code'], 'a token minted for one id order does not verify against another' );

// A token minted for one operation's parameters must not apply another's.
$bwh_plan_params = diviops_bwh_call( array( 'targets' => array( 205 ), 'status' => 'publish' ) );
$bwh_swapped     = diviops_bwh_call(
	array( 'targets' => array( 205 ), 'status' => 'private', 'dry_run' => false, 'plan_token' => diviops_bwh_token( $bwh_plan_params, 'bwh_plan_params' ) )
);
assert_same( 'bulk.plan_stale', $bwh_swapped['error']['code'], 'a token minted for status=publish does not authorize status=private' );

// ── 5. The upfront whole-set gate ────────────────────────────────────────
//
// The owner's approved default: a partial application that skipped the bad
// ones is worse than a refusal naming them, because nobody reads a results
// table looking for absences.

diviops_bwh_reset();
diviops_bwh_post( 301, 'draft' );
diviops_bwh_post( 302, 'draft' );
$GLOBALS['diviops_test_uneditable_ids'] = array( 302 );

$bwh_gate_plan = diviops_bwh_call( array( 'targets' => array( 301, 302 ), 'status' => 'publish' ) );
assert_true( $bwh_gate_plan['ok'], 'a plan containing a refusable target still returns a plan' );
$bwh_verdicts = array();
foreach ( diviops_bwh_changes( $bwh_gate_plan ) as $change ) {
	$bwh_verdicts[ $change['id'] ] = $change['verdict'];
}
assert_same(
	'will_refuse:forbidden',
	$bwh_verdicts[302],
	'and the refusal is VISIBLE in the plan rather than discovered at apply time'
);
assert_same( 'will_apply', $bwh_verdicts[301], 'while the clean target reads will_apply' );

$bwh_gate = diviops_bwh_call(
	array( 'targets' => array( 301, 302 ), 'status' => 'publish', 'dry_run' => false, 'plan_token' => diviops_bwh_token( $bwh_gate_plan, 'bwh_gate_plan' ) )
);
assert_true( false === $bwh_gate['ok'], 'and the apply refuses the WHOLE run' );
assert_same( 'bulk.preflight_refused', $bwh_gate['error']['code'], 'with a gate-specific code' );
assert_same( 'draft', $GLOBALS['diviops_test_posts'][301]->post_status, 'the clean target is NOT written -- all or nothing on the gate' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// A post type outside the write scope refuses the run. The READ side reaches
// SCANNABLE_POST_TYPES; the write side is page and post, so a Theme Builder
// layout — which already applies site-wide on its own — is never a bulk target.
diviops_bwh_reset();
diviops_bwh_post( 303, 'draft' );
diviops_bwh_post( 304, 'draft', 'et_body_layout' );
$bwh_tb_plan = diviops_bwh_call( array( 'targets' => array( 303, 304 ), 'status' => 'publish' ) );
$bwh_tb      = diviops_bwh_call(
	array( 'targets' => array( 303, 304 ), 'status' => 'publish', 'dry_run' => false, 'plan_token' => diviops_bwh_token( $bwh_tb_plan, 'bwh_tb_plan' ) )
);
assert_same( 'bulk.preflight_refused', $bwh_tb['error']['code'], 'a Theme Builder layout in the target list refuses the run' );
assert_same(
	'bulk.post_type_not_writable',
	$bwh_tb['error']['data']['refused'][0]['code'],
	'naming the post-type scope as the reason'
);

// ── 6. The per-target publish capability ─────────────────────────────────
//
// page_update_status's own publish gate lives in a ROUTE permission callback
// resolving the capability for a single $request['id']. A single route-level
// callback structurally cannot express this for a batch spanning mixed post
// types, so this re-check is a privilege boundary rather than a nicety.

diviops_bwh_reset();
diviops_bwh_post( 401, 'draft', 'page' );
diviops_bwh_post( 402, 'draft', 'post' );
$GLOBALS['diviops_bwh_post_type_caps'] = array( 'post' => 'publish_posts' );
$GLOBALS['diviops_test_denied_caps']   = array( 'publish_posts' );

$bwh_cap_plan = diviops_bwh_call( array( 'targets' => array( 401, 402 ), 'status' => 'publish' ) );
$bwh_cap_v    = array();
foreach ( diviops_bwh_changes( $bwh_cap_plan ) as $change ) {
	$bwh_cap_v[ $change['id'] ] = $change['verdict'];
}
assert_same(
	'will_refuse:rest_cannot_publish',
	$bwh_cap_v[402],
	'the post the caller may not publish is refused by its OWN post type capability'
);
assert_same(
	'will_apply',
	$bwh_cap_v[401],
	'while the page, whose publish capability the caller does hold, is not -- proving the check is per target and per post type'
);

// Draft is not a publish-capability transition, so the same batch is clean.
$GLOBALS['diviops_test_denied_caps'] = array( 'publish_posts' );
$bwh_draft_plan = diviops_bwh_call( array( 'targets' => array( 401, 402 ), 'status' => 'pending' ) );
$bwh_draft_v    = array();
foreach ( diviops_bwh_changes( $bwh_draft_plan ) as $change ) {
	$bwh_draft_v[ $change['id'] ] = $change['verdict'];
}
assert_same( 'will_apply', $bwh_draft_v[402], 'a transition needing no publish capability is not gated by one' );
$GLOBALS['diviops_test_denied_caps'] = array();

// ── 7. A clean apply, end to end ─────────────────────────────────────────

diviops_bwh_reset();
diviops_bwh_post( 501, 'draft' );
diviops_bwh_post( 502, 'publish' );

$bwh_ok_plan = diviops_bwh_call( array( 'targets' => array( 501, 502 ), 'status' => 'publish' ) );
$bwh_ok      = diviops_bwh_call(
	array( 'targets' => array( 501, 502 ), 'status' => 'publish', 'dry_run' => false, 'plan_token' => diviops_bwh_token( $bwh_ok_plan, 'bwh_ok_plan' ) )
);

assert_true( $bwh_ok['ok'], 'a run where every target succeeds reports ok' );
assert_same( 'publish', $GLOBALS['diviops_test_posts'][501]->post_status, 'and the status was actually written' );
assert_same( 1, $bwh_ok['data']['counts']['applied'] ?? null, 'one target applied' );
assert_same( 1, $bwh_ok['data']['counts']['skipped'] ?? null, 'and one was already at the requested status' );
$bwh_ok_targets = isset( $bwh_ok['data']['targets'] ) && is_array( $bwh_ok['data']['targets'] ) ? $bwh_ok['data']['targets'] : array( array(), array() );
assert_same( 'skipped', $bwh_ok_targets[1]['status'] ?? null, 'reported as skipped' );
assert_same(
	'already_publish',
	$bwh_ok_targets[1]['reason'] ?? null,
	'with the primer\'s already_<state> reason, so a re-run is idempotent rather than an error'
);
assert_true(
	false !== strpos( (string) ( $bwh_ok['data']['recovery'] ?? '' ), 'will NOT undo' ),
	'and the response states plainly that the content snapshot does not undo a status change'
);

// ── 8. The manifest is the recovery record ───────────────────────────────
//
// An adversarial review found this hole: the harness forces a snapshot on, the
// snapshot captures post_content, and this payload never touches post_content
// — so restoring it puts back bytes that never changed and leaves the status
// where the run left it. A test asserting "a snapshot exists and was marked"
// passes straight over that.

assert_true( isset( $bwh_ok['data']['run_id'] ), 'a completed apply reports a run_id, which is the only handle on its manifest' );
$bwh_run_id   = (string) ( $bwh_ok['data']['run_id'] ?? '' );
$bwh_manifest = diviops_call( 'bulk_run_get', array( new DiviOps_Test_Request( array( 'run_id' => $bwh_run_id ) ) ) )->get_data();

assert_true( $bwh_manifest['ok'], 'the manifest is readable by run_id' );
// Read through a guarded local, so a mutant that produced no manifest fails an
// assertion instead of fataling on a null offset -- a fatal is not a kill.
$bwh_mtargets = isset( $bwh_manifest['data']['targets'] ) && is_array( $bwh_manifest['data']['targets'] )
	? $bwh_manifest['data']['targets']
	: array( array( 'before' => array( 'post_status' => '', 'post_date_gmt' => '' ) ) );
assert_same( 2, count( $bwh_mtargets ), 'and carries one entry per target' );
assert_same(
	'draft',
	$bwh_mtargets[0]['before']['post_status'],
	'recording the PRIOR status, which is the only thing that can put the change back'
);
assert_same(
	'2026-08-01 14:00:00',
	$bwh_mtargets[0]['before']['post_date_gmt'],
	'and the prior post_date_gmt, which page_update_status mutates on some transitions'
);
assert_true(
	! isset( $bwh_mtargets[0]['before']['value'] ),
	'the manifest stores no content bytes -- those live in the snapshot store, not in a second copy'
);
assert_true(
	isset( $bwh_manifest['data']['token_fingerprint'] ) && 16 === strlen( $bwh_manifest['data']['token_fingerprint'] ),
	'a truncated fingerprint is stored for correlation, never the token itself: it is a MAC and a readable option is not where one belongs'
);

// Reverting really works: feed the recorded statuses back through the same tool.
$bwh_revert_plan = diviops_bwh_call( array( 'targets' => array( 501 ), 'status' => 'draft' ) );
$bwh_revert      = diviops_bwh_call(
	array( 'targets' => array( 501 ), 'status' => 'draft', 'dry_run' => false, 'plan_token' => diviops_bwh_token( $bwh_revert_plan, 'bwh_revert_plan' ) )
);
assert_true( $bwh_revert['ok'], 'the documented revert path runs' );
assert_same(
	'draft',
	$GLOBALS['diviops_test_posts'][501]->post_status,
	'and actually restores the recorded prior status -- proving recovery, not merely that a snapshot exists'
);

$bwh_missing = diviops_call( 'bulk_run_get', array( new DiviOps_Test_Request( array( 'run_id' => 'run_00000000000000_0000000000000000' ) ) ) )->get_data();
assert_same( 'not_found', $bwh_missing['error']['code'], 'an unknown run_id is not_found rather than an empty manifest' );

// ── 8b. The snapshot is actually restorable ──────────────────────────────
//
// `rollback_snapshot_restore()` refuses outright when after.checksum is empty,
// and only `rollback_snapshot_run_mark()` ever sets it. So a run that creates
// snapshots and never marks them produces records that are real, listed, and
// permanently unrestorable -- and an assertion that only checked "a snapshot
// exists" would call that a pass. The run summary computes `restorable` for
// exactly this, so assert on that rather than on presence.

assert_true(
	isset( $bwh_ok['data']['snapshot_chunks'] ) && is_array( $bwh_ok['data']['snapshot_chunks'] ) && count( $bwh_ok['data']['snapshot_chunks'] ) > 0,
	'the run reports the snapshot chunk it wrote, which is the only handle on those snapshots'
);

$bwh_chunk = diviops_call(
	'rollback_snapshot_get',
	array( new DiviOps_Test_Request( array( 'snapshot_id' => (string) ( $bwh_ok['data']['snapshot_chunks'][0] ?? 'run_00000000000000_0000000000000000' ) ) ) )
)->get_data();

assert_true( $bwh_chunk['ok'], 'and that chunk reads back — a handle that does not resolve is not a handle' );
$bwh_chunk_targets = isset( $bwh_chunk['data']['targets'] ) && is_array( $bwh_chunk['data']['targets'] )
	? $bwh_chunk['data']['targets']
	: array();
assert_true( count( $bwh_chunk_targets ) > 0, 'carrying at least one target (otherwise the checks below inspect nothing)' );

$bwh_applied_entry = null;
foreach ( $bwh_chunk_targets as $entry ) {
	if ( 501 === (int) $entry['id'] ) {
		$bwh_applied_entry = $entry;
	}
}
assert_true( null !== $bwh_applied_entry, 'the written target has a snapshot entry' );
$bwh_applied_entry = is_array( $bwh_applied_entry ) ? $bwh_applied_entry : array( 'after' => array( 'checksum' => null ), 'restorable' => false );
assert_true(
	is_string( $bwh_applied_entry['after']['checksum'] ) && '' !== $bwh_applied_entry['after']['checksum'],
	'whose after.checksum is set -- a created-but-unmarked snapshot is permanently unrestorable, and restore refuses it outright'
);
assert_true(
	true === $bwh_applied_entry['restorable'],
	'so the snapshot this run forced on is genuinely restorable, not merely present'
);

// ── 8c. The per-target loop contract, driven directly ────────────────────
//
// The token is checked once at the top of the run, so every drift a test can
// arrange before the call is caught there and the in-loop re-verification is
// never reached. That does not make it dead code -- it is the only defence
// against an edit landing inside a single target's own verify-then-write
// window, which no single-threaded test can stage. So it is driven directly,
// with a planned state that disagrees with live state.
//
// The guarded write cannot help here and that is the whole point: it compares
// STORED against REQUESTED, and a bulk write that overwrites a concurrent edit
// with stale content is stored exactly as requested and reverts nothing.

diviops_bwh_reset();
diviops_bwh_post( 551, 'draft' );

$bwh_planned = diviops_call( 'bulk_target_state', array( 551 ) );
$bwh_run     = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_test', array( 'tool_operation' => 'test' ) ) );

// Content changed after the plan was taken.
$bwh_drifted_plan                     = $bwh_planned;
$bwh_drifted_plan['content_checksum'] = 'sha256:' . str_repeat( '0', 64 );
$bwh_args = array( &$bwh_run, $bwh_drifted_plan, 'publish' );
$bwh_out = diviops_call_ref( 'bulk_status_apply_one', $bwh_args );
assert_same( 'failed', $bwh_out['bucket'], 'a target whose CONTENT no longer matches the plan fails inside the loop' );
assert_same( 'bulk.target_drifted', $bwh_out['result']['code'], 'as target_drifted' );
assert_same( 'draft', $GLOBALS['diviops_test_posts'][551]->post_status, 'and is not written' );

// Status changed after the plan was taken.
$bwh_drifted_status                = $bwh_planned;
$bwh_drifted_status['post_status'] = 'pending';
$bwh_args = array( &$bwh_run, $bwh_drifted_status, 'publish' );
$bwh_out = diviops_call_ref( 'bulk_status_apply_one', $bwh_args );
assert_same( 'bulk.target_drifted', $bwh_out['result']['code'], 'and so does a target whose STATUS no longer matches' );

// Modified time changed after the plan was taken.
$bwh_drifted_mod                      = $bwh_planned;
$bwh_drifted_mod['post_modified_gmt'] = '2099-01-01 00:00:00';
$bwh_args = array( &$bwh_run, $bwh_drifted_mod, 'publish' );
$bwh_out = diviops_call_ref( 'bulk_status_apply_one', $bwh_args );
assert_same( 'bulk.target_drifted', $bwh_out['result']['code'], 'and so does one whose post_modified_gmt moved' );

// The undrifted control. Without it the three assertions above would be
// satisfied just as well by a loop that refused every target unconditionally.
$bwh_args = array( &$bwh_run, $bwh_planned, 'publish' );
$bwh_out = diviops_call_ref( 'bulk_status_apply_one', $bwh_args );
assert_same( 'applied', $bwh_out['bucket'], 'while an UNDRIFTED target is applied -- the control proving the loop is not simply refusing everything' );
assert_same( 'publish', $GLOBALS['diviops_test_posts'][551]->post_status, 'and really was written' );

// Permission re-checked inside the loop, not only at the gate.
diviops_bwh_reset();
diviops_bwh_post( 552, 'draft' );
$bwh_planned_perm                       = diviops_call( 'bulk_target_state', array( 552 ) );
$GLOBALS['diviops_test_uneditable_ids'] = array( 552 );
$bwh_args = array( &$bwh_run, $bwh_planned_perm, 'publish' );
$bwh_out = diviops_call_ref( 'bulk_status_apply_one', $bwh_args );
assert_same( 'forbidden', $bwh_out['result']['code'], 'edit_post is re-checked per target immediately before its own write' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// And the publish capability, per target, for that target's own post type.
diviops_bwh_reset();
diviops_bwh_post( 553, 'draft', 'post' );
$bwh_planned_cap                     = diviops_call( 'bulk_target_state', array( 553 ) );
$GLOBALS['diviops_test_denied_caps'] = array( 'publish_posts' );
$bwh_args = array( &$bwh_run, $bwh_planned_cap, 'publish' );
$bwh_out = diviops_call_ref( 'bulk_status_apply_one', $bwh_args );
assert_same(
	'rest_cannot_publish',
	$bwh_out['result']['code'],
	'and so is the publish capability -- a route-level callback resolves one id, so only this re-check covers a mixed-type batch'
);
$GLOBALS['diviops_test_denied_caps'] = array();

// ── 9. A partial run is never a success ──────────────────────────────────
//
// The harness primer tells every caller to branch on the envelope.
// preset_reassign returns ok:true carrying a nested success:false, so a partial
// application satisfies that branch as a success. Repeating that here would
// multiply the defect.

diviops_bwh_reset();
diviops_bwh_post( 601, 'draft' );
diviops_bwh_post( 602, 'draft' );

$bwh_part_plan = diviops_bwh_call( array( 'targets' => array( 601, 602 ), 'status' => 'publish' ) );
// Drift 602 AFTER the preflight gate has already passed, so the failure is
// discovered inside the loop rather than before it -- which is the only way to
// reach the partial-run path at all.
$GLOBALS['diviops_test_posts'][602]->post_modified_gmt = '2026-09-09 09:09:09';
$bwh_part = diviops_bwh_call(
	array( 'targets' => array( 601, 602 ), 'status' => 'publish', 'dry_run' => false, 'plan_token' => diviops_bwh_token( $bwh_part_plan, 'bwh_part_plan' ) )
);
assert_same(
	'bulk.plan_stale',
	$bwh_part['error']['code'],
	'drift detected at the top of the run refuses before the loop -- the token is checked against live state'
);
assert_same( 'draft', $GLOBALS['diviops_test_posts'][601]->post_status, 'so neither target was written' );

// To reach the in-loop failure path the token has to still verify, so the drift
// has to happen after verification. The lock is the reachable way to do that:
// a target whose lock is already held fails inside the loop while its
// neighbour succeeds.
diviops_bwh_reset();
diviops_bwh_post( 603, 'draft' );
diviops_bwh_post( 604, 'draft' );
$bwh_lock_plan = diviops_bwh_call( array( 'targets' => array( 603, 604 ), 'status' => 'publish' ) );
set_transient( 'diviops_bulk_lock_604', time(), 30 );
$bwh_lock = diviops_bwh_call(
	array( 'targets' => array( 603, 604 ), 'status' => 'publish', 'dry_run' => false, 'plan_token' => diviops_bwh_token( $bwh_lock_plan, 'bwh_lock_plan' ) )
);

assert_true( false === $bwh_lock['ok'], 'a run with one failed target reports ok:false' );
assert_same( 'bulk.partial_failure', $bwh_lock['error']['code'], 'as a partial failure' );
assert_same( 1, $bwh_lock['error']['data']['counts']['applied'], 'the clean target was applied' );
assert_same( 1, $bwh_lock['error']['data']['counts']['failed'], 'and the locked one failed' );
assert_same( 'bulk.target_locked', $bwh_lock['error']['data']['targets'][1]['code'], 'naming the lock' );
assert_same( 'publish', $GLOBALS['diviops_test_posts'][603]->post_status, 'on_error defaults to continue, so the clean target still went through' );
assert_same( 'draft', $GLOBALS['diviops_test_posts'][604]->post_status, 'and the locked one did not' );
assert_true(
	isset( $bwh_lock['error']['data']['run_id'] ),
	'the complete run record travels in error.data, so a caller branching on the envelope still gets it'
);
delete_transient( 'diviops_bulk_lock_604' );

// on_error: stop leaves the remainder explicitly not_attempted rather than silent.
diviops_bwh_reset();
diviops_bwh_post( 605, 'draft' );
diviops_bwh_post( 606, 'draft' );
diviops_bwh_post( 607, 'draft' );
$bwh_stop_plan = diviops_bwh_call( array( 'targets' => array( 605, 606, 607 ), 'status' => 'publish' ) );
set_transient( 'diviops_bulk_lock_605', time(), 30 );
$bwh_stop = diviops_bwh_call(
	array(
		'targets'    => array( 605, 606, 607 ),
		'status'     => 'publish',
		'dry_run'    => false,
		'plan_token' => diviops_bwh_token( $bwh_stop_plan, 'bwh_stop_plan' ),
		'on_error'   => 'stop',
	)
);
assert_same( 2, $bwh_stop['error']['data']['counts']['not_attempted'], 'on_error:stop leaves the remaining targets not_attempted' );
assert_same( 'not_attempted', $bwh_stop['error']['data']['targets'][1]['status'], 'named individually, so a resume knows exactly which ids to re-plan' );
assert_same( 'draft', $GLOBALS['diviops_test_posts'][606]->post_status, 'and they really were not written' );
delete_transient( 'diviops_bulk_lock_605' );

// ── 10. Rate limiting counts targets, not requests ───────────────────────
//
// The write limit is a de-facto blast-radius ceiling: an agent that goes wrong
// damages at most `write` pages a minute. One batch request covering N posts
// would silently raise that ceiling N-fold.

$bwh_apply_req = new DiviOps_Test_Request( array( 'targets' => array( 1, 2, 3, 4, 5 ), 'plan_token' => 'x.y' ) );
$bwh_plan_req  = new DiviOps_Test_Request( array( 'targets' => array( 1, 2, 3, 4, 5 ) ) );

assert_same(
	5,
	diviops_call( 'bulk_rate_limit_cost', array( '/diviops/v1/bulk/status-change', $bwh_apply_req ) ),
	'a bulk APPLY costs one slot per target'
);
assert_same(
	1,
	diviops_call( 'bulk_rate_limit_cost', array( '/diviops/v1/bulk/status-change', $bwh_plan_req ) ),
	'a dry run costs 1, because it writes nothing'
);
assert_same(
	1,
	diviops_call( 'bulk_rate_limit_cost', array( '/diviops/v1/page/update-content/9', $bwh_apply_req ) ),
	'and every non-bulk route still costs 1, so nothing else changes'
);
assert_same(
	DiviOps_Agent::BULK_MAX_TARGETS,
	diviops_call(
		'bulk_rate_limit_cost',
		array( '/diviops/v1/bulk/status-change', new DiviOps_Test_Request( array( 'targets' => range( 1, 500 ), 'plan_token' => 'x.y' ) ) )
	),
	'a caller inflating targets past the cap cannot inflate the charge past it either'
);

// The cost resolver is only half of it. Asserting what it returns proves
// nothing about whether check_rate_limit() actually SPENDS that much -- a
// limiter that computed the cost and then incremented by 1 would satisfy every
// assertion above while leaving the blast-radius ceiling exactly as wide as it
// was. So drive the limiter itself and read the bucket.

/**
 * Invoke the real rate limiter for a request and return the bucket count.
 *
 * @param array  $params Request parameters.
 * @param string $route  REST route.
 * @param string $method HTTP method.
 * @return array{result:mixed,count:int}
 */
function diviops_bwh_spend( array $params, string $route, string $method = 'POST' ): array {
	$request = new DiviOps_BulkRateLimit_Request( $params, $route, $method );
	$result  = diviops_call( 'check_rate_limit', array( null, null, $request ) );
	$data   = get_transient( 'diviops_rl_write_' . get_current_user_id() );
	return array( 'result' => $result, 'count' => is_array( $data ) ? (int) $data['count'] : 0 );
}

// The limiter returns early for an unauthenticated caller, so give it one.
$GLOBALS['diviops_test_current_user_id'] = 7;
assert_same( 7, get_current_user_id(), 'the harness has an authenticated caller, without which the limiter returns before counting anything' );

delete_transient( 'diviops_rl_write_' . get_current_user_id() );

$bwh_spend_1 = diviops_bwh_spend(
	array( 'targets' => array( 1, 2, 3, 4 ), 'plan_token' => 'x.y' ),
	'/diviops/v1/bulk/status-change'
);
assert_same(
	4,
	$bwh_spend_1['count'],
	'a bulk apply of 4 targets SPENDS 4 slots from the write bucket, not 1 -- the resolver returning 4 is not the same as the limiter charging it'
);

$bwh_spend_2 = diviops_bwh_spend(
	array( 'targets' => array( 1, 2, 3 ), 'plan_token' => 'x.y' ),
	'/diviops/v1/bulk/status-change'
);
assert_same( 7, $bwh_spend_2['count'], 'and a second apply adds its own target count to the same window' );

$bwh_spend_3 = diviops_bwh_spend( array( 'content' => 'x' ), '/diviops/v1/page/update-content/9' );
assert_same( 8, $bwh_spend_3['count'], 'while an ordinary single-page write still costs exactly 1' );

delete_transient( 'diviops_rl_write_' . get_current_user_id() );
$GLOBALS['diviops_test_current_user_id'] = 0;

// ── 11. status=future is refused rather than half-supported ──────────────

diviops_bwh_reset();
diviops_bwh_post( 701, 'draft' );
$bwh_future = diviops_bwh_call( array( 'targets' => array( 701 ), 'status' => 'future' ) );
assert_true( false === $bwh_future['ok'], 'status=future is refused' );
assert_true(
	false !== strpos( $bwh_future['error']['hint'], 'diviops_page_update_status' ),
	'and points at the single-page tool that takes the per-target date it needs'
);

diviops_bwh_reset();
