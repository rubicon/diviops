<?php
// SPDX-License-Identifier: MIT
/**
 * Server-local scratch artifact containment guard (#544).
 *
 * Two MCP artifact stores resolve their root against `process.cwd()`, which is
 * this repository's root whenever the server is launched from a checkout:
 * `diviops-server/src/page-export-ref.ts:99-104` writes
 * `<cwd>/.diviops-tmp/page-exports`, and
 * `diviops-server/src/cross-env-preflight/source-payload-ref.ts:34-39` writes
 * `<cwd>/.diviops-tmp/cross-env-source-payloads`. Both defaults are deliberate --
 * the handle + checksum + TTL design refuses a caller-supplied destination on
 * purpose -- so the artifacts belong on disk and the containment has to happen in
 * git rather than in the stores.
 *
 * A page export is Divi's portability schema, which inlines every image on the
 * page as base64. It is a verbatim copy of a real page from a development site.
 * `rubicon/diviops` is a public repository, so one of these reaching a commit
 * publishes a client's page content under our name. That is the same failure
 * family as #506, where a maintainer session log reached two pushed branches of
 * this repository before an adversarial review caught it.
 *
 * This file was written because `.diviops-tmp/` was untracked AND unignored on
 * `main`, one `git add -A` away from exactly that. It asserts the two halves that
 * have to hold together:
 *
 *   1. The ignore rule EXISTS AND MATCHES, asked of `git check-ignore` rather
 *      than by grepping `.gitignore` for a string. A text-presence assertion
 *      passes on a pattern that matches nothing, which is the bug being fixed.
 *   2. Nothing artifact-shaped is tracked, asked of git's own index rather than
 *      the filesystem -- the artifacts are supposed to be on disk. The question
 *      is what a push would publish, and `.gitignore` is one `git add -f` away
 *      from being irrelevant with nothing to report it. The sibling gate
 *      `tests/test-upstream-releases-untracked.php` draws the same distinction.
 *
 * Deliberately NOT covered: the cross-env store's own filenames. Its
 * `HANDLE_PATTERN` (`source-payload-ref.ts:28`) is
 * `/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/` -- no prefix -- so a payload file is
 * indistinguishable by name from any other JSON in the tree, and a name rule for
 * it would either miss everything or flag 20 legitimate files. The directory
 * segment is the only thing that store can be caught by here. The page-export
 * store is luckier: its handles carry a `pe-` prefix
 * (`page-export-ref.ts:97`), which is load-bearing for its own prune and is
 * reused here to catch an artifact copied out of the store to somewhere else in
 * the tree.
 *
 * Also NOT covered: `<WP_PATH>/.diviops-tmp`, the WP-CLI safe root at
 * `diviops-server/src/wp-cli-fs-validator.ts:30`. That one lives on the
 * WordPress install, not in this repository. The unanchored ignore pattern would
 * still hold if an install were ever placed inside the tree, but nothing here
 * depends on that.
 *
 * How the negatives were measured, with the controls that make them evidence
 * rather than a regex that could not match:
 *
 *   - `git ls-files | grep -cE '(^|/)pe-[A-Za-z0-9][A-Za-z0-9._-]*\.json$'` is 0,
 *     against a control relaxing only the `pe-` prefix away, which is 20.
 *   - `git ls-files | grep -c '\.diviops-tmp'` is 0, against a control counting
 *     `diviops-server` segments, which is 88.
 *   - `git check-ignore -v --no-index` resolves `worktrees/x/y.txt` to
 *     `.gitignore:4:worktrees/` and refuses `FORK.md`, so the probe neither
 *     matches everything nor nothing. Both controls are asserted below rather
 *     than left in this comment.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$root = dirname( __DIR__ );

/**
 * Ask git whether a pathname is excluded, and by which rule.
 *
 * Returns the `-v` rule half (`<source>:<linenum>:<pattern>`) when git excludes
 * the path, and '' when it does not. The source half is asserted, not just the
 * verdict: a rule living in `.git/info/exclude` would satisfy a yes/no check
 * while leaving every other clone of this repository unprotected.
 *
 * `--no-index` is deliberate. Without it a tracked path reports as not-excluded
 * even when a pattern matches it, so the index would mask the very rule this is
 * measuring. Whether anything is tracked is a separate question, asked
 * separately below.
 *
 * @param string $root Repository root.
 * @param string $path Pathname to test, repository-relative. Need not exist.
 * @return string Rule that excluded the path, or '' if none did.
 */
function diviops_sau_check_ignore( string $root, string $path ): string {
	$command = sprintf(
		'git -C %s check-ignore -v --no-index -- %s 2>/dev/null',
		escapeshellarg( $root ),
		escapeshellarg( $path )
	);

	$lines = array();
	$code  = 1;
	exec( $command, $lines, $code );

	if ( 0 !== $code || empty( $lines ) ) {
		return '';
	}

	// `<source>:<linenum>:<pattern>\t<pathname>` -- keep the rule, drop the path.
	$parts = explode( "\t", (string) $lines[0], 2 );

	return $parts[0];
}

/**
 * List every path git tracks in the repository.
 *
 * @param string $root Repository root.
 * @return array<int,string> Tracked paths, repository-relative.
 */
