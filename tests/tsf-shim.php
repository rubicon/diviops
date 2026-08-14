<?php
/**
 * Faithful stub of The SEO Framework's (TSF) public API surface that
 * DiviOps_SEO_TSF_Adapter (trait-seo.php) depends on.
 *
 * Modeled directly against the real `autodescription` 5.1.4 plugin, installed but
 * inactive on the reference site
 * (`/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/plugins/autodescription`),
 * the exact version this adapter's MIN_VERSION/discovery() targets:
 *   - inc/classes/pool.class.php               (tsf()->X() factory method names)
 *   - inc/classes/data/plugin/post.class.php    (Post::get_meta_item/get_default_meta/
 *                                                 update_single_meta_item/save_meta)
 *   - inc/classes/data/filter/sanitize.class.php (Sanitize::metadata_content)
 *   - inc/classes/meta/{title,description,open-graph,twitter}.class.php
 *
 * What IS faithful: every tsf()->X() call site the adapter makes exists here with the
 * real method signature; get_meta_item()/get_default_meta()/update_single_meta_item()
 * share the SAME WordPress postmeta store tests/wp-shim.php's get_post_meta()/
 * update_post_meta()/delete_post_meta() already provide, so the adapter's read/write/
 * rollback machinery runs against real persisted state, not a canned response; and
 * update_single_meta_item() reproduces TSF's real full-default-key REWRITE behavior
 * (confirmed by reading Post::save_meta() directly: every single-field write re-merges
 * the post's full meta view and loops EVERY one of the 17 default keys, update_post_meta
 * on a truthy/non-empty-string value or delete_post_meta on an empty one) rather than
 * touching only the field being written. This matters because DiviOps's own
 * read_registered_state()/restore_registered_state() rollback machinery snapshots the
 * WHOLE default-meta key set, not just the semantic fields DiviOps exposes -- a stub
 * that only touched the written key would make that rollback path a mocked-behavior
 * test that passes while proving nothing, exactly the class of bug this fork's own
 * #28/#36/#97 lesson warns about.
 *
 * What is deliberately NOT modeled:
 *   - TSF's real title/description/OG/Twitter GENERATION logic (custom-field-with-
 *     fallback-to-generated, homepage overrides, Twitter's fallback to Open Graph).
 *     That logic lives entirely inside TSF's own Title/Description/Open_Graph/Twitter
 *     classes and depends on WordPress query state (front-page detection, site
 *     options) this harness has none of. DiviOps_SEO_TSF_Adapter never re-derives that
 *     logic itself -- effective_fields() only calls the real tsf()->title()->get_title()
 *     etc. accessor and packages the return value -- so tests script each effective
 *     getter's return value directly via $GLOBALS['diviops_test_tsf_effective'], the
 *     same "record what was asked, script what comes back" pattern the media suite
 *     uses for wp_remote_get(). Reimplementing TSF's decision tree here would risk
 *     being wrong in a way nothing would catch: the opposite of faithful.
 *   - Sanitize::metadata_content()'s full pipeline. The early steps (nbsp/newline/tab
 *     to space, trim, collapse repeated spacing) are plain string operations and are
 *     modeled exactly; the later steps (lone_hyphen_to_entity, backward_solidus_to_entity,
 *     capital_P_dangit, wptexturize, html_entity_decode with ENT_HTML5) depend on
 *     WordPress-core formatting functions this harness does not have and are
 *     deliberately NOT modeled. Trait-seo.php's own seo_validate_plain_text() already
 *     rejects markup, control characters, and dynamic tokens before sanitize() ever
 *     runs, so the unmodeled steps affect only typographic punctuation on already-
 *     validated plain text, not the behavior under test.
 *
 * @package DiviOps
 */

declare( strict_types = 1 );

require_once __DIR__ . '/wp-shim.php';

if ( ! defined( 'THE_SEO_FRAMEWORK_PRESENT' ) ) {
	define( 'THE_SEO_FRAMEWORK_PRESENT', true );
}

