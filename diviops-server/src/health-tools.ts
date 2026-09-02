// SPDX-License-Identifier: MIT
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
    "Test the connection to the WordPress site, verify the Divi MCP plugin is active, and report WHICH site answered. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }; success payload is { connected: true, message: \"Connected to Divi <version>\", site } and connection failure surfaces as { ok: false, error: { code: 'wp_error', message } } with the underlying transport message preserved. `site` is { home_url, site_name, environment_type, is_multisite, wp_version }, or null against a plugin too old to report it. `home_url` is the authoritative identifier — compare it before any cross-environment write, since cross_env_header_apply and cross_env_layout_apply are destructive and hard to reverse against the wrong target. `environment_type` is ADVISORY ONLY and nothing branches on it: WordPress answers \"production\" whenever WP_ENVIRONMENT_TYPE is undefined, which is the default, so a staging site commonly reports itself as production. Identify environments by `home_url`, never by `environment_type`.",
  annotations: { readOnlyHint: true, idempotentHint: true },
  _meta: { idempotent: "true" },
} as const;

export const META_INFO_CONFIG = {
  description:
    "Returns DiviOps MCP server identity, server_version, license type, numeric tool_count, registered tool catalog summary, active plugin version summary, WP-CLI allowlist, connected-site identity, and plugin handshake/slice state including Pro and FluentCart target readiness. `site` is { home_url, site_name, environment_type, is_multisite, wp_version } read live per call, or null against a plugin too old to report it. `home_url` is the authoritative identifier — compare it before any cross-environment write, since cross_env_header_apply and cross_env_layout_apply are destructive and hard to reverse against the wrong target. `environment_type` is ADVISORY ONLY and nothing branches on it: WordPress answers \"production\" whenever WP_ENVIRONMENT_TYPE is undefined, which is the default, so a staging site commonly reports itself as production. Identify environments by `home_url`, never by `environment_type`. `handshake` is the spawn-time snapshot the capability gates were negotiated against; `live` re-reads the plugin on every call and reports its version, its code_fingerprint (a sha256 over the plugin's PHP source, which identifies the running build where a version number cannot), and `stale` — true when the plugin changed since this session started, null when that could not be determined. Use as the S0 preflight before dogfooding or product work. Returns the standardized envelope { ok, data?, error: { code, message, hint? } }.",
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
