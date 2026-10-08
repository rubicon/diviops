<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Import a Divi portability layout JSON onto a page (#490).
 *
 * The mirror of `trait-portability.php`'s `page_export()`, and deliberately NOT
 * built on Divi's own import.
 *
 * WHY NO DIVI SEAM. Divi 5's importer is
 * `ET\Builder\Framework\Portability\PortabilityPost::import( array $files, string $layout,
 * int $post_id, ?bool $include_global_presets_option, ?array $overrides )` at
 * `includes/builder-5/server/Framework/Portability/PortabilityPost.php:2133`, read on
 * the reference install at Divi 5.13.1. Two properties of it decided this file's
 * shape.
 *
 * It is well behaved where `export()` is not: grepped across its whole body
 * (`2133-2570`, the next declaration being `_populate_imported_layout_image_dimensions()`
 * at `:2571`) it reads no `$_POST`/`$_GET`/`$_FILES`/`$_REQUEST` and calls no
 * `wp_send_json*`/`die`/`exit`, and `$upload = $layout ? null : wp_handle_upload( … )`
 * means a non-empty `$layout` string bypasses the upload path entirely. So it COULD
 * be called from a REST handler, which `export()` could not.
 *
 * But it writes global data, and nothing can ask it not to. Inside that same call:
 * `import_global_colors( $import['global_colors'] )`,
 * `GlobalPreset::process_presets_for_import( $success['presets'] )` and
 * `update_post_meta( $post_id, '_et_pb_custom_css', … )`. Grepping the same range
 * for `dry.?run`/`preview`/`simulate` finds nothing — it has no preview mode. A dry
 * run that called it would therefore have already merged the payload's presets and
 * colours into the site before printing its "plan", and those merges would land
 * outside the rollback snapshot #490 requires per write. That is two of the issue's
 * own non-negotiables broken by the act of asking what would happen.
 *
 * It also does not write the post: no `wp_update_post` and no `wp_insert_post`
 * anywhere in its body. It computes content and returns it. So the only thing it
 * uniquely offers is D4-shortcode-to-D5 conversion
 * (`ShortcodeMigration::maybe_migrate_legacy_shortcode()` then
 * `Conversion::maybeConvertContent()`), and a payload needing that is refused here
 * by name rather than written unconverted — shortcodes stored into a D5 page render
 * as literal text instead of failing, which is the silent-wrongness class #490 was
 * split out of #382 to avoid.
 *
 * WHAT THIS MEANS FOR GLOBAL DATA. Presets and global colours carried by the payload
 * are CLASSIFIED and reported, never merged. Merging them already has purpose-built
 * routes here — `preset_create`/`preset_update`, `global_color_create`/`global_color_update`,
 * `variable_create`/`variable_update` — each with its own envelope, dry run and
 * rollback, and routing them through a side-effecting vendor call would bypass all
 * of it. A reference present in the target with a DIFFERENT value refuses the import
 * by default, because the page would then render with the target's values rather
 * than the exported ones.
 *
 * @package DiviOps
 */

/**
 * Constants for the layout importer.
 *
 * A helper `final class` rather than trait constants, the shape
 * `DiviOps_Compatibility_Divi` and `DiviOps_Divi_Read_Bridge` already use here:
 * traits cannot declare constants before PHP 8.2 and this plugin supports 7.4.
 */
final class DiviOps_Layout_Import {

	/**
	 * Error-code namespace for this route's own refusals.
	 *
	 * Also the `$error_namespace` handed to `update_post_content_with_integrity_guard()`,
	 * so a drift refusal from the shared write path arrives as
	 * `layout_import.global_layout_drift` rather than under another route's name.
	 */
	const NS = 'layout_import';

	/**
	 * A `sha256:<64 hex>` digest, the shape `page_content_checksum()` emits and
	 * `page_export()`'s `manifest.sha256` carries.
	 */
	const SHA256_PATTERN = '/^sha256:[0-9a-f]{64}$/';
}

trait DiviOps_Agent_Layout_Import {

	/**
	 * Import a portability layout JSON onto a page.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function page_layout_import( $request ) {
		$raw = $request->get_param( 'artifact_json' );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return self::envelope_error(
				'invalid_input',
				'artifact_json is required and must be the payload string page_export returned.',
				'Pass data.artifact_json from diviops_page_export verbatim. Re-encoding it changes the bytes its checksum covers.',
				400,
				[ 'field' => 'artifact_json' ]
			);
		}

		// Parameter shape before payload work, so a caller mistake is never
		// reported as a problem with the payload.
		$expected_artifact = $request->get_param( 'expected_artifact_sha256' );
		if ( null !== $expected_artifact ) {
			if ( ! is_string( $expected_artifact ) || ! preg_match( DiviOps_Layout_Import::SHA256_PATTERN, $expected_artifact ) ) {
				return self::envelope_error(
					'invalid_input',
					'expected_artifact_sha256 must look like sha256:<64 lowercase hex>.',
					'Use manifest.sha256 from the same diviops_page_export response, unaltered.',
					400,
					[ 'field' => 'expected_artifact_sha256' ]
				);
			}
		}

		$target = $request->get_param( 'target' );
		$is_overwrite = ( null !== $target && 'new' !== $target );

		$expected_checksum = $request->get_param( 'expected_checksum' );
		if ( $is_overwrite && ( ! is_string( $expected_checksum ) || '' === $expected_checksum ) ) {
			return self::envelope_error(
				'invalid_input',
				'Overwriting an existing page requires expected_checksum.',
				'Read the target with diviops_page_get and pass its content_checksum. Omit target entirely to import onto a new page instead.',
				400,
				[ 'field' => 'expected_checksum', 'target' => $target ]
			);
		}
		if ( $is_overwrite && ! preg_match( DiviOps_Layout_Import::SHA256_PATTERN, (string) $expected_checksum ) ) {
			return self::envelope_error(
				'invalid_input',
				'expected_checksum must look like sha256:<64 lowercase hex>.',
				'Use content_checksum from diviops_page_get on the target page.',
				400,
				[ 'field' => 'expected_checksum' ]
			);
		}

		// The pin is compared against the bytes as received, never against a
		// re-encoding of the decoded array: PHP and JavaScript escape `/` and
		// non-ASCII differently, so a re-encoded hash would agree only with
		// itself and every pinned import would refuse.
		if ( null !== $expected_artifact ) {
			$actual = 'sha256:' . hash( 'sha256', $raw );
			if ( ! hash_equals( (string) $expected_artifact, $actual ) ) {
				return self::envelope_error(
					DiviOps_Layout_Import::NS . '.artifact_drift',
					'The artifact bytes do not match expected_artifact_sha256.',
					'Re-export the source page; the payload was altered or truncated in transit.',
					409,
					[ 'expected' => (string) $expected_artifact, 'actual' => $actual, 'byte_length' => strlen( $raw ) ]
				);
			}
		}

		$artifact = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return self::envelope_error(
				DiviOps_Layout_Import::NS . '.invalid_json',
				'artifact_json is not valid JSON: ' . json_last_error_msg() . '.',
				'A truncated payload is the usual cause. Compare its byte length against manifest.byte_length from the export.',
				400,
				[ 'json_error' => json_last_error_msg(), 'byte_length' => strlen( $raw ) ]
			);
		}

		// Valid JSON that is not an artifact gets a DIFFERENT code from invalid
		// JSON, because the two have different causes and different fixes: one is
		// a transport problem, the other is the wrong document.
		if ( ! is_array( $artifact ) || ! isset( $artifact['data'] ) || ! is_array( $artifact['data'] ) || empty( $artifact['data'] ) ) {
			return self::envelope_error(
				DiviOps_Layout_Import::NS . '.malformed_artifact',
				'The payload decoded, but it is not a Divi portability artifact with a non-empty data map.',
				'Pass the artifact_json from diviops_page_export. A Divi Theme Builder or preset export is a different shape and is not accepted here.',
				400,
				[ 'top_level_keys' => is_array( $artifact ) ? array_slice( array_keys( $artifact ), 0, 8 ) : null ]
			);
		}

		$extracted = self::layout_import_extract_content( $artifact['data'] );
		if ( null === $extracted ) {
			return self::envelope_error(
				DiviOps_Layout_Import::NS . '.malformed_artifact',
				'The artifact data map carries no layout content.',
				'Each data entry should be the post_content string, or an object with a post_content key.',
				400,
				[ 'data_keys' => array_slice( array_map( 'strval', array_keys( $artifact['data'] ) ), 0, 8 ) ]
			);
		}

		if ( self::layout_import_is_shortcode_payload( $extracted['content'] ) ) {
			return self::envelope_error(
				DiviOps_Layout_Import::NS . '.shortcode_payload_unsupported',
				'This payload is a Divi 4 shortcode layout, which this route does not convert.',
				'Open it once in the Divi 5 builder on the source site and re-export, so the payload is block markup. Writing it unconverted would store shortcodes into a Divi 5 page, where they render as literal text rather than failing.',
				422,
				[ 'source_id' => $extracted['source_id'] ]
			);
		}

		// Not merely odd input: writing prose over a layout would report success and
		// leave a page with no modules on it. The shortcode refusal above cannot
		// catch this, because it only fires on an `[et_pb_` opener.
		if ( false === strpos( $extracted['content'], '<!-- wp:divi/' ) ) {
			return self::envelope_error(
				DiviOps_Layout_Import::NS . '.not_divi_content',
				'The payload carries no Divi block, so it is not a layout.',
				'Confirm this artifact came from diviops_page_export on a Divi 5 page. A Theme Builder export, a preset export or a plain-text document all land here.',
				422,
				[ 'source_id' => $extracted['source_id'], 'content_bytes' => strlen( $extracted['content'] ) ]
			);
		}

		// Remap BEFORE canonicalising and before the plan measures anything, so
		// content_bytes is the length of what will actually be stored and the
		// canonical pass runs over the rewritten markup rather than the incoming one.
		$remapped = self::layout_import_remap( $extracted['content'], $artifact, $request->get_param( 'remap' ) );
		if ( isset( $remapped['error'] ) ) {
			return self::envelope_error(
				DiviOps_Layout_Import::NS . '.remap_failed',
				sprintf( 'The reference remap could not be applied: %s.', (string) $remapped['error'] ),
				'The payload\'s attribute JSON could not be decoded and re-encoded safely, so nothing was rewritten. Re-export the source page.',
				422,
				[ 'cause' => (string) $remapped['error'] ]
			);
		}
		$extracted['content'] = (string) $remapped['content'];

		// CANONICALISE BEFORE ANYTHING MEASURES OR WRITES THE CONTENT.
		//
		// This is not cosmetic and it is not optional. WordPress re-serialises on
		// save through serialize_blocks() -> serialize_block_attributes(), which
		// hex-escapes `<`, `>`, `&` and `"` inside attribute JSON. Writing a
		// payload that still carries those bytes raw makes
		// update_post_content_with_integrity_guard() compare its own
		// non-canonical expectation against WordPress's canonical storage, read the
		// difference as corruption, and REVERT a write that was correct — the #206
		// defect, which `tests/test-module-update-write-safety.php` now pins for
		// every write path. An imported layout hits it almost always, because
		// `$variable()` design tokens carry `"` and prose carries HTML.
		//
		// Normalising already-canonical content is a no-op, so this cannot itself
		// introduce drift. It happens here, before the plan is built, so the
		// reported content_bytes is the length that will actually be stored.
		$normalized = self::normalize_divi_full_content_for_write( $extracted['content'] );
		if ( empty( $normalized['ok'] ) ) {
			$normalize_error = $normalized['error'] ?? [];
			return self::envelope_error(
				DiviOps_Layout_Import::NS . '.unsafe_attribute_json',
				$normalize_error['message'] ?? 'The payload contains Divi block attribute JSON this site cannot store safely.',
				$normalize_error['hint'] ?? 'Re-export the source page. A malformed escape inside attribute JSON must be corrected before it can be written.',
				422,
				array_merge( [ 'source_id' => $extracted['source_id'] ], (array) $normalize_error )
			);
		}
		$extracted['content'] = (string) $normalized['content'];

		$references = self::layout_import_classify_references( $artifact );
		$summary    = self::layout_import_summarize( $references );

		$allow_collisions = (bool) $request->get_param( 'allow_reference_collisions' );
		if ( $summary['collision'] > 0 && ! $allow_collisions ) {
			return self::envelope_error(
				DiviOps_Layout_Import::NS . '.reference_collision',
				sprintf(
					'%d reference(s) exist in this site with a different value than the payload carries.',
					$summary['collision']
				),
				'The page would render with THIS site\'s values, not the exported ones, and nothing would report the difference. Reconcile them with the preset/global_color tools, or pass allow_reference_collisions:true to accept the target\'s values deliberately.',
				409,
				[
					'reference_summary' => $summary,
					'collisions'        => self::layout_import_collisions( $references ),
				]
			);
		}

		$plan = [
			'remap'             => $remapped['plan'],
			'source_id'         => $extracted['source_id'],
			'content_bytes'     => strlen( $extracted['content'] ),
			'artifact_sha256'   => 'sha256:' . hash( 'sha256', $raw ),
			'references'        => $references,
			'reference_summary' => $summary,
		];

		// Defaults to TRUE, inverting this plugin's usual convention, for the
		// reason the bulk family inverts it: forgetting the flag on a single-page
		// handler costs one page, and forgetting it here writes a whole layout
		// over one.
		$dry_run = null === $request->get_param( 'dry_run' ) ? true : (bool) $request->get_param( 'dry_run' );
		if ( $dry_run ) {
			return self::envelope_success( [ 'dry_run' => true ] + $plan );
		}

		return self::layout_import_apply( $request, $extracted['content'], $plan, $is_overwrite ? $target : null, (string) $expected_checksum );
	}

	/**
	 * Write the imported layout, onto a named target or onto a new page.
	 *
	 * @param WP_REST_Request $request           REST request.
	 * @param string          $content           Layout content to write.
	 * @param array           $plan              Plan payload to echo back.
	 * @param mixed           $target            Target post id, or null to create.
	 * @param string          $expected_checksum Required when overwriting.
	 * @return WP_REST_Response
	 */
	private static function layout_import_apply( $request, string $content, array $plan, $target, string $expected_checksum ) {
		if ( null === $target ) {
			return self::layout_import_apply_new( $request, $content, $plan );
		}

		$post_id = absint( $target );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				sprintf( 'Page #%d not found.', $post_id ),
				'Verify the id with diviops_page_list, or omit target to import onto a new page.',
				404,
				[ 'page_id' => $post_id ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				sprintf( 'You cannot edit page #%d.', $post_id ),
				'The route-level write capability is not enough; this is the row-level edit_post gate.',
				403,
				[ 'page_id' => $post_id ]
			);
		}

		$previous = (string) $post->post_content;
		$actual   = self::page_content_checksum( $previous );
		if ( ! hash_equals( $expected_checksum, $actual ) ) {
			return self::envelope_error(
				DiviOps_Layout_Import::NS . '.content_drift',
				sprintf( 'Page #%d changed since you read it.', $post_id ),
				'Re-read the page with diviops_page_get and retry with its current content_checksum. There is no force option: overwriting a layout you have not seen is the mistake this guard exists for.',
				409,
				[ 'page_id' => $post_id, 'expected_checksum' => $expected_checksum, 'actual_checksum' => $actual ]
			);
		}

		// Snapshot BEFORE the write, and refuse the write outright if the capture
		// failed. A layout import is the largest single content write this plugin
		// makes, so proceeding without a recoverable copy is not a degraded mode.
		$run      = self::rollback_snapshot_run_begin(
			'diviops_page_layout_import',
			[ 'tool_operation' => 'layout_import.apply', 'page_id' => $post_id ]
		);
		$captured = self::rollback_snapshot_run_capture( $run, $post );
		if ( false === $captured ) {
			return self::envelope_error(
				'rollback_snapshot.storage_failed',
				sprintf( 'Could not store a rollback snapshot for page #%d, so nothing was written.', $post_id ),
				'This is usually an options-table write failure. Retry, and check the site error log if it persists.',
				500,
				[ 'page_id' => $post_id ]
			);
		}

		$written = self::layout_import_write( $post_id, $content, $previous );
		if ( is_wp_error( $written ) ) {
			self::rollback_snapshot_run_flush( $run );
			return self::envelope_from_content_write_error( $written );
		}

		$chunks = self::rollback_snapshot_run_flush( $run );

		return self::envelope_success( [
			'dry_run'         => false,
			'page_id'         => $post_id,
			'created'         => false,
			'snapshot_run_id' => $run['run_id'] ?? null,
			'snapshot_chunks' => count( (array) $chunks ),
		] + $plan );
	}

	/**
	 * Create a page and write the layout onto it.
	 *
	 * The page is inserted EMPTY and the content written through the shared
	 * integrity guard, rather than inserted with the content in one call. The guard
	 * is what performs the readback and the #11 global-layout drift check, and a
	 * layout arriving from another install is exactly the input those exist for —
	 * skipping them because the page happens to be new would make the newest content
	 * on the site the least checked.
	 *
	 * A guard refusal therefore leaves an empty draft behind. Its id is reported in
	 * the refusal rather than silently abandoned, so a caller can trash it.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param string          $content Layout content.
	 * @param array           $plan    Plan payload to echo back.
	 * @return WP_REST_Response
	 */
	private static function layout_import_apply_new( $request, string $content, array $plan ) {
		$title = $request->get_param( 'title' );
		$title = is_string( $title ) && '' !== trim( $title ) ? $title : 'Imported layout';

		$created = wp_insert_post( [
			'post_title'   => $title,
			'post_content' => '',
			'post_status'  => 'draft',
			'post_type'    => 'page',
		] );

		if ( is_wp_error( $created ) || ! $created ) {
			return self::envelope_error(
				DiviOps_Layout_Import::NS . '.create_failed',
				'The page could not be created, so nothing was imported.',
				'Check the site error log; wp_insert_post() refused.',
				500,
				null
			);
		}

		$post_id = (int) $created;
		$written = self::layout_import_write( $post_id, $content, '' );
		if ( is_wp_error( $written ) ) {
			// The empty draft survives a guard refusal. Its id travels in the
			// refusal rather than being abandoned silently, so a caller can trash
			// it; deleting it here would destroy the only evidence of what was
			// attempted.
			return self::envelope_error(
				DiviOps_Layout_Import::NS . '.write_refused',
				sprintf( 'The layout was not written, and empty draft page #%d was left behind.', $post_id ),
				'Trash page #' . $post_id . ' with diviops_page_trash, fix the cause named in error.data.cause, and retry.',
				(int) ( is_array( $written->get_error_data() ) && isset( $written->get_error_data()['status'] ) ? $written->get_error_data()['status'] : 500 ),
				[
					'page_id' => $post_id,
					'created' => true,
					'cause'   => [
						'code'    => (string) $written->get_error_code(),
						'message' => (string) $written->get_error_message(),
					],
				]
			);
		}

		return self::envelope_success( [
			'dry_run' => false,
			'page_id' => $post_id,
			'created' => true,
		] + $plan );
	}

	/**
	 * Classify every reference the payload declares against this site.
	 *
	 * The payload's own declarations are the input, not the content: `page_export()`
	 * already walked the block attrs and emitted the referenced preset subset, and
	 * Divi's serializer emits `global_colors`. Re-walking the content here would be a
	 * second implementation of that scan, free to drift from it.
	 *
	 * @param array $artifact Decoded artifact.
	 * @return array{presets:array<int,array>,global_colors:array<int,array>}
	 */
	private static function layout_import_classify_references( array $artifact ): array {
		$live_presets = [];
		foreach ( self::collect_d5_preset_audit_entries( self::get_d5_presets() ) as $row ) {
			$live_presets[ (string) $row['id'] ] = $row['entry'];
		}

		$incoming_presets = isset( $artifact['presets'] ) && is_array( $artifact['presets'] ) ? $artifact['presets'] : [];
		$presets          = [];
		foreach ( self::collect_d5_preset_audit_entries( $incoming_presets ) as $row ) {
			$id         = (string) $row['id'];
			$presets[] = [
				'id'          => $id,
				'bucket'      => $row['bucket'],
				'bucket_key'  => $row['bucket_key'],
				'disposition' => self::layout_import_disposition( $live_presets, $id, $row['entry'] ),
			];
		}

		$live_colors = self::layout_import_live_global_colors();
		$incoming    = isset( $artifact['global_colors'] ) && is_array( $artifact['global_colors'] ) ? $artifact['global_colors'] : [];
		$colors      = [];
		foreach ( $incoming as $id => $value ) {
			$colors[] = [
				'id'          => (string) $id,
				'disposition' => self::layout_import_disposition( $live_colors, (string) $id, $value ),
			];
		}

		return [ 'presets' => $presets, 'global_colors' => $colors ];
	}

	/**
	 * The live global-colour palette, read through EVERY storage path.
	 *
	 * Deliberately `global_color_paths()` + `read_global_color_path()` rather than
	 * `cross_env_global_colors()`, which reads only the top-level
	 * `et_global_data.global_colors`. A site storing its palette under
	 * `et_divi.et_global_data.global_colors` would then read as having no colours at
	 * all, and every incoming colour would classify `missing` instead of `resolved`
	 * or `collision` — the refusal this route exists for would never fire. That
	 * multipath storage is what the `global_color_storage_multipath_v1` capability
	 * key advertises.
	 *
	 * @return array<string,mixed>
	 */
	private static function layout_import_live_global_colors(): array {
		foreach ( self::global_color_paths() as $candidate ) {
			$content = self::read_global_color_path( $candidate['path'] );
			if ( is_array( $content ) && ! empty( $content ) ) {
				return $content;
			}
		}

		return [];
	}

	/**
	 * `resolved`, `collision` or `missing` for one reference.
	 *
	 * Equality is a normalised loose comparison, not `===`: both sides have been
	 * through `maybe_unserialize()` and `json_decode()` respectively, so an
	 * identical preset can arrive with integer-vs-string scalars or a different key
	 * order. Comparing sorted, JSON-normalised copies means a re-import of an
	 * untouched page reports `resolved` rather than a site-wide false collision,
	 * which would make the default refusal fire on every honest import.
	 *
	 * @param array  $live  Live values keyed by id.
	 * @param string $id    Reference id.
	 * @param mixed  $value Incoming value.
	 * @return string
	 */
	private static function layout_import_disposition( array $live, string $id, $value ): string {
		if ( ! array_key_exists( $id, $live ) ) {
			return 'missing';
		}

		return self::layout_import_values_equal( $live[ $id ], $value ) ? 'resolved' : 'collision';
	}

	/**
	 * Whether two reference values are the same after normalisation.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool
	 */
	private static function layout_import_values_equal( $a, $b ): bool {
		return self::layout_import_normalize( $a ) === self::layout_import_normalize( $b );
	}

	/**
	 * A value reduced to a comparable canonical form: arrays key-sorted
	 * recursively, scalars cast to string.
	 *
	 * @param mixed $value Value to normalise.
	 * @return mixed
	 */
	private static function layout_import_normalize( $value ) {
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ (string) $k ] = self::layout_import_normalize( $v );
			}
			ksort( $out );
			return $out;
		}
		if ( is_bool( $value ) || null === $value ) {
			return $value;
		}

		return (string) $value;
	}

	/**
	 * Counts per disposition across every reference group.
	 *
	 * Always carries all three keys, including zeros. A caller branching on
	 * `$summary['collision']` must not have to distinguish "none" from "absent".
	 *
	 * @param array $references classify_references() output.
	 * @return array{resolved:int,collision:int,missing:int}
	 */
	private static function layout_import_summarize( array $references ): array {
		$summary = [ 'resolved' => 0, 'collision' => 0, 'missing' => 0 ];
		foreach ( $references as $rows ) {
			foreach ( (array) $rows as $row ) {
				$key = $row['disposition'] ?? null;
				if ( isset( $summary[ $key ] ) ) {
					$summary[ $key ]++;
				}
			}
		}

		return $summary;
	}

	/**
	 * Just the colliding references, for the refusal payload.
	 *
	 * @param array $references classify_references() output.
	 * @return array<int,array>
	 */
	private static function layout_import_collisions( array $references ): array {
		$out = [];
		foreach ( $references as $group => $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( 'collision' === ( $row['disposition'] ?? null ) ) {
					$out[] = [ 'group' => (string) $group, 'id' => (string) ( $row['id'] ?? '' ) ];
				}
			}
		}

		return $out;
	}

	/**
	 * Rewrite cross-site references in the layout, and report every one (#96).
	 *
	 * URL-LEVEL REWRITING, ID-LEVEL REPORTING, and the split is the design rather
	 * than a shortcut. A full URL is a long unique string, so replacing it cannot
	 * collide with anything. A bare attachment id is a few digits that could equally
	 * be a font size or a z-index, and finding the ones that really are attachment
	 * references needs the coverage of Divi's PROTECTED `get_data_images()` — six
	 * attribute basenames across three responsive suffixes plus gallery ids — which
	 * `portability_referenced_image_count()` reaches by reflection precisely because a
	 * second copy of that list would drift from it. Rewriting ids by guessing where
	 * they live is how an import silently breaks images, so ids are reported with a
	 * disposition and left alone.
	 *
	 * The rewriting itself delegates to `bulk_replace_in_content()`, which decodes one
	 * opener's attribute JSON, replaces on the decoded tree and re-encodes. A raw byte
	 * splice over serialized markup is what empties a module when a replacement
	 * carries `"` or `\`.
	 *
	 * `url_map` is applied BEFORE the host rewrite, so an asset the caller mapped
	 * explicitly wins over the blanket host substitution that would otherwise catch
	 * its prefix first.
	 *
	 * @param string $content  Layout content.
	 * @param array  $artifact Decoded artifact, for its declared `images`.
	 * @param mixed  $remap    The caller's `remap` object, or null.
	 * @return array{content:string,plan:array,error?:string}
	 */
	private static function layout_import_remap( string $content, array $artifact, $remap ): array {
		$remap   = is_array( $remap ) ? $remap : [];
		$url_map = isset( $remap['url_map'] ) && is_array( $remap['url_map'] ) ? $remap['url_map'] : [];
		$source  = isset( $remap['source_home_url'] ) && is_string( $remap['source_home_url'] )
			? rtrim( trim( $remap['source_home_url'] ), '/' )
			: '';

		$plan = [ 'url_map' => [], 'attachments' => [], 'links' => [] ];

		// Explicit per-URL mappings first.
		foreach ( $url_map as $from => $to ) {
			if ( ! is_string( $from ) || ! is_string( $to ) || '' === $from || $from === $to ) {
				continue;
			}
			$applied = self::layout_import_rewrite( $content, $from, $to );
			if ( isset( $applied['error'] ) ) {
				return [ 'content' => $content, 'plan' => $plan, 'error' => (string) $applied['error'] ];
			}
			$content          = $applied['content'];
			$plan['url_map'][] = [ 'from' => $from, 'to' => $to, 'occurrences' => (int) $applied['occurrences'] ];
		}

		// Then the blanket host rewrite.
		if ( '' !== $source ) {
			$target  = rtrim( (string) home_url(), '/' );
			$applied = $target === $source
				? [ 'content' => $content, 'occurrences' => 0 ]
				: self::layout_import_rewrite( $content, $source, $target );
			if ( isset( $applied['error'] ) ) {
				return [ 'content' => $content, 'plan' => $plan, 'error' => (string) $applied['error'] ];
			}
			$content               = $applied['content'];
			$plan['host_rewrite'] = [
				'from'        => $source,
				'to'          => $target,
				'occurrences' => (int) $applied['occurrences'],
			];
		}

		$plan['attachments'] = self::layout_import_attachment_dispositions( $artifact, $source, $url_map );

		// After the rewriting above, deliberately: an id-only reference is by
		// construction untouched by a URL rewrite, so reporting it afterwards
		// describes the content the caller will actually get.
		$plan['links'] = self::layout_import_link_dispositions( $content );

		return [ 'content' => $content, 'plan' => $plan ];
	}

	/**
	 * One literal rewrite across body and attribute JSON, with an occurrence count.
	 *
	 * @param string $content Content to rewrite.
	 * @param string $from    Literal to find.
	 * @param string $to      Replacement.
	 * @return array{content:string,occurrences:int,error?:string}
	 */
	private static function layout_import_rewrite( string $content, string $from, string $to ): array {
		$result = self::bulk_replace_in_content( $content, $from, $to, 'both' );
		if ( ! empty( $result['error'] ) ) {
			return [ 'content' => $content, 'occurrences' => 0, 'error' => (string) $result['error'] ];
		}

		return [
			'content'     => (string) $result['content'],
			'occurrences' => (int) ( $result['body'] ?? 0 ) + (int) ( $result['attrs'] ?? 0 ),
		];
	}

	/**
	 * A disposition per attachment the payload declares.
	 *
	 * `url_rewritten` rather than `resolved`, deliberately. The URL now resolves on
	 * this site, which is what makes the image render; the stored attachment ID is
	 * still the source site's and was NOT rewritten. Calling that "resolved" would
	 * imply both halves were fixed, and a caller relying on the id — for a featured
	 * image, or anything reading it back out of the attrs — would be misled.
	 *
	 * @param array  $artifact Decoded artifact.
	 * @param string $source   Source home URL, or '' when no host rewrite ran.
	 * @param array  $url_map  The caller's explicit URL mappings.
	 * @return array<int,array>
	 */
	private static function layout_import_attachment_dispositions( array $artifact, string $source, array $url_map ): array {
		$images = isset( $artifact['images'] ) && is_array( $artifact['images'] ) ? $artifact['images'] : [];
		$rows   = [];

		foreach ( $images as $entry ) {
			$entry = self::normalize_storage_array( $entry );
			if ( null === $entry ) {
				continue;
			}
			$url = isset( $entry['url'] ) && is_string( $entry['url'] ) ? $entry['url'] : '';
			$id  = isset( $entry['id'] ) ? (int) $entry['id'] : 0;

			$rewritten = isset( $url_map[ $url ] )
				|| ( '' !== $source && '' !== $url && 0 === strpos( $url, $source ) );

			$rows[] = [
				'id'          => $id,
				'url'         => $url,
				'disposition' => $rewritten ? 'url_rewritten' : 'unresolved',
			];
		}

		return $rows;
	}

	/**
	 * One row per internal reference stored only as a post id (#538).
	 *
	 * These are the references a URL-level remap can never reach, because there is
	 * no URL in the content to rewrite: the id IS the whole reference. They cross to
	 * the target unchanged, where the same number is a different page or no page at
	 * all, and the visible symptom is a visitor landing somewhere unintended.
	 *
	 * REPORTED, NEVER REWRITTEN, and that split is #96's and not reopened here.
	 * Guessing a target id from a slug is how an import silently repoints a link, so
	 * this says which ids crossed and whether each resolves here, and leaves acting
	 * on them to the caller. The dispositions are deliberately `id_present` and
	 * `id_absent` — each states only whether a post with that id exists on this site,
	 * which is the single thing actually checked. A name like `id_unresolved` would
	 * imply the reference had been compared against its source meaning, which it has
	 * not; #537 was filed for exactly that class of overstatement.
	 *
	 * Scanned from the content rather than from an artifact collection, because Divi's
	 * `serialize_layout()` emits no links collection and adding one would be a second
	 * scanner free to drift from this one. The walk composes the same three primitives
	 * `portability_scan_markup()` composes and reaches no block parser, which
	 * `tests/test-layout-import.php`'s header depends on.
	 *
	 * @param string $content Layout markup, after any URL rewriting.
	 * @return array<int,array{kind:string,id:int,disposition:string}>
	 */
	private static function layout_import_link_dispositions( string $content ): array {
		$rows   = [];
		$offset = 0;

		while ( null !== ( $opener = self::next_block_opener( $content, $offset ) ) ) {
			$bounds = self::block_opening_comment_end( $content, $opener['pos'] );
			if ( null === $bounds ) {
				break;
			}

			$attrs = self::extract_attrs_from_block_markup(
				substr( $content, $opener['pos'], $bounds['comment_end'] - $opener['pos'] )
			);

			$block = (string) $opener['name'];

			if ( is_array( $attrs ) ) {
				if ( self::GLOBAL_LAYOUT_BLOCK_NAME === $block ) {
					$row = self::layout_import_link_row( 'global_layout', $block, $attrs['globalModule'] ?? null );
					if ( null !== $row ) {
						$rows[] = $row;
					}
				}

				$hits = [];
				self::dynamic_content_scan_attrs( $attrs, '', $hits );
				foreach ( $hits as $candidate ) {
					$row = self::layout_import_link_token_row( $block, (string) $candidate );
					if ( null !== $row ) {
						$rows[] = $row;
					}
				}
			} else {
				// Reported rather than skipped, which is the whole point. This scanner
				// runs before normalize_divi_full_content_for_write(), so undecodable
				// attribute JSON is a real shape here; dropping the block would make the
				// report claim there are no id-only references in content it never read.
				$rows[] = [
					'kind'        => 'block_attrs',
					'id'          => 0,
					'block'       => $block,
					'disposition' => 'attrs_unreadable',
				];
			}

			$offset = $bounds['comment_end'];
		}

		return $rows;
	}

	/**
	 * One link row from a dynamic-content token, or null when the token is not an
	 * id-only link.
	 *
	 * The option name alone is not enough and neither is `type`. `$variable(...)$` is
	 * Divi's SHARED wrapper: global colours (`gcid-`), variables (`gvid-`) and fonts
	 * (`gfid-`) use the identical syntax and the identical `"type":"content"`, as
	 * `dynamic_content_write_path_rejection()`'s docblock records. And the same
	 * post-link option family appears with no id at all — the live export of page
	 * 900390 carries `"name":"post_link_url","settings":{}`, which resolves against
	 * whichever post is being rendered and is therefore not a cross-site reference.
	 * So the discriminator is the option naming a post link AND carrying a numeric
	 * `post_id` in its settings.
	 *
	 * @param string $block     Block the token was found in.
	 * @param string $candidate Raw attribute value already matched as a token.
	 * @return array{kind:string,id:int,block:string,disposition:string}|null
	 */
	private static function layout_import_link_token_row( string $block, string $candidate ) {
		if ( 0 !== strpos( $candidate, '$variable(' ) ) {
			return null;
		}

		$parsed = self::dynamic_content_parse_modern( $candidate, 0, 'display' );
		$name   = isset( $parsed['name'] ) ? (string) $parsed['name'] : '';
		if ( '' === $name ) {
			return null;
		}

		$is_post_link = 0 === strpos( $name, 'post_link_url' ) || 'any_post_link_url' === $name;
		if ( ! $is_post_link ) {
			return null;
		}

		$settings = isset( $parsed['settings'] ) && is_array( $parsed['settings'] ) ? $parsed['settings'] : [];

		return self::layout_import_link_row( 'dynamic_link', $block, $settings['post_id'] ?? null );
	}

	/**
	 * One link row for a bare post id, or null when the value is not one.
	 *
	 * @param string $kind  Reference shape that carried the id.
	 * @param string $block Block the reference was found in.
	 * @param mixed  $id    Candidate id, as stored.
	 * @return array{kind:string,id:int,block:string,disposition:string}|null
	 */
	private static function layout_import_link_row( string $kind, string $block, $id ) {
		// ctype_digit rather than is_numeric, because is_numeric accepts forms that
		// the cast then turns into a DIFFERENT number: (int) '12.9' is 12 and
		// (int) '1e3' is 1000, so a malformed value would be reported as a confident
		// reference to a post the payload never named. Omitting a malformed id is
		// recoverable; inventing a plausible one is not, because the caller cannot
		// tell it from a real row. Divi stores these as bare digit strings.
		if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) ) {
			return null;
		}

		$post_id = (int) $id;
		if ( $post_id <= 0 ) {
			return null;
		}

		return [
			'kind'        => $kind,
			'id'          => $post_id,
			'block'       => $block,
			'disposition' => null === get_post( $post_id ) ? 'id_absent' : 'id_present',
		];
	}

	/**
	 * The ONE place this route writes post_content.
	 *
	 * Both destinations — a named target and a freshly created page — come through
	 * here, so canonicalisation and the integrity guard cannot be paired on one path
	 * and forgotten on the other. `tests/test-module-update-write-safety.php` asserts
	 * that property per function body rather than per file, which is what makes a
	 * single write function the correct shape instead of two normalised call sites.
	 *
	 * The normalisation is a second pass: `page_layout_import()` already canonicalised
	 * the payload before building the plan, so the reported `content_bytes` is what
	 * gets stored. Normalising canonical content is a no-op, and repeating it here
	 * keeps this function correct on its own terms rather than correct only because
	 * of what its callers happen to do first.
	 *
	 * @param int    $post_id  Page being written.
	 * @param string $content  Layout content.
	 * @param string $previous Content being replaced ('' for a new page).
	 * @return mixed True/updated post on success, WP_Error on refusal.
	 */
	private static function layout_import_write( int $post_id, string $content, string $previous ) {
		$normalized = self::normalize_divi_full_content_for_write( $content );
		if ( ! empty( $normalized['ok'] ) ) {
			$content = (string) $normalized['content'];
		}

		return self::update_post_content_with_integrity_guard(
			$post_id,
			$content,
			DiviOps_Layout_Import::NS,
			sprintf( 'page #%d', $post_id ),
			$previous,
			true
		);
	}

	/**
	 * The layout content out of an artifact's `data` map, with the id it came from.
	 *
	 * `serialize_layout()` keys `data` by source post id, and the value is either
	 * the content string or an array carrying `post_content` depending on the
	 * export path. Both are accepted because both occur in real payloads; anything
	 * else returns null so the caller can refuse rather than coerce an object to
	 * the string `"Array"`.
	 *
	 * @param array $data The artifact's `data` map.
	 * @return array{source_id:string,content:string}|null
	 */
	private static function layout_import_extract_content( array $data ) {
		foreach ( $data as $id => $entry ) {
			if ( is_string( $entry ) ) {
				return [ 'source_id' => (string) $id, 'content' => $entry ];
			}
			if ( is_array( $entry ) && isset( $entry['post_content'] ) && is_string( $entry['post_content'] ) ) {
				return [ 'source_id' => (string) $id, 'content' => $entry['post_content'] ];
			}
		}

		return null;
	}

	/**
	 * Whether this content is a Divi 4 shortcode layout rather than D5 blocks.
	 *
	 * Deliberately not "does it contain a shortcode": a D5 page can legitimately
	 * carry a shortcode inside a text module. The test is an `[et_pb_*` opener with
	 * NO Divi block comment anywhere, which is the D4-export shape and cannot be a
	 * D5 page, since every D5 layout serialises as `<!-- wp:divi/… -->`.
	 *
	 * @param string $content Layout content.
	 * @return bool
	 */
	private static function layout_import_is_shortcode_payload( string $content ): bool {
		return false !== strpos( $content, '[et_pb_' ) && false === strpos( $content, '<!-- wp:divi/' );
	}
}
