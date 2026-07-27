<?php
/**
 * Menu CRUD — menu_delete / menu_item_remove / menu_item_reorder /
 * menu_location_unassign (#30).
 *
 * trait-menu.php shipped create/get/list/item-add-page/item-add-custom/
 * location-assign but no removal, reorder, or location-unassign path. These four
 * handlers close that gap, mirroring the existing menu patterns (menu_can_manage
 * gate, menu_object_by_id not-found, inline dry-run plans, menu_readback success).
 *
 * These tests drive the real handlers against the harness's nav-menu registry and
 * the WP-core primitives it shims — wp_get_nav_menu_object / wp_get_nav_menu_items /
 * wp_delete_nav_menu / wp_update_post / wp_delete_post / get_nav_menu_locations /
 * set_theme_mod / get_registered_nav_menus — the same category of documented-contract
 * stub the suite already relies on for get_post. Assertions are on the HANDLER's
 * envelope and the resulting registry state, never on a shim's internals.
 *
 * current_user_can is a fixed-true stub, so every handler's menu_can_manage
 * forbidden branch is inspected-only here (as the library_delete suite notes for
 * its own delete_post branch); everything else — the not-found guards, the
 * item-belongs-to-menu guard, reorder set validation, the dry-run plans, the real
 * mutations and their registry effects, and location no-op idempotency — runs
 * against real plugin code.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

function diviops_menu_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

/**
 * Reset every nav-menu-related registry slot so a section starts from a known
 * empty world regardless of what earlier sections left behind.
 */
function diviops_menu_reset() {
	$GLOBALS['diviops_test_nav_menus']            = array();
	$GLOBALS['diviops_test_nav_menu_items']       = array();
	$GLOBALS['diviops_test_theme_mods']           = array();
	$GLOBALS['diviops_test_registered_nav_menus'] = array();
	$GLOBALS['diviops_test_post_meta']            = array();
}

// ═══ menu_delete ═══════════════════════════════════════════════════════════

diviops_menu_reset();
diviops_test_register_nav_menu( 10, 'Primary' );
diviops_test_register_nav_menu( 20, 'Footer' );
diviops_test_register_nav_menu_item( array( 'ID' => 101, 'menu_id' => 10, 'title' => 'Home', 'menu_order' => 1 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 102, 'menu_id' => 10, 'title' => 'About', 'menu_order' => 2 ) );
set_theme_mod( 'nav_menu_locations', array( 'header' => 10, 'footer' => 20 ) );

// (1) A missing menu is a not_found, not a fatal.
$resp = diviops_call( 'menu_delete', array( diviops_menu_request( array( 'id' => 999 ) ) ) );
$data = $resp->get_data();
assert_true( false === $data['ok'], 'deleting a missing menu returns an error envelope' );
assert_same( 'not_found', $data['error']['code'], 'a missing menu is not_found' );
assert_same( 404, $resp->get_status(), 'menu not_found carries HTTP 404' );

