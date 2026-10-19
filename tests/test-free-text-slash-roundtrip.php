<?php
// SPDX-License-Identifier: MIT
/**
 * Free-text write fields survive a round trip with their backslashes (#595).
 *
 * `post_content` was wrapped in `wp_slash()` on essentially every write in this
 * plugin and every other free-text field was not. Core unslashes on the way in —
 * `wp_insert_post()` strips the DB-bound data (wp-includes/post.php) and
 * `add_metadata()`/`update_metadata()` strip key and value (wp-includes/meta.php)
 * — so each of those stored a value with its backslashes removed while the
 * response echoed what the caller sent. The tool reported a title it had not
 * stored.
 *
 * Every assertion below reads the STORED `post_title` back through `get_post()`,
 * never the response envelope, because the response echo is exactly what was
 * trustworthy while the bug was live.
 *
 * ## Why this file requires only the shim
 *
 * It does NOT require the #598 stubs (`tests/wp-block-parser-shim.php`,
 * `tests/wp-block-registry-rest-shim.php`), and that is the point rather than an
 * omission. #595, #598 and #603 all recorded that these twelve sites "sit behind
 * `parse_blocks()`" and therefore could carry no end-to-end assertion. That was
 * wrong, and this file is the refutation.
 *
 * Measured by driving all nine handlers twice in one process — once requiring
 * only the shim, once also requiring both #598 stubs with `DIVIOPS_WP_SOURCE`
 * set — and getting identical results. Since `parse_blocks()` is undefined in the
 * first run, a reached call would have fataled; it did not. A `token_get_all()`
 * scan with `T_COMMENT`/`T_DOC_COMMENT` stripped confirms why: none of the nine
 * calls `parse_blocks()` or `parse_blocks_for_write()` directly. Five reach
 * `authoring_shape_preflight()` (trait-authoring-shape.php), which guards its
 * parse with `if ( ! function_exists( 'parse_blocks' ) ) { continue; }` — a
 * documented, deliberate degradation. Contrast
 * `normalize_and_validate_divi_markup_before_write()` (trait-theme-builder.php),
 * whose `parse_blocks_for_write()` call is unguarded: that is the path
 * `page_block_insert` fatals on, and the real justification for #598.
 *
 * The original claim came from a plain `grep` for `parse_blocks`, which matches
 * comments — `trait-canvas.php` mentions it in prose above an unrelated budget
 * check. Use the tokenizer.
 *
 * ## What is NOT covered, and what it would take
 *
 *   - `library_save` (`trait-library.php`) cannot be driven here. Its
 *     uniqueness probe calls `WP_Query` with a hardcoded, unconditional
 *     `tax_query`, and the shim refuses unmodelled arguments rather than
 *     ignoring them (`diviops_test_query_refuse_unmodelled()`) — which is
 *     correct behaviour, not a bug. No `layout_type`/`scope` combination avoids
 *     it: both are required, validated against fixed allow-lists, and the
 *     `tax_query` is written into the args array literally. Driving it needs a
 *     dedicated stub modelling `tax_query` against the shim's term registry.
 *     Reported rather than faked, per CONTRIBUTING.md's shim contract. Its
 *     *slashing* is still covered, structurally, in section C.
 *   - `layout_import_apply_new` (`trait-layout-import.php`) is private and
 *     reached from `page_layout_import`, which takes an `artifact_json` payload
 *     string from `page_export`, not `content`. Driving it end to end means
 *     constructing that payload; out of scope here and left as a named gap.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * The fixture title. One literal backslash, which is the entire point: core
 * unslashes DB-bound data, so an unslashed write loses it.
 */
const DIVIOPS_FTSR_TITLE = 'Hero \\ Alpha';

const DIVIOPS_FTSR_ROW = '<!-- wp:divi/row {"builderVersion":"5.0.0","module":{"meta":{"adminLabel":{"desktop":{"value":"R"}}}}} --><!-- /wp:divi/row -->';

/**
 * Clear every registry slot a drive can write, so each case starts from an empty
 * world.
 *
 * Not hygiene — load-bearing. Without it `canvas_create` leaves a canvas whose
 * title matches the next case's, and `canvas_duplicate` then refuses with a
 * `conflict` that belongs to the previous assertion. Section B turns that leak
 * into an assertion rather than suppressing it.
 */
