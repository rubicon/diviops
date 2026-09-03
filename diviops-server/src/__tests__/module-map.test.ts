// SPDX-License-Identifier: MIT
/**
 * `diviops_schema_get_module_map`'s data path (#385).
 *
 * The PHP suite owns the artifact's own correctness. What only this side can prove
 * is that the shipped file is reachable from the built layout the npm package
 * actually publishes, and that a name outside the artifact's coverage is refused
 * rather than answered with nothing — "no entry" and "this module is not covered"
 * are different answers and a caller has to be able to tell them apart.
 */

import { strict as assert } from "node:assert";
import { test } from "node:test";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { DiviopsError } from "../envelope.js";
import { moduleMapAnswer, resetModuleMapCache } from "../module-map.js";

const here = dirname(fileURLToPath(import.meta.url));
// Built layout: this file runs from dist/__tests__/, so two levels up is the package.
const packageRoot = join(here, "..", "..");

test("the artifact resolves from the built layout, with real coverage", () => {
  resetModuleMapCache();
  const index = moduleMapAnswer(undefined) as {
    modules: string[];
    counts: Record<string, number>;
    sources: Record<string, Record<string, unknown>>;
  };

  assert.ok(
    index.modules.length > 0,
    "the index lists modules — an empty answer would make every later assertion vacuous",
  );
  assert.equal(index.modules.length, index.counts.modules);
  assert.ok(index.sources.divi.version, "the Divi provenance stamp survives the read");
});

test("a covered module answers with paths, invalidates and its element map", () => {
  resetModuleMapCache();
  const entry = moduleMapAnswer("divi/button") as {
    paths: string[];
    invalidates: string[];
    elements: Record<string, unknown> | null;
  };

  assert.ok(entry.paths.length > 0);
  assert.ok(entry.invalidates.length > 0, "the invalidates set is served, not dropped");
  assert.ok(entry.elements && Object.keys(entry.elements).length > 0);
});

test("a module outside the artifact's coverage is not_found, not an empty answer", () => {
  resetModuleMapCache();
  assert.throws(
    () => moduleMapAnswer("difl/advanced-video"),
    (e: unknown) =>
      e instanceof DiviopsError &&
      e.code === "not_found" &&
      typeof e.hint === "string" &&
      e.hint.includes("difl/*"),
  );
});

test("the artifact is declared in package.json files, or npm would ship a tool with no data", () => {
  const pkg = JSON.parse(
    readFileSync(join(packageRoot, "package.json"), "utf8"),
  ) as { files: string[] };

  assert.ok(
    pkg.files.includes("data/module-map.json"),
    "data/module-map.json must be in package.json files",
  );
});
