# Run-Scoped Rollback Snapshots Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let `preset_reassign` apply up to 1000 pages with every page restorable, by storing one snapshot record per chunk of pages instead of one per page.

**Architecture:** A new `schema_version => 2` snapshot record holds a `targets` array of per-page entries, each with its own `before` and `after` blocks. Runs write `ceil(pages / 100)` such records. v2 records deliberately fail `rollback_snapshot_normalize_record()`'s positive-`target.id` check, which keeps them out of every reader that treats a null normalization as "skip"; `rollback_snapshot_managed_inventory()` — DiviOps Agent Pro's seam — does **not** do that, so it carries an explicit guard as well. All v2 capability is served by additive, version-aware code paths.

**Tech Stack:** PHP 7.4+ (floor enforced by `tests/test-php-version-floor-check.php`), WordPress options API, no Composer/PHPUnit — the harness is `php tests/run.php`. TypeScript for the MCP server surface (`diviops-server/src`).

**Spec:** `docs/superpowers/specs/2026-08-18-run-scoped-rollback-snapshots-design.md`

## Global Constraints

- **Never rename** plugin slug `diviops-agent`, class `DiviOps_Agent`, REST namespace `diviops/v1`, or filter `diviops_agent_handshake_extensions`. Pro attaches through the class and the filter; the published `@rubicontv/diviops-mcp` calls `/diviops/v1/*`.
- **A v2 record MUST be absent from `rollback_snapshot_managed_inventory()`, and MUST NOT appear there as a malformed row.** This is the Pro-compatibility property (spec D3). Normalizing to `null` through `rollback_snapshot_normalize_record()` is the necessary mechanism but is **not sufficient on its own** — `managed_inventory()` reports a rejected record as `malformed: true` rather than skipping it, so it needs an explicit guard. Assert the property against the real reader, never just the mechanism.
- **TASK ORDER IS LOAD-BEARING, and the original order in this plan was wrong.** Tasks 4 (v2-aware read) and 5 (v2 restore) MUST both merge before Task 3 (rewiring `preset_reassign`). A v2 chunk record is write-only until they land: `rollback_snapshot_get`, `_restore`, and `_delete` all route through `rollback_snapshot_normalize_record()` and return HTTP 400 `invalid_input` "Rollback snapshot record is malformed.", and `_list` omits it entirely — verified by execution against the real handlers. Rewiring first would trade N restorable rows for one unreadable blob, a regression that bites at page one while the overflow it fixes only bites past page 500. Corrected order: **1 → 2 → 4 → 5 → 3 → 6**.
- **Retention gets no exemption** (spec D7). A run record is one row against the 500-row cap and is evicted oldest-first like any other.
- **Chunk bound is 100 pages per record** (spec D2), expressed as a named constant. May be lowered on evidence; may not be raised without re-testing memory and `max_allowed_packet` ceilings.
- **Single-page write paths are unchanged** (spec D4). Only `preset_reassign` writes run records.
- Every new PHP file carries `// SPDX-License-Identifier:` on line 2 — `GPL-2.0-or-later` under `plugins/`, `MIT` under `tests/`, `scripts/`, `diviops-server/src/`. `tests/test-spdx-headers.php` gates this.
- Test files are `tests/test-*.php`; run with `php tests/run.php`, filter with `php tests/run.php <substring>`. Every test file runs in **one shared process**, so no test may leave top-level variables or stored options behind.
- Conventional Commits. Each commit body must argue the change, not just describe it — release-please renders subjects into `CHANGELOG.md`, and the body is the durable record of why.
- No AI-authorship trailers on any commit, PR, or file.

---

