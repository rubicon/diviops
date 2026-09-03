// Credential-free smoke test (#163): spawn the built server, complete a real MCP
// handshake over stdio, and assert the always-on tool surface.
//
// No WordPress site is required. `requireCredentials()` (src/index.ts) is a
// PRESENCE check only — it exits when WP_URL/WP_USER/WP_APP_PASSWORD are unset,
// but does not validate them — and `main()` catches a failed handshake as
// non-fatal by design, marking the capability gate failed so plugin tools
// surface the real 401/5xx instead of being misreported as missing capabilities.
// So dummy values pointed at a closed port yield a complete `tools/list` of the
// always-on surface, with the Pro slices correctly absent (they register only
// against a live handshake).
//
// This gates `npm publish`, so it must fail loudly rather than pass vacuously:
// a zero-tool listing, a drifted count, a missing representative tool, or a
// handshake version that disagrees with package.json all exit non-zero.
import { spawn } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// 104 plugin + 12 local registrations. The 30 Pro tools are deliberately NOT
// counted: they register only when a live handshake reports the Pro target, so a
// credential-free run must not see them.
const EXPECTED_ALWAYS_ON = 116;

// A representative slice spanning several capability domains. If the registry
// silently emptied or a domain stopped registering, a bare count check could
// still pass against a coincidentally equal number; naming tools cannot.
const EXPECTED_NAMES = [
  'diviops_meta_info',
  'diviops_page_get',
  'diviops_module_update',
  'diviops_preset_inspect',
  'diviops_variable_update',
  'diviops_library_delete',
  'diviops_media_upload',
  'diviops_menu_create',
  'diviops_tb_layout_get',
  'diviops_schema_list_modules',
  'diviops_revision_list',
  'diviops_dynamic_content_list',
];

// Pro tools must be ABSENT without a live handshake.
const PRO_TOOLS = ['diviops_cross_env_layout_apply', 'diviops_cross_env_header_apply'];

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const serverPath = join(root, 'dist', 'index.js');
const { version: PKG_VERSION } = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'));

function fail(message) {
  console.error(`SMOKE FAIL: ${message}`);
  process.exit(1);
}

function listTools(env) {
  return new Promise((resolve, reject) => {
    const server = spawn('node', [serverPath], {
      stdio: ['pipe', 'pipe', 'inherit'],
      env: { ...process.env, ...env },
    });

    const pending = new Map();
    let buffer = '';
    let settled = false;

    const timeout = setTimeout(() => finish(new Error('timed out waiting for server')), 20_000);

    function finish(error, value) {
      if (settled) return;
      settled = true;
      clearTimeout(timeout);
      server.kill();
      error ? reject(error) : resolve(value);
    }

    const send = (message) => server.stdin.write(`${JSON.stringify(message)}\n`);
    const waitFor = (id) =>
      new Promise((res, rej) => pending.set(id, { resolve: res, reject: rej }));

    server.on('error', (error) => finish(error));
    server.on('exit', (code) => {
      if (!settled && code !== null && code !== 0) {
        finish(new Error(`server exited early with code ${code}`));
      }
    });

    server.stdout.on('data', (chunk) => {
      buffer += chunk.toString();
      let newline;
      while ((newline = buffer.indexOf('\n')) >= 0) {
        const line = buffer.slice(0, newline).trim();
        buffer = buffer.slice(newline + 1);
        if (!line) continue;
        let message;
        try {
          message = JSON.parse(line);
        } catch {
          continue;
        }
        if (message.id !== undefined && pending.has(message.id)) {
          const { resolve: res, reject: rej } = pending.get(message.id);
          pending.delete(message.id);
          message.error ? rej(new Error(JSON.stringify(message.error))) : res(message.result);
        }
      }
    });

    (async () => {
      send({
        jsonrpc: '2.0',
        id: 1,
        method: 'initialize',
        params: {
          protocolVersion: '2024-11-05',
          capabilities: {},
          clientInfo: { name: 'smoke', version: '0.0.0' },
        },
      });
      const init = await waitFor(1);
      send({ jsonrpc: '2.0', method: 'notifications/initialized' });
      send({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} });
      const result = await waitFor(2);
      finish(null, {
        names: (result?.tools ?? []).map((t) => t.name),
        version: init?.serverInfo?.version,
      });
    })().catch((error) => finish(error));
  });
}

try {
  // Port 9 (discard) is closed, so the handshake fails fast without reaching any
  // real site. Nothing is written anywhere.
  const { names, version } = await listTools({
    WP_URL: 'http://127.0.0.1:9',
    WP_USER: 'smoke',
    WP_APP_PASSWORD: 'smoke',
  });

  if (!Array.isArray(names) || names.length === 0) {
    fail('tools/list returned no tools — the registry is empty or the handshake never completed');
  }
  if (version !== PKG_VERSION) {
    fail(`handshake advertises version ${version}, package.json says ${PKG_VERSION}`);
  }
  if (names.length !== EXPECTED_ALWAYS_ON) {
    fail(
      `expected ${EXPECTED_ALWAYS_ON} always-on tools, got ${names.length}. ` +
        'If a tool was intentionally added or removed, update EXPECTED_ALWAYS_ON.',
    );
  }
  const missing = EXPECTED_NAMES.filter((n) => !names.includes(n));
  if (missing.length > 0) {
    fail(`missing expected tool(s): ${missing.join(', ')}`);
  }
  const leakedPro = PRO_TOOLS.filter((n) => names.includes(n));
  if (leakedPro.length > 0) {
    fail(`Pro tool(s) registered without a live handshake: ${leakedPro.join(', ')}`);
  }

  const unique = new Set(names);
  if (unique.size !== names.length) {
    fail(`duplicate tool names registered (${names.length} listed, ${unique.size} unique)`);
  }

  // Every declared bin must resolve in the build (#222). This gate validated the
  // TOOL surface and nothing else, so 1.8.1 shipped advertising three bins while
  // the tarball carried one: `diviops-preset` had no source in this fork at all,
  // and the cross-env-preflight vendor step copies library modules but not the
  // entry point. Same shape as #214's Stable tag — a declaration nothing asserts.
  const { bin } = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'));
  const declaredBins = Object.entries(bin ?? {});
  if (declaredBins.length === 0) {
    fail('package.json declares no bin entries — expected at least the server entry point');
  }
  const danglingBins = declaredBins.filter(([, target]) => !existsSync(join(root, target)));
  if (danglingBins.length > 0) {
    fail(
      `package.json declares bin(s) with no built target: ${danglingBins
        .map(([name, target]) => `${name} -> ${target}`)
        .join(', ')}. Either ship the entry point or remove the bin entry — never publish one that cannot run.`,
    );
  }

  console.log(
    `SMOKE OK: ${names.length} always-on tools, version ${version}, Pro slices correctly absent`,
  );
} catch (error) {
  fail(error instanceof Error ? error.message : String(error));
}
