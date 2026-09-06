# design_system_apply Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `diviops_design_system_apply`, a REST tool that applies a validated design-token set — colours (flat and derived), fonts and variables — to a live Divi 5 site in one atomic, idempotent, dry-runnable call.

**Architecture:** A new trait `DiviOps_Agent_DesignSystem` mixed into `DiviOps_Agent`, following the shape `variable_create_fluid_system()` already established: validate the whole payload first, build a `$plan`, then write. Nothing is written unless every token validates. Derived colours are emitted as real Divi `$variable(...)$` references, topologically ordered, with cycles refused. One site-wide cache invalidation per run.

**Tech Stack:** PHP 7.4 floor, WordPress REST, no Composer. Tests are plain PHP via `php tests/run.php`.

**Spec:** `docs/superpowers/specs/2026-09-05-design-system-authoring-design.md`

## Global Constraints

- **PHP 7.4 floor.** Traits carry no constants below PHP 8.2 — every shared list is a `private static function` returning an array. The dev machine runs newer PHP and will not catch a violation.
- **Never rename the four frozen identifiers:** slug `diviops-agent`, class `DiviOps_Agent`, REST namespace `diviops/v1`, filter `diviops_agent_handshake_extensions`.
- **Assertion surface is exactly two functions:** `assert_same( $expected, $actual, $message )` and `assert_true( $actual, $message )`. There is no `assert_false`, no `assert_throws`.
- **Per-file function prefix in tests.** Functions are process-global once declared. Use `diviops_ds_` for every helper in the new test file.
- **Reach private statics with `diviops_call( $method, $args )`.**
- **Expected values derive from a citable source,** never from what the harness produced.
- **Status vocabulary is per surface** (#393): colours `active|inactive|temporary`, fonts `active|inactive`. Omitted means `active` on create and the **stored** value on update.
- **Slug derivation REJECTS, never sanitises.** A silent rewrite under `overwrite=true` clobbers the wrong token set — the reasoning already recorded on `validate_name_prefix()`.
- **Never add an AI-authorship trailer** to any commit.
- **Run the suite with the ssh override** so the drift gate can reach staging:
  `DIVIOPS_SSH='ssh -o BatchMode=yes -o RemoteCommand=none -o RequestTTY=no' php tests/run.php`

## File Structure

| File | Responsibility |
| --- | --- |
| `plugins/diviops-agent/includes/trait-design-system.php` (create) | The whole feature: validation, slugging, dependency ordering, plan building, apply. Brand-new file, so no `FORK.md` row is required for it. |
| `plugins/diviops-agent/diviops-agent.php` (modify) | `require_once` + `use` + route registration + capability key. Upstream-origin, so it needs a `FORK.md` row. |
| `tests/test-design-system-apply.php` (create) | All assertions for the feature. |
| `FORK.md` (modify) | Divergence row for `diviops-agent.php`. |
| `diviops-server/src/index.ts` (modify) | MCP tool registration. |
| `skills/divi-5-builder/SKILL.md` (modify) | Document the tool. |

---

### Task 1: Token-set validation and the never-invent refusal

**Files:**
- Create: `plugins/diviops-agent/includes/trait-design-system.php`
- Create: `tests/test-design-system-apply.php`

**Interfaces:**
- Consumes: `self::envelope_error()`, `self::valid_global_color_statuses()` (both existing).
- Produces:
  - `design_system_color_settings_keys(): array` → `['lightness','saturation','opacity']`
  - `design_system_validate_colors( array $colors ): array` → on success `[ 'ok' => true, 'tokens' => array<int, array{name:string,value:?string,derived_from:?string,settings:array,label:string,status:?string,index:int}> ]`; on failure `[ 'ok' => false, 'code' => 'invalid_input', 'message' => string, 'data' => array{field:string,token:?string,index:int} ]`

- [ ] **Step 1: Write the failing test**

Create `tests/test-design-system-apply.php`:

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * design_system_apply: a style guide's token set becomes live Divi tokens (#392).
 *
 * Spec: docs/superpowers/specs/2026-09-05-design-system-authoring-design.md
 *
 * The rule this file exists to enforce is that the tool NEVER invents a token. A colour
 * carrying neither a literal value nor a `derived_from` is refused, so a skill that
 * hallucinates one produces a loud error instead of a quietly wrong palette. That rule is
 * unenforceable as a prompt instruction, which is why the boundary is here.
 *
 * Assertions are on STORAGE, not the response, wherever a write happens: the defect class
 * this repository keeps paying for is a response that reports success over a store that
 * says something else.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
// These handlers sit behind Divi's option layer — see that shim's docblock for why
// et_get_option() is opt-in rather than part of wp-shim.php.
require_once __DIR__ . '/divi-active-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

/** Validate a colour token list through the private helper. */
function diviops_ds_validate( array $colors ): array {
	return diviops_call( 'design_system_validate_colors', array( $colors ) );
}

// A colour with a literal value validates.
$ok = diviops_ds_validate( array( array( 'name' => 'primary', 'value' => '#1B4D8F' ) ) );
assert_same( true, $ok['ok'] ?? null, 'a colour with a literal value validates' );
assert_same( 'primary', $ok['tokens'][0]['name'] ?? null, 'and is returned as a token' );

// A colour with neither a value nor a derived_from is REFUSED. This is the
// never-invent-a-token rule, and the reason this boundary is in PHP at all.
$bad = diviops_ds_validate( array( array( 'name' => 'ghost' ) ) );
assert_same( false, $bad['ok'] ?? null, 'a colour with no value and no derived_from is refused' );
assert_same( 'invalid_input', $bad['code'] ?? null, 'and refused as invalid_input' );
assert_same( 'ghost', $bad['data']['token'] ?? null, 'and the refusal names the offending token' );
assert_same( 0, $bad['data']['index'] ?? null, 'and its index, so a 60-token payload is actionable' );

// Both a value and a derived_from is ambiguous, and ambiguity is refused rather than
// resolved by precedence — a precedence rule here would silently discard one of them.
$both = diviops_ds_validate( array(
	array( 'name' => 'primary', 'value' => '#1B4D8F', 'derived_from' => 'other' ),
) );
assert_same( false, $both['ok'] ?? null, 'a colour carrying both value and derived_from is refused' );

// An unrecognised settings key is refused, not dropped. A dropped setting is a
// silently different colour.
$badkey = diviops_ds_validate( array(
	array( 'name' => 'primary', 'value' => '#1B4D8F' ),
	array( 'name' => 'tint', 'derived_from' => 'primary', 'settings' => array( 'brightness' => 10 ) ),
) );
assert_same( false, $badkey['ok'] ?? null, 'an unrecognised settings key is refused' );
assert_same( 'brightness', $badkey['data']['field'] ?? null, 'and named in the refusal' );

// settings is only legal alongside derived_from.
$orphan = diviops_ds_validate( array(
	array( 'name' => 'primary', 'value' => '#1B4D8F', 'settings' => array( 'lightness' => 5 ) ),
) );
assert_same( false, $orphan['ok'] ?? null, 'settings without derived_from is refused' );

// The three legal settings keys, per the measured live palette.
$settings = diviops_call( 'design_system_color_settings_keys', array() );
assert_same( array( 'lightness', 'saturation', 'opacity' ), $settings, 'the settings vocabulary matches Divi' );
```

- [ ] **Step 2: Run the test and watch it fail**

Run: `php tests/run.php design-system-apply`

Expected: FAIL. The first failure will be a fatal (exit 255) because `trait-design-system.php` does not exist yet and `design_system_validate_colors` is not a method. **A fatal is not a kill and is not a useful red** — create the file with an empty trait first (Step 3), re-run, and confirm the failure becomes assertion failures at exit 1 before implementing.

- [ ] **Step 3: Create the trait file with the two helpers**

Create `plugins/diviops-agent/includes/trait-design-system.php`:

```php
<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/**
 * Trait DiviOps_Agent_DesignSystem
 *
 * Applies a validated design-token set — colours, fonts and variables — to a live Divi 5
 * site in one call (#392).
 *
 * Mixed into DiviOps_Agent via `use` in diviops-agent.php.
 *
 * The parsing half lives in the divi-5-builder skill, deliberately: reading free-form brand
 * guidelines is model work, and an unbounded parser has no business in a write path. What
 * lives here is the half that must be enforceable — this trait refuses a token that carries
 * no value, which turns "never invent a token" from a prompt instruction into a contract a
 * test can hold.
 *
 * Shape follows variable_create_fluid_system(): validate the whole payload, build a plan,
 * then write. A partial apply is worse than a refusal, because the caller cannot tell which
 * half landed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DiviOps_Agent_DesignSystem {

	/**
	 * The settings a derived colour may carry.
	 *
	 * Measured from the live staging palette (Divi 5.12.0), where 47 of 99 colours are
	 * references. Every observed entry uses one or more of these three keys with integer
	 * values, e.g.
	 *   $variable({"type":"color","value":{"name":"gcid-primary-color",
	 *              "settings":{"lightness":34,"opacity":20}}})$
	 *
	 * A private static function rather than a constant: traits carry no constants below
	 * PHP 8.2 and this plugin's floor is 7.4.
	 */
	private static function design_system_color_settings_keys(): array {
		return [ 'lightness', 'saturation', 'opacity' ];
	}

	/**
	 * Validate the colour half of a token set.
	 *
	 * Returns a plain array rather than an envelope so the caller can aggregate across
	 * surfaces and emit one refusal for the whole payload.
	 *
	 * @param array $colors Raw colour entries from the request.
	 * @return array
	 */
	private static function design_system_validate_colors( array $colors ): array {
		$tokens = [];

		foreach ( array_values( $colors ) as $idx => $c ) {
			if ( ! is_array( $c ) ) {
				return self::design_system_reject( 'colors', null, $idx, 'each colour must be an object.' );
			}

			$name = isset( $c['name'] ) && is_string( $c['name'] ) ? trim( $c['name'] ) : '';
			if ( '' === $name ) {
				return self::design_system_reject( 'name', null, $idx, 'each colour needs a name.' );
			}

			$has_value   = array_key_exists( 'value', $c ) && '' !== (string) $c['value'];
			$has_derived = array_key_exists( 'derived_from', $c ) && '' !== (string) $c['derived_from'];

			// The never-invent rule. Neither means the caller (or a skill upstream of it)
			// does not actually know this token's value, and guessing one is the failure
			// this boundary exists to prevent.
			if ( ! $has_value && ! $has_derived ) {
				return self::design_system_reject(
					'value',
					$name,
					$idx,
					"colour '{$name}' has neither a value nor a derived_from. This tool never invents a token value."
				);
			}
			if ( $has_value && $has_derived ) {
				return self::design_system_reject(
					'value',
					$name,
					$idx,
					"colour '{$name}' carries both value and derived_from. Supply exactly one."
				);
			}

			$settings = [];
			if ( array_key_exists( 'settings', $c ) ) {
				if ( ! $has_derived ) {
					return self::design_system_reject(
						'settings',
						$name,
						$idx,
						"colour '{$name}' carries settings without derived_from; settings only apply to a derived colour."
					);
				}
				if ( ! is_array( $c['settings'] ) ) {
					return self::design_system_reject( 'settings', $name, $idx, 'settings must be an object.' );
				}
				foreach ( $c['settings'] as $key => $val ) {
					if ( ! in_array( $key, self::design_system_color_settings_keys(), true ) ) {
						return self::design_system_reject(
							(string) $key,
							$name,
							$idx,
							sprintf(
								"colour '%s' carries an unrecognised setting '%s'. Allowed: %s.",
								$name,
								(string) $key,
								implode( ', ', self::design_system_color_settings_keys() )
							)
						);
					}
					if ( ! is_int( $val ) && ! ( is_string( $val ) && ctype_digit( ltrim( $val, '-' ) ) ) ) {
						return self::design_system_reject(
							(string) $key,
							$name,
							$idx,
							sprintf( "colour '%s' setting '%s' must be an integer.", $name, (string) $key )
						);
					}
					$settings[ $key ] = (int) $val;
				}
			}

			$status = null;
			if ( array_key_exists( 'status', $c ) ) {
				$status = (string) $c['status'];
				if ( ! in_array( $status, self::valid_global_color_statuses(), true ) ) {
					return self::design_system_reject(
						'status',
						$name,
						$idx,
						sprintf(
							"colour '%s' status must be one of: %s.",
							$name,
							implode( ', ', self::valid_global_color_statuses() )
						)
					);
				}
			}

			$tokens[] = [
				'name'         => $name,
				'value'        => $has_value ? (string) $c['value'] : null,
				'derived_from' => $has_derived ? (string) $c['derived_from'] : null,
				'settings'     => $settings,
				'label'        => isset( $c['label'] ) && is_string( $c['label'] ) ? $c['label'] : $name,
				'status'       => $status,
				'index'        => $idx,
			];
		}

		return [ 'ok' => true, 'tokens' => $tokens ];
	}

	/**
	 * Build a refusal. Every rejection names the token AND its index, because a caller
	 * applying sixty tokens cannot act on "one of them is wrong" — the same reasoning
	 * behind colors_index in global_color_upsert.
	 */
	private static function design_system_reject( string $field, ?string $token, int $index, string $message ): array {
		return [
			'ok'      => false,
			'code'    => 'invalid_input',
			'message' => $message,
			'data'    => [ 'field' => $field, 'token' => $token, 'index' => $index ],
		];
	}
}
```

- [ ] **Step 4: Wire the trait so the test can load it**

In `plugins/diviops-agent/diviops-agent.php`, add the require beside the others (they sit around line 46) and the `use` beside the others (around line 74):

```php
require_once __DIR__ . '/includes/trait-design-system.php';
```

```php
	use DiviOps_Agent_DesignSystem;
