# Upstream Sync PR 1: Safe Refactors + Additive Fields — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adopt the upstream `ba008d2` sync commit's safe, disjoint pieces — named option-registry helpers in `trait-core.php`, their call-site migrations in `trait-preset.php`/`trait-variable.php`, a `trait-rollback.php` bug fix, `trait-meta.php`/`compatibility.ts` handshake field additions, `wp-client.ts` cancellation plumbing, and a scoped `package.json` devDependency addition — with zero net change in behavior except the two intentional additions (rollback backfill fix, handshake fields).

**Architecture:** Pure refactor + additive-field PR. No new design decisions; every change either (a) extracts an existing inline pattern into a named function with identical arguments/behavior, or (b) adds an optional field/parameter that no existing caller is forced to supply. See `docs/superpowers/specs/2026-08-02-upstream-sync-reconciliation-design.md` §"Adopt as-is" for the source-of-truth rationale per file.

**Tech Stack:** PHP 7.4+ (WordPress plugin, `plugins/diviops-agent/`), TypeScript/Node ESM (`diviops-server/`), this repo's plain-PHP test runner (`tests/run.php`, no PHPUnit/Composer), Node's built-in `node:test` for the TypeScript side.

## Global Constraints

- Never rename or alter behavior of the four frozen identifiers: plugin slug `diviops-agent`, class `DiviOps_Agent`, REST namespace `diviops/v1`, filter `diviops_agent_handshake_extensions`. Nothing in this PR touches any of them — confirmed in the design spec.
- **Testability boundary, established by this repo's own convention** (see `tests/test-variable-update.php`'s own doc comment): `tests/wp-shim.php` does **not** shim `get_option`/`update_option`/`get_post`/`get_current_user_id`/`wp_get_current_user`/`get_site_url` — calling any of these in the plain-PHP harness fatals. Every PHP task in this plan therefore verifies via (a) `php -l` syntax check, (b) exact behavior-preservation by inspection (same option name, same args, same call shape), and (c) a full `php tests/run.php` regression run at the end — **not** a new unit test, because writing one would require faking WordPress option storage, which this project's engineering policy forbids as a mocked-behavior test. This is not a shortcut; it matches the existing, accepted boundary this codebase already draws.
- TypeScript tasks (wp-client.ts) genuinely are unit-testable without a live site (dependency-injected `fetch`, no WordPress primitives involved) — those get real new tests, TDD, failing-first.
- Signed commits, no `--no-verify`. Commit after each task passes its own verification step.
- Do not adopt anything from the upstream sync commit not explicitly listed in this plan — see the design spec's "package.json" entry for a concrete example of upstream code that must NOT be adopted (the `check-release-boundary.mjs` reference, which doesn't exist even on `upstream/main`).

---

### Task 1: `trait-core.php` — add the four registry helper functions

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-core.php` (insert after line 715, migrate one call site at line ~925)
- Test: none (unshimmed WP primitives — see Global Constraints)

**Interfaces:**
- Produces: `DiviOps_Agent_Core::read_divi_global_variables_registry(): array`,
  `DiviOps_Agent_Core::write_divi_global_variables_registry( array $registry ): bool`,
  `DiviOps_Agent_Core::read_canonical_d5_preset_registry( $default = [] )`,
  `DiviOps_Agent_Core::write_canonical_d5_preset_registry( $registry ): bool` — all `private static`, consumed by Tasks 2 and 3.

- [ ] **Step 1: Insert the four helper functions**

Insert immediately after the comment block ending at line 715 (`// corroboration: 5.5.1→5.5.2 migration test confirms `_ng` byte-identical-empty across the transition (see #719 comment thread).`), before the existing docblock for the D5 preset candidate-paths function:

```php
	/**
	 * Read Divi's raw non-color Global Variables registry without normalization.
	 *
	 * This exact upstream-owned option is written by Divi's
	 * GlobalData::set_global_variables(). Its public getter adds customizer
	 * defaults and runtime-only allowedActions fields, so it is not equivalent
	 * for guarded read/modify/write operations that must preserve stored bytes.
	 */
	private static function read_divi_global_variables_registry(): array {
		// Divi-owned integration state; do not rename to a DiviOps option.
		$registry = get_option( 'et_divi_global_variables', [] );
		return is_array( $registry ) ? $registry : [];
	}

	/**
	 * Persist Divi's raw non-color Global Variables registry.
	 */
	private static function write_divi_global_variables_registry( array $registry ): bool {
		// Divi-owned integration state; exact key required by Divi's option layer.
		return update_option( 'et_divi_global_variables', $registry );
	}

	/**
	 * Read the exact canonical Divi 5 preset registry.
	 *
	 * @param mixed $default Value returned when the option is absent.
	 * @return mixed
	 */
	private static function read_canonical_d5_preset_registry( $default = [] ) {
		// Divi-owned integration state; GlobalPreset::option_name() supplies the suffix.
		return get_option( 'et_divi_builder_global_presets_d5', $default );
	}

	/**
	 * Write the exact canonical Divi 5 preset registry.
	 *
	 * Direct access preserves doctor backup/readback/rollback semantics; Divi's
	 * public preset controllers do not expose an equivalent whole-registry
	 * transaction.
	 */
	private static function write_canonical_d5_preset_registry( $registry ): bool {
		// Divi-owned integration state; do not create a prefixed parallel copy.
		return update_option( 'et_divi_builder_global_presets_d5', $registry, false );
	}

```

- [ ] **Step 2: Migrate `save_d5_presets()`'s inline write to the new helper**

