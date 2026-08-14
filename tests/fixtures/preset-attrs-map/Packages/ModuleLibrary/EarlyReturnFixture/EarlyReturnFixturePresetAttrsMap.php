<?php
/**
 * Fixture: a map file that names a module it does not serve.
 *
 * Every real `PresetAttrsMap.php` opens with a guard that returns the base map
 * untouched when the module name does not match. Because the resolver finds a file by
 * looking for the module name in its source, a name quoted somewhere other than the
 * guard is exactly what makes that early return reachable: the file is located, the
 * call succeeds, and nothing happens. The resolver has to notice.
 *
 * @package DiviOps
 */

namespace DiviOps\Fixtures\Packages\ModuleLibrary\EarlyReturnFixture;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access forbidden.' );
}

/**
 * Serves one module name while quoting another it deliberately ignores.
 */
class EarlyReturnFixturePresetAttrsMap {

	/**
	 * Module names this fixture is related to but does not serve.
	 *
	 * @var array
	 */
	const RELATED_MODULES = array( 'divi/fixture-early-return-decoy' );

	/**
	 * Get the preset attributes map.
	 *
	 * @param array  $map         The preset attributes map.
	 * @param string $module_name The module name.
	 *
	 * @return array
	 */
	public static function get_map( array $map, string $module_name ) {
		if ( 'divi/fixture-early-return' !== $module_name ) {
			return $map;
		}

		return array_merge(
			$map,
			array(
				'module.decoration.spacing__margin' => array(
					'attrName' => 'module.decoration.spacing',
					'preset'   => array( 'style' ),
					'subName'  => 'margin',
				),
			)
		);
	}
}
