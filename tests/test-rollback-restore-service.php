<?php
// SPDX-License-Identifier: MIT
/**
 * A restore must be able to leave a way back (#512).
 *
 * ── What this covers ──────────────────────────────────────────────────────
 *
 * `rollback_snapshot_restore()` writes the stored `before.value` over whatever
 * the page currently holds and captures nothing of what it overwrote. Aim it at
 * the wrong snapshot and the page's live content is gone: the store's whole job
 * is being trustworthy after something has already gone wrong, and this was the
 * one path in it with no undo.
 *
 * `FORK.md:284` recorded upstream `e06b837` as an adopt blocked on #460. #460
 * closed; nothing tracked the adopt. This file is the coverage for taking it.
 *
 * Two things land together and only one of them is new behaviour:
 *
 *   1. The restore body moves into a PUBLIC static service,
 *      `rollback_snapshot_restore_service( $snapshot_id, $dry_run, $protect_current )`.
 *      Public is load-bearing, not stylistic — DiviOps Agent Pro 1.0.16-beta
 *      resolves it with `is_callable( [ 'DiviOps_Agent', ... ] )` and withholds
 *      its `managed_recovery_plan_v1` capability when it is absent, silently
 *      rather than as an error. A private method fails that gate identically to
 *      a missing one.
 *   2. `$protect_current` captures a recovery point BEFORE the write and
 *      finalises it against the observed after-state afterwards.
 *
 * `$protect_current` is deliberately not a REST input. The route's contract is
 * unchanged by this file; every assertion below that drives the route asserts
 * the SAME answer the route gave before, which is the point of a
 * characterization suite sitting under a refactor.
 *
 * ── What this does NOT cover, and why ─────────────────────────────────────
 *
 * This list was wrong once already. An adversarial review before merge mutated
 * the handler 28 ways and found six survivors where this docblock declared one;
 * section 12 exists because of that, and closes four of them. What follows is
 * the re-measured list, and every entry has been confirmed to survive a mutation
 * rather than assumed.
 *
 * **Two fields cannot be false in any reachable fixture, so asserting them pins
 * a literal rather than a mechanism.** `capture_verified` and `finalized` are
 * both `rollback_snapshot_record_persisted()` results, and that function can
 * only return false when the option store fails to read back what it was given.
 * `tests/wp-shim.php`'s `update_option()` always succeeds, so the false branch —
 * and with it the `capture_readback_failed` refusal — is unreachable. **The stub
 * that would be required is an option store that can be told to drop or corrupt
 * one write**, which is a shim capability this repository does not have; per
 * CONTRIBUTING.md widening the SHARED shim to manufacture it is how a false
 * green outlives its test. The two assertions on those fields say so in their
 * own messages rather than claiming coverage they do not have.
 *
 * `rollback_snapshot_record_persisted()` itself IS covered, directly, in section
 * 9 — including both false cases. What is uncovered is its two call sites.
 *
 * **The bounded recovery attempt** inside
 * `rollback_snapshot_finish_recovery_point()` fires only when a write reports
 * failure AFTER changing the page. Reaching it through the real code needs
 * `update_post_content_with_integrity_guard()` to both mutate and fail; the stub
 * required is a write-guard seam the plugin does not have. Section 10 does reach
 * `$respond` with a live `$point` — which the review found nothing else did —
 * and asserts the attempt does NOT fire there, which is the property that
 * matters.
 *
 * ── Expected values ───────────────────────────────────────────────────────
 *
 * Every error code below is cited to the branch that sets it in
 * `plugins/diviops-agent/includes/trait-rollback.php`, never read off a run. No
 * HTTP status code is asserted — the envelope helper owns the mapping and
 * `test-core-characterization.php` pins it, so repeating it here would be a
 * second copy to drift. The checksum algorithm is
 * `rollback_snapshot_checksum()` — `'sha256:'` then `hash( 'sha256', $value )` —
 * reproduced in the fixture builder rather than called, so an assertion cannot
 * pass by sharing a broken implementation.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/** `rollback_snapshot_checksum()`'s algorithm, reproduced rather than called. */
function rollback_rs_checksum( string $value ): string {
	return 'sha256:' . hash( 'sha256', $value );
}

/**
 * Seed a restorable single-target snapshot and put its page in the after-state.
 *
 * The page must hold `$after` for the restore to get past the drift gate
 * (`trait-rollback.php`, the `content_drift` branch), which is the guard a
 * careless fixture trips first.
 *
 * @return array{snapshot_id: string, option: string, post_id: int}
 */
