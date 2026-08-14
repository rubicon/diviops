<?php
/**
 * seo_provider_list / seo_metadata_get / seo_metadata_update (#37).
 *
 * trait-seo.php shipped with zero test coverage before this file: no test file, no
 * TSF stub. tests/tsf-shim.php is a faithful stub of The SEO Framework 5.1.4's public
 * API surface (installed but inactive on the reference site), built by reading the
 * real plugin source directly -- see that file's docblock for exactly what is and
 * isn't modeled and why.
 *
 * This suite covers the trait's entire existing surface (provider discovery/list,
 * the read path, and the write path's validation/checksum/dry-run/rollback machinery)
 * as well as the four fields this PR adds (og_title, og_description, twitter_title,
 * twitter_description) using that same machinery. current_user_can is a fixed-true
 * stub in the harness unless a test opts into the denial seams
 * ($GLOBALS['diviops_test_denied_caps'] / '_uneditable_ids'), which the forbidden-path
 * cases below use directly rather than treating those branches as inspected-only.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/tsf-shim.php';

function diviops_seo_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

function diviops_seo_reset(): void {
	$GLOBALS['diviops_test_posts']            = array();
	$GLOBALS['diviops_test_post_meta']        = array();
	$GLOBALS['diviops_test_denied_caps']      = array();
	$GLOBALS['diviops_test_uneditable_ids']   = array();
	$GLOBALS['diviops_test_tsf_write_throws']         = null;
	$GLOBALS['diviops_test_tsf_write_corrupt_field']  = null;
	diviops_test_tsf_reset();
}

/**
 * Read a post's real checksum through the actual get handler, so tests that need a
 * valid expected_checksum never hand-compute one.
 */
function diviops_seo_checksum( int $post_id ): string {
	$resp = diviops_call( 'seo_metadata_get', array( diviops_seo_request( array( 'id' => $post_id ) ) ) );
	return $resp->get_data()['data']['checksum'];
}

// ── seo_provider_list ───────────────────────────────────────────────────────

diviops_seo_reset();
$resp = diviops_call( 'seo_provider_list', array( diviops_seo_request( array() ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'seo_provider_list succeeds' );
assert_same( 1, $data['data']['count'], 'exactly one adapter is listed' );
assert_same( 'tsf', $data['data']['providers'][0]['provider'], 'the listed provider is tsf' );
assert_true( $data['data']['providers'][0]['active'], 'tsf reports active when active_plugins lists it and tsf() exists' );
assert_true( $data['data']['providers'][0]['compatible'], 'version 5.1.4 satisfies MIN_VERSION' );
assert_same( 'runtime_verified', $data['data']['providers'][0]['adapter'], 'a healthy provider is runtime_verified' );

// ── discovery(): not-installed state (reachable in-process) ────────────────

diviops_seo_reset();
$GLOBALS['diviops_test_options']['active_plugins'] = array();
$resp = diviops_call( 'seo_provider_list', array( diviops_seo_request( array() ) ) );
$provider = $resp->get_data()['data']['providers'][0];
assert_true( false === $provider['installed'], 'clearing active_plugins with no fixture on WP_PLUGIN_DIR reports not installed' );
assert_true( false === $provider['active'], 'not installed implies not active' );
assert_same( 'not_installed', $provider['adapter'], 'adapter reports not_installed' );
assert_true( null === $provider['version'], 'version is unknown when nothing can be read' );

// ── discovery(): installed-but-inactive and active-but-incompatible ────────
// Both require THE_SEO_FRAMEWORK_VERSION or WP_PLUGIN_DIR to differ from this
// process's already-defined constants -- run as child processes, mirroring
// test-media-svg-capability-gate.php's constant-precedence check.

// Both probes require tsf-shim.php directly (not test-seo.php, which would
// re-run this entire file's top-level assertions inside the child process and
// garble the echoed JSON with their PASS/FAIL output).
$inactive_probe = sprintf(
	'define( "WP_PLUGIN_DIR", %s );'
	. ' require %s;'
	. ' $GLOBALS["diviops_test_options"]["active_plugins"] = [];'
	. ' $resp = diviops_call( "seo_provider_list", [ new DiviOps_Test_Request( [] ) ] );'
	. ' $p = $resp->get_data()["data"]["providers"][0];'
	. ' echo json_encode( [ $p["installed"], $p["active"], $p["adapter"], $p["version"] ] );',
	var_export( __DIR__ . '/fixtures/tsf-plugin', true ),
	var_export( __DIR__ . '/tsf-shim.php', true )
);
$inactive_result = json_decode( trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $inactive_probe ) . ' 2>&1' ) ), true );
assert_same( array( true, false, 'unavailable', '5.1.4' ), $inactive_result, 'installed-but-inactive: installed true, active false, adapter unavailable, header version read' );

