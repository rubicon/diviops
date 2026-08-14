<?php
/**
 * Merge-aware resolution of a Divi module's preset-attributes map.
 *
 * Divi registers one `<Module>PresetAttrsMap::get_map( array $map, string $module_name )`
 * per module on the `divi_conversion_presets_attrs_map` filter. That method both adds
 * and removes keys: it unsets a list of paths from the base map it is handed, then
 * merges its own additions on top. `CTAPresetAttrsMap.php` unsets 151 keys and merges
 * 161 back, and the unset ones are paths CTA's own source says are invalid for CTA.
 *
 * Reading quoted strings out of such a file therefore produces invalid paths, and the
 * order matters as much as the membership: unset runs before merge, so a key in both
 * lists survives while a key in the unset list alone does not.
 *
 * Rather than model any of that, this resolver runs `get_map()` and reads the result.
 * That is sound because these classes do nothing else. A token scan of all 111
 * `PresetAttrsMap.php` files Divi 5.9.0 ships (65 under `ModuleLibrary/`, 46 under
 * `Module/Options/`) found their entire vocabulary to be `array_merge`, `array_filter`,
 * `in_array`, `substr`, `defined`, and static calls to sibling `*PresetAttrsMap`
 * classes. No WordPress function, no I/O, no global state.
 *
 * Divi source is read-only reference here; nothing in this file writes to it.
 *
 * @package DiviOps
 */

/**
 * Resolve a `Packages` directory to a canonical, existing path.
 *
 * @param string $packages_dir Path to a `Packages` root, Divi's or a fixture's.
 *
 * @throws RuntimeException When the directory does not exist.
 */
function diviops_preset_attrs_map_packages_root( string $packages_dir ): string {
	$root = realpath( $packages_dir );

	if ( false === $root || ! is_dir( $root ) ) {
		throw new RuntimeException( sprintf( 'not a directory: %s', $packages_dir ) );
	}

	return $root;
}

/**
 * Make a `Packages` root loadable: define ABSPATH and register its autoloader.
 *
 * Every `PresetAttrsMap.php` opens with an `ABSPATH` guard that kills the process
 * outright when the constant is missing, and module maps call sibling family maps that
 * have to be loadable by class name.
 *
 * The autoloader prefers Divi's own Composer classmap, which is the authoritative
 * class-to-file mapping for that tree and sidesteps the directory-casing question
 * entirely (`ModuleLibrary/Cta/` holds `CTAPresetAttrsMap.php`). Fixtures ship no
 * classmap, so it falls back to the namespace convention both trees follow: everything
 * after the `Packages` segment is the path under the root. Either way the resolved file
 * must sit inside this root, so autoloading can never reach outside the tree it was
 * pointed at.
 *
 * @param string $packages_root Canonical `Packages` root.
 */
function diviops_preset_attrs_map_bootstrap( string $packages_root ): void {
	static $registered = array();

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', $packages_root . DIRECTORY_SEPARATOR );
	}

	if ( isset( $registered[ $packages_root ] ) ) {
		return;
	}

	$registered[ $packages_root ] = true;

	$classmap_file = dirname( $packages_root ) . '/vendor/composer/autoload_classmap.php';
	$classmap      = is_file( $classmap_file ) ? (array) require $classmap_file : array();

	spl_autoload_register(
		static function ( $class ) use ( $packages_root, $classmap ): void {
			if ( isset( $classmap[ $class ] ) ) {
				$candidate = $classmap[ $class ];
			} else {
				$offset = strpos( $class, '\\Packages\\' );

				if ( false === $offset ) {
					return;
				}

				$relative  = substr( $class, $offset + strlen( '\\Packages\\' ) );
				$candidate = $packages_root . '/' . str_replace( '\\', '/', $relative ) . '.php';
			}

			$file = realpath( $candidate );

			// Another root's class, or something outside the tree we were pointed at.
			if ( false === $file || 0 !== strpos( $file, $packages_root . DIRECTORY_SEPARATOR ) ) {
				return;
			}

			require_once $file;
		}
	);
}

