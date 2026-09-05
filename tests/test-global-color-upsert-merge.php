<?php
// SPDX-License-Identifier: MIT
/**
 * global_color_upsert() must MERGE into the stored entry, not replace it (#380).
 *
 * The handler behind diviops_global_color_create and diviops_global_color_update
 * assigned a fixed six-key literal over `$color_map[ $id ]`. Divi stores more than
 * those six keys — `id` and `order` at minimum, plus whatever a future Divi version
 * adds — so every update silently dropped them. The tool's own description promises
 * "Only provided fields are updated; omitted fields are preserved", which is the
 * contract these assertions pin.
 *
 * Reported from real work on staging (four times out of four) before it was read in
 * source. `order` is the field that bites: Divi renders the palette in `order`, so a
 * colour that loses it sorts unpredictably and the damage is invisible until someone
 * opens the picker.
 *
 * `usedInPosts` was ALREADY preserved by hand before this fix — a partial merge
 * covering exactly one unknown key. That is why the bug survived: the code looks like
 * it preserves, and does, for the single field someone thought about.
 */

require_once __DIR__ . '/wp-shim.php';
// This handler lives behind Divi's option layer, so the harness has to present a
// Divi-ACTIVE site. That is opt-in on purpose — see the docblock in the shim for the
// three test files that depend on et_get_option() being ABSENT by default.
require_once __DIR__ . '/divi-active-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

/**
 * Seed et_global_data with one colour carrying the full Divi-native key set.
 *
 * `customField` stands in for any key a future Divi release adds that this fork has
 * never heard of. A merge that only enumerates known keys would still drop it, so the
 * assertions below are about the merge being total, not about a list of fields we
 * happened to think of.
 */
function diviops_t380_seed( array $overrides = array() ): void {
	$entry = array_merge(
		array(
			'id'          => 'gcid-xebc8wg4kb',
			'label'       => 'white',
			'color'       => '#FFFFFF',
			'order'       => '24',
			'status'      => 'active',
			'folder'      => '',
			'usedInPosts' => array( 900390 ),
			'lastUpdated' => '2026-09-02T23:45:29.000Z',
			'customField' => 'divi-may-add-this',
		),
		$overrides
	);
	// Seed through Divi's own accessor so the test exercises the real storage path
	// (et_divi['et_global_data']), not a flat option the handler never reads.
	et_update_option( 'et_global_data', array( 'global_colors' => array( 'gcid-xebc8wg4kb' => $entry ) ) );
}

function diviops_t380_stored( string $id = 'gcid-xebc8wg4kb' ): array {
	$raw  = et_get_option( 'et_global_data' );
	$data = is_array( $raw ) ? $raw : array();
	return $data['global_colors'][ $id ] ?? array();
}

/** Drive the real handler through a request, as the REST layer would. */
function diviops_t380_upsert( array $colors ) {
	$req = new WP_REST_Request();
	$req->set_param( 'colors', $colors );
	$req->set_param( 'mode', 'merge' );
	return diviops_call( 'global_color_upsert', array( $req ) );
}

// ---------------------------------------------------------------------------
// The defect itself.
// ---------------------------------------------------------------------------

diviops_t380_seed();
diviops_t380_upsert( array( array( 'id' => 'gcid-xebc8wg4kb', 'color' => '#FAFAFA' ) ) );
$after = diviops_t380_stored();

assert_same( '#FAFAFA', $after['color'] ?? null, 'the requested field is written' );
assert_same( 'gcid-xebc8wg4kb', $after['id'] ?? null, 'id survives the write (#380)' );
assert_same( '24', $after['order'] ?? null, 'order survives the write (#380)' );
assert_same(
	'divi-may-add-this',
	$after['customField'] ?? null,
	'a key this fork does not know about survives — the merge is total, not an enumeration'
);

// ---------------------------------------------------------------------------
// Omitted fields keep their stored values rather than reverting to defaults.
// ---------------------------------------------------------------------------

diviops_t380_seed( array( 'label' => 'Brand White', 'folder' => 'brand' ) );
diviops_t380_upsert( array( array( 'id' => 'gcid-xebc8wg4kb', 'color' => '#EEEEEE' ) ) );
$omitted = diviops_t380_stored();

assert_same( 'Brand White', $omitted['label'] ?? null, 'an omitted label is not blanked' );
assert_same( 'brand', $omitted['folder'] ?? null, 'an omitted folder is not blanked' );
assert_same( array( 900390 ), $omitted['usedInPosts'] ?? null, 'usedInPosts is still preserved' );

// ---------------------------------------------------------------------------
// The write must still WRITE. A merge that silently no-opped would satisfy every
// preservation assertion above while breaking the tool entirely.
// ---------------------------------------------------------------------------

diviops_t380_seed();
diviops_t380_upsert( array( array(
	'id'     => 'gcid-xebc8wg4kb',
	'color'  => '#000000',
	'label'  => 'black now',
	'status' => 'archived',
) ) );
$written = diviops_t380_stored();

assert_same( '#000000', $written['color'] ?? null, 'a provided color overwrites the stored one' );
assert_same( 'black now', $written['label'] ?? null, 'a provided label overwrites the stored one' );
assert_same( 'archived', $written['status'] ?? null, 'a provided status overwrites the stored one' );
assert_same( '24', $written['order'] ?? null, 'order is preserved alongside a real update' );

// DEFECT (#393, not fixed here): the status allowlist is [active, archived], but the
// live palette uses `inactive` on 42 of 99 colours and `archived` on none. An
// unrecognised status is silently coerced to 'active' rather than refused, so asking
// to deactivate a colour ACTIVATES it. This assertion pins the current wrong
// behaviour so the eventual fix reads as an intentional change, not a regression.
diviops_t380_seed( array( 'status' => 'inactive' ) );
diviops_t380_upsert( array( array( 'id' => 'gcid-xebc8wg4kb', 'status' => 'inactive' ) ) );
assert_same(
	'active',
	diviops_t380_stored()['status'] ?? null,
	'DEFECT #393: a valid Divi status of "inactive" is silently coerced to "active"'
);

// lastUpdated is a stamp, not a merged field — it must move on every write.
diviops_t380_seed( array( 'lastUpdated' => '2020-01-01T00:00:00.000Z' ) );
diviops_t380_upsert( array( array( 'id' => 'gcid-xebc8wg4kb', 'color' => '#123456' ) ) );
$stamped = diviops_t380_stored();

assert_true(
	isset( $stamped['lastUpdated'] ) && '2020-01-01T00:00:00.000Z' !== $stamped['lastUpdated'],
	'lastUpdated is restamped on write, not merged through from the stored entry'
);

// ---------------------------------------------------------------------------
// A new colour inherits nothing, and writing it leaves its siblings intact.
// ---------------------------------------------------------------------------

diviops_t380_seed();
diviops_t380_upsert( array( array( 'id' => 'gcid-brandnew', 'color' => '#ABCDEF', 'label' => 'New' ) ) );
$fresh = diviops_t380_stored( 'gcid-brandnew' );

assert_same( '#ABCDEF', $fresh['color'] ?? null, 'a brand-new colour is written' );
assert_true( ! isset( $fresh['customField'] ), 'a new entry inherits nothing from its siblings' );
assert_same( '24', diviops_t380_stored()['order'] ?? null, 'the untouched sibling is undamaged' );
