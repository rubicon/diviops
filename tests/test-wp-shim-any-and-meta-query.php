<?php
// SPDX-License-Identifier: MIT
/**
 * wp-shim get_posts(): `'any'` and meta_query against core's contract (#318).
 *
 * The two stubs #316 left in place, both named in the function's own doc block as
 * known gaps rather than accepted behaviour:
 *
 *   - `'any'` was "everything in $GLOBALS['diviops_test_posts']". Core is narrower.
 *     WP_Query resolves `post_type => 'any'` to
 *     `get_post_types( array( 'exclude_from_search' => false ) )`
 *     (wp-includes/class-wp-query.php:2612-2613), and for `post_status` it adds a
 *     `post_status <> '<status>'` term for every status in
 *     `get_post_stati( array( 'exclude_from_search' => true ) )` the caller did not
 *     name explicitly (wp-includes/class-wp-query.php:2667-2673). On a stock
 *     install that is `trash` and `auto-draft`, because register_post_status()
 *     derives `exclude_from_search` from `internal` (wp-includes/post.php:1510-1512)
 *     and those two are core's only `internal` statuses that do not override it.
 *     Caller: rollback_snapshot_managed_target_posts() in trait-rollback.php.
 *
 *   - `meta_query` honoured EXISTS / NOT EXISTS and skipped every other clause with
 *     `continue`, so a clause carrying a `value` matched everything instead of
 *     filtering. Caller: cross_env_query_attachments_for_hint() in
 *     trait-theme-builder.php, which passes `=` or `LIKE` on `_wp_attached_file`.
 *
 * Both are now modelled off a registry rather than approximated: the shim carries
 * core's built-in post types and post statuses with the flags core derives for
 * them, and resolves `'any'` through that registry exactly as WP_Query does.
 *
 * The meta_query clause evaluator models the operators whose result is decidable
 * without a database — core's non-numeric operator set minus the regex family — and
 * throws on everything else rather than answering a question it cannot model. That
 * distinction is the whole point of #318: a shim that silently widens the result is
 * indistinguishable from a filter that worked.
 *
 * Eighteen of the assertions below fail against the pre-#318 shim; the four that do
 * not are marked inline as controls or guards, so the file states which of its
 * expectations were demonstrated red and which are pinning behaviour that already
 * held. The last three cases cannot run against the pre-#318 shim at all: they call
 * the registration helper the fix introduces, and the file aborts there with an
 * undefined-function fatal, which is itself the finding — there was no registry.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Run get_posts() against a fixed fixture set and return the matched ids.
 *
 * The post registry and both meta registries are replaced outright and restored:
 * every test file in tests/ shares one process, so a fixture left behind by another
 * file would make these results depend on discovery order.
 *
 * @param array<string, mixed> $args    get_posts() arguments.
 * @param callable             $fixture Receives nothing; registers the fixtures.
 * @return array<int, int> Matched post ids, in the order returned.
 */
function wp_shim_query_ids( array $args, callable $fixture ): array {
	$saved_posts = $GLOBALS['diviops_test_posts'];
	$saved_meta  = $GLOBALS['diviops_test_post_meta'] ?? array();
	$saved_rows  = $GLOBALS['diviops_test_post_meta_rows'] ?? array();

	$GLOBALS['diviops_test_posts']          = array();
	$GLOBALS['diviops_test_post_meta']      = array();
	$GLOBALS['diviops_test_post_meta_rows'] = array();
	$fixture();

	try {
		return array_map( 'intval', get_posts( array_merge( array( 'fields' => 'ids' ), $args ) ) );
	} finally {
		$GLOBALS['diviops_test_posts']          = $saved_posts;
		$GLOBALS['diviops_test_post_meta']      = $saved_meta;
		$GLOBALS['diviops_test_post_meta_rows'] = $saved_rows;
	}
}

/**
 * Register a post fixture with an explicit post_type and post_status.
 *
 * @param int    $post_id Post id.
 * @param string $type    post_type.
 * @param string $status  post_status.
 */
function wp_shim_fixture_post( int $post_id, string $type, string $status ): void {
	$post              = diviops_test_register_post( $post_id, 'fixture', $type, 'fixture ' . $post_id );
	$post->post_status = $status;
}

/**
 * The post-type / post-status fixture set for the `'any'` cases.
 *
 * One row per distinct answer core gives: two searchable built-in types, two types
 * core excludes from search, a type nothing registered at all, and the four statuses
 * that separate "excluded from search" from "merely not published".
 */
