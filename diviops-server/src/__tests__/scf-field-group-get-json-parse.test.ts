/**
 * Regression coverage for #167.
 *
 * `diviops_scf_field_group_get` resolves an ACF key by running
 * `wp post list --name=<key> --format=json` and parsing the JSON stdout to
 * find the matching post ID. That parse used to sit in a `try` with a bare
 * `catch` — a parse failure fell straight through to the same `not_found`
 * branch used for "this key genuinely has no row," so a caller could not
 * tell a missing key apart from wp-cli stdout that failed to parse at all.
 *
 * That second case is not hypothetical: reproduced live when a PHP imagick
 * startup warning printed ahead of wp-cli's `--format=json` payload, which
 * made `diviops_scf_field_group_list` fail with a `wp_error` envelope while
 * `diviops_scf_field_group_get` would have reported the same pollution as a
 * confident (and wrong) `not_found`.
 *
 * Both tools now route through the shared `parseScfWpCliJson` helper in
 * index.ts, so this file covers both: the `_get` lookup (the site that was
 * actually broken) and `_list` (which already handled this correctly
 * before the fix, and must keep doing so after sharing the helper).
 *
 * `WP_CLI_CMD` is pointed at a real (scripted) executable rather than a
 * live wp-cli/WordPress install, so this exercises the real
 * `createWpCli(...).runArgs(...)` process-spawn path and the real
 * registered tool handlers end to end — the only test double is the
 * external wp-cli program itself, not any application code.
 *
 * Excluded from tsconfig.test.json (like tool-cancellation.test.ts and
 * canonical-tool-registry-finalization.test.ts) because it imports
 * `../index.js`, which that narrower build does not compile.
 */
