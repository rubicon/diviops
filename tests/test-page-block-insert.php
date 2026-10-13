<?php
// SPDX-License-Identifier: MIT
/**
 * page_block_insert() — insert a row/column (any block) at a path/selector on a
 * page, without rebuilding the section (#32 / G5).
 *
 * module get/update/move/clone/lock already treat rows/columns as first-class,
 * but there was no way to INSERT a new one at a specific position. The Theme
 * Builder already had this exact primitive (tb_layout_block_insert +
 * apply_tb_block_insert_at_path / find_tb_block_by_path / find_tb_block_by_selector
 * + the idempotency helpers), and those helpers operate on generic parsed block
 * trees — nothing TB-specific. So page_block_insert REUSES them verbatim and adds
 * only a page post-type guard and the page write path; it deliberately does NOT
 * duplicate that intricate, already-proven logic, nor refactor the #11-critical
 * TB handler.
 *
 * WHAT THIS FILE CAN AND CANNOT COVER. The harness does not shim parse_blocks()
 * or serialize_blocks() (only serialize_block singular), so the full
 * parse -> find -> apply -> serialize -> write-guard round trip — the same one
 * test-global-layout-write-guard.php documents as live-only ("cannot be
 * reproduced faithfully outside a genuine REST dispatch") — is verified LIVE on
 * the colleyvillelions site (a scratch page carrying a divi/global-layout wrapper,
 * insert a row, confirm the wrapper is preserved not materialized), not here.
 * What IS unit-tested here, honestly and against real plugin code:
 *   1. The handler's pre-parse guard/validation branches (not_found, invalid
 *      content, invalid position, and the exactly-one-of parent_selector/parent_path
 *      rule) — all run before any parse machinery.
 *   2. The generic tree helpers the handler reuses — find_tb_block_by_path,
 *      apply_tb_block_insert, tb_target_payload, tb_insert_sequence_matches —
 *      exercised directly with hand-built block trees. These were previously
 *      reachable only through the live TB write path, so this is their first
 *      direct coverage.
 * The write-guard wiring itself (update_post_content_with_integrity_guard with
 * $check_global_layout_drift = true) is the identical, already-reviewed call the
 * TB/module/preset paths use; page_block_insert inherits its wrapper-materialization
 * protection. That wiring is inspected + live-verified.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$GLOBALS['diviops_test_posts'] = array();

function diviops_pbi_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

// A minimal hand-built parsed-block tree: one section containing one row.
function diviops_pbi_tree() {
	return array(
		array(
			'blockName'    => 'divi/section',
			'attrs'        => array(),
			'innerBlocks'  => array(
				array(
					'blockName'    => 'divi/row',
					'attrs'        => array( 'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'First Row' ) ) ) ) ),
					'innerBlocks'  => array(),
					'innerContent' => array(),
				),
			),
			'innerContent' => array(),
		),
	);
}

function diviops_pbi_new_row( $label = 'New Row' ) {
	return array(
		'blockName'    => 'divi/row',
		'attrs'        => array( 'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => $label ) ) ) ) ),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	);
}

// ── the reused generic tree helpers (first direct coverage) ───────────────
// find_tb_block_by_path / apply_tb_block_insert declare a by-reference first
// parameter, so each call goes through diviops_call_ref with the args array
// assigned to a named variable first (PHP only binds references from variables,
// not from inline assignment expressions).

// find_tb_block_by_path resolves a dot path to a target payload.
$tree     = diviops_pbi_tree();
$pathArgs = array( &$tree, '0' );
$target   = diviops_call_ref( 'find_tb_block_by_path', $pathArgs );
assert_same( 'divi/section', $target['block_name'], 'path "0" resolves to the section' );
assert_same( 0, $target['index'], 'the target payload reports its sibling index' );
assert_same( 1, count( $target['children'] ), 'the section payload exposes its one child row' );

$tree2      = diviops_pbi_tree();
$pathArgs2  = array( &$tree2, '0.0' );
$target2    = diviops_call_ref( 'find_tb_block_by_path', $pathArgs2 );
assert_same( 'divi/row', $target2['block_name'], 'path "0.0" descends to the row' );
assert_same( 'First Row', $target2['admin_label'], 'the payload surfaces the row admin label' );

// A non-zero index at depth: both single-level paths above resolve to index 0,
// so they cannot catch an index-computation regression. A second child lets
// path "0.1" assert index 1 — the index-at-depth is computed, not hardcoded.
$tree_multi = diviops_pbi_tree();
$tree_multi[0]['innerBlocks'][] = diviops_pbi_new_row( 'Second Row' );
$multiArgs    = array( &$tree_multi, '0.1' );
$target_multi = diviops_call_ref( 'find_tb_block_by_path', $multiArgs );
assert_same( 1, $target_multi['index'], 'path "0.1" resolves to sibling index 1, not a hardcoded 0' );
assert_same( 'Second Row', $target_multi['admin_label'], 'path "0.1" resolves to the second child' );

// invalid + not-found path resolution returns WP_Error, not a fatal.
$tree3     = diviops_pbi_tree();
$badArgs   = array( &$tree3, 'nope' );
$err       = diviops_call_ref( 'find_tb_block_by_path', $badArgs );
assert_true( is_wp_error( $err ), 'a malformed path returns a WP_Error' );
$tree4       = diviops_pbi_tree();
$oorArgs     = array( &$tree4, '9' );
$notfound    = diviops_call_ref( 'find_tb_block_by_path', $oorArgs );
assert_true( is_wp_error( $notfound ), 'an out-of-range path returns a WP_Error not_found' );

// apply_tb_block_insert: append into a container adds a child at the end.
$tree5      = diviops_pbi_tree();
$appendArgs = array( &$tree5, '0', 'append', array( diviops_pbi_new_row( 'Appended' ) ) );
diviops_call_ref( 'apply_tb_block_insert', $appendArgs );
assert_same( 2, count( $tree5[0]['innerBlocks'] ), 'append adds a child to the section' );
assert_same( 'Appended', $tree5[0]['innerBlocks'][1]['attrs']['module']['meta']['adminLabel']['desktop']['value'], 'the appended child lands last' );

// prepend puts the new child first.
$tree6       = diviops_pbi_tree();
$prependArgs = array( &$tree6, '0', 'prepend', array( diviops_pbi_new_row( 'Prepended' ) ) );
diviops_call_ref( 'apply_tb_block_insert', $prependArgs );
assert_same( 'Prepended', $tree6[0]['innerBlocks'][0]['attrs']['module']['meta']['adminLabel']['desktop']['value'], 'prepend lands the new child first' );

// after inserts a sibling right after the target.
$tree7     = diviops_pbi_tree();
$afterArgs = array( &$tree7, '0.0', 'after', array( diviops_pbi_new_row( 'Sibling' ) ) );
diviops_call_ref( 'apply_tb_block_insert', $afterArgs );
assert_same( 2, count( $tree7[0]['innerBlocks'] ), 'after inserts a sibling row alongside the first' );
assert_same( 'Sibling', $tree7[0]['innerBlocks'][1]['attrs']['module']['meta']['adminLabel']['desktop']['value'], 'the sibling lands after the target' );

// tb_insert_sequence_matches underpins insert idempotency.
$rows = array( diviops_pbi_new_row( 'A' ), diviops_pbi_new_row( 'B' ) );
assert_true(
	diviops_call( 'tb_insert_sequence_matches', array( $rows, array( diviops_pbi_new_row( 'A' ) ), 0 ) ),
	'a matching block at the offset is detected (idempotency backstop)'
);
assert_true(
	! diviops_call( 'tb_insert_sequence_matches', array( $rows, array( diviops_pbi_new_row( 'Z' ) ), 0 ) ),
	'a non-matching block is not falsely reported present'
);

// ── page_block_insert handler: pre-parse guard/validation branches ────────

// not_found: no such page.
$resp = diviops_call( 'page_block_insert', array( diviops_pbi_request( array( 'id' => 7777, 'content' => '<!-- wp:divi/row --><!-- /wp:divi/row -->', 'parent_path' => '0' ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'an unknown page id is not_found' );
assert_same( 404, $resp->get_status(), 'not_found is HTTP 404' );

// From here on, a real page exists so the guards past not_found are reachable.
diviops_test_register_post( 4100, '<!-- wp:divi/section --><!-- /wp:divi/section -->', 'page', 'Scratch' );

// invalid content (non-string / empty) is rejected before any parsing.
$resp = diviops_call( 'page_block_insert', array( diviops_pbi_request( array( 'id' => 4100, 'content' => '', 'parent_path' => '0' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'empty content is invalid_input' );
assert_same( 'content', $data['error']['data']['field'], 'the content error names the field' );

// invalid position.
$resp = diviops_call( 'page_block_insert', array( diviops_pbi_request( array( 'id' => 4100, 'content' => '<!-- wp:divi/row --><!-- /wp:divi/row -->', 'position' => 'sideways', 'parent_path' => '0' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an unknown position is invalid_input' );
assert_same( 'position', $data['error']['data']['field'], 'the position error names the field' );

// the exactly-one-of parent_selector/parent_path rule: neither.
$resp = diviops_call( 'page_block_insert', array( diviops_pbi_request( array( 'id' => 4100, 'content' => '<!-- wp:divi/row --><!-- /wp:divi/row -->' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'providing neither parent_selector nor parent_path is invalid_input' );

// ...and both.
$resp = diviops_call( 'page_block_insert', array( diviops_pbi_request( array( 'id' => 4100, 'content' => '<!-- wp:divi/row --><!-- /wp:divi/row -->', 'parent_path' => '0', 'parent_selector' => 'divi/section' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'providing both parent_selector and parent_path is invalid_input' );

// ── structural regression: route wiring + capability key ──────────────────

$plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );
assert_true(
	1 === preg_match(
		"/register_rest_route\\(\\s*self::REST_NAMESPACE,\\s*'\\/page\\/block-insert[^']*',\\s*\\[\\s*'methods'\\s*=>\\s*'POST',\\s*'callback'\\s*=>\\s*\\[\\s*__CLASS__,\\s*'page_block_insert'\\s*\\]/s",
		$plugin_src
	),
	'/page/block-insert is registered as a POST route dispatching to page_block_insert'
);
assert_true(
	1 === preg_match( "/'page_block_insert'/", $plugin_src ),
	"the 'page_block_insert' capability key is present in CAPABILITIES"
);
assert_true(
	method_exists( 'DiviOps_Agent', 'page_block_insert' ),
	'DiviOps_Agent::page_block_insert exists once the trait is mixed in'
);

// ── #592: a retried insert must find the sequence it already wrote ────────
// An insert can move the block its target was resolved from, and a retry then
// has to compare against the shifted position. Which positions shift is not
// obvious, and 'before' was getting the unshifted offset:
//
//   parent_selector 'divi/row[adminLabel="Anchor B"]', position 'before'
//     first call  -> [Anchor A, Inserted, Anchor B]
//     retry       -> selector re-resolves Anchor B at index 2, the offset says
//                    look at 2, Anchor B sits there, no match, insert AGAIN.
//
// Two scopes that look like they should be affected and are not, pinned below so
// the distinction is not lost. parent_path is POSITIONAL: after inserting before
// '0.1' that path names the inserted block, so a retry matches and no-ops. And
// 'after' never moves its anchor, so its unshifted offset is already right --
// which is why the fix must cover 'before' and 'append' only. Applying the shift
// to 'after' would break a path that works.
//
// Driven at tree level against the real helpers. page_block_insert cannot be
// driven end to end here: it reaches parse_blocks(), which no shim implements by
// design -- several suites use "Call to undefined function parse_blocks()" as a
// positive probe signal. Faking a WP block parser to get around that would be a
// mocked-behavior test and would diverge from WP's own grammar.
//
// The offset comes from the PRODUCTION helper, never recomputed here, so a
// mutation to it fails these assertions instead of passing them.

/**
 * Apply one insert, then ask whether a retry would find the sequence already there.
 *
 * @param string $position  append|prepend|before|after.
 * @param string $resolve   'selector' or 'path'.
 * @param string $target    Selector string, or dot path.
 * @return array{found:bool,labels:array<int,string>}
 */