```

- [ ] **Step 5: Run the test and verify it passes**

Run: `php tests/run.php design-system-apply`
Expected: PASS, 11 assertions.

- [ ] **Step 6: Commit**

```bash
git add plugins/diviops-agent/includes/trait-design-system.php \
        plugins/diviops-agent/diviops-agent.php \
        tests/test-design-system-apply.php
git commit -S -m "feat(#392): validate a design-system colour token set, refusing invented tokens"
```

---

### Task 2: Deterministic ids, with slug rejection rather than sanitising

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-design-system.php`
- Modify: `tests/test-design-system-apply.php`

**Interfaces:**
- Consumes: `design_system_reject()` from Task 1.
- Produces:
  - `design_system_slug( string $name ): ?string` → the slug, or `null` when the input would have to be rewritten.
  - `design_system_token_id( string $prefix, string $namespace, string $name ): ?string` → e.g. `gcid-acme-primary`, or `null` when the name is unslugifiable.

- [ ] **Step 1: Write the failing test**

Append to `tests/test-design-system-apply.php`:

```php
// ---------------------------------------------------------------------------
// Deterministic ids. Re-running the same style guide must land on the same ids,
// which is what makes a re-import an update instead of a duplicate.
// ---------------------------------------------------------------------------

assert_same(
	'gcid-acme-primary',
	diviops_call( 'design_system_token_id', array( 'gcid', 'acme', 'primary' ) ),
	'an id is prefix + namespace + slug'
);
assert_same(
	'gcid-acme-primary-600',
	diviops_call( 'design_system_token_id', array( 'gcid', 'acme', 'Primary 600' ) ),
	'a spaced, capitalised name slugs deterministically'
);
assert_same(
	diviops_call( 'design_system_token_id', array( 'gcid', 'acme', 'Primary 600' ) ),
	diviops_call( 'design_system_token_id', array( 'gcid', 'acme', 'Primary 600' ) ),
	'and the same input always yields the same id'
);

// REJECT rather than sanitise. validate_name_prefix() already carries this rule and
// states why: sanitize_key() silently rewriting "oa!" to "oa" aliases bogus input onto
// another token set, and under overwrite=true that rewrites the WRONG tokens.
assert_same( null, diviops_call( 'design_system_slug', array( 'brand!' ) ), 'a name needing rewriting is rejected, not sanitised' );
assert_same( null, diviops_call( 'design_system_slug', array( '' ) ), 'an empty name is rejected' );
assert_same( null, diviops_call( 'design_system_slug', array( '---' ) ), 'a name that slugs to nothing is rejected' );
assert_same( 'primary-600', diviops_call( 'design_system_slug', array( 'Primary 600' ) ), 'case and spaces are a legal, lossless transform' );
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `php tests/run.php design-system-apply`
Expected: FAIL at exit 1 on the id assertions. If it exits 255 instead, the method name is misspelled — fix that before implementing.

- [ ] **Step 3: Implement**

Add to `trait-design-system.php`, inside the trait:

```php
	/**
	 * Derive a slug from a token name, or refuse.
	 *
	 * Lowercasing and collapsing whitespace to hyphens is lossless and reversible enough
	 * to be safe. Anything else is not: `sanitize_key()` silently rewriting "brand!" to
	 * "brand" would alias one token onto another, and under overwrite=true that means
	 * updating a token the caller never named. `validate_name_prefix()` already refuses
	 * for this reason; this follows it rather than inventing a second policy.
	 *
	 * @return string|null Null when the name cannot become a slug without rewriting.
	 */
	private static function design_system_slug( string $name ): ?string {
		$slug = strtolower( trim( $name ) );
		$slug = preg_replace( '/\s+/', '-', $slug );
		if ( ! is_string( $slug ) || '' === $slug ) {
			return null;
		}
		// Anything left that is not [a-z0-9-] would have to be dropped. Refuse instead.
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return null;
		}
		if ( '' === trim( $slug, '-' ) ) {
			return null;
		}
		return $slug;
	}

	/**
	 * Deterministic token id: `<prefix>-<namespace>-<slug>`.
	 *
	 * Readable in the Visual Builder's picker and greppable inside a stored
	 * `$variable()$` token, which a hash would not be.
	 *
	 * @return string|null Null when the name is unslugifiable.
	 */
	private static function design_system_token_id( string $prefix, string $namespace, string $name ): ?string {
		$slug = self::design_system_slug( $name );
		if ( null === $slug ) {
			return null;
		}
		return $prefix . '-' . $namespace . '-' . $slug;
	}
