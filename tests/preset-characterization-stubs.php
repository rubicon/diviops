<?php
// SPDX-License-Identifier: MIT
/**
 * Dedicated stubs for tests/test-preset-characterization.php (#328).
 *
 * `tests/wp-shim.php` is off limits for this work — several agents are editing
 * it concurrently, and an agent that widens the shared harness to make its own
 * test pass produces a false green that outlives the test. Everything this file
 * declares is therefore additive, guarded, and named for this suite.
 *
 * Two things are missing from the shim that `trait-preset.php` needs, and both
 * are WordPress-core primitives rather than Divi storage — nothing here fakes a
 * behaviour under test:
 *
 *   1. `wp_generate_password()`. `preset_registry_doctor()` uses it for the
 *      suffix of its pre-mutation backup option name, so without it the whole
 *      repair-and-write path is unreachable. Modelled on WP core's own
 *      definition in `wp-includes/pluggable.php` (`wp_generate_password()`,
 *      line 2964 in the 6.x tree on this machine): the base alphabet is
 *      `a-zA-Z0-9`, `$special_chars` appends `!@#$%^&*()`, and
 *      `$extra_special_chars` appends the punctuation set. The doctor calls it
 *      as `wp_generate_password( 8, false, false )`, so only the base alphabet
 *      is ever exercised here. `random_int()` stands in for core's `wp_rand()`
 *      — the property that matters to the caller is "N characters from that
 *      alphabet, different each call", not the specific PRNG.
 *
 *   2. A `$wpdb` that answers `get_col()`. The shim's `DiviOps_Test_wpdb` is
 *      `final` and exposes `get_results()` only, so
 *      `preset_registry_chunk_transients()` takes its
 *      `! method_exists( $wpdb, 'get_col' )` early return and reports zero
 *      chunk transients no matter what the options table holds — which would
 *      leave the doctor's chunk-cleanup coupling gate structurally
 *      unobservable. `DiviOps_Preset_Characterization_Wpdb` composes the real
 *      shim object rather than replacing it: `esc_like()`, `prepare()` and
 *      `get_results()` delegate untouched, so the SELECT is still executed by
 *      the shim's own LIKE/ORDER BY/LIMIT engine, and only `get_col()` is new.
 *      It models WP core's `wpdb::get_col( $query, $x = 0 )` — the $x'th column
 *      of every result row, as a flat list.
 *
 * The suite installs the wrapper around one section and restores the original
 * object afterwards, so no other test file sees a different `$wpdb`.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * Model WP core's wp_generate_password().
	 *
	 * @param int  $length              Password length.
	 * @param bool $special_chars       Append the standard special characters.
	 * @param bool $extra_special_chars Append the salt/key punctuation set.
	 * @return string
	 */
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		if ( $special_chars ) {
			$chars .= '!@#$%^&*()';
		}
		if ( $extra_special_chars ) {
			$chars .= '-_ []{}<>~`+=,.;:/?|';
		}
		$password = '';
		for ( $index = 0; $index < (int) $length; $index++ ) {
			$password .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $password;
	}
}

if ( ! class_exists( 'DiviOps_Preset_Characterization_Wpdb' ) ) {
	/**
	 * `$wpdb` decorator that adds `get_col()` on top of the shim's object.
	 *
	 * Composition, not replacement: every query still runs through the shim's
	 * `get_results()`, so the LIKE-escape, ORDER BY and LIMIT semantics the
	 * chunk-transient scan depends on are the shim's, not a second opinion.
	 */
	final class DiviOps_Preset_Characterization_Wpdb {

		/** @var string Options table name, mirrored from the wrapped object. */
		public $options;

		/** @var object The shim's own $wpdb. */
		private $inner;

		/**
		 * @param object $inner The shim's $wpdb instance.
		 */
		public function __construct( $inner ) {
			$this->inner   = $inner;
			$this->options = $inner->options;
		}

		/**
		 * Delegate LIKE escaping.
		 *
		 * @param string $text Raw text.
		 * @return string
		 */
		public function esc_like( $text ) {
			return $this->inner->esc_like( $text );
		}

		/**
		 * Delegate placeholder interpolation.
		 *
		 * @param string $query Query with %s/%d placeholders.
		 * @param mixed  ...$args Values.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			return $this->inner->prepare( $query, ...$args );
		}

		/**
		 * Delegate row selection.
		 *
		 * @param string $query Prepared SQL.
		 * @return array<int, stdClass>
		 */
		public function get_results( $query ) {
			return $this->inner->get_results( $query );
		}

		/**
		 * Model WP core's wpdb::get_col(): one column of the result set as a
		 * flat list.
		 *
		 * One normalisation before delegating: the shim's SELECT grammar
		 * requires an explicit `ASC`/`DESC` after `ORDER BY`, and
		 * `preset_registry_chunk_transients()` emits `ORDER BY option_name`
		 * with no direction — a shape the shim throws on rather than sorts. An
		 * omitted direction is ASC in ANSI SQL and in MySQL, and the shim's own
		 * executor already defaults `$direction` to ASC once it has parsed the
		 * clause; it simply cannot parse the elided form. Spelling the default
		 * out is therefore a no-op on meaning, and it keeps the LIKE-escape,
		 * ordering and LIMIT semantics in the shim rather than reimplemented
		 * here.
		 *
		 * @param string $query Prepared SQL.
		 * @param int    $x     Zero-based column offset.
		 * @return array<int, mixed>
		 */
		public function get_col( $query, $x = 0 ) {
			$query = (string) preg_replace(
				'/(ORDER\s+BY\s+(?:option_id|option_name))(?!\s+(?:ASC|DESC)\b)/i',
				'$1 ASC',
				(string) $query
			);
			$out = array();
			foreach ( $this->get_results( $query ) as $row ) {
				$values = array_values( get_object_vars( $row ) );
				$out[]  = $values[ (int) $x ] ?? null;
			}
			return $out;
		}
	}
}