/**
 * Index every module name declared by a map file under a `Packages` root.
 *
 * A map file names the modules it serves as quoted `namespace/slug` strings in its own
 * guard. Some serve two: `FullwidthPostContentPresetAttrsMap` guards on both
 * `divi/fullwidth-post-content` and `divi/post-content`. Indexing every such string in
 * the file rather than parsing the guard keeps discovery independent of whether the
 * guard is written as a comparison or an `in_array()`, at the cost of occasionally
 * indexing a name the file mentions but does not serve. That case is caught at
 * resolution, where the early return is visible.
 *
 * @param string $packages_dir Path to a `Packages` root.
 *
 * @throws RuntimeException When the root holds no map files at all.
 *
 * @return array<string, string> Module name to absolute map-file path.
 */
function diviops_preset_attrs_map_index( string $packages_dir ): array {
	$root  = diviops_preset_attrs_map_packages_root( $packages_dir );
	$files = glob( $root . '/ModuleLibrary/*/*PresetAttrsMap.php' ) ?: array();

	/*
	 * A scan that inspected nothing must fail rather than return an empty index. An
	 * empty result here means the root is wrong or Divi moved the files, and either
	 * reads as "this module has no paths" to every caller downstream.
	 */
	if ( array() === $files ) {
		throw new RuntimeException(
			sprintf( 'no PresetAttrsMap files found under %s/ModuleLibrary', $root )
		);
	}

	$index = array();

	foreach ( $files as $file ) {
		$source = (string) file_get_contents( $file );

		if ( ! preg_match_all( '/[\'"]([a-z][a-z0-9-]*\/[a-z0-9-]+)[\'"]/', $source, $matches ) ) {
			continue;
		}

		foreach ( array_unique( $matches[1] ) as $module_name ) {
			if ( isset( $index[ $module_name ] ) && $index[ $module_name ] !== $file ) {
				throw new RuntimeException(
					sprintf(
						'module %s is declared by two map files: %s and %s',
						$module_name,
						$index[ $module_name ],
						$file
					)
				);
			}

			$index[ $module_name ] = $file;
		}
	}

	ksort( $index );

	return $index;
}

/**
 * Index every shared decoration family declared under a `Packages` root.
 *
 * Families live at `Module/Options/<Group>/<Class>PresetAttrsMap.php` and are named here
 * after the class rather than the directory, because `Module/Options/FormField/` holds
 * two unrelated maps (`FieldDecoration` and `FormField`) and a directory key would drop
 * one of them without saying so.
 *
 * @param string $packages_dir Path to a `Packages` root.
 *
 * @throws RuntimeException When the root holds no family maps at all.
 *
 * @return array<string, string> Family name to absolute map-file path.
 */
function diviops_preset_attrs_map_shared_index( string $packages_dir ): array {
	$root  = diviops_preset_attrs_map_packages_root( $packages_dir );
	$files = glob( $root . '/Module/Options/*/*PresetAttrsMap.php' ) ?: array();

	/*
	 * A scan that inspected nothing must fail rather than return an empty index, the same
	 * rule the per-module index follows.
	 */
	if ( array() === $files ) {
		throw new RuntimeException(
			sprintf( 'no shared PresetAttrsMap files found under %s/Module/Options', $root )
		);
	}

	$index = array();

	foreach ( $files as $file ) {
		$family = (string) preg_replace( '/PresetAttrsMap\.php$/', '', basename( $file ) );

		if ( isset( $index[ $family ] ) && $index[ $family ] !== $file ) {
			throw new RuntimeException(
				sprintf(
					'family %s is declared by two map files: %s and %s',
					$family,
					$index[ $family ],
					$file
				)
			);
		}

		$index[ $family ] = $file;
	}

	ksort( $index );

	return $index;
}