### Task 1: v2 record shape, constants, and the Pro-compatibility guarantee

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-rollback.php`
- Test: `tests/test-rollback-run-record-shape.php` (create)

**Interfaces:**
- Produces: `rollback_snapshot_run_chunk_size(): int`, `rollback_snapshot_generate_run_id(string $tool): string`, `rollback_snapshot_is_run_record(array $record): bool`

- [ ] **Step 1: Write the failing test**

Create `tests/test-rollback-run-record-shape.php`:

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * Run-scoped rollback snapshot record shape (#199).
 *
 * The load-bearing assertion here is the negative one: a v2 run record must
 * normalize to null through the v1 path, because that is what keeps every
 * existing reader — including DiviOps Agent Pro's managed-recovery seam, which
 * this repository cannot update or test — skipping v2 records instead of
 * misreading them. See spec D3.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$chunk = (int) diviops_call( 'rollback_snapshot_run_chunk_size' );
assert_same( 100, $chunk, 'the run chunk bound is 100 pages per record' );

$run_id = (string) diviops_call( 'rollback_snapshot_generate_run_id', array( 'diviops_preset_reassign' ) );
assert_true(
    (bool) preg_match( '/^run_[0-9]{14}_[a-f0-9]{16}$/', $run_id ),
    'a run id is distinguishable from a per-page snapshot id by prefix'
);
assert_true(
    false !== diviops_call( 'rollback_snapshot_validate_id', array( $run_id ) ),
    'a run id passes the shared snapshot id validator, so it can be stored and addressed like any other'
);

$record = array(
    'schema_version' => 2,
    'snapshot_id'    => $run_id,
    'status'         => 'created',
    'created_at'     => gmdate( 'c' ),
    'tool'           => 'diviops_preset_reassign',
    'targets'        => array(
        array( 'id' => 900, 'kind' => 'post', 'post_type' => 'page' ),
    ),
);

assert_true(
    (bool) diviops_call( 'rollback_snapshot_is_run_record', array( $record ) ),
    'a v2 record carrying targets is recognized as a run record'
);

$legacy = array(
    'schema_version' => 1,
    'snapshot_id'    => 'snap_20260818000000_abcdef0123456789',
    'target'         => array( 'id' => 900, 'kind' => 'post' ),
);
assert_true(
    ! diviops_call( 'rollback_snapshot_is_run_record', array( $legacy ) ),
    'a v1 single-target record is not mistaken for a run record'
);

// The compatibility guarantee. If this ever fails, Pro is being handed a shape
// it was not built for.
assert_same(
    null,
    diviops_call( 'rollback_snapshot_normalize_record', array( $record, 'diviops_rollback_snapshot_' . $run_id, $record ) ),
    'a v2 run record normalizes to null through the v1 path, so legacy readers skip it'
);
```

- [ ] **Step 2: Run the test and watch it fail**

Run: `php tests/run.php rollback-run-record-shape`
Expected: fatal `ReflectionException: Method DiviOps_Agent::rollback_snapshot_run_chunk_size() does not exist`. That is the feature-missing signal for a Reflection-driven harness.

- [ ] **Step 3: Write the minimal implementation**

In `plugins/diviops-agent/includes/trait-rollback.php`, after `rollback_snapshot_retention_batch()`:

```php
	/**
	 * Pages per run-snapshot record (#199).
	 *
	 * before.value is a page's full prior post_content. A thousand of them in one
	 * option row could reach tens of megabytes, and get_option() loads the row
	 * whole — a memory cliff on constrained hosting and a max_allowed_packet risk
	 * on write. 100 keeps a worst-case record in the low single-digit megabytes
	 * while still turning a 1000-page run from 1000 rows into 10, which is the
	 * entire point. Lower this on evidence; do not raise it without re-testing
	 * both ceilings.
	 */
	private static function rollback_snapshot_run_chunk_size(): int {
		return 100;
	}

	/**
	 * Identifier for a run-scoped record.
	 *
	 * Prefixed run_ rather than snap_ so a run record is distinguishable from a
	 * per-page snapshot by id alone, in logs and in a caller's hands, without
	 * loading the row. Still conforms to rollback_snapshot_validate_id() so it
	 * addresses and stores exactly like any other snapshot id.
	 */
	private static function rollback_snapshot_generate_run_id( string $tool ): string {
		$seed = $tool . '|run|' . microtime( true ) . '|' . wp_rand();
		return 'run_' . gmdate( 'YmdHis' ) . '_' . substr( hash( 'sha256', $seed ), 0, 16 );
	}

	/**
	 * Whether a stored record is run-scoped.
	 *
	 * Keyed on the targets array rather than schema_version alone, so a record
	 * claiming version 2 without the payload to back it is not treated as one.
	 */
	private static function rollback_snapshot_is_run_record( array $record ): bool {
		return 2 === absint( $record['schema_version'] ?? 0 )
			&& is_array( $record['targets'] ?? null )
			&& array() !== $record['targets'];
	}
```

No change to `rollback_snapshot_normalize_record()` is needed or wanted: a v2 record has no scalar `target.id`, so the existing `$target_id <= 0` guard already returns `null`. That is the compatibility mechanism working by construction.

