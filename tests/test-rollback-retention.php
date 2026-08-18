<?php
// SPDX-License-Identifier: MIT
/**
 * Rollback snapshot retention and inventory windowing (#187).
 *
 * Two defects, one root cause. Snapshots are one wp_options row each, holding the
 * target's full prior post_content, and nothing ever removed them: the 7-day
 * `expires_at` they carry is read only by an admin badge, and no WP-Cron exists
 * anywhere in the plugin. The store therefore grew without bound.
 *
 * That mattered because rollback_snapshot_managed_inventory() — the PHP seam Pro's
 * managed recovery reads — is capped at 1000 records, and it selected that window
 * oldest-first. Crossing 1000 meant the snapshots dropped from the window were the
 * *most recent* ones, so a fail-closed consumer lost managed recovery entirely at
 * the moment it became most necessary.
 *
 * These tests pin both halves: a count cap enforced at snapshot creation, and the
 * inventory window selecting newest-first. Note that the ordering assertion is only
 * meaningful because tests/wp-shim.php's $wpdb fake genuinely parses and honors the
 * ORDER BY clause it is handed — a fake that returned a canned list would pass
 * whether the production query said ASC or DESC.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Remove only snapshot rows, leaving the shared option store otherwise intact.
 *
 * The runner loads every test file into one process, so wiping
 * $GLOBALS['diviops_test_options'] wholesale would strip keys such as
 * 'active_plugins' that other test files depend on.
 */
function diviops_test_reset_snapshots(): void {
	foreach ( array_keys( $GLOBALS['diviops_test_options'] ) as $name ) {
		if ( 0 === strpos( (string) $name, 'diviops_rollback_snapshot_' ) ) {
			unset( $GLOBALS['diviops_test_options'][ $name ] );
			unset( $GLOBALS['diviops_test_option_rows'][ $name ] );
		}
	}
}

/**
 * Count the snapshot rows currently in the store.
 */
function diviops_test_snapshot_count(): int {
	$count = 0;
	foreach ( array_keys( $GLOBALS['diviops_test_options'] ) as $name ) {
		if ( 0 === strpos( (string) $name, 'diviops_rollback_snapshot_' ) ) {
			++$count;
		}
	}
	return $count;
}

/**
 * Build a fully-formed, normalizable snapshot record.
 *
 * It must survive rollback_snapshot_normalize_record() — matching snapshot_id and
 * a positive target id — or the inventory silently classifies it as malformed and
 * the ordering assertions below would be measuring the wrong thing.
 *
 * @param string $snapshot_id Snapshot identifier, matching the option-name suffix.
 * @param int    $created_ts  Creation timestamp.
 * @return array<string, mixed>
 */
function diviops_test_snapshot_record( string $snapshot_id, int $created_ts ): array {
	return array(
		'schema_version' => 1,
		'snapshot_id'    => $snapshot_id,
		'status'         => 'write_applied',
		'created_at'     => gmdate( 'c', $created_ts ),
		'expires_at'     => gmdate( 'c', $created_ts + ( 7 * 86400 ) ),
		'created_by'     => array( 'user_id' => 1, 'login' => 'dax' ),
		'tool'           => 'page_update_content',
		'operation'      => array( 'dry_run' => false ),
		'target'         => array( 'kind' => 'post', 'id' => 900, 'post_type' => 'page' ),
		'before'         => array(
			'checksum'     => 'sha256:' . str_repeat( 'a', 64 ),
			'byte_length'  => 3,
			'value'        => 'old',
			'side_effects' => array( 'post_meta' => array() ),
		),
		'after'          => array(
			'checksum'     => 'sha256:' . str_repeat( 'b', 64 ),
			'byte_length'  => 3,
			'side_effects' => array( 'post_meta' => array() ),
		),
		'restore'        => array( 'restorable' => true, 'restored_at' => null, 'restored_by' => null ),
		'cleanup'        => array( 'deleted_at' => null, 'deleted_by' => null ),
	);
}

/*
 * ---------------------------------------------------------------------------
 * The $wpdb fake must honor ORDER BY, or every assertion below is theatre.
 * ---------------------------------------------------------------------------
 */

