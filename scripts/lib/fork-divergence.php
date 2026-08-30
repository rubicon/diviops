<?php
// SPDX-License-Identifier: MIT
/**
 * Whether this change records its upstream divergence in FORK.md (#297).
 *
 * `CLAUDE.md` requires intentional changes to upstream-origin files to be recorded in
 * `FORK.md`'s divergence table. The table is the cherry-pick reconciliation aid for an
 * upstream sync: when upstream touches a file this fork has diverged on, the table is
 * what says how and why we diverged. A missing row means a future sync reconciles
 * against an incomplete record, and the cost lands on whoever runs it.
 *
 * The rule was enforced by memory alone and was holding roughly half the time, so this
 * turns it into a gate. `tests/test-fork-divergence-ledger.php` drives it.
 *
 * The status vocabulary is deliberately five values rather than a boolean, because "I
 * diffed and found nothing wrong" and "I could not determine what changed" must never
 * render the same way:
 *
 *   no-base      — the base ref does not resolve (a shallow clone, a detached
 *                  checkout, a fresh clone with no remote). Skip.
 *   no-changes   — the base resolved but named no changed paths, which is a run
 *                  sitting on the base ref itself. Skip.
 *   clean        — paths changed, none of them upstream-origin. Pass.
 *   documented   — an upstream-origin path changed and FORK.md changed with it. Pass.
 *   undocumented — an upstream-origin path changed and FORK.md did not. Fail.
 *
 * @package DiviOps
 */

/**
 * Paths whose contents are upstream-origin and therefore need a ledger row.
 *
 * Scoped to what #297 names: the plugin and the MCP server's source. Everything else
 * this fork owns outright (tests, scripts, workflows, the repo docs) is out.
 *
 * @return array<int, string> Repository-relative path prefixes, each ending in a slash.
 */
function diviops_fork_ledger_guarded_prefixes(): array {
	return array( 'plugins/diviops-agent/', 'diviops-server/src/' );
}

/**
 * The ref this fork's changes are measured against.
 *
 * `DIVIOPS_FORK_BASE_REF` overrides it, so a checkout whose base is not `origin/main`
 * — a PR targeting a release branch, a mirror using a different remote name — can point
 * the gate at the right ref instead of falling through to a silent skip.
 */
function diviops_fork_ledger_base_ref(): string {
	$env = getenv( 'DIVIOPS_FORK_BASE_REF' );
	return is_string( $env ) && '' !== trim( $env ) ? trim( $env ) : 'origin/main';
}

/**
 * Run a git command in a repository and return its output lines.
 *
 * @param string             $root   Repository root.
 * @param array<int, string> $args   Arguments, each shell-escaped individually.
 * @param int                $status Receives the exit status.
 * @return array<int, string> Output lines.
 */
function diviops_fork_ledger_git( string $root, array $args, ?int &$status = null ): array {
	$command = 'git -C ' . escapeshellarg( $root );
	foreach ( $args as $arg ) {
		$command .= ' ' . escapeshellarg( $arg );
	}

	$out    = array();
	$status = 0;
	exec( $command . ' 2>/dev/null', $out, $status );
	return $out;
}

/**
 * Split `-z` git output into paths.
 *
 * The lines are rejoined before splitting so a path containing a newline survives
 * exec()'s line splitting intact. `-z` is used rather than plain `--name-only` because
 * git otherwise quotes and escapes unusual paths, and a gate that mangles the path it
 * is about to accuse someone over is worse than no gate.
 *
 * @param array<int, string> $lines Raw exec() output.
 * @return array<int, string> Paths.
 */
function diviops_fork_ledger_paths_from_z( array $lines ): array {
	return array_values( array_filter( explode( "\0", implode( "\n", $lines ) ), 'strlen' ) );
}

/**
 * Collect the paths this checkout changes, and whether each existed at the base.
 *
 * Two diffs are unioned. `<base>...HEAD` is the committed change, measured from the
 * merge base so a base ref that has moved on does not drag unrelated files in. `HEAD`
 * alone adds the uncommitted working tree, so the gate fires while a change is still
 * being made rather than only once it is committed.
 *
 * The per-path base lookup is what separates a new file from a modified one, and it
 * asks git rather than reading a diff status letter — which keeps renames honest. A
 * rename's new path is absent from the base (new, exempt) while its old path is present
 * and shows up as a deletion (divergence, and it needs a row).
 *
 * @param string $root Repository root.
 * @param string $base Base ref.
 * @return array<string, bool>|null Path => existed at the base; null when no diff could
 *                                  be resolved at all.
 */
