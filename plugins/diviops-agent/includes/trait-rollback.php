<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Rollback snapshot storage and read/delete tools.
 *
 * Typed storage inspection, cleanup, backup, and restore support.
 *
 * @package DiviOpsAgent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Rollback {
	private static function rollback_snapshot_option_prefix(): string {
		return 'diviops_rollback_snapshot_';
	}

	private static function rollback_snapshot_created_stale_seconds(): int {
		$minute = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
		return 15 * $minute;
	}

	private static function rollback_snapshot_validate_id( $snapshot_id ) {
		$snapshot_id = sanitize_text_field( (string) $snapshot_id );
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{2,127}$/', $snapshot_id ) ) {
			return false;
		}
		return $snapshot_id;
	}

	private static function rollback_snapshot_option_name( string $snapshot_id ): string {
		return self::rollback_snapshot_option_prefix() . $snapshot_id;
	}

	private static function rollback_snapshot_id_from_option_name( string $option_name ) {
		$prefix = self::rollback_snapshot_option_prefix();
		if ( 0 !== strpos( $option_name, $prefix ) ) {
			return false;
		}
		return self::rollback_snapshot_validate_id( substr( $option_name, strlen( $prefix ) ) );
	}

	/**
	 * Normalize the database-local options insertion sequence without relying on
	 * the platform integer width.
	 *
	 * @return string|null Canonical unsigned BIGINT decimal, or null when unsafe.
	 */
	private static function rollback_snapshot_storage_sequence( $option_id ): ?string {
		if ( ! is_int( $option_id ) && ! is_string( $option_id ) ) {
			return null;
		}
		$sequence = (string) $option_id;
		if ( ! preg_match( '/^[0-9]+$/', $sequence ) ) {
			return null;
		}
		$sequence = ltrim( $sequence, '0' );
		if ( '' === $sequence ) {
			return null;
		}
		$maximum = '18446744073709551615';
		if ( strlen( $sequence ) > strlen( $maximum ) || ( strlen( $sequence ) === strlen( $maximum ) && 0 < strcmp( $sequence, $maximum ) ) ) {
			return null;
		}
		return $sequence;
	}

	private static function rollback_snapshot_current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	private static function rollback_snapshot_user_login(): ?string {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return null;
		}
		$user = wp_get_current_user();
		if ( ! is_object( $user ) || empty( $user->user_login ) ) {
			return null;
		}
		return (string) $user->user_login;
	}

	private static function rollback_snapshot_expiry_seconds(): int {
		$day = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
		return 7 * $day;
	}

	private static function rollback_snapshot_requested( $request ): bool {
		$value = $request->get_param( 'backup' ) ?? false;
		if ( function_exists( 'rest_sanitize_boolean' ) ) {
			return rest_sanitize_boolean( $value );
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( (string) $value ), [ '1', 'true', 'yes', 'on' ], true );
	}

	private static function rollback_snapshot_now(): string {
		return gmdate( 'c' );
	}

	private static function rollback_snapshot_checksum( string $value ): string {
		return 'sha256:' . hash( 'sha256', $value );
	}

	private static function rollback_snapshot_generate_id( int $post_id, string $tool ): string {
		$seed = $tool . '|' . $post_id . '|' . microtime( true ) . '|' . wp_rand();
		return 'snap_' . gmdate( 'YmdHis' ) . '_' . substr( hash( 'sha256', $seed ), 0, 16 );
	}

	private static function rollback_snapshot_side_effect_meta_keys(): array {
		return [
			'_et_pb_use_builder',
			'_et_pb_use_divi_5',
			'_et_pb_page_layout',
			'_et_pb_built_for_post_type',
		];
	}

	private static function rollback_snapshot_capture_side_effects( int $post_id ): array {
		$post_meta = [];
		foreach ( self::rollback_snapshot_side_effect_meta_keys() as $key ) {
			$exists = function_exists( 'metadata_exists' )
				? metadata_exists( 'post', $post_id, $key )
				: '' !== (string) get_post_meta( $post_id, $key, true );
			$post_meta[ $key ] = [
				'exists' => (bool) $exists,
				'value'  => $exists ? get_post_meta( $post_id, $key, true ) : null,
			];
		}

		return [ 'post_meta' => $post_meta ];
	}

	private static function rollback_snapshot_target_from_post( $post ): array {
		return [
			'kind'      => 'post',
			'id'        => (int) $post->ID,
			'post_type' => sanitize_key( (string) ( $post->post_type ?? '' ) ),
		];
	}

	private static function rollback_snapshot_before_from_post( $post ): array {
		$value = (string) ( $post->post_content ?? '' );
		return [
			'checksum'     => self::rollback_snapshot_checksum( $value ),
			'byte_length'  => strlen( $value ),
			'value'        => $value,
			'side_effects' => self::rollback_snapshot_capture_side_effects( (int) $post->ID ),
		];
	}

	private static function rollback_snapshot_plan_for_post_write( $post, string $tool, array $operation ): array {
		$before = self::rollback_snapshot_before_from_post( $post );
		unset( $before['value'] );

		return [
			'requested'  => true,
			'created'    => false,
			'dry_run'    => true,
			'tool'       => $tool,
			'operation'  => (object) $operation,
			'target'     => self::rollback_snapshot_target_from_post( $post ),
			'before'     => $before,
			'created_at' => null,
			'expires_at' => null,
		];
	}

	private static function rollback_snapshot_noop_for_post_write( $post, string $tool, array $operation ): array {
		$plan = self::rollback_snapshot_plan_for_post_write( $post, $tool, $operation );
		$plan['dry_run'] = false;
		$plan['reason']  = 'noop';
		return $plan;
	}

	/**
	 * Maximum snapshot rows the store retains.
	 *
	 * This — not `expires_at` — is what bounds the store. The ceiling that
	 * actually matters is a count: rollback_snapshot_managed_inventory() reads at
	 * most 1000 records and reports `truncated` past that, so a fail-closed
	 * consumer loses managed recovery once the store crosses it. A time-based
	 * policy cannot guarantee staying under a count ceiling — a busy site can
	 * produce well over 1000 snapshots inside the 7-day expiry window — so the
	 * bound is a count, held far enough below 1000 to leave headroom.
	 */
	private static function rollback_snapshot_retention_limit(): int {
		return 500;
	}

	/**
	 * Most rows a single write will evict.
	 *
	 * Retention runs inside a guarded write, so the work it adds has to be
	 * bounded. In steady state the store is over the cap by exactly one and this
	 * never binds. It exists for the upgrade case: a site that ran the previous
	 * unbounded build can arrive with thousands of accumulated rows, and deleting
	 * all of them inside one REST request risks the timeout that would turn a
	 * good content write into a failed one. A backlog drains across subsequent
	 * writes instead.
	 */
	private static function rollback_snapshot_retention_batch(): int {
		return 50;
	}

	/**
	 * Pages per run-snapshot record (#199).
	 *
	 * A bulk run stores one record per chunk of pages rather than one per page,
	 * because one per page is what overflows the retention cap: an apply of
	 * 501-1000 pages evicts its own earliest snapshots as it runs and still
	 * reports success for those pages.
	 *
	 * The bound is 100 rather than the whole run because `before.value` holds a
	 * page's full prior post_content. A thousand of them in one option row could
	 * reach tens of megabytes, and get_option() loads a row whole — a memory
	 * cliff on constrained hosting and a max_allowed_packet risk on write. 100
	 * keeps a worst-case record in the low single-digit megabytes while still
	 * turning a 1000-page run from 1000 rows into 10, which is the entire point.
	 *
	 * Lower this on evidence; do not raise it without re-testing both ceilings.
	 */
	private static function rollback_snapshot_run_chunk_size(): int {
		return 100;
	}

	/**
	 * Identifier for a run-scoped record.
	 *
	 * Prefixed `run_` rather than `snap_` so a run record is distinguishable from
	 * a per-page snapshot by id alone — in logs, in an error envelope, and in a
	 * caller's hands — without loading the row to look at its schema_version. The
	 * shape still satisfies rollback_snapshot_validate_id(), including once a
	 * `_c<n>` chunk suffix is appended, so run records store and address exactly
	 * like any other snapshot.
	 */
	private static function rollback_snapshot_generate_run_id( string $tool ): string {
		$seed = $tool . '|run|' . microtime( true ) . '|' . wp_rand();
		return 'run_' . gmdate( 'YmdHis' ) . '_' . substr( hash( 'sha256', $seed ), 0, 16 );
	}

	/**
	 * Whether a stored record is run-scoped.
	 *
	 * Keyed on the targets payload and not on schema_version alone: a record
	 * claiming version 2 without targets to back it is malformed, and treating it
	 * as a run record would send the restore path walking an empty list and
	 * reporting success for a recovery that restored nothing.
	 *
	 * @param array<string, mixed> $record Stored snapshot record.
	 */
	private static function rollback_snapshot_is_run_record( array $record ): bool {
		return 2 === absint( $record['schema_version'] ?? 0 )
			&& is_array( $record['targets'] ?? null )
			&& array() !== $record['targets'];
	}

	/**
	 * Open a run-scoped snapshot.
	 *
	 * Returns a handle the caller threads through capture/mark/flush by
	 * reference. Deliberately not stored anywhere yet: nothing is written until a
	 * chunk fills or the run flushes, so an aborted run before its first write
	 * leaves no orphan row behind.
	 *
	 * @param string               $tool      Tool identifier recorded on each chunk.
	 * @param array<string, mixed> $operation Operation detail recorded on each chunk.
	 * @return array<string, mixed>
	 */
	private static function rollback_snapshot_run_begin( string $tool, array $operation ): array {
		return [
			'run_id'         => self::rollback_snapshot_generate_run_id( $tool ),
			'tool'           => $tool,
			'operation'      => $operation,
			'created_at'     => self::rollback_snapshot_now(),
			'seq'            => 0,
			'open'           => [],
			'chunks'         => [],
			'storage_failed' => false,
		];
	}

	/**
	 * Capture a page's prior state into the run's open chunk.
	 *
	 * Flushes as soon as the chunk fills rather than accumulating the whole run,
	 * so peak memory is one chunk and not one run — which is the difference
	 * between a 1000-page apply costing a few megabytes and costing hundreds.
	 *
	 * Keyed by post id, first capture wins: a run that touches the same page
	 * twice must still restore to the state from before the run, never to an
	 * intermediate one. A repeat capture returns the entry already stored rather
	 * than the one it just discarded, so a caller reading the return value cannot
	 * be handed the intermediate state.
	 *
	 * @param array<string, mixed> $run  Run handle, by reference.
	 * @param object               $post Post being written.
	 * @return array<string, mixed>|false The captured entry, or false when a full
	 *                                    chunk could not be written and the run
	 *                                    must not continue.
	 */
	private static function rollback_snapshot_run_capture( array &$run, $post ) {
		// Flush a full chunk on the way IN to the next capture, never on the way
		// out of this one. Flushing immediately after filling would write the
		// boundary page before its own rollback_snapshot_run_mark() call could
		// land, freezing that entry with a null after.checksum — and restore
		// refuses outright on an empty after.checksum, so exactly one page per
		// chunk would have been silently unrestorable. Peak memory is one chunk
		// plus one entry.
		if ( count( $run['open'] ) >= self::rollback_snapshot_run_chunk_size() ) {
			if ( null === self::rollback_snapshot_run_flush_open( $run ) ) {
				// The chunk is full and could not be stored. Appending anyway
				// would grow the very insert that just failed, and the likeliest
				// real cause of the failure is an oversized row — so the response
				// to "that was too big" would be to make the next one bigger.
				// Refuse instead and let the caller abort with pages still
				// recoverable.
				return false;
			}
		}

		$target = self::rollback_snapshot_target_from_post( $post );
		$entry  = [
			'id'        => (int) $post->ID,
			'kind'      => (string) ( $target['kind'] ?? 'post' ),
			'post_type' => (string) ( $target['post_type'] ?? '' ),
			'status'    => 'created',
			'before'    => self::rollback_snapshot_before_from_post( $post ),
			'after'     => [
				'checksum'     => null,
				'byte_length'  => null,
				'side_effects' => null,
			],
		];

		if ( isset( $run['open'][ (int) $post->ID ] ) ) {
			return $run['open'][ (int) $post->ID ];
		}

		$run['open'][ (int) $post->ID ] = $entry;

		return $entry;
	}

	/**
	 * Record the outcome of a page's write on its run entry.
	 *
	 * rollback_snapshot_restore() refuses outright unless after.checksum is
	 * non-empty, so an entry that is captured and never marked is permanently
	 * unrestorable. Every write path that captures must therefore mark, on both
	 * the success and the failure branch.
	 *
	 * @param array<string, mixed> $run            Run handle, by reference.
	 * @param int                  $post_id        Post the entry belongs to.
	 * Returns whether the mark landed. A void return would make the one dangerous
	 * caller ordering undetectable: run_mark() finds its entry in the OPEN chunk,
	 * so a caller that captures even one page ahead of marking would leave one
	 * unmarked entry per chunk — null after.checksum, permanently unrestorable,
	 * no signal. A caller that gets false must treat the run as compromised.
	 *
	 * @param array<string, mixed> $run            Run handle, by reference.
	 * @param int                  $post_id        Post the entry belongs to.
	 * @param string               $status         Terminal status for this entry.
	 * @param string|null          $after_content  Post-write content, or null when no write landed.
	 * @return bool Whether the entry was found in the open chunk and marked.
	 */
	private static function rollback_snapshot_run_mark( array &$run, int $post_id, string $status, ?string $after_content ): bool {
		if ( ! isset( $run['open'][ $post_id ] ) ) {
			return false;
		}

		$run['open'][ $post_id ]['status'] = $status;
		if ( null === $after_content ) {
			return true;
		}

		$run['open'][ $post_id ]['after'] = [
			'checksum'     => self::rollback_snapshot_checksum( $after_content ),
			'byte_length'  => strlen( $after_content ),
			'side_effects' => self::rollback_snapshot_capture_side_effects( $post_id ),
		];

		return true;
	}

	/**
	 * Derive a chunk's record status from the entries it holds.
	 *
	 * Hardcoding write_applied would record a chunk whose every page aborted
	 * before its write as applied. rollback_snapshot_managed_inventory() already
	 * gates on record status for v1 records, so any v2-aware reader added later
	 * would inherit that lie from data written today.
	 *
	 * @param array<int, array<string, mixed>> $entries Chunk entries.
	 */
	private static function rollback_snapshot_run_chunk_status( array $entries ): string {
		$applied = 0;
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry['after']['checksum'] ) ) {
				++$applied;
			}
		}

		if ( 0 === $applied ) {
			return 'aborted_before_write';
		}

		return $applied === count( $entries ) ? 'write_applied' : 'partially_applied';
	}

	/**
	 * Write the open chunk as one record and start a new one.
	 *
	 * Retention runs after the write, exactly as it does for a per-page snapshot:
	 * a run record is one row against the cap like any other and gets no
	 * exemption. Exempting an in-progress run would let the store exceed the cap
	 * by the run's size, which is the unbounded options table the cap exists to
	 * prevent.
	 *
	 * @param array<string, mixed> $run Run handle, by reference.
	 * @return string|null Option name written, or null when the chunk was empty.
	 */
	private static function rollback_snapshot_run_flush_open( array &$run ): ?string {
		if ( array() === $run['open'] ) {
			return null;
		}

		++$run['seq'];
		$snapshot_id = $run['run_id'] . '_c' . $run['seq'];
		$record      = [
			'schema_version' => 2,
			'snapshot_id'    => $snapshot_id,
			'run_id'         => $run['run_id'],
			'chunk'          => $run['seq'],
			'status'         => self::rollback_snapshot_run_chunk_status( $run['open'] ),
			'created_at'     => $run['created_at'],
			'expires_at'     => gmdate( 'c', time() + self::rollback_snapshot_expiry_seconds() ),
			'created_by'     => [
				'user_id' => self::rollback_snapshot_current_user_id(),
				'login'   => self::rollback_snapshot_user_login(),
			],
			'tool'           => $run['tool'],
			'operation'      => $run['operation'],
			'targets'        => array_values( $run['open'] ),
			'restore'        => [ 'restorable' => true, 'restored_at' => null, 'restored_by' => null ],
			'cleanup'        => [ 'deleted_at' => null, 'deleted_by' => null ],
		];

		$option_name = self::rollback_snapshot_option_name( $snapshot_id );
		$added       = add_option( $option_name, $record, '', 'no' );
		if ( ! $added ) {
			// Leave the chunk open rather than dropping it: silently discarding
			// captured pages would remove their restore path with no signal, which
			// is the failure being fixed, not an acceptable degradation. Flag the
			// run so the caller can tell a storage failure apart from an empty
			// chunk — both return null here, and only this flag distinguishes them.
			--$run['seq'];
			$run['storage_failed'] = true;
			return null;
		}

		$run['open']     = [];
		$run['chunks'][] = $option_name;

		self::rollback_snapshot_enforce_retention();

		return $option_name;
	}

	/**
	 * Close the run, writing any partial trailing chunk.
	 *
	 * @param array<string, mixed> $run Run handle, by reference.
	 * @return array<int, string> Option names written for this run, in order.
	 */
	private static function rollback_snapshot_run_flush( array &$run ): array {
		self::rollback_snapshot_run_flush_open( $run );
		return $run['chunks'];
	}

	/**
	 * Metadata view of a run chunk, without payload bytes.
	 *
	 * The v1 normalizer cannot be reused: it rejects anything without a singular
	 * positive target.id, which is deliberately how run records stay out of the
	 * managed-recovery seam Pro reads. This is the additive v2 replacement, and it
	 * mirrors v1's rule that a read never returns before.value unless the caller
	 * asked for it.
	 *
	 * @param array<string, mixed> $record      Stored run record.
	 * @param string               $option_name Option the record came from.
	 * @return array<string, mixed>|null Null when the record is not a usable run record.
	 */
	private static function rollback_snapshot_run_summary( array $record, string $option_name ): ?array {
		if ( ! self::rollback_snapshot_is_run_record( $record ) ) {
			return null;
		}

		$snapshot_id = self::rollback_snapshot_id_from_option_name( $option_name );
		if ( false === $snapshot_id ) {
			return null;
		}

		$targets = [];
		foreach ( $record['targets'] as $entry ) {
			$entry = self::rollback_snapshot_as_array( $entry );
			$id    = absint( $entry['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$before = self::rollback_snapshot_as_array( $entry['before'] ?? [] );
			$after  = self::rollback_snapshot_as_array( $entry['after'] ?? [] );

			$targets[] = [
				'id'          => $id,
				'kind'        => sanitize_key( (string) ( $entry['kind'] ?? 'post' ) ),
				'post_type'   => sanitize_key( (string) ( $entry['post_type'] ?? '' ) ),
				'status'      => sanitize_key( (string) ( $entry['status'] ?? 'created' ) ),
				'before'      => [
					'checksum'    => $before['checksum'] ?? null,
					'byte_length' => $before['byte_length'] ?? null,
				],
				'after'       => [
					'checksum'    => $after['checksum'] ?? null,
					'byte_length' => $after['byte_length'] ?? null,
				],
				// An entry with no after.checksum was captured and never marked.
				// Restore refuses it, so say so here rather than letting a caller
				// discover it only when recovery fails.
				'restorable'  => ! empty( $after['checksum'] ) && array_key_exists( 'value', $before ),
			];
		}

		if ( [] === $targets ) {
			return null;
		}

		$expires_at = sanitize_text_field( (string) ( $record['expires_at'] ?? '' ) );
		$expires_ts = '' !== $expires_at ? strtotime( $expires_at ) : false;

		return [
			'kind'           => 'run',
			'schema_version' => 2,
			'snapshot_id'    => $snapshot_id,
			'run_id'         => sanitize_text_field( (string) ( $record['run_id'] ?? '' ) ),
			'chunk'          => absint( $record['chunk'] ?? 0 ),
			'status'         => sanitize_key( (string) ( $record['status'] ?? 'created' ) ),
			'created_at'     => sanitize_text_field( (string) ( $record['created_at'] ?? '' ) ),
			'expires_at'     => $expires_at,
			'expired'        => false !== $expires_ts && $expires_ts < time(),
			'tool'           => sanitize_text_field( (string) ( $record['tool'] ?? '' ) ),
			'page_count'     => count( $targets ),
			'targets'        => $targets,
		];
	}

	/**
	 * Restore one entry of a run chunk.
	 *
	 * Deliberately mirrors rollback_snapshot_restore()'s gates one for one —
	 * target existence, per-object edit permission, supported type, restorable
	 * state, and the strict drift binding with no force override — because a
	 * batched storage shape must not weaken any check a single-snapshot restore
	 * applies. What differs is the outcome: a refusal is returned for this entry
	 * only, so its siblings still restore.
	 *
	 * @param array<string, mixed> $entry   One record from the chunk's targets.
	 * @param string               $chunk_id Chunk this entry belongs to.
	 * @param bool                 $dry_run Whether to report rather than write.
	 * @return array<string, mixed> Outcome carrying at least id and ok.
	 */
	private static function rollback_snapshot_run_restore_entry( array $entry, string $chunk_id, bool $dry_run ): array {
		$post_id = absint( $entry['id'] ?? 0 );
		$refuse  = static function ( string $reason, string $detail, array $extra = [] ) use ( $post_id ) {
			return array_merge( [ 'id' => $post_id, 'ok' => false, 'reason' => $reason, 'detail' => $detail ], $extra );
		};

		$before = self::rollback_snapshot_as_array( $entry['before'] ?? [] );
		$after  = self::rollback_snapshot_as_array( $entry['after'] ?? [] );

		// Checked before the post is loaded: an entry that was captured and never
		// marked can never be restored, whatever the live page looks like.
		if ( ! array_key_exists( 'value', $before ) || empty( $after['checksum'] ) ) {
			return $refuse( 'unrestorable', 'This page was captured but its write was never recorded, so there is no verified state to restore from.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $refuse( 'not_found', 'The page this entry covers no longer exists.' );
		}
		if ( ! self::can_inspect_post_object( $post ) ) {
			return $refuse( 'forbidden', 'You cannot restore this page.' );
		}
		if ( ! self::rollback_snapshot_supported_target( $post ) ) {
			return $refuse( 'unsupported_target', 'This target type is not supported by the restore MVP.', [ 'post_type' => (string) $post->post_type ] );
		}

		$current_content      = (string) ( $post->post_content ?? '' );
		$current_checksum     = self::rollback_snapshot_checksum( $current_content );
		$current_side_effects = self::rollback_snapshot_capture_side_effects( $post_id );
		$after_side_effects   = self::rollback_snapshot_as_array( $after['side_effects'] ?? [] );
		$content_drift        = ! hash_equals( (string) $after['checksum'], $current_checksum );
		$side_effect_drift    = ! empty( $after_side_effects ) && ! self::rollback_snapshot_side_effects_equal( $after_side_effects, $current_side_effects );

		if ( $content_drift || $side_effect_drift ) {
			// Refused for this page alone. Failing the whole run because one page
			// changed would make the common recovery case unusable: any page edited
			// after the run would veto every other page's restore.
			return $refuse(
				'conflict',
				'Refused because this page changed after the run wrote it.',
				[
					'drift' => [
						'content'                 => $content_drift,
						'side_effects'            => $side_effect_drift,
						'expected_after_checksum' => (string) $after['checksum'],
						'current_checksum'        => $current_checksum,
					],
				]
			);
		}

		$restore_content = (string) $before['value'];
		if ( $dry_run ) {
			return [
				'id'       => $post_id,
				'ok'       => true,
				'dry_run'  => true,
				'target'   => "{$post->post_type}#{$post_id}",
				'before'   => [ 'checksum' => $current_checksum ],
				'after'    => [ 'checksum' => self::rollback_snapshot_checksum( $restore_content ) ],
			];
		}

		$result = self::update_post_content_with_integrity_guard(
			$post_id,
			$restore_content,
			'rollback_snapshot',
			"rollback run chunk {$chunk_id}",
			$current_content
		);
		if ( is_wp_error( $result ) ) {
			return $refuse( 'write_failed', (string) $result->get_error_message(), [ 'code' => (string) $result->get_error_code() ] );
		}

		$side_effect_readback = self::rollback_snapshot_restore_side_effects( $post_id, self::rollback_snapshot_as_array( $before['side_effects'] ?? [] ) );
		if ( is_wp_error( $side_effect_readback ) ) {
			self::invalidate_divi_cache( $post_id );
			return $refuse( 'side_effect_readback_failed', 'Content was written, but captured Divi post-meta did not verify after restore.', [ 'committed' => true ] );
		}

		$readback          = get_post( $post_id );
		$readback_content  = $readback && isset( $readback->post_content ) ? (string) $readback->post_content : '';
		$restored_checksum = self::rollback_snapshot_checksum( $readback_content );
		$expected_checksum = self::rollback_snapshot_checksum( $restore_content );
		if ( ! hash_equals( $expected_checksum, $restored_checksum ) ) {
			self::invalidate_divi_cache( $post_id );
			return $refuse( 'readback_failed', 'Restore readback checksum did not match after the content write.', [ 'committed' => true, 'expected_checksum' => $expected_checksum, 'restored_checksum' => $restored_checksum ] );
		}

		self::invalidate_divi_cache( $post_id );

		return [
			'id'                     => $post_id,
			'ok'                     => true,
			'target'                 => [ 'kind' => 'post', 'id' => $post_id, 'post_type' => (string) $post->post_type ],
			'prior_current_checksum' => $current_checksum,
			'restored_checksum'      => $restored_checksum,
		];
	}

	/**
	 * Restore a run chunk, whole or in part.
	 *
	 * @param object               $request     REST request.
	 * @param string               $chunk_id    Validated chunk id.
	 * @param string               $option_name Option holding the chunk.
	 * @param array<string, mixed> $record      Stored run record.
	 */
	private static function rollback_snapshot_run_restore( $request, string $chunk_id, string $option_name, array $record ) {
		$summary = self::rollback_snapshot_run_summary( $record, $option_name );
		if ( null === $summary ) {
			return self::envelope_error( 'invalid_input', 'Rollback run record is malformed.', null, 400, [ 'snapshot_id' => $chunk_id ] );
		}

		$entries = [];
		foreach ( $record['targets'] as $entry ) {
			$entry = self::rollback_snapshot_as_array( $entry );
			$id    = absint( $entry['id'] ?? 0 );
			if ( $id > 0 ) {
				$entries[ $id ] = $entry;
			}
		}

		$requested = $request->get_param( 'page_ids' );
		if ( null !== $requested && '' !== $requested ) {
			if ( ! is_array( $requested ) ) {
				return self::envelope_error( 'invalid_input', 'page_ids must be an array of post ids.', 'Omit page_ids to restore the whole run.', 400, [ 'snapshot_id' => $chunk_id ] );
			}
			$selected = [];
			$unknown  = [];
			foreach ( $requested as $raw ) {
				$id = absint( $raw );
				if ( isset( $entries[ $id ] ) ) {
					$selected[ $id ] = $entries[ $id ];
					continue;
				}
				$unknown[] = $id;
			}
			if ( [] !== $unknown ) {
				// Named but absent is an error, not a no-op: silently ignoring it
				// would let a caller believe a page was reverted when nothing
				// touched it.
				return self::envelope_error(
					'invalid_input',
					'page_ids named pages that this run does not cover.',
					'Call diviops_rollback_snapshot_get on this snapshot_id to see which pages it covers.',
					400,
					[ 'snapshot_id' => $chunk_id, 'unknown_page_ids' => $unknown, 'covered_page_ids' => array_keys( $entries ) ]
				);
			}
			$entries = $selected;
		}

		$dry_run  = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );
		$restored = [];
		$refused  = [];
		foreach ( $entries as $entry ) {
			$outcome = self::rollback_snapshot_run_restore_entry( $entry, $chunk_id, $dry_run );
			if ( ! empty( $outcome['ok'] ) ) {
				$restored[] = $outcome;
				continue;
			}
			$refused[] = $outcome;
		}

		if ( ! $dry_run && [] !== $restored ) {
			$record['restore']                = self::rollback_snapshot_as_array( $record['restore'] ?? [] );
			$record['restore']['restored_at'] = self::rollback_snapshot_now();
			$record['restore']['restored_by'] = [ 'user_id' => self::rollback_snapshot_current_user_id(), 'login' => self::rollback_snapshot_user_login() ];
			$record['restore']['restored_page_ids'] = array_values( array_map( static function ( $row ) {
				return (int) $row['id'];
			}, $restored ) );
			update_option( $option_name, $record, false );
		}

		return self::envelope_success( [
			'snapshot_id' => $chunk_id,
			'kind'        => 'run',
			'run_id'      => $summary['run_id'],
			'dry_run'     => $dry_run,
			'requested'   => count( $entries ),
			'restored'    => $restored,
			'refused'     => $refused,
			'force'       => [ 'supported' => false, 'used' => false ],
		] );
	}

	/**
	 * Evict the oldest snapshots beyond the retention cap.
	 *
	 * Best-effort by design: this runs after the snapshot row is safely stored,
	 * ignores individual deletion failures, and never returns an error. A problem
	 * reclaiming space must not fail an otherwise-good content write.
	 *
	 * Ordering is newest-first so the surplus tail is the oldest rows, and the
	 * snapshot just created — always first — can never evict itself. A restored
	 * snapshot is evicted on age like any other; the cap bounds row count, a goal
	 * indifferent to restore history.
	 *
	 * @return int Rows actually deleted.
	 */
	private static function rollback_snapshot_enforce_retention(): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
			return 0;
		}

		$limit = self::rollback_snapshot_retention_limit();
		$like  = $wpdb->esc_like( self::rollback_snapshot_option_prefix() ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- `$wpdb->options` is the WordPress options table; this bounded retention scan prepares both values and has no stable cache API equivalent.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d", $like, $limit + self::rollback_snapshot_retention_batch() ) );
		if ( ! is_array( $rows ) || count( $rows ) <= $limit ) {
			return 0;
		}

		$deleted = 0;
		foreach ( array_slice( $rows, $limit ) as $row ) {
			$option_name = is_array( $row ) ? (string) ( $row['option_name'] ?? '' ) : (string) ( $row->option_name ?? '' );
			// Only ever delete a row whose name parses as a snapshot id, so a
			// prefix collision can never take an unrelated option with it.
			if ( false === self::rollback_snapshot_id_from_option_name( $option_name ) ) {
				continue;
			}
			if ( delete_option( $option_name ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	private static function rollback_snapshot_create_for_post_write( $post, string $tool, array $operation ) {
		$snapshot_id = self::rollback_snapshot_generate_id( (int) $post->ID, $tool );
		$created_at  = self::rollback_snapshot_now();
		$expires_at  = gmdate( 'c', time() + self::rollback_snapshot_expiry_seconds() );
		$record      = [
			'schema_version' => 1,
			'snapshot_id'    => $snapshot_id,
			'status'         => 'created',
			'created_at'     => $created_at,
			'expires_at'     => $expires_at,
			'created_by'     => [
				'user_id' => self::rollback_snapshot_current_user_id(),
				'login'   => self::rollback_snapshot_user_login(),
			],
			'tool'           => $tool,
			'operation'      => array_merge( [ 'dry_run' => false ], $operation ),
			'target'         => self::rollback_snapshot_target_from_post( $post ),
			'before'         => self::rollback_snapshot_before_from_post( $post ),
			'after'          => [
				'checksum'     => null,
				'byte_length'  => null,
				'side_effects' => null,
			],
			'restore'        => [ 'restorable' => true, 'restored_at' => null, 'restored_by' => null ],
			'cleanup'        => [ 'deleted_at' => null, 'deleted_by' => null ],
		];

		$option_name = self::rollback_snapshot_option_name( $snapshot_id );
		$added       = add_option( $option_name, $record, '', 'no' );
		if ( ! $added ) {
			return new WP_Error(
				'rollback_snapshot.storage_failed',
				'Could not create rollback snapshot before content write.',
				[
					'status' => 500,
					'hint'   => 'Retry after confirming the WordPress options table is writable.',
					'target' => self::rollback_snapshot_target_from_post( $post ),
				]
			);
		}

		self::rollback_snapshot_enforce_retention();

		return $record;
	}

	private static function rollback_snapshot_summary_for_record( array $record ): array {
		if ( array_key_exists( 'created', $record ) && false === $record['created'] && empty( $record['snapshot_id'] ) ) {
			$record['requested'] = true;
			return $record;
		}

		$summary = self::rollback_snapshot_normalize_record( $record, self::rollback_snapshot_option_name( (string) $record['snapshot_id'] ), $record );
		if ( null === $summary ) {
			return [
				'requested'   => true,
				'created'     => true,
				'snapshot_id' => (string) ( $record['snapshot_id'] ?? '' ),
				'status'      => (string) ( $record['status'] ?? '' ),
			];
		}
		$summary['requested'] = true;
		$summary['created']   = true;
		return $summary;
	}

	private static function rollback_snapshot_mark_post_write( array $record, string $status, ?string $after_content = null ): array {
		$record['status'] = $status;
		if ( null !== $after_content ) {
			$record['after'] = [
				'checksum'     => self::rollback_snapshot_checksum( $after_content ),
				'byte_length'  => strlen( $after_content ),
				'side_effects' => self::rollback_snapshot_capture_side_effects( (int) $record['target']['id'] ),
			];
		}
		update_option( self::rollback_snapshot_option_name( (string) $record['snapshot_id'] ), $record, false );
		return $record;
	}

	private static function rollback_snapshot_mark_from_write_error( array $record, $error ): array {
		$code   = is_object( $error ) && method_exists( $error, 'get_error_code' ) ? (string) $error->get_error_code() : '';
		$status = false !== strpos( $code, '.content_write_corruption' ) ? 'write_failed_restored' : 'aborted_before_write';
		$current = get_post( (int) $record['target']['id'] );
		$after   = $current && isset( $current->post_content ) ? (string) $current->post_content : null;
		return self::rollback_snapshot_mark_post_write( $record, $status, $after );
	}

	private static function rollback_snapshot_error_with_summary( $error, array $record ) {
		$data = is_object( $error ) && method_exists( $error, 'get_error_data' ) && is_array( $error->get_error_data() )
			? $error->get_error_data()
			: [];
		$data['backup'] = self::rollback_snapshot_summary_for_record( $record );
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	private static function rollback_snapshot_add_to_response( array $response, ?array $record ): array {
		if ( null !== $record ) {
			$response['backup'] = self::rollback_snapshot_summary_for_record( $record );
		}
		return $response;
	}

	private static function rollback_snapshot_created_by_current_user( array $record ): bool {
		$user_id = self::rollback_snapshot_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		$created_by = self::rollback_snapshot_as_array( $record['created_by'] ?? [] );
		return $user_id === (int) ( $created_by['user_id'] ?? 0 );
	}

	private static function rollback_snapshot_as_array( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			return get_object_vars( $value );
		}
		return [];
	}

	private static function rollback_snapshot_raw_byte_size( $raw_value, array $record ): int {
		if ( is_string( $raw_value ) ) {
			return strlen( $raw_value );
		}
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $record ) : json_encode( $record );
		return is_string( $json ) ? strlen( $json ) : 0;
	}

	private static function rollback_snapshot_normalize_record( array $record, string $option_name, $raw_value = null, ?string $autoload = null ) {
		$option_snapshot_id = self::rollback_snapshot_id_from_option_name( $option_name );
		$snapshot_id        = self::rollback_snapshot_validate_id( $record['snapshot_id'] ?? $option_snapshot_id );
		if ( false === $option_snapshot_id || false === $snapshot_id || $option_snapshot_id !== $snapshot_id ) {
			return null;
		}

		$target = self::rollback_snapshot_as_array( $record['target'] ?? [] );
		$target_id = absint( $target['id'] ?? 0 );
		if ( $target_id <= 0 ) {
			return null;
		}
		$target_kind = sanitize_key( (string) ( $target['kind'] ?? 'post' ) );
		if ( '' === $target_kind ) {
			$target_kind = 'post';
		}

		$status = sanitize_key( (string) ( $record['status'] ?? 'created' ) );
		if ( '' === $status ) {
			$status = 'created';
		}

		$created_at = sanitize_text_field( (string) ( $record['created_at'] ?? '' ) );
		$expires_at = sanitize_text_field( (string) ( $record['expires_at'] ?? '' ) );
		$created_ts = '' !== $created_at ? strtotime( $created_at ) : false;
		$expires_ts = '' !== $expires_at ? strtotime( $expires_at ) : false;
		$now = time();

		$created_by = self::rollback_snapshot_as_array( $record['created_by'] ?? [] );
		$before     = self::rollback_snapshot_as_array( $record['before'] ?? [] );
		$after      = self::rollback_snapshot_as_array( $record['after'] ?? [] );
		$restore    = self::rollback_snapshot_as_array( $record['restore'] ?? [] );
		$cleanup    = self::rollback_snapshot_as_array( $record['cleanup'] ?? [] );

		return [
			'schema_version' => absint( $record['schema_version'] ?? 1 ),
			'snapshot_id'    => $snapshot_id,
			'status'         => $status,
			'created_at'     => $created_at,
			'expires_at'     => $expires_at,
			'expired'        => false !== $expires_ts && $expires_ts < $now,
			'interrupted'    => 'created' === $status && false !== $created_ts && ( $now - $created_ts ) > self::rollback_snapshot_created_stale_seconds(),
			'created_by'     => [
				'user_id' => absint( $created_by['user_id'] ?? 0 ),
				'login'   => sanitize_text_field( (string) ( $created_by['login'] ?? '' ) ),
			],
			'tool'           => sanitize_key( (string) ( $record['tool'] ?? '' ) ),
			'operation'      => (object) self::rollback_snapshot_as_array( $record['operation'] ?? [] ),
			'target'         => [
				'kind'      => $target_kind,
				'id'        => $target_id,
				'post_type' => sanitize_key( (string) ( $target['post_type'] ?? '' ) ),
			],
			'before'         => [
				'checksum'    => sanitize_text_field( (string) ( $before['checksum'] ?? '' ) ),
				'byte_length' => isset( $before['byte_length'] ) ? absint( $before['byte_length'] ) : null,
				'has_value'   => array_key_exists( 'value', $before ),
			],
			'after'          => [
				'checksum'    => sanitize_text_field( (string) ( $after['checksum'] ?? '' ) ),
				'byte_length' => isset( $after['byte_length'] ) ? absint( $after['byte_length'] ) : null,
			],
			'restore'        => [
				'restorable'  => (bool) ( $restore['restorable'] ?? false ),
				'restored_at' => isset( $restore['restored_at'] ) ? sanitize_text_field( (string) $restore['restored_at'] ) : null,
			],
			'cleanup'        => [
				'deleted_at' => isset( $cleanup['deleted_at'] ) ? sanitize_text_field( (string) $cleanup['deleted_at'] ) : null,
			],
			'option'         => [
				'name'      => $option_name,
				'autoload'  => null === $autoload ? null : sanitize_key( (string) $autoload ),
				'byte_size' => self::rollback_snapshot_raw_byte_size( $raw_value, $record ),
			],
		];
	}

	private static function rollback_snapshot_target_post( array $summary ) {
		return function_exists( 'get_post' ) ? get_post( (int) $summary['target']['id'] ) : null;
	}

	/**
	 * Batch-load managed-inventory targets and prime their post-meta caches.
	 *
	 * @param int[] $target_ids Snapshot target post IDs.
	 * @return array<int,object> Posts keyed by ID.
	 */
	private static function rollback_snapshot_managed_target_posts( array $target_ids ): array {
		$target_ids = array_values( array_unique( array_filter( array_map( 'absint', $target_ids ) ) ) );
		if ( empty( $target_ids ) ) {
			return [];
		}

		$posts = [];
		if ( function_exists( 'get_posts' ) ) {
			$loaded = get_posts( [
				'post__in'               => $target_ids,
				'post_type'              => 'any',
				'post_status'            => 'any',
				'numberposts'             => count( $target_ids ),
				'orderby'                 => 'post__in',
				'suppress_filters'        => false,
				'no_found_rows'           => true,
				'update_post_meta_cache'  => true,
				'update_post_term_cache'  => false,
			] );
			foreach ( is_array( $loaded ) ? $loaded : [] as $post ) {
				if ( is_object( $post ) && ! empty( $post->ID ) ) {
					$posts[ (int) $post->ID ] = $post;
				}
			}
			// Query filters can scope the batch result (for example, to the
			// current language). Backfill requested IDs individually so an
			// existing recovery target is never misclassified as missing.
			foreach ( $target_ids as $target_id ) {
				if ( isset( $posts[ $target_id ] ) ) {
					continue;
				}
				$post = function_exists( 'get_post' ) ? get_post( $target_id ) : null;
				if ( is_object( $post ) && ! empty( $post->ID ) ) {
					$posts[ (int) $post->ID ] = $post;
				}
			}
			return $posts;
		}

		// Standalone fixtures and unusually small hosts may not expose get_posts().
		foreach ( $target_ids as $target_id ) {
			$post = function_exists( 'get_post' ) ? get_post( $target_id ) : null;
			if ( $post ) {
				$posts[ $target_id ] = $post;
			}
		}
		return $posts;
	}

	private static function rollback_snapshot_target_access( array $summary ): array {
		$post = self::rollback_snapshot_target_post( $summary );
		if ( $post ) {
			return [
				'exists'  => true,
				'allowed' => self::can_inspect_post_object( $post ),
				'creator' => self::rollback_snapshot_created_by_current_user( $summary ),
				'admin'   => current_user_can( 'manage_options' ),
			];
		}

		$creator = self::rollback_snapshot_created_by_current_user( $summary );
		$admin   = current_user_can( 'manage_options' );
		return [
			'exists'  => false,
			'allowed' => $creator || $admin,
			'creator' => $creator,
			'admin'   => $admin,
		];
	}

	private static function rollback_snapshot_scan_records( int $scan_limit = 250 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
			return [];
		}

		$like = $wpdb->esc_like( self::rollback_snapshot_option_prefix() ) . '%';
		$scan_limit = max( 1, min( 1000, $scan_limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- `$wpdb->options` is the WordPress options table; this bounded snapshot scan prepares both values and has no stable cache API equivalent.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d", $like, $scan_limit ) );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$records = [];
		foreach ( $rows as $row ) {
			$option_name  = is_array( $row ) ? (string) ( $row['option_name'] ?? '' ) : (string) ( $row->option_name ?? '' );
			$option_value = is_array( $row ) ? ( $row['option_value'] ?? null ) : ( $row->option_value ?? null );
			$autoload     = is_array( $row ) ? ( $row['autoload'] ?? null ) : ( $row->autoload ?? null );
			$value        = maybe_unserialize( $option_value );
			if ( ! is_array( $value ) ) {
				continue;
			}
			// #199: a run chunk cannot go through the v1 normalizer — it has no
			// singular target.id, which is exactly what keeps it out of the
			// managed-recovery seam Pro reads. It still has to be listable, or a
			// caller has no way to discover the id they need in order to restore.
			$run_summary = self::rollback_snapshot_run_summary( $value, $option_name );
			if ( null !== $run_summary ) {
				$records[] = $run_summary;
				continue;
			}

			$summary = self::rollback_snapshot_normalize_record( $value, $option_name, $option_value, null === $autoload ? null : (string) $autoload );
			if ( null !== $summary ) {
				$records[] = $summary;
			}
		}

		usort(
			$records,
			static function ( $a, $b ) {
				return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
			}
		);

		return $records;
	}

	/**
	 * Return the complete metadata-only snapshot inventory used by trusted
	 * site-local recovery orchestration.
	 *
	 * This is deliberately a PHP service seam, not a REST route. The Pro
	 * caller must pass the site-wide administrator gate before it can inspect
	 * viability, and this method repeats that gate before touching records.
	 * Snapshot payload bytes and raw post-meta values never leave this method.
	 *
	 * @return array|WP_Error Complete inventory evidence or a refusal.
	 */
	public static function rollback_snapshot_managed_inventory() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Managed rollback inventory requires manage_options.', [ 'status' => 403 ] );
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
			return new WP_Error( 'rollback_snapshot.inventory_unavailable', 'Rollback snapshot inventory is unavailable.', [ 'status' => 500 ] );
		}

		$maximum = 1000;
		$like    = $wpdb->esc_like( self::rollback_snapshot_option_prefix() ) . '%';
		// Fetch one sentinel row beyond the supported ceiling so orchestration
		// can fail closed instead of planning from a silently truncated subset.
		//
		// Newest-first (#187). The window used to be selected oldest-first, so
		// once the store crossed the ceiling the records dropped were the most
		// recent ones — precisely those a recovery is most likely to need.
		// Ordering by option_id rather than option_name deliberately: option_id
		// is the database's own monotonic insertion sequence, which this method
		// already trusts and reports as `storage_sequence`, whereas option_name
		// embeds a gmdate() stamp taken at creation and so misorders under clock
		// skew. The presentation `usort` below is unchanged.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded internal inventory has no stable cache API equivalent.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d", $like, $maximum + 1 ) );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'rollback_snapshot.inventory_unavailable', 'Rollback snapshot inventory query failed.', [ 'status' => 500 ] );
		}

		$truncated = count( $rows ) > $maximum;
		$rows      = array_slice( $rows, 0, $maximum );
		$records   = [];
		$target_ids = [];
		foreach ( $rows as $row ) {
			$option_value = is_array( $row ) ? ( $row['option_value'] ?? null ) : ( $row->option_value ?? null );
			$value = maybe_unserialize( $option_value );
			if ( is_array( $value ) ) {
				$target = self::rollback_snapshot_as_array( $value['target'] ?? [] );
				if ( ! empty( $target['id'] ) ) {
					$target_ids[] = absint( $target['id'] );
				}
			}
		}
		$target_posts = self::rollback_snapshot_managed_target_posts( $target_ids );
		$target_side_effects = [];
		foreach ( $rows as $row ) {
			$option_name       = is_array( $row ) ? (string) ( $row['option_name'] ?? '' ) : (string) ( $row->option_name ?? '' );
			$option_value      = is_array( $row ) ? ( $row['option_value'] ?? null ) : ( $row->option_value ?? null );
			$autoload          = is_array( $row ) ? ( $row['autoload'] ?? null ) : ( $row->autoload ?? null );
			$storage_option_id = is_array( $row ) ? ( $row['option_id'] ?? null ) : ( $row->option_id ?? null );
			$storage_sequence  = self::rollback_snapshot_storage_sequence( $storage_option_id );
			$value             = maybe_unserialize( $option_value );
			$snapshot_id_from_option_name = self::rollback_snapshot_id_from_option_name( $option_name );
			$byte_size         = is_string( $option_value ) ? strlen( $option_value ) : ( is_array( $value ) ? self::rollback_snapshot_raw_byte_size( $option_value, $value ) : 0 );

			if ( ! is_array( $value ) ) {
				$records[] = [
					'snapshot_id'      => false === $snapshot_id_from_option_name ? '' : $snapshot_id_from_option_name,
					'storage_sequence' => $storage_sequence,
					'malformed'        => true,
					'viable'           => false,
					'viability_reasons' => [ 'record_not_array' ],
					'option'           => [ 'autoload' => null === $autoload ? null : sanitize_key( (string) $autoload ), 'byte_size' => $byte_size ],
				];
				continue;
			}

			// #199: run-scoped records are deliberately outside this seam.
			//
			// This method is what DiviOps Agent Pro's managed recovery reads, and
			// Pro is closed-source and cannot be updated or tested from this
			// repository — so its view has to stay what it was before v2 records
			// existed. rollback_snapshot_normalize_record() rejecting a v2 record
			// is NOT sufficient on its own: unlike every other reader, this one
			// does not skip on a null normalization, it reports the row as
			// malformed below. Without this guard a single 1000-page reassign
			// would show Pro ten records it reads as store corruption, and they
			// would also be undeletable through rollback_snapshot_managed_delete_exact(),
			// which refuses a malformed record.
			if ( self::rollback_snapshot_is_run_record( $value ) ) {
				continue;
			}

			$summary = self::rollback_snapshot_normalize_record( $value, $option_name, $option_value, null === $autoload ? null : (string) $autoload );
			if ( null === $summary ) {
				$records[] = [
					'snapshot_id'      => false === $snapshot_id_from_option_name ? '' : $snapshot_id_from_option_name,
					'storage_sequence' => $storage_sequence,
					'malformed'        => true,
					'viable'           => false,
					'viability_reasons' => [ 'record_identity_invalid' ],
					'option'           => [ 'autoload' => null === $autoload ? null : sanitize_key( (string) $autoload ), 'byte_size' => $byte_size ],
				];
				continue;
			}

			$reasons = [];
			if ( 1 !== (int) $summary['schema_version'] ) {
				$reasons[] = 'schema_unsupported';
			}
			if ( empty( $summary['before']['has_value'] ) ) {
				$reasons[] = 'payload_missing';
			}
			if ( ! preg_match( '/^sha256:[a-f0-9]{64}$/', (string) $summary['before']['checksum'] ) || ! preg_match( '/^sha256:[a-f0-9]{64}$/', (string) $summary['after']['checksum'] ) ) {
				$reasons[] = 'checksum_evidence_missing';
			}
			if ( empty( $summary['restore']['restorable'] ) || 'write_applied' !== $summary['status'] ) {
				$reasons[] = 'status_not_restorable';
			}

			$target_id = (int) $summary['target']['id'];
			$post = $target_posts[ $target_id ] ?? null;
			if ( ! $post ) {
				$reasons[] = 'target_missing';
			} elseif ( ! self::rollback_snapshot_supported_target( $post ) ) {
				$reasons[] = 'target_unsupported';
			} elseif ( ! self::can_inspect_post_object( $post ) ) {
				$reasons[] = 'permission_uncertain';
			} else {
				$current_checksum = self::rollback_snapshot_checksum( (string) ( $post->post_content ?? '' ) );
				if ( ! hash_equals( (string) $summary['after']['checksum'], $current_checksum ) ) {
					$reasons[] = 'content_drift';
				}
				$after = self::rollback_snapshot_as_array( $value['after'] ?? [] );
				$expected_side_effects = self::rollback_snapshot_as_array( $after['side_effects'] ?? [] );
				if ( ! empty( $expected_side_effects ) && ! isset( $target_side_effects[ $target_id ] ) ) {
					$target_side_effects[ $target_id ] = self::rollback_snapshot_capture_side_effects( $target_id );
				}
				if ( ! empty( $expected_side_effects ) && ! self::rollback_snapshot_side_effects_equal( $expected_side_effects, $target_side_effects[ $target_id ] ) ) {
					$reasons[] = 'side_effect_drift';
				}
			}

			$records[] = [
				'snapshot_id'      => $summary['snapshot_id'],
				'storage_sequence' => $storage_sequence,
				'schema_version'   => $summary['schema_version'],
				'serializer'       => 'wordpress_divi_full_content.v1',
				'status'           => $summary['status'],
				'created_at'       => $summary['created_at'],
				'expires_at'       => $summary['expires_at'],
				'expired'          => $summary['expired'],
				'interrupted'      => $summary['interrupted'],
				'target'           => array_merge( $summary['target'], [ 'exists' => (bool) $post ] ),
				'before_checksum'  => $summary['before']['checksum'],
				'after_checksum'   => $summary['after']['checksum'],
				'restorable'       => $summary['restore']['restorable'],
				'malformed'        => false,
				'viable'           => empty( $reasons ),
				'viability_reasons' => $reasons,
				'option'           => [
					'autoload'  => $summary['option']['autoload'],
					'byte_size' => $summary['option']['byte_size'],
				],
			];
		}

		usort( $records, static function ( $a, $b ) { return strcmp( (string) ( $a['snapshot_id'] ?? '' ), (string) ( $b['snapshot_id'] ?? '' ) ); } );
		return [
			'complete'        => ! $truncated,
			'truncated'       => $truncated,
			'limit'           => $maximum,
			'count'           => count( $records ),
			'bytes'           => array_sum( array_map( static function ( $record ) { return (int) ( $record['option']['byte_size'] ?? 0 ); }, $records ) ),
			'malformed_count' => count( array_filter( $records, static function ( $record ) { return ! empty( $record['malformed'] ); } ) ),
			'records'         => $records,
		];
	}

	/**
	 * Delete one exact snapshot ID for trusted managed-retention code.
	 * Existing target permission and missing-target administrator cleanup
	 * semantics remain owned here in Free/core.
	 *
	 * @return array|WP_Error Deletion/readback evidence or a refusal.
	 */
	public static function rollback_snapshot_managed_delete_exact( $snapshot_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Managed rollback deletion requires manage_options.', [ 'status' => 403 ] );
		}
		$snapshot_id = self::rollback_snapshot_validate_id( $snapshot_id );
		if ( false === $snapshot_id ) {
			return new WP_Error( 'invalid_input', 'Invalid rollback snapshot id.', [ 'status' => 400 ] );
		}

		$option_name = self::rollback_snapshot_option_name( $snapshot_id );
		$record      = get_option( $option_name, null );
		if ( ! is_array( $record ) ) {
			return [ 'snapshot_id' => $snapshot_id, 'deleted' => false, 'already_absent' => true, 'byte_size' => 0 ];
		}
		$summary = self::rollback_snapshot_normalize_record( $record, $option_name, $record );
		if ( null === $summary ) {
			return new WP_Error( 'rollback_snapshot.malformed', 'Rollback snapshot record is malformed.', [ 'status' => 409, 'snapshot_id' => $snapshot_id ] );
		}
		$access = self::rollback_snapshot_target_access( $summary );
		if ( ! $access['allowed'] ) {
			return new WP_Error( 'forbidden', 'You cannot delete this rollback snapshot.', [ 'status' => 403, 'snapshot_id' => $snapshot_id ] );
		}

		$deleted = delete_option( $option_name );
		$absent  = null === get_option( $option_name, null );
		if ( ! $deleted || ! $absent ) {
			return new WP_Error( 'rollback_snapshot.delete_failed', 'Rollback snapshot deletion did not verify.', [ 'status' => 500, 'snapshot_id' => $snapshot_id, 'deleted' => (bool) $deleted, 'absent' => $absent ] );
		}
		return [
			'snapshot_id' => $snapshot_id,
			'deleted'     => true,
			'already_absent' => false,
			'byte_size'   => (int) $summary['option']['byte_size'],
			'target'      => [ 'kind' => $summary['target']['kind'], 'id' => $summary['target']['id'], 'exists' => $access['exists'] ],
		];
	}

	private static function rollback_snapshot_filtered_summaries( $request ): array {
		$target_kind = sanitize_key( (string) ( $request->get_param( 'target_kind' ) ?? '' ) );
		$target_id   = absint( $request->get_param( 'target_id' ) ?? 0 );
		$status      = sanitize_key( (string) ( $request->get_param( 'status' ) ?? '' ) );
		$limit       = absint( $request->get_param( 'limit' ) ?? 20 );
		$limit       = max( 1, min( 100, $limit ) );

		$rows = [];
		foreach ( self::rollback_snapshot_scan_records() as $summary ) {
			// #199: a run chunk covers many pages, so every filter below that reads
			// a singular $summary['target'] has to be answered differently. Handled
			// explicitly rather than by letting the singular reads fail soft —
			// PHP would emit "Undefined array key" notices and then compare null,
			// which silently passes filters that should have excluded the row.
			if ( 'run' === ( $summary['kind'] ?? '' ) ) {
				if ( '' !== $status && $status !== $summary['status'] ) {
					continue;
				}
				// A run's entries are always posts today, so a kind filter for
				// anything else excludes the whole record.
				if ( '' !== $target_kind && 'post' !== $target_kind ) {
					continue;
				}

				$visible = [];
				foreach ( $summary['targets'] as $entry ) {
					if ( $target_id > 0 && $target_id !== (int) $entry['id'] ) {
						continue;
					}
					$post = get_post( (int) $entry['id'] );
					// Same per-object gate the singular path applies: a caller only
					// learns a run covers a page they are allowed to see.
					if ( $post && self::can_inspect_post_object( $post ) ) {
						$visible[] = $entry;
					}
				}
				if ( [] === $visible ) {
					continue;
				}

				$summary['targets']            = $visible;
				$summary['page_count_visible'] = count( $visible );
				$rows[]                        = $summary;
				if ( count( $rows ) >= $limit ) {
					break;
				}
				continue;
			}

			if ( '' !== $target_kind && $target_kind !== $summary['target']['kind'] ) {
				continue;
			}
			if ( $target_id > 0 && $target_id !== (int) $summary['target']['id'] ) {
				continue;
			}
			if ( '' !== $status && $status !== $summary['status'] ) {
				continue;
			}

			$access = self::rollback_snapshot_target_access( $summary );
			if ( ! $access['allowed'] ) {
				continue;
			}
			$summary['target']['exists'] = $access['exists'];
			if ( ! $access['exists'] ) {
				$summary['payload_redacted'] = true;
				$summary['warning']          = 'target_missing_metadata_only';
			}
			$rows[] = $summary;
			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		return $rows;
	}

	public static function rollback_snapshot_list( $request ) {
		$snapshots = self::rollback_snapshot_filtered_summaries( $request );
		return self::envelope_success( [
			'snapshots' => $snapshots,
			'count'     => count( $snapshots ),
		] );
	}

	public static function rollback_snapshot_get( $request ) {
		$snapshot_id = self::rollback_snapshot_validate_id( $request->get_param( 'snapshot_id' ) );
		if ( false === $snapshot_id ) {
			return self::envelope_error( 'invalid_input', 'Invalid rollback snapshot id.', 'Use the snapshot_id returned by diviops_rollback_snapshot_list.', 400 );
		}

		$option_name = self::rollback_snapshot_option_name( $snapshot_id );
		$record      = get_option( $option_name, null );
		if ( ! is_array( $record ) ) {
			return self::envelope_error( 'not_found', 'Rollback snapshot not found.', null, 404, [ 'snapshot_id' => $snapshot_id ] );
		}
		// #199: run chunks are read through their own summary. Falling through to
		// the v1 normalizer would answer 400 malformed for a perfectly good record.
		$run_record = self::rollback_snapshot_run_summary( $record, $option_name );
		if ( null !== $run_record ) {
			return self::envelope_success( $run_record );
		}

		$summary = self::rollback_snapshot_normalize_record( $record, $option_name, $record );
		if ( null === $summary ) {
			return self::envelope_error( 'invalid_input', 'Rollback snapshot record is malformed.', null, 400, [ 'snapshot_id' => $snapshot_id ] );
		}

		$access = self::rollback_snapshot_target_access( $summary );
		if ( ! $access['allowed'] ) {
			return self::envelope_error( 'forbidden', 'You cannot inspect this rollback snapshot target.', null, 403, [ 'snapshot_id' => $snapshot_id, 'target' => $summary['target'] ] );
		}

		$include_value = rest_sanitize_boolean( $request->get_param( 'include_value' ) ?? false );
		$summary['target']['exists'] = $access['exists'];
		$data = [ 'snapshot' => $summary ];

		if ( ! $access['exists'] ) {
			$data['snapshot']['payload_redacted'] = true;
			$data['snapshot']['warning']          = 'target_missing_payload_redacted';
			return self::envelope_success( $data );
		}

		if ( $include_value ) {
			$before = self::rollback_snapshot_as_array( $record['before'] ?? [] );
			$after  = self::rollback_snapshot_as_array( $record['after'] ?? [] );
			$data['snapshot']['before']['value'] = $before['value'] ?? null;
			if ( isset( $before['side_effects'] ) ) {
				$data['snapshot']['before']['side_effects'] = $before['side_effects'];
			}
			if ( isset( $after['side_effects'] ) ) {
				$data['snapshot']['after']['side_effects'] = $after['side_effects'];
			}
		}

		return self::envelope_success( $data );
	}

	public static function rollback_snapshot_delete( $request ) {
		$snapshot_id = self::rollback_snapshot_validate_id( $request->get_param( 'snapshot_id' ) );
		if ( false === $snapshot_id ) {
			return self::envelope_error( 'invalid_input', 'Invalid rollback snapshot id.', 'Use the snapshot_id returned by diviops_rollback_snapshot_list.', 400 );
		}

		$option_name = self::rollback_snapshot_option_name( $snapshot_id );
		$record      = get_option( $option_name, null );
		if ( ! is_array( $record ) ) {
			return self::envelope_error( 'not_found', 'Rollback snapshot not found.', null, 404, [ 'snapshot_id' => $snapshot_id ] );
		}
		// #199: a run chunk must be deletable, or the only thing that can ever
		// remove one is retention eviction and a caller cannot clear space.
		$run_record = self::rollback_snapshot_run_summary( $record, $option_name );
		if ( null !== $run_record ) {
			$run_deleted = delete_option( $option_name );
			return self::envelope_success( [
				'snapshot_id' => $snapshot_id,
				'kind'        => 'run',
				'run_id'      => $run_record['run_id'],
				'deleted'     => (bool) $run_deleted,
				'page_count'  => $run_record['page_count'],
				'deleted_by'  => [
					'user_id' => self::rollback_snapshot_current_user_id(),
					'login'   => self::rollback_snapshot_user_login(),
				],
			] );
		}

		$summary = self::rollback_snapshot_normalize_record( $record, $option_name, $record );
		if ( null === $summary ) {
			return self::envelope_error( 'invalid_input', 'Rollback snapshot record is malformed.', null, 400, [ 'snapshot_id' => $snapshot_id ] );
		}

		$access = self::rollback_snapshot_target_access( $summary );
		if ( ! $access['allowed'] ) {
			return self::envelope_error( 'forbidden', 'You cannot delete this rollback snapshot.', null, 403, [ 'snapshot_id' => $snapshot_id, 'target' => $summary['target'] ] );
		}

		$deleted = delete_option( $option_name );
		return self::envelope_success( [
			'snapshot_id' => $snapshot_id,
			'deleted'     => (bool) $deleted,
			'target'      => [
				'kind'   => $summary['target']['kind'],
				'id'     => $summary['target']['id'],
				'exists' => $access['exists'],
			],
			'deleted_by'  => [
				'user_id' => self::rollback_snapshot_current_user_id(),
				'login'   => self::rollback_snapshot_user_login(),
			],
		] );
	}

	private static function rollback_snapshot_supported_target( $post ): bool {
		return is_object( $post ) && in_array(
			(string) ( $post->post_type ?? '' ),
			[ 'post', 'page', 'et_header_layout', 'et_body_layout', 'et_footer_layout' ],
			true
		);
	}

	private static function rollback_snapshot_normalize_nested_value( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		ksort( $value );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::rollback_snapshot_normalize_nested_value( $item );
		}
		return $value;
	}

	private static function rollback_snapshot_side_effects_equal( array $expected, array $actual ): bool {
		return self::rollback_snapshot_normalize_nested_value( $expected['post_meta'] ?? [] ) === self::rollback_snapshot_normalize_nested_value( $actual['post_meta'] ?? [] );
	}

	private static function rollback_snapshot_restore_side_effects( int $post_id, array $side_effects ) {
		$captured = self::rollback_snapshot_as_array( $side_effects['post_meta'] ?? [] );
		foreach ( self::rollback_snapshot_side_effect_meta_keys() as $key ) {
			if ( ! array_key_exists( $key, $captured ) ) {
				continue;
			}
			$state = self::rollback_snapshot_as_array( $captured[ $key ] );
			if ( ! empty( $state['exists'] ) ) {
				update_post_meta( $post_id, $key, $state['value'] ?? null );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}

		$readback = self::rollback_snapshot_capture_side_effects( $post_id );
		if ( ! self::rollback_snapshot_side_effects_equal( $side_effects, $readback ) ) {
			return new WP_Error(
				'rollback_snapshot.side_effect_readback_failed',
				'Rollback content was written, but captured Divi post-meta did not verify after restore.',
				[ 'status' => 500, 'side_effects' => [ 'expected' => $side_effects, 'readback' => $readback ] ]
			);
		}
		return $readback;
	}

	public static function rollback_snapshot_restore( $request ) {
		$snapshot_id = self::rollback_snapshot_validate_id( $request->get_param( 'snapshot_id' ) );
		if ( false === $snapshot_id ) {
			return self::envelope_error( 'invalid_input', 'Invalid rollback snapshot id.', 'Use the snapshot_id returned by diviops_rollback_snapshot_list.', 400 );
		}

		$option_name = self::rollback_snapshot_option_name( $snapshot_id );
		$record      = get_option( $option_name, null );
		if ( ! is_array( $record ) ) {
			return self::envelope_error( 'not_found', 'Rollback snapshot not found.', null, 404, [ 'snapshot_id' => $snapshot_id ] );
		}
		// #199: run chunks restore through their own path, whole or by page_ids.
		if ( self::rollback_snapshot_is_run_record( $record ) ) {
			return self::rollback_snapshot_run_restore( $request, $snapshot_id, $option_name, $record );
		}

		$summary = self::rollback_snapshot_normalize_record( $record, $option_name, $record );
		if ( null === $summary ) {
			return self::envelope_error( 'invalid_input', 'Rollback snapshot record is malformed.', null, 400, [ 'snapshot_id' => $snapshot_id ] );
		}

		// Do not read or copy before.value until the live target's row-level edit gate passes.
		$post = self::rollback_snapshot_target_post( $summary );
		if ( ! $post ) {
			return self::envelope_error( 'not_found', 'Rollback snapshot target no longer exists.', null, 404, [ 'snapshot_id' => $snapshot_id, 'target' => $summary['target'] ] );
		}
		if ( ! self::can_inspect_post_object( $post ) ) {
			return self::envelope_error( 'forbidden', 'You cannot restore this rollback snapshot target.', null, 403, [ 'snapshot_id' => $snapshot_id, 'target' => $summary['target'] ] );
		}
		if ( ! self::rollback_snapshot_supported_target( $post ) ) {
			return self::envelope_error( 'invalid_input', 'This rollback snapshot target type is not supported by the restore MVP.', null, 400, [ 'snapshot_id' => $snapshot_id, 'post_type' => (string) $post->post_type ] );
		}

		$before = self::rollback_snapshot_as_array( $record['before'] ?? [] );
		$after  = self::rollback_snapshot_as_array( $record['after'] ?? [] );
		if ( empty( $summary['restore']['restorable'] ) || ! array_key_exists( 'value', $before ) || empty( $after['checksum'] ) ) {
			return self::envelope_error( 'conflict', 'Rollback snapshot is not in a restorable state.', null, 409, [ 'snapshot_id' => $snapshot_id, 'status' => $summary['status'] ] );
		}

		$current_content      = (string) ( $post->post_content ?? '' );
		$current_checksum     = self::rollback_snapshot_checksum( $current_content );
		$current_side_effects = self::rollback_snapshot_capture_side_effects( (int) $post->ID );
		$after_side_effects   = self::rollback_snapshot_as_array( $after['side_effects'] ?? [] );
		$content_drift        = ! hash_equals( (string) $after['checksum'], $current_checksum );
		$side_effect_drift    = ! empty( $after_side_effects ) && ! self::rollback_snapshot_side_effects_equal( $after_side_effects, $current_side_effects );
		if ( $content_drift || $side_effect_drift ) {
			return self::envelope_error(
				'conflict',
				'Rollback refused because the target changed after the snapshot write.',
				'Inspect the drift and create a fresh guarded backup before any later restore attempt. This MVP has no force override.',
				409,
				[
					'snapshot_id' => $snapshot_id,
					'drift'       => [
						'content'      => $content_drift,
						'side_effects' => $side_effect_drift,
						'expected_after_checksum' => (string) $after['checksum'],
						'current_checksum'        => $current_checksum,
						'expected_after_side_effects' => $after_side_effects,
						'current_side_effects'        => $current_side_effects,
					],
				]
			);
		}

		$restore_content = (string) $before['value'];
		if ( rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false ) ) {
			return self::dry_run_response(
				"Would restore rollback snapshot {$snapshot_id} to {$post->post_type}#{$post->ID}.",
				[ [ 'kind' => 'rollback_snapshot.restore', 'target' => "{$post->post_type}#{$post->ID}", 'before' => [ 'checksum' => $current_checksum ], 'after' => [ 'checksum' => self::rollback_snapshot_checksum( $restore_content ) ] ] ]
			);
		}

		$result = self::update_post_content_with_integrity_guard(
			(int) $post->ID,
			$restore_content,
			'rollback_snapshot',
			"rollback snapshot {$snapshot_id}",
			$current_content
		);
		if ( is_wp_error( $result ) ) {
			return self::envelope_from_content_write_error( $result );
		}

		$side_effect_readback = self::rollback_snapshot_restore_side_effects( (int) $post->ID, self::rollback_snapshot_as_array( $before['side_effects'] ?? [] ) );
		if ( is_wp_error( $side_effect_readback ) ) {
			$error_data = $side_effect_readback->get_error_data();
			self::invalidate_divi_cache( (int) $post->ID );
			return self::envelope_error(
				'rollback_snapshot.side_effect_readback_failed',
				'Rollback content was written, but captured Divi post-meta did not verify after restore.',
				'The target content changed; inspect the side-effect diagnostics before retrying.',
				500,
				[
					'snapshot_id'       => $snapshot_id,
					'record_id'         => (int) $post->ID,
					'committed'         => true,
					'changed_fields'    => [ 'post_content', 'post_meta' ],
					'prior_checksum'    => $current_checksum,
					'intended_checksum' => self::rollback_snapshot_checksum( $restore_content ),
					'side_effects'      => is_array( $error_data ) ? ( $error_data['side_effects'] ?? null ) : null,
				]
			);
		}

		$readback          = get_post( (int) $post->ID );
		$readback_content  = $readback && isset( $readback->post_content ) ? (string) $readback->post_content : '';
		$restored_checksum = self::rollback_snapshot_checksum( $readback_content );
		$expected_checksum = self::rollback_snapshot_checksum( $restore_content );
		if ( ! hash_equals( $expected_checksum, $restored_checksum ) ) {
			self::invalidate_divi_cache( (int) $post->ID );
			return self::envelope_error(
				'readback_failed',
				'Rollback restore readback checksum did not match after the content write.',
				'The target content may have changed; inspect checksums before retrying.',
				500,
				[
					'snapshot_id'       => $snapshot_id,
					'record_id'         => (int) $post->ID,
					'committed'         => true,
					'changed_fields'    => [ 'post_content', 'post_meta' ],
					'prior_checksum'    => $current_checksum,
					'expected_checksum' => $expected_checksum,
					'restored_checksum' => $restored_checksum,
				]
			);
		}

		self::invalidate_divi_cache( (int) $post->ID );
		$record['status']                            = 'restore_applied';
		$record['restore']                           = self::rollback_snapshot_as_array( $record['restore'] ?? [] );
		$record['restore']['restored_at']            = self::rollback_snapshot_now();
		$record['restore']['restored_by']            = [ 'user_id' => self::rollback_snapshot_current_user_id(), 'login' => self::rollback_snapshot_user_login() ];
		$record['restore']['prior_current_checksum'] = $current_checksum;
		$record['restore']['restored_checksum']      = $restored_checksum;
		update_option( $option_name, $record, false );

		return self::envelope_success( [
			'snapshot_id'           => $snapshot_id,
			'target'                => [ 'kind' => 'post', 'id' => (int) $post->ID, 'post_type' => (string) $post->post_type ],
			'prior_current_checksum' => $current_checksum,
			'restored_checksum'      => $restored_checksum,
			'readback'               => [ 'verified' => true, 'checksum' => $restored_checksum, 'byte_length' => strlen( $readback_content ), 'side_effects' => $side_effect_readback ],
			'cache'                  => [ 'invalidated' => true, 'post_id' => (int) $post->ID ],
			'status'                 => [ 'before' => $summary['status'], 'after' => 'restore_applied', 'restored_at' => $record['restore']['restored_at'] ],
			'force'                  => [ 'supported' => false, 'used' => false ],
			'restore_backup'         => [ 'created' => false, 'deferred' => true, 'reason' => 'Strict checksum binding prevents intervening-state overwrite in this MVP.' ],
		] );
	}
}
