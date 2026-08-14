import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  summarizeDescription,
  extractTools,
  renderToolRow,
  renderAlwaysOnTable,
  renderProTable,
  renderHeader,
  regenerateContent,
  loadSourceSet,
  ENTRY_FILE,
  TARGET_FILE,
} from './regen-tool-reference.mjs';

// Synthetic TypeScript, structurally modelled on the registration call sites in
// src/index.ts but written here rather than copied from it, so a fixture can
// never silently become a copy of the file under test.
const FIXTURE_ENTRY = `
import { SHARED_CONFIG } from "./shared.js";

const DRY_RUN_SUFFIX = " Pass dry_run: true to preview.";
const DRY_RUN_FIELD = z.boolean().optional().default(false).describe("Preview only.");
const CONFIRMATION_FIELDS = {
  fingerprint: z.string().describe("Exact fingerprint."),
};
const runtimeFlag = true;

registerPluginTool(
  "diviops_widget_get",
  {
    description:
      "Read one widget. Returns the standardized envelope.",
    inputSchema: {
      widget_id: z.number().int().positive().describe("Widget ID"),
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => ({}),
);

registerPluginTool(
  "diviops_widget_update",
  {
    description:
      "Update one widget, e.g. its label. Second sentence here." + DRY_RUN_SUFFIX,
    inputSchema: {
      widget_id: z.number().describe("Widget ID"),
      label: z.string().optional().describe("New label"),
      dry_run: DRY_RUN_FIELD,
    },
    annotations: { idempotentHint: false },
    _meta: { idempotent: "false" },
  },
  async () => ({}),
);

registerLocalTool(
  "diviops_widget_ping",
  {
    ...SHARED_CONFIG,
  },
  async () => ({}),
);

registerPluginTool(
  "diviops_widget_export",
  {
    description:
      \`Export a widget for an offline \${runtimeFlag ? "full" : "partial"} review. Trailing prose.\`,
    inputSchema: {},
    annotations: { idempotentHint: true },
    _meta: { idempotent: "conditional" },
  },
  async () => ({}),
);

registerProTool(
  "diviops_wc_order_get",
  {
    description: "Read one order.",
    inputSchema: {
      order_id: z.number().describe("Order ID"),
      ...CONFIRMATION_FIELDS,
    },
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => ({}),
  { target: "widgetcart", capabilityKey: "widgetcart_order_get" },
);
`;

const FIXTURE_SHARED = `
export const SHARED_CONFIG = {
  description:
    "Probe the connection. Reports nothing else.",
  annotations: { readOnlyHint: true, idempotentHint: true },
  _meta: { idempotent: "true" },
} as const;
`;

const FIXTURE_SOURCES = [
  { fileName: 'index.ts', text: FIXTURE_ENTRY },
  { fileName: 'shared.ts', text: FIXTURE_SHARED },
];

function toolNamed(tools, name) {
  const tool = tools.find((t) => t.name === name);
  assert.ok(tool, `fixture tool ${name} was extracted`);
  return tool;
}

describe('summarizeDescription', () => {
  it('returns a single-sentence description unchanged', () => {
    assert.equal(summarizeDescription('Read one widget.'), 'Read one widget.');
  });

  it('keeps only the first sentence and marks the truncation', () => {
    assert.equal(
      summarizeDescription('Read one widget. Requires edit_posts. Returns the envelope.'),
      'Read one widget. …'
    );
  });

  it('does not split on an "e.g." abbreviation', () => {
    assert.equal(
      summarizeDescription('Update a widget, e.g. its label, in place.'),
      'Update a widget, e.g. its label, in place.'
    );
  });

  it('does not split on an "i.e." abbreviation', () => {
    assert.equal(summarizeDescription('Trash it, i.e. soft-delete it.'), 'Trash it, i.e. soft-delete it.');
  });

  it('does not split on a decimal point inside a number', () => {
    assert.equal(summarizeDescription('Requires Divi 5.1 or newer.'), 'Requires Divi 5.1 or newer.');
  });

  it('collapses newlines and runs of whitespace', () => {
    assert.equal(summarizeDescription('Read\n   one\twidget.'), 'Read one widget.');
  });

  it('escapes pipe characters so a description cannot break out of its table cell', () => {
    assert.equal(summarizeDescription('Accepts a|b.'), 'Accepts a\\|b.');
  });

  it('escapes backslashes, which markdown would otherwise read as escaping the next character', () => {
    assert.equal(summarizeDescription('Matches \\d in the path.'), 'Matches \\\\d in the path.');
  });

  it('escapes a backslash already followed by a pipe, rather than leaving the pipe unescaped', () => {
    assert.equal(summarizeDescription('Matches a\\|b.'), 'Matches a\\\\\\|b.');
  });

  it('returns the whole text when it carries no sentence-ending punctuation', () => {
    assert.equal(summarizeDescription('Read one widget'), 'Read one widget');
  });
});

