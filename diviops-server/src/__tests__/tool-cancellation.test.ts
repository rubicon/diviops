/**
 * Plugin-tool cancellation at the MCP tool boundary (#134).
 *
 * `wp-client-cancellation.test.ts` proves `WPClient` honors an *explicit*
 * signal. Nothing proved that the SDK's per-request `extra.signal` — the one
 * a `notifications/cancelled` actually aborts — ever reaches the transport for
 * the ~145 plugin/pro tools, because the registration wrappers dropped `extra`
 * before the handler ran. These assertions exercise the real registered
 * handlers out of the real finalized registry, with a recording `fetch`
 * injected in front of the module-level `WPClient`.
 *
 * The cancellation contract is deliberately asymmetric, and both halves are
 * asserted here:
 *   - read / idempotent tools hand the signal to `fetch`, so a cancel aborts
 *     the in-flight request;
 *   - mutating tools withhold it, so a write already on the wire completes
 *     rather than tearing open mid-transaction (an aborted HTTP write has no
 *     WordPress-side rollback), while a cancel that lands *before* dispatch
 *     still fails fast with no bytes sent.
 *
 * `globalThis.fetch` is replaced before `../index.js` is imported because
 * `WPClient` binds its fetch at construction time and the server constructs
 * its client at module load. Nothing here touches a live site.
 */