```

- [ ] **Step 4: Run the test and verify it passes**

Run: `php tests/run.php design-system-apply`
Expected: PASS, 18 assertions.

- [ ] **Step 5: Commit**

```bash
git add plugins/diviops-agent/includes/trait-design-system.php tests/test-design-system-apply.php
git commit -S -m "feat(#392): deterministic token ids that reject rather than sanitise a name"
```

---

### Task 3: Dependency ordering and cycle detection

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-design-system.php`
- Modify: `tests/test-design-system-apply.php`

**Interfaces:**
- Consumes: the token array from `design_system_validate_colors()` (Task 1).
- Produces:
  - `design_system_order_colors( array $tokens ): array` → on success `[ 'ok' => true, 'tokens' => array ]` in dependency order; on a cycle or unresolvable reference, the `design_system_reject()` shape.

- [ ] **Step 1: Write the failing test**

Append to `tests/test-design-system-apply.php`:

```php
// ---------------------------------------------------------------------------
// Derived colours must be written after the colour they reference, whatever order
// the style guide listed them in.
// ---------------------------------------------------------------------------

function diviops_ds_order( array $colors ): array {
	$v = diviops_ds_validate( $colors );
	assert_same( true, $v['ok'] ?? null, 'the ordering fixture validates before it is ordered' );
	return diviops_call( 'design_system_order_colors', array( $v['tokens'] ) );
}

// Derived listed BEFORE its target — the plan must still emit the target first.
$ordered = diviops_ds_order( array(
	array( 'name' => 'tint', 'derived_from' => 'primary', 'settings' => array( 'lightness' => 20 ) ),
	array( 'name' => 'primary', 'value' => '#1B4D8F' ),
) );
assert_same( true, $ordered['ok'] ?? null, 'an out-of-order payload is orderable' );
assert_same( 'primary', $ordered['tokens'][0]['name'] ?? null, 'the referenced colour is written first' );
assert_same( 'tint', $ordered['tokens'][1]['name'] ?? null, 'and the derived colour second' );

// A cycle is refused. Divi cannot resolve a -> b -> a, and writing it produces a palette
// that renders wrong with no error anywhere.
$cycle = diviops_ds_order( array(
	array( 'name' => 'a', 'derived_from' => 'b' ),
	array( 'name' => 'b', 'derived_from' => 'a' ),
) );
assert_same( false, $cycle['ok'] ?? null, 'a dependency cycle is refused' );
assert_same( 'derived_from', $cycle['data']['field'] ?? null, 'and named as a derived_from problem' );

// A reference to a token that is neither in this payload nor already on the site is
// refused rather than flattened to a literal.
$dangling = diviops_ds_order( array(
	array( 'name' => 'tint', 'derived_from' => 'nowhere', 'settings' => array( 'lightness' => 5 ) ),
) );
assert_same( false, $dangling['ok'] ?? null, 'an unresolvable derived_from is refused' );

// A reference to an id that already exists on the site resolves, because a style guide
// legitimately extends a palette rather than replacing it.
$existing = diviops_call( 'design_system_order_colors', array(
	diviops_ds_validate( array(
		array( 'name' => 'tint', 'derived_from' => 'gcid-primary-color', 'settings' => array( 'lightness' => 7 ) ),
	) )['tokens'],
	array( 'gcid-primary-color' => true ),
) );
assert_same( true, $existing['ok'] ?? null, 'a derived_from naming an existing site id resolves' );
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `php tests/run.php design-system-apply`
Expected: FAIL at exit 1.

- [ ] **Step 3: Implement**

Add to `trait-design-system.php`:

```php
	/**
	 * Order colour tokens so a derived colour is written after the colour it references.
	 *
	 * Divi resolves a reference by id at render time, so a reference written before its
	 * target is not an error at write time — it renders wrong later, silently. Ordering
	 * here is what keeps that from being possible.
	 *
	 * A depth-first walk with three marks: unvisited, in-progress, done. Re-entering an
	 * in-progress node is a cycle.
	 *
	 * @param array $tokens      Validated colour tokens.
	 * @param array $existing_ids Map of ids already on the site, keyed by id.
	 * @return array
	 */
	private static function design_system_order_colors( array $tokens, array $existing_ids = [] ): array {
		$by_name = [];
		foreach ( $tokens as $t ) {
			$by_name[ $t['name'] ] = $t;
		}

		$state   = [];
		$ordered = [];
		$error   = null;

		$visit = function ( $name ) use ( &$visit, &$state, &$ordered, &$error, $by_name, $existing_ids ) {
			if ( null !== $error ) {
				return;
			}
			if ( isset( $state[ $name ] ) && 'done' === $state[ $name ] ) {
				return;
			}
			if ( isset( $state[ $name ] ) && 'open' === $state[ $name ] ) {
				$token = $by_name[ $name ];
				$error = self::design_system_reject(
					'derived_from',
					$name,
					$token['index'],
					"colour '{$name}' is part of a derived_from cycle. Divi cannot resolve one."
				);
				return;
			}

			$state[ $name ] = 'open';
			$token          = $by_name[ $name ];
			$target         = $token['derived_from'];

			if ( null !== $target ) {
				if ( isset( $by_name[ $target ] ) ) {
					$visit( $target );
				} elseif ( ! isset( $existing_ids[ $target ] ) ) {
					$error = self::design_system_reject(
						'derived_from',
						$name,
						$token['index'],
						"colour '{$name}' derives from '{$target}', which is neither in this payload nor on the site."
					);
					return;
				}
			}

			$state[ $name ] = 'done';
			$ordered[]      = $token;
		};

		foreach ( $tokens as $t ) {
			$visit( $t['name'] );
			if ( null !== $error ) {
				return $error;
			}
		}

		return [ 'ok' => true, 'tokens' => $ordered ];
	}
