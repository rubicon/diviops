<?php
// SPDX-License-Identifier: MIT
/**
 * The skill must not quote tool descriptions verbatim (#515).
 *
 * `skills/divi-5-builder/references/tools.md` and `diviops-server/src/index.ts`
 * describe the same tools to two different readers. Where the skill says
 * something the description does not — a gotcha, what to do about a refusal —
 * that is the skill earning its length. Where it QUOTES the description, the
 * duplication can rot: reword the description and the skill silently keeps a
 * copy of text that no longer exists, and an agent reads the skill.
 *
 * Not hypothetical. It fired twice inside one batch of PRs on 2026-09-27:
 * #509 quoted media_list's "title search term" while #511 was rewriting that
 * exact string, and the fix for that re-introduced a different verbatim phrase
 * from the same file.
 *
 * THE THRESHOLD WAS CHOSEN BEFORE THE RESULT WAS LOOKED AT, and that ordering
 * is the whole discipline here. 50 characters: at 40 the matches include
 * mid-sentence fragments straddling clause boundaries and coincidental shared
 * vocabulary, while 50 characters of exact prose is long enough that a match is
 * copied text rather than chance. Tuning this number after seeing what it flags
 * would produce a gate that reports PASS while inspecting nothing, which is the
 * failure this repository has hit three times and which `tests/run.php` fails on
 * empty discovery to prevent. If a future change needs the threshold moved, move
 * it for a stated reason about prose, never to make the current file pass.
 *
 * Measured when written: 35 runs across 24 lines at 50 characters. All but the
 * allow-listed one were rewritten to describe behaviour rather than echo wording.
 *
 * Covered here:
 *
 *   - Both files were actually read, asserted before anything is concluded.
 *   - The scanner detects a run it is KNOWN to be able to find — the allow-listed
 *     entry doubles as the positive control, so this gate cannot pass while
 *     inspecting nothing.
 *   - The scanner does NOT match a string absent from one side.
 *   - Code fences and inline code spans are stripped first. They are quotations
 *     by design — a parameter name or an error code has exactly one correct
 *     spelling and must match. Only prose is scanned.
 *   - No run of 50+ characters of prose appears in both files, except the
 *     allow-list.
 *   - The allow-list itself is asserted non-empty and every entry is asserted
 *     still present in both files, so a stale waiver cannot accumulate silently.
 *
 * NOT covered: the reverse direction. A description that quotes the skill is the
 * same defect seen from the other side, but the description is the thing an agent
 * reads first and the skill is the copy, so the skill is where the rot lands.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Runs of prose, `$min` characters or longer, present verbatim in both strings.
 *
 * @param string $needle_src Text to scan (markdown, already code-stripped).
 * @param string $haystack   Text to scan against (normalized).
 * @param int    $min        Minimum run length in characters.
 * @return array<int, array<int, string>> Line number => runs found on it.
 */
function diviops_sdd_shared_runs( string $needle_src, string $haystack, int $min ): array {
	$found = array();
	$lines = explode( "\n", $needle_src );
	foreach ( $lines as $index => $line ) {
		$s   = (string) preg_replace( '/\s+/', ' ', $line );
		$len = strlen( $s );
		$i   = 0;
		while ( $i + $min <= $len ) {
			if ( false !== strpos( $haystack, substr( $s, $i, $min ) ) ) {
				$j = $i + $min;
				while ( $j < $len && false !== strpos( $haystack, substr( $s, $i, $j - $i + 1 ) ) ) {
					++$j;
				}
				$found[ $index + 1 ][] = substr( $s, $i, $j - $i );
				$i                     = max( $i + 1, $j - $min + 1 );
			} else {
				++$i;
			}
		}
	}
	return $found;
}

