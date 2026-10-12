<?php
// SPDX-License-Identifier: MIT
/**
 * Every `file:NNNN` cited in FORK.md's divergence rows must resolve (#581).
 *
 * FORK.md's divergence table is the cherry-pick reconciliation aid for upstream
 * syncs, and its rows cite line anchors throughout. Nothing resolved them. On
 * 2026-10-12 four were published stale in a single session, including one
 * invalidated by the very commit that published it: a 30-line change moved
 * `trait-core.php`'s pattern from `:1272` to `:1279` minutes after the anchor was
 * written into a PR body, and the row separately carried `:1251` and `:2517` for
 * lines that had moved. A true claim with a stale anchor reads as an invented one.
 *
 * The existing gates do not cover this and never meant to.
 * `tests/test-fork-divergence-ledger.php` asserts only that FORK.md was TOUCHED in
 * the same change — it never parses a row — and the markdown link gate checks
 * links, not anchors.
 *
 * What this checks is the floor the issue names: in range, and the line is not
 * blank. It deliberately does NOT assert the line still contains the construct the
 * row describes; that is a stronger form worth attempting later, and this floor
 * already catches every 2026-10-12 case.
 *
 * Non-vacuity is asserted, not assumed. A gate that reports what it inspected but
 * derives pass/fail only from problems-found passes while inspecting nothing, which
 * happened three times on the predecessor repository. The fixture half below proves
 * each verdict is reachable, and the live half fails if it resolved zero anchors.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Map every tracked file's basename to its repository paths.
 *
 * @return array<string,array<int,string>>
 */
function diviops_far_basename_map(): array {
	$root  = dirname( __DIR__ );
	$out   = array();
	$files = array();
	exec( 'cd ' . escapeshellarg( $root ) . ' && git ls-files 2>/dev/null', $files );
	foreach ( $files as $path ) {
		$out[ basename( $path ) ][] = $path;
	}

	return $out;
}

/**
 * Resolve the anchors cited in one table row.
 *
 * Two forms, and only the unambiguous ones are checked. This gate reports NO false
 * positives by construction, because a gate that cries wolf gets switched off.
 *
 * 1. An explicit `trait-core.php:1279` naming a file git tracks: always checked.
 * 2. A bare `` `:1279` ``: checked only when the row mentions NO untracked file
 *    before it, and then attributed to the most recent file the row named. These
 *    rows use inheritance — `diviops-agent.php:2389` followed by a bare `:2432`
 *    means the same file — so attribution has to follow the mentions, not the
 *    row's subject.
 *
 * The ambiguity that forces rule 2 is real, not hypothetical, and both directions
 * were observed on this FORK.md. Resolving a bare anchor against the row's subject
 * reported seven false positives, four of which inherit a Divi core file. Resolving
 * it against the most recent mention regardless of trackedness instead SKIPS the
 * `:1279` in the #555 row, because an external parser file is cited between the
 * subject and the anchor.
 *
 * **Stated coverage limit:** this therefore would NOT have caught the stale `:1251`
 * that motivated the gate, since that anchor sits in a row citing WordPress's
 * parser. It does catch every anchor in a row that is wholly about our own tree,
 * which is the majority, and every explicit citation of a tracked file. Narrowing
 * further to avoid false alarms is the deliberate trade; widening it needs a way to
 * disambiguate attribution, not a looser rule.
 *
 * Out of scope and skipped rather than failed: any anchor whose file git does not
 * track. FORK.md cites Divi's and WordPress's source constantly, and has rows about
 * upstream files this fork never adopted, such as `trait-scf.php`.
 *
 * @param string                          $row    One markdown table row.
 * @param array<string,array<int,string>> $bases  Basename map from git ls-files.
 * @param callable                        $reader fn(string $path): ?array of lines.
 * @return array{checked:int,skipped:int,bad:array<int,string>}
 */