function wp_shim_any_fixtures(): void {
	wp_shim_fixture_post( 987501, 'page', 'publish' );
	wp_shim_fixture_post( 987502, 'post', 'publish' );
	wp_shim_fixture_post( 987503, 'attachment', 'inherit' );
	wp_shim_fixture_post( 987504, 'revision', 'inherit' );
	wp_shim_fixture_post( 987505, 'wp_block', 'publish' );
	wp_shim_fixture_post( 987506, 'page', 'trash' );
	wp_shim_fixture_post( 987507, 'page', 'auto-draft' );
	wp_shim_fixture_post( 987508, 'page', 'draft' );
	wp_shim_fixture_post( 987509, 'et_pb_layout', 'publish' );
}

/**
 * Run an `'any'` query over wp_shim_any_fixtures().
 *
 * @param array<string, mixed> $args get_posts() arguments.
 * @return array<int, int>
 */
function wp_shim_any_ids( array $args ): array {
	return wp_shim_query_ids(
		array_merge( array( 'posts_per_page' => -1, 'order' => 'ASC' ), $args ),
		'wp_shim_any_fixtures'
	);
}

// Control: the fixture set is nine posts and an unfiltered-by-type query sees the
// four pages among them. If this stops holding, every expectation below is
// measuring the wrong thing.
assert_same(
	array( 987501, 987506, 987507, 987508 ),
	wp_shim_any_ids( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'trash', 'auto-draft', 'draft' ) ) ),
	'control: the fixture set carries four pages across four statuses, so a filter that does nothing is visible as extra rows rather than as an empty result'
);

/* -- post_type 'any' is the searchable types, not every row ------------- */

assert_same(
	array( 987501, 987502, 987503, 987508 ),
	wp_shim_any_ids( array( 'post_type' => 'any', 'post_status' => 'any' ) ),
	"post_type 'any' is get_post_types( array( 'exclude_from_search' => false ) ) (class-wp-query.php:2612), which drops the revision, the wp_block and the unregistered type; post_status 'any' drops trash and auto-draft (class-wp-query.php:2667)"
);

assert_same(
	array( 987501, 987502 ),
	wp_shim_any_ids( array( 'post_type' => 'any', 'post_status' => 'publish' ) ),
	"an explicit post_status still filters within post_type 'any' — the wp_block fixture is published and is still excluded, because it is the type that is excluded from search, not the status"
);

// Guard, not a red case: naming a type has always worked. It is pinned because the
// obvious way to implement the wildcard — filtering the registry once, up front — is
// also the way to accidentally apply exclude_from_search to a named type.
assert_same(
	array( 987504 ),
	wp_shim_any_ids( array( 'post_type' => 'revision', 'post_status' => 'inherit' ) ),
	'a type core excludes from search is still queryable by name — exclude_from_search scopes the wildcard, it does not hide the rows'
);

assert_same(
	array(),
	wp_shim_any_ids( array( 'post_type' => array( 'any' ), 'post_status' => 'any' ) ),
	"core tests post_type for 'any' with string identity (class-wp-query.php:2612), so array( 'any' ) is a literal type name that matches nothing, not the wildcard"
);

/* -- post_status 'any' excludes only what core excludes ----------------- */

assert_same(
	array( 987501, 987506, 987508 ),
	wp_shim_any_ids( array( 'post_type' => 'page', 'post_status' => array( 'any', 'trash' ) ) ),
	"naming a status alongside 'any' re-admits it, because core only adds a `post_status <> x` term for an excluded status the caller did not list (class-wp-query.php:2668-2672) — auto-draft, unnamed, stays out"
);

assert_same(
	array( 987506, 987508 ),
	wp_shim_any_ids( array( 'post_type' => 'page', 'post_status' => 'draft,trash' ) ),
	'a comma-separated post_status string is a list, as core splits it (class-wp-query.php:2659-2661), not one status literally named "draft,trash"'
);

/* -- meta_query applies the comparison it was given --------------------- */

/**
 * The attachment fixture set for the meta_query cases.
 *
 * Modelled on what cross_env_query_attachments_for_hint() actually resolves: two
 * uploads sharing a basename in different month folders, a third that shares
 * neither, and one attachment with no _wp_attached_file row at all.
 */
function wp_shim_meta_fixtures(): void {
	wp_shim_fixture_post( 987601, 'attachment', 'inherit' );
	wp_shim_fixture_post( 987602, 'attachment', 'inherit' );
	wp_shim_fixture_post( 987603, 'attachment', 'inherit' );
	wp_shim_fixture_post( 987604, 'attachment', 'inherit' );
	update_post_meta( 987601, '_wp_attached_file', '2024/05/photo.jpg' );
	update_post_meta( 987602, '_wp_attached_file', '2023/01/photo.jpg' );
	update_post_meta( 987603, '_wp_attached_file', '2024/05/banner.png' );
	// Differs from the query value below by case alone, which is the one comparison
	// whose answer is set by the column's collation rather than by any core code.
	update_post_meta( 987604, 'diviops_shim_case', '2024/05/Photo.JPG' );
}

