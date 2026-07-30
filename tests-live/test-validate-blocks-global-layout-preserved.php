<?php
/**
 * #99 regression, end-to-end over real HTTP: validate_blocks() must not
 * materialize a divi/global-layout wrapper into the resolved content of the
 * layout it references — it must see and report the wrapper itself.
 *
 * tests/test-parse-blocks-for-write-coverage.php already covers this
 * structurally (validate_blocks() calls parse_blocks_for_write(), not bare
 * parse_blocks()). This file exists for the same reason
 * test-validator-stored-markup.php does for #97: the plain-PHP suite cannot
 * exercise parse_blocks_for_write()'s real Divi-class branch at all (no real
 * Divi install in the shim), so the only way to know materialization
 * actually doesn't happen — not just that the right function name is called
 * — is to submit real global-layout-bearing content to the real REST route.
 *
 * Page 900390 carries two real divi/global-layout wrappers (read-only — see
 * harness.php). Reading it to build a fixture is the point; nothing is ever
 * written back to it.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/harness.php';

$reference_content = live_get_post_content( 900390 );
assert_true(
	substr_count( $reference_content, 'wp:divi/global-layout' ) >= 2,
	'page 900390 actually carries at least 2 divi/global-layout wrappers — otherwise this test would pass for the wrong reason'
);

// ── validate/blocks against inline content, exercising validate_blocks() ──
// directly rather than through a page write ──────────────────────────────
$validated = live_rest_call(
	'POST',
	'diviops/v1/validate/blocks',
	array( 'content' => $reference_content )
);
assert_same( 200, $validated['status'], 'validate/blocks on real global-layout-bearing markup returns HTTP 200' );
assert_true( ! empty( $validated['body']['ok'] ), 'validate/blocks response envelope reports ok:true' );

$errors = $validated['body']['data']['errors'] ?? array();
$gl_errors = array_values( array_filter( $errors, function ( $e ) {
	return 'divi/global-layout' === ( $e['block'] ?? '' );
} ) );
assert_true(
	count( $gl_errors ) > 0,
	'the parsed tree still contains divi/global-layout as its own block — the wrapper was seen, not silently expanded into the referenced layout\'s resolved content'
);

// ── same check via page_id, the path a caller validating an existing page ──
// (rather than submitting content inline) actually takes ──────────────────
$validated_by_id = live_rest_call(
	'POST',
	'diviops/v1/validate/blocks',
	array( 'page_id' => 900390 )
);
assert_same( 200, $validated_by_id['status'], 'validate/blocks by page_id on the real reference page returns HTTP 200' );
$errors_by_id  = $validated_by_id['body']['data']['errors'] ?? array();
$gl_errors_by_id = array_values( array_filter( $errors_by_id, function ( $e ) {
	return 'divi/global-layout' === ( $e['block'] ?? '' );
} ) );
assert_true(
	count( $gl_errors_by_id ) > 0,
	'the same holds when validating by page_id rather than inline content'
);

// ── page 900390 itself is never touched by any of this — read-only calls only ──
$after = live_get_post_content( 900390 );
assert_same(
	$reference_content,
	$after,
	'page 900390 content is byte-identical after both validate calls — this test never wrote to it'
);

echo "PASS: validate-blocks-global-layout-preserved (7 assertions)\n";
