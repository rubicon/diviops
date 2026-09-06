<?php
// SPDX-License-Identifier: MIT
/**
 * Local dev-site deploy + drift guard (#292).
 *
 * The dev site runs a COPY of the plugin, not a symlink, so cutting a release does
 * not reach it and nothing reports that it is behind. On 2026-08-28 v1.19.2 shipped
 * the #288 validator fix while the site still ran 1.19.1, and a session building
 * pages kept hitting the exact false positives that release had fixed. A stale
 * install is silent — it looks identical to a current one until you diff the files.
 *
 * `scripts/deploy-local-site.sh` fixes the deploy. This file is the part that
 * matters: a deploy step someone can forget reproduces the bug, so the check has to
 * fire on its own.
 *
 * The trap this repository has been bitten by three times is a gate that reports
 * what it inspected but derives pass/fail only from problems-found — it passes while
 * inspecting nothing. CI has no local site, so the live half of this check is
 * unreachable there and MUST NOT silently look like a pass. Two things keep that
 * honest:
 *
 *   1. Every status this check can reach is exercised against synthetic fixtures
 *      built in a temp directory, so the suite makes real assertions on every
 *      machine including CI. That is what proves the skip path is reachable at all
 *      rather than being dead code nobody ever runs.
 *   2. The live half prints a loud SKIP with a stated reason when no site is
 *      configured, and asserts that the reason exists. "I checked and the site is
 *      current" and "I could not find a site to check" never render the same.
 *
 * @package DiviOps
 */

require_once dirname( __DIR__ ) . '/scripts/lib/local-site.php';

$root      = dirname( __DIR__ );
$repo_main = $root . '/plugins/diviops-agent/diviops-agent.php';

assert_true( is_file( $repo_main ), 'the repository plugin main file is where this check expects it' );

$repo_version = diviops_plugin_version( $repo_main );
assert_true(
	is_string( $repo_version ) && '' !== $repo_version,
	'the repository plugin declares a const VERSION this check can compare against'
);

/* -------------------------------------------------------------------------
 * Synthetic fixtures. Never the real site: a build may be running against it,
 * and a test has no business writing there.
 * ---------------------------------------------------------------------- */

$tmp = sys_get_temp_dir() . '/diviops-local-site-test-' . getmypid();
diviops_drift_test_rmtree( $tmp );
mkdir( $tmp, 0700, true );
assert_true( is_dir( $tmp ), 'a temporary fixture directory is available' );

/**
 * Recursively delete a directory tree.
 *
 * @param string $dir Directory to remove.
 */
function diviops_drift_test_rmtree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			diviops_drift_test_rmtree( $path );
		} else {
			unlink( $path );
		}
	}
	rmdir( $dir );
}

/**
 * Build a synthetic WordPress root carrying an installed diviops-agent at a version.
 *
 * @param string      $wp_root Directory to create as the WordPress root.
 * @param string|null $version Version to declare, or null to write no plugin file.
 * @return string The plugin directory that was created.
 */
function diviops_drift_test_site( string $wp_root, ?string $version ): string {
	$plugin_dir = $wp_root . '/wp-content/plugins/diviops-agent';
	mkdir( $plugin_dir, 0700, true );
	if ( null !== $version ) {
		file_put_contents(
			$plugin_dir . '/diviops-agent.php',
			"<?php\n/**\n * Plugin Name: DiviOps Agent\n * Version: " . $version . "\n */\nclass DiviOps_Agent {\n\tconst VERSION = '" . $version . "';\n}\n"
		);
	}
	return $plugin_dir;
}

/**
 * Build a synthetic WordPress root carrying a real copy of the repository plugin.
 *
 * A hand-written one-file stub declaring the right version is not "in sync" once the
 * check compares trees, so anything asserting `current` has to install the actual
 * plugin the repository ships.
 *
 * @param string $wp_root Directory to create as the WordPress root.
 * @param string $src     Repository plugin directory to copy.
 * @return string The plugin directory that was created.
 */
function diviops_drift_test_clone( string $wp_root, string $src ): string {
	$plugin_dir = $wp_root . '/wp-content/plugins/diviops-agent';
	mkdir( $plugin_dir, 0700, true );
	$out    = array();
	$status = 0;
	exec(
		sprintf( 'cp -a %s/. %s/', escapeshellarg( rtrim( $src, '/' ) ), escapeshellarg( $plugin_dir ) ),
		$out,
		$status
	);
	assert_same( 0, $status, 'the fixture copied the repository plugin: ' . implode( "\n", $out ) );
	return $plugin_dir;
}