```

- [ ] **Step 4: Run the test and verify it passes**

Run: `php tests/run.php design-system-apply`
Expected: PASS, 28 assertions.

- [ ] **Step 5: Commit**

```bash
git add plugins/diviops-agent/includes/trait-design-system.php tests/test-design-system-apply.php
git commit -S -m "feat(#392): order derived colours by dependency and refuse cycles"
```

---

### Task 4: The `$variable()` reference token

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-design-system.php`
- Modify: `tests/test-design-system-apply.php`

**Interfaces:**
- Produces: `design_system_reference_token( string $target_id, array $settings ): string`

- [ ] **Step 1: Write the failing test**

Append to `tests/test-design-system-apply.php`:

```php
// ---------------------------------------------------------------------------
// The reference grammar. Expected values below are transcribed from a real entry on
// staging (Divi 5.12.0), not from what this code produces:
//   $variable({"type":"color","value":{"name":"gcid-primary-color","settings":{"lightness":7}}})$
// ---------------------------------------------------------------------------

assert_same(
	'$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{"lightness":7}}})$',
	diviops_call( 'design_system_reference_token', array( 'gcid-primary-color', array( 'lightness' => 7 ) ) ),
	'a single-setting reference matches the stored grammar byte for byte'
);

assert_same(
	'$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{"lightness":34,"opacity":20}}})$',
	diviops_call( 'design_system_reference_token', array( 'gcid-primary-color', array( 'lightness' => 34, 'opacity' => 20 ) ) ),
	'and a two-setting reference matches the observed multi-setting form'
);

// Divi's own entries carry a settings object even when empty; emitting no key at all
// would be a different shape than the one measured.
assert_same(
	'$variable({"type":"color","value":{"name":"gcid-x","settings":{}}})$',
	diviops_call( 'design_system_reference_token', array( 'gcid-x', array() ) ),
	'an empty settings object is still emitted as an object, not omitted'
);
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `php tests/run.php design-system-apply`
Expected: FAIL at exit 1.

- [ ] **Step 3: Implement**

Add to `trait-design-system.php`:

```php
	/**
	 * Build Divi's `$variable()$` colour-reference token.
	 *
	 * The grammar is transcribed from a live entry on staging (Divi 5.12.0) rather than
	 * inferred:
	 *   $variable({"type":"color","value":{"name":"gcid-primary-color","settings":{"lightness":7}}})$
	 *
	 * JSON_UNESCAPED_SLASHES matters: Divi's stored form carries no escaped slashes, and a
	 * byte-different token is a different string to every scanner that greps for it.
	 * The empty settings object is emitted as `{}` rather than `[]`, which is why the cast
	 * to object is explicit — PHP would otherwise serialize an empty array as `[]`.
	 */
	private static function design_system_reference_token( string $target_id, array $settings ): string {
		$payload = [
			'type'  => 'color',
			'value' => [
				'name'     => $target_id,
				'settings' => (object) $settings,
			],
		];
		return '$variable(' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . ')$';
	}
