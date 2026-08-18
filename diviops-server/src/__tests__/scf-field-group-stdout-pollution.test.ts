// SPDX-License-Identifier: MIT
/**
 * `diviops_scf_field_group_list` / `_get` against a polluted wp-cli stdout
 * stream (#167).
 *
 * These are end-to-end: `WP_CLI_CMD` is pointed at a real executable stub
 * before `../index.js` is imported, so the assertions run through the actual
 * registered tool handlers, the actual allowlist, the actual `execFile`, and
 * the actual envelope. Nothing is mocked — the stub simply plays back a
 * stdout fixture and exits 0, which is precisely what the reference site's
 * wp-cli does when PHP prints an imagick startup warning ahead of the JSON.
 *
 * Two separate defects are pinned here.
 *
 * 1. Both tools parsed the raw buffer, so any startup noise made them fail.
 *    `_list` at least said so (`wp_error`, "non-JSON output").
 *
 * 2. `_get` was worse. Its `post_name` lookup parsed the same polluted stream
 *    inside a bare `catch {}` that left `resolved` null, which fell through to
 *    `not_found` — reporting a specific, plausible, wrong answer ("that field
 *    group does not exist") for what was actually a parse failure, and sending
 *    the caller off to debug a missing field group that exists. A parse
 *    failure must never be able to surface as `not_found`.
 *
 * The genuine no-such-row case must keep returning `not_found`, so that is
 * asserted alongside — otherwise a fix could satisfy the pollution tests by
 * collapsing both outcomes into `wp_error`.
 */
