// SPDX-License-Identifier: MIT
/**
 * Reading a page_export artifact back out of the server's own store (#490).
 *
 * WHY THIS EXISTS. `diviops_page_export` does NOT return the payload by default:
 * it writes the artifact to the server-local store and returns `artifact_ref`,
 * inlining `artifact_json` only under `return_payload: true`, which its own
 * description warns can be many megabytes of base64. So an agent that exports and
 * then imports holds a HANDLE, not the bytes.
 *
 * An importer that accepted only `artifact_json` would therefore not compose with
 * its own export half — the agent would have to re-export with `return_payload`
 * and push the whole artifact through the conversation, which is exactly what the
 * export tool tells it not to do. `readPageExportArtifact()` is the missing half,
 * and the server resolves the handle to bytes before calling the plugin, because
 * the plugin cannot read the server's local files.
 *
 * The handle is attacker-shaped input in the general case (it arrives as a tool
 * argument), so the traversal refusal is asserted here rather than assumed from
 * `assertPageExportHandle()` being called somewhere.
 */

import { describe, it, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const STORE = mkdtempSync(join(tmpdir(), 'diviops-layout-import-'));
process.env.DIVIOPS_PAGE_EXPORT_REF_DIR = STORE;

// BARE hex, with no `sha256:` prefix: that is what this store's own sha256()
// returns and what it puts in `artifact_ref.checksum`, with `algorithm` naming
// the digest separately. The plugin's expected_artifact_sha256 wants the
// prefixed form, so the import tool adds it -- asserted in the tool, not here.
const sha256 = (s: string) => createHash('sha256').update(s, 'utf8').digest('hex');

let createPageExportRef: typeof import('../page-export-ref.js').createPageExportRef;
let readPageExportArtifact: typeof import('../page-export-ref.js').readPageExportArtifact;

before(async () => {
  const mod = await import('../page-export-ref.js');
  createPageExportRef = mod.createPageExportRef;
  readPageExportArtifact = mod.readPageExportArtifact;
});

after(() => {
  rmSync(STORE, { recursive: true, force: true });
});

const ARTIFACT = JSON.stringify({
  context: 'et_builder',
  data: { '4242': { post_content: '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->' } },
});

describe('readPageExportArtifact', () => {
  it('returns the exact bytes createPageExportRef stored', () => {
    const ref = createPageExportRef(4242, ARTIFACT, sha256(ARTIFACT));
    const read = readPageExportArtifact(ref.handle);

    // Byte equality, not deep-equality of the parsed objects. The whole reason
    // page_export ships a string is that re-encoding changes the bytes its
    // checksum covers, so a round trip that only preserved the OBJECT would
    // silently break every pinned import.
    assert.equal(read.json, ARTIFACT);
    assert.equal(read.checksum, sha256(ARTIFACT));
  });

  it('reports the stored checksum, so a caller can pin without re-hashing', () => {
    const ref = createPageExportRef(7, ARTIFACT, sha256(ARTIFACT));
    assert.equal(readPageExportArtifact(ref.handle).checksum, ref.checksum);
  });

  it('refuses a traversal handle instead of reading outside the store', () => {
    assert.throws(() => readPageExportArtifact('../../etc/passwd'), /invalid/i);
  });

  it('refuses a handle that does not match the minted shape', () => {
    assert.throws(() => readPageExportArtifact('not a handle'), /invalid/i);
  });

  it('reports a missing handle distinctly from an invalid one', () => {
    // A well-formed handle whose file is gone is the EXPIRED case, and it is the
    // one a real caller hits: the store prunes on a TTL, so a handle from
    // yesterday is shaped correctly and absent. Collapsing it into "invalid"
    // would send the caller looking for a malformed argument.
    const ref = createPageExportRef(99, ARTIFACT, sha256(ARTIFACT));
    rmSync(join(STORE, `${ref.handle}.json`));
    assert.throws(() => readPageExportArtifact(ref.handle), /expired|not found|no longer/i);
  });

  it('refuses when the stored bytes no longer match the handle checksum', () => {
    // Detects tampering or a truncated write in the store itself. Without this the
    // corruption would travel to the plugin and be written onto a page.
    const ref = createPageExportRef(1234, ARTIFACT, sha256(ARTIFACT));
    writeFileSync(join(STORE, `${ref.handle}.json`), `${ARTIFACT} `, { encoding: 'utf8' });
    assert.throws(() => readPageExportArtifact(ref.handle), /checksum/i);
  });
});
