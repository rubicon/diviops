<?php
// SPDX-License-Identifier: MIT
/**
 * enqueue_admin_styles() — the dashboard stylesheet's screen gate (#451).
 *
 * The admin refresh moved the dashboard's presentation out of inline `style`
 * attributes and into `assets/admin.css`, which means the screen is now unstyled
 * unless a stylesheet is actually enqueued on it. `admin_enqueue_scripts` fires on
 * every admin screen, so the guard has to answer two questions and both failures are
 * silent: enqueue nowhere and the dashboard renders as unstyled markup that still
 * looks like a page, enqueue everywhere and this plugin's CSS variables and `.button`
 * overrides land on other people's admin screens.
 *
 * That is why the hook suffix is captured from `add_menu_page()`'s return rather than
 * hardcoded. `toplevel_page_diviops` is what it happens to be today; a literal would
 * stop matching if the slug's sanitisation or the menu registration changed, with no
 * error anywhere.
 *
 * The static is set through Reflection before each case rather than by relying on an
 * earlier call in the same process. `tests/run.php` shares one process across every
 * file, so a test whose setup is "whatever ran before me" passes or fails on glob
 * order.
 *
 * NOT covered: that the stylesheet is correct, or that core resolves the `dashicons`
 * dependency. `tests/admin-enqueue-stubs.php` records the call and models neither.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/admin-enqueue-stubs.php';

/**
 * Reflect DiviOps_Agent::$admin_page_hook, which is private.
 *
 * The `setAccessible()` call is guarded on PHP_VERSION_ID exactly as
 * `diviops_call()` in tests/wp-shim.php guards its own: it is required on the 7.4
 * floor this plugin declares and emits a deprecation notice from 8.5, and test output
 * has to stay pristine on both.
 */
function diviops_ase_hook_property(): ReflectionProperty {
	$property = new ReflectionProperty( 'DiviOps_Agent', 'admin_page_hook' );
	if ( PHP_VERSION_ID < 80100 ) {
		$property->setAccessible( true );
	}
	return $property;
}

/**
 * Set DiviOps_Agent::$admin_page_hook.
 *
 * @param mixed $value Hook suffix, or false as add_menu_page() returns it when the
 *                     capability check fails.
 */
function diviops_ase_set_hook( $value ): void {
	diviops_ase_hook_property()->setValue( null, $value );
}

/**
 * Run enqueue_admin_styles() for one screen and return the styles it enqueued.
 *
 * @param string $hook Screen hook suffix.
 * @return array<int, array<string, mixed>> Recorded wp_enqueue_style() calls.
 */
function diviops_ase_enqueue_for( string $hook ): array {
	$GLOBALS['diviops_admin_enqueue_styles'] = array();
	DiviOps_Agent::enqueue_admin_styles( $hook );
	return $GLOBALS['diviops_admin_enqueue_styles'];
}

// ── The hook suffix comes from add_menu_page(), not from a literal ───────

diviops_ase_set_hook( '' );
DiviOps_Agent::register_admin_page();

$ase_hook = diviops_ase_hook_property()->getValue();

assert_same(
	'toplevel_page_diviops',
	$ase_hook,
	'register_admin_page() records the hook suffix add_menu_page() returned'
);

// ── The gate ─────────────────────────────────────────────────────────────

$ase_styles = diviops_ase_enqueue_for( $ase_hook );
assert_same( 1, count( $ase_styles ), 'the dashboard screen enqueues exactly one stylesheet' );

foreach ( array( 'plugins.php', 'index.php', 'toplevel_page_diviops-pro-license', 'edit.php' ) as $ase_other ) {
	assert_same(
		array(),
		diviops_ase_enqueue_for( $ase_other ),
		"{$ase_other} enqueues nothing — admin_enqueue_scripts fires on every screen, so an ungated enqueue lands this plugin's CSS on all of them"
	);
}

// A capability check inside add_menu_page() makes it return false rather than a hook
// suffix. Nothing must be enqueued then, and in particular a falsy stored hook must
// not be allowed to match a falsy-ish screen.
diviops_ase_set_hook( false );
assert_same(
	array(),
	diviops_ase_enqueue_for( $ase_hook ),
	'a menu registration that never happened enqueues nothing, even on the screen it would have owned'
);
diviops_ase_set_hook( $ase_hook );

// ── What is enqueued ─────────────────────────────────────────────────────

$ase_style = diviops_ase_enqueue_for( $ase_hook )[0];

assert_same( 'diviops-agent-admin', $ase_style['handle'], 'the stylesheet registers under its own handle' );
assert_same( array( 'dashicons' ), $ase_style['deps'], 'dashicons is declared as a dependency; the snapshot cards and support list draw their icons from it' );
assert_same(
	DiviOps_Agent::VERSION,
	$ase_style['ver'],
	'the plugin version is the cache-buster, so an update is not served a stale stylesheet from a browser cache'
);
assert_same(
	'assets/admin.css',
	substr( (string) $ase_style['src'], -16 ),
	'the src points at the plugin-relative stylesheet'
);

// The file the handle promises has to exist. A rename or a packaging slip produces a
// 404 that renders as an unstyled page rather than as an error.
assert_true(
	is_readable( dirname( __DIR__ ) . '/plugins/diviops-agent/assets/admin.css' ),
	'assets/admin.css ships in the plugin directory'
);
