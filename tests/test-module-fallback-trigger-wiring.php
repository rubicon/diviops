<?php
/**
 * module_get() / module_move() must actually route into the parser fallback
 * when the raw find_block() scanner hits malformed markup, not just when a
 * collector is called directly by reflection.
 *
 * #16 added the parser-backed fallback collectors (collect_readable_divi_blocks(),
 * collect_parser_move_blocks()) and tests/test-global-layout-parser-collectors.php
 * covers their counting logic directly, by handing them an already-parsed
 * parse_blocks()-shaped array via diviops_call_ref(). That proves the collectors
 * are correct once reached. It never proves the *wiring* that decides when to
 * reach them: module_get() and module_move() each call the raw find_block()
 * scanner first, and only fall through to find_block_for_read_with_parser() /
 * move_block_with_parser() when that scanner returns a `parse_error` (module_get)
 * or a `parse_error` with `parser_fallbackable === true` (module_move). If a
 * future change narrowed or broke that condition, no existing test would catch
 * it, and third-party or global-layout targeting on a genuinely malformed page
 * would silently stop falling back (#17).
 *
 * find_block()'s only parse_error path is the missing-closing-tag branch (a
 * container opener whose matching `<!-- /wp:name -->` never appears before
 * EOF), and that branch always sets parser_fallbackable => true. So "malformed
 * markup that find_block() cannot parse" and "malformed markup the parser
 * fallback is reached for" are the same condition in this codebase today.
 *
 * What this file proves, without mocking anything:
 *
 * 1. TRIGGER: malformed markup earlier in the page causes module_get() /
 *    module_move() to detect find_block()'s parse_error and *attempt* the
 *    parser-backed path, rather than returning the raw parse_error to the
 *    caller. find_block_for_read_with_parser() and move_block_with_parser()
 *    both call WordPress's real parse_blocks() as their very first statement,
 *    and this harness deliberately does not shim parse_blocks() (see
 *    tests/wp-shim.php's docblock and #17's own scoping note) — a faithful
 *    reimplementation is a large parser state machine, and a partial one
 *    built only for these malformed-input shapes would be mocking the exact
 *    behavior under test rather than testing it. So the observable proof
 *    available in this harness is that execution reaches parse_blocks() at
 *    all: calling it with no shim throws a plain, catchable PHP `Error`
 *    ("Call to undefined function parse_blocks()"), and that error is only
 *    reachable from inside the fallback functions. Catching that specific
 *    error is proof the wiring routed into the fallback; not catching it (or
 *    catching a different error) would mean the wiring silently changed.
 *
 * 2. NO FALSE POSITIVES: on well-formed content, both handlers resolve via
 *    the raw scanner and return the correct result without ever touching
 *    parse_blocks() (no exception at all).
 *
 * 3. NO FALSE TRIGGER ON UNRELATED ERRORS: find_block()'s `block_not_found`
 *    (label genuinely absent, well-formed page) is not a `parse_error` and
 *    must not route into the fallback either — this pins the condition to
 *    parse_error specifically, not "any error from find_block()".
 *    envelope_from_helper_error() collapses block_not_found onto the
 *    canonical envelope code `not_found` (and, separately, would collapse an
 *    unhandled parse_error onto `divi_error`), so the envelope-level
 *    assertion below checks for `not_found`, not the internal WP_Error code.
 *
 * What remains uncovered and why: whether find_block_for_read_with_parser() /
 * move_block_with_parser() then produce the *correct* result once parse_blocks()
 * actually runs on this malformed markup (right block, right global-layout
 * auto_index per #14) is NOT asserted here — that requires a real parse_blocks(),
 * which this harness does not have. That collector-level correctness (given an
 * already-parsed tree) is what test-global-layout-parser-collectors.php already
 * covers; this file covers the trigger condition that decides whether that code
 * runs at all.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Call a DiviOps_Agent handler and report whether it threw, without letting
 * an unexpected throw crash the rest of the suite. Not a mock of the handler
 * — it is the real call, just with the outcome captured instead of asserted
 * to be one particular way ahead of time.
 *
 * @param string $method Method name, passed through diviops_call().
 * @param array  $args   Positional arguments.
 * @return array{threw:bool,message:string,response:mixed}
 */