function diviops_pbi_retry_probe( string $position, string $resolve, string $target ): array {
	$tree = array(
		array(
			'blockName'    => 'divi/section',
			'attrs'        => array(),
			'innerBlocks'  => array( diviops_pbi_new_row( 'Anchor A' ), diviops_pbi_new_row( 'Anchor B' ) ),
			'innerContent' => array(),
		),
	);
	$inserted = array( diviops_pbi_new_row( 'Inserted' ) );
	$count    = count( $inserted );
	$finder   = 'selector' === $resolve ? 'find_tb_block_by_selector' : 'find_tb_block_by_path';

	$args     = array( &$tree, $target );
	$resolved = diviops_call_ref( $finder, $args );
	// insert_at mirrors the handler. It is not what this test pins -- if it were
	// wrong the label order asserted by the caller would be wrong too.
	$insert_at = 'append' === $position
		? count( $resolved['children'] )
		: ( 'prepend' === $position ? 0 : ( 'before' === $position ? $resolved['index'] : $resolved['index'] + 1 ) );
	$apply = array( &$tree, $resolved['path'], $position, $inserted );
	diviops_call_ref( 'apply_tb_block_insert', $apply );

	$again    = array( &$tree, $target );
	$retarget = diviops_call_ref( $finder, $again );
	$retry_at = 'append' === $position
		? count( $retarget['children'] )
		: ( 'prepend' === $position ? 0 : ( 'before' === $position ? $retarget['index'] : $retarget['index'] + 1 ) );
	$offset   = diviops_call( 'tb_insert_idempotency_offset', array( $position, $retry_at, $count, 'selector' === $resolve ) );
	$scope    = in_array( $position, array( 'append', 'prepend' ), true ) ? $retarget['children'] : $retarget['siblings'];

	$labels = array();
	foreach ( $tree[0]['innerBlocks'] as $block ) {
		$labels[] = $block['attrs']['module']['meta']['adminLabel']['desktop']['value'];
	}

	return array(
		'found'  => (bool) diviops_call( 'tb_insert_sequence_matches', array( $scope, $inserted, $offset ) ),
		'labels' => $labels,
	);
}

