<?php
// SPDX-License-Identifier: MIT
/**
 * Characterization: library_list() / library_get() / library_save() (#328).
 *
 * These pin CURRENT behaviour exactly as it is, right or wrong. They are a
 * tripwire for the upstream reconciliation in
 * https://github.com/rubicon/diviops/issues/328, not a statement that every
 * behaviour asserted here is correct: an adoption that changes one of them
 * fails loudly instead of landing unnoticed, which is what turns "did we lose
 * anything?" from an opinion into a test result.
 *
 * Coverage measured before writing them, by searching tests/ and tests-live/
 * for each `public static function` name in the trait, with a positive control
 * (`envelope_success`, 2 files) and a negative one (a name that exists nowhere,
 * 0 files) run first so a zero result was trustworthy:
 *
 *   library_delete  covered   tests/test-library-delete.php, 8 behavioural cases
 *   library_get     UNCOVERED one hit, inside a comment in tests/test-media.php
 *   library_list    UNCOVERED no hit anywhere
 *   library_save    UNCOVERED no hit anywhere
 *
 * library_list() could not have been covered before now: it calls
 * update_object_term_cache(), which the harness did not define, so any call
 * fataled on an undefined function. That shim is added alongside this file and
 * records its calls rather than pretending to fill a cache this harness has no
 * storage for.
 *
 * WHAT IS NOT CHARACTERIZED HERE, and why: taxonomy-scoped FILTERING. Reporting
 * is characterized, and was not when this file was written.
 *
 * The original note said this harness had no term registry to resolve a slug
 * against, which was true of both halves at the time. #358 supplied the registry
 * and taught wp_get_object_terms() the `fields => 'slugs'` shape get_term_slug()
 * actually asks for, so the `layout_type`/`scope` a row REPORTS is real output
 * now and is asserted below. Before that the stub answered `'slugs'` with term
 * objects, so an item carrying terms would have reported a stdClass in a string
 * field, and this file could only ever assert the empty-term branch.
 *
 * Filtering is still not modelled. library_list()'s `layout_type`/`scope` params
 * and library_existing_id_by_title()'s uniqueness scope both express themselves
 * as a `tax_query`, which WP_Query here still refuses. Waiving the argument
 * (below) makes the filter inert, so an assertion about filtered output would be
 * an assertion about the harness's blindness rather than about the handler. That
 * half is left to tests-live/ rather than faked.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/*
 * WP_Query arguments this harness refuses, waived here per the seam
 * diviops_test_query_refuse_unmodelled() documents. Each waiver asserts the
 * argument is inert FOR THESE FIXTURES, and that is this file's claim to
 * justify:
 *
 *   perm     query_inspectable_post_ids() (trait-core.php) sets
 *            `perm => 'editable'` as a coarse prefilter and then re-applies the
 *            exact per-object edit_post check itself. The exact check is what
 *            this file asserts on, through $GLOBALS['diviops_test_uneditable_ids'];
 *            nothing here reads the scan-truncation counters, which is the one
 *            place the prefilter's width could show through. Same justification
 *            tests/test-media.php records for media_list().
 *
 *   orderby  library_list() asks for `modified DESC`. Every assertion below is
 *   order    on membership or count, compared after sorting, and every fixture
 *            set fits inside the scan window, so the truncation that would make
 *            row order decide the returned SET never happens.
 *
 *   tax_query  library_existing_id_by_title() scopes uniqueness to
 *            (layout_type, scope). Ignoring it widens the lookup to every
 *            et_pb_layout carrying the exact title. Inert here because every
 *            et_pb_layout fixture in this file carries a distinct post_title,
 *            so the title predicate alone isolates at most one row — asserted
 *            below at the point it matters rather than assumed.
 */
$GLOBALS['diviops_test_wp_query_unmodelled_ok'] = array( 'perm', 'orderby', 'order', 'tax_query' );

$GLOBALS['diviops_test_posts']             = array();
$GLOBALS['diviops_test_object_terms']      = array();
$GLOBALS['diviops_test_terms']             = array();
$GLOBALS['diviops_test_post_meta']         = array();
$GLOBALS['diviops_test_uneditable_ids']    = array();
$GLOBALS['diviops_test_term_cache_primed'] = array();
$GLOBALS['diviops_test_next_id']           = 9000;

