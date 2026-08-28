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
diviops_drift_test_clone( $root_current, $repo_plugin_dir );
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

// Coverage assertion. Every status this check can produce must have been reached by
// a fixture, so no branch of it is dead code that only "works" in theory.
$expected_statuses = array( 'unconfigured', 'absent', 'invalid', 'drift', 'current' );
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

diviops_drift_test_rmtree( $tmp );

/* -------------------------------------------------------------------------
 * The live check: is THIS machine's dev site behind?
 *
 * Skips loudly with a stated reason when no site is configured or present, which
 * is every CI run. Fails naming both versions when a site is present and behind.
 * ---------------------------------------------------------------------- */

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
		'the local dev site runs the current plugin — run scripts/deploy-local-site.sh. ' . $live['reason']
	);
} else {
	printf( "SKIP  local-site drift check: %s%s", $live['reason'], PHP_EOL );
	assert_true(
		in_array( $live['status'], array( 'unconfigured', 'absent' ), true ),
		'a skipped drift check skipped for a known reason, not an unrecognised one'
	);
}
