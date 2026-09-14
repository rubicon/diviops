<?php
// SPDX-License-Identifier: MIT
/**
 * render_admin_page() enforces manage_options itself (#454).
 *
 * The dashboard's capability check used to live entirely in a different method's
 * argument list: `register_admin_page()` hands `'manage_options'` to
 * `add_menu_page()`, and WordPress's menu machinery is what refused everyone else.
 * That holds only for as long as the menu registration is the sole route in, and
 * `render_admin_page()` is `public static` on a class that loads on every request —
 * any plugin, theme or drop-in can call it directly and get the REST base URL, the
 * rate-limit configuration, the Divi, Design Library and Pro versions, and the
 * rollback snapshot summaries with their checksums.
 *
 * So both layers are pinned here, because the value is in the pair: the registration
 * still declares the capability, and the renderer no longer trusts it to.
 *
 * The denial is exercised through the shared shim's existing capability-denial seam
 * (`$GLOBALS['diviops_test_denied_caps']`) rather than a `current_user_can()` of this
 * suite's own, which would shadow the seam every other suite is written against.
 * `wp_die()` is modelled as a function that does not return, because that is what
 * core's does; see tests/admin-capability-stubs.php.
 *
 * NOT covered: the dashboard's markup. The capable-caller case below asserts only
 * that control reached the renderer body, and the body then throws for want of the
 * escaping, URL and time primitives the harness does not model (`esc_html_e`,
 * `esc_attr_e`, `esc_url`, `rest_url`, `add_query_arg`, `human_time_diff`). Modelling
 * those to assert on generated markup is a separate piece of work, and pinning the
 * markup of a screen that is redesigned on every upstream refresh would mostly
 * generate false failures.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/admin-capability-stubs.php';

/**
 * Run render_admin_page() for one caller and report everything it did.
 *
 * @param array<int, string> $denied Capabilities the caller does not hold.
 * @return array{die: array<int, array<string, mixed>>, translations: array<int, array<string, mixed>>, output: string, thrown: string}
 */
function diviops_acg_render( array $denied ): array {
	$GLOBALS['diviops_test_denied_caps']            = $denied;
	$GLOBALS['diviops_admin_wp_die_calls']          = array();
	$GLOBALS['diviops_admin_esc_html_translations'] = array();

	$thrown = '';

	// Buffered because a denial that leaked markup is the failure this suite exists
	// to catch, and because the runner treats any stray child output as a problem.
	ob_start();
	try {
		DiviOps_Agent::render_admin_page();
	} catch ( Throwable $error ) {
		$thrown = get_class( $error );
	}
	$output = (string) ob_get_clean();

	$GLOBALS['diviops_test_denied_caps'] = array();

	return array(
		'die'          => $GLOBALS['diviops_admin_wp_die_calls'],
		'translations' => $GLOBALS['diviops_admin_esc_html_translations'],
		'output'       => $output,
		'thrown'       => $thrown,
	);
}

// ── The registration still declares the capability ───────────────────────
//
// The renderer's own guard is defence in depth, not a replacement. If this stops
// being true the menu entry becomes visible to users the renderer then refuses,
// which is a worse screen than no entry at all.

$GLOBALS['diviops_acg_menu_pages'] = array();

DiviOps_Agent::register_admin_page();

assert_same(
	1,
	count( $GLOBALS['diviops_acg_menu_pages'] ),
	'register_admin_page() registers exactly one top-level menu page'
);
assert_same(
	'manage_options',
	$GLOBALS['diviops_acg_menu_pages'][0]['capability'],
	'the menu registration still gates the screen on manage_options'
);
assert_same(
	array( 'DiviOps_Agent', 'render_admin_page' ),
	$GLOBALS['diviops_acg_menu_pages'][0]['callback'],
	'and the callback it gates is the renderer guarded below'
);

// ── A caller without the capability ──────────────────────────────────────

$acg_denied = diviops_acg_render( array( 'manage_options' ) );

// Defaulted rather than indexed directly, so a failing run prints the assertion and
// not a pile of "undefined array key" warnings on top of it.
$acg_refusal     = $acg_denied['die'][0] ?? array( 'message' => null );
$acg_translation = $acg_denied['translations'][0] ?? array(
	'text'   => null,
	'domain' => null,
);

assert_same( 1, count( $acg_denied['die'] ), 'a caller without manage_options is stopped by wp_die()' );
assert_same(
	'You do not have permission to view this dashboard.',
	$acg_refusal['message'],
	'the refusal says which screen was refused, not just that something was'
);
assert_same(
	DiviOps_Test_Admin_Wp_Die::class,
	$acg_denied['thrown'],
	'the refusal ends the request; core wp_die() does not return, so nothing may run after it'
);

// The point of the guard. A refusal that still printed the page would be no refusal.
assert_same(
	'',
	$acg_denied['output'],
	'the refused caller receives none of the dashboard markup'
);

assert_same(
	1,
	count( $acg_denied['translations'] ),
	'the refusal message is passed through esc_html__() rather than echoed raw'
);
assert_same(
	'You do not have permission to view this dashboard.',
	$acg_translation['text'],
	'and it is that message that is translated'
);
assert_same(
	'diviops-agent',
	$acg_translation['domain'],
	"the message is translatable under this plugin's own text domain, which is what the header declares"
);

// ── A caller holding the capability ──────────────────────────────────────

$acg_allowed = diviops_acg_render( array() );

assert_same(
	array(),
	$acg_allowed['die'],
	'a caller holding manage_options is not stopped at the guard'
);

/*
 * Non-vacuity for the case above. "The guard did not fire" and "the renderer was
 * never entered" produce an identical empty list, so this pins that control actually
 * reached the body. Which throwable the body produces is deliberately not asserted —
 * it is whichever un-modelled primitive it reaches first. If the harness ever grows
 * those stubs the renderer will run to completion and `thrown` becomes '', and this
 * is the assertion to replace, with a check on the markup that then exists.
 */
assert_true(
	'' !== $acg_allowed['thrown'] && DiviOps_Test_Admin_Wp_Die::class !== $acg_allowed['thrown'],
	'control reached the renderer body rather than stopping at the guard, and threw: '
		. ( '' === $acg_allowed['thrown'] ? '(nothing)' : $acg_allowed['thrown'] )
);
