<?php
// SPDX-License-Identifier: MIT
/**
 * Characterization of the preset handlers that had no test coverage (#328).
 *
 * `plugins/diviops-agent/includes/trait-preset.php` is 3,221 lines and 44
 * functions — the largest single capability domain in the plugin and the one
 * that owns its destructive operations (delete, cleanup, reassign). Measured by
 * searching `tests/` for each of the twelve public handler names, with a
 * positive control run first (`preset_reassign` → 10 files), only two were
 * referenced at all before this file, and both only structurally:
 * `preset_inspect` and `preset_cleanup` appear in
 * `tests/test-preset-ref-scan-post-types.php` as names in the #314 post-type
 * argument. `preset_reassign` is the exception and is covered behaviourally by
 * `tests/test-preset-reassign-write-safety.php`. The other nine had zero
 * references of any kind:
 *
 *   preset_registry_doctor, preset_list, preset_audit_storage, preset_audit,
 *   preset_update, preset_delete, preset_set_default, preset_create,
 *   preset_scan_orphans
 *
 * A characterization test records what the code does today, right or wrong, so
 * that a later edit — an upstream adoption in particular — cannot change it
 * silently. Several behaviours pinned below are defects, and they are pinned AS
 * defects, each flagged with a `DEFECT:` comment naming what is wrong. Do not
 * "fix" one by editing the expectation here: the assertion failing is the signal
 * it exists to produce, and the PR body lists them so a later fix reads as a fix
 * rather than a regression.
 *
 * ── Relationship to tests/test-preset-reassign-write-safety.php ─────────────
 *
 * That file owns `preset_reassign()` and its three write-safety helpers
 * (`preset_reassign_apply_target_refusal`, the round-trip drift gate, and
 * `preset_reassign_apply_response`). Nothing here re-covers any of them. This
 * file extends around it in two directions: the eleven OTHER public handlers,
 * and the registry-shape helpers `preset_reassign()` shares with them but which
 * that file could not reach — `collect_group_chain_refs`,
 * `_extract_chain_slot_map`, `_write_chain_slot_map`,
 * `_swap_chain_refs_in_group_presets_map`, `strip_reserved_keys` and
 * `_strip_redundant_inline_attrs`.
 *
 * ── The et_get_option boundary, and what it costs ──────────────────────────
 *
 * Divi's `et_get_option()` is deliberately unshimmed in this harness (see
 * tests/test-variable-update.php and tests/test-preset-reassign-write-safety.php
 * for the settled reasoning: faking Divi's option-storage routing means
 * asserting against a fake persistence layer). This file does NOT define it —
 * doing so would silently change how every later test file behaves, which is
 * exactly the shared-harness failure the #328 brief forbids.
 *
 * The registry read path survives that boundary because
 * `probe_storage_paths()` stops at the first non-empty candidate and the first
 * candidate — `et_divi_builder_global_presets_d5` — is a top-level WP option
 * read through `get_option()`. Seeding it is therefore enough to drive most of
 * this trait for real. What it does NOT rescue is any code that walks PAST the
 * canonical path: `detect_d5_legacy_path()` skips it by construction, and
 * `audit_d5_preset_storage()` probes every candidate unconditionally. Both then
 * read `et_divi.builder_global_presets_d5` through `et_get_option()`.
 *
 * Handlers that are therefore only partly reachable here, with the exact stub
 * each one needs (a faithful `et_get_option( $name, $default, ... )` reading
 * Divi's `et_divi` umbrella option, which is a wp-shim decision, not this
 * suite's):
 *
 *   - preset_list          — NOT COVERED. Calls `et_get_option( 'et_global_data' )`
 *                            on every request (trait-preset.php:240), before any
 *                            observable work.
 *   - preset_scan_orphans  — NOT COVERED. Calls
 *                            `et_get_option( 'builder_global_presets_ng', ... )`
 *                            on every request (trait-preset.php:3145).
 *   - preset_audit_storage — NOT COVERED. `audit_d5_preset_storage()` probes the
 *                            nested path unconditionally.
 *   - preset_inspect       — NOT COVERED, same cause, plus `preset_storage_occurrences()`
 *                            and `preset_inspection_registry()` walk every path too.
 *
 * The four mutating handlers — create, update, delete, and preset_id-mode
 * set_default — are covered in full, response envelope excepted. Each one calls
 * `save_d5_presets()` and only THEN builds `_meta` through
 * `d5_preset_write_meta()` → `detect_d5_legacy_path()`, so the write is complete
 * and assertable by the time the boundary is hit; `diviops_pc_wrote()` below
 * says how. What is not assertable for those four is the shape of the success
 * envelope they would have returned.
 *
 * `preset_set_default` in bucket-addressed mode returns an envelope like any
 * other handler, because that one path skips `attach_meta` entirely — which is
 * itself pinned below as a defect.
 *
 * Two further helpers are unreachable for a second, independent reason:
 * `collect_preset_consumer_samples()` and the live-scan half of
 * `collect_page_preset_refs()` both need `parse_blocks()`, which this harness
 * also does not shim (tests/test-parse-blocks-for-write-coverage.php). The
 * fixture below keeps the post registry empty for exactly that reason, so the
 * scan is a real zero rather than an accident.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/preset-characterization-stubs.php';

// ── Fixture bookkeeping ───────────────────────────────────────────────────
//
// The options and post registries are process-wide and shared with every other
// test file, and this one runs early in the alphabetical order (before all four
// other test-preset-*.php files). Everything is snapshotted here and restored at
// the very bottom.
//
// The post registry is emptied rather than merely snapshotted: preset_audit()
// and preset_cleanup() both call collect_page_preset_refs(), which get_posts()
// every SCANNABLE_POST_TYPES row on the site and calls parse_blocks() on any
// whose content contains `"modulePreset"` or `"groupPreset"`. parse_blocks() is
// unshimmed and throws. A post left behind by an earlier file would therefore
// turn this suite into a fatal error rather than a failure — and, short of
// that, would make every reference count here depend on which files ran first.
$GLOBALS['diviops_pc_saved_options']     = $GLOBALS['diviops_test_options'];
$GLOBALS['diviops_pc_saved_option_rows'] = $GLOBALS['diviops_test_option_rows'];
$GLOBALS['diviops_pc_saved_posts']       = $GLOBALS['diviops_test_posts'] ?? array();
$GLOBALS['diviops_test_posts']           = array();

/** The canonical D5 registry option. Divi's own `GlobalPreset::option_name()` suffix. */
const DIVIOPS_PC_OPTION = 'et_divi_builder_global_presets_d5';

/**
 * Seed the canonical registry and return it, so a caller can diff against the
 * exact bytes it wrote.
 *
 * @param array $registry Registry payload.
 * @return array
 */
function diviops_pc_seed( array $registry ): array {
	update_option( DIVIOPS_PC_OPTION, $registry, false );
	return $registry;
}

/**
 * Read the canonical registry back.
 *
 * @return mixed
 */
function diviops_pc_stored() {
	return get_option( DIVIOPS_PC_OPTION, null );
}

/**
 * Invoke a handler with a request built from the given params.
 *
 * @param string $method Handler name on DiviOps_Agent.
 * @param array  $params Request params.
 * @return WP_REST_Response
 */
function diviops_pc_call( string $method, array $params = array() ) {
	return diviops_call( $method, array( new DiviOps_Test_Request( $params ) ) );
}

/**
 * Invoke a handler and return its envelope body.
 *
 * @param string $method Handler name on DiviOps_Agent.
 * @param array  $params Request params.
 * @return array
 */
function diviops_pc_body( string $method, array $params = array() ): array {
	return diviops_pc_call( $method, $params )->get_data();
}

/**
 * Drive a handler through its write and stop at the storage-meta step.
 *
 * Every mutating handler in this trait except the bucket-addressed
 * `preset_set_default` clear does the same two things in the same order:
 * `save_d5_presets()`, then `attach_meta( ..., d5_preset_write_meta() )`. The
 * second reaches `et_get_option()` and throws here (see the header). The write
 * has already happened by then, so catching that specific Error is how the
 * write itself is characterized — and, separately, is proof the handler got
 * past every refusal gate ahead of it.
 *
 * This is the same technique tests/test-preset-ref-scan-post-types.php uses
 * against unshimmed `parse_blocks()`: a catchable Error from a named primitive
 * is evidence of reaching the call site. Returns true only when the handler
 * reached the storage-meta step; a returned envelope (i.e. a refusal) and any
 * other Error both return false, so a mutation that turns a write into a
 * refusal fails the assertion rather than passing quietly.
 *
 * @param string $method Handler name on DiviOps_Agent.
 * @param array  $params Request params.
 * @return bool
 */
function diviops_pc_wrote( string $method, array $params ): bool {
	try {
		diviops_pc_call( $method, $params );
		return false;
	} catch ( Error $error ) {
		return false !== strpos( $error->getMessage(), 'et_get_option' );
	}
}

/**
 * A two-bucket registry in Divi's own shape.
 *
 * Shape confirmed against Divi's server source, not inferred: `GlobalPreset.php`
 * reads `$all_data['module'][ <moduleName> ]['items'][ <uuid> ]` (line 576) and
 * the sibling `['default']` pointer (line 549) — the exact keys every handler in
 * this trait walks.
 *
 * @return array
 */
function diviops_pc_registry_fixture(): array {
	return array(
		'module' => array(
			'divi/heading' => array(
				'default' => 'mod-default',
				'items'   => array(
					// Spam by the repeated-phrase heuristic, and the bucket default:
					// preset_cleanup must rename rather than remove it.
					'mod-default' => array( 'name' => 'Heading Heading Hero', 'attrs' => array( 'a' => 1 ) ),
					// Spam, unreferenced, not default: removable.
					'mod-spam'    => array( 'name' => 'Text Text', 'attrs' => array( 'b' => 2 ) ),
					// Not spam, unreferenced, not default: kept unless scope=all.
					'mod-clean'   => array( 'name' => 'Hero Banner', 'attrs' => array( 'c' => 3 ) ),
					// No attrs and no styleAttrs: preset_audit's empty_defaults bucket.
					'mod-empty'   => array( 'name' => 'Blank Blank' ),
					// Pulls a group preset in through the module-bucket chain shape.
					'mod-chained' => array(
						'name'         => 'Card',
						'attrs'        => array( 'd' => 4 ),
						'groupPresets' => array( 'divi/font' => array( 'presetId' => 'grp-chained' ) ),
					),
				),
			),
		),
		'group'  => array(
			'divi/font' => array(
				'default' => '',
				'items'   => array(
					// Referenced only through mod-chained's chain: unsafe to delete.
					'grp-chained' => array( 'name' => 'Font Font Body', 'attrs' => array( 'e' => 5 ) ),
					// Referenced by nothing at all.
					'grp-orphan'  => array( 'name' => 'Unused Caption', 'attrs' => array( 'f' => 6 ) ),
				),
			),
		),
	);
}

// ══ preset_registry_doctor ════════════════════════════════════════════════
//
// The doctor is the only handler in this trait that mutates the registry with a
// backup, a readback and a restore-on-mismatch, so its branches are pinned in
// full: refusal, audit, dry-run, no-op, apply, and the coupling between the
// timestamp repair and the chunk-transient cleanup.

// ── A registry that is not an array is refused before anything is read ────

diviops_pc_seed( array() );
update_option( DIVIOPS_PC_OPTION, 'this is not a registry', false );
$response = diviops_pc_call( 'preset_registry_doctor', array() );
$body     = $response->get_data();
assert_same( false, $body['ok'] ?? null, 'doctor refuses a registry that is not an array' );
assert_same( 'validation_failed', $body['error']['code'] ?? null, 'the refusal code is validation_failed' );
assert_same( 400, $response->get_status(), 'an unusable registry container is a 400' );
assert_same( DIVIOPS_PC_OPTION, $body['error']['data']['path'] ?? null, 'the refusal names the option it read' );
assert_same( 'string', $body['error']['data']['actual_type'] ?? null, 'the refusal reports gettype() of what it found' );

// ── Audit mode: read-only, and read-only even with dry_run unset ──────────

$timestamps = array(
	'module' => array(
		'divi/heading' => array(
			'default' => '',
			'items'   => array(
				// A parseable ISO-8601 instant with milliseconds: repairable.
				'ts-iso'   => array( 'name' => 'A', 'created' => '2026-01-02T03:04:05.678Z' ),
				// Already an integer: not a finding at all (trait-preset.php:126).
				'ts-int'   => array( 'name' => 'B', 'updated' => 1735786800000 ),
				// A string that is not an instant: a finding, but unrepairable.
				'ts-junk'  => array( 'name' => 'C', 'created' => 'not a date' ),
				// Neither field present: never inspected.
				'ts-clean' => array( 'name' => 'D' ),
			),
		),
	),
);
diviops_pc_seed( $timestamps );