/**
 * Build a request for a library handler.
 *
 * @param array $params Request parameters.
 * @return DiviOps_Test_Request
 */
function diviops_lib_req( array $params ) {
	return new DiviOps_Test_Request( $params );
}

/**
 * Register an et_pb_layout fixture carrying the columns the library handlers read.
 *
 * diviops_test_register_post() does not set post_modified, and both library_get()
 * and library_list() report it, so it is set here rather than left to an
 * undefined-property warning.
 *
 * @param int    $id       Post id.
 * @param string $title    post_title.
 * @param string $status   post_status.
 * @param string $content  post_content.
 * @param string $modified post_modified.
 * @return object
 */
function diviops_lib_layout( int $id, string $title, string $status = 'publish', string $content = '', string $modified = '2026-01-02 03:04:05' ) {
	$post                = diviops_test_register_post( $id, $content, 'et_pb_layout', $title );
	$post->post_status   = $status;
	$post->post_modified = $modified;
	return $post;
}

/**
 * The titles of every et_pb_layout currently in the fixture registry.
 *
 * @return array<int, string>
 */
function diviops_lib_layout_titles(): array {
	$titles = array();
	foreach ( (array) $GLOBALS['diviops_test_posts'] as $post ) {
		if ( 'et_pb_layout' === $post->post_type ) {
			$titles[] = (string) $post->post_title;
		}
	}
	return $titles;
}

/* =========================================================================
 * library_get()
 * ====================================================================== */

// An id with no post behind it is a not_found envelope, not a fatal.
$resp = diviops_call( 'library_get', array( diviops_lib_req( array( 'id' => 4242 ) ) ) );
$data = $resp->get_data();
assert_true( false === $data['ok'], 'library_get: a missing id returns an error envelope' );
assert_same( 'not_found', $data['error']['code'], 'library_get: a missing library item is not_found' );
assert_same( 404, $resp->get_status(), 'library_get: not_found carries HTTP 404' );

