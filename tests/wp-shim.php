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

if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	/**
	 * Registry stub mirroring what the reference install actually reports:
	 * third-party Divi modules register under the `module` / `child-module`
	 * categories, unrelated plugin blocks do not, and Divi's own modules are
	 * largely absent from the registry entirely.
	 */
	class WP_Block_Type_Registry {

		private static $instance = null;

		private $blocks = array(
			'difl/faq'          => 'module',
			'difl/faqitem'      => 'child-module',
			'difl/counter'      => 'module',
			'd5bgo/bg-overlay'  => 'module',
			'gravityforms/form' => '',
			'tec/event'         => '',
			'core/paragraph'    => 'text',
		);

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function get_registered( $name ) {
			if ( ! isset( $this->blocks[ $name ] ) ) {
				return null;
			}
			$type           = new stdClass();
			$type->name     = $name;
			$type->category = $this->blocks[ $name ];
			return $type;
		}

		public function get_all_registered() {
			$all = array();
			foreach ( array_keys( $this->blocks ) as $name ) {
				$all[ $name ] = $this->get_registered( $name );
			}
			return $all;
		}
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		$value = preg_replace( '/[\r\n\t ]+/', ' ', (string) $value );
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( ...$args ) {
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'serialize_block_attributes' ) ) {
	/**
	 * Reimplementation of WordPress core's serialize_block_attributes(): JSON-encode
	 * the attrs, then apply core's exact substitution table for bytes that would
	 * otherwise break out of the block comment or the JSON string (a literal
	 * backslash, `--`, `<`, `>`, `&`, and an escaped double-quote).
	 */
	function serialize_block_attributes( $block_attributes ) {
		$encoded_attributes = wp_json_encode( $block_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return strtr(
			$encoded_attributes,
			array(
				'\\\\' => '\\u005c',
				'--'   => '\\u002d\\u002d',
				'<'    => '\\u003c',
				'>'    => '\\u003e',
				'&'    => '\\u0026',
				'\\"'  => '\\u0022',
			)
		);
	}
}

if ( ! function_exists( 'get_comment_delimited_block_content' ) ) {
	/**
	 * Reimplementation of WordPress core's block-comment serializer: wrap a
	 * block name, its JSON attrs, and its rendered inner content in the
	 * `<!-- wp:name {...} -->...<!-- /wp:name -->` comment form, or the
	 * self-closing `<!-- wp:name {...} /-->` form when content is empty.
	 *
	 * Attribute escaping matches core's serialize_block_attributes() exactly.
	 * Unlike core, this does not strip a `core/` block namespace for display,
	 * since nothing in this codebase serializes a core/-namespaced block
	 * through this path.
	 */
	function get_comment_delimited_block_content( $block_name, $block_attributes, $block_content ) {
		if ( null === $block_name ) {
			return $block_content;
		}

		$serialized_attributes = empty( $block_attributes ) ? '' : serialize_block_attributes( $block_attributes ) . ' ';

		if ( '' === $block_content ) {
			return '<!-- wp:' . $block_name . ' ' . $serialized_attributes . '/-->';
		}

		return '<!-- wp:' . $block_name . ' ' . $serialized_attributes . '-->' . $block_content . '<!-- /wp:' . $block_name . ' -->';
	}
}

if ( ! function_exists( 'serialize_block' ) ) {
	/**
	 * Reimplementation of WordPress core's serialize_block(): rebuild a
	 * block's comment-delimited markup from its parsed array, recursing into
	 * innerBlocks via innerContent's null placeholders, with the same
	 * attribute escaping as get_comment_delimited_block_content(). collect_
	 * readable_divi_blocks() and collect_parser_move_blocks() always pass a
	 * shallow copy with innerContent/innerBlocks already emptied for this
	 * call, so the recursive branch never runs in practice here.
	 */
	function serialize_block( $block ) {
		$block_content = '';
		$index         = 0;
		foreach ( $block['innerContent'] ?? array() as $chunk ) {
			$block_content .= is_string( $chunk ) ? $chunk : serialize_block( $block['innerBlocks'][ $index++ ] );
		}

		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

		return get_comment_delimited_block_content( $block['blockName'] ?? null, $attrs, $block_content );
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Just enough of WP_REST_Response for envelope_success()/envelope_error() to
	 * build a response and for tests to read it back via get_data().
	 */
	class WP_REST_Response {

		private $data;
		private $status;

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function set_data( $data ) {
			$this->data = $data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! isset( $GLOBALS['diviops_test_posts'] ) ) {
	$GLOBALS['diviops_test_posts'] = array();
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return $GLOBALS['diviops_test_posts'][ $post_id ] ?? null;
	}
}

if ( ! function_exists( 'diviops_test_register_post' ) ) {
	/**
	 * Register a fake post for get_post() to return, so a REST-handler method
	 * (one that takes a $request rather than raw content, like module_update())
	 * can be exercised directly against the real plugin code instead of only
	 * through its content-scanning internals.
	 *
	 * @param int    $post_id Post id.
	 * @param string $content post_content.
	 * @return object
	 */
	function diviops_test_register_post( int $post_id, string $content ) {
		$post = (object) array(
			'ID'           => $post_id,
			'post_content' => $content,
		);
		$GLOBALS['diviops_test_posts'][ $post_id ] = $post;
		return $post;
	}
}

if ( ! class_exists( 'DiviOps_Test_Request' ) ) {
	/**
	 * Minimal stand-in for WP_REST_Request. get_param() plus array access for
	 * `$request['id']` is everything module_update()/resolve_module_target()
	 * call on the request object.
	 */
	class DiviOps_Test_Request implements ArrayAccess {

		private $params;

		public function __construct( array $params = array() ) {
			$this->params = $params;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		public function offsetGet( $offset ): mixed {
			return $this->params[ $offset ] ?? null;
		}

		public function offsetSet( $offset, $value ): void {
			$this->params[ $offset ] = $value;
		}

		public function offsetUnset( $offset ): void {
			unset( $this->params[ $offset ] );
		}
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
