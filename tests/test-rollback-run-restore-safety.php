<?php
// SPDX-License-Identifier: MIT
/**
 * Permission, honesty, and input gates on run-scoped restore (#199).
 *
 * Every assertion here exists because an adversarial review reproduced the
 * opposite behavior against the first version of the v2 read/restore paths.
 * Recorded as tests rather than as a fixed diff, because each one is a property
 * a future reader could plausibly break again:
 *
 *   - get/delete on a run chunk ran with NO per-object permission gate, on a
 *     route whose permission_callback is only current_user_can('edit_posts').
 *     A Contributor could enumerate pages they cannot edit and destroy the sole
 *     recovery record for them. Before v2 those calls answered 400 and were
 *     harmless, so the v2 path introduced the exposure.
 *   - A restore in which every single page failed still answered ok:true.
 *   - page_ids was unregistered on the route, so "" restored the WHOLE run and
 *     absint() coerced junk into real page ids.
 *   - Partial restores clobbered each other's bookkeeping, and a page this tool
 *     had already restored was later refused as "changed after the run wrote it".
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Seed a stored run chunk left in the post-write state.
 *
 * @param int  $base  First post id.
 * @param int  $count Pages covered.
 * @param bool $mark  Whether to mark each entry.
 * @return array{chunk: string, option: string, ids: array<int, int>}
 */
function run_safety_seed( int $base, int $count, bool $mark = true ): array {
	$run = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_preset_reassign', array() ) );
	$ids = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$id    = $base + $i;
		$ids[] = $id;
		$post  = diviops_test_register_post( $id, 'BEFORE-' . $id );

		$cap = array( &$run, $post );
		diviops_call_ref( 'rollback_snapshot_run_capture', $cap );

		$post->post_content = 'AFTER-' . $id;
		if ( $mark ) {
			$mk = array( &$run, $id, 'write_applied', 'AFTER-' . $id );
			diviops_call_ref( 'rollback_snapshot_run_mark', $mk );
		}
	}
	$fl     = array( &$run );
	$stored = diviops_call_ref( 'rollback_snapshot_run_flush', $fl );

	return array(
		'chunk'  => str_replace( 'diviops_rollback_snapshot_', '', $stored[0] ),
		'option' => $stored[0],
		'ids'    => $ids,
	);
}

/**
 * Call a rollback handler and return its decoded envelope.
 *
 * @param string               $method Handler name.
 * @param array<string, mixed> $params Request params.
 * @return array<string, mixed>
 */
function run_safety_call( string $method, array $params ): array {
	$request = new WP_REST_Request();
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = DiviOps_Agent::$method( $request );
	$data     = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : $response;
	return is_array( $data ) ? $data : array();
}

/*
 * ---------------------------------------------------------------------------
 * Permission: a caller who cannot edit any covered page learns nothing and
 * destroys nothing.
 * ---------------------------------------------------------------------------
 */

$denied = run_safety_seed( 82000, 3 );
$GLOBALS['diviops_test_uneditable_ids'] = $denied['ids'];

$denied_get = run_safety_call( 'rollback_snapshot_get', array( 'snapshot_id' => $denied['chunk'] ) );
assert_same( false, $denied_get['ok'] ?? null, 'reading a run chunk covering only pages the caller cannot edit is refused' );
assert_same( 'forbidden', $denied_get['error']['code'] ?? null, 'the refusal is a permission refusal' );

$denied_delete = run_safety_call( 'rollback_snapshot_delete', array( 'snapshot_id' => $denied['chunk'] ) );
assert_same( false, $denied_delete['ok'] ?? null, 'deleting a run chunk covering pages the caller cannot edit is refused' );
assert_true(
	false !== get_option( $denied['option'], false ),
	'the recovery record survives a refused delete — destroying it would remove the only rollback for pages the caller has no rights over'
);

unset( $GLOBALS['diviops_test_uneditable_ids'] );

/*
 * A caller who can edit SOME covered pages sees only those. The chunk is a
 * batching detail; it must not become a way to enumerate pages by side effect.
 */
$GLOBALS['diviops_test_uneditable_ids'] = array( 82000, 82001 );

$partial_view = run_safety_call( 'rollback_snapshot_get', array( 'snapshot_id' => $denied['chunk'] ) );
assert_same( true, $partial_view['ok'] ?? null, 'a caller who can edit one covered page may read the chunk' );
$visible_ids = array();
foreach ( $partial_view['data']['targets'] ?? array() as $entry ) {
	$visible_ids[] = (int) $entry['id'];
}
assert_same( array( 82002 ), $visible_ids, 'only the pages the caller may edit are returned' );

$mixed_delete = run_safety_call( 'rollback_snapshot_delete', array( 'snapshot_id' => $denied['chunk'] ) );
assert_same( false, $mixed_delete['ok'] ?? null, 'deleting a chunk is refused unless the caller may edit every page it covers' );

