<?php
// SPDX-License-Identifier: MIT
/**
 * Version-sync guard (#48).
 *
 * The plugin advertises its version in TWO places: the WordPress plugin header
 * `Version:` line and the `DiviOps_Agent::VERSION` constant. release-please bumps
 * both through its extra-files generic updater — the header via
 * `x-release-please-start-version` / `x-release-please-end` block markers (a block
 * form so no inline comment corrupts WP's header parser), the constant via an
 * inline `// x-release-please-version` marker. If either annotation is removed or
 * mis-placed, release-please would bump only one location and the two would
 * silently drift. This asserts they agree, are valid semver, and still carry both
 * markers — so a broken updater config fails the suite instead of shipping a
 * mismatched version.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );
$semver     = '[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.]+)?';

assert_true(
	1 === preg_match( '/^\s*\*\s*Version:\s*(' . $semver . ')\s*$/m', $plugin_src, $header_match ),
	'the WP plugin header declares a semver Version'
);
assert_true(
	1 === preg_match( '/const\s+VERSION\s*=\s*\'(' . $semver . ')\'/', $plugin_src, $const_match ),
	'DiviOps_Agent::VERSION is a semver string'
);
assert_same(
	$header_match[1],
	$const_match[1],
	'the plugin header Version and the VERSION constant agree — release-please must bump both'
);

// Both release-please annotations must remain, or a future bump silently updates
// only one location — the exact failure this guard exists to prevent.
assert_true(
	false !== strpos( $plugin_src, 'x-release-please-start-version' )
		&& false !== strpos( $plugin_src, 'x-release-please-end' ),
	'the header Version line is wrapped in x-release-please block markers'
);
assert_true(
	1 === preg_match( '/const\s+VERSION\s*=\s*\'' . $semver . '\';\s*\/\/\s*x-release-please-version/', $plugin_src ),
	'the VERSION constant carries the inline x-release-please-version marker'
);

/*
 * THIRD location (#214). `readme.txt`'s `Stable tag` is also a version declaration, and
 * it was the one nothing watched: it sat at 1.5.10 against a 1.16.1 plugin — eleven
 * minors of drift — while the readme's own changelog claimed it "Keeps `Stable tag`
 * aligned with the plugin header version."
 *
 * It drifted precisely because this guard covered two of three locations and
 * release-please's extra-files listed only the PHP file. An unwatched invariant is not
 * an invariant. Asserting the marker too, so a future config change that stops updating
 * the readme fails here rather than rotting quietly for another eleven releases.
 */
$readme_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/readme.txt' );

assert_true(
	1 === preg_match( '/^Stable tag:\s*(' . $semver . ')\s*$/m', $readme_src, $stable_match ),
	'readme.txt declares a semver Stable tag'
);
assert_same(
	$header_match[1],
	$stable_match[1],
	'readme.txt Stable tag agrees with the plugin header Version — release-please must bump all three locations'
);
assert_true(
	false !== strpos( $readme_src, 'x-release-please-start-version' )
		&& false !== strpos( $readme_src, 'x-release-please-end' ),
	'the Stable tag line is wrapped in x-release-please block markers'
);
