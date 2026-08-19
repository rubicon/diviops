<?php
// SPDX-License-Identifier: MIT
/**
 * Reading and restoring a run-scoped rollback snapshot (#199, spec D1/D5/D6).
 *
 * Until this lands a v2 chunk record is write-only: rollback_snapshot_get,
 * _restore and _delete all route through rollback_snapshot_normalize_record(),
 * which rejects a record with no singular target.id, and answer HTTP 400
 * "Rollback snapshot record is malformed."; _list omits it entirely. That is why
 * this task has to merge BEFORE preset_reassign is rewired — rewiring first would
 * trade N restorable rows for one unreadable blob, a regression that bites at page
 * one while the overflow it fixes only bites past page 500.
 *
 * The behavior that matters most here is partial restore (spec D1/D6). A bad
 * reassign usually goes wrong on a subset — a few pages where the preset resolved
 * differently than expected — so a caller must be able to revert those without
 * discarding the pages that came out right, and one drifted page must not block
 * its siblings. Any page edited after the run would otherwise veto the whole
 * recovery, which is the common case rather than the rare one.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Build a stored run chunk covering $count pages, left in the post-write state.
 *
 * Each page ends up holding its "after" content, so a restore of that page is not
 * refused for drift — drift is introduced deliberately, per assertion, below.
 *
 * @param int    $base_id First post id; ids are sequential from here.
 * @param int    $count   Pages in the run.
 * @param bool   $mark    Whether to mark each entry (an unmarked entry is unrestorable by design).
 * @return array{chunk_id: string, option: string, ids: array<int, int>}
 */
function run_restore_seed( int $base_id, int $count, bool $mark = true ): array {
	$run = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_preset_reassign', array( 'tool_operation' => 'preset.reassign' ) ) );
	$ids = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$post_id = $base_id + $i;
		$ids[]   = $post_id;

		$post = diviops_test_register_post( $post_id, 'BEFORE-' . $post_id );

		$capture = array( &$run, $post );
		diviops_call_ref( 'rollback_snapshot_run_capture', $capture );

		// The live page moves to its post-write state, exactly as a real run's
		// guarded write would leave it before the entry is marked.
		$post->post_content = 'AFTER-' . $post_id;

		if ( $mark ) {
			$mark_args = array( &$run, $post_id, 'write_applied', 'AFTER-' . $post_id );
			diviops_call_ref( 'rollback_snapshot_run_mark', $mark_args );
		}
	}

	$flush  = array( &$run );
	$stored = diviops_call_ref( 'rollback_snapshot_run_flush', $flush );

	return array(
		'chunk_id' => str_replace( 'diviops_rollback_snapshot_', '', $stored[0] ),
		'option'   => $stored[0],
		'ids'      => $ids,
	);
}

/**
 * Invoke a rollback handler with the given params and return its decoded envelope.
 *
 * @param string               $method Handler name on DiviOps_Agent.
 * @param array<string, mixed> $params Request params.
 * @return array<string, mixed>
 */