diviops_test_reset_snapshots();
add_option( 'diviops_rollback_snapshot_snap_20260101000000_aaaaaaaaaaaaaaaa', array( 'first' ), '', 'no' );
add_option( 'diviops_rollback_snapshot_snap_20260102000000_bbbbbbbbbbbbbbbb', array( 'second' ), '', 'no' );

$ascending = $GLOBALS['wpdb']->get_results(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
		$GLOBALS['wpdb']->esc_like( 'diviops_rollback_snapshot_' ) . '%',
		10
	)
);
$descending = $GLOBALS['wpdb']->get_results(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d",
		$GLOBALS['wpdb']->esc_like( 'diviops_rollback_snapshot_' ) . '%',
		10
	)
);

assert_same( 2, count( $ascending ), 'the $wpdb fake matches both snapshot rows via LIKE' );
assert_true(
	false !== strpos( $ascending[0]->option_name, 'snap_20260101000000' ),
	'ORDER BY option_id ASC puts the older row first — the fake honors the clause'
);
assert_true(
	false !== strpos( $descending[0]->option_name, 'snap_20260102000000' ),
	'ORDER BY option_id DESC puts the newer row first — the fake is not returning a canned order'
);

// esc_like() escapes the prefix's underscores; an escaped `_` must match a literal
// underscore only, never any character. Without this the LIKE would over-match.
add_option( 'diviops_rollback_snapshotXsnap_20260103000000_cccccccccccccccc', array( 'decoy' ), '', 'no' );
$escaped = $GLOBALS['wpdb']->get_results(
	$GLOBALS['wpdb']->prepare(
		"SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d",
		$GLOBALS['wpdb']->esc_like( 'diviops_rollback_snapshot_' ) . '%',
		10
	)
);
assert_same( 2, count( $escaped ), 'esc_like() underscores match literally, so the X-separated decoy row is excluded' );
unset( $GLOBALS['diviops_test_options']['diviops_rollback_snapshotXsnap_20260103000000_cccccccccccccccc'] );

/*
 * ---------------------------------------------------------------------------
 * Q3: the managed inventory window must keep the NEWEST snapshots.
 * ---------------------------------------------------------------------------
 */

diviops_test_reset_snapshots();

$oldest_id = '';
$newest_id = '';
$base_ts   = strtotime( '2026-01-01 00:00:00 UTC' );
for ( $index = 0; $index < 1001; $index++ ) {
	$created_ts  = $base_ts + ( $index * 60 );
	$snapshot_id = 'snap_' . gmdate( 'YmdHis', $created_ts ) . '_' . substr( hash( 'sha256', (string) $index ), 0, 16 );
	if ( 0 === $index ) {
		$oldest_id = $snapshot_id;
	}
	$newest_id = $snapshot_id;
	add_option(
		'diviops_rollback_snapshot_' . $snapshot_id,
		diviops_test_snapshot_record( $snapshot_id, $created_ts ),
		'',
		'no'
	);
}
assert_same( 1001, diviops_test_snapshot_count(), 'the fixture seeded one row beyond the 1000-record inventory ceiling' );

$inventory = DiviOps_Agent::rollback_snapshot_managed_inventory();

assert_true( is_array( $inventory ), 'the managed inventory returns evidence rather than a refusal' );
assert_true( (bool) $inventory['truncated'], 'crossing the ceiling reports truncated' );
assert_same( false, (bool) $inventory['complete'], 'a truncated inventory is not complete' );
assert_same( 1000, $inventory['count'], 'the inventory returns exactly the ceiling' );

$returned_ids = array_column( $inventory['records'], 'snapshot_id' );
assert_same( 0, (int) count( array_filter( $inventory['records'], static function ( $record ) {
	return ! empty( $record['malformed'] );
} ) ), 'every seeded record normalized — the ordering assertions measure real records' );

assert_true(
	in_array( $newest_id, $returned_ids, true ),
	'the NEWEST snapshot survives truncation — it is the one a user most needs to recover'
);
assert_same(
	false,
	in_array( $oldest_id, $returned_ids, true ),
	'the OLDEST snapshot is the one dropped when the window truncates'
);

/*
 * ---------------------------------------------------------------------------
 * Q1/Q2: a count cap, enforced when a snapshot is created.
 * ---------------------------------------------------------------------------
 */

