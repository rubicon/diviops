// SPDX-License-Identifier: MIT
/**
 * Server↔plugin compatibility surface.
 *
 * As of #486, the global `MIN_PLUGIN_VERSION` floor is gone. Compatibility
 * is now decided per-tool against a capability map returned by the plugin's
 * `/handshake`. See `wp-client.ts` (handshake parse) and `index.ts`
 * (`requireCapability` gate at each plugin-touching tool entry).
 *
 * Legacy plugins may emit `capabilities` as a string[] of coarse namespace
 * tags, not the per-tool map this server expects. `wp-client.ts` normalizes
 * that legacy shape to an empty map, which makes every gated tool fail fast
 * with capability-based upgrade guidance. No numeric plugin-version floor is
 * inferred from that legacy response.
 *
 * `compareVersions` is kept exported because handshake helper code and
 * future per-tool soft-deprecation messages may want it.
 */

/**
 * Compare two semver-like version strings (supports pre-release tags).
 *
 * Returns:
 *  -1 if a < b
 *   0 if a === b
 *   1 if a > b
 *
 * Pre-release versions (e.g. 1.0.0-beta.22) sort before their release (1.0.0).
 */
export function compareVersions(a: string, b: string): -1 | 0 | 1 {
  const parseVersion = (v: string) => {
    const [core, pre] = v.split('-', 2);
    const parts = core.split('.').map(Number);
    return { parts, pre: pre ?? null };
  };

  const va = parseVersion(a);
  const vb = parseVersion(b);

  // Compare numeric parts.
  const maxLen = Math.max(va.parts.length, vb.parts.length);
  for (let i = 0; i < maxLen; i++) {
    const na = va.parts[i] ?? 0;
    const nb = vb.parts[i] ?? 0;
    if (na < nb) return -1;
    if (na > nb) return 1;
  }

  // Equal numeric parts — pre-release sorts before release.
  if (va.pre === null && vb.pre === null) return 0;
  if (va.pre !== null && vb.pre === null) return -1;
  if (va.pre === null && vb.pre !== null) return 1;

  // Both have pre-release — compare lexicographically with numeric awareness.
  const aParts = va.pre!.split('.');
  const bParts = vb.pre!.split('.');
  const preLen = Math.max(aParts.length, bParts.length);
  for (let i = 0; i < preLen; i++) {
    const ap = aParts[i];
    const bp = bParts[i];
    if (ap === undefined) return -1;
    if (bp === undefined) return 1;
    const an = Number(ap);
    const bn = Number(bp);
    if (!isNaN(an) && !isNaN(bn)) {
      if (an < bn) return -1;
      if (an > bn) return 1;
    } else {
      if (ap < bp) return -1;
      if (ap > bp) return 1;
    }
  }

  return 0;
}

/**
 * Per-target presence record (ADR-003 § handshake shape). `present`
 * reports whether the target plugin is installed + bootable on this
 * site; `version` is advisory only and may be null when version
 * detection is unavailable (front-end requests can't always reach
 * `get_plugin_data()`).
 */
export interface HandshakeTarget {
  present: boolean;
  version?: string | null;
}

/**
 * Advisory plugin version/presence record contributed by extension plugins.
 * This is a diagnostics/preflight surface only; capability gates continue to
 * use the explicit capability map and target/module flags.
 */
export interface HandshakePluginInfo {
  active: boolean;
  version?: string | null;
}

/**
 * Runtime facts this server observes about its own environment and reports
 * to the plugin in the handshake (#123).
 *
 * These are things the plugin cannot see for itself. WP-CLI is the motivating
 * case: this server executes it, in a process whose environment PHP has no
 * access to, so the plugin's dashboard previously guessed from PHP and showed
 * a red cross on setups where WP-CLI worked.
 *
 * Every field is optional and additive. A plugin that predates this contract
 * ignores the whole object; a server that cannot determine a field omits it,
 * and the plugin records "unknown" rather than a negative.
 */
export interface ClientRuntime {
  /** Whether this server has a usable WP-CLI (WP_PATH or WP_CLI_CMD wired up). */
  wp_cli?: boolean;
}

