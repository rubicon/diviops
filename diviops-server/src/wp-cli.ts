// SPDX-License-Identifier: MIT
/**
 * WP-CLI Wrapper for Local by Flywheel
 *
 * Executes WP-CLI commands using Local's PHP/MySQL environment.
 * Uses execFile (no shell) to prevent command injection.
 * Auto-detects PHP/MySQL versions from Local's directory structure.
 */

import { execFile } from 'child_process';
import { readdirSync, readFileSync, existsSync } from 'fs';
import { homedir } from 'os';
import { dirname, isAbsolute, join, resolve, sep } from 'path';
import {
  resolveSafeFsRoot,
  fsValidationDisabled,
  ensureSafeFsRoot,
  matchFsSensitiveCommand,
  validateFilesystemFlags,
} from './wp-cli-fs-validator.js';

interface WpCliConfig {
  /** Absolute path to the WordPress installation */
  wpPath: string;
  /** Local by Flywheel site ID (e.g. "BKr7xMxlH"). Auto-detected from wpPath if omitted. */
  localSiteId?: string;
  /**
   * Custom WP-CLI command prefix for containerized environments (e.g. "ddev wp").
   *
   * The prefix is split into an executable plus argv and run through `execFile`,
   * so a wrapper that forwards argv element for element — `ddev wp`,
   * `docker exec … wp`, `npx wp-env run cli wp` — preserves every argument
   * exactly. A wrapper that crosses a *shell* boundary does not: see the ssh
   * warning in `createWpCli`.
   */
  wpCliCmd?: string;
}

/** Default child-process deadline, in milliseconds. */
const DEFAULT_TIMEOUT_MS = 30_000;

/**
 * Resolve the wp-cli child-process deadline.
 *
 * 30s covers a local wp-cli comfortably and is far too short for a remote one:
 * a cold ssh connection plus a large `search-replace` or `export` blows through
 * it, and the caller sees a timeout kill that looks like a hung site (#344).
 *
 * A non-positive or non-integer override is refused rather than coerced —
 * `Number('soon')` is NaN and `parseInt('1.5')` is 1, and either quietly
 * becomes a deadline nobody chose.
 */
function resolveWpCliTimeoutMs(): number {
  const raw = process.env.DIVIOPS_WP_CLI_TIMEOUT_MS?.trim();
  if (!raw) return DEFAULT_TIMEOUT_MS;

  const parsed = Number(raw);
  if (!Number.isSafeInteger(parsed) || parsed <= 0) {
    console.warn(
      `[diviops] Ignoring DIVIOPS_WP_CLI_TIMEOUT_MS="${raw}" — expected a positive integer of milliseconds. Using ${DEFAULT_TIMEOUT_MS}.`,
    );
    return DEFAULT_TIMEOUT_MS;
  }

  return parsed;
}

/**
 * Default WP-CLI commands — safe for public distribution.
 * Read-only commands + non-destructive writes needed for core MCP functionality.
 */
const DEFAULT_COMMANDS: readonly string[] = [
  // Options (read-only)
  'option get',
  'option list',
  // Posts (read + create/update)
  'post list',
  'post get',
  'post create',
  'post update',
  'post meta get',
  'post meta list',
  'post meta set',
  'post meta update',
  // Taxonomy assignments (read-only). Assignment writes (`post term add/set/remove`)
  // stay unsupported until a concrete workflow justifies a typed or extended path.
  'post term list',
  // Post types (read-only)
  'post-type list',
  'post-type get',
  // Taxonomies (read + non-destructive write)
  'taxonomy list',
  'term list',
  'term create',
  'term update',
  // ACF / SCF (schema ops — idempotent dev-time workflow)
  'acf export',
  'acf import',
  'acf field-group list',
  'acf field-group get',
  // SCF 6.8.4+ adds `wp scf json {status,sync,import,export}` (also aliased as
  // `wp acf json …`). All four are dev-time schema ops — `status`/`sync` are
  // diff/apply against on-disk JSON, `import` re-creates DB entries from JSON,
  // `export` writes DB schema to JSON. Same tier semantics as the legacy `acf`
  // entries above; FS-touching subcommands (export, import) are second-pass
  // validated below.
  'scf json status',
  'scf json sync',
  'scf json import',
  'scf json export',
  'acf json status',
  'acf json sync',
  'acf json import',
  'acf json export',
  // Users (read-only)
  'user list',
  // Cache (non-destructive maintenance)
  'cache flush',
  'transient delete',
  'rewrite flush',
  // Export (reads data, writes to file only)
  'export',
  // Info (read-only + non-destructive writes)
  'cron event list',
  'plugin list',
  // `plugin update` runs from authenticated sources (WP plugin repo or licensed
  // update server) — same trust model as `core check-update`. Unlike `plugin
  // activate` / `plugin deactivate` (extended tier), it does not enable
  // previously-disabled code paths; it refreshes already-installed plugins.
  'plugin update',
  'theme list',
  'menu list',
  'site url',
  // Core (read-only only — destructive core commands stay out)
  // `core language list` is listed explicitly; the prefix matcher does exact-prefix equality +
  // space-terminated startsWith, so this does NOT accidentally authorize `core language install`
  // or any other mutating `core language` subcommand.
  'core version',
  'core check-update',
  'core is-installed',
  'core verify-checksums',
  'core language list',
  // DB (read-only introspection only — `db query` stays out; it's arbitrary SQL with no scoping
  // and belongs in a higher-risk tier, tracked separately).
  'db columns',
  'db size',
  'db tables',
  'db check',
  'db search',
];

