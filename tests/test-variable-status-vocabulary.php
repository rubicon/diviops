<?php
// SPDX-License-Identifier: MIT
/**
 * variable_update() validates status per storage bucket, not against a flat union (#406).
 *
 * `variable_update` auto-detects its storage bucket from the id prefix — `gcid-*` are
 * colours in `et_divi.et_global_data.global_colors`, everything else is a `gvid-*` variable
 * in `et_divi_global_variables`. It validated both against one list:
 *
 *     $valid_statuses = [ 'active', 'inactive', 'archived', 'temporary' ];
 *
 * That is the **union** of three different vocabularies, so it accepted every value for every
 * bucket. Divi uses a different vocabulary per surface, read from its own source at
 * `Packages/GlobalData/GlobalData.php` on the staging install:
 *
 * | Surface | Divi's documented statuses | GlobalData.php |
 * | --- | --- | --- |
 * | Global colours (`gcid-*`) | `active` \| `inactive` \| `temporary` | :119, :339, :381, :472 |
 * | Global variables (`gvid-*`) | `active` \| `archived` | :883, :1056-1077 |
 *
 * ## Why over-permissive is not harmless here
 *
 * `archived` is the soft-delete marker Divi's own front end reads for variables — there is no
 * equivalent handling for `inactive` on a `gvid-*`. So `variable_update({ id: 'gvid-…',
 * status: 'inactive' })` succeeded, stored `inactive`, and the variable kept rendering. The
 * caller asked to retire it and nothing happened.
 *
 * This is the quieter half of #393's family: a value the writer accepts that the reader never
 * acts on. #393 was the loud half — a coercion that inverted the caller's intent.
 *
 * The mirror case is `variable_update({ id: 'gcid-…', status: 'archived' })`, which this
 * handler accepted while `global_color_upsert` refuses it after #393. The two colour writers
 * disagreed with each other, which is exactly the drift a shared helper prevents.
 *
 * ## Tightening a writer cannot strand stored data
 *
 * #406 asks for the live counts before choosing, because a read that returns a status the
 * writer would now refuse breaks round-tripping. Measured on staging (Divi 5.12.1) before
 * this change: `et_divi_global_variables` does not exist at all — zero `gvid-*` rows — and of
 * 103 colours, 61 are `active` and 42 `inactive`, with **zero** carrying `archived` or
 * `temporary`. So no stored row on that install becomes unwritable. The
 * "unknown stored status survives" assertion below is what keeps that true in general.
 *
 * ## What is asserted
 *
 * Storage, not the response — same reason as `tests/test-global-status-vocabulary.php`. A
 * response-shaped assertion stays green through a writer that reports success and stores
 * something else, which is the entire defect class.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
// variable_update reaches colours through Divi's option layer (et_get_option) and variables
// through WordPress's (get_option). Both are shimmed; the Divi half is opt-in.
require_once __DIR__ . '/divi-active-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

/** Seed one non-colour variable at a given status. */
function diviops_vsv_seed_variable( string $status = 'active' ): void {
	update_option( 'et_divi_global_variables', array(
		'numbers' => array(
			'gvid-vsv-space' => array(
				'id'          => 'gvid-vsv-space',
				'type'        => 'numbers',
				'label'       => 'Space XL',
				'value'       => '48px',
				'order'       => 4,
				'status'      => $status,
				'lastUpdated' => '2026-09-01T00:00:00.000Z',
			),
		),
	) );
}

/** Seed one colour at a given status. */
function diviops_vsv_seed_color( string $status = 'active' ): void {
	et_update_option( 'et_global_data', array(
		'global_colors' => array(
			'gcid-vsv-brand' => array(
				'id'          => 'gcid-vsv-brand',
				'label'       => 'Brand',
				'color'       => '#101820',
				'order'       => '9',
				'status'      => $status,
				'folder'      => '',
				'usedInPosts' => array(),
				'lastUpdated' => '2026-09-01T00:00:00.000Z',
			),
		),
	) );
}

/** Drive variable_update and return the response. */
function diviops_vsv_update( array $params ) {
	$req = new WP_REST_Request();
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return diviops_call( 'variable_update', array( $req ) );
}

/** Read the stored variable status back out of the registry. */
function diviops_vsv_stored_variable_status(): ?string {
	$registry = get_option( 'et_divi_global_variables', array() );
	return $registry['numbers']['gvid-vsv-space']['status'] ?? null;
}

/** Read the stored colour status back out of Divi's option. */
function diviops_vsv_stored_color_status(): ?string {
	$raw = et_get_option( 'et_global_data' );
	return is_array( $raw ) ? ( $raw['global_colors']['gcid-vsv-brand']['status'] ?? null ) : null;
}

// ---------------------------------------------------------------------------
// The vocabularies themselves, read from the plugin rather than restated here.
// A list written out in this file a third time is how #393 happened.
// ---------------------------------------------------------------------------

