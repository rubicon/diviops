<?php
// SPDX-License-Identifier: MIT
/**
 * wp-shim WP_Query: post_type / post_status / meta_query against core's contract (#326).
 *
 * `get_posts()` was corrected for exactly these arguments in #314/#315 (an array
 * `post_type` compared with `!==`), #316 (`post__in`, `numberposts`) and #318
 * (`'any'` resolved off a registry, `meta_query` actually applied). The `WP_Query`
 * class in the same file was left carrying both remaining defects, and it is the
 * class every `'any'` and `meta_query` caller in this plugin actually goes through:
 *
 *   - `post_type` and `post_status` were compared with `!==` against a single
 *     string, so an array matched nothing and `post_status => 'any'` matched
 *     nothing at all. A handler driven through it returned an empty result, which
 *     reads as "the site has no canvases" rather than as a broken stub. Callers:
 *     canvas_existing_id_by_title(), canvas_list(), canvas_orphan_audit() and
 *     canvas_audit_reference_candidates() in trait-canvas.php, and
 *     library_existing_id_by_title() in trait-library.php.
 *
 *   - `meta_query` was not read at all, so a clause matched everything. The three
 *     canvas callers all pass the same shape: `_divi_canvas_parent_post_id`,
 *     an already-`(int)`-cast value, `type => 'NUMERIC'`, no `compare`. An
 *     assertion that "the canvases for parent page N came back" was an assertion
 *     about the fixture registry, not about the query.
 *
 * Both are now answered by the same registries and the same clause evaluator
 * `get_posts()` uses — `diviops_test_query_post_filter()` /
 * `diviops_test_query_post_matches()` and `diviops_test_meta_query_matches()` —
 * rather than by a second implementation that could drift from the first. The
 * cross-check near the end of this file asserts the two agree on one query.
 *
 * `type => 'NUMERIC'` is modelled for equality on integer values only, which is
 * the whole of the shape those three callers pass. `NUMERIC` casts to `SIGNED`
 * (wp-includes/class-wp-meta-query.php:329-331) and MySQL turns a non-integer
 * string into a truncated value plus a warning rather than rejecting it, so every
 * input whose answer would come from those truncation rules — an ordering
 * comparison, a non-integer value on either side, any other cast type — still
 * raises. A refusal is a visible gap; an approximation is invisible wrong
 * coverage.
 *
 * The NUMERIC path has no live counterpart under tests-live/ on purpose: the
 * reference install carries zero `_divi_canvas_*` meta rows and zero
 * `et_pb_canvas` posts (measured 2026-09-01 against wp_postmeta's 3,145 rows and
 * 34 `_divi`-prefixed rows as a positive control), so a live test would assert
 * nothing while looking like coverage.
 *
 * Every assertion below fails against the shim that preceded the issue it is
 * filed under — #326 for the sections above, #330 for the last one — except
 * those marked inline as a control or a guard.
 *
 * The final sections are #330. #326 left `title`, `orderby`/`order`, `perm`,
 * `tax_query` and a negative `posts_per_page` accepted and ignored, which held two
 * standards inside one class: a meta_query feature the shim cannot model refuses
 * loudly, while a query argument it cannot model is silently dropped.
 *
 * They are split by whether this harness can answer them the way core does.
 * `title` can — it is exact string equality on a value core reaches by a stated
 * route — so it is modelled, and the cases below pin it to core's own predicate
 * rather than to a truthiness test, which is where a `! empty()` reading of it
 * goes wrong in both directions at once ('0' silently ignored, '   ' wrongly
 * refused). The rest cannot: there is no term registry for `tax_query`, no user
 * or `post_author` for `perm`, no ordering for `orderby`, so those raise. A
 * refusal is a visible gap; an approximation is invisible wrong coverage.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Run a WP_Query against a fixed fixture set and return the matched ids.
 *
 * The post registry and both meta registries are replaced outright and restored:
 * every test file in tests/ shares one process, so a fixture left behind by
 * another file would make these results depend on discovery order.
 *
 * @param array<string, mixed> $args    WP_Query arguments.
 * @param callable             $fixture Receives nothing; registers the fixtures.
 * @param array<int, string>   $waive   Argument names to waive the #330 refusal for.
 * @return array<int, int> Matched post ids, in the order returned.
 */
