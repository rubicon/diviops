<?php
// SPDX-License-Identifier: MIT
/**
 * Global colour and font writers use Divi's own status vocabulary, per surface (#393).
 *
 * Both writers carried `in_array( $status_raw, [ 'active', 'archived' ], true ) ? $status_raw
 * : 'active'`. That list is wrong twice over, and the ternary made it silent.
 *
 * ## Divi 5.12.0's actual vocabulary, measured from its own source
 *
 * Read from `wp-content/themes/Divi/includes/builder-5/server/Packages/GlobalData/GlobalData.php`
 * on the staging install, not inferred from this plugin's existing list:
 *
 * | Surface | Divi's documented statuses | GlobalData.php |
 * | --- | --- | --- |
 * | Global colours | `active` \| `inactive` \| `temporary` | :119, :339, :381, :472 |
 * | Global fonts | `active` \| `inactive` | :427 |
 * | Global variables (non-colour) | `active` \| `archived` | :883, :1056, :1063, :1070, :1077 |
 *
 * Three vocabularies, one per surface. The old list is the **variable** one, applied to
 * colours and fonts — the shape of a copied list rather than a derived one. `archived` is
 * real, but it belongs to `gvid-*` variables, where `FrontEnd.php:644` gates rendering on it
 * as the soft-delete marker. It has no meaning for a colour or a font.
 *
 * ## Why the coercion mattered more than the list
 *
 * An unrecognised status did not error — the ternary fell through to `'active'`. So asking to
 * deactivate a colour ACTIVATED it, and the call reported success. Measured on the live
 * staging palette before the fix: 57 `active`, **42 `inactive`**, 0 `archived`. Every one of
 * those 42 would flip on any write through this handler, including a write that never
 * mentioned status — because `status` is one of the computed keys that wins over the stored
 * entry in #380's merge.
 *
 * That also meant the tool could not round-trip its own reads: `global_color_list` returns
 * `inactive`, and feeding that entry straight back in changed it.
 *
 * ## What is asserted
 *
 * Storage, not the response. The bug was that the stored value differed from the requested
 * one while the response said success, so a response-shaped assertion is exactly the one that
 * would have stayed green through it.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
// These handlers sit behind Divi's option layer — see the shim's own docblock for why
// et_get_option() is opt-in rather than part of wp-shim.php.
require_once __DIR__ . '/divi-active-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

/** Seed one colour at a given status. */
function diviops_gsv_seed_color( string $status = 'active' ): void {
	et_update_option( 'et_global_data', array(
		'global_colors' => array(
			'gcid-statustest' => array(
				'id'          => 'gcid-statustest',
				'label'       => 'brand',
				'color'       => '#FFFFFF',
				'order'       => '7',
				'status'      => $status,
				'folder'      => '',
				'usedInPosts' => array(),
				'lastUpdated' => '2026-09-01T00:00:00.000Z',
			),
		),
	) );
}

/** Upsert one colour entry and return the response. */
function diviops_gsv_upsert( array $entry ) {
	$req = new WP_REST_Request();
	$req->set_param( 'colors', array( $entry ) );
	$req->set_param( 'mode', 'merge' );
	return diviops_call( 'global_color_upsert', array( $req ) );
}

/** Read the stored colour back through Divi's own accessor. */
function diviops_gsv_stored_status(): ?string {
	$raw = et_get_option( 'et_global_data' );
	return is_array( $raw ) ? ( $raw['global_colors']['gcid-statustest']['status'] ?? null ) : null;
}

/** Create or update a font and return the response. */
function diviops_gsv_font( array $params, string $handler = 'global_font_create' ) {
	$req = new WP_REST_Request();
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return diviops_call( $handler, array( $req ) );
}

// ---------------------------------------------------------------------------
// Colours: Divi's three statuses are accepted and stored verbatim.
// ---------------------------------------------------------------------------

foreach ( array( 'active', 'inactive', 'temporary' ) as $status ) {
	diviops_gsv_seed_color();
	$resp = diviops_gsv_upsert( array( 'id' => 'gcid-statustest', 'status' => $status ) );
	assert_same( true, $resp->get_data()['ok'] ?? null, "a colour status of '{$status}' is accepted" );
	assert_same( $status, diviops_gsv_stored_status(), "and '{$status}' is stored verbatim, not coerced" );
}

// The reported defect, stated as its own assertion because it is the one that
// inverted a caller's intent rather than merely losing a value.
diviops_gsv_seed_color( 'inactive' );
diviops_gsv_upsert( array( 'id' => 'gcid-statustest', 'status' => 'inactive' ) );
assert_same(
	'inactive',
	diviops_gsv_stored_status(),
	'asking to deactivate a colour no longer activates it (#393)'
);

// A write that never mentions status leaves the stored one alone. Before the fix this
// was the silent case: `status` is a computed key that wins over the stored entry in
// #380's merge, so an unrelated colour edit flipped 42 of 99 live colours to active.
diviops_gsv_seed_color( 'inactive' );
diviops_gsv_upsert( array( 'id' => 'gcid-statustest', 'color' => '#EEEEEE' ) );
assert_same(
	'inactive',
	diviops_gsv_stored_status(),
	'a write that omits status preserves the stored status'
);

