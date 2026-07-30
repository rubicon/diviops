<?php
/**
 * Live-WP integration runner (#20) — opt-in, hits a real WordPress site over
 * real HTTP. NEVER wired into tests/run.php and NEVER run in CI (see
 * .github/workflows/test.yaml, which only invokes tests/run.php) — this is a
 * manual, local, deliberate tool, gated on the site credentials in
 * tests-live/README.md being explicitly exported first.
 *
 * Why this exists at all, when tests/run.php already has 900+ assertions:
 * three separate bugs (#28, #36, #35/#97) passed the entire shimmed suite and
 * failed on first contact with real WordPress, because the shim models what
 * this fork assumes about WordPress and Divi, not what they actually do. See
 * #20's "PROMOTED" comment for the postmortem and the standing lesson. This
 * runner is the only thing in the repo that executes against the real thing.
 *
 * Usage:
 *   php tests-live/run.php                Run every live test file
 *   php tests-live/run.php page-duplicate  Run only files matching a substring
 *
 * @package DiviOps
 */

declare( strict_types = 1 );

require_once __DIR__ . '/harness.php';

// Force config validation up front, before any test file runs, so a missing
// credential fails fast with one clear message instead of partway through a
// fixture that then has to be manually cleaned up.
Live_Config::load();

fwrite( STDERR, "\n*** LIVE SUITE: this makes real HTTP calls and real WP-CLI writes against " . getenv( 'DIVIOPS_LIVE_URL' ) . " ***\n\n" );

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

// Same "zero files is a failure, not a green no-op" guard as tests/run.php —
// see that file's header for why this is deliberate.
if ( array() === $files ) {
	if ( '' !== $filter ) {
		printf( "FAIL  no live test file matched the filter '%s' in tests-live/test-*.php%s", $filter, PHP_EOL );
	} else {
		printf( "FAIL  no live test files discovered: tests-live/test-*.php matched nothing%s", PHP_EOL );
	}
	exit( 1 );
}

foreach ( $files as $file ) {
	Live_Test_Runner::$current = basename( $file );
	require $file;
}

$failed = count( Live_Test_Runner::$failures );

echo "\n";
if ( 0 === $failed ) {
	printf( "PASS  %d assertion(s) in %d file(s)%s", Live_Test_Runner::$passed, count( $files ), PHP_EOL );
	exit( 0 );
}

foreach ( Live_Test_Runner::$failures as $failure ) {
	printf( "FAIL  %s%s", $failure, PHP_EOL );
}
printf(
	"%sFAIL  %d passed, %d failed%s",
	PHP_EOL,
	Live_Test_Runner::$passed,
	$failed,
	PHP_EOL
);
exit( 1 );