import { describe, it, before, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

type FetchCall = { url: string; init: RequestInit | undefined };

let calls: FetchCall[] = [];
let respond: (url: string, init: RequestInit | undefined) => Promise<Response> = async () =>
  new Response(JSON.stringify({ ok: true, data: {} }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });

// Installed BEFORE index.js is imported (see file header). The indirection
// through `respond` lets each test swap the behavior without re-importing.
globalThis.fetch = (async (url: string | URL | Request, init?: RequestInit) => {
  calls.push({ url: String(url), init });
  return respond(String(url), init);
}) as typeof fetch;

type RecordedTool = { name: string; config: Record<string, unknown>; handler: (...args: unknown[]) => Promise<unknown> };

const handlers = new Map<string, RecordedTool>();

/**
 * Capability keys for exactly the tools these assertions invoke. The gate in
 * `registerPluginTool` short-circuits with a missing-capability envelope
 * before any wp call, so a tool under test has to be advertised or the test
 * would assert against the gate instead of the transport.
 */
const CAPABILITIES: Record<string, boolean> = {
  page_list: true,
  page_update_content: true,
  schema_list_modules: true,
  cross_env_source_export_get: true,
};

before(async () => {
  const index = await import('../index.js');
  const registry = index.finalizeProductionRegistryForHandshake({
    kind: 'ok',
    capabilities: CAPABILITIES,
    pluginVersion: '99.0.0',
    proActive: false,
    availableTargets: {},
    activeModules: {},
    plugins: {},
  });
  registry.install({
    registerTool(name: string, config: unknown, handler: unknown) {
      handlers.set(name, {
        name,
        config: config as Record<string, unknown>,
        handler: handler as (...args: unknown[]) => Promise<unknown>,
      });
    },
    registerResource() {},
  });
});

function tool(name: string): RecordedTool {
  const found = handlers.get(name);
  assert.ok(found, `${name} should be present in the finalized registry`);
  return found;
}

/** The `RequestHandlerExtra` shape the SDK hands a tool callback. */
function extra(signal: AbortSignal) {
  return { signal, requestId: 1, sendNotification: async () => {}, sendRequest: async () => {} };
}

describe('plugin-tool cancellation', () => {
  beforeEach(() => {
    calls = [];
    respond = async () =>
      new Response(JSON.stringify({ ok: true, data: {} }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
  });

  it('threads the MCP request signal into fetch for a read tool', async () => {
    const controller = new AbortController();
    await tool('diviops_page_list').handler({ post_type: 'page' }, extra(controller.signal));

    assert.equal(calls.length, 1, 'the read tool should have issued exactly one request');
    assert.equal(
      calls[0].init?.signal,
      controller.signal,
      'the same AbortSignal instance the SDK created for this request should reach fetch',
    );
  });

  it('threads the signal for a tool registered without an inputSchema, where the SDK passes extra as the only argument', async () => {
    const controller = new AbortController();
    // diviops_schema_list_modules declares no inputSchema, so the SDK calls
    // handler(extra) — extra is argument zero, not one. A wrapper that reads
    // a fixed second position silently disables cancellation for every such
    // tool.
    assert.equal(
      tool('diviops_schema_list_modules').config.inputSchema,
      undefined,
      'this assertion is only meaningful while the tool genuinely has no inputSchema',
    );
    await tool('diviops_schema_list_modules').handler(extra(controller.signal));

    assert.equal(calls.length, 1);
    assert.equal(calls[0].init?.signal, controller.signal);
  });

  it('withholds the signal from fetch for a mutating tool so an in-flight write is never torn open', async () => {
    const controller = new AbortController();
    await tool('diviops_page_update_content').handler(
      { page_id: 12, content: '<!-- wp:divi/section --><!-- /wp:divi/section -->' },
      extra(controller.signal),
    );

    assert.equal(calls.length, 1, 'the write should have been dispatched');
    assert.ok(
      calls[0].init?.signal === undefined || calls[0].init?.signal === null,
      'a mutating tool must not hand the abort signal to fetch — an aborted HTTP write has no WordPress-side rollback',
    );
  });

  it('fails a mutating tool fast when the request is already cancelled, before any bytes are sent', async () => {
    const controller = new AbortController();
    controller.abort(new Error('cancelled before dispatch'));

    await assert.rejects(
      () =>
        tool('diviops_page_update_content').handler(
          { page_id: 12, content: '<!-- wp:divi/section --><!-- /wp:divi/section -->' },
          extra(controller.signal),
        ),
      (err: unknown) => err instanceof Error && err.message === 'cancelled before dispatch',
      'an already-cancelled write must reject with the abort reason rather than dispatching',
    );
    assert.equal(calls.length, 0, 'no request should have been sent for an already-cancelled write');
  });

  it('fails a read tool fast when the request is already cancelled', async () => {
    const controller = new AbortController();
    controller.abort(new Error('cancelled before dispatch'));

    await assert.rejects(
      () => tool('diviops_page_list').handler({ post_type: 'page' }, extra(controller.signal)),
      (err: unknown) => err instanceof Error && err.message === 'cancelled before dispatch',
    );
    assert.equal(calls.length, 0);
  });

  it('surfaces a mid-flight abort as a cancellation instead of a soft wp_error envelope', async () => {
    // diviops_cross_env_source_export_get wraps its wp call in wrapResponse,
    // which catches everything and returns { ok:false, error:{ code:'wp_error' } }.
    // A cancelled call must not be reported to the client as a connection
    // failure it can retry — it has to reject so the SDK suppresses the
    // response for the request the client already gave up on.
    const controller = new AbortController();
    respond = async (_url, init) => {
      controller.abort(new Error('cancelled mid-flight'));
      // Mirror what a real fetch does once its signal aborts.
      throw init?.signal?.reason ?? new Error('aborted');
    };

    await assert.rejects(
      () =>
        tool('diviops_cross_env_source_export_get').handler(
          { source_id: 7, source_kind: 'tb_header_layout' },
          extra(controller.signal),
        ),
      (err: unknown) => err instanceof Error && err.message === 'cancelled mid-flight',
      'wrapResponse must rethrow when the request signal is aborted, not swallow it into an envelope',
    );
  });

  it('keeps concurrent tool invocations isolated — cancelling one does not cancel the other', async () => {
    const cancelled = new AbortController();
    const surviving = new AbortController();

    let releaseFirst: () => void = () => {};
    const firstDispatched = new Promise<void>((resolve) => {
      releaseFirst = resolve;
    });

    respond = async (url) => {
      if (url.includes('/page/list')) {
        releaseFirst();
        await new Promise((resolve) => setTimeout(resolve, 10));
      }
      return new Response(JSON.stringify({ ok: true, data: {} }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    };

    const first = tool('diviops_page_list').handler({ post_type: 'page' }, extra(cancelled.signal));
    await firstDispatched;
    const second = tool('diviops_schema_list_modules').handler(extra(surviving.signal));
    await Promise.allSettled([first, second]);

    const listCall = calls.find((c) => c.url.includes('/page/list'));
    const modulesCall = calls.find((c) => c.url.includes('/schema/modules'));
    assert.ok(listCall && modulesCall, 'both concurrent invocations should have reached fetch');
    assert.equal(listCall.init?.signal, cancelled.signal);
    assert.equal(
      modulesCall.init?.signal,
      surviving.signal,
      'each invocation carries its own request context; one must not leak into the other',
    );
  });

  it('binds no ambient signal for a wp call made outside any tool invocation', async () => {
    const { WPClient } = await import('../wp-client.js');
    const client = new WPClient({
      siteUrl: 'https://example.test',
      username: 'u',
      applicationPassword: 'p',
    });
    // Startup's own handshake runs here — outside any tool context. Nothing
    // should be bound to it.
    await client.request('/handshake', { method: 'POST', body: {} });

    assert.equal(calls.length, 1);
    assert.ok(
      calls[0].init?.signal === undefined || calls[0].init?.signal === null,
      'a wp call outside a tool invocation must not pick up a signal',
    );
  });
});
