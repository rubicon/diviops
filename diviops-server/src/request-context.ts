// SPDX-License-Identifier: MIT
/**
 * Per-MCP-request context, carried ambiently for the duration of a tool call (#134).
 *
 * The SDK creates one `AbortController` per request and aborts it when the
 * client sends `notifications/cancelled`. Getting that signal from the tool
 * boundary down to the `fetch` inside `WPClient` would otherwise mean adding a
 * signal parameter to ~145 handlers and 136 `wp.request`/`wp.requestEnveloped`
 * call sites. `AsyncLocalStorage` carries it instead: the registration wrapper
 * establishes the context, and `WPClient` reads it at dispatch time.
 *
 * `abortTransport` is the cancellation policy, decided once per tool from its
 * own registration metadata. Reads and idempotent operations may be torn down
 * mid-flight. Mutations may not: an aborted HTTP write gets no WordPress-side
 * rollback, so a half-applied `module_update` is worse than a write that
 * finishes after the client stopped listening. A mutation still refuses to
 * dispatch at all once the signal has aborted, which costs nothing because
 * no bytes have been sent.
 *
 * This module deliberately has no dependencies — both `index.ts` (the
 * producer) and `wp-client.ts` / `envelope.ts` (the consumers) import it.
 */

import { AsyncLocalStorage } from "node:async_hooks";

export type RequestContext = {
  /** The MCP request's abort signal, when the SDK provided one. */
  signal?: AbortSignal;
  /**
   * Whether the underlying WordPress HTTP request may be aborted mid-flight.
   * True for read-only / idempotent tools, false for mutations.
   */
  abortTransport: boolean;
};

const store = new AsyncLocalStorage<RequestContext>();

/** Run `fn` (and everything it awaits) with `ctx` as the ambient request context. */
export function runWithRequestContext<T>(ctx: RequestContext, fn: () => T): T {
  return store.run(ctx, fn);
}

/**
 * The ambient request context, or `undefined` outside a tool invocation —
 * startup's own handshake, for instance, binds nothing.
 */
export function getRequestContext(): RequestContext | undefined {
  return store.getStore();
}
