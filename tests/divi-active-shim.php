<?php
// SPDX-License-Identifier: MIT
/**
 * OPT-IN shim: Divi's theme-option accessors, for tests that need a Divi-ACTIVE site.
 *
 * `require_once` this AFTER wp-shim.php and BEFORE the plugin file, in the few tests
 * that exercise a handler behind Divi's option layer. It is deliberately NOT part of
 * `wp-shim.php`.
 *
 * ## Why this is separate, and must stay separate
 *
 * The plugin decides whether Divi is installed with `function_exists( 'et_get_option' )`
 * — at `diviops-agent.php:755`, `diviops-agent.php:2621` and `trait-meta.php:1847`.
 * The base harness therefore models a **Divi-inactive site**, and that absence is
 * load-bearing in at least three ways that are easy to destroy by accident:
 *
 *   1. `tests/test-meta-characterization.php` asserts `divi.active === false`
 *      *because* `et_get_option()` is absent. Defining it globally makes that
 *      assertion fail, and it is not a stale assertion — it pins the documented
 *      capability-reporting contract for a site without Divi.
 *   2. `tests/test-global-font-ref-scan-post-types.php` and
 *      `tests/test-variable-ref-scan-post-types.php` use the FATAL from an undefined
 *      `et_get_option()` as a probe signal: a post the scanner reached dies on the
 *      unshimmed `parse_blocks()`, one it filtered out survives to die on
 *      `et_get_option()`. Defining it globally silently removes one arm of that
 *      probe.
 *   3. `tests/test-preset-characterization.php` reads the same fatal as "the write
 *      was reached".
 *
 * Loading this file globally was tried while fixing #380 and broke all three at once.
 * The absence of a function is part of this harness's contract, not a gap in it.
 *
 * ## What is modelled, and what refuses
 *
 * Read from Divi 5.11.1's real implementation — `Divi/epanel/custom_functions.php:211`
 * (get) and `:272` (update) — not from inference. Divi stores theme options in ONE
 * ROW: `Divi/functions.php:17` sets `$et_store_options_in_one_row = true` and
 * `$shortname = 'divi'`, so `et_get_option( 'et_global_data' )` resolves to
 * `get_option( 'et_divi' )['et_global_data']`. Confirmed against the live staging
 * site, whose `et_divi` option carries `et_global_data` with 99 `global_colors`.
 *
 * The real functions take six further parameters covering global settings, product
 * settings and WPML object remapping. Every call site in this plugin uses only the
 * one- and two-argument forms — verified by enumerating all 37 calls — so those paths
 * RAISE here rather than being approximated. A shim that approximates produces
 * passing tests on broken code, which this repository has shipped once already.
 */

if ( ! isset( $GLOBALS['diviops_test_et_shortname'] ) ) {
	$GLOBALS['diviops_test_et_shortname'] = 'divi';
}

if ( ! function_exists( 'et_options_stored_in_one_row' ) ) {
	/**
	 * Divi sets `$et_store_options_in_one_row = true` in functions.php. Modelled as a
	 * constant true rather than a togglable global: no plugin code path depends on the
	 * false branch, and offering both would invite a test to pin the one production
	 * never takes.
	 */
	function et_options_stored_in_one_row() {
		return true;
	}
}

if ( ! function_exists( 'et_get_option' ) ) {
	/**
	 * Model Divi's et_get_option() for the one-row storage path only.
	 *
	 * Reproduces the real fallback rule: return the named key from `et_<shortname>`,
	 * and substitute `$default_value` only when the key is ABSENT. Divi's own source
	 * carries the comment "option value might be equal to false, so check if the
	 * option is not set in the database" — which is why a stored `false` must survive
	 * rather than being replaced by the default.
	 */
	function et_get_option( $option_name, $default_value = '', $used_for_object = '', $force_default_value = false, $is_global_setting = false, $global_setting_main_name = '', $global_setting_sub_name = '', $is_product_setting = false ) {
		if ( $is_global_setting || $is_product_setting || '' !== $used_for_object ) {
			throw new RuntimeException(
				'divi-active-shim et_get_option(): the global-setting, product-setting and WPML object paths are not modelled. '
				. 'No plugin call site uses them; model one against Divi rather than approximating if that changes.'
			);
		}

		$theme_options = get_option( 'et_' . $GLOBALS['diviops_test_et_shortname'] );
		if ( ! is_array( $theme_options ) || ! array_key_exists( $option_name, $theme_options ) ) {
			return ( '' !== $default_value || $force_default_value ) ? $default_value : false;
		}

		return $theme_options[ $option_name ];
	}
}

if ( ! function_exists( 'et_update_option' ) ) {
	/**
	 * Model Divi's et_update_option() for the one-row storage path only.
	 *
	 * Reads `et_<shortname>`, sets the single key, writes the whole array back — so a
	 * write to one option must not disturb its siblings. That merge-not-replace
	 * property is exactly what #380 got wrong one level down, inside `global_colors`.
	 */
	function et_update_option( $option_name, $new_value, $is_new_global_setting = false, $global_setting_main_name = '', $global_setting_sub_name = '', $is_product_setting = false ) {
		if ( $is_new_global_setting || $is_product_setting ) {
			throw new RuntimeException(
				'divi-active-shim et_update_option(): the global-setting and product-setting paths are not modelled. '
				. 'No plugin call site uses them; model one against Divi rather than approximating if that changes.'
			);
		}

		$name          = 'et_' . $GLOBALS['diviops_test_et_shortname'];
		$theme_options = get_option( $name );
		if ( ! is_array( $theme_options ) ) {
			$theme_options = array();
		}
		$theme_options[ $option_name ] = $new_value;

		return update_option( $name, $theme_options );
	}
}