$body = diviops_pc_body( 'preset_registry_doctor', array() );
assert_same( true, $body['ok'] ?? null, 'doctor audit mode succeeds' );
assert_same( DIVIOPS_PC_OPTION, $body['data']['option_name'] ?? null, 'audit names the canonical option' );
assert_same( 2, $body['data']['finding_count'] ?? null, 'only the two non-integer timestamps are findings — an int is skipped' );
assert_same( 1, $body['data']['repairable_count'] ?? null, 'only the parseable instant is repairable' );
assert_same( false, $body['data']['repair'] ?? null, 'audit mode reports repair:false' );
assert_same( false, $body['data']['mutated'] ?? null, 'audit mode reports mutated:false' );
assert_same( false, array_key_exists( 'plan', $body['data'] ), 'audit mode emits no plan — a plan is a repair-mode field' );
assert_same( false, $body['data']['truncated'] ?? null, 'two findings under the default limit of 100 are not truncated' );

$finding = $body['data']['findings'][0];
assert_same( 'module', $finding['type'], 'a finding carries its registry bucket' );
assert_same( 'divi/heading', $finding['bucket_key'], 'a finding carries its bucket key' );
assert_same( 'ts-iso', $finding['preset_id'], 'a finding carries the preset UUID' );
assert_same( 'A', $finding['preset_name'], 'a finding carries the preset name for operator context' );
assert_same( 'created', $finding['field'], 'a finding names the offending field' );
assert_same( 'module.divi/heading.items.ts-iso.created', $finding['path'], 'the dotted path is bucket.bucketKey.items.uuid.field' );
assert_same( array( 'module', 'divi/heading', 'items', 'ts-iso', 'created' ), $finding['segments'], 'segments are the same path pre-split for the writer' );
assert_same( 'string', $finding['actual_type'], 'a finding reports gettype() of the stored value' );
assert_same( 'integer milliseconds since Unix epoch', $finding['expected_shape'], 'a finding states the shape Divi expects' );
assert_same( true, $finding['repairable'], 'a parseable instant is repairable' );
// Expected value derived independently of the code under test:
// `date -j -u -f "%Y-%m-%dT%H:%M:%S" "2026-01-02T03:04:05" "+%s"` → 1767323045,
// times 1000 for milliseconds, plus the literal .678 fraction → 1767323045678.
assert_same( 1767323045678, $finding['replacement'], 'the replacement is epoch seconds x 1000 plus the millisecond fraction' );
assert_same( false, $body['data']['findings'][1]['repairable'], 'an unparseable string is a finding but not repairable' );
assert_same( null, $body['data']['findings'][1]['replacement'], 'an unrepairable finding carries no replacement' );

// ── preset_registry_iso_milliseconds: what counts as an instant ───────────
//
// The accepted grammar is the regex at trait-preset.php:147 — a full date, a
// literal T, a full time, an optional 1-6 digit fraction, and a MANDATORY zone
// that is either `Z` or `±HH:MM`. Each case below is derived from that grammar,
// and the numeric expectations from `date -j -u` as above.

assert_same( 1767323045678, diviops_call_static( 'preset_registry_iso_milliseconds', array( '2026-01-02T03:04:05.678Z' ) ), 'a Z-zoned instant with milliseconds parses' );
assert_same( 1767323045000, diviops_call_static( 'preset_registry_iso_milliseconds', array( '2026-01-02T03:04:05Z' ) ), 'the fraction is optional' );
assert_same( 1767323045000, diviops_call_static( 'preset_registry_iso_milliseconds', array( '2026-01-02T03:04:05+00:00' ) ), 'a numeric UTC offset is accepted and resolves to the same instant' );
// Microsecond precision truncates toward zero rather than rounding: the code
// floors $date->format('u') / 1000 (trait-preset.php:151).
assert_same( 1767323045123, diviops_call_static( 'preset_registry_iso_milliseconds', array( '2026-01-02T03:04:05.123999Z' ) ), 'sub-millisecond precision is floored, not rounded' );
assert_same( null, diviops_call_static( 'preset_registry_iso_milliseconds', array( '2026-01-02T03:04:05' ) ), 'a zoneless instant is rejected — the grammar requires a zone' );
assert_same( null, diviops_call_static( 'preset_registry_iso_milliseconds', array( '2026-01-02 03:04:05Z' ) ), 'a space separator is rejected — the grammar requires a literal T' );
assert_same( null, diviops_call_static( 'preset_registry_iso_milliseconds', array( 'not a date' ) ), 'a non-instant string is rejected' );
assert_same( null, diviops_call_static( 'preset_registry_iso_milliseconds', array( '  2026-13-45T99:99:99Z  ' ) ), 'the value is trimmed first, then rejected on impossible components' );

// ── The limit parameter, and the truncated flag ───────────────────────────

$body = diviops_pc_body( 'preset_registry_doctor', array( 'limit' => 1 ) );
assert_same( 1, $body['data']['finding_count'] ?? null, 'limit caps the finding scan mid-walk' );
assert_same( true, $body['data']['truncated'] ?? null, 'hitting the limit sets truncated' );

// DEFECT: `(int) ( $request->get_param( 'limit' ) ?: 100 )` at trait-preset.php:25
// applies `?:` BEFORE the cast, so a caller-supplied limit of 0 is falsey and
// silently becomes the default 100 rather than being clamped up to 1. A negative
// limit does clamp to 1, so the two out-of-range inputs are treated differently.
$body = diviops_pc_body( 'preset_registry_doctor', array( 'limit' => 0 ) );
assert_same( 2, $body['data']['finding_count'] ?? null, 'DEFECT: limit=0 falls through `?:` to the default 100, so it does not clamp to 1' );
$body = diviops_pc_body( 'preset_registry_doctor', array( 'limit' => -5 ) );
assert_same( 1, $body['data']['finding_count'] ?? null, 'a negative limit clamps up to 1' );

// ── Repair, dry-run: a plan, no write ─────────────────────────────────────

$before = diviops_pc_stored();
$body   = diviops_pc_body( 'preset_registry_doctor', array( 'repair' => true, 'dry_run' => true ) );
assert_same( true, $body['data']['repair'] ?? null, 'dry-run repair reports repair:true' );
assert_same( true, $body['data']['dry_run'] ?? null, 'dry-run repair reports dry_run:true' );
assert_same( false, $body['data']['mutated'] ?? null, 'dry-run repair reports mutated:false' );
assert_same( 'Repair 1 parseable preset timestamp(s).', $body['data']['plan']['summary'] ?? null, 'the plan summary counts only the repairable findings' );
assert_same( 1, count( $body['data']['plan']['changes'] ), 'the plan carries one change per repairable finding' );
assert_same( 'preset_registry.timestamp', $body['data']['plan']['changes'][0]['kind'], 'the change kind is preset_registry.timestamp' );
assert_same( 'module.divi/heading.items.ts-iso.created', $body['data']['plan']['changes'][0]['target'], 'the change targets the dotted path' );
assert_same( '2026-01-02T03:04:05.678Z', $body['data']['plan']['changes'][0]['before'], 'the change carries the stored value as before' );
assert_same( 1767323045678, $body['data']['plan']['changes'][0]['after'], 'the change carries the parsed milliseconds as after' );
assert_same(
	array( 'Unsupported timestamp at module.divi/heading.items.ts-junk.created will not be changed.' ),
	$body['data']['plan']['warnings'],
	'every unrepairable finding becomes a plan warning naming its path'
);
assert_same( $before, diviops_pc_stored(), 'a dry-run repair leaves the stored registry byte-identical' );

// ── Repair with nothing to repair: an explicit no-op, still no write ──────

diviops_pc_seed( array( 'module' => array( 'divi/heading' => array( 'default' => '', 'items' => array( 'ok' => array( 'name' => 'A', 'created' => 1735786800000 ) ) ) ) ) );
$body = diviops_pc_body( 'preset_registry_doctor', array( 'repair' => true ) );
assert_same( true, $body['data']['noop'] ?? null, 'a repair with no planned changes reports noop:true' );
assert_same( false, $body['data']['mutated'] ?? null, 'a no-op repair reports mutated:false' );
assert_same( false, array_key_exists( 'backup_option_name', $body['data'] ), 'a no-op repair creates no backup — it returns before add_option()' );

// ── Repair, apply: backup, mutate, read back ──────────────────────────────

$original = diviops_pc_seed( $timestamps );
$body     = diviops_pc_body( 'preset_registry_doctor', array( 'repair' => true ) );
assert_same( true, $body['data']['mutated'] ?? null, 'an applied repair reports mutated:true' );
assert_same( true, $body['data']['readback_verified'] ?? null, 'an applied repair reports readback_verified:true' );
assert_same( false, $body['data']['dry_run'] ?? null, 'an applied repair reports dry_run:false' );
$backup_name = $body['data']['backup_option_name'] ?? '';
assert_same( 1, preg_match( '/^diviops_preset_registry_backup_\d{8}_\d{6}_[A-Za-z0-9]{8}$/', (string) $backup_name ), 'the backup option name is the documented prefix plus a UTC stamp and an 8-character suffix' );
assert_same( $original, get_option( $backup_name, null ), 'the backup holds the pre-repair registry exactly' );
$after = diviops_pc_stored();
assert_same( 1767323045678, $after['module']['divi/heading']['items']['ts-iso']['created'], 'the repairable timestamp is rewritten in place as integer milliseconds' );
assert_same( 'not a date', $after['module']['divi/heading']['items']['ts-junk']['created'], 'the unrepairable timestamp is left exactly as found' );
assert_same( 1735786800000, $after['module']['divi/heading']['items']['ts-int']['updated'], 'an already-integer timestamp is untouched' );
assert_same( false, array_key_exists( 'created', $after['module']['divi/heading']['items']['ts-clean'] ), 'a preset with no timestamp fields gains none' );
delete_option( $backup_name );

// ── Chunk transients, and the coupling gate ───────────────────────────────
//
// `preset_registry_chunk_transients()` needs a $wpdb that answers get_col(); the
// shim's is final and exposes get_results() only, so the wrapper from
// preset-characterization-stubs.php is installed for this section and removed
// straight afterwards. Without it the whole chunk surface reports zero rows
// regardless of the options table, and the coupling gate below would pass while
// observing nothing.

$diviops_pc_real_wpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb']      = new DiviOps_Preset_Characterization_Wpdb( $diviops_pc_real_wpdb );

// Divi writes its chunked preset payload to transients named
// `et_global_preset_chunks_<n>`; WordPress stores a transient as the option
// `_transient_<name>` with its expiry in `_transient_timeout_<name>`
// (wp-includes/option.php, set_transient()). These three rows cover the
// classifier's three outcomes.
update_option( '_transient_et_global_preset_chunks_alpha', 'payload', false );
update_option( '_transient_timeout_et_global_preset_chunks_alpha', time() - 100, false );
update_option( '_transient_et_global_preset_chunks_beta', '', false );
update_option( '_transient_et_global_preset_chunks_gamma', 'payload', false );
update_option( '_transient_timeout_et_global_preset_chunks_gamma', time() + 3600, false );

diviops_pc_seed( $timestamps );
$body = diviops_pc_body( 'preset_registry_doctor', array() );
$rows = $body['data']['chunk_transients'] ?? array();
assert_same( 3, count( $rows ), 'every chunk transient is listed, candidate or not' );
assert_same( 3, $body['data']['chunk_transient_count'] ?? null, 'chunk_transient_count matches the row list' );
assert_same( '_transient_et_global_preset_chunks_alpha', $rows[0]['option_name'], 'rows come back ORDER BY option_name' );
assert_same( true, $rows[0]['stale'], 'a timeout in the past marks the row stale' );
assert_same( false, $rows[0]['failed'], 'a row with a payload is not failed' );
assert_same( true, $rows[0]['cleanup_candidate'], 'stale alone makes a row a cleanup candidate' );
assert_same( '_transient_timeout_et_global_preset_chunks_alpha', $rows[0]['timeout_option_name'], 'a present timeout row is named so it can be deleted alongside' );
assert_same( false, $rows[1]['stale'], 'a row with no timeout row at all is not stale' );
assert_same( true, $rows[1]['failed'], 'an empty-string payload marks the row failed' );
assert_same( null, $rows[1]['timeout_option_name'], 'an absent timeout row is reported as null rather than a name that does not exist' );
assert_same( true, $rows[1]['cleanup_candidate'], 'failed alone makes a row a cleanup candidate' );
assert_same( false, $rows[2]['stale'], 'a timeout in the future is not stale' );
assert_same( false, $rows[2]['cleanup_candidate'], 'a live row with a payload is not a candidate' );

