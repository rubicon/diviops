<?php
// SPDX-License-Identifier: MIT
/**
 * Fixture: a map that serves a module and does nothing to it.
 *
 * Divi ships five of these on 5.9.0 (`divi/canvas-portal`, `divi/group-carousel`,
 * `divi/icon-list`, `divi/icon-list-item`, `divi/lottie`): the guard matches, and the
 * body returns the same map the guard already returned. Serving the module and merely
 * naming it are behaviourally identical here, which is what forces the resolver to read
 * the guard for this one case.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\ModuleLibrary\InertFixture;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Returns the base map untouched for the module it serves.
 */
class InertFixturePresetAttrsMap {

	/**
	 * Get the preset attributes map.
	 *
	 * @param array  $map         The preset attributes map.
	 * @param string $module_name The module name.
	 *
	 * @return array
	 */
	public static function get_map( array $map, string $module_name ) {
		if ( 'divi/fixture-inert' !== $module_name ) {
			return $map;
		}

		return $map;
	}
}