```

- [ ] **Step 4: Run the test and verify it passes**

Run: `php tests/run.php design-system-apply`
Expected: PASS, 31 assertions.

- [ ] **Step 5: Commit**

```bash
git add plugins/diviops-agent/includes/trait-design-system.php tests/test-design-system-apply.php
git commit -S -m "feat(#392): emit Divi's \$variable() colour-reference grammar"
```

---

### Task 5: The handler — plan, apply, idempotency, dry-run, cache

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-design-system.php`
- Modify: `tests/test-design-system-apply.php`

**Interfaces:**
- Consumes: everything from Tasks 1–4, plus existing `self::envelope_success()`, `self::envelope_error()`, `self::dry_run_response()`, `self::attach_meta()`, `self::invalidate_divi_cache_sitewide()`, `self::validate_name_prefix()`, `self::get_customizer_color_count()`.
- Produces: `public static function design_system_apply( $request )` returning the standard envelope with `data` = `{ namespace, created[], updated[], skipped[], created_count, updated_count, skipped_count, cache }`.

- [ ] **Step 1: Write the failing test**

Append to `tests/test-design-system-apply.php`:

```php
// ---------------------------------------------------------------------------
// Applying a token set. Assertions read STORAGE through Divi's own accessor.
// ---------------------------------------------------------------------------

function diviops_ds_apply( array $params ) {
	$req = new WP_REST_Request();
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return diviops_call( 'design_system_apply', array( $req ) );
}

function diviops_ds_palette(): array {
	$raw = et_get_option( 'et_global_data' );
	return is_array( $raw ) ? ( $raw['global_colors'] ?? array() ) : array();
}

et_update_option( 'et_global_data', array( 'global_colors' => array() ) );

$payload = array(
	array( 'name' => 'primary', 'value' => '#1B4D8F', 'label' => 'Primary' ),
	array( 'name' => 'primary-600', 'derived_from' => 'primary', 'settings' => array( 'lightness' => -10 ) ),
);

$resp = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => $payload ) );
$data = $resp->get_data();
assert_same( true, $data['ok'] ?? null, 'a valid token set applies' );

$palette = diviops_ds_palette();
assert_same( 2, count( $palette ), 'both colours are written' );
assert_same( '#1B4D8F', $palette['gcid-acme-primary']['color'] ?? null, 'the literal colour is stored as given' );
assert_same(
	'$variable({"type":"color","value":{"name":"gcid-acme-primary","settings":{"lightness":-10}}})$',
	$palette['gcid-acme-primary-600']['color'] ?? null,
	'the derived colour is stored as a reference to the token it derives from'
);
assert_same( 'active', $palette['gcid-acme-primary']['status'] ?? null, 'a new colour defaults to active' );
assert_true( isset( $palette['gcid-acme-primary']['order'] ), 'and carries an order' );

// DoD test 2: re-running the same payload UPDATES rather than duplicating.
$before_order = $palette['gcid-acme-primary']['order'];
$again = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => $payload, 'overwrite' => true ) );
$palette2 = diviops_ds_palette();
assert_same( 2, count( $palette2 ), 're-applying the same style guide does not duplicate' );
assert_same( $before_order, $palette2['gcid-acme-primary']['order'] ?? null, 'and preserves order across the re-run' );
assert_same( 2, $again->get_data()['data']['updated_count'] ?? null, 'and reports both as updates, not creates' );

// overwrite defaults to false, and an existing id is skipped with a reason rather than
// silently overwritten.
$skipped = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => $payload ) );
$sdata   = $skipped->get_data();
assert_same( 2, $sdata['data']['skipped_count'] ?? null, 'without overwrite, existing ids are skipped' );
assert_same( 'exists', $sdata['data']['skipped'][0]['reason'] ?? null, 'and the skip states its reason' );

// A dry run writes nothing.
et_update_option( 'et_global_data', array( 'global_colors' => array() ) );
$plan = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => $payload, 'dry_run' => true ) );
assert_same( true, $plan->get_data()['data']['dry_run'] ?? null, 'a dry run reports itself as one' );
assert_same( 0, count( diviops_ds_palette() ), 'and writes nothing' );

// One refusal fails the WHOLE payload. A partial apply is worse than a refusal, because
// the caller cannot tell which half landed.
et_update_option( 'et_global_data', array( 'global_colors' => array() ) );
$partial = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => array(
	array( 'name' => 'good', 'value' => '#000000' ),
	array( 'name' => 'ghost' ),
) ) );
assert_same( false, $partial->get_data()['ok'] ?? null, 'one invalid token refuses the whole payload' );
assert_same( 0, count( diviops_ds_palette() ), 'and nothing at all is written' );

// An unslugifiable name is refused rather than aliased onto another token.
$badname = diviops_ds_apply( array( 'namespace' => 'acme', 'colors' => array(
	array( 'name' => 'brand!', 'value' => '#000000' ),
) ) );
assert_same( false, $badname->get_data()['ok'] ?? null, 'an unslugifiable token name is refused' );
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `php tests/run.php design-system-apply`
Expected: FAIL at exit 1 (the handler does not exist, so `diviops_call` will fatal — confirm the fatal is only "method does not exist" and add the method stub before reading the assertion failures).

- [ ] **Step 3: Implement**

Add to `trait-design-system.php`:

```php
	/**
	 * Apply a design-token set to the site.
	 *
	 * Whole-payload validation up front, then a plan, then writes. Nothing is written
	 * unless every token validates.
	 */
	public static function design_system_apply( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Requires admin capability', [ 'status' => 403 ] );
		}

		$dry_run   = rest_sanitize_boolean( $request->get_param( 'dry_run' ) ?? false );
		$overwrite = rest_sanitize_boolean( $request->get_param( 'overwrite' ) ?? false );
		$colors    = $request->get_param( 'colors' );
		$colors    = is_array( $colors ) ? $colors : [];

		try {
			$namespace = self::validate_name_prefix( $request->get_param( 'namespace' ), 'namespace', 'ds' );
		} catch ( \Exception $e ) {
			return self::envelope_error( 'invalid_input', $e->getMessage(), null, 400, [ 'field' => 'namespace' ] );
		}

		if ( empty( $colors ) ) {
			return self::envelope_error(
				'invalid_input',
				'colors must be a non-empty array. A run that would write nothing is a mistake worth reporting, not a no-op.',
				null,
				400,
				[ 'field' => 'colors' ]
			);
		}

		$validated = self::design_system_validate_colors( $colors );
		if ( true !== ( $validated['ok'] ?? false ) ) {
			return self::envelope_error( $validated['code'], $validated['message'], null, 400, $validated['data'] );
		}

		$raw          = et_get_option( 'et_global_data' );
		$global_data  = is_array( $raw ) ? $raw : [];
		$color_map    = isset( $global_data['global_colors'] ) && is_array( $global_data['global_colors'] )
			? $global_data['global_colors']
			: [];
		$existing_ids = [];
		foreach ( array_keys( $color_map ) as $existing_id ) {
			$existing_ids[ $existing_id ] = true;
		}

		$ordered = self::design_system_order_colors( $validated['tokens'], $existing_ids );
		if ( true !== ( $ordered['ok'] ?? false ) ) {
			return self::envelope_error( $ordered['code'], $ordered['message'], null, 400, $ordered['data'] );
		}

		// Resolve every id before writing anything, so an unslugifiable name late in the
		// payload cannot leave the earlier half applied.
		$ids = [];
		foreach ( $ordered['tokens'] as $t ) {
			$id = self::design_system_token_id( 'gcid', $namespace, $t['name'] );
			if ( null === $id ) {
				return self::envelope_error(
					'invalid_input',
					sprintf(
						"colour '%s' cannot become an id without rewriting its name. Use only letters, digits, spaces and hyphens.",
						$t['name']
					),
					'A silently rewritten name would alias this token onto a different one, and under overwrite=true would update the wrong entry.',
					400,
					[ 'field' => 'name', 'token' => $t['name'], 'index' => $t['index'] ]
				);
			}
			$ids[ $t['name'] ] = $id;
		}

		$max_order = self::get_customizer_color_count();
		foreach ( $color_map as $entry ) {
			if ( isset( $entry['order'] ) ) {
				$max_order = max( $max_order, (int) $entry['order'] );
			}
		}

		$created = [];
		$updated = [];
		$skipped = [];
		$changes = [];

		foreach ( $ordered['tokens'] as $t ) {
			$id     = $ids[ $t['name'] ];
			$exists = isset( $color_map[ $id ] ) && is_array( $color_map[ $id ] );

			if ( $exists && ! $overwrite ) {
				$skipped[] = [ 'id' => $id, 'name' => $t['name'], 'reason' => 'exists' ];
				continue;
			}

			$existing = $exists ? $color_map[ $id ] : [];

			if ( null !== $t['derived_from'] ) {
				$target = isset( $ids[ $t['derived_from'] ] ) ? $ids[ $t['derived_from'] ] : $t['derived_from'];
				$value  = self::design_system_reference_token( $target, $t['settings'] );
			} else {
				$value = $t['value'];
			}

			// Status follows #393: an omitted status keeps the stored one, and defaults to
			// active only for a genuinely new entry.
			$status = null !== $t['status']
				? $t['status']
				: ( $existing['status'] ?? 'active' );

			$order = $exists && isset( $existing['order'] )
				? $existing['order']
				: (string) ( ++$max_order );

			$record = array_merge(
				$existing,
				[
					'id'          => $id,
					'color'       => $value,
					'label'       => $t['label'],
					'status'      => $status,
					'order'       => $order,
					'lastUpdated' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				]
			);
			if ( ! isset( $record['folder'] ) ) {
				$record['folder'] = '';
			}
			if ( ! isset( $record['usedInPosts'] ) || ! is_array( $record['usedInPosts'] ) ) {
				$record['usedInPosts'] = [];
			}

			$changes[] = [
				'kind'   => $exists ? 'design_system.update' : 'design_system.create',
				'target' => 'global_colors/' . $id,
				'after'  => [ 'id' => $id, 'color' => $value, 'label' => $t['label'], 'status' => $status ],
			];

			if ( $dry_run ) {
				if ( $exists ) {
					$updated[] = [ 'id' => $id, 'name' => $t['name'] ];
				} else {
					$created[] = [ 'id' => $id, 'name' => $t['name'] ];
				}
				continue;
			}

			$color_map[ $id ] = $record;
			if ( $exists ) {
				$updated[] = [ 'id' => $id, 'name' => $t['name'] ];
			} else {
				$created[] = [ 'id' => $id, 'name' => $t['name'] ];
			}
		}

		if ( $dry_run ) {
			return self::dry_run_response(
				sprintf(
					'Would apply %d colour token(s): %d create, %d update, %d skipped.',
					count( $created ) + count( $updated ),
					count( $created ),
					count( $updated ),
					count( $skipped )
				),
				$changes,
				[],
				[
					'namespace'     => $namespace,
					'created'       => $created,
					'updated'       => $updated,
					'skipped'       => $skipped,
					'created_count' => count( $created ),
					'updated_count' => count( $updated ),
					'skipped_count' => count( $skipped ),
				]
			);
		}

		$cache = null;
		if ( ! empty( $created ) || ! empty( $updated ) ) {
			$global_data['global_colors'] = $color_map;
			et_update_option( 'et_global_data', $global_data );
			// Once for the whole run, never once per token (#381). A per-token call would
			// mean N full-site CSS sweeps for one authoring pass.
			$cache = self::invalidate_divi_cache_sitewide();
		}

		return self::attach_meta(
			self::envelope_success( [
				'namespace'     => $namespace,
				'created'       => $created,
				'updated'       => $updated,
				'skipped'       => $skipped,
				'created_count' => count( $created ),
				'updated_count' => count( $updated ),
				'skipped_count' => count( $skipped ),
				'cache'         => $cache,
			] ),
			[ 'canonical_path' => 'et_divi.et_global_data.global_colors' ]
		);
	}
