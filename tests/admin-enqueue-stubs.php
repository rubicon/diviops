<?php
// SPDX-License-Identifier: MIT
/**
 * Dedicated stubs for tests/test-admin-style-enqueue.php (#451).
 *
 * `tests/wp-shim.php` models none of the admin-menu or asset-registration
 * primitives, and CONTRIBUTING.md's shim contract forbids widening the shared
 * shim to make one suite pass. Everything here is additive, guarded by
 * `function_exists`, and named for this suite.
 *
 * Each stub models a WordPress PRIMITIVE, never the behaviour under test. The
 * behaviour under test is `enqueue_admin_styles()`'s hook-suffix gate and the
 * arguments it hands `wp_enqueue_style()`; these four only supply the inputs
 * and record the outputs.
 *
 * Sources, transcribed rather than invented:
 *
 *   plugin_dir_path()      wp-includes/plugin.php — `trailingslashit( dirname( $file ) )`.
 *   add_menu_page()        wp-admin/includes/menu.php ends by returning
 *                          `get_plugin_page_hookname( $menu_slug, '' )`, which for a
 *                          top-level page with no parent is `'toplevel_page_' . $menu_slug`
 *                          (wp-admin/includes/plugin.php). That return value is the whole
 *                          point of the static this suite exercises.
 *   plugins_url()          Returns an absolute URL under the plugins directory. The host is
 *                          NOT modelled — a test site's URL is site configuration, not a
 *                          contract — so the suite asserts only the tail of the path.
 *   wp_enqueue_style()     Records the call. Core's real registration and dependency
 *                          resolution are not modelled and nothing here asserts them.
 *
 * @package DiviOps
 */

if ( ! isset( $GLOBALS['diviops_admin_enqueue_styles'] ) ) {
	$GLOBALS['diviops_admin_enqueue_styles'] = array();
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return rtrim( dirname( (string) $file ), '/\\' ) . '/';
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '' ) {
		return 'https://example.invalid/wp-content/plugins/'
			. basename( dirname( (string) $plugin ) ) . '/'
			. ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $icon_url = '', $position = null ) {
		return 'toplevel_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		$GLOBALS['diviops_admin_enqueue_styles'][] = array(
			'handle' => $handle,
			'src'    => $src,
			'deps'   => $deps,
			'ver'    => $ver,
			'media'  => $media,
		);
	}
}