/**
 * Extended commands that require explicit opt-in via DIVIOPS_WP_CLI_ALLOW env var.
 * These carry higher risk: destructive operations, bulk database modification,
 * arbitrary code execution, or the ability to disable security features.
 *
 * To enable, set a comma-separated subset, e.g.:
 *   DIVIOPS_WP_CLI_ALLOW="option update,post delete,search-replace"
 *
 * Only list the specific commands you need — unknown entries are ignored.
 *
 * Known limitation: validation is prefix-based, not flag-aware. Commands that
 * touch the filesystem (acf export/import, export, eval-file) can write to
 * or read from any path reachable by the WP-CLI user. For shared environments
 * consider wrapping the MCP server with a stricter proxy or running it under
 * an account with limited filesystem permissions.
 */
const EXTENDED_COMMANDS: readonly string[] = [
  'option update',       // Can change site URL, admin email, active plugins
  'option delete',       // Destructive — permanently removes a WP option
  'post delete',         // Destructive — permanently removes content
  'post meta delete',    // Destructive — removes metadata
  'term delete',         // Destructive — removes taxonomy terms
  'search-replace',      // Bulk DB modification — highest-risk content op
  'import',              // Bulk content ingestion from WXR files
  'plugin activate',     // Can enable untrusted plugins
  'plugin deactivate',   // Can disable security plugins
  'eval-file',           // Executes arbitrary PHP from a file path
];

/** Build the effective allowlist from defaults + user opt-ins. */
function buildAllowlist(): readonly string[] {
  const extra = process.env.DIVIOPS_WP_CLI_ALLOW?.trim();
  if (!extra) return DEFAULT_COMMANDS;

  // Wildcard sentinel — convenience for trusted local-dev environments. Grants
  // every entry in EXTENDED_COMMANDS but does NOT unlock anything beyond it
  // (notably: `db query` stays out — see #361 Chunk B). Always emits a startup
  // warning so the broad grant is never silent.
  if (extra === '*' || extra === 'all') {
    console.warn(
      `[diviops] DIVIOPS_WP_CLI_ALLOW="${extra}" — granting ALL ${EXTENDED_COMMANDS.length} extended commands. Intended for trusted local-dev only.`,
    );
    return [...DEFAULT_COMMANDS, ...EXTENDED_COMMANDS];
  }

  const requested = extra.split(',').map((s) => s.trim()).filter(Boolean);
  const granted = new Set<string>(DEFAULT_COMMANDS);

  for (const cmd of requested) {
    if (EXTENDED_COMMANDS.includes(cmd)) {
      granted.add(cmd);
    } else if (!granted.has(cmd)) {
      console.warn(`[diviops] Ignoring unknown WP-CLI allow entry: "${cmd}"`);
    }
  }

  return [...granted];
}

const ALLOWED_COMMANDS: readonly string[] = buildAllowlist();

/**
 * Validate a parsed command against the allowlist.
 * Checks the first 1-3 args against allowed command prefixes (supports
 * 2-word commands like "post list" and 3-word like "post meta get").
 */
function isCommandAllowed(args: string[]): { allowed: boolean; reason?: string } {
  if (args.length === 0) {
    return { allowed: false, reason: 'Empty command' };
  }

  // Build candidate prefixes: "post meta get", "post meta", "post"
  const threeWord = args.slice(0, 3).join(' ');
  const twoWord = args.slice(0, 2).join(' ');
  const oneWord = args[0];

  for (const allowed of ALLOWED_COMMANDS) {
    if (threeWord === allowed || twoWord === allowed || oneWord === allowed) {
      return { allowed: true };
    }
    // Allow commands with additional args/flags after the allowed prefix
    if (threeWord.startsWith(allowed + ' ') || twoWord.startsWith(allowed + ' ')) {
      return { allowed: true };
    }
  }

  const extendable = EXTENDED_COMMANDS.filter((c) => !ALLOWED_COMMANDS.includes(c));
  const hint = extendable.some((c) => twoWord === c || threeWord === c || oneWord === c.split(' ')[0])
    ? ` This command can be enabled via DIVIOPS_WP_CLI_ALLOW env var (see README).`
    : extendable.length > 0
      ? ` Opt-in commands available: ${extendable.join(', ')}.`
      : '';

  return {
    allowed: false,
    reason: `Command "${twoWord}" not in allowlist.${hint}`,
  };
}

/**
 * Parse a command string into an array of arguments.
 * Handles quoted strings (single and double quotes).
 */
