<?php
// SPDX-License-Identifier: MIT
/**
 * Primitives the Divi read bridge calls (#504).
 *
 * A dedicated stub file rather than an addition to `tests/wp-shim.php`, because
 * CONTRIBUTING's shim contract forbids a suite widening the shared shim: the
 * acceptable outcomes are a stub named for the suite or a reported gap. Follows
 * `tests/wp-date-hierarchy-shim.php` and `tests/preset-characterization-stubs.php`
 * — everything additive, guarded, and modelling a PRIMITIVE and never the
 * behaviour under test.
 *
 * Three primitives, none of them this bridge's logic:
 *
 *   - `wp_create_nonce()` / `wp_verify_nonce()` — WordPress core, `pluggable.php`.
 *     Core derives a token from the action, user id, session token and time tick.
 *     Modelled here as a deterministic function of the action alone, which is
 *     enough for the only property the bridge depends on: the same action yields
 *     a token that verifies against that action and no other.
 *   - `rest_do_request()` — WordPress core, `rest-api.php`, which is
 *     `rest_get_server()->dispatch( $request )`. Modelled as a table the test
 *     fills, because the bridge's job is to build the right request and shape the
 *     answer, not to re-test core's dispatcher.
 *   - Divi's `RESTController::get_nonce_name()` / `::create_nonce()`, whose real
 *     shapes were read off the live install rather than guessed:
 *     `get_nonce_name()` returns `<full route>--<METHOD>`, e.g.
 *     `/divi/v1/loop/query-types--GET`, confirmed on staging at Divi 5.13.1.
 *
 * What this file deliberately CANNOT model, and the test says so too: whether a
 * nonce minted this way actually satisfies Divi's own
 * `rest_request_before_callbacks` gate. That is not a property of our code and no
 * stub can establish it. It was established by live probe against staging on
 * 2026-09-27 — with a minted nonce both target routes returned HTTP 200, and
 * without one, and with a wrong one, both returned HTTP 400 `invalid_nonce`.
 *
 * @package DiviOps
 */