/**
 * Write a CLAUDE.md-shaped file declaring (or omitting) a local site path.
 *
 * @param string      $path    File to write.
 * @param string|null $wp_root Path to declare, or null to omit the line entirely.
 */
function diviops_drift_test_claude_md( string $path, ?string $wp_root ): void {
	$body = "# Agent Instructions\n\n## Development environment\n\n";
	if ( null !== $wp_root ) {
		$body .= 'Local site: `' . $wp_root . "`\n";
	} else {
		$body .= "There is no local site on this machine.\n";
	}
	file_put_contents( $path, $body );
}

$statuses_seen = array();

// 1. Nothing configured anywhere: no env var, no path in CLAUDE.md.
$md_none = $tmp . '/CLAUDE-none.md';
diviops_drift_test_claude_md( $md_none, null );
$report          = diviops_local_site_report( null, $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'unconfigured', $report['status'], 'no env var and no path in CLAUDE.md reports unconfigured, not current' );
assert_true( '' !== $report['reason'], 'the unconfigured skip states a reason' );
assert_same( null, $report['plugin_dir'], 'an unconfigured report names no plugin directory' );

// 2. Configured but not present on this machine — the CI case.
$report          = diviops_local_site_report( $tmp . '/no-such-site', $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'absent', $report['status'], 'a configured target that does not exist reports absent, not current' );
assert_true(
	false !== strpos( $report['reason'], $tmp . '/no-such-site' ),
	'the absent skip names the path it looked for'
);

// 3. Present but not a DiviOps plugin directory.
$root_invalid = $tmp . '/invalid';
diviops_drift_test_site( $root_invalid, null );
$report          = diviops_local_site_report( $root_invalid, $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'invalid', $report['status'], 'a directory with no diviops-agent.php is invalid, not merely absent' );

// 4. Present and behind — the bug this issue exists for.
$root_stale = $tmp . '/stale';
diviops_drift_test_site( $root_stale, '0.0.1' );
$report          = diviops_local_site_report( $root_stale, $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'drift', $report['status'], 'an installed version below the repository version reports drift' );
assert_same( '0.0.1', $report['installed_version'], 'the report reads the installed version off disk' );
assert_same( $repo_version, $report['repo_version'], 'the report carries the repository version' );
assert_true(
	false !== strpos( $report['reason'], '0.0.1' ) && false !== strpos( $report['reason'], (string) $repo_version ),
	'the drift reason names BOTH version numbers, so the failure is actionable without further digging'
);

// 5. Present and current: the same version AND the same files.
$repo_plugin_dir = dirname( $repo_main );
$root_current    = $tmp . '/current';
$dir_current     = diviops_drift_test_clone( $root_current, $repo_plugin_dir );
$report          = diviops_local_site_report( $root_current, $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'current', $report['status'], 'an installed tree identical to the repository reports current' );

// 5a. Same version, changed file. This is the case the version-only check missed:
// every change merged to main since the last version bump looks like this, so the
// gate was reporting "current" over a site missing merged fixes (#307).
$root_edited = $tmp . '/edited';
$dir_edited  = diviops_drift_test_clone( $root_edited, $repo_plugin_dir );
file_put_contents( $dir_edited . '/diviops-agent.php', "\n// stale install, predating a merged fix\n", FILE_APPEND );
assert_same(
	$repo_version,
	diviops_plugin_version( $dir_edited . '/diviops-agent.php' ),
	'the edited fixture still declares the repository version, so only content can distinguish it'
);
$report          = diviops_local_site_report( $root_edited, $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'drift', $report['status'], 'a matching version over differing file content still reports drift' );
assert_same( $repo_version, $report['installed_version'], 'the content-drift report carries the installed version' );
assert_true(
	false !== strpos( $report['reason'], 'diviops-agent.php' ),
	'the content-drift reason names a differing path, not just that something differs: ' . $report['reason']
);