// The gate: chunk cleanup is deliberately coupled to an actual timestamp repair
// (trait-preset.php:44). Asking to clear transients on a registry with nothing
// repairable must plan — and do — nothing.
diviops_pc_seed( array( 'module' => array( 'divi/heading' => array( 'default' => '', 'items' => array( 'ok' => array( 'name' => 'A', 'created' => 1735786800000 ) ) ) ) ) );
$body = diviops_pc_body( 'preset_registry_doctor', array( 'repair' => true, 'dry_run' => true, 'clear_chunk_transients' => true ) );
assert_same( 0, count( $body['data']['plan']['changes'] ), 'clear_chunk_transients plans nothing when no timestamp repair is available' );
assert_same( 'Repair 0 parseable preset timestamp(s).', $body['data']['plan']['summary'] ?? null, 'the summary drops the transient clause when the repair count is zero' );

// With a repair available, the same request plans the deletes and names them.
diviops_pc_seed( $timestamps );
$body    = diviops_pc_body( 'preset_registry_doctor', array( 'repair' => true, 'dry_run' => true, 'clear_chunk_transients' => true ) );
$kinds   = array_column( $body['data']['plan']['changes'], 'kind' );
$targets = array_column( $body['data']['plan']['changes'], 'target' );
assert_same(
	array( 'preset_registry.timestamp', 'preset_registry.chunk_transient_delete', 'preset_registry.chunk_timeout_delete', 'preset_registry.chunk_transient_delete' ),
	$kinds,
	'a coupled plan is the timestamp change, then each candidate row followed by its timeout row when one exists'
);
assert_same(
	array( 'module.divi/heading.items.ts-iso.created', '_transient_et_global_preset_chunks_alpha', '_transient_timeout_et_global_preset_chunks_alpha', '_transient_et_global_preset_chunks_beta' ),
	$targets,
	'only the two cleanup candidates are planned; the live row is not'
);
assert_same( 'Repair 1 parseable preset timestamp(s) and clear stale/failed chunk transients.', $body['data']['plan']['summary'] ?? null, 'the summary gains the transient clause once a repair exists' );

// Applied, the candidates and their timeout rows are gone and the live row stays.
diviops_pc_seed( $timestamps );
$body        = diviops_pc_body( 'preset_registry_doctor', array( 'repair' => true, 'clear_chunk_transients' => true ) );
$backup_name = $body['data']['backup_option_name'] ?? '';
assert_same(
	array( '_transient_et_global_preset_chunks_alpha', '_transient_et_global_preset_chunks_beta' ),
	$body['data']['cleared_chunk_transients'] ?? null,
	'cleared_chunk_transients names the transients removed, not their timeout rows'
);
assert_same( null, get_option( '_transient_et_global_preset_chunks_alpha', null ), 'a stale candidate is deleted' );
assert_same( null, get_option( '_transient_timeout_et_global_preset_chunks_alpha', null ), 'its timeout row is deleted with it' );
assert_same( null, get_option( '_transient_et_global_preset_chunks_beta', null ), 'a failed candidate is deleted' );
assert_same( 'payload', get_option( '_transient_et_global_preset_chunks_gamma', null ), 'a live chunk transient survives the cleanup' );
delete_option( $backup_name );
delete_option( '_transient_et_global_preset_chunks_gamma' );
delete_option( '_transient_timeout_et_global_preset_chunks_gamma' );

// truncated is an OR over both scans, so a limit shorter than the chunk list
// sets it even when there are no findings at all.
update_option( '_transient_et_global_preset_chunks_one', 'payload', false );
update_option( '_transient_et_global_preset_chunks_two', 'payload', false );
diviops_pc_seed( array( 'module' => array( 'divi/heading' => array( 'default' => '', 'items' => array( 'ok' => array( 'name' => 'A' ) ) ) ) ) );
$body = diviops_pc_body( 'preset_registry_doctor', array( 'limit' => 1 ) );
assert_same( 0, $body['data']['finding_count'] ?? null, 'this registry has no timestamp findings at all' );
assert_same( 1, $body['data']['chunk_transient_count'] ?? null, 'the limit also caps the chunk-transient SELECT' );
assert_same( true, $body['data']['truncated'] ?? null, 'truncated is set by the chunk scan alone, not only by findings' );
delete_option( '_transient_et_global_preset_chunks_one' );
delete_option( '_transient_et_global_preset_chunks_two' );

$GLOBALS['wpdb'] = $diviops_pc_real_wpdb;

// Proof the wrapper was doing real work and the section above was not observing
// an empty list by accident: with the shim's own $wpdb back in place, the
// method_exists( $wpdb, 'get_col' ) guard at trait-preset.php:193 short-circuits.
update_option( '_transient_et_global_preset_chunks_probe', 'payload', false );
$body = diviops_pc_body( 'preset_registry_doctor', array() );
assert_same( 0, $body['data']['chunk_transient_count'] ?? null, 'without a get_col()-capable $wpdb the chunk scan returns empty rather than erroring' );
delete_option( '_transient_et_global_preset_chunks_probe' );

// ══ preset_audit ══════════════════════════════════════════════════════════

diviops_pc_seed( diviops_pc_registry_fixture() );
$audit = diviops_pc_body( 'preset_audit' )['data'];

assert_same( 7, $audit['total_presets'], 'total_presets counts items across both buckets' );
assert_same( array(), $audit['page_refs'], 'with no posts registered the page-content scan contributes nothing' );

// Classification is a chain and the first arm wins: an item with neither attrs
// nor styleAttrs is an empty default even when its name is spam.
assert_same( 1, $audit['empty_default_count'], 'a preset with no attrs and no styleAttrs is an empty default, ahead of any spam test' );

// DEFECT: `empty_defaults` is the only one of the four classification buckets
// whose LIST is not returned — the response carries `spam_referenced`,
// `spam_unreferenced`, `descriptive` and `orphan_default_pointers`, but for
// empty defaults only the count (trait-preset.php:979-992). A caller told there
// are N empty defaults has no way to learn which they are.
assert_same( false, array_key_exists( 'empty_defaults', $audit ), 'DEFECT: preset_audit reports empty_default_count but never the list behind it' );

$spam_unref = array_column( $audit['spam_unreferenced'], 'id' );
$spam_ref   = array_column( $audit['spam_referenced'], 'id' );
$descriptive = array_column( $audit['descriptive'], 'id' );
assert_same( array( 'mod-clean', 'mod-chained', 'grp-orphan' ), $descriptive, 'everything with content and a non-spam name is descriptive' );
assert_same( array( 'grp-chained' ), $spam_ref, 'only a spam name that something actually references is spam_referenced' );

// DEFECT: `referenced` is `block_ref_count > 0 || group_ref_count > 0` and
// nothing else, so BEING THE BUCKET DEFAULT does not make a preset referenced.
// `mod-default` is the registered default for module/divi/heading and is
// nevertheless filed under `spam_unreferenced` — the list an operator reads as
// "safe to delete". preset_cleanup separately refuses to delete it, so the two
// halves of the same trait disagree about whether it is a deletion candidate.
assert_same( array( 'mod-default', 'mod-spam' ), $spam_unref, 'DEFECT: the bucket default is listed as spam_unreferenced alongside a genuinely unused preset' );
assert_same( 2, $audit['spam_unreferenced_count'], 'spam_unreferenced_count matches' );
assert_same( 1, $audit['spam_referenced_count'], 'spam_referenced_count matches' );
assert_same( 3, $audit['descriptive_count'], 'descriptive_count matches' );

$by_id = array();
foreach ( array_merge( $audit['spam_referenced'], $audit['spam_unreferenced'], $audit['descriptive'] ) as $row ) {
	$by_id[ $row['id'] ] = $row;
}
assert_same( 0, $by_id['mod-default']['ref_count'], 'the bucket default has no references' );
assert_same( false, $by_id['mod-default']['referenced'], 'and is reported as unreferenced' );
assert_same( true, $by_id['mod-default']['is_default'], 'while is_default — the flag that actually protects it — is set' );

// A group preset pulled in by a module preset's chain is referenced, and the
// referencing UUID is reported back — this is the #314 union that stops cleanup
// deleting load-bearing group presets.
assert_same( 1, $by_id['grp-chained']['group_ref_count'], 'a chain reference counts as a group ref' );
assert_same( 0, $by_id['grp-chained']['block_ref_count'], 'and not as a block ref' );
assert_same( true, $by_id['grp-chained']['referenced'], 'a chain-only group preset reads as referenced' );
assert_same( array( 'mod-chained' ), $by_id['grp-chained']['referenced_by_presets'], 'referenced_by_presets names the referencing preset' );
assert_same( false, array_key_exists( 'referenced_by_presets', $by_id['mod-chained'] ), 'referenced_by_presets is a group-bucket field only' );
assert_same( 1, $audit['total_referenced_uuids'], 'the referenced union is the one chain-referenced UUID' );

// has_attrs is an OR over attrs and styleAttrs, so a preset carrying only
// styleAttrs is content-bearing.
$style_only = diviops_pc_registry_fixture();
$style_only['module']['divi/heading']['items']['mod-empty'] = array( 'name' => 'Style Only', 'styleAttrs' => array( 'g' => 7 ) );
diviops_pc_seed( $style_only );
$audit = diviops_pc_body( 'preset_audit' )['data'];
assert_same( 0, $audit['empty_default_count'], 'a preset with styleAttrs but no attrs is not an empty default' );

// A `default` pointer that resolves to nothing is reported separately, and the
// preset itself is not invented.
$orphan_pointer = diviops_pc_registry_fixture();
$orphan_pointer['module']['divi/heading']['default'] = 'gone-uuid';
diviops_pc_seed( $orphan_pointer );
$audit = diviops_pc_body( 'preset_audit' )['data'];
assert_same(
	array( array( 'type' => 'module', 'module' => 'divi/heading', 'orphan_id' => 'gone-uuid' ) ),
	$audit['orphan_default_pointers'],
	'a default pointer with no matching item is reported with its bucket coordinates'
);
assert_same( 1, $audit['orphan_default_pointer_count'], 'orphan_default_pointer_count matches' );
assert_same( 7, $audit['total_presets'], 'an orphan pointer does not add a phantom preset to the count' );

// ══ is_spam_preset_name / clean_spam_preset_name ══════════════════════════
//
// Detection is an unanchored repeated-phrase match of 1-4 words
// (trait-preset.php:875). Cleaning is a REPLACEMENT ANCHORED AT THE START
// (trait-preset.php:886). The two do not agree, which is the defect pinned
// below.

assert_same( true, diviops_call_static( 'is_spam_preset_name', array( 'Text Text' ) ), 'a doubled single word is spam' );
assert_same( true, diviops_call_static( 'is_spam_preset_name', array( 'Online Courses Online Courses Text' ) ), 'a doubled multi-word phrase is spam' );
assert_same( true, diviops_call_static( 'is_spam_preset_name', array( 'text TEXT' ) ), 'the match is case-insensitive' );
assert_same( false, diviops_call_static( 'is_spam_preset_name', array( 'Hero Banner' ) ), 'two different words are not spam' );
assert_same( false, diviops_call_static( 'is_spam_preset_name', array( '' ) ), 'an empty name is never spam' );
assert_same( false, diviops_call_static( 'is_spam_preset_name', array( 'Texts Text' ) ), 'the word-boundary anchors stop a prefix from matching' );

assert_same( 'Text', diviops_call_static( 'clean_spam_preset_name', array( 'Text Text' ) ), 'a leading doubled word collapses to one' );
assert_same( 'Online Courses Text', diviops_call_static( 'clean_spam_preset_name', array( 'Online Courses Online Courses Text' ) ), 'a leading doubled phrase collapses and the tail survives' );
assert_same( 'Online Courses Text', diviops_call_static( 'clean_spam_preset_name', array( 'Online Courses Online Courses Online Courses Text' ) ), 'three repeats collapse to one' );