function diviops_ftsr_reset(): void {
	$GLOBALS['diviops_test_posts']          = array();
	$GLOBALS['diviops_test_post_meta']      = array();
	$GLOBALS['diviops_test_post_meta_rows'] = array();
	$GLOBALS['diviops_test_options']        = array();
	$GLOBALS['diviops_test_last_insert']    = array();
	$GLOBALS['diviops_test_next_id']        = 9000;
}

/**
 * The stored post_title for an id, read out of the registry the handler wrote to.
 *
 * @param int $post_id Post id.
 * @return string
 */
function diviops_ftsr_stored( int $post_id ): string {
	$post = get_post( $post_id );
	return (string) ( $post->post_title ?? '<<no such post>>' );
}

/**
 * Drive one handler and return its response data.
 *
 * @param string $handler Handler name on DiviOps_Agent.
 * @param array  $params  Request params.
 * @return array
 */
function diviops_ftsr_drive( string $handler, array $params ): array {
	$resp = diviops_call( $handler, array( new DiviOps_Test_Request( $params ) ) );
	return (array) $resp->get_data();
}

/**
 * Register a source post complete enough to be duplicated.
 *
 * `page_duplicate()` reads `post_name` and `post_modified_gmt` off the source
 * object (trait-page.php). `diviops_test_register_post()` deliberately defaults
 * only post_excerpt/post_parent/menu_order/post_status, so a fixture that skips
 * these emits undefined-property warnings and the suite's output stops being
 * pristine. Set them here rather than widening the shared shim.
 *
 * @param int    $post_id   Post id.
 * @param string $post_type Post type.
 * @param string $title     Existing title.
 * @return object
 */
function diviops_ftsr_source( int $post_id, string $post_type, string $title ) {
	$post                    = diviops_test_register_post( $post_id, DIVIOPS_FTSR_ROW, $post_type, $title );
	$post->post_name         = 'source-' . $post_id;
	$post->post_modified_gmt = '2026-01-01 00:00:00';
	return $post;
}

// ── A. the ten sites, driven end to end ──────────────────────────────────

// canvas_create. Needs a parent page to attach to.
diviops_ftsr_reset();
diviops_ftsr_source( 8099, 'page', 'Parent Page' );
$ftsr = diviops_ftsr_drive( 'canvas_create', array(
	'title'          => DIVIOPS_FTSR_TITLE,
	'content'        => DIVIOPS_FTSR_ROW,
	'parent_page_id' => 8099,
) );
assert_true( true === ( $ftsr['ok'] ?? null ), 'canvas_create runs to completion with no block parser present' );
assert_same(
	DIVIOPS_FTSR_TITLE,
	diviops_ftsr_stored( (int) $ftsr['data']['canvas_post_id'] ),
	'canvas_create stores the title with its backslash intact'
);

// canvas_duplicate.
diviops_ftsr_reset();
diviops_ftsr_source( 8100, 'et_pb_canvas', 'Source Canvas' );
$ftsr = diviops_ftsr_drive( 'canvas_duplicate', array(
	'id'    => 8100,
	'title' => DIVIOPS_FTSR_TITLE,
) );
assert_true( true === ( $ftsr['ok'] ?? null ), 'canvas_duplicate runs to completion' );
assert_same(
	DIVIOPS_FTSR_TITLE,
	diviops_ftsr_stored( (int) $ftsr['data']['id'] ),
	'canvas_duplicate stores the new title with its backslash intact'
);

// canvas_update, which rewrites the title on an existing post.
diviops_ftsr_reset();
diviops_ftsr_source( 8101, 'et_pb_canvas', 'Old Canvas Title' );
$ftsr = diviops_ftsr_drive( 'canvas_update', array(
	'id'    => 8101,
	'title' => DIVIOPS_FTSR_TITLE,
) );
assert_true( true === ( $ftsr['ok'] ?? null ), 'canvas_update runs to completion' );
assert_same(
	DIVIOPS_FTSR_TITLE,
	diviops_ftsr_stored( 8101 ),
	'canvas_update rewrites the title with its backslash intact'
);

