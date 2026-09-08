<?php
// SPDX-License-Identifier: MIT
/**
 * The optional exact-checksum guard on page_update_content (#391).
 *
 * This is not characterization — the behaviour is new here. It is adopted from
 * upstream `oaris-dev/diviops@e4427a8` (release v1.5.55, plugin 1.5.16), which
 * this fork diverged before. The contract is fixed from the outside rather
 * than chosen: DiviOps Agent Pro's campaign controller sends the parameter and
 * refuses to run at all unless the Free plugin advertises
 * `page_update_content_expected_checksum`, so the parameter name, the
 * `sha256:` + lowercase-hex-64 shape, the hash input (the exact post_content
 * bytes, unnormalized) and the `page.content_drift` refusal code all have to
 * agree byte for byte or the guard refuses every legitimate write. Each was
 * read from Pro's own controller on the staging install before this was
 * written, not inferred.
 *
 * Covered here:
 *
 *   - page_get emits content_checksum over the exact post_content bytes.
 *   - Omitting expected_checksum preserves the legacy unconditional write.
 *   - A non-string, a malformed string and an uppercase-hex string are each
 *     refused as invalid_input before anything is written.
 *   - A stale checksum refuses with page.content_drift at HTTP 409, reporting
 *     both checksums and mutated:false, and leaves post_content untouched.
 *   - dry_run is refused on a stale checksum too, so a caller cannot plan
 *     against content it has not reviewed.
 *   - A matching checksum writes.
 *   - The pre-write re-read refuses when the stored bytes have moved since the
 *     handler loaded its post object, which is the case the entry check cannot
 *     see and the only reason the second check exists.
 *   - The capability key is advertised, and the route declares the parameter.
 *
 * NOT covered: whether a real `$wpdb` object cache actually serves a stale
 * post object mid-request. That is a WordPress property, not this plugin's,
 * and the harness models the divergence directly instead — see
 * tests/page-checksum-wpdb-stub.php, whose `$forced` map is what a concurrent
 * editor looks like from inside the handler.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/page-checksum-wpdb-stub.php';

$GLOBALS['diviops_pec_fixture_ids'] = array();

$diviops_pec_wpdb = diviops_pcw_install();

/**
 * Register a fixture page and remember it for teardown.
 *
 * @param int    $id      Post id.
 * @param string $content post_content.
 * @return object The registered post.
 */
function diviops_pec_post( int $id, string $content ) {
	$GLOBALS['diviops_pec_fixture_ids'][] = $id;
	$post                                 = diviops_test_register_post( $id, $content, 'page', 'Checksum Fixture' );
	$post->post_status       = 'publish';
	$post->post_name         = 'checksum-fixture';
	$post->post_modified     = '2026-01-02 03:04:05';
	$post->post_modified_gmt = '2026-01-02 03:04:05';
	$post->post_date         = '2026-01-01 00:00:00';
	$post->post_date_gmt     = '2026-01-01 00:00:00';
	return $post;
}

/**
 * Invoke a handler with a request built from the given params.
 *
 * @param string $method Handler name on DiviOps_Agent.
 * @param array  $params Request params.
 * @return mixed WP_REST_Response.
 */
function diviops_pec_call( string $method, array $params = array() ) {
	return diviops_call( $method, array( new DiviOps_Test_Request( $params ) ) );
}

/**
 * The checksum shape both sides of the contract agree on.
 *
 * @param string $content Raw post_content.
 * @return string
 */
function diviops_pec_checksum( string $content ): string {
	return 'sha256:' . hash( 'sha256', $content );
}

/**
 * The stored post_content for a fixture id.
 *
 * @param int $id Post id.
 * @return string
 */
function diviops_pec_stored( int $id ): string {
	return (string) $GLOBALS['diviops_test_posts'][ $id ]->post_content;
}

$diviops_pec_divi = '<!-- wp:divi/section {"attrs":{}} --><!-- wp:divi/row {"attrs":{}} --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
$diviops_pec_next = '<!-- wp:divi/section {"attrs":{"module":{}}} --><!-- /wp:divi/section -->';

// ══ page_get emits the binding ════════════════════════════════════════════

diviops_pec_post( 7801, $diviops_pec_divi );
$diviops_pec_body = diviops_pec_call( 'page_get', array( 'id' => 7801 ) )->get_data();