- [ ] **Step 4: Run the test and watch it pass**

Run: `php tests/run.php rollback-run-record-shape`
Expected: `PASS 6 assertion(s) in 1 file(s)`

- [ ] **Step 5: Run the full suite**

Run: `php tests/run.php`
Expected: PASS, file count up by one, no assertion-count decrease elsewhere.

- [ ] **Step 6: Commit**

```bash
git add tests/test-rollback-run-record-shape.php plugins/diviops-agent/includes/trait-rollback.php
git commit -F - <<'EOF'
feat(rollback): add the run-scoped snapshot record shape (#199)

preset_reassign takes one snapshot per applied page, the store is capped at 500
rows, and REASSIGN_MAX_PAGES is 1000 — so an apply of 501-1000 pages keeps only
its last 500 snapshots while reporting success for all of them.

This adds the record shape that fixes it: schema_version 2 with a targets array,
so a run costs ceil(pages / 100) rows instead of one per page.

The compatibility mechanism is deliberate and load-bearing. A v2 record carries
no scalar target.id, so rollback_snapshot_normalize_record() already returns null
for it, and every existing reader skips it as unrecognized. That includes
rollback_snapshot_managed_inventory(), the seam DiviOps Agent Pro's managed
recovery reads — Pro is closed-source and cannot be updated or tested from this
repository, so its behavior for single-target snapshots must stay bit-for-bit
unchanged. The test asserts that null explicitly rather than leaving it implied.

Chunk bound is 100 because before.value holds full prior post_content and
get_option() loads a row whole; 1000 pages in one row is a memory and packet
risk. 100 still gives a 100x reduction against the retention cap.
EOF
```

---

### Task 2: create and mark run records

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-rollback.php`
- Test: `tests/test-rollback-run-record-lifecycle.php` (create)

**Interfaces:**
- Consumes: `rollback_snapshot_run_chunk_size()`, `rollback_snapshot_generate_run_id()`, `rollback_snapshot_is_run_record()` from Task 1
- Produces: `rollback_snapshot_run_begin( string $tool, array $operation ): array` returning `[ 'run_id' => string, 'chunks' => array, 'open' => array ]`; `rollback_snapshot_run_capture( array &$run, $post ): array`; `rollback_snapshot_run_mark( array &$run, int $post_id, string $status, ?string $after_content ): void`; `rollback_snapshot_run_flush( array &$run ): array` returning stored option names

- [ ] **Step 1: Write the failing test**

Create `tests/test-rollback-run-record-lifecycle.php`:

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * Run-scoped snapshot capture, marking, and chunk flushing (#199).
 *
 * Asserts the two properties that make a run record restorable at all: every
 * captured page ends up in exactly one chunk, and every entry carries its own
 * after.checksum. rollback_snapshot_restore() refuses outright without that
 * checksum, so an entry that is captured but never marked is permanently
 * unrestorable — the footgun the #38 spec found the hard way.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$chunk = (int) diviops_call( 'rollback_snapshot_run_chunk_size' );

$run = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_preset_reassign', array( 'tool_operation' => 'preset.reassign' ) ) );
assert_true( is_array( $run ) && ! empty( $run['run_id'] ), 'run_begin returns a run handle carrying a run id' );

$ids = array();
for ( $i = 0; $i < $chunk + 5; $i++ ) {
    $post_id = 5000 + $i;
    $ids[]   = $post_id;
    $post    = diviops_test_make_post( $post_id, 'before content ' . $i );
    diviops_call_ref( 'rollback_snapshot_run_capture', $args = array( &$run, $post ) );
    diviops_call_ref( 'rollback_snapshot_run_mark', $args2 = array( &$run, $post_id, 'write_applied', 'after content ' . $i ) );
}

$stored = diviops_call_ref( 'rollback_snapshot_run_flush', $args3 = array( &$run ) );
assert_same( 2, count( $stored ), 'a run of chunk+5 pages flushes to exactly two records' );

$seen = array();
foreach ( $stored as $option_name ) {
    $record = get_option( $option_name, null );
    assert_true( is_array( $record ), 'each flushed chunk is a stored array record' );
    assert_true( (bool) diviops_call( 'rollback_snapshot_is_run_record', array( $record ) ), 'each flushed chunk is a run record' );
    assert_true( count( $record['targets'] ) <= $chunk, 'no chunk exceeds the chunk bound' );
    foreach ( $record['targets'] as $entry ) {
        $seen[] = (int) $entry['id'];
        assert_true( ! empty( $entry['after']['checksum'] ), 'every entry carries an after checksum, without which restore refuses' );
        assert_true( isset( $entry['before']['value'] ), 'every entry carries its prior content' );
    }
}

sort( $seen );
assert_same( $ids, $seen, 'every captured page appears in exactly one chunk, none lost and none duplicated' );

foreach ( $stored as $option_name ) {
    delete_option( $option_name );
}
```

