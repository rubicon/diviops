<?php
/**
 * Release-scope guard (#153).
 *
 * `release-please-config.json` declares two packages: the repository root `.`
 * (the WordPress plugin) and `diviops-server` (the MCP server). Without a path
 * filter the root package parses EVERY commit in the repository, so a change
 * confined to `diviops-server/` bumps the plugin's version too — which is
 * exactly what happened in release `ae5598b`, where a server-only LICENSE fix
 * (`6065787`, two files, both under `diviops-server/`) moved the plugin from
 * 1.14.1 to 1.14.2 despite touching no plugin file.
 *
 * release-please's `exclude-paths` skips a commit for a package when ALL of the
 * commit's files fall under an excluded path — so a commit touching both the
 * plugin and the server still counts for the root, which is the behavior we
 * want. This asserts the filter is present and names a path that really exists,
 * because a typo'd exclusion silently does nothing and would restore the bug
 * without failing anything.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$repo_root   = dirname( __DIR__ );
$config_path = $repo_root . '/release-please-config.json';

assert_true( file_exists( $config_path ), 'release-please-config.json exists at the repository root' );

$config = json_decode( (string) file_get_contents( $config_path ), true );

assert_true( is_array( $config ), 'release-please-config.json is valid JSON' );
assert_true( isset( $config['packages'] ) && is_array( $config['packages'] ), 'the config declares a packages map' );

$packages = $config['packages'];

assert_true( array_key_exists( '.', $packages ), 'the root package "." is declared' );
assert_true( array_key_exists( 'diviops-server', $packages ), 'the "diviops-server" package is declared' );

// The defect: the root package parsed server-only commits because it had no
// path filter at all.
$root = $packages['.'];

assert_true(
	isset( $root['exclude-paths'] ) && is_array( $root['exclude-paths'] ),
	'the root package declares an exclude-paths array so server-only commits do not bump the plugin'
);

$root_excluded = isset( $root['exclude-paths'] ) ? (array) $root['exclude-paths'] : array();

assert_true(
	in_array( 'diviops-server', $root_excluded, true ),
	'the root package excludes "diviops-server" from its commit parsing'
);

// A typo here would be silent — release-please would simply never match the
// path and the plugin would keep bumping. Assert every excluded path is a real
// directory so the guard fails loudly instead of passing vacuously.
foreach ( $root_excluded as $excluded ) {
	assert_true(
		is_string( $excluded ) && '' !== $excluded,
		'each root exclude-paths entry is a non-empty string'
	);
	assert_true(
		is_dir( $repo_root . '/' . $excluded ),
		sprintf( 'root exclude-paths entry "%s" is an existing directory', (string) $excluded )
	);
}

// Every non-root package path must also be real, for the same reason.
foreach ( array_keys( $packages ) as $package_path ) {
	if ( '.' === $package_path ) {
		continue;
	}
	assert_true(
		is_dir( $repo_root . '/' . $package_path ),
		sprintf( 'declared package path "%s" is an existing directory', (string) $package_path )
	);
}

// The subpackage must NOT exclude itself, or it would stop releasing entirely.
$server = $packages['diviops-server'];

assert_true(
	! isset( $server['exclude-paths'] )
		|| ! in_array( 'diviops-server', (array) $server['exclude-paths'], true ),
	'the diviops-server package does not exclude its own path'
);
