<?php
// SPDX-License-Identifier: MIT
/**
 * Plain-PHP test runner.
 *
 * Fork-owned. Upstream ships no tests and no CI, so this is the entire safety net
 * for anything this fork changes. Runs without WordPress, Composer, or PHPUnit.
 *
 * Usage:
 *   php tests/run.php                Run every test file
 *   php tests/run.php drop-in        Run only files matching a substring
 *
 * Test files live in tests/ as test-*.php and call the assertion helpers below. A
 * failing assertion records and continues, so one run reports every failure rather
 * than only the first.
 *
 * @package DiviOps
 */

declare( strict_types = 1 );

final class DiviOps_Test_Runner {

	/** @var int */
	public static $passed = 0;

	/** @var array<int, string> */
	public static $failures = array();

	/** @var string */
	public static $current = '';

	/**
	 * Assert strict equality.
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  What this assertion proves.
	 */
	public static function assert_same( $expected, $actual, string $message ): void {
		if ( $expected === $actual ) {
			++self::$passed;
			return;
		}
		self::$failures[] = sprintf(
			"%s: %s\n    expected: %s\n    actual:   %s",
			self::$current,
			$message,
			self::render( $expected ),
			self::render( $actual )
		);
	}

	/**
	 * Assert a value is boolean true.
	 *
	 * @param mixed  $actual  Actual value.
	 * @param string $message What this assertion proves.
	 */
	public static function assert_true( $actual, string $message ): void {
		self::assert_same( true, $actual, $message );
	}

	/**
	 * Render a value for a failure message.
	 *
	 * @param mixed $value Value to render.
	 */
	private static function render( $value ): string {
		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return false === $encoded ? gettype( $value ) : $encoded;
	}
}

/**
 * Assert strict equality.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  What this assertion proves.
 */
function assert_same( $expected, $actual, string $message ): void {
	DiviOps_Test_Runner::assert_same( $expected, $actual, $message );
}

/**
 * Assert a value is boolean true.
 *
 * @param mixed  $actual  Actual value.
 * @param string $message What this assertion proves.
 */
function assert_true( $actual, string $message ): void {
	DiviOps_Test_Runner::assert_true( $actual, $message );
}

$filter = $argv[1] ?? '';
$files  = glob( __DIR__ . '/test-*.php' ) ?: array();

if ( '' !== $filter ) {
	$files = array_values(
		array_filter(
			$files,
			static function ( string $file ) use ( $filter ): bool {
				return false !== strpos( basename( $file ), $filter );
			}
		)
	);
}

/*
 * A suite that discovered nothing must fail, not report a green PASS of zero
 * assertions. Zero files means the glob is wrong, a test was renamed, or a filter
 * matched nothing. Each of those is a broken suite masquerading as a passing one,
 * which is worse than a visible failure. Say which case it is.
 */
if ( array() === $files ) {
	if ( '' !== $filter ) {
		printf( "FAIL  no test file matched the filter '%s' in tests/test-*.php%s", $filter, PHP_EOL );
	} else {
		printf( "FAIL  no test files discovered: tests/test-*.php matched nothing%s", PHP_EOL );
	}
	exit( 1 );
}

foreach ( $files as $file ) {
	DiviOps_Test_Runner::$current = basename( $file );
	require $file;
}

$failed = count( DiviOps_Test_Runner::$failures );

echo "\n";
if ( 0 === $failed ) {
	printf( "PASS  %d assertion(s) in %d file(s)%s", DiviOps_Test_Runner::$passed, count( $files ), PHP_EOL );
	exit( 0 );
}

foreach ( DiviOps_Test_Runner::$failures as $failure ) {
	printf( "FAIL  %s%s", $failure, PHP_EOL );
}
printf(
	"%sFAIL  %d passed, %d failed%s",
	PHP_EOL,
	DiviOps_Test_Runner::$passed,
	$failed,
	PHP_EOL
);
exit( 1 );
