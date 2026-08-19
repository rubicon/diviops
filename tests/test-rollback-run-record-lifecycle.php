<?php
// SPDX-License-Identifier: MIT
/**
 * Run-scoped snapshot capture, marking, and chunk flushing (#199).
 *
 * Two properties make a run record restorable at all, and both are asserted here
 * rather than trusted from the call order:
 *
 *   1. Every captured page lands in exactly one chunk — none lost, none
 *      duplicated. A page silently dropped between capture and flush is a page
 *      with no restore path, which is the bug this whole change exists to fix.
 *   2. Every entry carries its own after.checksum. rollback_snapshot_restore()
 *      refuses outright when after.checksum is empty, so an entry captured but
 *      never marked is permanently unrestorable — the footgun the #38
 *      bulk-operations spec found the hard way.
 *
 * The size assertion at the bottom uses realistic page bytes on purpose. The
 * chunk bound exists to avoid a get_option() memory cliff and a
 * max_allowed_packet failure, and a size test that passed on token fixtures
 * would be measuring nothing.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$chunk = (int) diviops_call( 'rollback_snapshot_run_chunk_size' );

$run = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_preset_reassign', array( 'tool_operation' => 'preset.reassign' ) ) );
assert_true( is_array( $run ) && ! empty( $run['run_id'] ), 'run_begin returns a run handle carrying a run id' );

$ids = array();
for ( $i = 0; $i < $chunk + 5; $i++ ) {
	$post_id = 61000 + $i;
	$ids[]   = $post_id;
	$post    = diviops_test_register_post( $post_id, 'before content ' . $i );

	$capture_args = array( &$run, $post );
	diviops_call_ref( 'rollback_snapshot_run_capture', $capture_args );

	$mark_args = array( &$run, $post_id, 'write_applied', 'after content ' . $i );
	diviops_call_ref( 'rollback_snapshot_run_mark', $mark_args );
}

$flush_args = array( &$run );
$stored     = diviops_call_ref( 'rollback_snapshot_run_flush', $flush_args );

assert_same( 2, count( $stored ), 'a run of chunk+5 pages flushes to exactly two records, not one per page' );

$seen = array();
foreach ( $stored as $option_name ) {
	$record = get_option( $option_name, null );
	assert_true( is_array( $record ), 'each flushed chunk is a stored array record' );
	assert_true(
		(bool) diviops_call( 'rollback_snapshot_is_run_record', array( $record ) ),
		'each flushed chunk is recognized as a run record'
	);
	assert_true(
		count( $record['targets'] ) <= $chunk,
		'no chunk exceeds the chunk bound, which is what keeps a record inside the memory and packet ceilings'
	);
	foreach ( $record['targets'] as $entry ) {
		$seen[] = (int) $entry['id'];
		assert_true(
			! empty( $entry['after']['checksum'] ),
			'every entry carries an after checksum, without which restore refuses outright'
		);
		assert_true(
			isset( $entry['before']['value'] ),
			'every entry carries its prior content, which is the thing a restore writes back'
		);
	}
}

sort( $seen );
assert_same( $ids, $seen, 'every captured page appears in exactly one chunk — none lost, none duplicated' );

foreach ( $stored as $option_name ) {
	delete_option( $option_name );
}

/*
 * ---------------------------------------------------------------------------
 * Size, at realistic page bytes. If this fails, lower the chunk bound in
 * rollback_snapshot_run_chunk_size() — do not relax the assertion.
 * ---------------------------------------------------------------------------
 */

$big  = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_preset_reassign', array() ) );
$page = str_repeat( 'x', 40 * 1024 );
for ( $i = 0; $i < $chunk; $i++ ) {
	$post_id = 62000 + $i;
	$post    = diviops_test_register_post( $post_id, $page );

	$big_capture = array( &$big, $post );
	diviops_call_ref( 'rollback_snapshot_run_capture', $big_capture );

	$big_mark = array( &$big, $post_id, 'write_applied', $page );
	diviops_call_ref( 'rollback_snapshot_run_mark', $big_mark );
}
$big_flush  = array( &$big );
$big_stored = diviops_call_ref( 'rollback_snapshot_run_flush', $big_flush );

assert_same( 1, count( $big_stored ), 'a full chunk of realistic pages is exactly one record' );

$bytes = strlen( serialize( get_option( $big_stored[0] ) ) );
assert_true(
	$bytes < 8 * 1024 * 1024,
	'a full chunk of 40KB pages serializes under 8MB, well inside a default max_allowed_packet'
);

foreach ( $big_stored as $option_name ) {
	delete_option( $option_name );
}

/*
 * ---------------------------------------------------------------------------
 * Marking has to be detectable, not silent.
 *
 * run_mark() finds its entry in the OPEN chunk. Once a chunk flushes, its pages
 * are gone from open — so a caller that looks even one page ahead before marking
 * would leave one unmarked entry per chunk, with a null after.checksum, which
 * restore refuses. That is the exact defect this change exists to remove,
 * reintroduced at 1/100th the scale. A void return makes it undetectable, so
 * marking reports whether it landed.
 * ---------------------------------------------------------------------------
 */

$signal = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_preset_reassign', array() ) );
$post   = diviops_test_register_post( 63000, 'content' );

$cap_args = array( &$signal, $post );
diviops_call_ref( 'rollback_snapshot_run_capture', $cap_args );

