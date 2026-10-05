<?php
// SPDX-License-Identifier: MIT
/**
 * The canvas content type guard runs before the #474 budget (#540).
 *
 * `canvas_create()` and `canvas_update()` were the only two of
 * `authoring_shape_preflight()`'s seven call sites to pass `(string) $content`
 * instead of `$content`. The cast emitted `PHP Warning: Array to string
 * conversion` into `php tests/run.php` output, made the preflight's own
 * `parser_invalid` refusal structurally unreachable from canvas, and left
 * `input_bytes` measuring the literal five-byte string `Array` rather than
 * refusing the payload.
 *
 * The cast existed for a real reason: canvas is the one domain where `content` is
 * optional, so a direct handler call legitimately passes `null`, and
 * `authoring_shape_preflight( [ null ] )` refuses that. The fix hoists each
 * handler's own `is_string` guard above the preflight and passes `$content ?? ''`,
 * so the optional path keeps working without a cast that also swallows arrays.
 *
 * What this file covers:
 *
 *   - No PHP diagnostic is raised for a non-string `content` on either handler.
 *     This is the defect itself, so it is asserted directly rather than inferred
 *     from the refusal: before the fix both handlers refused correctly AND warned,
 *     which is why the existing canvas characterization suite was green while the
 *     warning fired.
 *   - The refusal carries `error.data.field` and `error.data.received_type`, which
 *     the page routes already return and canvas did not.
 *   - The one refusal precedence this fix deliberately changes.
 *   - The optional-content paths the hoist must not break.
 *
 * What this file deliberately does NOT cover:
 *
 *   - The byte, block, depth and string budgets themselves. Those are
 *     `tests/test-authoring-shape-budget.php`, which drives
 *     `authoring_shape_preflight()` directly. This file only asserts that canvas
 *     reaches it with a string, never that the limits are right.
 *   - The five uncast call sites. They already pass `$content` raw and were not
 *     touched.
 *   - `title` type handling. `canvas_update`'s `is_scalar( $title )` guard is
 *     pinned in `tests/test-canvas-characterization.php` section (14) and is a
 *     different parameter with a different contract.
 *
 * Expected values: the error code, message, hint and `data` keys are derived from
 * `plugins/diviops-agent/includes/trait-page.php:452-460`, the sibling guard this
 * change brings canvas into line with, not read off a run of the canvas handlers.
 * The `hint` names `diviops_canvas_get` where the page copy names
 * `diviops_page_get_layout`, because that is the read tool for this domain. HTTP
 * 400 is the status that guard's own `envelope_error()` call passes.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/*
 * The canvas handlers' queries ask for three arguments the WP_Query stub refuses
 * by default. Waived exactly as tests/test-canvas-characterization.php waives
 * them, and inert here for the same reasons: `perm => 'editable'` is re-applied
 * per object by the handler's own edit_post check, and `orderby`/`order` cannot
 * change which rows come back for fixture sets smaller than the posts_per_page
 * cap. No assertion below depends on query ordering.
 */
$GLOBALS['diviops_test_wp_query_unmodelled_ok'] = array( 'perm', 'orderby', 'order' );

/**
 * Reset every registry these handlers read or write.
 *
 * @return void
 */
function diviops_cctg_reset() {
	$GLOBALS['diviops_test_posts']          = array();
	$GLOBALS['diviops_test_post_meta']      = array();
	$GLOBALS['diviops_test_post_meta_rows'] = array();
	$GLOBALS['diviops_test_uneditable_ids'] = array();
	$GLOBALS['diviops_test_next_id']        = 9000;
	unset( $GLOBALS['diviops_test_last_insert'] );
}

/**
 * Invoke a canvas handler, collecting every PHP diagnostic it raises.
 *
 * The handler is called inside a `set_error_handler()` that records rather than
 * prints, so this file stays quiet in suite output whether or not the fix is
 * present and the assertion is the only thing that reports. The handler returns
 * true, which stops PHP's own reporting from also running.
 *
 * @param string $method Private static handler name.
 * @param array  $params Request parameters.
 * @return array{response: mixed, diagnostics: array<int, string>}
 */