/**
 * Is this shared run explained ENTIRELY by allow-listed identifiers?
 *
 * Excise every allow-listed substring and ask whether any contiguous remainder
 * still reaches the threshold. Substring-matching the whole run instead would
 * waive prose that merely happens to mention an id, which is why this is a
 * function with its own assertions below rather than an inline condition: on any
 * given day the file may contain no such prose, and then the two rules are
 * indistinguishable and the looser one looks correct.
 *
 * @param string   $run     The shared run.
 * @param string[] $allowed Allow-listed identifiers.
 * @param int      $min     Threshold in characters.
 * @return bool
 */
function diviops_sdd_is_waived( string $run, array $allowed, int $min ): bool {
	$residue = str_replace( $allowed, "\n", $run );
	$longest = 0;
	foreach ( explode( "\n", $residue ) as $piece ) {
		$longest = max( $longest, strlen( $piece ) );
	}
	return $longest < $min;
}

/**
 * Strip fenced blocks and inline code spans, preserving line count.
 *
 * @param string $markdown Markdown source.
 * @return string
 */
function diviops_sdd_strip_code( string $markdown ): string {
	$blanked = (string) preg_replace_callback(
		'/```.*?```/s',
		function ( $m ) {
			return (string) preg_replace( '/[^\n]/', ' ', $m[0] );
		},
		$markdown
	);
	return (string) preg_replace_callback(
		'/`[^`\n]*`/',
		function ( $m ) {
			return str_repeat( ' ', strlen( $m[0] ) );
		},
		$blanked
	);
}

$sdd_min = 50;

/*
 * Runs permitted in both files. Every entry needs a reason that is about the
 * CONTENT, never about convenience: an allow-list that grows unexamined is the
 * rot this gate exists to prevent, wearing a different hat.
 *
 * The five customizer-bound colour ids are a fact, not prose. They exist on every
 * Divi install, an agent needs the list in the skill to know which ids it must not
 * treat as ordinary, and a caller needs it in the description for the same reason.
 * There is one correct spelling of each and no way to say it differently without
 * saying it wrongly. Rewording either side would be a defect, not a fix.
 */
$sdd_allowed = array(
	'gcid-primary-color',
	'gcid-secondary-color',
	'gcid-heading-color',
	'gcid-body-color',
	'gcid-link-color',
);

$sdd_md_path = __DIR__ . '/../skills/divi-5-builder/references/tools.md';
$sdd_ts_path = __DIR__ . '/../diviops-server/src/index.ts';
$sdd_md_raw  = (string) file_get_contents( $sdd_md_path );
$sdd_ts_raw  = (string) file_get_contents( $sdd_ts_path );

assert_true( strlen( $sdd_md_raw ) > 1000, '#515: tools.md was read, so a zero-finding below means no duplication rather than no input' );
assert_true( strlen( $sdd_ts_raw ) > 1000, '#515: index.ts was read, for the same reason' );

$sdd_ts = (string) preg_replace(
	'/\s+/',
	' ',
	str_replace( array( '\\n', "\\'", '\\"' ), array( ' ', "'", '"' ), $sdd_ts_raw )
);
$sdd_md = diviops_sdd_strip_code( $sdd_md_raw );

assert_same(
	substr_count( $sdd_md_raw, "\n" ),
	substr_count( $sdd_md, "\n" ),
	'#515: stripping code preserves the line count, so a reported line number points at the real line'
);

/* The allow-list doubles as the positive control. */

assert_true( count( $sdd_allowed ) > 0, '#515: the allow-list is non-empty, which is what makes the control below meaningful' );
foreach ( $sdd_allowed as $sdd_entry ) {
	assert_true(
		false !== strpos( $sdd_ts, $sdd_entry ),
		'#515: allow-listed run is still present in index.ts — a waiver for text that moved is a stale waiver: ' . substr( $sdd_entry, 0, 40 )
	);
	assert_true(
		false !== strpos( (string) preg_replace( '/\s+/', ' ', $sdd_md ), $sdd_entry ),
		'#515: and still present in tools.md, so the entry is earning its place: ' . substr( $sdd_entry, 0, 40 )
	);
	assert_true(
		1 === preg_match( '/^gcid-[a-z-]+$/D', $sdd_entry ),
		'#515: every waiver is a bare identifier, never a phrase — a phrase waiver would excuse prose: ' . $sdd_entry
	);
}