If `diviops_test_make_post()` does not already exist in `tests/wp-shim.php`, add it there as a shared helper returning a `WP_Post`-shaped object with `ID` and `post_content`, following the shim's existing post-construction idiom — do not define it in this test file, or it will collide in the shared process.

- [ ] **Step 2: Run the test and watch it fail**

Run: `php tests/run.php rollback-run-record-lifecycle`
Expected: fatal on `rollback_snapshot_run_begin()` not existing.

- [ ] **Step 3: Write the minimal implementation**

Add to `trait-rollback.php`. `run_capture` appends to an open chunk and flushes it when full, so peak memory is one chunk, not one run:

```php
	private static function rollback_snapshot_run_begin( string $tool, array $operation ): array {
		return [
			'run_id'    => self::rollback_snapshot_generate_run_id( $tool ),
			'tool'      => $tool,
			'operation' => $operation,
			'created_at'=> self::rollback_snapshot_now(),
			'seq'       => 0,
			'open'      => [],
			'chunks'    => [],
		];
	}

	private static function rollback_snapshot_run_capture( array &$run, $post ): array {
		$entry = [
			'id'     => (int) $post->ID,
			'kind'   => 'post',
			'before' => self::rollback_snapshot_before_from_post( $post ),
			'after'  => [ 'checksum' => null, 'byte_length' => null, 'side_effects' => null ],
		];
		$target                     = self::rollback_snapshot_target_from_post( $post );
		$entry['post_type']         = (string) ( $target['post_type'] ?? '' );
		$run['open'][ (int) $post->ID ] = $entry;
		if ( count( $run['open'] ) >= self::rollback_snapshot_run_chunk_size() ) {
			self::rollback_snapshot_run_flush_open( $run );
		}
		return $entry;
	}

	private static function rollback_snapshot_run_mark( array &$run, int $post_id, string $status, ?string $after_content ): void {
		if ( ! isset( $run['open'][ $post_id ] ) ) {
			return;
		}
		$run['open'][ $post_id ]['status'] = $status;
		if ( null !== $after_content ) {
			$run['open'][ $post_id ]['after'] = [
				'checksum'     => self::rollback_snapshot_checksum( $after_content ),
				'byte_length'  => strlen( $after_content ),
				'side_effects' => self::rollback_snapshot_capture_side_effects( $post_id ),
			];
		}
	}

	private static function rollback_snapshot_run_flush_open( array &$run ): ?string {
		if ( array() === $run['open'] ) {
			return null;
		}
		$run['seq']++;
		$snapshot_id = $run['run_id'] . '_c' . $run['seq'];
		$record      = [
			'schema_version' => 2,
			'snapshot_id'    => $snapshot_id,
			'run_id'         => $run['run_id'],
			'chunk'          => $run['seq'],
			'status'         => 'write_applied',
			'created_at'     => $run['created_at'],
			'expires_at'     => gmdate( 'c', time() + self::rollback_snapshot_expiry_seconds() ),
			'created_by'     => self::rollback_snapshot_actor(),
			'tool'           => $run['tool'],
			'operation'      => $run['operation'],
			'targets'        => array_values( $run['open'] ),
			'restore'        => [ 'restorable' => true, 'restored_at' => null, 'restored_by' => null ],
			'cleanup'        => [ 'deleted_at' => null, 'deleted_by' => null ],
		];
		$option_name = self::rollback_snapshot_option_name( $snapshot_id );
		add_option( $option_name, $record, '', 'no' );
		$run['open']     = [];
		$run['chunks'][] = $option_name;
		self::rollback_snapshot_enforce_retention();
		return $option_name;
	}

	private static function rollback_snapshot_run_flush( array &$run ): array {
		self::rollback_snapshot_run_flush_open( $run );
		return $run['chunks'];
	}
```

Reuse whatever `rollback_snapshot_create_for_post_write()` already uses to build `created_by`; if that is inline rather than a helper, extract it to `rollback_snapshot_actor()` in this task and have both call it, rather than duplicating the expression.