// DEFECT: detection is unanchored, cleaning is anchored at `^`. A repetition
// that is not at the start of the name is therefore detected as spam and then
// left completely unchanged. In preset_cleanup this lands in the
// `$is_spam && ( $is_ref || $is_default )` arm, computes `$clean_name === $name`,
// records no rename, and counts the preset as kept — so a referenced spam name
// of this shape is reported as spam by audit forever and never repaired.
assert_same( true, diviops_call_static( 'is_spam_preset_name', array( 'Card Title Title' ) ), 'a repetition in the middle of a name is detected as spam' );
assert_same( 'Card Title Title', diviops_call_static( 'clean_spam_preset_name', array( 'Card Title Title' ) ), 'DEFECT: the cleaner is anchored at ^ and leaves that same name untouched' );

// ══ preset_cleanup ════════════════════════════════════════════════════════
//
// Every destructive branch here is gated by the same three facts — is the
// preset referenced, is it the bucket default, and is its name spam — so each
// gate is exercised on its own.

// ── Default mode, dry-run: plan only, no write ───────────────────────────

$original = diviops_pc_seed( diviops_pc_registry_fixture() );
$body     = diviops_pc_body( 'preset_cleanup' )['data'];
assert_same( true, $body['dry_run'], 'preset_cleanup defaults to dry_run' );
assert_same( array( 'mod-spam', 'mod-empty' ), array_column( $body['removed'], 'id' ), 'default mode removes unreferenced, non-default spam — content-bearing or not' );
assert_same( array( 'mod-default', 'grp-chained' ), array_column( $body['renamed'], 'id' ), 'default mode renames spam it will not remove: the bucket default and the chain-referenced group preset' );
assert_same( 'Heading Hero', $body['renamed'][0]['new_name'], 'the rename is the collapsed name' );
assert_same( 'Heading Heading Hero', $body['renamed'][0]['old_name'], 'the rename carries the original for the operator' );
assert_same( 5, $body['kept_count'], 'kept_count is every item the removal pass did not take' );
assert_same( $original, diviops_pc_stored(), 'a dry-run cleanup leaves the registry byte-identical' );

// The dry-run plan is the shared `dry_run_response()` envelope
// (trait-core.php:2423), and preset_cleanup_dry_run_changes() builds its rows —
// every delete first, then every rename, then every dedup, regardless of the
// order the passes discovered them.
assert_same(
	'Would clean presets: remove 2, rename 2, dedupe 0, keep 5.',
	$body['plan']['summary'],
	'the plan summary reports all four counters'
);
assert_same( array( 'preset.delete', 'preset.delete', 'preset.rename', 'preset.rename' ), array_column( $body['plan']['changes'], 'kind' ), 'plan rows are grouped by kind, not interleaved in discovery order' );
assert_same( 'preset/divi/heading/mod-spam', $body['plan']['changes'][0]['target'], 'the target is preset/<bucketKey>/<uuid>' );
assert_same( null, $body['plan']['changes'][0]['after'], 'a delete has no after state' );
assert_same( array( 'name' => 'Heading Heading Hero' ), $body['plan']['changes'][2]['before'], 'the rename before carries the old name' );
assert_same( array( 'name' => 'Heading Hero' ), $body['plan']['changes'][2]['after'], 'the rename after carries the new name' );

// ── Default mode, apply: the registry actually changes ───────────────────

diviops_pc_seed( diviops_pc_registry_fixture() );
$body   = diviops_pc_body( 'preset_cleanup', array( 'dry_run' => false ) )['data'];
$stored = diviops_pc_stored();
assert_same( false, $body['dry_run'], 'an applied cleanup reports dry_run:false' );
assert_same( false, array_key_exists( 'plan', $body ), 'an applied cleanup emits no plan' );
assert_same( false, isset( $stored['module']['divi/heading']['items']['mod-spam'] ), 'the unreferenced spam preset is gone from storage' );
assert_same( 'Heading Hero', $stored['module']['divi/heading']['items']['mod-default']['name'], 'the default spam preset was renamed in place, not removed' );
assert_same( true, isset( $stored['module']['divi/heading']['items']['mod-default'] ), 'and it still exists' );
assert_same( 'Font Body', $stored['group']['divi/font']['items']['grp-chained']['name'], 'the chain-referenced group preset was renamed, not removed' );
assert_same( true, isset( $stored['group']['divi/font']['items']['grp-orphan'] ), 'a non-spam orphan is untouched by default mode' );

// ── remove_orphans, scope=spam: name is the extra gate ───────────────────

diviops_pc_seed( diviops_pc_registry_fixture() );
$body = diviops_pc_body( 'preset_cleanup', array( 'action' => 'remove_orphans', 'scope' => 'spam' ) )['data'];
assert_same( 'remove_orphans', $body['action'], 'the action is echoed back' );
assert_same( 'spam', $body['scope'], 'the scope is echoed back' );
assert_same( array( 'mod-spam', 'mod-empty' ), array_column( $body['removed'], 'id' ), 'scope=spam removes only spam-named orphans — mod-clean and grp-orphan are untouched' );
assert_same( 'Would remove 2 unreferenced spam preset(s).', $body['plan']['summary'], 'the summary names the scope' );

// An unrecognised scope silently becomes `spam` rather than being refused.
diviops_pc_seed( diviops_pc_registry_fixture() );
$body = diviops_pc_body( 'preset_cleanup', array( 'action' => 'remove_orphans', 'scope' => 'everything' ) )['data'];
assert_same( 'spam', $body['scope'], 'an unrecognised scope falls back to spam instead of being refused' );

// ── remove_orphans, scope=all: the two protective gates on their own ─────

diviops_pc_seed( diviops_pc_registry_fixture() );
$body = diviops_pc_body( 'preset_cleanup', array( 'action' => 'remove_orphans', 'scope' => 'all', 'dry_run' => false ) )['data'];
$stored = diviops_pc_stored();
assert_same(
	array( 'mod-spam', 'mod-clean', 'mod-empty', 'mod-chained', 'grp-orphan' ),
	array_column( $body['removed'], 'id' ),
	'scope=all removes every unreferenced non-default preset regardless of name'
);
assert_same( true, isset( $stored['module']['divi/heading']['items']['mod-default'] ), 'the bucket default survives scope=all — the is_default gate' );
assert_same( true, isset( $stored['group']['divi/font']['items']['grp-chained'] ), 'the chain-referenced group preset survives scope=all — the referenced gate' );
assert_same( 2, $body['kept_count'], 'kept_count is the two protected presets' );

// DEFECT: the referenced set is computed ONCE, before the removal pass. In this
// run `mod-chained` is deleted (nothing references it) while `grp-chained` is
// protected BY `mod-chained`'s now-deleted chain ref. The registry left behind
// is therefore not a fixed point: running the identical command again deletes
// the group preset the first run went out of its way to protect. An operator who
// reads "removed 5, kept 2" has no way to see that the second number is
// temporary.
$body   = diviops_pc_body( 'preset_cleanup', array( 'action' => 'remove_orphans', 'scope' => 'all', 'dry_run' => false ) )['data'];
$stored = diviops_pc_stored();
assert_same( array( 'grp-chained' ), array_column( $body['removed'], 'id' ), 'DEFECT: a second identical run deletes the preset the first run protected' );
assert_same( false, isset( $stored['group']['divi/font']['items']['grp-chained'] ), 'and it is gone from storage' );

// Proof the chain union is load-bearing rather than incidental: strip the chain
// and the same request deletes the group preset it was protecting.
$unchained = diviops_pc_registry_fixture();
unset( $unchained['module']['divi/heading']['items']['mod-chained']['groupPresets'] );
diviops_pc_seed( $unchained );
$body = diviops_pc_body( 'preset_cleanup', array( 'action' => 'remove_orphans', 'scope' => 'all' ) )['data'];
assert_same( true, in_array( 'grp-chained', array_column( $body['removed'], 'id' ), true ), 'without the chain ref the same preset becomes removable' );

// ── rename_strip_prefix ─────────────────────────────────────────────────

$prefixed = array(
	'module' => array(
		'divi/heading' => array(
			'default' => '',
			'items'   => array(
				'p1' => array( 'name' => 'DiviOps Hero', 'attrs' => array( 'a' => 1 ) ),
				'p2' => array( 'name' => 'DiviOps', 'attrs' => array( 'b' => 2 ) ),
				'p3' => array( 'name' => 'Untouched', 'attrs' => array( 'c' => 3 ) ),
			),
		),
	),
);
diviops_pc_seed( $prefixed );
$body   = diviops_pc_body( 'preset_cleanup', array( 'action' => 'rename_strip_prefix', 'prefix' => 'DiviOps ', 'dry_run' => false ) )['data'];
$stored = diviops_pc_stored();
assert_same( array( 'p1' ), array_column( $body['renamed'], 'id' ), 'only names that start with the prefix are renamed' );

// DEFECT: `prefix` is run through `sanitize_text_field()` (trait-preset.php:1007),
// which collapses whitespace runs and trims — WordPress core's own behaviour, at
// `wp-includes/formatting.php` `_sanitize_text_fields()`. So a caller asking to
// strip `"DiviOps "` actually strips `"DiviOps"`, and every renamed preset is
// left with a leading space. There is no request shape that strips the separator,
// and the handler reports the rename as if it had succeeded cleanly.
assert_same( ' Hero', $stored['module']['divi/heading']['items']['p1']['name'], 'DEFECT: the trailing space is trimmed off the prefix, so the stripped name keeps a leading space' );
assert_same( 'DiviOps', $stored['module']['divi/heading']['items']['p2']['name'], 'a name that is exactly the prefix is skipped — stripping it would leave nothing' );
assert_same( 'Untouched', $stored['module']['divi/heading']['items']['p3']['name'], 'a non-matching name is untouched' );
assert_same( 3, $body['kept_count'], 'kept_count in this action counts every item walked, renamed ones included' );
assert_same( 'DiviOps', $body['prefix'], 'and the response echoes the sanitized prefix, not the one that was asked for' );

// An empty prefix falls through to default cleanup rather than stripping
// nothing: the guard is `'' !== $prefix`, not the action alone.
diviops_pc_seed( $prefixed );
$body = diviops_pc_body( 'preset_cleanup', array( 'action' => 'rename_strip_prefix', 'prefix' => '' ) )['data'];
assert_same( false, array_key_exists( 'prefix', $body ), 'an empty prefix skips the strip branch entirely and runs the default pass' );

// ── dedup ───────────────────────────────────────────────────────────────

$dupes = array(
	'module' => array(
		'divi/heading' => array(
			'default' => 'd-default',
			'items'   => array(
				'd-default' => array( 'name' => 'Kept Default', 'attrs' => array( 'same' => true ) ),
				'd-dupe'    => array( 'name' => 'Duplicate', 'attrs' => array( 'same' => true ) ),
				'd-other'   => array( 'name' => 'Different', 'attrs' => array( 'other' => true ) ),
				'd-noattrs' => array( 'name' => 'No Attrs' ),
			),
		),
	),
);
diviops_pc_seed( $dupes );
$body   = diviops_pc_body( 'preset_cleanup', array( 'dedup' => true, 'dry_run' => false ) )['data'];
$stored = diviops_pc_stored();
assert_same( array( 'd-dupe' ), array_column( $body['deduped'], 'id' ), 'the unprotected member of an identical-attrs pair is deduped' );
assert_same( 'd-default', $body['deduped'][0]['kept_id'], 'the surviving UUID is reported as kept_id' );
assert_same( false, isset( $stored['module']['divi/heading']['items']['d-dupe'] ), 'and it is gone from storage' );
assert_same( true, isset( $stored['module']['divi/heading']['items']['d-default'] ), 'the bucket default survives the dedup' );
assert_same( true, isset( $stored['module']['divi/heading']['items']['d-noattrs'] ), 'a preset with no attrs is skipped by the hash pass entirely' );