/**
 * Shape returned by `POST /diviops/v1/handshake`.
 *
 * `capabilities` is a per-tool map keyed by post-rename tool slug
 * (without the `diviops_` prefix). Older plugins (pre-1.2.0) emit
 * a string[] of coarse namespace keys; the server normalizes that
 * legacy shape to an empty map (every gated tool then fails fast
 * with an upgrade hint, which is the intended behavior).
 *
 * ADR-003 / ADR-007 Pro-extension fields (`pro_active`,
 * `pro_version`, `available_targets`, `active_modules`) are
 * optional — Free-only sites omit them entirely. The server treats
 * absence as `pro_active: false` + empty target/module maps so
 * Pro-gated tools cleanly decline registration on Free sites.
 */
export interface HandshakeResult {
  compatible: boolean;
  plugin_version?: string | null;
  /**
   * sha256 over the plugin's own PHP source (#215). Optional: plugins older
   * than 1.20.0 omit it, and the server reports "unknown" rather than
   * inventing a comparison it cannot make.
   */
  code_fingerprint?: string | null;
  min_server: string;
  divi: {
    active: boolean;
    version: string | null;
  };
  capabilities: Record<string, boolean>;
  /** ADR-007 § 7.1 — Pro plugin presence flag. Undefined on Free sites. */
  pro_active?: boolean;
  /** ADR-003 — Pro plugin version (when `pro_active === true`). */
  pro_version?: string;
  /** ADR-003 — per-target presence map (FluentCart, future slices). */
  available_targets?: Record<string, HandshakeTarget>;
  /** ADR-003 — per-target admin-controlled activation toggle. */
  active_modules?: Record<string, boolean>;
  /** Advisory installed-plugin versions for S0 preflight reports. */
  plugins?: Record<string, HandshakePluginInfo>;
  /** Permission-gated non-secret identity of the authenticated App Password owner. */
  authenticated_user?: { id: number; login: string };
  /** Observed WordPress site URL; normalized and pinned by launcher mode. */
  site_url?: string;
}

export function proToolGatesSatisfied(
  state: {
    proActive: boolean;
    availableTargets: Record<string, HandshakeTarget>;
    activeModules: Record<string, boolean>;
    capabilities: Record<string, boolean>;
  },
  gates: { target: string; capabilityKey: string },
): boolean {
  if (state.proActive !== true) return false;
  const target = state.availableTargets[gates.target];
  if (!target || target.present !== true) return false;
  if (state.activeModules[gates.target] !== true) return false;
  if (state.capabilities[gates.capabilityKey] !== true) return false;
  return true;
}

/**
 * Thrown when a tool handler calls `requireCapability(key)` and the
 * plugin's handshake response did not include `key`. The server's
 * tool dispatch wraps this into the MCP error response, surfacing
 * the upgrade hint to the agent.
 */
export class MissingCapabilityError extends Error {
  constructor(
    public readonly capability: string,
    public readonly pluginVersion?: string | null,
    public readonly pluginComponent: "free" | "pro" = "free",
  ) {
    super(capabilityMissingMessage(capability, pluginVersion, pluginComponent));
    this.name = 'MissingCapabilityError';
  }
}

export function observedVersion(version?: string | null): string | null {
  if (typeof version !== "string") return null;
  const normalized = version.trim();
  return normalized && normalized.toLowerCase() !== "unknown"
    ? normalized
    : null;
}

export function capabilityMissingMessage(
  capability: string,
  pluginVersion?: string | null,
  pluginComponent: "free" | "pro" = "free",
): string {
  const component =
    pluginComponent === "pro"
      ? "Pro WordPress plugin"
      : "Free WordPress plugin";
  const version = observedVersion(pluginVersion);
  const versionEvidence = version ? ` (observed version ${version})` : "";
  return (
    `The connected ${component}${versionEvidence} does not advertise ` +
    `the "${capability}" capability required by this operation. ` +
    "MCP server and WordPress plugin versions are independent."
  );
}