- [ ] **Step 4: Run the test and watch it pass**

Run: `php tests/run.php rollback-run-record-lifecycle`
Expected: PASS.

- [ ] **Step 5: Prove the chunk bound holds under realistic size**

Add to the same test file, before the cleanup loop — a token fixture proves nothing about a memory cliff:

```php
$big = diviops_call( 'rollback_snapshot_run_begin', array( 'diviops_preset_reassign', array() ) );
$page = str_repeat( 'x', 40 * 1024 ); // 40KB, a plausible Divi page
for ( $i = 0; $i < $chunk; $i++ ) {
    $post = diviops_test_make_post( 7000 + $i, $page );
    diviops_call_ref( 'rollback_snapshot_run_capture', $a = array( &$big, $post ) );
    diviops_call_ref( 'rollback_snapshot_run_mark', $b = array( &$big, 7000 + $i, 'write_applied', $page ) );
}
$big_stored = diviops_call_ref( 'rollback_snapshot_run_flush', $c = array( &$big ) );
assert_same( 1, count( $big_stored ), 'a full chunk of realistic pages is one record' );
$bytes = strlen( serialize( get_option( $big_stored[0] ) ) );
assert_true( $bytes < 8 * 1024 * 1024, 'a full chunk of 40KB pages serializes under 8MB, well inside default max_allowed_packet' );
foreach ( $big_stored as $option_name ) { delete_option( $option_name ); }
```

Run: `php tests/run.php rollback-run-record-lifecycle`
Expected: PASS. If the size assertion fails, lower the chunk bound in Task 1 and re-run — do not raise the assertion.

- [ ] **Step 6: Run the full suite and commit**

```bash
php tests/run.php
git add tests/test-rollback-run-record-lifecycle.php tests/wp-shim.php plugins/diviops-agent/includes/trait-rollback.php
git commit -F - <<'EOF'
feat(rollback): capture, mark, and flush run-scoped snapshot chunks (#199)

Adds the run lifecycle: begin, capture per page, mark per page after its write,
flush to chunked records. A chunk is flushed as soon as it fills rather than at
the end of the run, so peak memory is one chunk and not one run.

Every entry carries its own after.checksum, set when that page's write completes.
rollback_snapshot_restore() refuses outright when after.checksum is empty, so an
entry captured but never marked would be permanently unrestorable. The test
asserts the checksum on every entry rather than trusting the call order.

The size assertion uses 40KB pages, not token fixtures: the chunk bound exists to
avoid a get_option() memory cliff and a max_allowed_packet failure, and a size
test that passes on 100-byte pages would be measuring nothing.
EOF
```

---

