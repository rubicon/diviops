<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Read-only bridge to two of Divi's own REST families (#504).
 *
 * @package DiviOps_Agent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allowlists and identifiers for the Divi read bridge.
 *
 * A helper `final class` rather than constants on the trait, matching
 * `DiviOps_Compatibility_Divi` and `DiviOps_SEO_TSF_Adapter`: trait constants
 * require PHP 8.2 and this plugin's floor is PHP 7.4, where a trait with a
 * `const` does not parse at all.
 *
 * Both lists were read off a live install by enumerating
 * `rest_get_server()->get_routes()`, not assembled from documentation. Divi
 * 5.13.1 serves 165 `divi/v1` routes, 55 of them GET-capable; these sixteen are
 * every GET route in the two families. `tests/test-divi-read-bridge.php` pins
 * both lists against that enumeration, so adding a name here fails the suite
 * until the enumeration is redone.
 */
final class DiviOps_Divi_Read_Bridge {
	const DIVI_NAMESPACE = 'divi/v1';
	const CONTROLLER     = 'ET\Builder\Framework\Controllers\RESTController';
	const LOOP_PREFIX    = 'loop';
	const COND_PREFIX    = 'option-data/conditions';

	const LOOP_SUBROUTES = array(
		'custom-field-options',
		'custom-field-value-options',
		'field-list-items',
		'product-price-range',
		'query-order-by',
		'query-posts',
		'query-results',
		'query-taxonomies',
		'query-types',
	);

	const CONDITIONS_SUBROUTES = array(
		'author',
		'categories',
		'post-meta-fields',
		'post-type',
		'posts',
		'tags',
		'user-role',
	);
}

trait DiviOps_Agent_Divi_Read {

	/**
	 * Read one of Divi's `loop/*` routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function divi_loop_read( $request ) {
		return self::divi_read_forward(
			DiviOps_Divi_Read_Bridge::LOOP_PREFIX,
			DiviOps_Divi_Read_Bridge::LOOP_SUBROUTES,
			$request
		);
	}

	/**
	 * Read one of Divi's `option-data/conditions/*` routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function divi_conditions_read( $request ) {
		return self::divi_read_forward(
			DiviOps_Divi_Read_Bridge::COND_PREFIX,
			DiviOps_Divi_Read_Bridge::CONDITIONS_SUBROUTES,
			$request
		);
	}

	/**
	 * Mint Divi's CSRF nonce, dispatch one allowlisted GET in-process, and wrap
	 * the answer in this plugin's envelope.
	 *
	 * **Why in-process and not a nonce handed to the client.** #504 proposed
	 * returning the token so the MCP client could call Divi directly. Dispatching
	 * here instead keeps the CSRF token on the server, costs the caller one
	 * request rather than two, and keeps the answer inside the `{ ok, data, error }`
	 * contract every other tool honours. A client holding a Divi nonce would also
	 * be a client able to call any of the other 149 `divi/v1` routes, which is
	 * exactly the general bridge this issue decided against.
	 *
	 * **Why an allowlist and not a pattern.** The standing objection to driving
	 * Divi's REST surface is semantic drift: an undocumented endpoint that changes
	 * behaviour returns 200 and means something different. That objection bites
	 * hardest on writes and on output we would reinterpret. Forwarding a named GET
	 * verbatim is the narrow case where it does not, so the allowlist is the
	 * boundary of the claim — not a performance detail.
	 *
	 * @param string          $prefix   Family prefix within the Divi namespace.
	 * @param array           $allowed  Subroutes this family permits.
	 * @param WP_REST_Request $request  Request.
	 * @return WP_REST_Response
	 */
	private static function divi_read_forward( string $prefix, array $allowed, $request ) {
		$subroute = $request['subroute'] ?? $request->get_param( 'subroute' );

		if ( ! is_string( $subroute ) || ! in_array( $subroute, $allowed, true ) ) {
			return self::envelope_error(
				'invalid_input',
				'Unknown or disallowed Divi subroute.',
				'This bridge forwards a fixed list of read-only Divi routes. Use one of the names in error.data.allowed.',
				400,
				[
					'received' => is_string( $subroute ) ? $subroute : null,
					'allowed'  => array_values( $allowed ),
				]
			);
		}

		$args = $request->get_param( 'args' );
		if ( null !== $args ) {
			if ( ! is_array( $args ) ) {
				return self::envelope_error(
					'invalid_input',
					'args must be an object of scalar values.',
					'Pass args as a flat map, e.g. { "post_type": "page" }.',
					400
				);
			}
			foreach ( $args as $key => $value ) {
				if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z][a-z0-9_]*$/D', $key ) ) {
					return self::envelope_error(
						'invalid_input',
						'args keys must be lowercase alphanumeric with underscores.',
						'Divi\'s own parameter names all take this shape; anything else is a caller mistake rather than a route we should guess at.',
						400,
						[ 'key' => is_string( $key ) ? $key : null ]
					);
				}
				if ( ! is_scalar( $value ) && null !== $value ) {
					return self::envelope_error(
						'invalid_input',
						'args values must be scalars.',
						'A nested structure cannot be forwarded as a query parameter; send the scalar Divi expects.',
						400,
						[ 'key' => $key ]
					);
				}
			}
		}

		$controller = DiviOps_Divi_Read_Bridge::CONTROLLER;
		if ( ! class_exists( $controller ) || ! is_callable( [ $controller, 'create_nonce' ] ) ) {
			return self::envelope_error(
				'divi_unavailable',
				'Divi\'s REST controller is not loaded, so its CSRF nonce cannot be minted.',
				'This bridge requires Divi 5 active on the site. Without the nonce every Divi route answers 400 invalid_nonce.',
				503
			);
		}
		if ( ! function_exists( 'rest_do_request' ) ) {
			return self::envelope_error(
				'divi_unavailable',
				'WordPress REST dispatch is unavailable.',
				'rest_do_request() is required to forward the read in-process.',
				503
			);
		}

		$route      = '/' . $prefix . '/' . $subroute;
		$full_route = '/' . DiviOps_Divi_Read_Bridge::DIVI_NAMESPACE . $route;
		$nonce      = $controller::create_nonce( DiviOps_Divi_Read_Bridge::DIVI_NAMESPACE, $route, 'GET' );

		$divi_request = new WP_REST_Request( 'GET', $full_route );
		$divi_request->set_header( 'X-ET-Nonce', $nonce );
		if ( is_array( $args ) ) {
			foreach ( $args as $key => $value ) {
				$divi_request->set_param( $key, $value );
			}
		}

		$response = rest_do_request( $divi_request );
		$status   = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 500;
		$data     = method_exists( $response, 'get_data' ) ? $response->get_data() : null;

		if ( $status < 200 || $status >= 300 ) {
			$code = is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : null;
			return self::envelope_error(
				'divi_route_failed',
				'Divi refused the read.',
				'The failure is Divi\'s, not this bridge\'s. error.data carries its status and code so a caller can tell a permission problem from a bad parameter.',
				$status,
				[
					'route'       => $full_route,
					'divi_status' => $status,
					'divi_code'   => $code,
				]
			);
		}

		return self::envelope_success(
			[
				'route' => $full_route,
				'divi'  => $data,
			]
		);
	}
}
