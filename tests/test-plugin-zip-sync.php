<?php
/**
 * Shipped-artifact sync guard (#229).
 *
 * The repository tracks a built `.zip` for each WordPress plugin, and every install
 * path we document points at it: `README.md`, `SETUP.md`, and the README published
 * to npm. Nothing kept those zips in step with the source they are built from.
 * `diviops-agent.zip` was found carrying plugin 1.5.10 while the source was at
 * 1.16.2, missing three fork-authored traits entirely, because it was an inherited
 * upstream artifact that had never been rebuilt here. A user following our own
 * instructions installed an eleven-minor-versions-old plugin against a current MCP
 * server, and a failed capability gate drops tools silently rather than erroring,
 * so the symptom was missing tools with no message.
 *
 * This asserts the tracked zips match their source: same declared version, same set
 * of files. It also asserts it actually inspected something, because a guard that
 * derives pass or fail only from problems-found will pass while inspecting nothing.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$root = dirname( __DIR__ );

/** Plugins that ship a tracked zip: slug => source directory. */
$plugins = array(
	'diviops-agent'          => 'plugins/diviops-agent',
	'diviops-design-library' => 'plugins/diviops-design-library',
);

assert_true( class_exists( 'ZipArchive' ), 'ext-zip is available to inspect the shipped artifacts' );

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

$inspected = 0;

foreach ( $plugins as $slug => $src_dir ) {
	$zip_path  = $root . '/' . $slug . '.zip';
	$main_file = $root . '/' . $src_dir . '/' . $slug . '.php';

	assert_true( is_file( $zip_path ), sprintf( '%s.zip is tracked at the repository root', $slug ) );
	assert_true( is_file( $main_file ), sprintf( '%s source main file exists', $slug ) );
	if ( ! is_file( $zip_path ) || ! is_file( $main_file ) ) {
		continue;
	}

	$zip = new ZipArchive();
	assert_true( true === $zip->open( $zip_path ), sprintf( '%s.zip opens as a valid archive', $slug ) );
	if ( true !== $zip->open( $zip_path ) ) {
		continue;
	}

	// 1. The version inside the shipped artifact matches the version in source.
	$packaged = $zip->getFromName( $slug . '/' . $slug . '.php' );
	assert_true(
		is_string( $packaged ) && '' !== $packaged,
		sprintf( '%s.zip contains %s/%s.php', $slug, $slug, $slug )
	);
	if ( is_string( $packaged ) && '' !== $packaged ) {
		assert_same(
			diviops_zip_test_header_version( (string) file_get_contents( $main_file ) ),
			diviops_zip_test_header_version( $packaged ),
			sprintf( '%s.zip declares the same Version as its source — the zip is rebuilt on release', $slug )
		);
	}

	// 2. The shipped artifact carries exactly the files the source directory has.
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

	assert_same(
		$in_src,
		$in_zip,
		sprintf( '%s.zip contains exactly the files in %s — no stale or missing file', $slug, $src_dir )
	);

	$zip->close();
	++$inspected;
}

// A gate that reports what it inspected but derives pass/fail only from
// problems-found will pass while inspecting nothing. Assert the coverage itself.
assert_same( count( $plugins ), $inspected, 'every plugin that ships a zip was actually inspected' );