/**
 * A header-carrying `WP_REST_Request`.
 *
 * `tests/wp-shim.php` declares its own inside `if ( ! class_exists(
 * 'WP_REST_Request' ) )`, and its version models only construct/set_param/
 * get_param — it carries no headers at all. The bridge under test must set
 * `X-ET-Nonce` on the request it dispatches, so the thin version cannot exercise
 * the production path.
 *
 * Declared here, BEFORE the shim is required, so the shim's own guard yields.
 * This is CONTRIBUTING's option 1 — a dedicated stub, guarded, transcribed from
 * core, modelling a primitive — rather than widening the shared shim, which the
 * contract forbids and which would change every other suite's harness.
 *
 * Transcribed from `wp-includes/rest-api/class-wp-rest-request.php`:
 * `canonicalize_header_name()` is `strtolower( str_replace( '_', '-', $key ) )`;
 * headers are stored as arrays and `get_header()` returns them imploded with a
 * comma, or null when absent; `set_header()` replaces and `add_header()` appends.
 * The ArrayAccess surface and `set_param`/`get_param` mirror the shim's so the
 * code path is identical either way.
 */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess {
		/** @var array */
		protected $params = array();
		/** @var array */
		protected $headers = array();
		/** @var string */
		protected $method = '';
		/** @var string */
		protected $route = '';

		public function __construct( $method = '', $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		/** Core: canonicalize_header_name(). @param string $key @return string */
		public static function canonicalize_header_name( $key ) {
			return strtolower( str_replace( '_', '-', (string) $key ) );
		}

		public function set_header( $key, $value ) {
			$this->headers[ self::canonicalize_header_name( $key ) ] = (array) $value;
		}

		public function add_header( $key, $value ) {
			$k = self::canonicalize_header_name( $key );
			if ( ! isset( $this->headers[ $k ] ) ) {
				$this->headers[ $k ] = array();
			}
			$this->headers[ $k ][] = $value;
		}

		public function get_header( $key ) {
			$k = self::canonicalize_header_name( $key );
			return isset( $this->headers[ $k ] ) ? implode( ',', $this->headers[ $k ] ) : null;
		}

		public function get_headers() {
			return $this->headers;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_params() {
			return $this->params;
		}

		public function get_method() {
			return $this->method;
		}

		public function get_route() {
			return $this->route;
		}

		#[\ReturnTypeWillChange]
		public function offsetExists( $offset ) {
			return isset( $this->params[ $offset ] );
		}

		#[\ReturnTypeWillChange]
		public function offsetGet( $offset ) {
			return $this->params[ $offset ] ?? null;
		}

		#[\ReturnTypeWillChange]
		public function offsetSet( $offset, $value ) {
			$this->params[ $offset ] = $value;
		}

		#[\ReturnTypeWillChange]
		public function offsetUnset( $offset ) {
			unset( $this->params[ $offset ] );
		}
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Core: `wp-includes/pluggable.php`. Core's token also binds the user, the
	 * session token and the time tick; none of those vary within one test
	 * process, so the action alone reproduces the behaviour that matters.
	 *
	 * @param string $action Nonce action.
	 * @return string
	 */
	function wp_create_nonce( $action = -1 ) {
		return substr( hash( 'sha256', 'diviops-test-nonce|' . (string) $action ), 0, 10 );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Core: `wp-includes/pluggable.php`. Returns 1 for the freshest tick, 2 for
	 * the grace window, false otherwise. Only 1 and false are reachable here.
	 *
	 * @param string $nonce  Token.
	 * @param string $action Nonce action.
	 * @return int|false
	 */
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : false;
	}
}

/**
 * Responses `rest_do_request()` will return, keyed by "<METHOD> <route>".
 *
 * A route with no entry is a 404, which is what core does for an unregistered
 * route and is the shape a wrong route built by the bridge would really hit.
 */
$GLOBALS['diviops_drb_dispatch']  = array();
$GLOBALS['diviops_drb_requests']  = array();

if ( ! function_exists( 'rest_do_request' ) ) {
	/**
	 * Core: `wp-includes/rest-api.php` — `rest_get_server()->dispatch( $request )`.
	 *
	 * Records every dispatched request so a test can assert what the bridge
	 * actually built, and refuses to answer a request carrying no `X-ET-Nonce`
	 * so a bridge that forgot the header cannot pass by accident. That refusal
	 * models Divi's gate shape (400 `invalid_nonce`), which is the one thing the
	 * live probe confirmed and is therefore transcribed rather than invented.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	function rest_do_request( $request ) {
		$route  = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		$method = method_exists( $request, 'get_method' ) ? (string) $request->get_method() : 'GET';
		$nonce  = method_exists( $request, 'get_header' ) ? $request->get_header( 'X-ET-Nonce' ) : null;

		$GLOBALS['diviops_drb_requests'][] = array(
			'route'  => $route,
			'method' => $method,
			'nonce'  => $nonce,
			'params' => method_exists( $request, 'get_params' ) ? $request->get_params() : array(),
		);

		$expected = ltrim( $route, '/' );
		$action   = '/' . $expected . '--' . $method;
		if ( ! is_string( $nonce ) || '' === $nonce || 1 !== wp_verify_nonce( $nonce, $action ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'invalid_nonce',
					'message' => 'Invalid nonce.',
				),
				400
			);
		}

		$key = $method . ' ' . $route;
		if ( ! array_key_exists( $key, $GLOBALS['diviops_drb_dispatch'] ) ) {
			return new WP_REST_Response(
				array(
					'code'    => 'rest_no_route',
					'message' => 'No route was found matching the URL and request method.',
				),
				404
			);
		}
		$entry = $GLOBALS['diviops_drb_dispatch'][ $key ];
		return new WP_REST_Response( $entry['data'], $entry['status'] );
	}
}

/**
 * Register a canned Divi response.
 *
 * @param string $method HTTP method.
 * @param string $route  Full route, e.g. /divi/v1/loop/query-types.
 * @param mixed  $data   Payload.
 * @param int    $status HTTP status.
 * @return void
 */
function diviops_drb_route( string $method, string $route, $data, int $status = 200 ) {
	$GLOBALS['diviops_drb_dispatch'][ $method . ' ' . $route ] = array(
		'data'   => $data,
		'status' => $status,
	);
}

/** Forget every recorded dispatch. @return void */
function diviops_drb_reset_requests() {
	$GLOBALS['diviops_drb_requests'] = array();
}

/** The last request the bridge dispatched, or null. @return array|null */
function diviops_drb_last_request() {
	$n = count( $GLOBALS['diviops_drb_requests'] );
	return $n > 0 ? $GLOBALS['diviops_drb_requests'][ $n - 1 ] : null;
}

if ( ! class_exists( 'ET\Builder\Framework\Controllers\RESTController' ) ) {
	// Its own file, not an eval: a namespaced class cannot be declared inside a
	// conditional block. Same reason tests/fixtures/divi-rest-classes.php exists.
	require_once __DIR__ . '/fixtures/divi-read-bridge-rest-controller.php';
}
