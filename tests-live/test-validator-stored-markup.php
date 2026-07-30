<?php
/**
 * #97 regression, end-to-end over real HTTP: the full-content write guard
 * must accept real, already-rendering Divi markup carrying $variable()
 * global-color/global-variable tokens, and must still reject a genuine
 * caller pseudo-escape mistake outside any such wrapper.
 *
 * tests/test-block-attr-pseudo-escape.php already covers this at the unit
 * level via reflection. This file exists because #97 itself was invisible to
 * that style of test until it was reproduced live — the bug was in how
 * normalize_and_validate_divi_markup_before_write() behaves when actually
 * wired behind the real /page/update-content/{id} REST route, on content a
 * real Divi install actually authored, not a hand-built fixture. Reading
 * that route's real behavior is the only way to be sure the fix holds
 * end-to-end, not just at the function that was directly patched.
 *
 * Fixture content is READ from page 900390 (read-only — see harness.php)
 * and written into a scratch page, never back into 900390 itself.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/harness.php';

$reference_content = live_get_post_content( 900390 );
assert_true(
	strlen( $reference_content ) > 10000,
	'page 900390 has substantial real content to draw the fixture from (sanity check on the read itself)'
);
assert_true(
	false !== strpos( $reference_content, '$variable(' ),
	'the reference content actually carries $variable() tokens — otherwise this test would pass for the wrong reason'
);

$scratch_id = live_create_scratch_page( $reference_content, 'DiviOps live-suite #97 validator regression' );

// ── dry_run: validation must pass WITHOUT persisting anything ──
//
// Submits DIFFERENT content than what the page already holds specifically so
// non-persistence is actually observable: dry_run-ing the page's own existing
// content back at itself would make a real accidental write indistinguishable
// from correct no-op behavior (caught in review — the first version of this
// test had exactly that gap).
$dry_run_content = '<!-- wp:divi/text {"module":{"meta":{"adminLabel":{"desktop":{"value":"dry_run probe — must never persist"}}}},"builderVersion":"5.1.1"} /-->';
$dry             = live_rest_call(
	'POST',
	'diviops/v1/page/update-content/' . $scratch_id,
	array(
		'content' => $dry_run_content,
		'dry_run' => true,
	)
);
assert_same( 200, $dry['status'], 'dry_run update-content on real $variable()-bearing markup returns HTTP 200, not the old invalid_input rejection' );
assert_true( ! empty( $dry['body']['ok'] ), 'dry_run response envelope reports ok:true' );
assert_same(
	$reference_content,
	live_get_post_content( $scratch_id ),
	'dry_run actually did not persist — the page still holds its original content, not the dry_run probe content'
);

// ── real write: the full guard, including persistence ──
$real = live_rest_call(
	'POST',
	'diviops/v1/page/update-content/' . $scratch_id,
	array(
		'content' => $reference_content,
	)
);
assert_same( 200, $real['status'], 'real update-content on real $variable()-bearing markup returns HTTP 200' );
assert_true( ! empty( $real['body']['ok'] ), 'real write response envelope reports ok:true' );

$persisted = live_get_post_content( $scratch_id );
assert_true(
	false !== strpos( $persisted, '$variable(' ),
	'the $variable() tokens survived the real write path, not just the validation step'
);

// ── the guard must still reject a genuine caller mistake — proves the #97 ──
// fix narrowed scope rather than disabling the check (mirrors the unit test,
// now over the real route) ──────────────────────────────────────────────
$bad_content = '<!-- wp:divi/text {"module":{"meta":{"adminLabel":{"desktop":{"value":"click u003c here, not a real escape"}}}},"builderVersion":"5.1.1"} /-->';
$bad_scratch_id = live_create_scratch_page( '<!-- wp:divi/text {"builderVersion":"5.1.1"} /-->', 'DiviOps live-suite #97 negative case' );

$rejected = live_rest_call(
	'POST',
	'diviops/v1/page/update-content/' . $bad_scratch_id,
	array(
		'content' => $bad_content,
		'dry_run' => true,
	)
);
assert_same( 400, $rejected['status'], 'a genuine bare pseudo-escape outside any $variable() wrapper is still rejected over the real route' );
assert_same( 'invalid_input', $rejected['body']['error']['code'] ?? null, 'the rejection is still the expected invalid_input error code' );

echo "PASS: validator-stored-markup (10 assertions)\n";
