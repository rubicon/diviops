<?php
// SPDX-License-Identifier: MIT
/**
 * Upstream release archive containment guard (#268, narrowed by #363).
 *
 * `upstream-releases/` is a local archive of published DiviOps releases. Its
 * free half is the fork base. Its Pro half is DiviOps Agent Pro, a commercial
 * plugin this project neither forks nor licenses.
 *
 * Reading a Pro artifact is allowed -- the clean-room rule that forbade it was
 * rescinded by the owner on 2026-09-02. What survives is narrower and is what
 * this gate enforces: nothing Pro may be COMMITTED, because that is the half
 * with a consequence outside this machine.
 *
 * `rubicon/diviops` is a public repository. Committing a Pro artifact would
 * publish a third party's proprietary source under our name -- a licensing
 * incident, not a tidiness problem. `.gitignore` covers the directory, but a
 * `.gitignore` is one `git add -f` away from being irrelevant and nothing would
 * report it. This turns that from a convention into a gate.
 *
 * Asserts against git's own index rather than the filesystem, because the
 * question is not "what is on disk" -- the archives are supposed to be on disk --
 * but "what would a push publish".
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$root = dirname( __DIR__ );

/**
 * List every path git tracks under a prefix.
 *
 * @param string $root   Repository root.
 * @param string $prefix Path prefix to list.
 * @return array<int,string> Tracked paths, repository-relative.
 */
function diviops_tracked_paths_under( string $root, string $prefix ): array {
	$command = sprintf(
		'git -C %s ls-files -z -- %s',
		escapeshellarg( $root ),
		escapeshellarg( $prefix )
	);

	$output = shell_exec( $command );
	if ( ! is_string( $output ) || '' === $output ) {
		return array();
	}

	return array_values( array_filter( explode( "\0", $output ), 'strlen' ) );
}

assert_true(
	is_dir( $root . '/.git' ) || is_file( $root . '/.git' ),
	'the suite is running inside a git checkout, so git can be asked what is tracked'
);

$tracked = diviops_tracked_paths_under( $root, 'upstream-releases' );

// Non-vacuity. A containment gate whose verdict is "found no violations" passes
// just as loudly when it inspected nothing at all -- when the directory was
// renamed, when the prefix stopped matching, when git returned an error string.
// The README is tracked on purpose, so its presence proves this gate is actually
// looking at the right place. That exact failure -- a gate reporting success
// while inspecting an empty set -- happened three times on this project's
// predecessor, which is why the runner refuses an empty test discovery too.
assert_true(
	in_array( 'upstream-releases/README.md', $tracked, true ),
	'the archive README is tracked, which proves this gate inspected the real path rather than an empty set'
);

$unexpected = array_values(
	array_filter(
		$tracked,
		static function ( string $path ): bool {
			return 'upstream-releases/README.md' !== $path;
		}
	)
);

assert_same(
	array(),
	$unexpected,
	'nothing under upstream-releases/ is tracked except README.md -- a tracked archive would publish '
		. 'a commercial plugin from a public repository'
);

// The directory check above is necessary but not sufficient. The boundary is the
// ARTIFACT NAME, not the folder: a Pro-suite bundle extracted into the repo root,
// a Pro plugin zip copied next to the plugin it patches, or a stray unzip into a
// scratch path are all the same licensing incident and all sit outside
// upstream-releases/. Asserting on the name catches them wherever they land.
$pro_named = array_values(
	array_filter(
		diviops_tracked_paths_under( $root, '.' ),
		static function ( string $path ): bool {
			// Every SEGMENT, not just the basename. An extracted Pro bundle is a
			// DIRECTORY whose own name is the only Pro-named part of the path --
			// `scripts/diviops-pro-suite-v1.5.51-beta/note.txt` has basename
			// `note.txt` and would otherwise sail straight through this gate.
			foreach ( explode( '/', $path ) as $segment ) {
				// A hyphen-delimited `pro` word, not a prefix. The prefixes alone
				// only described the artifact FILENAMES, so renaming the containing
				// directories to agent-pro/ and suite-pro/ made this blind to
				// anything extracted inside them: only the directory carried the
				// marker and the files under it did not (#271). This form does not
				// depend on a naming scheme staying put. Checked against every
				// tracked path in the repository: zero false positives, because
				// nothing legitimate here uses `pro` as a standalone word.
				if ( 1 === preg_match( '/(^|-)pro($|-)/i', $segment ) ) {
					return true;
				}
			}
			return false;
		}
	)
);

assert_same(
	array(),
	$pro_named,
	'no Pro-named artifact is tracked anywhere in the repository -- the boundary is the artifact name, '
		. 'not the directory it happens to sit in'
);

// The two halves carry different licenses and different rules, so the README has
// to actually say so. A guard that protects the directory while the directory's
// own documentation omits why is one refactor away from someone "tidying up" the
// commercial-half warning out of existence.
$readme = (string) file_get_contents( $root . '/upstream-releases/README.md' );

assert_true(
	'' !== $readme,
	'the archive README has content'
);

foreach ( array( 'diviops-pro-suite-*', 'diviops-agent-pro*', 'Never commit', 'Do not transcribe', 'Surface Pro overlap', 'public' ) as $needle ) {
	assert_true(
		false !== strpos( $readme, $needle ),
		sprintf( 'the archive README still names the Pro artifacts and all three surviving rules (missing: %s)', $needle )
	);
}

// The rescission itself has to stay legible as a DECISION. Without a date and an
// owner attached, the next reader who finds a permissive rule where a strict one
// used to be cannot tell a deliberate change from something that got softened by
// accident, and the safe reading of that ambiguity is to re-impose the old rule
// and refuse a read the owner explicitly permitted.
assert_true(
	false !== strpos( $readme, 'rescinded' ) && false !== strpos( $readme, '2026-09-02' ),
	'the archive README records that the clean-room rule was rescinded and when, so the change reads as a decision rather than a drift'
);