/**
 * Run an attachment query carrying a meta_query over wp_shim_meta_fixtures().
 *
 * @param array<int|string, mixed> $meta_query meta_query argument.
 * @return array<int, int>
 */
function wp_shim_meta_ids( array $meta_query ): array {
	return wp_shim_query_ids(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'order'          => 'ASC',
			'meta_query'     => $meta_query,
		),
		'wp_shim_meta_fixtures'
	);
}

/**
 * Return the message of the RuntimeException a meta_query raises, or ''.
 *
 * A gap the shim refuses to model must announce itself. Returning '' here means the
 * shim answered the query instead, which is the silent-widening this issue is about.
 *
 * @param array<int|string, mixed> $meta_query meta_query argument.
 */
function wp_shim_meta_error( array $meta_query ): string {
	try {
		wp_shim_meta_ids( $meta_query );
		return '';
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	}
}

// Control: all four attachments exist, so a clause that filters nothing is visible
// as extra rows.
assert_same(
	array( 987601, 987602, 987603, 987604 ),
	wp_shim_meta_ids( array() ),
	'control: the fixture set is four attachments, three of them carrying _wp_attached_file'
);

assert_same(
	array( 987601 ),
	wp_shim_meta_ids( array( array( 'key' => '_wp_attached_file', 'value' => '2024/05/photo.jpg', 'compare' => '=' ) ) ),
	"a `=` clause filters on the value — the exact shape cross_env_query_attachments_for_hint() passes when the hint carries an upload path"
);

assert_same(
	array( 987601 ),
	wp_shim_meta_ids( array( array( 'key' => '_wp_attached_file', 'value' => '2024/05/photo.jpg' ) ) ),
	"a clause with no compare is `=` in core when the value is not an array (class-wp-meta-query.php:544), not a clause to skip"
);

assert_same(
	array( 987601, 987602 ),
	wp_shim_meta_ids( array( array( 'key' => '_wp_attached_file', 'value' => 'photo.jpg', 'compare' => 'LIKE' ) ) ),
	"LIKE is substring containment: core wraps the value in `%...%` itself (class-wp-meta-query.php:755), which is how the basename branch of the attachment hint resolves both month folders"
);

assert_same(
	array(),
	wp_shim_meta_ids( array( array( 'key' => '_wp_attached_file', 'value' => 'photo%jpg', 'compare' => 'LIKE' ) ) ),
	'core escapes the caller\'s own wildcards with esc_like() before wrapping (class-wp-meta-query.php:755), so a `%` in the value is a literal percent sign and matches nothing here'
);

assert_same(
	array( 987602, 987603 ),
	wp_shim_meta_ids( array( array( 'key' => '_wp_attached_file', 'value' => '2024/05/photo.jpg', 'compare' => '!=' ) ) ),
	'`!=` is a comparison on the joined row, so the attachment carrying no _wp_attached_file row at all is not a match'
);

assert_same(
	array( 987601, 987602 ),
	wp_shim_meta_ids( array( array( 'key' => '_wp_attached_file', 'value' => array( '2024/05/photo.jpg', '2023/01/photo.jpg' ) ) ) ),
	'a clause with no compare and an array value is IN in core (class-wp-meta-query.php:544)'
);

assert_same(
	array( 987601 ),
	wp_shim_meta_ids( array( array( 'key' => '_wp_attached_file', 'value' => '2024/05/photo.jpg', 'compare' => 'EXISTS' ) ) ),
	'EXISTS carrying a value is interpreted as `=` by core (class-wp-meta-query.php:759-762), not as a bare key-presence test'
);

// Guard, not a red case: NOT EXISTS already worked. It is pinned because core
// ignores `value` for it outright (class-wp-meta-query.php:765-767), which a clause
// evaluator that reads `value` first would quietly get wrong.
assert_same(
	array( 987604 ),
	wp_shim_meta_ids( array( array( 'key' => '_wp_attached_file', 'value' => '2024/05/photo.jpg', 'compare' => 'NOT EXISTS' ) ) ),
	'NOT EXISTS ignores a value core would otherwise compare'
);

/* -- multiple rows for one key ------------------------------------------ */

assert_same(
	array( 987602 ),
	wp_shim_query_ids(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'meta_query'     => array( array( 'key' => 'diviops_shim_probe', 'value' => 'second', 'compare' => '=' ) ),
		),
		static function (): void {
			wp_shim_fixture_post( 987601, 'attachment', 'inherit' );
			wp_shim_fixture_post( 987602, 'attachment', 'inherit' );
			add_post_meta( 987601, 'diviops_shim_probe', 'first' );
			add_post_meta( 987602, 'diviops_shim_probe', 'first' );
			add_post_meta( 987602, 'diviops_shim_probe', 'second' );
		}
	),
	'a key with several rows matches when any one row satisfies the comparison, which is what core\'s JOIN on the meta table produces'
);