$incompatible_probe = sprintf(
	'define( "THE_SEO_FRAMEWORK_VERSION", "5.0.0" );'
	. ' require %s;'
	. ' $resp = diviops_call( "seo_provider_list", [ new DiviOps_Test_Request( [] ) ] );'
	. ' $p = $resp->get_data()["data"]["providers"][0];'
	. ' echo json_encode( [ $p["active"], $p["compatible"], $p["adapter"] ] );',
	var_export( __DIR__ . '/tsf-shim.php', true )
);
$incompatible_result = json_decode( trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $incompatible_probe ) . ' 2>&1' ) ), true );
assert_same( array( true, false, 'unavailable' ), $incompatible_result, 'active but below MIN_VERSION: active true, compatible false, adapter unavailable' );

// ── seo_metadata_get ─────────────────────────────────────────────────────────

diviops_seo_reset();

// (1) Invalid post id.
$resp = diviops_call( 'seo_metadata_get', array( diviops_seo_request( array( 'id' => 0 ) ) ) );
$data = $resp->get_data();
assert_true( false === $data['ok'], 'post_id 0 is rejected' );
assert_same( 'invalid_input', $data['error']['code'], 'post_id 0 is invalid_input' );
assert_same( 400, $resp->get_status(), 'invalid post_id carries HTTP 400' );