function parseCommand(command: string): string[] {
  const args: string[] = [];
  let current = '';
  let inSingle = false;
  let inDouble = false;

  for (let i = 0; i < command.length; i++) {
    const ch = command[i];
    const next = command[i + 1];

    if (ch === '\\' && !inSingle && next !== undefined) {
      current += next;
      i++;
    } else if (ch === "'" && !inDouble) {
      inSingle = !inSingle;
    } else if (ch === '"' && !inSingle) {
      inDouble = !inDouble;
    } else if (/\s/.test(ch) && !inSingle && !inDouble) {
      if (current.length > 0) {
        args.push(current);
        current = '';
      }
    } else {
      current += ch;
    }
  }

  if (current.length > 0) {
    args.push(current);
  }

  return args;
}

function isWithinWpPath(candidate: string, wpPath: string): boolean {
  const absolute = resolve(candidate);
  const root = resolve(wpPath);
  return absolute === root || absolute.startsWith(root + sep);
}

function normalizePathValue(value: string, wpPath: string): string {
  return isAbsolute(value) ? value : resolve(wpPath, value);
}

function coalesceSplitPathValue(
  args: string[],
  startIndex: number,
  wpPath: string,
): { value: string; nextIndex: number } {
  const first = args[startIndex];
  if (first === undefined) {
    return { value: '', nextIndex: startIndex };
  }

  const normalizedFirst = normalizePathValue(first, wpPath);
  if (!isAbsolute(first)) {
    if (existsSync(normalizedFirst)) {
      return { value: normalizedFirst, nextIndex: startIndex + 1 };
    }
  } else {
    const root = resolve(wpPath);
    if (isWithinWpPath(normalizedFirst, root) && existsSync(normalizedFirst)) {
      return { value: normalizedFirst, nextIndex: startIndex + 1 };
    }

    const couldBeWpPathSplit = root.startsWith(resolve(first) + ' ');
    if (!couldBeWpPathSplit && !isWithinWpPath(normalizedFirst, root)) {
      return { value: normalizedFirst, nextIndex: startIndex + 1 };
    }
  }

  let bestParentMatch: { value: string; nextIndex: number } | null = null;
  for (let endIndex = startIndex + 1; endIndex < args.length; endIndex++) {
    if (args[endIndex]?.startsWith('--')) break;
    const joined = args.slice(startIndex, endIndex + 1).join(' ');
    const normalized = normalizePathValue(joined, wpPath);
    if (!isWithinWpPath(normalized, wpPath)) {
      continue;
    }
    if (existsSync(normalized)) {
      return { value: normalized, nextIndex: endIndex + 1 };
    }
    if (existsSync(dirname(normalized))) {
      bestParentMatch = { value: normalized, nextIndex: endIndex + 1 };
    }
  }

  return bestParentMatch ?? { value: normalizedFirst, nextIndex: startIndex + 1 };
}

function normalizePositionalPathArg(
  args: string[],
  pathIndex: number,
  wpPath: string,
): string[] {
  if (args[pathIndex] === undefined || args[pathIndex]?.startsWith('--')) {
    return args;
  }
  const before = args.slice(0, pathIndex);
  const { value, nextIndex } = coalesceSplitPathValue(args, pathIndex, wpPath);
  return [...before, value, ...args.slice(nextIndex)];
}

function normalizeDirFlagArgs(args: string[], wpPath: string): string[] {
  const normalized: string[] = [];
  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--dir' && args[i + 1] !== undefined) {
      const { value, nextIndex } = coalesceSplitPathValue(args, i + 1, wpPath);
      normalized.push(arg, value);
      i = nextIndex - 1;
    } else if (arg?.startsWith('--dir=')) {
      const rawValue = arg.slice('--dir='.length);
      const synthetic = [rawValue, ...args.slice(i + 1)];
      const { value, nextIndex } = coalesceSplitPathValue(synthetic, 0, wpPath);
      normalized.push(`--dir=${value}`);
      i += nextIndex - 1;
    } else {
      normalized.push(arg);
    }
  }
  return normalized;
}

function normalizeWpCliPathArgs(args: string[], wpPath: string): string[] {
  const threeWord = args.slice(0, 3).join(' ');
  const twoWord = args.slice(0, 2).join(' ');
  const oneWord = args[0];

  if (oneWord === 'eval-file' || oneWord === 'import') {
    return normalizePositionalPathArg(args, 1, wpPath);
  }

  if (twoWord === 'acf export' || twoWord === 'acf import') {
    return normalizePositionalPathArg(args, 2, wpPath);
  }

  if (threeWord === 'scf json import' || threeWord === 'acf json import') {
    return normalizePositionalPathArg(args, 3, wpPath);
  }

  if (
    oneWord === 'export' ||
    threeWord === 'scf json export' ||
    threeWord === 'acf json export'
  ) {
    return normalizeDirFlagArgs(args, wpPath);
  }

  return args;
}

export const __wpCliTesting = {
  isCommandAllowed,
  parseCommand,
  normalizeWpCliPathArgs,
  resolveWpCliTimeoutMs,
};

/** How a wp-cli invocation failed. See `runArgv`'s docblock for the taxonomy. */
export type WpCliFailureKind = 'rejected' | 'spawn_failed' | 'killed' | 'exited';

