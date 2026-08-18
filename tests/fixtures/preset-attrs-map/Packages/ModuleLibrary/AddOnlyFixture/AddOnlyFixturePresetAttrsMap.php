<?php
// SPDX-License-Identifier: MIT
/**
 * Fixture: a module map that only adds keys.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\ModuleLibrary\AddOnlyFixture;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Adds two keys to whatever base map it is handed and removes nothing.
 */
class AddOnlyFixturePresetAttrsMap {

	/**
	 * Get the preset attributes map.
	 *
	 * @param array  $map         The preset attributes map.
	 * @param string $module_name The module name.
	 *
	 * @return array
	 */
	public static function get_map( array $map, string $module_name ) {
		if ( 'divi/fixture-add-only' !== $module_name ) {
			return $map;
		}

		return array_merge(
			$map,
			array(
				'title.innerContent'         => array(
					'attrName' => 'title.innerContent',
					'preset'   => 'content',
				),
				'title.decoration.font__size' => array(
					'attrName' => 'title.decoration.font',
					'preset'   => array( 'style' ),
					'subName'  => 'size',
				),
			)
		);
	}
}
