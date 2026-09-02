<?php
// SPDX-License-Identifier: MIT
/**
 * Characterization of the canvas domain (trait-canvas.php).
 *
 * Written for the upstream reconciliation triage
 * (https://github.com/rubicon/diviops/issues/328). All seven `canvas_*` REST
 * handlers had no test exercising them: `canvas_duplicate`, `canvas_list` and
 * `canvas_orphan_audit` were named in comments and assertion messages elsewhere
 * in tests/, and `canvas_create`, `canvas_get`, `canvas_update` and
 * `canvas_delete` appeared nowhere at all. Adopting an upstream hunk into a file
 * with no net cannot be checked; this file is the net.
 *
 * These are characterization tests, not correctness tests. They pin what the
 * handlers do today, quirks included, so that any adoption which moves the
 * behaviour fails loudly instead of passing quietly. Two quirks are pinned
 * deliberately and are NOT endorsements:
 *
 *   - `canvas_create` validates `canvas_id`'s format before it looks the parent
 *     page up, so a call that is wrong in both ways reports the malformed id
 *     rather than the missing parent.
 *   - `canvas_update` applies `wp_update_post` before it validates
 *     `append_to_main`, so a payload carrying both a good title and a bad
 *     `append_to_main` returns 400 with the title already written.
 *
 * Both are order-of-operations facts, and order of operations is exactly what an
 * adopted early-return hunk changes. Upstream's 2026-08-31 sync adds an
 * in-handler `published_post_types_permission_result()` gate at the top of
 * `canvas_create` and `canvas_duplicate`, above every guard below; if that is
 * ever taken, these assertions are what says whether a refusal that used to be
 * `not_found` or `invalid_input` silently became a 403.
 *
 * Everything runs against the real handler through the shared wp-shim: real
 * envelope helpers, real uniqueness probe, real meta writes. `current_user_can`
 * is fixed-true apart from the `diviops_test_uneditable_ids` seam, so the
 * per-object forbidden branches of create/duplicate/update/delete are not
 * reachable here and are left uncharacterized; `canvas_get`'s read gate goes
 * through `can_inspect_post_object()` and is exercised.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/*
 * The canvas queries ask for three arguments the WP_Query stub refuses by
 * default. Waived here because each is inert for these fixtures:
 *
 *   - `perm => 'editable'` is added by query_inspectable_post_ids() as a coarse
 *     prefilter and is re-applied exactly, per object, by its own edit_post
 *     check afterwards — which this file does exercise, through
 *     diviops_test_uneditable_ids.
 *   - `orderby`/`order` ask for newest-first. Every fixture set below is smaller
 *     than the posts_per_page cap in play, so no ordering can change which rows
 *     come back, and no assertion here depends on their order.
 */
$GLOBALS['diviops_test_wp_query_unmodelled_ok'] = array( 'perm', 'orderby', 'order' );

/**
 * Reset every registry these handlers read or write, so each section starts from
 * a known store rather than inheriting the previous section's canvases.
 */
function diviops_canvas_reset() {
	$GLOBALS['diviops_test_posts']           = array();
	$GLOBALS['diviops_test_post_meta']       = array();
	$GLOBALS['diviops_test_post_meta_rows']  = array();
	$GLOBALS['diviops_test_uneditable_ids']  = array();
	$GLOBALS['diviops_test_next_id']         = 9000;
	unset( $GLOBALS['diviops_test_last_insert'] );
}

/**
 * Register an et_pb_canvas fixture with the columns the handlers read bare.
 *
 * @param int    $post_id   Canvas post id.
 * @param string $title     post_title.
 * @param string $content   post_content.
 * @param int    $parent_id Parent page id, written to _divi_canvas_parent_post_id when non-zero.
 * @param string $canvas_id _divi_canvas_id value, written when non-empty.
 * @return object
 */
function diviops_canvas_fixture( int $post_id, string $title, string $content = '', int $parent_id = 0, string $canvas_id = '' ) {
	$post                = diviops_test_register_post( $post_id, $content, 'et_pb_canvas', $title );
	$post->post_modified = '2026-09-01 00:00:00';
	$post->post_name     = sanitize_title( $title );
	if ( $parent_id > 0 ) {
		update_post_meta( $post_id, '_divi_canvas_parent_post_id', $parent_id );
	}
	if ( '' !== $canvas_id ) {
		update_post_meta( $post_id, '_divi_canvas_id', $canvas_id );
	}
	return $post;
}

/**
 * @param array $params Request parameters.
 * @return DiviOps_Test_Request
 */
function diviops_canvas_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

