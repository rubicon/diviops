<?php
// SPDX-License-Identifier: MIT
/**
 * A posts-aware `$wpdb` for the expected_checksum suite (#391).
 *
 * `tests/wp-shim.php`'s `DiviOps_Test_wpdb` models the options table and
 * nothing else: it exposes no `posts` property and no `get_var()`. Every
 * caller of `$wpdb->posts` in this plugin therefore takes its
 * primitive-absent branch under the harness, which is a legitimate runtime
 * shape and is why `trait-canvas.php` and `trait-preset.php` guard the same
 * way. `page_content_read_uncached()` cannot be exercised at all through that
 * shape, so this file supplies the primitive rather than widening the shared
 * shim (see CONTRIBUTING.md, "The shim contract").
 *
 * It is a decorator, not a replacement: every method and property it does not
 * model forwards to the shim's own `$wpdb`, so nothing this file installs
 * changes what the options-table model does. It models exactly one query
 * shape and throws on any other, because a fake that answered an unmodelled
 * shape would return a plausible checksum and take the test green on broken
 * code.
 *
 * `$forced` is the point of the whole file. The pre-write check exists because
 * `get_post()` may hand back a request-local cached object after another
 * request has committed an edit, so the only way to characterize it is a
 * store whose bytes differ from the cached post object's. Setting
 * `$forced[$id]` is that divergence, and it is what a concurrent editor looks
 * like from inside the handler.
 *
 * Install with diviops_pcw_install() and undo with diviops_pcw_restore(). The
 * runner shares one process across every file, and leaving a `$wpdb` carrying
 * `posts` and `get_var()` installed would flip the guarded branch in
 * `preset_scan_orphans()` and `canvas_orphan_audit()` for every file that runs
 * after this one.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

if ( ! class_exists( 'DiviOps_PageChecksum_Wpdb' ) ) {
	/**
	 * Decorator adding `posts` and a single SELECT shape to the shim's $wpdb.
	 */
	final class DiviOps_PageChecksum_Wpdb {

		/** @var string Posts table name, matching $wpdb->posts. */
		public $posts = 'wp_posts';

		/**
		 * Post ids whose stored bytes differ from the cached post object.
		 *
		 * @var array<int, string>
		 */
		public $forced = array();

		/** @var array<int, string> Every query this decorator has executed. */
		public $queries = array();

		/** @var object The shim's own $wpdb. */
		private $inner;

		/**
		 * @param object $inner The shim's own $wpdb.
		 */
		public function __construct( $inner ) {
			$this->inner = $inner;
		}

		/**
		 * Forward every unmodelled property read to the shim's $wpdb.
		 *
		 * @param string $name Property name.
		 * @return mixed
		 */
		public function __get( $name ) {
			return $this->inner->$name ?? null;
		}

		/**
		 * Forward every unmodelled method call to the shim's $wpdb.
		 *
		 * @param string $name Method name.
		 * @param array  $args Arguments.
		 * @return mixed
		 */
		public function __call( $name, $args ) {
			return $this->inner->$name( ...$args );
		}

		/**
		 * Declared rather than forwarded through __call, because the plugin
		 * gates on method_exists() and that returns false for a magic method.
		 *
		 * @param string $query Query with placeholders.
		 * @param mixed  ...$args Placeholder values.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			return $this->inner->prepare( $query, ...$args );
		}

		/**
		 * Model the one SELECT `page_content_read_uncached()` issues.
		 *
		 * @param string $query Prepared SQL.
		 * @return string|null post_content, or null when no row matches.
		 * @throws RuntimeException On any other query shape.
		 */
		public function get_var( $query ) {
			$this->queries[] = (string) $query;

			$pattern = '/^\s*SELECT\s+post_content\s+FROM\s+wp_posts\s+WHERE\s+ID\s*=\s*(?P<id>\d+)\s+LIMIT\s+1\s*$/is';
			if ( ! preg_match( $pattern, (string) $query, $matches ) ) {
				throw new RuntimeException( 'DiviOps_PageChecksum_Wpdb cannot execute this query shape: ' . $query );
			}

			$post_id = (int) $matches['id'];
			if ( array_key_exists( $post_id, $this->forced ) ) {
				return $this->forced[ $post_id ];
			}
			$post = $GLOBALS['diviops_test_posts'][ $post_id ] ?? null;
			return null === $post ? null : (string) $post->post_content;
		}
	}
}

if ( ! function_exists( 'diviops_pcw_install' ) ) {
	/**
	 * Install the decorator over the shim's $wpdb and return it.
	 *
	 * @return DiviOps_PageChecksum_Wpdb
	 */
	function diviops_pcw_install(): DiviOps_PageChecksum_Wpdb {
		$GLOBALS['diviops_pcw_saved_wpdb'] = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']                   = new DiviOps_PageChecksum_Wpdb( $GLOBALS['diviops_pcw_saved_wpdb'] );
		return $GLOBALS['wpdb'];
	}

	/**
	 * Put the shim's own $wpdb back.
	 */
	function diviops_pcw_restore(): void {
		$GLOBALS['wpdb'] = $GLOBALS['diviops_pcw_saved_wpdb'];
		unset( $GLOBALS['diviops_pcw_saved_wpdb'] );
	}
}
