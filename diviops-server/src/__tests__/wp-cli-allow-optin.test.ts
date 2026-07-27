/**
 * Tests for the DIVIOPS_WP_CLI_ALLOW opt-in mechanism (buildAllowlist() in
 * wp-cli.ts). The effective allowlist is computed once, at module load
 * time, from the env var present at that moment — so each case here needs
 * its own fresh module instance with the env var set before import.
 *
 * Dynamic `import()` with a distinct query string per case forces Node to
 * treat each import as a separate module instance (bypassing the ES module
 * cache), which reruns buildAllowlist() against whatever env is set at that
 * moment. The specifier is built at runtime (not a literal) so TypeScript
 * doesn't try to statically resolve the fake query string.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

const ALLOW_ENV = 'DIVIOPS_WP_CLI_ALLOW';
let caseCounter = 0;

async function freshWpCliModule(): Promise<any> {
  caseCounter += 1;
  const specifier: string = `../wp-cli.js?diviops-optin-test-case=${caseCounter}`;
  return import(specifier);
}

describe('DIVIOPS_WP_CLI_ALLOW opt-in mechanism', () => {
  it('grants exactly the requested extended command and nothing else', async () => {
    process.env[ALLOW_ENV] = 'post delete';
    const { __wpCliTesting } = await freshWpCliModule();
    delete process.env[ALLOW_ENV];

    assert.equal(__wpCliTesting.isCommandAllowed(['post', 'delete', '5']).allowed, true);
    assert.equal(__wpCliTesting.isCommandAllowed(['search-replace', 'old', 'new']).allowed, false);
    assert.equal(__wpCliTesting.isCommandAllowed(['eval-file', '/tmp/x.php']).allowed, false);
  });

  it('grants a comma-separated list of extended commands', async () => {
    process.env[ALLOW_ENV] = 'post delete, search-replace';
    const { __wpCliTesting } = await freshWpCliModule();
    delete process.env[ALLOW_ENV];

    assert.equal(__wpCliTesting.isCommandAllowed(['post', 'delete', '5']).allowed, true);
    assert.equal(__wpCliTesting.isCommandAllowed(['search-replace', 'old', 'new']).allowed, true);
    assert.equal(__wpCliTesting.isCommandAllowed(['eval-file', '/tmp/x.php']).allowed, false);
  });

  it('leaves the default allowlist untouched alongside the opt-in grant', async () => {
    process.env[ALLOW_ENV] = 'post delete';
    const { __wpCliTesting } = await freshWpCliModule();
    delete process.env[ALLOW_ENV];

    assert.equal(__wpCliTesting.isCommandAllowed(['post', 'list']).allowed, true);
  });

  it('ignores an unknown/unrecognized opt-in entry rather than granting it or crashing', async () => {
    process.env[ALLOW_ENV] = 'totally-made-up-command';
    const { __wpCliTesting } = await freshWpCliModule();
    delete process.env[ALLOW_ENV];

    assert.equal(
      __wpCliTesting.isCommandAllowed(['totally-made-up-command']).allowed,
      false,
    );
    // The default allowlist still functions normally.
    assert.equal(__wpCliTesting.isCommandAllowed(['post', 'list']).allowed, true);
  });

  it('does not grant a command that is neither in DEFAULT_COMMANDS nor EXTENDED_COMMANDS even when named verbatim in the opt-in list', async () => {
    process.env[ALLOW_ENV] = 'db query';
    const { __wpCliTesting } = await freshWpCliModule();
    delete process.env[ALLOW_ENV];

    // "db query" is neither a default nor an extended command — it is
    // deliberately absent from both lists (arbitrary SQL, no scoping). The
    // opt-in mechanism only recognizes EXTENDED_COMMANDS entries, so naming
    // it in DIVIOPS_WP_CLI_ALLOW must not grant it.
    assert.equal(__wpCliTesting.isCommandAllowed(['db', 'query', 'SELECT 1']).allowed, false);
  });

  it('the "*" wildcard grants every extended command', async () => {
    process.env[ALLOW_ENV] = '*';
    const { __wpCliTesting } = await freshWpCliModule();
    delete process.env[ALLOW_ENV];

    assert.equal(__wpCliTesting.isCommandAllowed(['search-replace', 'old', 'new']).allowed, true);
    assert.equal(__wpCliTesting.isCommandAllowed(['eval-file', '/tmp/x.php']).allowed, true);
    assert.equal(__wpCliTesting.isCommandAllowed(['option', 'delete', 'siteurl']).allowed, true);
    // The wildcard still does not reach "db query" — it is out of both lists
    // entirely, not merely gated behind the extended tier.
    assert.equal(__wpCliTesting.isCommandAllowed(['db', 'query', 'SELECT 1']).allowed, false);
  });

  it('the "all" alias behaves identically to "*"', async () => {
    process.env[ALLOW_ENV] = 'all';
    const { __wpCliTesting } = await freshWpCliModule();
    delete process.env[ALLOW_ENV];

    assert.equal(__wpCliTesting.isCommandAllowed(['plugin', 'activate', 'some-plugin']).allowed, true);
  });

  it('an unset env var reproduces the plain DEFAULT_COMMANDS allowlist', async () => {
    delete process.env[ALLOW_ENV];
    const { __wpCliTesting } = await freshWpCliModule();

    assert.equal(__wpCliTesting.isCommandAllowed(['post', 'list']).allowed, true);
    assert.equal(__wpCliTesting.isCommandAllowed(['post', 'delete', '5']).allowed, false);
  });
});
