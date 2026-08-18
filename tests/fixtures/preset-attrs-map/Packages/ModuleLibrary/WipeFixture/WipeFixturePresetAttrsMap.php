<?php
// SPDX-License-Identifier: MIT
/**
 * Fixture: a module map that discards the base map wholesale.
 *
 * Divi does this for real: `SocialMediaFollowItemPresetAttrsMap::get_map()` returns
 * `[]` for `divi/social-media-follow-network`, so every key the conversion layer
 * generated for that module is dropped and nothing replaces it. The file body is a
 * guard and a `return []`, with no path strings in it at all.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\ModuleLibrary\WipeFixture;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Returns an empty map for the module it serves.
 */
class WipeFixturePresetAttrsMap {

	/**
	 * Get the preset attributes map.
	 *
	 * @param array  $map         The preset attributes map.
	 * @param string $module_name The module name.
	 *
	 * @return array
	 */
	public static function get_map( array $map, string $module_name ) {
		if ( 'divi/fixture-wipe' !== $module_name ) {
			return $map;
		}

		return array();
	}
}
