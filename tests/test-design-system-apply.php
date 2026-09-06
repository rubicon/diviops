<?php
// SPDX-License-Identifier: MIT
/**
 * design_system_apply: a style guide's token set becomes live Divi tokens (#392).
 *
 * Spec: docs/superpowers/specs/2026-09-05-design-system-authoring-design.md
 * Plan: docs/superpowers/plans/2026-09-05-design-system-apply.md
 *
 * The rule this file exists to enforce is that the tool NEVER invents a token. A colour
 * carrying neither a literal value nor a `derived_from` is refused, so a skill that
 * hallucinates one produces a loud error instead of a quietly wrong palette. That rule is
 * unenforceable as a prompt instruction, which is the whole reason the parse/apply boundary
 * sits in PHP rather than leaving the skill to make N single-token calls.
 *
 * ## What is asserted
 *
 * STORAGE, wherever a write happens — read back through Divi's own accessor. The defect
 * class this repository keeps paying for is a response that reports success over a store
 * that says something else, and #393 was exactly that shape three weeks running.
 *
 * ## Scope
 *
 * Colours only, deliberately. All 99 live tokens on staging are colours, and colours are
 * where the `$variable()` reference problem lives — 47 of those 99. Fonts and non-colour
 * variables reuse this same plan/apply skeleton and are a follow-up, recorded in the plan's
 * self-review rather than silently dropped.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
// These handlers sit behind Divi's option layer — see that shim's docblock for why
// et_get_option() is opt-in rather than part of wp-shim.php.
require_once __DIR__ . '/divi-active-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

/** Validate a colour token list through the private helper. */
function diviops_ds_validate( array $colors ): array {
	return diviops_call( 'design_system_validate_colors', array( $colors ) );
}

// ---------------------------------------------------------------------------
// Validation, and the never-invent refusal.
// ---------------------------------------------------------------------------

$ok = diviops_ds_validate( array( array( 'name' => 'primary', 'value' => '#1B4D8F' ) ) );
assert_same( true, $ok['ok'] ?? null, 'a colour with a literal value validates' );
assert_same( 'primary', $ok['tokens'][0]['name'] ?? null, 'and is returned as a token' );

// The never-invent-a-token rule, and the reason this boundary is in PHP at all.
$bad = diviops_ds_validate( array( array( 'name' => 'ghost' ) ) );
assert_same( false, $bad['ok'] ?? null, 'a colour with no value and no derived_from is refused' );
assert_same( 'invalid_input', $bad['code'] ?? null, 'and refused as invalid_input' );
assert_same( 'ghost', $bad['data']['token'] ?? null, 'and the refusal names the offending token' );
assert_same( 0, $bad['data']['index'] ?? null, 'and its index, so a 60-token payload is actionable' );

// Ambiguity is refused rather than resolved by precedence — a precedence rule here would
// silently discard one of the two values the caller supplied.
$both = diviops_ds_validate( array(
	array( 'name' => 'primary', 'value' => '#1B4D8F', 'derived_from' => 'other' ),
) );
assert_same( false, $both['ok'] ?? null, 'a colour carrying both value and derived_from is refused' );

// An unrecognised settings key is refused, not dropped. A dropped setting is a silently
// different colour, which is the failure mode this whole file is about.
$badkey = diviops_ds_validate( array(
	array( 'name' => 'primary', 'value' => '#1B4D8F' ),
	array( 'name' => 'tint', 'derived_from' => 'primary', 'settings' => array( 'brightness' => 10 ) ),
) );
assert_same( false, $badkey['ok'] ?? null, 'an unrecognised settings key is refused' );
assert_same( 'brightness', $badkey['data']['field'] ?? null, 'and named in the refusal' );

$orphan = diviops_ds_validate( array(
	array( 'name' => 'primary', 'value' => '#1B4D8F', 'settings' => array( 'lightness' => 5 ) ),
) );
assert_same( false, $orphan['ok'] ?? null, 'settings without derived_from is refused' );

