<?php
// SPDX-License-Identifier: MIT
/**
 * variable_create() must MERGE into the stored record, and must stamp Divi's
 * canonical `variableType` on every non-colour variable it makes (#417).
 *
 * Same defect class as #380, one storage over: the handler assigned a fixed key
 * literal over `$vars[ $type ][ $id ]` (non-colour) and over `$colors[ $id ]`
 * (colour), so an upsert onto an id the Visual Builder made destroyed every key
 * the literal did not enumerate. Reproduced through this harness before it was
 * fixed: a `numbers` record seeded with `variableType` came back without it, and
 * a colour seeded with `id` / `folder` / `usedInPosts` came back without any of
 * the three.
 *
 * ## Where the expected values come from
 *
 * Measured on staging (Divi 5.12.1) before anything was designed, read-only:
 *
 * - `et_divi_global_variables` holds 164 `gvid-*` records — 143 `numbers`,
 *   18 `images`, 3 `gradients`. 53 carry `variableType` (32/18/3 by bucket) and
 *   its value is always the bucket name. 9 carry this fork's own `type` key and
 *   no `variableType`; the two sets do not overlap, which is the fingerprint of
 *   the two writers.
 * - `et_divi.et_global_data.global_colors` holds 103 `gcid-*` records. All 103
 *   carry `id`, `folder` and `usedInPosts`; **none** carries `variableType`.
 *   That asymmetry is why this file asserts `variableType` on the non-colour
 *   branch and asserts its ABSENCE on the colour branch — writing it there would
 *   invent a key Divi's colour storage has never used.
 *
 * Source for the value itself, not read off this harness: Divi 5.12.1's Visual
 * Builder reducer in `includes/builder-5/visual-builder/build/global-data.js`
 * writes `variableType: n` onto every global variable it stores, where `n` is
 * the bucket key. Two consumers read it back — `module-utils.js` gates image
 * inlining on `"images" === e.variableType`, and `ai-agent.js` reports
 * `variableType: r.variableType ?? null` in its variable metadata. Divi's PHP
 * neither reads nor writes it: zero hits for `variableType` across all 1,637
 * PHP files of Divi 5.12.1, with `global_variables` as the positive control
 * returning hits in `core/components/Portability.php`. So it is a
 * Visual-Builder-owned field that this fork must preserve and mint, not one the
 * server layer will backfill.
 *
 * ## What is deliberately NOT covered
 *
 * The `order` recomputation on an upsert is pinned as a marked DEFECT rather
 * than fixed: it is the same damage #380 described (a variable that loses its
 * place sorts to the end of the palette), but it is a different key from #417's
 * subject and fixing it needs its own issue. The colour branch's failure to mint
 * `id` / `folder` / `usedInPosts` on a genuinely new colour is likewise recorded
 * and not fixed — `global_color_upsert` does not mint them either, so changing
 * it here would put the two writers out of step.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
// The colour branch reads and writes through Divi's option layer, so the harness
// has to present a Divi-ACTIVE site. Opt-in on purpose — see the shim's docblock
// for the three test files that depend on et_get_option() being ABSENT.
require_once __DIR__ . '/divi-active-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

/**
 * Seed the non-colour registry with one Visual-Builder-shaped record.
 *
 * The key set is transcribed from a live staging record
 * (`gvid-39xbek1wyj`, bucket `numbers`) plus `customField`, which stands in for
 * any key a future Divi release adds that this fork has never heard of. A merge
 * that only enumerates known keys would still drop that one, so these assertions
 * are about the merge being total rather than about a list of fields someone
 * happened to think of.
 */
function diviops_t417_seed_vars( array $overrides = array(), string $bucket = 'numbers', string $id = 'gvid-t417' ): void {
	$entry = array_merge(
		array(
			'id'           => $id,
			'label'        => 'Content Wrap Wide',
			'value'        => 'clamp(320px, 75vw, 1200px)',
			'order'        => '7',
			'status'       => 'active',
			'lastUpdated'  => '2020-01-01T00:00:00.000Z',
			'variableType' => $bucket,
			'customField'  => 'divi-may-add-this',
		),
		$overrides
	);
	update_option( 'et_divi_global_variables', array( $bucket => array( $id => $entry ) ) );
}

/** Read one stored non-colour record straight out of the Divi-owned option. */
function diviops_t417_stored_var( string $id = 'gvid-t417', string $bucket = 'numbers' ): array {
	$registry = get_option( 'et_divi_global_variables', array() );
	return ( is_array( $registry ) && isset( $registry[ $bucket ][ $id ] ) ) ? $registry[ $bucket ][ $id ] : array();
}