function rollback_rs_seed( int $post_id, string $before, string $after ): array {
	$snapshot_id = 'rs' . $post_id;
	$option      = 'diviops_rollback_snapshot_' . $snapshot_id;

	diviops_test_register_post( $post_id, $after );

	// Derived from the real capture, never hand-written. The shape is four Divi
	// meta keys each carrying `exists` and `value`, and a hand-rolled
	// `[ 'post_meta' => [] ]` is close enough to look right while failing the
	// `side_effect_drift` comparison — which reads as a drift refusal rather
	// than as a bad fixture.
	$side_effects = diviops_call( 'rollback_snapshot_capture_side_effects', array( $post_id ) );

	$record = array(
		'schema_version' => 1,
		'snapshot_id'    => $snapshot_id,
		'status'         => 'write_applied',
		'created_at'     => gmdate( 'c' ),
		'expires_at'     => gmdate( 'c', time() + 604800 ),
		'created_by'     => array( 'user_id' => 1, 'login' => 'dax' ),
		'tool'           => 'diviops_page_update_content',
		'operation'      => array( 'dry_run' => false ),
		'target'         => array( 'kind' => 'post', 'id' => $post_id, 'post_type' => 'page' ),
		'before'         => array(
			'checksum'     => rollback_rs_checksum( $before ),
			'byte_length'  => strlen( $before ),
			'value'        => $before,
			'side_effects' => $side_effects,
		),
		'after'          => array(
			'checksum'     => rollback_rs_checksum( $after ),
			'byte_length'  => strlen( $after ),
			'side_effects' => $side_effects,
		),
		'restore'        => array( 'restorable' => true, 'restored_at' => null, 'restored_by' => null ),
		'cleanup'        => array( 'deleted_at' => null, 'deleted_by' => null ),
	);

	update_option( $option, $record, false );

	return array( 'snapshot_id' => $snapshot_id, 'option' => $option, 'post_id' => $post_id );
}

/** Decode whatever a handler or the service returned into its envelope array. */
function rollback_rs_envelope( $response ): array {
	$data = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : $response;
	return is_array( $data ) ? $data : array();
}

/**
 * Call the service, degrading to a synthetic refusal when it is not publicly callable.
 *
 * Without this a `private` service turns every assertion below into one fatal —
 * and CONTRIBUTING.md's mutation rule is explicit that a fatal is not a kill,
 * because nothing asserted anything. The guard makes the visibility mutation
 * fail as an assertion, which is what the file claims to be testing.
 */
function rollback_rs_service( ...$args ): array {
	if ( ! is_callable( array( 'DiviOps_Agent', 'rollback_snapshot_restore_service' ) ) ) {
		return array( 'ok' => false, 'error' => array( 'code' => 'service_not_callable', 'data' => array() ) );
	}
	return rollback_rs_envelope( call_user_func_array( array( 'DiviOps_Agent', 'rollback_snapshot_restore_service' ), $args ) );
}

/** Drive the REST route, so route-contract assertions go through the real entry point. */
function rollback_rs_route( array $params ): array {
	$request = new WP_REST_Request();
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rollback_rs_envelope( DiviOps_Agent::rollback_snapshot_restore( $request ) );
}

/** Count this test's snapshot options, so "a recovery point was created" is measurable. */
function rollback_rs_option_count(): int {
	global $wpdb;
	$found = 0;
	foreach ( array_keys( $GLOBALS['diviops_test_options'] ?? array() ) as $name ) {
		if ( 0 === strpos( (string) $name, 'diviops_rollback_snapshot_' ) ) {
			$found++;
		}
	}
	return $found;
}

/*
 * ---------------------------------------------------------------------------
 * 1. The seam Pro resolves.
 * ---------------------------------------------------------------------------
 *
 * `is_callable( [ 'DiviOps_Agent', 'rollback_snapshot_restore_service' ] )` is
 * the exact expression Pro 1.0.16-beta evaluates. A private method makes that
 * false from outside the class, so visibility is asserted directly rather than
 * inferred from the call below succeeding.
 */

assert_true(
	method_exists( 'DiviOps_Agent', 'rollback_snapshot_restore_service' ),
	'#512: DiviOps_Agent declares rollback_snapshot_restore_service'
);
assert_true(
	is_callable( array( 'DiviOps_Agent', 'rollback_snapshot_restore_service' ) ),
	'#512: rollback_snapshot_restore_service is publicly callable, which is the expression Pro gates its managed-recovery capability on'
);

$reflected = new ReflectionMethod( 'DiviOps_Agent', 'rollback_snapshot_restore_service' );
assert_true( $reflected->isStatic(), '#512: the service is static, so Pro can reach it without an instance' );
assert_same( 3, $reflected->getNumberOfParameters(), '#512: the service takes snapshot_id, dry_run and protect_current' );
assert_same( 1, $reflected->getNumberOfRequiredParameters(), '#512: only snapshot_id is required, so an existing caller passing one argument keeps working' );

