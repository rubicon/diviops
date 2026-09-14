<?php
// SPDX-License-Identifier: MIT
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

/*
 * #402: a merged SINGLE release PR builds zero releases, and the fix is config.
 *
 * With both packages sharing one release PR, release-please titles it
 * `chore: release main` — a title carrying no component. On merge it parses the
 * component back as `undefined` and rejects the PR for BOTH configured paths:
 *
 *   PR component: undefined does not match configured component: diviops-agent
 *   PR component: undefined does not match configured component: mcp-server
 *
 * Zero releases are built, no tag is cut, and `autorelease: pending` is left on
 * the merged PR — which aborts every later run before the tag-creating step, so
 * the state cannot clear itself. Eight occurrences, and the ninth (v1.23.2) was
 * PREDICTED in advance from the pattern below and failed exactly as predicted:
 * every release carrying only ONE of the two packages failed, and every release
 * cut alongside the other succeeded.
 *
 * `separate-pull-requests` gives each package its own release PR, whose title
 * therefore carries that package's component and parses back to it.
 *
 * This asserts the setting is present AND strictly boolean true, because
 * release-please reads a JSON `"true"` string as a plain truthy value in some
 * paths and silently as absent in others — a stringly-typed flag here would
 * restore the bug while looking correct.
 */
assert_true(
	array_key_exists( 'separate-pull-requests', $config ),
	'the config sets separate-pull-requests so each package gets its own release PR (#402)'
);

assert_same(
	true,
	isset( $config['separate-pull-requests'] ) ? $config['separate-pull-requests'] : null,
	'separate-pull-requests is boolean true, not a truthy string or number (#402)'
);

/*
 * Separate PRs only disambiguate if the two packages resolve to DIFFERENT
 * component identities. If both resolved to the same one, the per-package PRs
 * would carry the same title and the component would be ambiguous again.
 */
$root_component   = isset( $root['component'] ) ? $root['component'] : $root['package-name'];
$server_component = isset( $server['component'] ) ? $server['component'] : $server['package-name'];

assert_true(
	is_string( $root_component ) && '' !== $root_component,
	'the root package resolves to a non-empty component identity'
);
assert_true(
	$root_component !== $server_component,
	sprintf( 'the two packages resolve to distinct components ("%s" vs "%s")', $root_component, $server_component )
);

/*
 * The fix must not change what the tags are called. `publish.yaml` triggers on
 * `release: published` but guards on the `mcp-server-v` prefix, and branch
 * protection plus the #402 recovery recipe both assume the root tags as plain
 * `vX.Y.Z`. Changing PR grouping must leave both tag shapes alone.
 */
assert_same(
	false,
	isset( $config['include-component-in-tag'] ) ? $config['include-component-in-tag'] : null,
	'the root still tags as vX.Y.Z (include-component-in-tag stays false at top level)'
);
assert_same(
	true,
	isset( $server['include-component-in-tag'] ) ? $server['include-component-in-tag'] : null,
	'the server still tags as mcp-server-vX.Y.Z, which publish.yaml guards on'
);