describe('extractTools', () => {
  it('extracts every registration call site across the three helpers', () => {
    const tools = extractTools(FIXTURE_SOURCES);
    assert.deepEqual(
      tools.map((t) => t.name).sort(),
      [
        'diviops_wc_order_get',
        'diviops_widget_export',
        'diviops_widget_get',
        'diviops_widget_ping',
        'diviops_widget_update',
      ]
    );
  });

  it('records the registration helper as the tool kind', () => {
    const tools = extractTools(FIXTURE_SOURCES);
    assert.equal(toolNamed(tools, 'diviops_widget_get').kind, 'plugin');
    assert.equal(toolNamed(tools, 'diviops_widget_ping').kind, 'server_local');
    assert.equal(toolNamed(tools, 'diviops_wc_order_get').kind, 'pro');
  });

  it('reads description, inputs, and the _meta.idempotent marker', () => {
    const tool = toolNamed(extractTools(FIXTURE_SOURCES), 'diviops_widget_get');
    assert.equal(tool.description, 'Read one widget. Returns the standardized envelope.');
    assert.deepEqual(tool.inputs, [{ name: 'widget_id', optional: false }]);
    assert.equal(tool.idempotent, 'true');
  });

  it('concatenates a description built from a string plus a module-level constant', () => {
    const tool = toolNamed(extractTools(FIXTURE_SOURCES), 'diviops_widget_update');
    assert.equal(
      tool.description,
      'Update one widget, e.g. its label. Second sentence here. Pass dry_run: true to preview.'
    );
  });

  it('marks an input optional when its schema chain ends in .optional() or .default()', () => {
    const tool = toolNamed(extractTools(FIXTURE_SOURCES), 'diviops_widget_update');
    assert.deepEqual(tool.inputs, [
      { name: 'widget_id', optional: false },
      { name: 'label', optional: true },
      { name: 'dry_run', optional: true },
    ]);
  });

  it('resolves a spread config object declared in another source file', () => {
    const tool = toolNamed(extractTools(FIXTURE_SOURCES), 'diviops_widget_ping');
    assert.equal(tool.description, 'Probe the connection. Reports nothing else.');
    assert.equal(tool.idempotent, 'true');
    assert.deepEqual(tool.inputs, []);
  });

  it('resolves a spread inputSchema object into its individual fields', () => {
    const tool = toolNamed(extractTools(FIXTURE_SOURCES), 'diviops_wc_order_get');
    assert.deepEqual(tool.inputs, [
      { name: 'order_id', optional: false },
      { name: 'fingerprint', optional: false },
    ]);
  });

  it('records the Pro registration gates', () => {
    const tool = toolNamed(extractTools(FIXTURE_SOURCES), 'diviops_wc_order_get');
    assert.equal(tool.target, 'widgetcart');
    assert.equal(tool.capabilityKey, 'widgetcart_order_get');
  });

  it('substitutes an ellipsis for a template-literal placeholder the server fills in at runtime', () => {
    const tool = toolNamed(extractTools(FIXTURE_SOURCES), 'diviops_widget_export');
    assert.equal(tool.description, 'Export a widget for an offline … review. Trailing prose.');
  });

  it('throws rather than emitting an empty catalog when the source registers no tools', () => {
    assert.throws(
      () => extractTools([{ fileName: 'index.ts', text: 'const x = 1;\n' }]),
      /no tool registration call sites/
    );
  });

  it('throws naming the identifier when an input schema constant cannot be resolved', () => {
    const source = `
registerPluginTool(
  "diviops_widget_get",
  {
    description: "Read one widget.",
    inputSchema: { widget_id: SOME_UNRESOLVED_FIELD },
    _meta: { idempotent: "true" },
  },
  async () => ({}),
);
`;
    assert.throws(() => extractTools([{ fileName: 'index.ts', text: source }]), /SOME_UNRESOLVED_FIELD/);
  });

  it('throws naming the tool when a registration carries no _meta.idempotent marker', () => {
    const source = `
registerPluginTool(
  "diviops_widget_get",
  { description: "Read one widget.", inputSchema: {} },
  async () => ({}),
);
`;
    assert.throws(() => extractTools([{ fileName: 'index.ts', text: source }]), /diviops_widget_get/);
  });
});