function diviops_far_check_row( string $row, array $bases, callable $reader ): array {
	$checked    = 0;
	$skipped    = 0;
	$bad        = array();
	$current    = null;
	$saw_extern = false;

	// An `upstream-releases/` citation is third-party code this repository only
	// archives, so every anchor in the row describes that artifact rather than
	// our tree. Neither the tokenizer nor the basename map can see that on its
	// own: the archive is gitignored, a `.zip` matches no known extension, and
	// an internal `includes/trait-core.php` collides with ours by basename. Left
	// unhandled, upstream's own line numbers resolve against this tree and the
	// row passes on a coincidence (#586).
	if ( false !== strpos( $row, 'upstream-releases/' ) ) {
		$saw_extern = true;
	}

	$file_re  = '[A-Za-z0-9_./-]+\.(?:php|ts|md|json|sh|js|txt)';
	$token_re = '#`(' . $file_re . ')`|(' . $file_re . '):(\d{2,5})|`:(\d{2,5})`#';

	if ( ! preg_match_all( $token_re, $row, $tokens, PREG_SET_ORDER ) ) {
		return array( 'checked' => 0, 'skipped' => 0, 'bad' => array() );
	}

	foreach ( $tokens as $t ) {
		$backticked = $t[1] ?? '';
		$explicit   = $t[2] ?? '';
		$line       = '';
		if ( '' !== $explicit ) {
			$line = $t[3];
		} elseif ( isset( $t[4] ) && '' !== $t[4] ) {
			$line = $t[4];
		}

		$named = '' !== $backticked ? $backticked : $explicit;
		if ( '' !== $named ) {
			$current = $named;
			if ( ! ( $bases[ basename( $named ) ] ?? array() ) ) {
				$saw_extern = true;
			}
		}
		if ( '' === $line || null === $current ) {
			continue;
		}

		$candidates = false !== strpos( (string) $current, 'upstream-releases/' )
			? array()
			: ( $bases[ basename( $current ) ] ?? array() );
		if ( ! $candidates ) {
			$skipped++;
			continue;
		}
		// A bare anchor in a row that has already cited an untracked file cannot be
		// attributed with confidence. Skip rather than risk a false alarm.
		if ( '' === $explicit && $saw_extern ) {
			$skipped++;
			continue;
		}
		$checked++;
		if ( ! diviops_far_any_resolves( $candidates, (int) $line, $reader ) ) {
			$bad[] = $current . ':' . $line;
		}
	}

	return array( 'checked' => $checked, 'skipped' => $skipped, 'bad' => $bad );
}

/**
 * Does the line exist and carry content in any candidate file?
 *
 * @param array<int,string> $paths  Candidate paths.
 * @param int               $line   1-based line number.
 * @param callable          $reader fn(string $path): ?array of lines.
 * @return bool
 */
function diviops_far_any_resolves( array $paths, int $line, callable $reader ): bool {
	foreach ( $paths as $path ) {
		$lines = $reader( $path );
		if ( null === $lines ) {
			continue;
		}
		if ( $line >= 1 && $line <= count( $lines ) && '' !== trim( $lines[ $line - 1 ] ) ) {
			return true;
		}
	}

	return false;
}

/* -------------------------------------------------------------------------
 * 1. Fixture half. Every verdict has to be reachable, or the live half below
 *    could be passing because the checker cannot fail.
 * ---------------------------------------------------------------------- */

$far_fake_bases = array( 'thing.php' => array( 'plugins/thing.php' ) );
$far_fake_file  = array_merge( array( '<?php' ), array_fill( 1, 19, '$a = 1;' ) );
$far_fake_file[13] = '';                                        // 20 lines, line 14 blank
$far_reader     = function ( string $path ) use ( $far_fake_file ) {
	return 'plugins/thing.php' === $path ? $far_fake_file : null;
};

$far = diviops_far_check_row( '| `plugins/thing.php` | names `:12` | #1 |', $far_fake_bases, $far_reader );
assert_same( 1, $far['checked'], 'a bare anchor inherits the backticked path before it and is inspected' );
assert_same( array(), $far['bad'], 'and is accepted when in range and non-blank' );

$far = diviops_far_check_row( '| `plugins/thing.php` | names `:99` | #1 |', $far_fake_bases, $far_reader );
assert_same( 1, count( $far['bad'] ), 'an out-of-range anchor is reported, so a stale one cannot pass' );

$far = diviops_far_check_row( '| `plugins/thing.php` | names `:14` | #1 |', $far_fake_bases, $far_reader );
assert_same( 1, count( $far['bad'] ), 'an in-range but BLANK line is reported too, since a row never describes a blank line' );

$far = diviops_far_check_row( '| `plugins/thing.php` | thing.php:12 and `:99` | #1 |', $far_fake_bases, $far_reader );
assert_same( 2, $far['checked'], 'an explicit file:line is inspected, and the bare anchor after it inherits that file' );
assert_same( array( 'thing.php:99' ), $far['bad'], 'and the inherited bare anchor is attributed to the file it inherited' );

