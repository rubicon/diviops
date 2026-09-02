// SPDX-License-Identifier: MIT
/**
 * Site identity in the preflight surface (#343).
 *
 * `meta_ping` proved that *a* Divi install answered and never said which one,
 * and `meta_info` reported everything about the connection except the site.
 * That matters because `cross_env_header_apply` and `cross_env_layout_apply`
 * write layouts across environments: a misidentified source or target makes
 * those writes destructive and hard to reverse, and the preflight meant to
 * prevent it could not answer "which site is this".
 *
 * `home_url` is the load-bearing field, so a payload without one yields no
 * identity at all rather than a partial block that answers a question nobody
 * asked while the one that matters stays unanswered.
 *
 * `environment_type` is advisory. WordPress answers `production` whenever
 * `WP_ENVIRONMENT_TYPE` is undefined, which is the default, so a staging site
 * reports itself as production — measured on a real staging host. Nothing may
 * branch on it, and the invariance test below pins that on this side of the
 * wire the way tests/test-meta-site-identity.php pins it on the plugin's.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { normalizeSiteIdentity } from '../compatibility.js';
import { WPClient } from '../wp-client.js';

const IDENTITY = {
  home_url: 'https://staging.example.test',
  site_name: 'Identity Fixture',
  environment_type: 'production',
  is_multisite: false,
  wp_version: '6.8.1',
};

function clientReturning(body: unknown): WPClient {
  const fetchImpl = (async () =>
    new Response(JSON.stringify(body), {
      status: 200,
      headers: { 'content-type': 'application/json' },
    })) as unknown as typeof globalThis.fetch;

  return new WPClient({
    siteUrl: 'http://example.invalid',
    username: 'u',
    applicationPassword: 'p',
    fetch: fetchImpl,
  });
}

describe('normalizeSiteIdentity', () => {
  it('reads a well-formed identity block', () => {
    assert.deepEqual(normalizeSiteIdentity(IDENTITY), IDENTITY);
  });

  it('reports no identity at all when home_url is missing', () => {
    // A block naming the site but not its URL is worse than nothing: it reads
    // as an answer while leaving the only field an operator compares blank.
    assert.equal(
      normalizeSiteIdentity({ site_name: 'Somewhere', environment_type: 'production' }),
      null,
    );
  });

  it('reports no identity for a plugin too old to send one', () => {
    for (const absent of [undefined, null, 'nope', 42, []]) {
      assert.equal(normalizeSiteIdentity(absent), null);
    }
  });

  it('answers null rather than guessing for fields it did not receive', () => {
    const identity = normalizeSiteIdentity({ home_url: 'https://example.test' });

    assert.equal(identity?.home_url, 'https://example.test');
    assert.equal(identity?.environment_type, null);
    assert.equal(identity?.wp_version, null);
    assert.equal(identity?.is_multisite, false);
  });

  it('does not branch on environment_type', () => {
    const baselines = new Set<string>();
    const reported: string[] = [];

    for (const environment_type of ['production', 'staging', 'local', 'development']) {
      const identity = normalizeSiteIdentity({ ...IDENTITY, environment_type });
      assert.ok(identity);
      reported.push(identity.environment_type as string);
      const { environment_type: _dropped, ...rest } = identity;
      baselines.add(JSON.stringify(rest));
    }

    // Positive control first: an implementation that dropped the field would
    // satisfy the invariance check below while proving nothing.
    assert.deepEqual(reported, ['production', 'staging', 'local', 'development']);
    assert.equal(baselines.size, 1, 'every other field is unchanged by the environment type');
  });
});

describe('testConnection reports which site answered', () => {
  it('surfaces the identity the settings endpoint returned', async () => {
    const client = clientReturning({
      ok: true,
      data: { builder: { version: '5.11.1' }, site_identity: IDENTITY },
    });

    const ping = await client.testConnection();

    assert.equal(ping.ok, true);
    assert.equal(ping.message, 'Connected to Divi 5.11.1');
    assert.deepEqual(ping.site, IDENTITY);
  });

  it('still connects, reporting no identity, against a plugin that predates the block', async () => {
    const client = clientReturning({ ok: true, data: { builder: { version: '5.11.1' } } });

    const ping = await client.testConnection();

    assert.equal(ping.ok, true);
    assert.equal(ping.site, null);
  });
});