import { describe, it, before, after } from "node:test";
import assert from "node:assert/strict";
import { mkdtempSync, rmSync, writeFileSync, chmodSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

// A stand-in `wp` binary. Branches on the `post list`/`post get` argv it
// receives so each test drives a specific wp-cli response without a real
// WordPress install.
//
//   - `post list --name=<key>` is the `_get` lookup step: `polluted`
//     reproduces the PHP-warning-before-JSON shape that broke #167;
//     `missing`/`exists` are the legitimate-empty and legitimate-match
//     cases the fix must not regress.
//   - `post list` with no `--name=` is the `_list` tool's query; its
//     response is read from FAKE_WP_LIST_STDOUT_FILE so both the
//     parse-failure and the empty-result cases can be exercised for that
//     tool too, proving the shared helper didn't change `_list`'s
//     already-correct behavior. The path is fixed at wpCli construction
//     time (`createWpCli` snapshots `process.env` once, at import), but
//     its contents are rewritten per test, which is why this is a file
//     rather than a second env var.
//   - `post get <id>` is `_get`'s final fetch once a key has resolved.
const FAKE_WP_CLI_SCRIPT = `#!/usr/bin/env node
import { readFileSync } from "node:fs";

const args = process.argv.slice(2);

if (args[0] === "post" && args[1] === "list") {
  const nameArg = args.find((a) => a.startsWith("--name="));
  if (nameArg) {
    const name = nameArg.slice("--name=".length);
    if (name === "polluted") {
      // A PHP bootstrap warning bleeding into the JSON stream ahead of the
      // real payload — wp-cli still exits 0, but stdout is not valid JSON.
      process.stdout.write('PHP Warning:  Module "imagick" already loaded in Unknown on line 0\\n[]');
      process.exit(0);
    }
    if (name === "missing") {
      process.stdout.write("[]");
      process.exit(0);
    }
    if (name === "exists") {
      process.stdout.write('[{"ID":501}]');
      process.exit(0);
    }
    process.stderr.write("fake-wp-cli: unrecognized --name for post list: " + name);
    process.exit(1);
  }
  const listStdoutFile = process.env.FAKE_WP_LIST_STDOUT_FILE;
  if (listStdoutFile) {
    process.stdout.write(readFileSync(listStdoutFile, "utf-8"));
    process.exit(0);
  }
  process.stderr.write("fake-wp-cli: FAKE_WP_LIST_STDOUT_FILE not set for unfiltered post list");
  process.exit(1);
}

if (args[0] === "post" && args[1] === "get") {
  const id = args[2];
  if (id === "501") {
    process.stdout.write(JSON.stringify({
      ID: 501,
      post_name: "group_exists",
      post_title: "Exists",
      post_content: 'a:0:{}',
      post_status: "publish",
      post_modified: "2026-01-01 00:00:00",
    }));
    process.exit(0);
  }
  process.stderr.write("Could not find the post with ID " + id + ".");
  process.exit(1);
}

process.stderr.write("fake-wp-cli: unrecognized invocation: " + args.join(" "));
process.exit(1);
`;

type Handler = (...args: unknown[]) => Promise<{ content: Array<{ type: "text"; text: string }> }>;

let tmpDir: string;
let listStdoutFile: string;
let tool: (name: string) => Handler;

before(async () => {
  tmpDir = mkdtempSync(join(tmpdir(), "diviops-fake-wpcli-"));
  const scriptPath = join(tmpDir, "fake-wp.mjs");
  writeFileSync(scriptPath, FAKE_WP_CLI_SCRIPT, "utf-8");
  chmodSync(scriptPath, 0o755);
  listStdoutFile = join(tmpDir, "list-stdout.txt");
  writeFileSync(listStdoutFile, "[]", "utf-8");

  // Must be set before `../index.js` is imported: `wpCli` is constructed at
  // module load from these env vars (a one-time snapshot of `process.env`
  // taken inside `createWpCli`), same as WP_URL/WP_USER/WP_APP_PASSWORD are
  // read at module load for `WPClient` in tool-cancellation.test.ts. That
  // snapshot is also why `_list`'s per-test stdout is a file path rather
  // than a second env var — an env var set after import would never reach
  // the spawned child.
  process.env.WP_CLI_CMD = `node ${scriptPath}`;
  process.env.WP_PATH = tmpDir;
  process.env.FAKE_WP_LIST_STDOUT_FILE = listStdoutFile;

  const index = await import("../index.js");
  const registry = index.finalizeProductionRegistryForHandshake({
    kind: "ok",
    capabilities: {},
    pluginVersion: "99.0.0",
    proActive: false,
    availableTargets: {},
    activeModules: {},
    plugins: {},
  });

  const handlers = new Map<string, Handler>();
  registry.install({
    registerTool(name: string, _config: unknown, handler: unknown) {
      handlers.set(name, handler as Handler);
    },
    registerResource() {},
  });

  tool = (name: string) => {
    const found = handlers.get(name);
    assert.ok(found, `${name} should be present in the finalized registry`);
    return found;
  };
});

after(() => {
  rmSync(tmpDir, { recursive: true, force: true });
});

function envelope(result: { content: Array<{ type: "text"; text: string }> }): any {
  return JSON.parse(result.content[0].text);
}

describe("diviops_scf_field_group_get — non-JSON lookup stdout (#167)", () => {
  it("surfaces a parse failure as wp_error, never as not_found", async () => {
    const result = await tool("diviops_scf_field_group_get")({ key: "polluted" }, {});
    const env = envelope(result);
    assert.equal(env.ok, false);
    assert.equal(env.error.code, "wp_error");
    assert.notEqual(env.error.code, "not_found");
    assert.match(env.error.message, /non-JSON output/);
    assert.match(env.error.hint ?? "", /stdout began with/);
  });

  it("still reports not_found for a genuinely empty, validly-parsed lookup", async () => {
    const result = await tool("diviops_scf_field_group_get")({ key: "missing" }, {});
    const env = envelope(result);
    assert.equal(env.ok, false);
    assert.equal(env.error.code, "not_found");
  });

  it("resolves a key that exists to the matching field group", async () => {
    const result = await tool("diviops_scf_field_group_get")({ key: "exists" }, {});
    const env = envelope(result);
    assert.equal(env.ok, true);
    assert.equal(env.data.ID, 501);
    assert.equal(env.data.post_name, "group_exists");
  });
});

describe("diviops_scf_field_group_list — shares parseScfWpCliJson with _get", () => {
  it("surfaces a parse failure as wp_error instead of silently returning empty", async () => {
    writeFileSync(
      listStdoutFile,
      'PHP Warning:  Module "imagick" already loaded in Unknown on line 0\n[]',
      "utf-8",
    );
    const result = await tool("diviops_scf_field_group_list")({}, {});
    const env = envelope(result);
    assert.equal(env.ok, false);
    assert.equal(env.error.code, "wp_error");
    assert.match(env.error.hint ?? "", /stdout began with/);
  });

  it("still returns an empty array for a genuinely empty, validly-parsed result", async () => {
    writeFileSync(listStdoutFile, "[]", "utf-8");
    const result = await tool("diviops_scf_field_group_list")({}, {});
    const env = envelope(result);
    assert.equal(env.ok, true);
    assert.deepEqual(env.data, []);
  });
});
