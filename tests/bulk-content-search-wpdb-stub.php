<?php
// SPDX-License-Identifier: MIT
/**
 * A posts-aware `$wpdb` for the content_search suite (#494).
 *
 * `tests/wp-shim.php`'s `DiviOps_Test_wpdb` models the options table and
 * nothing else: no `posts` property, no `get_col()`. `content_search` cannot
 * be exercised at all through that shape — `bulk_search_candidate_ids()`
 * takes its primitive-absent branch and returns `unsupported` — so this file
 * supplies the primitive rather than widening the shared shim (CONTRIBUTING.md,
 * "The shim contract"). It follows `tests/page-checksum-wpdb-stub.php`, which
 * solved the same problem for `page_content_read_uncached()`.
 *
 * It is a decorator, not a replacement: everything it does not model forwards
 * to the shim's own `$wpdb`, so the options-table model is untouched.
 *
 * ── Why it executes the query instead of returning a canned list ─────────
 *
 * The behaviour under test *is* the query: which post types and statuses it
 * restricts to, that it ORs two `LIKE` needles rather than one, and that it
 * selects one row beyond the ceiling so `truncated` is a fact rather than an
 * inference. A fake that pattern-matched the caller and handed back a fixed
 * id list would report PASS whether the production query searched one needle
 * or two — which is the single defect this phase exists to avoid, since the
 * escaped needle is what finds Divi's attribute text at all.
 *
 * So it parses the real SQL and evaluates it against the shim's post store,
 * honouring the `IN` lists, both `LIKE` patterns including `esc_like()`'s
 * backslash escapes, and the `LIMIT`. Any other shape throws.
 *
 * Install with diviops_bcs_install() and undo with diviops_bcs_restore(). The
 * runner shares one process across every file, and leaving a `$wpdb` carrying
 * `posts` and `get_col()` installed would flip the guarded branch in
 * `preset_scan_orphans()` and `canvas_orphan_audit()` for every file after
 * this one.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

if ( ! class_exists( 'DiviOps_BulkContentSearch_Wpdb' ) ) {
	/**
	 * Decorator adding `posts` and one SELECT shape to the shim's $wpdb.
	 */
	final class DiviOps_BulkContentSearch_Wpdb {

		/** @var string Posts table name, matching $wpdb->posts. */
		public $posts = 'wp_posts';

		/** @var array<int, string> Every query this decorator has executed. */
		public $queries = array();

		/**
		 * Post ids this decorator returns as candidates regardless of content.
		 *
		 * Models one real primitive it otherwise cannot: MySQL's default
		 * `utf8mb4_unicode_ci` collation makes `LIKE` case- and
		 * accent-insensitive, while PHP's `strpos` is neither. A live site
		 * therefore hands the handler candidate rows whose bytes its own
		 * confirmation scan cannot find. This decorator's LIKE is byte-exact
		 * — deliberately, because a case-insensitive fake would be modelling
		 * MySQL rather than the handler — so without this seam the handler's
		 * branch for that case is unreachable and any test of it would pass
		 * by never having produced a candidate at all.
		 *
		 * Same role as `$forced` in tests/page-checksum-wpdb-stub.php.
		 *
		 * @var array<int, int>
		 */
		public $extra_ids = array();

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
		 * Declared rather than forwarded through __call, because callers gate
		 * on method_exists() and that returns false for a magic method.
		 *
		 * @param string $query Query with placeholders.
		 * @param mixed  ...$args Placeholder values.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			return $this->inner->prepare( $query, ...$args );
		}

		/**
		 * Declared for the same reason as prepare().
		 *
		 * @param string $text Text to escape.
		 * @return string
		 */
		public function esc_like( $text ) {
			return $this->inner->esc_like( $text );
		}

		/**
		 * Evaluate the one SELECT `bulk_search_candidate_ids()` issues.
		 *
		 * @param string $query Prepared SQL.
		 * @return array<int, string> Matching post ids, as strings, as $wpdb returns.
		 * @throws RuntimeException On any other query shape.
		 */
		public function get_col( $query ) {
			$this->queries[] = (string) $query;

			$pattern = '/^\s*SELECT\s+ID\s+FROM\s+wp_posts\s+'
				. 'WHERE\s+post_type\s+IN\s*\((?P<types>[^)]*)\)\s+'
				. 'AND\s+post_status\s+IN\s*\((?P<statuses>[^)]*)\)\s+'
				. 'AND\s*\((?P<likes>.*?)\)\s+'
				. 'ORDER\s+BY\s+ID\s+ASC\s+'
				. 'LIMIT\s+(?P<limit>\d+)\s*$/is';
			if ( ! preg_match( $pattern, (string) $query, $matches ) ) {
				throw new RuntimeException( 'DiviOps_BulkContentSearch_Wpdb cannot execute this query shape: ' . $query );
			}

			$types    = self::sql_string_list( $matches['types'] );
			$statuses = self::sql_string_list( $matches['statuses'] );

			$likes = array();
			if ( preg_match_all( "/post_content\s+LIKE\s+'((?:[^']|'')*)'/i", $matches['likes'], $like_matches ) ) {
				foreach ( $like_matches[1] as $raw ) {
					$likes[] = str_replace( "''", "'", $raw );
				}
			}
			if ( array() === $likes ) {
				throw new RuntimeException( 'DiviOps_BulkContentSearch_Wpdb found no LIKE clause in: ' . $query );
			}

			$ids = array();
			foreach ( $GLOBALS['diviops_test_posts'] as $post_id => $post ) {
				if ( ! in_array( (string) $post->post_type, $types, true ) ) {
					continue;
				}
				if ( ! in_array( (string) $post->post_status, $statuses, true ) ) {
					continue;
				}
				foreach ( $likes as $like ) {
					if ( preg_match( self::like_to_regex( $like ), (string) $post->post_content ) ) {
						$ids[] = (int) $post_id;
						break;
					}
				}
			}

			foreach ( $this->extra_ids as $extra ) {
				if ( ! in_array( (int) $extra, $ids, true ) ) {
					$ids[] = (int) $extra;
				}
			}

			sort( $ids );
			$ids = array_slice( $ids, 0, (int) $matches['limit'] );

			return array_map( 'strval', $ids );
		}

		/**
		 * Split a quoted SQL string list into its values.
		 *
		 * @param string $list The text between the IN parentheses.
		 * @return array<int, string>
		 */
		private static function sql_string_list( string $list ): array {
			$values = array();
			if ( preg_match_all( "/'((?:[^']|'')*)'/", $list, $matches ) ) {
				foreach ( $matches[1] as $raw ) {
					$values[] = str_replace( "''", "'", $raw );
				}
			}
			return $values;
		}

		/**
		 * Translate a SQL LIKE pattern into an unanchored regex, honouring
		 * esc_like()'s backslash escapes so an escaped `%` matches a literal
		 * one. Mirrors DiviOps_Test_wpdb::like_to_regex(), which anchors
		 * because option names match whole; this one is fed `%needle%` and so
		 * anchors through the leading and trailing wildcards instead.
		 *
		 * @param string $like LIKE pattern.
		 * @return string
		 */
		private static function like_to_regex( string $like ): string {
			$regex  = '';
			$length = strlen( $like );
			for ( $index = 0; $index < $length; $index++ ) {
				$character = $like[ $index ];
				if ( '\\' === $character && $index + 1 < $length ) {
					++$index;
					$regex .= preg_quote( $like[ $index ], '/' );
					continue;
				}
				if ( '%' === $character ) {
					$regex .= '.*';
					continue;
				}
				if ( '_' === $character ) {
					$regex .= '.';
					continue;
				}
				$regex .= preg_quote( $character, '/' );
			}
			return '/^' . $regex . '$/s';
		}
	}
}

if ( ! function_exists( 'diviops_bcs_install' ) ) {
	/**
	 * Install the decorator over the shim's $wpdb and return it.
	 *
	 * @return DiviOps_BulkContentSearch_Wpdb
	 */
	function diviops_bcs_install(): DiviOps_BulkContentSearch_Wpdb {
		$GLOBALS['diviops_bcs_saved_wpdb'] = $GLOBALS['wpdb'];
		$GLOBALS['wpdb']                   = new DiviOps_BulkContentSearch_Wpdb( $GLOBALS['diviops_bcs_saved_wpdb'] );
		return $GLOBALS['wpdb'];
	}

	/**
	 * Put the shim's own $wpdb back.
	 */
	function diviops_bcs_restore(): void {
		$GLOBALS['wpdb'] = $GLOBALS['diviops_bcs_saved_wpdb'];
		unset( $GLOBALS['diviops_bcs_saved_wpdb'] );
	}
}