/*
 * ---------------------------------------------------------------------------
 * 2. Unprotected restore is unchanged.
 * ---------------------------------------------------------------------------
 *
 * The refactor's whole risk is that moving the body changes an answer. These
 * pin the route's behaviour, and the service is then shown to agree with it.
 */

$plain = rollback_rs_seed( 81001, 'BEFORE-81001', 'AFTER-81001' );

$restored = rollback_rs_route( array( 'snapshot_id' => $plain['snapshot_id'] ) );
assert_same( true, $restored['ok'] ?? null, 'the route still restores a clean single-target snapshot' );
assert_same( 'BEFORE-81001', (string) get_post( 81001 )->post_content, 'the page holds its pre-write content after an unprotected restore' );
assert_same(
	false,
	$restored['data']['restore_backup']['created'] ?? null,
	'an unprotected restore still reports restore_backup.created false, the value the MVP envelope has always carried'
);
assert_same(
	true,
	$restored['data']['restore_backup']['deferred'] ?? null,
	'and still reports it as deferred rather than as a created backup'
);
assert_same( 'restore_applied', $restored['data']['status']['after'] ?? null, 'the snapshot status moves to restore_applied' );

$service_seed = rollback_rs_seed( 81002, 'BEFORE-81002', 'AFTER-81002' );
$via_service  = rollback_rs_service( $service_seed['snapshot_id'] );
assert_same( true, $via_service['ok'] ?? null, 'the service restores on its own, with only a snapshot id' );
assert_same( 'BEFORE-81002', (string) get_post( 81002 )->post_content, 'a service call with no request object writes the same content the route writes' );
assert_same(
	$restored['data']['restore_backup'],
	$via_service['data']['restore_backup'] ?? null,
	'the service and the route agree on the unprotected restore_backup block'
);

/*
 * ---------------------------------------------------------------------------
 * 3. dry_run still writes nothing, through either entry point.
 * ---------------------------------------------------------------------------
 */

$dry = rollback_rs_seed( 81003, 'BEFORE-81003', 'AFTER-81003' );

$dry_route = rollback_rs_route( array( 'snapshot_id' => $dry['snapshot_id'], 'dry_run' => true ) );
assert_same( true, $dry_route['ok'] ?? null, 'a dry-run restore succeeds' );
assert_same( true, $dry_route['data']['dry_run'] ?? null, 'and is reported as a dry run' );
assert_same( 'AFTER-81003', (string) get_post( 81003 )->post_content, 'a dry-run restore leaves the page untouched' );

$dry_service = rollback_rs_service( $dry['snapshot_id'], true );
assert_same( true, $dry_service['data']['dry_run'] ?? null, 'the service honours dry_run as its second argument' );
assert_same( 'AFTER-81003', (string) get_post( 81003 )->post_content, 'and still leaves the page untouched' );

/*
 * ---------------------------------------------------------------------------
 * 4. protect_current captures a recovery point before writing.
 * ---------------------------------------------------------------------------
 *
 * The count is taken around the call rather than asserted absolutely, because
 * earlier sections leave their own rows behind and an absolute number would
 * pin the order of this file rather than the behaviour.
 */

$guarded = rollback_rs_seed( 81004, 'BEFORE-81004', 'AFTER-81004' );
$before_count = rollback_rs_option_count();

$protected = rollback_rs_service( $guarded['snapshot_id'], false, true );
assert_same( true, $protected['ok'] ?? null, 'a protected restore succeeds' );
assert_same( 'BEFORE-81004', (string) get_post( 81004 )->post_content, 'a protected restore writes the same content an unprotected one does' );
assert_same(
	$before_count + 1,
	rollback_rs_option_count(),
	'a protected restore leaves exactly one new snapshot row behind — the recovery point'
);

$backup = $protected['data']['restore_backup'] ?? array();
assert_same( true, $backup['created'] ?? null, 'the protected restore reports a recovery point was created' );
assert_same( true, $backup['capture_verified'] ?? null, 'and reports capture_verified (see the declared gap: this pins the field, not the read-back)' );
assert_same( true, $backup['finalized'] ?? null, 'and reports finalized (see the declared gap: the false case needs a store that drops a write)' );
assert_same( true, $backup['usable'] ?? null, 'and that it is usable, which is the only claim a caller can act on' );
assert_same( false, $backup['recovery_attempted'] ?? null, 'no recovery is attempted when the restore succeeded' );
assert_same(
	rollback_rs_checksum( 'AFTER-81004' ),
	$backup['before_checksum'] ?? null,
	'the recovery point captured the content the restore was about to overwrite, not the content it wrote'
);

