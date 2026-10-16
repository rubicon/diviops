<?php
// SPDX-License-Identifier: MIT
/**
 * A `WP_Block_Type_Registry` shaped like a context where Divi's own block
 * registration has run, so a write handler's validation gate can be reached
 * (#598).
 *
 * `tests/wp-shim.php` models the registry a WP-CLI process sees: Divi's own
 * modules "largely absent". That is accurate and load-bearing for the suites
 * that pin the validator's narrow parser-level exemption, and it is NOT changed
 * here — nothing in this file edits the shared shim.
 *
 * It is also the wrong world for driving a write end to end. Every Divi write
 * path runs `normalize_and_validate_divi_markup_before_write()`, whose tree walk
 * reports `unknown_block_type` for any `divi/*` block the registry does not
 * carry, and the handler refuses on a non-empty error list. Against the stock
 * registry a perfectly ordinary `divi/row` is refused, so the handler returns
 * `invalid_input` without ever reaching its write — which is a property of the
 * harness, not of the plugin.
 *
 * Registry membership for `divi/*` is genuinely context-dependent, which is why
 * two models can both be right. `trait-validate.php`'s own measurement records
 * that on the reference install (Divi 5.11.1) a WP-CLI process sees exactly two
 * registered `divi/*` types while a REST request sees essentially all of them,
 * and every diviops-agent write arrives as a real `register_rest_route()`
 * dispatch. This file is the REST side of that split.
 *
 * Primary source for the names, read on staging (Divi 5.13) rather than assumed:
 * `ModuleRegistration::register_block_type_from_metadata()` registers each
 * module from its metadata, and the names come from
 * `includes/builder-5/visual-builder/packages/module-library/src/components/<module>/module.json`
 * — `row` declares `divi/row`, `column` declares `divi/column`, `section`
 * declares `divi/section`, `text` declares `divi/text`, across 114 module.json
 * files in total.
 *
 * The four `divi/*` entries here are the ones this harness's fixtures build
 * with, NOT a claim about the complete registry — transcribing all 114 would be
 * a list with nothing keeping it current. `divi/global-layout` and `divi/root`
 * are deliberately absent: `test-validate-parser-level-blocks.php` measured them
 * as never registered in either context, so leaving them out keeps the
 * parser-level exemption exercisable rather than masked.
 *
 * **Require this BEFORE `wp-shim.php` or anything that requires it**, the
 * inverse of `wp-date-hierarchy-shim.php`'s order. The shim guards its own
 * registry with `class_exists()`, so defining this class first makes the shim
 * skip its copy; requiring it afterwards silently does nothing at all. The
 * requiring suite is expected to assert the class it got is this one.
 *
 * @package DiviOps
 */

declare( strict_types = 1 );

if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	/**
	 * Same surface as the shim's registry — `get_instance()`,
	 * `get_registered()`, `get_all_registered()`, returning a `stdClass` with
	 * `name` and `category` — so a handler cannot tell which one it got except
	 * by what is registered.
	 */
	class WP_Block_Type_Registry {

		/**
		 * Marks this as the REST-shaped registry so a suite can assert it loaded
		 * the one it meant to, rather than silently running against the shim's.
		 */
		const DIVIOPS_REST_SHAPED = true;

		private static $instance = null;

		/**
		 * Every entry from the shim's own registry, carried over verbatim so
		 * this override is strictly additive, plus the four `divi/*` container
		 * and module types above. `test-wp-source-block-parser.php` reads the
		 * shim's array out of its source and asserts none of its keys went
		 * missing here, so the carried-over half cannot drift.
		 */
		private $blocks = array(
			'difl/faq'          => 'module',
			'difl/faqitem'      => 'child-module',
			'difl/counter'      => 'module',
			'd5bgo/bg-overlay'  => 'module',
			'gravityforms/form' => '',
			'tec/event'         => '',
			'core/paragraph'    => 'text',
			'divi/section'      => 'module',
			'divi/row'          => 'module',
			'divi/column'       => 'module',
			'divi/text'         => 'module',
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
