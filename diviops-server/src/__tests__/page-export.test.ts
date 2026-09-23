// SPDX-License-Identifier: MIT
/**
 * `diviops_page_export` and its artifact store (#382).
 *
 * The export is Divi's own portability artifact, and that artifact embeds every
 * image as base64 — local attachments read off disk, remote ones fetched and
 * base64'd. A photo-heavy page is megabytes, so the owner's decision is that the
 * payload must NOT reach the calling agent's context by default. The assertion
 * that pins that is deliberately not "the result has a ref key": a tool could
 * return both. It searches the SERIALIZED tool result for the image bytes
 * themselves, because that string is exactly what the client receives.
 *
 * The rest is the refusal contract. A digest disagreement must leave nothing on
 * disk — the failure mode worth preventing is a wrong-bytes artifact sitting in
 * the store looking importable — and a handle carrying `..` or a separator must
 * never resolve to a path, since the whole reason this store hands out handles
 * instead of accepting a destination is to keep arbitrary filesystem write off
 * this server's tool surface.
 *
 * `globalThis.fetch` is replaced BEFORE `../index.js` is imported, because
 * `WPClient` binds its fetch at construction and the server constructs its
 * client at module load. Nothing here touches a live site, and
 * `DIVIOPS_PAGE_EXPORT_REF_DIR` is pointed at a throwaway directory so the
 * store's own root is never written.
 */
import { describe, it, before, beforeEach, after } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync, readdirSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const STORE = mkdtempSync(join(tmpdir(), 'diviops-page-export-'));
process.env.DIVIOPS_PAGE_EXPORT_REF_DIR = STORE;

// A base64 run long enough that finding it in the serialized result is
// unambiguous, and distinctive enough that a coincidental match is impossible.
const IMAGE_BASE64 = 'iVBORw0KGgoAAAANSUhEUg' + 'QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVph'.repeat(8);

const ARTIFACT = {
  context: 'et_builder',
  data: { '900390': '<!-- wp:divi/section --><!-- /wp:divi/section -->' },
  images: { 'https://example.test/wp-content/uploads/hero.png': IMAGE_BASE64 },
  post_title: 'Référence Page',
  post_type: 'page',
  theme_builder: [],
  global_colors: [['gcid-primary', { color: '#ff0000' }]],
  canvases: [],
  presets: {},
};

/**
 * The bytes the plugin says it hashed, deliberately NOT what `JSON.stringify`
 * would produce for the same object: slashes are escaped and the non-ASCII
 * character is `\u`-escaped, which is what PHP's `json_encode` emits at default
 * flags. If this server ever re-serialised the artifact instead of passing the
 * bytes through, the round-trip assertion below would fail on exactly this
 * difference — which is the whole reason the contract ships bytes rather than an
 * object.
 */