// The four legal keys, read from Divi's OWN source: GlobalData.php:177 documents "hue,
// saturation, lightness, and opacity" and :187 reads $settings['hue'] ?? 0. An earlier
// revision listed only three, derived from the 47 reference entries on the live palette —
// none of which carries a hue. That is measuring the sample instead of the contract, and it
// made a legal Divi setting look like a typo.
$settings = diviops_call( 'design_system_color_settings_keys', array() );
assert_same( array( 'hue', 'saturation', 'lightness', 'opacity' ), $settings, 'the settings vocabulary matches Divi' );

$hue = diviops_ds_validate( array(
	array( 'name' => 'primary', 'value' => '#1B4D8F' ),
	array( 'name' => 'shifted', 'derived_from' => 'primary', 'settings' => array( 'hue' => 30 ) ),
) );
assert_same( true, $hue['ok'] ?? null, 'a hue-shifted derived colour is accepted' );

// '--5' passed a ctype_digit(ltrim(...,'-')) guard and cast to 0 — a silently different colour.
$dbl = diviops_ds_validate( array(
	array( 'name' => 'primary', 'value' => '#1B4D8F' ),
	array( 'name' => 'tint', 'derived_from' => 'primary', 'settings' => array( 'lightness' => '--5' ) ),
) );
assert_same( false, $dbl['ok'] ?? null, "a malformed integer like '--5' is refused, not cast to 0" );

// A colour value is validated through the same sanitizer the sibling writer uses.
$junk = diviops_ds_validate( array( array( 'name' => 'primary', 'value' => 'not-a-colour' ) ) );
assert_same( false, $junk['ok'] ?? null, 'a value that is not a CSS colour is refused' );
$rgba = diviops_ds_validate( array( array( 'name' => 'primary', 'value' => 'rgba(0,0,0,0.3)' ) ) );
assert_same( true, $rgba['ok'] ?? null, 'functional rgba() notation is accepted — Divi\'s own palette stores it' );

// A non-scalar reached a (string) cast and became the literal "Array".
$arr = diviops_ds_validate( array( array( 'name' => 'primary', 'value' => array( '#000000' ) ) ) );
assert_same( false, $arr['ok'] ?? null, 'a non-scalar value is refused rather than cast to the string "Array"' );

// Status follows #393's per-surface vocabulary rather than a second copy of the list.
$badstatus = diviops_ds_validate( array(
	array( 'name' => 'primary', 'value' => '#1B4D8F', 'status' => 'archived' ),
) );
assert_same( false, $badstatus['ok'] ?? null, 'a colour status outside Divi\'s colour vocabulary is refused' );

// ---------------------------------------------------------------------------
// Deterministic ids. Re-running the same style guide must land on the same ids,
// which is what makes a re-import an update instead of a duplicate.
// ---------------------------------------------------------------------------

assert_same(
	'gcid-acme-primary',
	diviops_call( 'design_system_token_id', array( 'gcid', 'acme', 'primary' ) ),
	'an id is prefix + namespace + slug'
);
assert_same(
	'gcid-acme-primary-600',
	diviops_call( 'design_system_token_id', array( 'gcid', 'acme', 'Primary 600' ) ),
	'a spaced, capitalised name slugs deterministically'
);
assert_same(
	diviops_call( 'design_system_token_id', array( 'gcid', 'acme', 'Primary 600' ) ),
	diviops_call( 'design_system_token_id', array( 'gcid', 'acme', 'Primary 600' ) ),
	'and the same input always yields the same id'
);

// REJECT rather than sanitise. validate_name_prefix() already carries this rule and states
// why: sanitize_key() silently rewriting "oa!" to "oa" aliases bogus input onto another
// token set, and under overwrite=true that rewrites the WRONG tokens.
assert_same( null, diviops_call( 'design_system_slug', array( 'brand!' ) ), 'a name needing rewriting is rejected, not sanitised' );
assert_same( null, diviops_call( 'design_system_slug', array( '' ) ), 'an empty name is rejected' );
assert_same( null, diviops_call( 'design_system_slug', array( '---' ) ), 'a name that slugs to nothing is rejected' );
assert_same( 'primary-600', diviops_call( 'design_system_slug', array( 'Primary 600' ) ), 'case and spaces are a legal, lossless transform' );

// ---------------------------------------------------------------------------
// Derived colours must be written after the colour they reference, whatever order
// the style guide happened to list them in.
// ---------------------------------------------------------------------------

