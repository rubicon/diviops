<?php
// SPDX-License-Identifier: MIT
/**
 * The Claude Code plugin version tracks the plugin release (#227).
 *
 * Claude Code caches an installed plugin by version at
 * ~/.claude/plugins/cache/<marketplace>/<plugin>/<version>/. With
 * .claude-plugin/plugin.json pinned at a static version, that directory name never
 * changes and an installed copy never refreshes — no matter how far the marketplace
 * source advances.
 *
 * That is not theoretical. On 2026-08-18 the local cache was still upstream content
 * after the marketplace was repointed to this fork, because both sides read 1.1.0:
 * three reference files (~2,900 lines) had never reached an installed copy at all.
 * It was hand-synced, which is not a fix anyone else installing this marketplace can
 * perform. Two days later it had drifted again — the cache held 1.16.2 while the repo
 * was at 1.17.1, and the three files #248 edited were stale.
 *
 * So the version has to move whenever shipped content moves. release-please now
 * updates plugin.json as part of the "." package release, and this asserts the wiring
 * stays in place. It is the marketplace sibling of tests/test-version-sync.php, which
 * guards the same property inside the WordPress plugin file.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

marketplace_version_sync_run();

/**
 * Wrapped so none of these locals reach tests/run.php's scope.
 *
 * run.php requires each test file inside a closure for exactly this reason, but a
 * file that leaks anyway would still corrupt anything sharing a name — that is how
 * the SPDX gate once corrupted the runner's own file count (#233).
 */
function marketplace_version_sync_run(): void {
	$root = dirname( __DIR__ );

	$plugin_json_path = $root . '/.claude-plugin/plugin.json';
	$manifest_path    = $root . '/.release-please-manifest.json';
	$config_path      = $root . '/release-please-config.json';

	foreach ( array( $plugin_json_path, $manifest_path, $config_path ) as $required ) {
		assert_true(
			is_readable( $required ),
			'required file exists so the assertions below measure something: ' . basename( $required )
		);
	}

	$plugin_json = json_decode( (string) file_get_contents( $plugin_json_path ), true );
	$manifest    = json_decode( (string) file_get_contents( $manifest_path ), true );
	$config      = json_decode( (string) file_get_contents( $config_path ), true );

	assert_true( is_array( $plugin_json ), '.claude-plugin/plugin.json parses as JSON' );
	assert_true( is_array( $manifest ), '.release-please-manifest.json parses as JSON' );
	assert_true( is_array( $config ), 'release-please-config.json parses as JSON' );

	$declared = (string) ( $plugin_json['version'] ?? '' );
	$released = (string) ( $manifest['.'] ?? '' );

	assert_true(
		1 === preg_match( '/^\d+\.\d+\.\d+$/', $declared ),
		'the marketplace plugin declares a plain semver version, found: ' . $declared
	);

	/*
	 * The assertion this file exists for. A mismatch means an installed cache
	 * directory is named after a version that no longer describes what shipped, so
	 * every consumer of this marketplace is reading stale skills.
	 */
	assert_same(
		$released,
		$declared,
		'.claude-plugin/plugin.json version matches the released plugin version — otherwise the Claude Code cache key never changes and installed skills never refresh (#227)'
	);

	/*
	 * The wiring, not just today's values. Without release-please updating this file
	 * the two would agree exactly once and then drift apart at the next release,
	 * which is precisely the failure being fixed.
	 */
	$extra_files = $config['packages']['.']['extra-files'] ?? array();
	assert_true( is_array( $extra_files ) && array() !== $extra_files, 'the "." package declares extra-files' );

	$tracked = null;
	foreach ( $extra_files as $entry ) {
		if ( is_array( $entry ) && '.claude-plugin/plugin.json' === ( $entry['path'] ?? '' ) ) {
			$tracked = $entry;
		}
	}

	assert_true(
		null !== $tracked,
		'release-please tracks .claude-plugin/plugin.json in the "." package extra-files, so the version moves on every release (#227)'
	);
	assert_same(
		'json',
		$tracked['type'] ?? null,
		'it is tracked with the json updater — the generic updater relies on annotation comments, which JSON cannot carry'
	);
	assert_same(
		'$.version',
		$tracked['jsonpath'] ?? null,
		'the json updater targets $.version, the field Claude Code uses as the cache key'
	);
}
