<?php
// SPDX-License-Identifier: MIT
/**
 * Divi's REST nonce helper, as the read bridge uses it (#504).
 *
 * Its own file because a namespaced class cannot be declared inside a
 * conditional block — the same reason `tests/fixtures/divi-rest-classes.php`
 * exists. Required from `tests/divi-read-bridge-stubs.php` behind a
 * `class_exists()` guard.
 *
 * Both methods are `public static` on the real class, which is the whole reason
 * the bridge is possible. The action shape is transcribed from the live install
 * rather than guessed: `get_nonce_name()` returns
 * `get_full_route( $namespace, $route ) . '--' . $method`, which on staging at
 * Divi 5.13.1 produced exactly `/divi/v1/loop/query-types--GET`.
 *
 * @package DiviOps
 */

namespace ET\Builder\Framework\Controllers;

class RESTController {
	/**
	 * @param string $namespace REST namespace, e.g. divi/v1.
	 * @param string $route     Route within the namespace.
	 * @return string
	 */
	public static function get_full_route( $namespace, $route ) {
		return '/' . trim( $namespace, '/' ) . '/' . ltrim( $route, '/' );
	}

	/**
	 * @param string $namespace REST namespace.
	 * @param string $route     Route within the namespace.
	 * @param string $method    HTTP method.
	 * @return string
	 */
	public static function get_nonce_name( $namespace, $route, $method ) {
		return self::get_full_route( $namespace, $route ) . '--' . $method;
	}

	/**
	 * @param string $namespace REST namespace.
	 * @param string $route     Route within the namespace.
	 * @param string $method    HTTP method.
	 * @return string
	 */
	public static function create_nonce( $namespace, $route, $method ) {
		return \wp_create_nonce( self::get_nonce_name( $namespace, $route, $method ) );
	}
}
