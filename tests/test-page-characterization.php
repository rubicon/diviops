<?php
// SPDX-License-Identifier: MIT
/**
 * Characterization of the `trait-page.php` handlers that had no test coverage.
 *
 * `plugins/diviops-agent/includes/trait-page.php` is the largest file this fork
 * inherited — 5,027 lines, 66 functions, 21 public REST handlers — and upstream
 * ships no tests for any of it. Measured by scanning `tests/` and `tests-live/`
 * for each handler name (109 files, positive control run first against a name
 * known to be present), eight handlers had zero references of any kind:
 *
 *   page_get, page_set_meta, page_update_meta, page_update_status,
 *   section_append, section_replace, section_remove, section_get
 *
 * and two more were named only in prose in another file's docblock, never
 * driven: `page_list` (mentioned by tests/test-page-create.php when contrasting
 * its own write-safety rule) and `page_trash` (mentioned by
 * tests/test-library-delete.php for the same reason). This file characterizes
 * all ten, plus `page_update_content`, whose write path had only source-level
 * wiring coverage in tests/test-page-update-meta-guard.php.
 *
 * A characterization test records what the code does TODAY, right or wrong, so a
 * later edit — an upstream adoption in particular — cannot change it silently.
 * Several behaviours pinned below are defects. They are pinned AS defects, each
 * marked `DEFECT` in the comment directly above its assertion and listed in the
 * PR. Do not "fix" one by editing the expectation here; the assertion failing is
 * the signal it exists to produce, and a real fix should land as its own issue
 * that deliberately updates the line.
 *
 * ── What is NOT covered here, and why ────────────────────────────────────
 *
 *   - `page_get_layout()` calls WordPress's real `parse_blocks()` as its first
 *     act. This harness deliberately does not shim that (see tests/wp-shim.php
 *     and tests/test-module-fallback-trigger-wiring.php: a partial
 *     reimplementation built for the fixtures at hand would be mocking the
 *     thing under test). The same boundary rules out `module_get`/`module_move`
 *     parser-fallback result correctness. Unchanged by this file.
 *   - `page_create`, `page_duplicate`, `page_block_insert`, `module_update`,
 *     `module_move`, `module_lock`, `module_clone` already have behavioural
 *     files of their own; this file does not duplicate them.
 *   - `module_unlock` is reached only through `walk_and_mutate`, which
 *     tests/test-namespace-agnostic-targeting.php drives directly. Left there.
 *
 * Two WordPress-core primitives this trait calls unguarded are absent from
 * `tests/wp-shim.php`, which this file must not edit. They live in
 * `tests/wp-date-hierarchy-shim.php`, transcribed from core with the source
 * line cited: `is_post_type_hierarchical()` (page_update_meta's parent branch)
 * and `get_date_from_gmt()` (page_update_status's future branch).
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-date-hierarchy-shim.php';

// ── Fixture bookkeeping ───────────────────────────────────────────────────
//
// Every registry this file touches is process-wide and shared with the other 73
// test files. Everything created or changed here is recorded now and undone at
// the bottom, and the teardown asserts it actually emptied so a future edit that
// adds a fixture and forgets the bookkeeping fails HERE rather than as a
// confusing failure in a file that happens to run later.
//
// run.php requires each test file inside a closure, so a variable written at
// this file's top level is local to that closure and NOT a global. The
// bookkeeping list therefore lives in $GLOBALS explicitly, which is what lets
// diviops_pgc_post() append to the array the teardown reads.

$GLOBALS['diviops_pgc_fixture_ids'] = array();

$diviops_pgc_saved = array(
	'wp_query_waivers' => $GLOBALS['diviops_test_wp_query_unmodelled_ok'] ?? array(),
	'page_type_args'   => $GLOBALS['diviops_test_post_types']['page'],
	'gmt_offset_set'   => array_key_exists( 'gmt_offset', (array) ( $GLOBALS['diviops_test_options'] ?? array() ) ),
	'gmt_offset'       => $GLOBALS['diviops_test_options']['gmt_offset'] ?? null,
	'next_id'          => (int) ( $GLOBALS['diviops_test_next_id'] ?? 9000 ),
);

/**
 * Register a fixture post and remember it for teardown.
 *
 * `diviops_test_register_post()` leaves post_modified, post_modified_gmt,
 * post_name and post_date_gmt unset. Every one of them is read by a handler
 * below (`page_list` reads post_modified, `page_meta_readback()` reads
 * post_name/post_modified_gmt, `page_update_status()` reads post_date_gmt), and
 * an unset property is a PHP warning in the run rather than a clean failure, so
 * they are set here explicitly.
 *
 * @param int    $id      Post id.
 * @param string $content post_content.
 * @param string $type    post_type.
 * @param string $title   post_title.
 * @param string $status  post_status.
 * @param string $slug    post_name.
 * @return object The registered post.
 */
function diviops_pgc_post( int $id, string $content, string $type = 'page', string $title = '', string $status = 'publish', string $slug = '' ) {
	$GLOBALS['diviops_pgc_fixture_ids'][] = $id;
	$post                     = diviops_test_register_post( $id, $content, $type, $title );
	$post->post_status        = $status;
	$post->post_name          = $slug;
	$post->post_modified      = '2026-01-02 03:04:05';
	$post->post_modified_gmt  = '2026-01-02 03:04:05';
	$post->post_date          = '2026-01-01 00:00:00';
	$post->post_date_gmt      = '2026-01-01 00:00:00';
	return $post;
}

/**
 * Invoke a handler with a request built from the given params.
 *
 * @param string $method Handler name on DiviOps_Agent.
 * @param array  $params Request params.
 * @return mixed WP_REST_Response, or WP_Error for the one handler that still returns one.
 */
function diviops_pgc_call( string $method, array $params = array() ) {
	return diviops_call( $method, array( new DiviOps_Test_Request( $params ) ) );
}

/**
 * The `id` field of every row in a page_list result, sorted.
 *
 * Sorted on purpose: this harness's WP_Query returns fixtures in registry order
 * whatever `orderby` asks for, so ORDER is not a property this file may assert.
 * See the waiver note above the page_list section.
 *
 * @param array $data page_list envelope data.
 * @return array<int, int>
 */
function diviops_pgc_result_ids( array $data ): array {
	$ids = array_map(
		static function ( array $row ): int {
			return (int) $row['id'];
		},
		$data['results'] ?? array()
	);
	sort( $ids );
	return $ids;
}

/**
 * The one row in a page_list result carrying this id.
 *
 * @param array $data page_list envelope data.
 * @param int   $id   Post id.
 * @return array
 */
function diviops_pgc_row( array $data, int $id ): array {
	foreach ( $data['results'] ?? array() as $row ) {
		if ( (int) $row['id'] === $id ) {
			return $row;
		}
	}
	return array();
}

// Divi markup used throughout. Attribute-free openers on purpose: the write path
// runs normalize_divi_full_content_for_write(), which rewrites a Divi opener's
// attrs through serialize_block_attrs_canonical(). An attr-free opener takes that
// function's `'' === $trimmed_tail` branch (trait-core.php:500-502) and
// re-serializes to exactly `<!-- wp:NAME -->`, so an expected string built by hand
// here is byte-exact for a reason, not by luck.
$diviops_pgc_divi   = '<!-- wp:divi/section --><!-- wp:divi/text -->Body<!-- /wp:divi/text --><!-- /wp:divi/section -->';
$diviops_pgc_plain  = '<!-- wp:paragraph -->Plain<!-- /wp:paragraph -->';

// ══ post_uses_divi / content_uses_divi ════════════════════════════════════
//
// The predicate behind `has_divi` on every page_get/page_list row AND behind the
// #45 Divi-meta init guard on every content write. Driven directly because both
// callers only expose its boolean.

assert_same( true, diviops_call( 'content_uses_divi', array( $diviops_pgc_divi ) ), 'content_uses_divi is true for divi/* block markup' );
assert_same( false, diviops_call( 'content_uses_divi', array( $diviops_pgc_plain ) ), 'content_uses_divi is false for core block markup' );
assert_same( false, diviops_call( 'content_uses_divi', array( 42 ) ), 'a non-string is false rather than a type error' );

// DEFECT, pinned as-is. Both predicates test for the literal namespace prefix
// `divi/`, so a page assembled purely from third-party Divi modules — the
// `difl/*` and `d5bgo/*` blocks this fork's own reference page 900390 carries —
// reports has_divi false, and a page_update_content write of such content skips
// initialize_divi_page_meta() entirely. tests/test-namespace-agnostic-targeting.php
// established for the targeting layer that a third-party namespace is a
// first-class Divi module; this predicate never got the same treatment.
assert_same(
	false,
	diviops_call( 'content_uses_divi', array( '<!-- wp:difl/faq /-->' ) ),
	'DEFECT: content built only from a third-party Divi module namespace is not recognised as Divi'
);

// ══ page_get ══════════════════════════════════════════════════════════════

$response = diviops_pgc_call( 'page_get', array( 'id' => 999731 ) );
$body     = $response->get_data();
assert_same( false, $body['ok'] ?? null, 'page_get refuses an unknown id' );
assert_same( 'not_found', $body['error']['code'] ?? null, 'the refusal code is not_found' );
assert_same( 404, $response->get_status(), 'the unknown-id refusal is a 404' );
assert_same( 999731, $body['error']['data']['page_id'] ?? null, 'the refusal names the id it looked for' );

