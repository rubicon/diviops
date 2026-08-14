/**
 * WPClient cancellation + fetch injection (upstream ba008d2, adopted as PR 1).
 *
 * The injected `fetch` config option exists specifically so this is testable
 * without monkeypatching globalThis.fetch (the pattern
 * handshake-client-runtime.test.ts uses for its own, separate reasons).
 * Nothing here touches a live site.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { WPClient } from '../wp-client.js';

function client(fetchImpl: typeof fetch) {
  return new WPClient({
    siteUrl: 'https://example.test',
    username: 'user',
    applicationPassword: 'pass',
    fetch: fetchImpl,
  });
}

describe('WPClient cancellation', () => {
  it('uses the injected fetch instead of globalThis.fetch', async () => {
    let called = false;
    const fake = (async (_url: string | URL | Request, _init?: RequestInit) => {
      called = true;
      return new Response(JSON.stringify({ ok: true }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }) as typeof fetch;

    await client(fake).request('/meta/ping');
    assert.equal(called, true, 'the injected fetch implementation should have been called');
  });

  it('threads an explicit AbortSignal through to the injected fetch', async () => {
    let receivedSignal: AbortSignal | null | undefined;
    const fake = (async (_url: string | URL | Request, init?: RequestInit) => {
      receivedSignal = init?.signal;
      return new Response(JSON.stringify({ ok: true }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }) as typeof fetch;

    const controller = new AbortController();
    await client(fake).request('/meta/ping', { signal: controller.signal });
    assert.equal(receivedSignal, controller.signal, 'the same AbortSignal instance should reach fetch');
  });

  it('testConnection rethrows the abort reason when the signal is already aborted', async () => {
    const controller = new AbortController();
    controller.abort(new Error('cancelled by caller'));

    const fake = (async () => {
      throw new Error('fetch should not be reached after abort, but if it is, this is the transport error');
    }) as typeof fetch;

    await assert.rejects(
      () => client(fake).testConnection(controller.signal),
      (err: unknown) => err instanceof Error && err.message === 'cancelled by caller',
      'testConnection should rethrow the abort reason, not wrap it in a generic connection-failed message',
    );
  });
});
