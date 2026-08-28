<?php
// SPDX-License-Identifier: MIT
/**
 * tb_template_list reachability reporting (#291).
 *
 * Divi only ever resolves templates belonging to the single *active* Theme
 * Builder master — the newest published `et_theme_builder` that is not a
 * library clone (et_theme_builder_get_theme_builder_post_id, theme-builder.php).
 * Template ids come only from that master's `_et_template` meta rows, and the
 * default pick is the FIRST default in that list (ThemeBuilderRequest::get_template
 * takes `$default_templates[0]`, applying the enable-gate after the pick).
 *
 * tb_template_list used to issue a flat `et_template` query with no master
 * scoping, so templates owned by trashed or superseded masters came back
 * indistinguishable from the live ones, carrying `is_default: true` flags that
 * could never apply. Saving the Theme Builder creates and trashes a master each
 * time, so any site that has been re-saved accumulates them.
 *
 * These tests pin the accepted fix: no row is filtered out, but every row states
 * which master owns it and whether it is reachable, and `is_default` is never
 * true for a template the router cannot see.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Index a tb_template_list result set by template id, so assertions can name a
 * template rather than depend on row order.
 *
 * @param array $results tb_template_list `results` payload.
 * @return array<int, array>
 */
function diviops_tb_rows_by_id( array $results ): array {
	$by_id = array();
	foreach ( $results as $row ) {
		$by_id[ (int) $row['id'] ] = $row;
	}
	return $by_id;
}

/**
 * Drop a set of fixture posts and all their meta from the shared registries, so
 * one scenario in this file cannot leak into the next. Scoped to the ids this
 * file created — the registries are process-wide and shared with every other
 * test file.
 *
 * @param int[] $ids Fixture post ids.
 */
function diviops_tb_forget_fixtures( array $ids ): void {
	foreach ( $ids as $id ) {
		unset(
			$GLOBALS['diviops_test_posts'][ $id ],
			$GLOBALS['diviops_test_post_meta'][ $id ],
			$GLOBALS['diviops_test_post_meta_rows'][ $id ]
		);
	}
}

// ── Scenario A: several masters, exactly one active ──────────────────────
//
// 700 trashed, 701 published (the active master), 702 trashed, 703 published
// but a library clone (Divi excludes those from master discovery via the
// `_et_library_theme_builder` meta-not-exists clause).

diviops_test_register_post( 700, '', 'et_theme_builder', 'Superseded master' );
$GLOBALS['diviops_test_posts'][700]->post_status = 'trash';
diviops_test_register_post( 701, '', 'et_theme_builder', 'Active master' );
diviops_test_register_post( 702, '', 'et_theme_builder', 'Superseded master 2' );
$GLOBALS['diviops_test_posts'][702]->post_status = 'trash';
diviops_test_register_post( 703, '', 'et_theme_builder', 'Library-cloned master' );
update_post_meta( 703, '_et_library_theme_builder', '1' );

foreach ( array( 800, 801, 802, 803, 804, 805 ) as $template_id ) {
	diviops_test_register_post( $template_id, '', 'et_template', 'Template ' . $template_id );
	update_post_meta( $template_id, '_et_enabled', '1' );
}

// 800 is a default owned by a trashed master — the exact shape the bug report hit.
update_post_meta( 800, '_et_default', '1' );
add_post_meta( 700, '_et_template', 800 );

// 801 and 802 are both defaults under the active master; only 801 wins, because
// it is first in the linked list. 803 is an ordinary conditional template.
update_post_meta( 801, '_et_default', '1' );
update_post_meta( 802, '_et_default', '1' );
add_post_meta( 803, '_et_use_on', 'all_pages' );
add_post_meta( 701, '_et_template', 801 );
add_post_meta( 701, '_et_template', 802 );
add_post_meta( 701, '_et_template', 803 );

// 804 is an orphan: no master's linked list names it at all.
update_post_meta( 804, '_et_default', '1' );

// 805 belongs to the library-cloned master, which never routes.
update_post_meta( 805, '_et_default', '1' );
add_post_meta( 703, '_et_template', 805 );

$response = diviops_call( 'tb_template_list', array( new DiviOps_Test_Request( array() ) ) );
$payload  = $response->get_data();

assert_true( ! empty( $payload['ok'] ), 'tb_template_list returns a success envelope' );

$data = $payload['data'] ?? array();
$rows = diviops_tb_rows_by_id( $data['results'] ?? array() );

assert_same( 701, $data['active_master_id'] ?? null, 'the response names the active master at top level' );
assert_same( 6, count( $rows ), 'every published template is still returned — no row is filtered out' );
assert_same( 6, (int) ( $data['total'] ?? 0 ), 'total still counts every published template' );

assert_same( 700, $rows[800]['master_id'] ?? null, 'a template owned by a trashed master reports that master' );
assert_same( false, $rows[800]['is_active_master'] ?? null, 'a trashed master is not the active master' );
assert_same( false, $rows[800]['is_default'] ?? null, 'a template under a trashed master never reports is_default: true' );
assert_same( false, $rows[800]['effective'] ?? null, 'a template under a trashed master is not effective' );

