<?php
/**
 * Minimal WordPress shim for the plain-PHP test harness.
 *
 * The harness in tests/run.php has no WordPress and no Composer — it is plain PHP
 * with no autoloader and no bootstrap beyond require(). Loading the real plugin
 * file (plugins/diviops-agent/diviops-agent.php) still requires a handful of
 * WordPress symbols to exist at require-time: DiviOps_Agent::init() runs
 * unconditionally at file scope and calls add_action()/add_filter(), and several
 * scanner methods call wp_strip_all_tags(), WP_Error, and is_wp_error(). This file
 * defines just enough of those symbols to let the real plugin load unmodified, then
 * exposes Reflection helpers so tests can call its private static methods directly.
 *
 * Safe to require_once from multiple test files in the same process: every symbol
 * is guarded, and the plugin file itself is only ever required once.
 *
 * @package DiviOps
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = (string) $string;
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}
		return trim( $string );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		private $errors      = array();
		private $error_data  = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? $codes[0] : '';
		}

		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		public function get_error_data( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'DiviOps_Agent' ) ) {
	require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';
}

if ( ! function_exists( 'diviops_call' ) ) {
	/**
	 * Call a private static method on DiviOps_Agent by value.
	 *
	 * Use this for every method except one that takes a by-reference parameter
	 * (currently only parse_block_tree's &$counters) — use diviops_call_ref for that.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 * @return mixed
	 */
	function diviops_call( string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( 'DiviOps_Agent', $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		return $reflection->invoke( null, ...$args );
	}
}

if ( ! function_exists( 'diviops_call_ref' ) ) {
	/**
	 * Call a private static method on DiviOps_Agent, binding arguments by reference.
	 *
	 * Required for parse_block_tree, whose &$counters parameter must accumulate
	 * across recursive calls — ReflectionMethod::invoke() with a spread copies
	 * arguments and silently fails to bind the reference, so this uses invokeArgs()
	 * with the caller's array passed by reference instead.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments, passed by reference.
	 * @return mixed
	 */
	function diviops_call_ref( string $method, array &$args ) {
		$reflection = new ReflectionMethod( 'DiviOps_Agent', $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		return $reflection->invokeArgs( null, $args );
	}
}