// Both protected: the pass keeps both rather than picking a winner.
$both_default = $dupes;
$both_default['module']['divi/heading']['items'] = array(
	'd-a' => array( 'name' => 'A', 'attrs' => array( 'same' => true ) ),
	'd-b' => array( 'name' => 'B', 'attrs' => array( 'same' => true ) ),
);
$both_default['module']['divi/heading']['default'] = 'd-a';
$both_default['group'] = array( 'divi/font' => array( 'default' => '', 'items' => array(
	'g-a' => array( 'name' => 'GA', 'attrs' => array( 'x' => 1 ), 'groupPresets' => array() ),
) ) );
// Make d-b chain-referenced so both members of the pair are protected.
$both_default['module']['divi/heading']['items']['d-c'] = array(
	'name'         => 'C',
	'attrs'        => array( 'zzz' => 1 ),
	'groupPresets' => array( 'divi/font' => array( 'presetId' => 'd-b' ) ),
);
diviops_pc_seed( $both_default );
$body   = diviops_pc_body( 'preset_cleanup', array( 'dedup' => true, 'dry_run' => false ) )['data'];
$stored = diviops_pc_stored();
assert_same( array(), $body['deduped'], 'when both members of a pair are protected, neither is deduped' );
assert_same( true, isset( $stored['module']['divi/heading']['items']['d-a'] ), 'the default survives' );
assert_same( true, isset( $stored['module']['divi/heading']['items']['d-b'] ), 'the chain-referenced duplicate survives' );

// ══ preset_update ═════════════════════════════════════════════════════════

diviops_pc_seed( diviops_pc_registry_fixture() );
$response = diviops_pc_call( 'preset_update', array( 'preset_id' => 'no-such-uuid', 'name' => 'X' ) );
$body     = $response->get_data();
assert_same( false, $body['ok'], 'preset_update refuses an unknown UUID' );
assert_same( 'not_found', $body['error']['code'], 'the refusal code is not_found' );
assert_same( 404, $response->get_status(), 'an unknown UUID is a 404' );
assert_same( "Preset 'no-such-uuid' not found", $body['error']['message'], 'the refusal quotes the UUID it was given' );

$body = diviops_pc_body( 'preset_update', array( 'preset_id' => 'mod-clean', 'name' => 'Renamed', 'attrs' => array( 'z' => 1 ), 'priority' => 20, 'dry_run' => true ) )['data'];
assert_same( true, $body['dry_run'], 'a dry-run update reports dry_run:true' );
assert_same(
	"Would update preset 'mod-clean' (module/divi/heading) — name, attrs+styleAttrs+renderAttrs, priority.",
	$body['plan']['summary'],
	'the summary lists the fields that would change, with attrs named as the three-bag mirror it really writes'
);
assert_same( 'preset.update', $body['plan']['changes'][0]['kind'], 'the change kind is preset.update' );
assert_same( 'preset/module/divi/heading/mod-clean', $body['plan']['changes'][0]['target'], 'the target is preset/<type>/<bucketKey>/<uuid>' );
assert_same( array( 'name', 'attrs+styleAttrs+renderAttrs', 'priority' ), $body['plan']['changes'][0]['after']['fields'], 'the change carries the field list' );

// A field of the wrong type is dropped from the plan rather than refused: attrs
// must be an array and priority must be numeric.
$body = diviops_pc_body( 'preset_update', array( 'preset_id' => 'mod-clean', 'attrs' => 'not an array', 'priority' => 'soon', 'dry_run' => true ) )['data'];
assert_same( "Would update preset 'mod-clean' (module/divi/heading) — no fields (no-op).", $body['plan']['summary'], 'a non-array attrs and a non-numeric priority are silently ignored rather than refused' );
assert_same( array(), $body['plan']['changes'][0]['after']['fields'], 'the field list is empty' );

// The dry-run branch is checked AFTER the in-memory registry has already been
// mutated, so it is only the missing save_d5_presets() call that makes it safe.
assert_same( 'Hero Banner', diviops_pc_stored()['module']['divi/heading']['items']['mod-clean']['name'], 'a dry-run update writes nothing to storage' );

// ── Apply: the three-bag mirror, and the touched timestamp ───────────────

diviops_pc_seed( diviops_pc_registry_fixture() );
$before_seconds = time();
assert_same( true, diviops_pc_wrote( 'preset_update', array( 'preset_id' => 'mod-clean', 'name' => 'Updated Name', 'attrs' => array( 'z' => 1 ), 'priority' => 20 ) ), 'an applied update reaches the storage-meta step, so the write above it ran' );
$updated = diviops_pc_stored()['module']['divi/heading']['items']['mod-clean'];
assert_same( 'Updated Name', $updated['name'], 'the name is written' );
assert_same( array( 'z' => 1 ), $updated['attrs'], 'attrs are written' );
assert_same( array( 'z' => 1 ), $updated['styleAttrs'], 'and mirrored into styleAttrs — Divi Pass A reads this bag' );
assert_same( array( 'z' => 1 ), $updated['renderAttrs'], 'and into renderAttrs — Divi Pass B reads this one, at higher specificity' );
assert_same( 20, $updated['priority'], 'a numeric priority is cast to int and written' );
// `updated` is `time() * 1000` (trait-preset.php:1410) — seconds, not
// microseconds, so the expected value is bounded by the clock either side of
// the call rather than read back from the handler.
assert_same( true, is_int( $updated['updated'] ), 'updated is written as an integer' );
assert_same( true, $updated['updated'] >= $before_seconds * 1000 && $updated['updated'] <= ( time() + 1 ) * 1000, 'updated is wall-clock seconds scaled to milliseconds, not microseconds' );

// A partial update leaves the untouched fields exactly as they were, and still
// stamps `updated` — so `updated` moves even on a request that changes nothing.
diviops_pc_seed( diviops_pc_registry_fixture() );
assert_same( true, diviops_pc_wrote( 'preset_update', array( 'preset_id' => 'mod-clean' ) ), 'an update naming no fields still reaches the write' );
$untouched = diviops_pc_stored()['module']['divi/heading']['items']['mod-clean'];
assert_same( 'Hero Banner', $untouched['name'], 'a field not named in the request is left alone' );
assert_same( array( 'c' => 3 ), $untouched['attrs'], 'and so are its attrs' );
assert_same( true, isset( $untouched['updated'] ), 'but the updated stamp is written regardless — a no-field update is not a no-op in storage' );

// The walk finds a preset in the group bucket too, not just the module bucket.
diviops_pc_seed( diviops_pc_registry_fixture() );
assert_same( true, diviops_pc_wrote( 'preset_update', array( 'preset_id' => 'grp-orphan', 'name' => 'Group Renamed' ) ), 'the update walk reaches the group bucket' );
assert_same( 'Group Renamed', diviops_pc_stored()['group']['divi/font']['items']['grp-orphan']['name'], 'and writes there' );

// ══ preset_delete ═════════════════════════════════════════════════════════

diviops_pc_seed( diviops_pc_registry_fixture() );
$response = diviops_pc_call( 'preset_delete', array( 'preset_id' => 'nope' ) );
$body     = $response->get_data();
assert_same( 'not_found', $body['error']['code'], 'preset_delete refuses an unknown UUID' );
assert_same( 404, $response->get_status(), 'an unknown UUID is a 404' );

$response = diviops_pc_call( 'preset_delete', array( 'preset_id' => 'mod-default' ) );
$body     = $response->get_data();
assert_same( false, $body['ok'], 'preset_delete refuses to delete a bucket default without force' );
assert_same( 'conflict', $body['error']['code'], 'the refusal code is conflict' );
assert_same( 409, $response->get_status(), 'a default-pointer conflict is a 409' );
assert_same( 'is_default', $body['error']['data']['reason'], 'the refusal states why' );
assert_same( 'mod-default', $body['error']['data']['preset_id'], 'the refusal carries the UUID' );
assert_same( 'module', $body['error']['data']['type'], 'the refusal carries the bucket' );
assert_same( 'divi/heading', $body['error']['data']['module'], 'the refusal carries the bucket key' );
assert_same( 'Heading Heading Hero', $body['error']['data']['name'], 'the refusal carries the preset name' );
assert_same( diviops_pc_registry_fixture(), diviops_pc_stored(), 'a refused delete leaves the registry byte-identical' );

// The guard is scoped to the bucket's own default: another bucket naming this
// UUID as ITS default must not protect it.
$other_default = diviops_pc_registry_fixture();
$other_default['group']['divi/font']['default'] = 'mod-clean';
diviops_pc_seed( $other_default );
assert_same( true, diviops_pc_wrote( 'preset_delete', array( 'preset_id' => 'mod-clean' ) ), 'a UUID named as ANOTHER bucket default is not protected by that pointer' );
$stored = diviops_pc_stored();
assert_same( false, isset( $stored['module']['divi/heading']['items']['mod-clean'] ), 'and the delete goes through' );
assert_same( 'mod-clean', $stored['group']['divi/font']['default'], 'DEFECT: the stale pointer in the other bucket is left dangling — the delete only clears its own bucket' );
assert_same( 4, count( $stored['module']['divi/heading']['items'] ), 'the deleted UUID is the only item removed' );
assert_same( 'mod-default', $stored['module']['divi/heading']['default'], 'and its own bucket pointer is untouched' );

// force=true deletes the bucket default and clears the pointer in the same
// write — the documented opt-out from the 409 above.
diviops_pc_seed( diviops_pc_registry_fixture() );
assert_same( true, diviops_pc_wrote( 'preset_delete', array( 'preset_id' => 'mod-default', 'force' => true ) ), 'force=true gets past the default-pointer guard' );
$stored = diviops_pc_stored();
assert_same( false, isset( $stored['module']['divi/heading']['items']['mod-default'] ), 'the default preset is removed' );
assert_same( '', $stored['module']['divi/heading']['default'], 'and the pointer is cleared in the same write rather than left dangling' );

// ══ preset_set_default ════════════════════════════════════════════════════

// ── Bucket-addressed mode ───────────────────────────────────────────────

diviops_pc_seed( diviops_pc_registry_fixture() );
$response = diviops_pc_call( 'preset_set_default', array( 'type' => 'module', 'module' => 'divi/heading' ) );
$body     = $response->get_data();
assert_same( 'invalid_input', $body['error']['code'], 'bucket-addressed mode without unset=true is refused' );
assert_same( 400, $response->get_status(), 'that refusal is a 400' );
assert_same( 'unset', $body['error']['data']['field'], 'the refusal names the field' );
assert_same( true, $body['error']['data']['expected'], 'and the value it expected' );
assert_same( false, $body['error']['data']['received'], 'and what it got' );

$response = diviops_pc_call( 'preset_set_default', array( 'type' => 'module', 'module' => 'divi/nope', 'unset' => true ) );
assert_same( 'not_found', $response->get_data()['error']['code'], 'an unknown bucket is a not_found' );
assert_same( 404, $response->get_status(), 'that refusal is a 404' );

$original = diviops_pc_seed( diviops_pc_registry_fixture() );
$body     = diviops_pc_body( 'preset_set_default', array( 'type' => 'module', 'module' => 'divi/heading', 'unset' => true, 'dry_run' => true ) )['data'];
assert_same( "Would clear default-preset pointer for module/divi/heading (was: 'mod-default').", $body['plan']['summary'], 'the dry-run summary quotes the pointer it would clear' );
assert_same( 'preset.set_default', $body['plan']['changes'][0]['kind'], 'the change kind is preset.set_default' );
assert_same( 'preset/module/divi/heading', $body['plan']['changes'][0]['target'], 'the bucket-addressed target has no UUID segment' );
assert_same( array( 'default' => 'mod-default' ), $body['plan']['changes'][0]['before'], 'before carries the current pointer' );
assert_same( array( 'default' => '' ), $body['plan']['changes'][0]['after'], 'after carries the empty pointer' );
assert_same( $original, diviops_pc_stored(), 'the dry-run writes nothing' );

$body   = diviops_pc_body( 'preset_set_default', array( 'type' => 'module', 'module' => 'divi/heading', 'unset' => true ) )['data'];
$stored = diviops_pc_stored();
assert_same( '', $stored['module']['divi/heading']['default'], 'the applied clear empties the pointer' );
assert_same( true, isset( $stored['module']['divi/heading']['items']['mod-default'] ), 'and leaves the preset itself in place' );
assert_same( 'mod-default', $body['preset']['was_default_id'], 'the response reports the pointer that was cleared' );
assert_same( '', $body['preset']['new_default_id'], 'and the new empty pointer' );
assert_same( false, $body['preset']['is_default'], 'and that nothing is default now' );
assert_same( 'Default preset cleared for module/divi/heading.', $body['message'], 'the message names the bucket' );

// DEFECT: this is the only registry write in the trait that does not run its
// response through attach_meta()/d5_preset_write_meta(), so a caller of the
// bucket-addressed clear gets no `_meta.canonical_path` and no
// `legacy_path_detected` advisory — the very hint that tells an agent a second
// storage path also holds presets. Every sibling write (create, update, delete,
// preset_id-mode set_default) attaches it.
assert_same( false, array_key_exists( '_meta', diviops_pc_body( 'preset_set_default', array( 'type' => 'group', 'module' => 'divi/font', 'unset' => true ) ) ), 'DEFECT: the bucket-addressed clear returns no _meta, unlike every other write in this trait' );