/**
 * Resolve one shared decoration family's full key vocabulary.
 *
 * The family is run, not read. `ButtonPresetAttrsMap` spells six of its 149 keys and
 * delegates the rest to seven sibling maps, so the delegated paths exist nowhere in its
 * own source and a text scan reports a vocabulary that is missing most of itself.
 *
 * Most families take the element prefix they are keyed on as their one argument, which
 * is how a single family serves every element of every module. Three take none and
 * return absolute paths instead; passing a prefix to one of those is refused rather than
 * ignored, so a caller never gets back keys their prefix had no part in.
 *
 * @param string $packages_dir Path to a `Packages` root.
 * @param string $family       Family name, for example `Button`.
 * @param string $attr_name    Element prefix to key the family on. Empty for the
 *                             parameterless families.
 *
 * @throws RuntimeException When no map file declares the family, when the prefix does
 *                          not match the map's arity, or when the map resolves to
 *                          nothing.
 *
 * @return array{family: string, class: string, file: string, attr: string,
 *               parameterless: bool, keys: array<int, string>}
 */
function diviops_preset_attrs_map_shared_resolve( string $packages_dir, string $family, string $attr_name = '' ): array {
	$root  = diviops_preset_attrs_map_packages_root( $packages_dir );
	$index = diviops_preset_attrs_map_shared_index( $root );

	if ( ! isset( $index[ $family ] ) ) {
		throw new RuntimeException(
			sprintf( 'no shared PresetAttrsMap under %s declares family %s', $root, $family )
		);
	}

	$file  = $index[ $family ];
	$class = diviops_preset_attrs_map_class_name( $file );

	diviops_preset_attrs_map_bootstrap( $root );
	require_once $file;

	if ( ! method_exists( $class, 'get_map' ) ) {
		throw new RuntimeException( sprintf( '%s has no get_map() method', $class ) );
	}

	$parameterless = 0 === ( new ReflectionMethod( $class, 'get_map' ) )->getNumberOfParameters();

	if ( $parameterless && '' !== $attr_name ) {
		throw new RuntimeException(
			sprintf( '%s takes no attribute prefix: its keys are absolute paths', $class )
		);
	}

	if ( ! $parameterless && '' === $attr_name ) {
		throw new RuntimeException(
			sprintf( '%s requires an attribute prefix to key its subfields on', $class )
		);
	}

	$resolved = $parameterless ? $class::get_map() : $class::get_map( $attr_name );

	if ( ! is_array( $resolved ) ) {
		throw new RuntimeException( sprintf( '%s::get_map() did not return an array', $class ) );
	}

	$keys = array_keys( $resolved );
	sort( $keys );

	/*
	 * Every family Divi ships contributes at least one key. An empty return means the
	 * class was not exercised the way it expects, which must not read as "this family has
	 * no vocabulary".
	 */
	if ( array() === $keys ) {
		throw new RuntimeException( sprintf( '%s::get_map() resolved to zero keys', $class ) );
	}

	return array(
		'family'        => $family,
		'class'         => $class,
		'file'          => $file,
		'attr'          => $attr_name,
		'parameterless' => $parameterless,
		'keys'          => $keys,
	);
}

/**
 * Read the fully qualified class name a map file declares.
 *
 * @param string $file Absolute path to a map file.
 *
 * @throws RuntimeException When the file declares no class.
 */
function diviops_preset_attrs_map_class_name( string $file ): string {
	$tokens    = token_get_all( (string) file_get_contents( $file ) );
	$namespace = '';
	$class     = '';
	$count     = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) ) {
			continue;
		}

		if ( T_NAMESPACE === $tokens[ $i ][0] ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					continue;
				}

				if ( is_array( $tokens[ $j ] ) ) {
					$namespace .= $tokens[ $j ][1];
					continue;
				}

				break;
			}

			continue;
		}

		if ( T_CLASS === $tokens[ $i ][0] ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
					$class = $tokens[ $j ][1];
					break;
				}
			}

			break;
		}
	}

	if ( '' === $class ) {
		throw new RuntimeException( sprintf( 'no class declared in %s', $file ) );
	}

	return '' === $namespace ? $class : trim( $namespace, '\\' ) . '\\' . $class;
}

