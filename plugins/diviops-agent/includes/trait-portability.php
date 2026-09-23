<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Divi layout export through Divi's own portability serializer (#382).
 *
 * `page_export` hands back the raw Divi export payload for one post, plus a
 * manifest describing it, so a caller can move a layout to another install
 * without opening the payload.
 *
 * THE SEAM IS `serialize_layout()`, NOT `export()`. Divi's
 * `ET_Core_Portability::export()` (`core/components/Portability.php:559` at
 * Divi 5.13.1) reads `$_POST['post']`, `$_POST['content']` and
 * `$_POST['apply_global_presets']` and ends in `wp_send_json_error()`, which
 * dies — unusable from a REST handler. `serialize_layout( $id, $post_id,
 * $content, $theme_builder_meta, $chunk )` (`:1302-1383`) takes every input as
 * an explicit parameter, returns `array{ready:bool, chunks:int, data:array}`,
 * and its body reads no superglobal and calls no `wp_send_json`/`die`/`exit`.
 *
 * IMAGE CHUNKING IS TURNED OFF FOR THE DURATION OF THE CALL.
 * `chunk_images()` (`:3469-3504`) takes its paginating branch only when
 * `apply_filters( 'et_core_portability_paginate_images', true )` holds AND
 * `count( $images ) > 5`; that branch writes temp files through
 * `temp_file()` / `$filesystem->put_contents()`. The `else` branch encodes in
 * one pass and touches no filesystem. `portability_run_serialize_layout()`
 * therefore installs `__return_false` on that filter around the call and
 * removes it again in a `finally`, so a throw cannot leave a site-wide filter
 * installed. With pagination off the return is always `ready === true` and
 * `chunks === 1`; the handler asserts both anyway as a backstop, because a
 * partial export that reports success is the failure this route must not have.
 *
 * MEMORY AND TIME ARE DIVI'S PROBLEM ALREADY. `serialize_layout()` opens with
 * `prevent_failure()` (`:4102`), which does `@set_time_limit( 0 )` and raises
 * `memory_limit` to 256M when it is lower. This handler adds nothing.
 *
 * A CONTEXT IS REGISTERED BEFORE LOADING. Of the helpers `serialize_layout()`
 * reaches, `apply_query()` (`:3442`) reads `$this->instance->exclude` and
 * `$this->instance->include`. `ET_Core_Portability::__construct()` sets
 * `instance` from `et_core_cache_get( $context, 'et_core_portability' )`, which
 * is `false` outside a registered context, so those reads warn. Registering our
 * own context id — never `et_builder` — keeps this route's empty
 * include/exclude out of Divi's own admin registration.
 *
 * WHAT THIS PAYLOAD DOES NOT CARRY, AND WHY THE MANIFEST SAYS SO.
 * `serialize_layout()` emits exactly eight keys and `presets` is not among
 * them: every `$data['presets'] = …` in `Portability.php` (`:1477`, `:1564`,
 * `:1578`) is inside `serialize_theme_builder()`, which begins at `:1384`.
 * `export()` obtains presets separately (`:634`) via
 * `get_used_global_presets( $shortcode_object, … )` — but that method is
 * **`protected`** (`:4418`), and on Divi 5 content it returns the empty
 * accumulator regardless: `serialize_layout()` builds `$shortcode_object` with
 * `et_fb_process_shortcode()` (`:1330`), and that function returns its input
 * **string** unchanged when the content holds no `[`
 * (`includes/builder/functions.php:11443-11445`), otherwise runs a D4
 * shortcode regex that matches no Gutenberg block. Both
 * `get_used_global_presets()` (`:4418`) and `_get_used_global_colors()`
 * (`:4365`) open with an `! is_array( $shortcode_object )` guard whose own
 * comment says so: D5 content arrives "as a Gutenberg string format content
 * rather than a shortcode array", so presets are "currently" not processed.
 * The same guard is why `serialize_layout()`'s `global_colors` key is empty on
 * a D5 page.
 *
 * So `presets` is built here instead, from this plugin's own D5-correct
 * scanner: `walk_blocks_for_preset_refs()` (`trait-preset.php`) reads the
 * `modulePreset` and `groupPreset.<slot>.presetId` references out of parsed
 * block attrs, and those uuids select a subset of the live D5 registry read by
 * `get_d5_presets()` (`trait-core.php`). The emitted shape is the D5 registry
 * shape — `{ module: { <name>: { items: { <uuid>: … } } }, group: { … } }` —
 * which is the shape Divi 5 itself puts on the wire: `PortabilityPost::import()`
 * sets `$success['presets'] = GlobalPreset::get_data()`
 * (`includes/builder-5/server/Framework/Portability/PortabilityPost.php:2356`),
 * and `GlobalPreset::process_presets_for_import()`
 * (`Packages/GlobalData/GlobalPreset.php:3082`) auto-detects the incoming
 * preset format before merging.
 *
 * `manifest.artifact_omits` names what this seam cannot produce, because a
 * caller must not read a complete-looking payload as a complete export. Divi
 * 5's own exporter is `PortabilityPost::export()` (`:2642`) and it emits nine
 * keys, adding `global_variables`, `page_settings_meta` and `thumbnails` — but
 * for a `type => 'post'` export it does not compute presets, colors or
 * variables server-side at all: it reads them from request params the Visual
 * Builder's JavaScript prepared client-side (`:2733-2735`). There is no
 * server-side seam that produces those three, which is why they are declared
 * missing rather than silently absent.
 *
 * @package DiviOps
 */