// discovery()'s active-and-compatible branch requires this constant. The
// active-but-incompatible branch cannot be driven within this process (PHP
// constants are immutable once defined, and every test file in tests/run.php
// shares one process) -- test-seo.php exercises it via a child PHP process
// that defines a different value before requiring this file, mirroring
// test-media-svg-capability-gate.php's constant-precedence child-process check.
if ( ! defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
	define( 'THE_SEO_FRAMEWORK_VERSION', '5.1.4' );
}

// discovery()'s installed-but-inactive path reads the plugin's header comment
// from WP_PLUGIN_DIR/PLUGIN_FILE via is_file()/file_get_contents(). Left
// undefined here (falling back to ABSPATH . 'wp-content/plugins', which does
// not exist in this harness) so the DEFAULT state across this shared process
// is "not installed" -- a fixed WP_PLUGIN_DIR pointing at a real fixture file
// would make "not installed" unreachable for every test in the same process.
// test-seo.php drives "installed but inactive" via a child PHP process that
// defines WP_PLUGIN_DIR to tests/fixtures/tsf-plugin before requiring this
// file, the same technique used below for the version-incompatible branch.

if ( ! function_exists( 'metadata_exists' ) ) {
	/**
	 * Model WP core's metadata_exists(): whether the given meta key has a row at
	 * all, backed by the same scalar-per-key postmeta store get_post_meta()/
	 * update_post_meta() (wp-shim.php) already read and write.
	 */
	function metadata_exists( $meta_type, $object_id, $meta_key ) {
		if ( 'post' !== $meta_type ) {
			return false;
		}
		$meta = $GLOBALS['diviops_test_post_meta'][ $object_id ] ?? array();
		return array_key_exists( $meta_key, $meta );
	}
}