function diviops_fork_ledger_changes( string $root, string $base ) {
	diviops_fork_ledger_git( $root, array( 'rev-parse', '--verify', '--quiet', $base . '^{commit}' ), $status );
	if ( 0 !== $status ) {
		return null;
	}

	$paths = array();
	foreach ( array( array( 'diff', '--name-only', '-z', $base . '...HEAD' ), array( 'diff', '--name-only', '-z', 'HEAD' ) ) as $args ) {
		$lines = diviops_fork_ledger_git( $root, $args, $status );

		// An empty diff and a failed command both print nothing. The exit status is
		// the only thing separating "nothing changed" from "never compared", and
		// reading it wrong is what turns this into a gate that passes while
		// inspecting nothing.
		if ( 0 !== $status ) {
			return null;
		}
		foreach ( diviops_fork_ledger_paths_from_z( $lines ) as $path ) {
			$paths[ $path ] = true;
		}
	}

	$changes = array();
	foreach ( array_keys( $paths ) as $path ) {
		diviops_fork_ledger_git( $root, array( 'cat-file', '-e', $base . ':' . $path ), $status );
		$changes[ $path ] = 0 === $status;
	}
	return $changes;
}

/**
 * Is every changed line in this path a version declaration?
 *
 * release-please rewrites the plugin version in `diviops-agent.php` (the header
 * `Version:` and `const VERSION`) and in `readme.txt` (`Stable tag:`). Those files are
 * upstream-origin and sit under a guarded prefix, so without this the gate failed every
 * release commit — in CI as well as locally, which blocked the release rather than
 * merely annoying (#321).
 *
 * The question asked here is about CONTENT, deliberately. Exempting by commit author or
 * by branch name would trust a label instead of the diff, and would wave through
 * anything else that rode along in the same commit. If every added and removed line is a
 * version declaration then nothing diverged; if even one line is not, the file is a real
 * change and needs its row.
 *
 * `-U0` so only changed lines appear, and the `+++`/`---` file headers are skipped
 * explicitly rather than by relying on them never matching the pattern.
 *
 * A git invocation that fails returns false — "I could not read this diff" must land on
 * the side that keeps the gate accusing, never on the side that exempts.
 *
 * @param string $root Repository root.
 * @param string $base Base ref.
 * @param string $path Path to inspect.
 * @return bool
 */
function diviops_fork_ledger_version_only_change( string $root, string $base, string $path ): bool {
	$saw_change = false;

	foreach ( array( array( 'diff', '-U0', $base . '...HEAD', '--', $path ), array( 'diff', '-U0', 'HEAD', '--', $path ) ) as $args ) {
		$out = diviops_fork_ledger_git( $root, $args, $status );
		if ( 0 !== $status ) {
			return false;
		}

		foreach ( $out as $line ) {
			if ( '' === $line ) {
				continue;
			}
			if ( 0 === strpos( $line, '+++' ) || 0 === strpos( $line, '---' ) ) {
				continue;
			}
			if ( '+' !== $line[0] && '-' !== $line[0] ) {
				continue;
			}

			$saw_change = true;
			if ( ! diviops_fork_ledger_is_version_line( substr( $line, 1 ) ) ) {
				return false;
			}
		}
	}

	// No changed lines at all means the caller was wrong about this path having changed.
	// Reporting that as "version-only" would exempt a file on the strength of a diff that
	// produced nothing, which is the pass-while-inspecting-nothing shape this file exists
	// to avoid.
	return $saw_change;
}

/**
 * Is one line a version declaration?
 *
 * Each pattern requires an actual version NUMBER, so a line that merely mentions the
 * word cannot buy an exemption.
 *
 * @param string $line Line contents, without the diff's leading +/-.
 * @return bool
 */
