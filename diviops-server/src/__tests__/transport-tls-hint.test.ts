// SPDX-License-Identifier: MIT
/**
 * Remediation hint for a rejected self-signed certificate (#284).
 *
 * A self-signed certificate is the normal case for local WordPress development —
 * Local by Flywheel, MAMP, Valet and DDEV all issue one. Node rejects it while
 * `curl` accepts it from the system keychain, so every hand-run reproduction
 * succeeds and the investigation is steered away from TLS. On 2026-08-27 that
 * cost hours across three sessions, and TLS was explicitly *refuted* on the
 * evidence of a `curl` that returned 200.
 *
 * #281 made the errno visible. It is still not actionable on its own:
 * `DEPTH_ZERO_SELF_SIGNED_CERT` reads as a problem with the site, when the site
 * is fine and the fix is one environment variable on this process.
 *
 * Two boundaries this pins deliberately:
 *
 * - The hint must name `NODE_EXTRA_CA_CERTS` and must never name
 *   `NODE_TLS_REJECT_UNAUTHORIZED`. The first adds trust for one certificate;
 *   the second disables verification for every host the process later contacts,
 *   including production ones. A convenience hint that teaches the insecure
 *   fix is worse than no hint.
 * - An unrelated cause gets no TLS hint. Guessing at a cause we did not observe
 *   is precisely how the original investigation went wrong, and a confidently
 *   wrong hint would repeat that at scale.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { wrapResponse } from '../envelope.js';
import { WPClient } from '../wp-client.js';

/** A `TypeError('fetch failed')` shaped the way undici produces one. */
function fetchFailed(code: string): TypeError {
  const error = new TypeError('fetch failed');
  (error as { cause?: unknown }).cause = Object.assign(new Error(code), { code });
  return error;
}

async function envelopeFor(thrown: unknown) {
  return wrapResponse(async () => {
    throw thrown;
  });
}

const TLS_CODES = [
  'DEPTH_ZERO_SELF_SIGNED_CERT',
  'SELF_SIGNED_CERT_IN_CHAIN',
  'UNABLE_TO_VERIFY_LEAF_SIGNATURE',
];

describe('a rejected self-signed certificate explains itself', () => {
  for (const code of TLS_CODES) {
    it(`names NODE_EXTRA_CA_CERTS for ${code}`, async () => {
      const result = await envelopeFor(fetchFailed(code));

      assert.equal(result.ok, false);
      const error = (result as { error: { message: string; hint?: string } }).error;
      assert.ok(error.hint, `${code} produced no hint`);
      assert.match(error.hint as string, /NODE_EXTRA_CA_CERTS/);
    });
  }

  it('never suggests disabling verification', async () => {
    for (const code of TLS_CODES) {
      const result = await envelopeFor(fetchFailed(code));
      const hint = (result as { error: { hint?: string } }).error.hint ?? '';
      assert.doesNotMatch(
        hint,
        /NODE_TLS_REJECT_UNAUTHORIZED/,
        `${code} hint offered the insecure fix`,
      );
    }
  });

  it('stays silent on a cause it did not observe', async () => {
    const result = await envelopeFor(fetchFailed('ECONNREFUSED'));

    assert.equal(result.ok, false);
    const error = (result as { error: { message: string; hint?: string } }).error;
    assert.equal(
      error.hint,
      undefined,
      'a refused connection is not a certificate problem and must not be labelled one',
    );
    assert.match(error.message, /ECONNREFUSED/, 'the errno itself must still survive');
  });
});

describe('every tool, not just meta_ping, reports the cause chain', () => {
  it('renders the cause on a transport error thrown from any tool', async () => {
    // #281 applied describeError only in testConnection and the boot handshake,
    // so a page/preset/module tool still surfaced a bare "fetch failed". They
    // all funnel through this path.
    const result = await envelopeFor(fetchFailed('EAI_AGAIN'));

    assert.equal(result.ok, false);
    assert.match(
      (result as { error: { message: string } }).error.message,
      /EAI_AGAIN/,
      'a transport failure from an ordinary tool must name its errno too',
    );
  });
});

describe('meta_ping carries the hint through the connection check', () => {
  it('returns a hint alongside the failure message', async () => {
    const fetchImpl = (async () => {
      throw fetchFailed('DEPTH_ZERO_SELF_SIGNED_CERT');
    }) as unknown as typeof globalThis.fetch;

    const client = new WPClient({
      siteUrl: 'http://example.invalid',
      username: 'u',
      applicationPassword: 'p',
      fetch: fetchImpl,
    });

    const result = await client.testConnection();

    assert.equal(result.ok, false);
    assert.match(result.message, /DEPTH_ZERO_SELF_SIGNED_CERT/);
    assert.ok(result.hint, 'testConnection dropped the hint');
    assert.match(result.hint as string, /NODE_EXTRA_CA_CERTS/);
  });

  it('reports no hint when the connection failed for another reason', async () => {
    const fetchImpl = (async () => {
      throw fetchFailed('ECONNREFUSED');
    }) as unknown as typeof globalThis.fetch;

    const client = new WPClient({
      siteUrl: 'http://example.invalid',
      username: 'u',
      applicationPassword: 'p',
      fetch: fetchImpl,
    });

    const result = await client.testConnection();

    assert.equal(result.ok, false);
    assert.equal(result.hint, undefined);
  });
});
