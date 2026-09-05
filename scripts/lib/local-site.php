<?php
// SPDX-License-Identifier: MIT
/**
 * Where the local dev site is, and whether it is running the current plugin (#292).
 *
 * The dev site runs a COPY of the plugin, not a symlink, so a release does not reach
 * it and a stale install is silent — it looks identical to a current one until you
 * diff the files. Two things need to agree on where that site is and what it runs:
 * `scripts/deploy-local-site.sh` and `tests/test-local-site-drift.php`. They share
 * this file so they cannot drift apart, and so the resolution itself is testable
 * rather than duplicated twice in two languages.
 *
 * Target resolution, in order:
 *
 *   1. `DIVIOPS_LOCAL_SITE` — the WordPress root (the directory holding
 *      `wp-content/`). A path that already ends in `diviops-agent` is accepted as the
 *      plugin directory itself, because that is the obvious way to misread the name.
 *   2. The ``Local site: `…` `` line in `CLAUDE.md`.
 *
 * Either form may name a host: `[user@]host:/absolute/path` is reached over SSH, and
 * anything else is a path on this machine. That is the whole of #340. The site this
 * repository works against moved to staging.colleyvillelions.com while the retired
 * LocalWP install stayed on disk, so the gate went on comparing against a site nobody
 * runs and reporting `current` — which renders exactly like certifying the live one.
 *
 * The remote shell is `DIVIOPS_SSH`, defaulting to `ssh -o BatchMode=yes
 * -o ConnectTimeout=10`. BatchMode is not decoration: a gate that can stop and ask for
 * a passphrase is a gate that hangs a test run. The variable exists so the tests can
 * drive every remote branch through a stub on a machine with no network and no key.
 *
 * Usable from the shell as well as from PHP:
 *
 *   php scripts/lib/local-site.php plugin-dir
 *   php scripts/lib/local-site.php host
 *   php scripts/lib/local-site.php status
 *   php scripts/lib/local-site.php reason
 *   php scripts/lib/local-site.php installed-version
 *   php scripts/lib/local-site.php repo-version
 *   php scripts/lib/local-site.php source
 *   php scripts/lib/local-site.php diff
 *
 * "In sync" means the installed files hold the same bytes as the repository's, not
 * that the two declare the same version and not that their mtimes agree. A version
 * string only moves at release time, so between releases every change merged to main
 * is invisible to it: on 2026-08-28 the site ran 1.19.2 against a repository at
 * 1.19.2 while missing the merged #293 fix, and the check reported current (#307).
 * The comparison lives in diviops_local_site_file_diff() and both the deploy and the
 * drift check call it, so they cannot disagree about what in sync means.
 *
 * @package DiviOps
 */

/**
 * Read `const VERSION = '…'` out of a plugin main file.
 *
 * @param string $file Path to a plugin main file.
 * @return string|null The declared version, or null when the file or const is absent.
 */
function diviops_plugin_version( string $file ) {
	if ( ! is_file( $file ) ) {
		return null;
	}
	return diviops_plugin_version_from_source( (string) file_get_contents( $file ) );
}

/**
 * Read `const VERSION = '…'` out of plugin main-file source text.
 *
 * Split out from diviops_plugin_version() because a file on another host arrives as a
 * string, never as a path this process can stat.
 *
 * @param string $src Contents of a plugin main file.
 * @return string|null The declared version, or null when the const is absent.
 */
function diviops_plugin_version_from_source( string $src ) {
	if ( 1 === preg_match( '/^\s*const\s+VERSION\s*=\s*\'([^\']+)\'/m', $src, $m ) ) {
		return $m[1];
	}
	return null;
}

/**
 * The command used to reach a remote site.
 *
 * @return string A remote-shell command line, as rsync's `-e` wants it.
 */
function diviops_site_remote_shell(): string {
	$ssh = getenv( 'DIVIOPS_SSH' );
	return is_string( $ssh ) && '' !== trim( $ssh )
		? trim( $ssh )
		: 'ssh -o BatchMode=yes -o ConnectTimeout=10';
}

/**
 * Render a target for a human: `host:/path` remotely, `/path` locally.
 *
 * @param string|null $host       Host, or null for this machine.
 * @param string      $plugin_dir Plugin directory.
 */
function diviops_site_label( $host, string $plugin_dir ): string {
	return null === $host ? $plugin_dir : $host . ':' . $plugin_dir;
}