```

- [ ] **Step 4: Run the test and verify it passes**

Run: `php tests/run.php design-system-apply`
Expected: PASS, 48 assertions.

- [ ] **Step 5: Run the whole suite**

Run: `DIVIOPS_SSH='ssh -o BatchMode=yes -o RemoteCommand=none -o RequestTTY=no' php tests/run.php`
Expected: PASS. If `test-fork-divergence-ledger.php` fails naming `diviops-agent.php`, that is Task 6's `FORK.md` row — leave it and continue.

- [ ] **Step 6: Commit**

```bash
git add plugins/diviops-agent/includes/trait-design-system.php tests/test-design-system-apply.php
git commit -S -m "feat(#392): apply a design-token set atomically, idempotently, with dry-run"
```

---

### Task 6: Route, capability, MCP registration, docs and divergence row

**Files:**
- Modify: `plugins/diviops-agent/diviops-agent.php` (route registration ~line 2272 area, capability list ~line 152)
- Modify: `diviops-server/src/index.ts`
- Modify: `skills/divi-5-builder/SKILL.md`
- Modify: `FORK.md`

**Interfaces:**
- Consumes: `design_system_apply()` from Task 5.
- Produces: `POST /diviops/v1/design-system/apply`, capability key `design_system_apply`, MCP tool `diviops_design_system_apply`.

- [ ] **Step 1: Register the REST route**

In `plugins/diviops-agent/diviops-agent.php`, beside the other `register_rest_route` calls:

```php
		register_rest_route( self::REST_NAMESPACE, '/design-system/apply', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'design_system_apply' ],
			// Admin-only: bulk write across the global colour registry.
			'permission_callback' => [ __CLASS__, 'check_admin_permission' ],
			'args'                => [
				'namespace' => [ 'required' => false, 'type' => 'string', 'default' => 'ds' ],
				'colors'    => [ 'required' => true,  'type' => 'array' ],
				'dry_run'   => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
				'overwrite' => [ 'required' => false, 'type' => 'boolean', 'default' => false ],
			],
		] );