// 5c. Same version, same bytes, different mtimes. `git checkout` stamps every file
// with checkout time, so a fresh worktree or a CI clone differs from an installed
// copy in mtime on every single file while the code is byte-identical. A check that
// counts those as drift fires constantly and gets ignored, which is a worse gate
// than the version-only one it replaced (#307).
$root_touched = $tmp . '/touched';
$dir_touched  = diviops_drift_test_clone( $root_touched, $repo_plugin_dir );
foreach ( glob( $dir_touched . '/*' ) ?: array() as $touch_path ) {
	touch( $touch_path, 315532800 );
}
touch( $dir_touched, 315532800 );
$report = diviops_local_site_report( $root_touched, $md_none, $repo_main );
assert_same(
	'current',
	$report['status'],
	'identical bytes with different mtimes is current, not drift: ' . $report['reason']
);

// 5b. Same version, an extra file the repository does not ship. The deploy would
// delete it, so the check has to see it as drift too.
$root_extra = $tmp . '/extra';
$dir_extra  = diviops_drift_test_clone( $root_extra, $repo_plugin_dir );
file_put_contents( $dir_extra . '/leftover-from-an-older-install.php', "<?php\n" );
$report          = diviops_local_site_report( $root_extra, $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'drift', $report['status'], 'a file present on the site but not in the repository reports drift' );
assert_true(
	false !== strpos( $report['reason'], 'leftover-from-an-older-install.php' ),
	'the drift reason names the extra path: ' . $report['reason']
);

/**
 * Ask scripts/lib/local-site.php for one field about a synthetic WordPress root.
 *
 * `scripts/deploy-local-site.sh` reads this CLI, and it reads the exit status as well
 * as the output, so the exit status is part of the contract this suite has to pin.
 *
 * @param string $lib     Path to scripts/lib/local-site.php.
 * @param string $field   Field to ask for, e.g. `diff`.
 * @param string $wp_root Synthetic WordPress root to point it at.
 * @return array{status: int, output: string}
 */
function diviops_drift_test_cli( string $lib, string $field, string $wp_root ): array {
	$cmd = sprintf(
		'DIVIOPS_LOCAL_SITE=%s %s %s %s 2>&1',
		escapeshellarg( $wp_root ),
		escapeshellarg( PHP_BINARY ),
		escapeshellarg( $lib ),
		escapeshellarg( $field )
	);
	$out    = array();
	$status = 0;
	exec( $cmd, $out, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $out ),
	);
}

// 5d. The comparison could not run at all (#312). An identical tree and a failed
// rsync both itemize nothing, so the exit status is the only thing separating "in
// sync" from "never compared". Collapsing the null into an empty array — the kind of
// simplification that reads as tidying — makes the deploy print "no change: the
// installed plugin already matches this repository", exit 0 having compared nothing,
// and leaves the suite green. This is the assertion that the gate inspected
// something.
assert_same(
	array(),
	diviops_local_site_file_diff( $repo_plugin_dir, $dir_current ),
	'an in-sync tree returns an empty array of changes'
);
assert_same(
	null,
	diviops_local_site_file_diff( $tmp . '/no-such-source', $dir_current ),
	'a comparison rsync could not run returns null, a different value from the empty in-sync result'
);