function diviops_test_call_capturing_throw( string $method, array $args ) {
	try {
		return array(
			'threw'    => false,
			'message'  => '',
			'response' => diviops_call( $method, $args ),
		);
	} catch ( \Throwable $e ) {
		return array(
			'threw'    => true,
			'message'  => $e->getMessage(),
			'response' => null,
		);
	}
}

// A container opener with no matching closing comment anywhere in the page.
// find_block()'s depth-scan runs to EOF looking for "<!-- /wp:divi/text -->",
// never finds it, and returns the missing_closing_tag parse_error.
$malformed_unterminated_container = implode(
	'',
	array(
		'<!-- wp:divi/text {"module":{"advanced":{"text":{"text":{"desktop":{"value":"Unterminated"}}}}}} -->',
		'<p>Body content with no matching closing comment anywhere on this page.</p>',
	)
);

// ── module_get(): trigger, false-positive, and false-trigger checks ──────

$good_section = '<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Good Section"}}}}} /-->';

// (1) TRIGGER: the well-formed target sits after the malformed block, so
// find_block()'s forward scan hits the unterminated container first and
// returns parse_error before it ever reaches "Good Section".
diviops_test_register_post( 9101, $malformed_unterminated_container . $good_section );

$get_trigger_request = new DiviOps_Test_Request(
	array(
		'id'    => 9101,
		'label' => 'Good Section',
	)
);
$get_trigger = diviops_test_call_capturing_throw( 'module_get', array( $get_trigger_request ) );

assert_true(
	$get_trigger['threw'],
	'module_get() on a page with a leading malformed block does not return the raw parse_error — it attempts the parser fallback'
);
assert_true(
	$get_trigger['threw'] && false !== strpos( $get_trigger['message'], 'parse_blocks' ),
	'module_get() reaches parse_blocks() specifically, confirming find_block_for_read_with_parser() was entered'
);

// (2) NO FALSE POSITIVE: the same target on a well-formed-only page resolves
// via the raw scanner, with no exception at all.
diviops_test_register_post( 9102, $good_section );

$get_baseline_request = new DiviOps_Test_Request(
	array(
		'id'    => 9102,
		'label' => 'Good Section',
	)
);
$get_baseline = diviops_test_call_capturing_throw( 'module_get', array( $get_baseline_request ) );

assert_true( ! $get_baseline['threw'], 'module_get() on well-formed content never touches parse_blocks()' );
if ( ! $get_baseline['threw'] ) {
	$get_baseline_data = $get_baseline['response']->get_data();
	assert_true( ! empty( $get_baseline_data['ok'] ), 'module_get() resolves the well-formed target via the raw scanner' );
	assert_same( 'section', $get_baseline_data['data']['module']['block_type'] ?? null, 'the raw scanner reports the correct block type' );
	assert_same( 'Good Section', $get_baseline_data['data']['module']['admin_label'] ?? null, 'the raw scanner reports the correct admin label' );
}

// (3) NO FALSE TRIGGER: block_not_found is not a parse_error, so a genuinely
// missing label on a well-formed page must not route into the fallback.
diviops_test_register_post( 9103, $good_section );

$get_not_found_request = new DiviOps_Test_Request(
	array(
		'id'    => 9103,
		'label' => 'Does Not Exist',
	)
);
$get_not_found = diviops_test_call_capturing_throw( 'module_get', array( $get_not_found_request ) );

assert_true( ! $get_not_found['threw'], 'a plain block_not_found error must not route into the parser fallback' );
if ( ! $get_not_found['threw'] ) {
	$get_not_found_data = $get_not_found['response']->get_data();
	assert_true( empty( $get_not_found_data['ok'] ), 'module_get() reports failure for a genuinely missing label' );
	assert_same( 'not_found', $get_not_found_data['error']['code'] ?? null, 'the envelope reports not_found (block_not_found collapsed), not divi_error (parse_error collapsed)' );
}