/* -- a gap the shim will not model announces itself --------------------- */

assert_same(
	"wp-shim get_posts(): meta_query compare '>' is not modelled. Extend diviops_test_meta_query_matches() or assert against a modelled operator.",
	wp_shim_meta_error( array( array( 'key' => '_wp_attached_file', 'value' => '5', 'compare' => '>' ) ) ),
	'a numeric operator raises rather than matching everything — the silent-widening this issue exists to remove'
);

assert_same(
	"wp-shim get_posts(): meta_query compare 'REGEXP' is not modelled. Extend diviops_test_meta_query_matches() or assert against a modelled operator.",
	wp_shim_meta_error( array( array( 'key' => '_wp_attached_file', 'value' => 'photo', 'compare' => 'REGEXP' ) ) ),
	'the regex operators core recognises are refused too, rather than approximated with preg_match'
);

assert_same(
	"wp-shim get_posts(): meta_query clause key 'type' is not modelled. Extend diviops_test_meta_query_matches() or drop the key from the query under test.",
	wp_shim_meta_error( array( array( 'key' => '_wp_attached_file', 'value' => 5, 'type' => 'NUMERIC' ) ) ),
	'a cast core would apply in SQL is refused rather than ignored — canvas_find_by_title() passes exactly this shape through WP_Query'
);

assert_same(
	"wp-shim get_posts(): meta_query relation 'OR' is not modelled. Extend diviops_test_meta_query_matches() or assert against a single clause.",
	wp_shim_meta_error(
		array(
			'relation' => 'OR',
			array( 'key' => '_wp_attached_file', 'value' => '2024/05/photo.jpg' ),
			array( 'key' => '_wp_attached_file', 'value' => '2023/01/photo.jpg' ),
		)
	),
	'an OR relation raises instead of being silently evaluated as AND, which would return nothing and read as "no attachment matched"'
);

assert_same(
	"wp-shim get_posts(): meta_query comparison of '2024/05/Photo.JPG' with '2024/05/photo.jpg' depends on the database collation, which this harness does not model. Use fixtures that differ by more than case.",
	wp_shim_meta_error( array( array( 'key' => 'diviops_shim_case', 'value' => '2024/05/photo.jpg', 'compare' => '=' ) ) ),
	'a comparison whose answer depends on whether the column collation is case-sensitive raises rather than picking one — the shim cannot know the collation, so it must not guess'
);

/* -- the post-type registry is real, not a hardcoded exclusion list ----- */
//
// The two cases above that a query cannot reach: that the wildcard reads a registry
// rather than a fixed list of names is only visible from a type this file registers
// itself.

diviops_test_register_post_type( 'diviops_shim_public', array( 'public' => true ) );
diviops_test_register_post_type( 'diviops_shim_private', array( 'public' => false ) );

assert_same(
	array( 987511 ),
	wp_shim_query_ids(
		array( 'post_type' => 'any', 'post_status' => 'publish', 'posts_per_page' => -1, 'order' => 'ASC' ),
		static function (): void {
			wp_shim_fixture_post( 987511, 'diviops_shim_public', 'publish' );
			wp_shim_fixture_post( 987512, 'diviops_shim_private', 'publish' );
		}
	),
	"a type registered public is in the `'any'` set and one registered non-public is not, because core derives exclude_from_search from public (class-wp-post-type.php:606-607) — the wildcard reads a registry rather than a fixed list of names"
);

assert_same(
	array( 'diviops_shim_public' => 'diviops_shim_public' ),
	array_intersect_key(
		get_post_types( array( 'exclude_from_search' => false ) ),
		array( 'diviops_shim_public' => true, 'diviops_shim_private' => true )
	),
	'get_post_types() answers from the same registry WP_Query consults, so the two agree by construction rather than by two lists being kept in step'
);

assert_same(
	array( 'trash' => 'trash', 'auto-draft' => 'auto-draft' ),
	get_post_stati( array( 'exclude_from_search' => true ) ),
	"core's only statuses excluded from search are trash and auto-draft: register_post_status() defaults exclude_from_search to internal (post.php:1510-1512), and inherit and the four request-* statuses set it back to false explicitly"
);

assert_same(
	array(
		'publish' => 'publish',
		'future'  => 'future',
		'draft'   => 'draft',
		'pending' => 'pending',
		'private' => 'private',
	),
	get_post_stati( array( 'internal' => false ) ),
	'the status registry still answers the non-internal question page_create() validates against, which the hardcoded five-status stub answered before it'
);
