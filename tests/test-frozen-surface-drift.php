<?php
// SPDX-License-Identifier: MIT
/**
 * The frozen surface of diviops-agent.php, measured against the base ref (#451).
 *
 * `tests/test-drop-in-constraint.php` asserts the four frozen identifiers still
 * EXIST. That is the right question for a rename, and the wrong one for an edit that
 * rewrites the surrounding code: `const REST_NAMESPACE = 'diviops/v1';` moved into a
 * different class, or `const CAPABILITIES` losing one key in the middle of a large
 * refactor, both leave every existence check green. #451 adopted a 444-line upstream
 * rewrite of the admin region of that file, which is the largest single edit this
 * fork has made to the most divergence-sensitive file it owns, and "we checked by
 * eye" is not a control.
 *
 * So this asks a different question: is the frozen surface BYTE-IDENTICAL to what it
 * was at the base ref? Seven extracts, each compared against the same extract taken
 * from the base blob:
 *
 *   plugin_slug            the install directory and update key
 *   class_declaration      what Pro probes with class_exists()
 *   rest_namespace         what @rubicontv/diviops-mcp calls
 *   handshake_filter       what Pro hooks to advertise its capabilities
 *   version_header_marker  release-please's header markers
 *   version_const_marker   release-please's class-constant marker
 *   capabilities           the handshake surface Pro gates on, in order
 *
 * The two version extracts have the semver normalised to `<version>` before
 * comparison, deliberately. release-please rewrites both on every release, and a gate
 * that failed on a version bump would block the only process able to satisfy it —
 * the #321 defect, in a new place. What is pinned is the marker scaffolding
 * (`x-release-please-start-version`, `x-release-please-end`,
 * `// x-release-please-version`) and the declaration shape around it, which is what
 * makes release-please able to find the version at all.
 *
 * Base blobs are read with popen() rather than through
 * `diviops_fork_ledger_git()`, because exec() strips trailing whitespace from every
 * line it returns (verified). A byte-comparison fed by a lossy reader reports
 * differences that are not there and, worse, hides one that is.
 *
 * NOT covered: whether any of these values is CORRECT. That is
 * `tests/test-drop-in-constraint.php` (the identifiers), `tests/test-version-sync.php`
 * (the two version declarations agree) and `tests/test-tool-count-sync.php`
 * (CAPABILITIES against the server). This one only answers "did this change move it".
 *
 * @package DiviOps
 */

require_once dirname( __DIR__ ) . '/scripts/lib/fork-divergence.php';

/** Paths the frozen surface is read out of, relative to the repository root. */
const DIVIOPS_FROZEN_MAIN_PATH = 'plugins/diviops-agent/diviops-agent.php';
const DIVIOPS_FROZEN_META_PATH = 'plugins/diviops-agent/includes/trait-meta.php';

/**
 * First match of $pattern in $subject, or '' when it does not match.
 *
 * An unmatched pattern returning '' is load-bearing: a frozen construct that stops
 * being recognisable is exactly as alarming as one that changed, and both have to
 * reach the comparison rather than being skipped.
 *
 * @param string $subject Source text.
 * @param string $pattern Regular expression.
 * @return string Matched text with trailing whitespace removed, or ''.
 */
function diviops_frozen_match( string $subject, string $pattern ): string {
	return 1 === preg_match( $pattern, $subject, $m ) ? rtrim( $m[0] ) : '';
}

/**
 * Replace every semver in $text with a placeholder.
 *
 * @param string $text Text to normalise.
 * @return string
 */
function diviops_frozen_normalise_version( string $text ): string {
	return (string) preg_replace( '/[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.]+)?/', '<version>', $text );
}

/**
 * Extract the frozen surface from plugin source.
 *
 * @param string $main Contents of diviops-agent.php.
 * @param string $meta Contents of includes/trait-meta.php, which carries the filter.
 * @param string $slug Plugin directory name.
 * @return array<string, string> Extract name => matched text ('' when not found).
 */