unset( $GLOBALS['diviops_test_uneditable_ids'] );
delete_option( $denied['option'] );

/*
 * ---------------------------------------------------------------------------
 * Honesty: a restore where nothing was restored is not a success.
 * ---------------------------------------------------------------------------
 */

$all_fail = run_safety_seed( 83000, 2, false );
$failed   = run_safety_call( 'rollback_snapshot_restore', array( 'snapshot_id' => $all_fail['chunk'] ) );

assert_same( false, $failed['ok'] ?? null, 'a restore in which no page was restored reports failure, not success' );
assert_same( 0, count( $failed['error']['data']['restored'] ?? array() ), 'the failed envelope still reports what was restored' );
assert_same( 2, count( $failed['error']['data']['refused'] ?? array() ), 'the failed envelope names every refused page' );

delete_option( $all_fail['option'] );

/*
 * A partial restore is also not a plain success. preset_reassign already sets
 * this convention with preset.reassign_partial_failure; a rollback that half
 * worked must not be indistinguishable from one that fully worked.
 */
$half = run_safety_seed( 84000, 3 );
get_post( 84001 )->post_content = 'EDITED AFTER THE RUN';
$half_result = run_safety_call( 'rollback_snapshot_restore', array( 'snapshot_id' => $half['chunk'] ) );

assert_same( false, $half_result['ok'] ?? null, 'a restore where some pages were refused is not reported as a plain success' );
assert_same( 2, count( $half_result['error']['data']['restored'] ?? array() ), 'the partial envelope reports the pages that did restore' );
assert_same( 1, count( $half_result['error']['data']['refused'] ?? array() ), 'the partial envelope reports the page that did not' );

delete_option( $half['option'] );

/*
 * ---------------------------------------------------------------------------
 * Input: page_ids is strict. An empty string is what a form-encoded REST client
 * sends for "page_ids=", and it must not take the maximum-blast-radius branch.
 * ---------------------------------------------------------------------------
 */

$strict = run_safety_seed( 85000, 3 );

foreach ( array( '' => 'an empty string', '-1' => 'a negative id', 'abc' => 'a non-numeric id' ) as $value => $label ) {
	$result = run_safety_call(
		'rollback_snapshot_restore',
		array( 'snapshot_id' => $strict['chunk'], 'page_ids' => '' === $value ? '' : array( $value ) )
	);
	assert_same( false, $result['ok'] ?? null, $label . ' in page_ids is refused rather than acted on' );
	assert_same( 'invalid_input', $result['error']['code'] ?? null, $label . ' is refused as invalid input' );
}

foreach ( $strict['ids'] as $id ) {
	assert_same( 'AFTER-' . $id, (string) get_post( $id )->post_content, 'page ' . $id . ' was not touched by any refused page_ids call' );
}

delete_option( $strict['option'] );

/*
 * ---------------------------------------------------------------------------
 * Bookkeeping: partial restores accumulate, and a page this tool already
 * restored is not later blamed on someone else's edit.
 * ---------------------------------------------------------------------------
 */

$ledger = run_safety_seed( 86000, 3 );

run_safety_call( 'rollback_snapshot_restore', array( 'snapshot_id' => $ledger['chunk'], 'page_ids' => array( 86000 ) ) );
run_safety_call( 'rollback_snapshot_restore', array( 'snapshot_id' => $ledger['chunk'], 'page_ids' => array( 86001 ) ) );

$record = get_option( $ledger['option'], null );
$ledger_ids = $record['restore']['restored_page_ids'] ?? array();
sort( $ledger_ids );
assert_same(
	array( 86000, 86001 ),
	$ledger_ids,
	'a second partial restore adds to the ledger instead of clobbering the first'
);
assert_same(
	'partially_restored',
	(string) ( $record['status'] ?? '' ),
	'a chunk with some pages restored no longer claims the status it had before any restore'
);

/*
 * Re-restoring an already-restored page is still refused — the drift binding is
 * load-bearing — but the reason must not say the page changed after the run.
 * This tool changed it, and a caller triaging a bad rollback needs to tell
 * "already reverted" apart from "a human edited this".
 */
$again = run_safety_call( 'rollback_snapshot_restore', array( 'snapshot_id' => $ledger['chunk'], 'page_ids' => array( 86000 ) ) );
$again_entry = ( $again['error']['data']['refused'] ?? array() )[0] ?? array();
assert_same(
	'already_restored',
	$again_entry['reason'] ?? null,
	'a page this tool already restored is reported as already restored, not as drifted'
);

delete_option( $ledger['option'] );

/*
 * ---------------------------------------------------------------------------
 * Display: the admin dashboard reads a singular target, which a run chunk does
 * not carry. Rendering it as "page #0" is what happens when the label is built
 * without a run branch.
 * ---------------------------------------------------------------------------
 */

