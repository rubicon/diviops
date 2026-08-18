<?php
// SPDX-License-Identifier: MIT
/**
 * Fixture: a module map that composes a shared family under two element prefixes.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\ModuleLibrary\ComposedFixture;

use DiviOps\Fixtures\Packages\Module\Options\FixtureFamily\FixtureFamilyPresetAttrsMap;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Merges the shared family under `title.decoration` and `button.decoration`.
 */
class ComposedFixturePresetAttrsMap {

	/**
	 * Get the preset attributes map.
	 *
	 * @param array  $map         The preset attributes map.
	 * @param string $module_name The module name.
	 *
	 * @return array
	 */
	public static function get_map( array $map, string $module_name ) {
		if ( 'divi/fixture-composed' !== $module_name ) {
			return $map;
		}

		return array_merge(
			$map,
			FixtureFamilyPresetAttrsMap::get_map( 'title.decoration' ),
			FixtureFamilyPresetAttrsMap::get_map( 'button.decoration' )
		);
	}
}
