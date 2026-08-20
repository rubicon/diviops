<?php
// SPDX-License-Identifier: MIT
/**
 * Rollback snapshots capture canonical bytes, and restore repairs legacy ones (#208).
 *
 * Every write through update_post_content_with_integrity_guard() is read back and
 * compared byte-for-byte, and WordPress canonicalizes block-attribute JSON on save.
 * So handing the guard non-canonical bytes guarantees a mismatch and an automatic
 * revert — of a write that actually succeeded. Two rollback paths were exposed to
 * that: neither re-encodes attributes, but both faithfully write back whatever was
 * stored, and content written by a pre-#206 module_update is non-canonical on disk.
 *
 * The fix canonicalizes at CAPTURE rather than at restore, because a snapshot's
 * contract is "put it back exactly as it was". Cleaning on the way out would make
 * that permanently untrue; cleaning on the way in keeps it true for everything
 * captured from now on. Records captured before this change still hold
 * non-canonical bytes, so restore repairs those on read — a transitional path that
 * shrinks as old snapshots age out, not a standing exception.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * The read-time repair restore applies, exposed for assertions below.
 *
 * Named uniquely: tests/run.php requires every test file into ONE process.
 */
function self_restore_value( string $value ): string {
	return (string) diviops_call( 'rollback_snapshot_canonical_restore_value', array( $value ) );
}

/** Non-canonical block JSON: spaces after ':' and ',', same meaning, different bytes. */
const CANON_DIRTY = '<!-- wp:divi/text {"id": "txtA", "storeInstance": "1"} --><!-- /wp:divi/text -->';

$canonical = diviops_call( 'normalize_divi_full_content_for_write', array( CANON_DIRTY ) );
assert_same( true, $canonical['ok'] ?? null, 'the normalizer handles the fixture, so the assertions below measure something' );
$canon_value = (string) $canonical['content'];
assert_true(
	$canon_value !== CANON_DIRTY,
	'the fixture really is non-canonical — otherwise every assertion here passes trivially'
);

/*
 * ---------------------------------------------------------------------------
 * Capture stores canonical bytes, and the recorded checksum describes them.
 * ---------------------------------------------------------------------------
 */

$post   = diviops_test_register_post( 96000, CANON_DIRTY );
$before = diviops_call( 'rollback_snapshot_before_from_post', array( $post ) );

assert_same(
	$canon_value,
	$before['value'],
	'a snapshot captures canonical bytes, so restoring it cannot trip the integrity guard'
);
assert_same(
	diviops_call( 'rollback_snapshot_checksum', array( $canon_value ) ),
	$before['checksum'],
	'the recorded checksum describes the bytes actually stored — a record that disagreed with itself would be worse than the bug being fixed'
);
assert_same(
	strlen( $canon_value ),
	$before['byte_length'],
	'the recorded byte length describes the bytes actually stored'
);

/*
 * The live page is NOT rewritten by capturing it. Capture is a read; canonicalizing
 * the page itself would be a write nobody asked for.
 */
assert_same(
	CANON_DIRTY,
	(string) get_post( 96000 )->post_content,
	'capturing a snapshot does not modify the page it captures'
);

/*
 * ---------------------------------------------------------------------------
 * A legacy record — captured before this change, holding non-canonical bytes —
 * is repaired on restore rather than written back as-is.
 * ---------------------------------------------------------------------------
 */

$legacy_id     = 'snap_20260101000000_0123456789abcdef';
$legacy_option = 'diviops_rollback_snapshot_' . $legacy_id;
$after_content = '<!-- wp:divi/text {"id":"txtA","storeInstance":"1","x":1} --><!-- /wp:divi/text -->';

diviops_test_register_post( 96001, $after_content );

/*
 * Captured from the real helper rather than hand-written: restore compares the
 * live page's side effects against the recorded ones and refuses on any
 * difference, so an invented shape here would fail the drift check for reasons
 * that have nothing to do with what this file is testing.
 */
$live_side_effects = diviops_call( 'rollback_snapshot_capture_side_effects', array( 96001 ) );

add_option(
	$legacy_option,
	array(
		'schema_version' => 1,
		'snapshot_id'    => $legacy_id,
		'status'         => 'write_applied',
		'created_at'     => gmdate( 'c' ),
		'expires_at'     => gmdate( 'c', time() + 604800 ),
		'created_by'     => array( 'user_id' => 1, 'login' => 'dax' ),
		'tool'           => 'diviops_module_update',
		'target'         => array( 'kind' => 'post', 'id' => 96001, 'post_type' => 'page' ),
		// The whole point: stored before.value is non-canonical, as a pre-#206 record is.
		'before'         => array(
			'checksum'     => diviops_call( 'rollback_snapshot_checksum', array( CANON_DIRTY ) ),
			'byte_length'  => strlen( CANON_DIRTY ),
			'value'        => CANON_DIRTY,
			'side_effects' => $live_side_effects,
		),
		'after'          => array(
			'checksum'     => diviops_call( 'rollback_snapshot_checksum', array( $after_content ) ),
			'byte_length'  => strlen( $after_content ),
			'side_effects' => $live_side_effects,
		),
		'restore'        => array( 'restorable' => true, 'restored_at' => null, 'restored_by' => null ),
		'cleanup'        => array( 'deleted_at' => null, 'deleted_by' => null ),
	),
	'',
	'no'
);