function diviops_frozen_surface( string $main, string $meta, string $slug ): array {
	return array(
		'plugin_slug'           => $slug,
		'class_declaration'     => diviops_frozen_match( $main, '/^[ \t]*(?:final[ \t]+)?class[ \t]+DiviOps_Agent\b[^\r\n]*/m' ),
		'rest_namespace'        => diviops_frozen_match( $main, '/^[ \t]*const[ \t]+REST_NAMESPACE[ \t]*=[^\r\n]*\'diviops\/v1\'[^\r\n]*/m' ),
		'handshake_filter'      => diviops_frozen_match( $meta, '/^[^\r\n]*apply_filters\([^\r\n]*\'diviops_agent_handshake_extensions\'[^\r\n]*/m' ),
		'version_header_marker' => diviops_frozen_normalise_version(
			diviops_frozen_match( $main, '/^[ \t]*\*[ \t]*x-release-please-start-version.*?^[ \t]*\*[ \t]*x-release-please-end/ms' )
		),
		'version_const_marker'  => diviops_frozen_normalise_version(
			diviops_frozen_match( $main, '/^[ \t]*const[ \t]+VERSION[ \t]*=[ \t]*\'[^\']*\';[ \t]*\/\/[ \t]*x-release-please-version[ \t]*$/m' )
		),
		'capabilities'          => diviops_frozen_match( $main, '/^[ \t]*const[ \t]+CAPABILITIES[ \t]*=[ \t]*\[.*?^[ \t]*\];/ms' ),
	);
}

/**
 * The ordered capability keys inside a CAPABILITIES extract.
 *
 * Reads the quoted keys rather than the whole literal, so comments, wrapping and
 * indentation inside the const cannot register as a change to the surface Pro gates
 * on. An empty result from a non-empty extract is returned as-is and compares
 * unequal, which is the correct outcome: an extract whose keys stopped being
 * recognisable is exactly as alarming as one that lost a key.
 *
 * @param string $extract The matched `const CAPABILITIES = [ ... ];` text.
 * @return array<int, string> Keys in declaration order.
 */
function diviops_frozen_capability_keys( string $extract ): array {
	// Strip line comments first: the const is heavily commented with group labels,
	// and a label containing a quoted word would otherwise read as a key.
	$stripped = (string) preg_replace( '#//[^\r\n]*#', '', $extract );
	preg_match_all( "/'([a-z0-9_]+)'/", $stripped, $matches );
	return $matches[1];
}

/**
 * Compare two capability lists under the rule that actually protects Pro.
 *
 * Byte identity is the wrong test for this one extract, and #38 phase 1 was the
 * first change to discover it: no capability key had been added since this gate
 * landed in #451, so nothing had ever exercised the addition case. Under byte
 * identity, shipping ANY new tool fails this gate with no waiver — which means the
 * gate would be satisfied only by never growing the plugin, and the realistic way
 * that resolves is somebody deleting the gate.
 *
 * What the docblock above says the gate is for is precise, and additions are not in
 * it: "a failed capability gate removes a tool rather than reporting a problem."
 * Removing a key, renaming one, or reordering the existing ones can each silently
 * disable a Pro capability or vanish an MCP tool. Appending a new key cannot — Pro
 * gates on the keys it knows, and a key it has never heard of is inert to it.
 *
 * So the invariant enforced here is: every base key is still present, and the base
 * keys still appear in the same relative order. New keys may appear anywhere.
 *
 * @param array<int, string> $base Base-ref keys, in order.
 * @param array<int, string> $here This checkout's keys, in order.
 * @return string|null Reason the lists are incompatible, or null when they are fine.
 */
function diviops_frozen_capability_reason( array $base, array $here ): ?string {
	$missing = array_values( array_diff( $base, $here ) );
	if ( array() !== $missing ) {
		return 'capability key(s) removed or renamed: ' . implode( ', ', $missing );
	}

	// Relative order: walk this checkout's list and require the base keys to appear
	// in their base sequence. Anything interleaved is a new key and is skipped.
	$expected = 0;
	foreach ( $here as $key ) {
		if ( $expected < count( $base ) && $key === $base[ $expected ] ) {
			$expected++;
		}
	}
	if ( $expected !== count( $base ) ) {
		return 'capability keys reordered; the base order must be preserved';
	}

	return null;
}

/**
 * Read a blob at $ref exactly, or null when it does not resolve.
 *
 * popen() rather than exec(): exec() strips trailing whitespace from each output
 * line, which silently rewrites the bytes this gate exists to compare.
 *
 * @param string $root Repository root.
 * @param string $ref  Ref to read from.
 * @param string $path Repository-relative path.
 * @return string|null Blob contents, or null when git could not produce it.
 */