/**
 * Look at an installed plugin directory, here or on another host.
 *
 * One round trip answers all three questions the report needs — is the host there, is
 * the directory there, and what does its main file say — because over SSH each extra
 * question is another connection, and because the three answers have to come from the
 * same moment to describe the same site.
 *
 * The remote command's exit codes are the whole contract. 91 and 92 are picked to sit
 * clear of the shell's own (126, 127) and of ssh's transport failure (255), so
 * "the host said no" can never be confused with "the host never answered". Every code
 * this function does not recognise is unreachable, which is the safe reading: a state
 * that reports a site it could not talk to as `absent` is one skip away from a green
 * run over a site that is quietly behind.
 *
 * @param string|null $host       Host to reach, or null for this machine.
 * @param string      $plugin_dir Installed plugin directory.
 * @return array{state: string, source: string|null} state is
 *         found|absent|invalid|unreachable; source is the main file's text when found.
 */
function diviops_site_plugin_probe( $host, string $plugin_dir ): array {
	$main = rtrim( $plugin_dir, '/' ) . '/diviops-agent.php';

	if ( null === $host ) {
		if ( ! is_dir( $plugin_dir ) ) {
			return array(
				'state'  => 'absent',
				'source' => null,
			);
		}
		if ( ! is_file( $main ) ) {
			return array(
				'state'  => 'invalid',
				'source' => null,
			);
		}
		return array(
			'state'  => 'found',
			'source' => (string) file_get_contents( $main ),
		);
	}

	$remote = sprintf(
		'test -d %s || exit 91; cat %s 2>/dev/null || exit 92',
		escapeshellarg( rtrim( $plugin_dir, '/' ) ),
		escapeshellarg( $main )
	);

	$out    = array();
	$status = 0;
	exec(
		sprintf(
			'%s %s %s 2>/dev/null',
			diviops_site_remote_shell(),
			escapeshellarg( $host ),
			escapeshellarg( $remote )
		),
		$out,
		$status
	);

	if ( 0 === $status ) {
		return array(
			'state'  => 'found',
			'source' => implode( "\n", $out ),
		);
	}
	if ( 91 === $status ) {
		return array(
			'state'  => 'absent',
			'source' => null,
		);
	}
	if ( 92 === $status ) {
		return array(
			'state'  => 'invalid',
			'source' => null,
		);
	}
	return array(
		'state'  => 'unreachable',
		'source' => null,
	);
}

/**
 * List the content differences between the repository plugin and an installed copy.
 *
 * The single definition of "in sync" for this repository. `scripts/deploy-local-site.sh`
 * asks this same function what it would change, so the deploy and the drift check
 * cannot disagree.
 *
 * Two details carry the whole result:
 *
 * `-c` compares checksums. Without it rsync compares size and mtime, and `git
 * checkout` stamps every file with checkout time — so a fresh worktree or a CI clone
 * differs in mtime on every file while the code is byte-identical. Observed on
 * 2026-08-28: a worktree checkout reported 31 differing paths against a site where
 * exactly 2 files differed in content.
 *
 * The itemization is then filtered to lines that are not attribute-only. rsync's
 * first character says what it will do: `>`/`<` transfers content, `c` creates,
 * `*` deletes, and `.` means the content already matches and only metadata such as
 * an mtime would be touched. Only the first group means the site is running
 * different code, which is the question this check exists to answer.
 *
 * @param string      $src        Repository plugin directory.
 * @param string      $plugin_dir Installed plugin directory to compare against it.
 * @param string|null $host       Host holding $plugin_dir, or null for this machine.
 * @return array<int, string>|null Itemized lines for paths whose content differs,
 *                                 empty when the code matches; null when the
 *                                 comparison could not be run at all.
 */
function diviops_local_site_file_diff( string $src, string $plugin_dir, $host = null ) {
	$dest      = rtrim( $plugin_dir, '/' ) . '/';
	$transport = '';

	if ( null !== $host && '' !== $host ) {
		$dest      = $host . ':' . $dest;
		$transport = '-e ' . escapeshellarg( diviops_site_remote_shell() ) . ' ';
	}

	$out    = array();
	$status = 0;
	exec(
		sprintf(
			'rsync -a -c --delete --itemize-changes --dry-run %s%s %s 2>/dev/null',
			$transport,
			escapeshellarg( rtrim( $src, '/' ) . '/' ),
			escapeshellarg( $dest )
		),
		$out,
		$status
	);

	// An identical tree, a missing rsync and a host that never answered all print
	// nothing. The exit status is the only thing separating "in sync" from "never
	// compared", and reading this wrong turns the gate into one that passes while
	// inspecting nothing.
	if ( 0 !== $status ) {
		return null;
	}

	$changes = array();
	foreach ( $out as $line ) {
		$line = trim( $line );
		if ( '' === $line || '.' === $line[0] ) {
			continue;
		}
		$changes[] = $line;
	}
	return $changes;
}

/**
 * Reduce rsync's itemized change lines to the paths they name.
 *
 * Lines look like `>f.st...... includes/foo.php` or `*deleting   stale.php`.
 *
 * @param array<int, string> $changes Itemized change lines.
 * @return array<int, string> The paths, in the order rsync reported them.
 */