$diviops_vsv_var_statuses = diviops_call( 'valid_global_variable_statuses', array() );
assert_same(
	array( 'active', 'archived' ),
	$diviops_vsv_var_statuses,
	"the gvid-* vocabulary is Divi's variable vocabulary (GlobalData.php:883)"
);

// The colour bucket must reuse #393's helper rather than carry a second copy. Asserting the
// two are equal is weaker than asserting they are the SAME source, but a divergence is
// exactly what this catches: if either list is edited alone, this fails.
assert_same(
	diviops_call( 'valid_global_color_statuses', array() ),
	array( 'active', 'inactive', 'temporary' ),
	'the gcid-* vocabulary is the one #393 established for colours'
);

// ---------------------------------------------------------------------------
// gvid-* : active | archived accepted and stored verbatim.
// ---------------------------------------------------------------------------

foreach ( array( 'active', 'archived' ) as $status ) {
	diviops_vsv_seed_variable();
	$resp = diviops_vsv_update( array( 'id' => 'gvid-vsv-space', 'status' => $status ) );
	assert_same( true, $resp->get_data()['ok'] ?? null, "a variable status of '{$status}' is accepted" );
	assert_same( $status, diviops_vsv_stored_variable_status(), "and '{$status}' is stored verbatim" );
}

// ---------------------------------------------------------------------------
// gvid-* : the reported defect. `inactive` and `temporary` are real Divi statuses on OTHER
// surfaces, which is why they looked plausible in the union. Divi's front end never reads
// them for a variable, so storing one is a write that does nothing.
// ---------------------------------------------------------------------------

foreach ( array( 'inactive', 'temporary' ) as $bad ) {
	diviops_vsv_seed_variable( 'active' );
	$resp = diviops_vsv_update( array( 'id' => 'gvid-vsv-space', 'status' => $bad ) );
	$data = $resp->get_data();
	assert_same( false, $data['ok'] ?? null, "a variable status of '{$bad}' is refused, not silently stored (#406)" );
	assert_same( 'invalid_input', $data['error']['code'] ?? null, "and refused as invalid_input" );
	assert_same( 'active', diviops_vsv_stored_variable_status(), "and the stored status is untouched by the refusal" );
	assert_same( $bad, $data['error']['data']['received'] ?? null, "and the refusal names the value it received" );
}

// Non-string statuses, which are what make the `true` strict flag on the in_array load-bearing
// rather than stylistic. A mutation flipping it to loose survived the first matrix run,
// because every fixture above compares one plain string against other plain strings, where
// strict and loose agree.
//
//   - boolean `true` is loosely equal to ANY non-empty string, on every PHP version, so a
//     loose check would accept `status: true` and store the boolean.
//   - `'0'` is the PHP 7.4 case — this plugin's floor. There, `'0' == 'active'` casts the
//     non-numeric string to 0 and is TRUE. PHP 8 compares them as strings and is false, so
//     this fixture only kills the mutant on the 7.4 CI lane. It is kept anyway: the floor is
//     where the behaviour differs, and REST JSON can carry either value.
foreach ( array( true, '0', 0 ) as $diviops_vsv_nonstring ) {
	diviops_vsv_seed_variable( 'active' );
	$resp = diviops_vsv_update( array( 'id' => 'gvid-vsv-space', 'status' => $diviops_vsv_nonstring ) );
	$shown = var_export( $diviops_vsv_nonstring, true );
	assert_same( false, $resp->get_data()['ok'] ?? null, "a status of {$shown} is refused — the allowlist compares strictly" );
	assert_same( 'active', diviops_vsv_stored_variable_status(), "and {$shown} never reaches storage" );
}

// The refusal must advertise the vocabulary for THIS bucket. A caller told the allowed set is
// the four-value union learns nothing about why 'inactive' failed.
diviops_vsv_seed_variable();
$diviops_vsv_refusal = diviops_vsv_update( array( 'id' => 'gvid-vsv-space', 'status' => 'inactive' ) )->get_data();
assert_same(
	array( 'active', 'archived' ),
	$diviops_vsv_refusal['error']['data']['allowed'] ?? null,
	"the refusal advertises the variable vocabulary, not the union of all three surfaces"
);

// ---------------------------------------------------------------------------
// gcid-* : the colour bucket keeps Divi's colour vocabulary, and now agrees with
// global_color_upsert instead of contradicting it.
// ---------------------------------------------------------------------------

foreach ( array( 'active', 'inactive', 'temporary' ) as $status ) {
	diviops_vsv_seed_color();
	$resp = diviops_vsv_update( array( 'id' => 'gcid-vsv-brand', 'status' => $status ) );
	assert_same( true, $resp->get_data()['ok'] ?? null, "a colour status of '{$status}' is accepted by variable_update" );
	assert_same( $status, diviops_vsv_stored_color_status(), "and '{$status}' is stored verbatim" );
}

