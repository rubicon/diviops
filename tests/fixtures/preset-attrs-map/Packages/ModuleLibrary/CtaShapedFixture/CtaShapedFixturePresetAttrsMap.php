<?php
// SPDX-License-Identifier: MIT
/**
 * Fixture: the hazard `CTAPresetAttrsMap.php` poses, in miniature.
 *
 * Divi's CTA map unsets 151 keys and merges 161 back. The unset ones are paths CTA's
 * own source says are invalid for CTA, and they sit in the file as quoted strings that
 * look exactly like the valid ones. Any extractor that reads quoted strings out of the
 * file publishes all 151 as if they were real.
 *
 * The unset paths and their corrected replacements below are the real ones, copied as
 * identifiers from `ModuleLibrary/Cta/CTAPresetAttrsMap.php` on Divi 5.9.0 so the
 * regression test names the paths that actually caused this. Everything around them is
 * written here.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\ModuleLibrary\CtaShapedFixture;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Unsets deep-nested button paths and merges the shallow forms CTA really uses.
 */
class CtaShapedFixturePresetAttrsMap {

	/**
	 * Get the preset attributes map.
	 *
	 * @param array  $map         The preset attributes map.
	 * @param string $module_name The module name.
	 *
	 * @return array
	 */
	public static function get_map( array $map, string $module_name ) {
		if ( 'divi/fixture-cta-shaped' !== $module_name ) {
			return $map;
		}

		$keys_to_unset = array(
			'button.decoration.font.font__lineHeight',
			'button.decoration.button.innerContent__text',
			'button.decoration.button.decoration.button__icon.enable',
			'button.decoration.button.decoration.background__color',
			'button.decoration.button.decoration.border__radius',
			'button.decoration.button.decoration.font.font__size',
			'button.decoration.button.decoration.sizing__width',
		);

		foreach ( $keys_to_unset as $key ) {
			unset( $map[ $key ] );
		}

		return array_merge(
			$map,
			array(
				'title.innerContent'                    => array(
					'attrName' => 'title.innerContent',
					'preset'   => 'content',
				),
				'button.innerContent__text'             => array(
					'attrName' => 'button.innerContent',
					'preset'   => 'content',
					'subName'  => 'text',
				),
				'button.decoration.sizing__width'       => array(
					'attrName' => 'button.decoration.sizing',
					'preset'   => array( 'style' ),
					'subName'  => 'width',
				),
				'button.decoration.button__icon.enable' => array(
					'attrName' => 'button.decoration.button',
					'preset'   => array( 'style' ),
					'subName'  => 'icon.enable',
				),
			)
		);
	}
}
