<?php
/**
 * variable_update() — update-in-place for Variable Manager design tokens (#25).
 *
 * Before this fix, `variable` had create/delete/create-fluid-system/create-
 * gradient/scan-orphans/used-on-page but no update: changing a token's value
 * meant delete then recreate, which mints a fresh auto-generated id unless the
 * caller remembers to pass the old one back — and even then, variable_create's
 * unconditional overwrite bumps `order` and requires resending every field
 * (there is no partial-merge/not-found contract). variable_update fixes this:
 * strict lookup by id (unknown id -> not_found, it does not silently create),
 * partial field merge (only supplied fields change), and the id (plus, for
 * non-color buckets, the type) are structurally excluded from the override set
 * so they can never change. That last property is what keeps existing
 * `$variable({...})$` references resolving after the call, since the token
 * embeds the id, not the value.
 *
 * These tests cover the pure id-preservation/merge helper
 * (build_updated_variable_record) and the gradient-token builder it shares
 * with variable_create (build_gradient_variable_value) — neither touches
 * WordPress option storage. They also assert the two structural regressions
 * this change could silently introduce: the REST route not being wired, and
 * the capability key not being added to CAPABILITIES (the file's own doc
 * comment calls out that omission as a maintenance hazard).
 *
 * variable_update() itself resolves its storage bucket via et_get_option() /
 * get_option() and persists via et_update_option() / update_option() —
 * WordPress option-storage primitives this harness does not shim (see
 * wp-shim.php's own scope note). Faking those here would mean asserting
 * against a fake persistence layer's behavior instead of Divi's real one,
 * which is the mocked-behavior class of test this project's engineering
 * policy forbids. The full request-level paths this leaves unverified by
 * this suite: not_found on an unknown id, the customizer-bound-default 403
 * guard, invalid-status/invalid-hex-color 400s, and the actual
 * et_global_data / et_divi_global_variables writes. Those need live
 * verification against a real Divi 5 install (same boundary
 * test-global-layout-write-guard.php draws for its own unshimmed paths).
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

// ── build_updated_variable_record: the id-preservation transform ──────────

// (a) a supplied override changes only the fields it names; everything else
// on the existing record — including `order`, which variable_update never
// touches — passes through untouched.
$existing_a = array(
	'id'          => 'gvid-oa-size-xl',
	'type'        => 'numbers',
	'label'       => 'Old label',
	'value'       => '20px',
	'order'       => 3,
	'status'      => 'active',
	'lastUpdated' => '2020-01-01T00:00:00.000Z',
);
$updated_a  = diviops_call(
	'build_updated_variable_record',
	array( $existing_a, array( 'value' => '24px' ) )
);
assert_same( '24px', $updated_a['value'], 'the overridden field (value) is written' );
assert_same( 'gvid-oa-size-xl', $updated_a['id'], 'id is preserved when not part of the override set' );
assert_same( 'numbers', $updated_a['type'], 'type is preserved when not part of the override set' );
assert_same( 'Old label', $updated_a['label'], 'label is preserved when the caller did not supply a label override' );
assert_same( 3, $updated_a['order'], 'order is preserved — variable_update never includes it in the override set' );
assert_same( 'active', $updated_a['status'], 'status is preserved when the caller did not supply a status override' );

// (b) lastUpdated is bumped on every call, even a metadata-only update that
// changes no value — this is what makes a label/status-only PATCH still
// record a write.
$updated_b = diviops_call(
	'build_updated_variable_record',
	array( $existing_a, array( 'label' => 'New label' ) )
);
assert_true(
	'2020-01-01T00:00:00.000Z' !== $updated_b['lastUpdated'],
	'lastUpdated is bumped even when the override set contains no value change'
);
assert_true(
	1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $updated_b['lastUpdated'] ),
	'lastUpdated is stamped in the same ISO-8601-with-ms UTC shape variable_create/variable_delete use'
);
assert_same( '20px', $updated_b['value'], 'a label-only override leaves value untouched' );

// (c) an empty override set is a no-op merge except for the lastUpdated bump
// — proves the function does not require callers to restate unchanged
// fields (the whole point of "partial update").
$updated_c = diviops_call( 'build_updated_variable_record', array( $existing_a, array() ) );
assert_same( 'Old label', $updated_c['label'], 'no overrides leaves label as-is' );
assert_same( '20px', $updated_c['value'], 'no overrides leaves value as-is' );
assert_same( 3, $updated_c['order'], 'no overrides leaves order as-is' );

// (d) the colors-bucket record shape has no `type` key at all (the bucket is
// the array key, not a field) — the merge must not invent one.
$existing_d = array(
	'color'       => '#111111',
	'status'      => 'active',
	'label'       => 'Accent',
	'order'       => '1',
	'lastUpdated' => '2020-01-01T00:00:00.000Z',
);
$updated_d  = diviops_call(
	'build_updated_variable_record',
	array( $existing_d, array( 'color' => '#222222' ) )
);
assert_same( '#222222', $updated_d['color'], 'the overridden color field is written' );
assert_same( 'Accent', $updated_d['label'], 'label is preserved on a colors-bucket update' );
assert_true( ! array_key_exists( 'type', $updated_d ), 'the merge does not fabricate a type key for the colors bucket' );

// ── build_gradient_variable_value: shared with variable_create, exercised
// here because variable_update's gradients branch calls it directly ────────

// (a) structured input builds the canonical $variable(gradient) token.
$gradient_token = diviops_call(
	'build_gradient_variable_value',
	array(
		null,
		array(
			'stops' => array(
				array( 'position' => '0', 'color' => '#000000' ),
				array( 'position' => '100', 'color' => '#ffffff' ),
			),
		),
	)
);
assert_true( is_string( $gradient_token ), 'structured gradient input builds a string token, not an error response' );
assert_true(
	0 === strpos( $gradient_token, '$variable(' ) && '$' === substr( $gradient_token, -1 ),
	'the built token has the $variable(...)$ wrapper shape'
);
assert_true(
	false !== strpos( $gradient_token, '"type":"gradient"' ),
	'the built token declares type=gradient'
);

// (b) a pre-built token is accepted verbatim (path 2) — this is what lets a
// caller pass the exact token they got back from a prior create/update call.
$verbatim = '$variable({"type":"gradient","value":{"name":"gradient","settings":{}}})$';
assert_same(
	$verbatim,
	diviops_call( 'build_gradient_variable_value', array( $verbatim, null ) ),
	'a pre-built gradient token is passed through unchanged'
);

// (c) a raw CSS gradient string is rejected — the #921 footgun this helper
// exists to close. Confirms variable_update inherits the same rejection
// variable_create already has, rather than accidentally being more lenient.
$rejected = diviops_call(
	'build_gradient_variable_value',
	array( 'linear-gradient(90deg, #000, #fff)', null )
);
assert_true(
	$rejected instanceof WP_REST_Response,
	'a raw CSS gradient string is rejected as an error response, not accepted as a stored value'
);

// ── Structural regressions: route wiring + capability key ─────────────────

$plugin_file = dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';
$plugin_src  = (string) file_get_contents( $plugin_file );

assert_true(
	1 === preg_match(
		"/register_rest_route\\(\\s*self::REST_NAMESPACE,\\s*'\\/variable\\/update',\\s*\\[\\s*'methods'\\s*=>\\s*'POST',\\s*'callback'\\s*=>\\s*\\[\\s*__CLASS__,\\s*'variable_update'\\s*\\]/s",
		$plugin_src
	),
	'/variable/update is registered as a POST route dispatching to variable_update'
);

assert_true(
	1 === preg_match( "/'variable_update'/", $plugin_src ),
	"the 'variable_update' capability key is present in CAPABILITIES, per this file's own maintenance note"
);

$trait_file = dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-variable.php';
assert_true(
	method_exists( 'DiviOps_Agent', 'variable_update' ),
	'DiviOps_Agent::variable_update exists once the trait is mixed in'
);
assert_true( is_file( $trait_file ), 'trait-variable.php is where the handler is expected to live' );

// Defense-in-depth: id and type are structurally immutable. Even if a caller —
// or a future handler regression — puts them in the override set, the helper
// must strip them so every existing $variable() reference stays valid. This
// assertion fails against a bare array_merge and passes with the unset guard.
$tampered = diviops_call( 'build_updated_variable_record', array(
	array( 'id' => 'gvid-keep', 'type' => 'numbers', 'value' => '10px', 'label' => 'Keep' ),
	array( 'id' => 'gvid-HIJACK', 'type' => 'gradients', 'value' => '20px' ),
) );
assert_same( 'gvid-keep', $tampered['id'], 'id override is structurally stripped — id is immutable' );
assert_same( 'numbers', $tampered['type'], 'type override is structurally stripped — type is immutable' );
assert_same( '20px', $tampered['value'], 'a legitimate value override still applies alongside the stripped id/type' );