$label_run = diviops_call(
	'rollback_snapshot_display_label',
	array(
		array( 'kind' => 'run', 'page_count' => 12, 'run_id' => 'run_20260819000000_abcdef0123456789' ),
	)
);
assert_true(
	false === strpos( (string) $label_run, '#0' ),
	'a run chunk never renders as a phantom "#0" target in the dashboard'
);
assert_true(
	false !== strpos( (string) $label_run, '12' ),
	'a run chunk renders how many pages it covers'
);

$label_single = diviops_call(
	'rollback_snapshot_display_label',
	array(
		array( 'target' => array( 'post_type' => 'page', 'id' => 900, 'kind' => 'post' ) ),
	)
);
assert_same( 'page #900', $label_single, 'a single-target snapshot renders exactly as it did before run records existed' );

/*
 * ---------------------------------------------------------------------------
 * The discriminating page_ids case.
 *
 * The loop above asserts only ok:false and code=invalid_input, and an adversarial
 * review showed that is not enough: with the strict check reverted to absint(),
 * ALL of those shapes are still refused as invalid_input — by the pre-existing
 * unknown-page-id branch — so the assertions pass against the unfixed code. They
 * test the mechanism, not the property.
 *
 * The shape that discriminates is digits-then-junk that absint() coerces into a
 * REAL covered page id. Under absint() that call succeeds and reverts a page the
 * caller never named. Asserting the page's content is what makes this fail when
 * the fix is removed.
 * ---------------------------------------------------------------------------
 */

$coerce = run_safety_seed( 87000, 2 );

$coerced = run_safety_call(
	'rollback_snapshot_restore',
	array( 'snapshot_id' => $coerce['chunk'], 'page_ids' => array( '87000abc' ) )
);

assert_same( false, $coerced['ok'] ?? null, 'a digits-then-junk page id is refused rather than coerced into a real page id' );
assert_same(
	array( '87000abc' ),
	$coerced['error']['data']['invalid_page_ids'] ?? null,
	'the refusal names the malformed id, proving it was rejected as malformed and not merely as an unknown page'
);
assert_same(
	'AFTER-87000',
	(string) get_post( 87000 )->post_content,
	'the page the malformed id would have coerced to still holds its post-run content — nothing was reverted'
);

$record_after = get_option( $coerce['option'], null );
assert_true(
	empty( $record_after['restore']['restored_page_ids'] ),
	'no restore ledger was written by a refused call'
);

/*
 * An empty page_ids array restores nothing, so it must not report success — the
 * same class of lie as an all-refused run reporting ok:true.
 */
$empty_selection = run_safety_call(
	'rollback_snapshot_restore',
	array( 'snapshot_id' => $coerce['chunk'], 'page_ids' => array() )
);
assert_same( false, $empty_selection['ok'] ?? null, 'an empty page_ids selection is refused rather than reported as a successful restore of nothing' );

delete_option( $coerce['option'] );

/*
 * The route contract. This harness has no register_rest_route()/REST-dispatch
 * machinery — tests/test-media-positive-id-validation.php records the same
 * limitation — so the validator is a named method and is tested directly, and the
 * wiring is guarded structurally by reading the registration source. What stays
 * unverified here, exactly as for every other validate_callback in this codebase,
 * is that WordPress itself invokes the callback for this route.
 */

assert_true(
	is_wp_error( DiviOps_Agent::rollback_snapshot_validate_page_ids_param( array( '87000abc' ) ) ),
	'the route validator rejects a digits-then-junk id before the handler is reached'
);
assert_true(
	is_wp_error( DiviOps_Agent::rollback_snapshot_validate_page_ids_param( array( -1 ) ) ),
	'the route validator rejects a negative id'
);
assert_true(
	is_wp_error( DiviOps_Agent::rollback_snapshot_validate_page_ids_param( 'not-an-array' ) ),
	'the route validator rejects a bare scalar, which is what page_ids= sends'
);
assert_same(
	true,
	DiviOps_Agent::rollback_snapshot_validate_page_ids_param( array( 87000 ) ),
	'the route validator accepts a legitimate integer id'
);
assert_same(
	true,
	DiviOps_Agent::rollback_snapshot_validate_page_ids_param( array( '87000' ) ),
	'the route validator accepts a legitimate digit string'
);

$registration = file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );
$restore_route_pos = strpos( $registration, "/rollback-snapshot/restore/" );
assert_true( false !== $restore_route_pos, 'the rollback restore route registration is locatable, so the next assertion measures something' );

$restore_block = substr( $registration, $restore_route_pos, 1200 );
assert_true(
	false !== strpos( $restore_block, "'page_ids'" ),
	'page_ids is declared on the restore route rather than being an undeclared body field'
);
assert_true(
	false !== strpos( $restore_block, 'rollback_snapshot_validate_page_ids_param' ),
	'the restore route wires page_ids to the validator asserted above, so the two cannot drift apart'
);