export function capabilityUpgradeHint(
  capability: string,
  pluginComponent: "free" | "pro" = "free",
  alternative?: string,
): string {
  const component =
    pluginComponent === "pro"
      ? "Pro WordPress plugin"
      : "Free WordPress plugin";
  const fallback = alternative ? ` ${alternative}` : "";
  return (
    "MCP server and WordPress plugin versions are independent. " +
    `Install a compatible ${component} from the same DiviOps suite release ` +
    `or a newer supported component that advertises "${capability}", then ` +
    "reconnect or restart the MCP session to refresh the capability handshake." +
    fallback
  );
}

// ── Live plugin state (#215) ─────────────────────────────────────────

/**
 * Outcome of the live plugin re-check `diviops_meta_info` performs per call.
 *
 * Deliberately not a `HandshakeResult`: nothing downstream of this may treat
 * the re-check as a re-negotiation. The capability gates keep reflecting the
 * spawn-time handshake, because the tool list was finalized against that map
 * and a live map would silently disagree with the tools already advertised.
 */
export type LiveHandshake =
  | { ok: true; pluginVersion: string | null; codeFingerprint: string | null }
  | { ok: false; message: string };

/**
 * The `live` block of the `diviops_meta_info` payload.
 *
 * `stale` is three-valued on purpose. `false` is a claim that the spawn-time
 * snapshot still describes the site, and that claim cannot be made when the
 * re-check failed or when the plugin is too old to report a fingerprint —
 * answering `false` there would be the same confident-but-unfounded answer
 * this field exists to replace.
 */
export interface LiveHandshakeReport {
  state: "ok" | "failed";
  plugin_version: string | null;
  code_fingerprint: string | null;
  stale: boolean | null;
  warning?: string;
}

/** Short form for prose; the full digest stays in `code_fingerprint`. */
function shortFingerprint(fingerprint: string): string {
  return fingerprint.slice(0, 8);
}

/**
 * Compare a live plugin re-check against the spawn-time handshake snapshot.
 *
 * The comparison is done here rather than left to the caller because a signal
 * the caller has to construct from two fields is one most callers will not
 * construct — which is how a stale version went unnoticed for hours, twice,
 * while someone was reading the field that was lying.
 */
export function buildLiveHandshakeReport(
  spawn: { pluginVersion: string | null; codeFingerprint: string | null },
  live: LiveHandshake,
): LiveHandshakeReport {
  if (!live.ok) {
    return {
      state: "failed",
      plugin_version: null,
      code_fingerprint: null,
      stale: null,
      warning:
        `Could not re-check the plugin: ${live.message}. The values under ` +
        "`handshake` are this session's spawn-time snapshot and may no longer " +
        "describe the site.",
    };
  }

  const versionDrifted =
    spawn.pluginVersion !== null &&
    live.pluginVersion !== null &&
    spawn.pluginVersion !== live.pluginVersion;

  const fingerprintComparable =
    spawn.codeFingerprint !== null && live.codeFingerprint !== null;
  const fingerprintDrifted =
    fingerprintComparable && spawn.codeFingerprint !== live.codeFingerprint;

  const base = {
    state: "ok" as const,
    plugin_version: live.pluginVersion,
    code_fingerprint: live.codeFingerprint,
  };

  if (versionDrifted || fingerprintDrifted) {
    const facts: string[] = [];
    if (versionDrifted) {
      facts.push(`version ${spawn.pluginVersion} → ${live.pluginVersion}`);
    }
    if (fingerprintDrifted) {
      facts.push(
        `code fingerprint ${shortFingerprint(spawn.codeFingerprint as string)}` +
          ` → ${shortFingerprint(live.codeFingerprint as string)}`,
      );
    }
    return {
      ...base,
      stale: true,
      warning:
        `The plugin changed since this MCP session started (${facts.join("; ")}). ` +
        "Capability gates and the tool list still reflect the spawn-time " +
        "handshake. Restart the MCP client to re-negotiate, and kill any " +
        "orphaned server process first — those outlive the client that spawned them.",
    };
  }

  if (!fingerprintComparable) {
    return {
      ...base,
      stale: null,
      warning:
        "No code_fingerprint to compare: it is missing from the spawn-time " +
        "handshake, from the live re-check, or from both (a plugin predating " +
        "the field, or a handshake that failed at startup). A code change at " +
        "an unchanged version cannot be detected here.",
    };
  }

  return { ...base, stale: false };
}
