// SPDX-License-Identifier: MIT
export function normalizeSchemaModuleName(moduleName: string): string {
  const trimmed = moduleName.trim();
  return trimmed.startsWith("divi/") ? trimmed.slice("divi/".length) : trimmed;
}

/**
 * Build the `/schema/module/...` route for a module name.
 *
 * Each path segment is encoded separately so a namespace separator survives as
 * a real `/`. Encoding the whole name at once turns it into `%2F`, which the
 * plugin's route pattern (`/schema/module/(?P<name>[a-zA-Z0-9_/-]+)`) does not
 * match — that made every non-`divi/` module unreachable, 109 of 194 on the
 * reference site (#120). `divi/*` masked the bug because its prefix is stripped
 * above, leaving no slash to mangle.
 *
 * Per-segment encoding rather than no encoding: a space or `?` in a segment
 * still has to be escaped or the request breaks or truncates.
 */
export function schemaModuleRoute(moduleName: string): string {
  const encodedPath = normalizeSchemaModuleName(moduleName)
    .split("/")
    .map(encodeURIComponent)
    .join("/");
  return `/schema/module/${encodedPath}`;
}