function diviops_ds_order( array $colors ): array {
	$v = diviops_ds_validate( $colors );
	assert_same( true, $v['ok'] ?? null, 'the ordering fixture validates before it is ordered' );
	return diviops_call( 'design_system_order_colors', array( $v['tokens'] ) );
}

$ordered = diviops_ds_order( array(
	array( 'name' => 'tint', 'derived_from' => 'primary', 'settings' => array( 'lightness' => 20 ) ),
	array( 'name' => 'primary', 'value' => '#1B4D8F' ),
) );
assert_same( true, $ordered['ok'] ?? null, 'an out-of-order payload is orderable' );
assert_same( 'primary', $ordered['tokens'][0]['name'] ?? null, 'the referenced colour is written first' );
assert_same( 'tint', $ordered['tokens'][1]['name'] ?? null, 'and the derived colour second' );

// A cycle is refused. Divi cannot resolve a -> b -> a, and writing it produces a palette
// that renders wrong with no error anywhere.
$cycle = diviops_ds_order( array(
	array( 'name' => 'a', 'derived_from' => 'b' ),
	array( 'name' => 'b', 'derived_from' => 'a' ),
) );
assert_same( false, $cycle['ok'] ?? null, 'a dependency cycle is refused' );
assert_same( 'derived_from', $cycle['data']['field'] ?? null, 'and named as a derived_from problem' );

$dangling = diviops_ds_order( array(
	array( 'name' => 'tint', 'derived_from' => 'nowhere', 'settings' => array( 'lightness' => 5 ) ),
) );
assert_same( false, $dangling['ok'] ?? null, 'an unresolvable derived_from is refused rather than flattened' );

// A style guide legitimately extends an existing palette rather than replacing it, so a
// reference to an id already on the site resolves.
$existing_tokens = diviops_ds_validate( array(
	array( 'name' => 'tint', 'derived_from' => 'gcid-primary-color', 'settings' => array( 'lightness' => 7 ) ),
) );
$existing = diviops_call( 'design_system_order_colors', array(
	$existing_tokens['tokens'],
	array( 'gcid-primary-color' => true ),
) );
assert_same( true, $existing['ok'] ?? null, 'a derived_from naming an existing site id resolves' );

// ---------------------------------------------------------------------------
// The reference grammar. Expected values are transcribed from a real entry on staging
// (Divi 5.12.0), not from what this code produces:
//   $variable({"type":"color","value":{"name":"gcid-primary-color","settings":{"lightness":7}}})$
// ---------------------------------------------------------------------------

assert_same(
	'$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{"lightness":7}}})$',
	diviops_call( 'design_system_reference_token', array( 'gcid-primary-color', array( 'lightness' => 7 ) ) ),
	'a single-setting reference matches the stored grammar byte for byte'
);
assert_same(
	'$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{"lightness":34,"opacity":20}}})$',
	diviops_call( 'design_system_reference_token', array( 'gcid-primary-color', array( 'lightness' => 34, 'opacity' => 20 ) ) ),
	'and a two-setting reference matches the observed multi-setting form'
);
assert_same(
	'$variable({"type":"color","value":{"name":"gcid-x","settings":{}}})$',
	diviops_call( 'design_system_reference_token', array( 'gcid-x', array() ) ),
	'an empty settings object is emitted as an object, not omitted or rendered as []'
);

// ---------------------------------------------------------------------------
// Applying a token set. Assertions read STORAGE back through Divi's own accessor.
// ---------------------------------------------------------------------------

function diviops_ds_apply( array $params ) {
	$req = new WP_REST_Request();
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return diviops_call( 'design_system_apply', array( $req ) );
}

function diviops_ds_palette(): array {
	$raw = et_get_option( 'et_global_data' );
	return is_array( $raw ) ? ( $raw['global_colors'] ?? array() ) : array();
}

et_update_option( 'et_global_data', array( 'global_colors' => array() ) );

$payload = array(
	array( 'name' => 'primary', 'value' => '#1B4D8F', 'label' => 'Primary' ),
	array( 'name' => 'primary-600', 'derived_from' => 'primary', 'settings' => array( 'lightness' => -10 ) ),
);

$resp = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => $payload ) );
assert_same( true, $resp->get_data()['ok'] ?? null, 'a valid token set applies' );

