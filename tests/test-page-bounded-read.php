<?php
// SPDX-License-Identifier: MIT
/**
 * Optional bounded page-content reads (#516).
 *
 * This is not characterization — the behaviour is new here. It is adopted from
 * upstream `oaris-dev/diviops@e1a4d59` (release v1.5.66, plugin 1.5.26), which
 * this fork diverged before.
 *
 * Unlike #391's checksum guard, nothing external refuses us for lacking this:
 * Pro 1.0.16-beta never mentions `page_get_bounded_utf8_v1`, and upstream's own
 * server returns a plain `capability_missing` rather than dropping the tool. It
 * is adopted because the read genuinely fails without it — measured on staging
 * 2026-09-27, `page_get` cannot return 6 of 88 Divi records, the client's token
 * cap rejecting the response past roughly 85 KB of post_content. The parameter
 * names, the `sha256:` whole-content checksum, the refusal codes and the
 * chunk-boundary rule are nonetheless taken verbatim from upstream so a client
 * written against either plugin behaves identically.
 *
 * Covered here:
 *
 *   - The default read is untouched: no bounded params, full content_raw, and
 *     none of the chunk fields appear.
 *   - offset or expected_checksum without bounded:true is invalid_input (400).
 *   - A bounded read of content shorter than one chunk completes in one call.
 *   - Content longer than one chunk reports complete:false and a next_offset,
 *     and the whole sequence reassembles to the original bytes exactly.
 *   - The checksum is over the WHOLE content, not the chunk — which is the only
 *     reason drift is detectable at all.
 *   - A continuation with no expected_checksum is refused (400), so a caller
 *     cannot walk a page it never pinned.
 *   - A stale expected_checksum refuses with page.content_drift (409) and
 *     returns no bytes. There is deliberately no force path.
 *   - Invalid UTF-8 refuses with page.invalid_encoding (422) and returns no
 *     bytes, rather than emitting a broken chunk.
 *   - An offset landing mid-character is refused (400).
 *   - A multibyte character straddling the chunk end walks the end back off the
 *     continuation bytes, so every chunk is independently valid UTF-8.
 *   - An offset past total_bytes is refused (400); an offset exactly equal to
 *     total_bytes is the legitimate empty terminal chunk.
 *   - The capability key is advertised and the route declares the parameters.
 *   - The PHP chunk size and the server's text limit are a coherent pair: the
 *     limit must exceed the chunk size times the worst-case JSON escaping
 *     factor across both escaping layers. Raising one alone is the failure this
 *     assertion exists to catch.
 *
 * NOT covered: that the client's token cap is what rejects an oversized
 * response. That is a property of the MCP client, not of this plugin, and it is
 * recorded as the measured motivation above rather than asserted here. Also not
 * covered: plugin memory. The handler still reads and hashes the full content,
 * so this bounds the RESPONSE and upstream says so too.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$GLOBALS['diviops_pbr_fixture_ids'] = array();

/**
 * Register a fixture page and remember it for teardown.
 *
 * @param int    $id      Post id.
 * @param string $content post_content.
 * @return object The registered post.
 */
function diviops_pbr_post( int $id, string $content ) {
	$GLOBALS['diviops_pbr_fixture_ids'][] = $id;
	$post                                 = diviops_test_register_post( $id, $content, 'page', 'Bounded Fixture' );
	$post->post_status       = 'publish';
	$post->post_name         = 'bounded-fixture';
	$post->post_modified     = '2026-01-02 03:04:05';
	$post->post_modified_gmt = '2026-01-02 03:04:05';
	return $post;
}

/**
 * Call page_get with the given params.
 *
 * @param array $params Request params.
 * @return mixed WP_REST_Response.
 */
function diviops_pbr_resp( array $params ) {
	return diviops_call( 'page_get', array( new DiviOps_Test_Request( $params ) ) );
}

/**
 * The decoded envelope body for a page_get call.
 *
 * @param array $params Request params.
 * @return array
 */
function diviops_pbr_get( array $params ) {
	return diviops_pbr_resp( $params )->get_data();
}

/**
 * The sha256: checksum of a string, as the contract spells it.
 *
 * Computed here independently of the handler so a mutation to the handler's own
 * helper cannot make the expectation agree with it by construction.
 *
 * @param string $s Bytes.
 * @return string
 */