/**
 * Collect every path-shaped quoted string in a map file.
 *
 * These are candidates, not results. They are fed to `get_map()` as a probe base so the
 * keys it strips can be observed being stripped; nothing is reported on the strength of
 * having appeared in the source. The set is deliberately over-broad, since it also
 * picks up `attrName` and `subName` values, which costs nothing: a candidate the map
 * does not touch is simply never mentioned again.
 *
 * Candidate generation bounds completeness, not correctness. A key a map unsets is
 * always quoted in the file that unsets it, so the probe covers the removals; a removal
 * it somehow missed would be absent from the report rather than wrong in it.
 *
 * @param string $file Absolute path to a map file.
 *
 * @return array<int, string>
 */
function diviops_preset_attrs_map_source_paths( string $file ): array {
	$source = (string) file_get_contents( $file );

	if ( ! preg_match_all( '/[\'"]([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+(?:__[A-Za-z0-9_.]+)?)[\'"]/', $source, $matches ) ) {
		return array();
	}

	return array_values( array_unique( $matches[1] ) );
}

/**
 * Read the module names a map file's own guard tests against.
 *
 * Used only to settle the one case behaviour cannot: five of Divi 5.9.0's per-module
 * maps match their module name and then return the map they were handed, so serving the
 * module and merely naming it produce identical output for every input. Reading the
 * guard says which it is.
 *
 * Scoped to the condition of the first `if` inside `get_map()`, which is where every
 * one of these files puts its early return, so a module name quoted anywhere else in
 * the file is not mistaken for one the map serves. Both guard spellings Divi uses are
 * covered: a direct `!==` comparison and `! in_array( $module_name, [ … ], true )`.
 *
 * @param string $file Absolute path to a map file.
 *
 * @return array<int, string>
 */
function diviops_preset_attrs_map_guard_names( string $file ): array {
	$tokens = token_get_all( (string) file_get_contents( $file ) );
	$count  = count( $tokens );
	$names  = array();

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
			continue;
		}

		$is_get_map = false;

		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				continue;
			}

			$is_get_map = is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] && 'get_map' === $tokens[ $j ][1];
			break;
		}

		if ( ! $is_get_map ) {
			continue;
		}

		// Walk to the first `if` in the body, then capture its parenthesised condition.
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( ! is_array( $tokens[ $j ] ) || T_IF !== $tokens[ $j ][0] ) {
				continue;
			}

			$depth = 0;

			for ( $k = $j + 1; $k < $count; $k++ ) {
				if ( '(' === $tokens[ $k ] ) {
					$depth++;
					continue;
				}

				if ( ')' === $tokens[ $k ] ) {
					$depth--;

					if ( 0 === $depth ) {
						break 3;
					}

					continue;
				}

				if ( $depth > 0 && is_array( $tokens[ $k ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $k ][0] ) {
					$names[] = trim( $tokens[ $k ][1], "'\"" );
				}
			}

			break;
		}
	}

	return array_values( array_unique( $names ) );
}

/**
 * Run a map twice, once for the module asked about and once for a name nothing serves.
 *
 * This is how the early return at the top of every `get_map()` is caught. The resolver
 * finds a map file by looking for the module name in its source, so a name quoted
 * anywhere other than the guard reaches a method that returns the base map untouched,
 * and reporting that base back would be publishing an answer nobody computed.
 *
 * Comparing against a deliberately unserved name is decisive where comparing against
 * the base alone is not: `divi/code` only unsets keys, and `divi/social-media-follow-network`
 * returns an empty array, so both look like "nothing happened" when the base is empty.
 * Against the same probe base, a map that serves the name produces a different array
 * (different keys, or the same keys carrying real attribute definitions instead of the
 * probe's placeholder) and a map that does not produces the probe base verbatim.
 *
 * @param string $class       Map class name.
 * @param string $module_name Module name to test.
 * @param array  $probe_base  Probe base map.
 *
 * @return array{served: bool, result: array}
 */