// The same failure through the report. The fixture is a plugin directory rsync
// cannot list: `--x` still resolves the known path inside it, so the installed
// version reads fine and the report gets past the version comparison to the file
// comparison, which is the branch under test. It relies on the suite not running as
// root, since root ignores the mode bits.
$root_unreadable = $tmp . '/unreadable';
$dir_unreadable  = diviops_drift_test_site( $root_unreadable, (string) $repo_version );
chmod( $dir_unreadable, 0100 );
clearstatcache( true );
assert_same(
	$repo_version,
	diviops_plugin_version( $dir_unreadable . '/diviops-agent.php' ),
	'the unreadable fixture still declares the repository version, so the report reaches the file comparison'
);
assert_same(
	null,
	diviops_local_site_file_diff( $repo_plugin_dir, $dir_unreadable ),
	'the fixture really does defeat rsync — without this the case below would pass having compared successfully'
);
$report          = diviops_local_site_report( $root_unreadable, $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same(
	'invalid',
	$report['status'],
	'a comparison that could not run reports invalid, never current: ' . $report['reason']
);
assert_true(
	false !== strpos( $report['reason'], 'could not compare' ),
	'the reason says the comparison did not run rather than describing a tree it never read: ' . $report['reason']
);

// The deploy gates on the `diff` subcommand: empty output means "nothing to do", and
// only `set -e` over a non-zero exit stops a failed comparison from taking that
// branch. Both halves of that are pinned here, because the two cases print the same
// nothing.
$lib = $root . '/scripts/lib/local-site.php';
$cli = diviops_drift_test_cli( $lib, 'diff', $root_unreadable );
assert_true(
	0 !== $cli['status'],
	'the diff subcommand exits non-zero when the comparison could not run: ' . $cli['output']
);
assert_true(
	false !== stripos( $cli['output'], 'could not compare' ),
	'the failed diff says why on stderr instead of exiting quietly: ' . $cli['output']
);

$cli = diviops_drift_test_cli( $lib, 'diff', $root_current );
assert_same( 0, $cli['status'], 'the diff subcommand exits zero for an in-sync site: ' . $cli['output'] );
assert_same(
	'',
	trim( $cli['output'] ),
	'an in-sync site prints nothing, which is exactly what a failed comparison prints too: ' . $cli['output']
);

chmod( $dir_unreadable, 0700 );

// 6. The CLAUDE.md fallback resolves when no env var is set.
$md_site = $tmp . '/CLAUDE-site.md';
diviops_drift_test_claude_md( $md_site, $root_current );
$report          = diviops_local_site_report( null, $md_site, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'current', $report['status'], 'with no env var the target falls back to the path in CLAUDE.md' );
assert_same( 'claude-md', $report['source'], 'the report says where the target came from' );

// The env var wins over CLAUDE.md.
$report = diviops_local_site_report( $root_stale, $md_site, $repo_main );
assert_same( 'env', $report['source'], 'DIVIOPS_LOCAL_SITE overrides the path in CLAUDE.md' );

/* -------------------------------------------------------------------------
 * 7. Targets reached over SSH (#340).
 *
 * The LocalWP site is retired and its directory survived on disk, so the gate
 * went on reporting `current` against an install nobody runs — which renders
 * identically to certifying the live one. staging.colleyvillelions.com is the
 * site that matters now, and it is only reachable over SSH.
 *
 * Every remote branch is exercised through a stub remote shell rather than a
 * real connection, so this half makes real assertions on a machine with no
 * network and no key, including CI. The stub is faithful to the only part of
 * ssh's contract this code depends on: drop the host argument, hand the rest
 * to a shell. That is what both the version probe and rsync's -e rely on.
 * ---------------------------------------------------------------------- */

/**
 * Write a stub standing in for `ssh`, running the "remote" command locally.
 *
 * It logs every invocation first. The fixtures it points at are ordinary local
 * directories, so code that ignored the host entirely and operated on the path
 * directly would produce exactly the same end state and pass — the log is what
 * separates "went over the transport" from "happened to work locally".
 *
 * @param string $path File to write.
 * @param string $log  File the stub appends its arguments to.
 * @return string The stub path.
 */
function diviops_drift_test_stub_shell( string $path, string $log ): string {
	file_put_contents(
		$path,
		"#!/bin/sh\necho \"\$@\" >> " . escapeshellarg( $log ) . "\nshift\nexec /bin/sh -c \"\$*\"\n"
	);
	chmod( $path, 0700 );
	return $path;
}

/**
 * Read and clear the stub remote shell's log.
 *
 * @param string $log Log file the stub appends to.
 * @return string Everything logged since the last call.
 */
function diviops_drift_test_stub_log( string $log ): string {
	$seen = is_file( $log ) ? (string) file_get_contents( $log ) : '';
	if ( is_file( $log ) ) {
		unlink( $log );
	}
	return $seen;
}

// Parsing: `[user@]host:/absolute/path` is a remote target, anything else is local.
$parsed = diviops_local_site_target( 'staging.example.com:/home/u/site', $md_none );
assert_same( 'staging.example.com', $parsed['host'], 'a host:path target names its host' );
assert_same(
	'/home/u/site/wp-content/plugins/diviops-agent',
	$parsed['plugin_dir'],
	'a remote WordPress root resolves to the plugin directory beneath it'
);
assert_same( 'env', $parsed['source'], 'a remote target from the env var says so' );

$parsed = diviops_local_site_target( 'deploy@host.example.com:/srv/wp/wp-content/plugins/diviops-agent', $md_none );
assert_same( 'deploy@host.example.com', $parsed['host'], 'a user@host prefix is part of the host, not the path' );
assert_same(
	'/srv/wp/wp-content/plugins/diviops-agent',
	$parsed['plugin_dir'],
	'a remote path already naming the plugin directory is taken as-is'
);

$parsed = diviops_local_site_target( $root_current, $md_none );
assert_same( null, $parsed['host'], 'a filesystem path resolves to no host, so the local path keeps working' );

$parsed = diviops_local_site_target( '/Users/dax/Local Sites/a:b/app/public', $md_none );
assert_same( null, $parsed['host'], 'an absolute path that happens to contain a colon is still local, not host:path' );

$stub_log = $tmp . '/stub-ssh.log';
$stub     = diviops_drift_test_stub_shell( $tmp . '/stub-ssh', $stub_log );
putenv( 'DIVIOPS_SSH=' . $stub );
diviops_drift_test_stub_log( $stub_log );

// The same four installed-plugin states, reached over the remote transport rather
// than the local one. `unreachable` has no local counterpart and comes after them.
$report          = diviops_local_site_report( 'fixture-host:' . $tmp . '/no-such-remote-site', $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'absent', $report['status'], 'a remote target with nothing installed reports absent, not current' );
assert_same( 'fixture-host', $report['host'], 'an absent remote report still names the host it asked' );

$report          = diviops_local_site_report( 'fixture-host:' . $root_invalid, $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'invalid', $report['status'], 'a remote directory with no diviops-agent.php is invalid, not absent' );

$report          = diviops_local_site_report( 'fixture-host:' . $root_stale, $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'drift', $report['status'], 'a remote install below the repository version reports drift' );
assert_same( '0.0.1', $report['installed_version'], 'the remote report reads the installed version off the host' );

$report          = diviops_local_site_report( 'fixture-host:' . $root_extra, $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'drift', $report['status'], 'a remote install at the same version with different files reports drift' );
assert_true(
	false !== strpos( $report['reason'], 'leftover-from-an-older-install.php' ),
	'the remote drift reason names the differing path: ' . $report['reason']
);

diviops_drift_test_stub_log( $stub_log );
$report          = diviops_local_site_report( 'fixture-host:' . $root_current, $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same( 'current', $report['status'], 'a remote install holding the same bytes reports current: ' . $report['reason'] );
assert_same( 'fixture-host', $report['host'], 'the current remote report names the host it inspected' );
$log = diviops_drift_test_stub_log( $stub_log );
assert_true(
	false !== strpos( $log, 'fixture-host' ),
	'the remote report reached the host through the transport rather than reading the path locally: ' . var_export( $log, true )
);
assert_true(
	false !== strpos( $log, 'rsync' ),
	'the remote comparison itself went over the transport, not just the version probe: ' . var_export( $log, true )
);

// The comparison itself over the remote transport. An in-sync tree and a transport
// that never ran both print nothing, so the two must not return the same value.
assert_same(
	array(),
	diviops_local_site_file_diff( $repo_plugin_dir, $dir_current, 'fixture-host' ),
	'an in-sync remote tree returns an empty array of changes'
);
assert_same(
	null,
	diviops_local_site_file_diff( $tmp . '/no-such-source', $dir_current, 'fixture-host' ),
	'a remote comparison rsync could not run returns null, not the empty in-sync result'
);

// The host cannot be reached at all. This is every CI run and every offline laptop,
// and it is the state that must never render as "I checked and staging is current".
putenv( 'DIVIOPS_SSH=/usr/bin/false' );
assert_same(
	null,
	diviops_local_site_file_diff( $repo_plugin_dir, $dir_current, 'fixture-host' ),
	'a remote comparison whose transport fails returns null, the same as any other comparison that never ran'
);
$report          = diviops_local_site_report( 'fixture-host:' . $root_current, $md_none, $repo_main );
$statuses_seen[] = $report['status'];
assert_same(
	'unreachable',
	$report['status'],
	'a host that cannot be reached is its own state, never current and never a bare absent: ' . $report['reason']
);
assert_true(
	false !== strpos( $report['reason'], 'fixture-host' ),
	'the unreachable reason names the host it failed to reach: ' . $report['reason']
);
assert_same( null, $report['installed_version'], 'an unreachable host yields no installed version to compare' );

putenv( 'DIVIOPS_SSH=' . $stub );

// The CLI carries the host, because scripts/deploy-local-site.sh has to know whether
// its gate, backup and lint run here or over there.
$cli = diviops_drift_test_cli( $lib, 'host', 'fixture-host:' . $root_current );
assert_same( 0, $cli['status'], 'the host subcommand exits zero for a remote target: ' . $cli['output'] );
assert_same( 'fixture-host', trim( $cli['output'] ), 'the host subcommand prints the host' );

$cli = diviops_drift_test_cli( $lib, 'host', $root_current );
assert_same( 0, $cli['status'], 'the host subcommand exits zero for a local target too: ' . $cli['output'] );
assert_same( '', trim( $cli['output'] ), 'a local target prints an empty host rather than failing' );

// Coverage assertion. Every status this check can produce must have been reached by
// a fixture, so no branch of it is dead code that only "works" in theory.
$expected_statuses = array( 'unconfigured', 'unreachable', 'absent', 'invalid', 'drift', 'current' );
sort( $expected_statuses );
$seen = array_values( array_unique( $statuses_seen ) );
sort( $seen );
assert_same( $expected_statuses, $seen, 'every reachable status was actually exercised, including the skip paths' );

/* -------------------------------------------------------------------------
 * scripts/deploy-local-site.sh, against synthetic sites only.
 * ---------------------------------------------------------------------- */

$deploy = $root . '/scripts/deploy-local-site.sh';
assert_true( is_file( $deploy ) && is_executable( $deploy ), 'scripts/deploy-local-site.sh exists and is executable' );

/**
 * Run the deploy script against a synthetic WordPress root.
 *
 * @param string $script  Script path.
 * @param string $wp_root Synthetic WordPress root.
 * @return array{status: int, output: string}
 */
function diviops_drift_test_deploy( string $script, string $wp_root ): array {
	$cmd = sprintf(
		'DIVIOPS_LOCAL_SITE=%s %s 2>&1',
		escapeshellarg( $wp_root ),
		escapeshellarg( $script )
	);
	$out    = array();
	$status = 0;
	exec( $cmd, $out, $status );
	return array(
		'status' => $status,
		'output' => implode( "\n", $out ),
	);
}

/**
 * Count timestamped backups sitting beside an installed plugin directory.
 *
 * @param string $wp_root Synthetic WordPress root.
 */
function diviops_drift_test_backups( string $wp_root ): int {
	return count( glob( $wp_root . '/wp-content/plugins/.diviops-agent.backup-*' ) ?: array() );
}

// A target that is not a DiviOps plugin directory is refused, not overwritten.
$root_refuse = $tmp . '/refuse';
diviops_drift_test_site( $root_refuse, null );
$run = diviops_drift_test_deploy( $deploy, $root_refuse );
assert_true( 0 !== $run['status'], 'the script refuses a target that is not a DiviOps plugin directory: ' . $run['output'] );
assert_same( 0, diviops_drift_test_backups( $root_refuse ), 'a refused deploy writes nothing, so it leaves no backup' );

// A real deploy onto a stale install.
$root_deploy = $tmp . '/deploy';
$dir_deploy  = diviops_drift_test_site( $root_deploy, '0.0.1' );
$run         = diviops_drift_test_deploy( $deploy, $root_deploy );
assert_same( 0, $run['status'], 'deploying onto a stale install succeeds: ' . $run['output'] );
assert_same(
	$repo_version,
	diviops_plugin_version( $dir_deploy . '/diviops-agent.php' ),
	'after deploying, the installed const VERSION equals the repository version'
);
assert_same( 1, diviops_drift_test_backups( $root_deploy ), 'a deploy that modified files left exactly one timestamped backup' );
assert_same(
	'current',
	diviops_local_site_report( $root_deploy, $md_none, $repo_main )['status'],
	'the drift check agrees with the deploy it just performed'
);

// Running it twice is safe, and the second run reports no change and takes no backup.
$run = diviops_drift_test_deploy( $deploy, $root_deploy );
assert_same( 0, $run['status'], 'a second consecutive deploy succeeds: ' . $run['output'] );
assert_true(
	false !== stripos( $run['output'], 'no change' ),
	'the second run says it changed nothing rather than reporting a deploy it did not do: ' . $run['output']
);
assert_same( 1, diviops_drift_test_backups( $root_deploy ), 'an idempotent no-op run takes no second backup' );

/* -------------------------------------------------------------------------
 * The same deploy, over the remote transport (#340).
 * ---------------------------------------------------------------------- */

putenv( 'DIVIOPS_SSH=' . $stub );

// The refuse-if-not-a-DiviOps-directory gate has to run on the host, not here.
// Locally that directory does not exist at all, so a gate that stayed local would
// pass it as "nothing to check" and point rsync --delete at a live install.
$root_remote_refuse = $tmp . '/remote-refuse';
diviops_drift_test_site( $root_remote_refuse, null );
$run = diviops_drift_test_deploy( $deploy, 'fixture-host:' . $root_remote_refuse );
assert_true(
	0 !== $run['status'],
	'the script refuses a remote target that is not a DiviOps plugin directory: ' . $run['output']
);
assert_same( 0, diviops_drift_test_backups( $root_remote_refuse ), 'a refused remote deploy leaves no backup' );

$root_remote = $tmp . '/remote-deploy';
$dir_remote  = diviops_drift_test_site( $root_remote, '0.0.1' );
diviops_drift_test_stub_log( $stub_log );
$run = diviops_drift_test_deploy( $deploy, 'fixture-host:' . $root_remote );
assert_same( 0, $run['status'], 'deploying onto a stale remote install succeeds: ' . $run['output'] );
assert_same(
	$repo_version,
	diviops_plugin_version( $dir_remote . '/diviops-agent.php' ),
	'after a remote deploy the installed const VERSION equals the repository version'
);
assert_same( 1, diviops_drift_test_backups( $root_remote ), 'a remote deploy that changed files left exactly one backup' );

// The fixtures are ordinary local directories, so a deploy that ignored the host and
// wrote straight to the path would leave the same tree, the same version and the same
// backup, and every assertion above would pass over a script that cannot reach a real
// site at all. This is the one that fails in that case.
// Deliberately not asserting on the rsync line: every rsync in this run goes over the
// transport, and telling the real transfer from the dry-run comparison means reading
// server flags whose spelling differs between rsync versions. The backup and the lint
// are unambiguous — a deploy that wrote to the path directly performs neither there.
$log = diviops_drift_test_stub_log( $stub_log );
assert_true(
	false !== strpos( $log, 'cp -a' ),
	'the remote deploy took its backup on the host, not here: ' . var_export( $log, true )
);
assert_true(
	false !== strpos( $log, 'php -l' ),
	'the remote deploy linted the files it landed on the host: ' . var_export( $log, true )
);
assert_same(
	'current',
	diviops_local_site_report( 'fixture-host:' . $root_remote, $md_none, $repo_main )['status'],
	'the drift check agrees with the remote deploy it just performed'
);

$run = diviops_drift_test_deploy( $deploy, 'fixture-host:' . $root_remote );
assert_same( 0, $run['status'], 'a second consecutive remote deploy succeeds: ' . $run['output'] );
assert_true(
	false !== stripos( $run['output'], 'no change' ),
	'the second remote run reports no change rather than a deploy it did not do: ' . $run['output']
);
assert_same( 1, diviops_drift_test_backups( $root_remote ), 'an idempotent remote no-op takes no second backup' );

// An unreachable host must stop the deploy loudly. A failed transport transfers
// nothing and itemizes nothing, which is byte-for-byte what an in-sync tree prints,
// so without reading the exit status this run would announce "no change" and exit 0
// having touched no site at all.
putenv( 'DIVIOPS_SSH=/usr/bin/false' );
$run = diviops_drift_test_deploy( $deploy, 'fixture-host:' . $root_remote );
assert_true(
	0 !== $run['status'],
	'the deploy fails on an unreachable host instead of reporting no change: ' . $run['output']
);
assert_true(
	false !== stripos( $run['output'], 'fixture-host' ),
	'the failed remote deploy names the host it could not reach: ' . $run['output']
);
assert_same( 1, diviops_drift_test_backups( $root_remote ), 'a deploy that could not reach the host takes no backup' );

putenv( 'DIVIOPS_SSH' );

/* -------------------------------------------------------------------------
 * The default remote shell must be able to run a command (#412).
 *
 * This gate went blind through an entire Divi upgrade and stayed green. The default was
 * `ssh -o BatchMode=yes -o ConnectTimeout=10`, and the staging host's ssh_config
 * deliberately sets `RequestTTY yes` + `RemoteCommand cd <webroot> && bash` so an
 * interactive login lands in the webroot. OpenSSH refuses to run a command argument when a
 * RemoteCommand is configured:
 *
 *     $ ssh -o BatchMode=yes staging.colleyvillelions.com 'wp option get home'
 *     Cannot execute command-line and remote command.     # exit 255
 *
 * Every probe died at 255, which the gate reads as "unreachable" and reports as SKIP — inside
 * a suite that then prints PASS. The config is a deliberate ergonomic choice and is not the
 * thing to change; the tooling has to be robust to it. `RemoteCommand=none` and
 * `RequestTTY=no` are correct on a host with no RemoteCommand too, so this costs nothing.
 *
 * Asserted on the string rather than by connecting, so it runs on every machine including CI,
 * where there is no host to reach.
 * ---------------------------------------------------------------------- */

$diviops_drift_default_shell = diviops_site_remote_shell();
assert_true(
	false !== strpos( $diviops_drift_default_shell, 'RemoteCommand=none' ),
	'the default remote shell neutralises a RemoteCommand from ssh_config, or every probe exits 255 and the gate skips (#412)'
);
assert_true(
	false !== strpos( $diviops_drift_default_shell, 'RequestTTY=no' ),
	'and does not request a TTY, which a configured RequestTTY would otherwise force'
);
assert_true(
	false !== strpos( $diviops_drift_default_shell, 'BatchMode=yes' ),
	'while still refusing to prompt — an unattended run must fail rather than hang'
);

// The deploy script builds its own copy of this default. Two copies that can disagree is how
// one of them goes stale: the drift gate and the deploy would then reach different hosts, or
// one would work while the other silently could not.
$diviops_drift_deploy_src = (string) file_get_contents( $root . '/scripts/deploy-local-site.sh' );
assert_true( strlen( $diviops_drift_deploy_src ) > 500, 'deploy-local-site.sh loaded — positive control for the match below' );
assert_same(
	1,
	preg_match( '/SSH="\$\{DIVIOPS_SSH:-([^}]*)\}"/', $diviops_drift_deploy_src, $diviops_drift_deploy_m ),
	'deploy-local-site.sh still builds its remote shell from a DIVIOPS_SSH default this test can read'
);
assert_same(
	$diviops_drift_default_shell,
	$diviops_drift_deploy_m[1],
	'the deploy script and the drift gate use the identical default remote shell (#412)'
);

diviops_drift_test_rmtree( $tmp );

/* -------------------------------------------------------------------------
 * The live check: is THIS machine's dev site behind?
 *
 * Skips loudly with a stated reason when no site is configured or present, which
 * is every CI run. Fails naming both versions when a site is present and behind.
 * ---------------------------------------------------------------------- */

// The gate watches whatever CLAUDE.md names, so CLAUDE.md naming nothing — or naming
// a retired install that still sits on disk — is how it goes blind while staying
// green. Pin the declared target itself, not just the code that reads it.
$declared = diviops_local_site_target( null, $root . '/CLAUDE.md' );
assert_true(
	null !== $declared['plugin_dir'],
	'CLAUDE.md declares the site this gate watches; without that line the live half inspects nothing'
);
assert_same(
	'staging.colleyvillelions.com',
	$declared['host'],
	'CLAUDE.md points the gate at staging, the site this work actually runs against (#340)'
);

$env_target = getenv( 'DIVIOPS_LOCAL_SITE' );
$live       = diviops_local_site_report(
	false === $env_target || '' === $env_target ? null : $env_target,
	$root . '/CLAUDE.md',
	$repo_main
);

assert_true( '' !== $live['reason'], 'the live drift check states what it found, whether it checked or skipped' );

if ( 'current' === $live['status'] || 'drift' === $live['status'] || 'invalid' === $live['status'] ) {
	assert_same(
		'current',
		$live['status'],
		'the dev site runs the current plugin — run scripts/deploy-local-site.sh. ' . $live['reason']
	);
} else {
	printf( "SKIP  site drift check: %s%s", $live['reason'], PHP_EOL );
	assert_true(
		in_array( $live['status'], array( 'unconfigured', 'unreachable', 'absent' ), true ),
		'a skipped drift check skipped for a known reason, not an unrecognised one'
	);
}
