<?php
/**
 * Fixture: one map file serving two module names.
 *
 * Divi does this for real: `FullwidthPostContentPresetAttrsMap` guards on
 * `in_array( $module_name, [ 'divi/fullwidth-post-content', 'divi/post-content' ], true )`.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\ModuleLibrary\TwoNamesFixture;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Serves both module names identically.
 */
class TwoNamesFixturePresetAttrsMap {

	/**
	 * Get the preset attributes map.
	 *
	 * @param array  $map         The preset attributes map.
	 * @param string $module_name The module name.
	 *
	 * @return array
	 */
	public static function get_map( array $map, string $module_name ) {
		if ( ! in_array( $module_name, array( 'divi/fixture-two-names', 'divi/fixture-two-names-alt' ), true ) ) {
			return $map;
		}

		return array_merge(
			$map,
			array(
				'content.innerContent' => array(
					'attrName' => 'content.innerContent',
					'preset'   => 'content',
				),
			)
		);
	}
}
