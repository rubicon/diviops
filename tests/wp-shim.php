<?php
// SPDX-License-Identifier: MIT
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

// Marks this process as the test harness, NOT production. media_upload()
// (trait-media.php) consults this to gate the diviops_media_host_resolver
// filter to test-only — in production the filter is never consulted, so a
// hooked callback cannot bypass the real-DNS SSRF address guard.
if ( ! defined( 'DIVIOPS_TESTING' ) ) {
	define( 'DIVIOPS_TESTING', true );
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! isset( $GLOBALS['diviops_test_hooks'] ) ) {
	$GLOBALS['diviops_test_hooks'] = array();
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Real filter registration, keyed by hook then priority, mirroring core's
	 * add_filter()/apply_filters() contract closely enough for the extensibility
	 * seams this harness exercises (e.g. diviops_media_host_resolver): tests
	 * register a callback here and apply_filters() below actually invokes it,
	 * rather than the harness silently no-op'ing every filter hook.
	 */
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['diviops_test_hooks'][ $hook ][ $priority ][] = array(
			'function'      => $callback,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	/**
	 * Remove every callback registered on a hook (or just one priority when
	 * given). Mirrors core's remove_all_filters() contract; the test harness
	 * uses this to reset an extensibility seam (e.g. diviops_media_host_resolver)
	 * between cases instead of accumulating stale callbacks across assertions.
	 */
	function remove_all_filters( $hook, $priority = false ) {
		if ( false === $priority ) {
			unset( $GLOBALS['diviops_test_hooks'][ $hook ] );
		} else {
			unset( $GLOBALS['diviops_test_hooks'][ $hook ][ $priority ] );
		}
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
	/**
	 * Fixed-true stub, with two seams: a blanket capability-denial seam
	 * (`$GLOBALS['diviops_test_denied_caps']`, an array of cap strings — e.g.
	 * `array( 'upload_files' )` denies that cap outright regardless of any
	 * target argument) and a per-object seam for 'edit_post' whose target id
	 * is listed in $GLOBALS['diviops_test_uneditable_ids']. The per-object
	 * seam is what lets can_inspect_post_object()'s edit_post gate
	 * (trait-core.php) be exercised behaviorally instead of only via source
	 * inspection (see the comment in test-revision.php predating this seam).
	 * Every other capability/call shape not opted into one of these seams
	 * stays fixed-true, so existing tests keep passing.
	 */
	function current_user_can( ...$args ) {
		$cap = $args[0] ?? '';
		if ( in_array( $cap, (array) ( $GLOBALS['diviops_test_denied_caps'] ?? array() ), true ) ) {
			return false;
		}
		if ( 'edit_post' === $cap && isset( $args[1] ) ) {
			$target = $args[1];
			$id     = is_object( $target ) ? (int) ( $target->ID ?? 0 ) : (int) $target;
			if ( in_array( $id, (array) ( $GLOBALS['diviops_test_uneditable_ids'] ?? array() ), true ) ) {
				return false;
			}
		}
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Fixed-zero stub — the natural "no auth context" value in this CLI
	 * harness. No test asserts on this value; it exists only so calls like
	 * handshake() (trait-meta.php) don't fatal on an undefined function.
	 */
	function get_current_user_id() {
		return 0;
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/**
	 * Fixed stub exposing the `user_login` property real callers read
	 * (e.g. handshake()'s `wp_get_current_user()->user_login`). No test
	 * asserts on this value.
	 */
	function wp_get_current_user() {
		return (object) array(
			'ID'         => 0,
			'user_login' => '',
		);
	}
}

if ( ! function_exists( 'get_site_url' ) ) {
	/**
	 * Fixed placeholder stub, matching get_permalink()/admin_url()'s
	 * example.test convention below. No test asserts on this value.
	 */
	function get_site_url() {
		return 'http://example.test';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * The site's front-end address, which is what tells two environments apart
	 * (#343). Deliberately a different default from get_site_url() above: the
	 * two genuinely differ on installs where WordPress lives in a subdirectory,
	 * and a harness where they returned the same string could not prove which
	 * of them the identity block actually reads.
	 *
	 * Overridable via $GLOBALS['diviops_test_home_url'].
	 */
	function home_url( $path = '' ) {
		$base = isset( $GLOBALS['diviops_test_home_url'] )
			? (string) $GLOBALS['diviops_test_home_url']
			: 'http://home.example.test';
		return '' === (string) $path
			? $base
			: rtrim( $base, '/' ) . '/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	/**
	 * Model WP core's wp_get_environment_type(): the configured environment when
	 * it is one of core's four recognised values, and 'production' otherwise.
	 *
	 * The fallback is the whole point, not a shortcut. Core answers 'production'
	 * whenever WP_ENVIRONMENT_TYPE is undefined, which is why a staging site
	 * reports itself as production — measured on staging.colleyvillelions.com,
	 * where the constant is not defined and core answers `production` (#343).
	 * Tests set $GLOBALS['diviops_test_environment_type'] to vary it.
	 */
	function wp_get_environment_type() {
		$type = isset( $GLOBALS['diviops_test_environment_type'] )
			? (string) $GLOBALS['diviops_test_environment_type']
			: 'production';
		return in_array( $type, array( 'local', 'development', 'staging', 'production' ), true )
			? $type
			: 'production';
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	/**
	 * Single-site by default, which is what this harness models everywhere else.
	 * Overridable via $GLOBALS['diviops_test_is_multisite'].
	 */
	function is_multisite() {
		return isset( $GLOBALS['diviops_test_is_multisite'] )
			? (bool) $GLOBALS['diviops_test_is_multisite']
			: false;
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * Fixed placeholder stub. No test asserts on this value; it exists so
	 * schema_get_settings() (trait-meta.php) can run without a fatal.
	 */
	function get_locale() {
		return 'en_US';
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * The handful of `$show` keys this plugin actually asks for. Anything else
	 * returns '' rather than guessing: a shim that invents a plausible value for
	 * an unmodelled key teaches a test that the plugin reads something it does
	 * not.
	 */
	function get_bloginfo( $show = '', $filter = 'raw' ) {
		switch ( (string) $show ) {
			case 'name':
				return isset( $GLOBALS['diviops_test_blogname'] )
					? (string) $GLOBALS['diviops_test_blogname']
					: 'DiviOps Test Site';
			case 'description':
				return isset( $GLOBALS['diviops_test_blogdescription'] )
					? (string) $GLOBALS['diviops_test_blogdescription']
					: '';
			case 'version':
				return isset( $GLOBALS['diviops_test_wp_version'] )
					? (string) $GLOBALS['diviops_test_wp_version']
					: '6.5';
			case 'url':
				return home_url();
			case 'language':
				return get_locale();
			default:
				return '';
		}
	}
}

if ( ! isset( $GLOBALS['diviops_test_options'] ) ) {
	$GLOBALS['diviops_test_options'] = array();
}
if ( ! isset( $GLOBALS['diviops_test_site_options'] ) ) {
	$GLOBALS['diviops_test_site_options'] = array();
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Model WP core's get_option(): the stored value, or $default when unset.
	 */
	function get_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['diviops_test_options'] )
			? $GLOBALS['diviops_test_options'][ $option ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Model WP core's update_option(): store the value, return true.
	 */
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['diviops_test_options'][ $option ] = $value;
		diviops_test_option_row( $option, null === $autoload ? null : ( $autoload ? 'yes' : 'no' ) );
		return true;
	}
}

/*
 * Options-table row metadata.
 *
 * $GLOBALS['diviops_test_options'] stays the flat name => value map several tests
 * write into directly, and remains authoritative for values. Anything that needs
 * the row's identity rather than its value — option_id insertion sequence and the
 * autoload column, both of which the real wp_options table carries and the
 * rollback-snapshot queries select on — reads this parallel map instead. Keeping
 * them separate is what lets a test keep assigning
 * $GLOBALS['diviops_test_options']['active_plugins'] directly.
 */
if ( ! isset( $GLOBALS['diviops_test_option_rows'] ) ) {
	$GLOBALS['diviops_test_option_rows'] = array();
}
if ( ! isset( $GLOBALS['diviops_test_option_sequence'] ) ) {
	$GLOBALS['diviops_test_option_sequence'] = 0;
}

if ( ! function_exists( 'diviops_test_option_row' ) ) {
	/**
	 * Return an option's row metadata, assigning an option_id on first sight.
	 *
	 * Rows created by a direct write into $GLOBALS['diviops_test_options'] never
	 * passed through add_option(), so they have no sequence yet. Assigning lazily
	 * here — rather than refusing to see them — keeps those rows visible to the
	 * $wpdb fake in the same insertion order the writes happened.
	 *
	 * @param string      $option   Option name.
	 * @param string|null $autoload 'yes'/'no' to set, or null to leave unchanged.
	 * @return array{option_id:int, autoload:string}
	 */
	function diviops_test_option_row( string $option, ?string $autoload = null ): array {
		if ( ! isset( $GLOBALS['diviops_test_option_rows'][ $option ] ) ) {
			$GLOBALS['diviops_test_option_rows'][ $option ] = array(
				'option_id' => ++$GLOBALS['diviops_test_option_sequence'],
				'autoload'  => 'yes',
			);
		}
		if ( null !== $autoload ) {
			$GLOBALS['diviops_test_option_rows'][ $option ]['autoload'] = $autoload;
		}
		return $GLOBALS['diviops_test_option_rows'][ $option ];
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Model WP core's add_option(): create the row and return true, or return
	 * false without touching anything when the option already exists.
	 *
	 * The false-on-existing branch is load-bearing, not incidental:
	 * rollback_snapshot_create_for_post_write() treats a falsey add_option()
	 * return as a storage failure and raises rollback_snapshot.storage_failed.
	 * A shim that always returned true would hide that path entirely.
	 */
	function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $option, $GLOBALS['diviops_test_options'] ) ) {
			return false;
		}
		$GLOBALS['diviops_test_options'][ $option ] = $value;
		diviops_test_option_row( (string) $option, ( 'no' === $autoload || false === $autoload ) ? 'no' : 'yes' );
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Model WP core's delete_option(): true when a row was removed, false when
	 * there was nothing to remove.
	 */
	function delete_option( $option ) {
		if ( ! array_key_exists( $option, $GLOBALS['diviops_test_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['diviops_test_options'][ $option ] );
		unset( $GLOBALS['diviops_test_option_rows'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	/**
	 * Model WP core's maybe_serialize(): arrays and objects are serialized,
	 * scalars pass through. The real options table stores a string in
	 * option_value, and the rollback code measures strlen() on exactly that
	 * string, so the $wpdb fake must hand back serialized text rather than a
	 * live PHP array.
	 */
	function maybe_serialize( $data ) {
		return ( is_array( $data ) || is_object( $data ) ) ? serialize( $data ) : $data;
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	/**
	 * Model WP core's maybe_unserialize(): unserialize serialized strings,
	 * return anything else untouched.
	 */
	function maybe_unserialize( $data ) {
		if ( ! is_string( $data ) ) {
			return $data;
		}
		$trimmed = trim( $data );
		if ( 'b:0;' === $trimmed ) {
			return false;
		}
		$restored = @unserialize( $trimmed );
		return false === $restored ? $data : $restored;
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * Deterministic stand-in for wp_rand(). Snapshot IDs mix this into a hash
	 * seed alongside microtime(), so uniqueness across calls still holds without
	 * real randomness.
	 */
	function wp_rand( $min = 0, $max = 0 ) {
		static $counter = 0;
		++$counter;
		$min = (int) $min;
		$max = (int) $max;
		if ( $max <= $min ) {
			return $counter;
		}
		return $min + ( $counter % ( $max - $min + 1 ) );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	/**
	 * Deterministic stand-in for wp_generate_uuid4(), in the same spirit as the
	 * wp_rand() stub above. Core's contract to its callers is a v4-shaped string
	 * that differs on every call; the canvas, preset, global-color and global-font
	 * handlers use it only as opaque identity, so shape and per-call uniqueness are
	 * the whole of what they depend on. Counting rather than randomising keeps the
	 * value assertable: a test can pin the exact id a handler stored instead of
	 * matching a pattern and hoping.
	 */
	function wp_generate_uuid4() {
		static $counter = 0;
		++$counter;
		return sprintf( '00000000-0000-4000-8000-%012d', $counter );
	}
}

if ( ! function_exists( 'update_meta_cache' ) ) {
	/**
	 * No-op, for the same reason update_post_caches() below is one: core primes
	 * the meta cache here, and this harness reads meta straight out of
	 * $GLOBALS['diviops_test_post_meta'], so there is nothing to prime. Callers
	 * that batch-prime before a read loop (canvas_list(), canvas_orphan_audit())
	 * call it unconditionally, so it has to exist.
	 */
	function update_meta_cache( $meta_type, $object_ids ) {
		return array();
	}
}

if ( ! function_exists( 'metadata_exists' ) ) {
	/**
	 * Model WP core's metadata_exists(): whether the key is present at all,
	 * which is a different question from whether its value is non-empty.
	 */
	function metadata_exists( $meta_type, $object_id, $meta_key ) {
		return isset( $GLOBALS['diviops_test_post_meta'][ (int) $object_id ][ $meta_key ] );
	}
}

if ( ! class_exists( 'DiviOps_Test_wpdb' ) ) {
	/**
	 * Options-table stand-in for $wpdb.
	 *
	 * This executes the SELECT it is handed rather than pattern-matching the
	 * caller and returning a canned list. That distinction is the whole point:
	 * the behavior under test in the rollback snapshot inventory *is* the
	 * ORDER BY direction, so a fake that ignored the clause would report PASS
	 * whether the production query said ASC or DESC. It honors the real
	 * SELECT column list, the LIKE pattern including esc_like()'s backslash
	 * escapes, ORDER BY on option_id/option_name in either direction, and LIMIT.
	 *
	 * Anything outside that shape throws instead of guessing, so an untested
	 * query shape fails loudly rather than silently returning a wrong-but-
	 * plausible row set.
	 */
	final class DiviOps_Test_wpdb {

		/** @var string Options table name, matching $wpdb->options. */
		public $options = 'wp_options';

		/** @var array<int, string> Every query this fake has executed, for assertions. */
		public $queries = array();

		/**
		 * Model $wpdb::esc_like(): backslash-escape LIKE's wildcards.
		 */
		public function esc_like( $text ) {
			return addcslashes( (string) $text, '_%\\' );
		}

		/**
		 * Model $wpdb::prepare() for the %s/%d placeholders this codebase uses.
		 * Strings are single-quoted with SQL's doubled-quote escaping, which
		 * leaves esc_like()'s backslashes intact for the LIKE matcher below.
		 */
		public function prepare( $query, ...$args ) {
			$index = 0;
			return (string) preg_replace_callback(
				'/%[sd%]/',
				static function ( $match ) use ( &$index, $args ) {
					if ( '%%' === $match[0] ) {
						return '%';
					}
					$value = $args[ $index ] ?? null;
					++$index;
					if ( '%d' === $match[0] ) {
						return (string) (int) $value;
					}
					return "'" . str_replace( "'", "''", (string) $value ) . "'";
				},
				(string) $query
			);
		}

		/**
		 * Execute a supported SELECT against the in-memory options store.
		 *
		 * @param string $query Prepared SQL.
		 * @return array<int, stdClass> Result rows.
		 */
		public function get_results( $query ) {
			$this->queries[] = (string) $query;

			$pattern = '/^\s*SELECT\s+(?P<columns>.+?)\s+FROM\s+\S+\s+WHERE\s+option_name\s+LIKE\s+'
				. "'(?P<like>(?:[^']|'')*)'"
				. '\s*(?:ORDER\s+BY\s+(?P<sort>option_id|option_name)\s+(?P<direction>ASC|DESC)\s*)?'
				. '(?:LIMIT\s+(?P<limit>\d+)\s*)?$/is';
			if ( ! preg_match( $pattern, (string) $query, $matches ) ) {
				throw new RuntimeException( 'DiviOps_Test_wpdb cannot execute this query shape: ' . $query );
			}

			$columns = array_map( 'trim', explode( ',', $matches['columns'] ) );
			if ( array( '*' ) === $columns ) {
				$columns = array( 'option_id', 'option_name', 'option_value', 'autoload' );
			}
			$known = array( 'option_id', 'option_name', 'option_value', 'autoload' );
			foreach ( $columns as $column ) {
				if ( ! in_array( $column, $known, true ) ) {
					throw new RuntimeException( 'DiviOps_Test_wpdb cannot select column: ' . $column );
				}
			}

			$like    = str_replace( "''", "'", $matches['like'] );
			$matched = array();
			foreach ( array_keys( $GLOBALS['diviops_test_options'] ) as $name ) {
				if ( preg_match( self::like_to_regex( $like ), (string) $name ) ) {
					$matched[] = (string) $name;
				}
			}

			$sort      = '' === ( $matches['sort'] ?? '' ) ? 'option_id' : $matches['sort'];
			$direction = strtoupper( '' === ( $matches['direction'] ?? '' ) ? 'ASC' : $matches['direction'] );
			usort(
				$matched,
				static function ( $left, $right ) use ( $sort, $direction ) {
					$comparison = 'option_id' === $sort
						? diviops_test_option_row( $left )['option_id'] <=> diviops_test_option_row( $right )['option_id']
						: strcmp( $left, $right );
					return 'DESC' === $direction ? -$comparison : $comparison;
				}
			);

			if ( '' !== ( $matches['limit'] ?? '' ) ) {
				$matched = array_slice( $matched, 0, (int) $matches['limit'] );
			}

			$rows = array();
			foreach ( $matched as $name ) {
				$meta = diviops_test_option_row( $name );
				$row  = new stdClass();
				foreach ( $columns as $column ) {
					if ( 'option_id' === $column ) {
						$row->option_id = (string) $meta['option_id'];
					} elseif ( 'option_name' === $column ) {
						$row->option_name = $name;
					} elseif ( 'autoload' === $column ) {
						$row->autoload = $meta['autoload'];
					} else {
						$row->option_value = maybe_serialize( $GLOBALS['diviops_test_options'][ $name ] );
					}
				}
				$rows[] = $row;
			}
			return $rows;
		}

		/**
		 * Translate a SQL LIKE pattern into an anchored regex, honoring the
		 * backslash escapes esc_like() introduces so an escaped underscore
		 * matches a literal underscore rather than any character.
		 */
		private static function like_to_regex( string $like ): string {
			$regex  = '';
			$length = strlen( $like );
			for ( $index = 0; $index < $length; $index++ ) {
				$character = $like[ $index ];
				if ( '\\' === $character && $index + 1 < $length ) {
					++$index;
					$regex .= preg_quote( $like[ $index ], '/' );
					continue;
				}
				if ( '%' === $character ) {
					$regex .= '.*';
					continue;
				}
				if ( '_' === $character ) {
					$regex .= '.';
					continue;
				}
				$regex .= preg_quote( $character, '/' );
			}
			return '/^' . $regex . '$/s';
		}
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new DiviOps_Test_wpdb();
}

if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * Model WP core's get_site_option(): the network-level counterpart of
	 * get_option(), backed by its own store since a single-site value and a
	 * network value are never the same row.
	 */
	function get_site_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['diviops_test_site_options'] )
			? $GLOBALS['diviops_test_site_options'][ $option ]
			: $default;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Real filter runner over the registry add_filter() above builds: runs every
	 * callback registered on $tag in priority order, each receiving $value plus
	 * as many of $args as its accepted_args declared, threading the return value
	 * through. A hook with no registered callbacks returns $value unchanged —
	 * the correct behavior for both an untouched hook and one reset via
	 * remove_all_filters().
	 */
	function apply_filters( $tag, $value, ...$args ) {
		if ( empty( $GLOBALS['diviops_test_hooks'][ $tag ] ) ) {
			return $value;
		}
		$by_priority = $GLOBALS['diviops_test_hooks'][ $tag ];
		ksort( $by_priority );
		foreach ( $by_priority as $callbacks ) {
			foreach ( $callbacks as $registered ) {
				$accepted   = max( 1, (int) $registered['accepted_args'] );
				$call_args  = array_slice( array_merge( array( $value ), $args ), 0, $accepted );
				$value      = call_user_func_array( $registered['function'], $call_args );
			}
		}
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

if ( ! function_exists( 'rest_ensure_response' ) ) {
	/**
	 * Mirrors core: a WP_Error passes through untouched, an existing
	 * WP_REST_Response is returned as-is, anything else is wrapped. Handlers
	 * return all three shapes, so a shim that always wrapped would hide the
	 * difference between an error and a successful payload.
	 */
	function rest_ensure_response( $response ) {
		if ( $response instanceof WP_Error || $response instanceof WP_REST_Response ) {
			return $response;
		}
		return new WP_REST_Response( $response );
	}
}

if ( ! isset( $GLOBALS['diviops_test_posts'] ) ) {
	$GLOBALS['diviops_test_posts'] = array();
}

if ( ! isset( $GLOBALS['diviops_test_get_posts_calls'] ) ) {
	$GLOBALS['diviops_test_get_posts_calls'] = array();
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
			// Defaulted so a caller reading these WP_Post columns (e.g.
			// page_duplicate()'s byte-copy, which carries post_excerpt/
			// post_parent/menu_order straight into wp_insert_post(); seo_post_evidence()'s
			// published/canonical check, which reads post_status) never hits an
			// undefined-property warning on a fixture that didn't set them
			// explicitly. Tests that care can still overwrite these on the
			// returned object before calling the handler under test.
			'post_excerpt' => '',
			'post_parent'  => 0,
			'menu_order'   => 0,
			'post_status'  => 'publish',
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

// WordPress' own time constants (wp-includes/default-constants.php). Values
// match core exactly — a shim that redefined them would let a test pass on a
// duration the real site would never use.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
}
if ( ! defined( 'MONTH_IN_SECONDS' ) ) {
	define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
}

// In-memory transient store. delete_transient() predates this and returned a
// bare true, which was fine while nothing read transients back; the
// client-runtime report (#123) round-trips through set/get, so the three now
// share real storage and delete actually deletes.
if ( ! isset( $GLOBALS['diviops_test_transients'] ) ) {
	$GLOBALS['diviops_test_transients'] = [];
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		$GLOBALS['diviops_test_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		// WordPress returns false for a missing transient, which is why callers
		// must not store a literal false and expect to read it back.
		return $GLOBALS['diviops_test_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		unset( $GLOBALS['diviops_test_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key, $value = '' ) {
		unset( $GLOBALS['diviops_test_post_meta'][ $post_id ][ $key ] );
		if ( '' === $value ) {
			unset( $GLOBALS['diviops_test_post_meta_rows'][ $post_id ][ $key ] );
			return true;
		}
		$rows = $GLOBALS['diviops_test_post_meta_rows'][ $post_id ][ $key ] ?? array();
		$GLOBALS['diviops_test_post_meta_rows'][ $post_id ][ $key ] = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $value ) {
					return (string) $row !== (string) $value;
				}
			)
		);
		return true;
	}
}

// ── Post-type and post-status registries ──────────────────────────────────
//
// A query's `'any'` is answered by these registries rather than by the rows.
// WP_Query resolves `post_type => 'any'` to
// `get_post_types( array( 'exclude_from_search' => false ) )`
// (wp-includes/class-wp-query.php:2612-2613), and `post_status => 'any'` to
// "every status except the ones registered `exclude_from_search` that the caller
// did not name" (wp-includes/class-wp-query.php:2667-2673). Without registries
// carrying those flags there is nothing for `'any'` to narrow against, which is
// why get_posts() treated it as "everything in the fixture set" until #318.
//
// Both registries store core's own default derivation rather than a pre-resolved
// answer, so a type or status a test registers gets the flag core would give it
// instead of one this harness assumed.

if ( ! function_exists( 'diviops_test_filter_registry' ) ) {
	/**
	 * Model wp_list_filter()'s 'AND' operator over one of the registries below:
	 * keep an entry when every requested key is present and equal, and return
	 * name => name, which is what core's 'names' output produces.
	 *
	 * The comparison is loose because core's is (WP_List_Util::filter in
	 * wp-includes/class-wp-list-util.php, which wp_list_filter() delegates to), so
	 * `'exclude_from_search' => false` matches a registered false without the
	 * caller having to match its type.
	 *
	 * @param array<string, mixed> $registry Registered entries, name => resolved args.
	 * @param array<string, mixed> $args     Flags every kept entry must carry.
	 * @return array<string, string>
	 */
	function diviops_test_filter_registry( array $registry, array $args ): array {
		$names = array();
		foreach ( $registry as $name => $registered ) {
			if ( ! is_array( $registered ) ) {
				throw new RuntimeException(
					sprintf(
						"wp-shim: registry entry '%s' is not a resolved argument array. Register it through diviops_test_register_post_type()/diviops_test_register_post_status() so its flags are derived the way core derives them.",
						(string) $name
					)
				);
			}
			foreach ( $args as $key => $value ) {
				// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- core's own comparison is loose.
				if ( ! array_key_exists( $key, $registered ) || $registered[ $key ] != $value ) {
					continue 2;
				}
			}
			$names[ (string) $name ] = (string) $name;
		}
		return $names;
	}
}

if ( ! function_exists( 'diviops_test_register_post_type' ) ) {
	/**
	 * Model WP core's register_post_type() for the flags a query reads: `public`
	 * defaults to false, and `exclude_from_search` defaults to its negation
	 * (wp-includes/class-wp-post-type.php:606-607).
	 *
	 * @param string               $post_type Post type name.
	 * @param array<string, mixed> $args      Registration arguments.
	 * @return array<string, mixed> The resolved arguments.
	 */
	function diviops_test_register_post_type( string $post_type, array $args = array() ): array {
		$args['public'] = (bool) ( $args['public'] ?? false );
		if ( ! isset( $args['exclude_from_search'] ) ) {
			$args['exclude_from_search'] = ! $args['public'];
		}
		$args['exclude_from_search']                      = (bool) $args['exclude_from_search'];
		$args['name']                                     = $post_type;
		$GLOBALS['diviops_test_post_types'][ $post_type ] = $args;
		return $args;
	}
}

if ( ! isset( $GLOBALS['diviops_test_post_types'] ) ) {
	/*
	 * WordPress's built-in post types, transcribed from create_initial_post_types()
	 * (wp-includes/post.php). Every one of them leaves exclude_from_search unset and
	 * inherits it from `public`, so only the public/non-public split is recorded
	 * here. Tests register their own types on top; Divi's own types are not seeded,
	 * because Divi registers all of them non-public and a test that needs one in an
	 * `'any'` result should say so.
	 */
	$GLOBALS['diviops_test_post_types'] = array();
	foreach ( array( 'post', 'page', 'attachment' ) as $diviops_test_type ) {
		diviops_test_register_post_type( $diviops_test_type, array( 'public' => true ) );
	}
	foreach (
		array(
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'wp_font_family',
			'wp_font_face',
		) as $diviops_test_type
	) {
		diviops_test_register_post_type( $diviops_test_type, array( 'public' => false ) );
	}
	unset( $diviops_test_type );
}

if ( ! function_exists( 'post_type_exists' ) ) {
	/**
	 * Model WP core's post_type_exists(): membership in the registered set.
	 */
	function post_type_exists( $post_type ) {
		return isset( $GLOBALS['diviops_test_post_types'][ (string) $post_type ] );
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	/**
	 * Model WP core's get_post_types(): the registered types matching every flag in
	 * $args, keyed by name. Only the 'names'/'and' form is modelled — the one both
	 * WP_Query's `'any'` resolution and canvas_audit_reference_candidates() in
	 * trait-canvas.php ask for.
	 */
	function get_post_types( $args = array(), $output = 'names', $operator = 'and' ) {
		if ( 'names' !== $output || 'and' !== $operator ) {
			throw new RuntimeException(
				sprintf( "wp-shim get_post_types(): only the 'names'/'and' form is modelled, not '%s'/'%s'.", (string) $output, (string) $operator )
			);
		}
		return diviops_test_filter_registry( $GLOBALS['diviops_test_post_types'], (array) $args );
	}
}

if ( ! function_exists( 'diviops_test_register_post_status' ) ) {
	/**
	 * Model WP core's register_post_status() default derivation
	 * (wp-includes/post.php:1485-1526, exclude_from_search at 1510-1512): a status
	 * given none of public/internal/protected/private is internal, each unset flag
	 * is false, and `exclude_from_search` follows `internal` unless the caller
	 * sets it.
	 *
	 * @param string               $status Post status name.
	 * @param array<string, mixed> $args   Registration arguments.
	 * @return array<string, mixed> The resolved arguments.
	 */
	function diviops_test_register_post_status( string $status, array $args = array() ): array {
		$visibility = array( 'public', 'internal', 'protected', 'private' );
		if ( array() === array_intersect( $visibility, array_keys( $args ) ) ) {
			$args['internal'] = true;
		}
		foreach ( $visibility as $flag ) {
			$args[ $flag ] = (bool) ( $args[ $flag ] ?? false );
		}
		if ( ! isset( $args['exclude_from_search'] ) ) {
			$args['exclude_from_search'] = $args['internal'];
		}
		$args['exclude_from_search']                      = (bool) $args['exclude_from_search'];
		$args['name']                                     = $status;
		$GLOBALS['diviops_test_post_statuses'][ $status ] = $args;
		return $args;
	}
}

if ( ! isset( $GLOBALS['diviops_test_post_statuses'] ) ) {
	/*
	 * WordPress's built-in post statuses, transcribed from create_initial_post_types()
	 * (wp-includes/post.php). `inherit` and the four request-* statuses are internal
	 * but set exclude_from_search back to false explicitly, which leaves trash and
	 * auto-draft as the only statuses a `post_status => 'any'` query drops.
	 */
	$GLOBALS['diviops_test_post_statuses'] = array();
	foreach (
		array(
			'publish'           => array( 'public' => true ),
			'future'            => array( 'protected' => true ),
			'draft'             => array( 'protected' => true ),
			'pending'           => array( 'protected' => true ),
			'private'           => array( 'private' => true ),
			'trash'             => array( 'internal' => true ),
			'auto-draft'        => array( 'internal' => true ),
			'inherit'           => array( 'internal' => true, 'exclude_from_search' => false ),
			'request-pending'   => array( 'internal' => true, 'exclude_from_search' => false ),
			'request-confirmed' => array( 'internal' => true, 'exclude_from_search' => false ),
			'request-failed'    => array( 'internal' => true, 'exclude_from_search' => false ),
			'request-completed' => array( 'internal' => true, 'exclude_from_search' => false ),
		) as $diviops_test_status => $diviops_test_status_args
	) {
		diviops_test_register_post_status( $diviops_test_status, $diviops_test_status_args );
	}
	unset( $diviops_test_status, $diviops_test_status_args );
}

if ( ! function_exists( 'get_post_stati' ) ) {
	/**
	 * Model WP core's get_post_stati(): the registered statuses matching every flag
	 * in $args, keyed by name. Values are the status names, matching how page_create
	 * consumes them (in_array on values, array_values for the error payload).
	 */
	function get_post_stati( $args = array(), $output = 'names', $operator = 'and' ) {
		if ( 'names' !== $output || 'and' !== $operator ) {
			throw new RuntimeException(
				sprintf( "wp-shim get_post_stati(): only the 'names'/'and' form is modelled, not '%s'/'%s'.", (string) $output, (string) $operator )
			);
		}
		return diviops_test_filter_registry( $GLOBALS['diviops_test_post_statuses'], (array) $args );
	}
}

if ( ! function_exists( 'diviops_test_query_post_filter' ) ) {
	/**
	 * Resolve a query's post_type and post_status arguments into the filter core
	 * builds from them, so get_posts() and WP_Query answer the question the same
	 * way instead of each carrying its own interpretation (#326).
	 *
	 * `post_type => 'any'` is a string identity test in core, resolved to
	 * `get_post_types( array( 'exclude_from_search' => false ) )`
	 * (wp-includes/class-wp-query.php:2612-2613); anything else, array or string,
	 * is a list of literal type names (class-wp-query.php:2620-2623).
	 *
	 * `post_status` is split on commas when it is a string
	 * (class-wp-query.php:2660-2662), and `'any'` is a member test: core adds a
	 * `post_status <> x` term for every status registered `exclude_from_search`
	 * that the caller did not also name (class-wp-query.php:2667-2673).
	 *
	 * @param mixed $post_type_arg   post_type argument.
	 * @param mixed $post_status_arg post_status argument.
	 * @return array{types: array<int, string>, statuses: array<int, mixed>, any_status: bool, dropped: array<int, string>}
	 */
	function diviops_test_query_post_filter( $post_type_arg, $post_status_arg ): array {
		$types = ( 'any' === $post_type_arg )
			? array_values( get_post_types( array( 'exclude_from_search' => false ) ) )
			: array_values( (array) $post_type_arg );

		$statuses   = is_array( $post_status_arg )
			? array_values( $post_status_arg )
			: explode( ',', (string) $post_status_arg );
		$any_status = in_array( 'any', $statuses, true );

		return array(
			'types'      => $types,
			'statuses'   => $statuses,
			'any_status' => $any_status,
			// The statuses `'any'` drops: excluded from search, and not named by
			// the caller alongside it.
			'dropped'    => $any_status
				? array_values( array_diff( get_post_stati( array( 'exclude_from_search' => true ) ), $statuses ) )
				: array(),
		);
	}
}

if ( ! function_exists( 'diviops_test_query_post_matches' ) ) {
	/**
	 * Whether one fixture row passes the type/status filter above.
	 *
	 * @param object               $post   Post fixture.
	 * @param array<string, mixed> $filter diviops_test_query_post_filter() result.
	 */
	function diviops_test_query_post_matches( $post, array $filter ): bool {
		if ( ! in_array( $post->post_type, $filter['types'], true ) ) {
			return false;
		}
		$status = isset( $post->post_status ) ? $post->post_status : 'publish';
		return $filter['any_status']
			? ! in_array( $status, $filter['dropped'], true )
			: in_array( $status, $filter['statuses'], true );
	}
}

if ( ! function_exists( 'diviops_test_query_refuse_unmodelled' ) ) {
	/**
	 * Raise on the WP_Query arguments this harness does not model (#330).
	 *
	 * The class held two standards. A meta_query feature it cannot model refuses
	 * loudly — eleven `wp-shim meta_query:` refusals cover unmodelled casts,
	 * operators, relations, nested groups and uncastable values — while a query
	 * argument it cannot model was accepted and dropped. That is the same defect
	 * class #318 and #326 closed one level down: the stub answers a different
	 * question than the caller asked and nothing says so. A refusal is a visible
	 * gap; a faithful implementation of something this harness cannot actually
	 * observe is one more chance to encode an assumption about core that no test
	 * here can check.
	 *
	 * `title` was the case that showed why the silence was costly, and it is the
	 * one argument of the five that is modelled instead — see the WP_Query class
	 * below. It got worse rather than better when meta_query started filtering:
	 * canvas_existing_id_by_title() went from returning nothing (the meta_query
	 * matched everything and `post_status => 'any'` matched nothing) to reporting
	 * the first canvas under the parent page as a title collision whatever its
	 * title, which is a plausible answer to a different question. Measured with
	 * `title` ignored, which is the pre-#330 behaviour: three canvases under
	 * parent 4242 and a lookup for a title none of them carries returns 900001. A
	 * test asserting "duplicating a canvas whose title already exists reports a
	 * conflict" would pass on any canvas existing.
	 *
	 * The line drawn here: an argument raises when ignoring it would change the
	 * answer core gives — the rows returned, or their order — AND this harness
	 * has no way to compute that answer. `title` fails the second half: it is
	 * exact string equality on a value core reaches by a stated route, so it is
	 * modelled rather than refused, and refusing it would have blocked the very
	 * collision tests the paragraph above says are missing. `tax_query`, `perm`
	 * and a real `orderby` fail nothing — there is no term registry, no user or
	 * `post_author`, and no ordering here — so they raise.
	 *
	 * An argument value that is inert in core is inert here too. An empty
	 * `tax_query` passes (core adds no term), `orderby => 'none'`, `false` or an
	 * empty array pass (core blanks ORDER BY, which is the registry order this
	 * class returns) and take `order` with them, and a positive `posts_per_page`
	 * pages as before. Only a negative `posts_per_page` raises — core reads -1 as
	 * unlimited and anything below -1 as `abs()` rows, while array_slice() reads
	 * every one of them as a length.
	 *
	 * `$GLOBALS['diviops_test_wp_query_unmodelled_ok']` is the waiver seam, a list
	 * of argument names. A handler-driven test cannot edit the query its handler
	 * builds, so without a seam a refusal deletes that handler's coverage
	 * outright — and reducing coverage is worse than the gap the refusal closes.
	 * Listing an argument asserts that it is inert for that file's fixtures, and
	 * that assertion is the test author's to justify in a comment. The waiver is
	 * per argument name, never a blanket off switch, so a file that waives
	 * `orderby` still refuses `title`. Three files take it today
	 * (test-media.php, test-tb-marked-unused-at.php,
	 * test-tb-template-list-master-scoping.php); the gap is legible in each of
	 * them rather than invisible everywhere, which is the whole point.
	 *
	 * @param array<string, mixed> $args WP_Query arguments.
	 * @throws RuntimeException When an argument's effect is not modelled.
	 */
	function diviops_test_query_refuse_unmodelled( array $args ): void {
		$waived = (array) ( $GLOBALS['diviops_test_wp_query_unmodelled_ok'] ?? array() );

		$refuse = static function ( string $key, string $label, string $reason ) use ( $waived ): void {
			if ( in_array( $key, $waived, true ) ) {
				return;
			}
			throw new RuntimeException(
				sprintf(
					"wp-shim WP_Query: %s is not modelled. %s Alternatively list '%s' in \$GLOBALS['diviops_test_wp_query_unmodelled_ok'] when the argument is inert for the fixtures under test.",
					$label,
					$reason,
					$key
				)
			);
		};

		if ( ! empty( $args['tax_query'] ) ) {
			$refuse(
				'tax_query',
				"'tax_query'",
				"Ignoring it returns rows under every term rather than the terms the caller scoped to. Model it in the WP_Query stub or drop 'tax_query' from the query under test."
			);
		}
		if ( ! empty( $args['perm'] ) ) {
			$refuse(
				'perm',
				sprintf( "perm '%s'", (string) $args['perm'] ),
				"Core narrows to the current user's own posts, and only when that user lacks the edit_others capability for the post type (class-wp-query.php:2694); this stub has no user, role or post_author to compute either half from, so it can neither apply that narrowing nor rule out that core would. Model it in the WP_Query stub or drop 'perm' from the query under test."
			);
		}
		// Core blanks ORDER BY outright for `orderby => 'none'`, and for false or
		// an empty array (class-wp-query.php:2513, 2518). Registry order is then
		// the order core itself returns, so those are inert rather than refused —
		// and with no ordering left, `order` has nothing to apply to either.
		$orderby_given = $args['orderby'] ?? null;
		$order_blanked = 'none' === $orderby_given
			|| ( empty( $orderby_given ) && ( is_array( $orderby_given ) || false === $orderby_given ) );

		foreach ( array( 'orderby', 'order' ) as $key ) {
			if ( $order_blanked ) {
				continue;
			}
			if ( ! empty( $args[ $key ] ) ) {
				$refuse(
					$key,
					sprintf(
						"%s '%s'",
						$key,
						is_scalar( $args[ $key ] ) ? (string) $args[ $key ] : gettype( $args[ $key ] )
					),
					sprintf(
						"This stub returns fixtures in registry order whatever the caller asks for, which is a different order than core's and, once posts_per_page truncates, a different set of rows. Model it in the WP_Query stub or drop '%s' from the query under test.",
						$key
					)
				);
			}
		}
		if ( isset( $args['posts_per_page'] ) && (int) $args['posts_per_page'] < 0 ) {
			$refuse(
				'posts_per_page',
				sprintf( "posts_per_page '%d'", (int) $args['posts_per_page'] ),
				'Core reads -1 as unlimited (class-wp-query.php:2024) but anything below -1 as abs() rows (class-wp-query.php:2042-2043), so -5 asks for five rows rather than every row; this stub passes the value straight to array_slice() as a length, which drops rows from the end in either case. Model it in the WP_Query stub or pass a positive cap larger than the fixture set.'
			);
		}
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
	 *
	 * post_name and post_modified are derived rather than left unset because core
	 * always assigns both on insert, and a handler that reads one back off the
	 * freshly created post (canvas_duplicate() returns the new slug) would
	 * otherwise emit an "Undefined property" warning into the run.
	 */
	function wp_insert_post( $postarr, $wp_error = false ) {
		$GLOBALS['diviops_test_last_insert'] = $postarr;
		$id = ( $GLOBALS['diviops_test_next_id'] ?? 9000 );
		$GLOBALS['diviops_test_next_id'] = $id + 1;
		$GLOBALS['diviops_test_posts'][ $id ] = (object) array(
			'ID'            => $id,
			'post_content'  => $postarr['post_content'] ?? '',
			'post_type'     => $postarr['post_type'] ?? 'post',
			'post_title'    => $postarr['post_title'] ?? '',
			'post_name'     => (string) ( $postarr['post_name'] ?? sanitize_title( (string) ( $postarr['post_title'] ?? '' ) ) ),
			'post_status'   => $postarr['post_status'] ?? 'draft',
			'post_excerpt'  => $postarr['post_excerpt'] ?? '',
			'post_parent'   => (int) ( $postarr['post_parent'] ?? 0 ),
			'menu_order'    => (int) ( $postarr['menu_order'] ?? 0 ),
			'post_modified' => (string) ( $postarr['post_modified'] ?? current_time( 'mysql' ) ),
		);
		return $id;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['diviops_test_post_meta'][ $post_id ][ $key ] = $value;
		// Core's update_post_meta() with no $prev_value collapses every row for
		// the key onto one value, so a prior add_post_meta() row set cannot
		// survive it. Drop the multi-row store to model that.
		unset( $GLOBALS['diviops_test_post_meta_rows'][ $post_id ][ $key ] );
		return true;
	}
}

/*
 * Multi-row post meta.
 *
 * $GLOBALS['diviops_test_post_meta'] models the single-row case update_post_meta()
 * creates, which is all most handlers need. WordPress meta is genuinely one row per
 * add_post_meta() call, though, and Divi relies on that: a Theme Builder master
 * records each of its templates as its own `_et_template` row
 * (theme-builder/api.php:244), and reads them back with a non-single
 * get_post_meta(). Rows added here therefore live in their own registry, which
 * get_post_meta() below prefers for non-single reads.
 */
if ( ! isset( $GLOBALS['diviops_test_post_meta_rows'] ) ) {
	$GLOBALS['diviops_test_post_meta_rows'] = array();
}

if ( ! function_exists( 'add_post_meta' ) ) {
	/**
	 * Model WP core's add_post_meta(): append a row for the key rather than
	 * replacing what is there. Honors $unique by refusing when the key already
	 * has any row, exactly as core does.
	 */
	function add_post_meta( $post_id, $key, $value, $unique = false ) {
		$rows = $GLOBALS['diviops_test_post_meta_rows'][ $post_id ][ $key ] ?? array();
		if ( $unique && ( $rows || isset( $GLOBALS['diviops_test_post_meta'][ $post_id ][ $key ] ) ) ) {
			return false;
		}
		$rows[] = $value;
		$GLOBALS['diviops_test_post_meta_rows'][ $post_id ][ $key ] = $rows;
		// Keep the single-row view answering with the first row, so a $single
		// read of a multi-row key behaves as core's "first row wins".
		if ( ! isset( $GLOBALS['diviops_test_post_meta'][ $post_id ][ $key ] ) ) {
			$GLOBALS['diviops_test_post_meta'][ $post_id ][ $key ] = $rows[0];
		}
		return true;
	}
}

if ( ! function_exists( 'update_post_caches' ) ) {
	/**
	 * No-op. Core primes the post/meta/term caches here; this harness reads
	 * meta straight out of the registries above, so there is nothing to prime
	 * and nothing a test could assert about it.
	 */
	function update_post_caches( &$posts, $post_type = 'post', $update_term_cache = true, $update_meta_cache = true ) {
	}
}

// ── meta_query clause evaluation ──────────────────────────────────────────
//
// Core turns a clause into a JOIN on the meta table plus a WHERE term
// (wp-includes/class-wp-meta-query.php, get_sql_for_clause()). Two consequences
// this models directly: a key with several rows matches when ANY row satisfies the
// comparison, because the JOIN produces one candidate row per meta row; and a
// comparison on a post with no row for the key never matches, because there is no
// row to compare (NOT EXISTS is the one operator with its own LEFT JOIN syntax).
//
// The operators modelled are core's non-numeric set minus the regex family: those
// are the ones whose answer follows from the stored string. Everything else — the
// numeric operators, REGEXP, a `type` cast, an OR relation, a nested group — raises
// instead of being approximated. A shim that answers a question it cannot model
// returns a wider result than the caller asked for, and a wider result is
// indistinguishable from a filter that worked (#318).

if ( ! function_exists( 'diviops_test_meta_compare_equal' ) ) {
	/**
	 * Compare a stored meta value with a queried one the way SQL `=` does, and
	 * refuse the one case whose answer is not in core's hands.
	 *
	 * MySQL decides case sensitivity by the column's collation, not by anything
	 * WordPress does — wp_postmeta.meta_value takes the database collation, which on
	 * a stock install is case-insensitive. This harness has no collation, so a pair
	 * that differs by case alone is a question it must not answer either way.
	 *
	 * @param string $stored Stored meta value.
	 * @param string $value  Queried value.
	 */
	function diviops_test_meta_compare_equal( string $stored, string $value ): bool {
		$exact = ( $stored === $value );
		if ( ! $exact && 0 === strcasecmp( $stored, $value ) ) {
			throw new RuntimeException( diviops_test_meta_collation_message( $stored, $value ) );
		}
		return $exact;
	}
}

if ( ! function_exists( 'diviops_test_meta_compare_like' ) ) {
	/**
	 * Compare the way SQL `LIKE` does once core has built the pattern: core wraps the
	 * value in `%...%` and escapes the caller's own wildcards with esc_like() first
	 * (wp-includes/class-wp-meta-query.php:753-756), so the pattern is a literal
	 * substring test. Case sensitivity is refused for the same reason as `=`.
	 *
	 * @param string $stored Stored meta value.
	 * @param string $value  Queried value.
	 */
	function diviops_test_meta_compare_like( string $stored, string $value ): bool {
		$exact = ( '' === $value ) || false !== strpos( $stored, $value );
		$fuzzy = ( '' === $value ) || false !== stripos( $stored, $value );
		if ( $exact !== $fuzzy ) {
			throw new RuntimeException( diviops_test_meta_collation_message( $stored, $value ) );
		}
		return $exact;
	}
}

if ( ! function_exists( 'diviops_test_meta_collation_message' ) ) {
	/**
	 * The refusal both comparisons raise when the answer turns on the collation.
	 *
	 * @param string $stored Stored meta value.
	 * @param string $value  Queried value.
	 */
	function diviops_test_meta_collation_message( string $stored, string $value ): string {
		return sprintf(
			"wp-shim meta_query: comparison of '%s' with '%s' depends on the database collation, which this harness does not model. Use fixtures that differ by more than case.",
			$stored,
			$value
		);
	}
}

if ( ! function_exists( 'diviops_test_meta_numeric_int' ) ) {
	/**
	 * The integer `CAST( meta_value AS SIGNED )` produces, for the one input shape
	 * whose result does not come from MySQL's own conversion rules: a canonical
	 * integer. Returns null for anything else, which the caller turns into a
	 * refusal.
	 *
	 * `type => 'NUMERIC'` casts to SIGNED (wp-includes/class-wp-meta-query.php:329-331)
	 * and MySQL turns a non-integer string into a truncated value plus a warning
	 * rather than rejecting it, so `'42.5'` and `'canvas-7f3a'` both have answers
	 * this harness would be inventing. Leading and trailing whitespace is trimmed
	 * because core trims a string value itself before binding it
	 * (class-wp-meta-query.php:737-739).
	 *
	 * @param mixed $value Queried or stored value.
	 */
	function diviops_test_meta_numeric_int( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$text = trim( $value );
		return preg_match( '/^-?[0-9]+$/', $text ) ? (int) $text : null;
	}
}

if ( ! function_exists( 'diviops_test_meta_query_matches' ) ) {
	/**
	 * Evaluate a meta_query against one post's meta rows.
	 *
	 * Shared by get_posts() and WP_Query so the two cannot drift into two answers
	 * for one question (#326).
	 *
	 * `type => 'NUMERIC'` is modelled for `=` on integer values, which is the whole
	 * of the shape the three canvas callers in trait-canvas.php pass. Every other
	 * cast, and every comparison under a cast whose answer would come from MySQL's
	 * conversion rules, raises — see diviops_test_meta_numeric_int().
	 *
	 * @param int                      $post_id    Post id.
	 * @param array<int|string, mixed> $meta_query meta_query argument.
	 */
	function diviops_test_meta_query_matches( int $post_id, array $meta_query ): bool {
		$modelled = array( '=', '!=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'EXISTS', 'NOT EXISTS' );
		// Core recognises these too and coerces anything else to '='
		// (wp-includes/class-wp-meta-query.php:547-572).
		$recognised = array_merge(
			$modelled,
			array( 'RLIKE', 'REGEXP', 'NOT REGEXP', '>', '>=', '<', '<=', 'BETWEEN', 'NOT BETWEEN' )
		);

		foreach ( $meta_query as $index => $clause ) {
			if ( 'relation' === $index ) {
				if ( 'AND' !== strtoupper( (string) $clause ) ) {
					throw new RuntimeException(
						sprintf(
							"wp-shim meta_query: relation '%s' is not modelled. Extend diviops_test_meta_query_matches() or assert against a single clause.",
							(string) $clause
						)
					);
				}
				continue;
			}
			if ( ! is_array( $clause ) || ! isset( $clause['key'] ) || ! is_string( $clause['key'] ) ) {
				throw new RuntimeException(
					'wp-shim meta_query: entries other than a first-order clause naming a single key are not modelled. Extend diviops_test_meta_query_matches() or flatten the query under test.'
				);
			}
			$unmodelled = array_diff( array_keys( $clause ), array( 'key', 'value', 'compare', 'type' ) );
			if ( array() !== $unmodelled ) {
				throw new RuntimeException(
					sprintf(
						"wp-shim meta_query: clause key '%s' is not modelled. Extend diviops_test_meta_query_matches() or drop the key from the query under test.",
						(string) reset( $unmodelled )
					)
				);
			}

			$cast = isset( $clause['type'] ) ? strtoupper( (string) $clause['type'] ) : '';
			if ( '' !== $cast && 'NUMERIC' !== $cast ) {
				throw new RuntimeException(
					sprintf(
						"wp-shim meta_query: type '%s' is not modelled; only NUMERIC equality on integer values is. Extend diviops_test_meta_query_matches() or drop the cast from the query under test.",
						$cast
					)
				);
			}

			$has_value = array_key_exists( 'value', $clause );
			// Core's default: IN for an array value, otherwise '='
			// (wp-includes/class-wp-meta-query.php:541-544).
			$compare = isset( $clause['compare'] )
				? strtoupper( (string) $clause['compare'] )
				: ( $has_value && is_array( $clause['value'] ) ? 'IN' : '=' );
			if ( ! in_array( $compare, $recognised, true ) ) {
				$compare = '=';
			}
			if ( 'NUMERIC' === $cast && '=' !== $compare ) {
				throw new RuntimeException(
					sprintf(
						"wp-shim meta_query: type 'NUMERIC' is modelled for compare '=' only, not '%s'. An ordering comparison under a cast is decided by MySQL's own conversion rules. Extend diviops_test_meta_query_matches() or assert against equality.",
						$compare
					)
				);
			}
			if ( ! in_array( $compare, $modelled, true ) ) {
				throw new RuntimeException(
					sprintf(
						"wp-shim meta_query: compare '%s' is not modelled. Extend diviops_test_meta_query_matches() or assert against a modelled operator.",
						$compare
					)
				);
			}

			$rows = $GLOBALS['diviops_test_post_meta_rows'][ $post_id ][ $clause['key'] ] ?? array();
			if ( array() === $rows && isset( $GLOBALS['diviops_test_post_meta'][ $post_id ][ $clause['key'] ] ) ) {
				$rows = array( $GLOBALS['diviops_test_post_meta'][ $post_id ][ $clause['key'] ] );
			}

			// Core ignores `value` outright for NOT EXISTS
			// (wp-includes/class-wp-meta-query.php:765-767).
			if ( 'NOT EXISTS' === $compare ) {
				if ( array() !== $rows ) {
					return false;
				}
				continue;
			}
			// A clause with no value adds no meta_value term at all
			// (wp-includes/class-wp-meta-query.php:730), so it is a key presence
			// test whatever the operator says.
			if ( ! $has_value ) {
				if ( array() === $rows ) {
					return false;
				}
				continue;
			}
			// EXISTS carrying a value is interpreted as '='
			// (wp-includes/class-wp-meta-query.php:759-762).
			if ( 'EXISTS' === $compare ) {
				$compare = '=';
			}

			$value = $clause['value'];
			if ( in_array( $compare, array( 'IN', 'NOT IN' ), true ) ) {
				// Core splits a non-array value on commas and whitespace
				// (wp-includes/class-wp-meta-query.php:733-736).
				$value = is_array( $value ) ? array_values( $value ) : preg_split( '/[,\s]+/', (string) $value );
			} elseif ( is_string( $value ) ) {
				$value = trim( $value );
			}
			if ( is_array( $value ) && ! in_array( $compare, array( 'IN', 'NOT IN' ), true ) ) {
				throw new RuntimeException(
					sprintf( "wp-shim meta_query: compare '%s' with an array value is not modelled.", $compare )
				);
			}

			$queried_int = null;
			if ( 'NUMERIC' === $cast ) {
				$queried_int = diviops_test_meta_numeric_int( $value );
				if ( null === $queried_int ) {
					throw new RuntimeException(
						sprintf(
							"wp-shim meta_query: type 'NUMERIC' compares integers, and the queried value '%s' is not one. MySQL's CAST truncates a non-integer rather than rejecting it, which this harness does not model.",
							is_scalar( $value ) ? (string) $value : gettype( $value )
						)
					);
				}
			}

			$matched = false;
			foreach ( $rows as $row ) {
				if ( null !== $row && ! is_scalar( $row ) ) {
					throw new RuntimeException(
						sprintf(
							"wp-shim meta_query: cannot compare the non-scalar value stored for '%s' on post %d; WordPress serialises such a value before it reaches the column.",
							$clause['key'],
							$post_id
						)
					);
				}
				$stored = (string) $row;
				if ( 'NUMERIC' === $cast ) {
					$stored_int = diviops_test_meta_numeric_int( $row );
					if ( null === $stored_int ) {
						throw new RuntimeException(
							sprintf(
								"wp-shim meta_query: type 'NUMERIC' compares integers, and the value '%s' stored for '%s' on post %d is not one. MySQL's CAST truncates a non-integer rather than rejecting it, which this harness does not model.",
								$stored,
								$clause['key'],
								$post_id
							)
						);
					}
					$matched = ( $stored_int === $queried_int );
				} else {
					switch ( $compare ) {
						case '=':
							$matched = diviops_test_meta_compare_equal( $stored, (string) $value );
							break;
						case '!=':
							$matched = ! diviops_test_meta_compare_equal( $stored, (string) $value );
							break;
						case 'LIKE':
							$matched = diviops_test_meta_compare_like( $stored, (string) $value );
							break;
						case 'NOT LIKE':
							$matched = ! diviops_test_meta_compare_like( $stored, (string) $value );
							break;
						case 'IN':
						case 'NOT IN':
							$in = false;
							foreach ( (array) $value as $candidate ) {
								if ( diviops_test_meta_compare_equal( $stored, (string) $candidate ) ) {
									$in = true;
									break;
								}
							}
							$matched = ( 'IN' === $compare ) ? $in : ! $in;
							break;
					}
				}
				if ( $matched ) {
					break;
				}
			}
			if ( ! $matched ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * Model the slice of WP core's get_posts() this codebase actually calls:
	 * post_type, post_status (string or array), posts_per_page (-1 for all),
	 * orderby ID or date with order asc/desc, `fields => 'ids'`, and a
	 * meta_query limited to EXISTS / NOT EXISTS clauses on a key. Runs against
	 * the same $GLOBALS['diviops_test_posts'] registry get_post() uses.
	 *
	 * Date ordering falls back to ID ordering when fixtures carry no post_date,
	 * which matches how Divi's masters actually sort — they are created in
	 * ascending id order, so newest-by-date is newest-by-id.
	 *
	 * post_type accepts an array as core does. It used to compare with `!==`
	 * against whatever was passed, so an array argument — the shape every
	 * multi-post-type scanner in this codebase uses — matched nothing and read
	 * as "the site has no such posts". That is the failure mode #314 is about,
	 * reproduced inside the harness meant to catch it.
	 *
	 * post__in restricts the result to the named ids, and `orderby => 'post__in'`
	 * returns them in the order the caller listed. numberposts is core's own cap,
	 * applied whenever posts_per_page is empty (wp-includes/post.php:2643). Both
	 * were accepted and ignored here, which made a caller passing them look
	 * satisfied while the shim answered a wider question than it was asked (#316).
	 *
	 * `'any'` reads the post-type and post-status registries above, as WP_Query
	 * does: for post_type it is the types registered `exclude_from_search => false`
	 * (class-wp-query.php:2612-2613), tested with string identity so `array( 'any' )`
	 * is a literal type name; for post_status it is every status except those
	 * registered `exclude_from_search => true` the caller did not name
	 * (class-wp-query.php:2667-2673). It used to mean "everything in the fixture
	 * registry", which returned trashed and auto-draft rows, revisions, and rows of
	 * types nothing had registered (#318).
	 *
	 * meta_query is evaluated by diviops_test_meta_query_matches() above, which
	 * models core's non-numeric operators and refuses the rest rather than skipping
	 * a clause it cannot apply. A skipped clause matched everything, so the shim
	 * answered a wider question than the caller asked (#318).
	 *
	 * Every call is recorded in $GLOBALS['diviops_test_get_posts_calls'] so a
	 * test can assert what a scanner actually asked the database for. Tests
	 * that use it reset the log themselves.
	 */
	function get_posts( $args = array() ) {
		$GLOBALS['diviops_test_get_posts_calls'][] = $args;

		$filter = diviops_test_query_post_filter(
			$args['post_type'] ?? 'post',
			$args['post_status'] ?? 'publish'
		);

		$per_page = ! empty( $args['posts_per_page'] )
			? (int) $args['posts_per_page']
			: (int) ( $args['numberposts'] ?? 5 );
		$order    = strtolower( (string) ( $args['order'] ?? 'desc' ) );
		$fields   = $args['fields'] ?? '';
		$post_in  = array_map( 'intval', (array) ( $args['post__in'] ?? array() ) );

		$matches = array();
		foreach ( (array) ( $GLOBALS['diviops_test_posts'] ?? array() ) as $post ) {
			if ( ! diviops_test_query_post_matches( $post, $filter ) ) {
				continue;
			}
			if ( array() !== $post_in && ! in_array( (int) $post->ID, $post_in, true ) ) {
				continue;
			}
			$keep = empty( $args['meta_query'] )
				|| diviops_test_meta_query_matches( (int) $post->ID, (array) $args['meta_query'] );
			if ( $keep ) {
				$matches[] = $post;
			}
		}

		if ( array() !== $post_in && 'post__in' === strtolower( (string) ( $args['orderby'] ?? '' ) ) ) {
			// Core ignores `order` for this orderby: the caller's list is the order.
			usort(
				$matches,
				static function ( $a, $b ) use ( $post_in ) {
					return array_search( (int) $a->ID, $post_in, true ) <=> array_search( (int) $b->ID, $post_in, true );
				}
			);
		} else {
			usort(
				$matches,
				static function ( $a, $b ) {
					return (int) $a->ID <=> (int) $b->ID;
				}
			);
			if ( 'desc' === $order ) {
				$matches = array_reverse( $matches );
			}
		}
		if ( $per_page > 0 ) {
			$matches = array_slice( $matches, 0, $per_page );
		}

		return ( 'ids' === $fields )
			? array_map(
				static function ( $p ) {
					return (int) $p->ID;
				},
				$matches
			)
			: $matches;
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

		#[\ReturnTypeWillChange]
		public function offsetGet( $offset ) {
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
		// A key written through add_post_meta() has real rows; a non-single read
		// must return all of them, which is how Divi reads `_et_template`.
		$rows = $GLOBALS['diviops_test_post_meta_rows'][ $post_id ][ $key ] ?? array();
		if ( $rows ) {
			return $single ? $rows[0] : array_values( $rows );
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

// ── media_upload() harness: sideload/fetch primitives + attachment registry ──
//
// download_url() is deliberately NOT stubbed here: media_fetch_to_temp()
// (trait-media.php) does not call it — WP core's download_url() auto-follows
// redirects with no per-hop SSRF revalidation, which is exactly the bypass
// the redirect-hop guard exists to close (#28). The fetch path uses
// wp_remote_get() with redirection disabled instead, stubbed below.

if ( ! function_exists( 'wp_max_upload_size' ) ) {
	function wp_max_upload_size() {
		return $GLOBALS['diviops_test_max_upload'] ?? 8388608;
	}
}

if ( ! isset( $GLOBALS['diviops_test_attachments'] ) ) {
	$GLOBALS['diviops_test_attachments'] = array();
}

if ( ! function_exists( 'media_handle_sideload' ) ) {
	/**
	 * Model WP core's media_handle_sideload(): register an attachment record
	 * from a local file and return its id. Test seam: $GLOBALS
	 * ['diviops_test_sideload_mime'] controls the recorded mime (defaults to
	 * image/png); the attachment registry backs wp_get_attachment_url() and
	 * get_post_mime_type() below. Deletes the sideloaded temp file, matching
	 * core's behavior of consuming the source file.
	 */
	function media_handle_sideload( $file, $post_id = 0, $desc = null, $post_data = array() ) {
		$id = $GLOBALS['diviops_test_next_attach_id'] = ( $GLOBALS['diviops_test_next_attach_id'] ?? 100 ) + 1;
		$GLOBALS['diviops_test_attachments'][ $id ] = array(
			'id'       => $id,
			'filename' => $file['name'],
			'parent'   => $post_id,
			'url'      => "https://site/wp-content/uploads/{$file['name']}",
			'mime'     => $GLOBALS['diviops_test_sideload_mime'] ?? 'image/png',
		);
		if ( file_exists( $file['tmp_name'] ) ) {
			@unlink( $file['tmp_name'] );
		}
		return $id;
	}
}

if ( ! isset( $GLOBALS['diviops_test_http_responses'] ) ) {
	$GLOBALS['diviops_test_http_responses'] = array();
}
if ( ! isset( $GLOBALS['diviops_test_remote_get_calls'] ) ) {
	$GLOBALS['diviops_test_remote_get_calls'] = array();
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Model WP core's wp_remote_get() called with redirection disabled (the
	 * caller — media_fetch_to_temp() — always passes 'redirection' => 0 so it
	 * can revalidate each hop itself rather than letting the HTTP layer
	 * auto-follow redirects past the SSRF guard). Pops the next scripted
	 * response off $GLOBALS['diviops_test_http_responses'], a FIFO queue
	 * tests script one entry per hop of a redirect chain. Each entry is
	 * either a WP_Error (network failure) or an array shaped like core's
	 * response: [ 'response' => [ 'code' => int ], 'headers' => [...],
	 * 'body' => string ]. An empty queue means a call went unscripted — this
	 * returns a WP_Error rather than a silent default, so a test that forgets
	 * to script a hop fails loudly instead of quietly passing.
	 */
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['diviops_test_remote_get_calls'][] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( empty( $GLOBALS['diviops_test_http_responses'] ) ) {
			return new WP_Error( 'http_request_failed', "diviops test harness: no scripted response for {$url}." );
		}
		return array_shift( $GLOBALS['diviops_test_http_responses'] );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) {
		$headers = $response['headers'] ?? array();
		$header  = strtolower( (string) $header );
		foreach ( $headers as $key => $value ) {
			if ( strtolower( (string) $key ) === $header ) {
				return $value;
			}
		}
		return '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $filename = '', $dir = '' ) {
		return tempnam( '' !== $dir ? $dir : sys_get_temp_dir(), 'divi' );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * Reimplementation of the parts of WP core's sanitize_file_name() this
	 * harness needs: strip special characters, collapse whitespace to dashes.
	 */
	function sanitize_file_name( $filename ) {
		$special_chars = array( '?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', chr( 0 ) );
		$filename      = str_replace( $special_chars, '', (string) $filename );
		$filename      = preg_replace( '/[\r\n\t ]+/', ' ', $filename );
		$filename      = trim( str_replace( ' ', '-', $filename ), '.-_' );
		return $filename;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url, $protocols = null ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $attachment_id ) {
		return $GLOBALS['diviops_test_attachments'][ $attachment_id ]['url'] ?? false;
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	/**
	 * Model WP core's get_attached_file(): a local filesystem path for the
	 * attachment, built from the registry's 'filename' ('' when the
	 * attachment isn't registered — media_list()'s fallback-to-URL-basename
	 * path covers that case). Unlike wp_get_attachment_url(), never carries a
	 * `?query`, which is exactly why media_list() (trait-media.php) prefers
	 * this for deriving an item's filename.
	 */
	function get_attached_file( $attachment_id, $unfiltered = false ) {
		$filename = $GLOBALS['diviops_test_attachments'][ $attachment_id ]['filename'] ?? '';
		return '' !== $filename ? "/uploads/{$filename}" : '';
	}
}

if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $post = null ) {
		$id = is_object( $post ) ? ( $post->ID ?? 0 ) : (int) $post;
		return $GLOBALS['diviops_test_attachments'][ $id ]['mime'] ?? false;
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	/**
	 * Model WP core's get_post_field(): read a single field off the post object
	 * the shared registry holds for $post_id, '' when the post or field is
	 * unset. Same primitive-stub category as get_post() — media_get() uses this
	 * for post_excerpt (WordPress's storage for an attachment's caption).
	 */
	function get_post_field( $field, $post = null, $context = 'display' ) {
		$p = get_post( $post );
		if ( ! $p || ! isset( $p->$field ) ) {
			return '';
		}
		return $p->$field;
	}
}

if ( ! isset( $GLOBALS['diviops_test_attachment_metadata'] ) ) {
	$GLOBALS['diviops_test_attachment_metadata'] = array();
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	/**
	 * Model WP core's wp_get_attachment_metadata(): return the array a test
	 * scripted for this attachment id (mirroring the 'sizes' sub-array a real
	 * image attachment carries), empty array when nothing was scripted.
	 */
	function wp_get_attachment_metadata( $attachment_id, $unfiltered = false ) {
		return $GLOBALS['diviops_test_attachment_metadata'][ $attachment_id ] ?? array();
	}
}

if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( $path, $suffix = '' ) {
		return basename( (string) $path, $suffix );
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query stub modeling post_type / post_status filtering, an 's'
	 * substring search against post_title, a 'post_mime_type' prefix filter (WP
	 * core lets a bare group like 'image' match any 'image/*' — this stub's
	 * prefix match subsumes that), a meta_query, and posts_per_page/paged
	 * pagination — run against the same $GLOBALS['diviops_test_posts'] registry
	 * get_post()/diviops_test_register_post() use, so a fixture registered there
	 * is what this class scans. `title` is modelled on core's own predicate; it is
	 * exact string equality on a value core reaches by a stated route, so there is
	 * nothing to approximate. NOT a general WP_Query reimplementation otherwise:
	 * `tax_query`, `perm`, a non-blank `orderby`/`order` and a negative
	 * `posts_per_page` are refused rather than modelled — there is no term
	 * registry, no user or post_author, and no ordering here to compute them
	 * from. See diviops_test_query_refuse_unmodelled().
	 *
	 * `no_found_rows` is modelled rather than ignored (#335). It changes neither
	 * the rows returned nor their order, only whether found_posts and
	 * max_num_pages are computed, so honouring it is one branch rather than an
	 * approximation of core this harness could get wrong.
	 *
	 * post_type and post_status resolve through diviops_test_query_post_filter(),
	 * the same registries get_posts() reads, and meta_query through
	 * diviops_test_meta_query_matches(), the same clause evaluator. Both were
	 * this class's own: post_type and post_status were compared with `!==`
	 * against a single string, so an array matched nothing and
	 * `post_status => 'any'` matched nothing at all, and meta_query was not read,
	 * so a clause matched everything. Every `'any'` and meta_query caller in
	 * trait-canvas.php and trait-library.php goes through this class rather than
	 * get_posts(), so both defects survived the get_posts() corrections in #315
	 * and #318 until #326.
	 */
	class WP_Query {
		public $posts         = array();
		public $found_posts   = 0;
		public $max_num_pages = 0;

		public function __construct( array $args = array() ) {
			diviops_test_query_refuse_unmodelled( $args );

			$filter      = diviops_test_query_post_filter(
				$args['post_type'] ?? 'post',
				$args['post_status'] ?? 'publish'
			);
			$search      = isset( $args['s'] ) ? strtolower( (string) $args['s'] ) : '';
			// Core's title handling in two steps, in core's own order: a scalar is
			// trimmed and anything else becomes '' (class-wp-query.php:850), then
			// the term is added only when the trimmed value is not '' — a strict
			// comparison, not a truthiness test (class-wp-query.php:2178), so the
			// title '0' filters and a whitespace-only one does not. The value
			// compared against is stripslashes() of it (class-wp-query.php:2179).
			$title_arg   = $args['title'] ?? '';
			$title       = is_scalar( $title_arg ) ? trim( (string) $title_arg ) : '';
			$title_match = '' === $title ? null : stripslashes( $title );
			$mime_prefix = isset( $args['post_mime_type'] ) ? (string) $args['post_mime_type'] : '';
			$fields      = $args['fields'] ?? '';
			$per_page    = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 10;
			$paged       = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;

			$matches = array();
			foreach ( (array) ( $GLOBALS['diviops_test_posts'] ?? array() ) as $post ) {
				if ( ! diviops_test_query_post_matches( $post, $filter ) ) {
					continue;
				}
				if ( '' !== $search && false === strpos( strtolower( (string) $post->post_title ), $search ) ) {
					continue;
				}
				if ( null !== $title_match && (string) $post->post_title !== $title_match ) {
					continue;
				}
				if ( '' !== $mime_prefix ) {
					$post_mime = isset( $post->post_mime_type ) ? (string) $post->post_mime_type : '';
					if ( 0 !== strpos( $post_mime, $mime_prefix ) ) {
						continue;
					}
				}
				if (
					! empty( $args['meta_query'] )
					&& ! diviops_test_meta_query_matches( (int) $post->ID, (array) $args['meta_query'] )
				) {
					continue;
				}
				$matches[] = $post;
			}

			// Core skips the count for a truthy no_found_rows rather than
			// computing it: set_found_posts() returns before assigning either
			// counter (class-wp-query.php:3699) and nothing else in the class
			// assigns them, so both keep the 0 they are declared with
			// (class-wp-query.php:178, 186). empty() is core's own (bool) cast
			// (class-wp-query.php:2063-2066) on every value it can be handed,
			// the string '0' included, and false is the default core assumes
			// when the argument is absent. Only the count is skipped — the LIMIT
			// still applies, so the slice below is deliberately outside this.
			if ( empty( $args['no_found_rows'] ) ) {
				$this->found_posts   = count( $matches );
				$this->max_num_pages = $per_page > 0 ? (int) ceil( $this->found_posts / $per_page ) : 0;
			}

			$offset     = ( $paged - 1 ) * $per_page;
			$page_posts = array_slice( $matches, $offset, $per_page );

			$this->posts = ( 'ids' === $fields )
				? array_map(
					function ( $p ) {
						return (int) $p->ID;
					},
					$page_posts
				)
				: $page_posts;
		}
	}
}

// ── Taxonomy term-relationship primitives ─────────────────────────────────
//
// WordPress models taxonomy registration as taxonomy => (post types it
// applies to) and term assignment as a per-object, per-taxonomy list of
// term ids (the wp_term_relationships table). These shims model that
// WP-core contract, not any Divi-specific behavior — the same category of
// primitive stub as the nav-menu/revision registries above. Used by
// page_duplicate()'s term-copy step (#35).

if ( ! isset( $GLOBALS['diviops_test_taxonomies'] ) ) {
	// taxonomy => list of post types it is registered against. Mirrors
	// WordPress's own defaults (category/post_tag on 'post', none on
	// 'page'); tests register more via diviops_test_register_taxonomy().
	$GLOBALS['diviops_test_taxonomies'] = array(
		'category' => array( 'post' ),
		'post_tag' => array( 'post' ),
	);
}

if ( ! function_exists( 'diviops_test_register_taxonomy' ) ) {
	/**
	 * Register a taxonomy against one or more post types, mirroring what
	 * WordPress core's register_taxonomy() records for get_object_taxonomies()
	 * to read back.
	 *
	 * @param string $taxonomy   Taxonomy name.
	 * @param array  $post_types Post types this taxonomy applies to.
	 */
	function diviops_test_register_taxonomy( string $taxonomy, array $post_types ) {
		$GLOBALS['diviops_test_taxonomies'][ $taxonomy ] = $post_types;
	}
}

if ( ! function_exists( 'get_object_taxonomies' ) ) {
	/**
	 * Model WP core's get_object_taxonomies(): the taxonomy names registered
	 * against a post type (accepts a post type string or a post-like object).
	 */
	function get_object_taxonomies( $object, $output = 'names' ) {
		$post_type = is_object( $object ) ? (string) ( $object->post_type ?? '' ) : (string) $object;
		$names     = array();
		foreach ( $GLOBALS['diviops_test_taxonomies'] as $taxonomy => $post_types ) {
			if ( in_array( $post_type, $post_types, true ) ) {
				$names[] = $taxonomy;
			}
		}
		return $names;
	}
}

if ( ! isset( $GLOBALS['diviops_test_object_terms'] ) ) {
	// [object_id][taxonomy] => array of term ids.
	$GLOBALS['diviops_test_object_terms'] = array();
}

if ( ! function_exists( 'diviops_test_register_object_terms' ) ) {
	/**
	 * Attach term ids to an object under a taxonomy, for wp_get_object_terms()
	 * to read back. The registration-time equivalent of wp_set_object_terms(),
	 * kept separate so a test's fixture setup and the handler's own writes
	 * (also via wp_set_object_terms()) are visibly distinct in a test file.
	 */
	function diviops_test_register_object_terms( int $post_id, string $taxonomy, array $term_ids ) {
		$GLOBALS['diviops_test_object_terms'][ $post_id ][ $taxonomy ] = array_map( 'intval', $term_ids );
	}
}

if ( ! function_exists( 'wp_get_object_terms' ) ) {
	/**
	 * Model WP core's wp_get_object_terms(): term ids (or minimal term
	 * objects) assigned to the given object id(s) under the given
	 * taxonomy/taxonomies. Only the 'fields' => 'ids' shape and the plain
	 * WP_Term-shaped default are modeled — the two shapes this codebase
	 * actually consumes.
	 */
	function wp_get_object_terms( $object_ids, $taxonomies, $args = array() ) {
		$object_ids = (array) $object_ids;
		$taxonomies = (array) $taxonomies;
		$ids        = array();
		foreach ( $object_ids as $object_id ) {
			foreach ( $taxonomies as $taxonomy ) {
				$ids = array_merge( $ids, $GLOBALS['diviops_test_object_terms'][ (int) $object_id ][ $taxonomy ] ?? array() );
			}
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
			return $ids;
		}

		return array_map(
			static function ( $id ) {
				return (object) array( 'term_id' => $id );
			},
			$ids
		);
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	/**
	 * Model WP core's wp_set_object_terms(): replace (or, with $append,
	 * merge into) an object's term list under one taxonomy. Returns the
	 * resulting term id list, mirroring core's success return of
	 * term_taxonomy_ids closely enough for callers here, which don't
	 * inspect the return value's shape.
	 */
	function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
		$object_id = (int) $object_id;
		$terms     = array_map( 'intval', (array) $terms );
		if ( $append && isset( $GLOBALS['diviops_test_object_terms'][ $object_id ][ $taxonomy ] ) ) {
			$terms = array_values( array_unique( array_merge( $GLOBALS['diviops_test_object_terms'][ $object_id ][ $taxonomy ], $terms ) ) );
		}
		$GLOBALS['diviops_test_object_terms'][ $object_id ][ $taxonomy ] = $terms;
		return $terms;
	}
}

if ( ! isset( $GLOBALS['diviops_test_term_cache_primed'] ) ) {
	$GLOBALS['diviops_test_term_cache_primed'] = array();
}

if ( ! function_exists( 'update_object_term_cache' ) ) {
	/**
	 * Model WP core's update_object_term_cache(): warm the object-term cache for
	 * a batch of ids ahead of the per-object term reads that follow.
	 *
	 * The observable effect here is none. wp_get_object_terms() above answers
	 * from $GLOBALS['diviops_test_object_terms'] directly and consults no cache,
	 * so there is no cache for this to fill and a stub that pretended to fill one
	 * would be modelling storage this harness does not have. What it does instead
	 * is RECORD the call, because the fact worth asserting is that a list handler
	 * primes the ids it is about to read one at a time — library_list() calls this
	 * with its page slice before reading each row's layout_type and scope, and
	 * dropping that call is a real N+1 regression no other assertion would catch.
	 *
	 * Test seam: $GLOBALS['diviops_test_term_cache_primed'] is a list of calls,
	 * each `array( 'ids' => int[], 'object_type' => string )`.
	 *
	 * @param int[]|int $object_ids  Object ids to prime.
	 * @param string    $object_type Object type ('post' for post-backed lists).
	 * @return void
	 */
	function update_object_term_cache( $object_ids, $object_type ) {
		$GLOBALS['diviops_test_term_cache_primed'][] = array(
			'ids'         => array_map( 'intval', (array) $object_ids ),
			'object_type' => (string) $object_type,
		);
	}
}

// ── media_set_featured_image() harness: thumbnail store + internal-request shim ──

if ( ! isset( $GLOBALS['diviops_test_thumbnails'] ) ) {
	$GLOBALS['diviops_test_thumbnails'] = array();
}
if ( ! isset( $GLOBALS['diviops_test_set_thumbnail_calls'] ) ) {
	$GLOBALS['diviops_test_set_thumbnail_calls'] = array();
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	/**
	 * Model WP core's get_post_thumbnail_id(): the post's featured-image
	 * attachment id from the harness thumbnail map, 0 when unset (mirroring
	 * core's cast of the unset `_thumbnail_id` meta through absint()). Test
	 * seam: $GLOBALS['diviops_test_thumbnails'][ post_id ] = attachment_id.
	 */
	function get_post_thumbnail_id( $post = null ) {
		$id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
		return (int) ( $GLOBALS['diviops_test_thumbnails'][ $id ] ?? 0 );
	}
}

if ( ! function_exists( 'set_post_thumbnail' ) ) {
	/**
	 * Model WP core's set_post_thumbnail(): record the attachment id as the
	 * post's featured image in the harness thumbnail map. Every call is also
	 * appended to diviops_test_set_thumbnail_calls, so a test can assert an
	 * idempotent no-op path never called this (not just that the end state
	 * happens to match). Returns true, as core does on success.
	 */
	function set_post_thumbnail( $post, $thumbnail_id ) {
		$id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
		$GLOBALS['diviops_test_thumbnails'][ $id ]     = (int) $thumbnail_id;
		$GLOBALS['diviops_test_set_thumbnail_calls'][] = array(
			'post_id'      => $id,
			'thumbnail_id' => (int) $thumbnail_id,
		);
		return true;
	}
}

if ( ! function_exists( 'has_post_thumbnail' ) ) {
	function has_post_thumbnail( $post = null ) {
		return get_post_thumbnail_id( $post ) > 0;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal stand-in for WordPress core's WP_REST_Request, covering only
	 * what media_set_featured_image()'s internal call to media_upload()
	 * needs: construct, set_param(), then get_param(). Real WP_REST_Request
	 * ships with the WordPress runtime in production; this shim exists so the
	 * harness can load and exercise the SAME production code path rather than
	 * a test-only substitute of it.
	 */
	class WP_REST_Request implements ArrayAccess {

		private $method;
		private $route;
		private $params = array();

		public function __construct( $method = '', $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
			return true;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		#[\ReturnTypeWillChange]
		public function offsetGet( $offset ) {
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

if ( ! function_exists( 'diviops_call_static' ) ) {
	/**
	 * Call a private static method on DiviOps_Agent, with no request object.
	 *
	 * Mirrors diviops_call(), but for helpers that take plain positional args
	 * instead of a REST request — e.g. media_ip_is_reserved(), media_url_is_safe().
	 * Delegates to diviops_call_ref() rather than duplicating its
	 * ReflectionMethod::invokeArgs() logic: passing this function's local
	 * $args into a by-reference parameter just aliases the same array, and
	 * PHP preserves per-element reference status across that alias, so an
	 * $args element built as a reference (e.g. `array( $url, &$reason,
	 * $resolver )`) still binds through to the callee's by-reference
	 * parameter exactly as it did with the duplicated implementation.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 * @return mixed
	 */
	function diviops_call_static( string $method, array $args = array() ) {
		return diviops_call_ref( $method, $args );
	}
}