function diviops_pbr_sum( string $s ): string {
	return 'sha256:' . hash( 'sha256', $s );
}

$pbr_chunk = 4096;

/* 1. The default read is untouched. */

$pbr_plain_content = '<!-- wp:divi/placeholder /-->';
diviops_pbr_post( 82010, $pbr_plain_content );
$pbr_plain = diviops_pbr_get( array( 'id' => 82010 ) );

assert_true( true === ( $pbr_plain['ok'] ?? null ), '#516: a page_get with no bounded params still succeeds' );
assert_same( $pbr_plain_content, $pbr_plain['data']['content_raw'] ?? null, '#516: and returns the whole content, because the default read is not bounded' );
assert_true( ! array_key_exists( 'chunk_bytes', $pbr_plain['data'] ?? array() ), '#516: the unbounded response carries no chunk_bytes, so an existing caller sees no new shape' );
assert_true( ! array_key_exists( 'next_offset', $pbr_plain['data'] ?? array() ), '#516: nor next_offset' );
assert_true( ! array_key_exists( 'complete', $pbr_plain['data'] ?? array() ), '#516: nor complete' );

/* 2. The bounded params require bounded:true. */

$pbr_offset_resp = diviops_pbr_resp( array( 'id' => 82010, 'offset' => 0 ) );
$pbr_offset_only = $pbr_offset_resp->get_data();
assert_true( false === ( $pbr_offset_only['ok'] ?? null ), '#516: offset without bounded:true is refused' );
assert_same( 'invalid_input', $pbr_offset_only['error']['code'] ?? null, '#516: and refused as invalid_input rather than silently ignored, because silently ignoring it returns a whole page to a caller who asked for a chunk' );
assert_same( 400, $pbr_offset_resp->get_status(), '#516: at HTTP 400' );

$pbr_sum_only = diviops_pbr_get( array( 'id' => 82010, 'expected_checksum' => diviops_pbr_sum( $pbr_plain_content ) ) );
assert_true( false === ( $pbr_sum_only['ok'] ?? null ), '#516: expected_checksum without bounded:true is refused too' );
assert_same( 'invalid_input', $pbr_sum_only['error']['code'] ?? null, '#516: also as invalid_input' );

/* 3. Content shorter than one chunk completes in a single call. */

$pbr_short = diviops_pbr_get( array( 'id' => 82010, 'bounded' => true ) );
assert_true( true === ( $pbr_short['ok'] ?? null ), '#516: a bounded read of short content succeeds' );
assert_same( 'utf-8', $pbr_short['data']['encoding'] ?? null, '#516: and declares its encoding, because the contract is UTF-8 specific' );
assert_same( $pbr_plain_content, $pbr_short['data']['content_raw'] ?? null, '#516: the single chunk is the whole content' );
assert_same( strlen( $pbr_plain_content ), $pbr_short['data']['total_bytes'] ?? null, '#516: total_bytes counts BYTES, not characters' );
assert_same( 0, $pbr_short['data']['offset'] ?? null, '#516: the first chunk starts at offset zero' );
assert_same( strlen( $pbr_plain_content ), $pbr_short['data']['chunk_bytes'] ?? null, '#516: chunk_bytes equals the content length when it fits' );
assert_true( array_key_exists( 'next_offset', $pbr_short['data'] ) && null === $pbr_short['data']['next_offset'], '#516: next_offset is present and null on the final chunk, not absent and not the total, so a caller cannot loop forever' );
assert_same( true, $pbr_short['data']['complete'] ?? null, '#516: and complete is true' );
assert_same( diviops_pbr_sum( $pbr_plain_content ), $pbr_short['data']['content_checksum'] ?? null, '#516: the checksum is over the content bytes' );

/* 4. Content longer than one chunk, and full reassembly. */

$pbr_long_content = str_repeat( 'a', ( $pbr_chunk * 2 ) + 17 );
diviops_pbr_post( 82011, $pbr_long_content );
$pbr_long_sum = diviops_pbr_sum( $pbr_long_content );