function diviops_cctg_call( string $method, array $params ): array {
	$diagnostics = array();
	set_error_handler(
		static function ( $errno, $errstr ) use ( &$diagnostics ): bool {
			$diagnostics[] = $errstr;
			return true;
		}
	);
	try {
		$response = diviops_call( $method, array( new DiviOps_Test_Request( $params ) ) );
	} finally {
		restore_error_handler();
	}
	return array(
		'response'    => $response,
		'diagnostics' => $diagnostics,
	);
}

/*
 * The envelope the page routes return for a non-string content, read from
 * trait-page.php:452-460. Canvas is asserted against this shape rather than
 * against its own output.
 */
$diviops_cctg_message = 'content must be a string of Divi block markup.';
$diviops_cctg_hint    = 'Pass content as a string. See diviops_canvas_get for the expected shape.';

// ── canvas_create ─────────────────────────────────────────────────────────

diviops_cctg_reset();
diviops_test_register_post( 4300, '', 'page', 'Parent Page' );

// (1) An array content raises no PHP diagnostic. This is the defect: before the
// fix the cast on the authoring_shape_preflight() argument warned here, and the
// refusal below was already correct, so only this assertion fails pre-fix.
$diviops_cctg_create = diviops_cctg_call(
	'canvas_create',
	array(
		'title'          => 'Hero',
		'parent_page_id' => 4300,
		'content'        => array( 'not', 'a', 'string' ),
	)
);
assert_same(
	array(),
	$diviops_cctg_create['diagnostics'],
	'canvas_create raises no PHP diagnostic for an array content'
);

// (2) ...and it still refuses, with the page routes' code, status and message.
$diviops_cctg_data = $diviops_cctg_create['response']->get_data();
assert_true( false === $diviops_cctg_data['ok'], 'an array content returns an error envelope' );
assert_same( 'invalid_input', $diviops_cctg_data['error']['code'], 'an array content is invalid_input' );
assert_same( 400, $diviops_cctg_create['response']->get_status(), 'an array content carries HTTP 400' );
assert_same(
	$diviops_cctg_message,
	$diviops_cctg_data['error']['message'],
	'the non-string content message names the expected type'
);

// (3) The diagnostics the page routes carry and canvas did not.
assert_same(
	$diviops_cctg_hint,
	$diviops_cctg_data['error']['hint'] ?? null,
	'the refusal hints at the canvas read tool'
);
assert_same(
	'content',
	$diviops_cctg_data['error']['data']['field'] ?? null,
	'the refusal names the offending field'
);
assert_same(
	'array',
	$diviops_cctg_data['error']['data']['received_type'] ?? null,
	'the refusal reports the type it received'
);

// (4) The precedence this fix deliberately changes, pinned so it cannot move
// again unnoticed. Before #540 the preflight ran at the top of the handler and
// the type check sat below the parent lookup, so this same call returned 404
// not_found — after warning. The hoist puts the type check first, which is the
// order trait-page.php already uses.
$diviops_cctg_both = diviops_cctg_call(
	'canvas_create',
	array(
		'title'          => 'Hero',
		'parent_page_id' => 999999,
		'content'        => array( 'x' ),
	)
);
assert_same(
	array(),
	$diviops_cctg_both['diagnostics'],
	'an array content with a missing parent raises no PHP diagnostic either'
);
assert_same(
	'invalid_input',
	$diviops_cctg_both['response']->get_data()['error']['code'],
	'a request wrong in both ways reports the content type, not the missing parent'
);

// (5) The optional-content paths the hoist must not break. canvas_create's
// content is `'required' => false, 'default' => ''` at diviops-agent.php:2389,
// so both an omitted content and an empty string are legitimate.
$diviops_cctg_none = diviops_cctg_call(
	'canvas_create',
	array(
		'title'          => 'No Content',
		'parent_page_id' => 4300,
	)
);
assert_same( array(), $diviops_cctg_none['diagnostics'], 'creating with no content raises no PHP diagnostic' );
assert_true(
	true === $diviops_cctg_none['response']->get_data()['ok'],
	'a canvas with no content is still created'
);

$diviops_cctg_empty = diviops_cctg_call(
	'canvas_create',
	array(
		'title'          => 'Empty Content',
		'parent_page_id' => 4300,
		'content'        => '',
	)
);
assert_true(
	true === $diviops_cctg_empty['response']->get_data()['ok'],
	'a canvas with an empty-string content is still created'
);