$recovery_record = get_option( 'diviops_rollback_snapshot_' . ( $backup['snapshot_id'] ?? '' ), null );
assert_true( is_array( $recovery_record ), 'the recovery point is a real stored snapshot, addressable by its own id' );
assert_same(
	'AFTER-81004',
	(string) ( $recovery_record['before']['value'] ?? '' ),
	'and holds the overwritten content, so restoring it undoes the restore'
);
assert_same(
	'rollback_snapshot_restore',
	(string) ( $recovery_record['tool'] ?? '' ),
	'the recovery point records which operation created it'
);

/*
 * ---------------------------------------------------------------------------
 * 5. protect_current does not relax any existing refusal.
 * ---------------------------------------------------------------------------
 *
 * Drift: the page is moved off the recorded after-state, so the `content_drift`
 * branch refuses with 409 before any write. Asserted under BOTH flags, because
 * a safety net that quietly widens the gate it hangs under is worse than none.
 */

$drifted = rollback_rs_seed( 81005, 'BEFORE-81005', 'AFTER-81005' );
get_post( 81005 )->post_content = 'SOMEONE-ELSE-EDITED-THIS';

$drift_plain = rollback_rs_service( $drifted['snapshot_id'] );
assert_same( false, $drift_plain['ok'] ?? null, 'a drifted target is refused without protection' );
assert_same( 'conflict', $drift_plain['error']['code'] ?? null, 'and the refusal is a conflict' );
assert_same( true, $drift_plain['error']['data']['drift']['content'] ?? null, 'and names content drift as the reason' );

$drift_guarded = rollback_rs_service( $drifted['snapshot_id'], false, true );
assert_same( false, $drift_guarded['ok'] ?? null, 'a drifted target is refused with protection too' );
assert_same( 'conflict', $drift_guarded['error']['code'] ?? null, 'and the refusal keeps its original code' );
assert_same( 'SOMEONE-ELSE-EDITED-THIS', (string) get_post( 81005 )->post_content, 'and nothing is written' );

/*
 * The redaction. On the protected path the error payload is narrowed to an
 * allow-list, because a Pro-side caller receiving this envelope must not be
 * handed page content or historical post-meta it never asked for. `drift`
 * carries both `current_side_effects` and the full expected/current checksums,
 * so it is exactly the key the allow-list drops.
 */
assert_true(
	isset( $drift_plain['error']['data']['drift'] ),
	'the unprotected refusal carries the full drift diagnostics'
);
assert_true(
	! isset( $drift_guarded['error']['data']['drift'] ),
	'#512: the protected refusal drops the drift diagnostics rather than forwarding content and post-meta to a Pro caller'
);
assert_same(
	$drifted['snapshot_id'],
	$drift_guarded['error']['data']['snapshot_id'] ?? null,
	'the protected refusal keeps the snapshot id, which the allow-list permits'
);
assert_true(
	array_key_exists( 'recovery_point', $drift_guarded['error']['data'] ?? array() ),
	'#512: every protected failure reports a recovery_point block, so a caller never has to guess whether one exists'
);
assert_same(
	false,
	$drift_guarded['error']['data']['recovery_point']['created'] ?? null,
	'a refusal reached before the capture reports no recovery point was created'
);
assert_same(
	false,
	$drift_guarded['error']['data']['recovery_point']['usable'] ?? null,
	'and reports it unusable, so an absent point cannot read as a usable one'
);

/*
 * ---------------------------------------------------------------------------
 * 6. #460's integrity refusal survives the move.
 * ---------------------------------------------------------------------------
 *
 * This refusal is ours, not upstream's — upstream's restore has no integrity
 * branch at all. It is the assertion most at risk from adopting upstream's body
 * wholesale, which is why it is pinned here as well as in
 * `test-rollback-payload-integrity.php`.
 */

$tampered = rollback_rs_seed( 81006, 'BEFORE-81006', 'AFTER-81006' );
$record   = get_option( $tampered['option'], array() );
$digest   = hash( 'sha256', 'BEFORE-81006' );
// One byte different, still a well-formed sha256: only a real comparison
// against the value can tell the two apart.
$record['before']['checksum'] = 'sha256:' . substr( $digest, 0, 63 ) . ( '0' === substr( $digest, 63, 1 ) ? '1' : '0' );
update_option( $tampered['option'], $record, false );

$mismatch = rollback_rs_service( $tampered['snapshot_id'] );
assert_same( false, $mismatch['ok'] ?? null, '#460: a payload that does not hash to its own checksum is still refused' );
assert_same( 'conflict', $mismatch['error']['code'] ?? null, '#460: and still as a conflict' );
assert_same( 'mismatch', $mismatch['error']['data']['before']['integrity'] ?? null, '#460: and still names integrity as the reason rather than blaming the status' );
assert_same( 'AFTER-81006', (string) get_post( 81006 )->post_content, '#460: and writes nothing' );

