<?php
// SPDX-License-Identifier: MIT
/**
 * Anti-rot gate for CONTRIBUTING.md's "Characterization tests" section (#369).
 *
 * The convention that section records was enforced in review and written down
 * nowhere for the life of the project, so it was re-derived once per wave, at a
 * different depth each time. Writing it down only helps if it stays true, and a
 * document is the easiest thing in a repository to leave behind: nothing breaks
 * when it goes stale.
 *
 * The naive gate for a document is `strpos( $src, 'characterization' )`, which
 * matches unrelated prose and reports green against a page that says nothing. So
 * the checks here are of two kinds, and the second kind is the point:
 *
 *   1. Distinctive, qualified phrases — multi-word strings that a stripped or
 *      hollowed-out section could not retain by accident.
 *   2. Cross-checks against the code the section describes. The two assertion
 *      functions are read out of `tests/run.php`, the Reflection helpers out of
 *      `tests/wp-shim.php`, and the defect markers out of the corpus itself, then
 *      each is required to appear in the document. Those directions catch the
 *      rot a phrase match cannot: a third assertion helper added to the runner
 *      and left undocumented, a `diviops_call*` helper renamed, a suite inventing
 *      a seventh marker the table does not define, or a precedent file the
 *      section names being moved out from under it (the failure #115/#116 hit).
 *
 * Every check counts what it inspected and asserts the count is non-zero first,
 * so this gate cannot pass by finding nothing to look at — the vacuous-gate trap
 * `CONTRIBUTING.md` names as having bitten this repository three times.
 *
 * @package DiviOps
 */

$diviops_conv_root    = dirname( __DIR__ );
$diviops_conv_doc     = $diviops_conv_root . '/CONTRIBUTING.md';
$diviops_conv_heading = '## Characterization tests';

assert_true( is_file( $diviops_conv_doc ), 'CONTRIBUTING.md exists where this gate expects it' );

$diviops_conv_src = (string) file_get_contents( $diviops_conv_doc );

/*
 * Isolate the section. Every check below runs against this slice rather than the
 * whole file, so a phrase that happens to appear in the release notes or the
 * fork-posture section cannot stand in for the convention being present.
 */
$diviops_conv_start = strpos( $diviops_conv_src, $diviops_conv_heading );
assert_true( false !== $diviops_conv_start, 'CONTRIBUTING.md carries the "Characterization tests" section' );

$diviops_conv_after   = substr( $diviops_conv_src, (int) $diviops_conv_start + strlen( $diviops_conv_heading ) );
$diviops_conv_end     = preg_match( '/^## /m', $diviops_conv_after, $m, PREG_OFFSET_CAPTURE )
	? (int) $m[0][1]
	: strlen( $diviops_conv_after );
$diviops_conv_section = substr( $diviops_conv_after, 0, $diviops_conv_end );

assert_true(
	strlen( $diviops_conv_section ) > 4000,
	'the "Characterization tests" section still carries substance, not just a heading'
);

$diviops_conv_subheads = preg_match_all( '/^### .+$/m', $diviops_conv_section );
assert_true(
	$diviops_conv_subheads >= 7,
	'the section still breaks the convention into its subsections (file shape, assertions, helpers, markers, expected values, shim contract, mutation, gates)'
);

/**
 * Sort and de-duplicate a list of names so two sets compare by content.
 *
 * @param array<int, string> $names Names.
 * @return array<int, string>
 */
function diviops_conv_set( array $names ): array {
	$names = array_values( array_unique( $names ) );
	sort( $names );
	return $names;
}

