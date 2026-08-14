<?php
/**
 * Fixture: a module map that only removes keys.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\ModuleLibrary\UnsetOnlyFixture;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Removes two keys from the base map and adds nothing back.
 */
class UnsetOnlyFixturePresetAttrsMap {

	/**
	 * Get the preset attributes map.
	 *
	 * @param array  $map         The preset attributes map.
	 * @param string $module_name The module name.
	 *
	 * @return array
	 */
	public static function get_map( array $map, string $module_name ) {
		if ( 'divi/fixture-unset-only' !== $module_name ) {
			return $map;
		}

		$keys_to_unset = array(
			'body.decoration.body.decoration.font__size',
			'body.decoration.body.decoration.font__color',
		);

		foreach ( $keys_to_unset as $key ) {
			unset( $map[ $key ] );
		}

		return $map;
	}
}
