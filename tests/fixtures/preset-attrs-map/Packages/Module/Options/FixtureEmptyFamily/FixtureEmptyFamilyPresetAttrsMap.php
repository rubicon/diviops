<?php
// SPDX-License-Identifier: MIT
/**
 * Fixture: a shared family map that returns nothing.
 *
 * No family Divi ships behaves this way. It exists so the resolver's refusal to report
 * an empty vocabulary as a result can be asserted, the same guard tests/run.php applies
 * to its own file discovery.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\Module\Options\FixtureEmptyFamily;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Emits no keys at all.
 */
class FixtureEmptyFamilyPresetAttrsMap {

	/**
	 * Get the map for this family under a given attribute prefix.
	 *
	 * @param string $attr_name The attribute name.
	 *
	 * @return array
	 */
	public static function get_map( string $attr_name ) {
		unset( $attr_name );

		return array();
	}
}
