<?php
// SPDX-License-Identifier: MIT
/**
 * Fixture: a shared decoration-family map.
 *
 * Divi's own shared families take the element prefix as an argument and key their
 * return on it, which is how one family serves every element of every module. Real
 * example: `TextEffectsPresetAttrsMap::get_map( 'button.decoration.font' )`.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\Module\Options\FixtureFamily;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Emits two subfields under whatever attribute prefix it is given.
 */
class FixtureFamilyPresetAttrsMap {

	/**
	 * Get the map for this family under a given attribute prefix.
	 *
	 * @param string $attr_name The attribute name.
	 *
	 * @return array
	 */
	public static function get_map( string $attr_name ) {
		return array(
			"{$attr_name}.fixtureFamily__intensity" => array(
				'attrName' => "{$attr_name}.fixtureFamily",
				'preset'   => array( 'style' ),
				'subName'  => 'intensity',
			),
			"{$attr_name}.fixtureFamily__color"     => array(
				'attrName' => "{$attr_name}.fixtureFamily",
				'preset'   => array( 'style' ),
				'subName'  => 'color',
			),
		);
	}
}
