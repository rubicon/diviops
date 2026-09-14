<?php
// SPDX-License-Identifier: MIT
/**
 * The three writers into `global_colors` mint NEW-colour shapes Divi cannot tell
 * apart (#438); one of the three is unified onto Divi's full record (#444).
 *
 * `variable_create()` (type=colors), `global_color_upsert()` and
 * `design_system_apply()` all write into `et_divi.et_global_data.global_colors`,
 * and all three now MERGE into an existing record (#380 for the second, #417 for
 * the first) so an EXISTING colour keeps whatever it had. On a create there is
 * nothing to merge, so each handler's key literal is the whole record, and the
 * three literals differ.
 *
 * #438 established that every key one of them omits is defaulted or derived by
 * every read path in Divi, so none of this is a defect. Two of the three shapes
 * are therefore pinned as `DELIBERATE` and left alone: `global_color_upsert()`
 * because its six keys ARE Divi's own PHP writer record, and
 * `design_system_apply()` because it already mints all eight.
 *
 * `variable_create()`'s colour branch was the third, and the owner chose to unify
 * it (#444). It was the only one of the three matching no Divi writer at all. The
 * assertions covering it were deliberately updated rather than deleted, which is
 * what the `DELIBERATE` marker exists for. The decision and its rationale are in
 * `FORK.md`. What this file exists to do is stop any of the three drifting
 * silently, and to carry the citations so nobody has to re-derive them.
 *
 * ## Where the expected values come from
 *
 * Re-measured read-only on staging on 2026-09-13, under Divi **5.13** — the
 * 5.12.1 figure that prompted #438 predates the 2026-09-12 21:24 UTC upgrade, and
 * the signature is unchanged across it. `et_divi.et_global_data.global_colors`
 * holds 103 `gcid-*` records carrying exactly one key signature:
 *
 *     color, folder, id, label, lastUpdated, order, status, usedInPosts
 *
 * The per-handler key lists asserted below are NOT read off this harness. Each is
 * the literal in the handler's own branch body, and the assertion exists so that
 * editing one of those literals fails here rather than landing unnoticed.
 *
 * ## Why a missing key is inert (Divi 5.13)
 *
 * Cited so a later reader can check them rather than trust this docblock.
 *
 * `id` — Divi's own three PHP colour writers mint none:
 * `GlobalData::convert_global_colors_data()` (`GlobalData.php:155-165`),
 * `get_customizer_colors()` (`:402-410`) and `get_imported_global_colors()`
 * (`:500-521`) each write the same six keys `color, folder, label, lastUpdated,
 * status, usedInPosts`. Every reader derives the id from the MAP KEY instead: the
 * colour-to-variable bridge in `visual-builder/build/global-data.js` pushes
 * `{id:t, ...}` where `t` is the key, the colour-picker list does
 * `Object.entries(T).map(([e,t])=>({id:e,...}))`, and the export selector
 * `getGlobalColorsToExport` emits `[key,{color,status,label}]` pairs. The one
 * reader that does touch `record.id` — the AI Agent's value deduplicator `Yj` in
 * `visual-builder/build/ai-agent.js` — backfills it: `return{...a,id:a.id??s}`,
 * where `s` is the map key.
 *
 * `order` — no Divi writer of any kind mints it; the VB's `ADD_GLOBAL_COLOR`
 * reducer writes `{id,color,status,lastUpdated,usedInPosts,label?}`. The bridge
 * reads it as `order:a||l+1`, falling back to the enumeration index.
 *
 * `folder` — read as `getIn(["globalColors",t,"folder"],"")` and passed to
 * `f=e=>{const t={editLabel:!0,editValue:!0,remove:!0,reorder:!0};return"customizer"===e&&(t.editLabel=!1,t.remove=!1,t.reorder=!1),t}`,
 * for which `f(undefined)` and `f('')` return the identical object.
 *
 * `usedInPosts` — read only behind `?.asMutable()??[]`.
 *
 * On the PHP side `GlobalData::sanitize_global_colors_data()` (`:602`) iterates
 * whatever keys are present and gates only on a `gcid-` prefix and a non-empty
 * `color`. The `isset($variable_data['type'],$variable_data['id'])` gate at `:1121`
 * that DOES require an id is the global **variables** path (`gvid-*`), not colours.
 *
 * Confirmed at runtime, read-only, on staging: both handlers' shapes pass Divi's
 * own sanitizer through with every key intact, and the positive control (a record
 * with no `color`, which Divi is documented to drop) was dropped — so the
 * sanitizer was discriminating rather than echoing its input.
 *
 * ## What this file does NOT cover
 *
 * The merge path on an existing colour in general.
 * `tests/test-global-color-upsert-merge.php` covers it for `global_color_upsert()`
 * and `tests/test-variable-create-merge.php` covers it for `variable_create()`.
 * The two upsert assertions here are narrower than that: they exist only to stop
 * the three keys #444 adds from being seeded flat, which would reintroduce #380
 * through the very keys added for parity.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
// Divi-ACTIVE site: all three handlers read through Divi's option layer. Opt-in on
// purpose — see the docblock in divi-active-shim.php for the three test files that
// depend on et_get_option() being ABSENT by default.
require_once __DIR__ . '/divi-active-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

/** The palette as Divi stores it. */
function diviops_gcs_palette(): array {
	$raw = et_get_option( 'et_global_data' );
	return is_array( $raw ) ? ( $raw['global_colors'] ?? array() ) : array();
}

