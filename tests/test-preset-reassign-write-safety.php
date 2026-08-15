<?php
/**
 * preset_reassign() write safety (#188).
 *
 * preset_reassign() is the plugin's only site-wide write. It shipped without a
 * rollback snapshot, without the readback/revert integrity guard, without any
 * check that its parse -> serialize round trip preserved the page, reporting a
 * partially failed apply as ok:true, and treating an omitted page_ids as
 * "every page and post on the site, re-resolved at apply time".
 *
 * Coverage here is split the way this harness already splits write-path
 * coverage, because preset_reassign() cannot be driven end to end in it:
 * parse_blocks() is deliberately unshimmed (see test-global-layout-write-guard.php's
 * header and test-parse-blocks-for-write-coverage.php), and this suite does not
 * fake that behavior.
 *
 *   (1) Behavioral tests over the three pure helpers the handler delegates to
 *       (apply-mode target refusal, round-trip drift detection, apply-response
 *       shaping). None of them touches option storage.
 *   (2) One request-level test that the apply-mode target refusal really is
 *       wired into preset_reassign() and reaches the caller as an envelope.
 *       That call returns before the preset registry is read, which is the
 *       only reason it can run here at all: the registry probe goes through
 *       et_get_option(), a Divi option-storage primitive this harness does not
 *       shim (see test-variable-update.php's header for the same boundary —
 *       faking it would mean asserting against a fake persistence layer).
 *   (3) Structural regression guards read from the real method source via
 *       Reflection, in the shape of test-parse-blocks-for-write-coverage.php,
 *       for the wiring inside the per-page loop — that the snapshot is created
 *       AND marked, that the write goes through the integrity guard, and that
 *       no bare wp_update_post() call comes back.
 *
 * What this suite therefore does NOT verify, and what live verification against
 * a real Divi install has to cover: that a real page's snapshot is restorable
 * end to end, that the integrity guard's refusals surface as per-page errors
 * rather than aborting the batch, and the round-trip gate's real-world refusal
 * rate. See the #188 PR description.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Read a method's exact source text via Reflection.
 *
 * Named for this file specifically: every test file in tests/ is require'd into
 * ONE process by tests/run.php, so a shared global name would collide with the
 * identically-purposed helper in test-parse-blocks-for-write-coverage.php,
 * which declares it unguarded.
 */
function preset_reassign_method_source( string $method ): string {
	$reflection = new ReflectionMethod( 'DiviOps_Agent', $method );
	$file       = $reflection->getFileName();
	$start      = $reflection->getStartLine() - 1;
	$length     = $reflection->getEndLine() - $start;
	$lines      = file( $file );
	return implode( '', array_slice( $lines, $start, $length ) );
}

/**
 * Strip comments and strings via the real tokenizer, so a call named only in
 * prose cannot be mistaken for a real one. preset_reassign() documents its own
 * write path in comments, so a text search over its body reports call sites
 * that do not exist.
 */
function preset_reassign_code_only( string $source ): string {
	$out = '';
	foreach ( token_get_all( '<?php ' . $source ) as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
				continue;
			}
			$out .= $token[1];
		} else {
			$out .= $token;
		}
	}
	return $out;
}

/**
 * True when the source really calls $function, ignoring comments and strings.
 * Case-insensitive because PHP function calls are.
 */
function preset_reassign_calls( string $source, string $function ): bool {
	return (bool) preg_match(
		'/(?<!function )\b' . preg_quote( $function, '/' ) . '\(/i',
		preset_reassign_code_only( $source )
	);
}

$reassign_source = preset_reassign_method_source( 'preset_reassign' );

// Sanity checks on the checker itself — a silently broken check would make
// every structural assertion below pass for the wrong reason.
assert_true(
	! preset_reassign_calls( '// bypasses wp_update_post() deliberately', 'wp_update_post' ),
	'preset_reassign_calls(): a call named only in a comment is not counted as a real call'
);
assert_true(
	preset_reassign_calls( '$r = wp_update_post( $args, true );', 'wp_update_post' ),
	'preset_reassign_calls(): a genuine call is counted'
);
assert_true(
	preset_reassign_calls( '$r = WP_Update_Post( $args );', 'wp_update_post' ),
	'preset_reassign_calls(): a case-varied call is counted (PHP calls are case-insensitive)'
);
assert_true(
	'' !== $reassign_source && false !== strpos( $reassign_source, 'preset_reassign' ),
	'preset_reassign_method_source(): the real method body was read, so the guards below inspect something'
);

// ── (1a) apply mode requires page_ids (defect 5) ─────────────────────────
//
// Omitting page_ids used to mean "every publish/draft/private page and post on
// the site", re-resolved at apply time, so an apply could write pages the
// dry-run never showed. Dry-run still scans site-wide; apply must be told what
// to write.