$request = new WP_REST_Request();
$request->set_param( 'snapshot_id', $legacy_id );
$response = DiviOps_Agent::rollback_snapshot_restore( $request );
$data     = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : array();

assert_same( true, $data['ok'] ?? null, 'a legacy non-canonical snapshot still restores' );
assert_same(
	$canon_value,
	(string) get_post( 96001 )->post_content,
	'the restored page holds canonical bytes, so the integrity guard has nothing to revert'
);
assert_same(
	diviops_call( 'rollback_snapshot_checksum', array( $canon_value ) ),
	$data['data']['restored_checksum'] ?? null,
	'the reported restored_checksum describes what actually landed, not the stale bytes the record held'
);

/*
 * The checksum contract, stated outright because #208 warns that a restore which
 * silently stops matching its own recorded checksum is a worse failure than the one
 * being fixed.
 *
 * For a LEGACY record the restored bytes deliberately differ from before.checksum:
 * the record holds non-canonical bytes and we repair them on the way out. That
 * divergence is the transitional cost of the fix, and it is asserted here rather
 * than discovered later.
 */
assert_true(
	( $data['data']['restored_checksum'] ?? '' ) !== ( CANON_DIRTY === $canon_value ? '' : diviops_call( 'rollback_snapshot_checksum', array( CANON_DIRTY ) ) ),
	'a repaired legacy restore does NOT match the stale before.checksum — expected, and the response reports the checksum that actually landed'
);

/*
 * A record captured AFTER this change has no such divergence: capture already
 * canonicalized, so the restored bytes match before.checksum exactly. That is the
 * guarantee cleaning-on-the-way-in exists to preserve.
 */
$fresh_before = diviops_call( 'rollback_snapshot_before_from_post', array( diviops_test_register_post( 96003, CANON_DIRTY ) ) );
assert_same(
	$fresh_before['checksum'],
	diviops_call( 'rollback_snapshot_checksum', array( self_restore_value( $fresh_before['value'] ) ) ),
	'for a record captured after this fix, the repair is a no-op and the restored bytes still match before.checksum'
);

delete_option( $legacy_option );

/*
 * ---------------------------------------------------------------------------
 * The same repair applies to a run chunk entry (#199's v2 records).
 * ---------------------------------------------------------------------------
 */

$run_chunk_id = 'run_20260101000000_0123456789abcdef_c1';
$run_option   = 'diviops_rollback_snapshot_' . $run_chunk_id;

diviops_test_register_post( 96002, $after_content );

add_option(
	$run_option,
	array(
		'schema_version' => 2,
		'snapshot_id'    => $run_chunk_id,
		'run_id'         => 'run_20260101000000_0123456789abcdef',
		'chunk'          => 1,
		'status'         => 'write_applied',
		'created_at'     => gmdate( 'c' ),
		'expires_at'     => gmdate( 'c', time() + 604800 ),
		'created_by'     => array( 'user_id' => 1, 'login' => 'dax' ),
		'tool'           => 'diviops_preset_reassign',
		'targets'        => array(
			array(
				'id'        => 96002,
				'kind'      => 'post',
				'post_type' => 'page',
				'status'    => 'write_applied',
				'before'    => array(
					'checksum'     => diviops_call( 'rollback_snapshot_checksum', array( CANON_DIRTY ) ),
					'byte_length'  => strlen( CANON_DIRTY ),
					'value'        => CANON_DIRTY,
					'side_effects' => $live_side_effects,
				),
				'after'     => array(
					'checksum'     => diviops_call( 'rollback_snapshot_checksum', array( $after_content ) ),
					'byte_length'  => strlen( $after_content ),
					'side_effects' => $live_side_effects,
				),
			),
		),
		'restore'        => array( 'restorable' => true, 'restored_at' => null, 'restored_by' => null ),
		'cleanup'        => array( 'deleted_at' => null, 'deleted_by' => null ),
	),
	'',
	'no'
);

$run_request = new WP_REST_Request();
$run_request->set_param( 'snapshot_id', $run_chunk_id );
$run_response = DiviOps_Agent::rollback_snapshot_restore( $run_request );
$run_data     = is_object( $run_response ) && method_exists( $run_response, 'get_data' ) ? $run_response->get_data() : array();

assert_same( true, $run_data['ok'] ?? null, 'a run chunk holding legacy non-canonical bytes still restores' );
assert_same(
	$canon_value,
	(string) get_post( 96002 )->post_content,
	'the restored run entry holds canonical bytes too — the repair is not v1-only'
);

delete_option( $run_option );