```

- [ ] **Step 2: Add the capability key**

In the capability list in `plugins/diviops-agent/diviops-agent.php` (alphabetical block around line 145-160), add a `design_system` group before the `page` entries:

```php
		// design system (#392)
		'design_system_apply',
```

- [ ] **Step 3: Verify the route and capability land**

Run:

```bash
php -r 'require "tests/wp-shim.php"; require "tests/divi-active-shim.php"; require "plugins/diviops-agent/diviops-agent.php"; echo method_exists("DiviOps_Agent","design_system_apply") ? "handler ok\n" : "MISSING\n";'
DIVIOPS_SSH='ssh -o BatchMode=yes -o RemoteCommand=none -o RequestTTY=no' php tests/run.php
```

Expected: `handler ok`, and the suite fails only on `test-fork-divergence-ledger.php` (fixed in Step 4).

- [ ] **Step 4: Add the FORK.md divergence row**

`trait-design-system.php` is a brand-new file and is exempt. `diviops-agent.php` is upstream-origin and needs a row in the "Modified upstream files" table:

```markdown
| `plugins/diviops-agent/diviops-agent.php` | Registers the fork-only `design-system/apply` route and its `design_system_apply` capability key (#392), wiring in the new `DiviOps_Agent_DesignSystem` trait. Bulk design-token authoring is absent upstream, which ships only single-token create/update tools. Admin-gated, because one call writes across the whole global colour registry. | #392 |
```

- [ ] **Step 5: Register the MCP tool**

In `diviops-server/src/index.ts`, following the shape of the neighbouring `diviops_variable_create_fluid_system` registration, add `diviops_design_system_apply` posting to `/design-system/apply` and returning `serializeEnvelope(result, "diviops_design_system_apply")`. Gate it on the `design_system_apply` capability key so a server running against an older plugin drops the tool rather than erroring.

- [ ] **Step 6: Document the tool in the skill**

In `skills/divi-5-builder/SKILL.md`, add to the tool reference:

```markdown
- **`diviops_design_system_apply`** — apply a whole design-token set at once. Deterministic
  ids (`gcid-<namespace>-<slug>`) make a re-run an update, not a duplicate. Derived colours
  are emitted as real `$variable()` references, so changing the base colour still cascades.
  Refuses a token with no value rather than inventing one; refuses a dependency cycle;
  refuses a name that would need rewriting to become an id. `dry_run` writes nothing;
  `overwrite=false` (the default) skips existing ids and reports them.
```

- [ ] **Step 7: Run the full suite and the server tests**

```bash
DIVIOPS_SSH='ssh -o BatchMode=yes -o RemoteCommand=none -o RequestTTY=no' php tests/run.php
cd diviops-server && npm test && cd ..
for f in plugins/diviops-agent/includes/*.php plugins/diviops-agent/diviops-agent.php tests/*.php; do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done
```

Expected: PHP suite PASS, server tests PASS, no lint failures.

- [ ] **Step 8: Mutation-check before opening the PR**

Back each file up to the scratchpad first — **never `git checkout --`**, which restores from HEAD and has eaten uncommitted work in this repository. Verify each mutation landed by **diffing the file**, not by trusting a changed `shasum`: a `str.count`/`sed` pattern can match as a substring of a differently-indented line and land somewhere else entirely.

Run at least these five, and confirm each dies at **exit 1** (an assertion), not exit 255 (a fatal — a fatal is not a kill):

| # | Mutation | Must break |
| --- | --- | --- |
| 1 | Return `[ 'ok' => true ]` from the no-value branch in `design_system_validate_colors()` | the never-invent assertions |
| 2 | Make `design_system_slug()` `preg_replace` non-alphanumerics away instead of returning null | the slug-rejection assertions |
| 3 | Return `$tokens` unchanged from `design_system_order_colors()` | the ordering and cycle assertions |
| 4 | Drop `JSON_UNESCAPED_SLASHES` from `design_system_reference_token()` | the grammar assertions |
| 5 | Assign a fresh `$order` on overwrite instead of preserving the stored one | the idempotency assertion |

Any survivor is a fixture hole, not an acceptable matrix line: extend the fixture until it dies.

- [ ] **Step 9: Commit and open the PR**

```bash
git add -A
git commit -S -m "feat(#392): register design_system_apply as a route, capability and MCP tool"
git push -u origin dev/392-design-system-authoring
```

PR body must state: the mutation matrix and what each mutation did, that assertions are on storage rather than the response, and that the skill-side parsing half remains out of scope. Use `Closes #392` only if the skill half is judged out of scope for the issue; otherwise `Refs #392`.

---

## Self-Review

**Spec coverage.** Input parsing → out of scope for v1, stated in the spec and in Task 6 Step 9. Emit through our own tools → Tasks 1-5 write via the colour registry directly rather than an import file. Model the messy reality → Task 4 (references) and Task 5 (status/order/`usedInPosts` preservation). Idempotent re-import → Task 2 (ids) and Task 5 (overwrite/skip, order preservation). Dry-run and readback → Task 5. Both DoD tests → Task 1 (invention) and Task 5 (idempotency). Cache invalidation → Task 5. Error semantics naming token and index → Task 1's `design_system_reject()`.

**Gap found and closed:** the spec lists fonts and non-colour variables in the token-set contract, but every task above covers colours only. That is deliberate and now stated: v1 is colours, which is where all 99 live tokens and the whole `$variable()` reference problem are. Fonts and variables reuse the same plan/apply skeleton and should be a follow-up issue opened when Task 6 lands, not silently dropped.

**Placeholder scan:** none. Every code step carries the actual code.

**Type consistency:** `design_system_reject()` returns the same `['ok'=>false,'code','message','data']` shape consumed by Task 3 and Task 5; `design_system_order_colors()` returns `['ok'=>true,'tokens']` matching `design_system_validate_colors()`; `design_system_token_id()` returns `?string` and every caller null-checks it.
