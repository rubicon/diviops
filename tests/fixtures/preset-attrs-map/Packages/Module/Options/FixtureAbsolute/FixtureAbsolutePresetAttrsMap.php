<?php
// SPDX-License-Identifier: MIT
/**
 * Fixture: a shared family map that takes no prefix and returns absolute paths.
 *
 * Three of Divi's own family maps are shaped this way, `VisibilitySettingsPresetAttrsMap`
 * among them. They serve exactly one element, so their keys are written out in full.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\Module\Options\FixtureAbsolute;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Emits two absolute subfield paths, with no prefix argument.
 */
class FixtureAbsolutePresetAttrsMap {

	/**
	 * Get the map for this family.
	 *
	 * @return array
	 */
	public static function get_map() {
		return array(
			'module.decoration.fixtureAbsolute__x' => array(
				'attrName' => 'module.decoration.fixtureAbsolute',
				'preset'   => array( 'style' ),
				'subName'  => 'x',
			),
			'module.decoration.fixtureAbsolute__y' => array(
				'attrName' => 'module.decoration.fixtureAbsolute',
				'preset'   => array( 'style' ),
				'subName'  => 'y',
			),
		);
	}
}
