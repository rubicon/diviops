<?php
// SPDX-License-Identifier: MIT
/**
 * Print the preset-attribute dot-paths one shared decoration family really contributes.
 *
 * Usage:
 *   php scripts/extract-shared-preset-paths.php <builder5-path> --family Button --attr button
 *   php scripts/extract-shared-preset-paths.php <builder5-path> --family Icon --attr module.decoration.icon --json
 *   php scripts/extract-shared-preset-paths.php <builder5-path> --family VisibilitySettings
 *   php scripts/extract-shared-preset-paths.php <builder5-path> --list
 *
 * `<builder5-path>` is Divi's `includes/builder-5` directory, or any `Packages` root with
 * the same layout. `--family` names the map class under `Module/Options/`, without the
 * `PresetAttrsMap` suffix; `--list` prints every family with its class and whether it
 * takes a prefix.
 *
 * `--attr` is the element prefix the family is keyed on, which is how one family serves
 * every element of every module. There is no default: `Icon` takes the icon attribute
 * itself (`module.decoration.icon`), `Font` takes the font attribute and appends its own
 * `.font` segment (`module.decoration.font` yields `module.decoration.font.font__size`),
 * and `Button` takes the bare element (`button`). Guessing one would put a prefix in the
 * output that the caller never asked for, so a missing `--attr` is an error. The three
 * families that take no prefix return absolute paths and reject `--attr` outright.
 *
 * This is the family-level counterpart to `extract-module-preset-paths.php`. Both run
 * `get_map()` rather than reading it, for the same reason in mirrored form: a per-module
 * map removes keys it names, and a family map adds keys it does not name. Divi's
 * `ButtonPresetAttrsMap` spells six of the 149 keys it contributes and delegates the rest
 * to seven sibling families, so a text scan of that file reports a vocabulary missing 143
 * of its own paths. `scripts/extract-decoration-paths.php --shared` is that text scan; it
 * stays correct only for the seven flat families it hardcodes, and this script supersedes
 * it for anything else.
 *
 * Divi source is read-only reference; this script never writes to it.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/lib/preset-attrs-map.php';

/**
 * Print usage and exit.
 *
 * @param int $status Exit status.
 */
function diviops_extract_shared_usage( int $status ): void {
	fwrite(
		STDERR,
		"usage: <builder5-path> --family <Name> [--attr <prefix>] [--json]\n"
		. "   or: <builder5-path> --list\n"
	);
	exit( $status );
}

/**
 * Resolve the `Packages` root from whichever path shape the caller passed.
 *
 * @param string $path Builder-5 root or a `Packages` root.
 */
function diviops_extract_shared_packages_root( string $path ): string {
	$path = rtrim( $path, '/' );

	return is_dir( $path . '/server/Packages' ) ? $path . '/server/Packages' : $path;
}

$args = array_slice( $argv, 1 );

if ( array() === $args ) {
	diviops_extract_shared_usage( 2 );
}

$path      = array_shift( $args );
$family    = '';
$attr_name = '';
$as_json   = false;
$list_only = false;

while ( array() !== $args ) {
	$flag = array_shift( $args );

	switch ( $flag ) {
		case '--family':
			$family = (string) array_shift( $args );
			break;
		case '--attr':
			$attr_name = (string) array_shift( $args );
			break;
		case '--json':
			$as_json = true;
			break;
		case '--list':
			$list_only = true;
			break;
		default:
			fwrite( STDERR, sprintf( "unknown argument: %s\n", $flag ) );
			diviops_extract_shared_usage( 2 );
	}
}

if ( ! $list_only && '' === $family ) {
	diviops_extract_shared_usage( 2 );
}

try {
	$packages = diviops_extract_shared_packages_root( (string) $path );

	if ( $list_only ) {
		$index = diviops_preset_attrs_map_shared_index( $packages );

		foreach ( $index as $name => $file ) {
			printf( "%s\t%s\n", $name, $file );
		}

		fwrite( STDERR, sprintf( "%d shared decoration family map(s)\n", count( $index ) ) );
		exit( 0 );
	}

	$resolved = diviops_preset_attrs_map_shared_resolve( $packages, $family, $attr_name );
} catch ( RuntimeException $error ) {
	fwrite( STDERR, sprintf( "ERROR: %s\n", $error->getMessage() ) );
	exit( 1 );
}

if ( $as_json ) {
	echo json_encode( $resolved, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ), "\n";
} else {
	printf( "# family: %s\n", $resolved['family'] );
	printf( "# class:  %s\n", $resolved['class'] );
	printf( "# file:   %s\n", $resolved['file'] );
	printf(
		"# attr:   %s\n",
		$resolved['parameterless'] ? '(none: this family returns absolute paths)' : $resolved['attr']
	);

	foreach ( $resolved['keys'] as $key ) {
		echo $key, "\n";
	}
}

fwrite(
	STDERR,
	sprintf(
		"resolved %d preset attr key(s) for family %s\n",
		count( $resolved['keys'] ),
		$resolved['family']
	)
);