function diviops_local_site_changed_paths( array $changes ): array {
	$paths = array();
	foreach ( $changes as $line ) {
		$parts = preg_split( '/\s+/', $line, 2 );
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			$paths[] = $parts[1];
		}
	}
	return $paths;
}

/**
 * Resolve which plugin directory the local dev site installs DiviOps Agent into.
 *
 * Answers where to look; says nothing about whether anything is there.
 *
 * @param string|null $env       Value of DIVIOPS_LOCAL_SITE, or null when unset.
 * @param string      $claude_md Path to the CLAUDE.md carrying the fallback path.
 * @return array{host: string|null, plugin_dir: string|null, source: string} `source` is env|claude-md|none.
 */
function diviops_local_site_target( $env, string $claude_md ): array {
	$root   = null;
	$source = 'none';

	if ( is_string( $env ) && '' !== trim( $env ) ) {
		$root   = rtrim( trim( $env ), '/' );
		$source = 'env';
	} elseif ( is_file( $claude_md ) ) {
		$md = (string) file_get_contents( $claude_md );
		if ( 1 === preg_match( '/^Local site:\s*`([^`]+)`/m', $md, $m ) ) {
			$root   = rtrim( trim( $m[1] ), '/' );
			$source = 'claude-md';
		}
	}

	if ( null === $root || '' === $root ) {
		return array(
			'host'       => null,
			'plugin_dir' => null,
			'source'     => 'none',
		);
	}

	// `[user@]host:/absolute/path` is a site reached over SSH. Requiring the path to
	// be absolute is what keeps a local path that happens to contain a colon — and
	// macOS lets a directory name hold one — from being read as a hostname.
	$host = null;
	if ( 1 === preg_match( '#^([^/:]+):(/.*)$#', $root, $m ) ) {
		$host = $m[1];
		$root = rtrim( $m[2], '/' );
	}

	// Accept the plugin directory itself, not only the WordPress root.
	$plugin_dir = 'diviops-agent' === basename( $root )
		? $root
		: $root . '/wp-content/plugins/diviops-agent';

	return array(
		'host'       => $host,
		'plugin_dir' => $plugin_dir,
		'source'     => $source,
	);
}

/**
 * Report whether the local dev site is running the repository's plugin version.
 *
 * The status vocabulary is deliberately six values rather than a boolean, because
 * "I checked and it is current" and "I could not find a site to check" must never
 * render the same way:
 *
 *   unconfigured — no env var, no path in CLAUDE.md. Skip.
 *   unreachable  — a host is named but did not answer. Skip, loudly, naming it.
 *   absent       — a target is configured but nothing is installed there. Skip.
 *   invalid      — the directory exists but is not a DiviOps plugin, or the file
 *                  comparison could not be run. Fail.
 *   drift        — the installed version or the installed files differ from the
 *                  repository. Fail.
 *   current      — the installed tree matches the repository. Pass.
 *
 * `unreachable` is a skip and not a failure on purpose: CI has no key for the site and
 * a laptop offline has no route to it, and a gate that fails on both is a gate people
 * learn to merge past. What it must never do is round down to `current`, which is why
 * it is its own word and why its reason names the host.
 *
 * @param string|null $env       Value of DIVIOPS_LOCAL_SITE, or null when unset.
 * @param string      $claude_md Path to the CLAUDE.md carrying the fallback path.
 * @param string      $repo_main Repository plugin main file to compare against.
 * @return array{status: string, reason: string, source: string, host: string|null, plugin_dir: string|null, installed_version: string|null, repo_version: string|null}
 */
