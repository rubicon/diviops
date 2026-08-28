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
 * Usable from the shell as well as from PHP:
 *
 *   php scripts/lib/local-site.php plugin-dir
 *   php scripts/lib/local-site.php status
 *   php scripts/lib/local-site.php reason
 *   php scripts/lib/local-site.php installed-version
 *   php scripts/lib/local-site.php repo-version
 *   php scripts/lib/local-site.php source
 *   php scripts/lib/local-site.php diff
 *
 * "In sync" means the installed tree equals the repository tree, not that the two
 * declare the same version. A version string only moves at release time, so between
 * releases every change merged to main is invisible to it: on 2026-08-28 the site
 * ran 1.19.2 against a repository at 1.19.2 while missing the merged #293 fix, and
 * the check reported current (#307). The comparison lives in
 * diviops_local_site_file_diff() and both the deploy and the drift check call it, so
 * they cannot disagree about what in sync means.
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
	$src = (string) file_get_contents( $file );
	if ( 1 === preg_match( '/^\s*const\s+VERSION\s*=\s*\'([^\']+)\'/m', $src, $m ) ) {
		return $m[1];
	}
	return null;
}

/**
 * List what rsync would change to make an installed copy match the repository.
 *
 * The single definition of "in sync" for this repository. `scripts/deploy-local-site.sh`
 * deploys with these exact flags, so an empty itemization here means that deploy would
 * write nothing.
 *
 * @param string $src        Repository plugin directory.
 * @param string $plugin_dir Installed plugin directory to compare against it.
 * @return array<int, string>|null Itemized change lines, empty when identical; null
 *                                 when the comparison could not be run at all.
 */
function diviops_local_site_file_diff( string $src, string $plugin_dir ) {
	$out    = array();
	$status = 0;
	exec(
		sprintf(
			'rsync -a --delete --itemize-changes --dry-run %s %s 2>/dev/null',
			escapeshellarg( rtrim( $src, '/' ) . '/' ),
			escapeshellarg( rtrim( $plugin_dir, '/' ) . '/' )
		),
		$out,
		$status
	);

	// An identical tree and a missing rsync both print nothing. The exit status is
	// the only thing separating "in sync" from "never compared", and reading this
	// wrong turns the gate into one that passes while inspecting nothing.
	if ( 0 !== $status ) {
		return null;
	}

	return array_values( array_filter( array_map( 'trim', $out ), 'strlen' ) );
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
 * @return array{plugin_dir: string|null, source: string} `source` is env|claude-md|none.
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
			'plugin_dir' => null,
			'source'     => 'none',
		);
	}

	// Accept the plugin directory itself, not only the WordPress root.
	$plugin_dir = 'diviops-agent' === basename( $root )
		? $root
		: $root . '/wp-content/plugins/diviops-agent';

	return array(
		'plugin_dir' => $plugin_dir,
		'source'     => $source,
	);
}

/**
 * Report whether the local dev site is running the repository's plugin version.
 *
 * The status vocabulary is deliberately five values rather than a boolean, because
 * "I checked and it is current" and "I could not find a site to check" must never
 * render the same way:
 *
 *   unconfigured — no env var, no path in CLAUDE.md. Skip.
 *   absent       — a target is configured but nothing is installed there. Skip.
 *   invalid      — the directory exists but is not a DiviOps plugin, or the file
 *                  comparison could not be run. Fail.
 *   drift        — the installed version or the installed files differ from the
 *                  repository. Fail.
 *   current      — the installed tree matches the repository. Pass.
 *
 * @param string|null $env       Value of DIVIOPS_LOCAL_SITE, or null when unset.
 * @param string      $claude_md Path to the CLAUDE.md carrying the fallback path.
 * @param string      $repo_main Repository plugin main file to compare against.
 * @return array{status: string, reason: string, source: string, plugin_dir: string|null, installed_version: string|null, repo_version: string|null}
 */
function diviops_local_site_report( $env, string $claude_md, string $repo_main ): array {
	$target       = diviops_local_site_target( $env, $claude_md );
	$repo_version = diviops_plugin_version( $repo_main );

	$report = array(
		'status'            => 'unconfigured',
		'reason'            => '',
		'source'            => $target['source'],
		'plugin_dir'        => $target['plugin_dir'],
		'installed_version' => null,
		'repo_version'      => $repo_version,
	);

	if ( null === $target['plugin_dir'] ) {
		$report['reason'] = sprintf(
			'no local site configured: DIVIOPS_LOCAL_SITE is unset and %s declares no "Local site:" path',
			$claude_md
		);
		return $report;
	}

	$main = $target['plugin_dir'] . '/diviops-agent.php';

	if ( ! is_dir( $target['plugin_dir'] ) ) {
		$report['status'] = 'absent';
		$report['reason'] = sprintf(
			'no plugin installed at %s (target came from %s)',
			$target['plugin_dir'],
			$target['source']
		);
		return $report;
	}

	$installed                     = diviops_plugin_version( $main );
	$report['installed_version']   = $installed;

	if ( null === $installed ) {
		$report['status'] = 'invalid';
		$report['reason'] = sprintf(
			'%s exists but declares no DiviOps Agent const VERSION — it is not a DiviOps plugin directory',
			$target['plugin_dir']
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
			$target['plugin_dir'],
			$installed,
			$repo_version
		);
		return $report;
	}

	// Same version is not the same code. Everything merged since the last version
	// bump declares the version the repository declares, so only the files can tell
	// a current install from one that predates a merged fix (#307).
	$changes = diviops_local_site_file_diff( dirname( $repo_main ), $target['plugin_dir'] );

	if ( null === $changes ) {
		$report['status'] = 'invalid';
		$report['reason'] = sprintf(
			'could not compare %s against %s: rsync exited non-zero. Nothing was inspected, so this is not a pass',
			$target['plugin_dir'],
			dirname( $repo_main )
		);
		return $report;
	}

	if ( array() !== $changes ) {
		$paths = diviops_local_site_changed_paths( $changes );
		$shown = array_slice( $paths, 0, 5 );

		$report['status'] = 'drift';
		$report['reason'] = sprintf(
			'%s runs %s, matching this repository, but %d path(s) differ from %s: %s%s',
			$target['plugin_dir'],
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
		'%s runs %s and its files match this repository',
		$target['plugin_dir'],
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

	$cli_src  = $repo_root . '/plugins/diviops-agent';
	$cli_diff = null === $cli_report['plugin_dir'] || ! is_dir( $cli_report['plugin_dir'] )
		? null
		: diviops_local_site_file_diff( $cli_src, $cli_report['plugin_dir'] );

	$fields = array(
		'plugin-dir'        => $cli_report['plugin_dir'],
		'status'            => $cli_report['status'],
		'reason'            => $cli_report['reason'],
		'installed-version' => $cli_report['installed_version'],
		'repo-version'      => $cli_report['repo_version'],
		'source'            => $cli_report['source'],
		'diff'              => null === $cli_diff ? null : implode( PHP_EOL, $cli_diff ),
	);

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
	if ( 'diff' === $field && '' === $fields[ $field ] ) {
		exit( 0 );
	}
	echo $fields[ $field ], PHP_EOL;
	exit( 0 );
}
