// SPDX-License-Identifier: MIT
import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, mkdirSync, symlinkSync, writeFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, sep } from 'node:path';

import {
  resolveSafeFsRoot,
  fsValidationDisabled,
  ensureSafeFsRoot,
  matchFsSensitiveCommand,
  isPathUnderSafeRoot,
  extractExportFlags,
  extractPositionalAfterPrefix,
  validateFilesystemFlags,
} from '../wp-cli-fs-validator.js';

const UNSAFE_ENV = 'DIVIOPS_WP_CLI_UNSAFE_FS';
const SAFE_ROOT_OVERRIDE_ENV = 'DIVIOPS_WP_CLI_SAFE_FS_ROOT';

function clearFsEnv() {
  delete process.env[UNSAFE_ENV];
  delete process.env[SAFE_ROOT_OVERRIDE_ENV];
}

beforeEach(clearFsEnv);
afterEach(clearFsEnv);

describe('resolveSafeFsRoot', () => {
  it('resolves to <wpPath>/.diviops-tmp when no override is set', () => {
    const wpPath = '/var/www/html';
    assert.equal(resolveSafeFsRoot(wpPath), resolve(wpPath, '.diviops-tmp'));
  });

  it('honors DIVIOPS_WP_CLI_SAFE_FS_ROOT when set', () => {
    process.env[SAFE_ROOT_OVERRIDE_ENV] = '/srv/safe-root';
    assert.equal(resolveSafeFsRoot('/var/www/html'), resolve('/srv/safe-root'));
  });

  it('trims whitespace on the override before resolving', () => {
    process.env[SAFE_ROOT_OVERRIDE_ENV] = '  /srv/safe-root  ';
    assert.equal(resolveSafeFsRoot('/var/www/html'), resolve('/srv/safe-root'));
  });

  it('ignores an override that is only whitespace and falls back to the default', () => {
    process.env[SAFE_ROOT_OVERRIDE_ENV] = '   ';
    assert.equal(resolveSafeFsRoot('/var/www/html'), resolve('/var/www/html', '.diviops-tmp'));
  });
});

describe('fsValidationDisabled', () => {
  it('is false when the escape hatch is unset', () => {
    assert.equal(fsValidationDisabled(), false);
  });

  it('is true for "1"', () => {
    process.env[UNSAFE_ENV] = '1';
    assert.equal(fsValidationDisabled(), true);
  });

  it('is true for "true" case-insensitively', () => {
    process.env[UNSAFE_ENV] = 'TRUE';
    assert.equal(fsValidationDisabled(), true);
  });

  it('is false for "0"', () => {
    process.env[UNSAFE_ENV] = '0';
    assert.equal(fsValidationDisabled(), false);
  });

  it('is false for "false"', () => {
    process.env[UNSAFE_ENV] = 'false';
    assert.equal(fsValidationDisabled(), false);
  });

  it('is false for an unrelated truthy-looking value', () => {
    process.env[UNSAFE_ENV] = 'yes';
    assert.equal(fsValidationDisabled(), false);
  });
});

describe('ensureSafeFsRoot', () => {
  let tmp: string;

  beforeEach(() => {
    tmp = mkdtempSync(join(tmpdir(), 'diviops-fsval-'));
  });

  afterEach(() => {
    rmSync(tmp, { recursive: true, force: true });
  });

  it('creates the directory recursively when missing', () => {
    const target = join(tmp, 'a', 'b', '.diviops-tmp');
    assert.equal(existsSync(target), false);
    ensureSafeFsRoot(target);
    assert.equal(existsSync(target), true);
  });

  it('is idempotent when the directory already exists', () => {
    const target = join(tmp, '.diviops-tmp');
    ensureSafeFsRoot(target);
    assert.doesNotThrow(() => ensureSafeFsRoot(target));
  });

  it('does not throw when the path cannot be created (parent is a file)', () => {
    const blocker = join(tmp, 'blocker-file');
    writeFileSync(blocker, 'not a directory');
    const impossible = join(blocker, 'child');
    assert.doesNotThrow(() => ensureSafeFsRoot(impossible));
    assert.equal(existsSync(impossible), false);
  });
});