/**
 * Seed the colour registry with one Visual-Builder-shaped colour.
 *
 * Key set transcribed from a live staging record (`gcid-z8ojhqb5h8`); all 103
 * colours on that site carry exactly these eight keys.
 */
function diviops_t417_seed_color( array $overrides = array() ): void {
	$entry = array_merge(
		array(
			'id'          => 'gcid-t417',
			'lastUpdated' => '2020-01-01T00:00:00.000Z',
			'label'       => 'white',
			'color'       => '#FFFFFF',
			'order'       => '24',
			'status'      => 'active',
			'folder'      => 'brand',
			'usedInPosts' => array( 900390 ),
		),
		$overrides
	);
	et_update_option( 'et_global_data', array( 'global_colors' => array( 'gcid-t417' => $entry ) ) );
}

/** Read one stored colour straight out of Divi's one-row theme option. */
function diviops_t417_stored_color( string $id = 'gcid-t417' ): array {
	$raw  = maybe_unserialize( et_get_option( 'et_global_data' ) );
	$data = is_array( $raw ) ? $raw : array();
	return $data['global_colors'][ $id ] ?? array();
}

/** Drive the real handler through a request, as the REST layer would. */
function diviops_t417_create( array $params ) {
	$req = new WP_REST_Request();
	foreach ( $params as $key => $value ) {
		$req->set_param( $key, $value );
	}
	return diviops_call( 'variable_create', array( $req ) );
}

// ---------------------------------------------------------------------------
// The defect itself, non-colour branch: an upsert onto a VB-made id.
// ---------------------------------------------------------------------------

diviops_t417_seed_vars();
diviops_t417_create( array(
	'type'  => 'numbers',
	'id'    => 'gvid-t417',
	'label' => 'Content Wrap Narrow',
	'value' => 'clamp(320px, 60vw, 900px)',
) );
$after = diviops_t417_stored_var();

assert_same(
	'numbers',
	$after['variableType'] ?? null,
	'variableType survives an upsert onto a Visual-Builder-made variable (#417)'
);
assert_same(
	'divi-may-add-this',
	$after['customField'] ?? null,
	'a key this fork does not know about survives — the merge is total, not an enumeration'
);

// The write must still WRITE. A merge that silently no-opped would satisfy every
// preservation assertion above while breaking the tool entirely.
assert_same( 'Content Wrap Narrow', $after['label'] ?? null, 'a provided label overwrites the stored one' );
assert_same( 'clamp(320px, 60vw, 900px)', $after['value'] ?? null, 'a provided value overwrites the stored one' );
assert_same( 'numbers', $after['type'] ?? null, "this fork's own type key is still written" );
assert_true(
	isset( $after['lastUpdated'] ) && '2020-01-01T00:00:00.000Z' !== $after['lastUpdated'],
	'lastUpdated is restamped on write, not merged through from the stored entry'
);

// DEFECT, pinned as-is and deliberately NOT fixed under #417. `order` is in the
// write payload, so an upsert recomputes it as max(order)+1 and moves an existing
// variable to the end of the bucket — the same damage #380 described for colours.
// The seed stores order "7" as its own max, so the recomputed value is 8. A later
// issue that preserves order on an upsert must update this line on purpose.
assert_same( 8, $after['order'] ?? null, 'DEFECT: an upsert recomputes order and moves the variable to the end' );

// ---------------------------------------------------------------------------
// A brand-new non-colour variable is stamped with Divi's canonical variableType.
// ---------------------------------------------------------------------------

diviops_t417_seed_vars();
diviops_t417_create( array(
	'type'  => 'numbers',
	'id'    => 'gvid-t417-new',
	'label' => 'Gutter',
	'value' => '24px',
) );
$fresh = diviops_t417_stored_var( 'gvid-t417-new' );

assert_same(
	'numbers',
	$fresh['variableType'] ?? null,
	'a newly created numbers variable carries variableType, as the VB reducer writes it (#417)'
);
assert_same( '24px', $fresh['value'] ?? null, 'the new variable is written' );
assert_true(
	! isset( $fresh['customField'] ),
	'a new entry inherits nothing from its siblings'
);
assert_same(
	'divi-may-add-this',
	diviops_t417_stored_var()['customField'] ?? null,
	'the untouched sibling is undamaged'
);

// variableType is the BUCKET, not a constant. `images` is the bucket Divi itself
// reads the field back for — module-utils.js gates image inlining on
// `"images" === e.variableType` — so a handler that hardcoded one bucket name
// would break exactly the path Divi uses it on.
diviops_t417_seed_vars( array(), 'images', 'gvid-t417-img' );
diviops_t417_create( array(
	'type'  => 'images',
	'id'    => 'gvid-t417-img2',
	'label' => 'Logo',
	'value' => 'https://example.com/logo.webp',
) );
assert_same(
	'images',
	diviops_t417_stored_var( 'gvid-t417-img2', 'images' )['variableType'] ?? null,
	'variableType is the bucket name, not a hardcoded one — an images variable reports images'
);