function diviops_frozen_blob( string $root, string $ref, string $path ): ?string {
	$command = 'git -C ' . escapeshellarg( $root ) . ' show ' . escapeshellarg( $ref . ':' . $path ) . ' 2>/dev/null';

	$handle = popen( $command, 'r' );
	if ( false === $handle ) {
		return null;
	}
	$bytes  = stream_get_contents( $handle );
	$status = pclose( $handle );

	return 0 === $status && is_string( $bytes ) ? $bytes : null;
}

/**
 * Compare this checkout's frozen surface against the base ref's.
 *
 * Three statuses, so "I compared and nothing moved" and "I could not compare"
 * never render the same way:
 *
 *   no-base   — the base ref or one of the base blobs does not resolve. Skip.
 *   unchanged — every extract matches the base. Pass.
 *   changed   — at least one extract differs. Fail, naming which.
 *
 * @param string $root Repository root.
 * @param string $base Base ref.
 * @return array{status: string, reason: string, differences: array<int, string>, inspected: int}
 */
function diviops_frozen_surface_report( string $root, string $base ): array {
	$base_main = diviops_frozen_blob( $root, $base, DIVIOPS_FROZEN_MAIN_PATH );
	$base_meta = diviops_frozen_blob( $root, $base, DIVIOPS_FROZEN_META_PATH );

	if ( null === $base_main || null === $base_meta ) {
		return array(
			'status'      => 'no-base',
			'reason'      => sprintf(
				'nothing was compared: %s does not resolve to both %s and %s in %s (a shallow or detached checkout). '
					. 'Nothing was inspected, so this is not a pass',
				$base,
				DIVIOPS_FROZEN_MAIN_PATH,
				DIVIOPS_FROZEN_META_PATH,
				$root
			),
			'differences' => array(),
			'inspected'   => 0,
		);
	}

	$here = diviops_frozen_surface(
		(string) @file_get_contents( $root . '/' . DIVIOPS_FROZEN_MAIN_PATH ),
		(string) @file_get_contents( $root . '/' . DIVIOPS_FROZEN_META_PATH ),
		basename( dirname( $root . '/' . DIVIOPS_FROZEN_MAIN_PATH ) )
	);
	$there = diviops_frozen_surface( $base_main, $base_meta, 'diviops-agent' );

	$differences  = array();
	$capabilities = null;
	foreach ( $here as $name => $value ) {
		if ( $value === ( $there[ $name ] ?? null ) ) {
			continue;
		}
		// Every extract but this one is frozen byte-for-byte. See
		// diviops_frozen_capability_reason() for why this one is not, and for
		// exactly what it is frozen against instead.
		if ( 'capabilities' === $name && '' !== $value && '' !== (string) ( $there[ $name ] ?? '' ) ) {
			$capabilities = diviops_frozen_capability_reason(
				diviops_frozen_capability_keys( (string) $there[ $name ] ),
				diviops_frozen_capability_keys( $value )
			);
			if ( null === $capabilities ) {
				continue;
			}
		}
		$differences[] = (string) $name;
	}

	if ( array() === $differences ) {
		return array(
			'status'      => 'unchanged',
			'reason'      => sprintf( 'compared %d frozen extract(s) against %s; all byte-identical', count( $here ), $base ),
			'differences' => array(),
			'inspected'   => count( $here ),
		);
	}

	return array(
		'status'      => 'changed',
		'reason'      => sprintf(
			'%d of %d frozen extract(s) differ from %s: %s. Renaming any of the four frozen identifiers silently '
				. 'disables DiviOps Agent Pro and makes MCP tools vanish without an error; editing a release-please '
				. 'marker breaks the release automation that owns the version; adding, removing or reordering a '
				. 'CAPABILITIES key changes the handshake surface Pro gates on',
			count( $differences ),
			count( $here ),
			$base,
			implode( ', ', $differences )
		),
		'differences' => $differences,
		'inspected'   => count( $here ),
	);
}

/* -------------------------------------------------------------------------
 * The extractor, over synthetic sources.
 *
 * This half is where the teeth are: it shows each extract moving when the thing it
 * pins moves. The live half below can only ever report "nothing changed" on a change
 * that did not touch the surface, which on its own is indistinguishable from an
 * extractor that matches nothing.
 * ---------------------------------------------------------------------- */

