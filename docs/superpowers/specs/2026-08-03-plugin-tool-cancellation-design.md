# Plugin-tool cancellation (issue #134) — design spec

Date: 2026-08-03
Issue: [#134](https://github.com/rubicon/diviops/issues/134)
Status: design — pending owner review

## Revision history

- 2026-08-03, initial design. Two owner decisions (via AskUserQuestion): threading
  mechanism = **AsyncLocalStorage** (over explicit per-handler threading); scope =
  **all three tool wrappers** + **abort-as-cancellation** semantics.
- 2026-08-03, revised after a Codex adversarial design review (no code yet — the design
  itself was reviewed). Four findings, all verified against the installed SDK/code before
  acceptance, materially changed the design: (1) the SDK calls `handler(extra)` — not
  `(args, extra)` — for tools with no `inputSchema`, so the wrapper must normalize arity;
  (2) store a context object, not a bare signal; (3) key the abort discriminator on the
  signal's `.aborted` flag, not `error.name === 'AbortError'` (Node 22 can reject `fetch`
  with a raw-string abort reason); (4) **do not abort destructive writes mid-flight** — an
  aborted HTTP write cannot roll back WordPress-side, so cancellation is gated by each
  tool's read-vs-mutation metadata. Owner confirmed the write-abort policy 2026-08-03.

## Problem

A cancelled MCP tool call (client sends `notifications/cancelled`) does not stop the
in-flight WordPress HTTP request for the ~145 plugin/pro/local tools. Only the two meta
tools (`diviops_meta_ping`/`diviops_meta_info`) honor cancellation, via an explicit
`requestAbortSignal` helper. This is a beyond-upstream extension — `upstream/main`'s own
`registerPluginTool` also drops the signal, so upstream wires cancellation only to the meta
tools. #134 extends it to every tool.

## Verified facts (against the installed code — cite before building on)

- The SDK creates one `AbortController` per request, exposes it as
  `RequestHandlerExtra.signal`, and aborts it on a matching `notifications/cancelled`
  (`@modelcontextprotocol/sdk` `dist/esm/shared/protocol.js:314,174`).
- **Callback arity depends on `inputSchema`** (`dist/esm/server/mcp.js:229-238`): a tool
  whose config has a truthy `inputSchema` (even `{}`) is called `handler(args, extra)`; a
  tool with no `inputSchema` key is called `handler(extra)` — `extra` is the FIRST and only
  argument. Example: `diviops_schema_list_modules` (`index.ts`) has no `inputSchema` and
  calls WP. A naive `(args, extra)` wrapper would bind `args = extra`, `extra = undefined`,
  silently disabling cancellation for every no-`inputSchema` tool. (This is why PR 3's
  `requestAbortSignal(_args, context)` defensively reads both argument positions.)
- The 3 wrappers (`registerPluginTool`, `registerLocalTool`, `registerProTool` in
  `index.ts`) currently drop the SDK's `extra`. There are 136 `wp.request(` /
  `wp.requestEnveloped(` call sites across ~145 handlers; handlers are shaped
  `async ({ ...args }) => …` with no signal parameter.
- `wp-client.ts`'s `request()`/`requestEnveloped()` already accept an optional explicit
  `signal?` and pass it to `fetch` (PR 1); WPClient accepts an injected `fetch` (PR 1).
- `wrapResponse` (`envelope.ts:137`) catches ALL thrown errors and converts them to
  `{ ok:false, error:{ code:'wp_error' | … } }` — it never throws. Many handlers wrap their
  logic in `wrapResponse`, so without a change an abort would be swallowed into a soft
  "connection failed" envelope instead of surfacing as a cancellation.
- No executable `wp.request()`/`wp.requestEnveloped()` calls happen OUTSIDE a tool handler
  in `src/` today (verified in the Codex review). `main()`'s startup `wp.handshake()` runs
  before the transport connects and before any tool call — so the ALS store is empty there,
  and the ambient-signal fallback binds nothing at startup.

## Design

### Cancellation policy (owner-confirmed) — the core behavioral contract

Cancellation is gated by each tool's own metadata (already present on every registration):

