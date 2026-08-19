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