$frozen_main = <<<'PHP'
<?php
/**
 * Plugin Name: DiviOps Agent
 * x-release-please-start-version
 * Version: 1.23.2
 * x-release-please-end
 */
class DiviOps_Agent {
	const VERSION = '1.23.2'; // x-release-please-version

	const CAPABILITIES = [
		// canvas
		'canvas_create', 'canvas_delete',
		'page_get',
	];

	const REST_NAMESPACE      = 'diviops/v1';
}
PHP;

$frozen_meta = <<<'PHP'
<?php
trait DiviOps_Meta {
	public static function handshake() {
		$extensions = apply_filters( 'diviops_agent_handshake_extensions', [] );
	}
}
PHP;

$frozen_baseline = diviops_frozen_surface( $frozen_main, $frozen_meta, 'diviops-agent' );

assert_same(
	array( 'plugin_slug', 'class_declaration', 'rest_namespace', 'handshake_filter', 'version_header_marker', 'version_const_marker', 'capabilities' ),
	array_keys( $frozen_baseline ),
	'the frozen surface is the seven extracts #451 names, in a fixed order'
);

foreach ( $frozen_baseline as $frozen_name => $frozen_value ) {
	assert_true(
		'' !== $frozen_value,
		"the extractor finds {$frozen_name} in a faithful source; an extract that matches nothing would compare equal to another nothing"
	);
}

// The extracts carry the real text, not a boolean dressed up as one. Asserted
// literally so a pattern that widened to swallow the rest of the file is caught.
assert_same( 'class DiviOps_Agent {', $frozen_baseline['class_declaration'], 'the class extract is the declaration line' );
assert_same( "\tconst REST_NAMESPACE      = 'diviops/v1';", $frozen_baseline['rest_namespace'], 'the namespace extract keeps the declaration verbatim, alignment included' );
assert_same( "\t\t\$extensions = apply_filters( 'diviops_agent_handshake_extensions', [] );", $frozen_baseline['handshake_filter'], 'the filter extract is the apply_filters call' );
assert_same( "\tconst VERSION = '<version>'; // x-release-please-version", $frozen_baseline['version_const_marker'], 'the const marker extract normalises the semver and keeps the marker comment' );
assert_same(
	" * x-release-please-start-version\n * Version: <version>\n * x-release-please-end",
	$frozen_baseline['version_header_marker'],
	'the header marker extract spans both markers and normalises the semver between them'
);
assert_true(
	false !== strpos( $frozen_baseline['capabilities'], "'page_get'," ) && false === strpos( $frozen_baseline['capabilities'], 'REST_NAMESPACE' ),
	'the capabilities extract stops at its own closing bracket rather than running on to the next constant'
);

/**
 * Which extracts move when $main / $meta are edited this way.
 *
 * The baseline is passed in rather than read from a global: `tests/run.php` requires
 * each test file inside its own closure, so a top-level variable here is not in any
 * scope a `global` declaration can reach, and reading one would silently compare
 * against null.
 *
 * @param array<string, string> $baseline Baseline surface to compare against.
 * @param string                $main     Edited main file.
 * @param string                $meta     Edited meta trait.
 * @param string                $slug     Plugin directory name.
 * @return array<int, string> Extract names that differ from the baseline.
 */
function diviops_frozen_moved( array $baseline, string $main, string $meta, string $slug ): array {
	$moved = array();
	foreach ( diviops_frozen_surface( $main, $meta, $slug ) as $name => $value ) {
		if ( $value !== ( $baseline[ $name ] ?? null ) ) {
			$moved[] = (string) $name;
		}
	}
	return $moved;
}

// Each of the four frozen identifiers, renamed the way a careless refactor would.
assert_same(
	array( 'plugin_slug' ),
	diviops_frozen_moved( $frozen_baseline, $frozen_main, $frozen_meta, 'diviops-agent-free' ),
	'renaming the plugin directory moves the slug extract'
);
assert_same(
	array( 'class_declaration' ),
	diviops_frozen_moved( $frozen_baseline, str_replace( 'class DiviOps_Agent {', 'class DiviOps_Agent_Free {', $frozen_main ), $frozen_meta, 'diviops-agent' ),
	'renaming the class moves the class extract'
);
assert_same(
	array( 'rest_namespace' ),
	diviops_frozen_moved( $frozen_baseline, str_replace( "'diviops/v1'", "'diviops/v2'", $frozen_main ), $frozen_meta, 'diviops-agent' ),
	'renaming the REST namespace moves the namespace extract'
);
assert_same(
	array( 'handshake_filter' ),
	diviops_frozen_moved( $frozen_baseline, $frozen_main, str_replace( 'diviops_agent_handshake_extensions', 'diviops_handshake_extensions', $frozen_meta ), 'diviops-agent' ),
	'renaming the handshake filter moves the filter extract'
);

