// SPDX-License-Identifier: MIT
/**
 * Tests for the DEFAULT-tier command allowlist in wp-cli.ts, exercised via
 * the `__wpCliTesting` testing surface the module exports for this purpose.
 *
 * This file relies on `DIVIOPS_WP_CLI_ALLOW` being unset for the process, so
 * `isCommandAllowed` reflects the plain DEFAULT_COMMANDS allowlist with no
 * opt-in extensions. The npm script that runs this suite unsets the var
 * explicitly (`env -u DIVIOPS_WP_CLI_ALLOW`) so this holds regardless of the
 * ambient shell environment. See wp-cli-allow-optin.test.ts for the opt-in
 * mechanism itself.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { __wpCliTesting } from '../wp-cli.js';

const { isCommandAllowed, parseCommand, normalizeWpCliPathArgs } = __wpCliTesting;

// Mirrors DEFAULT_COMMANDS in wp-cli.ts. Kept as a separate literal here
// (rather than importing it, since it isn't exported) so this test fails
// loudly if the exported allowlist behavior ever drifts from what a reader
// of wp-cli.ts would expect to be permitted by default.
const DEFAULT_COMMANDS: readonly string[] = [
  'option get',
  'option list',
  'post list',
  'post get',
  'post create',
  'post update',
  'post meta get',
  'post meta list',
  'post meta set',
  'post meta update',
  'post term list',
  'post-type list',
  'post-type get',
  'taxonomy list',
  'term list',
  'term create',
  'term update',
  'acf export',
  'acf import',
  'acf field-group list',
  'acf field-group get',
  'scf json status',
  'scf json sync',
  'scf json import',
  'scf json export',
  'acf json status',
  'acf json sync',
  'acf json import',
  'acf json export',
  'user list',
  'cache flush',
  'transient delete',
  'rewrite flush',
  'export',
  'cron event list',
  'plugin list',
  'plugin update',
  'theme list',
  'menu list',
  'site url',
  'core version',
  'core check-update',
  'core is-installed',
  'core verify-checksums',
  'core language list',
  'db columns',
  'db size',
  'db tables',
  'db check',
  'db search',
];

// Mirrors EXTENDED_COMMANDS in wp-cli.ts — opt-in only, must be denied by
// default.
const EXTENDED_COMMANDS: readonly string[] = [
  'option update',
  'option delete',
  'post delete',
  'post meta delete',
  'term delete',
  'search-replace',
  'import',
  'plugin activate',
  'plugin deactivate',
  'eval-file',
];

describe('isCommandAllowed — permitted subcommands', () => {
  for (const cmd of DEFAULT_COMMANDS) {
    it(`allows "${cmd}"`, () => {
      const result = isCommandAllowed(cmd.split(' '));
      assert.equal(result.allowed, true, result.reason);
    });
  }

  it('allows a permitted 2-word prefix with trailing flags/args', () => {
    assert.equal(isCommandAllowed(['post', 'list', '--format=json']).allowed, true);
  });

  it('allows a permitted 3-word prefix with trailing flags/args', () => {
    assert.equal(isCommandAllowed(['post', 'meta', 'get', '1', 'my_key']).allowed, true);
  });

  it('allows the exact one-word "export" prefix with flags', () => {
    assert.equal(isCommandAllowed(['export', '--dir=/tmp/out']).allowed, true);
  });
});

describe('isCommandAllowed — deny-by-default for extended (opt-in) commands', () => {
  for (const cmd of EXTENDED_COMMANDS) {
    it(`denies "${cmd}" with no DIVIOPS_WP_CLI_ALLOW opt-in`, () => {
      const result = isCommandAllowed(cmd.split(' '));
      assert.equal(result.allowed, false);
    });
  }
});

describe('isCommandAllowed — deny-by-default for everything else', () => {
  it('rejects an empty command', () => {
    const result = isCommandAllowed([]);
    assert.equal(result.allowed, false);
    assert.equal(result.reason, 'Empty command');
  });

  it('rejects an entirely unknown command', () => {
    assert.equal(isCommandAllowed(['foo', 'bar']).allowed, false);
  });

  it('rejects "db query" (arbitrary SQL, explicitly excluded even though other "db" subcommands are allowed)', () => {
    assert.equal(isCommandAllowed(['db', 'query', 'SELECT 1']).allowed, false);
  });

  it('rejects "core language install" even though "core language list" is allowed', () => {
    // Regression guard for the exact case the source comments call out: the
    // prefix matcher must not let a mutating subcommand ride in on a
    // read-only sibling's allowlist entry.
    assert.equal(isCommandAllowed(['core', 'language', 'install', 'fr_FR']).allowed, false);
  });

  it('rejects bare "core language" with no subcommand', () => {
    assert.equal(isCommandAllowed(['core', 'language']).allowed, false);
  });

  it('rejects "plugin activate" (extended) even though "plugin list"/"plugin update" (default) are allowed', () => {
    assert.equal(isCommandAllowed(['plugin', 'activate', 'some-plugin']).allowed, false);
  });
});

describe('isCommandAllowed — injection-flavored command heads are rejected', () => {
  const payloads: Array<{ label: string; args: string[] }> = [
    { label: 'bare shell command', args: ['rm', '-rf', '/'] },
    { label: 'leading semicolon', args: [';', 'rm', '-rf', '/'] },
    { label: 'leading &&', args: ['&&', 'rm', '-rf', '/'] },
    { label: 'leading pipe', args: ['|', 'cat', '/etc/passwd'] },
    { label: 'backtick-wrapped token', args: ['`rm', '-rf', '/`'] },
    { label: 'command substitution token', args: ['$(rm', '-rf', '/)'] },
    { label: 'sh -c smuggling', args: ['sh', '-c', 'rm -rf /'] },
  ];

  for (const { label, args } of payloads) {
    it(`rejects when the command head is a ${label}`, () => {
      assert.equal(isCommandAllowed(args).allowed, false);
    });
  }

  it('rejects a semicolon appended directly to an otherwise-allowed word ("post list; rm -rf /")', () => {
    // parseCommand splits on whitespace only, so ";" stays glued to "list",
    // producing the token "list;" — it no longer equals "list" nor is
    // "post list;" a space-terminated match for the allowed "post list"
    // prefix, so this is correctly rejected.
    const args = parseCommand('post list; rm -rf /');
    assert.deepEqual(args, ['post', 'list;', 'rm', '-rf', '/']);
    assert.equal(isCommandAllowed(args).allowed, false);
  });
});

describe('isCommandAllowed — trailing shell metacharacters after a genuinely allowed prefix', () => {
  // These are NOT injection: isCommandAllowed matches "post list" exactly
  // and allows it regardless of what follows, by the same "trailing
  // flags/args" design documented above ("post list --format=json"). The
  // tokens "&&"/"rm"/"-rf"/"/" end up as literal, inert argv elements that
  // are handed to execFile — which never spawns a shell (no `shell: true`,
  // no `exec`/`spawn` with a command string) — so there is no shell present
  // to give "&&" its chaining meaning. wp-cli itself receives them as
  // unrecognized positional arguments to "post list" and errors or ignores
  // them; no second process is ever spawned. This is documented here rather
  // than asserted as a rejection, because asserting rejection would describe
  // a boundary this module never promises and doesn't need to: the actual
  // injection defense is "no shell in the exec path", not "the allowlist
  // parses shell grammar".
  it('allows "post list && rm -rf /" at the allowlist layer (harmless: execFile never invokes a shell)', () => {
    const args = parseCommand('post list && rm -rf /');
    assert.deepEqual(args, ['post', 'list', '&&', 'rm', '-rf', '/']);
    assert.equal(isCommandAllowed(args).allowed, true);
  });
});

describe('parseCommand — tokenization', () => {
  it('splits on plain whitespace', () => {
    assert.deepEqual(parseCommand('post list'), ['post', 'list']);
  });

  it('splits on newlines like any other whitespace', () => {
    assert.deepEqual(parseCommand('post\nlist'), ['post', 'list']);
  });

  it('collapses repeated whitespace without producing empty tokens', () => {
    assert.deepEqual(parseCommand('post    list'), ['post', 'list']);
  });

  it('keeps double-quoted content as a single token, stripping the quotes', () => {
    assert.deepEqual(
      parseCommand('post create --post_title="Hello World"'),
      ['post', 'create', '--post_title=Hello World'],
    );
  });

  it('keeps single-quoted content as a single token, stripping the quotes', () => {
    assert.deepEqual(
      parseCommand("post create --post_title='Hello World'"),
      ['post', 'create', '--post_title=Hello World'],
    );
  });

  it('supports backslash-escaping a literal space inside an unquoted token', () => {
    assert.deepEqual(parseCommand('acf export /tmp/my\\ file.json'), [
      'acf',
      'export',
      '/tmp/my file.json',
    ]);
  });

  it('treats semicolons, ampersands, pipes, backticks, and $() as ordinary token characters', () => {
    // parseCommand has no concept of shell grammar — every one of these
    // stays a literal character in whatever token it appears in.
    assert.deepEqual(parseCommand('a;b'), ['a;b']);
    assert.deepEqual(parseCommand('a && b'), ['a', '&&', 'b']);
    assert.deepEqual(parseCommand('a | b'), ['a', '|', 'b']);
    assert.deepEqual(parseCommand('`a`'), ['`a`']);
    assert.deepEqual(parseCommand('$(a)'), ['$(a)']);
  });

  it('known limitation: an apostrophe inside an unquoted token is silently dropped (mitigated by runArgs, not by parseCommand)', () => {
    // Documented in wp-cli.ts's own comments: an embedded "'" toggles quote
    // mode instead of being treated as a literal character, so a filename
    // like "it's-fine.json" loses the apostrophe rather than staying intact.
    // This is a data-correctness quirk, not an allowlist or path-traversal
    // bypass — the resulting token is still resolved and safe-root-checked
    // normally, it is just spelled wrong. Typed wrappers should call
    // `runArgs` (pre-built argv, no parseCommand step) to avoid it entirely.
    assert.deepEqual(parseCommand("acf export /tmp/it's-fine.json"), [
      'acf',
      'export',
      '/tmp/its-fine.json',
    ]);
  });
});

describe('normalizeWpCliPathArgs — relative path resolution ahead of allowlist/FS validation', () => {
  const wpPath = '/var/www/html';

  it('leaves non-path commands untouched', () => {
    const args = ['post', 'list', '--format=json'];
    assert.deepEqual(normalizeWpCliPathArgs(args, wpPath), args);
  });

  it('resolves a relative "acf export" positional against wpPath', () => {
    assert.deepEqual(
      normalizeWpCliPathArgs(['acf', 'export', 'relative/schema.json'], wpPath),
      ['acf', 'export', '/var/www/html/relative/schema.json'],
    );
  });

  it('resolves a relative "scf json import" positional against wpPath', () => {
    assert.deepEqual(
      normalizeWpCliPathArgs(['scf', 'json', 'import', 'relative/schema.json'], wpPath),
      ['scf', 'json', 'import', '/var/www/html/relative/schema.json'],
    );
  });

  it('resolves a relative "export --dir=" value against wpPath', () => {
    assert.deepEqual(
      normalizeWpCliPathArgs(['export', '--dir=relative/out'], wpPath),
      ['export', '--dir=/var/www/html/relative/out'],
    );
  });

  it('resolves a relative "export --dir <value>" (space form) against wpPath', () => {
    assert.deepEqual(
      normalizeWpCliPathArgs(['export', '--dir', 'relative/out'], wpPath),
      ['export', '--dir', '/var/www/html/relative/out'],
    );
  });

  it('leaves an absolute path outside wpPath unchanged (containment is the FS validator’s job, not this function’s)', () => {
    assert.deepEqual(
      normalizeWpCliPathArgs(['acf', 'export', '/etc/passwd'], wpPath),
      ['acf', 'export', '/etc/passwd'],
    );
  });
});