function diviops_sau_tracked_paths( string $root ): array {
	$command = sprintf( 'git -C %s ls-files -z', escapeshellarg( $root ) );

	$output = shell_exec( $command );
	if ( ! is_string( $output ) || '' === $output ) {
		return array();
	}

	return array_values( array_filter( explode( "\0", $output ), 'strlen' ) );
}

assert_true(
	is_dir( $root . '/.git' ) || is_file( $root . '/.git' ),
	'the suite is running inside a git checkout, so git can be asked what is tracked and what is excluded'
);

/*
 * Non-vacuity for the check-ignore half. A probe that silently answers "not
 * excluded" to everything -- git missing from PATH, a bad flag, the wrong -C --
 * would let every target assertion below pass while measuring nothing, and the
 * exit code and the empty-output case are indistinguishable from a real refusal.
 * These two controls bracket it: one path this repository is known to exclude,
 * and one it is known to track.
 */
$control_rule = diviops_sau_check_ignore( $root, 'worktrees/x/y.txt' );

// Asserted against the committed PATTERN, not against a line number read off a
// run: `worktrees/` is in .gitignore because per-issue worktrees live inside the
// repository, and that fact outlives any reordering of the file.
assert_true(
	0 === strpos( $control_rule, '.gitignore:' ) && '' !== $control_rule
		&& ':worktrees/' === substr( $control_rule, -11 ),
	sprintf(
		'the check-ignore probe resolves a path this repository really excludes, naming the committed rule that did it (got %s)',
		'' === $control_rule ? '<no rule>' : $control_rule
	)
);

assert_same(
	'',
	diviops_sau_check_ignore( $root, 'FORK.md' ),
	'the check-ignore probe refuses a path this repository really tracks, so it is not matching everything'
);

/*
 * The ignore rule has to hold for both stores, and at both roots the server can
 * be launched from: the repository root, and `diviops-server/` when the server
 * is started from its own package directory. An anchored pattern would cover the
 * first and silently miss the second.
 */
$ignored_targets = array(
	'.diviops-tmp/page-exports/pe-900390-2fc3c1fc558a5e7f-eb7f4627.json'
		=> 'a page-export artifact at the repository root is excluded',
	'.diviops-tmp/cross-env-source-payloads/some-handle.json'
		=> 'a cross-env source payload at the repository root is excluded',
	'diviops-server/.diviops-tmp/page-exports/pe-1-a.json'
		=> 'a page-export artifact is excluded when the server ran from diviops-server/',
	'.diviops-tmp/anything-a-later-store-adds.json'
		=> 'the whole .diviops-tmp root is excluded, so a third store added later is covered without another rule',
);

foreach ( $ignored_targets as $path => $message ) {
	$rule = diviops_sau_check_ignore( $root, $path );

	assert_true( '' !== $rule, $message );

	// The rule must come from the committed .gitignore. A machine-local
	// .git/info/exclude would pass the assertion above and protect nobody who
	// clones this repository, which is every other machine and CI.
	assert_true(
		0 === strpos( $rule, '.gitignore:' ),
		sprintf( 'the rule excluding %s is committed in .gitignore rather than a machine-local exclude file', $path )
	);
}

$tracked = diviops_sau_tracked_paths( $root );

/*
 * Non-vacuity for the index half, in the two forms the house style asks for:
 * count what was inspected and assert the count, then prove the set is the real
 * index and not a plausible-looking empty one. A containment gate whose verdict
 * is "found no violations" passes just as loudly when it inspected nothing --
 * when git returned an error string, when the -C pointed somewhere else. That
 * exact failure happened three times on this project's predecessor, which is why
 * the runner refuses an empty test discovery too.
 */
assert_true(
	count( $tracked ) > 0,
	sprintf( 'git reported a non-empty index, so the scan below inspected real paths (%d inspected)', count( $tracked ) )
);

assert_true(
	in_array( 'FORK.md', $tracked, true ),
	'the tracked-path set contains a file known to be tracked, which proves it is this repository index rather than an empty set'
);

$tmp_tracked = array_values(
	array_filter(
		$tracked,
		static function ( string $path ): bool {
			// Every segment, not the basename. A store directory committed with
			// its contents has basename `pe-....json` only on the leaves; the
			// `.diviops-tmp` marker is carried by one interior segment alone.
			return in_array( '.diviops-tmp', explode( '/', $path ), true );
		}
	)
);

assert_same(
	array(),
	$tmp_tracked,
	'nothing under a .diviops-tmp directory is tracked anywhere in the repository -- these are scratch artifacts holding '
		. 'a real page inlined as base64, and this repository is public'
);

$export_named = array_values(
	array_filter(
		$tracked,
		static function ( string $path ): bool {
			// The handle shape the page-export store mints, from
			// page-export-ref.ts:97 -- `/^pe-[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/`
			// plus the `.json` the store appends. Matched on the basename
			// wherever it sits, because an artifact copied out of the store to a
			// scratch path is the same disclosure as one left inside it.
			return 1 === preg_match( '#(^|/)pe-[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.json$#', $path );
		}
	)
);

assert_same(
	array(),
	$export_named,
	'no page-export artifact is tracked anywhere in the repository -- the boundary is the artifact name, not the '
		. 'directory it happens to sit in'
);