/*
 * ---------------------------------------------------------------------------
 * 7. A run chunk is still the route's business, never the service's.
 * ---------------------------------------------------------------------------
 *
 * The route dispatches run records to `rollback_snapshot_run_restore()`, which
 * needs the request for `page_ids`. The service takes no request, so it refuses
 * rather than half-handling one — and says where to go instead.
 */

$run = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_preset_reassign', array( 'tool_operation' => 'preset.reassign' ) ) );
$run_post = diviops_test_register_post( 81007, 'BEFORE-81007' );
$capture  = array( &$run, $run_post );
diviops_call_ref( 'rollback_snapshot_run_capture', $capture );
$run_post->post_content = 'AFTER-81007';
$mark = array( &$run, 81007, 'write_applied', 'AFTER-81007' );
diviops_call_ref( 'rollback_snapshot_run_mark', $mark );
$flush     = array( &$run );
$stored    = diviops_call_ref( 'rollback_snapshot_run_flush', $flush );
$chunk_id  = str_replace( 'diviops_rollback_snapshot_', '', $stored[0] );

$run_route = rollback_rs_route( array( 'snapshot_id' => $chunk_id ) );
assert_same( true, $run_route['ok'] ?? null, 'the route still restores a run chunk through the run path' );
assert_same( 'BEFORE-81007', (string) get_post( 81007 )->post_content, 'and the run chunk still writes its pages back' );

$run_post->post_content = 'AFTER-81007';
$run_service = rollback_rs_service( $chunk_id );
assert_same( false, $run_service['ok'] ?? null, '#512: the service refuses a run chunk instead of treating it as a single target' );
assert_same( 'invalid_input', $run_service['error']['code'] ?? null, '#512: and refuses it as invalid input' );
assert_same( 'run', $run_service['error']['data']['kind'] ?? null, '#512: and names the kind, so a caller can route it correctly' );
assert_same( 'AFTER-81007', (string) get_post( 81007 )->post_content, '#512: and writes nothing' );

/*
 * ---------------------------------------------------------------------------
 * 8. The service validates its own inputs.
 * ---------------------------------------------------------------------------
 *
 * Pro reaches the service directly, so the argument guards cannot live only in
 * the REST handler. Both refusals are asserted from a service call.
 */

$bad_id = rollback_rs_service( 'not a snapshot id/../..' );
assert_same( false, $bad_id['ok'] ?? null, '#512: the service rejects a malformed snapshot id on its own' );
assert_same( 'invalid_input', $bad_id['error']['code'] ?? null, '#512: as invalid input' );

$absent = rollback_rs_service( 'rs99999999' );
assert_same( false, $absent['ok'] ?? null, '#512: the service reports an unknown snapshot id as a failure' );
assert_same( 'not_found', $absent['error']['code'] ?? null, '#512: as not_found' );

/*
 * ---------------------------------------------------------------------------
 * 9. The storage read-back predicate, on its own.
 * ---------------------------------------------------------------------------
 *
 * `rollback_snapshot_record_persisted()` is true on every path the sections
 * above exercise, so a mutation making it unconditionally true is invisible
 * there — a fixture hole, not a broader test. Driving it directly is the cheap
 * fix: it is the guard that stops `update_option()`'s two-meanings-for-false
 * from being read as "the recovery point is safely stored".
 */

$persisted_id     = 'rs81008';
$persisted_option = 'diviops_rollback_snapshot_' . $persisted_id;
$persisted_record = array( 'snapshot_id' => $persisted_id, 'status' => 'created', 'before' => array( 'value' => 'X' ) );

delete_option( $persisted_option );
assert_same(
	false,
	diviops_call( 'rollback_snapshot_record_persisted', array( $persisted_record ) ),
	'#512: a record that was never stored does not read back as persisted'
);

update_option( $persisted_option, $persisted_record, false );
assert_same(
	true,
	diviops_call( 'rollback_snapshot_record_persisted', array( $persisted_record ) ),
	'#512: a record the store returns unchanged reads back as persisted'
);

update_option( $persisted_option, array( 'snapshot_id' => $persisted_id, 'status' => 'created', 'before' => array( 'value' => 'Y' ) ), false );
assert_same(
	false,
	diviops_call( 'rollback_snapshot_record_persisted', array( $persisted_record ) ),
	'#512: a record the store returns ALTERED does not read back as persisted, which is the case update_option cannot report'
);