/**
 * Turn a failed wp-cli result into the message, hint and stderr an error
 * envelope should carry.
 *
 * Lives here rather than at the call sites because `meta_wp_cli` and the
 * `scf_*` round-trip both emit it, and they had drifted into two byte-identical
 * copies — a shape that guarantees the next correction lands in one of them.
 *
 * `isWrapper` is whether `WP_CLI_CMD` is configured. It only changes the
 * spawn-failure hint, and it has to: without a wrapper, a spawn failure really
 * is a missing or non-executable binary. With one that reaches a remote host,
 * the far likelier cause is the connection — refused, timed out, or throttled
 * after repeated connects, which is what a managed host does to a server that
 * opens a fresh ssh session per command (#344). Naming only ENOENT there sends
 * the operator to check a binary that was never the problem.
 */
export function describeWpCliFailure(
  result: {
    error?: string;
    stderr: string;
    exitCode: number | null;
    failureKind?: WpCliFailureKind;
  },
  options: { isWrapper: boolean },
): { kind: WpCliFailureKind; message: string; hint: string; stderr: string } {
  const detail = result.error ?? 'wp-cli command failed';
  const kind = result.failureKind ?? 'exited';

  if (kind === 'rejected') {
    return {
      kind,
      message: detail,
      hint: 'Command was rejected before execution. Common causes: not in the allowlist (see DIVIOPS_WP_CLI_ALLOW for opt-ins) or filesystem path outside DIVIOPS_WP_CLI_SAFE_FS_ROOT.',
      stderr: detail,
    };
  }

  if (kind === 'spawn_failed') {
    const transport = options.isWrapper
      ? ' For a WP_CLI_CMD wrapper that reaches a remote host, the connection is the likelier cause than the binary: refused, timed out, or rate-limited after repeated connects. Every command opens a new session unless ssh multiplexing is on — set `ControlMaster auto` with a `ControlPath` and a `ControlPersist` window for that host in ~/.ssh/config, and confirm the wrapper runs by hand.'
      : '';
    return {
      kind,
      message: `wp-cli could not spawn: ${detail}`,
      hint:
        'The OS refused to start the wp-cli executable — common causes: WP_CLI_CMD points at a missing binary (ENOENT), the binary is not executable (EACCES), or PATH does not include wp-cli. Verify `which wp` (or your WP_CLI_CMD prefix) resolves and is executable.' +
        transport +
        ' error.data.stdout / error.data.stderr are empty because the child never ran.',
      stderr: detail,
    };
  }

  if (kind === 'killed') {
    return {
      kind,
      message: `wp-cli command terminated: ${detail}`,
      hint: 'Command was launched but killed before it finished (timeout or signal). error.data.stdout / error.data.stderr carry whatever streamed before the kill. Raise the deadline with DIVIOPS_WP_CLI_TIMEOUT_MS (default 30000) or split the command into smaller batches.',
      stderr: result.stderr,
    };
  }

  return {
    kind,
    message: `wp-cli exited with code ${result.exitCode}`,
    hint: 'Inspect error.data.stderr for the failure reason; re-run with WP_CLI_DEBUG=1 in the env to surface PHP traceback.',
    stderr: result.stderr,
  };
}

/** Longest excerpt of the offending stream carried on a parse failure. */
const JSON_EXCERPT_LIMIT = 512;

/**
 * Raised when a `--format=json` stream carries no parsable JSON payload.
 * `excerpt` is a bounded slice of the stream — stdout can reach `maxBuffer`
 * (5MB), which must never be inlined into an error message.
 */
export class WpCliJsonParseError extends Error {
  readonly excerpt: string;

  constructor(message: string, stdout: string) {
    super(message);
    this.name = 'WpCliJsonParseError';
    this.excerpt =
      stdout.length > JSON_EXCERPT_LIMIT
        ? `${stdout.slice(0, JSON_EXCERPT_LIMIT - 1)}…`
        : stdout;
  }
}

/**
 * Walk forward from an opening `[` or `{` and return the index just past the
 * matching close, or -1 if the value never closes.
 *
 * Deliberately a character walk rather than a pattern: string contents are
 * skipped wholesale (with `\` escapes honored) so a brace inside a value —
 * an `acf-field-group` row's serialized `post_content` blob is full of them —
 * cannot end the span early. The walk only delimits a candidate; `JSON.parse`
 * remains the authority on whether that span is actually valid.
 */
function findValueEnd(text: string, start: number, budget: { remaining: number }): number {
  const stack: string[] = [];
  let inString = false;

  for (let i = start; i < text.length; i++) {
    if (budget.remaining-- <= 0) return -1;
    const ch = text[i];

    if (inString) {
      if (ch === '\\') {
        i++; // Skip the escaped character, whatever it is.
      } else if (ch === '"') {
        inString = false;
      }
      continue;
    }

    if (ch === '"') {
      inString = true;
    } else if (ch === '[' || ch === '{') {
      stack.push(ch);
    } else if (ch === ']' || ch === '}') {
      const open = stack.pop();
      if (open !== (ch === ']' ? '[' : '{')) return -1; // Mismatched close.
      if (stack.length === 0) return i + 1;
    }
  }

  return -1;
}

