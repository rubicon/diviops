// SPDX-License-Identifier: MIT
/**
 * Tests for the transport-failure cause chain (#281).
 *
 * Node's `fetch()` rejects with a `TypeError` whose message is exactly
 * `fetch failed`, regardless of what went wrong. The distinguishing
 * information — `ECONNREFUSED`, `EAI_AGAIN`, `UND_ERR_SOCKET`,
 * `CERT_HAS_EXPIRED` — lives on `error.cause`, and `testConnection()` used to
 * drop it. Every transport failure therefore reported the same four
 * characters, so "nothing is listening", "name resolution broke", and "the
 * socket died mid-flight" were indistinguishable from the envelope alone.
 *
 * That is not a cosmetic loss. An incident on 2026-08-27 had to eliminate TLS,
 * DNS, the secret-injection wrapper, the host sandbox, credentials, keep-alive
 * decay, and abort-signal classification one at a time, because the one field
 * that would have named the failure was discarded before it reached the
 * caller. An error path that emits a constant string no matter what happened
 * cannot be told apart from one that never inspected anything.
 *
 * The depth bound and the cycle test are not hypothetical tidiness. `cause`
 * is an arbitrary caller-supplied value, so a chain can be self-referential;
 * an unbounded walk would hang the error path itself — turning a diagnostic
 * into a second outage.
 *
 * Only `code`/`name` and a truncated message are rendered. A nested cause can
 * carry a request object with a URL or an `Authorization` header, and this
 * string is returned to the MCP client and written to logs.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { WPClient } from '../wp-client.js';

/**
 * A client whose transport always rejects with `error`, matching how Node
 * surfaces a connection-level failure.
 */
function clientRejectingWith(error: unknown): WPClient {
  const fetchImpl = (async () => {
    throw error;
  }) as unknown as typeof globalThis.fetch;

  return new WPClient({
    siteUrl: 'http://example.invalid',
    username: 'u',
    applicationPassword: 'p',
    fetch: fetchImpl,
  });
}

/** A `TypeError('fetch failed')` shaped the way undici produces one. */
function fetchFailed(cause?: unknown): TypeError {
  const error = new TypeError('fetch failed');
  if (arguments.length > 0) {
    (error as { cause?: unknown }).cause = cause;
  }
  return error;
}

describe('a transport failure names the underlying cause', () => {
  it('surfaces the errno of a refused connection', async () => {
    const client = clientRejectingWith(
      fetchFailed(Object.assign(new Error('connect ECONNREFUSED'), { code: 'ECONNREFUSED' })),
    );

    const result = await client.testConnection();

    assert.equal(result.ok, false);
    assert.match(
      result.message,
      /ECONNREFUSED/,
      'the errno is the whole diagnostic value of a transport failure and must reach the caller',
    );
  });

  it('renders a nested chain outermost-first', async () => {
    const inner = Object.assign(new Error('read ECONNRESET'), { code: 'ECONNRESET' });
    const outer = Object.assign(new Error('other side closed'), {
      code: 'UND_ERR_SOCKET',
      cause: inner,
    });

    const client = clientRejectingWith(fetchFailed(outer));

    const result = await client.testConnection();

    assert.equal(result.ok, false);
    assert.match(result.message, /UND_ERR_SOCKET.*ECONNRESET/, result.message);
  });

  it('leaves a causeless error exactly as it reads today', async () => {
    const client = clientRejectingWith(fetchFailed());

    const result = await client.testConnection();

    assert.equal(result.ok, false);
    assert.equal(
      result.message,
      'Connection failed: fetch failed',
      'adding an empty "(cause: )" to every causeless error would be a regression in its own right',
    );
  });

  it('terminates on a self-referential cause chain', async () => {
    const looping = Object.assign(new Error('loops'), { code: 'ELOOP' }) as Error & {
      cause?: unknown;
    };
    looping.cause = looping;

    const client = clientRejectingWith(fetchFailed(looping));

    const result = await client.testConnection();

    assert.equal(result.ok, false);
    assert.match(result.message, /ELOOP/);
    assert.ok(
      result.message.length < 500,
      `a bounded walk must not render an unbounded chain; got ${result.message.length} chars`,
    );
  });

  it('prefers a codeless cause message over its generic class name', async () => {
    // Surfaced by the smoke harness, whose stub cause is a plain `Error`:
    // rendering "(cause: Error)" restates the type system and tells the reader
    // nothing, which is the exact failure #281 set out to end.
    const client = clientRejectingWith(fetchFailed(new Error('socket hang up')));

    const result = await client.testConnection();

    assert.equal(result.ok, false);
    assert.match(result.message, /socket hang up/, result.message);
    assert.doesNotMatch(
      result.message,
      /cause: Error\b/,
      'a bare class name is not a diagnostic',
    );
  });

  it('does not widen a nested cause into a credential or URL leak', async () => {
    const leaky = Object.assign(new Error('request failed'), {
      code: 'UND_ERR_SOCKET',
      request: {
        origin: 'https://site.example',
        headers: { Authorization: 'Basic c3VwZXItc2VjcmV0' },
      },
    });

    const client = clientRejectingWith(fetchFailed(leaky));

    const result = await client.testConnection();

    assert.equal(result.ok, false);
    assert.doesNotMatch(
      result.message,
      /Basic |c3VwZXItc2VjcmV0|Authorization/,
      'this string is returned to the MCP client and written to logs',
    );
  });

  it('still throws the abort reason rather than reporting a connection failure', async () => {
    const controller = new AbortController();
    const reason = new Error('cancelled by caller');
    controller.abort(reason);

    const client = clientRejectingWith(fetchFailed({ code: 'ECONNREFUSED' }));

    await assert.rejects(
      () => client.testConnection(controller.signal),
      (thrown: unknown) => thrown === reason,
      'a cancelled call must not be reported as a site problem',
    );
  });
});