$pbr_c1 = diviops_pbr_get( array( 'id' => 82011, 'bounded' => true ) );
assert_true( true === ( $pbr_c1['ok'] ?? null ), '#516: the first chunk of a long page succeeds' );
assert_same( $pbr_chunk, $pbr_c1['data']['chunk_bytes'] ?? null, '#516: and is exactly one chunk wide' );
assert_same( false, $pbr_c1['data']['complete'] ?? null, '#516: not complete' );
assert_same( $pbr_chunk, $pbr_c1['data']['next_offset'] ?? null, '#516: next_offset is where the next chunk begins' );
assert_same( strlen( $pbr_long_content ), $pbr_c1['data']['total_bytes'] ?? null, '#516: total_bytes is the whole page, so a caller can size the job up front' );
assert_same( $pbr_long_sum, $pbr_c1['data']['content_checksum'] ?? null, '#516: the checksum is over the WHOLE content and not this chunk, which is the only thing that makes drift detectable' );

$pbr_asm    = (string) ( $pbr_c1['data']['content_raw'] ?? '' );
$pbr_next   = $pbr_c1['data']['next_offset'] ?? null;
$pbr_rounds = 1;
while ( null !== $pbr_next && $pbr_rounds < 20 ) {
	$pbr_step = diviops_pbr_get( array(
		'id'                => 82011,
		'bounded'           => true,
		'offset'            => $pbr_next,
		'expected_checksum' => $pbr_long_sum,
	) );
	if ( true !== ( $pbr_step['ok'] ?? null ) ) {
		break;
	}
	$pbr_asm .= (string) ( $pbr_step['data']['content_raw'] ?? '' );
	$pbr_next = $pbr_step['data']['next_offset'] ?? null;
	++$pbr_rounds;
}
assert_same( 3, $pbr_rounds, '#516: a page of two chunks plus a remainder takes exactly three reads, so the walk neither stalls nor skips' );
assert_same( null, $pbr_next, '#516: the walk terminates with next_offset null' );
assert_same( $pbr_long_content, $pbr_asm, '#516: and the concatenated chunks are byte-identical to the original content, which is the whole point of the feature' );

/* 5. A continuation must pin the content it is walking. */

$pbr_unpinned = diviops_pbr_get( array( 'id' => 82011, 'bounded' => true, 'offset' => $pbr_chunk ) );
assert_true( false === ( $pbr_unpinned['ok'] ?? null ), '#516: a continuation with no expected_checksum is refused' );
assert_same( 'invalid_input', $pbr_unpinned['error']['code'] ?? null, '#516: because a caller who never pinned the content can be handed two halves of two different pages' );

/* 6. Drift refuses, and there is no force path. */

$pbr_drift_resp = diviops_pbr_resp( array(
	'id'                => 82011,
	'bounded'           => true,
	'offset'            => $pbr_chunk,
	'expected_checksum' => diviops_pbr_sum( 'something else entirely' ),
) );
$pbr_drift = $pbr_drift_resp->get_data();
assert_true( false === ( $pbr_drift['ok'] ?? null ), '#516: a stale expected_checksum refuses' );
assert_same( 'page.content_drift', $pbr_drift['error']['code'] ?? null, '#516: under a drift-specific code, so a caller can tell a changed page from a bad request' );
assert_same( 409, $pbr_drift_resp->get_status(), '#516: at HTTP 409' );
assert_true( ! isset( $pbr_drift['data']['content_raw'] ), '#516: and returns no bytes, because mixing chunks across versions is the corruption this refusal prevents' );

$pbr_forced = diviops_pbr_get( array(
	'id'                => 82011,
	'bounded'           => true,
	'offset'            => $pbr_chunk,
	'expected_checksum' => diviops_pbr_sum( 'something else entirely' ),
	'force'             => true,
) );
assert_same( 'page.content_drift', $pbr_forced['error']['code'] ?? null, '#516: force does not exist on this path — drift still refuses, because a forced continuation would splice two versions of a page together' );

/* 7. Invalid UTF-8 refuses outright. */