$ok_args = array( &$signal, 63000, 'write_applied', 'after' );
assert_same( true, diviops_call_ref( 'rollback_snapshot_run_mark', $ok_args ), 'marking a captured page reports success' );

$miss_args = array( &$signal, 999999, 'write_applied', 'after' );
assert_same( false, diviops_call_ref( 'rollback_snapshot_run_mark', $miss_args ), 'marking a page that is not in the open chunk reports failure instead of returning silently' );

$signal_flush = array( &$signal );
foreach ( diviops_call_ref( 'rollback_snapshot_run_flush', $signal_flush ) as $option_name ) {
	delete_option( $option_name );
}

/*
 * ---------------------------------------------------------------------------
 * A duplicate capture returns what is actually stored.
 *
 * First-write-wins is correct: a run touching the same page twice must restore
 * to the state from before the run, not to an intermediate. But returning the
 * discarded entry would hand a caller the intermediate state it just rejected.
 * ---------------------------------------------------------------------------
 */

$dup      = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_preset_reassign', array() ) );
$dup_post = diviops_test_register_post( 64000, 'ORIGINAL pre-run content' );

$dup_first = array( &$dup, $dup_post );
diviops_call_ref( 'rollback_snapshot_run_capture', $dup_first );

$dup_post->post_content = 'INTERMEDIATE content after the first write';
$dup_second             = array( &$dup, $dup_post );
$returned               = diviops_call_ref( 'rollback_snapshot_run_capture', $dup_second );

assert_same(
	'ORIGINAL pre-run content',
	$returned['before']['value'],
	'a duplicate capture returns the stored pre-run entry, not the intermediate state it discarded'
);

$dup_mark = array( &$dup, 64000, 'write_applied', 'after' );
diviops_call_ref( 'rollback_snapshot_run_mark', $dup_mark );
$dup_flush = array( &$dup );
foreach ( diviops_call_ref( 'rollback_snapshot_run_flush', $dup_flush ) as $option_name ) {
	delete_option( $option_name );
}

/*
 * ---------------------------------------------------------------------------
 * A storage failure stops the run instead of growing the chunk.
 *
 * The chunk bound exists to keep a record inside the get_option() memory ceiling
 * and max_allowed_packet. If a failed write left the chunk open and captures kept
 * appending, the response to "that insert was too big" would be to make the next
 * insert bigger — self-amplifying, and the likeliest real cause of the failure in
 * the first place.
 *
 * add_option() is forced to fail by pre-creating the chunk's option name, which
 * is exactly what WordPress does when the row already exists.
 * ---------------------------------------------------------------------------
 */

$fail_run = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_preset_reassign', array() ) );
$blocker  = 'diviops_rollback_snapshot_' . $fail_run['run_id'] . '_c1';
add_option( $blocker, array( 'blocking' => true ), '', 'no' );

$capture_results = array();
for ( $i = 0; $i < $chunk + 3; $i++ ) {
	$post_id  = 65000 + $i;
	$fail_post = diviops_test_register_post( $post_id, 'content ' . $i );

	$fail_cap                 = array( &$fail_run, $fail_post );
	$capture_results[ $post_id ] = diviops_call_ref( 'rollback_snapshot_run_capture', $fail_cap );

	$fail_mark = array( &$fail_run, $post_id, 'write_applied', 'after ' . $i );
	diviops_call_ref( 'rollback_snapshot_run_mark', $fail_mark );
}

assert_true(
	in_array( false, $capture_results, true ),
	'a capture that cannot flush a full chunk reports failure rather than appending past the bound'
);
assert_true(
	count( $fail_run['open'] ) <= $chunk,
	'the open chunk never grows past the bound, even when its write keeps failing'
);
assert_true(
	! empty( $fail_run['storage_failed'] ),
	'the run handle records that storage failed, so a caller can tell it apart from an empty run'
);

$fail_flush = array( &$fail_run );
foreach ( diviops_call_ref( 'rollback_snapshot_run_flush', $fail_flush ) as $option_name ) {
	delete_option( $option_name );
}
delete_option( $blocker );

/*
 * ---------------------------------------------------------------------------
 * A chunk's record status reflects its entries.
 *
 * Hardcoding write_applied would report a chunk whose every page aborted before
 * its write as applied, and managed_inventory gates on record status for v1 —
 * so a future v2-aware reader would inherit the same lie.
 * ---------------------------------------------------------------------------
 */

$aborted      = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_preset_reassign', array() ) );
$aborted_post = diviops_test_register_post( 66000, 'content' );

$ab_cap = array( &$aborted, $aborted_post );
diviops_call_ref( 'rollback_snapshot_run_capture', $ab_cap );

$ab_mark = array( &$aborted, 66000, 'aborted_before_write', null );
diviops_call_ref( 'rollback_snapshot_run_mark', $ab_mark );

$ab_flush  = array( &$aborted );
$ab_stored = diviops_call_ref( 'rollback_snapshot_run_flush', $ab_flush );
$ab_record = get_option( $ab_stored[0], null );

assert_same(
	'aborted_before_write',
	$ab_record['status'],
	'a chunk whose every entry aborted before its write is not recorded as applied'
);

foreach ( $ab_stored as $option_name ) {
	delete_option( $option_name );
}