$palette = diviops_ds_palette();
assert_same( 2, count( $palette ), 'both colours are written' );
assert_same( '#1B4D8F', $palette['gcid-acme-primary']['color'] ?? null, 'the literal colour is stored as given' );
assert_same(
	'$variable({"type":"color","value":{"name":"gcid-acme-primary","settings":{"lightness":-10}}})$',
	$palette['gcid-acme-primary-600']['color'] ?? null,
	'the derived colour is stored as a reference to the token it derives from, not a flattened hex'
);
assert_same( 'active', $palette['gcid-acme-primary']['status'] ?? null, 'a new colour defaults to active' );
assert_true( isset( $palette['gcid-acme-primary']['order'] ), 'and carries an order' );
assert_same( array(), $palette['gcid-acme-primary']['usedInPosts'] ?? null, 'and the Divi-owned usedInPosts index is seeded' );

// Definition-of-done: re-running the same style guide UPDATES rather than duplicating.
$before_order = $palette['gcid-acme-primary']['order'];
$again = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => $payload, 'overwrite' => true ) );
$palette2 = diviops_ds_palette();
assert_same( 2, count( $palette2 ), 're-applying the same style guide does not duplicate' );
assert_same( $before_order, $palette2['gcid-acme-primary']['order'] ?? null, 'and preserves order across the re-run' );
assert_same( 2, $again->get_data()['data']['updated_count'] ?? null, 'and reports both as updates, not creates' );

// overwrite defaults to false: an existing id is skipped with a stated reason rather than
// silently overwritten.
$skipped = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => $payload ) );
$sdata   = $skipped->get_data();
assert_same( 2, $sdata['data']['skipped_count'] ?? null, 'without overwrite, existing ids are skipped' );
assert_same( 'exists', $sdata['data']['skipped'][0]['reason'] ?? null, 'and the skip states its reason' );

// A dry run writes nothing.
et_update_option( 'et_global_data', array( 'global_colors' => array() ) );
$plan = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => $payload, 'dry_run' => true ) );
assert_same( true, $plan->get_data()['data']['dry_run'] ?? null, 'a dry run reports itself as one' );
assert_same( 0, count( diviops_ds_palette() ), 'and writes nothing' );

// One refusal fails the WHOLE payload. A partial apply is worse than a refusal, because the
// caller cannot tell which half landed.
et_update_option( 'et_global_data', array( 'global_colors' => array() ) );
$partial = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => array(
	array( 'name' => 'good', 'value' => '#000000' ),
	array( 'name' => 'ghost' ),
) ) );
assert_same( false, $partial->get_data()['ok'] ?? null, 'one invalid token refuses the whole payload' );
assert_same( 0, count( diviops_ds_palette() ), 'and nothing at all is written' );

// An unslugifiable name is refused rather than aliased onto another token.
$badname = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => array(
	array( 'name' => 'brand!', 'value' => '#000000' ),
) ) );
assert_same( false, $badname->get_data()['ok'] ?? null, 'an unslugifiable token name is refused' );
assert_same( 0, count( diviops_ds_palette() ), 'and still nothing is written' );

// An empty payload is a mistake worth reporting, not a silent no-op.
$empty = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => array() ) );
assert_same( false, $empty->get_data()['ok'] ?? null, 'an empty colors array is refused' );

// The write reports what it did to the compiled CSS (#381). `unavailable` is correct in this
// harness — no WP_Filesystem is declared — and the point of the field is that a clear which
// could not run never renders as one that did.
et_update_option( 'et_global_data', array( 'global_colors' => array() ) );
$cached = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => array(
	array( 'name' => 'only', 'value' => '#123456' ),
) ) );
assert_same(
	'unavailable',
	$cached->get_data()['data']['cache']['status'] ?? null,
	'an apply reports a site-wide cache result'
);

