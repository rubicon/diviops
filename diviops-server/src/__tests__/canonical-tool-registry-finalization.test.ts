// SPDX-License-Identifier: MIT
/**
 * CanonicalToolRegistry finalization behavior (upstream ba008d2, hand-ported
 * as PR 3 of the upstream-sync reconciliation).
 *
 * verify-server-builds-and-starts.test.mjs (PR #129) proves the server
 * builds and reaches the missing-credentials refusal — it never imports
 * index.ts's own exports or exercises CanonicalToolRegistry at all. This is
 * the test that actually verifies the refactor's behavior: that the
 * finalized registry contains the expected tools, that idempotent metadata
 * still agrees between _meta and annotations (the #128 invariant, now under
 * a different registration path), that Free-mode (proActive:false)
 * finalization excludes Pro-gated tools (the Pro-active path is out of
 * scope here — the one-shot finalize guard prevents a second finalization
 * in the same process), and that a duplicate registration throws instead
 * of silently overwriting.
 *
 * Imports index.ts directly with no WP_URL/WP_USER/WP_APP_PASSWORD set —
 * this only works because requireCredentials() now runs inside main(),
 * gated by isDirectEntryPoint(), not at module load time (PR 3, Task 4).
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import {
  finalizeProductionRegistryForHandshake,
  type HandshakeState,
} from '../index.js';
import { CanonicalToolRegistry } from '../canonical-tool-registry.js';

type Recorded = { name: string; config: unknown };

function recordingRegistrar() {
  const tools: Recorded[] = [];
  const resources: Recorded[] = [];
  return {
    registerTool(name: string, config: unknown) {
      tools.push({ name, config });
    },
    registerResource(name: string, _uri: string, config: unknown) {
      resources.push({ name, config });
    },
    tools,
    resources,
  };
}

const OK_STATE: HandshakeState = {
  kind: 'ok',
  capabilities: {},
  pluginVersion: '99.0.0',
  proActive: false,
  availableTargets: {},
  activeModules: {},
  plugins: {},
};

describe('CanonicalToolRegistry finalization', () => {
  // finalizeProductionRegistryForHandshake can only be called once per
  // process (productionRegistryFinalized guard, verified below), so the
  // one real production-registry install happens here, once, and every
  // assertion about its contents reads from this same captured `target` —
  // not from separate finalization calls each test would otherwise need.
  const productionRegistry = finalizeProductionRegistryForHandshake(OK_STATE);
  const productionTarget = recordingRegistrar();
  productionRegistry.install(productionTarget);
  const productionToolNames = productionTarget.tools.map((t) => t.name);

  it('finalizes without throwing and installs a non-empty catalog', () => {
    assert.ok(productionRegistry instanceof CanonicalToolRegistry);
    assert.ok(productionToolNames.length > 0, 'the finalized registry should install at least one tool');
  });

  it('includes diviops_meta_ping and diviops_meta_info in the finalized catalog', () => {
    assert.ok(productionToolNames.includes('diviops_meta_ping'), 'diviops_meta_ping should be registered');
    assert.ok(productionToolNames.includes('diviops_meta_info'), 'diviops_meta_info should be registered');
  });

  it('throws on a second finalization call — the one-shot guard', () => {
    assert.throws(
      () => finalizeProductionRegistryForHandshake(OK_STATE),
      /already been finalized/,
      'finalizeProductionRegistryForHandshake must refuse a second call in the same process',
    );
  });

  it('every tool in the real finalized catalog has agreeing _meta.idempotent and annotations.idempotentHint (#128 invariant)', () => {
    // Checks the ACTUAL production catalog captured at describe-scope, not
    // a synthetic stand-in — this is the same invariant #128 fixed for the
    // old server.registerTool() path, re-verified under the new
    // registry.registerTool() -> install() indirection.
    let checked = 0;
    for (const t of productionTarget.tools as Array<{ name: string; config: Record<string, unknown> }>) {
      const meta = t.config._meta as { idempotent?: string } | undefined;
      const annotations = t.config.annotations as { idempotentHint?: boolean } | undefined;
      if (meta?.idempotent === undefined || annotations?.idempotentHint === undefined) continue;
      checked++;
      const metaBool = meta.idempotent === 'true';
      assert.equal(
        metaBool,
        annotations.idempotentHint,
        `${t.name}: _meta.idempotent (${meta.idempotent}) disagrees with annotations.idempotentHint (${annotations.idempotentHint})`,
      );
    }
    assert.ok(checked > 0, 'at least one registered tool should carry both _meta.idempotent and annotations.idempotentHint to check');
  });

  it('excludes Pro-gated tools from the Free-mode (proActive:false) catalog', () => {
    // OK_STATE has proActive:false, so registerProTool's internal gate should
    // register zero Pro tools. Assert a representative Pro-only tool is absent.
    assert.ok(
      !productionToolNames.includes('diviops_cross_env_header_apply'),
      'a Pro-gated tool must not appear in the Free-mode catalog',
    );
  });

  it('a duplicate tool registration throws instead of silently overwriting', () => {
    const registry = new CanonicalToolRegistry();
    registry.registerTool('diviops_dup_test', {}, async () => ({}));
    assert.throws(
      () => registry.registerTool('diviops_dup_test', {}, async () => ({})),
      /Duplicate canonical MCP tool registration: diviops_dup_test/,
    );
  });

  it('a duplicate resource registration throws instead of silently overwriting', () => {
    const registry = new CanonicalToolRegistry();
    registry.registerResource('dup-resource', 'divi://dup', {}, async () => ({}));
    assert.throws(
      () => registry.registerResource('dup-resource', 'divi://dup', {}, async () => ({})),
      /Duplicate canonical MCP resource registration: dup-resource/,
    );
  });
});
