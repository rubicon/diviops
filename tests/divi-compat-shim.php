<?php
// SPDX-License-Identifier: MIT
/**
 * Divi runtime stand-in for the compatibility-repair tests (#266).
 *
 * Recreates only what `trait-compatibility.php` actually inspects: the broken
 * controller, Divi's REST nonce-name helper, `UserRole`, the version constant,
 * and `wp_verify_nonce`. Everything is steerable through `$GLOBALS` so a single
 * shim covers every branch without redefining a class.
 *
 * `UserRole` is the exception and is loaded from a fixture, because whether
 * `can_edit_posts()` exists is the whole self-retirement question and a class
 * cannot be redefined mid-process. Define `DIVIOPS_TEST_DIVI_USERROLE_FIXTURE`
 * before requiring this file to pick a shape; it defaults to the broken one,
 * which is what Divi 5.11.1 actually ships.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

if ( ! defined( 'DIVIOPS_TEST_DIVI_USERROLE_FIXTURE' ) ) {
	define( 'DIVIOPS_TEST_DIVI_USERROLE_FIXTURE', __DIR__ . '/fixtures/divi-userrole-broken.php' );
}
require_once DIVIOPS_TEST_DIVI_USERROLE_FIXTURE;

if ( ! defined( 'ET_BUILDER_PRODUCT_VERSION' ) ) {
	define( 'ET_BUILDER_PRODUCT_VERSION', $GLOBALS['diviops_test_divi_version'] ?? '5.11.1' );
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Accepts only the exact nonce the test declares valid, so a wrong or
	 * absent nonce is a real refusal rather than a stub that waves everything
	 * through.
	 *
	 * @param string $nonce  Submitted nonce.
	 * @param string $action Nonce action name.
	 * @return int|false
	 */
	function wp_verify_nonce( $nonce, $action = -1 ) {
		$valid = $GLOBALS['diviops_test_valid_nonce'] ?? null;
		if ( null === $valid ) {
			return false;
		}
		return ( (string) $nonce === (string) $valid ) ? 1 : false;
	}
}

require_once __DIR__ . '/fixtures/divi-rest-classes.php';

/** Minimal REST request stand-in carrying route, method and headers. */
	class DiviOps_Test_Divi_Request {
		private $route;
		private $method;
		private $headers;

		public function __construct( string $route, string $method = 'GET', array $headers = array() ) {
			$this->route   = $route;
			$this->method  = $method;
			$this->headers = $headers;
		}

		public function get_route() {
			return $this->route;
		}

		public function get_method() {
			return $this->method;
		}

		public function get_header( $name ) {
			return $this->headers[ $name ] ?? null;
		}
	}

	/** The endpoint map shape `rest_endpoints` passes around. */
	function diviops_test_divi_endpoints( array $overrides = array() ): array {
		$controller = 'ET\Builder\Packages\ModuleLibrary\PostFilterItem\PostFilterProductPriceRangeController';
		$handler    = array(
			'methods'             => array( 'GET' => true ),
			'callback'            => array( $controller, 'index' ),
			'permission_callback' => array( $controller, 'index_permission' ),
		);
		return array_merge(
			array( '/divi/v1/loop/product-price-range' => array( array_merge( $handler, $overrides ) ) ),
			array( '/divi/v1/unrelated' => array( $handler ) )
		);
	}

	/** Host class that mixes in only the trait under test. */
	if ( ! class_exists( 'DiviOps_Compat_Host' ) ) {
		require_once dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-compatibility.php';
		class DiviOps_Compat_Host {
			use DiviOps_Agent_Compatibility;
		}
	}