/** Start from an empty palette so every id below is genuinely new. */
function diviops_gcs_reset(): void {
	et_update_option( 'et_global_data', array( 'global_colors' => array() ) );
}

/** Build a request the way the REST layer would. */
function diviops_gcs_request( array $params ): WP_REST_Request {
	$req = new WP_REST_Request();
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return $req;
}

// The eight-key signature every one of the 103 live records carries, sorted. Used
// below to name what each handler omits rather than restating three literals.
$diviops_gcs_live_signature = array(
	'color',
	'folder',
	'id',
	'label',
	'lastUpdated',
	'order',
	'status',
	'usedInPosts',
);

// ---------------------------------------------------------------------------
// Writer 1: variable_create(), type=colors. Five keys.
// ---------------------------------------------------------------------------

diviops_gcs_reset();
$resp = diviops_call( 'variable_create', array( diviops_gcs_request( array(
	'type'  => 'colors',
	'label' => 'Probe',
	'value' => '#3a7a6a',
	'id'    => 'gcid-probe-one',
) ) ) );
assert_same( true, $resp->get_data()['ok'] ?? null, 'variable_create makes a colour' );

$vc = diviops_gcs_palette()['gcid-probe-one'] ?? array();

// Unified onto the eight-key shape (#444). This branch was the only one of the three
// matching no Divi writer at all — Divi's three PHP colour writers all emit `folder`
// and `usedInPosts`, and so does the VB's ADD_GLOBAL_COLOR reducer — so it is the one
// the owner chose to bring into line. #438 established the omission was inert, so this
// is a consistency decision rather than a defect fix, which is why the other two shapes
// below stay pinned exactly as they are. The DELIBERATE assertions this replaces were
// updated rather than deleted, which is what that marker is for.
assert_same(
	array( 'id', 'color', 'status', 'label', 'order', 'lastUpdated', 'folder', 'usedInPosts' ),
	array_keys( $vc ),
	'variable_create mints all eight keys for a new colour, in this order (#444)'
);
assert_same(
	array(),
	array_values( array_diff( $diviops_gcs_live_signature, array_keys( $vc ) ) ),
	'and so omits nothing the 103 live records carry'
);
assert_same( 'gcid-probe-one', $vc['id'] ?? null, 'the minted id is the record\'s own gcid' );
assert_same( '', $vc['folder'] ?? null, 'a new colour is foldered at the root, as every Divi writer seeds it' );
assert_same( array(), $vc['usedInPosts'] ?? null, 'and its usedInPosts index starts empty rather than absent' );