// A real post of the wrong type is reported as not a library item, so the
// endpoint cannot be used to read an arbitrary page's raw content.
diviops_test_register_post( 500, 'page body', 'page', 'A Normal Page' );
$resp = diviops_call( 'library_get', array( diviops_lib_req( array( 'id' => 500 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'library_get: a non-et_pb_layout post is not a library item' );

// The row-level read gate: the coarse route capability is edit_posts, and this
// is the per-object edit_post check on top of it.
diviops_lib_layout( 700, 'Gated Block' );
$GLOBALS['diviops_test_uneditable_ids'] = array( 700 );
$resp                                   = diviops_call( 'library_get', array( diviops_lib_req( array( 'id' => 700 ) ) ) );
$data                                   = $resp->get_data();
assert_same( 'forbidden', $data['error']['code'], 'library_get: an uneditable library item is forbidden' );
assert_same( 403, $resp->get_status(), 'library_get: forbidden carries HTTP 403' );
assert_same( 'library_item', $data['error']['data']['target_kind'], 'library_get: the refusal names library_item as the target kind' );
assert_same( 700, $data['error']['data']['post_id'], 'library_get: the refusal echoes the post id' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// The success shape, in full.
diviops_lib_layout( 600, 'Hero Section', 'publish', '<!-- wp:divi/section --><!-- /wp:divi/section -->', '2026-02-03 04:05:06' );
$resp = diviops_call( 'library_get', array( diviops_lib_req( array( 'id' => 600 ) ) ) );
$data = $resp->get_data();
assert_true( true === $data['ok'], 'library_get: a readable library item succeeds' );
assert_same(
	array( 'id', 'title', 'layout_type', 'scope', 'modified', 'content_raw' ),
	array_keys( $data['data'] ),
	'library_get: the response carries exactly these keys, in this order'
);
assert_same( 600, $data['data']['id'], 'library_get: the response echoes the id' );
assert_same( 'Hero Section', $data['data']['title'], 'library_get: the response carries the title' );
assert_same( '<!-- wp:divi/section --><!-- /wp:divi/section -->', $data['data']['content_raw'], 'library_get: content_raw is the stored markup verbatim' );
assert_same( '2026-02-03 04:05:06', $data['data']['modified'], 'library_get: the response carries post_modified' );

// An item carrying no terms reports empty strings rather than null or an error.
// This is get_term_slug()'s empty guard, and it is the branch that decides what
// an unclassified library item looks like to a caller.
assert_same( '', $data['data']['layout_type'], 'library_get: an item with no layout_type term reports an empty string' );
assert_same( '', $data['data']['scope'], 'library_get: an item with no scope term reports an empty string' );

// An item that IS classified reports the slugs, which is the other half of that
// branch and the half this file could not reach before #358: the harness answered
// get_term_slug()'s `fields => 'slugs'` with term objects, so this assertion would
// have compared a string against a stdClass.
diviops_test_register_term( 71, 'section' );
diviops_test_register_term( 72, 'non_global' );
diviops_test_register_term( 73, 'row' );

diviops_lib_layout( 610, 'Classified Hero', 'publish', '', '2026-03-04 05:06:07' );
diviops_test_register_object_terms( 610, 'layout_type', array( 71 ) );
diviops_test_register_object_terms( 610, 'scope', array( 72 ) );
$data = diviops_call( 'library_get', array( diviops_lib_req( array( 'id' => 610 ) ) ) )->get_data();
assert_same( 'section', $data['data']['layout_type'], 'library_get: a classified item reports its layout_type slug' );
assert_same( 'non_global', $data['data']['scope'], 'library_get: a classified item reports its scope slug' );

// get_term_slug() returns element 0 and discards the rest. Divi's own UI assigns
// one layout_type, but nothing in the schema enforces it, and a second term is
// silently invisible rather than reported or refused. What is pinned is that
// exactly one slug survives, NOT which one: core orders terms by name ASC
// (WP_Term_Query's default orderby, wp-includes/class-wp-term-query.php:200)
// while this harness returns them in attachment order, so asserting the winner
// would pin the harness rather than the handler. wp_get_object_terms() here now
// refuses an explicit `orderby` for the same reason.
diviops_test_register_object_terms( 610, 'layout_type', array( 73, 71 ) );
$data     = diviops_call( 'library_get', array( diviops_lib_req( array( 'id' => 610 ) ) ) )->get_data();
$reported = $data['data']['layout_type'];
assert_true( is_string( $reported ), 'library_get: an item carrying several layout_type terms reports a single slug, not a list' );
assert_true( in_array( $reported, array( 'row', 'section' ), true ), 'library_get: the one slug reported is one of the terms actually attached' );

/* =========================================================================
 * library_list()
 * ====================================================================== */

$GLOBALS['diviops_test_posts']             = array();
$GLOBALS['diviops_test_term_cache_primed'] = array();

diviops_lib_layout( 810, 'Alpha' );
diviops_lib_layout( 820, 'Beta' );
diviops_lib_layout( 830, 'Gamma', 'draft' );          // wrong status
diviops_test_register_post( 840, '', 'page', 'Delta' ); // wrong type
diviops_lib_layout( 850, 'Epsilon' );                  // right, but uneditable
$GLOBALS['diviops_test_uneditable_ids'] = array( 850 );

$resp = diviops_call( 'library_list', array( diviops_lib_req( array() ) ) );
$data = $resp->get_data();
assert_true( true === $data['ok'], 'library_list: succeeds' );
assert_same(
	array( 'results', 'total', 'total_pages', 'truncated', 'scanned' ),
	array_keys( $data['data'] ),
	'library_list: the envelope carries exactly these keys, in this order'
);

$ids = array_map(
	static function ( array $row ): int {
		return (int) $row['id'];
	},
	$data['data']['results']
);
sort( $ids );
assert_same( array( 810, 820 ), $ids, 'library_list: a draft item, a non-library post and an uneditable item are all excluded' );
assert_same( 2, $data['data']['total'], 'library_list: total counts the rows that survive the per-object gate' );
assert_same( 1, $data['data']['total_pages'], 'library_list: total_pages is derived from the filtered total' );
assert_true( false === $data['data']['truncated'], 'library_list: a small set is not truncated' );

// scanned counts the coarse candidate set, BEFORE the per-object edit_post
// filter. 850 is scanned and then dropped, so scanned and total differ by it —
// which is how a caller can tell "nothing matched" from "everything matched was
// invisible to you".
assert_same( 3, $data['data']['scanned'], 'library_list: scanned counts candidates before the per-object gate, so it exceeds total' );

assert_same(
	array( 'id', 'title', 'layout_type', 'scope', 'modified' ),
	array_keys( $data['data']['results'][0] ),
	'library_list: a row carries exactly these keys, in this order'
);
assert_same( '', $data['data']['results'][0]['layout_type'], 'library_list: a row with no terms reports an empty layout_type' );

// The term cache is primed once, for exactly the ids on the returned page.
assert_same( 1, count( $GLOBALS['diviops_test_term_cache_primed'] ), 'library_list: primes the object-term cache exactly once' );
$primed = $GLOBALS['diviops_test_term_cache_primed'][0]['ids'];
sort( $primed );
assert_same( array( 810, 820 ), $primed, 'library_list: primes the cache for the ids it is about to read, not the whole candidate set' );
assert_same( 'post', $GLOBALS['diviops_test_term_cache_primed'][0]['object_type'], 'library_list: primes the post object-term cache' );

// Pagination, asserted set-wise because row order is not observable under the
// orderby waiver above.
$GLOBALS['diviops_test_term_cache_primed'] = array();
$page1 = diviops_call( 'library_list', array( diviops_lib_req( array( 'per_page' => 1, 'page' => 1 ) ) ) )->get_data();
$page2 = diviops_call( 'library_list', array( diviops_lib_req( array( 'per_page' => 1, 'page' => 2 ) ) ) )->get_data();
assert_same( 1, count( $page1['data']['results'] ), 'library_list: per_page caps the page size' );
assert_same( 1, count( $page2['data']['results'] ), 'library_list: the second page carries the remainder' );
assert_same( 2, $page1['data']['total_pages'], 'library_list: total_pages reflects per_page' );
$paged = array( (int) $page1['data']['results'][0]['id'], (int) $page2['data']['results'][0]['id'] );
sort( $paged );
assert_same( array( 810, 820 ), $paged, 'library_list: the two pages partition the result set with no repeat' );

// per_page clamps at both ends: 0 becomes 1, and anything above 100 becomes 100.
$clamped_low = diviops_call( 'library_list', array( diviops_lib_req( array( 'per_page' => 0 ) ) ) )->get_data();
assert_same( 1, count( $clamped_low['data']['results'] ), 'library_list: per_page 0 clamps up to 1' );
assert_same( 2, $clamped_low['data']['total_pages'], 'library_list: the clamped per_page is what total_pages divides by' );

$clamped_high = diviops_call( 'library_list', array( diviops_lib_req( array( 'per_page' => 500 ) ) ) )->get_data();
assert_same( 1, $clamped_high['data']['total_pages'], 'library_list: per_page 500 clamps down to 100, which still fits the set on one page' );

// A page past the end is an empty result, not an error — and with no ids to
// read, the cache is not primed at all.
$GLOBALS['diviops_test_term_cache_primed'] = array();
$beyond                                    = diviops_call( 'library_list', array( diviops_lib_req( array( 'per_page' => 1, 'page' => 9 ) ) ) )->get_data();
assert_true( true === $beyond['ok'], 'library_list: a page past the end still succeeds' );
assert_same( array(), $beyond['data']['results'], 'library_list: a page past the end returns no rows' );
assert_same( 2, $beyond['data']['total'], 'library_list: total describes the whole set, not the empty page' );
assert_same( array(), $GLOBALS['diviops_test_term_cache_primed'], 'library_list: an empty page primes nothing' );

// Rows report real slugs, per row rather than per response: 810 is classified on
// both taxonomies and 820 on neither scope, so the populated and empty branches
// are asserted in the same result set. Keyed by id because row order is not
// observable under the orderby waiver above.
diviops_test_register_object_terms( 810, 'layout_type', array( 71 ) );
diviops_test_register_object_terms( 810, 'scope', array( 72 ) );
diviops_test_register_object_terms( 820, 'layout_type', array( 73 ) );

$rows = array();
foreach ( diviops_call( 'library_list', array( diviops_lib_req( array() ) ) )->get_data()['data']['results'] as $row ) {
	$rows[ (int) $row['id'] ] = $row;
}
ksort( $rows );
assert_same( array( 810, 820 ), array_keys( $rows ), 'library_list: both classified fixtures are on the page, so neither assertion below is vacuous' );
assert_same( 'section', $rows[810]['layout_type'], 'library_list: a row reports its layout_type slug' );
assert_same( 'non_global', $rows[810]['scope'], 'library_list: a row reports its scope slug' );
assert_same( 'row', $rows[820]['layout_type'], 'library_list: each row resolves its own terms rather than reusing the first row\'s' );
assert_same( '', $rows[820]['scope'], 'library_list: a row classified on one taxonomy and not the other reports the empty string for the missing one' );

$GLOBALS['diviops_test_uneditable_ids'] = array();

/* =========================================================================
 * library_save()
 * ====================================================================== */

$GLOBALS['diviops_test_posts']        = array();
$GLOBALS['diviops_test_object_terms'] = array();
$GLOBALS['diviops_test_post_meta']    = array();
unset( $GLOBALS['diviops_test_last_insert'] );

$valid = array(
	'title'       => 'Fresh Hero',
	'content'     => '<!-- wp:divi/section --><!-- /wp:divi/section -->',
	'layout_type' => 'section',
	'scope'       => 'non_global',
);

// ── input validation, and the ORDER the guards run in ────────────────────
//
// The order is load-bearing for reconciliation: a preflight inserted ahead of
// these guards changes which error a bad payload gets, and a caller dispatching
// on error.code would silently start seeing a different one.

// A non-string content is rejected first, even when layout_type is also invalid.
$resp = diviops_call(
	'library_save',
	array( diviops_lib_req( array( 'title' => 'X', 'content' => array( 'not', 'a', 'string' ), 'layout_type' => 'bogus', 'scope' => 'non_global' ) ) )
);
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'library_save: a non-string content is invalid_input' );
assert_same( 400, $resp->get_status(), 'library_save: invalid_input carries HTTP 400' );
assert_same( 'content must be a string of Divi block markup.', $data['error']['message'], 'library_save: the content-type guard runs before the layout_type guard' );

$resp = diviops_call( 'library_save', array( diviops_lib_req( array_merge( $valid, array( 'layout_type' => 'bogus' ) ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'library_save: an unknown layout_type is invalid_input' );
assert_same( 'layout_type must be one of: section, row, module.', $data['error']['message'], 'library_save: the layout_type refusal enumerates the allowed values' );

$resp = diviops_call( 'library_save', array( diviops_lib_req( array_merge( $valid, array( 'scope' => 'bogus' ) ) ) ) );
$data = $resp->get_data();
assert_same( 'scope must be one of: global, non_global.', $data['error']['message'], 'library_save: the scope refusal enumerates the allowed values' );

$resp = diviops_call( 'library_save', array( diviops_lib_req( array_merge( $valid, array( 'title' => '   ' ) ) ) ) );
$data = $resp->get_data();
assert_same( 'title is required and must be a non-empty string.', $data['error']['message'], 'library_save: a whitespace-only title is empty after sanitize_text_field' );

// Content shape is NOT validated. This handler byte-copies whatever string it is
// given into the post; it never parses it, and it accepts markup that is not
// Divi blocks at all. That is the current contract, and it is the exact
// behaviour any content-shape preflight would change, so it is pinned rather
// than left implicit.
$resp = diviops_call(
	'library_save',
	array( diviops_lib_req( array_merge( $valid, array( 'title' => 'Plain Prose', 'content' => 'this is not block markup at all', 'dry_run' => true ) ) ) )
);
$data = $resp->get_data();
assert_true( true === $data['ok'], 'library_save: content that is not Divi block markup is accepted — the handler never parses it' );
assert_true( true === $data['data']['dry_run'], 'library_save: dry_run is reported on the plan' );

// An empty string is content too, and is likewise accepted.
$resp = diviops_call(
	'library_save',
	array( diviops_lib_req( array_merge( $valid, array( 'title' => 'Empty Item', 'content' => '', 'dry_run' => true ) ) ) )
);
assert_true( true === $resp->get_data()['ok'], 'library_save: empty content is accepted' );

// ── dry run plans without mutating ───────────────────────────────────────

$before = count( $GLOBALS['diviops_test_posts'] );
$resp   = diviops_call( 'library_save', array( diviops_lib_req( array_merge( $valid, array( 'dry_run' => true ) ) ) ) );
$data   = $resp->get_data();
assert_same( 'library.save', $data['data']['plan']['changes'][0]['kind'], 'library_save: the plan change kind is library.save' );
assert_same( 'et_pb_layout', $data['data']['plan']['changes'][0]['target'], 'library_save: the plan targets et_pb_layout' );
assert_same(
	array(
		'title'       => 'Fresh Hero',
		'layout_type' => 'section',
		'scope'       => 'non_global',
		'bytes'       => strlen( $valid['content'] ),
	),
	$data['data']['plan']['changes'][0]['after'],
	'library_save: the plan reports the resolved title, taxonomies and byte count'
);
assert_same( $before, count( $GLOBALS['diviops_test_posts'] ), 'library_save: a dry run creates nothing' );
assert_true( ! isset( $GLOBALS['diviops_test_last_insert'] ), 'library_save: a dry run never reaches wp_insert_post' );

// ── the real write ───────────────────────────────────────────────────────

$resp = diviops_call( 'library_save', array( diviops_lib_req( $valid ) ) );
$data = $resp->get_data();
assert_true( true === $data['ok'], 'library_save: a valid payload succeeds' );
assert_same(
	array( 'success', 'id', 'title', 'layout_type', 'scope', 'message' ),
	array_keys( $data['data'] ),
	'library_save: the response carries exactly these keys, in this order'
);
assert_true( true === $data['data']['success'], 'library_save: the response carries the legacy success flag alongside the envelope ok' );
assert_same( 9000, $data['data']['id'], 'library_save: the response carries the created id' );
assert_same( "Saved to Divi Library as 'Fresh Hero'.", $data['data']['message'], 'library_save: the message names the saved title' );

$insert = $GLOBALS['diviops_test_last_insert'];
assert_same( 'et_pb_layout', $insert['post_type'], 'library_save: the created post is an et_pb_layout' );
assert_same( 'publish', $insert['post_status'], 'library_save: a library item is created published, with no draft option' );
assert_same( 'Fresh Hero', $insert['post_title'], 'library_save: the sanitized title is what is stored' );
assert_same( $valid['content'], $insert['post_content'], 'library_save: the content is stored verbatim' );

assert_same( 'on', get_post_meta( 9000, '_et_pb_use_divi_5', true ), 'library_save: the item is marked as Divi 5 format' );
assert_same(
	array( 'layout_type', 'scope' ),
	array_keys( $GLOBALS['diviops_test_object_terms'][9000] ),
	'library_save: both taxonomies are written, layout_type first'
);

// The round trip, end to end: what library_save() WROTE is what library_get()
// reads back. The assertion above inspects only the KEYS of the object-term
// store, which is how this went unnoticed -- library_save() passes the slug
// STRINGS 'section' and 'non_global' to wp_set_object_terms()
// (trait-library.php:260-261), and the harness ran array_map( 'intval', ... )
// over them, storing term id 0 under both taxonomies. The keys looked right and
// the values were a term core cannot produce. Reading the slugs back raised, so
// this pair could not be asserted at all until the shim resolved a slug the way
// core does (#358).
$saved = diviops_call( 'library_get', array( diviops_lib_req( array( 'id' => 9000 ) ) ) )->get_data();
assert_true( true === $saved['ok'], 'library_save: the item it created is readable back through library_get' );
assert_same( 'section', $saved['data']['layout_type'], 'library_save: the layout_type it wrote reads back as that slug, not as term id 0' );
assert_same( 'non_global', $saved['data']['scope'], 'library_save: the scope it wrote reads back as that slug' );
assert_same( 'Fresh Hero', $saved['data']['title'], 'library_save: the round trip reads back the item that was just saved, not a leftover fixture' );

// ── title uniqueness ─────────────────────────────────────────────────────
//
// The tax_query waiver's premise, asserted rather than assumed: exactly one
// stored et_pb_layout carries this title, so widening the lookup past
// (layout_type, scope) cannot reach a different row than core would.
assert_same(
	1,
	count( array_keys( diviops_lib_layout_titles(), 'Fresh Hero', true ) ),
	'library_save: exactly one stored item carries the title under test, which is what makes the tax_query waiver inert here'
);

$resp = diviops_call( 'library_save', array( diviops_lib_req( $valid ) ) );
$data = $resp->get_data();
assert_true( false === $data['ok'], 'library_save: re-saving the same title under the same scope is refused' );
assert_same( 'conflict', $data['error']['code'], 'library_save: a duplicate title is a conflict' );
assert_same( 409, $resp->get_status(), 'library_save: conflict carries HTTP 409' );
assert_same(
	array(
		'existing_library_id' => 9000,
		'layout_type'         => 'section',
		'scope'               => 'non_global',
	),
	$data['error']['data'],
	'library_save: the conflict names the colliding item and the scope it collided under'
);

// The uniqueness check runs BEFORE the dry-run plan, so a dry run of a colliding
// save reports the conflict rather than a plan that would fail.
$resp = diviops_call( 'library_save', array( diviops_lib_req( array_merge( $valid, array( 'dry_run' => true ) ) ) ) );
assert_same( 'conflict', $resp->get_data()['error']['code'], 'library_save: a dry run of a colliding save reports the conflict, not a plan' );

/* =========================================================================
 * Where the capability policy lives.
 *
 * library_save() itself performs NO capability check. The whole policy sits in
 * the route's permission callback, in exactly one place, which is what stops the
 * two copies drifting the way #314 documents for the preset ref-scanners. These
 * assertions pin that single site, so a route re-registered against a weaker
 * callback fails here rather than silently widening who can write to the Divi
 * Library.
 * ====================================================================== */

$plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );
$trait_src  = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-library.php' );

assert_true( '' !== $plugin_src && '' !== $trait_src, 'the sources this file inspects were actually read' );

assert_true(
	1 === preg_match(
		"/register_rest_route\\(\\s*self::REST_NAMESPACE,\\s*'\\/library\\/save',\\s*\\[\\s*'methods'\\s*=>\\s*'POST',\\s*'callback'\\s*=>\\s*\\[\\s*__CLASS__,\\s*'library_save'\\s*\\],\\s*'permission_callback'\\s*=>\\s*\\[\\s*__CLASS__,\\s*'check_library_save_permission'\\s*\\]/s",
		$plugin_src
	),
	'/library/save dispatches to library_save behind check_library_save_permission'
);
assert_true(
	1 === preg_match(
		"/function check_library_save_permission\\(\\)\\s*\\{\\s*return self::fixed_publish_route_permission\\(\\s*'manage_options',\\s*\\[\\s*'et_pb_layout'\\s*\\]\\s*\\);/s",
		$plugin_src
	),
	'check_library_save_permission requires manage_options plus the mapped create/publish capabilities for et_pb_layout'
);
assert_same(
	0,
	preg_match_all( '/published_post_types_permission_result|current_user_can\(\s*\'manage_options\'/', $trait_src ),
	'library_save does not re-derive the capability policy the route callback already enforces'
);

assert_true( method_exists( 'DiviOps_Agent', 'library_list' ), 'DiviOps_Agent::library_list exists once the trait is mixed in' );
assert_true( method_exists( 'DiviOps_Agent', 'library_get' ), 'DiviOps_Agent::library_get exists once the trait is mixed in' );
assert_true( method_exists( 'DiviOps_Agent', 'library_save' ), 'DiviOps_Agent::library_save exists once the trait is mixed in' );

unset(
	$GLOBALS['diviops_test_wp_query_unmodelled_ok'],
	$GLOBALS['diviops_test_uneditable_ids'],
	$GLOBALS['diviops_test_last_insert']
);
$GLOBALS['diviops_test_posts']             = array();
$GLOBALS['diviops_test_object_terms']      = array();
$GLOBALS['diviops_test_terms']             = array();
$GLOBALS['diviops_test_post_meta']         = array();
$GLOBALS['diviops_test_term_cache_primed'] = array();