// (2) dry_run plans the deletion and mutates nothing.
$resp = diviops_call( 'menu_delete', array( diviops_menu_request( array( 'id' => 10, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_true( true === $data['data']['dry_run'], 'menu_delete dry_run carries the dry_run flag' );
assert_same( 'menu.delete', $data['data']['plan']['changes'][0]['kind'], 'dry_run plan kind is menu.delete' );
assert_same( 'menu:10', $data['data']['plan']['changes'][0]['target'], 'dry_run plan target is menu:{id}' );
assert_same( 'Primary', $data['data']['plan']['changes'][0]['before'], 'dry_run plan before is the menu name' );
assert_same( 'deleted', $data['data']['plan']['changes'][0]['after'], 'dry_run plan after is deleted' );
assert_true( isset( $GLOBALS['diviops_test_nav_menus'][10] ), 'dry_run left the menu in place' );
assert_true( isset( $GLOBALS['diviops_test_nav_menu_items'][101] ), 'dry_run left the menu items in place' );

// (3) Real delete removes the menu and its items and reports the freed location.
$resp = diviops_call( 'menu_delete', array( diviops_menu_request( array( 'id' => 10 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a real menu_delete succeeds' );
assert_same( 10, $data['data']['id'], 'the response echoes the deleted menu id' );
assert_same( 'Primary', $data['data']['name'], 'the response echoes the deleted menu name' );
assert_true( true === $data['data']['deleted'], 'the response reports deleted:true' );
assert_same( array( 'header' ), $data['data']['freed_locations'], 'the location assigned to this menu is reported freed' );
assert_true( ! isset( $GLOBALS['diviops_test_nav_menus'][10] ), 'the menu term is gone from the registry' );
assert_true( ! isset( $GLOBALS['diviops_test_nav_menu_items'][101] ), 'the menu items are gone from the registry' );
assert_true( ! isset( $GLOBALS['diviops_test_nav_menu_items'][102] ), 'every menu item is gone, not just the first' );
$locations = get_nav_menu_locations();
assert_true( ! isset( $locations['header'] ), 'the freed location no longer points at the deleted menu' );
assert_same( 20, (int) $locations['footer'], 'an unrelated location assignment is untouched' );

// ═══ menu_item_remove ══════════════════════════════════════════════════════

diviops_menu_reset();

// Menu 30 — dry-run + guards fixture (left intact by those cases).
//   301(0) ─ 302(301) ─ 303(302)
//          └ 304(301)
//   305(0)
diviops_test_register_nav_menu( 30, 'Tree30' );
diviops_test_register_nav_menu_item( array( 'ID' => 301, 'menu_id' => 30, 'title' => 'Top A', 'menu_order' => 1, 'parent' => 0 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 302, 'menu_id' => 30, 'title' => 'Child A1', 'menu_order' => 2, 'parent' => 301 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 303, 'menu_id' => 30, 'title' => 'Grandchild A1a', 'menu_order' => 3, 'parent' => 302 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 304, 'menu_id' => 30, 'title' => 'Child A2', 'menu_order' => 4, 'parent' => 301 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 305, 'menu_id' => 30, 'title' => 'Top B', 'menu_order' => 5, 'parent' => 0 ) );

// Menu 40 — a foreign menu, to prove an item can only be removed via its own menu.
diviops_test_register_nav_menu( 40, 'Other40' );
diviops_test_register_nav_menu_item( array( 'ID' => 401, 'menu_id' => 40, 'title' => 'Foreign', 'menu_order' => 1, 'parent' => 0 ) );

// (4) Missing menu → not_found.
$resp = diviops_call( 'menu_item_remove', array( diviops_menu_request( array( 'menu_id' => 999, 'item_id' => 301 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'removing from a missing menu is not_found' );
assert_same( 404, $resp->get_status(), 'menu-not-found on item remove is HTTP 404' );

// (5) Item id that exists in NO menu → not_found, field item_id.
$resp = diviops_call( 'menu_item_remove', array( diviops_menu_request( array( 'menu_id' => 30, 'item_id' => 9999 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'a non-existent item is not_found' );
assert_same( 'item_id', $data['error']['data']['field'], 'the offending field is item_id' );

// (6) Item that exists but belongs to another menu → not_found (belongs check).
$resp = diviops_call( 'menu_item_remove', array( diviops_menu_request( array( 'menu_id' => 30, 'item_id' => 401 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'an item from a different menu is not_found for this menu' );
assert_true( isset( $GLOBALS['diviops_test_nav_menu_items'][401] ), 'the foreign item is left untouched' );

// (7) dry_run default: plan to remove 1 item, mutate nothing.
$resp = diviops_call( 'menu_item_remove', array( diviops_menu_request( array( 'menu_id' => 30, 'item_id' => 302, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_true( true === $data['data']['dry_run'], 'item_remove dry_run carries the flag' );
assert_same( 'menu.item.remove', $data['data']['plan']['changes'][0]['kind'], 'plan kind is menu.item.remove' );
assert_same( 'menu_item:302', $data['data']['plan']['changes'][0]['target'], 'plan target is menu_item:{item_id}' );
assert_same( 1, $data['data']['plan']['changes'][0]['before']['removed_count'], 'default dry_run plans a single removal' );
assert_true( isset( $GLOBALS['diviops_test_nav_menu_items'][302] ), 'dry_run did not remove the item' );

// (8) dry_run cascade: plan counts the target plus all descendants (301 → 302,303,304 = 4).
$resp = diviops_call( 'menu_item_remove', array( diviops_menu_request( array( 'menu_id' => 30, 'item_id' => 301, 'cascade' => true, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_same( 4, $data['data']['plan']['changes'][0]['before']['removed_count'], 'cascade dry_run counts target + 3 descendants' );
assert_true( true === $data['data']['plan']['changes'][0]['before']['cascade'], 'cascade flag is echoed in the plan' );
assert_true( isset( $GLOBALS['diviops_test_nav_menu_items'][303] ), 'cascade dry_run removed nothing' );

// (9) Real default remove of a middle node re-parents its one child to the node's own parent.
//     Remove 302 (parent 301, child 303) → 303 re-parents to 301, 302 deleted.
$resp = diviops_call( 'menu_item_remove', array( diviops_menu_request( array( 'menu_id' => 30, 'item_id' => 302 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a default item remove succeeds' );
assert_same( array( 302 ), $data['data']['removed_item_ids'], 'only the target item is reported removed' );
assert_same( array( 303 ), $data['data']['reparented_child_ids'], 'the direct child is reported re-parented' );
assert_true( ! isset( $GLOBALS['diviops_test_nav_menu_items'][302] ), 'the target item is gone from the registry' );
assert_true( isset( $GLOBALS['diviops_test_nav_menu_items'][303] ), 'the re-parented child survives' );
assert_same( 301, (int) get_post_meta( 303, '_menu_item_menu_item_parent', true ), 'the child now points at the removed node parent' );

// Menu 31 — real default remove where the removed node has TWO direct children,
// both re-parented up to the removed node's parent (grandparent, here 0).
//   311(0) ─ 312(311)
//          └ 313(311) ─ 314(313)
diviops_test_register_nav_menu( 31, 'Tree31' );
diviops_test_register_nav_menu_item( array( 'ID' => 311, 'menu_id' => 31, 'title' => 'P', 'menu_order' => 1, 'parent' => 0 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 312, 'menu_id' => 31, 'title' => 'C1', 'menu_order' => 2, 'parent' => 311 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 313, 'menu_id' => 31, 'title' => 'C2', 'menu_order' => 3, 'parent' => 311 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 314, 'menu_id' => 31, 'title' => 'GC', 'menu_order' => 4, 'parent' => 313 ) );

$resp = diviops_call( 'menu_item_remove', array( diviops_menu_request( array( 'menu_id' => 31, 'item_id' => 311 ) ) ) );
$data = $resp->get_data();
assert_same( array( 312, 313 ), $data['data']['reparented_child_ids'], 'both direct children are re-parented' );
assert_same( 0, (int) get_post_meta( 312, '_menu_item_menu_item_parent', true ), 'first direct child re-parents to grandparent 0' );
assert_same( 0, (int) get_post_meta( 313, '_menu_item_menu_item_parent', true ), 'second direct child re-parents to grandparent 0' );
assert_same( 313, (int) get_post_meta( 314, '_menu_item_menu_item_parent', true ), 'a non-direct descendant keeps its own parent' );

// (10) Default remove of a leaf re-parents nothing.
$resp = diviops_call( 'menu_item_remove', array( diviops_menu_request( array( 'menu_id' => 30, 'item_id' => 305 ) ) ) );
$data = $resp->get_data();
assert_same( array( 305 ), $data['data']['removed_item_ids'], 'the leaf itself is removed' );
assert_same( array(), $data['data']['reparented_child_ids'], 'a leaf remove re-parents nothing' );

// Menu 32 — cascade removes the whole subtree, leaving unrelated siblings alone.
//   501(0) ─ 502(501) ─ 503(502)
//          └ 504(501)
//   505(0)
diviops_test_register_nav_menu( 32, 'Tree32' );
diviops_test_register_nav_menu_item( array( 'ID' => 501, 'menu_id' => 32, 'title' => 'Root', 'menu_order' => 1, 'parent' => 0 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 502, 'menu_id' => 32, 'title' => 'A', 'menu_order' => 2, 'parent' => 501 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 503, 'menu_id' => 32, 'title' => 'AA', 'menu_order' => 3, 'parent' => 502 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 504, 'menu_id' => 32, 'title' => 'B', 'menu_order' => 4, 'parent' => 501 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 505, 'menu_id' => 32, 'title' => 'Sibling', 'menu_order' => 5, 'parent' => 0 ) );

$resp    = diviops_call( 'menu_item_remove', array( diviops_menu_request( array( 'menu_id' => 32, 'item_id' => 501, 'cascade' => true ) ) ) );
$data    = $resp->get_data();
$removed = $data['data']['removed_item_ids'];
sort( $removed );
assert_same( array( 501, 502, 503, 504 ), $removed, 'cascade removes the target and every descendant' );
assert_same( array(), $data['data']['reparented_child_ids'], 'cascade re-parents nothing' );
assert_true( ! isset( $GLOBALS['diviops_test_nav_menu_items'][503] ), 'a deep descendant is actually deleted under cascade' );
assert_true( isset( $GLOBALS['diviops_test_nav_menu_items'][505] ), 'an unrelated sibling survives cascade' );

// ═══ menu_item_reorder ═════════════════════════════════════════════════════

diviops_menu_reset();
// Menu 60 — three top-level items plus one child, to exercise level scoping.
diviops_test_register_nav_menu( 60, 'Order60' );
diviops_test_register_nav_menu_item( array( 'ID' => 601, 'menu_id' => 60, 'title' => 'One', 'menu_order' => 1, 'parent' => 0 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 602, 'menu_id' => 60, 'title' => 'Two', 'menu_order' => 2, 'parent' => 0 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 603, 'menu_id' => 60, 'title' => 'Three', 'menu_order' => 3, 'parent' => 0 ) );
diviops_test_register_nav_menu_item( array( 'ID' => 604, 'menu_id' => 60, 'title' => 'Child', 'menu_order' => 1, 'parent' => 601 ) );

// (11) Missing menu → not_found.
$resp = diviops_call( 'menu_item_reorder', array( diviops_menu_request( array( 'menu_id' => 999, 'order' => array( 601 ) ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'reordering a missing menu is not_found' );

// (12) Empty order → invalid_input.
$resp = diviops_call( 'menu_item_reorder', array( diviops_menu_request( array( 'menu_id' => 60, 'order' => array(), 'parent' => 0 ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an empty order is invalid_input' );
assert_same( 'order', $data['error']['data']['field'], 'the offending field is order' );

// (13) Order referencing an item NOT at this level → invalid_input (604 is under 601).
$resp = diviops_call( 'menu_item_reorder', array( diviops_menu_request( array( 'menu_id' => 60, 'order' => array( 601, 602, 604 ), 'parent' => 0 ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an id from another level is invalid_input' );
assert_same( array( 601, 602, 603 ), $data['error']['data']['expected'], 'the error advertises the expected level id set' );

// (14) Order that omits a level member → invalid_input.
$resp = diviops_call( 'menu_item_reorder', array( diviops_menu_request( array( 'menu_id' => 60, 'order' => array( 601, 602 ), 'parent' => 0 ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'omitting a level member is invalid_input' );

// (15) Order with a duplicate → invalid_input.
$resp = diviops_call( 'menu_item_reorder', array( diviops_menu_request( array( 'menu_id' => 60, 'order' => array( 601, 601, 602 ), 'parent' => 0 ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a duplicate id is invalid_input' );

// (16) dry_run: before is the current level order, after is the request; no mutation.
$resp = diviops_call( 'menu_item_reorder', array( diviops_menu_request( array( 'menu_id' => 60, 'order' => array( 603, 601, 602 ), 'parent' => 0, 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_same( 'menu.item.reorder', $data['data']['plan']['changes'][0]['kind'], 'dry_run plan kind is menu.item.reorder' );
assert_same( 'menu:60', $data['data']['plan']['changes'][0]['target'], 'dry_run plan target is menu:{menu_id}' );
assert_same( array( 601, 602, 603 ), $data['data']['plan']['changes'][0]['before'], 'dry_run before is the current level order' );
assert_same( array( 603, 601, 602 ), $data['data']['plan']['changes'][0]['after'], 'dry_run after is the requested order' );
assert_same( 1, (int) $GLOBALS['diviops_test_nav_menu_items'][601]->menu_order, 'dry_run did not renumber any item' );

// (17) Real reorder assigns menu_order 1..N in the requested sequence.
$resp = diviops_call( 'menu_item_reorder', array( diviops_menu_request( array( 'menu_id' => 60, 'order' => array( 603, 601, 602 ), 'parent' => 0 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a real reorder succeeds' );
assert_same( 1, (int) $GLOBALS['diviops_test_nav_menu_items'][603]->menu_order, 'first requested item gets menu_order 1' );
assert_same( 2, (int) $GLOBALS['diviops_test_nav_menu_items'][601]->menu_order, 'second requested item gets menu_order 2' );
assert_same( 3, (int) $GLOBALS['diviops_test_nav_menu_items'][602]->menu_order, 'third requested item gets menu_order 3' );
$top_ids = array();
foreach ( $data['data']['items'] as $row ) {
	if ( 0 === (int) $row['parent'] ) {
		$top_ids[] = (int) $row['id'];
	}
}
assert_same( array( 603, 601, 602 ), $top_ids, 'the readback lists the top level in the new order' );
assert_same( 1, (int) $GLOBALS['diviops_test_nav_menu_items'][604]->menu_order, 'a child at another level is left untouched' );

// ═══ menu_location_unassign ════════════════════════════════════════════════

diviops_menu_reset();
$GLOBALS['diviops_test_registered_nav_menus'] = array( 'header' => 'Header', 'footer' => 'Footer' );
diviops_test_register_nav_menu( 70, 'Primary70' );
set_theme_mod( 'nav_menu_locations', array( 'header' => 70 ) );

// (18) An unregistered location → invalid_input.
$resp = diviops_call( 'menu_location_unassign', array( diviops_menu_request( array( 'location' => 'sidebar' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an unregistered location is invalid_input' );
assert_same( 'location', $data['error']['data']['field'], 'the offending field is location' );
assert_same( 400, $resp->get_status(), 'unassign invalid_input carries HTTP 400' );

// (19) A registered-but-unassigned location → idempotent no-op.
$resp = diviops_call( 'menu_location_unassign', array( diviops_menu_request( array( 'location' => 'footer' ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'unassigning an unassigned location is a success, not an error' );
assert_true( true === $data['data']['noop'], 'the no-op signal is set' );
assert_same( 'location_not_assigned', $data['data']['reason'], 'the no-op reason is location_not_assigned' );

// (20) dry_run: before is the current menu id, after is 0; no mutation.
$resp = diviops_call( 'menu_location_unassign', array( diviops_menu_request( array( 'location' => 'header', 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_same( 'menu.location.unassign', $data['data']['plan']['changes'][0]['kind'], 'dry_run plan kind is menu.location.unassign' );
assert_same( 'location:header', $data['data']['plan']['changes'][0]['target'], 'dry_run plan target is location:{location}' );
assert_same( 70, $data['data']['plan']['changes'][0]['before'], 'dry_run before is the currently assigned menu id' );
assert_same( 0, $data['data']['plan']['changes'][0]['after'], 'dry_run after is 0 (unassigned)' );
assert_same( 70, (int) get_nav_menu_locations()['header'], 'dry_run left the assignment in place' );

// (21) Real unassign clears the location.
$resp = diviops_call( 'menu_location_unassign', array( diviops_menu_request( array( 'location' => 'header' ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a real unassign succeeds' );
assert_same( 'header', $data['data']['location'], 'the response echoes the location' );
assert_true( ! isset( $data['data']['assigned']['header'] ), 'the location is absent from the returned assignment map' );
assert_true( ! isset( get_nav_menu_locations()['header'] ), 'the location is cleared in the theme mod' );

// ═══ structural regressions: route wiring + capability keys ════════════════

$plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );

$route_specs = array(
	'menu_delete'             => "\\/menu\\/delete[^']*",
	'menu_item_remove'        => "\\/menu\\/item\\/remove",
	'menu_item_reorder'       => "\\/menu\\/item\\/reorder",
	'menu_location_unassign'  => "\\/menu\\/location\\/unassign",
);
foreach ( $route_specs as $handler => $path ) {
	assert_true(
		1 === preg_match(
			"/register_rest_route\\(\\s*self::REST_NAMESPACE,\\s*'{$path}',\\s*\\[\\s*'methods'\\s*=>\\s*'POST',\\s*'callback'\\s*=>\\s*\\[\\s*__CLASS__,\\s*'{$handler}'\\s*\\]/s",
			$plugin_src
		),
		"a POST route dispatches to {$handler}"
	);
	assert_true(
		1 === preg_match( "/'{$handler}'/", $plugin_src ),
		"the '{$handler}' capability key is present in CAPABILITIES"
	);
	assert_true(
		method_exists( 'DiviOps_Agent', $handler ),
		"DiviOps_Agent::{$handler} exists once the trait is mixed in"
	);
}
