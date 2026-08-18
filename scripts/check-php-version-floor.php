<?php
// SPDX-License-Identifier: MIT
/**
 * Fail when plugins/ calls a PHP builtin function newer than the plugin's own
 * declared `Requires PHP` floor. See scripts/lib/php-version-floor-check.php for
 * why this exists and what it deliberately does not cover, and #180 for the bug
 * that prompted it.
 *
 * This same check also runs as a self-check assertion inside
 * tests/test-php-version-floor-check.php, which is what actually gates CI (it
 * runs in the existing "Test suite" jobs on every PR). This script is the
 * standalone, human-facing form of the same detector -- useful for an ad hoc run
 * against a path, or from a local pre-commit hook, without going through the full
 * test suite.
 *
 * Usage:
 *   php scripts/check-php-version-floor.php
 *
 * @package DiviOps
 */

require_once __DIR__ . '/lib/php-version-floor-check.php';

$plugin_root  = dirname( __DIR__ ) . '/plugins';
$plugin_mains = array(
	$plugin_root . '/diviops-agent/diviops-agent.php',
	$plugin_root . '/diviops-design-library/diviops-design-library.php',
);

$floor = null;

foreach ( $plugin_mains as $main_file ) {
	if ( ! is_file( $main_file ) ) {
		fwrite( STDERR, "ERROR: expected plugin main file not found: {$main_file}\n" );
		exit( 1 );
	}

	$declared = diviops_parse_requires_php( (string) file_get_contents( $main_file ) );

	if ( null === $declared ) {
		fwrite( STDERR, "ERROR: no 'Requires PHP:' header found in {$main_file}\n" );
		exit( 1 );
	}

	// Take the most restrictive (lowest) floor across every plugin this repo ships.
	if ( null === $floor || version_compare( $declared, $floor, '<' ) ) {
		$floor = $declared;
	}
}

$forbidden = array();
foreach ( diviops_php8_only_functions() as $name => $introduced_in ) {
	if ( version_compare( $introduced_in, $floor, '>' ) ) {
		$forbidden[ $name ] = $introduced_in;
	}
}

$files = diviops_find_php_files( $plugin_root );

// A scan that finds nothing must fail rather than report a green pass of zero
// files inspected -- the same discipline tests/run.php applies to its own file
// discovery.
if ( array() === $files ) {
	fwrite( STDERR, "ERROR: found no PHP files under plugins/ -- this is a blind gate, not a pass.\n" );
	exit( 1 );
}

$violations = array();
foreach ( $files as $file ) {
	foreach ( diviops_scan_source_for_forbidden_calls( (string) file_get_contents( $file ), $forbidden ) as $hit ) {
		$violations[] = array_merge( $hit, array( 'file' => $file ) );
	}
}

printf(
	"scanned %d PHP file(s) under plugins/ against Requires PHP: %s (checking %d PHP 8+ function(s))\n",
	count( $files ),
	$floor,
	count( $forbidden )
);

if ( array() !== $violations ) {
	foreach ( $violations as $violation ) {
		printf(
			"%s:%d: %s() was added in PHP %s, but the plugin declares Requires PHP: %s\n",
			$violation['file'],
			$violation['line'],
			$violation['function'],
			$violation['introduced_in'],
			$floor
		);
	}
	printf( "FAIL  %d call(s) to a PHP builtin newer than the declared floor\n", count( $violations ) );
	exit( 1 );
}

printf( "PASS  no PHP builtin call newer than Requires PHP: %s found under plugins/\n", $floor );
exit( 0 );
