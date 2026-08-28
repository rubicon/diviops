<?php
// SPDX-License-Identifier: MIT
/**
 * #304: `module_update`'s dry_run plan must carry `removed` over real HTTP, in the
 * envelope slot callers actually read -- `data.plan.changes[].removed`.
 *
 * `tests/test-module-update-dry-run-removals.php` already pins the behavior at 13
 * assertions, and it is a good test. What it cannot reach is the REST route: it
 * drives read_module_attr_path() -> apply_module_attr_updates() ->
 * module_update_plan_change() directly, so nothing in it observes the field
 * surviving json_encode, the HTTP hop, and json_decode, and nothing in it observes
 * WHERE in the envelope the field lands. That is the class of gap tests-live/
 * exists for -- three bugs (#28, #36, #35/#97) passed the whole shimmed suite and
 * failed on first contact with real WordPress.
 *
 * The fixture is not invented. Both modules are the exact stored bytes of blocks on
 * dev-site page 900390, read out with WP-CLI:
 *
 *   - the `divi/breadcrumbs` module, whose
 *     `breadcrumbLink.decoration.font.font.desktop.value.color` really does hold a
 *     `$variable()` color token naming `gcid-body-color`;
 *   - the `divi/column` labelled "Hero Copy Column", whose
 *     `module.decoration.sizing.desktop.value.size` really is a two-entry list.
 *
 * Two deliberate deviations from those bytes, both stated so a reader does not have
 * to diff the page to find them: `globalParent` is stripped (the scratch page is not
 * a global layout, so keeping a dangling parent id would be less faithful, not
 * more), and one fixture duplicates a `size` entry, because no real page carries a
 * duplicate and the duplicate case is a real defect review caught on PR #295.
 *
 * Page 900390 is only ever READ. Every call below targets a throwaway scratch page
 * created and force-deleted by harness.php, and every call passes `dry_run: true`,
 * so nothing here writes at all -- which is itself the last thing asserted.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/harness.php';

// -- the fixture: real stored bytes from page 900390 -------------------------

$live304_breadcrumbs_attrs = '{"module":{"decoration":{"spacing":{"desktop":{"value":{"margin":{"top":"0","bottom":"$variable({\\u0022type\\u0022:\\u0022content\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022gvid-1okpthdbn6\\u0022,\\u0022settings\\u0022:{}}})$","syncVertical":"off","syncHorizontal":"off"},"padding":{"top":"0","bottom":"1.2rem","left":"0","right":"0","syncVertical":"off","syncHorizontal":"off"}}}}}},"trail":{"advanced":{"htmlTag":{"desktop":{"value":"nav"}}}},"breadcrumb":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"","weight":"800","lineHeight":"$variable({\\u0022type\\u0022:\\u0022content\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022gvid-mh4a4sv92x\\u0022,\\u0022settings\\u0022:{}}})$","letterSpacing":"$variable({\\u0022type\\u0022:\\u0022content\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022gvid-tnaa1ty0dx\\u0022,\\u0022settings\\u0022:{}}})$","size":"$variable({\\u0022type\\u0022:\\u0022content\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022gvid-0yobjol7sn\\u0022,\\u0022settings\\u0022:{}}})$"}}}}}},"breadcrumbLink":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"$variable({\\u0022type\\u0022:\\u0022color\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022gcid-body-color\\u0022,\\u0022settings\\u0022:{\\u0022opacity\\u0022:72}}})$","weight":"800"},"hover":{"color":"$variable({\\u0022type\\u0022:\\u0022color\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022gcid-link-color\\u0022,\\u0022settings\\u0022:{}}})$"}}}}}},"home":{"innerContent":{"desktop":{"value":{"text":"Home","url":"$variable({\\u0022type\\u0022:\\u0022content\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022home_url\\u0022,\\u0022settings\\u0022:{}}})$"}}},"decoration":{"font":{"font":{"desktop":{"value":{"color":"$variable({\\u0022type\\u0022:\\u0022color\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022gcid-body-color\\u0022,\\u0022settings\\u0022:{\\u0022opacity\\u0022:72}}})$","weight":"800"},"hover":{"color":"$variable({\\u0022type\\u0022:\\u0022color\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022gcid-link-color\\u0022,\\u0022settings\\u0022:{}}})$"}}}}}},"separator":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"$variable({\\u0022type\\u0022:\\u0022color\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022gcid-body-color\\u0022,\\u0022settings\\u0022:{\\u0022opacity\\u0022:34}}})$","weight":"800"}}}},"spacing":{"desktop":{"value":{"margin":{"left":"$variable({\\u0022type\\u0022:\\u0022content\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022gvid-t5pdtdfhuo\\u0022,\\u0022settings\\u0022:{}}})$","right":"$variable({\\u0022type\\u0022:\\u0022content\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022gvid-t5pdtdfhuo\\u0022,\\u0022settings\\u0022:{}}})$","syncHorizontal":"off"}}}}}},"builderVersion":"5.9.0"}';

$live304_column_attrs = '{"module":{"meta":{"adminLabel":{"desktop":{"value":"Hero Copy Column"}}},"decoration":{"sizing":{"phone":{"value":{"flexType":"24_24"}},"phoneWide":{"value":{"flexType":"24_24"}},"tablet":{"value":{"flexType":"3_5"}},"tabletWide":{"value":{"flexType":"3_5"}},"desktop":{"value":{"flexType":"3_5","size":["flexShrink","flexGrow"],"alignSelf":"flex-start"}}},"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.1","decoration":{"layout":{"desktop":{"value":{"display":"flex","flexDirection":"column","rowGap":"0.95rem"}}},"sizing":{"desktop":{"value":{"flexType":"13_24"}},"tablet":{"value":{"flexType":"24_24","width":"100%","maxWidth":"100%"}}}}}';

// Same column, one `size` entry duplicated. A membership test finds the surviving
// copy and reports nothing removed; a multiset diff reports the one that is gone.
$live304_column_dup_attrs = str_replace(
	'"size":["flexShrink","flexGrow"]',
	'"size":["flexShrink","flexShrink","flexGrow"]',
	$live304_column_attrs
);

/**
 * Wrap the two modules in the minimal real Divi structure. No newlines anywhere --
 * post_content must land byte-for-byte, and the final assertion in this file is a
 * byte-exact comparison of the stored content against what was created.
 *
 * @param string $column_attrs Serialized attr JSON for the divi/column block.
 * @param string $breadcrumbs_attrs Serialized attr JSON for the divi/breadcrumbs block.
 * @return string
 */
