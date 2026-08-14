<?php
/**
 * Fixture: a shared family map that delegates most of its vocabulary to a sibling.
 *
 * Divi's own `ButtonPresetAttrsMap` declares six keys and gets the other 143 from seven
 * sibling family maps, none of which are spelled in its source. This fixture reproduces
 * that shape at small scale so the resolver can be shown finding keys a text scan of the
 * file cannot.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\Module\Options\FixtureComposite;

use DiviOps\Fixtures\Packages\Module\Options\FixtureFamily\FixtureFamilyPresetAttrsMap;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Emits one subfield of its own and delegates two element prefixes to a sibling family.
 */
class FixtureCompositePresetAttrsMap {

	/**
	 * Get the map for this family under a given attribute prefix.
	 *
	 * @param string $attr_name The attribute name.
	 *
	 * @return array
	 */
	public static function get_map( string $attr_name ) {
		$own = array(
			"{$attr_name}.decoration.fixtureComposite__mode" => array(
				'attrName' => "{$attr_name}.decoration.fixtureComposite",
				'preset'   => array( 'style' ),
				'subName'  => 'mode',
			),
		);

		return array_merge(
			$own,
			FixtureFamilyPresetAttrsMap::get_map( "{$attr_name}.decoration" ),
			FixtureFamilyPresetAttrsMap::get_map( "{$attr_name}.label" )
		);
	}
}