// ── canvas_create ─────────────────────────────────────────────────────────

diviops_canvas_reset();
diviops_test_register_post( 4300, '', 'page', 'Parent Page' );

// (1) A malformed canvas_id is refused, and it is refused BEFORE the parent
// lookup — the parent here does not exist either. This is the ordering an
// adopted top-of-handler gate would move.
$resp = diviops_call( 'canvas_create', array( diviops_canvas_request( array(
	'title'          => 'Hero',
	'parent_page_id' => 999999,
	'canvas_id'      => 'not valid!',
) ) ) );
$data = $resp->get_data();
assert_true( false === $data['ok'], 'a malformed canvas_id returns an error envelope' );
assert_same( 'invalid_input', $data['error']['code'], 'a malformed canvas_id is invalid_input' );
assert_same( 400, $resp->get_status(), 'invalid_input carries HTTP 400' );
assert_same(
	'canvas_id must contain only letters, numbers, and hyphens.',
	$data['error']['message'],
	'the malformed canvas_id message names the allowed characters'
);

// (2) A missing parent page is not_found, with the discovery hint.
$resp = diviops_call( 'canvas_create', array( diviops_canvas_request( array(
	'title'          => 'Hero',
	'parent_page_id' => 999999,
) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'a missing parent page is not_found' );
assert_same( 404, $resp->get_status(), 'a missing parent carries HTTP 404' );
assert_same(
	'Run diviops_page_list to discover valid parent_page_id values.',
	$data['error']['hint'],
	'the missing-parent hint points at diviops_page_list'
);

// (3) Non-string content is refused.
$resp = diviops_call( 'canvas_create', array( diviops_canvas_request( array(
	'title'          => 'Hero',
	'parent_page_id' => 4300,
	'content'        => array( 'not', 'a', 'string' ),
) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'array content is invalid_input' );
assert_same(
	'content must be a string of Divi block markup.',
	$data['error']['message'],
	'the non-string content message names the expected type'
);

// (4) append_to_main only accepts above/below on create — "" is not a clear here,
// it is simply falsy and skips the check (canvas_update is the path that clears).
$resp = diviops_call( 'canvas_create', array( diviops_canvas_request( array(
	'title'          => 'Hero',
	'parent_page_id' => 4300,
	'append_to_main' => 'sideways',
) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an out-of-set append_to_main is invalid_input' );
assert_same(
	'append_to_main must be "above" or "below".',
	$data['error']['message'],
	'create offers only above/below, with no clear option'
);

// (5) dry_run plans without inserting.
$resp = diviops_call( 'canvas_create', array( diviops_canvas_request( array(
	'title'          => 'Hero',
	'parent_page_id' => 4300,
	'canvas_id'      => 'hero-canvas',
	'content'        => '<p>hi</p>',
	'dry_run'        => true,
) ) ) );
$data = $resp->get_data();
assert_true( true === $data['data']['dry_run'], 'dry_run create reports the dry_run flag' );
assert_same( 'canvas.create', $data['data']['plan']['changes'][0]['kind'], 'the create plan kind is canvas.create' );
assert_same( 'page#4300', $data['data']['plan']['changes'][0]['target'], 'the create plan targets the parent page' );
assert_same( 9, $data['data']['plan']['changes'][0]['after']['bytes'], 'the create plan reports content bytes' );
assert_true( ! isset( $GLOBALS['diviops_test_last_insert'] ), 'dry_run create inserted nothing' );

// (6) The happy path: post columns, the seven meta keys, and the response shape.
$resp = diviops_call( 'canvas_create', array( diviops_canvas_request( array(
	'title'          => 'Hero',
	'parent_page_id' => 4300,
	'canvas_id'      => 'hero-canvas',
	'content'        => '<p>hi</p>',
	'append_to_main' => 'above',
	'z_index'        => '7',
) ) ) );
$data      = $resp->get_data();
$hero_id   = $data['data']['canvas_post_id'];
$insert    = $GLOBALS['diviops_test_last_insert'];
assert_true( $data['ok'], 'a well-formed create succeeds' );
assert_same( 'et_pb_canvas', $insert['post_type'], 'create inserts an et_pb_canvas post' );
assert_same( 'publish', $insert['post_status'], 'a canvas is created published, not draft' );
assert_same( 'hero-canvas', $data['data']['canvas_id'], 'an explicit canvas_id is honoured verbatim' );
assert_same( 4300, $data['data']['parent_page_id'], 'the response echoes the parent page id' );
assert_same( "Canvas 'Hero' created and linked to page 4300.", $data['data']['message'], 'the create message names the canvas and parent' );
assert_same( 'hero-canvas', get_post_meta( $hero_id, '_divi_canvas_id', true ), '_divi_canvas_id is stored' );
assert_same( 4300, get_post_meta( $hero_id, '_divi_canvas_parent_post_id', true ), '_divi_canvas_parent_post_id is stored' );
assert_same( 'above', get_post_meta( $hero_id, '_divi_canvas_append_to_main', true ), '_divi_canvas_append_to_main is stored' );
assert_same( 7, get_post_meta( $hero_id, '_divi_canvas_z_index', true ), 'z_index is cast to int before storage' );
assert_same( 'on', get_post_meta( $hero_id, '_et_pb_use_builder', true ), 'the Divi builder flag is set' );
assert_same( 'on', get_post_meta( $hero_id, '_et_pb_use_divi_5', true ), 'the Divi 5 flag is set' );
assert_true( '' !== get_post_meta( $hero_id, '_divi_canvas_created_at', true ), '_divi_canvas_created_at is stamped' );

// (7) An omitted canvas_id is generated, not left empty.
$resp = diviops_call( 'canvas_create', array( diviops_canvas_request( array(
	'title'          => 'Generated',
	'parent_page_id' => 4300,
) ) ) );
$data = $resp->get_data();
assert_true(
	1 === preg_match( '/^[A-Za-z0-9-]+$/', (string) $data['data']['canvas_id'] ),
	'an omitted canvas_id is auto-generated in the format the validator accepts'
);

// (8) Divi markup with no placeholder wrapper is wrapped; markup that already
// carries one is left alone.
$resp = diviops_call( 'canvas_create', array( diviops_canvas_request( array(
	'title'          => 'Wrapped',
	'parent_page_id' => 4300,
	'content'        => '<!-- wp:divi/text --><!-- /wp:divi/text -->',
) ) ) );
assert_same(
	"<!-- wp:divi/placeholder -->\n<!-- wp:divi/text --><!-- /wp:divi/text -->\n<!-- /wp:divi/placeholder -->",
	$GLOBALS['diviops_test_last_insert']['post_content'],
	'bare Divi markup is wrapped in a placeholder before insert'
);
$already = "<!-- wp:divi/placeholder --><!-- wp:divi/text /--><!-- /wp:divi/placeholder -->";
$resp    = diviops_call( 'canvas_create', array( diviops_canvas_request( array(
	'title'          => 'Already Wrapped',
	'parent_page_id' => 4300,
	'content'        => $already,
) ) ) );
assert_same(
	$already,
	$GLOBALS['diviops_test_last_insert']['post_content'],
	'markup already carrying a placeholder is not double-wrapped'
);

// (9) A second canvas with the same title under the same parent is a conflict,
// carrying the id of the one already there.
$resp = diviops_call( 'canvas_create', array( diviops_canvas_request( array(
	'title'          => 'Hero',
	'parent_page_id' => 4300,
) ) ) );
$data = $resp->get_data();
assert_same( 'conflict', $data['error']['code'], 'a duplicate title under the same parent is a conflict' );
assert_same( 409, $resp->get_status(), 'conflict carries HTTP 409' );
assert_same( $hero_id, $data['error']['data']['existing_canvas_id'], 'the conflict payload names the existing canvas' );
assert_same( 4300, $data['error']['data']['parent_page_id'], 'the conflict payload names the parent page' );
assert_same( 'Hero', $data['error']['data']['title'], 'the conflict payload echoes the colliding title' );

// (10) The uniqueness probe is scoped to the parent: the same title under a
// different parent is not a conflict.
diviops_test_register_post( 4301, '', 'page', 'Other Parent' );
$resp = diviops_call( 'canvas_create', array( diviops_canvas_request( array(
	'title'          => 'Hero',
	'parent_page_id' => 4301,
) ) ) );
assert_true( $resp->get_data()['ok'], 'the same title under a different parent is allowed' );

// (11) dry_run does not skip the conflict probe — a plan that would fail says so.
$resp = diviops_call( 'canvas_create', array( diviops_canvas_request( array(
	'title'          => 'Hero',
	'parent_page_id' => 4300,
	'dry_run'        => true,
) ) ) );
assert_same( 'conflict', $resp->get_data()['error']['code'], 'dry_run surfaces the conflict rather than planning an insert' );

// ── canvas_duplicate ──────────────────────────────────────────────────────

diviops_canvas_reset();
diviops_test_register_post( 4300, '', 'page', 'Parent Page' );
diviops_canvas_fixture( 5000, 'Hero', '<p>source</p>', 4300, 'source-canvas' );
update_post_meta( 5000, '_divi_canvas_append_to_main', 'below' );
update_post_meta( 5000, '_divi_canvas_z_index', 3 );

// (12) A missing id is not_found, not a fatal.
$resp = diviops_call( 'canvas_duplicate', array( diviops_canvas_request( array( 'id' => 4242 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'duplicating a missing id is not_found' );
assert_same( 404, $resp->get_status(), 'a missing duplicate source carries HTTP 404' );

// (13) A real post of the wrong type is refused by the same guard — this is what
// keeps canvas_duplicate from copying an arbitrary page.
$resp = diviops_call( 'canvas_duplicate', array( diviops_canvas_request( array( 'id' => 4300 ) ) ) );
assert_same( 'not_found', $resp->get_data()['error']['code'], 'a non-et_pb_canvas post is not a duplicable canvas' );

// (14) A non-string title is refused before anything is inserted.
$resp = diviops_call( 'canvas_duplicate', array( diviops_canvas_request( array( 'id' => 5000, 'title' => 42 ) ) ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'a non-string title is invalid_input' );

// (15) dry_run plans the auto-suffixed name without inserting.
$resp = diviops_call( 'canvas_duplicate', array( diviops_canvas_request( array( 'id' => 5000, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_true( true === $data['data']['dry_run'], 'dry_run duplicate reports the dry_run flag' );
assert_same( 'create', $data['data']['plan']['changes'][0]['kind'], 'the duplicate plan kind is create' );
assert_same( 'canvas', $data['data']['plan']['changes'][0]['target'], 'the duplicate plan targets the canvas domain' );
assert_same( 'Hero (Copy)', $data['data']['plan']['changes'][0]['after']['title'], 'the duplicate plan names the auto-suffixed title' );
assert_true( ! isset( $GLOBALS['diviops_test_last_insert'] ), 'dry_run duplicate inserted nothing' );

// (16) The real duplicate: byte-copied content, fresh identity, preserved
// display meta, builder flags written unconditionally.
$resp = diviops_call( 'canvas_duplicate', array( diviops_canvas_request( array( 'id' => 5000 ) ) ) );
$data   = $resp->get_data();
$copy_id = $data['data']['id'];
assert_true( $data['ok'], 'a duplicate of a real canvas succeeds' );
assert_same( 'Hero (Copy)', $data['data']['title'], 'the first copy is auto-suffixed "(Copy)"' );
assert_same( 5000, $data['data']['source_id'], 'the response names the source canvas' );
assert_same( 4300, $data['data']['parent_page_id'], 'the copy stays under the source parent' );
assert_same( '<p>source</p>', $GLOBALS['diviops_test_posts'][ $copy_id ]->post_content, 'content is copied byte for byte' );
assert_true(
	'source-canvas' !== get_post_meta( $copy_id, '_divi_canvas_id', true ),
	'the copy gets a fresh _divi_canvas_id rather than inheriting the source identity'
);
assert_same( $data['data']['canvas_id'], get_post_meta( $copy_id, '_divi_canvas_id', true ), 'the response echoes the stored canvas_id' );
assert_same( 'below', get_post_meta( $copy_id, '_divi_canvas_append_to_main', true ), 'append_to_main is carried onto the copy' );
assert_same( 3, get_post_meta( $copy_id, '_divi_canvas_z_index', true ), 'z_index is carried onto the copy' );
assert_same( 'on', get_post_meta( $copy_id, '_et_pb_use_builder', true ), 'the copy carries the Divi builder flag' );
assert_same( 'hero-copy', $GLOBALS['diviops_test_posts'][ $copy_id ]->post_name, 'the response slug comes off the created post' );
assert_true( isset( $GLOBALS['diviops_test_posts'][5000] ), 'the source canvas is left in place' );

// (17) The suffix chain strips an existing "(Copy)" instead of nesting it, and
// counts up past a name already taken.
$resp = diviops_call( 'canvas_duplicate', array( diviops_canvas_request( array( 'id' => $copy_id ) ) ) );
assert_same(
	'Hero (Copy 2)',
	$resp->get_data()['data']['title'],
	'duplicating "Hero (Copy)" yields "Hero (Copy 2)", not "Hero (Copy) (Copy)"'
);

// (18) An explicit colliding title is a conflict rather than a silent rename —
// the caller stated an intent, so it is reported, not sanitized away.
$resp = diviops_call( 'canvas_duplicate', array( diviops_canvas_request( array( 'id' => 5000, 'title' => 'Hero (Copy)' ) ) ) );
$data = $resp->get_data();
assert_same( 'conflict', $data['error']['code'], 'an explicit colliding title is a conflict' );
assert_same( 409, $resp->get_status(), 'the duplicate conflict carries HTTP 409' );
assert_same( $copy_id, $data['error']['data']['existing_canvas_id'], 'the duplicate conflict names the canvas already holding the title' );

// ── canvas_get ────────────────────────────────────────────────────────────

diviops_canvas_reset();
diviops_canvas_fixture( 5100, 'Readable', '<p>body</p>', 4300, 'readable-canvas' );
update_post_meta( 5100, '_divi_canvas_append_to_main', 'above' );
update_post_meta( 5100, '_divi_canvas_z_index', 5 );

// (19) not_found guards.
$resp = diviops_call( 'canvas_get', array( diviops_canvas_request( array( 'id' => 4242 ) ) ) );
assert_same( 'not_found', $resp->get_data()['error']['code'], 'reading a missing canvas is not_found' );

// (20) The full read shape.
$resp = diviops_call( 'canvas_get', array( diviops_canvas_request( array( 'id' => 5100 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'reading a real canvas succeeds' );
assert_same( 5100, $data['data']['canvas_post_id'], 'the read echoes the canvas post id' );
assert_same( 'Readable', $data['data']['title'], 'the read carries the title' );
assert_same( 'readable-canvas', $data['data']['canvas_id'], 'the read carries the canvas_id' );
assert_same( 4300, $data['data']['parent_page_id'], 'the read casts the parent meta to int' );
assert_same( 'above', $data['data']['append_to_main'], 'the read carries append_to_main' );
assert_same( 5, $data['data']['z_index'], 'the read carries z_index' );
assert_same( '<p>body</p>', $data['data']['content'], 'the read carries post_content' );

// (21) Row-level read denial goes through can_inspect_post_object(), so a canvas
// the caller cannot edit is refused even though the route-level gate passed.
$GLOBALS['diviops_test_uneditable_ids'] = array( 5100 );
$resp = diviops_call( 'canvas_get', array( diviops_canvas_request( array( 'id' => 5100 ) ) ) );
$data = $resp->get_data();
assert_true( false === $data['ok'], 'an uninspectable canvas is refused' );
assert_same( 403, $resp->get_status(), 'the row-level read denial carries HTTP 403' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// ── canvas_update ─────────────────────────────────────────────────────────

diviops_canvas_reset();
diviops_test_register_post( 4300, '', 'page', 'Parent Page' );
diviops_canvas_fixture( 5200, 'Editable', '<p>before</p>', 4300, 'editable-canvas' );

// (22) not_found guard.
$resp = diviops_call( 'canvas_update', array( diviops_canvas_request( array( 'id' => 4242, 'title' => 'x' ) ) ) );
assert_same( 'not_found', $resp->get_data()['error']['code'], 'updating a missing canvas is not_found' );

// (23) A payload with nothing actionable is refused at the boundary rather than
// reporting a success that touched nothing.
$resp = diviops_call( 'canvas_update', array( diviops_canvas_request( array( 'id' => 5200 ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a no-op update payload is invalid_input' );
assert_same(
	'canvas_update requires at least one of: content, title, append_to_main, z_index.',
	$data['error']['message'],
	'the no-op message lists the four actionable fields'
);

// (24) Type guards.
$resp = diviops_call( 'canvas_update', array( diviops_canvas_request( array( 'id' => 5200, 'content' => array() ) ) ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'array content is invalid_input on update' );
$resp = diviops_call( 'canvas_update', array( diviops_canvas_request( array( 'id' => 5200, 'title' => array() ) ) ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'a non-scalar title is invalid_input on update' );

// (25) dry_run names the fields it would touch and writes nothing.
$resp = diviops_call( 'canvas_update', array( diviops_canvas_request( array(
	'id'      => 5200,
	'title'   => 'Renamed',
	'z_index' => 9,
	'dry_run' => true,
) ) ) );
$data = $resp->get_data();
assert_same( 'canvas.update', $data['data']['plan']['changes'][0]['kind'], 'the update plan kind is canvas.update' );
assert_same( 'canvas#5200', $data['data']['plan']['changes'][0]['target'], 'the update plan targets the canvas' );
assert_same( 'Renamed', $data['data']['plan']['changes'][0]['after']['title'], 'the update plan carries the new title' );
assert_same( 9, $data['data']['plan']['changes'][0]['after']['z_index'], 'the update plan carries the new z_index' );
assert_same( 'Editable', $GLOBALS['diviops_test_posts'][5200]->post_title, 'dry_run update left the title alone' );

// (26) A content update wraps bare Divi markup exactly as create does.
$resp = diviops_call( 'canvas_update', array( diviops_canvas_request( array(
	'id'      => 5200,
	'content' => '<!-- wp:divi/text /-->',
) ) ) );
assert_true( $resp->get_data()['ok'], 'a content-only update succeeds' );
assert_same(
	"<!-- wp:divi/placeholder -->\n<!-- wp:divi/text /-->\n<!-- /wp:divi/placeholder -->",
	$GLOBALS['diviops_test_posts'][5200]->post_content,
	'update wraps bare Divi markup in a placeholder, matching create'
);

// (27) Metadata-only edits do not need content, and "" clears append_to_main.
update_post_meta( 5200, '_divi_canvas_append_to_main', 'above' );
$resp = diviops_call( 'canvas_update', array( diviops_canvas_request( array( 'id' => 5200, 'append_to_main' => '' ) ) ) );
assert_true( $resp->get_data()['ok'], 'a metadata-only update succeeds without content' );
assert_same( '', get_post_meta( 5200, '_divi_canvas_append_to_main', true ), 'an empty append_to_main clears the meta' );

// (28) An out-of-set append_to_main is refused — and the refusal happens after
// wp_update_post has already run, so a title passed alongside it is written.
// Pinned as behaviour, not endorsed: this is the partial-write window.
$resp = diviops_call( 'canvas_update', array( diviops_canvas_request( array(
	'id'             => 5200,
	'title'          => 'Written Anyway',
	'append_to_main' => 'sideways',
) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an out-of-set append_to_main is invalid_input on update' );
assert_same(
	'append_to_main must be "above", "below", or "" to clear.',
	$data['error']['message'],
	'update offers above/below/clear, unlike create'
);
assert_same(
	'Written Anyway',
	$GLOBALS['diviops_test_posts'][5200]->post_title,
	'the title is already committed when append_to_main is rejected — the post write precedes the meta validation'
);

// (29) A successful update reports the canonical success shape.
$resp = diviops_call( 'canvas_update', array( diviops_canvas_request( array( 'id' => 5200, 'z_index' => '11' ) ) ) );
$data = $resp->get_data();
assert_same( 5200, $data['data']['canvas_post_id'], 'the update response echoes the canvas id' );
assert_same( 'Canvas updated successfully.', $data['data']['message'], 'the update success message is stable' );
assert_same( 11, get_post_meta( 5200, '_divi_canvas_z_index', true ), 'z_index is cast to int on update' );

// ── canvas_delete ─────────────────────────────────────────────────────────

diviops_canvas_reset();
diviops_test_register_post( 4300, '', 'page', 'Parent Page' );
diviops_canvas_fixture( 5300, 'Doomed', '', 4300, 'doomed-canvas' );

// (30) not_found guard, and the type guard that stops it deleting a page.
$resp = diviops_call( 'canvas_delete', array( diviops_canvas_request( array( 'id' => 4242 ) ) ) );
assert_same( 'not_found', $resp->get_data()['error']['code'], 'deleting a missing canvas is not_found' );
$resp = diviops_call( 'canvas_delete', array( diviops_canvas_request( array( 'id' => 4300 ) ) ) );
assert_same( 'not_found', $resp->get_data()['error']['code'], 'a non-et_pb_canvas post is not deletable through canvas_delete' );
assert_true( isset( $GLOBALS['diviops_test_posts'][4300] ), 'the wrong-type post is left untouched' );

// (31) dry_run reports the before-state and deletes nothing.
$resp = diviops_call( 'canvas_delete', array( diviops_canvas_request( array( 'id' => 5300, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_same( 'canvas.delete', $data['data']['plan']['changes'][0]['kind'], 'the delete plan kind is canvas.delete' );
assert_same( 'Doomed', $data['data']['plan']['changes'][0]['before']['title'], 'the delete plan reports the title it would remove' );
assert_same( 4300, $data['data']['plan']['changes'][0]['before']['parent_page_id'], 'the delete plan reports the parent page' );
assert_true( isset( $GLOBALS['diviops_test_posts'][5300] ), 'dry_run delete removed nothing' );

// (32) The real delete is permanent — canvases have no trash step, unlike
// library_delete's default.
$resp = diviops_call( 'canvas_delete', array( diviops_canvas_request( array( 'id' => 5300 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'deleting a real canvas succeeds' );
assert_same( 5300, $data['data']['deleted_canvas_post_id'], 'the delete response names the removed canvas' );
assert_same( 4300, $data['data']['parent_page_id'], 'the delete response reports the parent page for cache invalidation' );
assert_true( ! isset( $GLOBALS['diviops_test_posts'][5300] ), 'the canvas is gone from the store, not trashed' );

// ── canvas_list ───────────────────────────────────────────────────────────

diviops_canvas_reset();
diviops_test_register_post( 4300, '', 'page', 'Parent Page' );
diviops_canvas_fixture( 5400, 'First', '', 4300, 'first-canvas' );
diviops_canvas_fixture( 5401, 'Second', '', 4300, 'second-canvas' );
diviops_canvas_fixture( 5402, 'Elsewhere', '', 4301, 'elsewhere-canvas' );
update_post_meta( 5400, '_divi_canvas_z_index', 2 );

// (33) An unfiltered list returns every canvas and nothing else.
$resp = diviops_call( 'canvas_list', array( diviops_canvas_request( array() ) ) );
$data = $resp->get_data();
$listed = array_column( $data['data']['canvases'], 'canvas_post_id' );
sort( $listed );
assert_true( $data['ok'], 'canvas_list succeeds' );
assert_same( array( 5400, 5401, 5402 ), $listed, 'an unfiltered list returns every canvas and no pages' );
assert_same( 3, $data['data']['total'], 'total counts the inspectable canvases' );
assert_same( 1, $data['data']['total_pages'], 'a set below the page size is one page' );
assert_true( false === $data['data']['truncated'], 'a small set is not truncated' );

// (34) parent_page_id filters through the NUMERIC meta_query clause.
$resp = diviops_call( 'canvas_list', array( diviops_canvas_request( array( 'parent_page_id' => 4300 ) ) ) );
$data = $resp->get_data();
$listed = array_column( $data['data']['canvases'], 'canvas_post_id' );
sort( $listed );
assert_same( array( 5400, 5401 ), $listed, 'parent_page_id scopes the list to that parent' );

// (35) The per-row shape, including the ?: null coercions.
$row = null;
foreach ( $data['data']['canvases'] as $candidate ) {
	if ( 5400 === $candidate['canvas_post_id'] ) {
		$row = $candidate;
	}
}
assert_true( null !== $row, 'the filtered list contains the canvas under test' );
assert_same( 'First', $row['title'], 'the row carries the title' );
assert_same( 'first-canvas', $row['canvas_id'], 'the row carries the canvas_id' );
assert_same( 4300, $row['parent_page_id'], 'the row casts parent_page_id to int' );
assert_same( null, $row['append_to_main'], 'an unset append_to_main is reported as null, not an empty string' );
assert_same( 2, $row['z_index'], 'the row carries z_index' );
assert_same( 'publish', $row['status'], 'the row carries the post status' );

// (36) A canvas the caller cannot inspect is dropped from the list AND from the
// totals — pagination metadata describes the filtered set, not the raw query.
$GLOBALS['diviops_test_uneditable_ids'] = array( 5401 );
$resp = diviops_call( 'canvas_list', array( diviops_canvas_request( array( 'parent_page_id' => 4300 ) ) ) );
$data = $resp->get_data();
assert_same( array( 5400 ), array_column( $data['data']['canvases'], 'canvas_post_id' ), 'an uninspectable canvas is dropped from the rows' );
assert_same( 1, $data['data']['total'], 'the total describes the filtered set, not the raw query' );
assert_same( 2, $data['data']['scanned'], 'scanned reports what the query examined before filtering' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// ── canvas_orphan_audit ───────────────────────────────────────────────────

diviops_canvas_reset();
diviops_test_register_post( 4300, '', 'page', 'Parent Page' );
diviops_canvas_fixture( 5500, 'Attached', '', 4300, 'attached-canvas' );
diviops_canvas_fixture( 5501, 'Adrift', '', 0, 'adrift-canvas' );
diviops_canvas_fixture( 5502, 'Nameless', '', 0, '' );

// (37) An unsupported status is refused rather than silently widened.
$resp = diviops_call( 'canvas_orphan_audit', array( diviops_canvas_request( array( 'status' => 'nonsense' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an unsupported audit status is invalid_input' );
assert_same(
	'status must be one of: any, publish, draft, pending, private, future, trash.',
	$data['error']['message'],
	'the audit status message lists the accepted set'
);

// (38) The three verdicts this fixture set produces.
$resp = diviops_call( 'canvas_orphan_audit', array( diviops_canvas_request( array() ) ) );
$data     = $resp->get_data();
$verdicts = array();
foreach ( $data['data']['canvases'] as $row ) {
	$verdicts[ $row['canvas_post_id'] ] = $row['verdict'];
}
ksort( $verdicts );
assert_true( $data['ok'], 'canvas_orphan_audit succeeds' );
assert_same( 'referenced', $verdicts[5500], 'a canvas whose parent post is readable is referenced' );
assert_same( 'likely_orphan', $verdicts[5501], 'a canvas with no reference evidence at all is a likely orphan' );
assert_same( 'unknown', $verdicts[5502], 'a canvas with no _divi_canvas_id cannot be classified, so it is unknown' );
assert_same( 3, $data['data']['summary']['total'], 'the summary counts every audited canvas' );
assert_same( 1, $data['data']['summary']['referenced'], 'the summary counts the referenced canvas' );
assert_same( 1, $data['data']['summary']['likely_orphan'], 'the summary counts the likely orphan' );
assert_same( 1, $data['data']['summary']['unknown'], 'the summary counts the unknown' );

// (39) The evidence behind a verdict is reported, not just the verdict.
$attached = null;
foreach ( $data['data']['canvases'] as $row ) {
	if ( 5500 === $row['canvas_post_id'] ) {
		$attached = $row;
	}
}
assert_true( null !== $attached, 'the audit includes the attached canvas' );
assert_same( 'parent_post_meta', $attached['references'][0]['kind'], 'the parent-meta reference is reported by kind' );
assert_same( 'authoritative', $attached['references'][0]['strength'], 'the parent-meta reference is authoritative' );
assert_same( 100, $attached['confidence'], 'an authoritative reference scores full confidence' );
assert_same( hash( 'sha256', '' ), $attached['content_checksum'], 'each row carries a content checksum' );

// (40) A stale parent pointer is an unknown, not an orphan — the audit refuses
// to call a canvas unreferenced when the evidence is merely unreadable.
diviops_canvas_reset();
diviops_canvas_fixture( 5600, 'Stale', '', 777777, 'stale-canvas' );
$resp = diviops_call( 'canvas_orphan_audit', array( diviops_canvas_request( array() ) ) );
$row  = $resp->get_data()['data']['canvases'][0];
assert_same( 'unknown', $row['verdict'], 'a canvas pointing at a missing parent is unknown, not a likely orphan' );
assert_same( 'stale_parent_post_id', $row['unknowns'][0]['kind'], 'the stale pointer is reported as its own unknown kind' );
assert_same( 0, $row['confidence'], 'an unknown verdict carries zero confidence' );

// ── the audit is read-only ────────────────────────────────────────────────

// (41) Nothing in the audit path mutates the store. This is the property the
// handler's own docblock claims, and it is the one an adopted hunk could break
// without any other assertion here noticing.
$before = array_keys( $GLOBALS['diviops_test_posts'] );
diviops_call( 'canvas_orphan_audit', array( diviops_canvas_request( array( 'include_context' => false ) ) ) );
assert_same( $before, array_keys( $GLOBALS['diviops_test_posts'] ), 'the audit removed and created nothing' );

// ── route wiring ──────────────────────────────────────────────────────────

$plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );

foreach ( array( 'create', 'list', 'orphan-audit', 'get', 'update', 'delete', 'duplicate' ) as $canvas_route ) {
	assert_true(
		false !== strpos( $plugin_src, "'/canvas/{$canvas_route}" ),
		"the /canvas/{$canvas_route} route is registered"
	);
}

// (42) canvas_create and canvas_duplicate are the two canvas routes gated on
// check_canvas_create_permission(), which is where this fork enforces the
// et_pb_canvas create+publish capability pair. Upstream's 2026-08-31 sync adds
// the same capability check a second time inside both handlers; this assertion
// records where ours lives, so a later adoption is a visible decision to hold
// the check in two places rather than a silent one.
assert_same(
	2,
	substr_count( $plugin_src, "'check_canvas_create_permission'" ),
	'exactly two routes are gated on check_canvas_create_permission'
);
assert_true(
	false !== strpos( $plugin_src, "return self::fixed_publish_route_permission( 'edit_pages', [ 'et_pb_canvas' ] );" ),
	'check_canvas_create_permission requires edit_pages plus the et_pb_canvas create/publish caps'
);

foreach ( array( 'canvas_create', 'canvas_duplicate', 'canvas_list', 'canvas_orphan_audit', 'canvas_get', 'canvas_update', 'canvas_delete' ) as $canvas_handler ) {
	assert_true(
		method_exists( 'DiviOps_Agent', $canvas_handler ),
		"DiviOps_Agent::{$canvas_handler} exists once the trait is mixed in"
	);
}

diviops_canvas_reset();
unset( $GLOBALS['diviops_test_wp_query_unmodelled_ok'] );