assert_same( 701, $rows[801]['master_id'] ?? null, 'the winning default reports the active master' );
assert_same( true, $rows[801]['is_active_master'] ?? null, 'the winning default is under the active master' );
assert_same( true, $rows[801]['is_default'] ?? null, 'the winning default still reports is_default: true' );
assert_same( true, $rows[801]['effective'] ?? null, 'the first default in the active master wins the default pick' );

assert_same( 701, $rows[802]['master_id'] ?? null, 'a shadowed default still reports its owning master' );
assert_same( true, $rows[802]['is_active_master'] ?? null, 'a shadowed default is still under the active master' );
assert_same( true, $rows[802]['is_default'] ?? null, 'a shadowed default is reachable, so its default flag stands' );
assert_same( false, $rows[802]['effective'] ?? null, 'a second default never wins the pick, so it is not effective' );

assert_same( 701, $rows[803]['master_id'] ?? null, 'a conditional template reports the active master' );
assert_same( false, $rows[803]['is_default'] ?? null, 'a conditional template is not a default' );
assert_same( true, $rows[803]['effective'] ?? null, 'a conditional template under the active master is effective' );

assert_same( 0, $rows[804]['master_id'] ?? null, 'a template no master links to reports master_id 0' );
assert_same( false, $rows[804]['is_active_master'] ?? null, 'an orphan template is not under the active master' );
assert_same( false, $rows[804]['is_default'] ?? null, 'an orphan template never reports is_default: true' );
assert_same( false, $rows[804]['effective'] ?? null, 'an orphan template is not effective' );

assert_same( 703, $rows[805]['master_id'] ?? null, 'a library-cloned master is still named as the owner' );
assert_same( false, $rows[805]['is_active_master'] ?? null, 'a library-cloned master is excluded from master discovery' );
assert_same( false, $rows[805]['is_default'] ?? null, 'a template under a library-cloned master never reports is_default: true' );
assert_same( false, $rows[805]['effective'] ?? null, 'a template under a library-cloned master is not effective' );

// ── Scenario B: every master trashed ─────────────────────────────────────
//
// Divi resolves nothing at all here. The rows must still come back, so a caller
// can see what exists, but nothing may claim to be live.

$GLOBALS['diviops_test_posts'][701]->post_status = 'trash';
$GLOBALS['diviops_test_posts'][703]->post_status = 'trash';

$orphaned_data = diviops_call( 'tb_template_list', array( new DiviOps_Test_Request( array() ) ) )->get_data()['data'] ?? array();
$orphaned_rows = $orphaned_data['results'] ?? array();

assert_same( 0, $orphaned_data['active_master_id'] ?? null, 'with no active master the response reports active_master_id 0' );
assert_same( 6, count( $orphaned_rows ), 'with no active master every template row is still returned' );

$no_default   = true;
$no_effective = true;
$no_active    = true;
foreach ( $orphaned_rows as $row ) {
	$no_default   = $no_default && ( false === $row['is_default'] );
	$no_effective = $no_effective && ( false === $row['effective'] );
	$no_active    = $no_active && ( false === $row['is_active_master'] );
}
assert_true( $no_default, 'with no active master no row reports is_default: true' );
assert_true( $no_effective, 'with no active master every row reports effective: false' );
assert_true( $no_active, 'with no active master no row reports is_active_master: true' );
assert_same( 700, $orphaned_rows[0]['master_id'] ?? null, 'ownership is still reported when no master is active' );

diviops_tb_forget_fixtures( array( 700, 701, 702, 703, 800, 801, 802, 803, 804, 805 ) );

// ── Scenario C: the single-master happy path ─────────────────────────────
//
// The overwhelmingly common site shape. Nothing about the fix may change it.

diviops_test_register_post( 710, '', 'et_theme_builder', 'Only master' );
diviops_test_register_post( 810, '', 'et_template', 'Only template' );
update_post_meta( 810, '_et_default', '1' );
update_post_meta( 810, '_et_enabled', '1' );
update_post_meta( 810, '_et_header_layout_id', '900335' );
add_post_meta( 710, '_et_template', 810 );

$simple_data = diviops_call( 'tb_template_list', array( new DiviOps_Test_Request( array() ) ) )->get_data()['data'] ?? array();
$simple_rows = $simple_data['results'] ?? array();

assert_same( 710, $simple_data['active_master_id'] ?? null, 'the single master is the active master' );
assert_same( 1, count( $simple_rows ), 'the single template is returned' );
assert_same( 710, $simple_rows[0]['master_id'] ?? null, 'the single template reports its master' );
assert_same( true, $simple_rows[0]['is_active_master'] ?? null, 'the single template is under the active master' );
assert_same( true, $simple_rows[0]['is_default'] ?? null, 'the single default still reports is_default: true' );
assert_same( true, $simple_rows[0]['effective'] ?? null, 'the single default is effective' );
assert_same( 900335, $simple_rows[0]['header_layout_id'] ?? null, 'the pre-existing payload fields are untouched' );
assert_same( true, $simple_rows[0]['enabled'] ?? null, 'the pre-existing enabled flag is untouched' );

diviops_tb_forget_fixtures( array( 710, 810 ) );
