<?php
// SPDX-License-Identifier: MIT
/**
 * Run-scoped rollback snapshot record shape (#199).
 *
 * preset_reassign() takes one snapshot per applied page, the store is capped at
 * 500 rows, and REASSIGN_MAX_PAGES is 1000 — so an apply of 501-1000 pages keeps
 * only its last 500 snapshots while reporting success for all of them. The fix is
 * a record that covers a chunk of pages instead of one page, so a run costs
 * ceil(pages / 100) rows.
 *
 * The load-bearing assertion in this file is the negative one at the bottom: a v2
 * run record must normalize to null through the v1 path. That is what keeps every
 * existing reader skipping v2 records instead of misreading them — including
 * rollback_snapshot_managed_inventory(), the PHP seam DiviOps Agent Pro's managed
 * recovery reads. Pro is closed-source, separately shipped, and cannot be updated
 * or tested from this repository, so its behavior for single-target snapshots has
 * to stay bit-for-bit unchanged. See spec D3
 * (docs/superpowers/specs/2026-08-18-run-scoped-rollback-snapshots-design.md).
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$chunk = (int) diviops_call( 'rollback_snapshot_run_chunk_size' );
assert_same( 100, $chunk, 'the run chunk bound is 100 pages per record' );

$run_id = (string) diviops_call( 'rollback_snapshot_generate_run_id', array( 'diviops_preset_reassign' ) );
assert_true(
	(bool) preg_match( '/^run_[0-9]{14}_[a-f0-9]{16}$/', $run_id ),
	'a run id is distinguishable from a per-page snapshot id by prefix alone, without loading the row'
);
assert_true(
	false !== diviops_call( 'rollback_snapshot_validate_id', array( $run_id ) ),
	'a run id passes the shared snapshot id validator, so it stores and addresses like any other snapshot id'
);

/*
 * A chunk id derives from the run id, so every chunk of a run is addressable on
 * its own and still traceable back to the run. It has to survive the same
 * validator, or the chunk could be written and then never read back.
 */
assert_true(
	false !== diviops_call( 'rollback_snapshot_validate_id', array( $run_id . '_c1' ) ),
	'a chunk id derived from a run id also passes the validator'
);

$record = array(
	'schema_version' => 2,
	'snapshot_id'    => $run_id . '_c1',
	'run_id'         => $run_id,
	'chunk'          => 1,
	'status'         => 'write_applied',
	'created_at'     => gmdate( 'c' ),
	'tool'           => 'diviops_preset_reassign',
	'targets'        => array(
		array( 'id' => 900, 'kind' => 'post', 'post_type' => 'page' ),
	),
);

assert_true(
	(bool) diviops_call( 'rollback_snapshot_is_run_record', array( $record ) ),
	'a v2 record carrying targets is recognized as a run record'
);

$legacy = array(
	'schema_version' => 1,
	'snapshot_id'    => 'snap_20260818000000_abcdef0123456789',
	'target'         => array( 'id' => 900, 'kind' => 'post' ),
);
assert_true(
	! diviops_call( 'rollback_snapshot_is_run_record', array( $legacy ) ),
	'a v1 single-target record is not mistaken for a run record'
);

/*
 * Keyed on the payload, not the version number alone: a record claiming v2
 * without targets to back it is malformed, and treating it as a run record would
 * send the restore path walking an empty list and reporting success.
 */
$hollow = array(
	'schema_version' => 2,
	'snapshot_id'    => $run_id . '_c9',
	'targets'        => array(),
);
assert_true(
	! diviops_call( 'rollback_snapshot_is_run_record', array( $hollow ) ),
	'a record claiming v2 with no targets is not treated as a run record'
);

/*
 * The compatibility guarantee. If this assertion ever fails, Pro's managed
 * recovery is being handed a record shape it was not built for.
 */
assert_same(
	null,
	diviops_call(
		'rollback_snapshot_normalize_record',
		array( $record, 'diviops_rollback_snapshot_' . $run_id . '_c1', $record )
	),
	'a v2 run record normalizes to null through the v1 path, so every legacy reader skips it'
);
