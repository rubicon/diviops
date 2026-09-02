// SPDX-License-Identifier: MIT
/**
 * `WP_CLI_CMD` pointed at a remote host over ssh (#344).
 *
 * `createWpCli` splits `WP_CLI_CMD` into an executable plus prefix args and
 * hands the whole thing to `execFile`, which passes argv to the child with no
 * shell in between. That is exactly right for a local wrapper — `ddev wp`,
 * `docker exec … wp` — because the wrapper forwards argv element for element.
 *
 * ssh does not. It concatenates everything after the host into one string and
 * the *remote* shell re-parses it, so per-argument boundaries are gone by the
 * time wp-cli sees them. Reproduced against a real host on 2026-09-01:
 *
 *   WP_CLI_CMD="ssh HOST wp --path=/srv/site"
 *   wp eval 'echo wp_get_environment_type();'
 *     → bash: -c: line 0: syntax error near unexpected token `('
 *
 * The remote shell is the one complaining, so the message reads like a
 * malformed wp-cli command. That misdirection is the expensive part.
 *
 * No remote host is involved here. Two executable fixtures stand in for the
 * two halves of the boundary:
 *
 *   - `remote-boundary` reproduces ssh's argv concatenation exactly
 *     (`exec bash -c "$*"`), and is what every assertion below runs through.
 *   - `wp-shim` is the re-quoting wrapper the docs now prescribe, built on the
 *     same boundary. It is here so the documented recipe cannot quietly stop
 *     working: if `printf '%q '` ever ceased to survive the round trip, these
 *     assertions fail rather than the operator finding out on a live site.
 *
 * The naive half is a characterization test — it pins ssh's behaviour, which
 * this codebase cannot change. What #344 changes is that the operator is told:
 * a startup warning when `WP_CLI_CMD` begins with `ssh`, and a spawn-failure
 * hint that names the connection rather than only the binary.
 */