// ---------------------------------------------------------------------------
// variable_create_fluid_system writes into the identical `numbers` bucket, and
// carried the identical defect. Fixing only the handler the issue named would
// have left the bulk generator — the tool that writes the most records — still
// stripping variableType on every overwrite=true run.
// ---------------------------------------------------------------------------

diviops_t417_seed_vars( array(), 'numbers', 'gvid-t417-space-1' );
$fluid_req = array(
	'namespace' => 't417',
	// min/max/steps, not base_px: the handler rejects a spacing category without
	// both bounds before it reaches any write.
	'spacing'   => array( 'min_px' => 8, 'max_px' => 32, 'steps' => 2 ),
	'overwrite' => true,
);
$req = new WP_REST_Request();
foreach ( $fluid_req as $key => $value ) {
	$req->set_param( $key, $value );
}
diviops_call( 'variable_create_fluid_system', array( $req ) );

$fluid_overwritten = diviops_t417_stored_var( 'gvid-t417-space-1' );
assert_same(
	'numbers',
	$fluid_overwritten['variableType'] ?? null,
	'variableType survives a fluid-system overwrite onto a Visual-Builder-made variable (#417)'
);
assert_same(
	'divi-may-add-this',
	$fluid_overwritten['customField'] ?? null,
	'a fluid-system overwrite is a total merge too, not an enumeration'
);
assert_same( '8px', $fluid_overwritten['value'] ?? null, 'the fluid-system overwrite still writes the computed value' );
// Unlike variable_create, this handler already preserved `order` on an
// overwrite — the branch above it reads the stored order instead of minting one.
// Int, not the seeded string "7": that branch is `(int) ( $existing_entry['order']
// ?? ++$max_order )`, so the cast is the handler's, not this harness's.
assert_same( 7, $fluid_overwritten['order'] ?? null, 'a fluid-system overwrite keeps the stored order' );

assert_same(
	'numbers',
	diviops_t417_stored_var( 'gvid-t417-space-2' )['variableType'] ?? null,
	'a fluid-system variable created fresh also carries variableType (#417)'
);

// ---------------------------------------------------------------------------
// Colour branch: the same replace-instead-of-merge, over a different storage.
// ---------------------------------------------------------------------------

diviops_t417_seed_color();
diviops_t417_create( array(
	'type'  => 'colors',
	'id'    => 'gcid-t417',
	'label' => 'Off White',
	'value' => '#FAFAFA',
) );
$color_after = diviops_t417_stored_color();

assert_same( 'gcid-t417', $color_after['id'] ?? null, 'a colour keeps its stored id through an upsert (#417)' );
assert_same( 'brand', $color_after['folder'] ?? null, 'a colour keeps its stored folder through an upsert (#417)' );
assert_same( array( 900390 ), $color_after['usedInPosts'] ?? null, 'a colour keeps its usedInPosts through an upsert (#417)' );
assert_same( '#FAFAFA', $color_after['color'] ?? null, 'a provided colour value overwrites the stored one' );
assert_same( 'Off White', $color_after['label'] ?? null, 'a provided colour label overwrites the stored one' );

// DEFECT, same shape as the numbers case above and equally out of #417's scope.
// get_customizer_color_count() floors the order at Divi's customizer-bound count,
// so the recomputed value is max( customizer_count, 24 ) + 1 = 25.
assert_same( '25', $color_after['order'] ?? null, 'DEFECT: an upsert recomputes a colour order too' );

// A brand-new colour must NOT be given variableType: zero of the 103 live
// `gcid-*` records on staging carry it, and Divi's colour storage is a different
// map from the `gvid-*` registry the VB reducer stamps.
diviops_t417_seed_color();
diviops_t417_create( array(
	'type'  => 'colors',
	'id'    => 'gcid-t417-new',
	'label' => 'Ink',
	'value' => '#0D2240',
) );
$fresh_color = diviops_t417_stored_color( 'gcid-t417-new' );

assert_same( '#0D2240', $fresh_color['color'] ?? null, 'a brand-new colour is written' );
assert_true(
	! array_key_exists( 'variableType', $fresh_color ),
	'a new colour is not given variableType — no live gcid-* record carries it'
);
assert_true(
	! isset( $fresh_color['usedInPosts'] ),
	'a new colour inherits nothing from its siblings'
);
assert_same(
	array( 900390 ),
	diviops_t417_stored_color()['usedInPosts'] ?? null,
	'the untouched colour sibling is undamaged'
);
