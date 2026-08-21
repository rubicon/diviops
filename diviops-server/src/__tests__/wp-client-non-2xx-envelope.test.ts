// SPDX-License-Identifier: MIT
/**
 * Tests for `WPClient.requestEnveloped()`'s handling of a non-2xx response
 * whose body is a well-formed envelope (#260).
 *
 * The enveloped-body early return sat above the `!response.ok` branch, so any
 * parseable envelope was handed straight back and the HTTP status was never
 * consulted. An HTTP 500 carrying `{"ok": true, "data": {...}}` was returned
 * as a successful write.
 *
 * That is the defect class this repository cares about most: a write that
 * reports success and did not happen. It does not need a hostile server. A PHP
 * fatal after the envelope has already been echoed, or a cache or WAF replaying
 * a previously cached 200 body under a 5xx status, both produce it. Every MCP
 * write tool posts through this method.
 *
 * The `ok: false` half of the early return is deliberate and must survive the
 * fix. Returning a non-2xx failure envelope unchanged is what keeps granular
 * codes like `rest_forbidden` and `invalid_type` alive against a mixed-version
 * deployment, instead of collapsing every legacy 4xx into a generic `wp_error`.
 * So these tests pin both directions: the contradiction is rejected, and the
 * agreement is passed through untouched.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { WPClient } from '../wp-client.js';
import { ErrorCodes } from '../envelope.js';

function clientReturning(status: number, body: unknown): WPClient {
  const fetchImpl = (async () =>
    new Response(JSON.stringify(body), {
      status,
      headers: { 'content-type': 'application/json' },
    })) as unknown as typeof globalThis.fetch;

  return new WPClient({
    siteUrl: 'http://example.invalid',
    username: 'u',
    applicationPassword: 'p',
    fetch: fetchImpl,
  });
}

describe('a non-2xx response cannot report a successful write', () => {
  it('refuses an ok:true envelope carried on a 500', async () => {
    const client = clientReturning(500, {
      ok: true,
      data: { id: 99, note: 'never really created' },
    });

    const result = await client.requestEnveloped('/preset/create', {
      method: 'POST',
      body: { name: 'X' },
    });

    assert.equal(
      result.ok,
      false,
      'a write the transport says failed must not come back as a success',
    );
  });

  it('names the contradiction rather than hiding it behind a generic error', async () => {
    // An operator reading this message needs to know the body claimed success,
    // because that is the fact that distinguishes this from an ordinary 500.
    const client = clientReturning(503, { ok: true, data: { id: 1 } });

    const result = await client.requestEnveloped('/preset/create', {
      method: 'POST',
    });

    assert.equal(result.ok, false);
    if (result.ok) return;
    assert.equal(result.error.code, ErrorCodes.WP_ERROR);
    assert.match(result.error.message, /503/);
    assert.match(result.error.message, /ok: true/);
  });

  it('rejects the contradiction on a 4xx as well as a 5xx', async () => {
    // The status family must not matter. A 403 that claims success is the same
    // contradiction as a 500 that claims success.
    const result = await clientReturning(403, {
      ok: true,
      data: {},
    }).requestEnveloped('/preset/create', { method: 'POST' });

    assert.equal(result.ok, false);
  });
});

describe('the non-2xx failure envelope still passes through untouched', () => {
  it('preserves a granular error code from a 403 that admits failure', async () => {
    const body = {
      ok: false,
      error: { code: 'rest_forbidden', message: 'no' },
    };
    const result = await clientReturning(403, body).requestEnveloped(
      '/preset/create',
      { method: 'POST' },
    );

    assert.deepEqual(
      result,
      body,
      'collapsing this into a generic wp_error would lose the upstream slug',
    );
  });

  it('preserves a granular error code from a 404 that admits failure', async () => {
    const body = {
      ok: false,
      error: { code: 'not_found', message: 'gone', hint: 'check the id' },
    };
    const result = await clientReturning(404, body).requestEnveloped('/page/1');

    assert.deepEqual(result, body);
  });
});

describe('a 2xx failure envelope is a failure, not a rewrite target', () => {
  it('returns a 200 ok:false envelope unchanged', async () => {
    // A domain-level failure delivered under HTTP 200. The guard keys on the
    // status, so this takes the pass-through branch — which is right, because
    // the envelope already says what went wrong and rewriting it would replace
    // a specific code with a generic one. This is pinned because the guard's
    // condition mentions `envelope.ok === false`, and it must stay clear that
    // the clause exists to rescue non-2xx failures, not to reclassify 2xx ones.
    const body = {
      ok: false,
      error: { code: 'invalid_input', message: 'name is required' },
    };
    const result = await clientReturning(200, body).requestEnveloped(
      '/preset/create',
      { method: 'POST' },
    );

    assert.deepEqual(result, body);
    assert.equal(result.ok, false);
  });
});

describe('the 2xx boundary is where the guard actually falls', () => {
  it('leaves the 207 partial-failure envelope alone', async () => {
    // #199 ships run-scoped preset_reassign partial failures as HTTP 207 with
    // `ok: false`. 207 is inside Response.ok's 200-299 range, so the guard must
    // not touch it. An over-correction that keyed on `ok === false` instead of
    // on the status would silently rewrite this envelope and destroy the
    // partial-failure code the caller needs to decide what to retry.
    const body = {
      ok: false,
      error: {
        code: 'preset.reassign_partial_failure',
        message: '2 of 5 pages failed',
      },
    };
    const result = await clientReturning(207, body).requestEnveloped(
      '/preset/reassign',
      { method: 'POST' },
    );

    assert.deepEqual(result, body);
  });

  it('treats 299 as success and 300 as not', async () => {
    // Pins the boundary itself, so a future edit to the condition cannot move
    // it without failing here.
    const passing = await clientReturning(299, {
      ok: true,
      data: {},
    }).requestEnveloped('/x', { method: 'POST' });
    assert.equal(passing.ok, true);

    const refused = await clientReturning(300, {
      ok: true,
      data: {},
    }).requestEnveloped('/x', { method: 'POST' });
    assert.equal(refused.ok, false);
  });
});

describe('a 2xx success envelope is unaffected', () => {
  it('returns a 200 ok:true envelope exactly as received', async () => {
    const body = { ok: true, data: { id: 7, name: 'Primary' } };
    const result = await clientReturning(200, body).requestEnveloped(
      '/preset/create',
      { method: 'POST' },
    );

    assert.deepEqual(result, body);
  });

  it('returns a 201 ok:true envelope exactly as received', async () => {
    const body = { ok: true, data: { id: 8 } };
    const result = await clientReturning(201, body).requestEnveloped(
      '/preset/create',
      { method: 'POST' },
    );

    assert.deepEqual(result, body);
  });
});
