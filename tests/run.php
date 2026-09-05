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

/*
 * Child mode (#395): `php tests/run.php --file <path>` runs exactly ONE test file and
 * emits a machine-readable tail the parent parses. The parent below re-invokes this
 * script once per file so each gets its own PHP process.
 *
 * Why a process per file and not a closure: `require` isolates variable scope but not
 * the FUNCTION TABLE. `function_exists()` is global and one-way, and the plugin uses
 * `function_exists( 'et_get_option' )` as its "is Divi active" signal
 * (`diviops-agent.php:755`, `:2621`, `trait-meta.php:1847`). In one process, any file
 * that defines the Divi option accessors flips every later file to Divi-active and
 * destroys the Divi-inactive coverage in test-meta-characterization.php plus the
 * fatal-as-signal probes in the ref-scan and preset characterization files. That made
 * every global colour/font/variable handler untestable — see #380.
 */
if ( isset( $argv[1] ) && '--file' === $argv[1] ) {
	$one = $argv[2] ?? '';
	if ( '' === $one || ! is_file( $one ) ) {
		fwrite( STDERR, "child: --file requires an existing test file\n" );
		exit( 2 );
	}
	DiviOps_Test_Runner::$current = basename( $one );
	( static function ( string $file ): void {
		require $file;
	} )( $one );

	// The parent reads these two lines. Keep them last and keep the format stable.
	printf( "__DIVIOPS_PASSED__ %d%s", DiviOps_Test_Runner::$passed, PHP_EOL );
	foreach ( DiviOps_Test_Runner::$failures as $child_failure ) {
		printf( "__DIVIOPS_FAILURE__ %s%s", str_replace( "\n", '\\n', $child_failure ), PHP_EOL );
	}
	exit( array() === DiviOps_Test_Runner::$failures ? 0 : 1 );
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

/*
 * Each test file is required inside its own closure rather than directly at this
 * top level. A `require` executes in the scope of the code that calls it, so a
 * test file that assigns a top-level variable — `$files`, `$file`, anything — would
 * otherwise land in this scope and could silently overwrite the runner's own state
 * (this is exactly how tests/test-spdx-headers.php once corrupted the "in N
 * file(s)" count below). The closure gives every test file a scope of its own, so
 * that class of collision can't recur even for a test that isn't careful about it.
 */
foreach ( $files as $file ) {
	$command = sprintf(
		'%s %s --file %s 2>&1',
		escapeshellarg( PHP_BINARY ),
		escapeshellarg( __FILE__ ),
		escapeshellarg( $file )
	);

	$output    = array();
	$exit_code = 0;
	exec( $command, $output, $exit_code );

	$saw_count = false;
	foreach ( $output as $line ) {
		if ( 0 === strpos( $line, '__DIVIOPS_PASSED__ ' ) ) {
			DiviOps_Test_Runner::$passed += (int) substr( $line, strlen( '__DIVIOPS_PASSED__ ' ) );
			$saw_count                    = true;
			continue;
		}
		if ( 0 === strpos( $line, '__DIVIOPS_FAILURE__ ' ) ) {
			DiviOps_Test_Runner::$failures[] = str_replace(
				'\\n',
				"\n",
				substr( $line, strlen( '__DIVIOPS_FAILURE__ ' ) )
			);
			continue;
		}
		// Anything else is the child's own output — a PHP fatal, a warning, a test
		// file that echoes. Surface it rather than swallowing it.
		printf( '%s%s', $line, PHP_EOL );
	}

	/*
	 * A child that died before printing its count is a fatal, not a pass. Without
	 * this, a segfaulting or fatal-erroring file would contribute zero assertions and
	 * zero failures — reported as green. A runner that swallows a dead child is
	 * strictly worse than the single-process one it replaced.
	 */
	if ( ! $saw_count ) {
		DiviOps_Test_Runner::$failures[] = sprintf(
			'%s: the test process died without reporting a result (exit %d) — see its output above',
			basename( $file ),
			$exit_code
		);
	}
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