/*
 * ---------------------------------------------------------------------------
 * 10. A committed restore is never undone by a bookkeeping failure (#512).
 * ---------------------------------------------------------------------------
 *
 * Found by adversarial review before merge. `$respond` was applied to EVERY
 * protected-path failure, including ones raised after the content write had
 * already committed and verified. On those, re-entering
 * `rollback_snapshot_finish_recovery_point()` with `$failed = true` sees a page
 * that legitimately changed — the restore changed it — decides the recovery
 * point is usable, and spends it: the successful restore is written back out,
 * while the envelope still says `committed: true` and "The content change
 * stands." Both were then false.
 *
 * `finalization_failed` already returned directly for exactly this reason;
 * `status_readback_failed` did not. The fix closes the class rather than the
 * instance — past the commit there is no `$point` left to spend.
 *
 * The trigger below is a record stored without a `snapshot_id` key.
 * `rollback_snapshot_normalize_record()` deliberately tolerates that (it falls
 * back to the id in the option name), so such a record restores normally; only
 * the storage read-back notices. That is a real shape, not a contrived one, and
 * it makes the failure reachable without mocking the option store.
 */

$bookkeeping = rollback_rs_seed( 81009, 'BEFORE-81009', 'AFTER-81009' );
$bk_record   = get_option( $bookkeeping['option'], array() );
unset( $bk_record['snapshot_id'] );
update_option( $bookkeeping['option'], $bk_record, false );

// Control: the record still restores, i.e. the fixture reaches the write and is
// not refused earlier for being malformed.
$bk = rollback_rs_service( $bookkeeping['snapshot_id'], false, true );

assert_same(
	'BEFORE-81009',
	(string) get_post( 81009 )->post_content,
	'#512: a restore that committed stays committed even when its own bookkeeping read-back fails'
);
assert_same(
	false,
	$bk['error']['data']['recovery_point']['recovery_attempted'] ?? false,
	'#512: and no recovery is attempted, because past the commit there is nothing to recover from'
);

/*
 * ---------------------------------------------------------------------------
 * 11. The recovery point compares like with like (#512, #208).
 * ---------------------------------------------------------------------------
 *
 * Also from the pre-merge review. `before.value` is captured CANONICAL — #208
 * normalises it in `rollback_snapshot_before_from_post()` so a restore does not
 * fight WordPress's own save-time canonicalisation. The page on disk is raw.
 * `rollback_snapshot_finish_recovery_point()` compared the two directly, so any
 * page whose stored bytes are non-canonical read as "changed" when nothing had
 * touched it — and on the failure path that opens the bounded recovery attempt,
 * which then writes to a page no one had written to.
 *
 * #208's own comment records that such content exists in the wild: "Content
 * stored by a pre-#206 module_update is non-canonical on disk."
 */

$raw_markup = '<!-- wp:divi/text {"attrs":{"url":"https:\/\/example.com"}} --><!-- /wp:divi/text -->';
$frame_post = diviops_test_register_post( 81010, $raw_markup );
$frame_point = diviops_call( 'rollback_snapshot_create_for_post_write', array( $frame_post, 'rollback_snapshot_restore', array() ) );

// Control: the fixture really does straddle the two frames. Without this the
// assertions below would pass on any page at all.
assert_true(
	$frame_point['before']['value'] !== $raw_markup,
	'#208: the fixture is non-canonical on disk, so capture and page bytes genuinely differ'
);

$frame_evidence = diviops_call( 'rollback_snapshot_finish_recovery_point', array( $frame_point, true ) );

assert_same(
	false,
	$frame_evidence['state_changed'] ?? null,
	'#512: a page nobody touched reports state_changed false even when its stored bytes are non-canonical'
);
assert_same(
	false,
	$frame_evidence['recovery_attempted'] ?? null,
	'#512: so no recovery write fires against it'
);
assert_same(
	$raw_markup,
	(string) get_post( 81010 )->post_content,
	'#512: and the page is left byte-for-byte as it was'
);

/*
 * ---------------------------------------------------------------------------
 * 12. Holes found by adversarial review of this very file (#512).
 * ---------------------------------------------------------------------------
 *
 * A pre-merge review mutated the handler 28 ways and found that six survived
 * everything above — including three of the things `$protect_current` exists to
 * do. Every assertion in this section exists because a specific mutation lived.
 * The surviving mutation is named above each one, because that is the only
 * honest record of why the assertion is worth its line.
 */

/* Mutation: delete `unset( $data['readback']['side_effects'] )` on the success
 * path. Section 5 covers the FAILURE-path allow-list; nothing covered this. The
 * trait calls historical post-meta "the caller's least business on the path that
 * exists to hand evidence to another plugin" — so it is a contract, not tidying. */
$redact = rollback_rs_seed( 81011, 'BEFORE-81011', 'AFTER-81011' );
$redact_protected = rollback_rs_service( $redact['snapshot_id'], false, true );
assert_true(
	! isset( $redact_protected['data']['readback']['side_effects'] ),
	'#512: a protected restore omits post-meta from its success readback'
);

