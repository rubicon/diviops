<?php
// SPDX-License-Identifier: MIT
/**
 * Divi's REST nonce helper and the broken Post Filter controller (#266).
 *
 * `index_permission()` reproduces the real defect: it calls
 * `UserRole::can_edit_posts()`, which Divi 5.10.x-5.11.x does not declare.
 * Whether that fatals depends on which `UserRole` fixture is loaded, which is
 * the point.
 *
 * @package DiviOps
 */

namespace ET\Builder\Framework\Controllers;

class RESTController {
	public static function get_nonce_name( $namespace, $route, $method ) {
		if ( ! empty( $GLOBALS['diviops_test_divi_nonce_name_empty'] ) ) {
			return '';
		}
		return $namespace . $route . $method;
	}
}

namespace ET\Builder\Packages\ModuleLibrary\PostFilterItem;

class PostFilterProductPriceRangeController {
	public static function index() {
		return array();
	}

	public static function index_permission(): bool {
		return \ET\Builder\Framework\UserRole\UserRole::can_edit_posts();
	}
}
