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

		private $errors          = array();
		private $error_data      = array();
		private $additional_data = array();

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

		/**
		 * Reimplementation of WP_Error::add_data() (wp-includes/class-wp-error.php):
		 * overwrites error_data[$code] with the new value, stashing whatever was
		 * there before into additional_data[$code][] rather than merging it in.
		 * Callers that want to preserve existing data must merge it in themselves
		 * before calling this, exactly as WordPress core requires.
		 */
		public function add_data( $data, $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			if ( isset( $this->error_data[ $code ] ) ) {
				$this->additional_data[ $code ][] = $this->error_data[ $code ];
			}
			$this->error_data[ $code ] = $data;
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

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * No-op filter runner: add_filter() above never registers a callback, so
	 * there is nothing to run here. Returns $value unchanged, which is the
	 * correct behavior for a harness with zero registered filters, not a
	 * shortcut around one.
	 */
	function apply_filters( $tag, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Reimplementation of WordPress core's sanitize_key() (wp-includes/formatting.php):
	 * lowercase, then strip everything but a-z, 0-9, underscore, and hyphen.
	 */
	function sanitize_key( $key ) {
		$sanitized_key = '';

		if ( is_scalar( $key ) ) {
			$sanitized_key = strtolower( $key );
			$sanitized_key = preg_replace( '/[^a-z0-9_\-]/', '', $sanitized_key );
		}

		return apply_filters( 'sanitize_key', $sanitized_key, $key );
	}
}

if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	/**
	 * Reimplementation of WordPress core's rest_sanitize_boolean()
	 * (wp-includes/rest-api.php): a string 'false' or '0' (case-insensitive)
	 * sanitizes to false; everything else casts through (bool).
	 */
	function rest_sanitize_boolean( $value ) {
		if ( is_string( $value ) ) {
			$value = strtolower( $value );
			if ( in_array( $value, array( 'false', '0' ), true ) ) {
				$value = false;
			}
		}

		return (bool) $value;
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

if ( ! function_exists( 'wp_trash_post' ) ) {
	/**
	 * Model WP core's wp_trash_post() against the harness post registry: flip the
	 * post's status to 'trash' and return its data object on success, false when
	 * the id is unknown. This is the same category of primitive stub as get_post()
	 * above — WordPress core with a simple documented contract — not a fake of
	 * Divi's proprietary option storage, which the variable_update suite correctly
	 * declined to fabricate.
	 */
	function wp_trash_post( $post_id ) {
		if ( ! isset( $GLOBALS['diviops_test_posts'][ $post_id ] ) ) {
			return false;
		}
		$GLOBALS['diviops_test_posts'][ $post_id ]->post_status = 'trash';
		return $GLOBALS['diviops_test_posts'][ $post_id ];
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	/**
	 * Model WP core's wp_delete_post(): remove the post from the registry and
	 * return its data object on success, false when the id is unknown. The
	 * $force_delete argument is accepted for signature parity; this store has no
	 * trash-vs-permanent distinction to honor, so a hard removal models both the
	 * force path and (for callers that pass false) the trash-bypass fallback.
	 */
	function wp_delete_post( $post_id, $force_delete = false ) {
		if ( ! isset( $GLOBALS['diviops_test_posts'][ $post_id ] ) ) {
			return false;
		}
		$post = $GLOBALS['diviops_test_posts'][ $post_id ];
		unset( $GLOBALS['diviops_test_posts'][ $post_id ] );
		return $post;
	}
}

if ( ! function_exists( 'diviops_test_register_post' ) ) {
	/**
	 * Register a fake post for get_post() to return, so a REST-handler method
	 * (one that takes a $request rather than raw content, like module_update())
	 * can be exercised directly against the real plugin code instead of only
	 * through its content-scanning internals.
	 *
	 * post_type and post_title default to values module_update() and friends
	 * never read, but module_get() builds its response from them directly
	 * (no null-coalescing), so a caller exercising that handler needs a
	 * complete-enough fixture or PHP emits an "Undefined property" warning.
	 *
	 * @param int    $post_id    Post id.
	 * @param string $content    post_content.
	 * @param string $post_type  post_type.
	 * @param string $post_title post_title.
	 * @return object
	 */
	function diviops_test_register_post( int $post_id, string $content, string $post_type = 'page', string $post_title = '' ) {
		$post = (object) array(
			'ID'           => $post_id,
			'post_content' => $content,
			'post_type'    => $post_type,
			'post_title'   => $post_title,
		);
		$GLOBALS['diviops_test_posts'][ $post_id ] = $post;
		return $post;
	}
}

if ( ! isset( $GLOBALS['diviops_test_post_types'] ) ) {
	// The default WordPress content post types; tests register more as needed.
	$GLOBALS['diviops_test_post_types'] = array( 'page' => true, 'post' => true );
}

if ( ! function_exists( 'post_type_exists' ) ) {
	/**
	 * Model WP core's post_type_exists(): membership in the registered set.
	 */
	function post_type_exists( $post_type ) {
		return isset( $GLOBALS['diviops_test_post_types'][ (string) $post_type ] );
	}
}

if ( ! function_exists( 'get_post_stati' ) ) {
	/**
	 * Model WP core's get_post_stati() for the non-internal statuses page_create
	 * validates against. Values are the status names, matching how the handler
	 * consumes them (in_array on values, array_values for the error payload).
	 */
	function get_post_stati( $args = array() ) {
		return array(
			'publish' => 'publish',
			'future'  => 'future',
			'draft'   => 'draft',
			'pending' => 'pending',
			'private' => 'private',
		);
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	/**
	 * Model WP core's wp_insert_post(): assign an incrementing id, store the post
	 * in the registry, and record the args of the most recent call so a test can
	 * assert what the handler asked WordPress to create (e.g. the post_type it
	 * resolved). Returns the new id. This records HANDLER input, it does not fake
	 * handler behavior.
	 */
	function wp_insert_post( $postarr, $wp_error = false ) {
		$GLOBALS['diviops_test_last_insert'] = $postarr;
		$id = ( $GLOBALS['diviops_test_next_id'] ?? 9000 );
		$GLOBALS['diviops_test_next_id'] = $id + 1;
		$GLOBALS['diviops_test_posts'][ $id ] = (object) array(
			'ID'           => $id,
			'post_content' => $postarr['post_content'] ?? '',
			'post_type'    => $postarr['post_type'] ?? 'post',
			'post_title'   => $postarr['post_title'] ?? '',
			'post_status'  => $postarr['post_status'] ?? 'draft',
		);
		return $id;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['diviops_test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) {
		return 'http://example.test/?p=' . (int) $post_id;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'http://example.test/wp-admin/' . ltrim( (string) $path, '/' );
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
