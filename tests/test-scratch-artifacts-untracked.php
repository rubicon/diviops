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
 *   3. The rule covers the directory the STORES ACTUALLY WRITE, read out of
 *      their TypeScript source rather than spelled again here. Halves 1 and 2
 *      take `.diviops-tmp` as a given, so on their own they stay green through a
 *      rename of the stores' default root while the new directory sits untracked
 *      AND unignored -- the exact state this file was written about. The probe
 *      paths are built from the source literal, so a rename makes git answer
 *      about the new name and this gate fail naming it. Pinning two places that
 *      have to agree is the house pattern (`tests/test-version-sync.php`,
 *      `tests/test-frozen-surface-drift.php`).
 *
 * Deliberately NOT covered: a root pointed somewhere else by environment.
 * `exportRoot()` and `payloadRoot()` each prefer an override --
 * `DIVIOPS_PAGE_EXPORT_REF_DIR` and `DIVIOPS_CROSS_ENV_PAYLOAD_REF_DIR` -- over
 * the default this file pins, and a session setting one to a path inside the
 * checkout puts artifacts outside every rule here. The page-export store
 * survives it anyway, because its handle prefix is matched by name wherever the
 * file sits; the cross-env store does not, for the reason in the next paragraph,
 * so under an override it has no coverage at all. That asymmetry is the reason
 * the handle-prefix presence of BOTH stores is asserted below: a gate cannot know
 * what environment a future session sets, but it can fail the moment the
 * cross-env store gains a prefix that would let it be caught by name too.
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
 * Whether a `-v` rule half came from a committed `.gitignore`.
 *
 * Tests the SOURCE FILE'S NAME, not its path. `git check-ignore` reports the
 * highest-precedence matching rule, which for a nested gitignore is that file:
 * `sub/.gitignore:1:.diviops-tmp/`. Requiring the root `.gitignore` therefore
 * turned this gate red the moment anyone added `diviops-server/.gitignore`
 * carrying the same rule -- a change that makes the exclusion stronger, not
 * weaker. What the assertion is actually for is excluding `.git/info/exclude`,
 * which would satisfy a yes/no check while protecting this machine and no clone.
 *
 * @param string $rule Rule half as `<source>:<linenum>:<pattern>`.
 * @return bool True when the source is a `.gitignore` file.
 */
function diviops_sau_rule_is_committed_gitignore( string $rule ): bool {
	$source = strstr( $rule, ':', true );

	if ( ! is_string( $source ) || '' === $source ) {
		return false;
	}

	return '.gitignore' === basename( $source );
}

/**
 * Read a store's compiled-in defaults out of its TypeScript source.
 *
 * Returns the match COUNTS alongside the values, because the two cannot be
 * recovered from each other: a handle prefix is legitimately empty (the cross-env
 * store has none), so `'' === $out['prefix']` cannot distinguish "declares no
 * prefix" from "the extractor found no HANDLE_PATTERN at all" -- and the second
 * is the failure that would make every assertion built on it vacuous.
 *
 * @param string $file Absolute path to a store's source file.
 * @return array{root_count:int,env_count:int,prefix_count:int,dir:string,subdir:string,env:string,prefix:string}
 */
