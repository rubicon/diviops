<?php
// SPDX-License-Identifier: MIT
/**
 * marked_unused_at reporting on the Theme Builder read surfaces (#303).
 *
 * Divi runs its own two-stage garbage collector over Theme Builder posts.
 * et_theme_builder_trash_draft_and_unused_posts() (theme-builder.php) stamps
 * every template and layout the active master does not reference with
 * `_et_theme_builder_marked_as_unused`, carrying a timestamp, then trashes
 * anything marked more than 7 days earlier. The marker is deleted again the
 * moment a post becomes referenced, so it is self-correcting.
 *
 * That makes it a cheap, Divi-native orphan signal — and a deletion warning,
 * since a marked post is queued to be trashed and then purged according to the
 * site's EMPTY_TRASH_DAYS. DiviOps read nothing of it.
 *
 * These tests pin the accepted fix: the stored value is reported verbatim on
 * both read surfaces, null when absent, and DiviOps never writes the key.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Index a tb_template_list result set by template id.
 *
 * @param array $results tb_template_list `results` payload.
 * @return array<int, array>
 */
function diviops_marked_rows_by_id( array $results ): array {
	$by_id = array();
	foreach ( $results as $row ) {
		$by_id[ (int) $row['id'] ] = $row;
	}
	return $by_id;
}

/**
 * Drop fixture posts and their meta from the process-wide registries.
 *
 * @param int[] $ids Fixture post ids.
 */
function diviops_marked_forget( array $ids ): void {
	foreach ( $ids as $id ) {
		unset(
			$GLOBALS['diviops_test_posts'][ $id ],
			$GLOBALS['diviops_test_post_meta'][ $id ],
			$GLOBALS['diviops_test_post_meta_rows'][ $id ]
		);
	}
}

// Divi writes this stamp with date(), not gmdate() — it is site-local time, and
// is reported exactly as stored rather than reinterpreted as UTC.
const DIVIOPS_MARKED_STAMP = '2026-07-19 17:48:44';

diviops_test_register_post( 730, '', 'et_theme_builder', 'Active master' );

diviops_test_register_post( 830, '', 'et_template', 'Live template' );
update_post_meta( 830, '_et_enabled', '1' );
update_post_meta( 830, '_et_default', '1' );
update_post_meta( 830, '_et_header_layout_id', '930' );
update_post_meta( 830, '_et_header_layout_enabled', '1' );
add_post_meta( 730, '_et_template', 830 );

// 831 is an orphan Divi has already marked — the shape this issue is about.
diviops_test_register_post( 831, '', 'et_template', 'Marked orphan template' );
update_post_meta( 831, '_et_enabled', '1' );
update_post_meta( 831, '_et_theme_builder_marked_as_unused', DIVIOPS_MARKED_STAMP );

diviops_test_register_post( 930, '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->', 'et_header_layout', 'Live layout' );
diviops_test_register_post( 931, '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->', 'et_header_layout', 'Marked orphan layout' );
update_post_meta( 931, '_et_theme_builder_marked_as_unused', DIVIOPS_MARKED_STAMP );

// ── tb_template_list ─────────────────────────────────────────────────────

// tb_template_list() asks WP_Query for `orderby => 'ID', order => 'ASC'`
// (trait-theme-builder.php:284-291). The shim refuses orderby rather than
// approximating an ordering it cannot compute from these fixtures (#330);
// waiving it here asserts that the argument is inert for this file, which holds
// twice over — the fixtures are registered in ascending id order, and every
// assertion below reads the result through diviops_marked_rows_by_id(), which
// indexes by template id rather than by position.
$GLOBALS['diviops_test_wp_query_unmodelled_ok'] = array( 'orderby', 'order' );

$rows = diviops_marked_rows_by_id(
	diviops_call( 'tb_template_list', array( new DiviOps_Test_Request( array() ) ) )->get_data()['data']['results'] ?? array()
);

assert_true( array_key_exists( 'marked_unused_at', $rows[830] ?? array() ), 'every template row carries marked_unused_at' );
// Read directly, not through ??, which collapses null into the default and so
// could never observe the value under test. The key's presence is asserted above.
assert_same( null, $rows[830]['marked_unused_at'], 'an unmarked template reports null' );
assert_same( DIVIOPS_MARKED_STAMP, $rows[831]['marked_unused_at'] ?? null, 'a marked template reports the stored stamp verbatim' );

// The marker is orthogonal to the reachability fields, not a substitute for them.
assert_same( true, $rows[830]['effective'] ?? null, 'the live template is still effective' );
assert_same( false, $rows[831]['effective'] ?? null, 'the marked orphan is still reported unreachable' );

// ── tb_layout_get ────────────────────────────────────────────────────────

$live_layout = diviops_call( 'tb_layout_get', array( new DiviOps_Test_Request( array( 'id' => 930 ) ) ) )->get_data()['data'] ?? array();
assert_true( array_key_exists( 'marked_unused_at', $live_layout ), 'the layout payload carries marked_unused_at' );
assert_same( null, $live_layout['marked_unused_at'], 'an unmarked layout reports null' );

$marked_layout = diviops_call( 'tb_layout_get', array( new DiviOps_Test_Request( array( 'id' => 931 ) ) ) )->get_data()['data'] ?? array();
assert_same( DIVIOPS_MARKED_STAMP, $marked_layout['marked_unused_at'] ?? null, 'a marked layout reports the stored stamp verbatim' );
assert_same( false, $marked_layout['effective'] ?? null, 'a marked layout is also reported unreachable' );

// ── Divi clears the marker when a post is referenced again ───────────────
//
// The GC deletes the meta for every post in the used set on each run, so a
// re-referenced post must report null again rather than a stale timestamp.

delete_post_meta( 831, '_et_theme_builder_marked_as_unused' );
add_post_meta( 730, '_et_template', 831 );

$recovered = diviops_marked_rows_by_id(
	diviops_call( 'tb_template_list', array( new DiviOps_Test_Request( array() ) ) )->get_data()['data']['results'] ?? array()
);
assert_true( array_key_exists( 'marked_unused_at', $recovered[831] ?? array() ), 'the re-referenced template still carries the field' );
assert_same( null, $recovered[831]['marked_unused_at'], 'a re-referenced template reports null again' );
assert_same( true, $recovered[831]['is_active_master'] ?? null, 're-linking the template puts it under the active master' );

// ── DiviOps never writes the marker ──────────────────────────────────────
//
// The key is Divi's, and it schedules deletion. Writing or clearing it would
// give DiviOps a share in that schedule. Read the real source and assert no
// write call site exists, rather than trusting that none was added.

$trait_source = file_get_contents( __DIR__ . '/../plugins/diviops-agent/includes/trait-theme-builder.php' );
assert_true( is_string( $trait_source ) && '' !== $trait_source, 'the theme-builder trait source is readable' );
assert_true(
	false !== strpos( $trait_source, '_et_theme_builder_marked_as_unused' ),
	'the trait reads the marker at all — otherwise this guard would pass while inspecting nothing'
);
foreach ( array( 'update_post_meta', 'add_post_meta', 'delete_post_meta' ) as $writer ) {
	assert_same(
		0,
		preg_match( '/' . preg_quote( $writer, '/' ) . '\s*\([^)]*_et_theme_builder_marked_as_unused/', $trait_source ),
		"the trait never calls {$writer}() on the unused marker"
	);
}

diviops_marked_forget( array( 730, 830, 831, 930, 931 ) );

unset( $GLOBALS['diviops_test_wp_query_unmodelled_ok'] );
