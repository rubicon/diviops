// SPDX-License-Identifier: MIT
/**
 * Integration test for the full path-handling pipeline behind
 * `createWpCli(...).run(command)`:
 *
 *   parseCommand(command)
 *     -> normalizeWpCliPathArgs(args, wpPath)   [wp-cli.ts]
 *     -> validateFilesystemFlags(args, safeRoot) [wp-cli-fs-validator.ts]
 *
 * The unit tests for each stage live in wp-cli-allowlist.test.ts and
 * wp-cli-fs-validator.test.ts. This file proves the stages compose
 * correctly: a relative path argument gets resolved against wpPath by the
 * first stage, and the safe-root check in the second stage still catches an
 * attempted escape once that relative path becomes absolute.
 */
import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { __wpCliTesting } from '../wp-cli.js';
import { resolveSafeFsRoot, validateFilesystemFlags } from '../wp-cli-fs-validator.js';

const { parseCommand, normalizeWpCliPathArgs } = __wpCliTesting;

describe('run(command) path pipeline: normalize -> FS-validate', () => {
  let wpPath: string;
  let safeRoot: string;

  beforeEach(() => {
    wpPath = mkdtempSync(join(tmpdir(), 'diviops-wppath-'));
    safeRoot = resolveSafeFsRoot(wpPath); // <wpPath>/.diviops-tmp
  });

  afterEach(() => {
    rmSync(wpPath, { recursive: true, force: true });
  });

  it('allows a relative "acf export" path that lands inside the safe root', () => {
    const args = normalizeWpCliPathArgs(
      parseCommand('acf export .diviops-tmp/schema.json'),
      wpPath,
    );
    const result = validateFilesystemFlags(args, safeRoot);
    assert.deepEqual(result, { allowed: true });
  });

  it('rejects a relative "acf export" path that resolves outside the safe root', () => {
    const args = normalizeWpCliPathArgs(
      parseCommand('acf export uploads/schema.json'),
      wpPath,
    );
    const result = validateFilesystemFlags(args, safeRoot);
    assert.equal(result.allowed, false);
  });

  it('rejects a traversal attempt that walks out of wpPath entirely via "acf export"', () => {
    const args = normalizeWpCliPathArgs(
      parseCommand('acf export ../../etc/passwd'),
      wpPath,
    );
    const result = validateFilesystemFlags(args, safeRoot);
    assert.equal(result.allowed, false);
  });

  it('allows a relative "export --dir=" path that lands inside the safe root', () => {
    const args = normalizeWpCliPathArgs(
      parseCommand('export --dir=.diviops-tmp/backup'),
      wpPath,
    );
    const result = validateFilesystemFlags(args, safeRoot);
    assert.deepEqual(result, { allowed: true });
  });

  it('rejects an "export --dir=" traversal that escapes wpPath', () => {
    const args = normalizeWpCliPathArgs(
      parseCommand('export --dir=../../var/www/html'),
      wpPath,
    );
    const result = validateFilesystemFlags(args, safeRoot);
    assert.equal(result.allowed, false);
  });

  it('an absolute --dir outside wpPath (already absolute, so normalizeWpCliPathArgs leaves it as-is) is still rejected by the FS validator', () => {
    const args = normalizeWpCliPathArgs(parseCommand('export --dir=/etc'), wpPath);
    const result = validateFilesystemFlags(args, safeRoot);
    assert.equal(result.allowed, false);
  });
});
