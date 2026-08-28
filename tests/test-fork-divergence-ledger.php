<?php
// SPDX-License-Identifier: MIT
/**
 * FORK.md divergence-ledger gate (#297).
 *
 * `CLAUDE.md` requires that intentional changes to upstream-origin files be recorded
 * in `FORK.md`'s divergence table, and nothing enforced it. Two changes on
 * 2026-08-28 merged without a row (#288 via PR #290, #291 via PR #293) and a third
 * the same morning (#215 via PR #294) did carry one, so the rule was holding roughly
 * half the time — which is what makes it worth automating rather than remembering
 * harder.
 *
 * The ledger is the cherry-pick reconciliation aid for an upstream sync: when
 * upstream touches a file this fork has diverged on, the table is what says how and
 * why. A missing row costs whoever runs that sync, long after the context is gone.
 *
 * The trap this repository has been bitten by three times is a gate that reports
 * what it inspected but derives pass/fail only from problems-found — it passes while
 * inspecting nothing. A diff-based gate is exactly that shape: a shallow clone, a
 * detached checkout, or a renamed base ref all produce an empty changed-file list
 * that looks identical to a clean change. Two things keep this honest, mirroring
 * tests/test-local-site-drift.php:
 *
 *   1. Every status the gate can reach is exercised against synthetic fixtures —
 *      including a real throwaway git repository with no base ref, which is what
 *      proves the skip path is reachable rather than dead code nobody ever runs.
 *   2. The live half prints a loud SKIP with a stated reason when no base ref
 *      resolves, and asserts that reason exists. "I diffed and found nothing wrong"
 *      and "I could not determine what changed" never render the same.
 *
 * @package DiviOps
 */

require_once dirname( __DIR__ ) . '/scripts/lib/fork-divergence.php';

$root = dirname( __DIR__ );

/* -------------------------------------------------------------------------
 * The verdict, over synthetic change maps.
 *
 * The map is path => "did this path exist at the base ref". A path that did not
 * exist at the base is a brand-new file; see diviops_fork_ledger_verdict() for why
 * new files are exempt and modifications are not.
 * ---------------------------------------------------------------------- */

$statuses_seen = array();

// Nothing changed. A resolvable base that names no changed paths is the shape of a
// run on main itself, and it must skip rather than report a clean inspection of an
// empty set.
$report          = diviops_fork_ledger_verdict( array() );
$statuses_seen[] = $report['status'];
assert_same( 'no-changes', $report['status'], 'a resolved diff naming no paths reports no-changes, not clean' );
assert_true( '' !== $report['reason'], 'the no-changes skip states a reason' );

// A tests-only change does not trip the gate.
$report          = diviops_fork_ledger_verdict(
	array(
		'tests/test-fork-divergence-ledger.php' => false,
		'tests/run.php'                         => true,
	)
);
$statuses_seen[] = $report['status'];
assert_same( 'clean', $report['status'], 'a tests-only change touches no upstream-origin file' );
assert_same( array(), $report['undocumented'], 'a clean verdict names no offending files' );

// A docs-only change does not trip the gate either.
assert_same(
	'clean',
	diviops_fork_ledger_verdict( array( 'README.md' => true, 'SETUP.md' => true ) )['status'],
	'a docs-only change touches no upstream-origin file'
);

// Modifying a plugin file with no FORK.md update is the failure this gate exists for.
$report          = diviops_fork_ledger_verdict(
	array(
		'plugins/diviops-agent/includes/trait-validate.php' => true,
		'tests/test-validate-parser-level-blocks.php'       => true,
	)
);
$statuses_seen[] = $report['status'];
assert_same( 'undocumented', $report['status'], 'modifying a plugin file without touching FORK.md is undocumented divergence' );
assert_same(
	array( 'plugins/diviops-agent/includes/trait-validate.php' ),
	$report['undocumented'],
	'the verdict names the offending file, and only it'
);
assert_true(
	false !== strpos( $report['reason'], 'trait-validate.php' ),
	'the failure reason names the offending file, so it is actionable without further digging: ' . $report['reason']
);

// The MCP server half of the guarded surface, same rule.
assert_same(
	array( 'diviops-server/src/wp-client.ts' ),
	diviops_fork_ledger_verdict( array( 'diviops-server/src/wp-client.ts' => true ) )['undocumented'],
	'diviops-server/src is guarded alongside the plugin'
);

// Touching FORK.md in the same change passes.
$report          = diviops_fork_ledger_verdict(
	array(
		'plugins/diviops-agent/includes/trait-validate.php' => true,
		'FORK.md' => true,
	)
);
$statuses_seen[] = $report['status'];
assert_same( 'documented', $report['status'], 'a plugin change carrying a FORK.md update passes' );
assert_same( array(), $report['undocumented'], 'a documented verdict names no offending files' );

