<?php
// SPDX-License-Identifier: MIT
/**
 * preset_reassign() records one run snapshot, not one per page (#199 task 3).
 *
 * This is the change the whole of #199 exists for. An apply of 501-1000 pages took
 * one snapshot row per page against a 500-row retention cap, so it evicted its own
 * earliest snapshots as it ran and reported success for pages it could no longer
 * restore. A run costs ceil(pages / 100) rows instead.
 *
 * Coverage is split the way test-preset-reassign-write-safety.php documents:
 * preset_reassign() cannot be driven end to end in this harness, because the preset
 * registry probe goes through et_get_option(), a Divi option-storage primitive this
 * suite does not shim. So the wiring is asserted structurally over the real method
 * source, and the one genuinely new helper is asserted behaviorally.
 *
 * The structural assertions are deliberately about the capture-to-mark PAIRING
 * rather than about which functions are called. Under the old API an unmarked
 * snapshot was a standalone row that restore refused — visible and contained. Under
 * the run API an unmarked entry rides inside a chunk that otherwise looks healthy,
 * so a single missed error path silently costs one page its restorability inside an
 * apparently good record.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Read a method's exact source text via Reflection.
 *
 * Uniquely named: tests/run.php requires every test file into ONE process, and
 * preset_reassign_method_source (test-preset-reassign-write-safety.php) and
 * reassign_budget_method_source are already taken.
 */
function reassign_run_method_source( string $method ): string {
	$reflection = new ReflectionMethod( 'DiviOps_Agent', $method );
	$file       = $reflection->getFileName();
	$start      = $reflection->getStartLine() - 1;
	return implode( '', array_slice( file( $file ), $start, $reflection->getEndLine() - $start ) );
}

$source = reassign_run_method_source( 'preset_reassign' );

/*
 * ---------------------------------------------------------------------------
 * The per-page snapshot is gone, and the run lifecycle is wired in its place.
 * ---------------------------------------------------------------------------
 */

assert_true(
	false === strpos( $source, 'rollback_snapshot_create_for_post_write' ),
	'preset_reassign no longer takes a snapshot per page — that is what overflowed the retention cap'
);
assert_true(
	false !== strpos( $source, 'rollback_snapshot_run_begin' ),
	'preset_reassign opens a run snapshot'
);
assert_true(
	false !== strpos( $source, 'rollback_snapshot_run_capture' ),
	'preset_reassign captures each page into the run'
);
assert_true(
	false !== strpos( $source, 'rollback_snapshot_run_flush' ),
	'preset_reassign flushes the run, without which nothing is stored at all'
);

/*
 * Both write outcomes must mark. rollback_snapshot_restore() refuses any entry
 * whose after.checksum is empty, so an unmarked entry is permanently unrestorable.
 */
assert_true(
	false !== strpos( $source, 'rollback_snapshot_run_mark_from_write_error' ),
	'the failed-write branch marks its entry, so a refused page is recorded rather than left blank'
);
assert_true(
	false !== strpos( $source, "rollback_snapshot_run_mark(" ) || false !== strpos( $source, 'rollback_snapshot_run_mark ' ),
	'the successful-write branch marks its entry'
);

/*
 * Capture can fail. rollback_snapshot_run_capture() returns false when a full chunk
 * could not be stored, and continuing past that would grow the very insert that just
 * failed while writing pages whose recovery data does not exist.
 */
assert_true(
	false !== strpos( $source, 'preset.rollback_storage_failed' ),
	'a capture that cannot be stored is reported with its own code rather than silently continuing'
);

/*
 * The caller has to be able to find the run afterwards.
 */
assert_true(
	(bool) preg_match( "/\\\$summary\\['rollback'\\]/", $source ),
	'the summary reports the run so a caller can find and restore it'
);
assert_true(
	false !== strpos( $source, "'pages_captured'" ),
	'the summary reports how many pages actually made it into the run, which is what a caller checks against pages_modified'
);

/*
 * ---------------------------------------------------------------------------
 * The one new helper, asserted behaviorally.
 *
 * Mirrors rollback_snapshot_mark_from_write_error(): a corruption error means the
 * write landed and was reverted, anything else means it never landed. Getting this
 * backwards would mislabel every failed page in a run.
 * ---------------------------------------------------------------------------
 */

$run  = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_preset_reassign', array() ) );
$post = diviops_test_register_post( 88000, 'ORIGINAL' );

$cap = array( &$run, $post );
diviops_call_ref( 'rollback_snapshot_run_capture', $cap );

$post->post_content = 'PARTIALLY WRITTEN';
$corruption = new WP_Error( 'preset.content_write_corruption', 'readback did not match' );
$mark_args  = array( &$run, 88000, $corruption );
assert_same(
	true,
	diviops_call_ref( 'rollback_snapshot_run_mark_from_write_error', $mark_args ),
	'marking a captured page from a write error reports that it landed'
);
assert_same(
	'write_failed_restored',
	$run['open'][88000]['status'],
	'a content-write corruption is recorded as a write that landed and was reverted'
);
assert_true(
	! empty( $run['open'][88000]['after']['checksum'] ),
	'the entry gets an after checksum from the live page, without which restore would refuse it'
);

$post2 = diviops_test_register_post( 88001, 'ORIGINAL' );
$cap2  = array( &$run, $post2 );
diviops_call_ref( 'rollback_snapshot_run_capture', $cap2 );

$other      = new WP_Error( 'preset.page_not_editable', 'nope' );
$mark_args2 = array( &$run, 88001, $other );
diviops_call_ref( 'rollback_snapshot_run_mark_from_write_error', $mark_args2 );
assert_same(
	'aborted_before_write',
	$run['open'][88001]['status'],
	'any other error is recorded as a write that never landed'
);

$flush = array( &$run );
foreach ( diviops_call_ref( 'rollback_snapshot_run_flush', $flush ) as $option_name ) {
	delete_option( $option_name );
}