// release-please's markers. A version bump must NOT move them — a gate that failed
// on the release commit would block the only process able to satisfy it (#321) —
// while losing a marker must.
assert_same(
	array(),
	diviops_frozen_moved( $frozen_baseline, str_replace( '1.23.2', '1.24.0', $frozen_main ), $frozen_meta, 'diviops-agent' ),
	'a release-please version bump moves nothing; the semver is normalised out before comparison'
);
assert_same(
	array( 'version_const_marker' ),
	diviops_frozen_moved( $frozen_baseline, str_replace( "'1.23.2'; // x-release-please-version", "'1.23.2';", $frozen_main ), $frozen_meta, 'diviops-agent' ),
	'dropping the const marker comment moves the const extract, because release-please then cannot find the version'
);
assert_same(
	array( 'version_header_marker' ),
	diviops_frozen_moved( $frozen_baseline, str_replace( " * x-release-please-start-version\n", '', $frozen_main ), $frozen_meta, 'diviops-agent' ),
	'dropping a header marker moves the header extract'
);

// CAPABILITIES. Membership and order are both part of the handshake surface, so both
// have to register — a reorder is what a mechanical sort of the list would produce.
assert_same(
	array( 'capabilities' ),
	diviops_frozen_moved( $frozen_baseline, str_replace( "\t\t'page_get',\n", '', $frozen_main ), $frozen_meta, 'diviops-agent' ),
	'removing a capability key moves the capabilities extract'
);
assert_same(
	array( 'capabilities' ),
	diviops_frozen_moved( $frozen_baseline, str_replace( "\t\t'page_get',\n", "\t\t'page_get', 'page_list',\n", $frozen_main ), $frozen_meta, 'diviops-agent' ),
	'adding a capability key moves the capabilities extract'
);
assert_same(
	array( 'capabilities' ),
	diviops_frozen_moved( $frozen_baseline, str_replace( "'canvas_create', 'canvas_delete',", "'canvas_delete', 'canvas_create',", $frozen_main ), $frozen_meta, 'diviops-agent' ),
	'reordering capability keys moves the capabilities extract, because the handshake advertises them in order'
);

// The admin refresh #451 actually made, in miniature: a large edit elsewhere in the
// file moves nothing. Without this the gate is indistinguishable from one that fails
// on any edit at all, which would be useless rather than strict.
assert_same(
	array(),
	diviops_frozen_moved( $frozen_baseline, $frozen_main . "\n// a new admin renderer lands here\n", $frozen_meta, 'diviops-agent' ),
	'editing the file outside the frozen surface moves nothing'
);

/* -------------------------------------------------------------------------
 * Reading a real base ref, against throwaway git repositories.
 *
 * The half that can silently inspect nothing is this one: a base ref that does not
 * resolve hands back nulls, and a reader that always failed would skip forever while
 * printing a reason that looks deliberate. Driving it against repositories built here
 * is what proves it reads git at all.
 * ---------------------------------------------------------------------- */

$frozen_statuses = array();
$frozen_tmp      = sys_get_temp_dir() . '/diviops-frozen-surface-test-' . getmypid();

/**
 * Recursively delete a directory tree.
 *
 * @param string $dir Directory to remove.
 */
function diviops_frozen_rmtree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			diviops_frozen_rmtree( $path );
		} else {
			unlink( $path );
		}
	}
	rmdir( $dir );
}

/**
 * Run a command inside a fixture repository, asserting it succeeded.
 *
 * @param string $dir     Working directory.
 * @param string $command Command to run.
 */
function diviops_frozen_run( string $dir, string $command ): void {
	$out    = array();
	$status = 0;
	exec( sprintf( 'cd %s && %s 2>&1', escapeshellarg( $dir ), $command ), $out, $status );
	assert_same( 0, $status, 'fixture setup command succeeded (' . $command . '): ' . implode( "\n", $out ) );
}