describe('matchFsSensitiveCommand', () => {
  it('matches the bare "export" command', () => {
    assert.equal(matchFsSensitiveCommand(['export', '--dir=/x']), 'export');
  });

  it('matches "acf export" as a two-word prefix', () => {
    assert.equal(matchFsSensitiveCommand(['acf', 'export', '/path']), 'acf export');
  });

  it('matches "acf import" as a two-word prefix', () => {
    assert.equal(matchFsSensitiveCommand(['acf', 'import', '/path']), 'acf import');
  });

  it('matches "scf json export" as a three-word prefix', () => {
    assert.equal(matchFsSensitiveCommand(['scf', 'json', 'export', '--dir=/x']), 'scf json export');
  });

  it('matches "scf json import" as a three-word prefix', () => {
    assert.equal(matchFsSensitiveCommand(['scf', 'json', 'import', '/path']), 'scf json import');
  });

  it('matches "acf json export" / "acf json import" aliases', () => {
    assert.equal(matchFsSensitiveCommand(['acf', 'json', 'export', '--dir=/x']), 'acf json export');
    assert.equal(matchFsSensitiveCommand(['acf', 'json', 'import', '/path']), 'acf json import');
  });

  it('prefers the longer prefix match over a shorter one that shares a trailing word', () => {
    // "scf json export" must be identified as the SCF command, not the bare
    // "export" command, even though both end in the word "export".
    assert.equal(matchFsSensitiveCommand(['scf', 'json', 'export']), 'scf json export');
  });

  it('does not match a non-FS-sensitive command', () => {
    assert.equal(matchFsSensitiveCommand(['post', 'list']), null);
  });

  it('does not match "scf json sync" (not FS-sensitive)', () => {
    assert.equal(matchFsSensitiveCommand(['scf', 'json', 'sync']), null);
  });

  it('returns null for an empty arg vector', () => {
    assert.equal(matchFsSensitiveCommand([]), null);
  });

  it('does not treat "eval-file" as FS-sensitive (documented scope gap, not a bug)', () => {
    // wp-cli-fs-validator.ts's own header comment states EXTENDED-tier FS
    // commands (import, eval-file) are opt-in via DIVIOPS_WP_CLI_ALLOW and
    // are "tracked separately as a future scope expansion" — not validated
    // here. This locks in that documented gap so a future change doesn't
    // silently narrow or widen it without a corresponding test update.
    assert.equal(matchFsSensitiveCommand(['eval-file', '/etc/passwd']), null);
  });

  it('does not treat bare "import" as FS-sensitive (same documented scope gap)', () => {
    assert.equal(matchFsSensitiveCommand(['import', '/tmp/backup.xml']), null);
  });
});

describe('extractExportFlags', () => {
  it('returns all-empty defaults when nothing is present', () => {
    assert.deepEqual(extractExportFlags(['export']), {
      dir: null,
      filenameFormat: null,
      stdout: false,
    });
  });

  it('parses --dir=<value> form', () => {
    assert.deepEqual(extractExportFlags(['export', '--dir=/tmp/out']), {
      dir: '/tmp/out',
      filenameFormat: null,
      stdout: false,
    });
  });

  it('parses --dir <value> (space-separated) form', () => {
    assert.deepEqual(extractExportFlags(['export', '--dir', '/tmp/out']), {
      dir: '/tmp/out',
      filenameFormat: null,
      stdout: false,
    });
  });

  it('parses --filename_format=<value> form', () => {
    assert.deepEqual(
      extractExportFlags(['export', '--filename_format={site}.{date}.xml']),
      { dir: null, filenameFormat: '{site}.{date}.xml', stdout: false },
    );
  });

  it('parses --filename_format <value> (space-separated) form', () => {
    assert.deepEqual(
      extractExportFlags(['export', '--filename_format', '{site}.xml']),
      { dir: null, filenameFormat: '{site}.xml', stdout: false },
    );
  });

  it('parses --stdout', () => {
    assert.deepEqual(extractExportFlags(['export', '--stdout']), {
      dir: null,
      filenameFormat: null,
      stdout: true,
    });
  });

  it('parses a combination of all three flags', () => {
    assert.deepEqual(
      extractExportFlags(['export', '--dir=/tmp/out', '--filename_format={site}.xml', '--stdout']),
      { dir: '/tmp/out', filenameFormat: '{site}.xml', stdout: true },
    );
  });

  it('does NOT recognize the legacy --filename flag (only --filename_format is real)', () => {
    // wp-cli's `wp export` has no `--filename` flag; only `--filename_format`.
    // Confirms the validator inspects the flag wp-cli actually reads, not one
    // that wp-cli silently ignores.
    assert.deepEqual(extractExportFlags(['export', '--filename=../../evil.xml']), {
      dir: null,
      filenameFormat: null,
      stdout: false,
    });
  });
});