function live304_document( string $column_attrs, string $breadcrumbs_attrs ): string {
	return '<!-- wp:divi/section {"builderVersion":"5.9.0"} -->'
		. '<!-- wp:divi/row {"builderVersion":"5.9.0"} -->'
		. '<!-- wp:divi/column ' . $column_attrs . ' -->'
		. '<!-- wp:divi/breadcrumbs ' . $breadcrumbs_attrs . ' /-->'
		. '<!-- /wp:divi/column -->'
		. '<!-- /wp:divi/row -->'
		. '<!-- /wp:divi/section -->';
}

$live304_column_label = 'Hero Copy Column';
$live304_color_path   = 'breadcrumbLink.decoration.font.font.desktop.value.color';
$live304_size_path    = 'module.decoration.sizing.desktop.value.size';

// The token as a caller sees it: real double quotes, because the \u0022 escapes
// in the stored bytes are JSON escaping, not part of the value. Every byte of it has to
// survive the plan's own json_encode and this file's json_decode to match.
$live304_color_token = '$variable({"type":"color","value":{"name":"gcid-body-color","settings":{"opacity":72}}})$';

$live304_doc     = live304_document( $live304_column_attrs, $live304_breadcrumbs_attrs );
$live304_doc_dup = live304_document( $live304_column_dup_attrs, $live304_breadcrumbs_attrs );

// -- fixture sanity: a fixture missing the values under test would make every -
// assertion below pass for the wrong reason -----------------------------------
assert_true(
	false !== strpos( $live304_doc, '"color":"$variable({\\u0022type\\u0022:\\u0022color\\u0022,\\u0022value\\u0022:{\\u0022name\\u0022:\\u0022gcid-body-color\\u0022,\\u0022settings\\u0022:{\\u0022opacity\\u0022:72}}})$"' ),
	'fixture sanity: the document carries the breadcrumbLink color token in its stored \\u0022-escaped form'
);
assert_true(
	false !== strpos( $live304_doc, '"size":["flexShrink","flexGrow"]' ),
	'fixture sanity: the column carries a real two-entry sizing list'
);
assert_true(
	false !== strpos( $live304_doc_dup, '"size":["flexShrink","flexShrink","flexGrow"]' ),
	'fixture sanity: the duplicate-entry fixture really does carry two copies of flexShrink'
);