assert_same(
	diviops_pec_checksum( $diviops_pec_divi ),
	$diviops_pec_body['data']['content_checksum'],
	'page_get returns content_checksum over the exact post_content bytes'
);
assert_same(
	$diviops_pec_divi,
	$diviops_pec_body['data']['content_raw'],
	'control: the bytes hashed are the same bytes content_raw reports'
);

// ══ Omission preserves the legacy contract ════════════════════════════════

diviops_pec_post( 7802, $diviops_pec_divi );
$diviops_pec_body = diviops_pec_call(
	'page_update_content',
	array( 'id' => 7802, 'content' => $diviops_pec_next )
)->get_data();

assert_same( true, $diviops_pec_body['ok'], 'a write with no expected_checksum still succeeds' );
assert_same( $diviops_pec_next, diviops_pec_stored( 7802 ), 'and the content is written' );

// ══ Shape refusals, all before any write ══════════════════════════════════

diviops_pec_post( 7803, $diviops_pec_divi );
$diviops_pec_body = diviops_pec_call(
	'page_update_content',
	array( 'id' => 7803, 'content' => $diviops_pec_next, 'expected_checksum' => 42 )
)->get_data();

assert_same( false, $diviops_pec_body['ok'], 'a non-string expected_checksum is refused' );
assert_same( 'invalid_input', $diviops_pec_body['error']['code'], 'as invalid_input' );
assert_same( 'expected_checksum', $diviops_pec_body['error']['data']['field'], 'naming the field' );
assert_same( false, $diviops_pec_body['error']['data']['mutated'], 'and reporting mutated:false' );
assert_same( $diviops_pec_divi, diviops_pec_stored( 7803 ), 'and nothing was written' );

$diviops_pec_body = diviops_pec_call(
	'page_update_content',
	array( 'id' => 7803, 'content' => $diviops_pec_next, 'expected_checksum' => 'sha256:nothex' )
)->get_data();
assert_same( 'invalid_input', $diviops_pec_body['error']['code'], 'a malformed checksum string is invalid_input' );

$diviops_pec_body = diviops_pec_call(
	'page_update_content',
	array(
		'id'                => 7803,
		'content'           => $diviops_pec_next,
		'expected_checksum' => 'sha256:' . strtoupper( hash( 'sha256', $diviops_pec_divi ) ),
	)
)->get_data();
assert_same(
	'invalid_input',
	$diviops_pec_body['error']['code'],
	'uppercase hex is invalid_input, because Pro sends lowercase and the pattern is the contract'
);
assert_same( $diviops_pec_divi, diviops_pec_stored( 7803 ), 'control: none of the three shape refusals wrote' );

// ══ Drift at the entry check ══════════════════════════════════════════════

diviops_pec_post( 7804, $diviops_pec_divi );
$diviops_pec_stale = diviops_pec_checksum( 'content the caller reviewed a while ago' );
$diviops_pec_resp  = diviops_pec_call(
	'page_update_content',
	array( 'id' => 7804, 'content' => $diviops_pec_next, 'expected_checksum' => $diviops_pec_stale )
);
$diviops_pec_body  = $diviops_pec_resp->get_data();

assert_same( 409, $diviops_pec_resp->get_status(), 'a stale checksum refuses at HTTP 409' );
assert_same( 'page.content_drift', $diviops_pec_body['error']['code'], 'with the code Pro matches on' );
assert_same( $diviops_pec_stale, $diviops_pec_body['error']['data']['expected_checksum'], 'echoing what the caller sent' );
assert_same(
	diviops_pec_checksum( $diviops_pec_divi ),
	$diviops_pec_body['error']['data']['current_checksum'],
	'alongside the checksum it would have to re-read to proceed'
);
assert_same( false, $diviops_pec_body['error']['data']['mutated'], 'and mutated:false' );
assert_same( $diviops_pec_divi, diviops_pec_stored( 7804 ), 'and the page is untouched' );

// A dry run is a plan the caller acts on, so it is refused on stale content
// too rather than describing a write against bytes nobody reviewed.
$diviops_pec_body = diviops_pec_call(
	'page_update_content',
	array(
		'id'                => 7804,
		'content'           => $diviops_pec_next,
		'expected_checksum' => $diviops_pec_stale,
		'dry_run'           => true,
	)
)->get_data();
assert_same( 'page.content_drift', $diviops_pec_body['error']['code'], 'dry_run is refused on a stale checksum too' );

// ══ A matching checksum writes ════════════════════════════════════════════