function diviops_local_site_report( $env, string $claude_md, string $repo_main ): array {
	$target       = diviops_local_site_target( $env, $claude_md );
	$repo_version = diviops_plugin_version( $repo_main );

	$report = array(
		'status'            => 'unconfigured',
		'reason'            => '',
		'source'            => $target['source'],
		'host'              => $target['host'],
		'plugin_dir'        => $target['plugin_dir'],
		'installed_version' => null,
		'repo_version'      => $repo_version,
	);

	if ( null === $target['plugin_dir'] ) {
		$report['reason'] = sprintf(
			'no site configured: DIVIOPS_LOCAL_SITE is unset and %s declares no "Local site:" path',
			$claude_md
		);
		return $report;
	}

	$label = diviops_site_label( $target['host'], $target['plugin_dir'] );
	$probe = diviops_site_plugin_probe( $target['host'], $target['plugin_dir'] );

	if ( 'unreachable' === $probe['state'] ) {
		$report['status'] = 'unreachable';
		$report['reason'] = sprintf(
			'could not reach %s over %s, so nothing about %s was inspected — this is not a pass',
			$target['host'],
			diviops_site_remote_shell(),
			$label
		);
		return $report;
	}

	if ( 'absent' === $probe['state'] ) {
		$report['status'] = 'absent';
		$report['reason'] = sprintf(
			'no plugin installed at %s (target came from %s)',
			$label,
			$target['source']
		);
		return $report;
	}

	$installed                   = null === $probe['source']
		? null
		: diviops_plugin_version_from_source( $probe['source'] );
	$report['installed_version'] = $installed;

	if ( null === $installed ) {
		$report['status'] = 'invalid';
		$report['reason'] = sprintf(
			'%s exists but declares no DiviOps Agent const VERSION — it is not a DiviOps plugin directory',
			$label
		);
		return $report;
	}

	if ( null === $repo_version ) {
		$report['status'] = 'invalid';
		$report['reason'] = sprintf( '%s declares no const VERSION to compare against', $repo_main );
		return $report;
	}

	if ( $installed !== $repo_version ) {
		$report['status'] = 'drift';
		$report['reason'] = sprintf(
			'%s runs %s but this repository is at %s',
			$label,
			$installed,
			$repo_version
		);
		return $report;
	}

	// Same version is not the same code. Everything merged since the last version
	// bump declares the version the repository declares, so only the files can tell
	// a current install from one that predates a merged fix (#307).
	$changes = diviops_local_site_file_diff( dirname( $repo_main ), $target['plugin_dir'], $target['host'] );

	if ( null === $changes ) {
		$report['status'] = 'invalid';
		$report['reason'] = sprintf(
			'could not compare %s against %s: rsync exited non-zero. Nothing was inspected, so this is not a pass',
			$label,
			dirname( $repo_main )
		);
		return $report;
	}

	if ( array() !== $changes ) {
		$paths = diviops_local_site_changed_paths( $changes );
		$shown = array_slice( $paths, 0, 5 );

		$report['status'] = 'drift';
		$report['reason'] = sprintf(
			'%s runs %s, matching this repository, but the contents of %d path(s) differ from %s: %s%s',
			$label,
			$installed,
			count( $paths ),
			dirname( $repo_main ),
			implode( ', ', $shown ),
			count( $paths ) > count( $shown ) ? ', ...' : ''
		);
		return $report;
	}

	$report['status'] = 'current';
	$report['reason'] = sprintf(
		'%s runs %s and its files hold the same bytes as this repository',
		$label,
		$installed
	);
	return $report;
}

// -----------------------------------------------------------------------------
// CLI mode, so deploy-local-site.sh reads the same resolution the test does.
// -----------------------------------------------------------------------------

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	$repo_root  = dirname( dirname( __DIR__ ) );
	$cli_env    = getenv( 'DIVIOPS_LOCAL_SITE' );
	$cli_report = diviops_local_site_report(
		false === $cli_env || '' === $cli_env ? null : $cli_env,
		$repo_root . '/CLAUDE.md',
		$repo_root . '/plugins/diviops-agent/diviops-agent.php'
	);

	$cli_src = $repo_root . '/plugins/diviops-agent';

	// Only the two states that got as far as comparing trees have a diff to report.
	// `is_dir()` used to stand in for that and cannot: a remote plugin directory is
	// not a path this process can stat, so it read as "nothing installed" for every
	// site reached over SSH.
	$cli_diff = in_array( $cli_report['status'], array( 'drift', 'current' ), true )
		? diviops_local_site_file_diff( $cli_src, (string) $cli_report['plugin_dir'], $cli_report['host'] )
		: null;

	$fields = array(
		'plugin-dir'        => $cli_report['plugin_dir'],
		'host'              => (string) $cli_report['host'],
		'status'            => $cli_report['status'],
		'reason'            => $cli_report['reason'],
		'installed-version' => $cli_report['installed_version'],
		'repo-version'      => $cli_report['repo_version'],
		'source'            => $cli_report['source'],
		'diff'              => null === $cli_diff ? null : implode( PHP_EOL, $cli_diff ),
	);

	// `diff` and `host` both have a meaningful empty answer — nothing to change, and
	// a site on this machine. Every other field's empty is a failure to resolve.
	$cli_empty_ok = array( 'diff', 'host' );

	$field = $argv[1] ?? '';
	if ( ! array_key_exists( $field, $fields ) ) {
		fwrite( STDERR, 'usage: php ' . basename( __FILE__ ) . ' <' . implode( '|', array_keys( $fields ) ) . '>' . PHP_EOL );
		exit( 2 );
	}
	if ( null === $fields[ $field ] ) {
		fwrite(
			STDERR,
			( 'diff' === $field ? 'could not compare ' . $cli_src . ' against the installed plugin' : $cli_report['reason'] ) . PHP_EOL
		);
		exit( 1 );
	}
	if ( in_array( $field, $cli_empty_ok, true ) && '' === $fields[ $field ] ) {
		exit( 0 );
	}
	echo $fields[ $field ], PHP_EOL;
	exit( 0 );
}
