/**
 * Tests for schema-route.ts — the URL builder behind `diviops_schema_get_module`.
 *
 * These exist because of #120: `encodeURIComponent()` was applied to the whole
 * module name, so a namespace separator became `%2F` and the WP REST route
 * `/schema/module/(?P<name>[a-zA-Z0-9_/-]+)` no longer matched. Every module
 * outside the `divi/` namespace was unreachable — 109 of 194 on the reference
 * site, all 108 DiviFlash (`difl/*`) plus `d5bgo/*`.
 *
 * `divi/*` hid the bug: the prefix is stripped before encoding, so no slash
 * survived to be mangled. That asymmetry is why this went unnoticed through
 * the whole native-introspection effort (#42 → #57, #66), which was PHP-side
 * and aimed at `divi/*` specifically.
 *
 * The plugin was never at fault — `difl/flipbox` returns 200 with 33 attribute
 * groups when requested with a raw slash. Only the server's URL construction
 * was wrong, which is why these tests assert on the built path rather than
 * against a live site.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { normalizeSchemaModuleName, schemaModuleRoute } from '../schema-route.js';

describe('normalizeSchemaModuleName', () => {
  it('strips the divi/ prefix, since the plugin defaults bare names to that namespace', () => {
    assert.equal(normalizeSchemaModuleName('divi/text'), 'text');
    assert.equal(normalizeSchemaModuleName('divi/toggle'), 'toggle');
  });

  it('leaves a bare name untouched', () => {
    assert.equal(normalizeSchemaModuleName('text'), 'text');
  });

  it('preserves non-divi namespaces in full', () => {
    // Stripping here would be wrong in a different way: the plugin resolves a
    // bare `flipbox` as `divi/flipbox` and returns not_found.
    assert.equal(normalizeSchemaModuleName('difl/flipbox'), 'difl/flipbox');
    assert.equal(normalizeSchemaModuleName('d5bgo/bg-overlay'), 'd5bgo/bg-overlay');
  });

  it('trims surrounding whitespace', () => {
    assert.equal(normalizeSchemaModuleName('  divi/text  '), 'text');
    assert.equal(normalizeSchemaModuleName('  difl/flipbox  '), 'difl/flipbox');
  });
});

describe('schemaModuleRoute', () => {
  it('builds the divi/ route with the prefix stripped', () => {
    assert.equal(schemaModuleRoute('divi/text'), '/schema/module/text');
  });

  it('keeps the namespace separator as a real path separator (#120)', () => {
    // The regression itself: %2F here is a 404 rest_no_route against the
    // plugin's route pattern, which accepts a literal `/`.
    assert.equal(schemaModuleRoute('difl/flipbox'), '/schema/module/difl/flipbox');
    assert.equal(schemaModuleRoute('d5bgo/bg-overlay'), '/schema/module/d5bgo/bg-overlay');
  });

  it('never emits an encoded slash for any namespace', () => {
    for (const name of ['divi/text', 'difl/flipbox', 'd5bgo/bg-overlay', 'difl/advanced-blurb']) {
      assert.ok(
        !schemaModuleRoute(name).includes('%2F'),
        `${name} produced an encoded slash, which the WP REST router will not match`,
      );
    }
  });

  it('still encodes characters that are genuinely unsafe in a path segment', () => {
    // Slash-preservation must not become "encode nothing" — a space or a
    // question mark would otherwise break or truncate the request.
    assert.equal(schemaModuleRoute('difl/odd name'), '/schema/module/difl/odd%20name');
    assert.equal(schemaModuleRoute('difl/q?x'), '/schema/module/difl/q%3Fx');
  });

  it('handles a bare name unchanged', () => {
    assert.equal(schemaModuleRoute('text'), '/schema/module/text');
  });
});
