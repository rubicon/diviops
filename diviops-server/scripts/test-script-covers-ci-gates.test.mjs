// SPDX-License-Identifier: MIT
/**
 * `npm test` must run everything CI runs for diviops-server (#250).
 *
 * The workflow invokes three scripts for this package — test:server-security,
 * test:regen-skill, test:tool-reference — and `npm test` used to run none of them.
 * A contributor running the obvious command got 278/278 green while CI was red.
 * That is exactly how #248 shipped a stale generated tool reference: the gate that
 * would have caught it was real, ran in CI, and was unreachable from the command
 * anyone would actually type.
 *
 * This asserts the property rather than the current wiring: every `npm run <script>`
 * the workflow invokes for this package must be reachable from `npm test`. Adding a
 * fourth CI script later without wiring it in fails here instead of silently
 * reintroducing the false green.
 */

import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const here = dirname(fileURLToPath(import.meta.url));
const packageJsonPath = resolve(here, "..", "package.json");
const workflowPath = resolve(here, "..", "..", ".github", "workflows", "test.yaml");

const pkg = JSON.parse(readFileSync(packageJsonPath, "utf8"));
const workflow = readFileSync(workflowPath, "utf8");

/**
 * Every `npm run <script>` the workflow invokes, restricted to scripts this
 * package actually declares — the workflow also drives repo-root PHP jobs, which
 * are not ours to run.
 */
function workflowScriptsForThisPackage() {
  const invoked = new Set();
  for (const match of workflow.matchAll(/npm run ([a-z0-9:_-]+)/gi)) {
    const name = match[1];
    if (Object.prototype.hasOwnProperty.call(pkg.scripts, name)) {
      invoked.add(name);
    }
  }
  return [...invoked].sort();
}

/**
 * Scripts reachable from `npm test`, following `npm run x` chains.
 */
function scriptsReachableFromTest() {
  const seen = new Set();
  const queue = ["test"];
  while (queue.length > 0) {
    const name = queue.shift();
    if (seen.has(name)) continue;
    seen.add(name);
    const body = pkg.scripts[name];
    if (typeof body !== "string") continue;
    for (const match of body.matchAll(/npm run ([a-z0-9:_-]+)/gi)) {
      queue.push(match[1]);
    }
  }
  return seen;
}

test("the workflow really does invoke scripts from this package", () => {
  const invoked = workflowScriptsForThisPackage();
  // Without this the assertion below passes vacuously if the regex ever stops
  // matching — the failure mode this whole file exists to prevent.
  assert.ok(
    invoked.length >= 3,
    `expected the workflow to invoke at least 3 of this package's scripts, found ${invoked.length}: ${invoked.join(", ")}`,
  );
});

test("every script CI runs for diviops-server is reachable from `npm test`", () => {
  const invoked = workflowScriptsForThisPackage();
  const reachable = scriptsReachableFromTest();
  const missing = invoked.filter((name) => !reachable.has(name));

  assert.deepEqual(
    missing,
    [],
    `these scripts run in CI but not from \`npm test\`, so a local green does not mean CI is green: ${missing.join(", ")}. ` +
      `Wire them into the "test" script in diviops-server/package.json.`,
  );
});