describe('extractPositionalAfterPrefix', () => {
  it('returns the first positional argument after the prefix', () => {
    assert.equal(extractPositionalAfterPrefix(['acf', 'export', '/path/to/file.json'], 2), '/path/to/file.json');
  });

  it('skips flags before the positional', () => {
    assert.equal(
      extractPositionalAfterPrefix(['acf', 'export', '--json', '/path/to/file.json'], 2),
      '/path/to/file.json',
    );
  });

  it('returns null when no positional is present', () => {
    assert.equal(extractPositionalAfterPrefix(['acf', 'export', '--json'], 2), null);
  });

  it('returns null when the prefix length exceeds the arg vector', () => {
    assert.equal(extractPositionalAfterPrefix(['acf', 'export'], 5), null);
  });

  it('works for the 3-word "scf json import" prefix length', () => {
    assert.equal(
      extractPositionalAfterPrefix(['scf', 'json', 'import', '/path/to/file.json'], 3),
      '/path/to/file.json',
    );
  });
});

describe('isPathUnderSafeRoot', () => {
  let root: string;

  beforeEach(() => {
    root = mkdtempSync(join(tmpdir(), 'diviops-saferoot-'));
  });

  afterEach(() => {
    rmSync(root, { recursive: true, force: true });
  });

  it('accepts the safe root itself', () => {
    assert.equal(isPathUnderSafeRoot(root, root), true);
  });

  it('accepts the safe root with a trailing separator', () => {
    assert.equal(isPathUnderSafeRoot(root + sep, root), true);
  });

  it('accepts an existing subdirectory of the safe root', () => {
    const sub = join(root, 'sub');
    mkdirSync(sub);
    assert.equal(isPathUnderSafeRoot(sub, root), true);
  });

  it('accepts a non-existing path whose parent is under the safe root', () => {
    // wp export --dir=<new-dir> — the directory doesn't exist yet.
    const notYetCreated = join(root, 'brand-new-export-dir');
    assert.equal(existsSync(notYetCreated), false);
    assert.equal(isPathUnderSafeRoot(notYetCreated, root), true);
  });

  it('accepts a deeply nested non-existing path under the safe root', () => {
    const deep = join(root, 'a', 'b', 'c', 'd');
    assert.equal(isPathUnderSafeRoot(deep, root), true);
  });

  it('rejects a relative path', () => {
    assert.equal(isPathUnderSafeRoot('relative/path', root), false);
  });

  it('rejects an empty path', () => {
    assert.equal(isPathUnderSafeRoot('', root), false);
  });

  it('rejects a path that escapes via ../ traversal', () => {
    const escaped = join(root, '..', 'evil');
    assert.equal(isPathUnderSafeRoot(escaped, root), false);
  });

  it('rejects a deep ../../../ traversal that lands outside the root', () => {
    const escaped = join(root, 'a', 'b', '..', '..', '..', 'evil');
    assert.equal(isPathUnderSafeRoot(escaped, root), false);
  });

  it('accepts a relative-looking traversal that normalizes back inside the root', () => {
    // root/sub/../sibling normalizes to root/sibling — still inside root.
    const staysInside = join(root, 'sub', '..', 'sibling');
    assert.equal(isPathUnderSafeRoot(staysInside, root), true);
  });

  it('rejects a sibling directory that merely shares the root as a string prefix', () => {
    // Guards the classic "/safe-root-evil" passing a naive startsWith("/safe-root") check.
    const lookalike = root + '-evil';
    assert.equal(isPathUnderSafeRoot(lookalike, root), false);
  });

  it('does not treat percent-encoded traversal sequences as real traversal', () => {
    // Node's fs functions never URL-decode path segments, so "%2e%2e" is a
    // literal directory name, not "..". A path nested under the safe root
    // using this literal segment name is genuinely inside the root.
    const literalSegment = join(root, '%2e%2e', 'file.json');
    assert.equal(isPathUnderSafeRoot(literalSegment, root), true);
  });

  it('rejects a symlink inside the safe root that points outside it', () => {
    const outside = mkdtempSync(join(tmpdir(), 'diviops-outside-'));
    try {
      const link = join(root, 'escape-hatch');
      symlinkSync(outside, link);
      assert.equal(isPathUnderSafeRoot(link, root), false);
    } finally {
      rmSync(outside, { recursive: true, force: true });
    }
  });

  it('accepts a symlink inside the safe root that points to another location inside it', () => {
    const innerTarget = join(root, 'real-target');
    mkdirSync(innerTarget);
    const link = join(root, 'alias');
    symlinkSync(innerTarget, link);
    assert.equal(isPathUnderSafeRoot(link, root), true);
  });
});