/**
 * Drive one dry_run module_update and return the single plan change entry from the
 * slot a caller reads it from -- `data.plan.changes[]`. Asserting the surrounding
 * envelope here rather than in each case keeps every case honest about reading the
 * real slot instead of hunting for `removed` wherever it happens to be.
 *
 * @param int                 $page_id Scratch page id.
 * @param array<string,mixed> $body    Request body, minus dry_run.
 * @param string              $case    Label for the assertion messages.
 * @return array<string,mixed> The plan change entry, or an empty array if the shape was wrong.
 */
function live304_plan_change( int $page_id, array $body, string $case ): array {
	$body['dry_run'] = true;
	$response        = live_rest_call( 'POST', 'diviops/v1/module/update/' . $page_id, $body );

	assert_same( 200, $response['status'], "{$case}: module/update returns HTTP 200" );
	assert_true( ! empty( $response['body']['ok'] ), "{$case}: response envelope reports ok:true" );
	assert_same( true, $response['body']['data']['dry_run'] ?? null, "{$case}: the response reports dry_run:true" );

	$changes = $response['body']['data']['plan']['changes'] ?? null;
	assert_true( is_array( $changes ) && 1 === count( $changes ), "{$case}: data.plan.changes carries exactly one entry" );
	if ( ! is_array( $changes ) || 1 !== count( $changes ) ) {
		return array();
	}

	assert_true(
		array_key_exists( 'removed', $changes[0] ),
		"{$case}: the change entry carries `removed` at data.plan.changes[].removed -- the slot a caller reads"
	);

	return $changes[0];
}

// -- the scratch pages -------------------------------------------------------

$live304_page     = live_create_scratch_page( $live304_doc, 'DiviOps live-suite #304 dry_run removals' );
$live304_page_dup = live_create_scratch_page( $live304_doc_dup, 'DiviOps live-suite #304 dry_run duplicate entries' );

assert_same(
	$live304_doc,
	live_get_post_content( $live304_page ),
	'the fixture document landed byte-for-byte, before any module_update runs'
);

// A snapshot for this page must not exist before, and must still not exist after a
// dry_run that asked for a backup. Read now so the after-comparison is against a
// measured baseline rather than an assumed zero.
$live304_snapshots_before = live_rest_call( 'GET', 'diviops/v1/rollback-snapshot/list?target_id=' . $live304_page );
assert_same( 200, $live304_snapshots_before['status'], 'rollback-snapshot/list answers 200 for the scratch page' );
$live304_snapshot_count_before = (int) ( $live304_snapshots_before['body']['data']['count'] ?? -1 );
assert_same( 0, $live304_snapshot_count_before, 'the scratch page starts with no rollback snapshots' );

// -- CASE 1 -- explicit null on a real design token --------------------------
// Clearing a value is how a targeted reset is spelled, and before #219 it previewed
// as an ordinary value change: `after` reads null and nothing named what was lost.

$live304_null_change = live304_plan_change(
	$live304_page,
	array(
		'auto_index' => 'breadcrumbs:1',
		'attrs'      => array( $live304_color_path => null ),
	),
	'explicit null'
);

// array_key_exists, not `?? null`: the value under test IS null, so a null-coalesce
// cannot tell a reported null apart from an absent field.
assert_true(
	array_key_exists( 'after', $live304_null_change ),
	'explicit null: the change entry carries `after`'
);
assert_same(
	null,
	$live304_null_change['after'],
	'explicit null: `after` is null, which on its own reads as an ordinary value change'
);
assert_same(
	array( array( 'path' => $live304_color_path, 'value' => $live304_color_token ) ),
	$live304_null_change['removed'] ?? null,
	'explicit null: `removed` names the cleared design token byte-for-byte, quotes intact, after the JSON round trip through the REST envelope'
);

