<?php
// SPDX-License-Identifier: MIT
/**
 * Regenerate `diviops-server/data/module-map.json` — the batched per-module map.
 *
 * Usage:
 *   php scripts/build-module-map.php <builder5-path> --types <dir>
 *   php scripts/build-module-map.php <builder5-path> --types <dir> --out <file>
 *   php scripts/build-module-map.php <builder5-path> --types <dir> --allow-shrink
 *
 * `<builder5-path>` is Divi's `includes/builder-5` directory, or any `Packages`
 * root with the same layout. `--types` is a directory holding an unpacked
 * `@divi/types` package — see the seam note in `scripts/lib/module-map.php`.
 *
 * Until #384 establishes how this repository obtains `@divi/types`, unpack it by
 * hand outside the repository and point `--types` at the resulting `package/`:
 *
 *   npm pack @divi/types && tar xzf divi-types-*.tgz   # yields ./package/
 *
 * Nothing from that package is committed here. Only its version string is, as a
 * provenance stamp inside the artifact.
 *
 * `scripts/extract-module-preset-paths.php` answers one module and prints text;
 * this batches it across every module Divi declares a preset map for, joins the
 * result to `@divi/types`, and writes one committed artifact.
 *
 * **The shrink gate.** A regeneration that produces less than the artifact it
 * replaces refuses to write. That is the whole point of running it here rather than
 * only in CI: CI has neither Divi nor `@divi/types` installed, so this is the only
 * place the comparison can be made against freshly extracted data. `--allow-shrink`
 * exists for a Divi release that genuinely removes attributes, and prints what it
 * is overriding.
 *
 * Neither Divi's source nor the `@divi/types` package is written to.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/lib/module-map.php';

/**
 * Print usage and exit.
 *
 * @param int $status Exit status.
 */
function diviops_build_module_map_usage( int $status ): void {
	fwrite(
		STDERR,
		"usage: <builder5-path> --types <dir> [--out <file>] [--allow-shrink]\n"
	);
	exit( $status );
}

/**
 * Read Divi's version out of its own theme header.
 *
 * Derived rather than accepted as a flag on purpose: the generated index this
 * artifact sits beside claimed a Divi version nobody re-checked, and a hand-entered
 * provenance stamp is exactly how that happens.
 *
 * @param string $start Path inside the Divi theme.
 *
 * @throws RuntimeException When no theme header with a version is found above it.
 */
function diviops_build_module_map_divi_version( string $start ): string {
	$dir = rtrim( $start, '/' );

	for ( $depth = 0; $depth < 8; $depth++ ) {
		$style = $dir . '/style.css';

		if ( is_file( $style ) ) {
			$header = (string) file_get_contents( $style, false, null, 0, 8192 );

			if ( 1 === preg_match( '/^Version:\s*(\S+)\s*$/m', $header, $found ) ) {
				return $found[1];
			}
		}

		$parent = dirname( $dir );

		if ( $parent === $dir ) {
			break;
		}

		$dir = $parent;
	}

	throw new RuntimeException(
		sprintf( 'no theme style.css with a Version: header found at or above %s', $start )
	);
}

$args = array_slice( $argv, 1 );

if ( array() === $args ) {
	diviops_build_module_map_usage( 2 );
}

$packages_dir = array_shift( $args );
$types_root   = '';
$out          = dirname( __DIR__ ) . '/diviops-server/data/module-map.json';
$allow_shrink = false;

while ( array() !== $args ) {
	$flag = array_shift( $args );

	switch ( $flag ) {
		case '--types':
			$types_root = (string) array_shift( $args );
			break;
		case '--out':
			$out = (string) array_shift( $args );
			break;
		case '--allow-shrink':
			$allow_shrink = true;
			break;
		default:
			fwrite( STDERR, sprintf( "unknown argument: %s\n", $flag ) );
			diviops_build_module_map_usage( 2 );
	}
}

if ( '' === $types_root ) {
	fwrite( STDERR, "--types is required: this artifact is the join of two sources, not one\n" );
	diviops_build_module_map_usage( 2 );
}

try {
	$divi_version = diviops_build_module_map_divi_version( (string) $packages_dir );
	$artifact     = diviops_module_map_build( (string) $packages_dir, $types_root, $divi_version );
} catch ( RuntimeException $error ) {
	fwrite( STDERR, sprintf( "ERROR: %s\n", $error->getMessage() ) );
	exit( 1 );
}

if ( is_file( $out ) ) {
	$previous = json_decode( (string) file_get_contents( $out ), true );

	if ( ! is_array( $previous ) ) {
		fwrite( STDERR, sprintf( "ERROR: %s exists but does not parse as JSON\n", $out ) );
		exit( 1 );
	}

	$reasons = diviops_module_map_shrink_report( $previous, $artifact );

	if ( array() !== $reasons ) {
		fwrite(
			STDERR,
			sprintf(
				"%s: regeneration produced less than the artifact on disk:\n",
				$allow_shrink ? 'WARNING' : 'ERROR'
			)
		);

		foreach ( $reasons as $reason ) {
			fwrite( STDERR, sprintf( "  - %s\n", $reason ) );
		}

		if ( ! $allow_shrink ) {
			fwrite(
				STDERR,
				"refusing to write. Re-run with --allow-shrink only when Divi really did remove these.\n"
			);
			exit( 1 );
		}
	}
}

$encoded = json_encode( $artifact, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

if ( false === $encoded ) {
	fwrite( STDERR, sprintf( "ERROR: cannot encode artifact: %s\n", json_last_error_msg() ) );
	exit( 1 );
}

if ( false === file_put_contents( $out, $encoded . "\n" ) ) {
	fwrite( STDERR, sprintf( "ERROR: cannot write %s\n", $out ) );
	exit( 1 );
}

fwrite(
	STDERR,
	sprintf(
		"wrote %s\n  Divi %s, @divi/types %s\n  %d module(s), %d path(s), %d invalidated key(s), %d disagreement(s)\n"
		. "  %d module(s) with no types file, %d types module(s) with no preset map, %d unserved name(s)\n",
		$out,
		$artifact['sources']['divi']['version'],
		$artifact['sources']['divi_types']['version'],
		$artifact['counts']['modules'],
		$artifact['counts']['paths'],
		$artifact['counts']['invalidates'],
		$artifact['counts']['disagreements'],
		$artifact['counts']['modules_without_types'],
		$artifact['counts']['modules_without_preset_map'],
		$artifact['counts']['unserved']
	)
);
