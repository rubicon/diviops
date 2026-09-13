<?php
// SPDX-License-Identifier: MIT
/**
 * The `gvid-` id charset every variable mint and accept point must hold to (#443).
 *
 * The variable domain minted and accepted ids over a wider charset than Divi can
 * resolve, so a variable was written to the registry, reported success, and then
 * silently failed to render. Nothing errored anywhere in the chain. The colour and
 * font siblings already guard this — `validate_global_color_id()`
 * (trait-global-color.php) and `validate_global_font_id()` (trait-global-font.php)
 * both enforce `/^[0-9a-z-]{1,80}$/` on the suffix — and the variable side had no
 * equivalent. This file is the gate for the one it now has.
 *
 * ── Where the charset comes from ─────────────────────────────────────────
 *
 * Measured against Divi 5.13 on the staging host named by CLAUDE.md, at
 * `wp-content/themes/Divi/includes/builder-5/server/`. The same three sites were
 * measured on 5.12.1 and are unchanged across that upgrade.
 *
 *   - `GlobalData::resolve_global_variable_value()` extracts with
 *     `/--(gvid-[a-z0-9\-]+)/i` — Packages/GlobalData/GlobalData.php:1281.
 *   - `GradientUtils` carries the identical pattern —
 *     Packages/StyleLibrary/Utils/GradientUtils.php:756.
 *   - `DetectFeature::_get_global_ids_by_prefix()` builds
 *     `'(' . preg_quote( $prefix, '~' ) . '[0-9a-z-]*)'` —
 *     FrontEnd/Assets/DetectFeature.php:138, reached for `gvid-` via
 *     `get_global_variable_ids()` at :420, which scopes the per-page
 *     `:root{--gvid-*}` emission.
 *
 * Both have to succeed for a variable to render: DetectFeature decides whether the
 * custom property is emitted on the page at all, GlobalData resolves it. Neither
 * charset contains `_`, and DetectFeature's carries no `/i` flag, so it is
 * case-sensitive where the other two are not. That asymmetry is why an uppercase id
 * is a real failure and not merely untidy — it resolves at one site and truncates at
 * the other.
 *
 * The 80-character ceiling is not Divi's. It is the sibling writers' own sane upper
 * bound, adopted here so two writers to one registry agree on what a legal id is —
 * the reasoning `design_system_apply()` already records at trait-design-system.php.
 *
 * ── What this file covers ────────────────────────────────────────────────
 *
 * The four places the variable domain produces or accepts a `gvid-` id:
 *
 *   1. `validate_name_prefix()`, which gates `name_prefix` and `namespace`.
 *   2. `variable_create`'s caller-supplied `id`.
 *   3. `variable_create`'s auto-generated `id`.
 *   4. `variable_create_fluid_system`'s minted plan ids, where the length ceiling
 *      is reached by concatenation and no check on the parts alone can see it.
 *
 * ── What it does not cover, and why it is not faked ──────────────────────
 *
 * The `colors` bucket is out of scope here in both senses: `gcid-` ids are the
 * sibling's contract, already covered by tests/test-design-system-apply.php, and the
 * colours branch of `variable_create` reads through `et_get_option()`, which
 * tests/test-variable-characterization.php documents as deliberately undefined in
 * this harness. Nothing below touches it.
 *
 * Divi's own extraction is not re-implemented here. These assertions pin what this
 * plugin refuses to write; that the refused shapes are the ones Divi drops is
 * established by reading Divi's source, cited above, not by modelling it in a stub.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/variable-characterization-stubs.php';

/**
 * Reset the registry and post fixtures between groups.
 *
 * Mirrors tests/test-variable-characterization.php's own reset rather than reusing
 * it: helpers are process-global once declared, but file load order is the runner's
 * to decide, so a suite that reached for another file's helper would pass or fatal
 * depending on the glob.
 */