// ── canvas_update ─────────────────────────────────────────────────────────

diviops_cctg_reset();
$diviops_cctg_canvas                = diviops_test_register_post( 7100, '', 'et_pb_canvas', 'Existing' );
$diviops_cctg_canvas->post_modified = '2026-09-01 00:00:00';

// (6) The same guard on the update route, which had its own cast at
// trait-canvas.php:1132 inside the `null !== $content` branch.
$diviops_cctg_update = diviops_cctg_call(
	'canvas_update',
	array(
		'id'      => 7100,
		'content' => array( 'not', 'a', 'string' ),
	)
);
assert_same(
	array(),
	$diviops_cctg_update['diagnostics'],
	'canvas_update raises no PHP diagnostic for an array content'
);
$diviops_cctg_udata = $diviops_cctg_update['response']->get_data();
assert_same( 'invalid_input', $diviops_cctg_udata['error']['code'], 'an array content is invalid_input on update' );
assert_same( 400, $diviops_cctg_update['response']->get_status(), 'an array content carries HTTP 400 on update' );
assert_same(
	$diviops_cctg_message,
	$diviops_cctg_udata['error']['message'],
	'update reuses the same non-string content message'
);
assert_same(
	$diviops_cctg_hint,
	$diviops_cctg_udata['error']['hint'] ?? null,
	'update hints at the canvas read tool too'
);
assert_same(
	'content',
	$diviops_cctg_udata['error']['data']['field'] ?? null,
	'update names the offending field'
);
assert_same(
	'array',
	$diviops_cctg_udata['error']['data']['received_type'] ?? null,
	'update reports the type it received'
);

// (7) A non-string SCALAR is the case the cast hid most completely: `(string) 12345`
// is silent, so no warning ever pointed at it, and the refusal reported no type.
// gettype() names it, which is what makes the diagnostic worth adding.
$diviops_cctg_int = diviops_cctg_call(
	'canvas_update',
	array(
		'id'      => 7100,
		'content' => 12345,
	)
);
assert_same( array(), $diviops_cctg_int['diagnostics'], 'an integer content raises no PHP diagnostic' );
assert_same(
	'integer',
	$diviops_cctg_int['response']->get_data()['error']['data']['received_type'] ?? null,
	'an integer content is reported as integer, not coerced'
);

// (8) A metadata-only update carries no content at all and must stay a success.
// This is the path the cast's `(string) null` was protecting, and the one a bare
// `authoring_shape_preflight( [ $content ] )` would have broken.
$diviops_cctg_meta = diviops_cctg_call(
	'canvas_update',
	array(
		'id'      => 7100,
		'z_index' => 5,
	)
);
assert_same( array(), $diviops_cctg_meta['diagnostics'], 'a metadata-only update raises no PHP diagnostic' );
assert_true(
	true === $diviops_cctg_meta['response']->get_data()['ok'],
	'a metadata-only update with no content still succeeds'
);

// ── the cast is gone from the source ──────────────────────────────────────

/*
 * A source-level gate, because the assertions above pass for a handler that
 * refuses early and then casts anyway further down. It counts what it inspected
 * before asserting anything about what it found, so it cannot pass by reading
 * nothing: the preflight call count is the positive control.
 */
$diviops_cctg_src = (string) file_get_contents(
	dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-canvas.php'
);
assert_true( '' !== $diviops_cctg_src, 'trait-canvas.php is readable for the source gate' );

$diviops_cctg_calls = preg_match_all(
	'/authoring_shape_preflight\(\s*\[([^\]]*)\]/',
	$diviops_cctg_src,
	$diviops_cctg_matches
);
assert_same( 2, $diviops_cctg_calls, 'trait-canvas.php still has exactly the two preflight call sites this gate inspects' );

$diviops_cctg_cast = 0;
foreach ( $diviops_cctg_matches[1] as $diviops_cctg_arg ) {
	if ( false !== strpos( $diviops_cctg_arg, '(string)' ) ) {
		++$diviops_cctg_cast;
	}
}
assert_same( 0, $diviops_cctg_cast, 'neither canvas preflight call casts its content to string' );
