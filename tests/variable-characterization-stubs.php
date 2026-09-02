<?php
// SPDX-License-Identifier: MIT
/**
 * Two class stubs tests/test-variable-characterization.php needs and
 * tests/wp-shim.php does not carry.
 *
 * These live here rather than in the shared shim on purpose. The shim is edited
 * concurrently by other work, and a suite that widens the shared harness to make
 * its own assertions reachable produces a green that outlives the reason for it.
 * Both stubs below are *class* definitions, so they are process-wide once this
 * file is required — the reasoning for why that is safe is recorded per stub.
 *
 * Neither stub stands in for behaviour under test. `WP_Post` is a data carrier
 * that only has to satisfy an `instanceof`; `GlobalData` is consulted for one
 * public static array whose contents are copied from Divi's own source. Anything
 * that would mean writing the behaviour being characterized —
 * `et_get_option()`, `parse_blocks()` — is deliberately absent here too; see the
 * "What is not reachable here" section of test-variable-characterization.php.
 *
 * @package DiviOps
 */

namespace {

	if ( ! class_exists( 'WP_Post' ) ) {
		/**
		 * Data-carrier stand-in for WordPress core's WP_Post.
		 *
		 * `variable_used_on_page()` gates on `$post instanceof WP_Post`
		 * (trait-variable.php:2344) before it does anything else with the row.
		 * The shim's `diviops_test_register_post()` hands back a `stdClass`, so
		 * without this class every post id in the harness — registered or not —
		 * falls into the `not_found` branch, and the handler's per-object read
		 * gate and its Divi-missing branch are both unreachable behind it.
		 *
		 * Modelled on core's own constructor at
		 * wp-includes/class-wp-post.php:311-315, which copies every property off
		 * the object it is handed rather than declaring a fixed set:
		 *
		 *     foreach ( get_object_vars( $post ) as $key => $value ) {
		 *         $this->$key = $value;
		 *     }
		 *
		 * Defining this class cannot change any other test's behaviour: the
		 * shim's fixtures stay `stdClass`, and `stdClass instanceof WP_Post` is
		 * false either way. It only lets a fixture that opts in — via
		 * `diviops_variable_register_wp_post()` below — pass a gate it
		 * previously could not.
		 */
		class WP_Post {

			/** @var int */
			public $ID = 0;

			/** @var string */
			public $post_content = '';

			/** @var string */
			public $post_type = 'page';

			/** @var string */
			public $post_title = '';

			/** @var string */
			public $post_excerpt = '';

			/** @var int */
			public $post_parent = 0;

			/** @var int */
			public $menu_order = 0;

			/** @var string */
			public $post_status = 'publish';

			/**
			 * @param object|array $post Row data.
			 */
			public function __construct( $post ) {
				foreach ( get_object_vars( (object) $post ) as $key => $value ) {
					$this->$key = $value;
				}
			}
		}
	}

	if ( ! function_exists( 'diviops_variable_register_wp_post' ) ) {
		/**
		 * Register a fixture in the shared post registry as a real `WP_Post`.
		 *
		 * Same store and same column defaults as the shim's
		 * `diviops_test_register_post()`, so `get_post()` finds it and every
		 * other reader behaves identically — the only difference is the class,
		 * which is the whole point.
		 *
		 * @param int    $post_id   Post id.
		 * @param string $content   post_content.
		 * @param string $post_type post_type.
		 * @param string $title     post_title.
		 * @return \WP_Post
		 */
		function diviops_variable_register_wp_post( int $post_id, string $content = '', string $post_type = 'page', string $title = '' ): \WP_Post {
			$post = new \WP_Post(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
					'post_type'    => $post_type,
					'post_title'   => $title,
					'post_excerpt' => '',
					'post_parent'  => 0,
					'menu_order'   => 0,
					'post_status'  => 'publish',
				)
			);
			$GLOBALS['diviops_test_posts'][ $post_id ] = $post;
			return $post;
		}
	}
}

namespace ET\Builder\Packages\GlobalData {

	if ( ! class_exists( '\ET\Builder\Packages\GlobalData\GlobalData' ) ) {
		/**
		 * Divi 5's GlobalData, reduced to the one public static property
		 * trait-variable.php reads from it.
		 *
		 * Three call sites consult it, each behind `class_exists()`:
		 * `get_customizer_color_count()` (:536-539), `variable_update()`
		 * (:1911-1922) and `variable_delete()` (:2101-2112). Without the class
		 * those three guards are simply false, so the
		 * `variable.customizer_default_immutable` refusal — a distinct error
		 * code with its own HTTP 403, named in the trait's file docblock — can
		 * never be observed, and neither can the create-order offset that reads
		 * the count.
		 *
		 * `$customizer_colors` is copied verbatim from the Divi 5 source on this
		 * fork's reference install, at `wp-content/themes/Divi/includes/
		 * builder-5/server/Packages/GlobalData/GlobalData.php:58-84`. Ids,
		 * labels, option_names and defaults are Divi's, not invented here: the
		 * id set and its count are exactly what the plugin reads, so a fixture
		 * built on a shortened list would silently mis-characterize both the
		 * refusal and the order offset.
		 *
		 * Safe to define process-wide: the only other consumers in the plugin
		 * are trait-global-color.php:115 and trait-theme-builder.php:826, and
		 * both read this same property for the same purpose. No test asserts
		 * the class is absent.
		 */
		class GlobalData {

			/**
			 * Verbatim from Divi 5's GlobalData.php:58-84.
			 *
			 * @var array<string, array<string, string>>
			 */
			public static $customizer_colors = array(
				'gcid-primary-color'   => array(
					'label'       => 'Primary Color',
					'option_name' => 'accent_color',
					'default'     => '#2ea3f2',
				),
				'gcid-secondary-color' => array(
					'label'       => 'Secondary Color',
					'option_name' => 'secondary_accent_color',
					'default'     => '#2ea3f2',
				),
				'gcid-heading-color'   => array(
					'label'       => 'Heading Text Color',
					'option_name' => 'header_color',
					'default'     => '#666666',
				),
				'gcid-body-color'      => array(
					'label'       => 'Body Text Color',
					'option_name' => 'font_color',
					'default'     => '#666666',
				),
				'gcid-link-color'      => array(
					'label'       => 'Link Color',
					'option_name' => 'link_color',
					'default'     => '#2ea3f2',
				),
			);
		}
	}
}
