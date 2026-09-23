<?php
// SPDX-License-Identifier: MIT
/**
 * `restorable` must not claim a payload the store cannot verify (#460).
 *
 * ── What was measured, and what it does and does not show ─────────────────
 *
 * Read-only on staging, 2026-09-23: of 64 stored snapshots carrying a
 * `before.value`, 26 had a `before.checksum` that did not match
 * `hash( 'sha256', before.value )`. Split by capture month:
 *
 *     2026-07   match  3   mismatch  1
 *     2026-08   match 26   mismatch 25
 *     2026-09   match  9   mismatch  0
 *
 * Every mismatch is historical. Current capture normalises `$value` first and
 * then derives BOTH `checksum` and `value` from that one string
 * (`trait-rollback.php` rollback_snapshot_before_from_post), so it cannot
 * produce the split. The 26 rows are data, not a live capture defect, and
 * nothing in this file claims otherwise — it does not test capture.
 *
 * What IS a live defect is the reporting. `restorable` was computed from the
 * PRESENCE of a value, never its integrity, so all 26 rows advertise
 * `restorable: true`. A recovery flag that says yes about bytes nobody
 * verified is the "a skip must not look like a pass" rule applied to the one
 * subsystem whose whole job is being trustworthy when something has already
 * gone wrong.
 *
 * Why refuse rather than restore anyway: the value is the ONLY thing a restore
 * can write, and a checksum that disagrees with it means the row was altered
 * after capture — truncated in storage, re-serialised, hand-edited. Writing
 * unverifiable bytes over a live page is the failure this subsystem exists to
 * prevent. The bytes are not destroyed by refusing; `rollback_snapshot_get`
 * still reports the row, and now reports WHY it is unusable.
 *
 * The load-bearing shape below is the paired fixture: `$intact` and
 * `$mismatch` differ in exactly one byte of `before.checksum` and nothing
 * else. Without that control an assertion that "restorable is false" passes
 * for any reason at all — a missing status, a bad id, a normaliser returning
 * null — and would certify the opposite of what it claims.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/** Build a v1 single-target record whose before.value hashes to $checksum. */
function rollback_integrity_v1_record( string $snapshot_id, string $value, string $checksum ): array {
	return array(
		'schema_version' => 1,
		'snapshot_id'    => $snapshot_id,
		'status'         => 'write_applied',
		'created_at'     => gmdate( 'c' ),
		'expires_at'     => gmdate( 'c', time() + 604800 ),
		'created_by'     => array( 'user_id' => 1, 'login' => 'dax' ),
		'tool'           => 'diviops_page_update_content',
		'target'         => array( 'kind' => 'post', 'id' => 900, 'post_type' => 'page' ),
		'before'         => array(
			'checksum'     => $checksum,
			'byte_length'  => strlen( $value ),
			'value'        => $value,
			'side_effects' => array( 'post_meta' => array() ),
		),
		'after'          => array(
			'checksum'     => 'sha256:' . hash( 'sha256', 'new content' ),
			'byte_length'  => 11,
			'side_effects' => array( 'post_meta' => array() ),
		),
		'restore'        => array( 'restorable' => true, 'restored_at' => null, 'restored_by' => null ),
		'cleanup'        => array( 'deleted_at' => null, 'deleted_by' => null ),
	);
}

$value      = '<!-- wp:divi/placeholder {"attrs":{}} /-->';
$true_sum   = 'sha256:' . hash( 'sha256', $value );
// One byte different, still a well-formed sha256 string: a format check passes
// on it, so only a real comparison against the value can tell them apart.
$wrong_sum  = 'sha256:' . substr( hash( 'sha256', $value ), 0, 63 ) . ( '0' === substr( hash( 'sha256', $value ), 63, 1 ) ? '1' : '0' );

assert_same(
	64,
	strlen( substr( $wrong_sum, 7 ) ),
	'#460: the control checksum is still a well-formed sha256, so the assertions below cannot be passing on a format check'
);
assert_true(
	$true_sum !== $wrong_sum,
	'#460: the two fixtures really do differ, so the paired comparison measures something'
);