describe('renderToolRow', () => {
  it('renders an always-on row with backticked inputs and a trailing ? on optional ones', () => {
    const row = renderToolRow(
      {
        name: 'diviops_widget_update',
        kind: 'plugin',
        idempotent: 'false',
        description: 'Update one widget. More prose.',
        inputs: [
          { name: 'widget_id', optional: false },
          { name: 'dry_run', optional: true },
        ],
      },
      { pro: false }
    );
    assert.equal(
      row,
      '| `diviops_widget_update` | plugin | `widget_id`, `dry_run?` | false | Update one widget. … |'
    );
  });

  it('renders an argument-free tool as _(none)_', () => {
    const row = renderToolRow(
      {
        name: 'diviops_widget_ping',
        kind: 'server_local',
        idempotent: 'true',
        description: 'Probe the connection.',
        inputs: [],
      },
      { pro: false }
    );
    assert.equal(row, '| `diviops_widget_ping` | server-local | _(none)_ | true | Probe the connection. |');
  });

  it('renders a Pro row carrying its target and capability key instead of a kind', () => {
    const row = renderToolRow(
      {
        name: 'diviops_wc_order_get',
        kind: 'pro',
        idempotent: 'true',
        description: 'Read one order.',
        inputs: [{ name: 'order_id', optional: false }],
        target: 'widgetcart',
        capabilityKey: 'widgetcart_order_get',
      },
      { pro: true }
    );
    assert.equal(
      row,
      '| `diviops_wc_order_get` | `widgetcart` | `widgetcart_order_get` | `order_id` | true | Read one order. |'
    );
  });
});

describe('renderAlwaysOnTable / renderProTable', () => {
  const tools = extractTools(FIXTURE_SOURCES);

  it('lists only the always-on tools, alphabetically, inside its sentinel pair', () => {
    const block = renderAlwaysOnTable(tools);
    assert.match(block, /^<!-- BEGIN GENERATED:tool-reference:always-on -->\n/);
    assert.match(block, /\n<!-- END GENERATED:tool-reference:always-on -->$/);
    const names = [...block.matchAll(/^\| `(diviops_[a-z0-9_]+)`/gm)].map((m) => m[1]);
    assert.deepEqual(names, [
      'diviops_widget_export',
      'diviops_widget_get',
      'diviops_widget_ping',
      'diviops_widget_update',
    ]);
  });

  it('lists only the Pro tools inside its own sentinel pair', () => {
    const block = renderProTable(tools);
    assert.match(block, /^<!-- BEGIN GENERATED:tool-reference:pro -->\n/);
    assert.match(block, /\n<!-- END GENERATED:tool-reference:pro -->$/);
    const names = [...block.matchAll(/^\| `(diviops_[a-z0-9_]+)`/gm)].map((m) => m[1]);
    assert.deepEqual(names, ['diviops_wc_order_get']);
  });

  it('refuses to render an empty always-on table', () => {
    assert.throws(() => renderAlwaysOnTable([]), /no always-on tools/);
  });

  it('refuses to render an empty Pro table', () => {
    assert.throws(() => renderProTable([]), /no Pro tools/);
  });
});