// ── preset_id mode ──────────────────────────────────────────────────────

diviops_pc_seed( diviops_pc_registry_fixture() );
$response = diviops_pc_call( 'preset_set_default', array() );
$body     = $response->get_data();
assert_same( 'invalid_input', $body['error']['code'], 'neither addressing mode supplied is refused' );
assert_same( array( 'preset_id|type+module+unset' ), $body['error']['data']['requires'], 'the refusal states both accepted shapes' );

$response = diviops_pc_call( 'preset_set_default', array( 'preset_id' => 'nope' ) );
assert_same( 'not_found', $response->get_data()['error']['code'], 'an unknown preset_id is a not_found' );
assert_same( 404, $response->get_status(), 'that refusal is a 404' );

$body = diviops_pc_body( 'preset_set_default', array( 'preset_id' => 'mod-clean', 'dry_run' => true ) )['data'];
assert_same( "Would set default preset for module/divi/heading to 'mod-clean' ('Hero Banner', was: 'mod-default').", $body['plan']['summary'], 'the set summary names the new preset, its name, and the pointer it replaces' );
assert_same( array( 'default' => 'mod-clean' ), $body['plan']['changes'][0]['after'], 'after carries the new pointer' );

$body = diviops_pc_body( 'preset_set_default', array( 'preset_id' => 'mod-clean', 'unset' => true, 'dry_run' => true ) )['data'];
assert_same( "Would clear default-preset pointer for module/divi/heading (was: 'mod-default').", $body['plan']['summary'], 'unset=true in preset_id mode reads as a clear, not a set' );
assert_same( array( 'default' => '' ), $body['plan']['changes'][0]['after'], 'and plans an empty pointer' );

// preset_id wins when both addressing shapes are supplied, so a mismatched
// type/module pair is ignored rather than refused.
$body = diviops_pc_body( 'preset_set_default', array( 'preset_id' => 'mod-clean', 'type' => 'group', 'module' => 'divi/font', 'unset' => true, 'dry_run' => true ) )['data'];
assert_same( "Would clear default-preset pointer for module/divi/heading (was: 'mod-default').", $body['plan']['summary'], 'preset_id takes precedence and a contradicting type+module pair is ignored' );

// Applied, preset_id mode moves the pointer inside the preset's own bucket.
diviops_pc_seed( diviops_pc_registry_fixture() );
assert_same( true, diviops_pc_wrote( 'preset_set_default', array( 'preset_id' => 'mod-clean' ) ), 'an applied set reaches the storage-meta step' );
$stored = diviops_pc_stored();
assert_same( 'mod-clean', $stored['module']['divi/heading']['default'], 'the pointer moves to the named preset' );
assert_same( '', $stored['group']['divi/font']['default'], 'and no other bucket is touched' );

diviops_pc_seed( diviops_pc_registry_fixture() );
assert_same( true, diviops_pc_wrote( 'preset_set_default', array( 'preset_id' => 'mod-clean', 'unset' => true ) ), 'an applied clear reaches the storage-meta step' );
assert_same( '', diviops_pc_stored()['module']['divi/heading']['default'], 'DEFECT: unset=true with a preset_id clears the bucket pointer even when that preset was not the default — the request names mod-clean and the pointer it erases is mod-default' );

// ══ preset_create ═════════════════════════════════════════════════════════

diviops_pc_seed( diviops_pc_registry_fixture() );

$response = diviops_pc_call( 'preset_create', array() );
$body     = $response->get_data();
assert_same( 'invalid_input', $body['error']['code'], 'preset_create refuses a request with nothing supplied' );
assert_same( 400, $response->get_status(), 'that refusal is a 400' );
assert_same( array( 'module_name', 'name', 'attrs' ), $body['error']['data']['missing'], 'the refusal lists every missing required field at once' );

$body = diviops_pc_body( 'preset_create', array( 'module_name' => 'divi/heading', 'name' => 'X', 'attrs' => array( 'a' => 1 ), 'type' => 'section' ) );
assert_same( 'invalid_input', $body['error']['code'], 'an unsupported type is refused' );
assert_same( array( 'module', 'group' ), $body['error']['data']['allowed'], 'the refusal lists the allowed types' );
assert_same( 'section', $body['error']['data']['received'], 'and echoes what it got' );

$body = diviops_pc_body( 'preset_create', array( 'module_name' => 'divi/heading', 'name' => 'X', 'attrs' => array( 'a' => 1 ), 'type' => 'group' ) );
assert_same( 'invalid_input', $body['error']['code'], 'a group preset without its group coordinates is refused' );
assert_same( array( 'group_name', 'group_id' ), $body['error']['data']['missing'], 'the refusal lists both missing group fields' );
assert_same( 'type', $body['error']['data']['rejected_field'], 'the refusal names type as the field that made them required' );

$response = diviops_pc_call( 'preset_create', array( 'module_name' => 'divi/heading', 'name' => 'Hero Banner', 'attrs' => array( 'a' => 1 ) ) );
$body     = $response->get_data();
assert_same( 'conflict', $body['error']['code'], 'a duplicate name inside the same bucket is refused' );
assert_same( 409, $response->get_status(), 'a name collision is a 409' );
assert_same( 'mod-clean', $body['error']['data']['existing_preset_id'], 'the refusal names the UUID already holding that name' );
assert_same( 'module', $body['error']['data']['bucket'], 'and the bucket' );
assert_same( 'divi/heading', $body['error']['data']['bucket_key'], 'and the bucket key' );

// Uniqueness is per bucket, not global: the same name under a different bucket
// key is accepted.
$body = diviops_pc_body( 'preset_create', array( 'module_name' => 'divi/text', 'name' => 'Hero Banner', 'attrs' => array( 'a' => 1 ), 'dry_run' => true ) );
assert_same( true, $body['ok'], 'the same name in a different bucket is not a collision' );

// DEFECT: for `type=group` the collision walk uses `group_name` as the bucket
// key, but the group's OWN item is stored under `group_name` too — so a group
// preset colliding with a module preset of the same name under the same
// `module_name` is NOT detected, while a module preset colliding with a group
// bucket of the same key IS. The two addressing halves disagree.
$cross = diviops_pc_registry_fixture();
$cross['group']['divi/heading'] = array( 'default' => '', 'items' => array( 'g-x' => array( 'name' => 'Hero Banner' ) ) );
diviops_pc_seed( $cross );
$body = diviops_pc_body( 'preset_create', array( 'module_name' => 'divi/heading', 'name' => 'Hero Banner', 'attrs' => array( 'a' => 1 ), 'type' => 'group', 'group_name' => 'divi/font', 'group_id' => 'font', 'dry_run' => true ) );
assert_same( true, $body['ok'], 'DEFECT: a group preset is checked against group/<group_name> only, so a same-named module preset under module_name never collides' );

diviops_pc_seed( diviops_pc_registry_fixture() );
$body = diviops_pc_body( 'preset_create', array( 'module_name' => 'divi/heading', 'name' => 'Brand New', 'attrs' => array( 'a' => 1 ), 'make_default' => true, 'priority' => 5, 'dry_run' => true ) )['data'];
assert_same(
	"Would create module preset 'Brand New' under module/divi/heading (existing items: 5, marking new preset as default).",
	$body['plan']['summary'],
	'the summary reports the bucket, its current item count, and the make_default intent'
);
assert_same( 'preset.create', $body['plan']['changes'][0]['kind'], 'the change kind is preset.create' );
assert_same( 'preset/module/divi/heading', $body['plan']['changes'][0]['target'], 'the target is the bucket, since no UUID exists yet' );
assert_same( 5, $body['plan']['changes'][0]['after']['priority'], 'a numeric priority is carried into the plan as an int' );
assert_same( null, $body['plan']['changes'][0]['after']['group_name'], 'group coordinates are null in the plan for a module preset' );
assert_same( 'UUID is generated at apply time; dry_run does not pre-allocate it.', $body['note'], 'the plan says explicitly that no UUID is reserved' );
assert_same( array( 'existing_items' => 5, 'current_default' => 'mod-default' ), $body['bucket_state'], 'the plan reports the bucket state it read' );
assert_same( diviops_pc_registry_fixture(), diviops_pc_stored(), 'the dry-run writes nothing' );

// ── Apply: the minted record ────────────────────────────────────────────

diviops_pc_seed( diviops_pc_registry_fixture() );
$before_ms = round( microtime( true ) * 1000 );
assert_same( true, diviops_pc_wrote( 'preset_create', array( 'module_name' => 'divi/heading', 'name' => 'Brand New', 'attrs' => array( 'a' => 1 ), 'priority' => 5 ) ), 'an applied create reaches the storage-meta step' );
$items = diviops_pc_stored()['module']['divi/heading']['items'];
$minted = array_values( array_diff( array_keys( $items ), array_keys( diviops_pc_registry_fixture()['module']['divi/heading']['items'] ) ) );
assert_same( 1, count( $minted ), 'exactly one item is minted' );
// wp_generate_uuid4() produces an RFC 4122 version-4 UUID: the version nibble is
// 4 and the variant nibble is one of 8/9/a/b.
assert_same( 1, preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $minted[0] ), 'the UUID is a version-4 UUID' );
$created = $items[ $minted[0] ];
assert_same( $minted[0], $created['id'], 'the record carries its own UUID under id' );
assert_same( 'Brand New', $created['name'], 'and the requested name' );
assert_same( 'divi/heading', $created['moduleName'], 'and the module it belongs to' );
assert_same( 'module', $created['type'], 'and its bucket type' );
assert_same( array( 'a' => 1 ), $created['attrs'], 'attrs are written' );
assert_same( array( 'a' => 1 ), $created['styleAttrs'], 'and mirrored into styleAttrs at create time, not only on update' );
assert_same( array( 'a' => 1 ), $created['renderAttrs'], 'and into renderAttrs' );
assert_same( 5, $created['priority'], 'a numeric priority is cast to int' );
// created/updated are `round( microtime( true ) * 1000 )` (trait-preset.php:1895)
// — MILLISECOND precision, unlike preset_update's `time() * 1000`, so the two
// handlers stamp the same field at different resolutions. Bounded by the clock
// either side of the call rather than read back from the handler.
assert_same( true, $created['created'] >= $before_ms && $created['created'] <= round( microtime( true ) * 1000 ), 'created is millisecond-precision wall clock' );
assert_same( $created['created'], $created['updated'], 'created and updated are stamped from the same reading' );
assert_same( false, array_key_exists( 'version', $created ), 'no version is recorded when ET_BUILDER_VERSION is undefined' );
assert_same( 'mod-default', diviops_pc_stored()['module']['divi/heading']['default'], 'without make_default the bucket pointer is untouched' );

diviops_pc_seed( diviops_pc_registry_fixture() );
assert_same( true, diviops_pc_wrote( 'preset_create', array( 'module_name' => 'divi/heading', 'name' => 'Brand New', 'attrs' => array( 'a' => 1 ), 'make_default' => true ) ), 'make_default reaches the write' );
$items  = diviops_pc_stored()['module']['divi/heading']['items'];
$minted = array_values( array_diff( array_keys( $items ), array_keys( diviops_pc_registry_fixture()['module']['divi/heading']['items'] ) ) );
assert_same( $minted[0], diviops_pc_stored()['module']['divi/heading']['default'], 'make_default points the bucket at the new UUID' );
assert_same( false, array_key_exists( 'priority', $items[ $minted[0] ] ), 'an omitted priority is left off the record entirely rather than defaulted' );

// A group preset lands in the group bucket keyed by group_name — NOT by
// module_name — and carries the group coordinates.
diviops_pc_seed( diviops_pc_registry_fixture() );
assert_same( true, diviops_pc_wrote( 'preset_create', array( 'module_name' => 'divi/heading', 'name' => 'Group New', 'attrs' => array( 'a' => 1 ), 'type' => 'group', 'group_name' => 'divi/border', 'group_id' => 'border', 'primary_attr_name' => 'module.decoration.border' ) ), 'a group create reaches the write' );
$group_items = diviops_pc_stored()['group']['divi/border']['items'];
assert_same( 1, count( $group_items ), 'the record lands under group/<group_name>' );
$group_record = array_values( $group_items )[0];
assert_same( 'divi/border', $group_record['groupName'], 'the record carries groupName' );
assert_same( 'border', $group_record['groupId'], 'and groupId' );
assert_same( 'module.decoration.border', $group_record['primaryAttrName'], 'and primaryAttrName when one was supplied' );
assert_same( 'divi/heading', $group_record['moduleName'], 'moduleName still records the module the preset was authored against' );
assert_same( '', diviops_pc_stored()['group']['divi/border']['default'], 'a freshly created bucket gets an empty default pointer rather than none' );