if ( ! function_exists( 'add_post_meta' ) ) {
	/**
	 * Model WP core's add_post_meta() against the same scalar-per-key postmeta
	 * store update_post_meta()/get_post_meta() (wp-shim.php) use. Every field TSF
	 * registers is single-row (each key's default in Post::get_default_meta() is a
	 * scalar, never an array), so a scalar store models the real shape faithfully;
	 * this does not attempt true duplicate-key multi-row postmeta, which nothing
	 * under test here needs.
	 */
	function add_post_meta( $post_id, $meta_key, $meta_value, $unique = false ) {
		$GLOBALS['diviops_test_post_meta'][ $post_id ][ $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'diviops_test_tsf_reset' ) ) {
	/**
	 * Reset every TSF test seam to a fresh active-and-compatible state. Call at the
	 * start of each isolated test group so cases don't leak scripted effective
	 * values or plugin-state overrides into each other.
	 *
	 * Drives discovery()'s real gates, not a parallel seam: $listed_active reads
	 * get_option('active_plugins') / get_site_option('active_sitewide_plugins')
	 * exactly as the adapter does, so "not installed" / "installed but inactive"
	 * are reachable by clearing these options rather than by a separate flag
	 * discovery() would never actually consult.
	 */
	function diviops_test_tsf_reset(): void {
		$GLOBALS['diviops_test_options']['active_plugins']              = array( DiviOps_SEO_TSF_Adapter::PLUGIN_FILE );
		$GLOBALS['diviops_test_site_options']['active_sitewide_plugins'] = array();
		$GLOBALS['diviops_test_tsf_supported_post_type']                = true;
		$GLOBALS['diviops_test_tsf_effective']                          = array();
		$GLOBALS['diviops_test_tsf_write_throws']                       = null;
		$GLOBALS['diviops_test_tsf_write_corrupt_field']                = null;
	}
}
diviops_test_tsf_reset();

if ( ! class_exists( 'DiviOps_Test_TSF_Post_API' ) ) {
	/**
	 * Faithful stub of Data\Plugin\Post, reached via tsf()->data()->plugin()->post().
	 */
	final class DiviOps_Test_TSF_Post_API {

		/**
		 * Verbatim key set and defaults from Post::get_default_meta() (17 keys),
		 * autodescription 5.1.4, read directly from the reference site's plugin copy.
		 */
		public static function default_meta(): array {
			return array(
				'_genesis_title'          => '',
				'_tsf_title_no_blogname'  => 0,
				'_genesis_description'    => '',
				'_genesis_canonical_uri'  => '',
				'redirect'                => '',
				'_social_image_url'       => '',
				'_social_image_id'        => 0,
				'_genesis_noindex'        => 0,
				'_genesis_nofollow'       => 0,
				'_genesis_noarchive'      => 0,
				'exclude_local_search'    => 0,
				'exclude_from_archive'    => 0,
				'_open_graph_title'       => '',
				'_open_graph_description' => '',
				'_twitter_title'          => '',
				'_twitter_description'    => '',
				'_tsf_twitter_card_type'  => '',
			);
		}

		public function get_default_meta( $post_id = 0 ) {
			return self::default_meta();
		}

		/**
		 * Mirrors Post::get_meta(): the default set overlaid with whichever of
		 * those same keys is actually stored for this post.
		 */
		private static function current_meta( int $post_id ): array {
			$defaults = self::default_meta();
			$stored   = $GLOBALS['diviops_test_post_meta'][ $post_id ] ?? array();
			return array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
		}

		public function get_meta_item( $item, $post_id = 0 ) {
			$meta = self::current_meta( (int) $post_id );
			return $meta[ $item ] ?? null;
		}

		/**
		 * Mirrors Post::update_single_meta_item() -> save_meta(): sets the one
		 * item, then rewrites EVERY default-meta key -- update_post_meta() for a
		 * truthy or non-empty-string value, delete_post_meta() for an empty one --
		 * exactly as the real save_meta() loop does.
		 *
		 * Two test seams model provider failure modes that are real possibilities
		 * for any third-party plugin's write path but that a healthy stub cannot
		 * otherwise produce, so DiviOps's own rollback machinery
		 * (seo_apply_failure_with_rollback / restore_registered_state) would
		 * otherwise stay completely unexercised:
		 * Both seams key on the PROVIDER meta key (e.g. '_open_graph_title'), the
		 * same $item this method itself receives -- not the semantic field name,
		 * which is a DiviOps-only concept the storage layer never sees:
		 *   - diviops_test_tsf_write_throws: named provider key => throw instead
		 *     of writing, modeling a hard provider failure (e.g. a DB error).
		 *   - diviops_test_tsf_write_corrupt_field: named provider key => persist
		 *     a value OTHER than the one requested, modeling a provider that
		 *     reports success but silently didn't apply the write.
		 */
		public function update_single_meta_item( $item, $value, $post_id ) {
			if ( $item === ( $GLOBALS['diviops_test_tsf_write_throws'] ?? null ) ) {
				throw new RuntimeException( 'diviops test harness: scripted provider write failure.' );
			}
			if ( $item === ( $GLOBALS['diviops_test_tsf_write_corrupt_field'] ?? null ) ) {
				$value = $value . '-CORRUPTED-BY-PROVIDER';
			}

			$post_id       = (int) $post_id;
			$meta          = self::current_meta( $post_id );
			$meta[ $item ] = $value;

			foreach ( $meta as $field => $field_value ) {
				if ( $field_value || ( is_string( $field_value ) && strlen( $field_value ) ) ) {
					update_post_meta( $post_id, $field, $field_value );
				} else {
					delete_post_meta( $post_id, $field );
				}
			}
		}

		public function refresh_static_properties() {
			// Real TSF clears its request-local $meta_memo static cache here. This
			// stub reads storage fresh on every call, so there is no memo to
			// clear; the method exists only so the adapter's call succeeds.
		}
	}
}

if ( ! class_exists( 'DiviOps_Test_TSF_Plugin' ) ) {
	final class DiviOps_Test_TSF_Plugin {
		public function post() {
			return new DiviOps_Test_TSF_Post_API();
		}
	}
}

if ( ! class_exists( 'DiviOps_Test_TSF_Data' ) ) {
	final class DiviOps_Test_TSF_Data {
		public function plugin() {
			return new DiviOps_Test_TSF_Plugin();
		}
	}
}

if ( ! class_exists( 'DiviOps_Test_TSF_Sanitize' ) ) {
	final class DiviOps_Test_TSF_Sanitize {
		/**
		 * Models Sanitize::metadata_content()'s plain-string-operation steps only
		 * -- see the file-level docblock for what is deliberately unmodeled.
		 */
		public function metadata_content( $text ) {
			if ( ! is_scalar( $text ) || '' === (string) $text ) {
				return '';
			}
			$text = (string) $text;
			$text = str_replace( "\xC2\xA0", ' ', $text ); // nbsp_to_space.
			$text = str_replace( array( "\r\n", "\r", "\n" ), ' ', $text ); // newline_to_space.
			$text = str_replace( "\t", ' ', $text ); // tab_to_space.
			$text = trim( $text );
			$text = preg_replace( '/ {2,}/', ' ', $text ); // remove_repeated_spacing.
			return $text;
		}
	}
}

if ( ! class_exists( 'DiviOps_Test_TSF_Post_Type' ) ) {
	final class DiviOps_Test_TSF_Post_Type {
		public function is_supported( $post_type ) {
			return (bool) ( $GLOBALS['diviops_test_tsf_supported_post_type'] ?? true );
		}
	}
}

if ( ! class_exists( 'DiviOps_Test_TSF_Title' ) ) {
	final class DiviOps_Test_TSF_Title {
		public function get_title( $args = null ) {
			return (string) ( $GLOBALS['diviops_test_tsf_effective']['seo_title'] ?? '' );
		}
	}
}

if ( ! class_exists( 'DiviOps_Test_TSF_Description' ) ) {
	final class DiviOps_Test_TSF_Description {
		public function get_description( $args = null ) {
			return (string) ( $GLOBALS['diviops_test_tsf_effective']['meta_description'] ?? '' );
		}
	}
}

if ( ! class_exists( 'DiviOps_Test_TSF_Open_Graph' ) ) {
	final class DiviOps_Test_TSF_Open_Graph {
		public function get_title( $args = null ) {
			return (string) ( $GLOBALS['diviops_test_tsf_effective']['og_title'] ?? '' );
		}
		public function get_description( $args = null ) {
			return (string) ( $GLOBALS['diviops_test_tsf_effective']['og_description'] ?? '' );
		}
	}
}

if ( ! class_exists( 'DiviOps_Test_TSF_Twitter' ) ) {
	final class DiviOps_Test_TSF_Twitter {
		public function get_title( $args = null ) {
			return (string) ( $GLOBALS['diviops_test_tsf_effective']['twitter_title'] ?? '' );
		}
		public function get_description( $args = null ) {
			return (string) ( $GLOBALS['diviops_test_tsf_effective']['twitter_description'] ?? '' );
		}
	}
}

if ( ! class_exists( 'DiviOps_Test_TSF_Root' ) ) {
	final class DiviOps_Test_TSF_Root {
		public function data() {
			return new DiviOps_Test_TSF_Data();
		}
		public function sanitize() {
			return new DiviOps_Test_TSF_Sanitize();
		}
		public function post_type() {
			return new DiviOps_Test_TSF_Post_Type();
		}
		public function title() {
			return new DiviOps_Test_TSF_Title();
		}
		public function description() {
			return new DiviOps_Test_TSF_Description();
		}
		public function open_graph() {
			return new DiviOps_Test_TSF_Open_Graph();
		}
		public function twitter() {
			return new DiviOps_Test_TSF_Twitter();
		}
	}
}

if ( ! function_exists( 'tsf' ) ) {
	/**
	 * Real signature: `function tsf(): The_SEO_Framework\Load` (inc/functions/api.php).
	 * discovery()'s active gate also requires function_exists('tsf'), which this
	 * satisfies for every test unconditionally -- inactive/absent-provider states
	 * are driven through $GLOBALS['diviops_test_tsf_active']/'_installed', which
	 * discovery() consults via the seams below, not by undefining this function.
	 */
	function tsf() {
		return new DiviOps_Test_TSF_Root();
	}
}