/**
 * Write a file inside a fixture repository, creating its directory.
 *
 * @param string $dir      Repository root.
 * @param string $relative Repository-relative path.
 * @param string $contents File contents.
 */
function diviops_frozen_write( string $dir, string $relative, string $contents ): void {
	$path = $dir . '/' . $relative;
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0700, true );
	}
	file_put_contents( $path, $contents );
}

/**
 * Build a throwaway repository carrying the two frozen-surface files.
 *
 * The base ref is created with `update-ref` rather than by adding a remote, so the
 * fixture never reaches the network. What the gate reads is a ref name, and a local
 * ref under refs/remotes/ is indistinguishable from a fetched one.
 *
 * @param string $dir       Directory to initialise.
 * @param string $main      Contents of the main plugin file.
 * @param string $meta      Contents of the meta trait.
 * @param bool   $with_base Whether to create the base ref at all.
 */
function diviops_frozen_repo( string $dir, string $main, string $meta, bool $with_base ): void {
	mkdir( $dir, 0700, true );
	diviops_frozen_run( $dir, 'git init -q -b main' );
	diviops_frozen_run( $dir, 'git config user.email fixture@example.invalid' );
	diviops_frozen_run( $dir, 'git config user.name Fixture' );
	diviops_frozen_run( $dir, 'git config commit.gpgsign false' );

	diviops_frozen_write( $dir, DIVIOPS_FROZEN_MAIN_PATH, $main );
	diviops_frozen_write( $dir, DIVIOPS_FROZEN_META_PATH, $meta );
	diviops_frozen_run( $dir, 'git add -A' );
	diviops_frozen_run( $dir, 'git commit -q -m base' );

	if ( $with_base ) {
		diviops_frozen_run( $dir, 'git update-ref refs/remotes/origin/main HEAD' );
	}
}

diviops_frozen_rmtree( $frozen_tmp );
mkdir( $frozen_tmp, 0700, true );
assert_true( is_dir( $frozen_tmp ), 'a temporary fixture directory is available' );

// No base ref: the shallow-clone case, and the one that must never look like a pass.
$frozen_repo_nobase = $frozen_tmp . '/no-base';
diviops_frozen_repo( $frozen_repo_nobase, $frozen_main, $frozen_meta, false );
$frozen_report     = diviops_frozen_surface_report( $frozen_repo_nobase, 'origin/main' );
$frozen_statuses[] = $frozen_report['status'];
assert_same( 'no-base', $frozen_report['status'], 'an unresolvable base ref reports no-base, not unchanged' );
assert_same( 0, $frozen_report['inspected'], 'a skipped comparison reports that it inspected nothing' );
assert_true(
	false !== strpos( $frozen_report['reason'], 'origin/main' ),
	'the no-base skip names the ref it looked for: ' . $frozen_report['reason']
);

// A base ref, and an edit that leaves the frozen surface alone.
$frozen_repo_ok = $frozen_tmp . '/unchanged';
diviops_frozen_repo( $frozen_repo_ok, $frozen_main, $frozen_meta, true );
diviops_frozen_write( $frozen_repo_ok, DIVIOPS_FROZEN_MAIN_PATH, $frozen_main . "\n// an admin rewrite lands here\n" );
$frozen_report     = diviops_frozen_surface_report( $frozen_repo_ok, 'origin/main' );
$frozen_statuses[] = $frozen_report['status'];
assert_same( 'unchanged', $frozen_report['status'], 'an edit outside the frozen surface compares unchanged: ' . $frozen_report['reason'] );
assert_same( 7, $frozen_report['inspected'], 'the unchanged verdict says how many extracts it compared' );

// The same repository with the REST namespace renamed in the working tree. Working-tree
// state counts: the point is to catch the mistake while it is still being made.
diviops_frozen_write( $frozen_repo_ok, DIVIOPS_FROZEN_MAIN_PATH, str_replace( "'diviops/v1'", "'diviops/v2'", $frozen_main ) );
$frozen_report     = diviops_frozen_surface_report( $frozen_repo_ok, 'origin/main' );
$frozen_statuses[] = $frozen_report['status'];
assert_same( 'changed', $frozen_report['status'], 'renaming the REST namespace in the working tree is caught' );
assert_same( array( 'rest_namespace' ), $frozen_report['differences'], 'the report names the extract that moved, and only it' );
assert_true(
	false !== strpos( $frozen_report['reason'], 'rest_namespace' ),
	'the failure reason names the extract, so it is actionable without further digging: ' . $frozen_report['reason']
);

