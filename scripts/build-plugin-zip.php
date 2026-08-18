<?php
/**
 * Build the distributable zip for each WordPress plugin (#229).
 *
 * Every install path this project documents points at a tracked `<slug>.zip` at the
 * repository root. Those zips were inherited from upstream and never rebuilt here,
 * so `diviops-agent.zip` shipped plugin 1.5.10 against source at 1.16.2 and was
 * missing three fork-authored traits. Keeping a built binary in step with source by
 * hand is the arrangement that produced that, so this builds it from source and
 * `tests/test-plugin-zip-sync.php` fails the suite when the two drift.
 *
 * Usage:
 *   php scripts/build-plugin-zip.php              Build every plugin zip
 *   php scripts/build-plugin-zip.php diviops-agent  Build one
 *
 * Entries are added in sorted order so a rebuild from unchanged source produces a
 * stable listing rather than filesystem-iteration order.
 *
 * @package DiviOps
 */

declare( strict_types = 1 );

$root    = dirname( __DIR__ );
$plugins = array( 'diviops-agent', 'diviops-design-library' );

$only = isset( $argv[1] ) ? (string) $argv[1] : '';
if ( '' !== $only ) {
	if ( ! in_array( $only, $plugins, true ) ) {
		fwrite( STDERR, sprintf( "unknown plugin: %s\n", $only ) );
		exit( 1 );
	}
	$plugins = array( $only );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "ext-zip is required to build plugin zips\n" );
	exit( 1 );
}

$built = 0;

foreach ( $plugins as $slug ) {
	$src = $root . '/plugins/' . $slug;
	if ( ! is_dir( $src ) ) {
		fwrite( STDERR, sprintf( "missing source directory: %s\n", $src ) );
		exit( 1 );
	}

	$files    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$files[] = ltrim( str_replace( $src, '', $file->getPathname() ), '/' );
		}
	}
	sort( $files );

	if ( array() === $files ) {
		fwrite( STDERR, sprintf( "refusing to build an empty zip for %s\n", $slug ) );
		exit( 1 );
	}

	$out = $root . '/' . $slug . '.zip';
	if ( is_file( $out ) && ! unlink( $out ) ) {
		fwrite( STDERR, sprintf( "could not remove the previous %s.zip\n", $slug ) );
		exit( 1 );
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $out, ZipArchive::CREATE ) ) {
		fwrite( STDERR, sprintf( "could not create %s\n", $out ) );
		exit( 1 );
	}
	$zip->addEmptyDir( $slug );
	foreach ( $files as $rel ) {
		$zip->addFile( $src . '/' . $rel, $slug . '/' . $rel );
	}
	$zip->close();

	printf( "built %s.zip (%d files)\n", $slug, count( $files ) );
	++$built;
}

printf( "%d plugin zip(s) built\n", $built );
