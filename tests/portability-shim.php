<?php
// SPDX-License-Identifier: MIT
/**
 * Divi portability stubs for tests/test-page-export.php (#382).
 *
 * A dedicated stub file rather than an extension of tests/wp-shim.php, per
 * CONTRIBUTING.md's shim contract: everything here is additive, guarded by
 * `function_exists`/`class_exists`, and models a PRIMITIVE — Divi's own
 * portability component — never the behaviour under test, which is this
 * plugin's `page_export()`.
 *
 * Every model below is transcribed from the real source read on the reference
 * install at Divi 5.13.1, with the line cited inline. A stub built from what
 * the author believed Divi returns would make the tests pass against a handler
 * that shares the author's mistake, which is the specific failure the contract
 * exists to prevent.
 *
 * Paths cited:
 *   PORT  = wp-content/themes/Divi/core/components/Portability.php
 *   FUNCS = wp-content/themes/Divi/includes/builder/functions.php
 *
 * DRIVING THE STUB. `$GLOBALS['diviops_portability_stub']` is the control
 * surface; `diviops_portability_stub_reset()` returns it to defaults. Keys:
 *
 *   'referenced'      array<int,string>  Image urls get_data_images() reports.
 *   'undecodable'     array<int,string>  Subset of the above that every fetch
 *                                        method fails on, so encode_images()
 *                                        drops them (PORT:3748-3751).
 *   'global_colors'   array              What serialize_layout() puts under
 *                                        'global_colors'.
 *   'force_ready'     bool|null          Override the computed `ready`.
 *   'force_chunks'    int|null           Override the computed `chunks`.
 *
 * And what it RECORDS, for the assertions:
 *
 *   'calls'                     int    serialize_layout() invocations.
 *   'paginate_filter_at_call'   bool   Whether __return_false was on
 *                                      et_core_portability_paginate_images at
 *                                      the moment serialize_layout() ran.
 *   'registered'                array  The args passed to
 *                                      et_core_portability_register().
 *   'last_args'                 array  serialize_layout()'s positional args.
 *
 * @package DiviOps
 */

if ( ! function_exists( 'diviops_portability_stub_reset' ) ) {
	/**
	 * Reset the stub control surface to its defaults.
	 *
	 * @param array $overrides Keys to set after the reset.
	 * @return void
	 */
	function diviops_portability_stub_reset( array $overrides = array() ) {
		$GLOBALS['diviops_portability_stub'] = array_merge(
			array(
				'referenced'              => array(),
				'undecodable'             => array(),
				'global_colors'           => array(),
				'force_ready'             => null,
				'force_chunks'            => null,
				'calls'                   => 0,
				'paginate_filter_at_call' => null,
				'registered'              => null,
				'last_args'               => null,
			),
			$overrides
		);
	}
}

diviops_portability_stub_reset();

// ── WordPress primitives tests/wp-shim.php does not define ────────────────
//
// Each is transcribed from core rather than approximated. add_filter() and
// remove_all_filters() already live in wp-shim.php and key their registry on
// $GLOBALS['diviops_test_hooks']; these read and write that same registry, so
// the four functions behave as one set.