/*
 * ── 1. The assertion surface, compared as a set against the runner ───────────
 *
 * `tests/run.php` declares the global assertion functions. If a third one is
 * ever added, the document that tells a contributor "two global functions, and
 * that is the whole surface" becomes a lie the same day.
 *
 * This compares SETS rather than asking whether each runner function is
 * mentioned somewhere in the section — a mention is not documentation of the
 * surface, and the difference is not hypothetical. The first version of this
 * gate did the mention check and a `assert_false()` added to the runner survived
 * it, because the section's own sentence "there is no `assert_false`" contains
 * the string. The documented set is therefore read from the fenced signature
 * block in the assertion-surface subsection, where names appear at the start of
 * a line, and prose about what does NOT exist cannot reach it.
 */
$diviops_conv_surface_start = strpos( $diviops_conv_section, '### The assertion surface' );
assert_true( false !== $diviops_conv_surface_start, 'the section declares its assertion surface in a subsection of its own' );

$diviops_conv_surface_rest = substr( $diviops_conv_section, (int) $diviops_conv_surface_start + 25 );
$diviops_conv_surface      = preg_match( '/^### /m', $diviops_conv_surface_rest, $diviops_conv_sm, PREG_OFFSET_CAPTURE )
	? substr( $diviops_conv_surface_rest, 0, (int) $diviops_conv_sm[0][1] )
	: $diviops_conv_surface_rest;

$diviops_conv_documented_asserts = diviops_conv_set(
	preg_match_all( '/^(assert_[a-z_]+)\s*\(/m', $diviops_conv_surface, $diviops_conv_dm ) ? $diviops_conv_dm[1] : array()
);
assert_true(
	count( $diviops_conv_documented_asserts ) >= 2,
	'the assertion-surface subsection shows the assertion signatures for this gate to read'
);

$diviops_conv_runner = (string) file_get_contents( $diviops_conv_root . '/tests/run.php' );
$diviops_conv_found  = preg_match_all( '/^function\s+(assert_[a-z_]+)\s*\(/m', $diviops_conv_runner, $diviops_conv_asserts );
assert_true( $diviops_conv_found >= 2, 'tests/run.php declares global assertion functions for this gate to inspect' );

assert_same(
	diviops_conv_set( $diviops_conv_asserts[1] ),
	$diviops_conv_documented_asserts,
	'the documented assertion surface is exactly the set of global assertion functions tests/run.php defines'
);

/*
 * ── 2. The Reflection helpers, compared as a set against the shim ────────────
 *
 * Same shape, same reason. `diviops_call`, `diviops_call_ref` and
 * `diviops_call_static` are not interchangeable, and choosing the wrong one does
 * not error — it yields an assertion that passes against an argument the method
 * never mutated. A fourth helper, a rename, or a helper removed from the shim
 * while the table keeps explaining it must all fail here.
 */
$diviops_conv_documented_calls = diviops_conv_set(
	preg_match_all( '/^\| `(diviops_call[a-z_]*)\s*\(/m', $diviops_conv_section, $diviops_conv_cm ) ? $diviops_conv_cm[1] : array()
);
assert_true(
	count( $diviops_conv_documented_calls ) >= 3,
	'the section tabulates the diviops_call* helpers for this gate to read'
);

$diviops_conv_shim  = (string) file_get_contents( $diviops_conv_root . '/tests/wp-shim.php' );
$diviops_conv_calls = preg_match_all( '/function\s+(diviops_call[a-z_]*)\s*\(/', $diviops_conv_shim, $diviops_conv_helpers );
assert_true( $diviops_conv_calls >= 3, 'tests/wp-shim.php declares the diviops_call* Reflection helpers for this gate to inspect' );

assert_same(
	diviops_conv_set( $diviops_conv_helpers[1] ),
	$diviops_conv_documented_calls,
	'the documented helper table is exactly the set of diviops_call* helpers tests/wp-shim.php defines'
);

/*
 * ── 3. The marker vocabulary, both directions ────────────────────────────────
 *
 * The section publishes a table of defect markers. Parse the markers back out of
 * it, then compare against the markers the corpus actually uses. A marker used
 * in a suite but absent from the table is a pin nobody grepping the table will
 * find; a marker in the table that no suite uses is documentation of something
 * that does not exist.
 *
 * The corpus scan looks for the shape the convention prescribes — an all-caps
 * token at the front of an assertion `$message`, so it is visible in failure
 * output. `echo`/`printf` lines are excluded: three suites print their own
 * `PASS:` summary line, which is output, not a marker.
 */