function diviops_sau_store_defaults( string $file ): array {
	$src = (string) file_get_contents( $file );

	$roots    = (int) preg_match_all( '/join\(\s*process\.cwd\(\)\s*,\s*"([^"]+)"\s*,\s*"([^"]+)"\s*\)/', $src, $root_m, PREG_SET_ORDER );
	$envs     = (int) preg_match_all( '/process\.env\.([A-Z0-9_]+)\s*\|\|/', $src, $env_m, PREG_SET_ORDER );
	$prefixes = (int) preg_match_all( '/HANDLE_PATTERN\s*=\s*\/\^([A-Za-z0-9._-]*)\[/', $src, $prefix_m, PREG_SET_ORDER );

	return array(
		'root_count'   => $roots,
		'env_count'    => $envs,
		'prefix_count' => $prefixes,
		'dir'          => 1 === $roots ? $root_m[0][1] : '',
		'subdir'       => 1 === $roots ? $root_m[0][2] : '',
		'env'          => 1 === $envs ? $env_m[0][1] : '',
		'prefix'       => 1 === $prefixes ? $prefix_m[0][1] : '',
	);
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

/**
 * Whether any path segment is a scratch-store directory, compared the way git does.
 *
 * @param string            $path Repository-relative tracked path.
 * @param array<int,string> $dirs Store directory names.
 * @return bool True when a segment equals a store directory, ignoring case.
 */
function diviops_sau_under_store_dir( string $path, array $dirs ): bool {
	foreach ( explode( '/', $path ) as $segment ) {
		foreach ( $dirs as $dir ) {
			if ( 0 === strcasecmp( $dir, $segment ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Whether a path carries a page-export handle name under any known prefix.
 *
 * @param string            $path     Repository-relative tracked path.
 * @param array<int,string> $prefixes Handle prefixes read from the stores.
 * @return bool True when the basename has the minted handle shape.
 */
function diviops_sau_export_named( string $path, array $prefixes ): bool {
	foreach ( $prefixes as $prefix ) {
		$pattern = '#(^|/)' . preg_quote( $prefix, '#' ) . '[A-Za-z0-9][A-Za-z0-9._-]{0,127}\.json$#i';

		if ( 1 === preg_match( $pattern, $path ) ) {
			return true;
		}
	}

	return false;
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

// Asserted against the KIND of source, not against the rule's text or its line
// number. Both of those are owned by whoever maintains `worktrees/`, and this
// gate owning them means refining that unrelated rule to `worktrees/**` -- still
// excluding this path, more precisely -- reports the probe as broken.
assert_true(
	diviops_sau_rule_is_committed_gitignore( $control_rule ),
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

	// The rule must come from a committed .gitignore. A machine-local
	// .git/info/exclude would pass the assertion above and protect nobody who
	// clones this repository, which is every other machine and CI.
	assert_true(
		diviops_sau_rule_is_committed_gitignore( $rule ),
		sprintf( 'the rule excluding %s is committed in a .gitignore rather than a machine-local exclude file (got %s)', $path, '' === $rule ? '<no rule>' : $rule )
	);
}

/*
 * The coupling half. Everything above spells `.diviops-tmp` out by hand, which is
 * exactly as blind to a renamed store root as the missing ignore rule was to an
 * unignored directory. These assertions read the directory out of each store's
 * source and ask git about THAT, so the gate follows a rename instead of
 * certifying a name nothing writes any more.
 */
$extractor_control = diviops_sau_store_defaults( $root . '/FORK.md' );

// Negative control for the extractor, ahead of trusting any match it reports: a
// file that declares no default root yields nothing, so the matches below are the
// stores' real literals rather than a regex that matches whatever it is handed.
assert_same(
	0,
	$extractor_control['root_count'],
	'the store-root extractor finds nothing in a file that declares no default root, so its matches are real'
);

// Value is whether the store mints a PREFIXED handle, which decides whether it
// can be caught by filename at all.
$stores = array(
	'diviops-server/src/page-export-ref.ts'                        => true,
	'diviops-server/src/cross-env-preflight/source-payload-ref.ts' => false,
);

$store_dirs       = array( '.diviops-tmp' );
$handle_prefixes  = array();
$stores_inspected = 0;

foreach ( $stores as $store => $mints_prefixed_handle ) {
	assert_true(
		is_file( $root . '/' . $store ),
		sprintf( '%s is where a store that writes a scratch root lives, so its defaults can be read', $store )
	);

	$defaults = diviops_sau_store_defaults( $root . '/' . $store );

	// Unambiguity, doubling as non-vacuity. Zero means the extractor stopped
	// matching and every assertion below would be measuring '' instead of the
	// source; more than one means the store grew a second root and this is
	// checking only the first.
	assert_same(
		1,
		$defaults['root_count'],
		sprintf( '%s declares exactly one join(process.cwd(), ...) default root for the extractor to read', $store )
	);

	assert_true(
		'' !== $defaults['dir'] && '' !== $defaults['subdir'],
		sprintf( '%s yields a non-empty root and subdirectory (got %s / %s)', $store, $defaults['dir'], $defaults['subdir'] )
	);

	// The load-bearing one: the probe path is built from the literal, so this is
	// the assertion that goes red on a rename .gitignore did not follow.
	$probe = $defaults['dir'] . '/' . $defaults['subdir'] . '/probe.json';
	$rule  = diviops_sau_check_ignore( $root, $probe );

	assert_true(
		'' !== $rule && diviops_sau_rule_is_committed_gitignore( $rule ),
		sprintf(
			'the directory %s writes by default (%s) is excluded by a committed .gitignore rule -- a failure here means that store changed its root and .gitignore did not follow',
			$store,
			$defaults['dir'] . '/' . $defaults['subdir']
		)
	);

	// Pins the override the docblock names as an uncovered case, so the two
	// cannot drift apart.
	assert_same(
		1,
		$defaults['env_count'],
		sprintf( '%s reads exactly one environment override for its root, which the docblock names as outside this gate', $store )
	);

	assert_same(
		1,
		$defaults['prefix_count'],
		sprintf( '%s declares a HANDLE_PATTERN the extractor can read', $store )
	);

	// The asymmetry that decides which store gets a name rule below, asserted
	// rather than described. A cross-env store that gains a prefix fails here,
	// and the fix is to give it the name rule it could not have before.
	assert_same(
		$mints_prefixed_handle,
		'' !== $defaults['prefix'],
		sprintf(
			'%s handle-prefix presence is unchanged (prefix %s) -- a store that gains one needs a name rule, one that loses one cannot have it',
			$store,
			'' === $defaults['prefix'] ? '<none>' : $defaults['prefix']
		)
	);

	if ( '' !== $defaults['dir'] ) {
		$store_dirs[] = $defaults['dir'];
	}

	if ( '' !== $defaults['prefix'] ) {
		$handle_prefixes[] = $defaults['prefix'];
	}

	++$stores_inspected;
}

// Non-vacuity for the loop itself: a `foreach` over a list that lost its entries
// runs zero times and reports nothing wrong.
assert_same(
	count( $stores ),
	$stores_inspected,
	sprintf( 'every store in the list was inspected rather than skipped (%d of %d)', $stores_inspected, count( $stores ) )
);

$store_dirs = array_values( array_unique( $store_dirs ) );

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

// Controls for the matcher, fed synthetic paths. The scan below runs over a clean
// index and so matches nothing; without these a matcher that could never match
// would pass it just the same.
assert_true( diviops_sau_under_store_dir( 'x/.diviops-tmp/cross-env-source-payloads/h.json', array( '.diviops-tmp' ) ), 'the directory matcher finds a store directory at an interior segment' );
assert_true( diviops_sau_under_store_dir( '.DIVIOPS-TMP/x.json', array( '.diviops-tmp' ) ), 'the directory matcher is case-insensitive, as git is under core.ignorecase' );
assert_true( false === diviops_sau_under_store_dir( 'diviops-server/src/index.ts', array( '.diviops-tmp' ) ), 'the directory matcher refuses a path outside any store directory' );

$tmp_tracked = array_values(
	array_filter(
		$tracked,
		static function ( string $path ) use ( $store_dirs ): bool {
			return diviops_sau_under_store_dir( $path, $store_dirs );
		}
	)
);

assert_same(
	array(),
	$tmp_tracked,
	'nothing under a scratch-store directory is tracked anywhere in the repository -- these are scratch artifacts holding '
		. 'a real page inlined as base64, and this repository is public'
);

// Non-vacuity for the name rule. Its prefixes come from source now, so an
// extractor that returned none would leave the filter matching nothing and the
// assertion below passing while checking no filename at all.
assert_true(
	count( $handle_prefixes ) > 0,
	sprintf(
		'at least one store mints a prefixed handle, so the name rule has something to match on (%d prefix(es): %s)',
		count( $handle_prefixes ),
		implode( ', ', $handle_prefixes )
	)
);

assert_true( diviops_sau_export_named( 'docs/pe-900390-abc.json', array( 'pe-' ) ), 'the name matcher finds a handle-shaped file outside the store' );
assert_true( diviops_sau_export_named( 'docs/PE-900390-ABC.JSON', array( 'pe-' ) ), 'the name matcher is case-insensitive' );
assert_true( false === diviops_sau_export_named( 'docs/some-payload.json', array( 'pe-' ) ), 'the name matcher refuses an unprefixed json file' );

$export_named = array_values(
	array_filter(
		$tracked,
		static function ( string $path ) use ( $handle_prefixes ): bool {
			return diviops_sau_export_named( $path, $handle_prefixes );
		}
	)
);

assert_same(
	array(),
	$export_named,
	'no page-export artifact is tracked anywhere in the repository -- the boundary is the artifact name, not the '
		. 'directory it happens to sit in'
);