$diviops_pgc_page = diviops_pgc_post( 7300, $diviops_pgc_divi, 'page', 'Home', 'publish', 'home' );

$response = diviops_pgc_call( 'page_get', array( 'id' => 7300 ) );
$data     = $response->get_data()['data'] ?? array();
assert_same( 200, $response->get_status(), 'a successful read is a 200' );
assert_same( 7300, $data['id'] ?? null, 'the payload names the post id' );
assert_same( 'Home', $data['title'] ?? null, 'and its title' );
assert_same( 'publish', $data['status'] ?? null, 'and its status' );
assert_same( 'page', $data['post_type'] ?? null, 'and its post type' );
// `url` is whatever get_permalink() answers for this id, not a string this file
// composes — comparing against the primitive pins the plugin's contract ("the
// row's url is the post's permalink") without also pinning the harness's
// placeholder URL format.
assert_same( get_permalink( 7300 ), $data['url'] ?? null, 'url is get_permalink() of the row' );
assert_same( '2026-01-02 03:04:05', $data['modified'] ?? null, 'modified is the stored post_modified, not the GMT column' );
assert_same( true, $data['has_divi'] ?? null, 'has_divi reports the divi/* markup' );
assert_same( $diviops_pgc_divi, $data['content_raw'] ?? null, 'page_get returns raw post_content unparsed' );

diviops_pgc_post( 7301, $diviops_pgc_plain, 'page', 'Not Divi' );
$data = diviops_pgc_call( 'page_get', array( 'id' => 7301 ) )->get_data()['data'] ?? array();
assert_same( false, $data['has_divi'] ?? null, 'a core-block page reports has_divi false' );

$GLOBALS['diviops_test_uneditable_ids'] = array( 7300 );
$response = diviops_pgc_call( 'page_get', array( 'id' => 7300 ) );
$body     = $response->get_data();
assert_same( 'forbidden', $body['error']['code'] ?? null, 'the row-level edit_post gate refuses an uninspectable page' );
assert_same( 403, $response->get_status(), 'the row-level read refusal is a 403' );
assert_same( 'page', $body['error']['data']['target_kind'] ?? null, 'the refusal names the target kind' );
assert_same( 7300, $body['error']['data']['post_id'] ?? null, 'and the post id, under post_id rather than page_id' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// ══ page_list ═════════════════════════════════════════════════════════════
//
// Two WP_Query arguments this handler sends are refused by the harness stub
// rather than modelled (see diviops_test_query_refuse_unmodelled()). Both are
// waived here, and the waiver is only honest with a stated justification:
//
//   - `orderby`/`order` ('modified'/'DESC'): the stub returns registry order
//     whatever core would do. Waived because NOTHING below asserts order — every
//     result-set assertion sorts (diviops_pgc_result_ids) or looks a row up by id
//     (diviops_pgc_row), and every per_page used is either larger than the fixture
//     set or paged over the whole of it, so no assertion can change with the
//     ordering. Order is a real gap in this file's coverage, named as such.
//   - `perm` ('editable'): core narrows to the caller's own posts, and only when
//     that user lacks edit_others_* for the type; this harness has no user, role
//     or post_author to compute either half from. Waived because the row-level
//     boundary this handler actually enforces is the per-object edit_post check
//     that query_inspectable_post_ids() applies AFTER the query, and that check IS
//     modelled and is asserted directly below.
//
// Fixtures use post types of this file's own so the shared registry's other 70-odd
// fixture posts cannot change the counts.

$GLOBALS['diviops_test_wp_query_unmodelled_ok'] = array( 'orderby', 'order', 'perm' );

diviops_test_register_post_type( 'diviops_pgc_type', array( 'public' => true ) );
diviops_pgc_post( 7310, $diviops_pgc_divi, 'diviops_pgc_type', 'Alpha', 'publish' );
diviops_pgc_post( 7311, $diviops_pgc_plain, 'diviops_pgc_type', 'Bravo', 'draft' );
diviops_pgc_post( 7312, $diviops_pgc_plain, 'diviops_pgc_type', 'Charlie', 'private' );
diviops_pgc_post( 7313, $diviops_pgc_plain, 'diviops_pgc_type', 'Delta', 'pending' );
diviops_pgc_post( 7314, $diviops_pgc_plain, 'diviops_pgc_type', 'Echo', 'trash' );

$data = diviops_pgc_call( 'page_list', array( 'post_type' => 'diviops_pgc_type' ) )->get_data()['data'] ?? array();
// The status list is hardcoded in the handler (trait-page.php:35) as exactly
// publish/draft/private, so pending and trash are outside the inventory.
assert_same( array( 7310, 7311, 7312 ), diviops_pgc_result_ids( $data ), 'page_list inventories publish, draft and private only' );
assert_same( 3, $data['total'] ?? null, 'total counts the inspectable set, not the whole post type' );
assert_same( 1, $data['total_pages'] ?? null, 'three rows fit one default page' );
assert_same( false, $data['truncated'] ?? null, 'a scan well under the 5000-candidate ceiling is not truncated' );
assert_same( 3, $data['scanned'] ?? null, 'scanned counts the candidate rows the query returned' );

$row = diviops_pgc_row( $data, 7310 );
assert_same( 'Alpha', $row['title'] ?? null, 'a row carries the post title' );
assert_same( 'publish', $row['status'] ?? null, 'and its status' );
assert_same( get_permalink( 7310 ), $row['url'] ?? null, 'and get_permalink() of the row' );
assert_same( '2026-01-02 03:04:05', $row['modified'] ?? null, 'and post_modified' );
assert_same( true, $row['has_divi'] ?? null, 'and whether it uses Divi' );
assert_same( false, diviops_pgc_row( $data, 7311 )['has_divi'] ?? null, 'which is false for the core-block row' );
assert_true( ! array_key_exists( 'content_raw', $row ), 'a list row carries no content — that is page_get\'s job' );

// The row-level filter runs AFTER the query, so a row the caller cannot edit is
// counted as scanned but never appears and never reaches `total`. That gap
// between `scanned` and `total` is the observable proof the check is per-object
// rather than folded into the query.
$GLOBALS['diviops_test_uneditable_ids'] = array( 7311 );
$data = diviops_pgc_call( 'page_list', array( 'post_type' => 'diviops_pgc_type' ) )->get_data()['data'] ?? array();
assert_same( array( 7310, 7312 ), diviops_pgc_result_ids( $data ), 'a row the caller cannot edit_post is dropped from the inventory' );
assert_same( 2, $data['total'] ?? null, 'total describes the filtered set' );
assert_same( 3, $data['scanned'] ?? null, 'but scanned still counts the row that was filtered out' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// An unknown post_type is silently retargeted to 'page' rather than refused.
// tests/test-page-create.php calls this out in prose as the divergence its own
// write-side rejection exists against; this is the read side of that pair,
// asserted.
//
// The fallback target is hardcoded as 'page', so unlike every other page_list
// probe here it cannot be scoped by using a post type of this file's own. The
// registry is swapped for exactly this file's two page fixtures instead — the
// same containment tests/test-preset-ref-scan-post-types.php uses — because the
// shared registry's other `page` rows belong to files that never set
// post_modified, and page_list reads that column on every row.
$diviops_pgc_all_posts         = $GLOBALS['diviops_test_posts'];
$GLOBALS['diviops_test_posts'] = array(
	7300 => $diviops_pgc_all_posts[7300],
	7301 => $diviops_pgc_all_posts[7301],
);
assert_same( false, post_type_exists( 'diviops_pgc_absent' ), 'control: the type under test really is unregistered' );
assert_same( true, post_type_exists( 'page' ), 'control: the fallback type is registered' );
$fallback   = diviops_pgc_call( 'page_list', array( 'post_type' => 'diviops_pgc_absent' ) )->get_data()['data'] ?? array();
$as_page    = diviops_pgc_call( 'page_list', array( 'post_type' => 'page' ) )->get_data()['data'] ?? array();
$empty_type = diviops_pgc_call( 'page_list', array( 'post_type' => '' ) )->get_data()['data'] ?? array();
$GLOBALS['diviops_test_posts'] = $diviops_pgc_all_posts;
// Positive control first: the comparison below is only evidence if the `page`
// call returned something. Two identical empty results would satisfy an equality
// assertion while proving nothing.
assert_same( array( 7300, 7301 ), diviops_pgc_result_ids( $as_page ), 'control: an explicit page call inventories the page fixtures' );
assert_same( $as_page, $fallback, 'an unknown post_type silently falls back to page rather than refusing' );
assert_same( $as_page, $empty_type, 'an empty post_type takes the same fallback' );

// `page` is floored at 1. Page 0 and page 1 are the same page; pages 1 and 2
// together cover the set exactly once.
//
// FINDING (behaviour unchanged, recorded here): page_list's own
// `max( absint( … ), 1 )` (trait-page.php:28) is dead. Every path through it
// reaches query_inspectable_post_ids(), which floors the page number again
// (trait-core.php:2020) before it is used for anything. Mutating either line
// alone leaves the floor intact — mutating both turns the assertion below red,
// which is what says the behaviour is pinned and the duplication is redundant
// rather than uncovered.
$page_zero = diviops_pgc_call( 'page_list', array( 'post_type' => 'diviops_pgc_type', 'per_page' => 2, 'page' => 0 ) )->get_data()['data'] ?? array();
$page_one  = diviops_pgc_call( 'page_list', array( 'post_type' => 'diviops_pgc_type', 'per_page' => 2, 'page' => 1 ) )->get_data()['data'] ?? array();
$page_two  = diviops_pgc_call( 'page_list', array( 'post_type' => 'diviops_pgc_type', 'per_page' => 2, 'page' => 2 ) )->get_data()['data'] ?? array();
assert_same( diviops_pgc_result_ids( $page_one ), diviops_pgc_result_ids( $page_zero ), 'page=0 is floored to page 1' );
assert_same( 2, count( $page_one['results'] ?? array() ), 'per_page caps the first page at two rows' );
assert_same( 2, $page_one['total_pages'] ?? null, 'total_pages divides the filtered total by per_page' );
assert_same( 1, count( $page_two['results'] ?? array() ), 'the second page carries the remainder' );
$paged = array_merge( diviops_pgc_result_ids( $page_one ), diviops_pgc_result_ids( $page_two ) );
sort( $paged );
assert_same( array( 7310, 7311, 7312 ), $paged, 'the two pages together cover the set exactly once' );

// per_page is capped at 100 (trait-page.php:27). Proving a cap needs more rows
// than the cap, so this block registers 101 of its own — with a cap of 100 and a
// request for 150 the first page is 100 rows and there are two pages, both of
// which would change if the cap moved.
diviops_test_register_post_type( 'diviops_pgc_bulk', array( 'public' => true ) );
for ( $diviops_pgc_i = 0; $diviops_pgc_i < 101; $diviops_pgc_i++ ) {
	diviops_pgc_post( 7400 + $diviops_pgc_i, '', 'diviops_pgc_bulk', 'Bulk ' . $diviops_pgc_i );
}
$bulk = diviops_pgc_call( 'page_list', array( 'post_type' => 'diviops_pgc_bulk', 'per_page' => 150 ) )->get_data()['data'] ?? array();
assert_same( 101, $bulk['total'] ?? null, 'control: all 101 bulk fixtures are inspectable' );
assert_same( 100, count( $bulk['results'] ?? array() ), 'per_page is capped at 100 however large the request' );
assert_same( 2, $bulk['total_pages'] ?? null, 'so 101 rows page as two, not one' );

$GLOBALS['diviops_test_wp_query_unmodelled_ok'] = $diviops_pgc_saved['wp_query_waivers'];

// ══ page_set_meta ═════════════════════════════════════════════════════════
//
// The one public handler in this trait that never adopted the response envelope:
// it returns a bare WP_Error on refusal and a bare array on success, so a client
// written against `{ ok, data }` / `{ ok, error }` cannot read it at all. Pinned
// as the current shape, defect and all.

diviops_pgc_post( 7320, $diviops_pgc_divi, 'page', 'Template Target' );

$result = diviops_pgc_call( 'page_set_meta', array( 'id' => 999732, 'template' => 'x.php' ) );
// DEFECT, pinned as-is. Every sibling handler returns an envelope response here.
assert_same( true, is_wp_error( $result ), 'DEFECT: page_set_meta returns a raw WP_Error, not the not_found envelope' );
assert_same( 'not_found', $result->get_error_code(), 'the error code is not_found' );
assert_same( 404, $result->get_error_data()['status'] ?? null, 'carrying a 404 in error data rather than a response status' );

$GLOBALS['diviops_test_denied_caps'] = array( 'edit_post' );
$result = diviops_pgc_call( 'page_set_meta', array( 'id' => 7320, 'template' => 'blocked.php' ) );
assert_same( true, is_wp_error( $result ), 'the capability refusal is also a raw WP_Error' );
assert_same( 'forbidden', $result->get_error_code(), 'the refusal code is forbidden' );
assert_same( 403, $result->get_error_data()['status'] ?? null, 'with a 403 in error data' );
assert_same( '', get_post_meta( 7320, '_wp_page_template', true ), 'the refusal wrote no template meta' );
$GLOBALS['diviops_test_denied_caps'] = array();

$response = diviops_pgc_call( 'page_set_meta', array( 'id' => 7320, 'template' => 'page-template-blank.php' ) );
$body     = $response->get_data();
assert_same( false, array_key_exists( 'ok', $body ), 'the success shape is bare — no ok/data envelope' );
assert_same( true, $body['success'] ?? null, 'it reports success under its own key' );
assert_same( 7320, $body['page_id'] ?? null, 'names the page' );
assert_same( 'page-template-blank.php', $body['template'] ?? null, 'and reads the template back from meta' );
assert_same( 'page-template-blank.php', get_post_meta( 7320, '_wp_page_template', true ), 'the template was stored' );

// The write is guarded by `if ( $template )` (trait-page.php:160), a truthiness
// test rather than a null/'' test, so an omitted template is a no-op read-back.
$body = diviops_pgc_call( 'page_set_meta', array( 'id' => 7320 ) )->get_data();
assert_same( 'page-template-blank.php', $body['template'] ?? null, 'omitting template leaves the stored value alone' );
$body = diviops_pgc_call( 'page_set_meta', array( 'id' => 7320, 'template' => '' ) )->get_data();
assert_same( 'page-template-blank.php', $body['template'] ?? null, 'an empty template is falsy and skips the write' );

// DEFECT, pinned as-is. PHP's truthiness makes the string '0' falsy, so a
// template file legitimately named `0` (or any caller sending the string '0')
// is silently dropped instead of stored, with a success response either way.
$body = diviops_pgc_call( 'page_set_meta', array( 'id' => 7320, 'template' => '0' ) )->get_data();
assert_same( 'page-template-blank.php', $body['template'] ?? null, "DEFECT: the literal string '0' is falsy, so it is silently not written" );
assert_same( true, $body['success'] ?? null, 'DEFECT: and the response still reports success' );

// ══ page_update_meta ══════════════════════════════════════════════════════

// `page` must be registered hierarchical for the parent branch to be reachable at
// all: WordPress core registers it that way in create_initial_post_types()
// (wp-includes/post.php:72, `'hierarchical' => true`), but tests/wp-shim.php seeds
// its built-in types recording only `public`, so the flag has to be supplied here.
// `post` stays non-hierarchical, matching core (wp-includes/post.php:36).
diviops_test_register_post_type( 'page', array( 'public' => true, 'hierarchical' => true ) );
assert_same( true, is_post_type_hierarchical( 'page' ), 'control: pages are hierarchical, so the parent branch is reachable' );
assert_same( false, is_post_type_hierarchical( 'post' ), 'control: posts are not, so the refusal branch is reachable too' );

diviops_pgc_post( 7330, $diviops_pgc_divi, 'page', 'Meta Target', 'publish', 'original-slug' );
diviops_pgc_post( 7331, $diviops_pgc_plain, 'page', 'Draft Target', 'draft', 'draft-one' );
diviops_pgc_post( 7332, $diviops_pgc_plain, 'post', 'Post Target', 'publish', 'post-target' );
diviops_pgc_post( 7333, $diviops_pgc_plain, 'post', 'Post Parent', 'publish', 'post-parent' );
diviops_pgc_post( 7334, $diviops_pgc_plain, 'page', 'Page Parent', 'publish', 'page-parent' );

$response = diviops_pgc_call( 'page_update_meta', array( 'id' => 999733, 'title' => 'X' ) );
assert_same( 'not_found', $response->get_data()['error']['code'] ?? null, 'an unknown id is not_found' );
assert_same( 404, $response->get_status(), 'and a 404' );

$GLOBALS['diviops_test_denied_caps'] = array( 'edit_post' );
$response = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'title' => 'X' ) );
assert_same( 'forbidden', $response->get_data()['error']['code'] ?? null, 'the edit_post gate refuses the write' );
assert_same( 403, $response->get_status(), 'and returns a 403' );
$GLOBALS['diviops_test_denied_caps'] = array();