- **Read / idempotent tools** — `annotations.readOnlyHint === true` OR
  `_meta.idempotent === "true"` OR `annotations.idempotentHint === true`: the ambient signal
  reaches `fetch`, so a cancel **aborts the in-flight request mid-flight**. Safe — aborting a
  read (or a retry-safe idempotent op) leaves nothing partial behind.
- **Mutating tools** — everything else: the signal is **NOT** handed to `fetch`. The request
  is allowed to **complete** even if cancelled mid-write. But before dispatch, the wp call
  checks `signal.aborted` and **fails fast** (rejects before sending) if the request is
  already cancelled — no bytes sent, no partial state. A cancel arriving mid-write is
  effectively advisory. Rationale: an aborted HTTP write cannot guarantee a WordPress-side
  rollback; tearing a `module_update`/`page_create`/`global_color_upsert` open mid-transaction
  risks partial state, which is worse than letting it finish.

So "every tool honors cancellation" holds, but the *action* differs by tool class: reads
abort transport; writes fail-fast-before-dispatch only.

### Components (5 files)

**1. NEW `src/request-context.ts`** — the ALS and its accessors. No dependencies; imported by
both `index.ts` (wrappers) and `wp-client.ts` (below both in the layering).

```ts
import { AsyncLocalStorage } from "node:async_hooks";

export type RequestContext = {
  /** The MCP request's abort signal, if the SDK provided one. */
  signal?: AbortSignal;
  /** Whether it is safe to abort the underlying WP HTTP transport mid-flight
   *  (true for read-only / idempotent tools; false for mutations). */
  abortTransport: boolean;
};

const store = new AsyncLocalStorage<RequestContext>();

export function runWithRequestContext<T>(ctx: RequestContext, fn: () => T): T {
  return store.run(ctx, fn);
}

export function getRequestContext(): RequestContext | undefined {
  return store.getStore();
}
```

**2. `src/index.ts` — a shared wrapper helper + the 3 wrappers.** Add one helper that
normalizes the SDK arity and establishes the context, then have each wrapper use it. The
helper (illustrative — final names at implementation time):

```ts
/** True when it is safe to abort this tool's WP transport mid-flight. */
function toolTransportAbortable(config: any): boolean {
  const a = config?.annotations;
  return a?.readOnlyHint === true
    || a?.idempotentHint === true
    || config?._meta?.idempotent === "true";
}

/** Run `handler` inside a request context carrying the SDK signal, preserving the
 *  SDK's exact call arity. `extra` (RequestHandlerExtra) is ALWAYS the last argument
 *  the SDK passes, whether the tool has an inputSchema (args, extra) or not (extra). */
function withRequestContext(config: any, handler: (...a: any[]) => Promise<any>) {
  return async (...callArgs: any[]) => {
    const extra = callArgs[callArgs.length - 1];
    const ctx: RequestContext = {
      signal: extra?.signal instanceof AbortSignal ? extra.signal : undefined,
      abortTransport: toolTransportAbortable(config),
    };
    return runWithRequestContext(ctx, () => handler(...callArgs));
  };
}
```

- `registerPluginTool`: keep the `requireCapability(key)` short-circuit first (it returns the
  missing-capability envelope before any wp call, so it can stay outside the context), then
  register `withRequestContext(config, wrappedHandlerWithCapabilityCheck)`. Concretely, the
  existing `wrapped` becomes variadic and its `return handler(args)` becomes the
  context-wrapped, arity-preserving `runWithRequestContext(ctx, () => handler(...callArgs))`.
- `registerLocalTool` / `registerProTool`: wrap the handler in `withRequestContext(config, handler)`
  before `registry.registerTool(name, config, wrapped)`.
- **No handler signatures change.** `...callArgs` is passed through verbatim, so each handler
  receives exactly what the SDK would have handed it today.

**3. `src/wp-client.ts` — ambient signal + policy, in `request()` and `requestEnveloped()`**
(and, for consistency, `testConnection`/`handshake`). Before dispatch:

```ts
const ctx = getRequestContext();
const ambient = ctx?.signal;
// Fail fast if the request is already cancelled — for BOTH reads and mutations,
// since nothing has been sent yet (no partial state).
if ((options.signal ?? ambient)?.aborted) {
  throw (options.signal ?? ambient)!.reason ?? new Error("Request cancelled");
}
// Reads/idempotent: hand the signal to fetch (abort mid-flight OK).
// Mutations (ctx.abortTransport === false): do NOT abort mid-flight — let it complete.
const effectiveSignal = options.signal ?? (ctx?.abortTransport ? ambient : undefined);
// … pass effectiveSignal to fetch (existing PR-1 plumbing) …
```