describe('renderHeader', () => {
  it('states the counts it was given and wraps them in the header sentinel pair', () => {
    const header = renderHeader({ plugin: 104, serverLocal: 11, pro: 30 });
    assert.match(header, /^<!-- BEGIN GENERATED:tool-reference:header -->\n/);
    assert.match(header, /\n<!-- END GENERATED:tool-reference:header -->$/);
    assert.match(
      header,
      /115 always-on tools \(104 plugin-backed, 11 server-local\) and 30 conditionally-registered Pro tools/
    );
  });

  it('refuses to render a header claiming zero tools', () => {
    assert.throws(() => renderHeader({ plugin: 0, serverLocal: 0, pro: 0 }), /no tools/);
  });
});

describe('regenerateContent', () => {
  const tools = extractTools(FIXTURE_SOURCES);
  const original = [
    'prefix prose stays untouched',
    '',
    '<!-- BEGIN GENERATED:tool-reference:header -->',
    'stale header',
    '<!-- END GENERATED:tool-reference:header -->',
    '',
    '<!-- BEGIN GENERATED:tool-reference:always-on -->',
    'stale always-on table',
    '<!-- END GENERATED:tool-reference:always-on -->',
    '',
    '<!-- BEGIN GENERATED:tool-reference:pro -->',
    'stale pro table',
    '<!-- END GENERATED:tool-reference:pro -->',
    '',
    'trailing prose stays untouched',
  ].join('\n');

  it('replaces all three sentinel regions and leaves surrounding prose untouched', () => {
    const result = regenerateContent(original, tools);
    const expected = [
      'prefix prose stays untouched',
      '',
      renderHeader({ plugin: 3, serverLocal: 1, pro: 1 }),
      '',
      renderAlwaysOnTable(tools),
      '',
      renderProTable(tools),
      '',
      'trailing prose stays untouched',
    ].join('\n');
    assert.equal(result, expected);
  });

  it('throws naming the missing sentinel region', () => {
    const content = ['<!-- BEGIN GENERATED:tool-reference:header -->', 'x', '<!-- END GENERATED:tool-reference:header -->'].join('\n');
    assert.throws(() => regenerateContent(content, tools), /tool-reference:always-on/);
  });

  it('throws rather than blanking the tables when handed an empty tool list', () => {
    assert.throws(() => regenerateContent(original, []), /no tools/);
  });
});

// The staleness guard. Everything above proves the transform; this proves the
// committed README is what the transform currently produces from the real
// source, so the reference cannot silently rot the way the hardcoded tool
// counts did before #90. It also cross-checks the AST extraction against the
// independent line-anchored regex count tests/test-tool-count-sync.php uses,
// so a parse that silently dropped call sites fails here rather than quietly
// shrinking the table.
describe('the committed reference is in sync with src/index.ts', () => {
  const sources = loadSourceSet(ENTRY_FILE);
  const tools = extractTools(sources);

  function countCallSites(helper) {
    const source = readFileSync(ENTRY_FILE, 'utf8');
    const matches = source.match(new RegExp(`^[ \\t]*${helper}\\(`, 'gm')) ?? [];
    assert.ok(matches.length > 0, `at least one ${helper}( call site exists in src/index.ts`);
    return matches.length;
  }

  it('extracts exactly as many tools per helper as the line-anchored call-site count finds', () => {
    const byKind = (kind) => tools.filter((t) => t.kind === kind).length;
    assert.equal(byKind('plugin'), countCallSites('registerPluginTool'));
    assert.equal(byKind('server_local'), countCallSites('registerLocalTool'));
    assert.equal(byKind('pro'), countCallSites('registerProTool'));
  });

  it('regenerates diviops-server/README.md byte-identically (run `npm run regen:tool-reference` if this fails)', () => {
    const committed = readFileSync(TARGET_FILE, 'utf8');
    assert.equal(regenerateContent(committed, tools), committed);
  });
});