$far = diviops_far_check_row( '| `plugins/thing.php` | GlobalData.php:1281 then `:99` | #1 |', $far_fake_bases, $far_reader );
assert_same( 0, $far['checked'], 'a bare anchor is NOT attributed once the row has cited an untracked file — the ambiguity is real in both directions' );
assert_same( array(), $far['bad'], 'so an ambiguous bare anchor never raises a false alarm' );

$far = diviops_far_check_row( '| `plugins/thing.php` | GlobalData.php:1281 then thing.php:99 | #1 |', $far_fake_bases, $far_reader );
assert_same( 1, $far['checked'], 'but an EXPLICIT citation of a tracked file is always checked, even after an untracked one' );
assert_same( array( 'thing.php:99' ), $far['bad'], 'and is reported when it does not resolve' );

/*
 * An upstream artifact under `upstream-releases/` is extern, both when its
 * extension is not one the tokenizer knows and when its basename collides with a
 * tracked file. Both forms bit #586. The archive is gitignored third-party code,
 * so `upstream-releases/suite/diviops-1.5.85.zip` never tokenizes at all, and an
 * internal `.../includes/trait-core.php` maps by basename straight onto OUR copy.
 * Writing upstream's own line numbers in a row therefore resolved them against
 * this tree: 13 anchors became 15 and the row PASSED on a coincidence, which is
 * the one outcome this gate exists to prevent.
 */
$far = diviops_far_check_row( '| `plugins/thing.php` | read from `upstream-releases/suite/diviops-1.5.85.zip`, names `:12` | #1 |', $far_fake_bases, $far_reader );
assert_same( 0, $far['checked'], 'a bare anchor after an upstream-releases/ citation is not checked, even though the .zip never tokenizes' );
assert_same( 1, $far['skipped'], 'and it is counted as out of scope rather than silently dropped' );

$far = diviops_far_check_row( '| `plugins/thing.php` | upstream-releases/suite/x/plugins/thing.php:12 | #1 |', $far_fake_bases, $far_reader );
assert_same( 0, $far['checked'], 'an explicit upstream-releases/ path is extern too, despite its basename matching a tracked file' );
assert_same( 1, $far['skipped'], 'it is counted as out of scope' );
assert_same( array(), $far['bad'], 'so an upstream line number can never be reported against our tree' );

$far = diviops_far_check_row( '| `plugins/thing.php` | prose with no anchors | #1 |', $far_fake_bases, $far_reader );
assert_same( 0, $far['checked'], 'a row citing no anchors inspects nothing, which is not a failure' );

/* -------------------------------------------------------------------------
 * 2. The live half, against this repository's real FORK.md.
 * ---------------------------------------------------------------------- */

$far_root   = dirname( __DIR__ );
$far_bases  = diviops_far_basename_map();
$far_cache  = array();
$far_live   = function ( string $path ) use ( $far_root, &$far_cache ) {
	if ( ! array_key_exists( $path, $far_cache ) ) {
		$full             = $far_root . '/' . $path;
		$far_cache[ $path ] = is_file( $full ) ? explode( "\n", (string) file_get_contents( $full ) ) : null;
	}
	return $far_cache[ $path ];
};

assert_true( count( $far_bases ) > 100, 'git ls-files found the tracked tree, so the resolver has files to resolve against' );

$far_rows    = array_values( array_filter(
	explode( "\n", (string) file_get_contents( $far_root . '/FORK.md' ) ),
	static function ( string $l ): bool {
		return 0 === strpos( $l, '| `' ) && (bool) preg_match( '/\| #\d+ \|\s*$/', $l );
	}
) );
assert_true( count( $far_rows ) > 10, 'FORK.md still has a divergence table with rows in it' );

$far_total   = 0;
$far_skipped = 0;
$far_bad     = array();
foreach ( $far_rows as $row ) {
	$result       = diviops_far_check_row( $row, $far_bases, $far_live );
	$far_total   += $result['checked'];
	$far_skipped += $result['skipped'];
	foreach ( $result['bad'] as $b ) {
		$far_bad[] = $b;
	}
}

printf(
	"FORK.md anchors: %d resolved, %d out of scope (untracked tree), across %d divergence row(s)%s\n",
	$far_total,
	$far_skipped,
	count( $far_rows ),
	$far_bad ? ', ' . count( $far_bad ) . ' UNRESOLVED' : ''
);

assert_true( $far_total > 0, 'the live half resolved at least one anchor — a run that inspected nothing is not a pass' );
assert_same(
	array(),
	$far_bad,
	'every line anchor cited in a FORK.md divergence row resolves to a real, non-blank line: ' . implode( '; ', array_slice( $far_bad, 0, 6 ) )
);