const ARTIFACT_JSON = JSON.stringify(ARTIFACT)
  .replace(/\//g, '\\/')
  .replace(/é/g, '\\u00e9');

/**
 * The digest, computed here over those bytes independently of the
 * implementation. Using the implementation's own helper would make every
 * assertion below tautological.
 */
function declaredSha256(json: string): string {
  return createHash('sha256').update(json, 'utf8').digest('hex');
}

const MANIFEST = {
  page_id: 900390,
  page_title: 'Référence Page',
  post_type: 'page',
  byte_length: Buffer.byteLength(ARTIFACT_JSON, 'utf8'),
  sha256: declaredSha256(ARTIFACT_JSON),
  images: { referenced: 1, encoded: 1, skipped: 0 },
  global_colors: ['gcid-primary'],
  presets: 0,
  attachment_ids: [4711],
  third_party_namespaces: [],
};

/**
 * A needle taken out of the plugin's own bytes rather than out of the object, so
 * the absence check below and the positive control that validates it are looking
 * for the SAME string. Built from the object it would be the unescaped markup,
 * which never appears in the file — the search would find nothing, and "found
 * nothing" would prove the needle wrong rather than the payload absent.
 */
const MARKUP_NEEDLE = ARTIFACT_JSON.slice(
  ARTIFACT_JSON.indexOf('<!-- wp:divi'),
  ARTIFACT_JSON.indexOf('-->') + 3,
);

type Responder = () => { status: number; body: unknown };

let respond: Responder = () => ({ status: 200, body: { ok: true, data: { artifact_json: ARTIFACT_JSON, manifest: MANIFEST } } });

globalThis.fetch = (async () => {
  const { status, body } = respond();
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}) as typeof fetch;

type RecordedTool = {
  config: Record<string, unknown>;
  handler: (...args: unknown[]) => Promise<{ content: { text: string }[] }>;
};

const handlers = new Map<string, RecordedTool>();
let store: typeof import('../page-export-ref.js');

before(async () => {
  store = await import('../page-export-ref.js');
  const index = await import('../index.js');
  index
    .finalizeProductionRegistryForHandshake({
      kind: 'ok',
      capabilities: { page_export: true },
      pluginVersion: '99.0.0',
      proActive: false,
      availableTargets: {},
      activeModules: {},
      plugins: {},
    })
    .install({
      registerTool(name: string, config: unknown, handler: unknown) {
        handlers.set(name, {
          config: config as Record<string, unknown>,
          handler: handler as RecordedTool['handler'],
        });
      },
      registerResource() {},
    });
});

beforeEach(() => {
  respond = () => ({ status: 200, body: { ok: true, data: { artifact_json: ARTIFACT_JSON, manifest: MANIFEST } } });
  for (const entry of readdirSync(STORE)) rmSync(join(STORE, entry));
});

after(() => rmSync(STORE, { recursive: true, force: true }));

/** Call the registered tool and return both the raw text and the parsed envelope. */
async function callExport(args: Record<string, unknown>) {
  const tool = handlers.get('diviops_page_export');
  assert.ok(tool, 'diviops_page_export should be present in the finalized registry');
  const result = await tool.handler(args, { signal: undefined });
  const text = result.content[0].text;
  return { text, envelope: JSON.parse(text) };
}

function storedFiles(): string[] {
  return readdirSync(STORE);
}

describe('diviops_page_export default call', () => {
  it('writes the artifact and keeps every byte of it out of the tool result', async () => {
    const { text, envelope } = await callExport({ page_id: 900390, return_payload: false });

    assert.equal(envelope.ok, true, text);

    // The load-bearing assertion: the client receives this exact string.
    assert.equal(
      text.includes(IMAGE_BASE64),
      false,
      'the base64 image payload must not appear anywhere in the serialized tool result',
    );
    assert.equal(
      text.includes(MARKUP_NEEDLE),
      false,
      'the artifact block markup must not appear in the serialized tool result either',
    );
    assert.equal(envelope.data.artifact_json, undefined, 'no artifact key at all by default');

    // Positive control: the same search DOES find these strings in the file the
    // tool wrote, so a search that finds nothing proves absence rather than a
    // broken needle.
    const [file] = storedFiles();
    const written = readFileSync(join(STORE, file), 'utf8');
    assert.equal(written.includes(IMAGE_BASE64), true);
    assert.equal(written.includes(MARKUP_NEEDLE), true);
  });

  it('returns the ref and the manifest the caller needs in place of the payload', async () => {
    const { envelope } = await callExport({ page_id: 900390, return_payload: false });
    const ref = envelope.data.artifact_ref;

    assert.equal(ref.algorithm, 'sha256');
    assert.equal(ref.storage, 'server_local_artifact');
    assert.equal(ref.format, 'diviops.page_export.artifact.v1');
    assert.equal(ref.checksum, MANIFEST.sha256);
    assert.match(ref.handle, /^pe-900390-[0-9a-f]{16}-[0-9a-f]{8}$/);
    assert.ok(Date.parse(ref.expires_at) > Date.now(), 'the ref states when the artifact is swept');
    assert.deepEqual(envelope.data.manifest, MANIFEST);
    assert.equal(storedFiles().length, 1);
  });

  it('is the default: omitting return_payload behaves the same as passing false', async () => {
    const { text, envelope } = await callExport({ page_id: 900390 });
    assert.equal(envelope.ok, true, text);
    assert.equal(text.includes(IMAGE_BASE64), false);
    assert.equal(envelope.data.artifact_json, undefined);
  });
});

describe('diviops_page_export with return_payload: true', () => {
  it('inlines the full artifact alongside the ref', async () => {
    const { text, envelope } = await callExport({ page_id: 900390, return_payload: true });

    assert.equal(envelope.ok, true, text);
    assert.equal(envelope.data.artifact_json, ARTIFACT_JSON);
    assert.equal(text.includes(IMAGE_BASE64), true, 'the opt-in really does carry the bytes');
    assert.equal(envelope.data.artifact_ref.checksum, MANIFEST.sha256);
  });

  it('warns in the description an agent actually reads that the payload can be megabytes', () => {
    const tool = handlers.get('diviops_page_export');
    assert.ok(tool);
    const description = String(tool.config.description);
    assert.match(description, /megabytes/i);
    assert.match(description, /base64/i);
    assert.match(description, /return_payload/);
  });
});

describe('the written artifact', () => {
  it('round-trips byte-identically', async () => {
    const { envelope } = await callExport({ page_id: 900390 });
    const path = store.pageExportPath(envelope.data.artifact_ref.handle);
    const bytes = readFileSync(path);

    // The plugin's bytes, NOT this runtime's re-encoding of the same object.
    // ARTIFACT_JSON deliberately carries PHP's escaping, so this assertion fails
    // the moment anything on this side round-trips the artifact through
    // JSON.parse/JSON.stringify -- which is precisely the bug that would break
    // the checksum on every real export.
    assert.equal(bytes.toString('utf8'), ARTIFACT_JSON);
    assert.notEqual(
      ARTIFACT_JSON,
      JSON.stringify(ARTIFACT),
      'the fixture is only meaningful while the plugin encoding and this runtime\'s encoding actually differ',
    );
    assert.deepEqual(JSON.parse(bytes.toString('utf8')), ARTIFACT);
    assert.equal(
      createHash('sha256').update(bytes).digest('hex'),
      MANIFEST.sha256,
      'the bytes on disk are the bytes that were hashed',
    );
    assert.equal(bytes.length, MANIFEST.byte_length);
  });

  it('is written 0600 — owner read/write and nothing else', async () => {
    const { envelope } = await callExport({ page_id: 900390 });
    const mode = statSync(store.pageExportPath(envelope.data.artifact_ref.handle)).mode & 0o777;
    assert.equal(mode, 0o600, `expected 0600, got 0${mode.toString(8)}`);
  });
});

describe('checksum verification', () => {
  it('refuses a digest disagreement, writes nothing, and reports both values', async () => {
    const wrong = 'f'.repeat(64);
    respond = () => ({
      status: 200,
      body: { ok: true, data: { artifact_json: ARTIFACT_JSON, manifest: { ...MANIFEST, sha256: wrong, byte_length: 17 } } },
    });

    const { envelope } = await callExport({ page_id: 900390 });

    assert.equal(envelope.ok, false);
    assert.equal(envelope.error.code, 'page_export.checksum_mismatch');
    assert.equal(envelope.error.data.declared, wrong);
    assert.equal(envelope.error.data.computed, MANIFEST.sha256);
    assert.equal(envelope.error.data.declared_byte_length, 17);
    assert.equal(envelope.error.data.computed_byte_length, MANIFEST.byte_length);
    assert.equal(envelope.data, undefined, 'a refusal hands back no ref to import');
    assert.deepEqual(storedFiles(), [], 'nothing reaches the store on a refusal');
  });

  it('refuses an artifact whose bytes drifted from a correct-looking manifest', async () => {
    // The realistic corruption: the manifest is well-formed and the artifact is
    // not the one it describes. A tool that skipped the comparison would write
    // this file and report success.
    respond = () => ({
      status: 200,
      body: {
        ok: true,
        data: { artifact_json: ARTIFACT_JSON.replace('"post_type":"page"', '"post_type":"post"'), manifest: MANIFEST },
      },
    });

    const { envelope } = await callExport({ page_id: 900390 });

    assert.equal(envelope.ok, false);
    assert.equal(envelope.error.code, 'page_export.checksum_mismatch');
    assert.notEqual(envelope.error.data.computed, envelope.error.data.declared);
    assert.deepEqual(storedFiles(), []);
  });

  it('refuses a manifest carrying no digest at all rather than trusting the bytes', async () => {
    respond = () => ({
      status: 200,
      body: { ok: true, data: { artifact_json: ARTIFACT_JSON, manifest: { ...MANIFEST, sha256: undefined } } },
    });

    const { envelope } = await callExport({ page_id: 900390 });

    assert.equal(envelope.ok, false);
    assert.equal(envelope.error.code, 'page_export.checksum_mismatch');
    assert.equal(envelope.error.data.declared, '');
    assert.deepEqual(storedFiles(), []);
  });

  it('accepts a `sha256:`-prefixed digest, which is the same value written differently', async () => {
    respond = () => ({
      status: 200,
      body: {
        ok: true,
        data: { artifact_json: ARTIFACT_JSON, manifest: { ...MANIFEST, sha256: `sha256:${MANIFEST.sha256.toUpperCase()}` } },
      },
    });

    const { envelope } = await callExport({ page_id: 900390 });

    assert.equal(envelope.ok, true, JSON.stringify(envelope));
    assert.equal(envelope.data.artifact_ref.checksum, MANIFEST.sha256);
  });
});

describe('handle validation', () => {
  it('rejects traversal and path separators instead of resolving them', () => {
    for (const bad of [
      'pe-../../etc/passwd',
      'pe-..',
      'pe-a/../b',
      'pe-a/b',
      'pe-a\\b',
      '../pe-a',
      '/etc/passwd',
      'pe-a\0b',
      '',
      'src-cross-env-handle',
    ]) {
      assert.throws(
        () => store.pageExportPath(bad),
        /handle is invalid/,
        `handle ${JSON.stringify(bad)} must be refused`,
      );
    }
  });

  it('resolves a handle it actually minted, so the refusals above are not vacuous', async () => {
    const { envelope } = await callExport({ page_id: 900390 });
    const handle = envelope.data.artifact_ref.handle;
    assert.equal(store.pageExportPath(handle), join(STORE, `${handle}.json`));
  });
});

describe('a plugin error envelope', () => {
  it('surfaces as a tool error with nothing written', async () => {
    respond = () => ({
      status: 404,
      body: { ok: false, error: { code: 'not_found', message: 'Page 900391 not found.' } },
    });

    const { envelope } = await callExport({ page_id: 900391 });

    assert.equal(envelope.ok, false);
    assert.equal(envelope.error.code, 'not_found');
    assert.deepEqual(storedFiles(), [], 'a failed export leaves no half-written artifact');
  });

  it('surfaces a success envelope that carries no artifact rather than writing undefined', async () => {
    respond = () => ({ status: 200, body: { ok: true, data: { manifest: MANIFEST } } });

    const { envelope } = await callExport({ page_id: 900390 });

    assert.equal(envelope.ok, false);
    assert.equal(envelope.error.code, 'wp_error');
    assert.match(envelope.error.message, /no artifact/);
    assert.deepEqual(storedFiles(), []);
  });
});