### Task 3: wire `preset_reassign` to run records

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-preset.php` (the per-page loop; the snapshot call site is the one reached in apply mode)
- Test: `tests/test-preset-reassign-run-snapshot.php` (create)

**Interfaces:**
- Consumes: the Task 2 run lifecycle
- Produces: `summary['rollback']` shaped `[ 'run_id' => string, 'chunks' => int, 'pages_captured' => int ]`

- [ ] **Step 1: Write the failing test**

Structural, following the idiom in `tests/test-preset-reassign-write-safety.php` — `preset_reassign()` cannot be driven end to end here because the registry probe goes through the unshimmed `et_get_option()`. Declare the Reflection source helper under a name unique to this file; `preset_reassign_method_source` and `reassign_budget_method_source` are already taken elsewhere in the shared process.

```php
<?php
// SPDX-License-Identifier: MIT
/**
 * preset_reassign() records one run snapshot, not one per page (#199).
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

function reassign_run_snapshot_source( string $method ): string {
    $reflection = new ReflectionMethod( 'DiviOps_Agent', $method );
    $file       = $reflection->getFileName();
    $start      = $reflection->getStartLine() - 1;
    return implode( '', array_slice( file( $file ), $start, $reflection->getEndLine() - $start ) );
}

$source = reassign_run_snapshot_source( 'preset_reassign' );

assert_true( false !== strpos( $source, 'rollback_snapshot_run_begin' ), 'preset_reassign opens a run snapshot' );
assert_true( false !== strpos( $source, 'rollback_snapshot_run_capture' ), 'preset_reassign captures each page into the run' );
assert_true( false !== strpos( $source, 'rollback_snapshot_run_mark' ), 'preset_reassign marks each page after its write, without which the entry is unrestorable' );
assert_true( false !== strpos( $source, 'rollback_snapshot_run_flush' ), 'preset_reassign flushes the run' );
assert_true(
    false === strpos( $source, 'rollback_snapshot_create_for_post_write' ),
    'preset_reassign no longer takes a per-page snapshot, which is what overflowed the retention cap'
);
assert_true( false !== strpos( $source, "'rollback'" ), 'the summary reports the run so a caller can restore it' );
```

- [ ] **Step 2: Run and watch it fail**

Run: `php tests/run.php preset-reassign-run-snapshot`
Expected: the `run_begin` assertion fails first.

- [ ] **Step 3: Implement**

In `trait-preset.php`: call `rollback_snapshot_run_begin()` before the `foreach ( $posts as $p )` loop in apply mode; replace the `rollback_snapshot_create_for_post_write()` call with `rollback_snapshot_run_capture()`; replace the corresponding mark call with `rollback_snapshot_run_mark()`; call `rollback_snapshot_run_flush()` after the loop and set `$summary['rollback']`. Capture must stay inside the same guard ordering the per-page snapshot had — captured before the write, marked after the readback — or the entry records the wrong `before`.

Preserve the existing per-page error paths: a page that fails the integrity guard or the global-layout backstop must still be marked with the failure status, exactly as `rollback_snapshot_mark_from_write_error()` did.

- [ ] **Step 4: Run the target test, then the full suite**

Run: `php tests/run.php preset-reassign-run-snapshot` then `php tests/run.php`
Expected: both PASS. `test-preset-reassign-write-safety.php` asserts structurally that the snapshot is created and marked — if its assertions name the old function, update them in this task and say so in the commit, since the behavior they guard is preserved under new names.

- [ ] **Step 5: Commit**

```bash
git add tests/test-preset-reassign-run-snapshot.php tests/test-preset-reassign-write-safety.php plugins/diviops-agent/includes/trait-preset.php
git commit -F - <<'EOF'
fix(preset_reassign): store one run snapshot instead of one per page (#199)

An apply of 501-1000 pages kept only its last 500 snapshots. Retention evicts
oldest-first after every write, so once a run passed the 500-row cap each new
page evicted the run's own earliest one — and the run reported success for every
page, because the snapshots really had been created. They just no longer existed.

The run now costs ceil(pages / 100) rows instead of one per page, so a 1000-page
apply uses 10 and every page stays restorable. REASSIGN_MAX_PAGES stays at 1000
and dry-run scanning is untouched.

This is strictly more recoverable than before, not a trade: previously the first
500 pages of a 1000-page apply had no restore path at all.
EOF
```

---

### Task 4: v2-aware get and list

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-rollback.php` (`rollback_snapshot_list`, `rollback_snapshot_get`)
- Test: `tests/test-rollback-run-record-read.php` (create)

**Interfaces:**
- Produces: list entries for run records carrying `kind: "run"`, `run_id`, `chunk`, `page_count`; `rollback_snapshot_get` returning the entry list for a run record

- [ ] **Step 1: Write the failing test**

Assert that a stored run record appears in `rollback_snapshot_list` output with `kind: "run"` and its page count, that `rollback_snapshot_get` returns its entries, and — the guarantee from Task 1, re-asserted at the read boundary — that `rollback_snapshot_managed_inventory()` does **not** return it. Seed one v1 record and one v2 record, assert the inventory returns exactly the v1 one, then delete both and assert the store is left as found.

- [ ] **Step 2: Run and watch it fail** — `php tests/run.php rollback-run-record-read`

- [ ] **Step 3: Implement** the additive v2 branches in `rollback_snapshot_list` and `rollback_snapshot_get`. Do **not** touch `rollback_snapshot_managed_inventory()`; its exclusion of v2 records is the Pro-compatibility guarantee and must remain by construction.

- [ ] **Step 4: Run the target test and the full suite**

- [ ] **Step 5: Commit** with a body explaining that the inventory seam is deliberately left blind to run records.

---

### Task 5: restore a run, whole or in part

**Files:**
- Modify: `plugins/diviops-agent/includes/trait-rollback.php` (`rollback_snapshot_restore`)
- Test: `tests/test-rollback-run-restore.php` (create)

**Interfaces:**
- Consumes: Tasks 1–2
- Produces: `rollback_snapshot_restore` accepting optional `page_ids`; response carrying `restored[]`, `refused[]` with per-entry reason

- [ ] **Step 1: Write the failing test.** Cover, each as its own assertion:
  - restoring a run with no `page_ids` restores every entry
  - restoring with `page_ids` restores exactly those and leaves the rest untouched
  - an entry whose live content drifted from its recorded `after` checksum is refused with `conflict` **and its siblings still restore** (spec D6)
  - an entry with an empty `after.checksum` is reported unrestorable rather than silently skipped (spec D5)
  - a `page_ids` value naming a page not in the run is refused, not ignored

- [ ] **Step 2: Run and watch it fail** — `php tests/run.php rollback-run-restore`

- [ ] **Step 3: Implement.** Branch on `rollback_snapshot_is_run_record()` at the top of `rollback_snapshot_restore()`; v1 records keep their exact current path, untouched. Each entry restores through the same guarded write and side-effect restore the v1 path uses — reuse `rollback_snapshot_restore_side_effects()`, do not reimplement it.

- [ ] **Step 4: Run the target test and the full suite**

- [ ] **Step 5: Commit**, arguing why a drifted page refuses individually rather than failing the run: any page edited after the reassign would otherwise block the whole recovery, which is the common case, not the rare one.

---

### Task 6: surface it — MCP tool, skill docs, FORK.md

**Files:**
- Modify: `diviops-server/src/index.ts` (the `rollback_snapshot_restore` tool description and its `page_ids` parameter; the `preset_reassign` description's summary shape)
- Modify: `skills/divi-5-builder/references/tools.md` (error-code table and `error.data` shapes)
- Modify: `skills/divi-5-builder/references/presets.md` (the reassign workflow)
- Modify: `FORK.md` (divergence table)
- Modify: `docs/superpowers/specs/2026-08-14-bulk-site-wide-operations-design.md` (amend "there is no multi-target snapshot shape" to point at the new spec)
- Test: `tests/test-tool-reference-sync.php` if one exists for `tools.md`; otherwise rely on `diviops-server`'s `regen-tool-reference` check

- [ ] **Step 1: Update `index.ts`** — add `page_ids` to the restore tool, document the partial-restore response, and document `summary.rollback` on reassign. An agent reads this description and nothing else; a partial restore that is not described will be treated as a success.

- [ ] **Step 2: Run the server checks**

```bash
cd diviops-server && npm run build && npm test
```

- [ ] **Step 3: Update the skill references and FORK.md**, then amend the #38 spec's constraint line.

- [ ] **Step 4: Run the full PHP suite and the server suite**

- [ ] **Step 5: Commit**

```bash
git commit -F - <<'EOF'
docs(rollback): document run-scoped snapshots and partial restore (#199)

Adds page_ids to the restore tool description, documents the partial-restore
response, and records summary.rollback on preset_reassign. An agent reads the MCP
tool description and nothing else, so a partial restore that is not described
there gets treated as a success.

Amends the #38 bulk-operations spec, which recorded "there is no multi-target
snapshot shape" as a fixed constraint. There is one now. That spec's batch cap of
25 is unchanged: run-scoped records relieve its cumulative-snapshot-cost leg, but
its other justifications — the second wp_update_post from invalidate_divi_cache,
rate limiting counting targets, and a plan a human can read — are untouched.
EOF
```

---

## Self-review notes

- **Spec coverage.** D1 → Task 5. D2 → Tasks 1, 2 (with the size proof). D3 → Task 1, re-asserted in Task 4. D4 → no task, enforced by leaving the other twelve call sites alone; Task 3's test asserts `preset_reassign` no longer calls the per-page path, which is the only site that changes. D5 → Tasks 2 and 5. D6 → Task 5. D7 → Task 2 (`enforce_retention()` still called on flush, no exemption).
- **Live verification remains open.** The harness cannot drive `preset_reassign` end to end. Restore of a real multi-page run must be verified against the local Divi install before this is trusted — and per repository rules, any write to that site needs the owner's confirmation first.
- **Ordering matters, and the first version of this note was incomplete.** Task 3 removes the per-page snapshot, so Tasks 1 and 2 must be merged first — that much was right. But Tasks 4 and 5 must ALSO precede it, because until they land nothing can read, restore, or delete a v2 record. The scouting pass for Task 3 caught this; the plan as originally written would have shipped a recovery regression. Corrected order: 1 → 2 → 4 → 5 → 3 → 6.