$redact_plain = rollback_rs_seed( 81012, 'BEFORE-81012', 'AFTER-81012' );
$redact_open  = rollback_rs_service( $redact_plain['snapshot_id'] );
assert_true(
	isset( $redact_open['data']['readback']['side_effects'] ),
	'#512: while an unprotected restore still reports them, so the omission is the protected path and not a lost field'
);

/* Mutation: delete the `$protect_current` write-safety preflight entirely. It is
 * item 1 of the three things the trait says protection adds — "so a recovery
 * point is never created that could not itself be restored" — and every fixture
 * above uses plain strings that pass it, so removing it changed nothing.
 *
 * The fixture has to be aimed precisely. The preflight checks TWO contents: the
 * one about to be written and the one about to be overwritten. Making the
 * RESTORE content unsafe proves nothing, because
 * `update_post_content_with_integrity_guard()` refuses that downstream anyway —
 * measured, both paths return the identical `invalid_input` and the same
 * "unbalanced or mis-nested" message, so the preflight could be deleted with no
 * visible change. The preflight's unique contribution is the CURRENT content: the
 * bytes that would go into the recovery point, which nothing else validates.
 *
 * So `before` is safe and the page holds unsafe bytes.
 * `assert_divi_full_content_safe_for_write()` (trait-core.php:584-599) refuses on
 * unbalanced container markers, so an opener with no closer reaches it. */
$unsafe_current = '<!-- wp:divi/section {"attrs":{}} -->';
$unsafe = rollback_rs_seed( 81013, 'BEFORE-81013', $unsafe_current );

$unsafe_guarded = rollback_rs_service( $unsafe['snapshot_id'], false, true );
assert_same( false, $unsafe_guarded['ok'] ?? null, '#512: a protected restore refuses when the content it would overwrite could not itself be restored' );
assert_same( $unsafe_current, (string) get_post( 81013 )->post_content, '#512: and writes nothing' );
assert_same(
	false,
	$unsafe_guarded['error']['data']['recovery_point']['created'] ?? null,
	'#512: refusing before the capture means no recovery point was created'
);

// The paired control, and the load-bearing half: the SAME snapshot restores
// without protection. Without it the assertion above would pass for any refusal
// at all — including the downstream write guard — and would not show that the
// preflight is what protection adds.
$unsafe_plain = rollback_rs_service( $unsafe['snapshot_id'] );
assert_same( true, $unsafe_plain['ok'] ?? null, '#512: the unprotected path runs no such preflight, so the same snapshot still restores over those bytes' );

/* Mutation: source `$content` in finish_recovery_point from `$point['before']['value']`
 * instead of the live page — i.e. record intent instead of observation, which the
 * trait docblock calls out as deliberately not what it does. Nothing asserted
 * `after_checksum`, so the swap was invisible. */
$observe = rollback_rs_seed( 81014, 'BEFORE-81014', 'AFTER-81014' );
$observe_result = rollback_rs_service( $observe['snapshot_id'], false, true );
assert_same(
	rollback_rs_checksum( 'BEFORE-81014' ),
	$observe_result['data']['restore_backup']['after_checksum'] ?? null,
	'#512: the recovery point records the page as it IS after the write, not the content the write intended'
);

/* Mutation: collapse `$failed && $unchanged ? 'aborted_before_write' : 'write_applied'`
 * to always `'write_applied'`. A point over a page that never changed has nothing
 * to give back, and saying otherwise offers a caller a recovery that would write
 * the same bytes twice. Section 11 reaches this branch; nothing read the status. */
$aborted_markup = '<!-- wp:divi/text {"attrs":{"url":"https:\/\/example.org"}} --><!-- /wp:divi/text -->';
$aborted_post   = diviops_test_register_post( 81015, $aborted_markup );
$aborted_point  = diviops_call( 'rollback_snapshot_create_for_post_write', array( $aborted_post, 'rollback_snapshot_restore', array() ) );
diviops_call( 'rollback_snapshot_finish_recovery_point', array( $aborted_point, true ) );
$aborted_stored = get_option( 'diviops_rollback_snapshot_' . $aborted_point['snapshot_id'], array() );
assert_same(
	'aborted_before_write',
	$aborted_stored['status'] ?? null,
	'#512: a recovery point over a page that never changed is stored as aborted_before_write, not as an applied write'
);

/* From the same review: the protected allow-list correctly strips `drift`, which
 * carries post-meta — but that left a protected drift refusal carrying nothing a
 * caller could act on beyond the snapshot id. `drift_kind` is the leak-free
 * discriminator. Asserted on both paths so it cannot quietly become
 * protected-only or leak the payload back. */
$kind_seed = rollback_rs_seed( 81016, 'BEFORE-81016', 'AFTER-81016' );
get_post( 81016 )->post_content = 'EDITED-ELSEWHERE';