// page_create.
diviops_ftsr_reset();
$ftsr = diviops_ftsr_drive( 'page_create', array(
	'title'   => DIVIOPS_FTSR_TITLE,
	'content' => DIVIOPS_FTSR_ROW,
) );
assert_true( true === ( $ftsr['ok'] ?? null ), 'page_create runs to completion' );
assert_same(
	DIVIOPS_FTSR_TITLE,
	diviops_ftsr_stored( (int) $ftsr['data']['page_id'] ),
	'page_create stores the title with its backslash intact'
);

// page_duplicate.
diviops_ftsr_reset();
diviops_ftsr_source( 8102, 'page', 'Source Page' );
$ftsr = diviops_ftsr_drive( 'page_duplicate', array(
	'id'    => 8102,
	'title' => DIVIOPS_FTSR_TITLE,
) );
assert_true( true === ( $ftsr['ok'] ?? null ), 'page_duplicate runs to completion' );
assert_same(
	DIVIOPS_FTSR_TITLE,
	diviops_ftsr_stored( (int) $ftsr['data']['page_id'] ),
	'page_duplicate stores the new title with its backslash intact'
);

// page_update_meta.
diviops_ftsr_reset();
diviops_ftsr_source( 8103, 'page', 'Old Page Title' );
$ftsr = diviops_ftsr_drive( 'page_update_meta', array(
	'id'    => 8103,
	'title' => DIVIOPS_FTSR_TITLE,
) );
assert_true( true === ( $ftsr['ok'] ?? null ), 'page_update_meta runs to completion' );
assert_same(
	DIVIOPS_FTSR_TITLE,
	diviops_ftsr_stored( 8103 ),
	'page_update_meta rewrites the title with its backslash intact'
);

// tb_template_create writes FOUR of the nineteen sites in one call: the template
// title, and three layout titles that each append a role suffix to it. The
// suffixed assertions are what pin those three lines specifically — a fix applied
// to the template title alone would leave them failing.
diviops_ftsr_reset();
$ftsr = diviops_ftsr_drive( 'tb_template_create', array(
	'title'          => DIVIOPS_FTSR_TITLE,
	'body_content'   => DIVIOPS_FTSR_ROW,
	'header_content' => DIVIOPS_FTSR_ROW,
	'footer_content' => DIVIOPS_FTSR_ROW,
	// A specific condition rather than the default slot: the default path
	// refuses when a master template already exists, which is a different
	// branch than the one these four title writes live on.
	'condition'      => 'homepage',
) );
assert_true( true === ( $ftsr['ok'] ?? null ), 'tb_template_create runs to completion' );
assert_same(
	DIVIOPS_FTSR_TITLE,
	diviops_ftsr_stored( (int) $ftsr['data']['template_id'] ),
	'tb_template_create stores the template title with its backslash intact'
);
assert_same(
	DIVIOPS_FTSR_TITLE . ' Header Layout',
	diviops_ftsr_stored( (int) $ftsr['data']['header_layout_id'] ),
	'the header layout title appends its suffix to the slash-preserved title'
);
assert_same(
	DIVIOPS_FTSR_TITLE . ' Footer Layout',
	diviops_ftsr_stored( (int) $ftsr['data']['footer_layout_id'] ),
	'the footer layout title appends its suffix to the slash-preserved title'
);
assert_same(
	DIVIOPS_FTSR_TITLE . ' Body Layout',
	diviops_ftsr_stored( (int) $ftsr['data']['body_layout_id'] ),
	'the body layout title appends its suffix to the slash-preserved title'
);

// ── B. the probe coupling ────────────────────────────────────────────────
//
// canvas_existing_id_by_title() hands its title to WP_Query, which runs
// stripslashes() on that query var before comparing it to the stored post_title
// (wp-includes/class-wp-query.php, verified against the WordPress 7.1.3 install
// on staging). So the probe has to be slashed for the same reason the write
// does, and fixing the write alone would make a backslash-bearing title
// permanently unmatchable — the uniqueness refusal would stop firing and the
// handler would create duplicates instead.
//
// Driven without a reset on purpose: the canvas created here is what the second
// call must find.
diviops_ftsr_reset();
diviops_ftsr_source( 8104, 'page', 'Parent Page' );
$ftsr = diviops_ftsr_drive( 'canvas_create', array(
	'title'          => DIVIOPS_FTSR_TITLE,
	'content'        => DIVIOPS_FTSR_ROW,
	'parent_page_id' => 8104,
) );
assert_true( true === ( $ftsr['ok'] ?? null ), 'the first canvas_create succeeds' );