function wp_shim_wp_query_ids( array $args, callable $fixture, array $waive = array() ): array {
	$saved_posts  = $GLOBALS['diviops_test_posts'];
	$saved_meta   = $GLOBALS['diviops_test_post_meta'] ?? array();
	$saved_rows   = $GLOBALS['diviops_test_post_meta_rows'] ?? array();
	// The refusal waiver too: another file that forgot to unset it would
	// otherwise turn every refusal expectation below into a silent answer.
	$saved_waiver = $GLOBALS['diviops_test_wp_query_unmodelled_ok'] ?? array();

	$GLOBALS['diviops_test_posts']                  = array();
	$GLOBALS['diviops_test_post_meta']              = array();
	$GLOBALS['diviops_test_post_meta_rows']         = array();
	$GLOBALS['diviops_test_wp_query_unmodelled_ok'] = $waive;
	$fixture();

	try {
		// A positive cap rather than -1: WP_Query's paging slice reads it as a
		// length, and this file is not the place to also settle what -1 means.
		$query = new WP_Query( array_merge( array( 'fields' => 'ids', 'posts_per_page' => 100 ), $args ) );
		return array_map( 'intval', $query->posts );
	} finally {
		$GLOBALS['diviops_test_posts']                  = $saved_posts;
		$GLOBALS['diviops_test_post_meta']              = $saved_meta;
		$GLOBALS['diviops_test_post_meta_rows']         = $saved_rows;
		$GLOBALS['diviops_test_wp_query_unmodelled_ok'] = $saved_waiver;
	}
}

/**
 * Register a post fixture with an explicit post_type and post_status.
 *
 * @param int    $post_id Post id.
 * @param string $type    post_type.
 * @param string $status  post_status.
 */
function wp_shim_wp_query_fixture_post( int $post_id, string $type, string $status ): void {
	$post              = diviops_test_register_post( $post_id, 'fixture', $type, 'fixture ' . $post_id );
	$post->post_status = $status;
}

/**
 * The post-type / post-status fixture set.
 *
 * One row per distinct answer core gives: two searchable built-in types, two
 * types core excludes from search, a type nothing registered at all, and the
 * four statuses that separate "excluded from search" from "merely not published".
 */
function wp_shim_wp_query_type_fixtures(): void {
	wp_shim_wp_query_fixture_post( 987701, 'page', 'publish' );
	wp_shim_wp_query_fixture_post( 987702, 'post', 'publish' );
	wp_shim_wp_query_fixture_post( 987703, 'attachment', 'inherit' );
	wp_shim_wp_query_fixture_post( 987704, 'revision', 'inherit' );
	wp_shim_wp_query_fixture_post( 987705, 'wp_block', 'publish' );
	wp_shim_wp_query_fixture_post( 987706, 'page', 'trash' );
	wp_shim_wp_query_fixture_post( 987707, 'page', 'auto-draft' );
	wp_shim_wp_query_fixture_post( 987708, 'page', 'draft' );
	wp_shim_wp_query_fixture_post( 987709, 'et_pb_canvas', 'publish' );
}

/**
 * Run a type/status query over wp_shim_wp_query_type_fixtures().
 *
 * @param array<string, mixed> $args  WP_Query arguments.
 * @param array<int, string>   $waive Argument names to waive the #330 refusal for.
 * @return array<int, int>
 */
function wp_shim_wp_query_type_ids( array $args, array $waive = array() ): array {
	return wp_shim_wp_query_ids( $args, 'wp_shim_wp_query_type_fixtures', $waive );
}

// Control: the fixture set is nine posts and a single-string query still sees the
// one published page among them. If this stops holding, every expectation below
// is measuring the wrong thing.
assert_same(
	array( 987701 ),
	wp_shim_wp_query_type_ids( array( 'post_type' => 'page', 'post_status' => 'publish' ) ),
	'control: the fixture set carries one published page, so a filter that does nothing is visible as extra rows rather than as an empty result'
);

