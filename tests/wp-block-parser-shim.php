<?php
// SPDX-License-Identifier: MIT
/**
 * Loads WordPress core's own block parser so block-parsing handlers can be
 * driven end to end (#598).
 *
 * `parse_blocks()` is deliberately absent from `tests/wp-shim.php` and must stay
 * absent: five suites use `Call to undefined function parse_blocks()` as a
 * positive probe signal that execution reached a given point, and
 * `tests/test-page-block-insert.php` says outright that `page_block_insert`
 * cannot be driven end to end for that reason. CONTRIBUTING.md's shim contract
 * forbids a characterization suite widening the shared shim, and names a
 * dedicated stub file as the vehicle instead — which is what this is. Requiring
 * it is opt-in per test file, and `tests/run.php` gives every file its own PHP
 * process, so a file that does not require this one cannot see `parse_blocks()`
 * however `DIVIOPS_WP_SOURCE` is set.
 *
 * The parser here IS core's, loaded from disk, never a reimplementation. That is
 * the whole point: this repository has been bitten six times (#7, #10, #18, #548,
 * #555, #578) by scanners that approximated WordPress's block grammar — the
 * tempered terminator, self-closing openers, attribute JSON containing `-->`. A
 * shim parser kinder or stricter than core would make assertions that pass
 * against a parser no site runs, which is how #591's defect class stayed
 * invisible. `wp-includes/class-wp-block-parser.php` requires its own two
 * companion classes (at its lines 397 and 404 on WordPress 7.1), and calls no
 * WordPress function at all — every bare call in it is a PHP built-in or one of
 * its own methods — so it loads against this harness with nothing approximated
 * around it.
 *
 * `parse_blocks()` and `serialize_blocks()` are one-line and three-line wrappers
 * respectively, and are the only things transcribed here.
 * `tests/test-wp-source-block-parser.php` reads core's own `blocks.php` and
 * asserts both transcriptions still match it, so a copy cannot drift from the
 * original while this file keeps claiming it has not.
 *
 * `serialize_blocks()` is here for the same reason as `parse_blocks()`: it is
 * absent from the shared shim (which defines only the singular
 * `serialize_block()`), because nothing could reach it while the parse half was
 * missing. A write handler needs both — `page_block_insert()` parses, mutates
 * the tree, then serializes it back at `trait-page.php:1389` — so loading one
 * without the other just moves the fatal down a few lines.
 *
 * Point `DIVIOPS_WP_SOURCE` at a WordPress root — the directory holding
 * `wp-includes/`. Absent or unusable, nothing is defined and the requiring suite
 * is expected to SKIP with a counted reason rather than pass vacuously, the same
 * way `DIVIOPS_DIVI_BUILDER5_PATH` works in
 * `tests/test-shared-preset-attrs-map.php`.
 *
 * @package DiviOps
 */

declare( strict_types = 1 );

require_once __DIR__ . '/wp-shim.php';

/**
 * Compose the path to core's block parser inside a WordPress root.
 *
 * Pure string work, with no filesystem access, so the composition is testable
 * on a machine that has no WordPress at all. An empty root yields an empty
 * string rather than a path rooted at `/`, because `getenv()` returns `''` for
 * an unset variable and `'/wp-includes/...'` is a real path on some machines.
 *
 * @param string $root WordPress root directory, with or without a trailing slash.
 * @return string Absolute path to the parser file, or '' for an empty root.
 */
function diviops_wpbp_parser_file( string $root ): string {
	$trimmed = rtrim( trim( $root ), '/' );
	if ( '' === $trimmed ) {
		return '';
	}
	return $trimmed . '/wp-includes/class-wp-block-parser.php';
}

/**
 * Resolve a usable WordPress root, or '' when there is not one.
 *
 * Verdicts, all reachable from the fixture half of the suite: an empty or
 * non-string value, a root whose composed parser path is not a readable file
 * (which covers both "no such directory" and "a WordPress too old to carry the
 * file"), and a root that does carry it.
 *
 * @param mixed $raw Candidate root, typically straight from getenv().
 * @return string The root with any trailing slash removed, or '' when unusable.
 */
function diviops_wpbp_resolve( $raw ): string {
	if ( ! is_string( $raw ) ) {
		return '';
	}
	$file = diviops_wpbp_parser_file( $raw );
	if ( '' === $file || ! is_file( $file ) ) {
		return '';
	}
	return rtrim( trim( $raw ), '/' );
}

if ( ! defined( 'DIVIOPS_WPBP_ROOT' ) ) {
	$diviops_wpbp_root = diviops_wpbp_resolve( getenv( 'DIVIOPS_WP_SOURCE' ) );

	/**
	 * The WordPress root whose parser was loaded, or '' when none was available.
	 *
	 * A requiring suite branches on this, and reports the '' case as a counted
	 * SKIP. A constant rather than a function so the value cannot be recomputed
	 * mid-run into a different answer than the one the load acted on.
	 */
	define( 'DIVIOPS_WPBP_ROOT', $diviops_wpbp_root );

	if ( '' !== $diviops_wpbp_root ) {
		require_once diviops_wpbp_parser_file( $diviops_wpbp_root );

		if ( ! function_exists( 'parse_blocks' ) ) {
			/**
			 * Transcribed from core's `parse_blocks()` (wp-includes/blocks.php),
			 * the only thing in this file not loaded from core. Kept honest by
			 * the transcription assertion in
			 * tests/test-wp-source-block-parser.php, which reads the function out
			 * of the loaded core's own blocks.php and compares it against this
			 * body rather than trusting a line number written here.
			 *
			 * The `block_parser_class` filter is reproduced because Divi 5 is what
			 * uses it: `parse_blocks_for_write()` (trait-core.php) exists
			 * precisely because Divi registers its own parser through it. The
			 * shim's apply_filters() is a real hook dispatcher, so a suite can
			 * exercise that seam.
			 *
			 * @param string $content Block markup.
			 * @return array Parsed block tree.
			 */
			function parse_blocks( $content ) {
				$parser_class = apply_filters( 'block_parser_class', 'WP_Block_Parser' );

				$parser = new $parser_class();
				return $parser->parse( $content );
			}
		}

		if ( ! function_exists( 'serialize_blocks' ) ) {
			/**
			 * Transcribed from core's `serialize_blocks()`
			 * (wp-includes/blocks.php), and likewise kept honest by the
			 * transcription assertion in
			 * tests/test-wp-source-block-parser.php.
			 *
			 * It delegates to the shared shim's `serialize_block()`, which is a
			 * reimplementation rather than core's own — core defines the
			 * singular in `blocks.php`, which cannot be loaded standalone. That
			 * reimplementation has its own contract coverage in
			 * `tests/test-wp-shim-*-contract.php`; this wrapper adds nothing to
			 * it beyond the loop.
			 *
			 * @param array $blocks Parsed block tree.
			 * @return string Serialized block markup.
			 */
			function serialize_blocks( $blocks ) {
				return implode( '', array_map( 'serialize_block', $blocks ) );
			}
		}
	}

	unset( $diviops_wpbp_root );
}