// A brand-new file under a guarded path needs no row. See the function's docblock:
// the table is a cherry-pick reconciliation aid, and a file upstream does not have
// cannot conflict with an upstream change.
assert_same(
	'clean',
	diviops_fork_ledger_verdict( array( 'diviops-server/src/__tests__/new-thing.test.ts' => false ) )['status'],
	'a file that did not exist at the base is new, and a new file needs no reconciliation row'
);

// Deleting an upstream-origin file is divergence too — the path existed at the base
// and no longer does, which is exactly what a future cherry-pick collides with.
assert_same(
	array( 'plugins/diviops-agent/includes/trait-seo.php' ),
	diviops_fork_ledger_verdict( array( 'plugins/diviops-agent/includes/trait-seo.php' => true ) )['undocumented'],
	'removing a file that existed at the base still requires a row'
);

// Neighbouring paths that merely start with a guarded prefix's leading segment are
// not guarded. `plugins/diviops-design-library/` is a separate plugin and outside
// what #297 scoped.
assert_same(
	'clean',
	diviops_fork_ledger_verdict( array( 'plugins/diviops-design-library/diviops-design-library.php' => true ) )['status'],
	'only the two paths #297 names are guarded'
);
assert_same(
	'clean',
	diviops_fork_ledger_verdict( array( 'diviops-server/scripts/smoke.mjs' => true ) )['status'],
	'diviops-server outside src/ is not guarded'
);

/* -------------------------------------------------------------------------
 * Resolving a real diff, against a throwaway git repository.
 *
 * The verdict above is pure and easy to trust. The half that can silently inspect
 * nothing is this one — a base ref that does not resolve, or a diff command that
 * fails, both hand back an empty list. Driving it against a real repository built
 * here is what proves it reads git rather than always returning nothing.
 * ---------------------------------------------------------------------- */

$tmp = sys_get_temp_dir() . '/diviops-fork-ledger-test-' . getmypid();
diviops_fork_ledger_test_rmtree( $tmp );
mkdir( $tmp, 0700, true );
assert_true( is_dir( $tmp ), 'a temporary fixture directory is available' );

/**
 * Recursively delete a directory tree.
 *
 * @param string $dir Directory to remove.
 */
function diviops_fork_ledger_test_rmtree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			diviops_fork_ledger_test_rmtree( $path );
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
function diviops_fork_ledger_test_run( string $dir, string $command ): void {
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
function diviops_fork_ledger_test_write( string $dir, string $relative, string $contents ): void {
	$path = $dir . '/' . $relative;
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0700, true );
	}
	file_put_contents( $path, $contents );
}

/**
 * Build a throwaway git repository carrying a base commit and a base ref.
 *
 * The base ref is created with `update-ref` rather than by adding a remote, because
 * the fixture must not reach the network. What the gate reads is a ref name, and a
 * local ref under refs/remotes/ is indistinguishable from a fetched one.
 *
 * @param string $dir       Directory to initialise.
 * @param bool   $with_base Whether to create the base ref at all.
 */
function diviops_fork_ledger_test_repo( string $dir, bool $with_base ): void {
	mkdir( $dir, 0700, true );
	diviops_fork_ledger_test_run( $dir, 'git init -q -b main' );
	diviops_fork_ledger_test_run( $dir, 'git config user.email fixture@example.invalid' );
	diviops_fork_ledger_test_run( $dir, 'git config user.name Fixture' );
	diviops_fork_ledger_test_run( $dir, 'git config commit.gpgsign false' );

	diviops_fork_ledger_test_write( $dir, 'FORK.md', "# Fork\n" );
	diviops_fork_ledger_test_write( $dir, 'plugins/diviops-agent/includes/trait-page.php', "<?php\n// base\n" );
	diviops_fork_ledger_test_run( $dir, 'git add -A' );
	diviops_fork_ledger_test_run( $dir, 'git commit -q -m base' );

	if ( $with_base ) {
		diviops_fork_ledger_test_run( $dir, 'git update-ref refs/remotes/origin/main HEAD' );
	}
}

// A repository with no base ref: the CI-shallow-clone case, and the one that must
// never look like a pass.
$repo_nobase = $tmp . '/no-base';
diviops_fork_ledger_test_repo( $repo_nobase, false );
diviops_fork_ledger_test_write( $repo_nobase, 'plugins/diviops-agent/includes/trait-page.php', "<?php\n// edited\n" );
$report          = diviops_fork_ledger_report( $repo_nobase, 'origin/main' );
$statuses_seen[] = $report['status'];
assert_same( 'no-base', $report['status'], 'an unresolvable base ref reports no-base, not clean' );
assert_true(
	false !== strpos( $report['reason'], 'origin/main' ),
	'the no-base skip names the ref it looked for: ' . $report['reason']
);
assert_same( array(), $report['undocumented'], 'a skipped gate accuses nobody' );

