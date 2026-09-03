// SPDX-License-Identifier: MIT
/**
 * Committed per-module map consumption for `diviops_schema_get_module_map` (#385).
 *
 * Loads `diviops-server/data/module-map.json` — the batched output of
 * `scripts/build-module-map.php`, which joins Divi's own per-module
 * `PresetAttrsMap.php` (merge-aware leaf paths plus the keys each map is proven to
 * strip) to `@divi/types` (the element map and the decoration groups each element
 * picks).
 *
 * Read on demand, one module at a time. The whole artifact is ~1.4 MB across 66
 * modules and the distribution is extremely uneven — `divi/image` contributes 5
 * paths, `divi/fullwidth-header` 933 — which is why it is served through a tool
 * rather than inlined into a skill reference that would load all of it for every
 * task.
 *
 * Consumer side only. Nothing here regenerates the artifact; that needs a Divi
 * install and an unpacked `@divi/types`, and is always run deliberately.
 */

import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { DiviopsError, ErrorCodes } from "./envelope.js";

const __dirname = dirname(fileURLToPath(import.meta.url));

export interface ModuleMapEntry {
  class: string;
  file: string;
  inert: boolean;
  wipes_base: boolean;
  paths: string[];
  invalidates: string[];
  elements: Record<
    string,
    { decoration_groups: string[]; type_ref: string | null }
  > | null;
  disagreements: { kind: string; element: string }[];
}

export interface ModuleMap {
  schema_version: string;
  generated_at: string;
  notes: string[];
  sources: Record<string, Record<string, unknown>>;
  counts: Record<string, number>;
  modules: Record<string, ModuleMapEntry>;
  modules_without_types: string[];
  modules_without_preset_map: string[];
  unserved: Record<string, string>;
}

let cached: ModuleMap | undefined;

/**
 * Load and cache the committed artifact. Resolved relative to this module the
 * same way `preset-cli/registry.ts` resolves `verified-attrs.json`: the built
 * layout puts it at `dist/../data/`, the `src/` layout at `src/../data/`.
 */
export function loadModuleMap(explicitPath?: string): ModuleMap {
  if (!explicitPath && cached) return cached;

  const candidates = explicitPath
    ? [explicitPath]
    : [
        join(__dirname, "..", "data", "module-map.json"),
        join(__dirname, "..", "..", "data", "module-map.json"),
      ];

  for (const candidate of candidates) {
    try {
      const parsed = JSON.parse(readFileSync(candidate, "utf8")) as ModuleMap;
      if (!explicitPath) cached = parsed;
      return parsed;
    } catch (e) {
      if ((e as NodeJS.ErrnoException)?.code === "ENOENT") continue;
      throw new DiviopsError(
        ErrorCodes.DIVI_ERROR,
        `module-map.json at ${candidate} could not be read: ${String(e)}`,
      );
    }
  }

  throw new DiviopsError(
    ErrorCodes.DIVI_ERROR,
    `Could not load module-map.json (tried: ${candidates.join(", ")}).`,
    "The package ships it under data/; a source checkout regenerates it with scripts/build-module-map.php.",
  );
}

/** Drop the cache. Tests load different fixtures in one process. */
export function resetModuleMapCache(): void {
  cached = undefined;
}

/**
 * Answer for one module, or the index when no module is named.
 *
 * An unknown name is `not_found` carrying the covered namespaces, not an empty
 * result: the artifact covers only `divi/*` modules that declare a per-module
 * preset map, so "nothing came back" and "this module is outside the artifact's
 * scope" are answers a caller must be able to tell apart.
 */
export function moduleMapAnswer(
  module: string | undefined,
  explicitPath?: string,
): Record<string, unknown> {
  const map = loadModuleMap(explicitPath);

  const provenance = {
    schema_version: map.schema_version,
    generated_at: map.generated_at,
    sources: map.sources,
  };

  if (!module) {
    return {
      ...provenance,
      notes: map.notes,
      counts: map.counts,
      modules: Object.keys(map.modules),
      modules_without_types: map.modules_without_types,
      modules_without_preset_map: map.modules_without_preset_map,
      unserved: map.unserved,
    };
  }

  const entry = map.modules[module];

  if (!entry) {
    throw new DiviopsError(
      ErrorCodes.NOT_FOUND,
      `No module map entry for "${module}".`,
      "Call with no module argument to list the covered names. The artifact covers divi/* modules that declare a per-module PresetAttrsMap; difl/* and d5bgo/* are covered by neither source and are absent by design.",
      {
        modules_without_preset_map: map.modules_without_preset_map,
      },
    );
  }

  return { ...provenance, module, ...entry };
}