// The three added keys must be MINTED on a create and PRESERVED on an upsert. Seeding
// them flat would reintroduce #380 through the very keys added for parity: `'folder' => ''`
// blanks a Divi-written folder and `'usedInPosts' => []` destroys Divi's reference index.
// The fixture carries non-default values for both, because a fixture holding only the
// empty defaults cannot tell a preserved value from a freshly seeded one.
et_update_option( 'et_global_data', array( 'global_colors' => array(
	'gcid-probe-one' => array(
		'id'          => 'gcid-probe-one',
		'color'       => '#3a7a6a',
		'status'      => 'active',
		'label'       => 'Probe',
		'order'       => '7',
		'lastUpdated' => '2026-09-13T00:00:00.000Z',
		'folder'      => 'brand',
		'usedInPosts' => array( 900390 ),
	),
) ) );
diviops_call( 'variable_create', array( diviops_gcs_request( array(
	'type'  => 'colors',
	'label' => 'Probe renamed',
	'value' => '#ffffff',
	'id'    => 'gcid-probe-one',
) ) ) );
$vc_again = diviops_gcs_palette()['gcid-probe-one'] ?? array();

assert_same( '#ffffff', $vc_again['color'] ?? null, 'an upsert still writes the requested colour' );
assert_same( 'brand', $vc_again['folder'] ?? null, 'and does not blank a folder it did not set (#444)' );
assert_same(
	array( 900390 ),
	$vc_again['usedInPosts'] ?? null,
	"and does not destroy Divi's usedInPosts index (#444)"
);

// `usedInPosts` has to come back an ARRAY, not merely come back. Divi's
// sanitize_global_colors_data() runs `array_map( 'sanitize_text_field', $param_value )`
// over that key with no type check (GlobalData.php:627), so carrying a scalar forward
// would fatal inside Divi rather than here — a failure this plugin's own suite could
// never see. Reading it forward with `?? []` alone is not enough for that reason: the
// guard has to be `is_array`, and this fixture is what makes the difference observable.
et_update_option( 'et_global_data', array( 'global_colors' => array(
	'gcid-probe-scalar' => array(
		'color'       => '#101010',
		'status'      => 'active',
		'label'       => 'Corrupt index',
		'order'       => '9',
		'lastUpdated' => '2026-09-13T00:00:00.000Z',
		'folder'      => '',
		'usedInPosts' => '900390',
	),
) ) );
diviops_call( 'variable_create', array( diviops_gcs_request( array(
	'type'  => 'colors',
	'label' => 'Corrupt index',
	'value' => '#202020',
	'id'    => 'gcid-probe-scalar',
) ) ) );
$vc_scalar = diviops_gcs_palette()['gcid-probe-scalar'] ?? array();

assert_same(
	array(),
	$vc_scalar['usedInPosts'] ?? null,
	'a non-array usedInPosts is replaced with an empty array, never carried forward (#444)'
);

// ---------------------------------------------------------------------------
// Writer 2: global_color_upsert(). Six keys — Divi's own PHP writer shape.
// ---------------------------------------------------------------------------

diviops_gcs_reset();
$resp = diviops_call( 'global_color_upsert', array( diviops_gcs_request( array(
	'colors' => array( array( 'id' => 'gcid-probe-two', 'color' => '#112233', 'label' => 'Two' ) ),
	'mode'   => 'merge',
) ) ) );
assert_same( true, $resp->get_data()['ok'] ?? null, 'global_color_upsert makes a colour' );

$gcu = diviops_gcs_palette()['gcid-probe-two'] ?? array();