diviops_pbr_post( 82012, "valid ascii \xC3\x28 then more" );
$pbr_bad_resp = diviops_pbr_resp( array( 'id' => 82012, 'bounded' => true ) );
$pbr_bad_utf8 = $pbr_bad_resp->get_data();
assert_true( false === ( $pbr_bad_utf8['ok'] ?? null ), '#516: content that is not valid UTF-8 refuses a bounded read' );
assert_same( 'page.invalid_encoding', $pbr_bad_utf8['error']['code'] ?? null, '#516: under its own code' );
assert_same( 422, $pbr_bad_resp->get_status(), '#516: at HTTP 422' );
assert_true( ! isset( $pbr_bad_utf8['data']['content_raw'] ), '#516: and returns no bytes at all rather than a chunk a caller cannot decode' );

$pbr_bad_unbounded = diviops_pbr_get( array( 'id' => 82012 ) );
assert_true( true === ( $pbr_bad_unbounded['ok'] ?? null ), '#516: the SAME page still reads unbounded, so the encoding refusal belongs to the bounded contract and is not a new restriction on page_get' );

/* 8 and 9. Character boundaries, both ends. */

$pbr_straddle_content = str_repeat( 'b', $pbr_chunk - 2 ) . "\xE2\x82\xAC" . str_repeat( 'c', 50 );
diviops_pbr_post( 82013, $pbr_straddle_content );
$pbr_straddle_sum = diviops_pbr_sum( $pbr_straddle_content );

$pbr_s1 = diviops_pbr_get( array( 'id' => 82013, 'bounded' => true ) );
assert_true( true === ( $pbr_s1['ok'] ?? null ), '#516: a chunk boundary landing inside a multibyte character still succeeds' );
assert_same( $pbr_chunk - 2, $pbr_s1['data']['chunk_bytes'] ?? null, '#516: the chunk end walks back off the continuation bytes, so it stops short of the full width rather than splitting the character' );
assert_same( 1, preg_match( '//u', (string) ( $pbr_s1['data']['content_raw'] ?? "\xC3" ) ), '#516: and the chunk it returns is independently valid UTF-8' );
assert_same( $pbr_chunk - 2, $pbr_s1['data']['next_offset'] ?? null, '#516: next_offset lands on the multibyte lead byte, which is a legal boundary for the following read' );

$pbr_s2 = diviops_pbr_get( array(
	'id'                => 82013,
	'bounded'           => true,
	'offset'            => $pbr_chunk - 2,
	'expected_checksum' => $pbr_straddle_sum,
) );
assert_true( true === ( $pbr_s2['ok'] ?? null ), '#516: continuing from that boundary succeeds' );
assert_same( 1, preg_match( '//u', (string) ( $pbr_s2['data']['content_raw'] ?? "\xC3" ) ), '#516: and that chunk is valid UTF-8 too, so the character was preserved across the split' );
assert_same( $pbr_straddle_content, (string) ( $pbr_s1['data']['content_raw'] ?? '' ) . (string) ( $pbr_s2['data']['content_raw'] ?? '' ), '#516: the two chunks reassemble to the original bytes, multibyte character intact' );

$pbr_mid = diviops_pbr_get( array(
	'id'                => 82013,
	'bounded'           => true,
	'offset'            => $pbr_chunk - 1,
	'expected_checksum' => $pbr_straddle_sum,
) );
assert_true( false === ( $pbr_mid['ok'] ?? null ), '#516: an offset landing mid-character is refused' );
assert_same( 'invalid_input', $pbr_mid['error']['code'] ?? null, '#516: because honouring it would return bytes that cannot stand alone as UTF-8' );

/* 10. Offsets at and past the end. */

$pbr_past = diviops_pbr_get( array(
	'id'                => 82013,
	'bounded'           => true,
	'offset'            => strlen( $pbr_straddle_content ) + 1,
	'expected_checksum' => $pbr_straddle_sum,
) );
assert_true( false === ( $pbr_past['ok'] ?? null ), '#516: an offset past total_bytes is refused' );
assert_same( 'invalid_input', $pbr_past['error']['code'] ?? null, '#516: as invalid_input, not as an empty success a caller would read as a finished walk' );