// Aliases: {title, post_title}, {slug, post_name}, {parent, post_parent}. Two
// aliases carrying DIFFERENT values is a refusal; two carrying the same value is
// not, because the caller's intent is unambiguous.
$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'title' => 'A', 'post_title' => 'B' ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'conflicting title aliases are refused' );
assert_same( 'title', $body['error']['data']['field'] ?? null, 'the refusal names the logical field' );
assert_same( array( 'title', 'post_title' ), $body['error']['data']['keys'] ?? null, 'and both alias keys it saw' );
assert_same( array( 'title' => 'A', 'post_title' => 'B' ), $body['error']['data']['values'] ?? null, 'and their values' );

$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330 ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a request naming no field at all is refused' );
assert_same( array( 'title', 'slug', 'parent', 'menu_order' ), $body['error']['data']['fields'] ?? null, 'the refusal lists the four accepted fields' );

$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'title' => 42 ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a non-string title is refused' );
assert_same( 'integer', $body['error']['data']['received_type'] ?? null, 'and the refusal reports the type it got' );

$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'title' => '   ' ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a whitespace-only title is empty after trim and refused' );

// Slugs must arrive already sanitized; the handler refuses rather than
// sanitizing silently, and hands back the value it would have used.
// `sanitize_title( 'About Us' )` is 'about-us' under core (remove_accents then
// sanitize_title_with_dashes: lowercase, whitespace runs to single hyphens) and
// under this harness's simplified model alike, so the expectation does not
// depend on which one is loaded.
$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'slug' => 'About Us' ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'an unsanitized slug is refused, not silently sanitized' );
assert_same( 'About Us', $body['error']['data']['received'] ?? null, 'the refusal echoes what was sent' );
assert_same( 'about-us', $body['error']['data']['sanitized'] ?? null, 'and offers the sanitized form to retry with' );
assert_same( 'original-slug', $GLOBALS['diviops_test_posts'][7330]->post_name, 'and nothing was written' );