function diviops_vic_reset() {
	$GLOBALS['diviops_test_posts']     = array();
	$GLOBALS['diviops_test_post_meta'] = array();
	$GLOBALS['diviops_test_options']   = array(
		'et_divi_global_variables'          => array(),
		'et_divi_builder_global_presets_d5' => array( 'module' => array() ),
	);
}

/**
 * `validate_name_prefix()`'s accepted value, or the text it refused with.
 *
 * Total, so a change that turns an accepted prefix into a refusal reports as a named
 * failure rather than an uncaught exception killing the run.
 *
 * @param mixed  $input   Caller-supplied prefix.
 * @param string $field   Field name carried into the message.
 * @param string $default Fallback for null/empty.
 * @return string
 */
function diviops_vic_prefix( $input, string $field, string $default ): string {
	try {
		return (string) diviops_call( 'validate_name_prefix', array( $input, $field, $default ) );
	} catch ( Throwable $e ) {
		return 'refused: ' . $e->getMessage();
	}
}

/**
 * `validate_global_variable_id()`'s canonical id, or the error code it refused with.
 *
 * @param mixed $raw Candidate id.
 * @return string
 */
function diviops_vic_id( $raw ): string {
	$result = diviops_call( 'validate_global_variable_id', array( $raw ) );
	if ( is_wp_error( $result ) ) {
		return 'refused: ' . $result->get_error_code();
	}
	return (string) $result;
}

/**
 * Build a request for the variable handlers.
 *
 * @param array $params Request parameters.
 * @return DiviOps_Test_Request
 */
function diviops_vic_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

/**
 * The stored non-colour registry.
 *
 * @return array
 */
function diviops_vic_registry(): array {
	return (array) ( $GLOBALS['diviops_test_options']['et_divi_global_variables'] ?? array() );
}

// ── validate_name_prefix: the charset, and what it now refuses ────────────

diviops_vic_reset();

assert_same( 'h', diviops_vic_prefix( null, 'typography.name_prefix', 'h' ), 'a null prefix still falls back to the default' );
assert_same( 'h', diviops_vic_prefix( '', 'typography.name_prefix', 'h' ), 'an empty prefix still falls back to the default' );
assert_same( 'hd', diviops_vic_prefix( 'HD', 'typography.name_prefix', 'h' ), 'a prefix is lowercased, because DetectFeature.php:138 matches lowercase only' );
assert_same( 'a-b-c9', diviops_vic_prefix( 'a-b-c9', 'x', 'h' ), 'hyphens and digits are inside the accepted charset' );

// The change this file exists for. `my_brand` minted `gvid-my_brand-space-1`, which
// both extractors cut at the underscore, leaving `gvid-my` — an id that matches no
// registry record, so the custom property is never emitted and the variable never
// renders. Refusing mirrors validate_global_color_id(); normalising `_` to `-` was
// considered and rejected, because a silently rewritten namespace aliases one token
// set onto another, and under overwrite=true rewrites tokens the caller never named.
assert_same(
	"refused: namespace 'my_brand' contains characters outside [a-z0-9-]. Divi's \$variable() resolver strips disallowed chars silently, so the generated IDs would be created in the registry but fail to resolve at render time. Use only [a-z0-9-].",
	diviops_vic_prefix( 'my_brand', 'namespace', 'oa' ),
	'an underscore is refused, and the refusal names the field, the value and the charset'
);
assert_same(
	"refused: typography.name_prefix 'has space' contains characters outside [a-z0-9-]. Divi's \$variable() resolver strips disallowed chars silently, so the generated IDs would be created in the registry but fail to resolve at render time. Use only [a-z0-9-].",
	diviops_vic_prefix( 'has space', 'typography.name_prefix', 'h' ),
	'a space is refused by the same branch, so the charset message covers both'
);

$diviops_vic_err = null;
try {
	diviops_call( 'validate_name_prefix', array( 'my_brand', 'namespace', 'oa' ) );
} catch ( Throwable $e ) {
	$diviops_vic_err = $e;
}
assert_true( $diviops_vic_err instanceof DiviOps_Variable_Input_Exception, 'the underscore refusal is an input-shape rejection, not a generic exception' );