trait DiviOps_Agent_Portability {

	/**
	 * Export one post's Divi layout as a raw portability payload plus manifest.
	 *
	 * @param WP_REST_Request $request REST request carrying `id`.
	 * @return WP_REST_Response
	 */
	public static function page_export( $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Page #{$post_id} not found.",
				'Verify the page id via diviops_page_list.',
				404,
				[ 'page_id' => $post_id ]
			);
		}

		// Row-level gate, same as page_get()/page_get_layout(): the route-level
		// edit_posts check is too coarse for a payload carrying full content and
		// base64 image bytes.
		if ( ! self::can_inspect_post_object( $post ) ) {
			return self::envelope_object_read_forbidden( $post_id, 'page' );
		}

		if ( ! in_array( $post->post_type, self::SCANNABLE_POST_TYPES, true ) ) {
			return self::envelope_error(
				'invalid_input',
				"Post #{$post_id} is a '{$post->post_type}', which carries no exportable Divi layout.",
				'Exportable post types are: ' . implode( ', ', self::SCANNABLE_POST_TYPES ) . '.',
				400,
				[ 'page_id' => $post_id, 'post_type' => $post->post_type ]
			);
		}

		$missing = self::portability_missing_dependencies();
		if ( ! empty( $missing ) ) {
			return self::envelope_error(
				'portability.unavailable',
				'Divi\'s portability system is not available on this site: ' . implode( ', ', $missing ) . ' missing.',
				'Activate Divi (or a Divi version shipping core/components/Portability.php) and retry.',
				503,
				[ 'page_id' => $post_id, 'missing' => $missing ]
			);
		}

		$content = (string) $post->post_content;
		$result  = self::portability_run_serialize_layout( $post_id, $content );

		if ( ! is_array( $result ) || ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
			return self::envelope_error(
				'portability.serialize_failed',
				"Divi's serialize_layout() returned no payload for page #{$post_id}.",
				'Check the site error log for a Divi portability failure.',
				500,
				[ 'page_id' => $post_id ]
			);
		}

		// Backstop, expected unreachable. portability_run_serialize_layout()
		// forces et_core_portability_paginate_images to false, and
		// chunk_images() (Portability.php:3469-3504) only produces chunks > 1
		// on the branch that filter gates. Refusing beats handing back one
		// chunk of a multi-chunk export as though it were whole.
		$ready  = isset( $result['ready'] ) ? $result['ready'] : null;
		$chunks = isset( $result['chunks'] ) ? $result['chunks'] : null;
		if ( true !== $ready || 1 !== (int) $chunks ) {
			return self::envelope_error(
				'portability.partial_export',
				"Divi reported an incomplete export for page #{$post_id}.",
				'Image pagination was disabled for this call, so this should not happen; report it with the ready/chunks values.',
				500,
				[ 'page_id' => $post_id, 'ready' => $ready, 'chunks' => $chunks ]
			);
		}

		$scan                = self::portability_scan_markup( $content );
		$artifact            = $result['data'];
		$artifact['presets'] = self::portability_used_presets( $scan['blocks'] );

		// The artifact leaves here as a STRING, not as an object, and that is the
		// whole design rather than a serialisation detail.
		//
		// The consumer has to write these bytes to a file a Divi import reads, and
		// it has to be able to prove the bytes it wrote are the bytes we hashed. If
		// we returned an object, the consumer would re-serialise it with its own
		// encoder and hash THAT, which loses two ways at once. PHP's json_encode
		// escapes `/` and non-ASCII at flags 0 and JavaScript's JSON.stringify
		// escapes neither, so on an artifact made mostly of URLs the two hashes
		// never agree -- every export would refuse. And `artifact.data` is keyed by
		// post id, which JavaScript's JSON.parse reorders into ascending numeric
		// order while PHP preserves insertion order, so even an identical encoder
		// would drift the moment more than one id is present.
		//
		// Shipping the bytes deletes the entire class: there is no cross-language
		// encoding contract to agree on, because only one side ever encodes.
		$encoded = wp_json_encode( $artifact );
		if ( ! is_string( $encoded ) ) {
			return self::envelope_error(
				'portability.encode_failed',
				sprintf( 'The exported payload for page #%d could not be encoded as JSON.', $post_id ),
				'This is almost always invalid UTF-8 in the page content or in an image filename. json_last_error_msg() names the cause.',
				500,
				[ 'page_id' => $post_id, 'json_error' => json_last_error_msg() ]
			);
		}

		return self::envelope_success( [
			'artifact_json' => $encoded,
			'manifest'      => self::portability_manifest( $post, $content, $artifact, $scan, $encoded ),
		] );
	}

	/**
	 * Names of the Divi portability symbols this route needs and cannot find.
	 *
	 * Returned as a list rather than a bool so the refusal can say which half
	 * is missing — a site with the class but not the loader is a different
	 * problem from a site with no Divi at all.
	 *
	 * @return array<int,string>
	 */
	private static function portability_missing_dependencies(): array {
		$missing = [];
		if ( ! class_exists( 'ET_Core_Portability' ) ) {
			$missing[] = 'ET_Core_Portability';
		}
		if ( ! function_exists( 'et_core_portability_load' ) ) {
			$missing[] = 'et_core_portability_load()';
		}
		if ( ! function_exists( 'et_core_portability_register' ) ) {
			$missing[] = 'et_core_portability_register()';
		}
		return $missing;
	}

	/**
	 * The portability context id this route registers.
	 *
	 * Deliberately not `et_builder`: registering under Divi's own context would
	 * overwrite the cached args Divi's admin set up, pushing this route's empty
	 * include/exclude into an unrelated export.
	 *
	 * A method rather than a class constant because trait constants require PHP
	 * 8.2 and this plugin's floor is 7.4.
	 *
	 * @return string
	 */
	private static function portability_context(): string {
		return 'diviops_page_export';
	}

	/**
	 * Call Divi's `serialize_layout()` with image pagination forced off.
	 *
	 * `add_filter`/`remove_filter` bracket the call in a try/finally so an
	 * exception inside Divi cannot leave `et_core_portability_paginate_images`
	 * pinned to false for every other consumer on the site.
	 *
	 * @param int    $post_id Post id being exported.
	 * @param string $content Raw post_content to serialize.
	 * @return mixed Whatever serialize_layout() returned.
	 */
	private static function portability_run_serialize_layout( int $post_id, string $content ) {
		$context = self::portability_context();

		// Mirrors Portability.php:4688 defaults for a single-post export. An
		// empty include/exclude is what apply_query() (:3442) treats as "no
		// filtering" — both reads there are `! empty()` guarded.
		et_core_portability_register(
			$context,
			[
				'type'    => 'post',
				'view'    => false,
				'include' => [],
				'exclude' => [],
			]
		);

		$portability = et_core_portability_load( $context );

		add_filter( 'et_core_portability_paginate_images', '__return_false' );
		try {
			return $portability->serialize_layout( $context, $post_id, $content );
		} finally {
			remove_filter( 'et_core_portability_paginate_images', '__return_false' );
		}
	}

	/**
	 * The global presets this page's blocks actually reference, as a subset of
	 * the live D5 registry.
	 *
	 * Divi's own `get_used_global_presets()` cannot serve here — see this
	 * file's header for why — so the reference scan is this plugin's
	 * `walk_blocks_for_preset_refs()` and the lookup is `get_d5_presets()`.
	 * Both already back `preset_audit`, so the export cannot disagree with the
	 * audit about which presets a page uses.
	 *
	 * @param array $blocks Flat parse_blocks()-shaped list from portability_scan_markup().
	 * @return array Registry-shaped subset; empty array when nothing is referenced.
	 */
	private static function portability_used_presets( array $blocks ): array {
		$all_uuids  = [];
		$page_uuids = [];
		$ref_count  = 0;
		self::walk_blocks_for_preset_refs( $blocks, $all_uuids, $page_uuids, $ref_count );

		if ( empty( $page_uuids ) ) {
			return [];
		}

		$wanted = array_flip( array_unique( $page_uuids ) );
		$subset = [];
		foreach ( self::collect_d5_preset_audit_entries( self::get_d5_presets() ) as $row ) {
			if ( ! isset( $wanted[ $row['id'] ] ) ) {
				continue;
			}
			$subset[ $row['bucket'] ][ $row['bucket_key'] ]['items'][ $row['id'] ] = $row['entry'];
		}

		return $subset;
	}

	/**
	 * Everything a caller needs about the payload without opening it.
	 *
	 * @param object $post     WP_Post being exported.
	 * @param string $content  Raw post_content.
	 * @param array  $artifact The payload as it will be returned, presets included.
	 * @param array  $scan     portability_scan_markup()'s return.
	 * @return array
	 */
	private static function portability_manifest( $post, string $content, array $artifact, array $scan, string $encoded ): array {
		// $encoded is the caller's own bytes, passed in rather than recomputed.
		// Recomputing here would hash a second encoding of the same array and
		// quietly allow the two to differ; it would also encode a potentially
		// multi-megabyte payload twice per request. What is hashed is exactly
		// what is returned as `artifact_json`, which is what makes the checksum
		// checkable by whoever receives it.

		$images    = self::portability_image_counts( $content, $artifact );
		$colors    = isset( $artifact['global_colors'] ) && is_array( $artifact['global_colors'] )
			? array_values( array_filter( array_keys( $artifact['global_colors'] ), 'is_string' ) )
			: [];
		$presets   = isset( $artifact['presets'] ) && is_array( $artifact['presets'] ) ? $artifact['presets'] : [];

		return [
			'page_id'     => (int) $post->ID,
			'page_title'  => (string) $post->post_title,
			'post_type'   => (string) $post->post_type,
			'byte_length' => strlen( $encoded ),
			'sha256'      => 'sha256:' . hash( 'sha256', $encoded ),
			'images'      => $images,
			'global_colors' => $colors,
			'presets'     => count( self::collect_d5_preset_audit_entries( $presets ) ),
			'attachment_ids' => self::portability_attachment_ids( $artifact ),
			'third_party_namespaces' => $scan['namespaces'],
			// Named, not implied. See this file's header: these three keys exist
			// in Divi 5's own export payload and no server-side seam produces
			// them, so a caller planning a cross-site move knows to carry them
			// another way rather than discovering the gap on the target.
			'artifact_omits' => [ 'global_variables', 'page_settings_meta', 'thumbnails' ],
		];
	}

	/**
	 * Referenced / encoded / skipped image counts.
	 *
	 * `serialize_layout()` returns only the ENCODED map, and `encode_images()`
	 * (`Portability.php:3724-3765`) `continue`s past any image every fetch
	 * method failed on: `_encode_attachment_image()` (`:3776`) returns `''`
	 * when the caller lacks `read_post` or the file is gone, and
	 * `_encode_remote_image()` (`:3810`) returns `''` on a `wp_remote_get()`
	 * that missed its 2-second timeout or came back non-image. A dropped image
	 * therefore vanishes from the payload with no error anywhere.
	 *
	 * The referenced list comes from Divi's own `get_data_images()` (`:3582`),
	 * which is `protected`, so it is reached by reflection rather than
	 * reimplemented — its extraction covers six attribute basenames across
	 * three responsive suffixes plus gallery ids, and a second copy of that
	 * would drift. When the method cannot be reached (a Divi version that
	 * renamed it), `referenced` and `skipped` are reported as `null`: an
	 * unknown count must not render as zero skipped.
	 *
	 * @param string $content  Raw post_content.
	 * @param array  $artifact The payload.
	 * @return array{referenced:int|null,encoded:int,skipped:int|null}
	 */
	private static function portability_image_counts( string $content, array $artifact ): array {
		$encoded = isset( $artifact['images'] ) && is_array( $artifact['images'] ) ? count( $artifact['images'] ) : 0;

		$referenced = self::portability_referenced_image_count( $content );
		if ( null === $referenced ) {
			return [ 'referenced' => null, 'encoded' => $encoded, 'skipped' => null ];
		}

		return [
			'referenced' => $referenced,
			'encoded'    => $encoded,
			'skipped'    => max( 0, $referenced - $encoded ),
		];
	}

	/**
	 * How many distinct images Divi found in this content, or null if unknown.
	 *
	 * @param string $content Raw post_content.
	 * @return int|null
	 */
	private static function portability_referenced_image_count( string $content ): ?int {
		if ( ! class_exists( 'ET_Core_Portability' ) || ! function_exists( 'et_core_portability_load' ) ) {
			return null;
		}
		if ( ! method_exists( 'ET_Core_Portability', 'get_data_images' ) ) {
			return null;
		}

		try {
			$portability = et_core_portability_load( self::portability_context() );
			$method      = new ReflectionMethod( 'ET_Core_Portability', 'get_data_images' );
			if ( PHP_VERSION_ID < 80100 ) {
				$method->setAccessible( true );
			}
			// serialize_layout() passes the same shape: [ $post_id => $content ]
			// after apply_query(), which is a passthrough for an empty
			// include/exclude (Portability.php:3442).
			$images = $method->invoke( $portability, [ 0 => $content ] );
		} catch ( Throwable $e ) {
			return null;
		}

		return is_array( $images ) ? count( $images ) : null;
	}

	/**
	 * Attachment ids the payload carries.
	 *
	 * `encode_images()` attaches `id` to an encoded entry only when it resolved
	 * the url to an attachment (`Portability.php:3758-3761`), so an entry with
	 * no `id` is a remote image with no local post behind it.
	 *
	 * @param array $artifact The payload.
	 * @return array<int,int>
	 */
	private static function portability_attachment_ids( array $artifact ): array {
		if ( ! isset( $artifact['images'] ) || ! is_array( $artifact['images'] ) ) {
			return [];
		}
		$ids = [];
		foreach ( $artifact['images'] as $entry ) {
			$entry = self::normalize_storage_array( $entry );
			if ( null === $entry || ! isset( $entry['id'] ) ) {
				continue;
			}
			$id = (int) $entry['id'];
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		sort( $ids );
		return $ids;
	}

	/**
	 * One pass over the block markup, collecting both things the manifest and
	 * the presets key need from it.
	 *
	 * `namespaces` — block-name namespaces that are neither `divi` nor `core`.
	 * An import target without the plugin behind `difl` or `d5bgo` renders
	 * those blocks as invalid, so the export has to name them.
	 *
	 * `blocks` — a flat `parse_blocks()`-shaped list carrying each opener's
	 * decoded attrs, which is what `walk_blocks_for_preset_refs()` consumes.
	 * Flat rather than nested is equivalent for that walker: it descends only
	 * into `innerBlocks`, and this list already holds every opener in the
	 * document at any depth.
	 *
	 * `parse_blocks()` is NOT used, and that is load-bearing rather than a
	 * preference. It is deliberately unshimmed in `tests/wp-shim.php` because
	 * several suites use "Call to undefined function parse_blocks()" as a
	 * positive probe signal, so a handler reaching it cannot be tested here at
	 * all. The scan instead walks openers with `next_block_opener()` and skips
	 * each one's attribute JSON via `block_opening_comment_end()` — the same
	 * discipline `global_layout_wrapper_identities()` uses, so a `<!-- wp:`
	 * sequence sitting inside another block's attribute string is not read as a
	 * block. No new regex over block markup: this repository has been bitten
	 * repeatedly by bounded patterns over nested, escaped block JSON.
	 *
	 * An opener that cannot be resolved to its own terminator ends the walk and
	 * returns what was found so far. Unlike the write-path drift check, nothing
	 * here can approve a mutation, so a partial answer is safe: naming three of
	 * four namespaces still beats naming none. An opener whose attrs fail to
	 * decode is skipped for the preset scan and still counted for its namespace.
	 *
	 * @param string $content Raw post_content.
	 * @return array{namespaces:array<int,string>,blocks:array<int,array>}
	 */
	private static function portability_scan_markup( string $content ): array {
		$namespaces = [];
		$blocks     = [];
		$offset     = 0;

		while ( null !== ( $opener = self::next_block_opener( $content, $offset ) ) ) {
			$bounds = self::block_opening_comment_end( $content, $opener['pos'] );
			if ( null === $bounds ) {
				break;
			}

			$slash = strpos( $opener['name'], '/' );
			if ( false !== $slash ) {
				$namespace = substr( $opener['name'], 0, $slash );
				if ( 'divi' !== $namespace && 'core' !== $namespace ) {
					$namespaces[ $namespace ] = $namespace;
				}
			}

			$attrs = self::extract_attrs_from_block_markup(
				substr( $content, $opener['pos'], $bounds['comment_end'] - $opener['pos'] )
			);
			if ( is_array( $attrs ) ) {
				$blocks[] = [ 'attrs' => $attrs, 'innerBlocks' => [] ];
			}

			$offset = $bounds['comment_end'];
		}

		$namespaces = array_values( $namespaces );
		sort( $namespaces );

		return [ 'namespaces' => $namespaces, 'blocks' => $blocks ];
	}
}