$diviops_conv_table = preg_match_all( '/^\| `([A-Z][A-Z ]*[A-Z])` \|/m', $diviops_conv_section, $diviops_conv_rows );
assert_true( $diviops_conv_table >= 5, 'the marker table defines the vocabulary this gate checks against' );

$diviops_conv_documented = array_map( 'strtoupper', $diviops_conv_rows[1] );

$diviops_conv_used  = array();
$diviops_conv_files = glob( __DIR__ . '/test-*.php' ) ?: array();
assert_true( count( $diviops_conv_files ) > 0, 'there are test files to scan for marker use' );

foreach ( $diviops_conv_files as $diviops_conv_file ) {
	foreach ( explode( "\n", (string) file_get_contents( $diviops_conv_file ) ) as $diviops_conv_line ) {
		if ( false !== strpos( $diviops_conv_line, 'echo ' ) || false !== strpos( $diviops_conv_line, 'printf' ) ) {
			continue;
		}
		if ( preg_match_all( '/[\'"]([A-Z][A-Z ]{2,29}):/', $diviops_conv_line, $diviops_conv_hits ) ) {
			foreach ( $diviops_conv_hits[1] as $diviops_conv_hit ) {
				$diviops_conv_used[ trim( $diviops_conv_hit ) ] = true;
			}
		}
	}
}

/*
 * Non-vacuity for the scan itself. An empty result is only evidence when the
 * pattern could have matched the form the answer takes — if a refactor moved
 * every marker out of the message argument, this gate must fail rather than
 * report that the corpus uses no markers the table is missing.
 */
assert_true(
	count( $diviops_conv_used ) >= 3,
	'the corpus scan found marker-prefixed assertion messages, so an empty undocumented-marker result would be meaningful'
);

$diviops_conv_unknown = array();
foreach ( array_keys( $diviops_conv_used ) as $diviops_conv_marker ) {
	if ( ! in_array( $diviops_conv_marker, $diviops_conv_documented, true ) ) {
		$diviops_conv_unknown[] = $diviops_conv_marker;
	}
}
sort( $diviops_conv_unknown );
assert_same(
	array(),
	$diviops_conv_unknown,
	'every marker the test corpus uses is defined in the marker table in CONTRIBUTING.md'
);

/*
 * ── 4. The files the section names still exist ───────────────────────────────
 *
 * A cross-reference that resolves to nothing looks authoritative and is worse
 * than no cross-reference; #115/#116 were exactly this, a header pointing at a
 * script and a doc that had never been committed. Globs are resolved as globs,
 * so `tests/test-wp-shim-*-contract.php` has to match at least one real file.
 */
$diviops_conv_paths = preg_match_all( '/`(tests\/[A-Za-z0-9_*.\/-]+\.php)`/', $diviops_conv_section, $diviops_conv_named );
assert_true( $diviops_conv_paths > 0, 'the section names test files for this gate to resolve' );

$diviops_conv_broken = array();
foreach ( array_unique( $diviops_conv_named[1] ) as $diviops_conv_path ) {
	$diviops_conv_abs = $diviops_conv_root . '/' . $diviops_conv_path;
	$diviops_conv_ok  = false !== strpos( $diviops_conv_path, '*' )
		? count( glob( $diviops_conv_abs ) ?: array() ) > 0
		: is_file( $diviops_conv_abs );
	if ( ! $diviops_conv_ok ) {
		$diviops_conv_broken[] = $diviops_conv_path;
	}
}
assert_same( array(), $diviops_conv_broken, 'every tests/ path the section cites resolves to a real file' );