// The case the `array_key_exists` guard actually exists for, and the one that makes the
// assertion above load-bearing rather than incidental: a stored status this allowlist does
// NOT know. Divi owns this vocabulary and can extend it; a value Divi itself wrote must
// survive a write that never mentioned status, or a future Divi release turns every
// unrelated colour edit into a rejected request.
//
// Without this, a mutation removing the guard is invisible — the previous fixture's stored
// 'inactive' is in the allowlist either way, so validating the default changes nothing.
// That mutation survived the first matrix run for exactly this reason.
diviops_gsv_seed_color( 'some-future-divi-status' );
$resp = diviops_gsv_upsert( array( 'id' => 'gcid-statustest', 'color' => '#DDDDDD' ) );
assert_same( true, $resp->get_data()['ok'] ?? null, 'a write omitting status is not rejected by a status this list has never seen' );
assert_same(
	'some-future-divi-status',
	diviops_gsv_stored_status(),
	'and the unknown stored status survives untouched — Divi owns this vocabulary, not us'
);

// ---------------------------------------------------------------------------
// Colours: anything outside Divi's vocabulary is REFUSED, never coerced.
// Replacing one silent coercion with a different one would fix nothing.
// ---------------------------------------------------------------------------

foreach ( array( 'archived', 'bogus', 'ACTIVE', '' ) as $bad ) {
	diviops_gsv_seed_color( 'inactive' );
	$resp = diviops_gsv_upsert( array( 'id' => 'gcid-statustest', 'status' => $bad ) );
	$data = $resp->get_data();
	$shown = '' === $bad ? '(empty string)' : $bad;
	assert_same( false, $data['ok'] ?? null, "a colour status of '{$shown}' is refused" );
	assert_same( 'invalid_input', $data['error']['code'] ?? null, "and refused as invalid_input, not silently coerced" );
	assert_same( 'inactive', diviops_gsv_stored_status(), "and the stored status is untouched by the refusal" );
}

// `archived` deserves its own note rather than being one item in the loop above: it is a
// REAL Divi status, just not for this surface. Divi uses it for `gvid-*` variables
// (GlobalData.php:883) where FrontEnd.php:644 reads it as the soft-delete marker. Accepting
// it on a colour is what let the wrong vocabulary look plausible for so long.
diviops_gsv_seed_color();
$resp = diviops_gsv_upsert( array( 'id' => 'gcid-statustest', 'status' => 'archived' ) );
assert_same(
	'archived',
	$resp->get_data()['error']['data']['received'] ?? null,
	'the refusal names the value it received, so a caller can see which surface it belongs to'
);

// ---------------------------------------------------------------------------
// The error must identify WHICH entry failed. global_color_upsert takes a batch,
// and a batch rejection that does not say which index is unactionable.
// ---------------------------------------------------------------------------

diviops_gsv_seed_color();
$req = new WP_REST_Request();
$req->set_param( 'colors', array(
	array( 'id' => 'gcid-statustest', 'status' => 'active' ),
	array( 'id' => 'gcid-second', 'color' => '#123456', 'status' => 'nonsense' ),
) );
$req->set_param( 'mode', 'merge' );
$batch = diviops_call( 'global_color_upsert', array( $req ) )->get_data();

assert_same( false, $batch['ok'] ?? null, 'a batch containing one bad status is refused' );
assert_same( 1, $batch['error']['data']['colors_index'] ?? null, 'and names the failing index' );

// ---------------------------------------------------------------------------
// Fonts: Divi documents only active | inactive for this surface (GlobalData.php:427).
// ---------------------------------------------------------------------------

diviops_gsv_font( array( 'id' => 'gfid-statustest', 'family' => 'Inter', 'source' => 'google', 'label' => 'Body' ) );

$resp = diviops_gsv_font( array( 'id' => 'gfid-statustest', 'status' => 'inactive' ), 'global_font_update' );
assert_same( true, $resp->get_data()['ok'] ?? null, 'a font status of inactive is accepted' );
assert_same( 'inactive', $resp->get_data()['data']['font']['status'] ?? null, 'and stored verbatim' );

$resp = diviops_gsv_font( array( 'id' => 'gfid-statustest', 'status' => 'temporary' ), 'global_font_update' );
assert_same(
	false,
	$resp->get_data()['ok'] ?? null,
	'temporary is refused on a font — it is a colour status, and the surfaces differ'
);

$resp = diviops_gsv_font( array( 'id' => 'gfid-statustest', 'status' => 'archived' ), 'global_font_update' );
assert_same( false, $resp->get_data()['ok'] ?? null, 'archived is refused on a font — it belongs to variables' );

$resp = diviops_gsv_font( array( 'id' => 'gfid-statustest', 'status' => 'bogus' ), 'global_font_update' );
assert_same( false, $resp->get_data()['ok'] ?? null, 'and an unrecognised font status is refused, not coerced' );