// ── The capabilities extract, which is NOT frozen byte-for-byte ──────────
//
// #38 phase 1 was the first change since #451 to add a capability key, and it
// found that byte identity forbids every addition with no waiver. These three
// fixtures pin the replacement rule in both directions, so "additions are fine"
// can never quietly become "capability drift is fine".
//
// Each fixture rewrites the working tree of a repository whose base ref still
// carries $frozen_main, so what is compared is a real base blob against a real
// working tree — the same path the live check below takes.

$frozen_repo_caps = $frozen_tmp . '/capabilities';
diviops_frozen_repo( $frozen_repo_caps, $frozen_main, $frozen_meta, true );

// (1) Appending a key. Pro gates on the keys it knows; one it has never heard of
// is inert to it, so this must pass.
diviops_frozen_write(
	$frozen_repo_caps,
	DIVIOPS_FROZEN_MAIN_PATH,
	str_replace( "\t\t'page_get',", "\t\t'page_get',\n\t\t// bulk\n\t\t'content_search',", $frozen_main )
);
$frozen_report     = diviops_frozen_surface_report( $frozen_repo_caps, 'origin/main' );
$frozen_statuses[] = $frozen_report['status'];
assert_same( 'unchanged', $frozen_report['status'], 'adding a capability key is not frozen-surface drift: ' . $frozen_report['reason'] );

// The fixture has to actually differ byte-for-byte, or (1) proves nothing: it would
// pass under the old rule too, and this whole block would be theatre.
assert_true(
	diviops_frozen_surface(
		(string) file_get_contents( $frozen_repo_caps . '/' . DIVIOPS_FROZEN_MAIN_PATH ),
		$frozen_meta,
		'diviops-agent'
	)['capabilities'] !== $frozen_baseline['capabilities'],
	'the added-key fixture does change the extract byte-for-byte, so its pass comes from the rule and not from the fixture being a no-op'
);

// (2) Removing a key. This is the failure the gate exists for: a capability key
// that disappears removes a Pro capability and vanishes an MCP tool silently.
diviops_frozen_write(
	$frozen_repo_caps,
	DIVIOPS_FROZEN_MAIN_PATH,
	str_replace( "\t\t'page_get',\n", '', $frozen_main )
);
$frozen_report     = diviops_frozen_surface_report( $frozen_repo_caps, 'origin/main' );
$frozen_statuses[] = $frozen_report['status'];
assert_same( 'changed', $frozen_report['status'], 'removing a capability key is caught' );
assert_same( array( 'capabilities' ), $frozen_report['differences'], 'and it is reported against the capabilities extract, and only it' );

// (3) Reordering the existing keys. Nothing is missing, so a set comparison would
// pass this; the order is part of the handshake surface #451 pinned.
diviops_frozen_write(
	$frozen_repo_caps,
	DIVIOPS_FROZEN_MAIN_PATH,
	str_replace(
		"\t\t'canvas_create', 'canvas_delete',\n\t\t'page_get',",
		"\t\t'canvas_delete', 'canvas_create',\n\t\t'page_get',",
		$frozen_main
	)
);
$frozen_report     = diviops_frozen_surface_report( $frozen_repo_caps, 'origin/main' );
$frozen_statuses[] = $frozen_report['status'];
assert_same( 'changed', $frozen_report['status'], 'reordering capability keys is caught, which a set comparison would not be' );

// The key reader itself, driven directly: a group-label comment carrying a quoted
// word must not register as a key. Without this, (1) could pass because the reader
// silently found nothing on both sides and compared two empty lists.
assert_same(
	array( 'canvas_create', 'canvas_delete', 'page_get' ),
	diviops_frozen_capability_keys( $frozen_baseline['capabilities'] ),
	'the capability reader returns the declared keys in order'
);
assert_same(
	array( 'a', 'b' ),
	diviops_frozen_capability_keys( "const CAPABILITIES = [\n\t// see 'notes' below\n\t'a', 'b',\n];" ),
	"a quoted word inside a line comment is not read as a capability key"
);
assert_same(
	null,
	diviops_frozen_capability_reason( array( 'a', 'b' ), array( 'a', 'x', 'b', 'y' ) ),
	'new keys interleaved among the base keys are allowed, as long as the base order survives'
);
assert_true(
	null !== diviops_frozen_capability_reason( array( 'a', 'b' ), array( 'b', 'a' ) ),
	'a swapped pair is reported as reordered'
);
assert_true(
	null !== diviops_frozen_capability_reason( array( 'a', 'b' ), array( 'a' ) ),
	'a dropped key is reported as removed'
);

