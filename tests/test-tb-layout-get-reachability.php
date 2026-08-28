<?php
// SPDX-License-Identifier: MIT
/**
 * tb_layout_get reachability reporting (#302).
 *
 * A Theme Builder layout routes only when a template under the ACTIVE master
 * names it in one of its `_et_{header,body,footer}_layout_id` meta rows AND the
 * matching `_et_*_layout_enabled` flag is set. Divi records that link on the
 * template side only, so a layout carries no back-pointer and "what uses this
 * layout" means walking the templates.
 *
 * tb_layout_get used to return any layout post by id with nothing but its
 * content: no master, no referrers, and no `post_status` filter either, so a
 * drafted or trashed layout came back as `ok: true` with full markup and no
 * indication it renders nowhere. #291/#293 fixed the sibling read surface
 * (tb_template_list); this is the one that was left.
 *
 * These tests pin the accepted fix: nothing that resolves today is refused, but
 * the payload states which template(s) name the layout, which master owns it,
 * and whether it is reachable.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Call tb_layout_get for one layout id and return its `data` payload.
 *
 * @param int $layout_id Layout post id.
 * @return array
 */
function diviops_tb_layout_payload( int $layout_id ): array {
	$response = diviops_call( 'tb_layout_get', array( new DiviOps_Test_Request( array( 'id' => $layout_id ) ) ) );
	return $response->get_data()['data'] ?? array();
}

/**
 * Drop fixture posts and their meta from the process-wide registries so one
 * scenario cannot leak into the next.
 *
 * @param int[] $ids Fixture post ids.
 */
function diviops_tb_layout_forget( array $ids ): void {
	foreach ( $ids as $id ) {
		unset(
			$GLOBALS['diviops_test_posts'][ $id ],
			$GLOBALS['diviops_test_post_meta'][ $id ],
			$GLOBALS['diviops_test_post_meta_rows'][ $id ]
		);
	}
}

// ── Scenario A: a live layout, a superseded one, and an unreferenced one ──
//
// 720 is the active master, 721 a trashed (superseded) one. Templates 820/821
// live under them. Layouts: 920 is live, 921 is under the superseded master,
// 922 is named by nobody, 923 is live but with its slot disabled.

diviops_test_register_post( 720, '', 'et_theme_builder', 'Active master' );
diviops_test_register_post( 721, '', 'et_theme_builder', 'Superseded master' );
$GLOBALS['diviops_test_posts'][721]->post_status = 'trash';

diviops_test_register_post( 820, '', 'et_template', 'Live template' );
update_post_meta( 820, '_et_enabled', '1' );
update_post_meta( 820, '_et_header_layout_id', '920' );
update_post_meta( 820, '_et_header_layout_enabled', '1' );
update_post_meta( 820, '_et_footer_layout_id', '923' );
// 923's slot is deliberately NOT enabled.
add_post_meta( 720, '_et_template', 820 );

diviops_test_register_post( 821, '', 'et_template', 'Superseded template' );
update_post_meta( 821, '_et_enabled', '1' );
update_post_meta( 821, '_et_header_layout_id', '921' );
update_post_meta( 821, '_et_header_layout_enabled', '1' );
add_post_meta( 721, '_et_template', 821 );

foreach ( array( 920, 921, 922, 923 ) as $layout_id ) {
	diviops_test_register_post( $layout_id, '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->', 'et_header_layout', 'Layout ' . $layout_id );
}
$GLOBALS['diviops_test_posts'][923]->post_type = 'et_footer_layout';

$live = diviops_tb_layout_payload( 920 );
assert_same( 920, $live['id'] ?? null, 'the live layout is still returned' );
assert_same( 'publish', $live['post_status'] ?? null, 'the payload reports post_status' );
assert_same( 720, $live['master_id'] ?? null, 'the live layout reports the active master' );
assert_same( true, $live['is_active_master'] ?? null, 'the live layout is under the active master' );
assert_same( array( 820 ), $live['referenced_by'] ?? null, 'the live layout names the template that references it' );
assert_same( true, $live['effective'] ?? null, 'a referenced, enabled layout under the active master is effective' );
assert_true( '' !== ( $live['content_raw'] ?? '' ), 'the pre-existing content_raw field is untouched' );

$superseded = diviops_tb_layout_payload( 921 );
assert_same( 721, $superseded['master_id'] ?? null, 'a layout under a superseded master reports that master' );
assert_same( false, $superseded['is_active_master'] ?? null, 'a superseded master is not the active master' );
assert_same( array( 821 ), $superseded['referenced_by'] ?? null, 'a superseded layout still names its referrer' );
assert_same( false, $superseded['effective'] ?? null, 'a layout under a superseded master is not effective' );

$unreferenced = diviops_tb_layout_payload( 922 );
assert_same( 0, $unreferenced['master_id'] ?? null, 'a layout no template names reports master_id 0' );
assert_same( array(), $unreferenced['referenced_by'] ?? null, 'a layout no template names reports an empty referenced_by' );
assert_same( false, $unreferenced['is_active_master'] ?? null, 'an unreferenced layout is not under the active master' );
assert_same( false, $unreferenced['effective'] ?? null, 'an unreferenced layout is not effective' );

$slot_disabled = diviops_tb_layout_payload( 923 );
assert_same( 720, $slot_disabled['master_id'] ?? null, 'a disabled-slot layout still reports its master' );
assert_same( true, $slot_disabled['is_active_master'] ?? null, 'a disabled-slot layout is still under the active master' );
assert_same( false, $slot_disabled['effective'] ?? null, 'a layout whose slot flag is off is not effective' );

// ── Scenario B: a drafted layout is served, and says so ──────────────────
//
// get_post() is status-agnostic, so this returned ok:true with full markup and
// no hint the layout was drafted. Returning it stays correct — inspecting or
// salvaging a detached layout is legitimate — but the status must be legible.

$GLOBALS['diviops_test_posts'][922]->post_status = 'draft';
$drafted = diviops_tb_layout_payload( 922 );
assert_same( 922, $drafted['id'] ?? null, 'a drafted layout is still returned, not refused' );
assert_same( 'draft', $drafted['post_status'] ?? null, 'a drafted layout reports its status' );
assert_same( false, $drafted['effective'] ?? null, 'a drafted layout is not effective' );

// ── Scenario C: several templates name the same layout ───────────────────

diviops_test_register_post( 822, '', 'et_template', 'Second referrer' );
update_post_meta( 822, '_et_enabled', '1' );
update_post_meta( 822, '_et_header_layout_id', '920' );
update_post_meta( 822, '_et_header_layout_enabled', '1' );
add_post_meta( 720, '_et_template', 822 );

$shared = diviops_tb_layout_payload( 920 );
assert_same( array( 820, 822 ), $shared['referenced_by'] ?? null, 'every template naming the layout is listed' );
assert_same( true, $shared['effective'] ?? null, 'a layout shared by two live templates is still effective' );

// ── Scenario D: no active master at all ──────────────────────────────────

$GLOBALS['diviops_test_posts'][720]->post_status = 'trash';

$no_master = diviops_tb_layout_payload( 920 );
assert_same( 720, $no_master['master_id'] ?? null, 'ownership is still reported when no master is active' );
assert_same( false, $no_master['is_active_master'] ?? null, 'with every master trashed nothing is under the active master' );
assert_same( false, $no_master['effective'] ?? null, 'with no active master no layout is effective' );
assert_same( array( 820, 822 ), $no_master['referenced_by'] ?? null, 'referrers are still reported with no active master' );

diviops_tb_layout_forget( array( 720, 721, 820, 821, 822, 920, 921, 922, 923 ) );