$pbr_at_end = diviops_pbr_get( array(
	'id'                => 82013,
	'bounded'           => true,
	'offset'            => strlen( $pbr_straddle_content ),
	'expected_checksum' => $pbr_straddle_sum,
) );
assert_true( true === ( $pbr_at_end['ok'] ?? null ), '#516: an offset exactly at total_bytes is legal, being the terminal empty chunk' );
assert_same( 0, $pbr_at_end['data']['chunk_bytes'] ?? null, '#516: it carries no bytes' );
assert_same( true, $pbr_at_end['data']['complete'] ?? null, '#516: and reports complete' );

/* 11. A malformed checksum is rejected on its shape. */

foreach ( array(
	'no-prefix'  => str_repeat( 'a', 64 ),
	'uppercase'  => 'sha256:' . strtoupper( str_repeat( 'a', 64 ) ),
	'short'      => 'sha256:' . str_repeat( 'a', 63 ),
	'not-string' => 42,
) as $pbr_label => $pbr_bad ) {
	$pbr_r = diviops_pbr_get( array(
		'id'                => 82011,
		'bounded'           => true,
		'offset'            => $pbr_chunk,
		'expected_checksum' => $pbr_bad,
	) );
	assert_same( 'invalid_input', $pbr_r['error']['code'] ?? null, "#516: a {$pbr_label} expected_checksum is refused on its shape before any comparison" );
}

/* 12. The capability key and route parameters are declared. */

$pbr_caps = DiviOps_Agent::CAPABILITIES;
assert_true( in_array( 'page_get_bounded_utf8_v1', $pbr_caps, true ), '#516: the precise capability key is advertised, because a client gates the bounded path on this exact string and a typo removes the feature with no error' );
assert_true( in_array( 'page_get', $pbr_caps, true ), '#516: and the unbounded key is still advertised (control: the membership test can find a key that is there)' );
assert_true( ! in_array( 'page_get_bounded_utf8_v2', $pbr_caps, true ), '#516: control: the membership test does not match a key that is absent' );

$pbr_plugin_src = (string) file_get_contents( __DIR__ . '/../plugins/diviops-agent/diviops-agent.php' );
foreach ( array( 'bounded', 'offset', 'expected_checksum' ) as $pbr_param ) {
	assert_true( false !== strpos( $pbr_plugin_src, "'{$pbr_param}'" ), "#516: the route declares the {$pbr_param} parameter, so WordPress does not drop it before the handler runs" );
}

/* 13. The chunk size and the server's text limit are a coherent pair. */

$pbr_ts = (string) file_get_contents( __DIR__ . '/../diviops-server/src/bounded-page-read.ts' );
assert_true( '' !== $pbr_ts, '#516: the server-side bounded-read module exists and was actually read before anything below is concluded from it' );
assert_same( 1, preg_match( '/BOUNDED_PAGE_TEXT_LIMIT\s*=\s*(\d+)\s*\*\s*1024/', $pbr_ts, $pbr_m ), '#516: the server states its text limit as an explicit KiB multiple' );
$pbr_limit = ( (int) $pbr_m[1] ) * 1024;
assert_same( 1, preg_match( '/BOUNDED_PAGE_CHUNK_BYTES\s*=\s*(\d+)/', $pbr_ts, $pbr_cm ), '#516: and names its chunk size as a single constant rather than repeating the number' );
assert_same( $pbr_chunk, (int) $pbr_cm[1], '#516: the server chunk size equals the PHP chunk size, because two sides disagreeing means every chunk fails validation' );
assert_true( $pbr_limit > $pbr_chunk * 7, '#516: the text limit exceeds the chunk size times the worst-case escaping factor of 7 across both JSON layers, which is the invariant that breaks if either number is raised alone' );

// ══ Teardown ════════════════════════════════════════════════════════════════════════

foreach ( $GLOBALS['diviops_pbr_fixture_ids'] as $pbr_id ) {
	unset(
		$GLOBALS['diviops_test_posts'][ $pbr_id ],
		$GLOBALS['diviops_test_post_meta'][ $pbr_id ],
		$GLOBALS['diviops_test_post_meta_rows'][ $pbr_id ]
	);
}

assert_same( null, $GLOBALS['diviops_test_posts'][82010] ?? null, '#516: teardown removed this file\'s fixture posts' );
assert_same( null, $GLOBALS['diviops_test_posts'][82013] ?? null, '#516: including the last of them' );