assert_same(
	null,
	diviops_call( 'preset_reassign_apply_target_refusal', array( 'dry-run', null ) ),
	'dry-run without page_ids is not refused — dry-run still scans site-wide'
);
assert_same(
	null,
	diviops_call( 'preset_reassign_apply_target_refusal', array( 'dry-run', array() ) ),
	'dry-run with an empty page_ids array is not refused either'
);
assert_same(
	null,
	diviops_call( 'preset_reassign_apply_target_refusal', array( 'apply', array( 123 ) ) ),
	'apply with page_ids is not refused'
);

/**
 * Assert a failed envelope's code and status.
 *
 * Checks the response type first: a regression that stops refusing returns
 * null, and dereferencing that would abort this file with a fatal and hide
 * every assertion after it.
 */
function preset_reassign_assert_refusal( $response, string $code, ?int $status, string $what ): void {
	if ( ! $response instanceof WP_REST_Response ) {
		assert_true( false, $what . ' returns a response rather than allowing the request through' );
		return;
	}
	$data = $response->get_data();
	assert_same( false, $data['ok'] ?? null, $what . ' is a failed envelope, not a success carrying a nested flag' );
	assert_same( $code, $data['error']['code'] ?? null, $what . ' carries code ' . $code );
	if ( null !== $status ) {
		assert_same( $status, $response->get_status(), $what . ' returns HTTP ' . $status );
	}
}

preset_reassign_assert_refusal(
	diviops_call( 'preset_reassign_apply_target_refusal', array( 'apply', null ) ),
	'preset.apply_requires_page_ids',
	400,
	'apply without page_ids'
);

preset_reassign_assert_refusal(
	diviops_call( 'preset_reassign_apply_target_refusal', array( 'apply', array() ) ),
	'preset.apply_requires_page_ids',
	null,
	'apply with an empty page_ids array — an empty list is not a target set'
);

// ── (1b) the refusal is actually wired into the handler ──────────────────
//
// A pure helper nothing calls proves nothing. This drives the real handler and
// asserts the envelope a caller would receive. It returns before the preset
// registry read, which is why this one request-level call is possible here.

