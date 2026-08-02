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