// primary_attr_name is optional and is omitted rather than written empty.
diviops_pc_seed( diviops_pc_registry_fixture() );
assert_same( true, diviops_pc_wrote( 'preset_create', array( 'module_name' => 'divi/heading', 'name' => 'Group New', 'attrs' => array( 'a' => 1 ), 'type' => 'group', 'group_name' => 'divi/border', 'group_id' => 'border' ) ), 'a group create without primary_attr_name reaches the write' );
assert_same( false, array_key_exists( 'primaryAttrName', array_values( diviops_pc_stored()['group']['divi/border']['items'] )[0] ), 'an omitted primary_attr_name is left off the record' );

// ══ Registry-shape helpers shared with preset_reassign ════════════════════
//
// tests/test-preset-reassign-write-safety.php owns the write-safety helpers.
// These are the shape helpers underneath them, which that file could not reach
// because preset_reassign() returns before the registry probe in this harness.

// ── collect_group_chain_refs: two shapes, one per bucket ─────────────────
//
// Divi stores a chain ref at a different path per bucket, and the code walks
// both (trait-preset.php:752-770): module-bucket presets carry a TOP-LEVEL
// `groupPresets` map, group-bucket presets carry `attrs.groupPreset`. Using the
// wrong shape for a bucket yields nothing at all, which is the failure the
// dual-shape walker exists to prevent.

$chain_registry = array(
	'module' => array(
		'divi/heading' => array(
			'items' => array(
				'm-top'    => array( 'groupPresets' => array( 'divi/font' => array( 'presetId' => 'g1' ) ) ),
				// Wrong shape for this bucket: invisible to the walker.
				'm-nested' => array( 'attrs' => array( 'groupPreset' => array( 'divi/font' => array( 'presetId' => 'g2' ) ) ) ),
				// Sentinels the walker skips on purpose.
				'm-sent'   => array( 'groupPresets' => array( 'a' => array( 'presetId' => 'default' ), 'b' => array( 'presetId' => '' ), 'c' => array( 'presetId' => 42 ) ) ),
				// A stacked array of ids, all counted.
				'm-stack'  => array( 'groupPresets' => array( 'divi/font' => array( 'presetId' => array( 'g1', 'g3' ) ) ) ),
			),
		),
	),
	'group'  => array(
		'divi/font' => array(
			'items' => array(
				'g-nested' => array( 'attrs' => array( 'groupPreset' => array( 'divi/border' => array( 'presetId' => 'g1' ) ) ) ),
				// Wrong shape for this bucket: invisible to the walker.
				'g-top'    => array( 'groupPresets' => array( 'divi/border' => array( 'presetId' => 'g4' ) ) ),
			),
		),
	),
);
$chain = diviops_call_static( 'collect_group_chain_refs', array( $chain_registry ) );
assert_same( 3, $chain['counts']['g1'] ?? null, 'g1 is counted once from the module top-level shape, once from a stacked array, and once from the group nested shape' );
assert_same( 1, $chain['counts']['g3'] ?? null, 'every id in a stacked presetId array is counted' );
assert_same( false, isset( $chain['counts']['g2'] ), 'a module-bucket preset using the group-bucket shape contributes nothing' );
assert_same( false, isset( $chain['counts']['g4'] ), 'a group-bucket preset using the module-bucket shape contributes nothing' );
assert_same( false, isset( $chain['counts']['default'] ), "the 'default' sentinel is never counted as a reference" );
assert_same( false, isset( $chain['counts'][''] ), 'an empty presetId is never counted' );
assert_same( false, isset( $chain['counts'][42] ), 'a non-string presetId is never counted' );
assert_same( array( 'm-top', 'm-stack', 'g-nested' ), $chain['referenced_by']['g1'], 'referenced_by is a de-duplicated list of the referencing UUIDs, in walk order' );

assert_same( array( 'divi/font' => array( 'presetId' => 'g1' ) ), diviops_call_static( '_extract_chain_slot_map', array( $chain_registry['module']['divi/heading']['items']['m-top'], 'module' ) ), 'the module extractor reads top-level groupPresets' );
assert_same( array(), diviops_call_static( '_extract_chain_slot_map', array( $chain_registry['module']['divi/heading']['items']['m-nested'], 'module' ) ), 'the module extractor ignores attrs.groupPreset' );
assert_same( array( 'divi/border' => array( 'presetId' => 'g1' ) ), diviops_call_static( '_extract_chain_slot_map', array( $chain_registry['group']['divi/font']['items']['g-nested'], 'group' ) ), 'the group extractor reads attrs.groupPreset' );
assert_same( array(), diviops_call_static( '_extract_chain_slot_map', array( array( 'groupPresets' => array( 'x' => 1 ) ), 'element' ) ), 'an unknown bucket name extracts nothing' );
assert_same( array(), diviops_call_static( '_extract_chain_slot_map', array( 'a string', 'module' ) ), 'a scalar preset extracts nothing' );
assert_same( array(), diviops_call_static( '_extract_chain_slot_map', array( array( 'groupPresets' => 'not a map' ), 'module' ) ), 'a scalar groupPresets extracts nothing' );

// DEFECT: `_write_chain_slot_map()` is module-only by construction
// (trait-preset.php:856). Handed a group-bucket preset it silently returns the
// preset unchanged rather than refusing, so a caller that used it for both
// buckets would drop the group write without any signal. The group write path
// lives in `_rewrite_registry_group_chains()` instead.
assert_same(
	array( 'groupPresets' => array( 's' => array( 'presetId' => 'new' ) ) ),
	diviops_call_static( '_write_chain_slot_map', array( array(), 'module', array( 's' => array( 'presetId' => 'new' ) ) ) ),
	'the module write installs the slot map at the top level'
);
assert_same(
	array( 'attrs' => array() ),
	diviops_call_static( '_write_chain_slot_map', array( array( 'attrs' => array() ), 'group', array( 's' => array( 'presetId' => 'new' ) ) ) ),
	'DEFECT: the group bucket is silently a no-op rather than a refusal'
);

// ── _swap_chain_refs_in_group_presets_map: scalar in, scalar out ─────────

$swap = diviops_call_static(
	'_swap_chain_refs_in_group_presets_map',
	array(
		array(
			'scalar'    => array( 'presetId' => 'old' ),
			'stacked'   => array( 'presetId' => array( 'keep', 'old' ) ),
			'untouched' => array( 'presetId' => 'other' ),
			'malformed' => array( 'noPresetId' => true ),
		),
		'old',
		'new',
	)
);
assert_same( 2, $swap['swaps'], 'both occurrences are swapped' );
assert_same( 'new', $swap['map']['scalar']['presetId'], 'a scalar presetId stays scalar after the swap' );
assert_same( array( 'keep', 'new' ), $swap['map']['stacked']['presetId'], 'an array presetId stays an array and keeps its siblings in place' );
assert_same( 'other', $swap['map']['untouched']['presetId'], 'a non-matching slot is left alone' );
assert_same( array( 'scalar' => 1, 'stacked' => 1 ), $swap['slot_swaps'], 'slot_swaps records only the slots that changed' );

// ── strip_reserved_keys + _strip_redundant_inline_attrs ─────────────────

$reserved = diviops_call_static( 'strip_reserved_keys' );
assert_same(
	array( 'meta', 'modulePreset', 'groupPreset', 'dynamicOptionGroups', 'id', 'storeInstanceId', 'name', 'moduleName', 'builderVersion' ),
	$reserved,
	'the reserved list is identity and binding data, not style'
);

$stripped = diviops_call_static(
	'_strip_redundant_inline_attrs',
	array(
		array(
			'modulePreset' => array( 'uuid' ),
			'meta'         => array( 'adminLabel' => 'Same' ),
			'module'       => array( 'advanced' => array( 'text' => 'Same', 'color' => 'Different' ) ),
			'unrelated'    => 'kept',
		),
		array(
			'modulePreset' => array( 'uuid' ),
			'meta'         => array( 'adminLabel' => 'Same' ),
			'module'       => array( 'advanced' => array( 'text' => 'Same', 'color' => 'Other' ) ),
		),
	)
);
assert_same( array( 'uuid' ), $stripped['modulePreset'], 'a reserved root key survives even when it is deep-equal to the preset' );
assert_same( array( 'adminLabel' => 'Same' ), $stripped['meta'], 'so does meta' );
assert_same( array( 'advanced' => array( 'color' => 'Different' ) ), $stripped['module'], 'a matching nested leaf is stripped and a differing sibling is kept' );
assert_same( 'kept', $stripped['unrelated'], 'a key the preset does not carry at all is kept' );

// The reserved list is applied at the ROOT only, which is what `$is_root` means
// (trait-preset.php:3117) — a nested key that happens to be called `meta` is
// stripped like any other.
$nested_meta = diviops_call_static(
	'_strip_redundant_inline_attrs',
	array(
		array( 'module' => array( 'meta' => 'Same', 'other' => 'Same' ) ),
		array( 'module' => array( 'meta' => 'Same', 'other' => 'Different' ) ),
	)
);
assert_same( array( 'other' => 'Same' ), $nested_meta['module'], 'a nested key named meta is not reserved and is stripped on a match' );

// A branch that empties out entirely is removed rather than left as [].
$emptied = diviops_call_static(
	'_strip_redundant_inline_attrs',
	array( array( 'module' => array( 'a' => 1 ) ), array( 'module' => array( 'a' => 1 ) ) )
);
assert_same( array(), $emptied, 'a nested branch that strips to empty is unset, not left as an empty array' );

// ── preset_scope_warnings ───────────────────────────────────────────────
//
// Any key literally named layout/position/sizing/transform anywhere in a bag is
// reported, because those are the attributes that let a "visual" preset move
// geometry. The one carve-out is Divi's box-shadow `position` sub-attribute,
// which selects inner vs outer shadow rather than placing anything: Divi reads
// it at `BoxShadowUtils.php:204` as `$attr_value['position']`, under the
// standard `<attrName>.<breakpoint>.<state>.value` attr shape.

$warnings = diviops_call_static(
	'preset_scope_warnings',
	array(
		array(
			'attrs'      => array(
				'module' => array(
					'decoration' => array(
						'layout'    => array( 'desktop' => array( 'value' => array( 'display' => 'flex' ) ) ),
						'boxShadow' => array( 'desktop' => array( 'value' => array( 'position' => 'inner' ) ) ),
					),
					'advanced'   => array( 'position' => array( 'desktop' => array( 'value' => array( 'mode' => 'absolute' ) ) ) ),
				),
			),
			'styleAttrs' => array( 'module' => array( 'decoration' => array( 'font' => array( 'size' => '2rem' ) ) ) ),
		)
	)
);
assert_same( 1, count( $warnings ), 'only the bag that actually carries a geometry key produces a warning' );
assert_same( 'visual_preset_scope_leak', $warnings[0]['type'], 'the warning type is visual_preset_scope_leak' );
assert_same( 'attrs', $warnings[0]['bag'], 'the warning names the bag it came from' );
assert_same( array( 'layout', 'position' ), $warnings[0]['categories'], 'categories are the distinct lowercased key names, de-duplicated' );
assert_same(
	array( 'module.decoration.layout', 'module.advanced.position' ),
	$warnings[0]['paths'],
	'paths are the full dotted locations, in walk order'
);
assert_same( false, in_array( 'module.decoration.boxShadow.desktop.value.position', $warnings[0]['paths'], true ), "box-shadow's position sub-attribute is exempt — it selects inner/outer, not geometry" );

assert_same( true, diviops_call_static( 'is_box_shadow_position_path', array( 'module.decoration.boxShadow.desktop.value.position' ) ), 'the exemption matches the full box-shadow attr path' );
assert_same( true, diviops_call_static( 'is_box_shadow_position_path', array( 'boxShadow.desktop.value.position' ) ), 'and matches at the root of a bag' );
assert_same( false, diviops_call_static( 'is_box_shadow_position_path', array( 'module.decoration.position' ) ), 'a bare position attribute is not exempt' );
assert_same( false, diviops_call_static( 'is_box_shadow_position_path', array( 'boxShadow.value.position' ) ), 'the exemption needs at least one segment between boxShadow and value' );

