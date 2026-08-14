<?php
/**
 * Print the preset-attribute dot-paths a single Divi module really declares.
 *
 * Usage:
 *   php scripts/extract-module-preset-paths.php <builder5-path> --module divi/cta
 *   php scripts/extract-module-preset-paths.php <builder5-path> --module divi/cta --base base-keys.txt
 *   php scripts/extract-module-preset-paths.php <builder5-path> --module divi/cta --json
 *   php scripts/extract-module-preset-paths.php <builder5-path> --list
 *
 * `<builder5-path>` is Divi's `includes/builder-5` directory, or any `Packages` root
 * with the same layout. `--base` reads one base-map key per line; blank lines and lines
 * starting with `#` are ignored. Without it the output is what the module contributes
 * on top of whatever base it is handed.
 *
 * Bare paths on stdout are the module's resolved keys. Comment lines carry the rest:
 * `# removed:` for base keys the map dropped, `# invalidates:` for keys it is proven to
 * strip whether or not the base held them, plus a header naming the class and file.
 * Everything is sorted, so output diffs cleanly between Divi versions.
 *
 * This is the merge-aware counterpart to `extract-decoration-paths.php`, which answers
 * a different question: that script prints the shared `module.decoration.*` vocabulary
 * of the seven families `advanced-attributes.md` documents, by scanning source text.
 * A per-module map cannot be read that way, because it removes keys as well as adding
 * them. See `scripts/lib/preset-attrs-map.php` for how the removals are resolved.
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
function diviops_extract_usage( int $status ): void {
	fwrite(
		STDERR,
		"usage: <builder5-path> --module <name> [--base <file>] [--json]\n"
		. "   or: <builder5-path> --list\n"
	);
	exit( $status );
}

/**
 * Resolve the `Packages` root from whichever path shape the caller passed.
 *
 * @param string $path Builder-5 root or a `Packages` root.
 */
function diviops_extract_packages_root( string $path ): string {
	$path = rtrim( $path, '/' );

	return is_dir( $path . '/server/Packages' ) ? $path . '/server/Packages' : $path;
}

/**
 * Read base-map keys from a file, one per line.
 *
 * @param string $file Path to the key list.
 *
 * @throws RuntimeException When the file cannot be read or holds no keys.
 *
 * @return array<string, bool>
 */
function diviops_extract_base_map( string $file ): array {
	$lines = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

	if ( false === $lines ) {
		throw new RuntimeException( sprintf( 'cannot read base key file: %s', $file ) );
	}

	$base = array();

	foreach ( $lines as $line ) {
		$key = trim( $line );

		if ( '' === $key || 0 === strpos( $key, '#' ) ) {
			continue;
		}

		$base[ $key ] = true;
	}

	if ( array() === $base ) {
		throw new RuntimeException( sprintf( 'base key file holds no keys: %s', $file ) );
	}

	return $base;
}

$args = array_slice( $argv, 1 );

if ( array() === $args ) {
	diviops_extract_usage( 2 );
}

$path        = array_shift( $args );
$module_name = '';
$base_file   = '';
$as_json     = false;
$list_only   = false;

while ( array() !== $args ) {
	$flag = array_shift( $args );

	switch ( $flag ) {
		case '--module':
			$module_name = (string) array_shift( $args );
			break;
		case '--base':
			$base_file = (string) array_shift( $args );
			break;
		case '--json':
			$as_json = true;
			break;
		case '--list':
			$list_only = true;
			break;
		default:
			fwrite( STDERR, sprintf( "unknown argument: %s\n", $flag ) );
			diviops_extract_usage( 2 );
	}
}

if ( ! $list_only && '' === $module_name ) {
	diviops_extract_usage( 2 );
}

try {
	$packages = diviops_extract_packages_root( (string) $path );

	if ( $list_only ) {
		$index = diviops_preset_attrs_map_index( $packages );

		foreach ( $index as $name => $file ) {
			printf( "%s\t%s\n", $name, $file );
		}

		fwrite( STDERR, sprintf( "%d module name(s) declared by per-module preset maps\n", count( $index ) ) );
		exit( 0 );
	}

	$base     = '' === $base_file ? array() : diviops_extract_base_map( $base_file );
	$resolved = diviops_preset_attrs_map_resolve( $packages, $module_name, $base );
} catch ( RuntimeException $error ) {
	fwrite( STDERR, sprintf( "ERROR: %s\n", $error->getMessage() ) );
	exit( 1 );
}

if ( $as_json ) {
	echo json_encode( $resolved, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ), "\n";
} else {
	printf( "# module: %s\n", $resolved['module'] );
	printf( "# class:  %s\n", $resolved['class'] );
	printf( "# file:   %s\n", $resolved['file'] );
	printf(
		"# base %d key(s), final %d key(s), %d added, %d removed\n",
		count( $resolved['base'] ),
		count( $resolved['final'] ),
		count( $resolved['added'] ),
		count( $resolved['removed'] )
	);

	if ( $resolved['inert'] ) {
		echo "# inert: this map serves the module and returns the map it is handed unchanged\n";
	}

	if ( $resolved['wipes_base'] ) {
		echo "# wipes base: this map discards every key it is handed\n";
	}

	foreach ( $resolved['final'] as $key ) {
		echo $key, "\n";
	}

	foreach ( $resolved['removed'] as $key ) {
		printf( "# removed: %s\n", $key );
	}

	foreach ( $resolved['invalidates'] as $key ) {
		printf( "# invalidates: %s\n", $key );
	}
}

fwrite(
	STDERR,
	sprintf(
		"resolved %d preset attr key(s) for %s (base %d, added %d, removed %d)\n",
		count( $resolved['final'] ),
		$resolved['module'],
		count( $resolved['base'] ),
		count( $resolved['added'] ),
		count( $resolved['removed'] )
	)
);