/**
 * Does the value ending at `end` also finish its line?
 *
 * Starting a line is necessary but not sufficient for a span to be the
 * payload. `print_r` and `var_dump` — the commonest things a debugging
 * mu-plugin echoes, and precisely the plugin output that suppression could
 * never have caught — indent an array index so the line begins `[0] => …`.
 * `[0]` parses, so without this check the extractor hands back `[0]` while
 * the real rows sit on the next line. wp-cli's own `--format=json` payload
 * always occupies its line to the end.
 */
function endsItsLine(text: string, end: number): boolean {
  for (let i = end; i < text.length; i++) {
    const ch = text[i];
    if (ch === '\n') return true;
    if (ch !== ' ' && ch !== '\t' && ch !== '\r') return false;
  }
  return true; // Ran to end of stream — the final line needs no newline.
}

/**
 * Indices of every `[` / `{` that opens a line, ignoring that line's leading
 * whitespace. Yielded in stream order.
 */
function* lineAnchoredStarts(text: string): Generator<number> {
  let atLineStart = true;

  for (let i = 0; i < text.length; i++) {
    const ch = text[i];

    if (ch === '\n') {
      atLineStart = true;
    } else if (atLineStart && (ch === ' ' || ch === '\t' || ch === '\r')) {
      // Still at the start of the line — indentation does not disqualify it.
    } else {
      if (atLineStart && (ch === '[' || ch === '{')) yield i;
      atLineStart = false;
    }
  }
}

/**
 * Parse a wp-cli `--format=json` stream that may carry non-JSON noise.
 *
 * wp-cli's contract is "stdout is JSON," but PHP writes to the same stream
 * before wp-cli runs: a startup warning (imagick module-API mismatch on the
 * reference site), a mu-plugin `echo`, a shutdown notice. The command still
 * exits 0, so the runner reports success while `JSON.parse` throws — the
 * failure mode behind #167.
 *
 * Suppressing the noise instead was investigated and does not work from this
 * runner: `-d display_errors=0` is a PHP flag and `execFile('wp', args)` makes
 * wp-cli the executable, while `WP_CLI_PHP_ARGS` is read only by wp-cli's
 * shell wrapper — Local ships `wp` as a bare phar behind a `php` shebang.
 * Suppression would also miss non-PHP pollution such as a plugin `echo`.
 *
 * A clean stream takes the fast path and behaves exactly like `JSON.parse`.
 * Otherwise the payload is looked for at the start of a line (leading
 * whitespace allowed), because that is where wp-cli puts it and PHP's
 * diagnostics end with a newline. Position, not content, is what separates
 * payload from noise: a bracket quoted mid-sentence in a warning is never a
 * candidate even when it would parse in isolation.
 *
 * That anchor is also what makes truncation fail loudly. A stream clipped by
 * `maxBuffer` ends mid-value, and its only line-anchored candidate never
 * closes; scanning every bracket instead would find some inner object that
 * parses and hand back a fragment of a list as though it were the list.
 *
 * Anchoring on `[` / `{` means bare scalars are not accepted. Every caller
 * runs a `list`/`get` that yields an array or object, and accepting scalars
 * would let a stray `0` on an otherwise-failed stream parse as a payload.
 *
 * @throws {WpCliJsonParseError} when no complete JSON value can be recovered.
 */
export function parseWpCliJson(stdout: string): unknown {
  // A plugin file saved with a BOM is the classic "output started at" cause,
  // and the BOM is invisible — left in place it produces a failure whose
  // quoted stdout looks like perfectly valid JSON.
  const text = stdout.charCodeAt(0) === 0xfeff ? stdout.slice(1) : stdout;

  try {
    const value = JSON.parse(text);
    if (typeof value === 'object' && value !== null) return value;
  } catch {
    // Fall through to the scan — the stream is polluted, truncated, or empty.
  }

  // The scan is O(candidates x stream): a stream of nothing but line-anchored
  // unclosed brackets is quadratic, measured at 8.4s for 100KB and rising
  // ~4x per doubling. This function is synchronous, so that would block the
  // whole MCP server and ignore the request AbortSignal. The budget is far
  // above anything real output needs and turns the pathological case into a
  // prompt failure instead of a hang.
  const budget = { remaining: text.length * 8 + 65_536 };

  for (const start of lineAnchoredStarts(text)) {
    const end = findValueEnd(text, start, budget);
    if (end === -1) {
      if (budget.remaining <= 0) break;
      continue;
    }
    if (!endsItsLine(text, end)) continue;

    try {
      return JSON.parse(text.slice(start, end));
    } catch {
      // This candidate was noise that merely looked structural. Keep looking.
    }
  }

  throw new WpCliJsonParseError(
    'wp-cli returned no parsable JSON for --format=json.',
    stdout,
  );
}

/**
 * Find the latest installed version of a Local lightning-service.
 * Scans ~/Library/Application Support/Local/lightning-services/ for directories
 * matching the prefix (e.g. "php-", "mysql-") and returns the latest.
 */
