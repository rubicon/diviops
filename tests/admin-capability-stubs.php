<?php
// SPDX-License-Identifier: MIT
/**
 * Dedicated stubs for tests/test-admin-page-capability-guard.php (#454).
 *
 * `tests/wp-shim.php` models none of the request-termination or escaping
 * primitives, and CONTRIBUTING.md's shim contract forbids widening the shared
 * shim to make one suite pass. Everything here is additive, guarded by
 * `function_exists`, and named for this suite.
 *
 * `current_user_can()` is deliberately absent: the shared shim already carries a
 * blanket capability-denial seam (`$GLOBALS['diviops_test_denied_caps']`), which is
 * exactly the input this suite needs. Redefining it here would shadow the seam every
 * other suite is written against.
 *
 * Each stub models a WordPress PRIMITIVE, never the behaviour under test. The
 * behaviour under test is the capability guard at the top of `render_admin_page()`;
 * these only supply its inputs and record what it did.
 *
 * Sources, transcribed rather than invented:
 *
 *   plugin_dir_path()  wp-includes/plugin.php — `trailingslashit( dirname( $file ) )`.
 *                 Reached because `register_admin_page()` resolves its menu icon off
 *                 disk before it registers anything.
 *   add_menu_page()  wp-admin/includes/menu.php ends by returning
 *                 `get_plugin_page_hookname( $menu_slug, '' )`, which for a top-level
 *                 page is `'toplevel_page_' . $menu_slug` (wp-admin/includes/plugin.php).
 *                 Core's capability check is NOT modelled: this suite asserts which
 *                 capability the plugin declares, so a stub that enforced it would be
 *                 answering the question instead of recording it.
 *   wp_die()      wp-includes/functions.php — hands off to a handler that ends the
 *                 request. It does NOT return. That is the property modelled here,
 *                 by throwing: a stub that returned would let the renderer run on
 *                 past a denial and the guard's whole point would go unobserved.
 *                 Core's handler selection, status codes and HTML are not modelled
 *                 and nothing here asserts them.
 *   __()          wp-includes/l10n.php — `translate( $text, $domain )`. With no MO
 *                 file loaded for the domain, `get_translations_for_domain()` hands
 *                 back a `NOOP_Translations` whose `translate()` returns the string
 *                 unchanged, which is also a real site's state for any domain with
 *                 no installed translation. The `gettext` / `gettext_{$domain}`
 *                 filters core applies are NOT modelled; no code in this repository
 *                 registers either.
 *   esc_html()    wp-includes/formatting.php — `_wp_specialchars( wp_check_invalid_utf8( $text ), ENT_QUOTES )`,
 *                 then the `esc_html` filter. `_wp_specialchars()` defaults to
 *                 `$double_encode = false`, which un-double-encodes existing
 *                 entities; that pass is not modelled, so this raises on any input
 *                 carrying `&` rather than returning an answer it cannot stand
 *                 behind. The UTF-8 validity pass and the filter are not modelled
 *                 either.
 *   esc_html__()  wp-includes/l10n.php — `esc_html( translate( $text, $domain ) )`,
 *                 composed here from the two above rather than reimplemented, and
 *                 recording its arguments so the text domain can be asserted.
 *
 * @package DiviOps
 */

/**
 * Thrown in place of the request ending.
 *
 * A distinct class, not a generic exception: the capable-caller case asserts that
 * whatever the renderer threw was NOT this.
 */
class DiviOps_Test_Admin_Wp_Die extends RuntimeException {}

if ( ! isset( $GLOBALS['diviops_admin_wp_die_calls'] ) ) {
	$GLOBALS['diviops_admin_wp_die_calls'] = array();
}

if ( ! isset( $GLOBALS['diviops_admin_esc_html_translations'] ) ) {
	$GLOBALS['diviops_admin_esc_html_translations'] = array();
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return rtrim( dirname( (string) $file ), '/\\' ) . '/';
	}
}

if ( ! isset( $GLOBALS['diviops_acg_menu_pages'] ) ) {
	$GLOBALS['diviops_acg_menu_pages'] = array();
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
		$GLOBALS['diviops_acg_menu_pages'][] = array(
			'page_title' => $page_title,
			'menu_title' => $menu_title,
			'capability' => $capability,
			'menu_slug'  => $menu_slug,
			'callback'   => $callback,
		);
		return 'toplevel_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) {
		$GLOBALS['diviops_admin_wp_die_calls'][] = array(
			'message' => $message,
			'title'   => $title,
			'args'    => $args,
		);
		throw new DiviOps_Test_Admin_Wp_Die( (string) $message );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		$text = (string) $text;
		if ( false !== strpos( $text, '&' ) ) {
			throw new RuntimeException(
				'tests/admin-capability-stubs.php: esc_html() does not model _wp_specialchars()\'s '
				. '$double_encode = false pass, so it refuses input carrying "&" rather than '
				. 'returning an answer core would not have given: ' . $text
			);
		}
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		$GLOBALS['diviops_admin_esc_html_translations'][] = array(
			'text'   => $text,
			'domain' => $domain,
		);
		return esc_html( __( $text, $domain ) );
	}
}