// DELIBERATE (#438). These six keys, in this order, are byte-for-byte the record
// Divi's own three PHP colour writers emit — GlobalData.php:155-165, :402-410 and
// :500-521. The handler's own comment claims it "mirrors Divi's canonical global-color
// payload"; this assertion is that claim made checkable.
assert_same(
	array( 'color', 'folder', 'label', 'lastUpdated', 'status', 'usedInPosts' ),
	array_keys( $gcu ),
	"DELIBERATE: global_color_upsert mints Divi's own six-key PHP writer shape"
);
// The brief that opened #438 named only `id`. `order` is missing too: `grep -n "'order'"`
// over trait-global-color.php returns nothing. On an update it survives the array_merge;
// on a create there is nothing to survive.
assert_same(
	array( 'id', 'order' ),
	array_values( array_diff( $diviops_gcs_live_signature, array_keys( $gcu ) ) ),
	'DELIBERATE: and omits id AND order — the two keys no Divi writer mints either'
);

// ---------------------------------------------------------------------------
// Writer 3: design_system_apply(). Eight keys — the complete shape.
// ---------------------------------------------------------------------------

diviops_gcs_reset();
$resp = diviops_call( 'design_system_apply', array( diviops_gcs_request( array(
	'namespace' => 'probe',
	'colors'    => array( array( 'name' => 'three', 'value' => '#445566', 'label' => 'Three' ) ),
) ) ) );
assert_same( true, $resp->get_data()['ok'] ?? null, 'design_system_apply makes a colour' );

$dsa = diviops_gcs_palette()['gcid-probe-three'] ?? array();

assert_same(
	array( 'id', 'color', 'label', 'status', 'order', 'lastUpdated', 'folder', 'usedInPosts' ),
	array_keys( $dsa ),
	'design_system_apply mints all eight keys — folder and usedInPosts appended after the merge'
);
assert_same(
	array(),
	array_values( array_diff( $diviops_gcs_live_signature, array_keys( $dsa ) ) ),
	'and so omits nothing the live palette carries'
);

// ---------------------------------------------------------------------------
// Why the difference is inert, proved against this fork's OWN reader rather than
// asserted from the docblock. variable_list's colour branch derives `id` from the
// map key (trait-variable.php:459), exactly as every Divi reader does.
// ---------------------------------------------------------------------------

diviops_gcs_reset();
// A record with NO `id` at all — what variable_create and global_color_upsert both mint.
et_update_option( 'et_global_data', array( 'global_colors' => array(
	'gcid-keyed-only' => array(
		'color'       => '#aabbcc',
		'status'      => 'active',
		'label'       => 'Keyed only',
		'order'       => '42',
		'lastUpdated' => '2026-09-13T00:00:00.000Z',
	),
) ) );
$listed = diviops_call( 'variable_list', array( diviops_gcs_request( array( 'type' => 'colors' ) ) ) );
$rows   = $listed->get_data()['data']['variables'] ?? array();
assert_same( 1, count( $rows ), 'the id-less record is listed, not skipped' );
assert_same( 'gcid-keyed-only', $rows[0]['id'] ?? null, 'and reports the map key as its id' );
assert_same( '#aabbcc', $rows[0]['value'] ?? null, 'and its colour reads back intact' );

// The sharp version: a record whose own `id` DISAGREES with its map key. If any reader
// preferred the stored field this would report the wrong id, and the inertness argument
// in #438 would be wrong. It reports the key, which is why a missing one costs nothing.
et_update_option( 'et_global_data', array( 'global_colors' => array(
	'gcid-the-key' => array(
		'id'          => 'gcid-a-different-value',
		'color'       => '#ddeeff',
		'status'      => 'active',
		'label'       => 'Disagreeing',
		'order'       => '43',
		'lastUpdated' => '2026-09-13T00:00:00.000Z',
	),
) ) );
$listed2 = diviops_call( 'variable_list', array( diviops_gcs_request( array( 'type' => 'colors' ) ) ) );
$rows2   = $listed2->get_data()['data']['variables'] ?? array();
assert_same( 1, count( $rows2 ), 'the disagreeing record is listed once' );
assert_same(
	'gcid-the-key',
	$rows2[0]['id'] ?? null,
	"the map key wins over the record's own id field — the field is never read"
);