import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { chmodSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import {
  createWpCli,
  describeWpCliFailure,
  __wpCliTesting,
} from '../wp-cli.js';

const fixtureDir = mkdtempSync(join(tmpdir(), 'diviops-remote-wrapper-'));

/** Writes an executable fixture and returns its path. */
function fixture(name: string, body: string): string {
  const path = join(fixtureDir, name);
  writeFileSync(path, body, 'utf-8');
  chmodSync(path, 0o755);
  return path;
}

/**
 * Stands in for the remote `wp`. Prints one argv element per line so a test can
 * assert on argument boundaries rather than on downstream behaviour — the whole
 * defect is that boundaries are lost, and a command that merely "worked" would
 * hide it.
 */
const argvDump = fixture(
  'argv-dump',
  `#!/bin/sh
for arg in "$@"; do
  printf '%s\\n' "$arg"
done
`,
);

/**
 * Stands in for `ssh HOST …`. `"$*"` joins the remaining argv with a single
 * space and `bash -c` re-parses the result, which is precisely what ssh does
 * to its command argv.
 */
const remoteBoundary = fixture(
  'remote-boundary',
  `#!/bin/bash
exec bash -c "$*"
`,
);

/**
 * The shim documented in SETUP.md, with the fixture boundary in place of ssh.
 * `printf '%q '` re-quotes each argument so the remote shell's re-parse
 * reconstructs the original argv.
 */
const wpShim = fixture(
  'wp-shim',
  `#!/bin/bash
exec ${JSON.stringify(remoteBoundary)} "${argvDump} $(printf '%q ' "$@")"
`,
);

/** Slow enough to outlast a short timeout, short enough not to slow the suite. */
const slowWp = fixture(
  'slow-wp',
  `#!/bin/sh
sleep 3
echo done
`,
);

/**
 * Build a runner over one of the fixtures. `wpPath` is only the wrapper's cwd
 * in `WP_CLI_CMD` mode, so the fixture directory serves.
 */
function runnerFor(wpCliCmd: string) {
  return createWpCli({ wpPath: fixtureDir, wpCliCmd });
}

after(() => {
  delete process.env.DIVIOPS_WP_CLI_TIMEOUT_MS;
});

describe('#344 (a) argv across a shell boundary', () => {
  it('loses argument boundaries through a bare ssh-shaped wrapper', async () => {
    const wp = runnerFor(`${remoteBoundary} ${argvDump}`);

    const result = await wp.runArgs(['option', 'get', 'two words']);

    assert.equal(result.success, true, 'the command itself succeeds — that is what makes this silent');
    assert.deepEqual(
      result.stdout.split('\n').filter(Boolean),
      ['option', 'get', 'two', 'words', '--no-color'],
      'the single argument "two words" arrived as two arguments',
    );
  });

  it('surfaces a remote shell syntax error, not a wp-cli error, on a quoted value', async () => {
    const wp = runnerFor(`${remoteBoundary} ${argvDump}`);

    const result = await wp.runArgs(['option', 'get', 'echo wp_get_environment_type();']);

    assert.equal(result.success, false);
    assert.match(
      result.stderr,
      /syntax error near unexpected token/,
      'the failure reads as a shell problem even though the caller passed a well-formed argument',
    );
  });

  it('delivers argv verbatim through the documented re-quoting shim', async () => {
    const wp = runnerFor(wpShim);

    const result = await wp.runArgs(['option', 'get', 'two words']);

    assert.equal(result.success, true);
    assert.deepEqual(
      result.stdout.split('\n').filter(Boolean),
      ['option', 'get', 'two words', '--no-color'],
      'the shim reconstructs the original argument boundaries',
    );
  });

  it('carries parentheses and quotes through the shim intact', async () => {
    const wp = runnerFor(wpShim);

    const result = await wp.runArgs(['option', 'get', "echo wp_get_environment_type(); 'quoted'"]);

    assert.equal(result.success, true, `expected success, got ${result.error}`);
    assert.deepEqual(
      result.stdout.split('\n').filter(Boolean),
      ['option', 'get', "echo wp_get_environment_type(); 'quoted'", '--no-color'],
      'the exact payload that fails through a bare ssh wrapper survives the shim',
    );
  });
});

describe('#344 (a) startup warning for an ssh wrapper', () => {
  /** Capture console.warn for the duration of one construction. */
  function warningsWhileBuilding(wpCliCmd: string): string[] {
    const captured: string[] = [];
    const original = console.warn;
    console.warn = (...args: unknown[]) => {
      captured.push(args.map(String).join(' '));
    };
    try {
      createWpCli({ wpPath: fixtureDir, wpCliCmd });
    } finally {
      console.warn = original;
    }
    return captured;
  }

  it('warns that a bare ssh wrapper will not preserve quoting', () => {
    const warnings = warningsWhileBuilding('ssh host wp --path=/srv/site');

    assert.equal(warnings.length, 1, `expected exactly one warning, got ${JSON.stringify(warnings)}`);
    assert.match(warnings[0], /ssh/i);
    assert.match(
      warnings[0],
      /quot/i,
      'the warning has to name quoting — that is the failure it is predicting',
    );
  });

  it('still warns when ssh carries options or an absolute path', () => {
    assert.equal(warningsWhileBuilding('ssh -o BatchMode=yes host wp').length, 1);
    assert.equal(warningsWhileBuilding('/usr/bin/ssh host wp').length, 1);
  });

  it('stays quiet for a local wrapper', () => {
    assert.deepEqual(warningsWhileBuilding('ddev wp'), []);
    assert.deepEqual(warningsWhileBuilding('docker exec -u www-data devkinsta_fpm wp'), []);
    // A shim script is the fix, not the problem — its name must not trip the check
    // just because "ssh" appears in it.
    assert.deepEqual(warningsWhileBuilding('/usr/local/bin/wp-over-ssh.sh'), []);
  });
});

describe('#344 (b) configurable wp-cli timeout', () => {
  const { resolveWpCliTimeoutMs } = __wpCliTesting;

  it('defaults to 30s when unset', () => {
    delete process.env.DIVIOPS_WP_CLI_TIMEOUT_MS;
    assert.equal(resolveWpCliTimeoutMs(), 30_000);
  });

  it('accepts a positive integer override', () => {
    process.env.DIVIOPS_WP_CLI_TIMEOUT_MS = '120000';
    assert.equal(resolveWpCliTimeoutMs(), 120_000);
    delete process.env.DIVIOPS_WP_CLI_TIMEOUT_MS;
  });

  it('falls back to the default on a value that is not a positive integer', () => {
    for (const bad of ['0', '-1', 'soon', '1.5', '']) {
      process.env.DIVIOPS_WP_CLI_TIMEOUT_MS = bad;
      assert.equal(
        resolveWpCliTimeoutMs(),
        30_000,
        `"${bad}" is not a usable timeout and must not silently become one`,
      );
    }
    delete process.env.DIVIOPS_WP_CLI_TIMEOUT_MS;
  });

  it('actually shortens the deadline the child is given', async () => {
    process.env.DIVIOPS_WP_CLI_TIMEOUT_MS = '250';
    const wp = runnerFor(slowWp);
    delete process.env.DIVIOPS_WP_CLI_TIMEOUT_MS;

    const started = Date.now();
    const result = await wp.runArgs(['option', 'get', 'blogname']);
    const elapsed = Date.now() - started;

    assert.equal(result.success, false);
    assert.equal(result.failureKind, 'killed', `expected a timeout kill, got ${result.error}`);
    assert.ok(
      elapsed < 3_000,
      `the override has to be read at construction: the child ran ${elapsed}ms against a 250ms budget`,
    );
  });

  it('is captured at construction, so a later env change cannot move the deadline', async () => {
    delete process.env.DIVIOPS_WP_CLI_TIMEOUT_MS;
    const wp = runnerFor(`${argvDump}`);
    process.env.DIVIOPS_WP_CLI_TIMEOUT_MS = '1';

    const result = await wp.runArgs(['option', 'get', 'blogname']);
    delete process.env.DIVIOPS_WP_CLI_TIMEOUT_MS;

    assert.equal(result.success, true, 'a runner built under the default keeps the default');
  });
});

describe('#344 (c) spawn-failure hint names the transport in wrapper mode', () => {
  const spawnFailure = {
    error: 'Spawn failed: ETIMEDOUT',
    stderr: '',
    exitCode: null,
    failureKind: 'spawn_failed' as const,
  };

  it('names connection failure and ssh multiplexing when a wrapper is configured', () => {
    const { hint } = describeWpCliFailure(spawnFailure, { isWrapper: true });

    assert.match(hint, /connect/i, 'a remote wrapper fails at the connection far more often than at the binary');
    assert.match(hint, /ControlMaster/);
    assert.match(hint, /ControlPersist/);
  });

  it('keeps the binary/PATH causes, which are still real', () => {
    const { hint } = describeWpCliFailure(spawnFailure, { isWrapper: true });

    assert.match(hint, /ENOENT/);
    assert.match(hint, /EACCES/);
    assert.match(hint, /PATH/);
  });

  it('does not mention ssh when no wrapper is configured', () => {
    const { hint } = describeWpCliFailure(spawnFailure, { isWrapper: false });

    assert.match(hint, /ENOENT/);
    assert.doesNotMatch(
      hint,
      /ControlMaster/,
      'Local by Flywheel mode has no connection to multiplex; naming one would be a wrong lead',
    );
  });

  it('leaves the other three failure kinds alone', () => {
    const kinds = ['rejected', 'killed', 'exited'] as const;
    for (const failureKind of kinds) {
      const described = describeWpCliFailure(
        { error: 'detail', stderr: 'stream', exitCode: failureKind === 'exited' ? 3 : null, failureKind },
        { isWrapper: true },
      );
      assert.equal(described.kind, failureKind);
      assert.doesNotMatch(
        described.hint,
        /ControlMaster/,
        `${failureKind} is not a transport failure and must not be told to configure multiplexing`,
      );
    }
  });

  it('reports the timeout kill as raisable now that the timeout is configurable', () => {
    const { hint } = describeWpCliFailure(
      { error: 'Command timed out', stderr: '', exitCode: null, failureKind: 'killed' },
      { isWrapper: true },
    );

    assert.match(
      hint,
      /DIVIOPS_WP_CLI_TIMEOUT_MS/,
      'the hint tells the caller to raise the timeout, so it must name the knob that does it',
    );
  });

  it('routes an absent failureKind to the exited branch, as the callers do', () => {
    const described = describeWpCliFailure(
      { error: 'boom', stderr: 'stream', exitCode: 1 },
      { isWrapper: false },
    );

    assert.equal(described.kind, 'exited');
    assert.equal(described.stderr, 'stream');
  });

  it('synthesizes stderr from the detail for the two kinds that never ran a child', () => {
    for (const failureKind of ['rejected', 'spawn_failed'] as const) {
      const described = describeWpCliFailure(
        { error: 'detail', stderr: '', exitCode: null, failureKind },
        { isWrapper: false },
      );
      assert.equal(described.stderr, 'detail', `${failureKind} has no child output to report`);
    }
  });
});