// ── 1. The predicate ──────────────────────────────────────────────────────
assert_true(
	(bool) diviops_call( 'rollback_snapshot_before_value_intact', array( array( 'value' => $value, 'checksum' => $true_sum ) ) ),
	'#460: a value that hashes to its stored checksum is intact'
);
assert_same(
	false,
	diviops_call( 'rollback_snapshot_before_value_intact', array( array( 'value' => $value, 'checksum' => $wrong_sum ) ) ),
	'#460: a value that does not hash to its stored checksum is not intact'
);
assert_same(
	false,
	diviops_call( 'rollback_snapshot_before_value_intact', array( array( 'checksum' => $true_sum ) ) ),
	'#460: a record with no value at all is not intact — there is nothing to verify'
);
assert_same(
	false,
	diviops_call( 'rollback_snapshot_before_value_intact', array( array( 'value' => $value ) ) ),
	'#460: a value with no checksum is not intact — absent evidence is not evidence of integrity'
);
assert_same(
	false,
	diviops_call( 'rollback_snapshot_before_value_intact', array( array( 'value' => $value, 'checksum' => '' ) ) ),
	'#460: an empty checksum string is not intact'
);

// ── 2. The v1 summary reports it ──────────────────────────────────────────
$intact_id   = 'snap_20260923000000_1111111111111111';
$mismatch_id = 'snap_20260923000000_2222222222222222';

$intact_summary = diviops_call(
	'rollback_snapshot_normalize_record',
	array(
		rollback_integrity_v1_record( $intact_id, $value, $true_sum ),
		'diviops_rollback_snapshot_' . $intact_id,
		null,
	)
);
$mismatch_summary = diviops_call(
	'rollback_snapshot_normalize_record',
	array(
		rollback_integrity_v1_record( $mismatch_id, $value, $wrong_sum ),
		'diviops_rollback_snapshot_' . $mismatch_id,
		null,
	)
);

assert_true(
	is_array( $intact_summary ) && is_array( $mismatch_summary ),
	'#460: both records normalise, so a false restorable below is about integrity and not about a rejected record'
);
assert_same(
	true,
	$intact_summary['restore']['restorable'],
	'#460: a verifiable payload is still restorable — the check does not simply refuse everything'
);
assert_same(
	false,
	$mismatch_summary['restore']['restorable'],
	'#460: a payload whose stored checksum disagrees with its stored value is NOT restorable'
);
assert_same(
	'verified',
	$intact_summary['before']['integrity'],
	'#460: the summary names the integrity state rather than leaving the caller to infer it'
);
assert_same(
	'mismatch',
	$mismatch_summary['before']['integrity'],
	'#460: a corrupt payload is distinguishable from an absent one and from an already-restored one'
);
// The reason a caller can act on: these two records are identical apart from
// the checksum, so `status` must NOT be what changed.
assert_same(
	$intact_summary['status'],
	$mismatch_summary['status'],
	'#460: the status is unchanged — integrity is reported as its own fact, not smuggled into status'
);

$no_value = rollback_integrity_v1_record( $intact_id, $value, $true_sum );
unset( $no_value['before']['value'] );
$no_value_summary = diviops_call(
	'rollback_snapshot_normalize_record',
	array( $no_value, 'diviops_rollback_snapshot_' . $intact_id, null )
);
assert_same(
	'absent',
	$no_value_summary['before']['integrity'],
	'#460: a record holding no payload reports absent, which is a different problem from a corrupt one'
);
assert_same(
	false,
	$no_value_summary['restore']['restorable'],
	'#460: a record holding no payload is not restorable'
);

