<?php
// SPDX-License-Identifier: MIT
/**
 * Regression: `preset_cleanup remove_orphans` must reach a fixed point in one pass (#378).
 *
 * The defect. `preset_cleanup()` computes its referenced set ONCE, before the
 * removal pass:
 *
 *     $referenced_set = $refs['all_uuids'] + $chain['counts'];
 *
 * `$refs['all_uuids']` is page-content references, which cannot change while the
 * handler runs — deleting a preset does not edit a page. `$chain['counts']` is
 * in-registry chain references, and those DO change: deleting a module preset
 * deletes its `groupPresets` chain refs along with it.
 *
 * So a module preset can be removed for being unreferenced while, in the same
 * pass, a group preset is spared *by that module preset's now-deleted chain ref*.
 * The registry left behind is not a fixed point, and an identical second run
 * deletes what the first run reported as kept:
 *
 *     run 1: removed 5, kept 2   (grp-chained protected by mod-chained's chain)
 *     run 2: removed grp-chained (mod-chained no longer exists to protect it)
 *
 * Re-running a destructive command that reported success is an ordinary thing to
 * do, and "kept 2" gave the operator no way to know one of those two was doomed.
 *
 * The fix, and what it changes. The handler now iterates the removal pass to a
 * fixed point, recomputing the chain half of the referenced set after each pass.
 * **Run 1 therefore removes strictly more than it used to** — it removes the full
 * orphan closure, which is exactly what run 1 + run 2 removed before. Nothing
 * survives the new single run that survived the old pair. The count the operator
 * reads is now the true one.
 *
 * Only the chain half is recomputed. Re-scanning page content per pass would
 * re-run `collect_page_preset_refs()`, a `get_posts()` over every
 * SCANNABLE_POST_TYPES row plus `parse_blocks()` on each hit, to learn something
 * that provably cannot have changed.
 *
 * `test-preset-characterization.php` pins the OLD behaviour at its two-run
 * assertions and is updated alongside this file; see the `#378` notes there.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

// ── Fixture bookkeeping ───────────────────────────────────────────────────
//
// Same contract as test-preset-characterization.php: the options and post
// registries are process-wide. The post registry is emptied rather than
// snapshotted because collect_page_preset_refs() calls parse_blocks() on any
// post whose content mentions a preset, and parse_blocks() is unshimmed and
// throws — a post left behind by an earlier file would turn this into a fatal
// error rather than a failure.
$GLOBALS['diviops_fp_saved_options']     = $GLOBALS['diviops_test_options'];
$GLOBALS['diviops_fp_saved_option_rows'] = $GLOBALS['diviops_test_option_rows'];
$GLOBALS['diviops_fp_saved_posts']       = $GLOBALS['diviops_test_posts'] ?? array();
$GLOBALS['diviops_test_posts']           = array();

const DIVIOPS_FP_OPTION = 'et_divi_builder_global_presets_d5';

/**
 * Seed the canonical registry.
 *
 * @param array $registry Registry payload.
 * @return array
 */
function diviops_fp_seed( array $registry ): array {
	update_option( DIVIOPS_FP_OPTION, $registry, false );
	return $registry;
}

/**
 * Read the canonical registry back.
 *
 * @return mixed
 */
function diviops_fp_stored() {
	return get_option( DIVIOPS_FP_OPTION, null );
}

/**
 * Invoke a handler and return its envelope body.
 *
 * @param string $method Handler name on DiviOps_Agent.
 * @param array  $params Request params.
 * @return array
 */
function diviops_fp_body( string $method, array $params = array() ): array {
	return diviops_call( $method, array( new DiviOps_Test_Request( $params ) ) )->get_data();
}

/**
 * Removed-preset ids from a `preset_cleanup` envelope, sorted for comparison.
 *
 * @param array $body Envelope body.
 * @return array
 */
function diviops_fp_removed_ids( array $body ): array {
	$data = $body['data'] ?? $body;
	$ids  = array_column( $data['removed'] ?? array(), 'id' );
	sort( $ids );
	return $ids;
}

/**
 * A registry whose only chain reference comes FROM a removable preset.
 *
 * `mod-chained` is referenced by nothing, so it is an orphan. `grp-chained` is
 * referenced by nothing except `mod-chained`'s chain — so once `mod-chained`
 * goes, it is an orphan too. The closure is therefore both of them, and a
 * correct single pass removes both.
 *
 * @return array
 */
function diviops_fp_doomed_chain_fixture(): array {
	return array(
		'module' => array(
			'divi/heading' => array(
				'default' => 'mod-default',
				'items'   => array(
					'mod-default' => array( 'name' => 'Kept Default', 'attrs' => array( 'a' => 1 ) ),
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
					'grp-chained' => array( 'name' => 'Font Body', 'attrs' => array( 'e' => 5 ) ),
				),
			),
		),
	);
}