diviops_pec_post( 7805, $diviops_pec_divi );
$diviops_pec_body = diviops_pec_call(
	'page_update_content',
	array(
		'id'                => 7805,
		'content'           => $diviops_pec_next,
		'expected_checksum' => diviops_pec_checksum( $diviops_pec_divi ),
	)
)->get_data();

assert_same( true, $diviops_pec_body['ok'], 'a matching checksum writes' );
assert_same( $diviops_pec_next, diviops_pec_stored( 7805 ), 'and the new content is stored' );

// ══ The pre-write re-read ═════════════════════════════════════════════════
//
// The entry check compares against the post object the handler already loaded.
// This one goes back to the row. Forcing the stored bytes apart from that
// object is the only way to tell the two checks apart, and it is exactly the
// case the second check exists for: a caller whose review binding matched on
// entry, against a row another request has since moved.

diviops_pec_post( 7806, $diviops_pec_divi );
$diviops_pec_wpdb->forced[7806] = 'someone else committed this while we were validating';
$diviops_pec_resp = diviops_pec_call(
	'page_update_content',
	array(
		'id'                => 7806,
		'content'           => $diviops_pec_next,
		'expected_checksum' => diviops_pec_checksum( $diviops_pec_divi ),
	)
);
$diviops_pec_body = $diviops_pec_resp->get_data();

assert_same( 409, $diviops_pec_resp->get_status(), 'a binding that passes entry and fails at the row refuses at 409' );
assert_same( 'page.content_drift', $diviops_pec_body['error']['code'], 'as content drift' );
assert_same(
	diviops_pec_checksum( 'someone else committed this while we were validating' ),
	$diviops_pec_body['error']['data']['current_checksum'],
	'reporting the stored bytes, not the ones the handler had loaded'
);
assert_same( $diviops_pec_divi, diviops_pec_stored( 7806 ), 'and the write did not happen' );

// The same re-read on an unmoved row is the pass-through the happy path above
// already depends on; assert it explicitly so a broken query shape cannot go
// unnoticed behind a fallback.
unset( $diviops_pec_wpdb->forced[7806] );
$diviops_pec_body = diviops_pec_call(
	'page_update_content',
	array(
		'id'                => 7806,
		'content'           => $diviops_pec_next,
		'expected_checksum' => diviops_pec_checksum( $diviops_pec_divi ),
	)
)->get_data();
assert_same( true, $diviops_pec_body['ok'], 'and the identical request succeeds once the row agrees again' );
assert_true(
	count( $diviops_pec_wpdb->queries ) > 0,
	'control: the re-read really issued a query, so the assertions above are not passing on a skipped branch'
);

// ══ The advertised contract ═══════════════════════════════════════════════
//
// A capability key that advertises a behaviour the code does not perform is
// worse than a missing one: Pro gates on this key alone and has no other way
// to discover the guard is absent.

assert_true(
	in_array( 'page_update_content_expected_checksum', DiviOps_Agent::CAPABILITIES, true ),
	'CAPABILITIES advertises page_update_content_expected_checksum'
);
assert_true(
	in_array( 'page_update_content', DiviOps_Agent::CAPABILITIES, true ),
	'control: the tool-name key it sits beside is still there'
);

$diviops_pec_source = file_get_contents( __DIR__ . '/../plugins/diviops-agent/diviops-agent.php' );
assert_true(
	false !== strpos( $diviops_pec_source, "'pattern'  => '^sha256:[a-f0-9]{64}\$'" ),
	'the route declares the checksum pattern, so WordPress refuses a malformed value before the handler runs'
);

// ══ Teardown ══════════════════════════════════════════════════════════════

foreach ( $GLOBALS['diviops_pec_fixture_ids'] as $diviops_pec_id ) {
	unset(
		$GLOBALS['diviops_test_posts'][ $diviops_pec_id ],
		$GLOBALS['diviops_test_post_meta'][ $diviops_pec_id ],
		$GLOBALS['diviops_test_post_meta_rows'][ $diviops_pec_id ]
	);
}
diviops_pcw_restore();

assert_same( null, $GLOBALS['diviops_test_posts'][7801] ?? null, 'teardown removed this file\'s fixture posts' );
assert_same( null, $GLOBALS['diviops_test_posts'][7806] ?? null, 'including the last of them' );
assert_same(
	false,
	$GLOBALS['wpdb'] instanceof DiviOps_PageChecksum_Wpdb,
	'and put the shim\'s own $wpdb back, so no later file takes a branch this one opened'
);
