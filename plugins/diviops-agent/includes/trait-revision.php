<?php
/**
 * Native WordPress post-revision read / diff / restore (#34).
 *
 * WordPress stores post revisions natively — each revision is a post of type
 * `revision` whose `post_parent` is the edited post, enumerated with
 * wp_get_post_revisions() and rolled back with wp_restore_post_revision(). This
 * domain surfaces WordPress's own revision history alongside the plugin's separate
 * option-backed `rollback` snapshot system (trait-rollback.php): the two are
 * complementary, not the same store. Rollback snapshots are guarded, checksum-bound,
 * single-post backups the plugin writes before its own content mutations; native
 * revisions are whatever WordPress recorded on each post save.
 *
 * The four handlers mirror the rollback domain's envelope discipline: a row-level
 * read gate (can_inspect_post_object) on the same edit_post boundary raw reads use,
 * a dry-run plan for the one mutating call, and a Divi cache bust after the write.
 *
 * @package DiviOpsAgent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_Revision {

	/**
	 * sha256 content checksum in the same `sha256:<hex>` shape the rollback
	 * domain and the diff response use.
	 */
	private static function revision_checksum( string $value ): string {
		return 'sha256:' . hash( 'sha256', $value );
	}

	/**
	 * True when a loaded post object is a WordPress revision.
	 */
	private static function revision_is_revision( $post ): bool {
		return is_object( $post ) && 'revision' === (string) ( $post->post_type ?? '' );
	}

	/**
	 * List native WordPress revisions for a post, newest first.
	 *
	 * GET /revision/list/(?P<id>\d+), read-gated.
	 */
	public static function revision_list( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				sprintf( 'No post found for id %d.', $post_id ),
				'Pass the id of an existing post whose revisions you want to list.',
				404,
				[ 'post_id' => $post_id ]
			);
		}
		if ( ! self::can_inspect_post_object( $post ) ) {
			return self::envelope_object_read_forbidden( $post_id, 'revision' );
		}

		// wp_get_post_revisions() already returns newest-first, keyed by revision id.
		$revisions = wp_get_post_revisions( $post_id, [ 'posts_per_page' => -1 ] );
		$rows      = [];
		foreach ( $revisions as $revision ) {
			$content = (string) ( $revision->post_content ?? '' );
			$rows[]  = [
				'id'          => (int) $revision->ID,
				'date'        => (string) ( $revision->post_modified ?? '' ),
				'author'      => (int) ( $revision->post_author ?? 0 ),
				'byte_length' => strlen( $content ),
			];
		}

		return self::envelope_success( [
			'post_id'   => $post_id,
			'revisions' => $rows,
		] );
	}

	/**
	 * Read one revision, including its raw stored content.
	 *
	 * GET /revision/get/(?P<revision_id>\d+), read-gated on the parent post.
	 */
	public static function revision_get( $request ) {
		$revision_id = absint( $request['revision_id'] );
		$revision    = get_post( $revision_id );
		if ( ! self::revision_is_revision( $revision ) ) {
			return self::envelope_error(
				'not_found',
				sprintf( 'No revision found for id %d.', $revision_id ),
				'Use diviops_revision_list to find valid revision ids for a post.',
				404,
				[ 'revision_id' => $revision_id ]
			);
		}

		$parent_id = (int) ( $revision->post_parent ?? 0 );
		if ( ! self::can_inspect_post_object( $parent_id ) ) {
			return self::envelope_object_read_forbidden( $parent_id, 'revision' );
		}

		$content = (string) ( $revision->post_content ?? '' );
		return self::envelope_success( [
			'id'          => (int) $revision->ID,
			'parent'      => $parent_id,
			'date'        => (string) ( $revision->post_modified ?? '' ),
			'author'      => (int) ( $revision->post_author ?? 0 ),
			'content_raw' => $content,
			'byte_length' => strlen( $content ),
		] );
	}

	/**
	 * Restore a post to one of its native revisions.
	 *
	 * POST /revision/restore/(?P<revision_id>\d+), write-gated. Supports dry_run.
	 */
	public static function revision_restore( $request ) {
		$revision_id = absint( $request['revision_id'] );
		$revision    = get_post( $revision_id );
		if ( ! self::revision_is_revision( $revision ) ) {
			return self::envelope_error(
				'not_found',
				sprintf( 'No revision found for id %d.', $revision_id ),
				'Use diviops_revision_list to find valid revision ids for a post.',
				404,
				[ 'revision_id' => $revision_id ]
			);
		}

		$parent_id = (int) ( $revision->post_parent ?? 0 );
		if ( ! current_user_can( 'edit_post', $parent_id ) ) {
			return self::envelope_error(
				'forbidden',
				sprintf( 'Cannot restore revisions of post #%d.', $parent_id ),
				'Authenticate as a user with edit_post capability for the parent post.',
				403,
				[ 'revision_id' => $revision_id, 'parent' => $parent_id ]
			);
		}

		$date = (string) ( $revision->post_modified ?? '' );
		if ( rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false ) ) {
			return self::dry_run_response(
				sprintf( 'Would restore post #%d to revision #%d (dated %s).', $parent_id, $revision_id, $date ),
				[
					[
						'kind'   => 'revision.restore',
						'target' => "post#{$parent_id}",
						'before' => 'current',
						'after'  => "revision#{$revision_id}",
					],
				],
				[],
				[ 'revision_id' => $revision_id, 'parent' => $parent_id ]
			);
		}

		$restored = wp_restore_post_revision( $revision_id );
		if ( ! $restored || is_wp_error( $restored ) ) {
			return self::envelope_error(
				'wp_error',
				'WordPress could not restore the revision.',
				'Confirm the revision id and that revisions are enabled for the parent post type.',
				500,
				[ 'revision_id' => $revision_id, 'parent' => $parent_id ]
			);
		}

		self::invalidate_divi_cache( $parent_id );

		return self::envelope_success( [
			'restored'               => true,
			'parent'                 => $parent_id,
			'restored_from_revision' => $revision_id,
		] );
	}

	/**
	 * Compare two revisions (or one revision against the parent's current content).
	 *
	 * GET /revision/diff, read-gated on the shared parent. `from` is required; `to`
	 * is optional and, when omitted, the comparison target is the parent post's
	 * current content (reported with id 0). This is a deliberately simple
	 * checksum/byte comparison — callers fetch full content via revision_get to
	 * diff textually.
	 */
	public static function revision_diff( $request ) {
		$from_id = absint( $request->get_param( 'from' ) );
		if ( $from_id <= 0 ) {
			return self::envelope_error(
				'invalid_input',
				'A from revision id is required.',
				'Pass from=<revision id> (and optionally to=<revision id>).',
				400
			);
		}

		$from = get_post( $from_id );
		if ( ! self::revision_is_revision( $from ) ) {
			return self::envelope_error(
				'not_found',
				sprintf( 'No revision found for from id %d.', $from_id ),
				'Use diviops_revision_list to find valid revision ids for a post.',
				404,
				[ 'from' => $from_id ]
			);
		}
		$from_parent = (int) ( $from->post_parent ?? 0 );

		if ( ! self::can_inspect_post_object( $from_parent ) ) {
			return self::envelope_object_read_forbidden( $from_parent, 'revision' );
		}

		$to_param = $request->get_param( 'to' );
		$has_to   = null !== $to_param && '' !== $to_param;

		$from_content = (string) ( $from->post_content ?? '' );

		if ( $has_to ) {
			$to_id = absint( $to_param );
			$to    = get_post( $to_id );
			if ( ! self::revision_is_revision( $to ) ) {
				return self::envelope_error(
					'not_found',
					sprintf( 'No revision found for to id %d.', $to_id ),
					'Use diviops_revision_list to find valid revision ids for a post.',
					404,
					[ 'to' => $to_id ]
				);
			}
			$to_parent = (int) ( $to->post_parent ?? 0 );
			if ( $to_parent !== $from_parent ) {
				return self::envelope_error(
					'invalid_input',
					'Cannot diff revisions of different posts.',
					'from and to must be revisions of the same post.',
					400,
					[ 'from_parent' => $from_parent, 'to_parent' => $to_parent ]
				);
			}
			$to_content = (string) ( $to->post_content ?? '' );
			$to_out_id  = $to_id;
		} else {
			$parent     = get_post( $from_parent );
			$to_content = $parent ? (string) ( $parent->post_content ?? '' ) : '';
			$to_out_id  = 0;
		}

		$from_bytes = strlen( $from_content );
		$to_bytes   = strlen( $to_content );

		return self::envelope_success( [
			'from'       => [
				'id'       => $from_id,
				'bytes'    => $from_bytes,
				'checksum' => self::revision_checksum( $from_content ),
			],
			'to'         => [
				'id'       => $to_out_id,
				'bytes'    => $to_bytes,
				'checksum' => self::revision_checksum( $to_content ),
			],
			'identical'  => $from_content === $to_content,
			'byte_delta' => $to_bytes - $from_bytes,
		] );
	}
}
