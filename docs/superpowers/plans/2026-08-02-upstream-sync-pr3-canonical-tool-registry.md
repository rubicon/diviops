# Upstream Sync PR 3: CanonicalToolRegistry Hand-Port — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `diviops-server/src/index.ts`'s direct `server.registerTool()`/`server.registerResource()` calls with an indirection layer (`CanonicalToolRegistry`), add a duplicate-registration guard, make the module safely importable without side effects (`isDirectEntryPoint()`), and adopt `health-tools.ts`'s production-consumed exports (`META_PING_CONFIG`, `META_INFO_CONFIG`, `requestAbortSignal`) into the existing `diviops_meta_ping`/`diviops_meta_info` tools.

**Architecture:** One new file (`canonical-tool-registry.ts`, vendored verbatim — small, self-contained, no WordPress/Divi-specific logic). `health-tools.ts` gets a fork-scoped subset (production exports only — the launcher-only exports stay deferred to #131, per the design spec's correction). `index.ts` is restructured in place: module-level `server` becomes module-level `registry`; a real `McpServer` is constructed locally inside `main()`, after handshake, and the finalized registry installs onto it. See `docs/superpowers/specs/2026-08-02-upstream-sync-reconciliation-design.md` §"Hand-port, sequenced last" for the source-of-truth rationale.

**Tech Stack:** TypeScript/Node ESM (`diviops-server/`), `node:test`.

## Global Constraints

- Never rename or alter behavior of the four frozen identifiers. Nothing in this PR touches any of them.
- **Sequencing**: this PR assumes PR 1 and PR 2 are already merged. `index.ts` has had 13+ feature commits since the upstream merge-base; every hand-port step below is written against **current `main`**, located by content match, not against upstream's stale line numbers.
- **Do NOT adopt** `health-tools.ts`'s launcher-only exports (`LAUNCHER_HEALTH_TOOL_NAMES`, `modelVisibleHealthResult`, `registerLauncherHealthTools`) or `target-evidence.ts` in any form — both stay deferred to #131. Only `META_PING_CONFIG`, `META_INFO_CONFIG`, `requestAbortSignal` are in scope here.
- **Acceptance gate is two-part**: the existing `verify-server-builds-and-starts.test.mjs` (build/startup regression, from PR #129) covers the "starts from a clean checkout" guarantee but does **not** exercise `CanonicalToolRegistry` behavior at all — Task 4 below adds a new test specifically for that. Neither test alone is sufficient; both must pass.
- **Cancellation reach — a known ceiling, decided deliberately here, not silently.** PR 1 added the `AbortSignal` plumbing to `wp-client.ts`, and this PR wires `requestAbortSignal` into the two local meta tools (`diviops_meta_ping`/`diviops_meta_info`) exactly as upstream does. But `registerPluginTool`'s wrapper is `(async (args) => … handler(args))` — it does NOT accept the SDK's `extra` argument, so the request-scoped `extra.signal` is dropped before any of the ~140 plugin tools' handlers could pass it to `wp.request()`/`wp.requestEnveloped()`. **Verified 2026-08-02: `upstream/main`'s own `registerPluginTool` has the identical `(args) => …` wrapper — upstream itself wires cancellation only through the meta tools, not the plugin tools.** A faithful hand-port therefore reaches only the meta tools. That is the default for this PR: match upstream, so an MCP client cancelling a long/destructive *plugin* tool call still lets the underlying WP HTTP request run to completion. If we instead want real cancellation across plugin tools, that is a deliberate **beyond-upstream extension** (widen `registerPluginTool`/`registerProTool`/`registerLocalTool` wrappers to accept `extra` and thread `extra.signal` into every `wp.*` call, plus a tool-boundary regression test proving a cancelled MCP call reaches injected fetch) — scope it as its own issue, do NOT fold it silently into this hand-port. Flagged by Codex adversarial review of PR 1; recorded here so the decision is explicit at implementation time.
- **`wrapResponse` vs. caller-abort (verify during Task 3).** `testConnection(signal)` rethrows `signal.reason` on an already-aborted signal (PR 1). When the meta_ping handler wraps `wp.testConnection(signal)` in `wrapResponse(...)`, confirm `wrapResponse` does NOT convert that rethrown abort into a soft `{ ok: false, error: { code: 'wp_error' } }` envelope — a cancelled call should surface as a cancellation, not a masked connection failure. If it does mask it, either let the abort propagate past `wrapResponse` or classify it distinctly. Codex secondary finding from PR 1 review.
- Signed commits, no `--no-verify`.

---

### Task 1: Vendor `canonical-tool-registry.ts` verbatim

**Files:**
- Create: `diviops-server/src/canonical-tool-registry.ts`

**Interfaces:**
- Produces: `ToolRegistrar`, `ResourceRegistrar`, `RegistryTarget` (exported types), `CanonicalToolRegistry` class with `registerTool()`, `registerResource()`, `install(target: RegistryTarget)`. Consumed by Task 3 (`index.ts`) and Task 4 (the new test).

This file has no WordPress/Divi-specific logic and no dependency on anything else in this repo — safe to vendor byte-for-byte from `upstream/main`.

- [ ] **Step 1: Create the file**

```typescript
/**
 * Transport-neutral ownership of DiviOps MCP registrations.
 *
 * The shipped server still materializes this registry into the v1 SDK. The
 * non-publishing v2 compatibility spike materializes the same definitions
 * into the split v2 server package. Keeping the registry ignorant of either
 * SDK is the guard against a second tool catalog or a wire-shape translator.
 */

// The SDKs deliberately expose overloaded, schema-driven registration APIs.
// This seam stores those values without trying to restate either SDK's
// generics; type safety remains at each canonical registration callsite.
/* eslint-disable @typescript-eslint/no-explicit-any */
export type ToolRegistrar = {
  registerTool(name: string, config: any, handler: any): unknown;
};

export type ResourceRegistrar = {
  registerResource(
    name: string,
    uri: string,
    config: any,
    handler: any,
  ): unknown;
};

export type RegistryTarget = ToolRegistrar & ResourceRegistrar;

type ToolRegistration = {
  name: string;
  config: any;
  handler: any;
};

type ResourceRegistration = {
  name: string;
  uri: string;
  config: any;
  handler: any;
};

export class CanonicalToolRegistry implements RegistryTarget {
  readonly #tools = new Map<string, ToolRegistration>();
  readonly #resources = new Map<string, ResourceRegistration>();

  registerTool(name: string, config: any, handler: any): void {
    if (this.#tools.has(name)) {
      throw new Error(`Duplicate canonical MCP tool registration: ${name}`);
    }
    this.#tools.set(name, { name, config, handler });
  }

  registerResource(name: string, uri: string, config: any, handler: any): void {
    if (this.#resources.has(name)) {
      throw new Error(`Duplicate canonical MCP resource registration: ${name}`);
    }
    this.#resources.set(name, { name, uri, config, handler });
  }

  install(target: RegistryTarget): void {
    for (const registration of this.#tools.values()) {
      target.registerTool(
        registration.name,
        registration.config,
        registration.handler,
      );
    }
    for (const registration of this.#resources.values()) {
      target.registerResource(
        registration.name,
        registration.uri,
        registration.config,
        registration.handler,
      );
    }
  }
}
/* eslint-enable @typescript-eslint/no-explicit-any */
```

- [ ] **Step 2: Type-check**

Run: `cd diviops-server && npx tsc --noEmit`
Expected: exits 0 (this file compiles standalone; it isn't imported anywhere yet).

- [ ] **Step 3: Commit**

```bash
cd diviops-server && git add src/canonical-tool-registry.ts
git commit -m "feat(server): vendor CanonicalToolRegistry from upstream ba008d2

Transport-neutral tool/resource registration store with a
duplicate-registration guard and an install(target) replay method.
Vendored verbatim — self-contained, no WordPress/Divi-specific logic.
Not yet wired into index.ts (Task 3)."
```

---

### Task 2: Vendor `health-tools.ts`'s production-consumed exports only

**Files:**
- Create: `diviops-server/src/health-tools.ts`

**Interfaces:**
- Produces: `META_PING_CONFIG`, `META_INFO_CONFIG` (tool-metadata config objects),
  `requestAbortSignal(args, context?): AbortSignal | undefined`. Consumed by Task 3.

**Explicitly excluded** (deferred to #131, not part of this file): `LAUNCHER_HEALTH_TOOL_NAMES`,
`modelVisibleHealthResult()`, `registerLauncherHealthTools()`, and the `TargetEvidence` import —
none of those are needed by production `diviops_meta_ping`/`diviops_meta_info`.

- [ ] **Step 1: Create the file with only the three production-consumed exports**

```typescript
/**
 * Production-consumed tool metadata + cancellation utility, vendored from
 * upstream ba008d2's health-tools.ts.
 *
 * Only the pieces diviops_meta_ping/diviops_meta_info actually use are
 * here. The file's launcher-only pieces (LAUNCHER_HEALTH_TOOL_NAMES,
 * modelVisibleHealthResult, registerLauncherHealthTools, and the
 * TargetEvidence dependency they carry) are deliberately NOT vendored —
 * they implement drift detection for a multi-profile launcher concept
 * this fork doesn't have. See issue #131.
 */

export const META_PING_CONFIG = {
  description:
    "Test the connection to the WordPress site and verify the Divi MCP plugin is active. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { connected: true, message: \"Connected to Divi <version>\" } and connection failure surfaces as { ok: false, error: { code: 'wp_error', message } } with the underlying transport message preserved.",
  annotations: { readOnlyHint: true, idempotentHint: true },
  _meta: { idempotent: "true" },
} as const;

export const META_INFO_CONFIG = {
  description:
    "Returns DiviOps MCP server identity, server_version, license type, numeric tool_count, registered tool catalog summary, active plugin version summary, WP-CLI allowlist, and plugin handshake/slice state including Pro and FluentCart target readiness. Use as the S0 preflight before dogfooding or product work. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
  annotations: { readOnlyHint: true, idempotentHint: true },
  _meta: { idempotent: "true" },
} as const;

type ToolCancellationContext = {
  signal?: AbortSignal;
  mcpReq?: { signal?: AbortSignal };
};

export function requestAbortSignal(
  args: unknown,
  context?: ToolCancellationContext,
): AbortSignal | undefined {
  const fallback = args as ToolCancellationContext | undefined;
  return (
    context?.mcpReq?.signal ??
    context?.signal ??
    fallback?.mcpReq?.signal ??
    fallback?.signal
  );
}
```

- [ ] **Step 2: Type-check**

Run: `cd diviops-server && npx tsc --noEmit`
Expected: exits 0.

- [ ] **Step 3: Commit**

```bash
cd diviops-server && git add src/health-tools.ts
git commit -m "feat(server): vendor health-tools.ts's production-consumed exports only

META_PING_CONFIG/META_INFO_CONFIG/requestAbortSignal, used by upstream's
own diviops_meta_ping/diviops_meta_info registrations (Task 3). The
file's launcher-only exports are deliberately excluded — see #131."
```

---

### Task 3: Hand-port `index.ts` — registry indirection, meta tools, resource

**Files:**
- Modify: `diviops-server/src/index.ts` (imports, `server`→`registry` declaration, 3 helper
  functions, `diviops_meta_ping`/`diviops_meta_info` registrations, the resource registration)

**Interfaces:**
- Consumes: `CanonicalToolRegistry` (Task 1), `META_PING_CONFIG`/`META_INFO_CONFIG`/
  `requestAbortSignal` (Task 2).
- Produces: module-level `registry: CanonicalToolRegistry` (replaces module-level `server`).

**Verify before starting:** confirm the anchors below still match current `main` — this file
has 8000+ lines and active development; if any `grep` in Step 0 returns no match or more than
one match where exactly one is expected, stop and re-locate the anchor by reading the
surrounding function instead of guessing.

- [ ] **Step 0: Confirm anchors**

Run:
```bash
cd diviops-server
grep -n '^const server = new McpServer' src/index.ts
grep -n '^type HandshakeState =' src/index.ts
grep -n 'server\.registerTool\|server\.registerResource' src/index.ts
```
Expected: one match for `const server`, one for `type HandshakeState`, and exactly 4 matches
for `server.registerTool`/`server.registerResource` (in `registerPluginTool`,
`registerLocalTool`, `registerProTool`, and the `divi-block-format-guide` resource).

- [ ] **Step 1: Add the two new imports**

Find (in the import block, right before `import { isolationFailure, ... } from "./validate-attrs.js";`):
```typescript
import { createWpCli } from "./wp-cli.js";
import {
  isolationFailure,
  scanValueForForeignVarRefs,
  writerIsolationErrorResult,
} from "./validate-attrs.js";
import { readFileSync, readdirSync } from "fs";
```

Replace with:
```typescript
import { createWpCli } from "./wp-cli.js";
import {
  META_INFO_CONFIG,
  META_PING_CONFIG,
  requestAbortSignal,
} from "./health-tools.js";
import { CanonicalToolRegistry } from "./canonical-tool-registry.js";
import {
  isolationFailure,
  scanValueForForeignVarRefs,
  writerIsolationErrorResult,
} from "./validate-attrs.js";
import { readFileSync, readdirSync, realpathSync } from "fs";
```

- [ ] **Step 2: Replace the module-level `server` with `registry`**

Find:
```typescript
const server = new McpServer({
  name: "diviops-mcp",
  version: SERVER_VERSION,
});
```

Replace with:
```typescript
const registry = new CanonicalToolRegistry();
```

(The `McpServer` import stays — it's still used later, inside `main()`, in Task 4.)

- [ ] **Step 3: Export `HandshakeState`** (needed by Task 4's new test)

Find:
```typescript
type HandshakeState =
```

Replace with:
```typescript
export type HandshakeState =
```

- [ ] **Step 4: Update the 3 helper functions to use `registry` instead of `server`**

In `registerPluginTool`, find:
```typescript
  server.registerTool(name, config, wrapped);
```
Replace with:
```typescript
  registry.registerTool(name, config, wrapped);
```

In `registerLocalTool`, find its signature:
```typescript
function registerLocalTool<H extends (args: any) => Promise<any>>(
  name: string,
  config: any,
  handler: H,
): void {
  recordToolCatalog({ name, kind: "server_local", registered: true });
  recordIdempotent(name, config?._meta);
  server.registerTool(name, config, handler);
}
```
Replace with:
```typescript
function registerLocalTool<
  H extends (args: any, context?: any) => Promise<any>,
>(
  name: string,
  config: any,
  handler: H,
): void {
  recordToolCatalog({ name, kind: "server_local", registered: true });
  recordIdempotent(name, config?._meta);
  registry.registerTool(name, config, handler);
}
```
(The signature widens `H` to accept an optional second `context` parameter — needed because
`diviops_meta_ping`'s handler, updated in Step 5, now takes `(_args, context)`.)

In `registerProTool`, find:
```typescript
  server.registerTool(name, config, handler);
```
Replace with:
```typescript
  registry.registerTool(name, config, handler);
```

- [ ] **Step 5: Wire `diviops_meta_ping`/`diviops_meta_info` to the vendored config + cancellation**

Find:
```typescript
registerLocalTool(
  "diviops_meta_ping",
  {
    description:
      "Test the connection to the WordPress site and verify the Divi MCP plugin is active. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { connected: true, message: \"Connected to Divi <version>\" } and connection failure surfaces as { ok: false, error: { code: 'wp_error', message } } with the underlying transport message preserved.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
    const response = await wrapResponse(async () => {
      const ping = await wp.testConnection();
      if (!ping.ok) {
        withCode(ErrorCodes.WP_ERROR, ping.message);
      }
```

Replace with:
```typescript
registerLocalTool(
  "diviops_meta_ping",
  {
    ...META_PING_CONFIG,
    _meta: { idempotent: "true" },
  },
  async (
    _args: unknown,
    context?: { signal?: AbortSignal; mcpReq?: { signal?: AbortSignal } },
  ) => {
    const signal = requestAbortSignal(_args, context);
    const response = await wrapResponse(async () => {
      const ping = await wp.testConnection(signal);
      if (!ping.ok) {
        withCode(ErrorCodes.WP_ERROR, ping.message);
      }
```

(Leave the rest of that handler body — everything after the `if (!ping.ok)` block — untouched;
this replacement only covers the config object and the handler's opening lines.)

Find:
```typescript
registerLocalTool(
  "diviops_meta_info",
  {
    description:
      "Returns DiviOps MCP server identity, server_version, license type, numeric tool_count, registered tool catalog summary, active plugin version summary, WP-CLI allowlist, and plugin handshake/slice state including Pro and FluentCart target readiness. Use as the S0 preflight before dogfooding or product work. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
    annotations: { idempotentHint: true },
    _meta: { idempotent: "true" },
  },
  async () => {
```

Replace with:
```typescript
registerLocalTool(
  "diviops_meta_info",
  {
    ...META_INFO_CONFIG,
    _meta: { idempotent: "true" },
  },
  async () => {
```

- [ ] **Step 6: Update the resource registration**

Find:
```typescript
server.registerResource(
  "divi-block-format-guide",
```

Replace with:
```typescript
registry.registerResource(
  "divi-block-format-guide",
```

- [ ] **Step 7: Type-check (expect errors — `main()` still references the removed `server`; fixed in Task 4)**

Run: `cd diviops-server && npx tsc --noEmit`
Expected: errors pointing at `main()`'s `await server.connect(transport)` line — this is
expected at this checkpoint; Task 4 fixes it. Do not proceed to commit yet.

- [ ] **Step 8: Commit** (as a WIP checkpoint, since Task 4 completes the picture — or squash with Task 4's commit if your workflow prefers one commit per file; either is fine, this plan calls it out separately for reviewability)

```bash
cd diviops-server && git add src/index.ts
git commit -m "refactor(server): route tool/resource registration through CanonicalToolRegistry

server.registerTool()/registerResource() calls in registerPluginTool,
registerLocalTool, registerProTool, and the divi-block-format-guide
resource all now go through the module-level registry instead.
diviops_meta_ping/diviops_meta_info adopt the vendored
META_PING_CONFIG/META_INFO_CONFIG + AbortSignal cancellation.
HandshakeState exported for the new registry test (Task 4). main()
still references the old McpServer-typed server variable — fixed in
the next commit, this one is a checkpoint for reviewability."
```

---

### Task 4: Restructure `main()` — `finalizeProductionRegistryForHandshake` + `isDirectEntryPoint()`

**Files:**
- Modify: `diviops-server/src/index.ts` (`main()` and the module's final invocation)
- Create: `diviops-server/src/__tests__/canonical-tool-registry-finalization.test.ts`

**Interfaces:**
- Produces: `finalizeProductionRegistryForHandshake(state: HandshakeState): CanonicalToolRegistry`
  (exported — this is also the seam the new test in this task calls directly, matching its own
  docblock's stated purpose: "Production startup and the credential-free dual-era compatibility
  fixture deliberately share this seam").
- Consumes: `registry` (module-level, Task 3), `registerCrossEnvEvidenceTools()`,
  `registerProTools()` (both already exist in this file, unchanged), `HandshakeState` (Task 3's
  export).

- [ ] **Step 1: Add the `finalizeProductionRegistryForHandshake` export, before `main()`**

Find (the end of `registerProTools()`, right before the `// ── Start ──` comment):
```typescript
    { target: "fluentcart", capabilityKey: "fluentcart_gateway_get" },
  );
}

// ── Start ────────────────────────────────────────────────────────────

async function main() {
```

Replace with:
```typescript
    { target: "fluentcart", capabilityKey: "fluentcart_gateway_get" },
  );
}

let productionRegistryFinalized = false;

/**
 * Complete the canonical registry after the connected site's handshake has
 * settled. Production startup and any future credential-free compatibility
 * fixture deliberately share this seam so a test materializes the real
 * Free/Pro-gated catalog instead of maintaining a second set of definitions.
 */
export function finalizeProductionRegistryForHandshake(
  state: HandshakeState,
): CanonicalToolRegistry {
  if (productionRegistryFinalized) {
    throw new Error("Production MCP registry has already been finalized");
  }
  handshakeState = state;
  registerCrossEnvEvidenceTools();
  registerProTools();
  productionRegistryFinalized = true;
  return registry;
}

// ── Start ────────────────────────────────────────────────────────────

function requireCredentials(): void {
  if (WP_URL && WP_USER && WP_APP_PASSWORD) return;
  const missing = [
    !WP_URL && "WP_URL",
    !WP_USER && "WP_USER",
    !WP_APP_PASSWORD && "WP_APP_PASSWORD",
  ].filter(Boolean);
  console.error(
    `Error: Missing required environment variable(s): ${missing.join(", ")}.\n` +
      "Set WP_URL to your WordPress site URL (e.g. http://mysite.local).\n" +
      "Generate an Application Password at: WP Admin → Users → Profile → Application Passwords",
  );
  process.exit(1);
}

async function main() {
  requireCredentials();
```

- [ ] **Step 2: Remove the now-redundant module-top-level credential check**

Find, near the top of the file (right after `const WP_APP_PASSWORD = process.env.WP_APP_PASSWORD ?? "";`):
```typescript
if (!WP_URL || !WP_USER || !WP_APP_PASSWORD) {
  const missing = [
    !WP_URL && "WP_URL",
    !WP_USER && "WP_USER",
    !WP_APP_PASSWORD && "WP_APP_PASSWORD",
  ].filter(Boolean);
  console.error(
    `Error: Missing required environment variable(s): ${missing.join(", ")}.\n` +
      "Set WP_URL to your WordPress site URL (e.g. http://mysite.local).\n" +
      "Generate an Application Password at: WP Admin → Users → Profile → Application Passwords",
  );
  process.exit(1);
}

const wp = new WPClient({
```

Replace with:
```typescript
const wp = new WPClient({
```

(The check itself moved into `requireCredentials()`, defined just above `main()` in Step 1.
`new WPClient({...})` with empty strings is harmless — its constructor does no I/O, confirmed
in PR 1's work on `wp-client.ts` — so the module stays safely importable even with missing
credentials, exactly the property `isDirectEntryPoint()` in Step 4 depends on.)

- [ ] **Step 3: Replace the `registerCrossEnvEvidenceTools()`/`registerProTools()` calls in `main()` with the finalization call, and move `McpServer` construction inside `main()`**

Find (inside `main()`, after the handshake try/catch block):
```typescript
  // The cross-env evidence schema is additive-capability aware: older Free
  // plugins retain the existing header-only enum, while current plugins prove
  // footer support through cross_env_footer_layout_evidence.
  registerCrossEnvEvidenceTools();

  // Pro coverage-slice registration must run AFTER the handshake so the
  // gates (`pro_active`, `available_targets`, `active_modules`,
  // capability map) reflect the connected site's actual state. On Free
  // sites — or when the handshake failed — registerProTool's internal
  // gates short-circuit so no Pro tools register.
  registerProTools();

  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("Divi MCP Server running on stdio");
}

main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
```

Replace with:
```typescript
  // Cross-env evidence and Pro coverage-slice registration must run AFTER
  // the handshake so capability and tier gates reflect the connected site.
  const finalizedRegistry =
    finalizeProductionRegistryForHandshake(handshakeState);

  const server = new McpServer({
    name: "diviops-mcp",
    version: SERVER_VERSION,
  });
  finalizedRegistry.install(server);
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("Divi MCP Server running on stdio");
}

function isDirectEntryPoint(): boolean {
  const entryPoint = process.argv[1];
  if (!entryPoint) return false;
  try {
    return realpathSync(entryPoint) === fileURLToPath(import.meta.url);
  } catch {
    return false;
  }
}

if (isDirectEntryPoint()) {
  main().catch((error) => {
    console.error("Fatal error:", error);
    process.exit(1);
  });
}
```

- [ ] **Step 4: Type-check**

Run: `cd diviops-server && npx tsc --noEmit`
Expected: exits 0, no errors.

- [ ] **Step 5: Write the new registry-behavior test — the acceptance gate `verify-server-builds-and-starts.test.mjs` doesn't cover**

Create `diviops-server/src/__tests__/canonical-tool-registry-finalization.test.ts`:

```typescript
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
 * a different registration path), that Pro tools only register when the
 * handshake state says Pro is active, and that a duplicate registration
 * throws instead of silently overwriting.
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
```

- [ ] **Step 6: Run the new test**

Run: `cd diviops-server && npx tsc && node --test dist/__tests__/canonical-tool-registry-finalization.test.js`
Expected: all 6 tests pass against the Task 1-4 implementation already in place. The
production-registry finalization happens once, at describe-scope, and every test that inspects
its contents (non-empty catalog, meta_ping/meta_info presence, the #128 idempotent-metadata
invariant) reads from that single captured install — not from separate finalization calls,
which the one-shot guard would reject.

- [ ] **Step 7: Commit**

```bash
cd diviops-server && git add src/index.ts src/__tests__/canonical-tool-registry-finalization.test.ts
git commit -m "refactor(server): finalizeProductionRegistryForHandshake + isDirectEntryPoint

main() now calls requireCredentials() first (moved from module load
time), finalizes the registry via the new exported
finalizeProductionRegistryForHandshake() after handshake settles,
constructs McpServer locally, and installs the finalized registry onto
it. isDirectEntryPoint() gates the top-level main() invocation so
importing this module (e.g. from a test) no longer has side effects —
this is what makes the new registry-finalization test possible without
WP credentials.

New test exercises what verify-server-builds-and-starts.test.mjs
(PR #129) never did: registry finalization, the one-shot guard, the
#128 idempotent-metadata invariant, and duplicate-registration
rejection for both tools and resources."
```

---

### Task 5: Full acceptance gate — both tests, both must pass

**Files:** none (verification only).

- [ ] **Step 1: Re-run the #41/#128 build+startup regression test**

Run: `cd diviops-server && node --test scripts/verify-server-builds-and-starts.test.mjs`
Expected: all 3 assertions still pass — specifically confirm the missing-credentials stderr
message is unchanged, proving `isDirectEntryPoint()` correctly still runs `main()` when invoked
as `node dist/index.js` directly, and `requireCredentials()` still fires first inside it.

- [ ] **Step 2: Run the new registry-behavior test**

Run: `cd diviops-server && node --test dist/__tests__/canonical-tool-registry-finalization.test.js`
Expected: all tests pass (with Step 5's stub resolved per its own note).

- [ ] **Step 3: Full diviops-server suite**

Run: `cd diviops-server && npm run test:server-security && npm run test:regen-skill && npm test`
Expected: all pass, no regressions from Tasks 1-4's restructuring.

- [ ] **Step 4: Live smoke check (optional but recommended given the scope of this refactor)**

With real `WP_URL`/`WP_USER`/`WP_APP_PASSWORD` set against a real site (confirm with Dax before
using colleyvillelions.local per this repo's site-constraints), run `node dist/index.js` and
confirm the "Handshake OK" / "Divi MCP Server running on stdio" console output still appears,
matching pre-refactor behavior exactly.

- [ ] **Step 5: File the tracking issue and open the PR**

Branch name: `dev/<issue-number>-canonical-tool-registry-hand-port`. PR body references the
design spec and links PR 1/PR 2 as prerequisites already merged.
