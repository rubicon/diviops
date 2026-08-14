/**
 * Regression test for #41 and #128 — the server must build from a clean
 * checkout of THIS repo and start.
 *
 * Both bugs were invisible to every other test in this repo because they
 * only manifest when something actually runs `dist/index.js` as a real
 * process. #41 (ERR_MODULE_NOT_FOUND on the vendored cross-env-preflight
 * modules) crashed before a single line of index.ts's own code ran. #128
 * (a missing _meta.idempotent declaration) was masked by #41 — it crashes
 * during tool registration, a point in startup #41 never let the process
 * reach. Neither would be caught by tsc alone (#41's files type-check fine
 * once vendored; #128 is a *runtime* check, not a type error) or by
 * test:server-security, which deliberately compiles only wp-cli.ts and
 * wp-cli-fs-validator.ts via tsconfig.test.json specifically because
 * src/index.ts couldn't build at all — see the now-stale comment this PR
 * also removes from .github/workflows/test.yaml.
 *
 * No WordPress credentials are needed. The server refuses to start without
 * WP_URL/WP_USER/WP_APP_PASSWORD with a specific, graceful message — that
 * refusal is itself the expected, healthy outcome this test asserts on. A
 * MODULE_NOT_FOUND or an idempotent-declaration stack trace instead of that
 * message is the regression.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const serverRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const distCrossEnvDir = join(serverRoot, 'dist', 'cross-env-preflight');

describe('server build', () => {
  it('builds cleanly from source (npm run build)', () => {
    // Removed first, not just overwritten: a prior successful run leaves
    // dist/cross-env-preflight/*.js on disk, and plain `tsc` (no copy step)
    // does not delete files it did not create. Without this, a *regression*
    // that silently drops the copy step would still find yesterday's files
    // still sitting there and pass -- confirmed directly: this exact test
    // still passed with the copy step removed from package.json until this
    // line was added, which is the failure mode a stale fixture always
    // produces and exactly why it must not be trusted uninspected.
    rmSync(distCrossEnvDir, { recursive: true, force: true });

    // Runs the real build the published package's prepublishOnly also runs,
    // not a narrower tsc invocation -- a narrower check is exactly what let
    // #41 stay invisible for as long as it did.
    execFileSync('npm', ['run', 'build'], { cwd: serverRoot, stdio: 'pipe' });
  });

  it('copies the vendored cross-env-preflight modules into dist/', () => {
    // tsc does not emit a .js file that already sits beside a same-named
    // .d.ts (allowJs is off) -- it treats the pair like a node_modules
    // dependency's own compiled output and only type-checks against the
    // .d.ts. Confirmed directly while fixing #41: `npm run build` exits 0
    // with this step *removed*, and dist/index.js still fails at import
    // time. A green build is therefore not sufficient evidence on its own;
    // this asserts the copy actually happened.
    for (const name of [
      'header-preflight.js',
      'layout-preflight.js',
      'layout-capability.js',
      'source-payload-ref.js',
    ]) {
      const path = join(serverRoot, 'dist', 'cross-env-preflight', name);
      assert.ok(existsSync(path), `dist/cross-env-preflight/${name} should exist after build`);
    }
  });

  it('starts and reaches the WP-credential check, not a crash', () => {
    const result = spawnSync('node', ['dist/index.js'], {
      cwd: serverRoot,
      env: { PATH: process.env.PATH }, // deliberately no WP_URL/WP_USER/WP_APP_PASSWORD
      encoding: 'utf8',
      timeout: 15_000,
    });

    assert.equal(
      result.error,
      undefined,
      `server process did not run to completion: ${result.error?.message}`,
    );
    const stderr = result.stderr ?? '';

    assert.ok(
      stderr.includes('Missing required environment variable'),
      `expected the graceful missing-credentials message, got:\n${stderr}`,
    );
    assert.ok(
      !stderr.includes('ERR_MODULE_NOT_FOUND'),
      `#41 regression: server crashed on module resolution:\n${stderr}`,
    );
    assert.ok(
      !stderr.includes('_meta.idempotent'),
      `#128 regression: a tool is missing its idempotent declaration:\n${stderr}`,
    );
  });
});