// (2) Missing post.
$resp = diviops_call( 'seo_metadata_get', array( diviops_seo_request( array( 'id' => 4242 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'a missing post is not_found' );
assert_same( 404, $resp->get_status(), 'not_found carries HTTP 404' );

// (3) Forbidden: edit_post denied for this specific post.
diviops_test_register_post( 100, 'content', 'page', 'Page 100' );
$GLOBALS['diviops_test_uneditable_ids'] = array( 100 );
$resp = diviops_call( 'seo_metadata_get', array( diviops_seo_request( array( 'id' => 100 ) ) ) );
$data = $resp->get_data();
assert_same( 'forbidden', $data['error']['code'], 'edit_post denial is forbidden' );
assert_same( 403, $resp->get_status(), 'forbidden carries HTTP 403' );
assert_true( false === $data['error']['data']['payload_exposed'], 'a forbidden read never claims payload exposure' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// (4) Provider absent (not installed): seo.provider_absent, 412.
$GLOBALS['diviops_test_options']['active_plugins'] = array();
$resp = diviops_call( 'seo_metadata_get', array( diviops_seo_request( array( 'id' => 100 ) ) ) );
$data = $resp->get_data();
assert_same( 'seo.provider_absent', $data['error']['code'], 'no installed provider is seo.provider_absent' );
assert_same( 412, $resp->get_status(), 'provider_absent carries HTTP 412' );
diviops_seo_reset();
diviops_test_register_post( 100, 'content', 'page', 'Page 100' );

// (5) Unsupported post type for the active provider.
$GLOBALS['diviops_test_tsf_supported_post_type'] = false;
$resp = diviops_call( 'seo_metadata_get', array( diviops_seo_request( array( 'id' => 100 ) ) ) );
$data = $resp->get_data();
assert_same( 'seo.post_type_unsupported', $data['error']['code'], 'an unsupported post type is refused' );
assert_same( 422, $resp->get_status(), 'post_type_unsupported carries HTTP 422' );
$GLOBALS['diviops_test_tsf_supported_post_type'] = true;

// (6) Successful read: shape, explicit/effective values, checksum, six fields.
$GLOBALS['diviops_test_tsf_effective'] = array(
	'seo_title'           => 'Effective Title',
	'meta_description'    => 'Effective description.',
	'og_title'            => 'Effective OG Title',
	'og_description'      => 'Effective OG description.',
	'twitter_title'       => 'Effective Twitter Title',
	'twitter_description' => 'Effective Twitter description.',
);
$resp = diviops_call( 'seo_metadata_get', array( diviops_seo_request( array( 'id' => 100 ) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a supported, editable, existing post reads successfully' );
$payload = $data['data'];
assert_same( 100, $payload['post']['id'], 'the response echoes the post id' );
assert_same( 6, count( $payload['fields'] ), 'six semantic fields are reported' );
$field_names = array_column( $payload['fields'], 'field' );
sort( $field_names );
assert_same(
	array( 'meta_description', 'og_description', 'og_title', 'seo_title', 'twitter_description', 'twitter_title' ),
	$field_names,
	'the field set is exactly the six semantic fields, alphabetized for comparison'
);
foreach ( $payload['fields'] as $row ) {
	assert_true( false === $row['explicit'], "field {$row['field']} starts with no explicit value stored" );
	assert_same( '', $row['stored_value'], "field {$row['field']} starts with an empty stored value" );
}
$by_field = array_column( $payload['fields'], null, 'field' );
assert_same( 'Effective OG Title', $by_field['og_title']['effective_value'], 'og_title effective_value comes from tsf()->open_graph()->get_title()' );
assert_same( 'Effective OG description.', $by_field['og_description']['effective_value'], 'og_description effective_value comes from tsf()->open_graph()->get_description()' );
assert_same( 'Effective Twitter Title', $by_field['twitter_title']['effective_value'], 'twitter_title effective_value comes from tsf()->twitter()->get_title()' );
assert_same( 'Effective Twitter description.', $by_field['twitter_description']['effective_value'], 'twitter_description effective_value comes from tsf()->twitter()->get_description()' );
assert_same( 1, preg_match( '/^sha256:[a-f0-9]{64}$/', $payload['checksum'] ), 'checksum has the documented sha256: shape' );

// ── seo_metadata_update: request validation ─────────────────────────────────

diviops_seo_reset();
diviops_test_register_post( 200, 'content', 'page', 'Page 200' );
$checksum = diviops_seo_checksum( 200 );

function diviops_seo_update( array $overrides = array() ) {
	// Uses seo_title, not one of the new fields: these cases exercise request-shape
	// validation (checksum format, changes-list shape, duplicate/unknown-field
	// handling) that is orthogonal to which semantic field is named, so the default
	// must be a field the allowlist already supports.
	$base = array(
		'id'                => 200,
		'expected_checksum' => diviops_seo_checksum( 200 ),
		'changes'           => array( array( 'field' => 'seo_title', 'action' => 'set', 'value' => 'New Title' ) ),
	);
	return diviops_call( 'seo_metadata_update', array( diviops_seo_request( array_merge( $base, $overrides ) ) ) );
}

// (7) Malformed expected_checksum.
$resp = diviops_seo_update( array( 'expected_checksum' => 'not-a-checksum' ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a malformed checksum is invalid_input' );
assert_same( 'expected_checksum', $data['error']['data']['field'], 'the error names the checksum field' );

// (8) changes must be a non-empty list of at most two.
$resp = diviops_seo_update( array( 'changes' => array() ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'an empty changes list is invalid_input' );

$resp = diviops_seo_update( array( 'changes' => array(
	array( 'field' => 'og_title', 'action' => 'clear' ),
	array( 'field' => 'og_description', 'action' => 'clear' ),
	array( 'field' => 'twitter_title', 'action' => 'clear' ),
) ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'more than two changes is invalid_input' );

// (9) A change entry must be an object, not a list.
$resp = diviops_seo_update( array( 'changes' => array( array( 'og_title', 'set', 'x' ) ) ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'a positional-array change entry is invalid_input' );

// (10) Field outside the allowlist.
$resp = diviops_seo_update( array( 'changes' => array( array( 'field' => 'canonical', 'action' => 'set', 'value' => 'x' ) ) ) );
$data = $resp->get_data();
assert_same( 'seo.field_unsupported', $data['error']['code'], 'a non-allowlisted field is seo.field_unsupported' );
assert_same(
	array( 'meta_description', 'og_description', 'og_title', 'seo_title', 'twitter_description', 'twitter_title' ),
	( function ( $allowed ) { sort( $allowed ); return $allowed; } )( $data['error']['data']['allowed'] ),
	'the error reports the full six-field allowlist'
);

// (11) Duplicate operation for the same field.
$resp = diviops_seo_update( array( 'changes' => array(
	array( 'field' => 'og_title', 'action' => 'set', 'value' => 'a' ),
	array( 'field' => 'og_title', 'action' => 'clear' ),
) ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'two operations on the same field is invalid_input' );

// (12) Action must be set or clear.
$resp = diviops_seo_update( array( 'changes' => array( array( 'field' => 'og_title', 'action' => 'delete' ) ) ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'an unknown action is invalid_input' );

// (13) set requires value; clear forbids it.
$resp = diviops_seo_update( array( 'changes' => array( array( 'field' => 'og_title', 'action' => 'set' ) ) ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'set without value is invalid_input' );

$resp = diviops_seo_update( array( 'changes' => array( array( 'field' => 'og_title', 'action' => 'clear', 'value' => 'x' ) ) ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'clear with a value is invalid_input (unknown property)' );

// (14) Checksum drift: a well-formed but wrong checksum is rejected with no mutation.
$wrong = 'sha256:' . str_repeat( '0', 64 );
$resp  = diviops_seo_update( array( 'expected_checksum' => $wrong ) );
$data  = $resp->get_data();
assert_same( 'seo.metadata_drift', $data['error']['code'], 'a well-formed but wrong checksum is seo.metadata_drift' );
assert_same( 409, $resp->get_status(), 'metadata_drift carries HTTP 409' );
assert_true( false === $data['error']['data']['mutated'], 'a drift rejection never mutates' );

// ── seo_metadata_update: plain-text validation, reused per-field ───────────

diviops_seo_reset();
diviops_test_register_post( 300, 'content', 'page', 'Page 300' );

foreach ( array( 'seo_title', 'meta_description', 'og_title', 'og_description', 'twitter_title', 'twitter_description' ) as $field ) {
	$resp = diviops_call( 'seo_metadata_update', array( diviops_seo_request( array(
		'id'                => 300,
		'expected_checksum' => diviops_seo_checksum( 300 ),
		'changes'           => array( array( 'field' => $field, 'action' => 'set', 'value' => "<script>alert(1)</script>" ) ),
	) ) ) );
	$data = $resp->get_data();
	assert_same( 'invalid_input', $data['error']['code'], "markup in {$field} is rejected" );
	assert_same( 'markup', $data['error']['data']['reason'], "{$field} markup rejection reason is 'markup'" );

	$resp = diviops_call( 'seo_metadata_update', array( diviops_seo_request( array(
		'id'                => 300,
		'expected_checksum' => diviops_seo_checksum( 300 ),
		'changes'           => array( array( 'field' => $field, 'action' => 'set', 'value' => '$variable(gcid-abc123)$' ) ),
	) ) ) );
	assert_same( 'seo.dynamic_value_unsupported', $resp->get_data()['error']['code'], "an unresolved dynamic token in {$field} is rejected" );
}

// ── seo_metadata_update: dry_run ────────────────────────────────────────────

diviops_seo_reset();
diviops_test_register_post( 400, 'content', 'page', 'Page 400' );

// (15) Non-noop dry run reports the plan and never mutates.
$resp = diviops_call( 'seo_metadata_update', array( diviops_seo_request( array(
	'id'                => 400,
	'expected_checksum' => diviops_seo_checksum( 400 ),
	'changes'           => array( array( 'field' => 'og_title', 'action' => 'set', 'value' => 'Planned Title' ) ),
	'dry_run'           => true,
) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a dry run is a success envelope' );
assert_true( $data['data']['dry_run'], 'the response is flagged dry_run' );
assert_same( 1, count( $data['data']['plan']['changes'] ), 'one planned change is reported' );
assert_same( 'seo.metadata.set', $data['data']['plan']['changes'][0]['kind'], 'the plan kind names the operation' );
assert_true( false === $data['data']['write_applied'], 'a dry run never applies a write' );
assert_true( false === $data['data']['noop'], 'a real change is not reported noop' );
$readback = diviops_call( 'seo_metadata_get', array( diviops_seo_request( array( 'id' => 400 ) ) ) );
$og       = array_values( array_filter( $readback->get_data()['data']['fields'], fn( $r ) => 'og_title' === $r['field'] ) )[0];
assert_true( false === $og['explicit'], 'dry_run never actually mutated the post' );

// (16) Noop dry run.
$resp = diviops_call( 'seo_metadata_update', array( diviops_seo_request( array(
	'id'                => 400,
	'expected_checksum' => diviops_seo_checksum( 400 ),
	'changes'           => array( array( 'field' => 'og_title', 'action' => 'clear' ) ),
	'dry_run'           => true,
) ) ) );
assert_true( $resp->get_data()['data']['noop'], 'clearing an already-unset field is a noop dry run' );

// ── seo_metadata_update: real writes, readback, and rollback ───────────────

diviops_seo_reset();
diviops_test_register_post( 500, 'content', 'page', 'Page 500' );

// (17) Real noop update (no dry_run) short-circuits without touching storage.
$resp = diviops_call( 'seo_metadata_update', array( diviops_seo_request( array(
	'id'                => 500,
	'expected_checksum' => diviops_seo_checksum( 500 ),
	'changes'           => array( array( 'field' => 'twitter_title', 'action' => 'clear' ) ),
) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'a real noop still succeeds' );
assert_true( $data['data']['noop'], 'a real noop is flagged noop' );
assert_true( false === $data['data']['write_applied'], 'a noop never applies a write' );

// (18) Real set + real clear, one per new field, verified via readback.
foreach ( array( 'og_title', 'og_description', 'twitter_title', 'twitter_description' ) as $field ) {
	$resp = diviops_call( 'seo_metadata_update', array( diviops_seo_request( array(
		'id'                => 500,
		'expected_checksum' => diviops_seo_checksum( 500 ),
		'changes'           => array( array( 'field' => $field, 'action' => 'set', 'value' => "Value for {$field}" ) ),
	) ) ) );
	$data = $resp->get_data();
	assert_true( $data['ok'], "setting {$field} succeeds" );
	assert_true( $data['data']['write_applied'], "setting {$field} applies a write" );
	assert_true( $data['data']['readback_verified'], "setting {$field} verifies readback" );
	$row = array_values( array_filter( $data['data']['fields'], fn( $r ) => $field === $r['field'] ) )[0];
	assert_true( $row['explicit'], "{$field} is explicit after set" );
	assert_same( "Value for {$field}", $row['stored_value'], "{$field} stores the sanitized value" );

	$resp = diviops_call( 'seo_metadata_update', array( diviops_seo_request( array(
		'id'                => 500,
		'expected_checksum' => diviops_seo_checksum( 500 ),
		'changes'           => array( array( 'field' => $field, 'action' => 'clear' ) ),
	) ) ) );
	$row = array_values( array_filter( $resp->get_data()['data']['fields'], fn( $r ) => $field === $r['field'] ) )[0];
	assert_true( false === $row['explicit'], "{$field} is no longer explicit after clear" );
	assert_same( '', $row['stored_value'], "{$field} stored value is empty after clear" );
}

// (19) Set og_description so the next case has a real value to prove survives
// an unrelated write untouched (the set+clear loop above left every field
// cleared again by the time it finished).
diviops_call( 'seo_metadata_update', array( diviops_seo_request( array(
	'id'                => 500,
	'expected_checksum' => diviops_seo_checksum( 500 ),
	'changes'           => array( array( 'field' => 'og_description', 'action' => 'set', 'value' => 'Sibling Check Value' ) ),
) ) ) );

// (20) Two fields in one request (the max allowed), including one old + one new field.
$resp = diviops_call( 'seo_metadata_update', array( diviops_seo_request( array(
	'id'                => 500,
	'expected_checksum' => diviops_seo_checksum( 500 ),
	'changes'           => array(
		array( 'field' => 'seo_title', 'action' => 'set', 'value' => 'Combined Title' ),
		array( 'field' => 'og_title', 'action' => 'set', 'value' => 'Combined OG Title' ),
	),
) ) ) );
$data = $resp->get_data();
assert_true( $data['ok'], 'two operations across old and new fields succeed together' );
assert_same( 2, count( $data['data']['changes'] ), 'both changes are reported' );
$by_field = array_column( $data['data']['fields'], null, 'field' );
assert_same( 'Combined Title', $by_field['seo_title']['stored_value'], 'seo_title updated' );
assert_same( 'Combined OG Title', $by_field['og_title']['stored_value'], 'og_title updated in the same request' );

// (21) A field NOT touched by this request keeps its prior stored value --
// proves update_single_meta_item's real full-default-key rewrite (modeled in
// tests/tsf-shim.php) does not corrupt sibling semantic fields even though it
// rewrites every default meta key on every single-field write.
assert_same( 'Sibling Check Value', $by_field['og_description']['stored_value'], 'og_description set two assertions ago survives an unrelated write untouched' );

// ── seo_metadata_update: provider write failure triggers rollback ──────────

diviops_seo_reset();
diviops_test_register_post( 600, 'content', 'page', 'Page 600' );

// Seed an explicit value so the rollback has a real prior state to restore to.
diviops_call( 'seo_metadata_update', array( diviops_seo_request( array(
	'id'                => 600,
	'expected_checksum' => diviops_seo_checksum( 600 ),
	'changes'           => array( array( 'field' => 'og_title', 'action' => 'set', 'value' => 'Original OG Title' ) ),
) ) ) );

// (21) The provider throws mid-write: DiviOps restores and verifies the prior state.
// The seam matches on the PROVIDER meta key ('_open_graph_title'), not the
// semantic field name ('og_title') -- write_field() passes FIELD_KEYS[$field]
// to update_single_meta_item(), the same provider-key boundary the real TSF
// storage layer itself only ever sees.
$GLOBALS['diviops_test_tsf_write_throws'] = '_open_graph_title';
$resp = diviops_call( 'seo_metadata_update', array( diviops_seo_request( array(
	'id'                => 600,
	'expected_checksum' => diviops_seo_checksum( 600 ),
	'changes'           => array( array( 'field' => 'og_title', 'action' => 'set', 'value' => 'Attempted New Title' ) ),
) ) ) );
$data = $resp->get_data();
assert_true( false === $data['ok'], 'a provider write failure is reported as an error envelope' );
assert_same( 'seo.readback_mismatch', $data['error']['code'], 'a caught write exception is reported as a readback mismatch after rollback' );
assert_true( $data['error']['data']['rollback']['verified'], 'the rollback restores and verifies the prior state' );
$GLOBALS['diviops_test_tsf_write_throws'] = null;
$readback = diviops_call( 'seo_metadata_get', array( diviops_seo_request( array( 'id' => 600 ) ) ) );
$row      = array_values( array_filter( $readback->get_data()['data']['fields'], fn( $r ) => 'og_title' === $r['field'] ) )[0];
assert_same( 'Original OG Title', $row['stored_value'], 'the post keeps its original value after the failed write is rolled back' );

// (22) The provider "succeeds" but the persisted value does not match the
// requested state: DiviOps detects the mismatch on readback and rolls back.
$GLOBALS['diviops_test_tsf_write_corrupt_field'] = '_open_graph_title';
$resp = diviops_call( 'seo_metadata_update', array( diviops_seo_request( array(
	'id'                => 600,
	'expected_checksum' => diviops_seo_checksum( 600 ),
	'changes'           => array( array( 'field' => 'og_title', 'action' => 'set', 'value' => 'Attempted New Title' ) ),
) ) ) );
$data = $resp->get_data();
assert_same( 'seo.readback_mismatch', $data['error']['code'], 'a silently-wrong persisted value is caught by readback verification' );
assert_true( $data['error']['data']['rollback']['verified'], 'the rollback restores and verifies the prior state after a readback mismatch' );
$GLOBALS['diviops_test_tsf_write_corrupt_field'] = null;
$readback = diviops_call( 'seo_metadata_get', array( diviops_seo_request( array( 'id' => 600 ) ) ) );
$row      = array_values( array_filter( $readback->get_data()['data']['fields'], fn( $r ) => 'og_title' === $r['field'] ) )[0];
assert_same( 'Original OG Title', $row['stored_value'], 'the post keeps its original value after the readback-mismatch write is rolled back' );

diviops_seo_reset();