Explicit `options.signal` (already threaded by the meta tools) still wins. The ambient signal
is the fallback, applied to `fetch` only when policy allows (reads/idempotent). This makes all
136 call sites cancellable with zero call-site edits.

**4. `src/envelope.ts` — `wrapResponse` abort-as-cancellation.** At the TOP of the `catch`,
before the `DiviopsError` branch:

```ts
} catch (e) {
  // A cancelled request must surface as a cancellation, not a soft wp_error envelope.
  // Key on the signal's aborted flag, NOT error.name === 'AbortError': Node 22 can reject
  // fetch with a raw-string abort reason, so the error shape is unreliable.
  if (getRequestContext()?.signal?.aborted) throw e;
  if (e instanceof DiviopsError) { … }        // unchanged
  return { ok: false, error: parseThrownError(e) };  // unchanged
}
```

The SDK converts a thrown handler error into an `isError` tool result but suppresses the
response entirely when the request signal is aborted (`protocol.js:365`) — so rethrowing only
when `.aborted` is safe and correct, and never turns a normal error into a spurious result.

**5. NEW `src/__tests__/tool-cancellation.test.ts`** — a real tool-boundary test, no
WP-primitive mocks. Uses the real wrappers + a real `WPClient` with an injected recording
`fetch`. Assertions:

- **(read) signal reaches fetch via ALS**: a read-only tool (no explicit signal in the
  handler) — the ambient signal instance arrives at `fetch`.
- **(mutation) signal withheld from fetch**: a mutating tool's `fetch` receives NO signal
  (not aborted mid-flight), proving the policy gate.
- **(mutation) fail-fast when already cancelled**: a mutating tool invoked with an
  already-aborted signal rejects before any `fetch` call (recording fetch never called).
- **abort-as-cancellation**: an aborted read surfaces as a rejection, NOT a
  `{ ok:false, error:{ code:'wp_error' } }` envelope (through `wrapResponse`).
- **no-`inputSchema` arity**: a tool registered with no `inputSchema` (SDK calls `handler(extra)`)
  still has its signal extracted (from the single last arg) and threaded.
- **concurrency isolation**: two overlapping tool invocations each carry their own signal —
  aborting one does not abort the other's fetch (ALS isolation).
- **outside a tool context**: `getRequestContext()` is `undefined` outside `runWithRequestContext`,
  so a `wp.*` call made outside any tool (e.g. startup) binds no ambient signal.

### Data flow

client cancels → SDK aborts the per-request controller → `extra.signal` fires → the same
signal object in the ALS `RequestContext` fires → for a read tool, the in-flight `fetch`
(which received `effectiveSignal`) aborts → the `wp.*` call rejects → `wrapResponse` sees
`getRequestContext().signal.aborted` and rethrows → the handler rejects on a request the
client already cancelled (SDK suppresses the response). For a mutation, `fetch` never received
the signal, so the write completes; a cancel that lands before dispatch fails fast instead.

## Testing approach

TDD, failing-first, against the real wrappers + injected `fetch` (no mocked WP primitives —
this is genuinely unit-testable because the signal path is pure Node/ALS, not WordPress). Each
assertion above is written to fail before the corresponding piece exists. Plus the full server
suite (`npm test`), the `test:server-security` lane, and `verify-server-builds-and-starts` to
confirm no regression. The new test file runs on the normal build path (it imports the built
`index.js`, like the PR 3 registry test) — so it gets the same dedicated CI-step treatment, NOT
the scoped `test:server-security` lane, to avoid the `ERR_MODULE_NOT_FOUND` trap PR 3 hit.

## Out of scope

- The meta tools keep their existing explicit `requestAbortSignal` wiring (already works); no
  need to reroute them through ALS.
- No new runtime dependency (`AsyncLocalStorage` is the Node built-in `node:async_hooks`).
- No handler signature changes; no changes to the 136 wp call sites.
- Per-write cancellation-token propagation into WordPress/PHP (true server-side rollback) —
  out of scope and likely infeasible; the fail-fast-before-dispatch policy is the safe bound.