function findServiceDir(localSupport: string, prefix: string, platform: string): string | null {
  const servicesDir = join(localSupport, 'lightning-services');
  try {
    const dirs = readdirSync(servicesDir)
      .filter((d) => d.startsWith(prefix))
      .sort()
      .reverse(); // Latest version first

    for (const dir of dirs) {
      const binDir = join(servicesDir, dir, 'bin', platform, 'bin');
      try {
        readdirSync(binDir); // Check it exists
        return binDir;
      } catch {
        // Try without nested bin
        const altDir = join(servicesDir, dir, 'bin', platform);
        try {
          readdirSync(altDir);
          return altDir;
        } catch {
          continue;
        }
      }
    }
  } catch {
    // lightning-services dir not found
  }
  return null;
}

/**
 * Detect the platform string for Local's binary directories.
 */
function detectPlatform(): string {
  const arch = process.arch === 'arm64' ? 'arm64' : 'x64';
  return `darwin-${arch}`;
}

/**
 * Auto-detect the Local by Flywheel site ID from a WordPress path.
 * Reads ~/Library/Application Support/Local/sites.json and matches by path.
 */
function detectLocalSiteId(wpPath: string): string | null {
  const home = homedir();
  const localSupport = join(home, 'Library', 'Application Support', 'Local');
  const sitesFile = join(localSupport, 'sites.json');

  if (!existsSync(sitesFile)) {
    return null;
  }

  try {
    const sites = JSON.parse(readFileSync(sitesFile, 'utf-8'));
    // Normalize the wpPath: strip trailing /app/public if present
    const normalizedWp = wpPath.replace(/\/app\/public\/?$/, '');

    for (const [siteId, site] of Object.entries(sites) as [string, any][]) {
      // site.path may use ~ for home dir
      const rawPath = site.path ?? '';
      if (!rawPath) continue; // Skip entries with missing path.
      const sitePath = rawPath.replace(/^~/, home);
      if (normalizedWp === sitePath || wpPath.startsWith(sitePath + '/')) {
        return siteId;
      }
    }
  } catch (e) {
    console.error(`Error reading Local sites.json: ${e}`);
  }

  return null;
}

/**
 * Build the environment variables needed for Local by Flywheel's WP-CLI.
 * Auto-detects PHP and MySQL versions from Local's directory structure.
 */
function buildLocalEnv(localSiteId: string): Record<string, string> {
  const localSupport = join(homedir(), 'Library', 'Application Support', 'Local');
  const runDir = `${localSupport}/run/${localSiteId}`;
  const platform = detectPlatform();

  const phpDir = findServiceDir(localSupport, 'php-', platform);
  const mysqlDir = findServiceDir(localSupport, 'mysql-', platform);
  const wpCliDir = '/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix';

  const pathParts = [
    mysqlDir,
    phpDir,
    wpCliDir,
    process.env.PATH ?? '',
  ].filter(Boolean);

  return {
    ...(process.env as Record<string, string>),
    MYSQL_HOME: `${runDir}/conf/mysql`,
    PHPRC: `${runDir}/conf/php`,
    PATH: pathParts.join(':'),
    WP_CLI_DISABLE_AUTO_CHECK_UPDATE: '1',
  };
}

/**
 * Warn when `WP_CLI_CMD` invokes `ssh` directly.
 *
 * `execFile` hands argv to the child untouched, which every local wrapper
 * forwards faithfully. ssh does not: it joins everything after the host into
 * one string and the *remote* shell re-parses it, so `wp eval 'echo foo();'`
 * arrives as a broken command line and the remote bash — not wp-cli — reports
 * the syntax error. Reproduced against a real host on 2026-09-01:
 *
 *   bash: -c: line 0: syntax error near unexpected token `('
 *
 * Nothing in this process can repair that, because only the wrapper knows what
 * the far shell will do with the string. So the operator is told at startup
 * instead, before the first mangled command turns into a hunt for a wp-cli bug
 * that is not there.
 *
 * Matched on the executable's basename, not on the command string: a shim
 * *named* for ssh is the recommended fix and must not be warned about.
 */
function warnIfShellBoundaryWrapper(executable: string, wpCliCmd: string): void {
  const command = executable.split(/[\\/]/).pop();
  if (command !== 'ssh') return;

  console.warn(
    `[diviops] WP_CLI_CMD="${wpCliCmd}" invokes ssh directly. ssh concatenates its command argv into one string that the remote shell re-parses, so per-argument quoting is lost — \`wp eval\`, \`search-replace\` patterns, and JSON filters fail as remote shell syntax errors rather than wp-cli errors. Point WP_CLI_CMD at a local shim that re-quotes instead:\n` +
      `  #!/bin/bash\n` +
      `  exec ssh -o BatchMode=yes HOST "cd /srv/site && wp $(printf '%q ' "$@")"\n` +
      `See https://github.com/rubicon/diviops/blob/main/SETUP.md#remote-hosts-over-ssh`,
  );
}

