// SPDX-License-Identifier: MIT
/**
 * Handshake client-runtime reporting (#123).
 *
 * The plugin's dashboard cannot observe whether WP-CLI works — this server
 * executes it, in a separate process whose environment PHP cannot read. So
 * the server reports it over the handshake it already performs.
 *
 * The contract that matters here is the *absence* case. `client_runtime` is
 * optional in both directions: a plugin predating it ignores the field, and a
 * server that omits it must leave any previously-stored report intact rather
 * than being recorded as a negative. Sending `{ wp_cli: false }` and sending
 * nothing are different statements — "I checked, it's broken" versus "I have
 * nothing to say" — and collapsing them would recreate #123's original defect
 * (a red cross on a working setup) on the other side of the wire.
 *
 * These assert on the request body the client builds, since that body *is* the
 * contract; nothing here touches a live site.
 */
import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';

import { WPClient } from '../wp-client.js';

type CapturedRequest = { url: string; body: Record<string, unknown> };

const originalFetch = globalThis.fetch;
let captured: CapturedRequest[] = [];

function stubFetch() {
  captured = [];
  globalThis.fetch = (async (url: string | URL | Request, init?: RequestInit) => {
    captured.push({
      url: String(url),
      body: init?.body ? JSON.parse(String(init.body)) : {},
    });
    return new Response(
      JSON.stringify({ compatible: true, plugin_version: '1.13.0', capabilities: {} }),
      { status: 200, headers: { 'Content-Type': 'application/json' } },
    );
  }) as typeof fetch;
}

function client() {
  return new WPClient({
    siteUrl: 'https://example.test',
    username: 'u',
    applicationPassword: 'p',
  });
}

describe('handshake client_runtime reporting', () => {
  beforeEach(stubFetch);
  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('omits client_runtime entirely when not supplied', async () => {
    await client().handshake('1.5.39');

    const [req] = captured;
    assert.equal(req.body.mcp_server_version, '1.5.39');
    assert.ok(
      !('client_runtime' in req.body),
      'the key must be absent, not present-and-empty — the plugin distinguishes "no report" from a report, and an empty object would read as the latter',
    );
  });

  it('reports WP-CLI as available when the server has it wired up', async () => {
    await client().handshake('1.5.39', { wp_cli: true });

    assert.deepEqual(captured[0].body.client_runtime, { wp_cli: true });
  });

  it('reports WP-CLI as unavailable explicitly, which is not the same as omitting it', async () => {
    await client().handshake('1.5.39', { wp_cli: false });

    assert.deepEqual(
      captured[0].body.client_runtime,
      { wp_cli: false },
      'an explicit false must survive as false — this is the server saying "I checked and it is not available", which the plugin records as a real negative',
    );
  });

  it('still sends mcp_server_version, which the plugin requires', async () => {
    await client().handshake('2.0.0', { wp_cli: true });

    assert.equal(
      captured[0].body.mcp_server_version,
      '2.0.0',
      'the pre-existing required field must not be disturbed by the additive one',
    );
  });

  it('posts to the handshake endpoint', async () => {
    await client().handshake('1.5.39', { wp_cli: true });

    assert.ok(captured[0].url.endsWith('/wp-json/diviops/v1/handshake'));
  });
});