describe('validateFilesystemFlags', () => {
  let root: string;

  beforeEach(() => {
    root = mkdtempSync(join(tmpdir(), 'diviops-validate-'));
  });

  afterEach(() => {
    rmSync(root, { recursive: true, force: true });
  });

  it('allows any command outside the FS-sensitive set regardless of args', () => {
    const result = validateFilesystemFlags(['post', 'list', '--format=json'], root);
    assert.deepEqual(result, { allowed: true });
  });

  describe('wp export', () => {
    it('rejects when neither --dir nor --stdout is given', () => {
      const result = validateFilesystemFlags(['export'], root);
      assert.equal(result.allowed, false);
      assert.match(result.reason ?? '', /requires an explicit --dir/);
    });

    it('allows --stdout with no --dir', () => {
      const result = validateFilesystemFlags(['export', '--stdout'], root);
      assert.deepEqual(result, { allowed: true });
    });

    it('rejects a relative --dir', () => {
      const result = validateFilesystemFlags(['export', '--dir=relative/out'], root);
      assert.equal(result.allowed, false);
      assert.match(result.reason ?? '', /relative path/);
    });

    it('rejects a --dir that resolves outside the safe root', () => {
      const outside = mkdtempSync(join(tmpdir(), 'diviops-outside-'));
      try {
        const result = validateFilesystemFlags(['export', `--dir=${outside}`], root);
        assert.equal(result.allowed, false);
        assert.match(result.reason ?? '', /resolves outside the safe filesystem root/);
      } finally {
        rmSync(outside, { recursive: true, force: true });
      }
    });

    it('rejects a --dir that escapes the safe root via ../ traversal', () => {
      const escaped = join(root, '..', 'evil-export-dir');
      const result = validateFilesystemFlags(['export', `--dir=${escaped}`], root);
      assert.equal(result.allowed, false);
    });

    it('allows a --dir inside the safe root', () => {
      const inside = join(root, 'export-out');
      const result = validateFilesystemFlags(['export', `--dir=${inside}`], root);
      assert.deepEqual(result, { allowed: true });
    });

    it('allows --dir equal to the safe root itself', () => {
      const result = validateFilesystemFlags(['export', `--dir=${root}`], root);
      assert.deepEqual(result, { allowed: true });
    });

    it('rejects a --filename_format containing a forward slash', () => {
      const inside = join(root, 'export-out');
      const result = validateFilesystemFlags(
        ['export', `--dir=${inside}`, '--filename_format=../../evil.xml'],
        root,
      );
      assert.equal(result.allowed, false);
      assert.match(result.reason ?? '', /path separators/);
    });

    it('rejects a --filename_format containing a backslash', () => {
      const inside = join(root, 'export-out');
      const result = validateFilesystemFlags(
        ['export', `--dir=${inside}`, '--filename_format=..\\..\\evil.xml'],
        root,
      );
      assert.equal(result.allowed, false);
    });

    it('allows a --filename_format template with no path separators', () => {
      const inside = join(root, 'export-out');
      const result = validateFilesystemFlags(
        ['export', `--dir=${inside}`, '--filename_format={site}.{date}.{n}.xml'],
        root,
      );
      assert.deepEqual(result, { allowed: true });
    });
  });

  describe('acf export / acf import', () => {
    it('allows wp-cli to surface its own error when the positional is missing', () => {
      const result = validateFilesystemFlags(['acf', 'export'], root);
      assert.deepEqual(result, { allowed: true });
    });

    it('rejects a relative positional path', () => {
      const result = validateFilesystemFlags(['acf', 'export', 'relative/file.json'], root);
      assert.equal(result.allowed, false);
      assert.match(result.reason ?? '', /relative path/);
    });

    it('rejects a positional path outside the safe root', () => {
      const outside = mkdtempSync(join(tmpdir(), 'diviops-outside-'));
      try {
        const result = validateFilesystemFlags(['acf', 'import', join(outside, 'file.json')], root);
        assert.equal(result.allowed, false);
      } finally {
        rmSync(outside, { recursive: true, force: true });
      }
    });

    it('allows a positional path inside the safe root', () => {
      const result = validateFilesystemFlags(['acf', 'export', join(root, 'schema.json')], root);
      assert.deepEqual(result, { allowed: true });
    });
  });

  describe('scf json export / acf json export', () => {
    it('allows wp-cli to surface its own error when --dir is missing', () => {
      const result = validateFilesystemFlags(['scf', 'json', 'export'], root);
      assert.deepEqual(result, { allowed: true });
    });

    it('allows --stdout with no --dir', () => {
      const result = validateFilesystemFlags(['scf', 'json', 'export', '--stdout'], root);
      assert.deepEqual(result, { allowed: true });
    });

    it('rejects a relative --dir', () => {
      const result = validateFilesystemFlags(['scf', 'json', 'export', '--dir=relative'], root);
      assert.equal(result.allowed, false);
    });

    it('rejects a --dir outside the safe root', () => {
      const outside = mkdtempSync(join(tmpdir(), 'diviops-outside-'));
      try {
        const result = validateFilesystemFlags(['acf', 'json', 'export', `--dir=${outside}`], root);
        assert.equal(result.allowed, false);
      } finally {
        rmSync(outside, { recursive: true, force: true });
      }
    });

    it('allows a --dir inside the safe root', () => {
      const inside = join(root, 'scf-out');
      const result = validateFilesystemFlags(['scf', 'json', 'export', `--dir=${inside}`], root);
      assert.deepEqual(result, { allowed: true });
    });
  });

  describe('scf json import / acf json import', () => {
    it('allows wp-cli to surface its own error when the positional is missing', () => {
      const result = validateFilesystemFlags(['scf', 'json', 'import'], root);
      assert.deepEqual(result, { allowed: true });
    });

    it('rejects a relative positional path', () => {
      const result = validateFilesystemFlags(['scf', 'json', 'import', 'relative.json'], root);
      assert.equal(result.allowed, false);
    });

    it('rejects a positional path outside the safe root', () => {
      const outside = mkdtempSync(join(tmpdir(), 'diviops-outside-'));
      try {
        const result = validateFilesystemFlags(
          ['acf', 'json', 'import', join(outside, 'schema.json')],
          root,
        );
        assert.equal(result.allowed, false);
      } finally {
        rmSync(outside, { recursive: true, force: true });
      }
    });

    it('allows a positional path inside the safe root', () => {
      const result = validateFilesystemFlags(
        ['scf', 'json', 'import', join(root, 'schema.json')],
        root,
      );
      assert.deepEqual(result, { allowed: true });
    });
  });

  describe('wrapper mode gate', () => {
    it('rejects an FS-sensitive command in wrapper mode with no explicit safe root override', () => {
      const result = validateFilesystemFlags(['export', '--stdout'], root, { isWrapper: true });
      assert.equal(result.allowed, false);
      assert.match(result.reason ?? '', /WP_CLI_CMD wrapper/);
    });

    it('rejects even a well-formed in-root --dir when in wrapper mode without the override', () => {
      const inside = join(root, 'export-out');
      const result = validateFilesystemFlags(['export', `--dir=${inside}`], root, { isWrapper: true });
      assert.equal(result.allowed, false);
    });

    it('validates normally in wrapper mode once the safe root override is set', () => {
      process.env[SAFE_ROOT_OVERRIDE_ENV] = root;
      const inside = join(root, 'export-out');
      const result = validateFilesystemFlags(['export', `--dir=${inside}`], root, { isWrapper: true });
      assert.deepEqual(result, { allowed: true });
    });

    it('still rejects an out-of-root --dir in wrapper mode with the override set', () => {
      process.env[SAFE_ROOT_OVERRIDE_ENV] = root;
      const outside = mkdtempSync(join(tmpdir(), 'diviops-outside-'));
      try {
        const result = validateFilesystemFlags(['export', `--dir=${outside}`], root, { isWrapper: true });
        assert.equal(result.allowed, false);
      } finally {
        rmSync(outside, { recursive: true, force: true });
      }
    });
  });
});
