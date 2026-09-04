import { describe, it, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import {
  renderElementLine,
  renderModuleBlock,
  renderHeader,
  parseModuleNames,
  regenerateContent,
  fetchDumpAll,
  buildTypesIndex,
  renderModuleTypeLine,
  compareTypesToDump,
  renderTypesIndexRegion,
  TARGET_FILE,
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
  it('renders the sentinel-wrapped header with divi_version, a 12-char schema_version fingerprint, and the @divi/types pin', () => {
    const header = renderHeader({
      diviVersion: '5.9.0',
      schemaVersion: 'af7c9d795e77deadbeef1234567890fedcba098',
      typesVersion: '1.0.12',
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
        '> Generated against Divi `5.9.0`, schema `af7c9d795e77…`; Tier 3 above against `@divi/types` `1.0.12`.',
        '',
        'Per CLAUDE.md "Suite architecture coherence": schema dump is the canonical index; VB-verified prose above is the canonical interpretation. The two sections are complementary, not competing — prose explains surprises, this index enumerates paths exhaustively. On conflicts, the prose above wins (per `feedback_vb_first_verification`).',
        '',
        '<!-- END GENERATED:header -->',
      ].join('\n')
    );
  });

  it('refuses to render a header that cannot state both provenances', () => {
    assert.throws(
      () => renderHeader({ diviVersion: '5.9.0', schemaVersion: 'abcdef123456', typesVersion: '' }),
      /@divi\/types/
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

  const typesIndex = {
    version: '1.0.12',
    modules: {
      'divi/fixture-one': { module: { decoration: ['background'], innerContent: false, advanced: null } },
      'divi/fixture-two': { module: { decoration: ['border'], innerContent: false, advanced: null } },
    },
  };

  const originalContent = [
    'prefix prose stays untouched',
    '',
    '<!-- BEGIN GENERATED:types-index -->',
    'old stale tier 3',
    '<!-- END GENERATED:types-index -->',
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

  it('replaces the Tier 3 types region, the header and every existing module block, leaving surrounding prose untouched', () => {
    const result = regenerateContent(originalContent, dumpAllData, typesIndex);

    const expected = [
      'prefix prose stays untouched',
      '',
      renderTypesIndexRegion(typesIndex, dumpAllData),
      '',
      renderHeader({
        diviVersion: '5.9.0',
        schemaVersion: dumpAllData.schema_version,
        typesVersion: '1.0.12',
      }),
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
    assert.throws(() => regenerateContent(content, dumpAllData, typesIndex), /no module sentinel blocks/);
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
    assert.throws(() => regenerateContent(content, dumpAllData, typesIndex), /divi\/does-not-exist/);
  });

  it('throws when a module sentinel exists but the header sentinel region is missing', () => {
    const content = [
      '<!-- BEGIN GENERATED:module:divi/fixture-one -->',
      'x',
      '<!-- END GENERATED:module:divi/fixture-one -->',
    ].join('\n');
    assert.throws(() => regenerateContent(content, dumpAllData, typesIndex), /header/);
  });

  it('throws when the Tier 3 types-index sentinel region is missing', () => {
    const content = originalContent.replace('types-index', 'types-index-typo');
    assert.throws(() => regenerateContent(content, dumpAllData, typesIndex), /types-index/);
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

// ─────────────────────────────────────────────────────────────────────────────
// The @divi/types second input (#384).
//
// Synthetic TypeScript modelled on the shapes `@divi/types` actually uses
// (`Element.Decoration.PickedAttributes<'a' | 'b'>`, an element whose whole
// attribute type is an alias into `element/types/`), written for the test
// rather than copied out of the package — the same convention the schema-dump
// fixtures above follow.

const SYNTHETIC_PACKAGE = {
  'module/library/internal/index.ts': `
    export interface InternalAttrs {
      builderVersion?: string;
      modulePreset?: string;
    }
  `,
  'module/element/decoration/index.ts': `
    export namespace Decoration {
      export interface Attributes {
        alpha?: string;
        beta?: string;
        gamma?: string;
      }
      export type PickedAttributes<TNames extends keyof Attributes> = Pick<Attributes, TNames>;
    }
  `,
  'module/element/types/widget/index.ts': `
    import { Decoration as ElementDecoration } from '../../decoration';
    export namespace Widget {
      export namespace Decoration {
        export type Attributes = ElementDecoration.PickedAttributes<'alpha' | 'gamma'>;
      }
      export interface Attributes {
        innerContent?: string;
        decoration?: Decoration.Attributes;
      }
    }
  `,
  'module/library/fixture-one/index.ts': `
    import { Decoration } from '../../element/decoration';
    import { Widget } from '../../element/types/widget';
    import { type InternalAttrs } from '../internal';

    export interface FixtureOneAttrs extends InternalAttrs {
      css?: { desktop?: string };
      module?: {
        advanced?: { link?: string; loop?: string };
        decoration?: Decoration.PickedAttributes<'beta' | 'alpha'>;
      };
      widget?: Widget.Attributes;
      bare?: { meta?: string };
    }
  `,
  'module/library/nested/thing/index.ts': `
    import { Decoration } from '../../../element/decoration';
    import { type InternalAttrs } from '../../internal';

    export interface NestedThingAttrs extends InternalAttrs {
      module?: { decoration?: Decoration.PickedAttributes<'gamma'> };
    }
  `,
};

function writeSyntheticPackage(files) {
  const root = mkdtempSync(path.join(tmpdir(), 'divi-types-384-'));
  for (const [rel, source] of Object.entries(files)) {
    const full = path.join(root, 'src', rel);
    mkdirSync(path.dirname(full), { recursive: true });
    writeFileSync(full, source);
  }
  return root;
}

describe('buildTypesIndex', () => {
  const roots = [];
  afterEach(() => {
    while (roots.length) rmSync(roots.pop(), { recursive: true, force: true });
  });
  const build = (files, version = '1.0.12') => {
    const root = writeSyntheticPackage(files);
    roots.push(root);
    return buildTypesIndex({ packageDir: root, version });
  };

  it('records the package version it was built from', () => {
    assert.equal(build(SYNTHETIC_PACKAGE, '9.9.9').version, '9.9.9');
  });

  it('names modules divi/<slug> from the library directory path, joining nested segments with a hyphen', () => {
    assert.deepEqual(Object.keys(build(SYNTHETIC_PACKAGE).modules).sort(), [
      'divi/fixture-one',
      'divi/nested-thing',
    ]);
  });

  it('resolves PickedAttributes through the type checker and sorts the group names', () => {
    const { modules } = build(SYNTHETIC_PACKAGE);
    assert.deepEqual(modules['divi/fixture-one'].module.decoration, ['alpha', 'beta']);
  });

  it('resolves an element whose whole attribute type is an alias into element/types', () => {
    const { modules } = build(SYNTHETIC_PACKAGE);
    assert.deepEqual(modules['divi/fixture-one'].widget, {
      decoration: ['alpha', 'gamma'],
      innerContent: true,
      advanced: null,
    });
  });

  it('records advanced sub-keys rather than only that advanced exists', () => {
    const { modules } = build(SYNTHETIC_PACKAGE);
    assert.deepEqual(modules['divi/fixture-one'].module.advanced, ['link', 'loop']);
  });

  it('keeps an element that declares no decoration groups at all', () => {
    const { modules } = build(SYNTHETIC_PACKAGE);
    assert.deepEqual(modules['divi/fixture-one'].bare, {
      decoration: [],
      innerContent: false,
      advanced: null,
    });
  });

  it('excludes css and every key the package own InternalAttrs declares', () => {
    const { modules } = build(SYNTHETIC_PACKAGE);
    const keys = Object.keys(modules['divi/fixture-one']);
    for (const excluded of ['css', 'builderVersion', 'modulePreset']) {
      assert.ok(!keys.includes(excluded), `${excluded} is a universal key and must not be indexed`);
    }
  });

  it('throws naming the file when a module declares no exported *Attrs type', () => {
    const files = { ...SYNTHETIC_PACKAGE, 'module/library/fixture-one/index.ts': 'export const x = 1;' };
    assert.throws(() => build(files), /fixture-one/);
  });

  it('throws rather than returning an empty index when the library directory holds no modules', () => {
    const files = {
      'module/library/internal/index.ts': SYNTHETIC_PACKAGE['module/library/internal/index.ts'],
      'module/element/decoration/index.ts': SYNTHETIC_PACKAGE['module/element/decoration/index.ts'],
    };
    assert.throws(() => build(files), /no module/i);
  });

  it('throws when the InternalAttrs declaration the exclusion set derives from is missing', () => {
    const files = { ...SYNTHETIC_PACKAGE };
    delete files['module/library/internal/index.ts'];
    assert.throws(() => build(files), /InternalAttrs/);
  });
});

describe('renderModuleTypeLine', () => {
  it('renders one bullet per module, elements alphabetical, groups comma-joined', () => {
    const line = renderModuleTypeLine('divi/fixture', {
      module: { decoration: ['background', 'animation'], innerContent: false, advanced: null },
      content: { decoration: ['bodyFont'], innerContent: false, advanced: null },
    });
    assert.equal(
      line,
      '- **`divi/fixture`** — `content`: bodyFont · `module`: animation, background'
    );
  });

  it('renders (none) for an element that declares no decoration groups', () => {
    const line = renderModuleTypeLine('divi/fixture', {
      chart: { decoration: [], innerContent: false, advanced: null },
    });
    assert.equal(line, '- **`divi/fixture`** — `chart`: (none)');
  });

  it('marks innerContent and lists the advanced sub-keys, innerContent first', () => {
    const line = renderModuleTypeLine('divi/fixture', {
      module: { decoration: ['spacing'], innerContent: true, advanced: ['link', 'loop'] },
    });
    assert.equal(
      line,
      '- **`divi/fixture`** — `module`: spacing (+innerContent; +advanced: link, loop)'
    );
  });

  it('marks an advanced group that declares no sub-keys without an empty list', () => {
    const line = renderModuleTypeLine('divi/fixture', {
      module: { decoration: ['spacing'], innerContent: false, advanced: [] },
    });
    assert.equal(line, '- **`divi/fixture`** — `module`: spacing (+advanced)');
  });

  it('throws rather than rendering a module with no elements at all', () => {
    assert.throws(() => renderModuleTypeLine('divi/empty', {}), /divi\/empty/);
  });
});

describe('compareTypesToDump', () => {
  const typesIndex = {
    version: '1.0.12',
    modules: {
      'divi/fixture-one': {
        module: { decoration: ['animation', 'button'], innerContent: false, advanced: ['link'] },
        title: { decoration: ['font'], innerContent: true, advanced: null },
        caption: { decoration: ['font'], innerContent: false, advanced: null },
      },
    },
  };
  const dumpAllData = {
    divi_version: '5.9.0',
    schema_version: 'a'.repeat(40),
    modules: {
      'divi/fixture-one': {
        attributes: {
          module: { settings: { decoration: { animation: {}, order: {} }, advanced: {} } },
          title: { settings: { decoration: { font: {} } } },
          extra: { settings: { decoration: { sizing: {} } } },
          metadata: { settings: {} },
        },
      },
      'divi/fixture-absent': { attributes: { module: { settings: {} } } },
    },
  };

  it('reports a decoration group only the types declare and one only the dump exposes', () => {
    const { rows } = compareTypesToDump(typesIndex, dumpAllData);
    const row = rows.find((r) => r.module === 'divi/fixture-one');
    assert.ok(row.typesOnly.includes('module.decoration.button'), 'button is types-only');
    assert.ok(row.dumpOnly.includes('module.decoration.order'), 'order is dump-only');
  });

  it('reports a whole element present on only one side, marked with a .* suffix, in both directions', () => {
    const { rows } = compareTypesToDump(typesIndex, dumpAllData);
    const row = rows.find((r) => r.module === 'divi/fixture-one');
    assert.ok(row.dumpOnly.includes('extra.*'), 'the extra element is dump-only');
    assert.ok(row.typesOnly.includes('caption.*'), 'the caption element is types-only');
  });

  it('reports an innerContent marker present on only one side', () => {
    const { rows } = compareTypesToDump(typesIndex, dumpAllData);
    const row = rows.find((r) => r.module === 'divi/fixture-one');
    assert.ok(row.typesOnly.includes('title.innerContent'), 'title innerContent is types-only');
  });

  it('lists a dump module @divi/types does not carry instead of silently skipping it', () => {
    const { missingFromTypes } = compareTypesToDump(typesIndex, dumpAllData);
    assert.deepEqual(missingFromTypes, ['divi/fixture-absent']);
  });

  it('compares only the modules the dump carries, and says how many that was', () => {
    const { comparedCount } = compareTypesToDump(typesIndex, dumpAllData);
    assert.equal(comparedCount, 1);
  });

  it('reports no disagreement for a module the two sources agree on', () => {
    const agreed = {
      version: '1.0.12',
      modules: { 'divi/same': { module: { decoration: ['animation'], innerContent: false, advanced: null } } },
    };
    const dump = {
      divi_version: '5.9.0',
      schema_version: 'b'.repeat(40),
      modules: { 'divi/same': { attributes: { module: { settings: { decoration: { animation: {} } } } } } },
    };
    const { rows } = compareTypesToDump(agreed, dump);
    assert.deepEqual(rows, [{ module: 'divi/same', typesOnly: [], dumpOnly: [] }]);
  });
});

describe('renderTypesIndexRegion', () => {
  const typesIndex = {
    version: '1.0.12',
    modules: {
      'divi/fixture-one': { module: { decoration: ['animation'], innerContent: false, advanced: null } },
      'divi/fixture-two': { module: { decoration: ['border'], innerContent: false, advanced: null } },
    },
  };
  const dumpAllData = {
    divi_version: '5.9.0',
    schema_version: 'c'.repeat(40),
    modules: {
      'divi/fixture-one': {
        attributes: { module: { settings: { decoration: { animation: {}, order: {} } } } },
      },
    },
  };

  it('wraps everything it renders in the types-index sentinel pair', () => {
    const region = renderTypesIndexRegion(typesIndex, dumpAllData);
    assert.ok(region.startsWith('<!-- BEGIN GENERATED:types-index -->'));
    assert.ok(region.endsWith('<!-- END GENERATED:types-index -->'));
  });

  it('pins the @divi/types version and its licence in the region prose', () => {
    const region = renderTypesIndexRegion(typesIndex, dumpAllData);
    assert.match(region, /`@divi\/types` `1\.0\.12`/);
    assert.match(region, /GPL-2\.0-or-later/);
  });

  it('states how many modules it covers and renders a bullet for each', () => {
    const region = renderTypesIndexRegion(typesIndex, dumpAllData);
    assert.match(region, /2 modules/);
    assert.ok(region.includes(renderModuleTypeLine('divi/fixture-one', typesIndex.modules['divi/fixture-one'])));
    assert.ok(region.includes(renderModuleTypeLine('divi/fixture-two', typesIndex.modules['divi/fixture-two'])));
  });

  it('renders the disagreements against the schema dump rather than dropping them', () => {
    const region = renderTypesIndexRegion(typesIndex, dumpAllData);
    assert.match(region, /module\.decoration\.order/);
  });

  it('throws rather than emitting an empty index', () => {
    assert.throws(() => renderTypesIndexRegion({ version: '1.0.12', modules: {} }, dumpAllData), /no module/i);
  });
});

// ─────────────────────────────────────────────────────────────────────────────
// The committed-output guard (#276).
//
// Everything above exercises the transform functions against synthetic input,
// which says nothing about whether the file actually committed to the repo is
// what this generator produces. Without the block below, a hand-edit between
// sentinels — to a module block, the Divi version pin, or the schema
// fingerprint — passes CI silently, while the generated header claims such
// edits are clobbered on regen.
//
// regen-tool-reference.test.mjs can rebuild its input from src/index.ts. This
// generator's input is a live WordPress install CI cannot reach, so the input
// is recorded instead. See __fixtures__/README.md.
const FIXTURE_FILE = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '__fixtures__/dump-all.json'
);

// The @divi/types half of the same problem: the package is fetched from npm at
// regen time, which CI cannot do either, so the distilled index it produces is
// recorded the same way. See __fixtures__/README.md.
const TYPES_FIXTURE_FILE = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '__fixtures__/divi-types.json'
);

describe('the committed module-formats.md matches the recorded schema dump', () => {
  const committed = readFileSync(TARGET_FILE, 'utf8');
  const fixture = JSON.parse(readFileSync(FIXTURE_FILE, 'utf8'));
  const typesFixture = JSON.parse(readFileSync(TYPES_FIXTURE_FILE, 'utf8'));
  const curated = parseModuleNames(committed);

  it('inspects a non-zero number of modules from @divi/types', () => {
    assert.ok(
      Object.keys(typesFixture.modules).length > 0,
      'the recorded @divi/types index carries at least one module'
    );
    assert.match(typesFixture.version, /^\d+\.\d+\.\d+$/, 'the fixture pins an npm version');
  });

  it('pins the same @divi/types version in the committed header', () => {
    assert.ok(
      committed.includes('`@divi/types` `' + typesFixture.version + '`'),
      'committed header should pin @divi/types ' + typesFixture.version
    );
  });

  // A gate that derives pass/fail only from problems-found will pass while
  // inspecting nothing. Assert the sample size before trusting the comparison.
  it('inspects a non-zero number of curated module blocks', () => {
    assert.ok(curated.length > 0, 'module-formats.md curates at least one sentinel-bounded module');
    assert.equal(
      Object.keys(fixture.modules).length,
      curated.length,
      'the fixture records exactly the modules the committed file curates'
    );
  });

  it('records which install it came from', () => {
    assert.match(fixture.divi_version, /^\d+\.\d+/, 'fixture carries a Divi version');
    assert.match(fixture.schema_version, /^[0-9a-f]{12,}$/, 'fixture carries a schema_version hash');
  });

  // The fixture is the generator's INPUT; the markdown is its OUTPUT. If the two
  // were recorded from different installs, the committed header pin disagrees.
  it('was recorded from the same Divi version the committed header pins', () => {
    assert.ok(
      committed.includes('Generated against Divi `' + fixture.divi_version + '`'),
      'committed header should pin Divi ' + fixture.divi_version +
        '; refresh both together (see __fixtures__/README.md)'
    );
  });

  it('regenerates module-formats.md byte-identically (run `npm run regen:skill` if this fails)', () => {
    assert.equal(regenerateContent(committed, fixture, typesFixture), committed);
  });
});