// ── validate_global_variable_id: the shared contract ──────────────────────

assert_same( 'gvid-brand-space-1', diviops_vic_id( 'gvid-brand-space-1' ), 'a lowercase hyphenated id is returned unchanged' );
assert_same( 'gvid-0', diviops_vic_id( 'gvid-0' ), 'a one-character suffix is legal — the ceiling is a maximum, not a minimum' );

assert_same( 'refused: invalid_id', diviops_vic_id( 'gvid-my_brand-x' ), 'an underscore is outside the id charset' );
// Uppercase resolves at GlobalData.php:1281, which carries /i, and truncates at
// DetectFeature.php:138, which does not. Passing one of the two is not rendering.
assert_same( 'refused: invalid_id', diviops_vic_id( 'gvid-aB3xY9Zq' ), 'uppercase is refused, because the page-scan extractor is case-sensitive' );
assert_same( 'refused: invalid_id', diviops_vic_id( 'gvid-1al.0625remzskv' ), 'a dot is refused — this exact id reached a live registry through the unguarded path' );
assert_same( 'refused: invalid_id', diviops_vic_id( 'gvid-' ), 'an empty suffix is refused' );
assert_same( 'refused: invalid_id', diviops_vic_id( 'brand-space-1' ), 'an id without the gvid- prefix is refused' );
assert_same( 'refused: invalid_id', diviops_vic_id( 'gcid-brand-primary' ), 'a colour id is refused by the variable validator' );

// The 80-char suffix ceiling, taken from validate_global_color_id()'s own
// [0-9a-z-]{1,80}. Both sides of the boundary, so an off-by-one in either
// direction is a named failure.
assert_same( 'gvid-' . str_repeat( 'a', 80 ), diviops_vic_id( 'gvid-' . str_repeat( 'a', 80 ) ), 'an 80-character suffix is accepted' );
assert_same( 'refused: invalid_id', diviops_vic_id( 'gvid-' . str_repeat( 'a', 81 ) ), 'an 81-character suffix is refused' );

// ── variable_create: the caller-supplied id ───────────────────────────────

diviops_vic_reset();

$diviops_vic_resp = diviops_call( 'variable_create', array( diviops_vic_request( array(
	'type'  => 'numbers',
	'label' => 'Underscored',
	'value' => '10px',
	'id'    => 'gvid-my_brand-x',
) ) ) );
$diviops_vic_data = $diviops_vic_resp->get_data();
assert_same( 'invalid_input', $diviops_vic_data['error']['code'] ?? null, 'an underscored caller-supplied id is refused' );
assert_same( 'id', $diviops_vic_data['error']['data']['field'] ?? null, 'the id refusal names the field' );
assert_same( 'gvid-my_brand-x', $diviops_vic_data['error']['data']['received'] ?? null, 'the id refusal echoes what was received' );
assert_same( array(), diviops_vic_registry(), 'and nothing is written' );

$diviops_vic_resp = diviops_call( 'variable_create', array( diviops_vic_request( array(
	'type'  => 'numbers',
	'label' => 'Shouty',
	'value' => '10px',
	'id'    => 'gvid-MyBrand',
) ) ) );
assert_same( 'invalid_input', $diviops_vic_resp->get_data()['error']['code'] ?? null, 'an uppercase caller-supplied id is refused' );

$diviops_vic_resp = diviops_call( 'variable_create', array( diviops_vic_request( array(
	'type'  => 'numbers',
	'label' => 'Long',
	'value' => '10px',
	'id'    => 'gvid-' . str_repeat( 'a', 81 ),
) ) ) );
assert_same( 'invalid_input', $diviops_vic_resp->get_data()['error']['code'] ?? null, 'an over-long caller-supplied id is refused' );
assert_same( array(), diviops_vic_registry(), 'and none of the three refused ids reached storage' );

