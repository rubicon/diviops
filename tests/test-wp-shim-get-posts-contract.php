<?php
// SPDX-License-Identifier: MIT
/**
 * wp-shim get_posts() vs core's contract (#316).
 *
 * The shim's `get_posts()` compared `post_type` with `!==` until #315, so the
 * array argument every multi-post-type scanner passes matched nothing and read
 * as "the site has no such posts". A test driven through it would have passed on
 * the empty result. That was one stub modelling this fork's assumptions instead
 * of core's behaviour; #316 asks what else in the same function does.
 *
 * Two more arguments were being accepted and ignored, both with live callers:
 *
 *   - `post__in` restricts a query to the named ids in core
 *     (wp-includes/post.php passes it straight to WP_Query). The shim returned
 *     every row of the matching type and status, so a test asserting "the batch
 *     fetch returned N posts" was asserting on the fixture registry rather than
 *     on the query. Callers: collect_preset_consumer_samples() in
 *     trait-preset.php and rollback_snapshot_managed_target_posts() in
 *     trait-rollback.php.
 *
 *   - `numberposts` is core's own default cap, mapped onto `posts_per_page`
 *     whenever `posts_per_page` is empty (wp-includes/post.php:2643). The shim
 *     read `posts_per_page` only, so a caller passing `numberposts` silently got
 *     core's default of 5 instead of the cap it asked for. Caller:
 *     rollback_snapshot_managed_target_posts(), which passes
 *     `count( $target_ids )` — so a snapshot of six or more targets would have
 *     been truncated to five with no assertion able to see it.
 *
 * `orderby => 'post__in'` comes with them: both `post__in` callers ask for it,
 * and a shim that filters by id but reorders the result is only half faithful.
 *
 * Every assertion below is written so the pre-fix shim fails it. The fixture set
 * is deliberately larger than any single expectation, so an argument that is
 * accepted and ignored shows up as extra rows rather than as an empty result
 * that could be mistaken for a clean filter.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Run get_posts() against a fixed fixture set and return the matched ids.
 *
 * The registry is replaced outright and restored: every test file in tests/
 * shares one process and one $GLOBALS['diviops_test_posts'], so a fixture left
 * behind by another file would make these results depend on discovery order.
 *
 * @param array<string, mixed> $args get_posts() arguments.
 * @return array<int, int> Matched post ids, in the order returned.
 */
function wp_shim_get_posts_ids( array $args ): array {
	$saved                         = $GLOBALS['diviops_test_posts'];
	$GLOBALS['diviops_test_posts'] = array();
	foreach ( range( 1, 7 ) as $offset ) {
		diviops_test_register_post( 987400 + $offset, 'fixture ' . $offset, 'page', 'shim contract fixture ' . $offset );
	}

	try {
		return array_map( 'intval', get_posts( array_merge( array( 'fields' => 'ids' ), $args ) ) );
	} finally {
		$GLOBALS['diviops_test_posts'] = $saved;
	}
}

// Control: seven fixtures exist and an unrestricted query sees all of them. If
// this ever stops holding, every expectation below is measuring the wrong thing.
assert_same(
	array( 987401, 987402, 987403, 987404, 987405, 987406, 987407 ),
	wp_shim_get_posts_ids( array( 'post_type' => 'page', 'posts_per_page' => -1, 'order' => 'ASC' ) ),
	'the fixture set is seven pages, so a filter that does nothing is visible as extra rows rather than as an empty result'
);

/* -- post__in restricts the result ------------------------------------- */

assert_same(
	array( 987402, 987405 ),
	wp_shim_get_posts_ids(
		array(
			'post_type'      => 'page',
			'post__in'       => array( 987405, 987402 ),
			'posts_per_page' => -1,
			'order'          => 'ASC',
		)
	),
	'post__in restricts the result to the named ids, as core does — a shim that ignores it returns the whole fixture set and makes a batch-fetch assertion vacuous'
);

assert_same(
	array(),
	wp_shim_get_posts_ids( array( 'post_type' => 'page', 'post__in' => array( 999999 ), 'posts_per_page' => -1 ) ),
	'a post__in naming no existing id returns nothing rather than falling back to an unfiltered query'
);

// An empty post__in is core's "no restriction": get_posts() only sets post__in
// from `include` when that argument is non-empty, and WP_Query skips an empty one.
assert_same(
	7,
	count( wp_shim_get_posts_ids( array( 'post_type' => 'page', 'post__in' => array(), 'posts_per_page' => -1 ) ) ),
	'an empty post__in is not a restriction, matching core, so it must not silently return nothing'
);

/* -- orderby post__in preserves the caller's order ---------------------- */

assert_same(
	array( 987406, 987401, 987404 ),
	wp_shim_get_posts_ids(
		array(
			'post_type'      => 'page',
			'post__in'       => array( 987406, 987401, 987404 ),
			'orderby'        => 'post__in',
			'posts_per_page' => -1,
		)
	),
	"orderby 'post__in' returns the rows in the order the caller listed them, which is what both post__in callers in this plugin ask for"
);

/* -- numberposts is core's cap when posts_per_page is absent ------------ */

assert_same(
	7,
	count( wp_shim_get_posts_ids( array( 'post_type' => 'page', 'numberposts' => 7, 'order' => 'ASC' ) ) ),
	'numberposts sets the cap when posts_per_page is absent (wp-includes/post.php:2643) — without it the caller silently gets core\'s default of 5'
);

assert_same(
	array( 987401, 987402 ),
	wp_shim_get_posts_ids( array( 'post_type' => 'page', 'numberposts' => 2, 'order' => 'ASC' ) ),
	'numberposts caps the result the same way posts_per_page does'
);

assert_same(
	array( 987401, 987402, 987403 ),
	wp_shim_get_posts_ids(
		array(
			'post_type'      => 'page',
			'numberposts'    => 7,
			'posts_per_page' => 3,
			'order'          => 'ASC',
		)
	),
	'posts_per_page wins when both are given, because core only maps numberposts onto an empty posts_per_page'
);

assert_same(
	5,
	count( wp_shim_get_posts_ids( array( 'post_type' => 'page' ) ) ),
	"core's default of five still applies when neither cap is given"
);