/*
 * Composed control. The five ids written as a list exceed the threshold on their
 * own, so the scanner is known to have something to find here. This asserts both
 * halves at once: the run IS detected, and the waiver fully explains it.
 */
$sdd_control_list = implode( ', ', $sdd_allowed );
assert_true(
	strlen( $sdd_control_list ) > $sdd_min,
	'#515: the five ids written as a list are longer than the threshold, so they are a real control and not a trivially-passing one'
);
assert_true(
	false !== strpos( $sdd_ts, $sdd_control_list ),
	'#515: and that list is present verbatim in index.ts, which is what makes the control load-bearing'
);

/*
 * The waiver rule, asserted on synthetic runs.
 *
 * A mutation that waives any run merely CONTAINING an allow-listed id survived
 * the first mutation matrix, because no prose in tools.md happens to mention one
 * today. That made the loose rule and the correct rule indistinguishable from the
 * live file alone, which is a hole in the fixture rather than an acceptable
 * result. These two assertions are the fixture.
 */
assert_true(
	diviops_sdd_is_waived( 'gcid-primary-color, gcid-secondary-color, gcid-heading-color', $sdd_allowed, $sdd_min ),
	'#515: a run made only of allow-listed ids and separators is waived'
);
assert_true(
	! diviops_sdd_is_waived(
		'gcid-primary-color is mentioned here and then this sentence continues for well past the threshold with ordinary prose',
		$sdd_allowed,
		$sdd_min
	),
	'#515: but a run that merely MENTIONS an allow-listed id while carrying threshold-length prose is NOT waived — waiving it would let any sentence buy immunity by naming one id'
);
assert_true(
	! diviops_sdd_is_waived( str_repeat( 'x', $sdd_min ), $sdd_allowed, $sdd_min ),
	'#515: and a run with no allow-listed text at all is never waived (control: the function can return false)'
);

/* Negative control: the scanner must not match text absent from one side. */

$sdd_absent = diviops_sdd_shared_runs(
	'zzz this sentence appears in neither of the two files being compared zzz',
	$sdd_ts,
	$sdd_min
);
assert_same( array(), $sdd_absent, '#515: the scanner reports nothing for a line absent from index.ts, so a clean result is a real result' );

/* The gate. */

$sdd_hits      = diviops_sdd_shared_runs( $sdd_md, $sdd_ts, $sdd_min );
$sdd_offending = array();
$sdd_scanned   = 0;
foreach ( $sdd_hits as $sdd_line => $sdd_runs ) {
	foreach ( $sdd_runs as $sdd_run ) {
		++$sdd_scanned;
		$sdd_waived = diviops_sdd_is_waived( $sdd_run, $sdd_allowed, $sdd_min );
		if ( ! $sdd_waived ) {
			$sdd_offending[] = "L{$sdd_line}: " . substr( $sdd_run, 0, 90 );
		}
	}
}

assert_true(
	$sdd_scanned > 0,
	'#515: the scan found at least one shared run overall — if this is zero the scanner has stopped working, because the allow-listed colour list is known to be in both files'
);

assert_same(
	array(),
	$sdd_offending,
	"#515: no run of {$sdd_min}+ characters of PROSE is shared between tools.md and index.ts outside the allow-list. Where the skill restates a description it rots the moment that description is reworded, and an agent reads the skill. Rewrite the skill line to describe the behaviour instead of echoing the wording"
);

echo 'skill description duplication: scanned ' . count( $sdd_hits ) . " line(s) with shared runs, {$sdd_scanned} run(s), "
	. count( $sdd_allowed ) . " allow-listed, threshold {$sdd_min}\n";
