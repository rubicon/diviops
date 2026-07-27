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

if ( ! function_exists( 'has_filter' ) ) {
	/**
	 * Model WP core's has_filter(): whether a callback is registered on $hook.
	 * add_filter() above is a no-op that never records anything, so this is
	 * driven directly by the test seam $GLOBALS['diviops_test_filters'], a map
	 * of hook name => priority (or any truthy value), mirroring core's return
	 * of the matched priority (or false when nothing is registered).
	 */
	function has_filter( $hook, $callback = false ) {
		return $GLOBALS['diviops_test_filters'][ $hook ] ?? false;
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

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Thin passthrough over parse_url() for the test harness.
	 *
	 * WordPress's real wp_parse_url() additionally handles scheme-less URLs
	 * (e.g. "//example.com/a.png") by faking a scheme before delegating to
	 * parse_url() and stripping it back out. None of media_url_is_safe()'s
	 * test cases are scheme-less, so a plain passthrough is sufficient here.
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component Optional PHP_URL_* component constant.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
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
	 *
	 * Nav-menu items are posts of type nav_menu_item in real WordPress, so
	 * wp_delete_post is exactly how menu_item_remove destroys them. This store
	 * keeps nav items in their own registry (see diviops_test_register_nav_menu_item),
	 * so this checks that registry first and removes from it, then falls back to
	 * the ordinary post registry — the same primitive, routed to whichever store
	 * actually holds the id.
	 */
	function wp_delete_post( $post_id, $force_delete = false ) {
		if ( isset( $GLOBALS['diviops_test_nav_menu_items'][ $post_id ] ) ) {
			$item = $GLOBALS['diviops_test_nav_menu_items'][ $post_id ];
			unset( $GLOBALS['diviops_test_nav_menu_items'][ $post_id ] );
			return $item;
		}
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

// ── Native WordPress post revisions ───────────────────────────────────────
//
// WordPress stores a revision as a post of type `revision` whose post_parent is
// the edited post; wp_get_post_revisions() enumerates a post's revisions (newest
// first, keyed by revision id) and wp_restore_post_revision() copies a revision's
// content back onto its parent, returning the parent id. These shims model that
// WP-core contract against the shared post registry (get_post already finds a
// revision registered here, since it lives in the same store) — the same category
// of primitive stub as get_post()/wp_insert_post() above, NOT a fake of Divi's
// proprietary storage.

if ( ! function_exists( 'diviops_test_register_revision' ) ) {
	/**
	 * Register a fake revision (a post of type `revision` linked to a parent).
	 * Stored in the shared post registry so get_post() resolves it, exactly as a
	 * real revision is an ordinary row in wp_posts.
	 *
	 * @param array $fields ID, post_parent, post_content, post_author, post_date,
	 *                      post_modified (defaults to post_date), post_title.
	 * @return object
	 */
	function diviops_test_register_revision( array $fields ) {
		$id       = (int) ( $fields['ID'] ?? 0 );
		$date     = (string) ( $fields['post_date'] ?? '' );
		$revision = (object) array(
			'ID'            => $id,
			'post_type'     => 'revision',
			'post_parent'   => (int) ( $fields['post_parent'] ?? 0 ),
			'post_content'  => (string) ( $fields['post_content'] ?? '' ),
			'post_author'   => (int) ( $fields['post_author'] ?? 0 ),
			'post_date'     => $date,
			'post_modified' => (string) ( $fields['post_modified'] ?? $date ),
			'post_title'    => (string) ( $fields['post_title'] ?? '' ),
		);
		$GLOBALS['diviops_test_posts'][ $id ] = $revision;
		return $revision;
	}
}

if ( ! function_exists( 'wp_get_post_revisions' ) ) {
	/**
	 * Model WP core's wp_get_post_revisions(): every post of type `revision` whose
	 * post_parent is $post_id, ordered newest-first (post_date DESC, then ID DESC,
	 * matching core's default ordering) and keyed by revision id. Honors
	 * posts_per_page: -1 (or absent) returns all, a positive value truncates.
	 * Returns [] for an unknown post, exactly as core returns an empty set.
	 */
	function wp_get_post_revisions( $post_id = 0, $args = array() ) {
		$post_id   = is_object( $post_id ) ? (int) $post_id->ID : (int) $post_id;
		$revisions = array();
		foreach ( $GLOBALS['diviops_test_posts'] as $post ) {
			if ( 'revision' === (string) ( $post->post_type ?? '' ) && (int) ( $post->post_parent ?? 0 ) === $post_id ) {
				$revisions[ (int) $post->ID ] = $post;
			}
		}
		uasort(
			$revisions,
			static function ( $a, $b ) {
				$cmp = strcmp( (string) ( $b->post_date ?? '' ), (string) ( $a->post_date ?? '' ) );
				return 0 !== $cmp ? $cmp : ( (int) $b->ID <=> (int) $a->ID );
			}
		);
		$limit = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : -1;
		if ( $limit > 0 ) {
			$revisions = array_slice( $revisions, 0, $limit, true );
		}
		return $revisions;
	}
}

if ( ! function_exists( 'wp_restore_post_revision' ) ) {
	/**
	 * Model WP core's wp_restore_post_revision(): copy the revision's content back
	 * onto its parent post and return the parent id. Returns false when the id is
	 * not a revision or the parent is gone — the failure signals the restore handler
	 * branches on. Core also restores title/excerpt and fires hooks; content is the
	 * observable slice this store and the handler exercise.
	 */
	function wp_restore_post_revision( $revision_id, $fields = null ) {
		$revision = get_post( $revision_id );
		if ( ! is_object( $revision ) || 'revision' !== (string) ( $revision->post_type ?? '' ) ) {
			return false;
		}
		$parent_id = (int) ( $revision->post_parent ?? 0 );
		if ( ! isset( $GLOBALS['diviops_test_posts'][ $parent_id ] ) ) {
			return false;
		}
		$GLOBALS['diviops_test_posts'][ $parent_id ]->post_content = (string) ( $revision->post_content ?? '' );
		return $parent_id;
	}
}

// invalidate_divi_cache() (called by revision_restore after a successful restore)
// touches these WP primitives; model them minimally. WP_CONTENT_DIR points at a
// path that does not exist, so the static-CSS glob branch is skipped.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', __DIR__ . '/.diviops-nonexistent-wp-content' );
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) {
		return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key, $value = '' ) {
		unset( $GLOBALS['diviops_test_post_meta'][ $post_id ][ $key ] );
		return true;
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

// ── Nav-menu registry + primitives ────────────────────────────────────────
//
// WordPress models a nav menu as a `nav_menu` taxonomy term, its items as posts
// of type `nav_menu_item` linked to that term, and the item hierarchy/type/target
// in per-item post meta (menu_order is a post column, the parent lives in the
// `_menu_item_menu_item_parent` meta key). wp_get_nav_menu_items() re-reads that
// meta on every call via wp_setup_nav_menu_item(). These shims model that contract:
// the parent is stored in the shared post-meta registry (so update_post_meta on a
// child's parent key actually re-parents it), menu_order lives on the item record
// (so wp_update_post reorders it), and wp_get_nav_menu_items() hydrates a fresh
// view sorted by menu_order — exactly the surface the menu handlers depend on.

if ( ! isset( $GLOBALS['diviops_test_nav_menus'] ) ) {
	$GLOBALS['diviops_test_nav_menus'] = array();
}
if ( ! isset( $GLOBALS['diviops_test_nav_menu_items'] ) ) {
	$GLOBALS['diviops_test_nav_menu_items'] = array();
}
if ( ! isset( $GLOBALS['diviops_test_theme_mods'] ) ) {
	$GLOBALS['diviops_test_theme_mods'] = array();
}
if ( ! isset( $GLOBALS['diviops_test_registered_nav_menus'] ) ) {
	$GLOBALS['diviops_test_registered_nav_menus'] = array();
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Minimal sanitize_title(): lowercase, collapse runs of non-alnum to a single
	 * hyphen, trim leading/trailing hyphens. Enough for deriving a menu slug from
	 * its name in the nav-menu registration helper.
	 */
	function sanitize_title( $title ) {
		$title = strtolower( trim( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( (string) $title, '-' );
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Model WP core's get_post_meta() against the harness meta registry that
	 * update_post_meta() writes to. Honors the $single flag: a single read returns
	 * the scalar (or '' when unset), a non-single read returns a list. This is what
	 * lets wp_get_nav_menu_items() read each item's `_menu_item_menu_item_parent`.
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$meta = $GLOBALS['diviops_test_post_meta'][ $post_id ] ?? array();
		if ( '' === $key ) {
			return $meta;
		}
		if ( ! array_key_exists( $key, $meta ) ) {
			return $single ? '' : array();
		}
		return $single ? $meta[ $key ] : array( $meta[ $key ] );
	}
}

if ( ! function_exists( 'wp_get_nav_menu_object' ) ) {
	/**
	 * Model WP core's wp_get_nav_menu_object(): resolve a menu by id (or accept an
	 * object as-is) to its term object, false when unknown.
	 */
	function wp_get_nav_menu_object( $menu ) {
		if ( is_object( $menu ) ) {
			return $menu;
		}
		$menu_id = (int) $menu;
		return $GLOBALS['diviops_test_nav_menus'][ $menu_id ] ?? false;
	}
}

if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
	/**
	 * Model WP core's wp_get_nav_menu_items(): return the items belonging to a menu
	 * ordered by menu_order, each with menu_item_parent hydrated from post meta
	 * (mirroring wp_setup_nav_menu_item), or false when the menu term is unknown.
	 * The item objects are fresh clones so mutating a returned row cannot corrupt
	 * the registry, exactly as core hands back per-call hydrated objects.
	 */
	function wp_get_nav_menu_items( $menu, $args = array() ) {
		$menu_id = is_object( $menu ) ? (int) $menu->term_id : (int) $menu;
		if ( ! isset( $GLOBALS['diviops_test_nav_menus'][ $menu_id ] ) ) {
			return false;
		}
		$items = array();
		foreach ( $GLOBALS['diviops_test_nav_menu_items'] as $base ) {
			if ( (int) $base->menu_id !== $menu_id ) {
				continue;
			}
			$item                   = clone $base;
			$item->menu_item_parent = (int) get_post_meta( $item->ID, '_menu_item_menu_item_parent', true );
			$items[]                = $item;
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return (int) $a->menu_order <=> (int) $b->menu_order;
			}
		);
		return $items;
	}
}

if ( ! function_exists( 'get_nav_menu_locations' ) ) {
	/**
	 * Model WP core's get_nav_menu_locations(): the `nav_menu_locations` theme mod,
	 * a map of theme-location key => menu term id. set_theme_mod writes the same
	 * slot, so assign/unassign round-trip through here.
	 */
	function get_nav_menu_locations() {
		$locations = $GLOBALS['diviops_test_theme_mods']['nav_menu_locations'] ?? array();
		return is_array( $locations ) ? $locations : array();
	}
}

if ( ! function_exists( 'get_registered_nav_menus' ) ) {
	/**
	 * Model WP core's get_registered_nav_menus(): the theme-declared locations,
	 * a map of location key => human label.
	 */
	function get_registered_nav_menus() {
		return $GLOBALS['diviops_test_registered_nav_menus'];
	}
}

if ( ! function_exists( 'set_theme_mod' ) ) {
	/**
	 * Model WP core's set_theme_mod(): store a theme mod by name. get_nav_menu_locations
	 * reads the `nav_menu_locations` slot this writes.
	 */
	function set_theme_mod( $name, $value ) {
		$GLOBALS['diviops_test_theme_mods'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	/**
	 * Model WP core's wp_update_post() for the columns the menu handlers touch.
	 * Nav-menu items are posts, so a reorder writes each item's menu_order through
	 * here; this routes an ID in the nav-item registry to that record's menu_order
	 * (and title/url if supplied), and any other ID to the ordinary post registry.
	 * Returns the post id on success, 0 on failure — matching core's contract.
	 */
	function wp_update_post( $postarr, $wp_error = false ) {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( $id <= 0 ) {
			return 0;
		}
		if ( isset( $GLOBALS['diviops_test_nav_menu_items'][ $id ] ) ) {
			$item = $GLOBALS['diviops_test_nav_menu_items'][ $id ];
			if ( array_key_exists( 'menu_order', $postarr ) ) {
				$item->menu_order = (int) $postarr['menu_order'];
			}
			if ( array_key_exists( 'post_title', $postarr ) ) {
				$item->title = (string) $postarr['post_title'];
			}
			return $id;
		}
		if ( isset( $GLOBALS['diviops_test_posts'][ $id ] ) ) {
			foreach ( $postarr as $key => $value ) {
				if ( 'ID' !== $key ) {
					$GLOBALS['diviops_test_posts'][ $id ]->$key = $value;
				}
			}
			return $id;
		}
		return 0;
	}
}

if ( ! function_exists( 'wp_delete_nav_menu' ) ) {
	/**
	 * Model WP core's wp_delete_nav_menu(): permanently remove the menu term, all
	 * of its items, and free every theme location that pointed at it. Nav menus
	 * have no trash, so this is a hard removal. Returns true on success, a WP_Error
	 * when the menu id is unknown — the two outcomes menu_delete branches on.
	 */
	function wp_delete_nav_menu( $menu ) {
		$menu_id = is_object( $menu ) ? (int) $menu->term_id : (int) $menu;
		if ( ! isset( $GLOBALS['diviops_test_nav_menus'][ $menu_id ] ) ) {
			return new WP_Error( 'nav_menu_not_found', 'Nav menu does not exist.' );
		}
		foreach ( $GLOBALS['diviops_test_nav_menu_items'] as $item_id => $base ) {
			if ( (int) $base->menu_id === $menu_id ) {
				unset( $GLOBALS['diviops_test_nav_menu_items'][ $item_id ] );
			}
		}
		$locations = get_nav_menu_locations();
		$changed   = false;
		foreach ( $locations as $location => $assigned_id ) {
			if ( (int) $assigned_id === $menu_id ) {
				unset( $locations[ $location ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			set_theme_mod( 'nav_menu_locations', $locations );
		}
		unset( $GLOBALS['diviops_test_nav_menus'][ $menu_id ] );
		return true;
	}
}

if ( ! function_exists( 'diviops_test_register_nav_menu' ) ) {
	/**
	 * Register a fake nav menu term so the menu handlers can be exercised directly.
	 *
	 * @param int    $term_id Menu term id.
	 * @param string $name    Menu display name.
	 * @param string $slug    Optional slug; derived from the name when omitted.
	 * @return object
	 */
	function diviops_test_register_nav_menu( int $term_id, string $name, string $slug = '' ) {
		$menu = (object) array(
			'term_id' => $term_id,
			'name'    => $name,
			'slug'    => '' !== $slug ? $slug : sanitize_title( $name ),
			'count'   => 0,
		);
		$GLOBALS['diviops_test_nav_menus'][ $term_id ] = $menu;
		return $menu;
	}
}

if ( ! function_exists( 'diviops_test_register_nav_menu_item' ) ) {
	/**
	 * Register a fake nav-menu item linked to a menu. The parent is written to the
	 * `_menu_item_menu_item_parent` post meta (where wp_get_nav_menu_items reads it),
	 * not stored on the record, so re-parenting through update_post_meta behaves
	 * exactly as it does in WordPress.
	 *
	 * @param array $fields ID, menu_id, title, url, type, object, object_id,
	 *                      menu_order, parent.
	 * @return object
	 */
	function diviops_test_register_nav_menu_item( array $fields ) {
		$id   = (int) ( $fields['ID'] ?? 0 );
		$item = (object) array(
			'ID'         => $id,
			'menu_id'    => (int) ( $fields['menu_id'] ?? 0 ),
			'title'      => (string) ( $fields['title'] ?? '' ),
			'url'        => (string) ( $fields['url'] ?? '' ),
			'type'       => (string) ( $fields['type'] ?? 'custom' ),
			'object'     => (string) ( $fields['object'] ?? '' ),
			'object_id'  => (int) ( $fields['object_id'] ?? 0 ),
			'menu_order' => (int) ( $fields['menu_order'] ?? 0 ),
		);
		$GLOBALS['diviops_test_nav_menu_items'][ $id ] = $item;
		update_post_meta( $id, '_menu_item_menu_item_parent', (int) ( $fields['parent'] ?? 0 ) );
		return $item;
	}
}

if ( ! function_exists( 'get_allowed_mime_types' ) ) {
	/**
	 * Model WP core's get_allowed_mime_types(): the site's ext-pattern => mime
	 * map. Tests drive this via $GLOBALS['diviops_test_allowed_mimes']; absent
	 * that, falls back to a representative default set of image types.
	 */
	function get_allowed_mime_types( $user = null ) {
		return $GLOBALS['diviops_test_allowed_mimes'] ?? array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
		);
	}
}

if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
	/**
	 * Model WP core's wp_check_filetype_and_ext(): real-byte type detection
	 * against a declared filename. Test seam: $GLOBALS['diviops_test_filetype']
	 * maps filename => [ext,type,proper_filename], or the string 'mismatch' to
	 * model core's byte/extension-mismatch result (all three fields false).
	 * An unmapped filename also returns the all-false shape, matching core's
	 * behavior for a file it cannot identify.
	 */
	function wp_check_filetype_and_ext( $file, $filename, $mimes = null ) {
		$map = $GLOBALS['diviops_test_filetype'] ?? array();
		if ( isset( $map[ $filename ] ) && 'mismatch' === $map[ $filename ] ) {
			return array(
				'ext'             => false,
				'type'            => false,
				'proper_filename' => false,
			);
		}
		if ( isset( $map[ $filename ] ) ) {
			return $map[ $filename ];
		}
		return array(
			'ext'             => false,
			'type'            => false,
			'proper_filename' => false,
		);
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

if ( ! function_exists( 'diviops_call_static' ) ) {
	/**
	 * Call a private static method on DiviOps_Agent, with no request object.
	 *
	 * Mirrors diviops_call(), but for helpers that take plain positional args
	 * instead of a REST request — e.g. media_ip_is_reserved(), media_url_is_safe().
	 * Uses invokeArgs() rather than invoke(...$args) so an $args element built as
	 * a reference (e.g. `array( $url, &$reason, $resolver )`) stays bound: PHP
	 * preserves per-element reference status across an array copy, so invokeArgs()
	 * with the plain (non-reference) $args parameter still passes the reference
	 * through to the callee's by-reference parameter.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 * @return mixed
	 */
	function diviops_call_static( string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( 'DiviOps_Agent', $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		return $reflection->invokeArgs( null, $args );
	}
}