/**
 * The same shape, except the chaining module preset is its bucket's DEFAULT.
 *
 * `is_default` protects `mod-chained` permanently, so its chain ref is load
 * bearing forever and `grp-chained` must survive every run. This is the control
 * that proves the fix narrows to the orphan closure rather than simply deleting
 * more.
 *
 * @return array
 */
function diviops_fp_protected_chain_fixture(): array {
	$registry = diviops_fp_doomed_chain_fixture();
	$registry['module']['divi/heading']['default'] = 'mod-chained';
	return $registry;
}

// ── 1. One pass removes the whole orphan closure ──────────────────────────
//
// Before the fix this returned only `mod-chained`, sparing `grp-chained` on the
// strength of a chain reference it had just destroyed.
diviops_fp_seed( diviops_fp_doomed_chain_fixture() );

$first = diviops_fp_body( 'preset_cleanup', array( 'action' => 'remove_orphans', 'scope' => 'all', 'dry_run' => false ) );

assert_same(
	array( 'grp-chained', 'mod-chained' ),
	diviops_fp_removed_ids( $first ),
	'#378: one pass removes the full orphan closure — the chained group preset goes with the module preset that was its only referrer'
);

$stored = diviops_fp_stored();
assert_same(
	false,
	isset( $stored['group']['divi/font']['items']['grp-chained'] ),
	'#378: the chained group preset is gone from storage after the FIRST run'
);
assert_same(
	true,
	isset( $stored['module']['divi/heading']['items']['mod-default'] ),
	'#378: the bucket default is untouched — the closure is orphans only'
);

// ── 2. The registry left behind is a fixed point ──────────────────────────
//
// This is the property the issue is really about: an identical second run must
// be a no-op. Before the fix it removed `grp-chained`.
$second = diviops_fp_body( 'preset_cleanup', array( 'action' => 'remove_orphans', 'scope' => 'all', 'dry_run' => false ) );

assert_same(
	array(),
	diviops_fp_removed_ids( $second ),
	'#378: an identical second run removes nothing — the first run reached a fixed point'
);

// ── 3. The count the operator reads is the true one ───────────────────────
//
// `kept` must not include presets that a further run would delete. With the
// closure removed in one pass, everything still standing is genuinely protected.
$data = $second['data'] ?? $second;
assert_same(
	0,
	(int) ( $data['removed_count'] ?? -1 ),
	'#378: removed_count is 0 on the second run'
);
assert_same(
	true,
	(int) ( $data['kept_count'] ?? -1 ) >= 1,
	'#378: kept_count still counts the survivors rather than reporting an empty registry'
);

// ── 4. Control: a chain ref from a PROTECTED preset still protects ────────
//
// Without this, "removes more" and "removes correctly" are indistinguishable.
// `mod-chained` is the bucket default here, so it can never be removed, so its
// chain ref never expires and `grp-chained` must survive indefinitely.
diviops_fp_seed( diviops_fp_protected_chain_fixture() );

$guarded = diviops_fp_body( 'preset_cleanup', array( 'action' => 'remove_orphans', 'scope' => 'all', 'dry_run' => false ) );

assert_same(
	false,
	in_array( 'grp-chained', diviops_fp_removed_ids( $guarded ), true ),
	'#378: a group preset chained from a preset that CANNOT be removed is still protected'
);

$stored = diviops_fp_stored();
assert_same(
	true,
	isset( $stored['group']['divi/font']['items']['grp-chained'] ),
	'#378: and it is still in storage'
);

$guarded_again = diviops_fp_body( 'preset_cleanup', array( 'action' => 'remove_orphans', 'scope' => 'all', 'dry_run' => false ) );
assert_same(
	array(),
	diviops_fp_removed_ids( $guarded_again ),
	'#378: the protected case is a fixed point too — a second run still removes nothing'
);

// ── 5. dry_run must predict what the real run does ────────────────────────
//
// A dry run that reports the old single-pass answer while the real run removes
// the closure would be worse than the original defect: the operator would be
// shown a smaller number than the one about to be executed.
diviops_fp_seed( diviops_fp_doomed_chain_fixture() );

$preview = diviops_fp_body( 'preset_cleanup', array( 'action' => 'remove_orphans', 'scope' => 'all', 'dry_run' => true ) );

assert_same(
	array( 'grp-chained', 'mod-chained' ),
	diviops_fp_removed_ids( $preview ),
	'#378: dry_run predicts the full closure, matching what the real run removes'
);

$stored = diviops_fp_stored();
assert_same(
	true,
	isset( $stored['group']['divi/font']['items']['grp-chained'] ),
	'#378: and dry_run wrote nothing'
);

// ── Restore ───────────────────────────────────────────────────────────────
$GLOBALS['diviops_test_options']     = $GLOBALS['diviops_fp_saved_options'];
$GLOBALS['diviops_test_option_rows'] = $GLOBALS['diviops_fp_saved_option_rows'];
$GLOBALS['diviops_test_posts']       = $GLOBALS['diviops_fp_saved_posts'];