$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'slug' => '!!!' ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a slug that sanitizes to nothing is refused' );
assert_same( '', $body['error']['data']['sanitized'] ?? null, 'with an empty sanitized form' );

// Parent validation, in the order the handler applies it.
$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'parent' => 'abc' ) )->get_data();
assert_same( 'string', $body['error']['data']['received_type'] ?? null, 'a non-numeric parent is refused by type' );

$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'parent' => 7330 ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a post cannot be its own parent' );
assert_same( 7330, $body['error']['data']['received'] ?? null, 'and the refusal names the id it rejected' );

$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'parent' => -1 ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a negative parent is refused' );

$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7332, 'parent' => 7333 ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a non-hierarchical post type cannot take a parent' );
assert_same( 'post', $body['error']['data']['post_type'] ?? null, 'and the refusal names that post type' );

$response = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'parent' => 999734 ) );
assert_same( 'not_found', $response->get_data()['error']['code'] ?? null, 'a parent id that does not exist is not_found' );
assert_same( 404, $response->get_status(), 'and a 404, not a 400' );

$response = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'parent' => 7332 ) );
assert_same( 'not_found', $response->get_data()['error']['code'] ?? null, 'a parent of a different post type is not_found too' );
assert_same( 'page', $response->get_data()['error']['data']['post_type'] ?? null, 'reported against the child\'s post type' );

$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'menu_order' => -1 ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a negative menu_order is refused' );
assert_same( -1, $body['error']['data']['received'] ?? null, 'and the refusal echoes it' );

$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'menu_order' => 'x' ) )->get_data();
assert_same( 'string', $body['error']['data']['received_type'] ?? null, 'a non-numeric menu_order is refused by type' );

// A request that asks for the values already stored is a no-op success, not a
// write and not a refusal.
$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'title' => 'Meta Target' ) )->get_data();
assert_same( true, $body['data']['noop'] ?? null, 'requesting the stored value is reported as a no-op' );
assert_same( 'Meta Target', $body['data']['title'] ?? null, 'and the current record comes back with it' );
assert_same( true, $body['data']['preserve_old_slug'] ?? null, 'preserve_old_slug defaults to true' );

$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'title' => 'Renamed', 'dry_run' => true ) )->get_data();
$plan = $body['data']['plan'] ?? array();
assert_same( true, $body['data']['dry_run'] ?? null, 'a dry run reports itself as one' );
assert_same( 1, count( $plan['changes'] ?? array() ), 'one requested field is one planned change' );
assert_same( 'page.update_meta.title', $plan['changes'][0]['kind'] ?? null, 'the change kind names the field' );
assert_same( 'page#7330', $plan['changes'][0]['target'] ?? null, 'the target names the page' );
assert_same( 'Meta Target', $plan['changes'][0]['before'] ?? null, 'with the stored value as before' );
assert_same( 'Renamed', $plan['changes'][0]['after'] ?? null, 'and the requested value as after' );
assert_same( false, $body['data']['old_slug_would_record'] ?? null, 'a title-only change records no old slug' );
assert_same( 'Meta Target', $GLOBALS['diviops_test_posts'][7330]->post_title, 'a dry run writes nothing' );

// Old-slug redirect bookkeeping. NOTE: WordPress core's own
// wp_check_for_changed_slugs() (wp-includes/post.php:7567-7591) returns early for
// hierarchical post types, so core would record NOTHING for a page. This handler
// implements the bookkeeping itself and applies it to pages too — a deliberate
// divergence from core, pinned here so an upstream adoption cannot drop it
// silently.
$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'slug' => 'new-slug' ) )->get_data();
assert_same( 'new-slug', $body['data']['slug'] ?? null, 'the readback reports the new slug' );
assert_same( 'new-slug', $body['data']['sanitized_slug'] ?? null, 'and the sanitized form it stored' );
assert_same( true, $body['data']['old_slug_recorded'] ?? null, 'a published post records its previous slug' );
assert_same( false, $body['data']['old_slug_removed'] ?? null, 'and removes nothing' );
assert_same( 'new-slug', $GLOBALS['diviops_test_posts'][7330]->post_name, 'the slug was written' );
assert_same( array( 'original-slug' ), get_post_meta( 7330, '_wp_old_slug', false ), 'the previous slug is a _wp_old_slug row' );

// Renaming BACK to a slug already in the redirect list both records the outgoing
// slug and drops the reclaimed one, mirroring core's rule at post.php:7588-7590.
$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'slug' => 'original-slug' ) )->get_data();
assert_same( true, $body['data']['old_slug_recorded'] ?? null, 'the outgoing slug is recorded' );
assert_same( true, $body['data']['old_slug_removed'] ?? null, 'and the reclaimed slug is dropped from the list' );
assert_same( array( 'new-slug' ), get_post_meta( 7330, '_wp_old_slug', false ), 'leaving only the slug that is now historical' );

// DEFECT, pinned as-is. `preserve_old_slug: false` reads as "do not keep old
// slugs", but it only suppresses recording THIS rename and deletes the outgoing
// slug when it happens to already be in the list. Slugs accumulated by earlier
// renames survive untouched, so a caller using the flag to stop redirecting is
// still redirecting.
$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'slug' => 'third-slug', 'preserve_old_slug' => false ) )->get_data();
assert_same( false, $body['data']['old_slug_recorded'] ?? null, 'preserve_old_slug=false records nothing' );
assert_same( false, $body['data']['old_slug_removed'] ?? null, 'and removes nothing here' );
assert_same( array( 'new-slug' ), get_post_meta( 7330, '_wp_old_slug', false ), 'DEFECT: the accumulated redirect survives preserve_old_slug=false' );

$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'slug' => 'new-slug', 'preserve_old_slug' => false ) )->get_data();
assert_same( true, $body['data']['old_slug_removed'] ?? null, 'reclaiming a listed slug does drop it, even with preserve_old_slug=false' );
assert_same( array(), get_post_meta( 7330, '_wp_old_slug', false ), 'and the list empties' );

// The old-slug rule is gated on the CURRENT status being publish, so a draft
// renames without leaving a redirect — and a dry run says so as a warning.
$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7331, 'slug' => 'draft-two', 'dry_run' => true ) )->get_data();
assert_same(
	array( 'Old-slug redirect meta is only recorded for currently published posts with a non-empty previous slug.' ),
	$body['data']['plan']['warnings'] ?? null,
	'a draft slug change warns that no redirect will be recorded'
);
$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7331, 'slug' => 'draft-two' ) )->get_data();
assert_same( false, $body['data']['old_slug_recorded'] ?? null, 'and the apply records none' );
assert_same( array(), get_post_meta( 7331, '_wp_old_slug', false ), 'leaving the meta empty' );

// Parent + menu_order together, applied.
$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'parent' => 7334, 'menu_order' => 5 ) )->get_data();
assert_same( 7334, $body['data']['parent'] ?? null, 'the readback reports the new parent' );
assert_same( 5, $body['data']['menu_order'] ?? null, 'and the new menu_order' );
assert_same( 7334, $GLOBALS['diviops_test_posts'][7330]->post_parent, 'the parent was written' );
assert_same( 5, $GLOBALS['diviops_test_posts'][7330]->menu_order, 'and so was the menu_order' );
assert_same( 'page', $body['data']['post_type'] ?? null, 'the readback carries the post type' );
assert_same( 7330, $body['data']['id'] ?? null, 'and the id' );

// Matching aliases are not a conflict — the same value under two keys proceeds.
$body = diviops_pgc_call( 'page_update_meta', array( 'id' => 7330, 'menu_order' => 6, 'parent' => 0, 'post_parent' => 0 ) )->get_data();
assert_same( 0, $body['data']['parent'] ?? null, 'two aliases carrying the same value are accepted' );
assert_same( 0, $GLOBALS['diviops_test_posts'][7330]->post_parent, 'and the write happened' );

// ══ page_update_content ═══════════════════════════════════════════════════

diviops_pgc_post( 7340, $diviops_pgc_plain, 'page', 'Content Target' );
diviops_pgc_post( 7341, $diviops_pgc_plain, 'post', 'Content Post' );

$response = diviops_pgc_call( 'page_update_content', array( 'id' => 999735, 'content' => '' ) );
assert_same( 'not_found', $response->get_data()['error']['code'] ?? null, 'an unknown id is not_found' );
assert_same( 404, $response->get_status(), 'and a 404' );

$GLOBALS['diviops_test_denied_caps'] = array( 'edit_post' );
$response = diviops_pgc_call( 'page_update_content', array( 'id' => 7340, 'content' => '' ) );
assert_same( 'forbidden', $response->get_data()['error']['code'] ?? null, 'the edit_post gate refuses the write' );
assert_same( 403, $response->get_status(), 'with a 403' );
$GLOBALS['diviops_test_denied_caps'] = array();

