<?php
/**
 * Fixture: a module map that removes keys and then adds keys back.
 *
 * The order is the point. `get_map()` unsets first and merges second, so a key that
 * appears in both lists survives, while a key that appears only in the unset list is
 * gone. A resolver that applied the two lists in either order, or that treated them as
 * one flat set of strings, would get this fixture wrong.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\ModuleLibrary\UnsetThenAddFixture;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Removes three keys, then re-adds one of them alongside a new one.
 */
class UnsetThenAddFixturePresetAttrsMap {

	/**
	 * Get the preset attributes map.
	 *
	 * @param array  $map         The preset attributes map.
	 * @param string $module_name The module name.
	 *
	 * @return array
	 */
	public static function get_map( array $map, string $module_name ) {
		if ( 'divi/fixture-unset-then-add' !== $module_name ) {
			return $map;
		}

		$keys_to_unset = array(
			'button.decoration.button.decoration.background__color',
			'button.decoration.button.decoration.border__radius',
			'button.decoration.sizing__width',
		);

		foreach ( $keys_to_unset as $key ) {
			unset( $map[ $key ] );
		}

		return array_merge(
			$map,
			array(
				'button.decoration.sizing__width' => array(
					'attrName' => 'button.decoration.sizing',
					'preset'   => array( 'style' ),
					'subName'  => 'width',
				),
				'button.decoration.background__color' => array(
					'attrName' => 'button.decoration.background',
					'preset'   => array( 'style' ),
					'subName'  => 'color',
				),
			)
		);
	}
}
