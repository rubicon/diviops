<?php
// SPDX-License-Identifier: MIT
/**
 * A block of assertions that does not run must say so, and say how many (#416).
 *
 * `tests/test-shared-preset-attrs-map.php` and `tests/test-preset-attrs-map-extractor.php`
 * guard 21 assertions behind `DIVIOPS_DIVI_BUILDER5_PATH`, because they inspect Divi's own
 * source and CI has no Divi install. That guard is correct. What was wrong is that an unset
 * variable produced no output at all, so `PASS 5519 assertion(s) in 94 file(s)` rendered
 * identically whether those 21 ran or not.
 *
 * Those 21 are the only assertions in the suite that a Divi upgrade can break — they pin
 * counts read out of Divi's tree. So through 5.12.0 -> 5.12.1 the suite was structurally
 * incapable of noticing, and reported green the whole way.
 *
 * This is the same failure family as #412, one level down. There, a gate could not reach
 * staging, read that as unreachable, and SKIPped inside a green suite. `CLAUDE.md` states the
 * rule both violate: "a gate that reports what it inspected but derives pass or fail only
 * from problems-found will pass while inspecting nothing."
 *
 * ## What this file gates, and why each half is needed
 *
 * The declared-count check lives in the two files themselves, because only they know their
 * own contents. But a self-check cannot notice its own deletion: remove the
 * `diviops_skip()` call and every assertion in those files still passes, and the suite goes
 * quietly back to hiding 21. So the structural half lives here — a file that gates on the
 * Divi tree MUST report the skip, enforced from outside.
 *
 * The runner half is asserted too. `diviops_skip()` only becomes visible if the child emits
 * the protocol line, the parent parses it, and the summary prints the total; a change that
 * drops any one of those three restores silence without failing anything.
 *
 * Scoped deliberately to `DIVIOPS_DIVI_BUILDER5_PATH` rather than to every `getenv()` in the
 * suite. Other env reads exist for a different purpose — `DIVIOPS_SSH` in
 * test-local-site-drift.php injects a stub so the remote branches CAN be exercised, which is
 * the opposite of skipping — and a gate that flagged those would be noise that teaches people
 * to ignore it.
 *
 * @package DiviOps
 */

$diviops_skip_dir = __DIR__;

/*
 * Every test file that gates work on a real Divi tree must also report the skip.
 * Discovery-based rather than a hardcoded pair, so a third such file is covered the day it
 * is written instead of the day someone remembers this gate exists.
 */
$diviops_skip_gated  = array();
$diviops_skip_files  = glob( $diviops_skip_dir . '/test-*.php' ) ?: array();

assert_true( count( $diviops_skip_files ) > 50, 'test files were discovered — the positive control for the scan below' );

foreach ( $diviops_skip_files as $diviops_skip_file ) {
	$diviops_skip_src = (string) file_get_contents( $diviops_skip_file );

	// This file names the variable in prose; it does not gate on it.
	if ( basename( $diviops_skip_file ) === basename( __FILE__ ) ) {
		continue;
	}
	if ( false === strpos( $diviops_skip_src, "getenv( 'DIVIOPS_DIVI_BUILDER5_PATH' )" ) ) {
		continue;
	}

	$diviops_skip_gated[] = basename( $diviops_skip_file );

	assert_true(
		false !== strpos( $diviops_skip_src, 'diviops_skip(' ),
		basename( $diviops_skip_file ) . ' gates assertions on a Divi tree, so it must report the skip when there is none'
	);
	assert_true(
		false !== strpos( $diviops_skip_src, '} else {' ),
		basename( $diviops_skip_file ) . ' reports that skip from an else branch, so an absent Divi tree cannot fall through silently'
	);
}

// The scan must have found the files it exists to check. Without this, renaming both of them
// turns this gate into one that inspects nothing and reports success — the exact defect the
// file is about.
assert_same(
	2,
	count( $diviops_skip_gated ),
	'both known Divi-tree-gated test files were found and checked: ' . implode( ', ', $diviops_skip_gated )
);

/*
 * The runner's half of the contract. Three links, and a break in any one of them restores
 * silence: the child has to emit the line, the parent has to read it, and the summary has to
 * carry the total. Asserted on run.php's source because the alternative — running the whole
 * suite from inside itself to observe the output — is far worse.
 */
$diviops_skip_runner = (string) file_get_contents( $diviops_skip_dir . '/run.php' );
assert_true( strlen( $diviops_skip_runner ) > 5000, 'run.php loaded — the positive control for the checks below' );

assert_true(
	false !== strpos( $diviops_skip_runner, 'function diviops_skip(' ),
	'run.php defines diviops_skip(), the call a test file uses to declare an omission'
);
assert_true(
	false !== strpos( $diviops_skip_runner, 'printf(' ) && false !== strpos( $diviops_skip_runner, '__DIVIOPS_SKIPPED__ %d %s' ),
	'the child emits the skip on the wire, or the parent process never learns about it'
);
assert_true(
	false !== strpos( $diviops_skip_runner, "'__DIVIOPS_SKIPPED__ '" ),
	'and the parent parses that line rather than printing it as stray child output'
);
assert_true(
	false !== strpos( $diviops_skip_runner, 'assertion(s) SKIPPED in %d file(s)' ),
	'and the run summary carries the skip total, so one line tells the whole truth about a run'
);

/*
 * The summary total must ride on BOTH exit paths. A skip total that appears only on a green
 * run disappears exactly when someone is already distracted by a failure — and a run with
 * failures is when an unnoticed 21 missing assertions matters most.
 */
// Counted as the ARGUMENT form `$skip_summary,` rather than the bare name: the bare name also
// matches its own assignment, which would make this assert 3 and quietly stop meaning "used
// on both paths".
assert_same(
	2,
	substr_count( $diviops_skip_runner, '$skip_summary,' ),
	'the skip summary is passed to both the PASS and the FAIL summary printf calls'
);

/*
 * End to end, by actually running the runner.
 *
 * Everything above is a source scan, and a source scan cannot see whether the value it found
 * is RENDERED. Two mutations proved that: changing the summary's `%s` to `%.0s`, and making
 * the accumulator add zero, both left every static assertion above green while the reported
 * total silently became nothing. The only thing that distinguishes "passed to printf" from
 * "printed" is reading the output.
 *
 * The filter selects the two Divi-tree files and excludes this one, so there is no recursion.
 */
$diviops_skip_cmd = sprintf(
	'%s %s %s 2>&1',
	escapeshellarg( PHP_BINARY ),
	escapeshellarg( $diviops_skip_dir . '/run.php' ),
	escapeshellarg( 'preset-attrs-map' )
);
$diviops_skip_out    = array();
$diviops_skip_status = 0;
exec( $diviops_skip_cmd, $diviops_skip_out, $diviops_skip_status );
$diviops_skip_text = implode( "\n", $diviops_skip_out );

assert_same( 0, $diviops_skip_status, 'the probe run of the two Divi-tree files passes: ' . $diviops_skip_text );
assert_true(
	false !== strpos( $diviops_skip_text, 'SKIP  test-shared-preset-attrs-map.php' ),
	'a skipping file is named on its own SKIP line, beside the file it belongs to'
);
assert_true(
	false !== strpos( $diviops_skip_text, 'DIVIOPS_DIVI_BUILDER5_PATH' ),
	'and the line states the reason in terms a reader can act on, not just that something was skipped'
);
assert_true(
	false !== strpos( $diviops_skip_text, '21 assertion(s) SKIPPED in 2 file(s)' ),
	'and the summary line carries the real total — the check no source scan can make: ' . $diviops_skip_text
);