diviops_test_reset_snapshots();

$limit = (int) diviops_call( 'rollback_snapshot_retention_limit' );
assert_true( $limit > 0, 'the retention limit is a positive count' );
assert_true( $limit < 1000, 'the retention limit sits below the 1000-record inventory ceiling, so the ceiling stays unreachable' );

$post             = new stdClass();
$post->ID         = 900;
$post->post_type  = 'page';
$post->post_content = '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->';

$first_id = '';
$results  = array();
for ( $index = 0; $index < $limit + 3; $index++ ) {
	$record = diviops_call(
		'rollback_snapshot_create_for_post_write',
		array( $post, 'page_update_content', array( 'dry_run' => false ) )
	);
	$results[] = $record;
	if ( 0 === $index ) {
		$first_id = is_array( $record ) ? (string) $record['snapshot_id'] : '';
	}
}

assert_same(
	$limit,
	diviops_test_snapshot_count(),
	'the store never exceeds the retention limit, however many snapshots are created'
);
assert_true(
	'' !== $first_id && null === get_option( 'diviops_rollback_snapshot_' . $first_id, null ),
	'the oldest snapshot is the one evicted once the cap is reached'
);

$last = $results[ count( $results ) - 1 ];
assert_true( is_array( $last ), 'a create that triggers eviction still returns a record, not a WP_Error' );
assert_true(
	null !== get_option( 'diviops_rollback_snapshot_' . $last['snapshot_id'], null ),
	'the snapshot just created is never the one evicted'
);

/*
 * Q2: a restored snapshot is treated exactly like one that was never restored.
 * The cap bounds row count, a goal indifferent to restore history.
 */

diviops_test_reset_snapshots();

$restored_ts = strtotime( '2026-02-01 00:00:00 UTC' );
$restored_id = 'snap_' . gmdate( 'YmdHis', $restored_ts ) . '_' . str_repeat( 'd', 16 );
$restored    = diviops_test_snapshot_record( $restored_id, $restored_ts );
$restored['restore']['restored_at'] = gmdate( 'c', $restored_ts + 60 );
$restored['restore']['restored_by'] = array( 'user_id' => 1, 'login' => 'dax' );
add_option( 'diviops_rollback_snapshot_' . $restored_id, $restored, '', 'no' );

for ( $index = 0; $index < $limit; $index++ ) {
	diviops_call(
		'rollback_snapshot_create_for_post_write',
		array( $post, 'page_update_content', array( 'dry_run' => false ) )
	);
}

assert_same( $limit, diviops_test_snapshot_count(), 'the cap still holds with a restored snapshot in the store' );
assert_same(
	null,
	get_option( 'diviops_rollback_snapshot_' . $restored_id, null ),
	'an already-restored snapshot is evicted on age like any other — restore history buys no exemption'
);

/*
 * Eviction is best-effort and must never turn a good write into a failed one.
 * A store already far past the cap is trimmed toward it rather than triggering
 * an unbounded delete storm inside a single guarded write.
 */

diviops_test_reset_snapshots();

$backlog_base = strtotime( '2026-03-01 00:00:00 UTC' );
for ( $index = 0; $index < $limit + 200; $index++ ) {
	$created_ts  = $backlog_base + ( $index * 60 );
	$snapshot_id = 'snap_' . gmdate( 'YmdHis', $created_ts ) . '_' . substr( hash( 'sha256', 'backlog' . $index ), 0, 16 );
	add_option(
		'diviops_rollback_snapshot_' . $snapshot_id,
		diviops_test_snapshot_record( $snapshot_id, $created_ts ),
		'',
		'no'
	);
}
$before_backlog_write = diviops_test_snapshot_count();

$backlog_record = diviops_call(
	'rollback_snapshot_create_for_post_write',
	array( $post, 'page_update_content', array( 'dry_run' => false ) )
);

assert_true( is_array( $backlog_record ), 'a write against a backlogged store still succeeds' );
assert_true(
	diviops_test_snapshot_count() < $before_backlog_write,
	'a backlogged store is trimmed toward the cap rather than left to grow'
);
assert_true(
	diviops_test_snapshot_count() >= $limit,
	'trimming converges on the cap rather than overshooting below it'
);

diviops_test_reset_snapshots();