// Deleting the whole plugin file is divergence too, and every extract should say so
// rather than the reader quietly handing back an empty string that compares equal.
unlink( $frozen_repo_ok . '/' . DIVIOPS_FROZEN_MAIN_PATH );
$frozen_report = diviops_frozen_surface_report( $frozen_repo_ok, 'origin/main' );
assert_same( 'changed', $frozen_report['status'], 'deleting the plugin file is caught' );
assert_same(
	array( 'class_declaration', 'rest_namespace', 'version_header_marker', 'version_const_marker', 'capabilities' ),
	$frozen_report['differences'],
	'every extract the deleted file carried is reported missing; the slug and the filter live elsewhere'
);

// The base blob is read exactly. exec() strips trailing whitespace from every line,
// so a reader built on it would report this file as unchanged from one carrying no
// trailing space at all — and would equally miss a real one-character edit.
$frozen_repo_bytes = $frozen_tmp . '/bytes';
diviops_frozen_repo( $frozen_repo_bytes, "const REST_NAMESPACE = 'x';   \n", "trailing\t\n", true );
assert_same(
	"const REST_NAMESPACE = 'x';   \n",
	diviops_frozen_blob( $frozen_repo_bytes, 'origin/main', DIVIOPS_FROZEN_MAIN_PATH ),
	'the base blob reader preserves trailing whitespace, which exec() would have eaten'
);
assert_same(
	null,
	diviops_frozen_blob( $frozen_repo_bytes, 'origin/main', 'plugins/diviops-agent/not-a-file.php' ),
	'a path that does not exist at the base ref reads as null, not as an empty file'
);

diviops_frozen_rmtree( $frozen_tmp );

// Coverage assertion. Every status the report can produce was reached by a fixture,
// so none of them — least of all the skip — is theory nobody executes.
$frozen_expected = array( 'changed', 'no-base', 'unchanged' );
$frozen_seen     = array_values( array_unique( $frozen_statuses ) );
sort( $frozen_seen );
assert_same( $frozen_expected, $frozen_seen, 'every reachable status was actually exercised, including the skip path' );

/* -------------------------------------------------------------------------
 * The live check: does THIS change move the frozen surface?
 * ---------------------------------------------------------------------- */

$frozen_root = dirname( __DIR__ );
$frozen_here = diviops_frozen_surface(
	(string) file_get_contents( $frozen_root . '/' . DIVIOPS_FROZEN_MAIN_PATH ),
	(string) file_get_contents( $frozen_root . '/' . DIVIOPS_FROZEN_META_PATH ),
	basename( dirname( $frozen_root . '/' . DIVIOPS_FROZEN_MAIN_PATH ) )
);

// Asserted unconditionally, before any comparison and regardless of whether the base
// ref resolves. A checkout whose frozen surface has gone unreadable must fail here
// rather than skip: an extractor matching nothing is the shape that lets this gate
// pass while inspecting nothing.
foreach ( $frozen_here as $frozen_name => $frozen_value ) {
	assert_true(
		'' !== $frozen_value,
		"{$frozen_name} is present in this checkout's plugin source"
	);
}

$frozen_live = diviops_frozen_surface_report( $frozen_root, diviops_fork_ledger_base_ref() );

assert_true( '' !== $frozen_live['reason'], 'the live frozen-surface check states what it found, whether it compared or skipped' );

if ( 'no-base' === $frozen_live['status'] ) {
	printf( "SKIP  frozen-surface drift check: %s%s", $frozen_live['reason'], PHP_EOL );
	assert_same( 0, $frozen_live['inspected'], 'a skipped comparison claims to have inspected nothing, so a green run cannot be mistaken for one that compared' );
} else {
	assert_same( 7, $frozen_live['inspected'], 'the live check compared all seven extracts, not a subset' );
	assert_same(
		'unchanged',
		$frozen_live['status'],
		'this change leaves the frozen surface byte-identical to the base ref. ' . $frozen_live['reason']
	);
}