// The same repository with a base ref, and an uncommitted edit to a guarded file.
// Working-tree changes count: the point is to catch the omission while the change is
// still being made, not only once it is committed.
$repo_dirty = $tmp . '/dirty';
diviops_fork_ledger_test_repo( $repo_dirty, true );
diviops_fork_ledger_test_write( $repo_dirty, 'plugins/diviops-agent/includes/trait-page.php', "<?php\n// edited\n" );
$report          = diviops_fork_ledger_report( $repo_dirty, 'origin/main' );
$statuses_seen[] = $report['status'];
assert_same( 'undocumented', $report['status'], 'a real uncommitted edit to a guarded file is caught' );
assert_same(
	array( 'plugins/diviops-agent/includes/trait-page.php' ),
	$report['undocumented'],
	'the report names the file git actually reported as changed'
);

// Committing the edit does not change the verdict: the gate diffs against the base
// ref, not against HEAD alone.
diviops_fork_ledger_test_run( $repo_dirty, 'git checkout -q -b dev/1-fixture' );
diviops_fork_ledger_test_run( $repo_dirty, 'git commit -q -am edit' );
assert_same(
	'undocumented',
	diviops_fork_ledger_report( $repo_dirty, 'origin/main' )['status'],
	'a committed edit on a branch is still measured against the base ref'
);

// Adding the ledger row clears it.
diviops_fork_ledger_test_write( $repo_dirty, 'FORK.md', "# Fork\n\n| trait-page.php | why |\n" );
$report = diviops_fork_ledger_report( $repo_dirty, 'origin/main' );
assert_same( 'documented', $report['status'], 'recording the divergence in FORK.md clears the gate' );

// A brand-new file under a guarded path, against a real repository rather than a
// hand-built map — this is the case the base-existence lookup has to get right.
$repo_new = $tmp . '/new-file';
diviops_fork_ledger_test_repo( $repo_new, true );
diviops_fork_ledger_test_write( $repo_new, 'diviops-server/src/brand-new.ts', "export const x = 1;\n" );
diviops_fork_ledger_test_run( $repo_new, 'git add -A' );
diviops_fork_ledger_test_run( $repo_new, 'git commit -q -m new' );
assert_same(
	'clean',
	diviops_fork_ledger_report( $repo_new, 'origin/main' )['status'],
	'a file absent from the base ref is new, and passes without a row'
);

// Sitting on the base ref with nothing changed: the run-on-main case.
$repo_main = $tmp . '/on-main';
diviops_fork_ledger_test_repo( $repo_main, true );
assert_same(
	'no-changes',
	diviops_fork_ledger_report( $repo_main, 'origin/main' )['status'],
	'a checkout sitting on the base ref with a clean tree reports no-changes, and does not fail'
);

diviops_fork_ledger_test_rmtree( $tmp );

// Coverage assertion. Every status this gate can produce must have been reached by a
// fixture, so no branch of it — least of all the skips — is theory nobody executes.
$expected_statuses = array( 'no-base', 'no-changes', 'clean', 'documented', 'undocumented' );
sort( $expected_statuses );
$seen = array_values( array_unique( $statuses_seen ) );
sort( $seen );
assert_same( $expected_statuses, $seen, 'every reachable status was actually exercised, including the skip paths' );

/* -------------------------------------------------------------------------
 * The live check: does THIS change record its divergence?
 *
 * Skips loudly with a stated reason when no base ref resolves, which is any shallow
 * or detached checkout. Fails naming the files when a guarded file changed without
 * FORK.md.
 * ---------------------------------------------------------------------- */

$live = diviops_fork_ledger_report( $root, diviops_fork_ledger_base_ref() );

assert_true( '' !== $live['reason'], 'the live ledger check states what it found, whether it checked or skipped' );

if ( 'clean' === $live['status'] || 'documented' === $live['status'] || 'undocumented' === $live['status'] ) {
	assert_true(
		'undocumented' !== $live['status'],
		'every changed upstream-origin file is recorded in FORK.md\'s divergence table. ' . $live['reason']
	);
} else {
	printf( "SKIP  FORK.md divergence check: %s%s", $live['reason'], PHP_EOL );
	assert_true(
		in_array( $live['status'], array( 'no-base', 'no-changes' ), true ),
		'a skipped ledger check skipped for a known reason, not an unrecognised one'
	);
}