$kind_guarded = rollback_rs_service( $kind_seed['snapshot_id'], false, true );
assert_same( 'content', $kind_guarded['error']['data']['drift_kind'] ?? null, '#512: a protected drift refusal still names which kind of drift it found' );
assert_true( ! isset( $kind_guarded['error']['data']['drift'] ), '#512: without carrying the payload that named it' );

$kind_plain = rollback_rs_service( $kind_seed['snapshot_id'] );
assert_same( 'content', $kind_plain['error']['data']['drift_kind'] ?? null, '#512: and the unprotected refusal reports the same kind' );
assert_true( isset( $kind_plain['error']['data']['drift'] ), '#512: alongside the full diagnostics it has always carried' );

/* `drift_kind` had only its `content` case covered, so collapsing the whole
 * expression to the literal `'content'` survived. Side-effect drift is the other
 * branch: the page's bytes still match, but the Divi post-meta the snapshot
 * captured does not. */
$se_seed = rollback_rs_seed( 81017, 'BEFORE-81017', 'AFTER-81017' );
update_post_meta( 81017, '_et_pb_use_builder', 'on' );

$se_drift = rollback_rs_service( $se_seed['snapshot_id'] );
assert_same( false, $se_drift['ok'] ?? null, '#512: post-meta that changed after the snapshot write is drift too' );
assert_same( 'side_effects', $se_drift['error']['data']['drift_kind'] ?? null, '#512: and drift_kind names it as side_effects, not content' );
assert_same( false, $se_drift['error']['data']['drift']['content'] ?? null, '#512: with content drift explicitly false, so the two kinds are distinguishable' );
assert_same( 'AFTER-81017', (string) get_post( 81017 )->post_content, '#512: and nothing is written' );

/*
 * ---------------------------------------------------------------------------
 * 13. Retention must not evict the snapshot being restored (#512, #514).
 * ---------------------------------------------------------------------------
 *
 * Raised as SUSPECTED by the pre-merge review ("consequence looks cosmetic"),
 * then measured. It is not cosmetic, and this PR is what makes it reachable:
 * before `$protect_current` existed a restore never captured, so
 * `rollback_snapshot_enforce_retention()` never ran during one.
 *
 * `enforce_retention()` deletes oldest-first past the 500-row cap, with no
 * notion of a row an in-flight operation is holding. A protected restore of an
 * OLD snapshot captures its recovery point first — which runs retention — which
 * can delete the very row the restore is working from. The restore then
 * finishes and `update_option()` writes that record back, which on a deleted row
 * is an INSERT, not an update.
 *
 * Measured before the fix: the target's `option_id` went 1 → 523. The row
 * survives and the content is correct, so nothing looks wrong — but the snapshot
 * has been silently promoted from oldest to newest in the retention order, and
 * genuinely newer snapshots will now be evicted ahead of it. A store that
 * quietly reorders its own eviction queue during a read-and-restore is the kind
 * of wrong that only shows up as "why is that old snapshot still here and my
 * recent one gone".
 *
 * The fixture has to exceed the real cap for retention to fire at all, so it
 * builds 520 filler rows and removes them again — `test-rollback-retention.php`
 * asserts an absolute row count, and leaving them behind would break it.
 */

$ret_seed   = rollback_rs_seed( 81018, 'BEFORE-81018', 'AFTER-81018' );
$ret_row_id = diviops_test_option_row( $ret_seed['option'] )['option_id'];

$ret_fillers = array();
for ( $i = 0; $i < 520; $i++ ) {
	$ret_fillers[] = 'diviops_rollback_snapshot_zzfill' . $i;
	update_option( 'diviops_rollback_snapshot_zzfill' . $i, array( 'snapshot_id' => 'zzfill' . $i, 'status' => 'write_applied' ), false );
}

// Control: the fixture really is over the cap and the target really is among the
// oldest, which is what puts it in the delete slice. Without this the assertion
// below passes on a store retention never touched.
assert_true(
	rollback_rs_option_count() > 500,
	'#514: the fixture exceeds the retention cap, so enforce_retention() actually runs'
);

$ret_result = rollback_rs_service( $ret_seed['snapshot_id'], false, true );
assert_same( true, $ret_result['ok'] ?? null, '#514: the restore itself still succeeds' );
assert_same( 'BEFORE-81018', (string) get_post( 81018 )->post_content, '#514: and writes the right content' );

assert_same(
	$ret_row_id,
	diviops_test_option_row( $ret_seed['option'] )['option_id'],
	'#514: retention does not evict the snapshot the restore is holding, so its place in the eviction order is unchanged'
);

foreach ( $ret_fillers as $ret_filler ) {
	delete_option( $ret_filler );
}