import { describe, it, before, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { chmodSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/**
 * Verbatim capture from the reference site (Local by Flywheel), `wp post list
 * --post_type=acf-field-group --format=json`. wp-cli exits 0; the warning is
 * on stdout, ahead of the payload.
 */
const IMAGICK_WARNING =
  '\nWarning: PHP Startup: imagick: Unable to initialize module\n' +
  'Module compiled with module API=20230831\n' +
  'PHP    compiled with module API=20240924\n' +
  'These options need to match\n' +
  ' in Unknown on line 0\n';

const stubDir = mkdtempSync(join(tmpdir(), 'diviops-scf-stub-'));
const listFixture = join(stubDir, 'list.out');
const lookupFixture = join(stubDir, 'lookup.out');
const getFixture = join(stubDir, 'get.out');
const wpStub = join(stubDir, 'wp');

/**
 * Stands in for the `wp` binary. Dispatches on argv the same way the real one
 * would: `post list --name=…` is the `_get` key lookup, `post get` is the
 * payload fetch, anything else is `_list`. Always exits 0 — the whole point
 * of #167 is that a *successful* command can still emit unparsable stdout.
 */
writeFileSync(
  wpStub,
  `#!/bin/sh
for arg in "$@"; do
  case "$arg" in
    --name=*) cat ${JSON.stringify(lookupFixture)}; exit 0 ;;
  esac
done
if [ "$1" = "post" ] && [ "$2" = "get" ]; then
  cat ${JSON.stringify(getFixture)}
  exit 0
fi
cat ${JSON.stringify(listFixture)}
exit 0
`,
  'utf-8',
);
chmodSync(wpStub, 0o755);

// Read by index.ts at module load, and captured by createWpCli at
// construction time — both happen during the dynamic import below, so these
// must be set before it, and cannot be varied afterwards. Per-test variation
// goes through the fixture files instead.
process.env.WP_CLI_CMD = wpStub;
process.env.WP_PATH = stubDir;

type RecordedTool = { handler: (...args: unknown[]) => Promise<unknown> };
const handlers = new Map<string, RecordedTool>();

before(async () => {
  const index = await import('../index.js');
  const registry = index.finalizeProductionRegistryForHandshake({
    kind: 'ok',
    capabilities: {},
    pluginVersion: '99.0.0',
    proActive: false,
    availableTargets: {},
    activeModules: {},
    plugins: {},
  });
  registry.install({
    registerTool(name: string, _config: unknown, handler: unknown) {
      handlers.set(name, { handler: handler as (...args: unknown[]) => Promise<unknown> });
    },
    registerResource() {},
  });
});

type Envelope = {
  ok: boolean;
  data?: unknown;
  error?: { code: string; message: string; hint?: string };
};

/** Invoke a registered tool and decode the envelope it serialized. */
async function callTool(name: string, args: Record<string, unknown> = {}): Promise<Envelope> {
  const found = handlers.get(name);
  assert.ok(found, `${name} should be present in the finalized registry`);
  const result = (await found.handler(args, {
    signal: new AbortController().signal,
    requestId: 1,
    sendNotification: async () => {},
    sendRequest: async () => {},
  })) as { content: Array<{ text: string }> };
  return JSON.parse(result.content[0].text) as Envelope;
}

beforeEach(() => {
  writeFileSync(listFixture, '[]\n', 'utf-8');
  writeFileSync(lookupFixture, '[]\n', 'utf-8');
  writeFileSync(getFixture, '{}\n', 'utf-8');
});

describe('diviops_scf_field_group_list against polluted stdout', () => {
  it('returns the field groups despite a PHP startup warning on stdout', async () => {
    writeFileSync(
      listFixture,
      `${IMAGICK_WARNING}[{"ID":900,"post_name":"group_hero","post_title":"Hero","post_status":"publish","post_modified":"2026-08-14 12:00:00"}]\n`,
      'utf-8',
    );

    const envelope = await callTool('diviops_scf_field_group_list');

    assert.equal(envelope.ok, true, `expected success, got ${JSON.stringify(envelope.error)}`);
    assert.deepEqual(envelope.data, [
      {
        ID: 900,
        post_name: 'group_hero',
        post_title: 'Hero',
        post_status: 'publish',
        post_modified: '2026-08-14 12:00:00',
      },
    ]);
  });

  it('still reports an empty site as an empty list, not an error', async () => {
    writeFileSync(listFixture, `${IMAGICK_WARNING}[]\n`, 'utf-8');

    const envelope = await callTool('diviops_scf_field_group_list');

    assert.equal(envelope.ok, true);
    assert.deepEqual(envelope.data, []);
  });

  it('reports a genuinely unparsable stream as wp_error', async () => {
    writeFileSync(listFixture, 'Error: could not connect to the database\n', 'utf-8');

    const envelope = await callTool('diviops_scf_field_group_list');

    assert.equal(envelope.ok, false);
    assert.equal(envelope.error?.code, 'wp_error');
  });
});

describe('diviops_scf_field_group_get against polluted stdout', () => {
  it('resolves an ACF key despite a PHP startup warning on both wp-cli calls', async () => {
    writeFileSync(lookupFixture, `${IMAGICK_WARNING}[{"ID":900}]\n`, 'utf-8');
    writeFileSync(
      getFixture,
      `${IMAGICK_WARNING}{"ID":900,"post_name":"group_hero","post_status":"publish"}\n`,
      'utf-8',
    );

    const envelope = await callTool('diviops_scf_field_group_get', { key: 'group_hero' });

    assert.equal(envelope.ok, true, `expected success, got ${JSON.stringify(envelope.error)}`);
    assert.deepEqual(envelope.data, {
      ID: 900,
      post_name: 'group_hero',
      post_status: 'publish',
    });
  });

  it('never reports a lookup parse failure as not_found', async () => {
    // The defect this issue was filed for: unparsable stdout became "no such
    // field group", pointing the caller at diviops_scf_field_group_list to
    // find something that was there all along.
    writeFileSync(lookupFixture, 'Error: could not connect to the database\n', 'utf-8');

    const envelope = await callTool('diviops_scf_field_group_get', { key: 'group_hero' });

    assert.equal(envelope.ok, false);
    assert.notEqual(
      envelope.error?.code,
      'not_found',
      'a parse failure must not masquerade as a missing field group',
    );
    assert.equal(envelope.error?.code, 'wp_error');
  });

  it('surfaces the offending stdout in the lookup failure so the cause is visible', async () => {
    writeFileSync(lookupFixture, 'Error: could not connect to the database\n', 'utf-8');

    const envelope = await callTool('diviops_scf_field_group_get', { key: 'group_hero' });

    const detail = `${envelope.error?.message ?? ''} ${envelope.error?.hint ?? ''}`;
    assert.match(detail, /could not connect to the database/);
  });

  it('resolves a numeric post ID despite a warning, skipping the lookup entirely', async () => {
    // The tool has two entry shapes. A numeric ID goes straight to `wp post
    // get`, so it exercises the payload parse without the lookup in front of
    // it — a branch the key-based cases never reach.
    writeFileSync(
      getFixture,
      `${IMAGICK_WARNING}{"ID":900,"post_name":"group_hero","post_status":"publish"}\n`,
      'utf-8',
    );

    const envelope = await callTool('diviops_scf_field_group_get', { key: '900' });

    assert.equal(envelope.ok, true, `expected success, got ${JSON.stringify(envelope.error)}`);
    assert.deepEqual(envelope.data, {
      ID: 900,
      post_name: 'group_hero',
      post_status: 'publish',
    });
  });

  it('refuses a lookup row that carries no numeric ID instead of fetching post "undefined"', async () => {
    // `parseWpCliJson` guarantees valid JSON, not the shape this call site
    // expects. Without a shape check the cast to `Array<{ID:number}>` is
    // unchecked, `String(rows[0].ID)` becomes the literal string "undefined",
    // and the server goes on to run `wp post get undefined` — a wrong answer
    // built on a value that was never there.
    writeFileSync(lookupFixture, '[0]\n', 'utf-8');

    const envelope = await callTool('diviops_scf_field_group_get', { key: 'group_hero' });

    assert.equal(envelope.ok, false);
    assert.equal(envelope.error?.code, 'wp_error');
  });

  it('still reports a genuinely absent key as not_found', async () => {
    // The empty-but-valid lookup. If a fix collapsed every lookup failure
    // into wp_error, this is what would regress.
    writeFileSync(lookupFixture, `${IMAGICK_WARNING}[]\n`, 'utf-8');

    const envelope = await callTool('diviops_scf_field_group_get', { key: 'group_missing' });

    assert.equal(envelope.ok, false);
    assert.equal(envelope.error?.code, 'not_found');
  });
});