// The pre-existing prefix rejection is a distinct, more specific message and keeps
// its own branch — the charset check runs after it, not instead of it.
$diviops_vic_resp = diviops_call( 'variable_create', array( diviops_vic_request( array(
	'type'  => 'numbers',
	'label' => 'Unprefixed',
	'value' => '10px',
	'id'    => 'brand-x',
) ) ) );
assert_same(
	"Non-color variable ID must start with 'gvid-', got 'brand-x'.",
	$diviops_vic_resp->get_data()['error']['message'] ?? null,
	'a missing prefix keeps its own message rather than being absorbed into the charset one'
);

// A legal id still writes, so the guard refuses the unresolvable rather than
// narrowing what works.
diviops_vic_reset();
$diviops_vic_resp = diviops_call( 'variable_create', array( diviops_vic_request( array(
	'type'  => 'numbers',
	'label' => 'Fine',
	'value' => '10px',
	'id'    => 'gvid-brand-space-1',
) ) ) );
assert_same( true, $diviops_vic_resp->get_data()['ok'] ?? null, 'a legal caller-supplied id is written' );
assert_true( isset( diviops_vic_registry()['numbers']['gvid-brand-space-1'] ), 'and it lands under the id the caller asked for' );

// ── variable_create: the dry-run placeholder is not a hole ────────────────
//
// A dry run with no `id` mints the literal placeholder `gvid-<auto>`, which is not
// a legal id and is never stored, so it has to be exempt from the charset check.
// The exemption is keyed on the handler having minted the id itself, not on
// recognising that string: `sanitize_text_field()` is what would strip the angle
// brackets off a caller who sent it, and the harness's own model of that primitive
// does not strip tags. Keying on the value would hand the caller the exemption.

diviops_vic_reset();

$diviops_vic_resp = diviops_call( 'variable_create', array( diviops_vic_request( array(
	'type'    => 'numbers',
	'label'   => 'Planned',
	'value'   => '10px',
	'dry_run' => true,
) ) ) );
assert_same( true, $diviops_vic_resp->get_data()['ok'] ?? null, 'a dry run with no id still plans' );
assert_same( array(), diviops_vic_registry(), 'and a dry run writes nothing' );

$diviops_vic_resp = diviops_call( 'variable_create', array( diviops_vic_request( array(
	'type'    => 'numbers',
	'label'   => 'Crafted',
	'value'   => '10px',
	'id'      => 'gvid-<auto>',
	'dry_run' => true,
) ) ) );
assert_same( 'invalid_input', $diviops_vic_resp->get_data()['error']['code'] ?? null, 'a caller sending the placeholder string verbatim does not inherit its exemption' );

// A dry run is a rehearsal, so it must refuse what the real write would refuse —
// otherwise the plan reports an id the caller cannot actually have.
$diviops_vic_resp = diviops_call( 'variable_create', array( diviops_vic_request( array(
	'type'    => 'numbers',
	'label'   => 'Planned badly',
	'value'   => '10px',
	'id'      => 'gvid-my_brand-x',
	'dry_run' => true,
) ) ) );
assert_same( 'invalid_input', $diviops_vic_resp->get_data()['error']['code'] ?? null, 'a dry run refuses an illegal caller-supplied id rather than planning it' );

// ── variable_create: the auto-generated id ────────────────────────────────
//
// wp_generate_password( 8, false ) draws from core's
// 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
// (wp-includes/pluggable.php:2965) — $special_chars=false suppresses the
// punctuation set, not the uppercase half. So the default path, taken whenever a
// caller omits `id`, minted an uppercase id roughly always.
//
// One draw would be a coin flip, so this asserts over a run of them. The chance of
// all 40 landing lowercase by luck is (36/62)^320, which is not a number this
// harness will ever see; a single surviving uppercase char fails the batch and the
// message names the offender.
diviops_vic_reset();