// An omitted status keeps the stored one on update (#393), rather than resetting to active.
et_update_option( 'et_global_data', array( 'global_colors' => array(
	'gcid-acme-kept' => array(
		'id' => 'gcid-acme-kept', 'color' => '#111111', 'label' => 'Kept',
		'status' => 'inactive', 'order' => '4', 'folder' => '', 'usedInPosts' => array(),
	),
) ) );
diviops_ds_apply( array( 'namespace' => 'acme', 'overwrite' => true, 'colors' => array(
	array( 'name' => 'kept', 'value' => '#222222' ),
) ) );
$kept = diviops_ds_palette();
assert_same( '#222222', $kept['gcid-acme-kept']['color'] ?? null, 'an update writes the new value' );
assert_same( 'inactive', $kept['gcid-acme-kept']['status'] ?? null, 'and an omitted status keeps the stored one' );
assert_same( '4', $kept['gcid-acme-kept']['order'] ?? null, 'and order survives the update' );

// ---------------------------------------------------------------------------
// Id contract. Two writers to one registry must not disagree about what a legal id is,
// and the sibling writer's validate_global_color_id() is that contract.
// ---------------------------------------------------------------------------

et_update_option( 'et_global_data', array( 'global_colors' => array() ) );

// validate_name_prefix() is the gvid-* charset and permits '_'. A gcid may not contain one:
// Divi's own scanner (DetectFeature.php:138, gcid-[0-9a-z-]*) truncates gcid-my_brand-primary
// to gcid-my, so the :root custom property is never emitted and the colour silently does not
// render. Nothing errors anywhere, which is why this has to be refused at write time.
$under = diviops_ds_apply( array( 'namespace' => 'my_brand', 'colors' => array(
	array( 'name' => 'primary', 'value' => '#1B4D8F' ),
) ) );
assert_same( false, $under->get_data()['ok'] ?? null, 'an underscore in the namespace is refused — it mints an id Divi cannot resolve' );
assert_same( 0, count( diviops_ds_palette() ), 'and nothing is written' );

// The suffix after gcid- is capped at 80 chars by the same contract.
$long = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => array(
	array( 'name' => str_repeat( 'ab', 60 ), 'value' => '#1B4D8F' ),
) ) );
assert_same( false, $long->get_data()['ok'] ?? null, 'an over-length id is refused' );

// The five customizer-bound colours are managed through WP Customizer; the sibling writers
// answer 403 on them and this one must not be a back door.
$locked = diviops_ds_apply( array( 'namespace' => 'primary', 'colors' => array(
	array( 'name' => 'color', 'value' => '#1B4D8F' ),
) ) );
$ldata = $locked->get_data();
assert_same( false, $ldata['ok'] ?? null, 'a customizer-bound id cannot be minted through this tool' );
assert_same(
	'variable.customizer_default_immutable',
	$ldata['error']['code'] ?? null,
	'and it answers with the same code the sibling writers use'
);

// Two names that mint one id are refused rather than silently collapsed last-wins, which
// would drop a token the caller supplied and make dry_run disagree with the real run.
$collide = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => array(
	array( 'name' => 'Brand Blue', 'value' => '#1B4D8F' ),
	array( 'name' => 'brand blue', 'value' => '#000000' ),
) ) );
assert_same( false, $collide->get_data()['ok'] ?? null, 'two names colliding on one id are refused' );
assert_same( 0, count( diviops_ds_palette() ), 'and neither is written' );

// ---------------------------------------------------------------------------
// The critical one: et_global_data can be stored serialized, and it holds far more than
// the palette. Reading it with a bare is_array() check treated a serialized string as "no
// data", and the write-back then replaced the WHOLE option.
// ---------------------------------------------------------------------------

et_update_option( 'et_global_data', serialize( array(
	'global_colors'    => array(),
	'global_fonts'     => array( 'gfid-keepme' => array( 'family' => 'Inter' ) ),
) ) );
$ser = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => array(
	array( 'name' => 'primary', 'value' => '#1B4D8F' ),
) ) );
assert_same( true, $ser->get_data()['ok'] ?? null, 'a serialized et_global_data is unserialized and written through' );
$after_ser = et_get_option( 'et_global_data' );
assert_true(
	isset( $after_ser['global_fonts']['gfid-keepme'] ),
	'and every sibling key under et_global_data survives — global_fonts is not destroyed'
);
assert_same( '#1B4D8F', $after_ser['global_colors']['gcid-acme-primary']['color'] ?? null, 'while the colour is still written' );