// ── module_move() dry-run: trigger, false-positive, and false-trigger checks ──

$move_source = '<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Move Source"}}}}} /-->';
$move_target = '<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Move Target"}}}}} /-->';

// (4) TRIGGER: both source and target sit after the malformed block, so
// find_block()'s scan for the source selector hits the unterminated
// container first (label mode scans the whole document) and returns
// parse_error/parser_fallbackable before module_move() ever resolves either
// selector against the well-formed sections.
diviops_test_register_post( 9111, $malformed_unterminated_container . $move_source . $move_target );

$move_trigger_request = new DiviOps_Test_Request(
	array(
		'id'            => 9111,
		'source_label'  => 'Move Source',
		'target_label'  => 'Move Target',
		'position'      => 'after',
		'dry_run'       => true,
	)
);
$move_trigger = diviops_test_call_capturing_throw( 'module_move', array( $move_trigger_request ) );

assert_true(
	$move_trigger['threw'],
	'module_move() on a page with a leading malformed block does not return the raw parse_error — it attempts the parser fallback'
);
assert_true(
	$move_trigger['threw'] && false !== strpos( $move_trigger['message'], 'parse_blocks' ),
	'module_move() reaches parse_blocks() specifically, confirming move_block_with_parser() was entered'
);

// (5) NO FALSE POSITIVE: the same move on a well-formed-only page resolves
// via the raw scanner, with no exception at all.
diviops_test_register_post( 9112, $move_source . $move_target );

$move_baseline_request = new DiviOps_Test_Request(
	array(
		'id'            => 9112,
		'source_label'  => 'Move Source',
		'target_label'  => 'Move Target',
		'position'      => 'after',
		'dry_run'       => true,
	)
);
$move_baseline = diviops_test_call_capturing_throw( 'module_move', array( $move_baseline_request ) );

assert_true( ! $move_baseline['threw'], 'module_move() on well-formed content never touches parse_blocks()' );
if ( ! $move_baseline['threw'] ) {
	$move_baseline_data = $move_baseline['response']->get_data();
	assert_true( ! empty( $move_baseline_data['ok'] ), 'module_move() dry-run resolves both selectors via the raw scanner' );
	assert_true( ! empty( $move_baseline_data['data']['dry_run'] ), 'the response is a dry-run plan, not an applied move' );
	assert_same(
		'Move Source',
		$move_baseline_data['data']['plan']['changes'][0]['before']['source'] ?? null,
		'the raw scanner resolves the correct source'
	);
	assert_same(
		'Move Target',
		$move_baseline_data['data']['plan']['changes'][0]['after']['target'] ?? null,
		'the raw scanner resolves the correct target'
	);
}

// (6) NO FALSE TRIGGER: block_not_found is not a parse_error, so a genuinely
// missing target label on a well-formed page must not route into the fallback.
diviops_test_register_post( 9113, $move_source . $move_target );

$move_not_found_request = new DiviOps_Test_Request(
	array(
		'id'            => 9113,
		'source_label'  => 'Move Source',
		'target_label'  => 'Does Not Exist',
		'position'      => 'after',
		'dry_run'       => true,
	)
);
$move_not_found = diviops_test_call_capturing_throw( 'module_move', array( $move_not_found_request ) );

assert_true( ! $move_not_found['threw'], 'a plain block_not_found error on the target side must not route into the parser fallback' );
if ( ! $move_not_found['threw'] ) {
	$move_not_found_data = $move_not_found['response']->get_data();
	assert_true( empty( $move_not_found_data['ok'] ), 'module_move() reports failure for a genuinely missing target label' );
	assert_same( 'not_found', $move_not_found_data['error']['code'] ?? null, 'the envelope reports not_found (block_not_found collapsed), not divi_error (parse_error collapsed)' );
}