if ( ! function_exists( '__return_false' ) ) {
	/**
	 * Core's `__return_false()` (wp-includes/functions.php): returns false.
	 *
	 * @return false
	 */
	function __return_false() {
		return false;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	/**
	 * Core's `has_filter()` (wp-includes/plugin.php): with no callback, whether
	 * anything is hooked; with one, that callback's priority or false.
	 *
	 * Core returns the integer priority, which is falsy at priority 0 — callers
	 * are expected to compare with `!==  false`. Modelled, not smoothed over.
	 *
	 * @param string $hook     Hook name.
	 * @param mixed  $callback Optional callback to look for.
	 * @return bool|int
	 */
	function has_filter( $hook, $callback = false ) {
		$registered = $GLOBALS['diviops_test_hooks'][ $hook ] ?? array();
		if ( false === $callback ) {
			return ! empty( $registered );
		}
		foreach ( $registered as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( $entry['function'] === $callback ) {
					return (int) $priority;
				}
			}
		}
		return false;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Core's `remove_filter()` (wp-includes/plugin.php): drop one callback from
	 * one hook at one priority, reporting whether it was there.
	 *
	 * @param string $hook     Hook name.
	 * @param mixed  $callback Callback to remove.
	 * @param int    $priority Priority it was registered at.
	 * @return bool
	 */
	function remove_filter( $hook, $callback, $priority = 10 ) {
		$removed = false;
		if ( ! isset( $GLOBALS['diviops_test_hooks'][ $hook ][ $priority ] ) ) {
			return false;
		}
		foreach ( $GLOBALS['diviops_test_hooks'][ $hook ][ $priority ] as $index => $entry ) {
			if ( $entry['function'] === $callback ) {
				unset( $GLOBALS['diviops_test_hooks'][ $hook ][ $priority ][ $index ] );
				$removed = true;
			}
		}
		if ( empty( $GLOBALS['diviops_test_hooks'][ $hook ][ $priority ] ) ) {
			unset( $GLOBALS['diviops_test_hooks'][ $hook ][ $priority ] );
		}
		if ( empty( $GLOBALS['diviops_test_hooks'][ $hook ] ) ) {
			unset( $GLOBALS['diviops_test_hooks'][ $hook ] );
		}
		return $removed;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	/**
	 * Core's `get_post_type()` (wp-includes/post.php): the post's type, or
	 * false when there is no such post. Delegates to wp-shim's get_post().
	 *
	 * @param int $post_id Post id.
	 * @return string|false
	 */
	function get_post_type( $post_id = null ) {
		$post = get_post( $post_id );
		return $post ? $post->post_type : false;
	}
}

// ── Divi's portability component ──────────────────────────────────────────

if ( ! class_exists( 'ET_Core_Portability' ) ) {
	/**
	 * Models the slice of `ET_Core_Portability` that `page_export()` reaches.
	 *
	 * Only `serialize_layout()` and `get_data_images()` are modelled; every
	 * other method Divi ships is deliberately absent, so a handler that started
	 * calling one would fatal here rather than pass against an invented model.
	 */
	class ET_Core_Portability {

		/** @var object|false Cached registration, as PORT:4738 sets it. */
		public $instance;

		/**
		 * PORT:4736 — `$this->instance = et_core_cache_get( $context, 'et_core_portability' );`
		 * which is `false` when no context was registered.
		 *
		 * @param string $context Registered context id.
		 */
		public function __construct( $context ) {
			$this->instance = $GLOBALS['diviops_portability_registry'][ $context ] ?? false;
		}

		/**
		 * Divi's image extractor, PORT:3582. `protected` in the real class,
		 * which is why `portability_referenced_image_count()` reaches it by
		 * reflection; keeping the visibility here is the point of the stub.
		 *
		 * The real body walks the content for six attribute basenames across
		 * three responsive suffixes plus gallery ids and returns a map KEYED BY
		 * URL (`$images[$value] = $value`, PORT:3672). Re-deriving that regex
		 * here would model the behaviour under test rather than the primitive,
		 * so the stub returns the driven list in the same keyed-by-url shape.
		 *
		 * @param array $data  `[ $post_id => $post_content ]`.
		 * @param bool  $force Unused here; part of the real signature.
		 * @return array<string,string>
		 */
		protected function get_data_images( $data, $force = false ) {
			$images = array();
			foreach ( $GLOBALS['diviops_portability_stub']['referenced'] as $url ) {
				$images[ $url ] = $url;
			}
			return $images;
		}

		/**
		 * Divi's `encode_images()`, PORT:3724-3765.
		 *
		 * Returns `[ $url => [ 'encoded' => <base64>, 'url' => $url, 'id' => <int> ] ]`
		 * and `continue`s past any url every fetch method failed on
		 * (PORT:3748-3751) — the silent drop `manifest.images.skipped` exists
		 * to surface. `id` is attached only when the url resolved to an
		 * attachment (PORT:3758-3761), so the stub omits it for a url carrying
		 * no `attachment-<id>` marker.
		 *
		 * @param array $images Urls to encode.
		 * @return array
		 */
		protected function encode_images( $images ) {
			$encoded = array();
			foreach ( $images as $url ) {
				if ( in_array( $url, $GLOBALS['diviops_portability_stub']['undecodable'], true ) ) {
					continue;
				}
				$entry = array(
					'encoded' => base64_encode( 'bytes:' . $url ),
					'url'     => $url,
				);
				if ( preg_match( '/attachment-(\d+)/', $url, $m ) ) {
					$entry['id'] = (int) $m[1];
				}
				$encoded[ $url ] = $entry;
			}
			return $encoded;
		}

		/**
		 * Divi's `chunk_images()`, PORT:3469-3504, transcribed for its control
		 * flow rather than its filesystem effects.
		 *
		 * The real body takes the paginating branch only when
		 * `apply_filters( 'et_core_portability_paginate_images', true )` holds
		 * AND `count( $images ) > 5`; that branch writes temp files. The `else`
		 * branch encodes in one pass. Both return
		 * `[ 'ready' => $chunk + 1 >= $chunks, 'chunks' => $chunks, 'images' => … ]`
		 * (PORT:3499-3503).
		 *
		 * Modelling the real condition — instead of a "paginated: yes/no" knob —
		 * is what gives the filter assertions teeth: a handler that stops
		 * installing `__return_false` gets a genuinely paginated return here,
		 * exactly as it would on a real site with more than five images.
		 *
		 * @param array  $images Urls.
		 * @param string $method Method name applied to the images.
		 * @param string $id     Temp-file id; unused in the stub.
		 * @param int    $chunk  Chunk index.
		 * @return array
		 */
		protected function chunk_images( $images, $method, $id, $chunk = 0 ) {
			$images_per_chunk = 5;
			$chunks           = 1;
			$paginate_images  = apply_filters( 'et_core_portability_paginate_images', true );

			if ( $paginate_images && count( $images ) > $images_per_chunk ) {
				$chunks = (int) ceil( count( $images ) / $images_per_chunk );
				$images = array_slice( $images, $images_per_chunk * $chunk, $images_per_chunk );
				$images = $this->$method( $images );
			} else {
				$images = $this->$method( $images );
			}

			return array(
				'ready'  => $chunk + 1 >= $chunks,
				'chunks' => $chunks,
				'images' => $images,
			);
		}

		/**
		 * Divi's `serialize_layout()`, PORT:1302-1383.
		 *
		 * The eight keys and their sources are transcribed exactly
		 * (PORT:1350-1359). Note what is NOT here: there is no `presets` key —
		 * every `$data['presets'] = …` in the real file (PORT:1477, :1564,
		 * :1578) lives in `serialize_theme_builder()`, which starts at
		 * PORT:1384. `global_colors` comes from
		 * `get_theme_builder_library_used_global_colors( $shortcode_object )`
		 * (PORT:1329), whose `_get_used_global_colors()` (PORT:4365) returns the
		 * empty accumulator for D5 content because
		 * `et_fb_process_shortcode()` hands it a string, not an array
		 * (FUNCS:11443-11445). The stub therefore defaults `global_colors` to
		 * empty and lets a test drive it, rather than inventing colours Divi
		 * would not have produced.
		 *
		 * @param string $id                 Unique serialization id.
		 * @param int    $post_id            Post being serialized.
		 * @param string $content            Raw post content.
		 * @param array  $theme_builder_meta Theme-builder metadata.
		 * @param int    $chunk              Chunk index.
		 * @return array{ready:bool,chunks:int,data:array}
		 */
		public function serialize_layout( $id, $post_id, $content, $theme_builder_meta = array(), $chunk = 0 ) {
			$stub = &$GLOBALS['diviops_portability_stub'];

			++$stub['calls'];
			$stub['last_args']               = array( $id, $post_id, $content, $theme_builder_meta, $chunk );
			$stub['paginate_filter_at_call'] = false !== has_filter( 'et_core_portability_paginate_images', '__return_false' );

			$data   = $this->apply_query( array( $post_id => $content ), 'set' );
			$images = $this->chunk_images( $this->get_data_images( $data ), 'encode_images', $id, $chunk );

			$payload = array(
				'context'       => 'et_builder',
				'data'          => $data,
				'images'        => $images['images'],
				'post_title'    => get_post_field( 'post_title', $post_id ),
				'post_type'     => get_post_type( $post_id ),
				'theme_builder' => $theme_builder_meta,
				'global_colors' => $stub['global_colors'],
				'canvases'      => array(),
			);

			return array(
				'ready'  => null === $stub['force_ready'] ? $images['ready'] : $stub['force_ready'],
				'chunks' => null === $stub['force_chunks'] ? $images['chunks'] : $stub['force_chunks'],
				'data'   => $payload,
			);
		}

		/**
		 * Divi's `apply_query()`, PORT:3442-3453. Both reads are `! empty()`
		 * guarded, so an empty include/exclude is a passthrough — which is the
		 * property `portability_run_serialize_layout()` relies on when it
		 * registers its own context with both empty.
		 *
		 * @param array  $data   Data keyed by id.
		 * @param string $method 'set' or 'unset'.
		 * @return array
		 */
		protected function apply_query( $data, $method ) {
			$operator = ( 'set' === $method ) ? true : false;
			foreach ( $data as $id => $value ) {
				if ( ! empty( $this->instance->exclude ) && isset( $this->instance->exclude[ $id ] ) === $operator ) {
					unset( $data[ $id ] );
				}
				if ( ! empty( $this->instance->include ) && isset( $this->instance->include[ $id ] ) === ! $operator ) {
					unset( $data[ $id ] );
				}
			}
			return $data;
		}
	}
}

if ( ! function_exists( 'et_core_portability_register' ) ) {
	/**
	 * Divi's `et_core_portability_register()`, PORT:4688-4716: merge the
	 * defaults, apply `et_core_portability_args_{$context}`, cache the result.
	 *
	 * The real function then calls `et_core_portability_load()` when
	 * `$data->view` is true; this route always registers `view => false`, and
	 * the stub keeps that branch so a handler that started passing `view: true`
	 * would show up as a second load rather than passing silently.
	 *
	 * @param string $context Context id.
	 * @param array  $args    Registration args.
	 * @return void
	 */
	function et_core_portability_register( $context, $args ) {
		$defaults = array(
			'context' => $context,
			'title'   => 'Portability',
			'name'    => false,
			'view'    => false,
			'type'    => false,
			'target'  => false,
			'include' => array(),
			'exclude' => array(),
		);

		$data = apply_filters( "et_core_portability_args_{$context}", (object) array_merge( $defaults, (array) $args ) );

		$GLOBALS['diviops_portability_registry'][ $context ]   = $data;
		$GLOBALS['diviops_portability_stub']['registered']     = (array) $data;

		if ( $data->view ) {
			et_core_portability_load( $context );
		}
	}
}

if ( ! function_exists( 'et_core_portability_load' ) ) {
	/**
	 * Divi's `et_core_portability_load()`, PORT:4730-4732: `new
	 * ET_Core_Portability( $context )`, nothing more.
	 *
	 * @param string $context Context id.
	 * @return ET_Core_Portability
	 */
	function et_core_portability_load( $context ) {
		return new ET_Core_Portability( $context );
	}
}