// The disagreement #406 names: global_color_upsert refuses `archived` on a colour after #393,
// while variable_update accepted it. Two writers to the same storage must not disagree about
// what is legal there.
diviops_vsv_seed_color( 'inactive' );
$diviops_vsv_arch = diviops_vsv_update( array( 'id' => 'gcid-vsv-brand', 'status' => 'archived' ) )->get_data();
assert_same( false, $diviops_vsv_arch['ok'] ?? null, "variable_update refuses 'archived' on a colour, as global_color_upsert does (#406)" );
assert_same( 'inactive', diviops_vsv_stored_color_status(), 'and the stored colour status is untouched' );
assert_same(
	array( 'active', 'inactive', 'temporary' ),
	$diviops_vsv_arch['error']['data']['allowed'] ?? null,
	'and advertises the colour vocabulary for the colour bucket'
);

// ---------------------------------------------------------------------------
// Divi owns these vocabularies and can extend them. A write that never mentions status must
// not re-validate the stored one, or a future Divi release turns every unrelated edit into a
// rejected request. This is the assertion that made #393's guard load-bearing; without a
// fixture whose stored status is OUTSIDE the allowlist, a mutation removing the guard is
// invisible, because validating an already-valid default changes nothing.
// ---------------------------------------------------------------------------

diviops_vsv_seed_variable( 'some-future-divi-status' );
$resp = diviops_vsv_update( array( 'id' => 'gvid-vsv-space', 'label' => 'Renamed' ) );
assert_same( true, $resp->get_data()['ok'] ?? null, 'a write omitting status is not rejected by a stored status this list has never seen' );
assert_same(
	'some-future-divi-status',
	diviops_vsv_stored_variable_status(),
	'and the unknown stored status survives untouched — Divi owns this vocabulary, not us'
);

diviops_vsv_seed_color( 'some-future-divi-status' );
$resp = diviops_vsv_update( array( 'id' => 'gcid-vsv-brand', 'label' => 'Renamed' ) );
assert_same( true, $resp->get_data()['ok'] ?? null, 'the same holds for the colour bucket' );
assert_same( 'some-future-divi-status', diviops_vsv_stored_color_status(), 'and the unknown colour status survives' );

// ---------------------------------------------------------------------------
// The sibling creators (#406 scope item 3). Neither accepts a status at all — both hardcode
// 'active' — so neither can write a wrong one. That is the correct shape, and pinning it here
// is what stops a later "add status support" change from reintroducing the union by copying
// variable_update's old list.
//
// Verified structurally rather than by assertion-on-absence: get_param('status') occurs
// exactly once in trait-variable.php, inside variable_update.
// ---------------------------------------------------------------------------

$diviops_vsv_raw = (string) file_get_contents(
	dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-variable.php'
);
assert_true( strlen( $diviops_vsv_raw ) > 10000, 'trait-variable.php loaded — the positive control for the counts below' );

/**
 * Strip comments so a source gate measures CODE, not prose that quotes it.
 *
 * The first version of the union check below counted raw file text and failed on this very
 * change: the fix's own explanatory comment quotes the old list verbatim to say why it is
 * wrong. A gate that cannot tell a quotation from the thing quoted reports the citation as
 * the defect — the same failure this repository already recorded for a markdown link gate
 * that counted links inside code fences.
 *
 * `token_get_all()` rather than a regex: PHP's own lexer knows where a comment ends, and a
 * regex over PHP source is the class of scanner #97 established will eventually be fooled.
 */
function diviops_vsv_code_only( string $php ): string {
	$out = '';
	foreach ( token_get_all( $php ) as $token ) {
		if ( is_array( $token ) ) {
			if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
				continue;
			}
			$out .= $token[1];
		} else {
			$out .= $token;
		}
	}
	return $out;
}

$diviops_vsv_code = diviops_vsv_code_only( $diviops_vsv_raw );

// Two positive controls, because a stripper that returned '' would make every count below
// zero and the gate would pass while inspecting nothing.
assert_true(
	strlen( $diviops_vsv_code ) > 10000 && false !== strpos( $diviops_vsv_code, 'valid_global_variable_statuses' ),
	'the comment stripper preserved the code — it still contains the helper it must see'
);
assert_true(
	strlen( $diviops_vsv_code ) < strlen( $diviops_vsv_raw ),
	'and it actually removed something, so the strip is real rather than a pass-through'
);

assert_same(
	1,
	substr_count( $diviops_vsv_code, "get_param( 'status' )" ),
	"only one handler in trait-variable.php reads a status param; the creators hardcode 'active'"
);

// And the union list itself must be gone FROM THE CODE. Its presence in an executable
// statement means a bucket is still validated against the wrong vocabulary; its presence in
// a comment is the fix explaining itself.
assert_same(
	0,
	substr_count( $diviops_vsv_code, "'active', 'inactive', 'archived', 'temporary'" ),
	'the flat four-value union is gone from trait-variable.php code (#406)'
);
assert_true(
	false !== strpos( $diviops_vsv_raw, "'active', 'inactive', 'archived', 'temporary'" ),
	'while the comment still cites it — proving the assertion above measures code, not the citation'
);