function diviops_preset_attrs_map_probe( string $class, string $module_name, array $probe_base ): array {
	$served_result   = $class::get_map( $probe_base, $module_name );
	$unserved_result = $class::get_map( $probe_base, 'diviops-probe/no-such-module' );

	return array(
		'served' => $served_result !== $unserved_result,
		'result' => is_array( $served_result ) ? $served_result : array(),
	);
}

/**
 * Resolve a module's final preset-attribute key set.
 *
 * The base map is what Divi's own conversion layer has already generated for the module
 * by the time the per-module filter runs; supply its keys to see what the map removes.
 * With no base, `final` is what the module contributes on top of whatever it is handed,
 * and `invalidates` is what it takes away, which for some modules is everything it
 * does.
 *
 * @param string               $packages_dir Path to a `Packages` root.
 * @param string               $module_name  Module name, for example `divi/cta`.
 * @param array<string, mixed> $base_map     Base map; only its keys are read.
 *
 * @throws RuntimeException When no map file serves the module, or when the file that
 *                          names it does not act on it.
 *
 * @return array{module: string, class: string, file: string, base: array<int, string>,
 *               final: array<int, string>, added: array<int, string>,
 *               removed: array<int, string>, invalidates: array<int, string>,
 *               wipes_base: bool, inert: bool}
 */
function diviops_preset_attrs_map_resolve( string $packages_dir, string $module_name, array $base_map = array() ): array {
	$root  = diviops_preset_attrs_map_packages_root( $packages_dir );
	$index = diviops_preset_attrs_map_index( $root );

	if ( ! isset( $index[ $module_name ] ) ) {
		throw new RuntimeException(
			sprintf( 'no PresetAttrsMap under %s declares module %s', $root, $module_name )
		);
	}

	$file  = $index[ $module_name ];
	$class = diviops_preset_attrs_map_class_name( $file );

	diviops_preset_attrs_map_bootstrap( $root );
	require_once $file;

	if ( ! method_exists( $class, 'get_map' ) ) {
		throw new RuntimeException( sprintf( '%s has no get_map() method', $class ) );
	}

	/*
	 * The sentinel keeps the probe base non-empty for a map whose file holds no path
	 * strings at all, and is how a map that discards everything it is handed shows up as
	 * having done something.
	 */
	$sentinel   = 'diviops.probe__sentinel';
	$probe_base = array_merge(
		array( $sentinel => true ),
		array_fill_keys( diviops_preset_attrs_map_source_paths( $file ), true )
	);

	$probe = diviops_preset_attrs_map_probe( $class, $module_name, $probe_base );
	$inert = false;

	if ( ! $probe['served'] ) {
		if ( ! in_array( $module_name, diviops_preset_attrs_map_guard_names( $file ), true ) ) {
			throw new RuntimeException(
				sprintf(
					'%s names %s but does not serve it: get_map() returns the map it is handed for that module name, exactly as it does for one no file serves.',
					$file,
					$module_name
				)
			);
		}

		$inert = true;
	}

	$probe_keys  = array_keys( $probe['result'] );
	$wipes_base  = ! $inert && ! in_array( $sentinel, $probe_keys, true );
	$invalidates = $inert
		? array()
		: array_values( array_diff( array_keys( $probe_base ), $probe_keys, array( $sentinel ) ) );
	sort( $invalidates );

	$resolved = $class::get_map( $base_map, $module_name );

	if ( ! is_array( $resolved ) ) {
		throw new RuntimeException( sprintf( '%s::get_map() did not return an array', $class ) );
	}

	$base_keys  = array_keys( $base_map );
	$final_keys = array_keys( $resolved );

	sort( $base_keys );
	sort( $final_keys );

	$added   = array_values( array_diff( $final_keys, $base_keys ) );
	$removed = array_values( array_diff( $base_keys, $final_keys ) );

	sort( $added );
	sort( $removed );

	return array(
		'module'      => $module_name,
		'class'       => $class,
		'file'        => $file,
		'base'        => $base_keys,
		'final'       => $final_keys,
		'added'       => $added,
		'removed'     => $removed,
		'invalidates' => $invalidates,
		'wipes_base'  => $wipes_base,
		'inert'       => $inert,
	);
}
