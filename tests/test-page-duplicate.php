<?php
/**
 * page_duplicate() — same-site whole-page duplication (#35 / G4, owner-split
 * comment 2026-07-29).
 *
 * Scope, per the split: read a page's layout (reusing page_get_layout's raw
 * content) and write it, unchanged, into a fresh post (reusing page_create's
 * created-post response shape). NO reference remapping — attachment ids,
 * internal links, and global color/font/variable refs are already valid
 * because the target IS the source site. That is explicitly out of scope
 * (cross-site remapping is #96) and the response says so via
 * `references_remapped: false` rather than leaving a caller to assume it
 * happened.
 *
 * BYTE COPY, NOT A PARSE/SERIALIZE ROUND TRIP (course-corrected after live
 * verification). The first version of this handler round-tripped the source
 * content through parse_blocks_for_write() + serialize_blocks() and the same
 * integrity-guard discipline page_block_insert() (#32) uses, on the theory
 * that any parse/serialize round trip needs that guard. Live testing against
 * a real Divi page (900390: 62KB, 23 block types, divi/global-layout
 * present) found that theory wrong two ways: (1) the write-safety validator
 * every guarded write runs false-positived on a `u003c`-shaped escape Divi
 * itself legitimately emits, refusing to duplicate a page that parses and
 * renders fine; (2) even when it succeeded, serialize_blocks(parse_blocks($c))
 * on that same page shifted 62,167 -> 61,855 bytes — lossy, for an operation
 * whose entire job is to produce an exact copy. Duplication does not MUTATE
 * a block tree the way page_block_insert does, so it has no reason to parse
 * one at all: no parse means the #11 divi/global-layout materialization
 * hazard is not merely guarded against, it is structurally impossible. This
 * matches canvas_duplicate() (trait-canvas.php), which already byte-copies
 * post_content via wp_slash( $source->post_content ) — the repo's
 * established precedent for a same-site content DUPLICATE as opposed to a
 * content MUTATION.
 *
 * WHAT CHANGED FOR THIS TEST FILE. Because the write path no longer touches
 * parse_blocks()/serialize_blocks() at all, it is now — unlike the first
 * version of this handler, and unlike page_block_insert's own tests — fully
 * exercisable in tests/wp-shim.php's harness, including the non-dry-run
 * write. This file adds real happy-path coverage (byte-identical content,
 * copied meta/terms/template/featured-image, and the global-layout-survival
 * case, now trivially true by construction rather than merely guarded) that
 * the previous version could only cover structurally/live.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

function diviops_pd_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

// ── not_found ───────────────────────────────────────────────────────────

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 8888 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'an unknown source page id is not_found' );
assert_same( 404, $resp->get_status(), 'not_found is HTTP 404' );

// From here on, real source pages exist so the guards past not_found are reachable.

diviops_test_register_post(
	5100,
	'<!-- wp:divi/section --><!-- wp:divi/text --><!-- /wp:divi/text --><!-- /wp:divi/section -->',
	'page',
	'Landing Page'
);

diviops_test_register_post(
	5101,
	'<p>Just some classic-editor HTML, no Divi markup at all.</p>',
	'page',
	'Plain Page'
);

// ── invalid title / status / post_type (pre-write guards) ────────────────

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100, 'title' => array( 'not', 'a', 'string' ) ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a non-string title is invalid_input' );
assert_same( 'title', $data['error']['data']['field'], 'the title error names the field' );

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100, 'status' => 'not-a-real-status' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an unrecognized status is invalid_input' );
assert_same( 'status', $data['error']['data']['field'], 'the status error names the field' );

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100, 'post_type' => 'not_a_registered_type' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an unregistered post_type is invalid_input' );
assert_same( 'post_type', $data['error']['data']['field'], 'the post_type error names the field' );

// ── dry_run: writes nothing, and reports the full plan honestly ──────────

$posts_before = count( $GLOBALS['diviops_test_posts'] );

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'dry_run on a valid source succeeds' );
assert_true( $data['data']['dry_run'], 'the response is marked dry_run' );
assert_same( $posts_before, count( $GLOBALS['diviops_test_posts'] ), 'dry_run creates no post — the registry is unchanged' );

$change = $data['data']['plan']['changes'][0];
assert_same( 'page.duplicate', $change['kind'], 'the plan change is kinded page.duplicate' );
assert_same( 'Landing Page (Copy)', $change['after']['title'], 'title defaults to "<source title> (Copy)" when omitted' );
assert_same( 'draft', $change['after']['status'], 'status defaults to draft when omitted' );
assert_same( 'page', $change['after']['post_type'], 'post_type defaults to inheriting the source post_type (page) when omitted' );
assert_same( 5100, $change['after']['source_id'], 'the plan names the source page id' );

// references_remapped: false, disclosed explicitly rather than left implicit —
// the core scope decision from the #35 split comment. Still accurate after
// the byte-copy redesign: this field is about cross-site references, not
// about how the write itself is performed.
assert_true(
	array_key_exists( 'references_remapped', $data['data'] ) && false === $data['data']['references_remapped'],
	'the response explicitly discloses references_remapped: false, not silence'
);
assert_true( $data['data']['source_uses_divi'], 'a source page with divi/* block markup is reported as source_uses_divi: true' );

// Explicit title is honored verbatim (no auto-suffix, unlike canvas_duplicate —
// page titles are not a WordPress uniqueness key, so no collision handling is
// needed; see this PR's report for the rationale).
$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100, 'dry_run' => true, 'title' => 'Custom Duplicate Title' ) ) ) );
$data = $resp->get_data();
assert_same( 'Custom Duplicate Title', $data['data']['plan']['changes'][0]['after']['title'], 'an explicit title is used as-is' );

// Explicit status/post_type override the defaults.
$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100, 'dry_run' => true, 'status' => 'publish', 'post_type' => 'post' ) ) ) );
$data = $resp->get_data();
assert_same( 'publish', $data['data']['plan']['changes'][0]['after']['status'], 'an explicit status overrides the draft default' );
assert_same( 'post', $data['data']['plan']['changes'][0]['after']['post_type'], 'an explicit post_type overrides source-type inheritance' );

// ── non-Divi source: allowed, disclosed, not blocked ──────────────────────

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5101, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'duplicating a non-Divi (classic content) source page is allowed, not refused' );
assert_true( ! $data['data']['source_uses_divi'], 'a non-Divi source is reported as source_uses_divi: false' );
assert_same( 'Plain Page (Copy)', $data['data']['plan']['changes'][0]['after']['title'], 'title defaulting works the same for a non-Divi source' );

// ── happy path: real (non-dry-run) duplication, byte-identical content ────
//
// Now possible in this harness for the first time: byte-copying reads the
// source's raw post_content and hands it straight to wp_insert_post(),
// so — unlike the removed parse_blocks_for_write() path — nothing here
// needs WordPress's real block parser.

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5100 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a real (non-dry-run) duplication of a Divi source page succeeds' );
$new_id = $data['data']['page_id'];
assert_true( $new_id > 0 && $new_id !== 5100, 'the response reports a new page id distinct from the source' );
assert_same( 5100, $data['data']['source_id'], 'the response names the source id' );
assert_same( 'Landing Page (Copy)', $data['data']['title'], 'the default title landed on the created post' );
assert_same( 'draft', $data['data']['status'], 'the default status landed on the created post' );

$new_post = get_post( $new_id );
assert_true( null !== $new_post, 'the new post exists in the registry' );
assert_same(
	'<!-- wp:divi/section --><!-- wp:divi/text --><!-- /wp:divi/text --><!-- /wp:divi/section -->',
	$new_post->post_content,
	'the new post_content is BYTE-IDENTICAL to the source — no parse/serialize round trip touched it'
);

// The source itself is untouched by duplicating it.
$source_after = get_post( 5100 );
assert_same(
	'<!-- wp:divi/section --><!-- wp:divi/text --><!-- /wp:divi/text --><!-- /wp:divi/section -->',
	$source_after->post_content,
	'the source page content is unmutated by page_duplicate()'
);

// ── the #11 hazard, now trivially true by construction ────────────────────
//
// A source page carrying a divi/global-layout wrapper is byte-copied like
// any other content: the handler never calls parse_blocks() or
// parse_blocks_for_write() at all, so there is no parser in the path that
// could expand the wrapper. This is not "the guard caught it" — there is no
// guard, because there is nothing for a guard to catch. Verified end-to-end
// here (unlike the first version of this handler, which could only prove
// this structurally/live) precisely because the byte-copy path needs no
// unshimmed WordPress parser primitives.

$global_layout_content = '<!-- wp:divi/global-layout {"globalModule":"900296","blockName":"divi/section","builderVersion":"5.9.0"} /-->';
diviops_test_register_post( 5102, $global_layout_content, 'page', 'Global Layout Host' );

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5102 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'duplicating a page carrying a divi/global-layout wrapper succeeds' );
$gl_new_id   = $data['data']['page_id'];
$gl_new_post = get_post( $gl_new_id );
assert_same(
	$global_layout_content,
	$gl_new_post->post_content,
	'the divi/global-layout wrapper — including its globalModule id — survives the copy byte-for-byte, unmaterialized'
);
assert_true(
	false !== strpos( $gl_new_post->post_content, 'globalModule":"900296"' ),
	'the specific globalModule id is preserved, not just a wrapper-shaped string'
);

// ── regression: content our OLD validator would have false-positived on
// (mirrors the #35 live-testing bug found on page 900390) now succeeds ────
//
// normalize_divi_full_content_for_write() rejects a literal "u003c"-shaped
// substring inside block attrs (a malformed pseudo-escape) UNLESS it is
// backslash-escaped. Real Divi markup can legitimately contain this shape.
// The old parse/serialize/validate implementation ran every duplicated
// page's content through that validator and would have rejected this one;
// the byte-copy implementation never validates stored source content at
// all, so this now succeeds.

$validator_hostile_content = '<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"contains u003c literally, no backslash"}}}}} --><!-- /wp:divi/section -->';
diviops_test_register_post( 5103, $validator_hostile_content, 'page', 'Validator Hostile' );

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5103 ) ) ) );
$data = $resp->get_data();
assert_true(
	$data['ok'],
	'content shaped like the false-positive the old validated round trip rejected on page 900390 now duplicates successfully'
);
assert_same(
	$validator_hostile_content,
	get_post( $data['data']['page_id'] )->post_content,
	'the validator-hostile content is copied byte-for-byte'
);

// ── meta / terms / template / featured image / excerpt are copied ─────────

diviops_test_register_post( 5104, '<!-- wp:divi/section --><!-- /wp:divi/section -->', 'post', 'Full Metadata Source' );
$meta_source = get_post( 5104 );
$meta_source->post_excerpt = 'A hand-written excerpt.';
$meta_source->post_parent  = 42;
$meta_source->menu_order   = 7;
update_post_meta( 5104, '_wp_page_template', 'templates/custom-template.php' );
set_post_thumbnail( 5104, 321 );
diviops_test_register_taxonomy( 'category', array( 'post' ) );
diviops_test_register_object_terms( 5104, 'category', array( 11, 12 ) );

$resp = diviops_call( 'page_duplicate', array( diviops_pd_request( array( 'id' => 5104 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'duplicating a page with excerpt/parent/menu_order/template/thumbnail/terms succeeds' );
$meta_new_id   = $data['data']['page_id'];
$meta_new_post = get_post( $meta_new_id );

assert_same( 'A hand-written excerpt.', $meta_new_post->post_excerpt, 'post_excerpt is copied to the duplicate' );
assert_same( 42, $meta_new_post->post_parent, 'post_parent is copied to the duplicate' );
assert_same( 7, $meta_new_post->menu_order, 'menu_order is copied to the duplicate' );
assert_same(
	'templates/custom-template.php',
	get_post_meta( $meta_new_id, '_wp_page_template', true ),
	'_wp_page_template meta is copied to the duplicate'
);
assert_same( 321, get_post_thumbnail_id( $meta_new_id ), 'the featured image (thumbnail id) is copied to the duplicate' );
assert_same(
	array( 11, 12 ),
	wp_get_object_terms( $meta_new_id, 'category', array( 'fields' => 'ids' ) ),
	'taxonomy term assignments are copied to the duplicate'
);

// The source's own meta/terms are untouched by duplicating it.
assert_same( 'A hand-written excerpt.', get_post( 5104 )->post_excerpt, 'source post_excerpt is unmutated' );
assert_same(
	array( 11, 12 ),
	wp_get_object_terms( 5104, 'category', array( 'fields' => 'ids' ) ),
	'source taxonomy terms are unmutated'
);

// ── structural regression: route wiring + capability key ──────────────────

$plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );
assert_true(
	1 === preg_match(
		"/register_rest_route\\(\\s*self::REST_NAMESPACE,\\s*'\\/page\\/duplicate[^']*',\\s*\\[\\s*'methods'\\s*=>\\s*'POST',\\s*'callback'\\s*=>\\s*\\[\\s*__CLASS__,\\s*'page_duplicate'\\s*\\]/s",
		$plugin_src
	),
	'/page/duplicate is registered as a POST route dispatching to page_duplicate'
);
assert_true(
	1 === preg_match( "/'page_duplicate'/", $plugin_src ),
	"the 'page_duplicate' capability key is present in CAPABILITIES"
);
assert_true(
	method_exists( 'DiviOps_Agent', 'page_duplicate' ),
	'DiviOps_Agent::page_duplicate exists once the trait is mixed in'
);

// ── structural regression: page_duplicate() byte-copies, it does NOT parse ─
//
// The inverse of what the first version of this suite asserted. Isolate
// EXACTLY page_duplicate()'s own body — not a neighboring method's docblock
// OR body — by brace-matching from its opening "{" to the matching closing
// "}", rather than bounding the window at the next `public static function`
// occurrence. A first attempt at this fix used exactly that "next method"
// bound and it was STILL wrong: page_block_insert's own DOCBLOCK (which
// precedes its "public static function" line, and itself mentions
// parse_blocks_for_write()) fell inside the window, so the assertion below
// passed while partly inspecting a comment about a different method, not
// page_duplicate()'s code — the "gate that passes while inspecting nothing"
// failure class CLAUDE.md warns about, caught here by actually checking the
// window's contents rather than trusting the bound. Brace-matching has no
// such gap: it stops at page_duplicate()'s own closing brace, period.

$trait_src            = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-page.php' );
$signature_needle     = 'public static function page_duplicate(';
$page_duplicate_start = strpos( $trait_src, $signature_needle );
assert_true( false !== $page_duplicate_start, 'page_duplicate() is defined in trait-page.php' );

$brace_open = strpos( $trait_src, '{', $page_duplicate_start );
assert_true( false !== $brace_open, "page_duplicate()'s opening brace was found" );

$depth  = 0;
$cursor = $brace_open;
$len    = strlen( $trait_src );
$brace_close = null;
while ( $cursor < $len ) {
	$ch = $trait_src[ $cursor ];
	if ( '{' === $ch ) {
		++$depth;
	} elseif ( '}' === $ch ) {
		--$depth;
		if ( 0 === $depth ) {
			$brace_close = $cursor;
			break;
		}
	}
	++$cursor;
}
assert_true( null !== $brace_close, "page_duplicate()'s matching closing brace was found by brace-depth walk" );

$page_duplicate_body = substr( $trait_src, $page_duplicate_start, $brace_close - $page_duplicate_start + 1 );
// Sanity check on the bound itself: the extracted body must not spill into
// the next method's signature — proof the window is exactly one method, not
// "roughly one method plus whatever followed it."
assert_true(
	false === strpos( $page_duplicate_body, 'public static function page_block_insert' ),
	"the extracted page_duplicate() body does not spill into page_block_insert()'s signature — the brace-matched bound is exact"
);

assert_true(
	false === strpos( $page_duplicate_body, 'parse_blocks_for_write(' ),
	'page_duplicate() does NOT call parse_blocks_for_write() — duplication byte-copies, it never parses'
);
assert_true(
	false === strpos( $page_duplicate_body, 'update_post_content_with_integrity_guard(' ),
	'page_duplicate() does NOT call update_post_content_with_integrity_guard() — a single wp_insert_post() carries the final content, so there is no second guarded write'
);
assert_true(
	false !== strpos( $page_duplicate_body, 'wp_insert_post(' ),
	'page_duplicate() creates the new post via wp_insert_post()'
);
assert_true(
	1 === preg_match( '/wp_insert_post\\(\\s*\\[[^;]*?wp_slash\\(\\s*\\$source_content\\s*\\)/s', $page_duplicate_body ),
	'page_duplicate() passes the source\'s raw content, wp_slash()-escaped, straight into the wp_insert_post() call — a byte copy, not a derived/reserialized value'
);