// -- CASE 2 -- list replacement ----------------------------------------------
// Lists replace wholesale rather than merging index-wise, so a shorter replacement
// drops entries. Dropping the FIRST of two is the discriminating case: an
// index-wise diff would name the trailing `flexGrow` that actually survived.

$live304_list_change = live304_plan_change(
	$live304_page,
	array(
		'label' => $live304_column_label,
		'attrs' => array( $live304_size_path => array( 'flexGrow' ) ),
	),
	'list replacement'
);

assert_same(
	array( 'flexGrow' ),
	$live304_list_change['after'] ?? null,
	'list replacement: `after` reports the surviving list'
);
assert_same(
	array( array( 'path' => $live304_size_path, 'value' => 'flexShrink' ) ),
	$live304_list_change['removed'] ?? null,
	'list replacement: `removed` names the dropped entry, not the trailing index that survived'
);

// -- CASE 3 -- duplicate entries ---------------------------------------------
// Caught by review on PR #295 (4f81c43): a membership test finds the surviving copy
// of a duplicated entry and reports nothing removed, which is the same
// under-reporting #219 exists to close, narrowed to duplicate values.

$live304_dup_change = live304_plan_change(
	$live304_page_dup,
	array(
		'label' => $live304_column_label,
		'attrs' => array( $live304_size_path => array( 'flexShrink', 'flexGrow' ) ),
	),
	'duplicate entries'
);

assert_same(
	array( array( 'path' => $live304_size_path, 'value' => 'flexShrink' ) ),
	$live304_dup_change['removed'] ?? null,
	'duplicate entries: dropping one of two identical entries reports one removal, not zero'
);

// -- CASE 4 -- the safe case -------------------------------------------------
// An absent `removed` and an empty one are indistinguishable to a caller, which is
// the ambiguity #219 is about -- so present-and-empty gets its own assertion.

$live304_merge_change = live304_plan_change(
	$live304_page,
	array(
		'label' => $live304_column_label,
		'attrs' => array( 'module.decoration.sizing.desktop.value' => array( 'alignSelf' => 'center' ) ),
	),
	'merging payload'
);

assert_same(
	array(
		'flexType'  => '3_5',
		'size'      => array( 'flexShrink', 'flexGrow' ),
		'alignSelf' => 'center',
	),
	$live304_merge_change['after'] ?? null,
	'merging payload: `after` reports the merged map, siblings intact'
);
assert_same(
	array(),
	$live304_merge_change['removed'] ?? null,
	'merging payload: `removed` is present and empty -- a safe write previews differently from a destructive one'
);

// -- CASE 5 -- dry_run really did not write ----------------------------------
// The four cases above include a null assignment and two list replacements. If any
// of them had written, the stored content would differ here.

$live304_backup_response = live_rest_call(
	'POST',
	'diviops/v1/module/update/' . $live304_page,
	array(
		'dry_run' => true,
		'backup'  => true,
		'label'   => $live304_column_label,
		'attrs'   => array( $live304_size_path => array( 'flexGrow' ) ),
	)
);
assert_same( 200, $live304_backup_response['status'], 'dry_run with backup:true returns HTTP 200' );

$live304_backup = $live304_backup_response['body']['data']['backup'] ?? array();
assert_same( false, $live304_backup['created'] ?? null, 'dry_run with backup:true reports the snapshot as planned, not created' );
assert_same( true, $live304_backup['dry_run'] ?? null, 'dry_run with backup:true reports the snapshot block itself as a dry run' );

$live304_snapshots_after = live_rest_call( 'GET', 'diviops/v1/rollback-snapshot/list?target_id=' . $live304_page );
assert_same( 200, $live304_snapshots_after['status'], 'rollback-snapshot/list answers 200 after the dry runs' );
assert_same(
	$live304_snapshot_count_before,
	(int) ( $live304_snapshots_after['body']['data']['count'] ?? -1 ),
	'no rollback snapshot was created by any dry_run, including the one that asked for a backup'
);

assert_same(
	$live304_doc,
	live_get_post_content( $live304_page ),
	'post_content is byte-identical to the fixture -- five dry runs, including two destructive payloads, wrote nothing'
);
assert_same(
	$live304_doc_dup,
	live_get_post_content( $live304_page_dup ),
	'the duplicate-entry page post_content is byte-identical too'
);

echo "PASS: module-update-dry-run-removals-live (41 assertions)\n";