$diviops_vic_minted = array();
for ( $diviops_vic_n = 0; $diviops_vic_n < 40; $diviops_vic_n++ ) {
	$diviops_vic_resp = diviops_call( 'variable_create', array( diviops_vic_request( array(
		'type'  => 'numbers',
		'label' => 'Auto ' . $diviops_vic_n,
		'value' => '10px',
	) ) ) );
	$diviops_vic_body = $diviops_vic_resp->get_data();
	$diviops_vic_minted[] = (string) ( $diviops_vic_body['data']['id'] ?? $diviops_vic_body['error']['code'] ?? 'missing' );
}

assert_same( 40, count( $diviops_vic_minted ), 'the auto-id batch actually ran forty creates rather than short-circuiting' );

$diviops_vic_illegal = array_values( array_filter(
	$diviops_vic_minted,
	function ( $id ) {
		return 1 !== preg_match( '/^gvid-[0-9a-z-]{1,80}$/', $id );
	}
) );
assert_same( array(), $diviops_vic_illegal, 'every auto-generated id matches the charset Divi extracts' );

// ── variable_create_fluid_system: the minted plan ids ─────────────────────

diviops_vic_reset();

$diviops_vic_resp = diviops_call( 'variable_create_fluid_system', array( diviops_vic_request( array(
	'namespace'  => 'my_brand',
	'typography' => array( 'base_px' => 16, 'steps' => 3 ),
) ) ) );
$diviops_vic_data = $diviops_vic_resp->get_data();
assert_same( 'invalid_input', $diviops_vic_data['error']['code'] ?? null, 'an underscored namespace is refused before any mint' );
assert_same( '[a-z0-9-]+', $diviops_vic_data['error']['data']['expected'] ?? null, 'the namespace refusal documents the tightened charset' );
assert_same( array(), diviops_vic_registry(), 'and the fluid system writes nothing' );

// The length ceiling is only reachable by concatenation: namespace and name_prefix
// are each legal on their own, and validate_name_prefix() caps neither. The minted
// id is `gvid-{namespace}-{prefix}-{n}`, so a 60-character namespace with a
// 30-character prefix is a 93-character suffix. This is what a post-mint check
// catches and no input-level check on the parts can.
diviops_vic_reset();

$diviops_vic_ns     = str_repeat( 'n', 60 );
$diviops_vic_pfx    = str_repeat( 'p', 30 );
assert_same( $diviops_vic_ns, diviops_vic_prefix( $diviops_vic_ns, 'namespace', 'oa' ), 'the long namespace is legal on its own' );
assert_same( $diviops_vic_pfx, diviops_vic_prefix( $diviops_vic_pfx, 'spacing.name_prefix', 'space' ), 'and so is the long name_prefix' );

$diviops_vic_resp = diviops_call( 'variable_create_fluid_system', array( diviops_vic_request( array(
	'namespace' => $diviops_vic_ns,
	'spacing'   => array( 'min_px' => 8, 'max_px' => 40, 'steps' => 2, 'name_prefix' => $diviops_vic_pfx ),
) ) ) );
$diviops_vic_data = $diviops_vic_resp->get_data();
assert_same( 'invalid_input', $diviops_vic_data['error']['code'] ?? null, 'a plan id past the 80-character ceiling is refused' );
assert_same( array(), diviops_vic_registry(), 'and no entry of that plan is written' );

// A legal namespace and prefix still generate and write, so the post-mint check
// refuses only what Divi cannot resolve.
diviops_vic_reset();

$diviops_vic_resp = diviops_call( 'variable_create_fluid_system', array( diviops_vic_request( array(
	'namespace' => 'brand',
	'spacing'   => array( 'min_px' => 8, 'max_px' => 40, 'steps' => 2, 'name_prefix' => 'space' ),
) ) ) );
$diviops_vic_data = $diviops_vic_resp->get_data();
assert_same( true, $diviops_vic_data['ok'] ?? null, 'a legal fluid system still succeeds' );
assert_true( isset( diviops_vic_registry()['numbers']['gvid-brand-space-1'] ), 'and mints the id the namespace and prefix describe' );