/* -- post_type accepts the shapes core accepts -------------------------- */

assert_same(
	array( 987701, 987702 ),
	wp_shim_wp_query_type_ids( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish' ) ),
	'an array post_type is an IN list in core (class-wp-query.php:2620-2623), not a value to compare a single post_type against — the `!==` defect #314 filed against get_posts(), still live in this class'
);

assert_same(
	array( 987701, 987702, 987703, 987708 ),
	wp_shim_wp_query_type_ids( array( 'post_type' => 'any', 'post_status' => 'any' ) ),
	"post_type 'any' is get_post_types( array( 'exclude_from_search' => false ) ) (class-wp-query.php:2612-2613), which drops the revision, the wp_block and the unregistered et_pb_canvas; post_status 'any' drops trash and auto-draft (class-wp-query.php:2667-2673)"
);

assert_same(
	array( 987701, 987702 ),
	wp_shim_wp_query_type_ids( array( 'post_type' => 'any', 'post_status' => 'publish' ) ),
	"an explicit post_status still filters within post_type 'any' — the wp_block fixture is published and is still excluded, because it is the type that is excluded from search, not the status"
);

// Guard, not a red case: core tests post_type for 'any' with string identity, so
// the array form has never been the wildcard. Pinned because resolving the
// wildcard from `in_array( 'any', ... )` is the obvious way to get it wrong.
assert_same(
	array(),
	wp_shim_wp_query_type_ids( array( 'post_type' => array( 'any' ), 'post_status' => 'any' ) ),
	"core tests post_type for 'any' with string identity (class-wp-query.php:2612), so array( 'any' ) is a literal type name that matches nothing, not the wildcard"
);

// Guard, not a red case: naming a type has always worked here. Pinned because
// filtering the registry once up front is also the way to accidentally apply
// exclude_from_search to a type the caller named.
assert_same(
	array( 987704 ),
	wp_shim_wp_query_type_ids( array( 'post_type' => 'revision', 'post_status' => 'inherit' ) ),
	'a type core excludes from search is still queryable by name — exclude_from_search scopes the wildcard, it does not hide the rows'
);

/* -- post_status accepts the shapes core accepts ------------------------ */

assert_same(
	array( 987701, 987708 ),
	wp_shim_wp_query_type_ids( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft' ) ) ),
	'core takes post_status as a list, using an array as given and splitting only a string (class-wp-query.php:2659-2662), not as a value to compare one status against'
);

assert_same(
	array( 987706, 987708 ),
	wp_shim_wp_query_type_ids( array( 'post_type' => 'page', 'post_status' => 'draft,trash' ) ),
	'a comma-separated post_status string is a list, as core splits it (class-wp-query.php:2660-2662), not one status literally named "draft,trash"'
);

assert_same(
	array( 987701, 987706, 987708 ),
	wp_shim_wp_query_type_ids( array( 'post_type' => 'page', 'post_status' => array( 'any', 'trash' ) ) ),
	"naming a status alongside 'any' re-admits it, because core only adds a `post_status <> x` term for an excluded status the caller did not list (class-wp-query.php:2667-2673) — auto-draft, unnamed, stays out"
);

/* -- WP_Query and get_posts() answer from the same registries ----------- */

// Control, not a red case: get_posts() has answered this correctly since #318.
// It is the other half of the pair — the expectation above it is the same list
// through WP_Query — so the two together say the classes agree rather than each
// merely matching a list written out by hand.
assert_same(
	array( 987701, 987702, 987703, 987708 ),
	( function (): array {
		$saved_posts                   = $GLOBALS['diviops_test_posts'];
		$GLOBALS['diviops_test_posts'] = array();
		wp_shim_wp_query_type_fixtures();
		try {
			return array_map(
				'intval',
				get_posts(
					array(
						'fields'         => 'ids',
						'post_type'      => 'any',
						'post_status'    => 'any',
						'posts_per_page' => 100,
						'order'          => 'ASC',
					)
				)
			);
		} finally {
			$GLOBALS['diviops_test_posts'] = $saved_posts;
		}
	} )(),
	"get_posts() resolves the same query to the same rows, because both now read the type and status registries through diviops_test_query_post_filter() rather than each carrying its own answer"
);

/* -- meta_query is applied, and NUMERIC equality is modelled ------------ */

/**
 * The canvas fixture set for the meta_query cases.
 *
 * Modelled on what canvas_list() and canvas_existing_id_by_title() actually
 * query: canvases carrying `_divi_canvas_parent_post_id`, two under one parent
 * page, one under another, and one with no parent row at all.
 */
function wp_shim_wp_query_meta_fixtures(): void {
	wp_shim_wp_query_fixture_post( 987711, 'et_pb_canvas', 'publish' );
	wp_shim_wp_query_fixture_post( 987712, 'et_pb_canvas', 'publish' );
	wp_shim_wp_query_fixture_post( 987713, 'et_pb_canvas', 'publish' );
	wp_shim_wp_query_fixture_post( 987714, 'et_pb_canvas', 'publish' );
	update_post_meta( 987711, '_divi_canvas_parent_post_id', 4242 );
	update_post_meta( 987712, '_divi_canvas_parent_post_id', 4242 );
	update_post_meta( 987713, '_divi_canvas_parent_post_id', 99 );
	// No _divi_canvas_parent_post_id row at all, plus a value no CAST can turn
	// into an integer without MySQL's own truncation rules deciding the answer.
	update_post_meta( 987714, '_divi_canvas_id', 'canvas-7f3a' );
}

/**
 * Run a canvas query carrying a meta_query over wp_shim_wp_query_meta_fixtures().
 *
 * @param array<int|string, mixed> $meta_query meta_query argument.
 * @return array<int, int>
 */
function wp_shim_wp_query_meta_ids( array $meta_query ): array {
	return wp_shim_wp_query_ids(
		array(
			'post_type'   => 'et_pb_canvas',
			'post_status' => 'any',
			'meta_query'  => $meta_query,
		),
		'wp_shim_wp_query_meta_fixtures'
	);
}

/**
 * Return the message of the RuntimeException a meta_query raises, or ''.
 *
 * A gap the shim refuses to model must announce itself. Returning '' here means
 * the shim answered the query instead, which is the silent widening this issue
 * is about.
 *
 * @param array<int|string, mixed> $meta_query meta_query argument.
 */
function wp_shim_wp_query_meta_error( array $meta_query ): string {
	try {
		wp_shim_wp_query_meta_ids( $meta_query );
		return '';
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	}
}

// Control: all four canvases exist under `post_status => 'any'`, so a clause that
// filters nothing is visible as extra rows. Red before the fix for the status
// half rather than the meta half.
assert_same(
	array( 987711, 987712, 987713, 987714 ),
	wp_shim_wp_query_meta_ids( array() ),
	'control: the fixture set is four canvases, three of them carrying a parent-page row'
);

assert_same(
	array( 987711, 987712 ),
	wp_shim_wp_query_meta_ids(
		array(
			array(
				'key'   => '_divi_canvas_parent_post_id',
				'value' => (int) 4242,
				'type'  => 'NUMERIC',
			),
		)
	),
	'the exact clause canvas_list(), canvas_orphan_audit() and canvas_existing_id_by_title() pass — key, an already-(int)-cast value, a NUMERIC cast and no compare — filters to the canvases under that parent instead of matching every row'
);

assert_same(
	array( 987713 ),
	wp_shim_wp_query_meta_ids(
		array(
			array(
				'key'   => '_divi_canvas_parent_post_id',
				'value' => (int) 99,
				'type'  => 'NUMERIC',
			),
		)
	),
	'a different parent selects a different canvas, so the clause is comparing the stored value rather than merely being present'
);

assert_same(
	array( 987711, 987712 ),
	wp_shim_wp_query_meta_ids( array( array( 'key' => '_divi_canvas_parent_post_id', 'value' => '4242' ) ) ),
	'a clause with no cast reaches the same evaluator get_posts() uses, where a non-array value with no compare is `=` (class-wp-meta-query.php:541-544)'
);

assert_same(
	array( 987714 ),
	wp_shim_wp_query_meta_ids( array( array( 'key' => '_divi_canvas_parent_post_id', 'compare' => 'NOT EXISTS' ) ) ),
	'NOT EXISTS reaches the evaluator too, rather than the whole meta_query being skipped'
);

/* -- what NUMERIC deliberately still refuses ---------------------------- */

assert_same(
	"wp-shim meta_query: type 'NUMERIC' is modelled for compare '=' only, not '>'. An ordering comparison under a cast is decided by MySQL's own conversion rules. Extend diviops_test_meta_query_matches() or assert against equality.",
	wp_shim_wp_query_meta_error(
		array(
			array(
				'key'     => '_divi_canvas_parent_post_id',
				'value'   => 100,
				'type'    => 'NUMERIC',
				'compare' => '>',
			),
		)
	),
	'an ordering comparison under a NUMERIC cast raises rather than matching everything — equality on integers is the whole of what the three canvas callers need and the whole of what is modelled'
);

assert_same(
	"wp-shim meta_query: type 'NUMERIC' compares integers, and the queried value '42.5' is not one. MySQL's CAST truncates a non-integer rather than rejecting it, which this harness does not model.",
	wp_shim_wp_query_meta_error(
		array(
			array(
				'key'   => '_divi_canvas_parent_post_id',
				'value' => '42.5',
				'type'  => 'NUMERIC',
			),
		)
	),
	'a non-integer queried value raises: CAST( ... AS SIGNED ) would truncate it, and a truncation this harness invented is not core behaviour'
);

assert_same(
	"wp-shim meta_query: type 'NUMERIC' compares integers, and the value 'canvas-7f3a' stored for '_divi_canvas_id' on post 987714 is not one. MySQL's CAST truncates a non-integer rather than rejecting it, which this harness does not model.",
	wp_shim_wp_query_meta_error(
		array(
			array(
				'key'   => '_divi_canvas_id',
				'value' => 7,
				'type'  => 'NUMERIC',
			),
		)
	),
	'a non-integer stored value raises for the same reason, and names the row it could not cast so the fixture is findable'
);

assert_same(
	"wp-shim meta_query: type 'DECIMAL' is not modelled; only NUMERIC equality on integer values is. Extend diviops_test_meta_query_matches() or drop the cast from the query under test.",
	wp_shim_wp_query_meta_error(
		array(
			array(
				'key'   => '_divi_canvas_parent_post_id',
				'value' => 4242,
				'type'  => 'DECIMAL',
			),
		)
	),
	'a cast other than NUMERIC raises rather than being treated as NUMERIC — DECIMAL and NUMERIC are distinct MySQL types (class-wp-meta-query.php:325-333) and only one of them is modelled'
);

assert_same(
	"wp-shim meta_query: type 'DATE' is not modelled; only NUMERIC equality on integer values is. Extend diviops_test_meta_query_matches() or drop the cast from the query under test.",
	wp_shim_wp_query_meta_error(
		array(
			array(
				'key'   => '_divi_canvas_parent_post_id',
				'value' => '2026-09-01',
				'type'  => 'DATE',
			),
		)
	),
	'a DATE cast raises: core would compare dates in SQL, which is not a comparison this harness can answer from a stored string'
);

assert_same(
	"wp-shim meta_query: compare 'REGEXP' is not modelled. Extend diviops_test_meta_query_matches() or assert against a modelled operator.",
	wp_shim_wp_query_meta_error(
		array( array( 'key' => '_divi_canvas_parent_post_id', 'value' => '42', 'compare' => 'REGEXP' ) )
	),
	'the regex operators core recognises are refused through WP_Query as they are through get_posts(), rather than approximated with preg_match'
);

assert_same(
	"wp-shim meta_query: relation 'OR' is not modelled. Extend diviops_test_meta_query_matches() or assert against a single clause.",
	wp_shim_wp_query_meta_error(
		array(
			'relation' => 'OR',
			array( 'key' => '_divi_canvas_parent_post_id', 'value' => 4242, 'type' => 'NUMERIC' ),
			array( 'key' => '_divi_canvas_parent_post_id', 'value' => 99, 'type' => 'NUMERIC' ),
		)
	),
	'an OR relation raises instead of being silently evaluated as AND, which would return nothing and read as "no canvas matched"'
);

assert_same(
	'wp-shim meta_query: entries other than a first-order clause naming a single key are not modelled. Extend diviops_test_meta_query_matches() or flatten the query under test.',
	wp_shim_wp_query_meta_error(
		array(
			array(
				'relation' => 'AND',
				array( 'key' => '_divi_canvas_parent_post_id', 'value' => 4242, 'type' => 'NUMERIC' ),
			),
		)
	),
	'a nested clause group raises rather than being flattened, because flattening changes which rows the query returns'
);

/* -- what the class deliberately refuses to answer (#330) --------------- */

/**
 * Return the message of the RuntimeException a WP_Query raises, or ''.
 *
 * The whole-query counterpart of wp_shim_wp_query_meta_error() above. '' means
 * the class answered the query instead of refusing it, which is the silent
 * widening these cases are about.
 *
 * @param array<string, mixed> $args  WP_Query arguments.
 * @param array<int, string>   $waive Argument names to waive the refusal for.
 */
function wp_shim_wp_query_error( array $args, array $waive = array() ): string {
	try {
		wp_shim_wp_query_type_ids( $args, $waive );
		return '';
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	}
}

/**
 * Fixtures for the title cases: one plain title plus the three values that
 * separate core's predicate from a truthiness test.
 */
function wp_shim_wp_query_title_fixtures(): void {
	$titles = array( 987801 => 'alpha', 987802 => '0', 987803 => '   ', 987804 => "O'Brien" );
	foreach ( $titles as $post_id => $title ) {
		$post              = diviops_test_register_post( $post_id, 'fixture', 'page', $title );
		$post->post_status = 'publish';
	}
}

/**
 * Run a title lookup over wp_shim_wp_query_title_fixtures().
 *
 * @param mixed $title The title argument, passed through exactly as given.
 * @return array<int, int>
 */
function wp_shim_wp_query_title_ids( $title ): array {
	return wp_shim_wp_query_ids(
		array( 'post_type' => 'page', 'post_status' => 'publish', 'title' => $title ),
		'wp_shim_wp_query_title_fixtures'
	);
}

assert_same(
	array( 987801 ),
	wp_shim_wp_query_title_ids( 'alpha' ),
	'an exact-title lookup returns the single row core returns — canvas_existing_id_by_title() and library_existing_id_by_title() are collision checks that read posts[0], so a widened result reports a conflict against the wrong post'
);

assert_same(
	array(),
	wp_shim_wp_query_title_ids( 'no fixture carries this title' ),
	'a title nothing matches returns nothing rather than every row, which is the answer that makes a collision check mean anything at all'
);

assert_same(
	array( 987802 ),
	wp_shim_wp_query_title_ids( '0' ),
	"the string '0' is a title like any other: core's test is '' !== the trimmed title (class-wp-query.php:850, 2178), not a truthiness test, so it filters rather than reading as absent"
);

assert_same(
	array( 987802 ),
	wp_shim_wp_query_title_ids( 0 ),
	'core runs a scalar title through trim() before that comparison (class-wp-query.php:850), so the integer 0 is the string "0" and matches the same row'
);

assert_same(
	array( 987804 ),
	wp_shim_wp_query_title_ids( "O\\'Brien" ),
	'core compares against stripslashes() of the title (class-wp-query.php:2179), so a slashed apostrophe matches the unslashed row rather than nothing'
);

assert_same(
	array( 987801, 987802, 987803, 987804 ),
	wp_shim_wp_query_title_ids( '   ' ),
	'a whitespace-only title trims to the empty string in core and adds no term at all, so it is inert rather than a filter matching the whitespace-titled row'
);

assert_same(
	array( 987801, 987802, 987803, 987804 ),
	wp_shim_wp_query_title_ids( '' ),
	'an empty title adds no term in core, so every row under the other arguments comes back'
);

assert_same(
	array( 987801, 987802, 987803, 987804 ),
	wp_shim_wp_query_title_ids( array( 'alpha' ) ),
	'core replaces a non-scalar title with the empty string before the comparison (class-wp-query.php:850), so an array is inert rather than matching or raising'
);

assert_same(
	"wp-shim WP_Query: 'tax_query' is not modelled. Ignoring it returns rows under every term rather than the terms the caller scoped to. Model it in the WP_Query stub or drop 'tax_query' from the query under test. Alternatively list 'tax_query' in \$GLOBALS['diviops_test_wp_query_unmodelled_ok'] when the argument is inert for the fixtures under test.",
	wp_shim_wp_query_error(
		array(
			'post_type'   => 'page',
			'post_status' => 'any',
			'tax_query'   => array( array( 'taxonomy' => 'layout_type', 'field' => 'slug', 'terms' => 'section' ) ),
		)
	),
	'a taxonomy scope raises rather than being dropped — the same widening as title, and it is how library_existing_id_by_title() scopes its uniqueness to (layout_type, scope)'
);

// Guard, not a red case: an empty tax_query adds no term in core either.
assert_same(
	'',
	wp_shim_wp_query_error( array( 'post_type' => 'page', 'post_status' => 'publish', 'tax_query' => array() ) ),
	'an empty tax_query is inert rather than a refusal'
);

assert_same(
	"wp-shim WP_Query: perm 'editable' is not modelled. Core narrows to the current user's own posts, and only when that user lacks the edit_others capability for the post type (class-wp-query.php:2694); this stub has no user, role or post_author to compute either half from, so it can neither apply that narrowing nor rule out that core would. Model it in the WP_Query stub or drop 'perm' from the query under test. Alternatively list 'perm' in \$GLOBALS['diviops_test_wp_query_unmodelled_ok'] when the argument is inert for the fixtures under test.",
	wp_shim_wp_query_error( array( 'post_type' => 'page', 'post_status' => 'any', 'perm' => 'editable' ) ),
	'query_inspectable_post_ids() passes perm as a coarse prefilter before its own per-object edit_post check; with no capability filter here a test cannot tell the two apart, so the prefilter reads as tested while being untested'
);

assert_same(
	"wp-shim WP_Query: orderby 'date' is not modelled. This stub returns fixtures in registry order whatever the caller asks for, which is a different order than core's and, once posts_per_page truncates, a different set of rows. Model it in the WP_Query stub or drop 'orderby' from the query under test. Alternatively list 'orderby' in \$GLOBALS['diviops_test_wp_query_unmodelled_ok'] when the argument is inert for the fixtures under test.",
	wp_shim_wp_query_error( array( 'post_type' => 'page', 'post_status' => 'any', 'orderby' => 'date', 'order' => 'DESC' ) ),
	'an orderby raises rather than being answered in registry order — canvas_list() asks for newest-first and would get whatever order the fixtures happened to be registered in'
);

assert_same(
	"wp-shim WP_Query: order 'DESC' is not modelled. This stub returns fixtures in registry order whatever the caller asks for, which is a different order than core's and, once posts_per_page truncates, a different set of rows. Model it in the WP_Query stub or drop 'order' from the query under test. Alternatively list 'order' in \$GLOBALS['diviops_test_wp_query_unmodelled_ok'] when the argument is inert for the fixtures under test.",
	wp_shim_wp_query_error( array( 'post_type' => 'page', 'post_status' => 'any', 'order' => 'DESC' ) ),
	"order alone still changes core's answer, because core's default orderby is post_date rather than nothing"
);

// Guard, not a red case: core blanks ORDER BY outright for 'none'
// (class-wp-query.php:2518), so registry order is the answer core gives and a
// refusal here would refuse a query this class already answers correctly.
assert_same(
	'',
	wp_shim_wp_query_error( array( 'post_type' => 'page', 'post_status' => 'any', 'orderby' => 'none' ) ),
	"orderby 'none' blanks core's ORDER BY, so the refusal is scoped to an ordering this stub would actually get wrong rather than to the argument"
);

assert_same(
	'',
	wp_shim_wp_query_error( array( 'post_type' => 'page', 'post_status' => 'any', 'orderby' => 'none', 'order' => 'DESC' ) ),
	'order is inert alongside a blanked ORDER BY, because there is no ordering left for a direction to apply to'
);

// Guard, not a red case: false and an empty array blank ORDER BY in core too
// (class-wp-query.php:2513), and both were already inert here.
assert_same(
	'',
	wp_shim_wp_query_error( array( 'post_type' => 'page', 'post_status' => 'any', 'orderby' => false ) ),
	'a false orderby blanks ORDER BY in core rather than falling back to the post_date default, so it is inert here as well'
);

assert_same(
	"wp-shim WP_Query: posts_per_page '-1' is not modelled. Core reads -1 as unlimited (class-wp-query.php:2024) but anything below -1 as abs() rows (class-wp-query.php:2042-2043), so -5 asks for five rows rather than every row; this stub passes the value straight to array_slice() as a length, which drops rows from the end in either case. Model it in the WP_Query stub or pass a positive cap larger than the fixture set. Alternatively list 'posts_per_page' in \$GLOBALS['diviops_test_wp_query_unmodelled_ok'] when the argument is inert for the fixtures under test.",
	wp_shim_wp_query_error( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1 ) ),
	'the shape a test author reaches for first to mean "every row" raises rather than silently returning all but the last one'
);

// Guard, not a red case: a positive posts_per_page is honoured, and refusing on
// the mere presence of the key would refuse the paging this class does model.
assert_same(
	array( 987701, 987706 ),
	wp_shim_wp_query_type_ids( array( 'post_type' => 'page', 'post_status' => 'publish,trash,draft', 'posts_per_page' => 2 ) ),
	'a positive posts_per_page still pages, so the refusal is scoped to the value core reads as unlimited rather than to the argument'
);

/* -- the waiver seam, and what it deliberately does not waive ----------- */

// Guard, not a red case: the pre-#330 class accepted these arguments anyway, so
// this expectation held before the refusal existed. It is pinned because a
// handler-driven test cannot edit the query its handler builds, and a refusal
// with no seam deletes that handler's coverage outright — three files take this
// waiver (test-media.php for perm, the two tb_template_list files for
// orderby/order) and each justifies inertness in a comment.
assert_same(
	array( 987701, 987708 ),
	wp_shim_wp_query_type_ids(
		array( 'post_type' => 'page', 'post_status' => 'any', 'orderby' => 'ID', 'order' => 'ASC' ),
		array( 'orderby', 'order' )
	),
	'a waived argument is accepted and ignored, which is what a handler-driven test needs when the argument is inert for its fixtures'
);

assert_same(
	"wp-shim WP_Query: perm 'editable' is not modelled. Core narrows to the current user's own posts, and only when that user lacks the edit_others capability for the post type (class-wp-query.php:2694); this stub has no user, role or post_author to compute either half from, so it can neither apply that narrowing nor rule out that core would. Model it in the WP_Query stub or drop 'perm' from the query under test. Alternatively list 'perm' in \$GLOBALS['diviops_test_wp_query_unmodelled_ok'] when the argument is inert for the fixtures under test.",
	wp_shim_wp_query_error(
		array( 'post_type' => 'page', 'post_status' => 'any', 'orderby' => 'ID', 'perm' => 'editable' ),
		array( 'orderby' )
	),
	'the waiver is per argument name rather than a blanket off switch — a file that waives orderby still gets the perm refusal, which is what keeps it from becoming the silent accept it replaced'
);

assert_same(
	'',
	wp_shim_wp_query_error( array( 'post_type' => 'page', 'post_status' => 'publish' ) ),
	'control: a query carrying none of the refused arguments is unaffected by any of this'
);