function diviops_fork_ledger_is_version_line( string $line ): bool {
	$patterns = array(
		'/^\s*\*?\s*Version:\s*[0-9]+\.[0-9]+\.[0-9]+/',      // plugin header
		'/^\s*const\s+VERSION\s*=\s*.[0-9]+\.[0-9]+\.[0-9]+/', // class constant
		'/^\s*Stable tag:\s*[0-9]+\.[0-9]+\.[0-9]+/',          // readme.txt
		'/^\s*"version"\s*:\s*"[0-9]+\.[0-9]+\.[0-9]+"/',      // package.json / manifests
	);

	foreach ( $patterns as $pattern ) {
		if ( 1 === preg_match( $pattern, $line ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Decide whether a set of changed paths needs a FORK.md row it does not have.
 *
 * A brand-new file under a guarded path is exempt. The table exists to make an
 * upstream cherry-pick predictable, and a file upstream does not have cannot conflict
 * with an upstream change — there is nothing to reconcile. Requiring a row for every
 * new `diviops-server/src/__tests__/*.test.ts` would be noise that trains people to
 * add empty rows. Modifying, deleting, or renaming a file that exists at the base is
 * the case that costs a future sync, and that is what this catches.
 *
 * @param array<string, bool> $changes Path => whether it existed at the base ref.
 * @return array{status: string, reason: string, undocumented: array<int, string>}
 */
function diviops_fork_ledger_verdict( array $changes, array $version_only = array() ): array {
	if ( array() === $changes ) {
		return array(
			'status'       => 'no-changes',
			'reason'       => 'the base ref resolved but the diff named no changed paths, so there is nothing to record',
			'undocumented' => array(),
		);
	}

	$undocumented = array();
	$exempted     = 0;
	foreach ( $changes as $path => $existed_at_base ) {
		if ( ! $existed_at_base ) {
			continue;
		}
		foreach ( diviops_fork_ledger_guarded_prefixes() as $prefix ) {
			if ( 0 === strpos( (string) $path, $prefix ) ) {
				if ( in_array( (string) $path, $version_only, true ) ) {
					++$exempted;
					break;
				}
				$undocumented[] = (string) $path;
				break;
			}
		}
	}

	// Stated, never silent. "Nothing under a guarded prefix changed" and "everything that
	// changed there was a version bump" are different facts, and a reader deciding whether
	// to trust this gate needs to be able to tell them apart.
	$version_note = $exempted > 0
		? sprintf( '; disregarded %d guarded file(s) whose only change is a version declaration', $exempted )
		: '';

	if ( array() === $undocumented ) {
		return array(
			'status'       => 'clean',
			'reason'       => sprintf(
				'inspected %d changed path(s); none is an upstream-origin file needing a row%s',
				count( $changes ),
				$version_note
			),
			'undocumented' => array(),
		);
	}

	if ( array_key_exists( 'FORK.md', $changes ) ) {
		return array(
			'status'       => 'documented',
			'reason'       => sprintf(
				'%d upstream-origin file(s) changed and FORK.md changed with them: %s',
				count( $undocumented ),
				implode( ', ', $undocumented )
			),
			'undocumented' => array(),
		);
	}

	return array(
		'status'       => 'undocumented',
		'reason'       => sprintf(
			'%d upstream-origin file(s) changed with no FORK.md update: %s. Record what diverges and why in '
				. "FORK.md's divergence table, or the next upstream cherry-pick reconciles against an incomplete record",
			count( $undocumented ),
			implode( ', ', $undocumented )
		),
		'undocumented' => $undocumented,
	);
}

/**
 * Report whether this checkout's change records its divergence.
 *
 * @param string $root Repository root.
 * @param string $base Base ref to measure against.
 * @return array{status: string, reason: string, undocumented: array<int, string>, base: string}
 */
function diviops_fork_ledger_report( string $root, string $base ): array {
	$changes = diviops_fork_ledger_changes( $root, $base );

	if ( null === $changes ) {
		return array(
			'status'       => 'no-base',
			'reason'       => sprintf(
				'no diff to inspect: %s does not resolve to a commit in %s (a shallow or detached checkout). '
					. 'Nothing was inspected, so this is not a pass',
				$base,
				$root
			),
			'undocumented' => array(),
			'base'         => $base,
		);
	}

	// Only guarded, pre-existing paths are worth the extra git call — a new file is
	// already exempt and an unguarded one was never going to be accused.
	$version_only = array();
	foreach ( $changes as $path => $existed_at_base ) {
		if ( ! $existed_at_base ) {
			continue;
		}
		foreach ( diviops_fork_ledger_guarded_prefixes() as $prefix ) {
			if ( 0 === strpos( (string) $path, $prefix ) && diviops_fork_ledger_version_only_change( $root, $base, (string) $path ) ) {
				$version_only[] = (string) $path;
			}
		}
	}

	$verdict         = diviops_fork_ledger_verdict( $changes, $version_only );
	$verdict['base'] = $base;
	return $verdict;
}