$pbi_sel = 'divi/row[adminLabel="Anchor B"]';

// Controls that already hold. If one of these fails, the shift is not the defect
// and the assertion below is measuring something else.
$probe = diviops_pbi_retry_probe( 'after', 'selector', $pbi_sel );
assert_same( array( 'Anchor A', 'Anchor B', 'Inserted' ), $probe['labels'], "control: 'after' inserts past the anchor" );
assert_true( $probe['found'], "control: a retried 'after' insert finds its own sequence, because the anchor does not move" );

$probe = diviops_pbi_retry_probe( 'before', 'path', '0.1' );
assert_same( array( 'Anchor A', 'Inserted', 'Anchor B' ), $probe['labels'], "control: 'before' by path inserts ahead of the anchor" );
assert_true( $probe['found'], "control: a retried 'before' insert by parent_path finds its own sequence, because the path is positional" );

$probe = diviops_pbi_retry_probe( 'append', 'path', '0' );
assert_true( $probe['found'], "control: a retried 'append' finds its own sequence" );

// The defect.
$probe = diviops_pbi_retry_probe( 'before', 'selector', $pbi_sel );
assert_same( array( 'Anchor A', 'Inserted', 'Anchor B' ), $probe['labels'], "'before' by selector inserts ahead of the anchor" );
assert_true( $probe['found'], "a retried 'before' insert by parent_selector finds its own sequence instead of duplicating it (#592)" );
