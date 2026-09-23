// SPDX-License-Identifier: MIT
/**
 * Tool descriptions that disagreed with their handlers (#510).
 *
 * A description is the only thing an agent reads before calling a tool, so a wrong
 * one is worse than a missing one: the agent acts on it confidently. All three of
 * these were found by documenting the tools for #507 and confirmed against the PHP
 * handler by line before being changed.
 *
 * What this file does NOT do is check descriptions against handlers generally. That
 * was considered and rejected: the obvious gate — "every error code named in a
 * description exists in the plugin source" — would have caught NEITHER defect here.
 * One was prose that described a narrower search than the code performs, the other
 * was an omission, and a code-existence check sees neither. A gate that cannot catch
 * the bugs that motivated it is a gate that exists to look thorough. These are pinned
 * as the specific facts they are instead.
 */
import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";

/**
 * Resolved by walking up for `src/index.ts` rather than with a fixed relative path.
 * These tests run from `dist/__tests__` after compilation, so `../index.ts` resolves
 * to `dist/index.ts` — which does not exist, and the whole file dies with ENOENT
 * rather than failing an assertion.
 */
function findSource(): string {
  let dir = import.meta.dirname;
  for (let i = 0; i < 6; i++) {
    const candidate = join(dir, "src", "index.ts");
    if (existsSync(candidate)) return candidate;
    dir = join(dir, "..");
  }
  throw new Error("could not locate src/index.ts from " + import.meta.dirname);
}

const SOURCE = readFileSync(findSource(), "utf8");

// The file is large and its absence is the failure mode this resolver exists for,
// so prove it was actually read before any assertion draws a conclusion from it.
assert.ok(SOURCE.length > 100_000, "index.ts was read, not silently empty");

/** The registration block for one tool: from its quoted name to the next registration. */
function registration(tool: string): string {
  const start = SOURCE.indexOf(`"${tool}",`);
  assert.notEqual(start, -1, `${tool} is registered`);
  const next = SOURCE.indexOf("registerPluginTool(", start + 1);
  return SOURCE.slice(start, next === -1 ? start + 4000 : next);
}

describe("tool descriptions that disagreed with their handlers (#510)", () => {
  it("media_list does not claim search is title-only — the handler passes it to WP_Query `s`", () => {
    const block = registration("diviops_media_list");
    assert.equal(
      /a title search term/.test(block),
      false,
      "the old wording claimed title-only; trait-media.php assigns search to WP_Query's `s`, which also matches caption and description",
    );
    assert.match(block, /WP_Query/);
    // The control: this assertion is only meaningful while the block really is
    // media_list's. Without it, a registration() that returned the wrong slice
    // would satisfy both assertions above by accident.
    assert.match(block, /List\/paginate media library attachments/);
  });

  it("media_upload names every refusal code its handler returns", () => {
    const block = registration("diviops_media_upload");
    for (const code of [
      "forbidden_target",
      "unsupported_media_type",
      "svg_sanitizer_required",
      "svg_capability_required",
      "payload_too_large",
      "fetch_failed",
    ]) {
      assert.match(block, new RegExp(`'${code}'`), `${code} is documented`);
    }
  });

  it("module_lock and module_unlock declare themselves idempotent, which their handlers are", () => {
    for (const tool of ["diviops_module_lock", "diviops_module_unlock"]) {
      const block = registration(tool);
      assert.match(
        block,
        /_meta: \{ idempotent: "true" \}/,
        `${tool} detects the already-in-state case, reports it, and emits a no-op dry-run plan — which is the primer's definition of an idempotent write`,
      );
      assert.match(block, /idempotentHint: true/, `${tool}'s annotation agrees with its _meta`);
    }
  });
});