$request = new WP_REST_Request( 'POST', '/diviops/v1/preset/reassign' );
$request->set_param( 'old_uuid', 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa' );
$request->set_param( 'new_uuid', 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb' );
$request->set_param( 'mode', 'apply' );

preset_reassign_assert_refusal(
	diviops_call( 'preset_reassign', array( $request ) ),
	'preset.apply_requires_page_ids',
	400,
	'preset_reassign() itself, on an apply that names no pages, before it reads the preset registry'
);

// ── (1c) round-trip drift detection (defect 3) ───────────────────────────
//
// FORK.md:111 records a real page losing 312 bytes (62,167 -> 61,855) to
// parse_blocks_for_write() -> serialize_blocks(), on a page that parses and
// renders fine. No existing guard catches it: the integrity guard compares
// stored against requested, and a silently normalized re-serialization IS what
// was requested. This helper is the check that does.

$identical = '<!-- wp:divi/section {"builderVersion":"5.9.0"} --><!-- /wp:divi/section -->';
assert_same(
	null,
	diviops_call( 'block_round_trip_drift', array( $identical, $identical ) ),
	'a byte-identical round trip reports no drift'
);

$shorter = '<!-- wp:divi/section {"builderVersion":"5.9.0"} --><!-- /wp:divi/section -->';
$lossy   = '<!-- wp:divi/section --><!-- /wp:divi/section -->';
$loss    = diviops_call( 'block_round_trip_drift', array( $shorter, $lossy ) );
assert_true(
	is_array( $loss ),
	'a round trip that drops bytes reports drift'
);
assert_same(
	strlen( $shorter ),
	$loss['original_bytes'],
	'drift diagnostics report the original byte length'
);
assert_same(
	strlen( $lossy ),
	$loss['reserialized_bytes'],
	'drift diagnostics report the re-serialized byte length'
);
assert_same(
	strlen( $lossy ) - strlen( $shorter ),
	$loss['byte_delta'],
	'drift diagnostics report a signed byte delta, negative when the round trip lost bytes'
);

// Same length, different bytes. A length-only comparison would call this
// identical and let a silently rewritten attribute through.
$same_length_a = '<!-- wp:divi/text {"a":"11"} /-->';
$same_length_b = '<!-- wp:divi/text {"a":"22"} /-->';
assert_same(
	strlen( $same_length_a ),
	strlen( $same_length_b ),
	'the same-length fixtures really are the same length, so the next assertion tests what it claims'
);
assert_true(
	is_array( diviops_call( 'block_round_trip_drift', array( $same_length_a, $same_length_b ) ) ),
	'a round trip that changes bytes without changing length still reports drift'
);
assert_same(
	0,
	diviops_call( 'block_round_trip_drift', array( $same_length_a, $same_length_b ) )['byte_delta'],
	'a same-length difference reports a zero byte delta, and is still drift'
);

// ── (1d) a partial apply is not a success (defect 4) ─────────────────────
//
// The envelope used to be envelope_success() carrying a nested success:false.
// The skill primer tells every caller to branch on the envelope's ok, so a run
// where pages failed read as success.

$clean_payload = array(
	'success'  => true,
	'mode'     => 'apply',
	'old_uuid' => 'aaaa',
	'new_uuid' => 'bbbb',
	'summary'  => array(
		'pages_modified' => 2,
		'errors'         => array(),
		'details'        => array(
			array( 'page_id' => 11, 'snapshot_id' => 'snap_11' ),
			array( 'page_id' => 12, 'snapshot_id' => 'snap_12' ),
		),
	),
);

$clean_response = diviops_call( 'preset_reassign_apply_response', array( $clean_payload ) );
assert_same(
	true,
	$clean_response->get_data()['ok'],
	'an apply with no per-page errors returns ok:true'
);
assert_same(
	200,
	$clean_response->get_status(),
	'a clean apply returns HTTP 200'
);
assert_same(
	true,
	$clean_response->get_data()['data']['success'],
	'a clean apply still carries success:true in its data'
);

$partial_payload                       = $clean_payload;
$partial_payload['summary']['errors']  = array(
	array( 'page_id' => 13, 'title' => 'Broken', 'error' => 'Current user cannot edit this post' ),
);
$partial_payload['summary']['details'] = array(
	array( 'page_id' => 11, 'snapshot_id' => 'snap_11' ),
	array( 'page_id' => 13, 'update_error' => 'Current user cannot edit this post' ),
);

$partial_response = diviops_call( 'preset_reassign_apply_response', array( $partial_payload ) );
$partial_data     = $partial_response->get_data();
assert_same(
	false,
	$partial_data['ok'],
	'an apply where any page failed returns ok:false'
);
assert_same(
	'preset.reassign_partial_failure',
	$partial_data['error']['code'],
	'a partial apply refuses with preset.reassign_partial_failure'
);
assert_same(
	207,
	$partial_response->get_status(),
	'a partial apply returns HTTP 207, since some pages really were written'
);
assert_same(
	$partial_payload['summary']['errors'],
	$partial_data['error']['data']['summary']['errors'],
	'the per-page errors survive into error.data'
);
assert_same(
	'snap_11',
	$partial_data['error']['data']['summary']['details'][0]['snapshot_id'],
	'the snapshot ids of pages that WERE written survive into error.data, so a partial run stays recoverable'
);

// ── (3) per-page write wiring (defects 1, 2, 3) ──────────────────────────
//
// These live inside the per-page loop, past a parse_blocks() call this harness
// does not shim, so they are asserted structurally against the real method
// source — the same approach test-parse-blocks-for-write-coverage.php uses for
// the invariant it guards.

assert_true(
	! preset_reassign_calls( $reassign_source, 'wp_update_post' ),
	'preset_reassign() contains no bare wp_update_post() call — every page write goes through the integrity guard (defect 2)'
);
assert_true(
	preset_reassign_calls( $reassign_source, 'update_post_content_with_integrity_guard' ),
	'preset_reassign() writes through update_post_content_with_integrity_guard(), so each page write gets readback verification and auto-revert (defect 2)'
);
assert_true(
	preset_reassign_calls( $reassign_source, 'rollback_snapshot_create_for_post_write' ),
	'preset_reassign() creates a rollback snapshot before each page write (defect 1)'
);
assert_true(
	preset_reassign_calls( $reassign_source, 'rollback_snapshot_mark_post_write' ),
	'preset_reassign() MARKS the snapshot after a successful write — rollback_snapshot_restore() refuses any snapshot whose after.checksum is empty, so an unmarked snapshot is permanently unrestorable (defect 1)'
);
assert_true(
	preset_reassign_calls( $reassign_source, 'rollback_snapshot_mark_from_write_error' ),
	'preset_reassign() marks the snapshot on a failed write, so a failed page is not left with a snapshot stuck at status created (defect 1)'
);
assert_true(
	preset_reassign_calls( $reassign_source, 'block_round_trip_drift' ),
	'preset_reassign() gates each page on its parse -> serialize round trip being byte-identical before mutating it (defect 3)'
);
assert_true(
	preset_reassign_calls( $reassign_source, 'preset_reassign_apply_response' ),
	'preset_reassign() shapes its apply response through the helper that fails the envelope on any per-page error (defect 4)'
);

echo "PASS: preset-reassign-write-safety (38 assertions)\n";