// A value that is neither an array nor unserializable is refused rather than overwritten.
et_update_option( 'et_global_data', 'not-serialized-and-not-an-array' );
$corrupt = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => array(
	array( 'name' => 'primary', 'value' => '#1B4D8F' ),
) ) );
assert_same( false, $corrupt->get_data()['ok'] ?? null, 'an unusable et_global_data is refused, not overwritten' );
assert_same(
	'not-serialized-and-not-an-array',
	et_get_option( 'et_global_data' ),
	'and the option is left exactly as it was'
);

// ---------------------------------------------------------------------------
// The #380 merge this file's docblock leans on. Without an assertion, removing array_merge
// left the whole suite green — the fixture's stored entry carried no key the writer does
// not itself set, so there was nothing for the merge to preserve.
// ---------------------------------------------------------------------------

et_update_option( 'et_global_data', array( 'global_colors' => array(
	'gcid-acme-merged' => array(
		'id' => 'gcid-acme-merged', 'color' => '#111111', 'label' => 'Merged',
		'status' => 'inactive', 'order' => '9', 'folder' => 'brand',
		'usedInPosts' => array( 900390 ),
		'customField' => 'divi-may-add-this',
	),
) ) );
diviops_ds_apply( array( 'namespace' => 'acme', 'overwrite' => true, 'colors' => array(
	array( 'name' => 'merged', 'value' => '#222222' ),
) ) );
$merged = diviops_ds_palette()['gcid-acme-merged'] ?? array();

assert_same( '#222222', $merged['color'] ?? null, 'the requested field is written' );
assert_same(
	'divi-may-add-this',
	$merged['customField'] ?? null,
	'a key this fork does not enumerate survives the write — the merge is total (#380)'
);
assert_same( 'brand', $merged['folder'] ?? null, 'a stored folder is not blanked' );
assert_same( array( 900390 ), $merged['usedInPosts'] ?? null, 'and Divi\'s usedInPosts index is preserved' );
assert_same( 'Merged', $merged['label'] ?? null, 'an omitted label keeps the stored one, as an omitted status does' );
assert_same( 'inactive', $merged['status'] ?? null, 'and the stored status is kept' );

// ---------------------------------------------------------------------------
// The dry-run plan must actually describe the change. An empty plan satisfied the earlier
// "it reports itself as a dry run" assertion while describing nothing.
// ---------------------------------------------------------------------------

et_update_option( 'et_global_data', array( 'global_colors' => array() ) );
$planned = diviops_ds_apply( array( 'namespace' => 'acme', 'dry_run' => true, 'colors' => array(
	array( 'name' => 'primary', 'value' => '#1B4D8F' ),
	array( 'name' => 'tint', 'derived_from' => 'primary', 'settings' => array( 'lightness' => 20 ) ),
) ) );
$pd = $planned->get_data()['data'] ?? array();
assert_same( 2, count( $pd['plan']['changes'] ?? array() ), 'the dry-run plan names one change per token' );
assert_same( 'design_system.create', $pd['plan']['changes'][0]['kind'] ?? null, 'and classifies a new token as a create' );
assert_same( 'global_colors/gcid-acme-primary', $pd['plan']['changes'][0]['target'] ?? null, 'and names the id it would write' );
assert_same( 2, $pd['created_count'] ?? null, 'and its counts match the plan' );

// ---------------------------------------------------------------------------
// The #381 rule: invalidate once per run, and NOT at all when nothing was written.
// ---------------------------------------------------------------------------

$nothing = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => array(
	array( 'name' => 'primary', 'value' => '#1B4D8F' ),
) ) );
assert_same( 1, $nothing->get_data()['data']['created_count'] ?? null, 'the first run creates' );

$rerun = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => array(
	array( 'name' => 'primary', 'value' => '#1B4D8F' ),
) ) );
$rd = $rerun->get_data()['data'] ?? array();
assert_same( 1, $rd['skipped_count'] ?? null, 'a re-run without overwrite skips' );
// `?? ` fires on a present-but-null value too, so it cannot tell "absent" from "null".
// array_key_exists can, and the distinction is the whole point: the key is always emitted,
// and null is how it says "no clear was attempted".
assert_true(
	array_key_exists( 'cache', $rd ) && null === $rd['cache'],
	'and clears no cache, because it wrote nothing'
);