export function createWpCli(config: WpCliConfig) {
  let executable = 'wp';
  let prefixArgs: string[] = [];
  let env: Record<string, string>;
  const customWpCliCmd = config.wpCliCmd?.trim();
  const execOptions = {
    env: process.env as Record<string, string>,
    timeout: resolveWpCliTimeoutMs(),
    maxBuffer: 5 * 1024 * 1024,
  };

  if (customWpCliCmd) {
    [executable, ...prefixArgs] = parseCommand(customWpCliCmd);
    if (!executable) {
      throw new Error('WP_CLI_CMD must include an executable.');
    }
    warnIfShellBoundaryWrapper(executable, customWpCliCmd);
    env = { ...(process.env as Record<string, string>) };
  } else {
    // Auto-detect site ID if not provided.
    const localSiteId = config.localSiteId || detectLocalSiteId(config.wpPath);
    if (!localSiteId) {
      throw new Error(
        `Could not detect Local by Flywheel site ID for path "${config.wpPath}". ` +
        `Provide LOCAL_SITE_ID env var or ensure the site is registered in Local.`
      );
    }

    env = buildLocalEnv(localSiteId);
  }

  const runOptions = customWpCliCmd
    ? { ...execOptions, env, cwd: config.wpPath }
    : { ...execOptions, env };

  // Internal argv-based runner — used by both `run(string)` (after
  // parseCommand) and `runArgs(string[])` (skip parseCommand). Typed
  // wrappers should call runArgs to bypass parseCommand's quote-toggling
  // weakness: when a value contains an apostrophe (e.g. label "Bob's
  // Group", file path /tmp/it's-fine.json), parseCommand mis-splits the
  // argv because it treats the embedded `'` as a quote toggle. Passing
  // pre-built argv eliminates the parsing step entirely so user-provided
  // strings flow through verbatim — execFile (no shell) handles them
  // correctly. Raised in prior review feedback (Copilot/Gemini both flagged).
  //
  // Result shape:
  //   - `output` is the legacy concatenated stream (stdout + stderr,
  //     trimmed, deprecation lines filtered) preserved for callers that
  //     have not migrated to the split form.
  //   - `stdout`, `stderr`, `exitCode` are the split fields envelope
  //     adopters consume — `stderr` is the pre-filter raw stream (no
  //     deprecation filtering), `exitCode` is `null` whenever a numeric
  //     exit code is unavailable (pre-execution rejection, spawn failure,
  //     timeout, or signal kill). The `failureKind` discriminator tells
  //     callers which of those four null-cases occurred so they don't have
  //     to guess from the captured streams:
  //       - `undefined` on success
  //       - `'rejected'`     — short-circuit before execFile (allowlist or
  //                            FS validator); `stdout`/`stderr` are empty
  //       - `'spawn_failed'` — execFile invoked but the OS refused to start
  //                            the child (ENOENT, EACCES, etc.); the child
  //                            never ran, `stdout`/`stderr` are empty.
  //                            `error.code` from execFile is a string errno
  //                            rather than a numeric exit code.
  //       - `'killed'`       — execFile launched the child but it was killed
  //                            (timeout or signal); `stdout`/`stderr` carry
  //                            whatever streamed before the kill
  //       - `'exited'`       — execFile launched the child and it exited
  //                            with a numeric code (success path is
  //                            distinguished by `success: true`)
  //     A prior Codex review pass flagged that `meta_wp_cli` conflated
  //     `'rejected'` with `'killed'` because both share `exitCode: null`,
  //     causing real timeouts to be misreported as pre-execution
  //     rejections. Pass 2 flagged that ENOENT-style spawn failures were
  //     being misclassified as `'killed'` (the child never ran). Splitting
  //     into four kinds lets every envelope adopter (`meta_wp_cli`
  //     passthrough, `scf_*` round-trip) emit accurate hints per cause.
  type RunArgvResult = {
    success: boolean;
    output: string;
    error?: string;
    stdout: string;
    stderr: string;
    exitCode: number | null;
    failureKind?: 'rejected' | 'spawn_failed' | 'killed' | 'exited';
  };
  const runArgv = async (args: string[]): Promise<RunArgvResult> => {
    const check = isCommandAllowed(args);
    if (!check.allowed) {
      return {
        success: false,
        output: '',
        error: check.reason,
        stdout: '',
        stderr: '',
        exitCode: null,
        failureKind: 'rejected',
      };
    }

    // Second-pass FS validation for commands whose flags/args can read/write
    // arbitrary paths. Scoped to DEFAULT-tier FS commands only — EXTENDED
    // (`import`, `eval-file`) are opt-in via DIVIOPS_WP_CLI_ALLOW, so opting
    // in signals the caller accepts path-scope risk. Skip entirely when the
    // user explicitly disables via DIVIOPS_WP_CLI_UNSAFE_FS=1.
    //
    // Wrapper mode (WP_CLI_CMD set) is gated separately inside
    // validateFilesystemFlags — host-derived safe roots don't correspond to
    // the wrapper's filesystem namespace, so the validator requires an
    // explicit DIVIOPS_WP_CLI_SAFE_FS_ROOT there. We also skip the host-side
    // mkdir in wrapper mode since the safe root is either container-scoped
    // (user-managed) or unset (validator rejects).
    if (!fsValidationDisabled() && matchFsSensitiveCommand(args)) {
      const safeRoot = resolveSafeFsRoot(config.wpPath);
      if (!customWpCliCmd) {
        ensureSafeFsRoot(safeRoot);
      }
      const fsCheck = validateFilesystemFlags(args, safeRoot, {
        isWrapper: !!customWpCliCmd,
      });
      if (!fsCheck.allowed) {
        return {
          success: false,
          output: '',
          error: fsCheck.reason,
          stdout: '',
          stderr: '',
          exitCode: null,
          failureKind: 'rejected',
        };
      }
    }

    const fullArgs = customWpCliCmd
      ? [...prefixArgs, ...args, '--no-color']
      : [...args, `--path=${config.wpPath}`, '--no-color'];

    return new Promise((resolve) => {
      execFile(
        executable,
        fullArgs,
        runOptions,
        (error, stdout, stderr) => {
          // Filter PHP deprecation warnings from the legacy concatenated
          // `output` field; `stdout` / `stderr` siblings are kept raw so
          // envelope adopters (meta_wp_cli passthrough, scf_* round-trip)
          // see the unfiltered streams.
          const output = (stdout + '\n' + stderr)
            .split('\n')
            .filter((line) => !line.includes('Deprecated:') && !line.includes('PHP Deprecated'))
            .join('\n')
            .trim();

          if (error) {
            // execFile callback fired with an error. Three possible
            // sub-cases; classifying them up-front so the handler can emit
            // accurate hints per cause:
            //
            //  - `error.killed === true`            → timeout (Node sets
            //    this when the configured `timeout` expires).
            //  - `error.signal != null`             → killed by signal.
            //  - typeof error.code === 'number'     → child ran and exited
            //    with a numeric exit code (the normal "exited non-zero"
            //    case).
            //  - typeof error.code === 'string' or undefined → spawn-side
            //    failure. ENOENT (binary missing), EACCES (not executable),
            //    EPERM, etc. — execFile reports these via the same error
            //    callback but the child never started. Distinct from
            //    "killed" because there's no partial output to preserve
            //    and the fix path is environmental (PATH, install, perms),
            //    not "raise the timeout."
            const codeRaw = (error as NodeJS.ErrnoException).code as
              | number
              | string
              | undefined;
            const isKilled = error.killed === true || error.signal != null;
            const isExited = typeof codeRaw === 'number';
            const exitCode = isExited ? (codeRaw as number) : null;
            const failureKind: 'killed' | 'exited' | 'spawn_failed' = isKilled
              ? 'killed'
              : isExited
                ? 'exited'
                : 'spawn_failed';
            const detail = isKilled
              ? error.killed
                ? 'Command timed out'
                : `Killed by signal ${error.signal}`
              : isExited
                ? `Exit code ${codeRaw}`
                : // spawn_failed — surface the system errno as the detail
                  // string ("Spawn failed: ENOENT") so the cause is visible
                  // without parsing structured fields. Falls back to the
                  // raw error.message when `code` is empty.
                  `Spawn failed: ${codeRaw ?? error.message ?? 'unknown'}`;
            resolve({
              success: false,
              output,
              error: detail,
              stdout,
              stderr,
              exitCode,
              failureKind,
            });
          } else {
            resolve({
              success: true,
              output,
              stdout,
              stderr,
              exitCode: 0,
            });
          }
        },
      );
    });
  };

  return {
    /**
     * Execute a WP-CLI command from a string. Parsed via parseCommand
     * (single/double-quote toggling, no escape support). Validated against
     * the allowlist. Uses execFile (no shell) to prevent command injection.
     *
     * Prefer `runArgs` from typed wrappers — it skips parseCommand entirely
     * so user-supplied values containing apostrophes/quotes flow through
     * verbatim instead of being mis-split.
     */
    async run(command: string): Promise<{
      success: boolean;
      output: string;
      error?: string;
      stdout: string;
      stderr: string;
      exitCode: number | null;
      failureKind?: 'rejected' | 'spawn_failed' | 'killed' | 'exited';
    }> {
      return runArgv(normalizeWpCliPathArgs(parseCommand(command), config.wpPath));
    },

    /**
     * Execute a WP-CLI command from a pre-built argv array. Skips
     * parseCommand so values containing whitespace, apostrophes, or
     * quotes pass through unmodified. Same allowlist + FS-safe-root
     * validation as `run`. Use this from typed wrappers that already
     * have the args structured (no string concatenation needed).
     */
    async runArgs(args: string[]): Promise<{
      success: boolean;
      output: string;
      error?: string;
      stdout: string;
      stderr: string;
      exitCode: number | null;
      failureKind?: 'rejected' | 'spawn_failed' | 'killed' | 'exited';
    }> {
      return runArgv(args);
    },

    /** Return the list of allowed commands and available extensions. */
    getAllowedCommands(): { allowed: string[]; extendable: string[] } {
      return {
        allowed: [...ALLOWED_COMMANDS],
        extendable: EXTENDED_COMMANDS.filter((c) => !ALLOWED_COMMANDS.includes(c)),
      };
    },
  };
}