$body = diviops_pgc_call( 'page_update_content', array( 'id' => 7340, 'content' => 42 ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a non-string content is refused' );
assert_same( 'content', $body['error']['data']['field'] ?? null, 'the refusal names the field' );
assert_same( 'integer', $body['error']['data']['received_type'] ?? null, 'and the type it received' );

// Attribute normalization refuses before any write. A Divi opener whose tail is
// not a JSON object is the first check in normalize_divi_full_content_for_write()
// (trait-core.php:504-511).
$body = diviops_pgc_call( 'page_update_content', array( 'id' => 7340, 'content' => '<!-- wp:divi/text not-json -->x<!-- /wp:divi/text -->' ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a Divi opener with non-JSON attrs is refused' );
assert_same( 'Divi block attributes must be a JSON object.', $body['error']['message'] ?? null, 'with the normalizer\'s own message' );
assert_same( 'divi/text', $body['error']['data']['block'] ?? null, 'naming the offending block' );
assert_same( 'content', $body['error']['data']['field'] ?? null, 'against the content field' );
assert_same( $diviops_pgc_plain, $GLOBALS['diviops_test_posts'][7340]->post_content, 'and nothing was written' );

// The marker-balance preflight in update_post_content_with_integrity_guard()
// rejects a container opener with no closer, before wp_update_post is reached.
$response = diviops_pgc_call( 'page_update_content', array( 'id' => 7340, 'content' => '<!-- wp:divi/section -->' ) );
$body     = $response->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'unbalanced block markers are refused' );
assert_same( 400, $response->get_status(), 'as a 400' );
assert_same( 1, $body['error']['data']['counts']['container_openers'] ?? null, 'the refusal reports the opener count' );
assert_same( 0, $body['error']['data']['counts']['closers'] ?? null, 'and the closer count' );
assert_same( $diviops_pgc_plain, $GLOBALS['diviops_test_posts'][7340]->post_content, 'and nothing was written' );

$body = diviops_pgc_call( 'page_update_content', array( 'id' => 7340, 'content' => $diviops_pgc_divi, 'dry_run' => true ) )->get_data();
$plan = $body['data']['plan'] ?? array();
assert_same( true, $body['data']['dry_run'] ?? null, 'a dry run reports itself as one' );
assert_same( 'page.update_content', $plan['changes'][0]['kind'] ?? null, 'the change kind is page.update_content' );
assert_same( 'page#7340', $plan['changes'][0]['target'] ?? null, 'targeting the page' );
assert_same( strlen( $diviops_pgc_plain ), $plan['changes'][0]['before']['bytes'] ?? null, 'reporting the stored byte count' );
assert_same( strlen( $diviops_pgc_divi ), $plan['changes'][0]['after']['bytes'] ?? null, 'and the requested one' );
assert_same( $diviops_pgc_plain, $GLOBALS['diviops_test_posts'][7340]->post_content, 'a dry run writes nothing' );
assert_same( '', get_post_meta( 7340, '_et_pb_use_builder', true ), 'and initializes no Divi meta' );

$body = diviops_pgc_call( 'page_update_content', array( 'id' => 7340, 'content' => $diviops_pgc_divi ) )->get_data();
assert_same( true, ! empty( $body['ok'] ), 'the apply returns a success envelope' );
assert_same( 7340, $body['data']['page_id'] ?? null, 'naming the page' );
assert_same( $diviops_pgc_divi, $GLOBALS['diviops_test_posts'][7340]->post_content, 'post_content was replaced' );
// Divi's own page-creation meta, stamped the first time Divi content appears.
assert_same( 'on', get_post_meta( 7340, '_et_pb_use_builder', true ), 'the first Divi write initializes _et_pb_use_builder' );
assert_same( 'on', get_post_meta( 7340, '_et_pb_use_divi_5', true ), 'and _et_pb_use_divi_5' );
assert_same( 'et_full_width_page', get_post_meta( 7340, '_et_pb_page_layout', true ), 'and the default page layout' );
assert_same( 'page', get_post_meta( 7340, '_et_pb_built_for_post_type', true ), 'keyed to the post type' );

// #45's root-cause guard, behaviourally: a SECOND write must not re-stamp the
// meta, or a caller's custom page layout is clobbered on every content update.
// tests/test-page-update-meta-guard.php pins the wiring by source inspection;
// this pins the effect.
update_post_meta( 7340, '_et_pb_page_layout', 'et_custom_layout' );
diviops_pgc_call( 'page_update_content', array( 'id' => 7340, 'content' => $diviops_pgc_divi . '<!-- wp:divi/section --><!-- /wp:divi/section -->' ) );
assert_same( 'et_custom_layout', get_post_meta( 7340, '_et_pb_page_layout', true ), '#45: a later write does not re-stamp the page layout' );

// And the stamp carries the post's REAL type, not a hardcoded 'page'.
diviops_pgc_call( 'page_update_content', array( 'id' => 7341, 'content' => $diviops_pgc_divi ) );
assert_same( 'post', get_post_meta( 7341, '_et_pb_built_for_post_type', true ), '#45: the init stamps the post\'s own post type' );

// ══ section_append ════════════════════════════════════════════════════════

$diviops_pgc_section     = '<!-- wp:divi/section --><!-- /wp:divi/section -->';
$diviops_pgc_new_section = '<!-- wp:divi/section --><!-- wp:divi/text -->NEW<!-- /wp:divi/text --><!-- /wp:divi/section -->';
$diviops_pgc_wrapped     = '<!-- wp:divi/placeholder -->' . $diviops_pgc_section . '<!-- /wp:divi/placeholder -->';

diviops_pgc_post( 7350, $diviops_pgc_wrapped, 'page', 'Append Target' );
diviops_pgc_post( 7351, $diviops_pgc_section, 'page', 'Unwrapped Target' );

$response = diviops_pgc_call( 'section_append', array( 'id' => 999736, 'content' => '' ) );
$body     = $response->get_data();
assert_same( 'not_found', $body['error']['code'] ?? null, 'an unknown id is not_found' );
assert_same( 'page', $body['error']['data']['target_kind'] ?? null, 'discriminated as a page miss' );
assert_same( 404, $response->get_status(), 'and a 404' );

$GLOBALS['diviops_test_denied_caps'] = array( 'edit_post' );
$response = diviops_pgc_call( 'section_append', array( 'id' => 7350, 'content' => '' ) );
assert_same( 'forbidden', $response->get_data()['error']['code'] ?? null, 'the edit_post gate refuses the append' );
assert_same( 403, $response->get_status(), 'with a 403' );
$GLOBALS['diviops_test_denied_caps'] = array();

$body = diviops_pgc_call( 'section_append', array( 'id' => 7350, 'content' => null ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a missing content is refused' );
assert_same( 'NULL', $body['error']['data']['received_type'] ?? null, 'reporting the type it received' );

$body = diviops_pgc_call( 'section_append', array( 'id' => 7350, 'content' => '', 'position' => 'middle' ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'an unknown position is refused' );
assert_same( array( 'start', 'end' ), $body['error']['data']['allowed'] ?? null, 'the refusal lists the two allowed positions' );
assert_same( 'middle', $body['error']['data']['received'] ?? null, 'and echoes what it got' );

$body = diviops_pgc_call( 'section_append', array( 'id' => 7350, 'content' => $diviops_pgc_new_section, 'dry_run' => true ) )->get_data();
$plan = $body['data']['plan'] ?? array();
assert_same( 'section.append', $plan['changes'][0]['kind'] ?? null, 'the change kind is section.append' );
assert_same( 'page#7350', $plan['changes'][0]['target'] ?? null, 'targeting the page' );
assert_same( 'end', $plan['changes'][0]['after']['position'] ?? null, 'position defaults to end' );
assert_same( strlen( $diviops_pgc_new_section ), $plan['changes'][0]['after']['bytes'] ?? null, 'and the plan reports the incoming byte count' );
assert_same( false, array_key_exists( 'before', $plan['changes'][0] ), 'an append plan carries no before state' );
assert_same( $diviops_pgc_wrapped, $GLOBALS['diviops_test_posts'][7350]->post_content, 'a dry run writes nothing' );

$body = diviops_pgc_call( 'section_append', array( 'id' => 7350, 'content' => $diviops_pgc_new_section ) )->get_data();
assert_same( true, ! empty( $body['ok'] ), 'the append returns a success envelope' );
assert_same( 'end', $body['data']['position'] ?? null, 'reporting the position it used' );
assert_same(
	'<!-- wp:divi/placeholder -->' . $diviops_pgc_section . $diviops_pgc_new_section . '<!-- /wp:divi/placeholder -->',
	$GLOBALS['diviops_test_posts'][7350]->post_content,
	'an end append lands inside the placeholder wrapper, after the existing sections'
);

$body = diviops_pgc_call( 'section_append', array( 'id' => 7351, 'content' => $diviops_pgc_new_section, 'position' => 'start' ) )->get_data();
assert_same( 'start', $body['data']['position'] ?? null, 'a start append reports its position' );
// The handler re-wraps unconditionally, so a page stored WITHOUT the placeholder
// wrapper gains one as a side effect of appending a section.
assert_same(
	'<!-- wp:divi/placeholder -->' . $diviops_pgc_new_section . $diviops_pgc_section . '<!-- /wp:divi/placeholder -->',
	$GLOBALS['diviops_test_posts'][7351]->post_content,
	'a start append prepends, and adds a placeholder wrapper the page did not have'
);

// ══ section_get / section_replace / section_remove ════════════════════════
//
// All three resolve their target through resolve_module_target() with
// allow_auto_index => false, and all three route helper errors through
// envelope_from_helper_error() with target_kind 'section'. The selector contract
// is characterized once here, on the read handler, then the two write handlers
// are driven for what is unique to them.
//
// The label needle find_all_sections() builds is the literal substring
// `"adminLabel":{"desktop":{"value":"<label>"}}` (trait-page.php:3641), so these
// fixtures are registered with that exact attribute shape rather than written
// through a handler.

$diviops_pgc_hero = '<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Hero"}}}}} -->'
	. '<!-- wp:divi/text -->Hero copy<!-- /wp:divi/text --><!-- /wp:divi/section -->';
$diviops_pgc_cta  = '<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"CTA"}}}}} -->'
	. '<!-- wp:divi/text -->Call to action<!-- /wp:divi/text --><!-- /wp:divi/section -->';
$diviops_pgc_two  = '<!-- wp:divi/placeholder -->' . $diviops_pgc_hero . $diviops_pgc_cta . '<!-- /wp:divi/placeholder -->';

diviops_pgc_post( 7360, $diviops_pgc_two, 'page', 'Sections' );
diviops_pgc_post( 7361, $diviops_pgc_two, 'page', 'Replace Target' );
diviops_pgc_post( 7362, $diviops_pgc_two, 'page', 'Remove Target' );
diviops_pgc_post( 7363, $diviops_pgc_two, 'page', 'Replace Occurrence Target' );

$response = diviops_pgc_call( 'section_get', array( 'id' => 999737, 'label' => 'Hero' ) );
assert_same( 'not_found', $response->get_data()['error']['code'] ?? null, 'an unknown page is not_found' );
assert_same( 404, $response->get_status(), 'and a 404' );

$GLOBALS['diviops_test_uneditable_ids'] = array( 7360 );
$response = diviops_pgc_call( 'section_get', array( 'id' => 7360, 'label' => 'Hero' ) );
assert_same( 'forbidden', $response->get_data()['error']['code'] ?? null, 'section_get applies the row-level read gate' );
assert_same( 'section', $response->get_data()['error']['data']['target_kind'] ?? null, 'discriminated as a section read' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

$body = diviops_pgc_call( 'section_get', array( 'id' => 7360 ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a request with no selector is refused' );
assert_same( 'missing_target', $body['error']['data']['reason'] ?? null, 'with reason missing_target' );

$body = diviops_pgc_call( 'section_get', array( 'id' => 7360, 'label' => 'Hero', 'match_text' => 'Hero copy' ) )->get_data();
assert_same( 'ambiguous_target', $body['error']['data']['reason'] ?? null, 'two selectors are ambiguous rather than silently prioritized' );
assert_same( array( 'label', 'match_text' ), $body['error']['data']['fields_provided'] ?? null, 'and the refusal names both' );

$body = diviops_pgc_call( 'section_get', array( 'id' => 7360, 'auto_index' => 'section:1' ) )->get_data();
assert_same( 'unsupported_selector', $body['error']['data']['reason'] ?? null, 'section tools reject auto_index explicitly rather than ignoring it' );

$response = diviops_pgc_call( 'section_get', array( 'id' => 7360, 'label' => 'Nope' ) );
$body     = $response->get_data();
assert_same( 'not_found', $body['error']['code'] ?? null, 'a label that matches nothing is not_found' );
assert_same( 404, $response->get_status(), 'and a 404' );
assert_same( 'section', $body['error']['data']['target_kind'] ?? null, 'discriminated as a section miss' );
assert_same( 'label', $body['error']['data']['target_mode'] ?? null, 'naming the mode that missed' );
assert_same( 'Nope', $body['error']['data']['target_value'] ?? null, 'and the value it looked for' );
assert_same( 7360, $body['error']['data']['page_id'] ?? null, 'and the page it searched' );

$body = diviops_pgc_call( 'section_get', array( 'id' => 7360, 'label' => 'Hero' ) )->get_data();
assert_same( $diviops_pgc_hero, $body['data']['markup'] ?? null, 'a label match returns that section\'s full markup' );
assert_same( 'label', $body['data']['matched_by'] ?? null, 'reporting how it matched' );
assert_same( 'Hero', $body['data']['target'] ?? null, 'and the target it matched on' );
assert_same( false, array_key_exists( 'total_matches', $body['data'] ), 'a single match reports no match count' );

$body = diviops_pgc_call( 'section_get', array( 'id' => 7360, 'match_text' => 'Call to action' ) )->get_data();
assert_same( $diviops_pgc_cta, $body['data']['markup'] ?? null, 'a text match returns the section containing that text' );
assert_same( 'text', $body['data']['matched_by'] ?? null, 'reporting text as the match mode' );
assert_same( 'text:Call to action', $body['data']['target'] ?? null, 'with the target prefixed text:' );

// A needle present in both sections matches both; occurrence selects between
// them, 1-based, defaulting to the first.
$body = diviops_pgc_call( 'section_get', array( 'id' => 7360, 'match_text' => 'wp:divi/text' ) )->get_data();
assert_same( 2, $body['data']['total_matches'] ?? null, 'a needle in both sections reports two matches' );
assert_same( 1, $body['data']['occurrence'] ?? null, 'occurrence defaults to the first' );
assert_same( $diviops_pgc_hero, $body['data']['markup'] ?? null, 'which is the first section in document order' );
assert_same(
	"Multiple sections (2) match text:wp:divi/text. Use 'occurrence' param to target a specific one.",
	$body['data']['warning'] ?? null,
	'and an ambiguity warning rides along with the payload'
);

$body = diviops_pgc_call( 'section_get', array( 'id' => 7360, 'match_text' => 'wp:divi/text', 'occurrence' => 2 ) )->get_data();
assert_same( $diviops_pgc_cta, $body['data']['markup'] ?? null, 'occurrence 2 selects the second match' );

$body = diviops_pgc_call( 'section_get', array( 'id' => 7360, 'match_text' => 'wp:divi/text', 'occurrence' => 3 ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'an occurrence past the match count is refused' );
assert_same( 'invalid_occurrence', $body['error']['data']['reason'] ?? null, 'with reason invalid_occurrence' );
assert_same( 'occurrence', $body['error']['data']['field'] ?? null, 'naming the offending field' );
assert_same( 3, $body['error']['data']['received'] ?? null, 'the occurrence it got' );
assert_same( 2, $body['error']['data']['total_matches'] ?? null, 'and how many matches there actually are' );

// `occurrence` is floored, not validated, at the targeting layer: absint()
// then max(1, …) (trait-page.php:4254), so 0 and -3 both read as 1.
$body = diviops_pgc_call( 'section_get', array( 'id' => 7360, 'label' => 'CTA', 'occurrence' => 0 ) )->get_data();
assert_same( $diviops_pgc_cta, $body['data']['markup'] ?? null, 'occurrence 0 is floored to 1 rather than refused' );

// ── section_replace ──────────────────────────────────────────────────────

$body = diviops_pgc_call( 'section_replace', array( 'id' => 7361, 'label' => 'Hero', 'content' => 42 ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a non-string replacement is refused' );
assert_same( 'integer', $body['error']['data']['received_type'] ?? null, 'reporting the type it received' );

// The content type check runs AFTER target resolution, so a bad target wins.
$body = diviops_pgc_call( 'section_replace', array( 'id' => 7361, 'content' => 42 ) )->get_data();
assert_same( 'missing_target', $body['error']['data']['reason'] ?? null, 'target resolution is checked before the content type' );

$body = diviops_pgc_call( 'section_replace', array( 'id' => 7361, 'label' => 'Hero', 'content' => $diviops_pgc_new_section, 'dry_run' => true ) )->get_data();
$plan = $body['data']['plan'] ?? array();
assert_same( 'section.replace', $plan['changes'][0]['kind'] ?? null, 'the change kind is section.replace' );
assert_same( 'label', $plan['changes'][0]['before']['matched_by'] ?? null, 'the plan reports how it matched' );
assert_same( 'Hero', $plan['changes'][0]['before']['target'] ?? null, 'and on what' );
assert_same( 1, $plan['changes'][0]['before']['total_matches'] ?? null, 'and how many sections matched' );
assert_same( strlen( $diviops_pgc_new_section ), $plan['changes'][0]['after']['bytes'] ?? null, 'and the replacement size' );
assert_same( $diviops_pgc_two, $GLOBALS['diviops_test_posts'][7361]->post_content, 'a dry run writes nothing' );

$body = diviops_pgc_call( 'section_replace', array( 'id' => 7361, 'label' => 'Hero', 'content' => $diviops_pgc_new_section ) )->get_data();
assert_same( true, ! empty( $body['ok'] ), 'the replace returns a success envelope' );
assert_same( 'label', $body['data']['matched_by'] ?? null, 'reporting the match mode' );
assert_same( 'Hero', $body['data']['target'] ?? null, 'and the target' );
assert_same( false, array_key_exists( 'total_matches', $body['data'] ), 'a single match reports no match count' );
// The splice is positional: only the matched span is replaced, and the enclosing
// placeholder wrapper and sibling section survive byte-for-byte.
assert_same(
	'<!-- wp:divi/placeholder -->' . $diviops_pgc_new_section . $diviops_pgc_cta . '<!-- /wp:divi/placeholder -->',
	$GLOBALS['diviops_test_posts'][7361]->post_content,
	'the matched section is spliced out and the rest of the document is untouched'
);

$response = diviops_pgc_call( 'section_replace', array( 'id' => 7361, 'label' => 'Hero', 'content' => $diviops_pgc_new_section ) );
assert_same( 'not_found', $response->get_data()['error']['code'] ?? null, 'replacing the same label twice misses the second time' );
assert_same( 404, $response->get_status(), 'as a 404' );

// Occurrence targeting on its own fixture, so it does not depend on what the
// label replace above left behind. A label match is unique here, so without this
// case nothing in the file could observe section_replace passing `occurrence`
// through to find_and_replace_section() at all.
$body = diviops_pgc_call(
	'section_replace',
	array( 'id' => 7363, 'match_text' => 'wp:divi/text', 'occurrence' => 2, 'content' => $diviops_pgc_new_section )
)->get_data();
assert_same( 2, $body['data']['occurrence'] ?? null, 'a multi-match replace echoes the occurrence it used' );
assert_same( 2, $body['data']['total_matches'] ?? null, 'and how many sections matched' );
assert_same(
	'<!-- wp:divi/placeholder -->' . $diviops_pgc_hero . $diviops_pgc_new_section . '<!-- /wp:divi/placeholder -->',
	$GLOBALS['diviops_test_posts'][7363]->post_content,
	'occurrence 2 replaces the second matching section and leaves the first alone'
);

// ── section_remove ───────────────────────────────────────────────────────

$body = diviops_pgc_call( 'section_remove', array( 'id' => 7362, 'match_text' => 'wp:divi/text', 'occurrence' => 2, 'dry_run' => true ) )->get_data();
$plan = $body['data']['plan'] ?? array();
assert_same( 'section.remove', $plan['changes'][0]['kind'] ?? null, 'the change kind is section.remove' );
assert_same( 2, $plan['changes'][0]['before']['occurrence'] ?? null, 'the plan reports the occurrence it will remove' );
assert_same( 2, $plan['changes'][0]['before']['total_matches'] ?? null, 'out of how many matched' );
assert_same( false, array_key_exists( 'after', $plan['changes'][0] ), 'a remove plan carries no after state' );
assert_same( $diviops_pgc_two, $GLOBALS['diviops_test_posts'][7362]->post_content, 'a dry run writes nothing' );

$body = diviops_pgc_call( 'section_remove', array( 'id' => 7362, 'match_text' => 'wp:divi/text', 'occurrence' => 2 ) )->get_data();
assert_same( true, ! empty( $body['ok'] ), 'the remove returns a success envelope' );
assert_same( 2, $body['data']['occurrence'] ?? null, 'a multi-match remove echoes the occurrence' );
assert_same( 2, $body['data']['total_matches'] ?? null, 'and the match count' );
assert_same(
	'<!-- wp:divi/placeholder -->' . $diviops_pgc_hero . '<!-- /wp:divi/placeholder -->',
	$GLOBALS['diviops_test_posts'][7362]->post_content,
	'the second matching section is cut out and nothing else moves'
);

$body = diviops_pgc_call( 'section_remove', array( 'id' => 7362, 'label' => 'Hero' ) )->get_data();
assert_same(
	'<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->',
	$GLOBALS['diviops_test_posts'][7362]->post_content,
	'removing the last section leaves an empty placeholder wrapper, not an empty document'
);

// A repeat remove is a 404 rather than an idempotent success — the trait's own
// comment (trait-page.php:1474-1481) states this is deliberate: label and
// match_text cannot tell "already removed" from "never existed".
$response = diviops_pgc_call( 'section_remove', array( 'id' => 7362, 'label' => 'Hero' ) );
assert_same( 'not_found', $response->get_data()['error']['code'] ?? null, 'a repeat remove is not_found, not an idempotent success' );

// ══ page_trash ════════════════════════════════════════════════════════════

diviops_pgc_post( 7370, $diviops_pgc_divi, 'page', 'Trash Me' );
diviops_pgc_post( 7371, $diviops_pgc_divi, 'page', 'Delete Me' );

$response = diviops_pgc_call( 'page_trash', array( 'id' => 999738 ) );
assert_same( 'not_found', $response->get_data()['error']['code'] ?? null, 'trashing an unknown page is not_found' );
assert_same( 404, $response->get_status(), 'and a 404' );

// The gate is delete_post, not edit_post — a user who may edit is not thereby
// allowed to trash.
$GLOBALS['diviops_test_denied_caps'] = array( 'delete_post' );
$response = diviops_pgc_call( 'page_trash', array( 'id' => 7370 ) );
assert_same( 'forbidden', $response->get_data()['error']['code'] ?? null, 'the delete_post gate refuses the trash' );
assert_same( 403, $response->get_status(), 'with a 403' );
assert_same( 'publish', $GLOBALS['diviops_test_posts'][7370]->post_status, 'and the post is untouched' );
$GLOBALS['diviops_test_denied_caps'] = array();

$body = diviops_pgc_call( 'page_trash', array( 'id' => 7370, 'dry_run' => true ) )->get_data();
$plan = $body['data']['plan'] ?? array();
assert_same( 'trash', $plan['changes'][0]['kind'] ?? null, 'the plan for a live page is a trash' );
assert_same( 'page#7370', $plan['changes'][0]['target'] ?? null, 'targeting the page' );
assert_same( 'publish', $plan['changes'][0]['before'] ?? null, 'from its current status' );
assert_same( 'trash', $plan['changes'][0]['after'] ?? null, 'to trash' );
assert_same( 'Trash Me', $body['data']['title'] ?? null, 'and the plan names the title' );
assert_same( 'publish', $GLOBALS['diviops_test_posts'][7370]->post_status, 'a dry run trashes nothing' );

$body = diviops_pgc_call( 'page_trash', array( 'id' => 7370 ) )->get_data();
assert_same( true, ! empty( $body['ok'] ), 'the trash returns a success envelope' );
assert_same( 'trash', $body['data']['status'] ?? null, 'reporting the end state' );
assert_same( false, array_key_exists( 'already_trashed', $body['data'] ), 'and no already_trashed flag on the first call' );
assert_same( 'trash', $GLOBALS['diviops_test_posts'][7370]->post_status, 'the post is in the trash' );

$body = diviops_pgc_call( 'page_trash', array( 'id' => 7370 ) )->get_data();
assert_same( true, ! empty( $body['ok'] ), 'a repeat trash still succeeds' );
assert_same( true, $body['data']['already_trashed'] ?? null, 'and flags the no-op for callers that care' );

$body = diviops_pgc_call( 'page_trash', array( 'id' => 7370, 'dry_run' => true ) )->get_data();
assert_same( 'noop', $body['data']['plan']['changes'][0]['kind'] ?? null, 'a dry run against an already-trashed page plans a noop' );
assert_same( 'trash', $body['data']['plan']['changes'][0]['after'] ?? null, 'ending where it already is' );

// force bypasses the trash entirely, including for a live post.
$body = diviops_pgc_call( 'page_trash', array( 'id' => 7371, 'force' => true, 'dry_run' => true ) )->get_data();
assert_same( 'delete', $body['data']['plan']['changes'][0]['kind'] ?? null, 'force plans a permanent delete' );
assert_same( 'deleted', $body['data']['plan']['changes'][0]['after'] ?? null, 'ending deleted' );
assert_same( true, isset( $GLOBALS['diviops_test_posts'][7371] ), 'a force dry run deletes nothing' );

$body = diviops_pgc_call( 'page_trash', array( 'id' => 7371, 'force' => true ) )->get_data();
assert_same( 'deleted', $body['data']['status'] ?? null, 'the force apply reports deleted' );
assert_same( 'Delete Me', $body['data']['title'] ?? null, 'echoing the title it captured before the delete' );
assert_same( false, isset( $GLOBALS['diviops_test_posts'][7371] ), 'and the post is gone' );

// ══ page_update_status ════════════════════════════════════════════════════

diviops_pgc_post( 7380, $diviops_pgc_divi, 'page', 'Status Target', 'publish' );
diviops_pgc_post( 7381, $diviops_pgc_divi, 'page', 'Noop Target', 'publish' );
diviops_pgc_post( 7382, $diviops_pgc_divi, 'page', 'Scheduled Target', 'future' );
$GLOBALS['diviops_test_posts'][7382]->post_date_gmt = gmdate( 'Y-m-d H:i:s', time() + 86400 );

$response = diviops_pgc_call( 'page_update_status', array( 'id' => 999739, 'status' => 'draft' ) );
assert_same( 'not_found', $response->get_data()['error']['code'] ?? null, 'an unknown id is not_found' );
assert_same( 404, $response->get_status(), 'and a 404' );

$GLOBALS['diviops_test_denied_caps'] = array( 'edit_post' );
$response = diviops_pgc_call( 'page_update_status', array( 'id' => 7380, 'status' => 'draft' ) );
assert_same( 'forbidden', $response->get_data()['error']['code'] ?? null, 'the edit_post gate refuses the change' );
assert_same( 403, $response->get_status(), 'with a 403' );
$GLOBALS['diviops_test_denied_caps'] = array();

$body = diviops_pgc_call( 'page_update_status', array( 'id' => 7380, 'status' => 'archived' ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a status outside the enum is refused' );
assert_same( array( 'publish', 'draft', 'private', 'pending', 'future' ), $body['error']['data']['allowed'] ?? null, 'the refusal lists the enum' );
assert_same( 'archived', $body['error']['data']['received'] ?? null, 'and echoes what it got' );
// 'trash' is deliberately outside the enum — page_trash owns that transition.
$body = diviops_pgc_call( 'page_update_status', array( 'id' => 7380, 'status' => 'trash' ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'trash is not a status this handler will set' );

// Three separate refusals guard `status=future`, and all three report
// invalid_input on the field date_gmt. Each is therefore pinned by the payload
// only IT carries, so that dropping any one guard cannot be absorbed by the next
// one along and still look like the same refusal.
$body = diviops_pgc_call( 'page_update_status', array( 'id' => 7380, 'status' => 'future' ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'status=future without a date is refused' );
assert_same( 'date_gmt', $body['error']['data']['field'] ?? null, 'naming date_gmt' );
assert_same( array( "status='future' implies non-empty date_gmt" ), $body['error']['data']['requires'] ?? null, 'stating the requirement it enforces' );
assert_same( false, array_key_exists( 'received', $body['error']['data'] ), 'and echoing no value, because none was sent' );

$body = diviops_pgc_call( 'page_update_status', array( 'id' => 7380, 'status' => 'future', 'date_gmt' => 'not a date' ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'an unparseable date is refused' );
assert_same( "date_gmt could not be parsed as a date: 'not a date'.", $body['error']['message'] ?? null, 'by the parse guard specifically' );
assert_same( 'not a date', $body['error']['data']['received'] ?? null, 'echoing the string it could not parse' );
assert_same( false, array_key_exists( 'min_lead_seconds', $body['error']['data'] ), 'and not by the lead-time guard further down' );

// The 60-second floor mirrors core's own scheduling rule: wp_insert_post()
// silently demotes `future` to `publish` when post_date_gmt is less than
// MINUTE_IN_SECONDS away (wp-includes/post.php:4805-4807, checked against
// WordPress 7.1). The handler refuses at the same threshold so the endpoint
// contract matches what the database would end up holding.
$body = diviops_pgc_call( 'page_update_status', array( 'id' => 7380, 'status' => 'future', 'date_gmt' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 30 ) ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'a schedule under one minute out is refused' );
assert_same( 60, $body['error']['data']['min_lead_seconds'] ?? null, 'and the refusal states the floor' );

$body = diviops_pgc_call( 'page_update_status', array( 'id' => 7381, 'status' => 'publish' ) )->get_data();
assert_same( true, $body['data']['noop'] ?? null, 'setting the status a post already has is a no-op' );
assert_same( true, array_key_exists( 'scheduled_for', $body['data'] ) && null === $body['data']['scheduled_for'], 'with no schedule reported' );
assert_same( 'publish', $GLOBALS['diviops_test_posts'][7381]->post_status, 'and no write' );

$body = diviops_pgc_call( 'page_update_status', array( 'id' => 7381, 'status' => 'publish', 'dry_run' => true ) )->get_data();
assert_same( array(), $body['data']['plan']['changes'] ?? null, 'a no-op dry run plans no changes at all' );

$body = diviops_pgc_call( 'page_update_status', array( 'id' => 7380, 'status' => 'draft', 'dry_run' => true ) )->get_data();
$plan = $body['data']['plan'] ?? array();
assert_same( 'update_status', $plan['changes'][0]['kind'] ?? null, 'the change kind is update_status' );
assert_same( 'page#7380', $plan['changes'][0]['target'] ?? null, 'targeting the page' );
assert_same( 'publish', $plan['changes'][0]['before'] ?? null, 'from its current status' );
assert_same( 'draft', $plan['changes'][0]['after'] ?? null, 'to the requested one' );
assert_same( true, array_key_exists( 'scheduled_for', $body['data'] ) && null === $body['data']['scheduled_for'], 'a non-future change schedules nothing' );
assert_same( 'publish', $GLOBALS['diviops_test_posts'][7380]->post_status, 'a dry run writes nothing' );

$body = diviops_pgc_call( 'page_update_status', array( 'id' => 7380, 'status' => 'draft' ) )->get_data();
assert_same( 'draft', $body['data']['status'] ?? null, 'the apply reports the new status' );
assert_same( 'draft', $GLOBALS['diviops_test_posts'][7380]->post_status, 'and writes it' );
assert_same( true, array_key_exists( 'scheduled_for', $body['data'] ) && null === $body['data']['scheduled_for'], 'scheduled_for is null for a non-future status' );

// Scheduling. gmt_offset is set to a non-UTC site so the local/UTC conversion is
// observable: post_date_gmt is the requested instant, post_date the same instant
// rendered in the site timezone. -6 is a real WordPress `gmt_offset` value (the
// maintainer's own Central Standard Time), and get_date_from_gmt() derives the
// zone from it exactly as core does.
update_option( 'gmt_offset', -6 );
$diviops_pgc_when = gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 );
$body = diviops_pgc_call( 'page_update_status', array( 'id' => 7380, 'status' => 'future', 'date_gmt' => $diviops_pgc_when ) )->get_data();
$diviops_pgc_row = $GLOBALS['diviops_test_posts'][7380];
assert_same( 'future', $diviops_pgc_row->post_status, 'the post is scheduled' );
assert_same( gmdate( 'Y-m-d H:i:s', strtotime( $diviops_pgc_when ) ), $diviops_pgc_row->post_date_gmt, 'post_date_gmt is the requested instant in MySQL form' );
assert_same(
	gmdate( 'Y-m-d H:i:s', strtotime( $diviops_pgc_when ) - 6 * HOUR_IN_SECONDS ),
	$diviops_pgc_row->post_date,
	'post_date is that instant converted into the site timezone, six hours behind UTC'
);
assert_same( $diviops_pgc_row->post_date_gmt, $body['data']['scheduled_for'] ?? null, 'and scheduled_for echoes the stored GMT date' );
// `edit_date` is not a post column — it is an instruction to wp_update_post. Core
// otherwise sets $clear_date and overwrites post_date with current_time() when the
// EXISTING row is a draft whose post_date_gmt was never set
// (wp-includes/post.php:5356-5364), which would silently unschedule the post. This
// harness's wp_update_post copies every argument onto the record, so the flag's
// presence on the row is the observable proof the handler sent it.
assert_same( true, $diviops_pgc_row->edit_date ?? null, 'the update carries edit_date => true so core does not clear the date' );
delete_option( 'gmt_offset' );

// Re-publishing a scheduled post clears its future date, so it goes live now
// rather than bouncing back into the scheduler.
$diviops_pgc_stale = $GLOBALS['diviops_test_posts'][7382]->post_date_gmt;
$body = diviops_pgc_call( 'page_update_status', array( 'id' => 7382, 'status' => 'publish' ) )->get_data();
assert_same( 'publish', $GLOBALS['diviops_test_posts'][7382]->post_status, 'the scheduled post is published' );
assert_same( true, $GLOBALS['diviops_test_posts'][7382]->post_date_gmt !== $diviops_pgc_stale, 'and its stale future date was replaced' );
assert_same( true, strtotime( $GLOBALS['diviops_test_posts'][7382]->post_date_gmt ) <= time() + 1, 'with a date that is no longer in the future' );
assert_same( true, array_key_exists( 'scheduled_for', $body['data'] ) && null === $body['data']['scheduled_for'], 'and nothing is reported as scheduled' );

// ── Teardown ──────────────────────────────────────────────────────────────

foreach ( $GLOBALS['diviops_pgc_fixture_ids'] as $diviops_pgc_id ) {
	unset(
		$GLOBALS['diviops_test_posts'][ $diviops_pgc_id ],
		$GLOBALS['diviops_test_post_meta'][ $diviops_pgc_id ],
		$GLOBALS['diviops_test_post_meta_rows'][ $diviops_pgc_id ]
	);
}
unset(
	$GLOBALS['diviops_test_post_types']['diviops_pgc_type'],
	$GLOBALS['diviops_test_post_types']['diviops_pgc_bulk']
);
$GLOBALS['diviops_test_post_types']['page']         = $diviops_pgc_saved['page_type_args'];
$GLOBALS['diviops_test_wp_query_unmodelled_ok']     = $diviops_pgc_saved['wp_query_waivers'];
$GLOBALS['diviops_test_uneditable_ids']             = array();
$GLOBALS['diviops_test_denied_caps']                = array();
$GLOBALS['diviops_test_next_id']                    = $diviops_pgc_saved['next_id'];
if ( $diviops_pgc_saved['gmt_offset_set'] ) {
	update_option( 'gmt_offset', $diviops_pgc_saved['gmt_offset'] );
} else {
	delete_option( 'gmt_offset' );
}

// The teardown is load-bearing for every file that runs after this one: this
// file registers 111 posts across three post types and re-registers `page`
// itself. Assert it actually emptied, so a future edit that adds a fixture and
// forgets the bookkeeping fails HERE.
assert_same( null, $GLOBALS['diviops_test_posts'][7300] ?? null, 'teardown removed this file\'s fixture posts' );
assert_same( null, $GLOBALS['diviops_test_posts'][7500] ?? null, 'including the last of the bulk pagination fixtures' );
assert_same( false, post_type_exists( 'diviops_pgc_type' ), 'teardown unregistered this file\'s post types' );
assert_same( $diviops_pgc_saved['page_type_args'], $GLOBALS['diviops_test_post_types']['page'], 'and restored the shared page post-type registration' );
assert_same( $diviops_pgc_saved['wp_query_waivers'], $GLOBALS['diviops_test_wp_query_unmodelled_ok'], 'and the WP_Query waiver list' );
assert_same( false, (bool) get_option( 'timezone_string' ), 'control: the site timezone was never set by string, so gmt_offset governed it' );
