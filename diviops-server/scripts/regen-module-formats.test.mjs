import { describe, it, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import {
  renderElementLine,
  renderModuleBlock,
  renderHeader,
  parseModuleNames,
  regenerateContent,
  fetchDumpAll,
} from './regen-module-formats.mjs';

describe('renderElementLine', () => {
  it('lists decoration group names alphabetically as backtick-wrapped dot-paths', () => {
    const line = renderElementLine('button', {
      decoration: { spacing: {}, background: {}, border: {} },
    });
    assert.equal(
      line,
      '- **button** — `button.decoration.background`, `button.decoration.border`, `button.decoration.spacing`'
    );
  });

  it('renders "no decoration groups" when the decoration object is empty', () => {
    const line = renderElementLine('content', { decoration: {} });
    assert.equal(line, '- **content** — _(no decoration groups)_');
  });

  it('renders "no decoration groups" when decoration is absent entirely', () => {
    const line = renderElementLine('content', {});
    assert.equal(line, '- **content** — _(no decoration groups)_');
  });

  it('appends an innerContent suffix when the key is present, regardless of its value', () => {
    const line = renderElementLine('title', { innerContent: {}, decoration: { font: {} } });
    assert.equal(line, '- **title** — `title.decoration.font` _(+innerContent)_');
  });

  it('appends an advanced suffix when the key is present', () => {
    const line = renderElementLine('module', { advanced: {}, decoration: { zIndex: {} } });
    assert.equal(line, '- **module** — `module.decoration.zIndex` _(+advanced)_');
  });

  it('joins both suffixes with innerContent before advanced when both are present', () => {
    const line = renderElementLine('imageIcon', {
      innerContent: {},
      advanced: {},
      decoration: { animation: {} },
    });
    assert.equal(
      line,
      '- **imageIcon** — `imageIcon.decoration.animation` _(+innerContent, +advanced)_'
    );
  });
});

describe('renderModuleBlock', () => {
  // Structurally modeled on a real Divi module's attribute shape, but with
  // synthetic key names — not copied from Divi's own module.json (which
  // carries Divi's own labels/descriptions), matching the existing
  // tests/fixtures/divi-module-library convention of synthetic-only fixtures.
  const fixtureEntry = {
    name: 'divi/fixture',
    attributes: {
      module: { settings: { decoration: { background: {}, border: {} }, advanced: {} } },
      title: { settings: { innerContent: {}, decoration: { font: {} } } },
      content: { settings: { innerContent: {} } },
      button: {
        settings: {
          innerContent: {},
          decoration: {
            background: {},
            border: {},
            boxShadow: {},
            button: {},
            font: {},
            sizing: {},
            spacing: {},
          },
        },
      },
      metadata: { settings: {} },
      className: { type: 'string' },
      style: { settings: {} },
      lock: { settings: {} },
    },
  };

  it('builds a full sentinel-wrapped block, keys sorted alphabetically, universal keys excluded', () => {
    const block = renderModuleBlock('divi/fixture', fixtureEntry);
    assert.equal(
      block,
      [
        '<!-- BEGIN GENERATED:module:divi/fixture -->',
        '',
        '<!-- TIER: free -->',
        '#### `divi/fixture`',
        '',
        '- **button** — `button.decoration.background`, `button.decoration.border`, `button.decoration.boxShadow`, `button.decoration.button`, `button.decoration.font`, `button.decoration.sizing`, `button.decoration.spacing` _(+innerContent)_',
        '- **content** — _(no decoration groups)_ _(+innerContent)_',
        '- **module** — `module.decoration.background`, `module.decoration.border` _(+advanced)_',
        '- **title** — `title.decoration.font` _(+innerContent)_',
        '',
        '<!-- END GENERATED:module:divi/fixture -->',
      ].join('\n')
    );
  });

  it('throws when no eligible attribute keys remain after excluding metadata/className/style/lock', () => {
    const entry = {
      name: 'divi/empty',
      attributes: {
        metadata: { settings: {} },
        className: { type: 'string' },
        style: { settings: {} },
        lock: { settings: {} },
      },
    };
    assert.throws(() => renderModuleBlock('divi/empty', entry), /divi\/empty/);
  });

  it('throws when the module entry has no attributes object at all', () => {
    const entry = { name: 'divi/broken' };
    assert.throws(() => renderModuleBlock('divi/broken', entry), /divi\/broken/);
  });
});

describe('renderHeader', () => {
  it('renders the sentinel-wrapped header with divi_version and a 12-char schema_version fingerprint', () => {
    const header = renderHeader({
      diviVersion: '5.9.0',
      schemaVersion: 'af7c9d795e77deadbeef1234567890fedcba098',
    });
    assert.equal(
      header,
      [
        '<!-- BEGIN GENERATED:header -->',
        '',
        '## Generated path index',
        '',
        '> Generated mechanically by `diviops-server/scripts/regen-module-formats.mjs` from `diviops_schema_get_module` dump-all output. Each module block lives between `BEGIN GENERATED:module:divi/<slug>` / `END GENERATED:module:divi/<slug>` HTML-comment sentinels (see `diviops-server/CONTRIBUTING.md` for the full convention). Do **not** edit between sentinels — edits are clobbered on regen.',
        '',
        '> Generated against Divi `5.9.0`, schema `af7c9d795e77…`.',
        '',
        'Per CLAUDE.md "Suite architecture coherence": schema dump is the canonical index; VB-verified prose above is the canonical interpretation. The two sections are complementary, not competing — prose explains surprises, this index enumerates paths exhaustively. On conflicts, the prose above wins (per `feedback_vb_first_verification`).',
        '',
        '<!-- END GENERATED:header -->',
      ].join('\n')
    );
  });
});

describe('parseModuleNames', () => {
  it('returns the divi/<slug> names of every existing module sentinel block, in file order', () => {
    const content = [
      '<!-- BEGIN GENERATED:header -->',
      'some header prose',
      '<!-- END GENERATED:header -->',
      '',
      '<!-- BEGIN GENERATED:module:divi/accordion -->',
      'stuff',
      '<!-- END GENERATED:module:divi/accordion -->',
      '',
      '<!-- BEGIN GENERATED:module:divi/button -->',
      'stuff',
      '<!-- END GENERATED:module:divi/button -->',
      '',
      'trailing footnote prose, not a sentinel',
    ].join('\n');

    assert.deepEqual(parseModuleNames(content), ['divi/accordion', 'divi/button']);
  });

  it('returns an empty array when the file has no module sentinel blocks', () => {
    const content = ['<!-- BEGIN GENERATED:header -->', 'prose only', '<!-- END GENERATED:header -->'].join(
      '\n'
    );
    assert.deepEqual(parseModuleNames(content), []);
  });
});

describe('regenerateContent', () => {
  const dumpAllData = {
    divi_version: '5.9.0',
    schema_version: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    modules: {
      'divi/fixture-one': {
        attributes: { module: { settings: { decoration: { background: {} } } } },
      },
      'divi/fixture-two': {
        attributes: { module: { settings: { decoration: { border: {} } } } },
      },
    },
  };

  const originalContent = [
    'prefix prose stays untouched',
    '',
    '<!-- BEGIN GENERATED:header -->',
    'old stale header',
    '<!-- END GENERATED:header -->',
    '',
    '<!-- BEGIN GENERATED:module:divi/fixture-one -->',
    'old stale content one',
    '<!-- END GENERATED:module:divi/fixture-one -->',
    '',
    '<!-- BEGIN GENERATED:module:divi/fixture-two -->',
    'old stale content two',
    '<!-- END GENERATED:module:divi/fixture-two -->',
    '',
    'trailing prose stays untouched',
  ].join('\n');

  it('replaces the header and every existing module block with freshly rendered content, leaving surrounding prose untouched', () => {
    const result = regenerateContent(originalContent, dumpAllData);

    const expected = [
      'prefix prose stays untouched',
      '',
      renderHeader({ diviVersion: '5.9.0', schemaVersion: dumpAllData.schema_version }),
      '',
      renderModuleBlock('divi/fixture-one', dumpAllData.modules['divi/fixture-one']),
      '',
      renderModuleBlock('divi/fixture-two', dumpAllData.modules['divi/fixture-two']),
      '',
      'trailing prose stays untouched',
    ].join('\n');

    assert.equal(result, expected);
  });

  it('throws when the file has no existing module sentinel blocks to regenerate', () => {
    const content = ['<!-- BEGIN GENERATED:header -->', 'x', '<!-- END GENERATED:header -->'].join('\n');
    assert.throws(() => regenerateContent(content, dumpAllData), /no module sentinel blocks/);
  });

  it('throws naming the module when an existing sentinel has no matching entry in the fresh dump', () => {
    const content = [
      '<!-- BEGIN GENERATED:header -->',
      'x',
      '<!-- END GENERATED:header -->',
      '<!-- BEGIN GENERATED:module:divi/does-not-exist -->',
      'x',
      '<!-- END GENERATED:module:divi/does-not-exist -->',
    ].join('\n');
    assert.throws(() => regenerateContent(content, dumpAllData), /divi\/does-not-exist/);
  });

  it('throws when a module sentinel exists but the header sentinel region is missing', () => {
    const content = [
      '<!-- BEGIN GENERATED:module:divi/fixture-one -->',
      'x',
      '<!-- END GENERATED:module:divi/fixture-one -->',
    ].join('\n');
    assert.throws(() => regenerateContent(content, dumpAllData), /header/);
  });
});

describe('fetchDumpAll', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('requests dump-all at a trailing-slash-stripped URL with a Basic Auth header built from wpUser/wpAppPassword', async () => {
    let capturedUrl;
    let capturedOptions;
    globalThis.fetch = async (url, options) => {
      capturedUrl = url;
      capturedOptions = options;
      return {
        ok: true,
        status: 200,
        json: async () => ({ ok: true, data: { divi_version: '5.9.0', schema_version: 'x', modules: {} } }),
      };
    };

    await fetchDumpAll({ wpUrl: 'http://example.test/', wpUser: 'admin', wpAppPassword: 'secretpass' });

    assert.equal(capturedUrl, 'http://example.test/wp-json/diviops/v1/schema/module/dump-all');
    assert.equal(
      capturedOptions.headers.Authorization,
      `Basic ${Buffer.from('admin:secretpass').toString('base64')}`
    );
  });

  it('returns data from a successful envelope', async () => {
    const data = { divi_version: '5.9.0', schema_version: 'abc', modules: {} };
    globalThis.fetch = async () => ({ ok: true, status: 200, json: async () => ({ ok: true, data }) });

    const result = await fetchDumpAll({ wpUrl: 'http://x', wpUser: 'u', wpAppPassword: 'p' });

    assert.deepEqual(result, data);
  });

  it('throws using the error envelope message on a non-2xx HTTP response', async () => {
    globalThis.fetch = async () => ({
      ok: false,
      status: 404,
      json: async () => ({ ok: false, error: { message: 'Not Found' } }),
    });

    await assert.rejects(() => fetchDumpAll({ wpUrl: 'http://x', wpUser: 'u', wpAppPassword: 'p' }), /Not Found/);
  });

  it('falls back to the HTTP status when the error envelope has no message', async () => {
    globalThis.fetch = async () => ({ ok: false, status: 500, json: async () => ({}) });

    await assert.rejects(() => fetchDumpAll({ wpUrl: 'http://x', wpUser: 'u', wpAppPassword: 'p' }), /HTTP 500/);
  });

  it('throws when the HTTP response is 2xx but the envelope itself reports ok:false', async () => {
    globalThis.fetch = async () => ({
      ok: true,
      status: 200,
      json: async () => ({ ok: false, error: { message: 'missing capability' } }),
    });

    await assert.rejects(
      () => fetchDumpAll({ wpUrl: 'http://x', wpUser: 'u', wpAppPassword: 'p' }),
      /missing capability/
    );
  });
});