Find (around original line 925, now shifted +45 lines by Step 1's insertion):

```php
	private static function save_d5_presets( $d5 ) {
		update_option( 'et_divi_builder_global_presets_d5', $d5, false );
	}
```

Replace with:

```php
	private static function save_d5_presets( $d5 ) {
		self::write_canonical_d5_preset_registry( $d5 );
	}
```

- [ ] **Step 3: Also apply this one-word comment fix in the same file** (upstream's diff, same file, no functional change)

Find:
```php
		// Top-level option.
```
(in the D5-preset-candidate-paths function, reading a top-level fallback path)

Replace with:
```php
		// Top-level candidates are documented Divi-owned integration stores.
```

- [ ] **Step 4: Syntax check**

Run: `php -l plugins/diviops-agent/includes/trait-core.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add plugins/diviops-agent/includes/trait-core.php
git commit -m "refactor(core): add named registry helpers for et_divi_global_variables/et_divi_builder_global_presets_d5

Extracted from upstream ba008d2. Pure refactor for this fork — verified
no correctness gap existed (every write-path read in this plugin already
used raw get_option/update_option, never Divi's public GlobalData getter
for these two options). Named helpers give the pattern one canonical
name instead of repeating it inline at 11+ call sites (Tasks 2-3)."
```

---

### Task 2: `trait-preset.php` — migrate to the new preset-registry helpers

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-preset.php` (4 call sites in `preset_registry_doctor()`, 1 comment in `preset_list()`'s D4-legacy read)
- Test: none (see Global Constraints)

**Interfaces:**
- Consumes: `read_canonical_d5_preset_registry( $default = [] )`, `write_canonical_d5_preset_registry( $registry ): bool` (Task 1).

- [ ] **Step 1: Migrate `preset_registry_doctor()`'s 4 call sites**

Find (around line 25):
```php
		$option_name      = 'et_divi_builder_global_presets_d5';
		$registry         = get_option( $option_name, [] );
```
Replace with:
```php
		$option_name      = 'et_divi_builder_global_presets_d5';
		$registry         = self::read_canonical_d5_preset_registry( [] );
```

Find (around line 90):
```php
		if ( ! self::preset_registry_values_equal( $updated, $registry ) && ! update_option( $option_name, $updated, false ) ) {
```
Replace with:
```php
		if ( ! self::preset_registry_values_equal( $updated, $registry ) && ! self::write_canonical_d5_preset_registry( $updated ) ) {
```

Find (around line 93):
```php
		if ( ! self::preset_registry_values_equal( get_option( $option_name, [] ), $updated ) ) {
			$restored = update_option( $option_name, $registry, false ) || self::preset_registry_values_equal( get_option( $option_name, [] ), $registry );
```
Replace with:
```php
		if ( ! self::preset_registry_values_equal( self::read_canonical_d5_preset_registry( [] ), $updated ) ) {
			$restored = self::write_canonical_d5_preset_registry( $registry ) || self::preset_registry_values_equal( self::read_canonical_d5_preset_registry( [] ), $registry );
```

- [ ] **Step 2: Add the D4-legacy-store clarifying comment** (documentation only, in `preset_list()` around line 231)

Find:
```php
		// Legacy Divi 4 / out-of-band `_ng` store. Surfaced for visibility;
		// per #719 banner this is NOT a D5 fallback — `audit_storage` tags
		// it with `legacy_d4_ng` provenance for unambiguous classification.
		$d4_raw = get_option( 'et_divi_builder_global_presets_ng', '' );
```
Replace with:
```php
		// Legacy Divi 4 / out-of-band `_ng` store. Surfaced for visibility;
		// per #719 banner this is NOT a D5 fallback — `audit_storage` tags
		// it with `legacy_d4_ng` provenance for unambiguous classification.
		// Divi-owned D4 legacy store; read-only diagnostic provenance.
		$d4_raw = get_option( 'et_divi_builder_global_presets_ng', '' );
```

(This one stays as a raw `get_option` — it's the legacy `_ng` store, not the canonical registry Task 1's helpers wrap. Do not migrate it.)

- [ ] **Step 3: Syntax check**

Run: `php -l plugins/diviops-agent/includes/trait-preset.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add plugins/diviops-agent/includes/trait-preset.php
git commit -m "refactor(preset): migrate preset_registry_doctor to the canonical registry helpers

Pure extract-method — same option name, same get/update args. Our only
change to this file (preset_reassign, #11) is 1700+ lines away in an
unrelated function; confirmed disjoint."
```

---

### Task 3: `trait-variable.php` — migrate all 11 call sites to the new helpers

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-variable.php`
- Test: none (see Global Constraints)

**Interfaces:**
- Consumes: `read_divi_global_variables_registry(): array`, `write_divi_global_variables_registry( array $registry ): bool`, `read_canonical_d5_preset_registry( $default = [] )` (Task 1).

There are 11 call sites total: 9 correspond to upstream's own diff (in `variable_id_appears_anywhere`, `get_defined_variable_ids`, `variable_list`, `variable_create`, `variable_create_fluid_system`, `variable_delete`), plus 2 more in this fork's own `variable_update()` (#25 — not present in upstream at all, migrated here for consistency so it isn't the sole holdout still bypassing the new convention).

- [ ] **Step 1: `variable_id_appears_anywhere()` (line ~254)**

Find:
```php
		$raw = get_option( 'et_divi_builder_global_presets_d5', '' );
```
Replace with:
```php
		$raw = self::read_canonical_d5_preset_registry( '' );
```

- [ ] **Step 2: `get_defined_variable_ids()` (line ~338)**

Find:
```php
		$vars = get_option( 'et_divi_global_variables', [] );
```
Replace with:
```php
		$vars = self::read_divi_global_variables_registry();
```

(This exact 4-space-indented `$vars = get_option(...)` pattern repeats at multiple call sites below — apply each replacement only at the specific line number given, since several are byte-identical text.)

- [ ] **Step 3: `variable_list()` (line ~470)**

Find:
```php
		$vars      = get_option( 'et_divi_global_variables', [] );
```
Replace with:
```php
		$vars      = self::read_divi_global_variables_registry();
```

- [ ] **Step 4: `variable_create()` read + write (lines ~1074, ~1129)**

Find (line ~1074):
```php
		$vars = get_option( 'et_divi_global_variables', [] );
```
Replace with:
```php
		$vars = self::read_divi_global_variables_registry();
```

Find (line ~1129):
```php
		update_option( 'et_divi_global_variables', $vars );
```
Replace with:
```php
		self::write_divi_global_variables_registry( $vars );
```

- [ ] **Step 5: `variable_create_fluid_system()` read + write (lines ~1724, ~1828)**

Find (line ~1724):
```php
		$vars = get_option( 'et_divi_global_variables', [] );
```
Replace with:
```php
		$vars = self::read_divi_global_variables_registry();
```

Find (line ~1828, note the extra indentation level — this one is inside an `if`):
```php
			update_option( 'et_divi_global_variables', $vars );
```
Replace with:
```php
			self::write_divi_global_variables_registry( $vars );
```

- [ ] **Step 6: `variable_update()` read + write — this fork's own #25 addition, not part of upstream's diff (lines ~1944, ~2069)**

Find (line ~1944, inside an `else` branch):
```php
			$vars = get_option( 'et_divi_global_variables', [] );
```
Replace with:
```php
			$vars = self::read_divi_global_variables_registry();
```

Find (line ~2069):
```php
		update_option( 'et_divi_global_variables', $vars );
```
Replace with:
```php
		self::write_divi_global_variables_registry( $vars );
```

- [ ] **Step 7: `variable_delete()` read + write (lines ~2133, ~2209)**

Find (line ~2133, inside an `else` branch):
```php
			$vars = get_option( 'et_divi_global_variables', [] );
```
Replace with:
```php
			$vars = self::read_divi_global_variables_registry();
```

Find (line ~2209):
```php
			update_option( 'et_divi_global_variables', $vars );
```
Replace with:
```php
			self::write_divi_global_variables_registry( $vars );
```

- [ ] **Step 8: Verify no raw call sites remain for these two options**

Run: `grep -n "get_option( 'et_divi_global_variables'\|update_option( 'et_divi_global_variables'\|get_option( 'et_divi_builder_global_presets_d5'" plugins/diviops-agent/includes/trait-variable.php`
Expected: no output (all migrated). If any line prints, it was missed in Steps 1-7 — fix before proceeding.

- [ ] **Step 9: Syntax check**

Run: `php -l plugins/diviops-agent/includes/trait-variable.php`
Expected: `No syntax errors detected`

- [ ] **Step 10: Commit**

```bash
git add plugins/diviops-agent/includes/trait-variable.php
git commit -m "refactor(variable): migrate all 11 call sites to the canonical registry helpers

9 sites mirror upstream's own refactor (variable_id_appears_anywhere,
get_defined_variable_ids, variable_list, variable_create,
variable_create_fluid_system, variable_delete); 2 more in this fork's
own variable_update() (#25) migrated for the same consistency reason —
it would otherwise be the sole remaining holdout bypassing the new
convention. Same option names, same get/update args throughout."
```

---

### Task 4: `trait-rollback.php` — fix the batch-target query-filter bug

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-rollback.php` (line ~407 region)
- Test: none (calls unshimmed `get_post()` — see Global Constraints)

**Interfaces:** none (internal bug fix, no new public surface).

- [ ] **Step 1: Flip `suppress_filters` and add the backfill loop**

Find (around line 404-417):
```php
				'post_status'            => 'any',
				'numberposts'             => count( $target_ids ),
				'orderby'                 => 'post__in',
				'suppress_filters'        => true,
				'no_found_rows'           => true,
				'update_post_meta_cache'  => true,
				'update_post_term_cache'  => false,
```

(Note the exact spacing above is preserved from the existing file's alignment — check the real file for the surrounding `post__in` context before/after this block, since this snippet is the changed lines only, not the full array.)

Replace `'suppress_filters' => true,` with `'suppress_filters' => false,` in place, keeping everything else in that array identical.

Then, immediately after the existing loop that populates `$posts` from the query results (the loop containing `$posts[ (int) $post->ID ] = $post;`), insert:

```php
			// Query filters can scope the batch result (for example, to the
			// current language). Backfill requested IDs individually so an
			// existing recovery target is never misclassified as missing.
			foreach ( $target_ids as $target_id ) {
				if ( isset( $posts[ $target_id ] ) ) {
					continue;
				}
				$post = function_exists( 'get_post' ) ? get_post( $target_id ) : null;
				if ( is_object( $post ) && ! empty( $post->ID ) ) {
					$posts[ (int) $post->ID ] = $post;
				}
			}
```

- [ ] **Step 2: Syntax check**

Run: `php -l plugins/diviops-agent/includes/trait-rollback.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add plugins/diviops-agent/includes/trait-rollback.php
git commit -m "fix(rollback): stop query filters from misclassifying real snapshot targets as gone

get_posts() with suppress_filters=true skipped normal WP query filters
(e.g. language scoping), which could silently exclude a real snapshot
target from the batch result. Flipped to false and added a per-ID
backfill via get_post() so a target present on the site is never
misclassified as missing, regardless of active query filters."
```

---

### Task 5: `trait-meta.php` — add `authenticated_user`/`site_url` to `handshake()`

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-meta.php` (line ~1723 region, inside `handshake()`)
- Test: none (calls unshimmed `get_current_user_id`/`wp_get_current_user`/`get_site_url` — see Global Constraints)

**Interfaces:**
- Produces: two new response fields, `authenticated_user: {id: int, login: string}` and `site_url: string`, on the existing `handshake()` REST response. Consumed by Task 6's TypeScript `HandshakeResult` interface (additive — no code reads these fields yet in this PR).

- [ ] **Step 1: Add the two fields**

Find (around line 1723):
```php
			'compatible'     => true,
			'plugin_version' => self::VERSION,
			'min_server'     => self::MIN_SERVER_VERSION,
			'divi'           => [
```

Replace with:
```php
			'compatible'     => true,
			'plugin_version' => self::VERSION,
			'min_server'     => self::MIN_SERVER_VERSION,
			'authenticated_user' => [
				'id'    => get_current_user_id(),
				'login' => wp_get_current_user()->user_login,
			],
			'site_url'       => get_site_url(),
			'divi'           => [
```

Confirmed disjoint from this fork's own `record_client_runtime()` addition (#123/PR #124) — that call sits in a different region of the same `handshake()` function body and neither reads nor writes anything this touches.

- [ ] **Step 2: Syntax check**

Run: `php -l plugins/diviops-agent/includes/trait-meta.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add plugins/diviops-agent/includes/trait-meta.php
git commit -m "feat(meta): add authenticated_user/site_url to the handshake response

Purely additive — two new response fields, no existing field changed.
Confirmed disjoint from record_client_runtime() (#123/PR #124), which
lives in a different region of the same handshake() function body."
```

---

### Task 6: `diviops-server/src/compatibility.ts` — add the matching `HandshakeResult` fields

**Files:**
- Modify: `diviops-server/src/compatibility.ts` (interface only, ~line 129)
- Test: none required (pure interface addition — `tsc` itself is the check; no parsing/normalization logic attached to these fields)

**Interfaces:**
- Produces: `HandshakeResult.authenticated_user?: { id: number; login: string }`, `HandshakeResult.site_url?: string` — both optional, matching Task 5's PHP response shape. Nothing in this PR consumes them yet (that's PR 3's `health-tools.ts` production wiring, per the design spec).

- [ ] **Step 1: Add the two optional fields to the interface**

Find (around line 129):
```typescript
  active_modules?: Record<string, boolean>;
  /** Advisory installed-plugin versions for S0 preflight reports. */
  plugins?: Record<string, HandshakePluginInfo>;
}
```

Replace with:
```typescript
  active_modules?: Record<string, boolean>;
  /** Advisory installed-plugin versions for S0 preflight reports. */
  plugins?: Record<string, HandshakePluginInfo>;
  /** Permission-gated non-secret identity of the authenticated App Password owner. */
  authenticated_user?: { id: number; login: string };
  /** Observed WordPress site URL; normalized and pinned by launcher mode. */
  site_url?: string;
}
```

- [ ] **Step 2: Type-check**

Run: `cd diviops-server && npx tsc --noEmit`
Expected: exits 0, no errors.

- [ ] **Step 3: Commit**

```bash
cd diviops-server && git add src/compatibility.ts
git commit -m "feat(compatibility): add authenticated_user/site_url to HandshakeResult

Matches the two new fields the plugin's handshake() now returns (Task 5).
Pure interface addition — both optional, nothing in this PR consumes
them. health-tools.ts's production wiring in PR 3 is the first consumer."
```

---

### Task 7: `diviops-server/src/wp-client.ts` — injectable `fetch` + `AbortSignal` plumbing, TDD

**Files:**
- Modify: `diviops-server/src/wp-client.ts` (constructor, `request()`, `requestEnveloped()`, `testConnection()`, `handshake()`)
- Create: `diviops-server/src/__tests__/wp-client-cancellation.test.ts`

**Interfaces:**
- Consumes: nothing new.
- Produces: `WPClientConfig.fetch?: typeof globalThis.fetch` (constructor injection point), `WPClient.request()`/`requestEnveloped()` gain an optional `signal?: AbortSignal` in their options object, `testConnection(signal?: AbortSignal)`, `handshake(serverVersion: string, clientRuntime?: ClientRuntime, signal?: AbortSignal)`.

**Signature note — resolved collision, not a straight upstream port:** upstream's diff adds `signal` as `handshake()`'s *second* positional parameter. This fork already has a second parameter there — `clientRuntime?: ClientRuntime` (#123), called today at `index.ts:8010` as `wp.handshake(SERVER_VERSION, { wp_cli: wpCli !== null })`. Adopting upstream's position verbatim would silently break that call's meaning. `signal` goes in as the **third** parameter instead — existing call sites are unaffected (both new parameters are optional and no one passes a third argument yet).

- [ ] **Step 1: Write the failing test**

Create `diviops-server/src/__tests__/wp-client-cancellation.test.ts`:

```typescript
/**
 * WPClient cancellation + fetch injection (upstream ba008d2, adopted as PR 1).
 *
 * The injected `fetch` config option exists specifically so this is testable
 * without monkeypatching globalThis.fetch (the pattern
 * handshake-client-runtime.test.ts uses for its own, separate reasons).
 * Nothing here touches a live site.
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { WPClient } from '../wp-client.js';

function client(fetchImpl: typeof fetch) {
  return new WPClient({
    siteUrl: 'https://example.test',
    username: 'user',
    applicationPassword: 'pass',
    fetch: fetchImpl,
  });
}

describe('WPClient cancellation', () => {
  it('uses the injected fetch instead of globalThis.fetch', async () => {
    let called = false;
    const fake = (async (_url: string | URL | Request, _init?: RequestInit) => {
      called = true;
      return new Response(JSON.stringify({ ok: true }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }) as typeof fetch;

    await client(fake).request('/meta/ping');
    assert.equal(called, true, 'the injected fetch implementation should have been called');
  });

  it('threads an explicit AbortSignal through to the injected fetch', async () => {
    let receivedSignal: AbortSignal | null | undefined;
    const fake = (async (_url: string | URL | Request, init?: RequestInit) => {
      receivedSignal = init?.signal;
      return new Response(JSON.stringify({ ok: true }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }) as typeof fetch;

    const controller = new AbortController();
    await client(fake).request('/meta/ping', { signal: controller.signal });
    assert.equal(receivedSignal, controller.signal, 'the same AbortSignal instance should reach fetch');
  });

  it('testConnection rethrows the abort reason when the signal is already aborted', async () => {
    const controller = new AbortController();
    controller.abort(new Error('cancelled by caller'));

    const fake = (async () => {
      throw new Error('fetch should not be reached after abort, but if it is, this is the transport error');
    }) as typeof fetch;

    await assert.rejects(
      () => client(fake).testConnection(controller.signal),
      (err: unknown) => err instanceof Error && err.message === 'cancelled by caller',
      'testConnection should rethrow the abort reason, not wrap it in a generic connection-failed message',
    );
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd diviops-server && npx tsc && node --test dist/__tests__/wp-client-cancellation.test.js`
Expected: build fails or test fails — `fetch` is not yet a recognized `WPClientConfig` field, `request()`/`testConnection()` don't accept `signal` yet.

- [ ] **Step 3: Implement — constructor + `request()`**

Find:
```typescript
export interface WPClientConfig {
  siteUrl: string;
  username: string;
  applicationPassword: string;
}

export class WPClient {
  private baseUrl: string;
  private authHeader: string;

  constructor(config: WPClientConfig) {
    // Strip trailing slash.
    this.baseUrl = config.siteUrl.replace(/\/+$/, '');

    // WP Application Passwords use Basic Auth.
    const credentials = Buffer.from(
      `${config.username}:${config.applicationPassword}`
    ).toString('base64');
    this.authHeader = `Basic ${credentials}`;
  }
```

Replace with:
```typescript
export interface WPClientConfig {
  siteUrl: string;
  username: string;
  applicationPassword: string;
  fetch?: typeof globalThis.fetch;
}

export class WPClient {
  private baseUrl: string;
  private authHeader: string;
  private fetchImpl: typeof globalThis.fetch;

  constructor(config: WPClientConfig) {
    // Strip trailing slash.
    this.baseUrl = config.siteUrl.replace(/\/+$/, '');
    this.fetchImpl = config.fetch ?? globalThis.fetch.bind(globalThis);

    // WP Application Passwords use Basic Auth.
    const credentials = Buffer.from(
      `${config.username}:${config.applicationPassword}`
    ).toString('base64');
    this.authHeader = `Basic ${credentials}`;
  }
```

Find (inside `request()`):
```typescript
  async request<T = unknown>(
    endpoint: string,
    options: {
      method?: string;
      body?: Record<string, unknown>;
      params?: Record<string, string>;
    } = {}
  ): Promise<T> {
    const { method = 'GET', body, params } = options;
```

Replace with:
```typescript
  async request<T = unknown>(
    endpoint: string,
    options: {
      method?: string;
      body?: Record<string, unknown>;
      params?: Record<string, string>;
      signal?: AbortSignal;
    } = {}
  ): Promise<T> {
    const { method = 'GET', body, params, signal } = options;
```

Find (later in the same `request()` method):
```typescript
    const fetchOptions: RequestInit = {
      method,
      headers: {
        Authorization: this.authHeader,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
    };

    if (body && method !== 'GET') {
      const normalized = endpointSkipsNormalization(endpoint) ? body : normalizeBody(body);
      fetchOptions.body = JSON.stringify(normalized);
    }

    const response = await fetch(url, fetchOptions);

    if (!response.ok) {
      const errorBody = await response.text();
```

Replace with:
```typescript
    const fetchOptions: RequestInit = {
      method,
      headers: {
        Authorization: this.authHeader,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      signal,
    };

    if (body && method !== 'GET') {
      const normalized = endpointSkipsNormalization(endpoint) ? body : normalizeBody(body);
      fetchOptions.body = JSON.stringify(normalized);
    }

    const response = await this.fetchImpl(url, fetchOptions);

    if (!response.ok) {
      const errorBody = await response.text();
```

- [ ] **Step 4: Implement — `requestEnveloped()`**

Apply the identical three-part change (options type gains `signal?: AbortSignal`, destructure it, add `signal` to `fetchOptions`, call `this.fetchImpl` instead of bare `fetch`) to `requestEnveloped()`, which has the same shape as `request()`:

Find:
```typescript
  async requestEnveloped<T = unknown>(
    endpoint: string,
    options: {
      method?: string;
      body?: Record<string, unknown>;
      params?: Record<string, string>;
    } = {},
  ): Promise<DiviopsResponse<T>> {
    const { method = 'GET', body, params } = options;
```

Replace with:
```typescript
  async requestEnveloped<T = unknown>(
    endpoint: string,
    options: {
      method?: string;
      body?: Record<string, unknown>;
      params?: Record<string, string>;
      signal?: AbortSignal;
    } = {},
  ): Promise<DiviopsResponse<T>> {
    const { method = 'GET', body, params, signal } = options;
```

Find (later in `requestEnveloped()`):
```typescript
    const fetchOptions: RequestInit = {
      method,
      headers: {
        Authorization: this.authHeader,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
    };
    if (body && method !== 'GET') {
      const normalized = endpointSkipsNormalization(endpoint) ? body : normalizeBody(body);
      fetchOptions.body = JSON.stringify(normalized);
    }

    const response = await fetch(url, fetchOptions);
    const rawBody = await response.text();
```

Replace with:
```typescript
    const fetchOptions: RequestInit = {
      method,
      headers: {
        Authorization: this.authHeader,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      signal,
    };
    if (body && method !== 'GET') {
      const normalized = endpointSkipsNormalization(endpoint) ? body : normalizeBody(body);
      fetchOptions.body = JSON.stringify(normalized);
    }

    const response = await this.fetchImpl(url, fetchOptions);
    const rawBody = await response.text();
```

- [ ] **Step 5: Implement — `testConnection()`**

Find:
```typescript
  async testConnection(): Promise<{ ok: boolean; message: string }> {
    try {
      const response = await this.requestEnveloped<{
        builder?: { version?: string };
      }>('/schema/settings');
      if (!response.ok) {
        return {
          ok: false,
          message: `Connection failed: [${response.error.code}] ${response.error.message}`,
        };
      }
      return {
        ok: true,
        message: `Connected to Divi ${response.data.builder?.version ?? 'unknown'}`,
      };
    } catch (error) {
      return {
        ok: false,
        message: `Connection failed: ${error instanceof Error ? error.message : String(error)}`,
      };
    }
  }
```

Replace with:
```typescript
  async testConnection(signal?: AbortSignal): Promise<{ ok: boolean; message: string }> {
    try {
      const response = await this.requestEnveloped<{
        builder?: { version?: string };
      }>('/schema/settings', { signal });
      if (!response.ok) {
        return {
          ok: false,
          message: `Connection failed: [${response.error.code}] ${response.error.message}`,
        };
      }
      return {
        ok: true,
        message: `Connected to Divi ${response.data.builder?.version ?? 'unknown'}`,
      };
    } catch (error) {
      if (signal?.aborted) throw signal.reason ?? error;
      return {
        ok: false,
        message: `Connection failed: ${error instanceof Error ? error.message : String(error)}`,
      };
    }
  }
```

- [ ] **Step 6: Implement — `handshake()`, with the resolved 3-parameter signature**

Find:
```typescript
  async handshake(
    serverVersion: string,
    clientRuntime?: ClientRuntime,
  ): Promise<HandshakeResult> {
    const result = await this.request<HandshakeResult>('/handshake', {
      method: 'POST',
      body: {
        mcp_server_version: serverVersion,
        // Only sent when known. The plugin treats an absent `client_runtime`
        // as "no report" rather than a negative one (#123), so omitting it
        // leaves any previous report intact instead of clobbering it.
        ...(clientRuntime ? { client_runtime: clientRuntime } : {}),
      },
    });
```

Replace with:
```typescript
  async handshake(
    serverVersion: string,
    clientRuntime?: ClientRuntime,
    signal?: AbortSignal,
  ): Promise<HandshakeResult> {
    const result = await this.request<HandshakeResult>('/handshake', {
      method: 'POST',
      body: {
        mcp_server_version: serverVersion,
        // Only sent when known. The plugin treats an absent `client_runtime`
        // as "no report" rather than a negative one (#123), so omitting it
        // leaves any previous report intact instead of clobbering it.
        ...(clientRuntime ? { client_runtime: clientRuntime } : {}),
      },
      signal,
    });
```

- [ ] **Step 7: Run test to verify it passes**

Run: `cd diviops-server && npx tsc && node --test dist/__tests__/wp-client-cancellation.test.js`
Expected: all 3 tests pass.

- [ ] **Step 8: Run the existing handshake test to confirm no regression**

Run: `cd diviops-server && node --test dist/__tests__/handshake-client-runtime.test.js`
Expected: unchanged, all pass (it monkeypatches `globalThis.fetch` directly, which still works since `fetchImpl` defaults to `globalThis.fetch.bind(globalThis)` when no `fetch` config is given).

- [ ] **Step 9: Commit**

```bash
cd diviops-server && git add src/wp-client.ts src/__tests__/wp-client-cancellation.test.ts
git commit -m "feat(wp-client): injectable fetch + AbortSignal cancellation plumbing

request()/requestEnveloped()/testConnection()/handshake() all now accept
an optional AbortSignal, threaded through to the underlying fetch call.
Constructor gains an optional fetch override for testability without
monkeypatching globalThis.fetch.

handshake()'s signal lands as a THIRD parameter, not upstream's second —
this fork's handshake() already has a second parameter (clientRuntime,
#123) that upstream doesn't have; adopting upstream's position verbatim
would have silently broken index.ts:8010's existing call. Resolved
during implementation planning, not left for a merge to silently get
wrong."
```

---

### Task 8: `diviops-server/package.json` — add the three devDependencies, nothing else

**Files:**
- Modify: `diviops-server/package.json`

**Interfaces:** none.

- [ ] **Step 1: Add exactly the three devDependencies**

Find:
```json
  "devDependencies": {
    "@types/node": "^22.0.0",
    "typescript": "^5.7.0"
  }
```

Replace with:
```json
  "devDependencies": {
    "@modelcontextprotocol/client": "2.0.0",
    "@modelcontextprotocol/core": "2.0.0",
    "@modelcontextprotocol/server": "2.0.0",
    "@types/node": "^22.0.0",
    "typescript": "^5.7.0"
  }
```

**Do not** change `version`, `files`, or `scripts.test` in this file — see the design spec's explicit rejection of those sub-hunks (upstream's own `test` script addition references `scripts/check-release-boundary.mjs`, which does not exist even on `upstream/main`; adopting it would break `npm test` immediately).

- [ ] **Step 2: Install and verify no dependency-resolution error**

Run: `cd diviops-server && npm install`
Expected: exits 0, no `ERESOLVE`/`E404`, `package-lock.json` updates to include the three new packages under `devDependencies`.

- [ ] **Step 3: Commit**

```bash
cd diviops-server && git add package.json package-lock.json
git commit -m "chore: add @modelcontextprotocol/{client,core,server}@2.0.0 as devDependencies

Dev-time only, not shipped in the npm tarball. Cost is near-zero; having
this available means PR 3's CanonicalToolRegistry multi-target
verification test doesn't need its own dependency-bump PR later. Does
NOT imply building a v2 target now — that stays #131-scoped."
```

---

### Task 9: Full regression suite + final verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full PHP test suite**

Run: `php tests/run.php`
Expected: same pass count as `main` before this PR started (zero regressions). If this is your first time running it in this session, note the baseline pass count first by checking out `main` and running it, then compare.

- [ ] **Step 2: Run the diviops-server test suites**

Run: `cd diviops-server && npm run test:server-security && npm run test:regen-skill && npm test`
Expected: all pass, including the new `wp-client-cancellation.test.ts` from Task 7.

- [ ] **Step 3: Run the #41/#128 build+startup regression test**

Run: `cd diviops-server && node --test scripts/verify-server-builds-and-starts.test.mjs`
Expected: all 3 assertions pass (build succeeds, cross-env-preflight files present, server reaches the missing-credentials refusal).

- [ ] **Step 4: File the tracking issue and open the PR**

This plan's work should land as a single PR closing a new issue titled along the lines of "adopt upstream's safe registry refactors + handshake fields (ba008d2 sync, part 1 of 3)", referencing the design spec at `docs/superpowers/specs/2026-08-02-upstream-sync-reconciliation-design.md`. Branch name: `dev/<issue-number>-upstream-sync-safe-refactors`, per this repo's `dev/<issue>-<slug>` convention.