// ── preset_is_bucket_default ────────────────────────────────────────────

diviops_pc_seed( diviops_pc_registry_fixture() );
assert_same( true, diviops_call_static( 'preset_is_bucket_default', array( 'mod-default', 'module', 'divi/heading', DIVIOPS_PC_OPTION ) ), 'the bucket default resolves true' );
assert_same( false, diviops_call_static( 'preset_is_bucket_default', array( 'mod-clean', 'module', 'divi/heading', DIVIOPS_PC_OPTION ) ), 'a sibling preset resolves false' );
assert_same( false, diviops_call_static( 'preset_is_bucket_default', array( 'mod-default', 'group', 'divi/heading', DIVIOPS_PC_OPTION ) ), 'the wrong bucket resolves false' );
assert_same( false, diviops_call_static( 'preset_is_bucket_default', array( 'mod-default', 'module', 'divi/heading', 'no_such_option' ) ), 'an unreadable storage path resolves false rather than erroring' );

// ── preset_primary_d5_source ────────────────────────────────────────────

$d4_row = array( 'path' => 'et_divi_builder_global_presets_ng', 'provenance' => 'legacy_d4_ng', 'bucket' => 'module', 'bucket_key' => 'x' );
$d5_row = array( 'path' => DIVIOPS_PC_OPTION, 'provenance' => 'd5_top_level', 'bucket' => 'module', 'bucket_key' => 'y' );
assert_same( $d5_row, diviops_call_static( 'preset_primary_d5_source', array( array( $d4_row, $d5_row ), array( 'fallback' => true ) ) ), 'the first non-legacy occurrence wins regardless of order' );
assert_same( array( 'fallback' => true ), diviops_call_static( 'preset_primary_d5_source', array( array( $d4_row ), array( 'fallback' => true ) ) ), 'a legacy-only occurrence list falls back to the audit entry source' );
assert_same( array(), diviops_call_static( 'preset_primary_d5_source', array( array(), array() ) ), 'no occurrences at all falls back to the empty default' );

// ── preset_chain_consumer_samples ───────────────────────────────────────

$sample_registry = array( 'module' => array( 'divi/heading' => array( 'items' => array() ) ) );
for ( $index = 0; $index < 12; $index++ ) {
	$sample_registry['module']['divi/heading']['items'][ 'c' . $index ] = array( 'name' => 'Consumer ' . $index );
}
$samples = diviops_call_static( 'preset_chain_consumer_samples', array( array_keys( $sample_registry['module']['divi/heading']['items'] ), $sample_registry ) );
assert_same( 10, count( $samples ), 'the consumer sample list is capped at ten' );
assert_same(
	array( 'kind' => 'preset', 'preset_id' => 'c0', 'name' => 'Consumer 0', 'bucket' => 'module', 'bucket_key' => 'divi/heading' ),
	$samples[0],
	'a sample carries the referencing preset UUID, its name and its bucket coordinates'
);
assert_same( array(), diviops_call_static( 'preset_chain_consumer_samples', array( array( 'not-present' ), $sample_registry ) ), 'an id that is in no bucket yields no samples' );

// ── walk_blocks_for_preset_refs ─────────────────────────────────────────
//
// This is the structural scanner every reference count in the trait derives
// from. It is reachable directly even though collect_page_preset_refs() is not,
// because the caller is what needs parse_blocks(), not the walker.

$blocks = array(
	array(
		'blockName'   => 'divi/section',
		'attrs'       => array( 'modulePreset' => array( 'uuid-a', 'default', '', 'uuid-a' ) ),
		'innerBlocks' => array(
			array(
				'blockName' => 'divi/heading',
				// The scalar form, which hand-edited and legacy markup can carry.
				'attrs'     => array( 'modulePreset' => 'uuid-b' ),
			),
			array(
				'blockName' => 'divi/text',
				'attrs'     => array(
					'groupPreset' => array(
						'divi/font'   => array( 'presetId' => array( 'uuid-c' ) ),
						'divi/border' => array( 'presetId' => 'default' ),
						'malformed'   => 'not a slot',
					),
				),
			),
		),
	),
);
$all_uuids  = array();
$page_uuids = array();
$ref_count  = 0;
$args       = array( $blocks, &$all_uuids, &$page_uuids, &$ref_count );
diviops_call_ref( 'walk_blocks_for_preset_refs', $args );
assert_same( 2, $all_uuids['uuid-a'] ?? null, 'a UUID repeated inside one modulePreset stack is counted once per occurrence' );
assert_same( 1, $all_uuids['uuid-b'] ?? null, 'a scalar modulePreset is accepted and counted' );
assert_same( 1, $all_uuids['uuid-c'] ?? null, 'a groupPreset slot presetId is counted' );
assert_same( false, isset( $all_uuids['default'] ), "the 'default' sentinel never becomes a reference" );
assert_same( false, isset( $all_uuids[''] ), 'an empty string never becomes a reference' );
assert_same( 3, $ref_count, 'ref_count increments once per CONTAINER that yielded at least one UUID, not once per UUID' );
assert_same( array( 'uuid-a', 'uuid-a', 'uuid-b', 'uuid-c' ), $page_uuids, 'page_uuids is the raw per-occurrence list, de-duplicated only by the caller' );

// A block with no attrs at all, and a slot map that is a scalar, are both
// walked without incident.
$all_uuids  = array();
$page_uuids = array();
$ref_count  = 0;
$args       = array( array( array( 'blockName' => 'divi/text' ), array( 'attrs' => array( 'groupPreset' => 'scalar' ) ) ), &$all_uuids, &$page_uuids, &$ref_count );
diviops_call_ref( 'walk_blocks_for_preset_refs', $args );
assert_same( array(), $all_uuids, 'a block with no attrs and a scalar groupPreset contribute nothing' );
assert_same( 0, $ref_count, 'and do not move ref_count' );

// ── preset_cleanup_dry_run_changes ──────────────────────────────────────

$changes = diviops_call_static(
	'preset_cleanup_dry_run_changes',
	array(
		array( array( 'id' => 'r1', 'module' => 'divi/heading', 'name' => 'Gone' ) ),
		array( array( 'id' => 'n1', 'module' => 'divi/heading', 'old_name' => 'Old', 'new_name' => 'New' ) ),
		array( array( 'id' => 'd1', 'module' => 'divi/heading', 'name' => 'Dupe', 'kept_id' => 'd0' ) ),
	)
);
assert_same( array( 'preset.delete', 'preset.rename', 'preset.delete_duplicate' ), array_column( $changes, 'kind' ), 'the three cleanup outcomes get three distinct change kinds' );
assert_same( 'd0', $changes[2]['before']['kept_id'], 'a dedup change names the UUID that survived' );
// Missing coordinates degrade to the literal string "unknown" in the target
// rather than producing `preset//` — pinned because the target string is what a
// caller correlates a plan row with.
$degraded = diviops_call_static( 'preset_cleanup_dry_run_changes', array( array( array() ), array(), array() ) );
assert_same( 'preset/unknown/unknown', $degraded[0]['target'], 'a change row with no coordinates targets preset/unknown/unknown' );
assert_same( null, $degraded[0]['before']['id'], 'and reports a null id rather than an empty string' );

// ── preset_registry_deep_copy / values_equal / set_path ──────────────────

$nested        = new stdClass();
$nested->depth = 2;
$source        = array( 'obj' => $nested, 'list' => array( 1, 2 ) );
$copy          = diviops_call_static( 'preset_registry_deep_copy', array( $source ) );
$copy['obj']->depth = 99;
assert_same( 2, $nested->depth, 'the deep copy clones nested objects rather than aliasing them' );
assert_same( true, is_object( $copy['obj'] ), 'and preserves their object-ness rather than normalising to arrays' );

assert_same( true, diviops_call_static( 'preset_registry_values_equal', array( array( 'a' => 1 ), array( 'a' => 1 ) ) ), 'structurally identical values compare equal' );
assert_same( false, diviops_call_static( 'preset_registry_values_equal', array( array( 'a' => 1 ), (object) array( 'a' => 1 ) ) ), 'an array and an object with the same contents are NOT equal — the comparison is over serialize()' );

$target = array( 'module' => array( 'm' => array( 'items' => array( 'u' => array( 'created' => 'iso' ) ) ) ) );
$args   = array( &$target, array( 'module', 'm', 'items', 'u', 'created' ), 123 );
diviops_call_ref( 'preset_registry_set_path', $args );
assert_same( 123, $target['module']['m']['items']['u']['created'], 'set_path writes through the segment list' );

$object_target = array( 'module' => (object) array( 'items' => array( 'u' => array( 'created' => 'iso' ) ) ) );
$args          = array( &$object_target, array( 'module', 'items', 'u', 'created' ), 456 );
diviops_call_ref( 'preset_registry_set_path', $args );
assert_same( 456, $object_target['module']->items['u']['created'], 'set_path descends through an object level without converting it to an array' );

// ══ The et_get_option boundary is real, not an assumption ═════════════════
//
// The header lists handlers this suite cannot drive. That list is only worth
// anything if the reason is checked rather than remembered, so the two direct
// callers are proved from the real method source. Structural, in the shape
// tests/test-preset-reassign-write-safety.php already uses, and with the
// checker itself sanity-checked first so a silently broken check cannot make
// these pass for the wrong reason.

/**
 * Read a method's exact source text via Reflection.
 *
 * Named for this file: every test file is require'd into ONE process, so a
 * shared global name would collide with the identically-purposed helpers in
 * test-preset-reassign-write-safety.php and test-parse-blocks-for-write-coverage.php.
 *
 * @param string $method Method name on DiviOps_Agent.
 * @return string
 */
function diviops_pc_method_source( string $method ): string {
	$reflection = new ReflectionMethod( 'DiviOps_Agent', $method );
	$start      = $reflection->getStartLine() - 1;
	$lines      = file( $reflection->getFileName() );
	return implode( '', array_slice( $lines, $start, $reflection->getEndLine() - $start ) );
}

/**
 * True when the source really calls $function, ignoring comments and strings —
 * these handlers document their own storage contract in prose, so a text search
 * reports call sites that do not exist.
 *
 * @param string $source   PHP source text.
 * @param string $function Function name.
 * @return bool
 */
function diviops_pc_calls( string $source, string $function ): bool {
	$code = '';
	foreach ( token_get_all( '<?php ' . $source ) as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
				continue;
			}
			$code .= $token[1];
		} else {
			$code .= $token;
		}
	}
	return (bool) preg_match( '/(?<!function )\b' . preg_quote( $function, '/' ) . '\(/i', $code );
}

assert_same( false, diviops_pc_calls( '// reads et_get_option() indirectly', 'et_get_option' ), 'the call checker does not count a name that appears only in a comment' );
assert_same( true, diviops_pc_calls( '$raw = et_get_option( "x" );', 'et_get_option' ), 'the call checker counts a genuine call' );

$list_source = diviops_pc_method_source( 'preset_list' );
assert_same( true, '' !== $list_source && false !== strpos( $list_source, 'preset_list' ), 'the real preset_list source was read, so the check below inspects something' );
assert_same( true, diviops_pc_calls( $list_source, 'et_get_option' ), 'preset_list calls et_get_option unconditionally, which is why it is not driven here' );
assert_same( true, diviops_pc_calls( diviops_pc_method_source( 'preset_scan_orphans' ), 'et_get_option' ), 'preset_scan_orphans calls et_get_option unconditionally, for the same reason' );
assert_same( false, diviops_pc_calls( diviops_pc_method_source( 'preset_audit' ), 'et_get_option' ), 'preset_audit does not, which is why it IS driven here' );

// ── Teardown ─────────────────────────────────────────────────────────────
//
// Restore the shared registries exactly. `diviops_test_options` is authoritative
// for values and `diviops_test_option_rows` for option_id ordering, and the
// $wpdb fake in test-rollback-retention.php sorts on the latter, so both are put
// back rather than only the first.
$GLOBALS['diviops_test_options']     = $GLOBALS['diviops_pc_saved_options'];
$GLOBALS['diviops_test_option_rows'] = $GLOBALS['diviops_pc_saved_option_rows'];
$GLOBALS['diviops_test_posts']       = $GLOBALS['diviops_pc_saved_posts'];
unset( $GLOBALS['diviops_pc_saved_options'], $GLOBALS['diviops_pc_saved_option_rows'], $GLOBALS['diviops_pc_saved_posts'] );
