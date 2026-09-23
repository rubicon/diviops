<?php
// SPDX-License-Identifier: MIT
/**
 * Primitives the bulk write harness needs and `tests/wp-shim.php` does not model (#495).
 *
 * Two functions, both genuine WordPress primitives rather than behaviour under
 * test, supplied here instead of by widening the shared shim (CONTRIBUTING.md,
 * "The shim contract"). Both are guarded by `function_exists`, so a later shim
 * that models either one wins and this file becomes inert rather than
 * conflicting.
 *
 * `wp_salt()` — a site-local secret. The value does not matter; the PROPERTIES
 * do, and they are what the harness relies on:
 *
 *   - non-empty, so the HMAC is actually keyed;
 *   - stable across calls, so a token minted in one request verifies in the next;
 *   - distinct per scheme, so the bulk key is not the auth key.
 *
 * Those three were measured on the reference install
 * (`wp-includes/pluggable.php:2585`) rather than assumed — a 96-character value
 * for a custom scheme, byte-identical across two separate PHP processes, and
 * different from both `auth` and another custom scheme. This models exactly
 * those properties and nothing else.
 *
 * It deliberately does NOT model core's key-derivation. A faithful copy would
 * be a second implementation of something the harness only needs three
 * properties from, and the test that mattered would then be testing the copy.
 *
 * `get_post_type_object()` — supplies `->cap->publish_posts`, which the
 * per-target publish-capability re-check resolves. The shim has no post-type
 * registry, so without this every publish gate would take an unresolvable-
 * capability branch and the check could never be exercised in the direction
 * that matters (allowed).
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Model wp_salt()'s three load-bearing properties: non-empty, stable, and
	 * distinct per scheme.
	 *
	 * @param string $scheme Salt scheme.
	 * @return string
	 */
	function wp_salt( $scheme = 'auth' ) {
		return 'diviops-test-salt/' . hash( 'sha256', 'fixed-site-secret|' . (string) $scheme );
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	/**
	 * Minimal post-type object carrying the capability map the harness reads.
	 *
	 * `$GLOBALS['diviops_bwh_post_type_caps']` overrides the capability name per
	 * post type, so a test can point one post type at a capability the caller
	 * does not hold and prove the per-target publish gate fires for that target
	 * and not for its neighbour.
	 *
	 * @param string $post_type Post type name.
	 * @return object|null
	 */
	function get_post_type_object( $post_type ) {
		$known = array( 'page', 'post', 'et_body_layout', 'et_header_layout', 'et_footer_layout' );
		if ( ! in_array( (string) $post_type, $known, true ) ) {
			return null;
		}

		$override = $GLOBALS['diviops_bwh_post_type_caps'][ (string) $post_type ] ?? null;
		$caps     = new stdClass();
		$caps->publish_posts = null === $override
			? ( 'page' === $post_type ? 'publish_pages' : 'publish_posts' )
			: (string) $override;
		$caps->create_posts  = 'edit_posts';

		$object       = new stdClass();
		$object->name = (string) $post_type;
		$object->cap  = $caps;

		return $object;
	}
}

if ( ! isset( $GLOBALS['diviops_bwh_post_type_caps'] ) ) {
	$GLOBALS['diviops_bwh_post_type_caps'] = array();
}

if ( ! class_exists( 'DiviOps_BulkRateLimit_Request' ) ) {
	/**
	 * A request that can answer `get_route()` and `get_method()`.
	 *
	 * `tests/wp-shim.php`'s `DiviOps_Test_Request` models `get_param()` and array
	 * access only, which is everything a handler needs. `check_rate_limit()` is
	 * not a handler -- it runs on `rest_pre_dispatch` and branches on the route
	 * and the HTTP method -- so it cannot be driven through that class at all.
	 *
	 * Supplied here rather than by widening the shared request class, because
	 * every existing test constructs that class with a bare params array and a
	 * route-aware default would have to be invented for all of them.
	 */
	final class DiviOps_BulkRateLimit_Request {

		/** @var array */
		private $params;

		/** @var string */
		private $route;

		/** @var string */
		private $method;

		/**
		 * @param array  $params Request parameters.
		 * @param string $route  REST route, as get_route() reports it.
		 * @param string $method HTTP method.
		 */
		public function __construct( array $params, string $route, string $method = 'POST' ) {
			$this->params = $params;
			$this->route  = $route;
			$this->method = $method;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_route() {
			return $this->route;
		}

		public function get_method() {
			return $this->method;
		}
	}
}
