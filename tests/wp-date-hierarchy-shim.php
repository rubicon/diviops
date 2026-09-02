<?php
// SPDX-License-Identifier: MIT
/**
 * Two WordPress-core primitives `tests/wp-shim.php` does not define, needed by
 * `tests/test-page-characterization.php`.
 *
 * This file exists rather than an edit to `wp-shim.php` on purpose: the shared
 * harness was being edited concurrently when this suite was written, and a test
 * that bends the shared harness to make itself pass produces a false green that
 * outlives the test. Everything here is transcribed from WordPress core, with the
 * source line cited, so a reader can check the model rather than trust it. Nothing
 * here models plugin behaviour.
 *
 * Both are unconditionally reachable in `trait-page.php` and are not guarded by
 * `function_exists()` at the call site, so without them PHP fatals and takes the
 * whole run down rather than failing one assertion:
 *
 *   - `is_post_type_hierarchical()` — `page_update_meta()`'s `parent > 0` branch.
 *   - `get_date_from_gmt()` — `page_update_status()`'s `status=future` branch,
 *     and its "clear a stale future date" branch on re-publish.
 *
 * `wp_check_post_hierarchy_for_loops()` is deliberately NOT defined here.
 * `page_update_meta()` calls it behind `function_exists()`, so leaving it absent
 * is the shape a real (if unusual) runtime can have, and the characterization
 * below pins the handler's behaviour with that branch skipped rather than
 * inventing a loop detector this harness cannot check.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

if ( ! function_exists( 'get_post_type_object' ) ) {
	/**
	 * Model WP core's get_post_type_object(): the registered arguments for a post
	 * type as an object, null when the type is not registered
	 * (wp-includes/post.php:1657-1665). Reads the same registry
	 * `diviops_test_register_post_type()` writes.
	 *
	 * Only the flags a test actually registers are present. Core's WP_Post_Type
	 * fills every unset property from its own defaults; the only one read here is
	 * `hierarchical`, which core defaults to false
	 * (wp-includes/class-wp-post-type.php `set_props()` defaults), so the
	 * accessor below applies that default rather than this function inventing a
	 * complete post-type object.
	 *
	 * @param string $post_type Post type name.
	 * @return object|null
	 */
	function get_post_type_object( $post_type ) {
		$args = $GLOBALS['diviops_test_post_types'][ (string) $post_type ] ?? null;
		return null === $args ? null : (object) $args;
	}
}

if ( ! function_exists( 'is_post_type_hierarchical' ) ) {
	/**
	 * WP core, verbatim in structure (wp-includes/post.php:1599-1606): an
	 * unregistered type is false, otherwise the registered `hierarchical` flag.
	 * The `?? false` stands in for WP_Post_Type's own default for a type
	 * registered without the argument, which is what the harness registry holds
	 * for every built-in type it seeds.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	function is_post_type_hierarchical( $post_type ) {
		if ( ! post_type_exists( $post_type ) ) {
			return false;
		}
		$object = get_post_type_object( $post_type );
		return (bool) ( $object->hierarchical ?? false );
	}
}

if ( ! function_exists( 'wp_timezone_string' ) ) {
	/**
	 * WP core, verbatim (wp-includes/functions.php:124-141): the `timezone_string`
	 * option when set, otherwise `gmt_offset` rendered as a `±hh:mm` offset. Both
	 * options come from the harness option store, so a test steers the site
	 * timezone the same way a site owner does.
	 *
	 * A fresh WordPress install has neither option set, which lands on `+00:00` —
	 * a UTC site, which is what this harness is by default.
	 *
	 * @return string
	 */
	function wp_timezone_string() {
		$timezone_string = get_option( 'timezone_string' );

		if ( $timezone_string ) {
			return $timezone_string;
		}

		$offset  = (float) get_option( 'gmt_offset' );
		$hours   = (int) $offset;
		$minutes = ( $offset - $hours );

		$sign      = ( $offset < 0 ) ? '-' : '+';
		$abs_hour  = abs( $hours );
		$abs_mins  = abs( $minutes * 60 );
		$tz_offset = sprintf( '%s%02d:%02d', $sign, $abs_hour, $abs_mins );

		return $tz_offset;
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * WP core, verbatim (wp-includes/functions.php:152-154).
	 *
	 * @return DateTimeZone
	 */
	function wp_timezone() {
		return new DateTimeZone( wp_timezone_string() );
	}
}

if ( ! function_exists( 'get_date_from_gmt' ) ) {
	/**
	 * WP core, verbatim (wp-includes/formatting.php:3763-3771): read the string as
	 * UTC and re-render it in the site timezone; an unparseable string becomes the
	 * epoch rather than an error.
	 *
	 * @param string $date_string A UTC datetime string.
	 * @param string $format      Output format.
	 * @return string
	 */
	function get_date_from_gmt( $date_string, $format = 'Y-m-d H:i:s' ) {
		$datetime = date_create( $date_string, new DateTimeZone( 'UTC' ) );

		if ( false === $datetime ) {
			return gmdate( $format, 0 );
		}

		return $datetime->setTimezone( wp_timezone() )->format( $format );
	}
}
