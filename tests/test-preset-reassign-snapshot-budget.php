<?php
// SPDX-License-Identifier: MIT
/**
 * preset_reassign() must not evict its own rollback snapshots (#199).
 *
 * #194 capped the rollback snapshot store at 500 rows, evicting oldest-first
 * inside every guarded write. #196 then made a snapshot mandatory for every
 * page preset_reassign() applies to, and REASSIGN_MAX_PAGES is 1000. Each
 * change is right on its own terms; together they mean a long apply deletes
 * its own earliest snapshots as it runs, and reports success for pages that
 * are no longer recoverable.
 *
 * The cap is shared by all ten write tools, not owned by preset_reassign(),
 * so the real trigger is not "a run over 500 pages" — it is
 * `stored + planned > limit`. A 300-page apply against a store already
 * holding 400 snapshots evicts 200 of its own.
 *
 * Resolution (owner decision, 2026-08-18): fail closed. Refuse an apply whose
 * page count exceeds the remaining budget, and disclose the budget in the
 * summary so a dry run shows it before anyone commits. That keeps #194's
 * bound exactly — the alternative, exempting an in-progress run from
 * eviction, would let the store exceed the cap by up to the run size, which
 * is the unbounded options table #194 exists to prevent.
 *
 * Coverage split follows the boundary test-preset-reassign-write-safety.php
 * documents: preset_reassign() cannot be driven end to end in this harness,
 * because the budget check sits after the preset registry probe and that
 * probe goes through et_get_option(), a Divi option-storage primitive this
 * harness does not shim. So the budget arithmetic is tested behaviorally
 * against the real $wpdb fake, and the wiring into the handler is tested
 * structurally via Reflection over the real method source.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Read a method's exact source text via Reflection.
 *
 * Named for this file specifically: tests/run.php requires every test file
 * into ONE process, so an unguarded shared name collides with the
 * identically-purposed helpers in test-preset-reassign-write-safety.php and
 * test-parse-blocks-for-write-coverage.php.
 */
function reassign_budget_method_source( string $method ): string {
	$reflection = new ReflectionMethod( 'DiviOps_Agent', $method );
	$file       = $reflection->getFileName();
	$start      = $reflection->getStartLine() - 1;
	$length     = $reflection->getEndLine() - $start;
	$lines      = file( $file );
	return implode( '', array_slice( $lines, $start, $length ) );
}

/**
 * Store a snapshot row the way rollback_snapshot_create_for_post_write() does.
 *
 * Autoload 'no' and the diviops_rollback_snapshot_ prefix are what the
 * retention scan's LIKE query matches on, so seeding any other way would not
 * be counted by the code under test.
 */
function reassign_budget_seed_snapshot( string $snapshot_id ): string {
	$option_name = 'diviops_rollback_snapshot_' . $snapshot_id;
	add_option(
		$option_name,
		array(
			'schema_version' => 1,
			'snapshot_id'    => $snapshot_id,
			'status'         => 'write_applied',
			'created_at'     => gmdate( 'c' ),
			'target'         => array( 'kind' => 'post', 'id' => 900, 'post_type' => 'page' ),
		),
		'',
		'no'
	);
	return $option_name;
}

/*
 * ---------------------------------------------------------------------------
 * The budget is measured against the real store, so every assertion below is
 * a delta from whatever this shared process already holds. An absolute
 * expectation would depend on which test files ran first.
 * ---------------------------------------------------------------------------
 */

$limit    = (int) diviops_call( 'rollback_snapshot_retention_limit' );
$baseline = (int) diviops_call( 'rollback_snapshot_budget_remaining' );

assert_true(
	$baseline >= 0 && $baseline <= $limit,
	'the remaining snapshot budget starts within [0, retention limit]'
);

$seeded = array();
for ( $i = 0; $i < 3; $i++ ) {
	$seeded[] = reassign_budget_seed_snapshot( 'budget-probe-' . $i . '-' . uniqid() );
}

assert_same(
	$baseline - 3,
	(int) diviops_call( 'rollback_snapshot_budget_remaining' ),
	'storing three snapshots reduces the remaining budget by exactly three'
);

foreach ( $seeded as $option_name ) {
	delete_option( $option_name );
}

assert_same(
	$baseline,
	(int) diviops_call( 'rollback_snapshot_budget_remaining' ),
	'deleting those snapshots restores the budget, leaving the shared store as found'
);

/*
 * ---------------------------------------------------------------------------
 * The budget floors at zero rather than going negative, because a negative
 * budget compared against a page count would let an oversized run through.
 * Seeded past the cap and then cleaned up, so later test files see the store
 * as they would have found it.
 * ---------------------------------------------------------------------------
 */

$overflow = array();
$needed   = $baseline + 5;
for ( $i = 0; $i < $needed; $i++ ) {
	$overflow[] = reassign_budget_seed_snapshot( 'budget-overflow-' . $i . '-' . uniqid() );
}

assert_same(
	0,
	(int) diviops_call( 'rollback_snapshot_budget_remaining' ),
	'a store at or past the retention cap reports a remaining budget of zero, never negative'
);

foreach ( $overflow as $option_name ) {
	delete_option( $option_name );
}

assert_same(
	$baseline,
	(int) diviops_call( 'rollback_snapshot_budget_remaining' ),
	'the overflow rows are cleaned up, leaving the shared store as found'
);

/*
 * ---------------------------------------------------------------------------
 * Wiring. The arithmetic above is worthless if preset_reassign() never asks.
 * ---------------------------------------------------------------------------
 */

$reassign_source = reassign_budget_method_source( 'preset_reassign' );

assert_true(
	false !== strpos( $reassign_source, 'rollback_snapshot_budget_remaining' ),
	'preset_reassign() consults the remaining snapshot budget'
);

assert_true(
	false !== strpos( $reassign_source, 'preset.snapshot_budget_exceeded' ),
	'preset_reassign() has a distinct refusal code for an apply that would outrun its snapshot budget'
);

assert_true(
	(bool) preg_match(
		'/\'apply\'\s*===\s*\$mode[^;]*snapshot_budget|snapshot_budget[^;]*\'apply\'\s*===\s*\$mode/s',
		$reassign_source
	),
	'the budget refusal is gated on apply mode, so a dry run still reports rather than refusing'
);

/*
 * Sliced to the $summary literal specifically. Asserting on the whole method
 * source would pass on the refusal envelope's own data bag alone, which every
 * caller who got refused already has — the point of this assertion is that a
 * run that is NOT refused still reports what its budget was.
 */
$summary_literal = '';
if ( preg_match( '/\$summary\s*=\s*\[(.*?)\n\t\t\];/s', $reassign_source, $summary_match ) ) {
	$summary_literal = $summary_match[1];
}

assert_true(
	'' !== $summary_literal,
	'the $summary literal is locatable in preset_reassign() source, so the next assertion measures something'
);

assert_true(
	false !== strpos( $summary_literal, "'snapshot_budget'" ),
	'the reassign summary discloses the snapshot budget, so a dry run shows it before anyone applies'
);