function run_restore_call( string $method, array $params ): array {
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
 * Task 4 — a run record is readable.
 * ---------------------------------------------------------------------------
 */

$read = run_restore_seed( 72000, 3 );

$got = run_restore_call( 'rollback_snapshot_get', array( 'snapshot_id' => $read['chunk_id'] ) );
assert_same( true, $got['ok'] ?? null, 'rollback_snapshot_get returns a success envelope for a run chunk instead of rejecting it as malformed' );
assert_same( 'run', $got['data']['kind'] ?? null, 'a run chunk reports kind "run" so a caller can tell it apart from a single-page snapshot' );
assert_same( 3, count( $got['data']['targets'] ?? array() ), 'rollback_snapshot_get returns every page the chunk covers' );

$listed = run_restore_call( 'rollback_snapshot_list', array() );
$found  = null;
foreach ( $listed['data']['snapshots'] ?? array() as $row ) {
	if ( ( $row['snapshot_id'] ?? '' ) === $read['chunk_id'] ) {
		$found = $row;
	}
}
assert_true( null !== $found, 'a run chunk appears in rollback_snapshot_list rather than being silently omitted' );
assert_same( 'run', $found['kind'] ?? null, 'the listed run chunk reports kind "run"' );
assert_same( 3, $found['page_count'] ?? null, 'the listed run chunk reports how many pages it covers' );

delete_option( $read['option'] );

/*
 * ---------------------------------------------------------------------------
 * Task 5 — restoring the whole run.
 * ---------------------------------------------------------------------------
 */

$whole = run_restore_seed( 73000, 3 );

$restored = run_restore_call( 'rollback_snapshot_restore', array( 'snapshot_id' => $whole['chunk_id'] ) );
assert_same( true, $restored['ok'] ?? null, 'restoring a run chunk with no page filter succeeds' );
assert_same( 3, count( $restored['data']['restored'] ?? array() ), 'every page in the run is restored when no filter is given' );
assert_same( 0, count( $restored['data']['refused'] ?? array() ), 'no page is refused when nothing has drifted' );

foreach ( $whole['ids'] as $post_id ) {
	assert_same(
		'BEFORE-' . $post_id,
		(string) get_post( $post_id )->post_content,
		'page ' . $post_id . ' holds its pre-run content after a whole-run restore'
	);
}

delete_option( $whole['option'] );

/*
 * ---------------------------------------------------------------------------
 * Task 5 — restoring a subset. Batching storage must not cost the caller the
 * ability to pick pages, which per-page snapshots gave them today.
 * ---------------------------------------------------------------------------
 */

$subset = run_restore_seed( 74000, 3 );

$partial = run_restore_call(
	'rollback_snapshot_restore',
	array( 'snapshot_id' => $subset['chunk_id'], 'page_ids' => array( 74001 ) )
);
assert_same( true, $partial['ok'] ?? null, 'restoring a named subset of a run succeeds' );
assert_same( 1, count( $partial['data']['restored'] ?? array() ), 'exactly the named page is restored' );

assert_same( 'BEFORE-74001', (string) get_post( 74001 )->post_content, 'the named page is reverted' );
assert_same( 'AFTER-74000', (string) get_post( 74000 )->post_content, 'a page not named is left exactly as it was' );
assert_same( 'AFTER-74002', (string) get_post( 74002 )->post_content, 'the other unnamed page is left exactly as it was' );

/*
 * A page id that is not in this run is refused by name. Ignoring it would let a
 * caller believe a page was reverted when nothing touched it.
 */
$stranger = run_restore_call(
	'rollback_snapshot_restore',
	array( 'snapshot_id' => $subset['chunk_id'], 'page_ids' => array( 999999 ) )
);
assert_same( false, $stranger['ok'] ?? null, 'naming a page that is not in the run is an error, not a silent no-op' );
assert_same( 'invalid_input', $stranger['error']['code'] ?? null, 'the unknown page id is refused as invalid input' );

delete_option( $subset['option'] );

/*
 * ---------------------------------------------------------------------------
 * Task 5 — a drifted page is refused alone, and its siblings still restore
 * (spec D6). Refusing the whole run because one page changed would make the
 * common recovery case unusable.
 * ---------------------------------------------------------------------------
 */

$drift = run_restore_seed( 75000, 3 );
get_post( 75001 )->post_content = 'EDITED BY SOMEONE ELSE AFTER THE RUN';

$mixed = run_restore_call( 'rollback_snapshot_restore', array( 'snapshot_id' => $drift['chunk_id'] ) );

assert_same( 2, count( $mixed['data']['restored'] ?? array() ), 'the two undrifted pages still restore' );
assert_same( 1, count( $mixed['data']['refused'] ?? array() ), 'the drifted page is refused' );

$refused_entry = ( $mixed['data']['refused'] ?? array() )[0] ?? array();
assert_same( 75001, $refused_entry['id'] ?? null, 'the refusal names the drifted page' );
assert_same( 'conflict', $refused_entry['reason'] ?? null, 'the drifted page is refused as a conflict, matching the single-snapshot restore contract' );

assert_same( 'BEFORE-75000', (string) get_post( 75000 )->post_content, 'a sibling of the drifted page is restored' );
assert_same( 'EDITED BY SOMEONE ELSE AFTER THE RUN', (string) get_post( 75001 )->post_content, 'the drifted page is left untouched rather than overwritten' );

delete_option( $drift['option'] );

/*
 * ---------------------------------------------------------------------------
 * Task 5 — an entry that was captured but never marked carries no after
 * checksum, so it cannot be safely restored. It has to be reported, not skipped
 * silently (spec D5).
 * ---------------------------------------------------------------------------
 */

$unmarked = run_restore_seed( 76000, 2, false );

$unmarked_result = run_restore_call( 'rollback_snapshot_restore', array( 'snapshot_id' => $unmarked['chunk_id'] ) );
assert_same( 0, count( $unmarked_result['data']['restored'] ?? array() ), 'an unmarked entry is not restored' );
assert_same( 2, count( $unmarked_result['data']['refused'] ?? array() ), 'every unmarked entry is reported rather than silently skipped' );

$unmarked_entry = ( $unmarked_result['data']['refused'] ?? array() )[0] ?? array();
assert_same(
	'unrestorable',
	$unmarked_entry['reason'] ?? null,
	'an entry with no after checksum is reported unrestorable, which is the state that makes it unrecoverable'
);

delete_option( $unmarked['option'] );