/*
 * ── 5. The substance a hollowed-out section would lose ───────────────────────
 *
 * Qualified multi-word strings, not single common words. Each names one rule the
 * convention exists to carry; a rewrite that drops the rule drops the phrase.
 */
$diviops_conv_required = array(
	'A characterization test that passes on broken code' => 'the section still states why a false green certifies the bug rather than merely missing it',
	'no test class'                                     => 'the section still states that the file shape is flat and procedural, with no test class',
	'is not decoration'                                 => 'the section still states that the $message argument is what a failure prints',
	'never from the harness'                            => 'the section still requires expected values to derive from the real system',
	'read off a run'                                    => 'the section still names reading an expected value off a run as the tell',
	'model core faithfully, or raise'                   => 'the section still states that the shim models core faithfully or raises, never approximates',
	'may not widen the shared shim'                     => 'the section still forbids a characterization suite from bending tests/wp-shim.php',
	'A surviving mutant is a fixture hole'              => 'the section still refuses a surviving mutant as an acceptable matrix line',
	'Never `git checkout --`'                           => 'the section still forbids git checkout -- as the way to undo a mutation',
	'shasum'                                            => 'the section still requires verifying that a mutation actually landed',
	'byte-identical to a surviving mutant'              => 'the section still explains why a green run does not prove the mutation landed',
	'a fatal is not a kill'                             => 'the section still distinguishes a mutation killed by an assertion from one that merely fataled',
	'assert the count is non-zero'                      => 'the section still requires a gate to assert it inspected something',
);

/*
 * Two more that live in the `## Testing` section rather than this one, because
 * they govern the whole suite: the runner's filter is positional, and there is
 * no flag form. Invented flags cost round trips the same way an invented test
 * class does, so the document has to say which form is real.
 */
$diviops_conv_doc_wide = array(
	'php tests/run.php seo' => 'the document still shows the runner filter in its positional form',
	'`--filter=`'           => 'the document still says the flag form does not exist',
);

$diviops_conv_absent  = array();
$diviops_conv_checked = 0;
foreach ( $diviops_conv_required as $diviops_conv_needle => $diviops_conv_why ) {
	++$diviops_conv_checked;
	if ( false === strpos( $diviops_conv_section, $diviops_conv_needle ) ) {
		$diviops_conv_absent[] = $diviops_conv_why;
	}
}
foreach ( $diviops_conv_doc_wide as $diviops_conv_needle => $diviops_conv_why ) {
	++$diviops_conv_checked;
	if ( false === strpos( $diviops_conv_src, $diviops_conv_needle ) ) {
		$diviops_conv_absent[] = $diviops_conv_why;
	}
}
assert_same(
	count( $diviops_conv_required ) + count( $diviops_conv_doc_wide ),
	$diviops_conv_checked,
	'every required phrase was actually inspected'
);
assert_true( $diviops_conv_checked >= 14, 'the substance check inspects the full rule set, not a shrunken one' );
assert_same( array(), $diviops_conv_absent, 'the section still carries every rule the convention exists to record' );

/*
 * ── 6. CLAUDE.md points here instead of keeping a second copy ────────────────
 *
 * #369's whole complaint is a convention living in more than one head. Two
 * copies of it in the repository is the same failure with more steps: they
 * drift, and a reader cannot tell which is current.
 */
$diviops_conv_claude = (string) file_get_contents( $diviops_conv_root . '/CLAUDE.md' );
assert_true(
	false !== strpos( $diviops_conv_claude, 'Characterization tests' )
		&& false !== strpos( $diviops_conv_claude, 'CONTRIBUTING.md' ),
	'CLAUDE.md routes to the canonical convention in CONTRIBUTING.md'
);
assert_same(
	0,
	preg_match_all( '/^\| `[A-Z][A-Z ]*[A-Z]` \|/m', $diviops_conv_claude ),
	'CLAUDE.md does not keep a second copy of the marker table that could drift from CONTRIBUTING.md'
);
