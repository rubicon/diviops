<?php
// SPDX-License-Identifier: MIT
/**
 * Plugin-artifact build guard (#229, reworked by #238).
 *
 * The distributable zip for each WordPress plugin is built by
 * `scripts/build-plugin-zip.php` and attached to each published plugin Release by
 * `.github/workflows/release-assets.yaml`. This asserts the builder produces an
 * artifact that matches source: same declared version, same file set.
 *
 * The original form of this guard compared a zip TRACKED IN THE REPOSITORY against
 * source. That was the wrong thing to check, and it blocked every release. Release
 * automation bumps the version in the plugin header and cannot rebuild a binary, so
 * the tracked zip was stale by construction the moment a release PR opened, and the
 * guard failed the very PR that would have made it true. The same defect class as
 * #221: a gate blocking the only process able to satisfy it. The zips are no longer
 * tracked; the builder is what gets verified, and it cannot go stale.
 *
 * Builds into a temporary directory so running the suite never writes into the
 * working tree.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$root = dirname( __DIR__ );

/** Plugins that ship a built zip: slug => source directory. */
$plugins = array(
	'diviops-agent'          => 'plugins/diviops-agent',
	'diviops-design-library' => 'plugins/diviops-design-library',
);

assert_true( class_exists( 'ZipArchive' ), 'ext-zip is available to build and inspect the artifacts' );

/**
 * Read the `Version:` value out of a WordPress plugin header.
 *
 * @param string $src Plugin file contents.
 * @return string Version string, or '' when absent.
 */
function diviops_zip_test_header_version( string $src ): string {
	$semver = '[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.]+)?';
	if ( 1 === preg_match( '/^\s*\*\s*Version:\s*(' . $semver . ')\s*$/m', $src, $m ) ) {
		return $m[1];
	}
	return '';
}

$tmp = sys_get_temp_dir() . '/diviops-zip-test-' . getmypid();
@mkdir( $tmp, 0700, true );
assert_true( is_dir( $tmp ), 'a temporary build directory is available' );

$build = escapeshellarg( $root . '/scripts/build-plugin-zip.php' );
exec( sprintf( 'php %s --out-dir=%s 2>&1', $build, escapeshellarg( $tmp ) ), $build_out, $build_status );
assert_same( 0, $build_status, 'scripts/build-plugin-zip.php exits 0: ' . implode( ' | ', $build_out ) );

$inspected = 0;

foreach ( $plugins as $slug => $src_dir ) {
	$zip_path  = $tmp . '/' . $slug . '.zip';
	$main_file = $root . '/' . $src_dir . '/' . $slug . '.php';

	assert_true( is_file( $zip_path ), sprintf( 'the builder produced %s.zip', $slug ) );
	assert_true( is_file( $main_file ), sprintf( '%s source main file exists', $slug ) );
	if ( ! is_file( $zip_path ) || ! is_file( $main_file ) ) {
		continue;
	}

	$zip = new ZipArchive();
	assert_true( true === $zip->open( $zip_path ), sprintf( '%s.zip opens as a valid archive', $slug ) );
	if ( true !== $zip->open( $zip_path ) ) {
		continue;
	}

	// 1. The version inside the built artifact matches the version in source.
	$packaged = $zip->getFromName( $slug . '/' . $slug . '.php' );
	assert_true(
		is_string( $packaged ) && '' !== $packaged,
		sprintf( '%s.zip contains %s/%s.php', $slug, $slug, $slug )
	);
	if ( is_string( $packaged ) && '' !== $packaged ) {
		$src_version = diviops_zip_test_header_version( (string) file_get_contents( $main_file ) );
		assert_true( '' !== $src_version, sprintf( '%s source declares a parseable Version', $slug ) );
		assert_same(
			$src_version,
			diviops_zip_test_header_version( $packaged ),
			sprintf( '%s.zip declares the same Version as its source', $slug )
		);
	}

	// 2. The artifact carries exactly the files the source directory has.
	$in_zip = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = (string) $zip->getNameIndex( $i );
		if ( '' === $name || '/' === substr( $name, -1 ) ) {
			continue;
		}
		$in_zip[] = preg_replace( '#^' . preg_quote( $slug, '#' ) . '/#', '', $name );
	}
	sort( $in_zip );

	$in_src   = array();
	$base     = $root . '/' . $src_dir;
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$in_src[] = ltrim( str_replace( $base, '', $file->getPathname() ), '/' );
		}
	}
	sort( $in_src );

	assert_true( array() !== $in_src, sprintf( '%s source directory is not empty', $slug ) );
	assert_same(
		$in_src,
		$in_zip,
		sprintf( '%s.zip contains exactly the files in %s', $slug, $src_dir )
	);

	$zip->close();
	unlink( $zip_path );
	++$inspected;
}

@rmdir( $tmp );

// A gate that reports what it inspected but derives pass/fail only from
// problems-found will pass while inspecting nothing. Assert the coverage itself.
assert_same( count( $plugins ), $inspected, 'every plugin that ships a zip was actually built and inspected' );

// The binaries are no longer TRACKED, on purpose (#238). Re-tracking one would
// reintroduce the drift this guard exists for. Checked against git's index rather
// than the filesystem: `release-assets.yaml` legitimately builds these into the
// repository root before uploading them, so mere presence is not the violation.
$tracked = array();
exec( sprintf( 'git -C %s ls-files -- "*.zip" 2>/dev/null', escapeshellarg( $root ) ), $tracked );
foreach ( array_keys( $plugins ) as $slug ) {
	assert_true(
		! in_array( $slug . '.zip', $tracked, true ),
		sprintf( '%s.zip is not tracked in git — it is built and attached to a Release', $slug )
	);
}