$ftsr = diviops_ftsr_drive( 'canvas_create', array(
	'title'          => DIVIOPS_FTSR_TITLE,
	'content'        => DIVIOPS_FTSR_ROW,
	'parent_page_id' => 8104,
) );
assert_true( false === ( $ftsr['ok'] ?? null ), 'a second canvas_create with the same backslashed title is refused' );
assert_same(
	'conflict',
	$ftsr['error']['code'] ?? null,
	'the uniqueness probe still matches a stored title carrying a backslash'
);

// ── C. every title query var in the plugin is slashed ────────────────────
//
// Section B covers canvas behaviourally. library_save has the identical coupling
// and cannot be driven here (see the header), so it is covered structurally
// instead — and the gate is written over the whole plugin rather than over that
// one file, so a third probe added later is caught too.
//
// This found a real omission: trait-library.php's probe was left unslashed while
// its write was slashed, which is strictly worse than leaving both alone. Before,
// both sides were unslashed and self-consistent; after, the stored title keeps
// its backslash and the probe strips it, so the conflict refusal silently stops
// firing and duplicates get created.
$ftsr_sources = glob( __DIR__ . '/../plugins/diviops-agent/includes/*.php' );
assert_true(
	is_array( $ftsr_sources ) && count( $ftsr_sources ) > 10,
	'the plugin include directory was discovered — the positive control for the scan below'
);

$ftsr_scanned = 0;
$ftsr_probes  = array();
foreach ( (array) $ftsr_sources as $ftsr_file ) {
	$ftsr_lines = explode( "\n", (string) file_get_contents( $ftsr_file ) );
	foreach ( $ftsr_lines as $ftsr_i => $ftsr_line ) {
		if ( 1 !== preg_match( '/new\s+WP_Query\s*\(|\bget_posts\s*\(/', $ftsr_line ) ) {
			continue;
		}
		++$ftsr_scanned;
		// The args array sits above the call. Take the preceding window and look
		// for a 'title' query var alongside the 'post_type' that marks it as a
		// WP_Query args array rather than a response payload.
		$ftsr_window = implode( "\n", array_slice( $ftsr_lines, max( 0, $ftsr_i - 40 ), min( 41, $ftsr_i + 1 ) ) );
		if ( 1 !== preg_match( "/'post_type'\s*=>/", $ftsr_window ) ) {
			continue;
		}
		if ( 1 !== preg_match( "/'title'\s*=>\s*([^,\n]+)/", $ftsr_window, $ftsr_m ) ) {
			continue;
		}
		$ftsr_probes[ basename( $ftsr_file ) . ':' . ( $ftsr_i + 1 ) ] = trim( $ftsr_m[1] );
	}
}

// A scan that matched no call sites would pass the loop below having compared
// nothing, which is the failure mode tests/run.php fails on for empty discovery.
assert_true(
	$ftsr_scanned > 0,
	'the scan found WP_Query/get_posts call sites to inspect'
);
// Pinned deliberately. A third title-based probe appearing here is expected to
// fail this line: that failure is the signal to go slash it, not to widen the
// number. Divi 5.13 / agent 1.30.3 ships exactly these two.
assert_same(
	2,
	count( $ftsr_probes ),
	'the plugin has exactly two WP_Query title probes'
);
$ftsr_unslashed = array();
foreach ( $ftsr_probes as $ftsr_where => $ftsr_expr ) {
	if ( false === strpos( $ftsr_expr, 'wp_slash(' ) ) {
		$ftsr_unslashed[] = $ftsr_where . ' => ' . $ftsr_expr;
	}
}
assert_same(
	array(),
	$ftsr_unslashed,
	'every WP_Query title probe slashes its title, so none can be defeated by stripslashes()'
);

printf(
	"free-text slash round-trip: 10 write site(s) driven end to end; %d WP_Query call site(s) scanned, %d title probe(s) found, %d unslashed\n",
	$ftsr_scanned,
	count( $ftsr_probes ),
	count( $ftsr_unslashed )
);
