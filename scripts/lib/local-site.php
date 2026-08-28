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
 *   invalid      — the directory exists but is not a DiviOps plugin. Fail.
 *   drift        — installed and repository versions differ. Fail.
 *   current      — they match. Pass.
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

	$report['status'] = 'current';
	$report['reason'] = sprintf( '%s runs %s, matching this repository', $target['plugin_dir'], $installed );
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

	$fields = array(
		'plugin-dir'        => $cli_report['plugin_dir'],
		'status'            => $cli_report['status'],
		'reason'            => $cli_report['reason'],
		'installed-version' => $cli_report['installed_version'],
		'repo-version'      => $cli_report['repo_version'],
		'source'            => $cli_report['source'],
	);

	$field = $argv[1] ?? '';
	if ( ! array_key_exists( $field, $fields ) ) {
		fwrite( STDERR, 'usage: php ' . basename( __FILE__ ) . ' <' . implode( '|', array_keys( $fields ) ) . '>' . PHP_EOL );
		exit( 2 );
	}
	if ( null === $fields[ $field ] ) {
		fwrite( STDERR, $cli_report['reason'] . PHP_EOL );
		exit( 1 );
	}
	echo $fields[ $field ], PHP_EOL;
	exit( 0 );
}