// ── 3. The v2 run chunk reports it the same way ───────────────────────────
//
// Two entries in ONE record, differing only in before.checksum. A per-record
// check would pass this file while leaving every sibling entry unexamined.
$run_id = 'run_20260923000000_3333333333333333';
$run_record = array(
	'schema_version' => 2,
	'snapshot_id'    => $run_id . '_c1',
	'run_id'         => $run_id,
	'chunk'          => 1,
	'status'         => 'write_applied',
	'created_at'     => gmdate( 'c' ),
	'expires_at'     => gmdate( 'c', time() + 604800 ),
	'tool'           => 'diviops_preset_reassign',
	'restore'        => array( 'restored_page_ids' => array() ),
	'targets'        => array(
		array(
			'id'        => 901,
			'kind'      => 'post',
			'post_type' => 'page',
			'status'    => 'write_applied',
			'before'    => array( 'checksum' => $true_sum, 'byte_length' => strlen( $value ), 'value' => $value ),
			'after'     => array( 'checksum' => 'sha256:' . hash( 'sha256', 'after-901' ), 'byte_length' => 9 ),
		),
		array(
			'id'        => 902,
			'kind'      => 'post',
			'post_type' => 'page',
			'status'    => 'write_applied',
			'before'    => array( 'checksum' => $wrong_sum, 'byte_length' => strlen( $value ), 'value' => $value ),
			'after'     => array( 'checksum' => 'sha256:' . hash( 'sha256', 'after-902' ), 'byte_length' => 9 ),
		),
	),
);

$run_summary = diviops_call( 'rollback_snapshot_run_summary', array( $run_record, 'diviops_rollback_snapshot_' . $run_id . '_c1' ) );
assert_true(
	is_array( $run_summary ) && isset( $run_summary['targets'] ) && 2 === count( $run_summary['targets'] ),
	'#460: the run chunk normalises to both entries, so the per-entry assertions below inspect two things and not zero'
);

$by_id = array();
foreach ( $run_summary['targets'] as $entry ) {
	$by_id[ (int) $entry['id'] ] = $entry;
}

assert_same(
	true,
	$by_id[901]['restorable'],
	'#460: the verifiable entry of the chunk is still restorable'
);
assert_same(
	false,
	$by_id[902]['restorable'],
	'#460: the corrupt entry of the SAME chunk is not restorable — the check is per entry, not per record'
);
assert_same(
	'verified',
	$by_id[901]['before']['integrity'],
	'#460: the run chunk reports per-entry integrity in the same vocabulary as the v1 summary'
);
assert_same(
	'mismatch',
	$by_id[902]['before']['integrity'],
	'#460: the corrupt entry names its own problem'
);
assert_same(
	false,
	$by_id[902]['restored'],
	'#460: the corrupt entry is refused for integrity, NOT misreported as already restored'
);

// ── 4. The Pro seam names the real reason ─────────────────────────────────
//
// rollback_snapshot_managed_inventory() is the PHP seam DiviOps Agent Pro reads.
// It already refuses a row whose restorable is false, but under the reason
// `status_not_restorable` — which is wrong here: the status is write_applied.
// A recovery tool that reports the wrong reason sends an operator to fix the
// wrong thing.
$inventory_option = 'diviops_rollback_snapshot_' . $mismatch_id;
add_option( $inventory_option, rollback_integrity_v1_record( $mismatch_id, $value, $wrong_sum ), '', 'no' );

$inventory = diviops_call( 'rollback_snapshot_managed_inventory' );
$row       = null;
foreach ( $inventory['records'] as $candidate ) {
	if ( $mismatch_id === ( $candidate['snapshot_id'] ?? '' ) ) {
		$row = $candidate;
	}
}
assert_true(
	is_array( $row ),
	'#460: the corrupt record reaches the Pro inventory at all, so the reason assertions below measure something'
);
assert_true(
	in_array( 'payload_integrity_failed', $row['viability_reasons'], true ),
	'#460: the inventory names payload_integrity_failed rather than blaming the status'
);
assert_same(
	false,
	$row['viable'],
	'#460: a corrupt payload is not viable for managed recovery'
);
assert_true(
	! in_array( 'payload_missing', $row['viability_reasons'], true ),
	'#460: a corrupt payload is not reported as a missing one — the row does hold bytes, they just cannot be trusted'
);

delete_option( $inventory_option );
