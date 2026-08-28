<?php
// SPDX-License-Identifier: MIT
/**
 * #206/#207 regression, end-to-end over real HTTP: `module_update` must leave
 * `$variable()` design tokens in a SIBLING attribute byte-identical, and must
 * merge rather than replace when handed a nested object.
 *
 * Why this belongs in tests-live/ and not tests/ (#217).
 *
 * `tests/test-module-update-write-safety.php` already pins both defects at the
 * unit level, and it is a good test. What it cannot express is the property that
 * made #207 fire in the field: `module_update` re-encodes the WHOLE block
 * comment, so the offending bytes were in an attribute the caller never
 * mentioned. Reproducing that needs a real module whose untouched siblings
 * really do carry `$variable(...)$` tokens, written through the real REST
 * transport, stored by real WordPress, and re-read from the real database --
 * because the failure was WordPress own `serialize_blocks()` canonicalizing
 * bytes the plugin had written non-canonically, which `tests/wp-shim.php`
 * deliberately does not model (see tests-live/README.md).
 *
 * The fixture is not invented. Its attrs are the exact stored bytes of the
 * `divi/group` labelled "LCI Photo Lockup CTA Copy - Left" on dev-site page 331
 * -- the module from the original report -- read out with WP-CLI, with only
 * `module.decoration.sizing.desktop.value.flexType` wound back to its pre-fix
 * value `18_24`. That preserves the calibration numbers from the failure:
 *
 *   - the correct write is exactly -2 bytes (`18_24` -> `3_5`, a pure string-
 *     length change and nothing else);
 *   - the pre-#206 plain encoder produces attr JSON 144 bytes SHORTER than
 *     canonical for this module, because it writes a backslash-quote where
 *     WordPress stores " -- the same 144-byte gap the original report
 *     measured as "expected 46128 / stored 46272 (delta 144)".
 *
 * So a regression does not merely fail an equality check here: the byte-delta
 * assertion moves from -2 to -146, which is the original bug signature.
 *
 * Page 331 itself is only ever READ. Every write goes to a throwaway scratch
 * page created and force-deleted by harness.php.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/harness.php';

// -- the fixture: real stored bytes from page 331, flexType wound back -------

// Pre-write attrs. Canonical (this is how WordPress actually stores them), so
// the normalizer is a no-op on them and any byte delta after the write is
// attributable solely to the write itself.
$live217_attrs_before = '{"module":{"meta":{"adminLabel":{"desktop":{"value":"LCI Photo Lockup CTA Copy - Left"}}},"decoration":{"layout":{"desktop":{"value":{"display":"flex","flexDirection":"column","justifyContent":"center","alignItems":"flex-start","columnGap":"1.875rem","rowGap":"1.875rem"}}},"sizing":{"desktop":{"value":{"flexType":"18_24","minHeight":"450px","size":["flexShrink"]}},"tablet":{"value":{"flexType":"24_24","width":"100%","maxWidth":"100%"}}},"spacing":{"desktop":{"value":{"padding":{"top":"$variable({\u0022type\u0022:\u0022content\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gvid-e5nb1cw3b7\u0022,\u0022settings\u0022:{}}})$","bottom":"$variable({\u0022type\u0022:\u0022content\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gvid-e5nb1cw3b7\u0022,\u0022settings\u0022:{}}})$","left":"$variable({\u0022type\u0022:\u0022content\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gvid-w9d8zfuodm\u0022,\u0022settings\u0022:{}}})$","syncVertical":"on","syncHorizontal":"off"}}},"tablet":{"value":{"padding":{"top":"2rem","bottom":"2rem","left":"1.5rem","right":"1.5rem","syncVertical":"off","syncHorizontal":"off"}}}}}},"builderVersion":"5.9.0"}';

// Post-write attrs: byte-for-byte identical except `18_24` -> `3_5`.
$live217_attrs_after = '{"module":{"meta":{"adminLabel":{"desktop":{"value":"LCI Photo Lockup CTA Copy - Left"}}},"decoration":{"layout":{"desktop":{"value":{"display":"flex","flexDirection":"column","justifyContent":"center","alignItems":"flex-start","columnGap":"1.875rem","rowGap":"1.875rem"}}},"sizing":{"desktop":{"value":{"flexType":"3_5","minHeight":"450px","size":["flexShrink"]}},"tablet":{"value":{"flexType":"24_24","width":"100%","maxWidth":"100%"}}},"spacing":{"desktop":{"value":{"padding":{"top":"$variable({\u0022type\u0022:\u0022content\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gvid-e5nb1cw3b7\u0022,\u0022settings\u0022:{}}})$","bottom":"$variable({\u0022type\u0022:\u0022content\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gvid-e5nb1cw3b7\u0022,\u0022settings\u0022:{}}})$","left":"$variable({\u0022type\u0022:\u0022content\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gvid-w9d8zfuodm\u0022,\u0022settings\u0022:{}}})$","syncVertical":"on","syncHorizontal":"off"}}},"tablet":{"value":{"padding":{"top":"2rem","bottom":"2rem","left":"1.5rem","right":"1.5rem","syncVertical":"off","syncHorizontal":"off"}}}}}},"builderVersion":"5.9.0"}';

/**
 * Wrap the group in the minimal real Divi structure. No newlines anywhere --
 * post_content must land byte-for-byte, and a fixture with whitespace invites
 * wpautop-shaped noise into a byte-exact comparison.
 *
 * @param string $group_attrs Serialized attr JSON for the divi/group block.
 * @return string
 */
function live217_document( string $group_attrs ): string {
	return '<!-- wp:divi/section {"builderVersion":"5.9.0"} -->'
		. '<!-- wp:divi/row {"builderVersion":"5.9.0"} -->'
		. '<!-- wp:divi/column {"builderVersion":"5.9.0"} -->'
		. '<!-- wp:divi/group ' . $group_attrs . ' -->'
		. '<!-- /wp:divi/group -->'
		. '<!-- /wp:divi/column -->'
		. '<!-- /wp:divi/row -->'
		. '<!-- /wp:divi/section -->';
}

$live217_label      = 'LCI Photo Lockup CTA Copy - Left';
$live217_doc_before = live217_document( $live217_attrs_before );
$live217_doc_after  = live217_document( $live217_attrs_after );

// The three token-bearing padding values live in `decoration.spacing`, which
// NEITHER write below ever mentions. That is the whole point of this file.
// Written here as the exact stored byte sequence, " escapes and all.
$live217_token_top   = '"top":"$variable({\u0022type\u0022:\u0022content\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gvid-e5nb1cw3b7\u0022,\u0022settings\u0022:{}}})$"';
$live217_token_left  = '"left":"$variable({\u0022type\u0022:\u0022content\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gvid-w9d8zfuodm\u0022,\u0022settings\u0022:{}}})$"';
$live217_tablet_flex = '"tablet":{"value":{"flexType":"24_24","width":"100%","maxWidth":"100%"}}';
$live217_admin_label = '"adminLabel":{"desktop":{"value":"' . $live217_label . '"}}';
$live217_layout      = '"layout":{"desktop":{"value":{"display":"flex","flexDirection":"column","justifyContent":"center","alignItems":"flex-start","columnGap":"1.875rem","rowGap":"1.875rem"}}}';

// -- fixture sanity: a fixture that does not carry the offending bytes would --
// make every assertion below pass for the wrong reason ----------------------
assert_true(
	false !== strpos( $live217_doc_before, $live217_token_top )
		&& false !== strpos( $live217_doc_before, $live217_token_left ),
	'fixture sanity: the pre-write document really does carry \\u0022-escaped $variable() tokens in decoration.spacing'
);
assert_true(
	false !== strpos( $live217_doc_before, '"flexType":"18_24"' ),
	'fixture sanity: desktop flexType starts at 18_24, so the write is a real change'
);
assert_same(
	-2,
	strlen( $live217_doc_after ) - strlen( $live217_doc_before ),
	'fixture sanity: the ONLY byte difference between pre- and post-write documents is 18_24 -> 3_5 (-2 bytes)'
);

/**
 * Assert every attribute the write never mentioned survived byte-for-byte in
 * $stored. Shared by both fixtures because both must preserve exactly the same
 * set -- the two write shapes differ only in how the one touched leaf is
 * addressed.
 *
 * @param string $stored Stored post_content read back from the database.
 * @param string $case   Label for the assertion messages.
 */
function live217_assert_siblings_survived( string $stored, string $case ): void {
	global $live217_token_top, $live217_token_left, $live217_tablet_flex, $live217_admin_label, $live217_layout;

	assert_true(
		false !== strpos( $stored, $live217_token_top ),
		"{$case}: the padding.top \$variable() token survives byte-for-byte, still \\u0022-escaped -- WordPress did not have to canonicalize it on save"
	);
	assert_true(
		false !== strpos( $stored, $live217_token_left ),
		"{$case}: the padding.left \$variable() token (a different gvid) survives byte-for-byte"
	);
	assert_true(
		false === strpos( $stored, '\\"type\\"' ),
		"{$case}: no token was re-encoded with a raw backslash-quote -- that byte shape is exactly what tripped the integrity guard in #206"
	);
	assert_true(
		false !== strpos( $stored, $live217_tablet_flex ),
		"{$case}: sizing.tablet survives intact (flexType 24_24) -- the value the old replace-semantics destroyed, whose loss only showed up as broken mobile stacking at 544px"
	);
	assert_true(
		false !== strpos( $stored, $live217_admin_label ),
		"{$case}: module.meta.adminLabel survives"
	);
	assert_true(
		false !== strpos( $stored, $live217_layout ),
		"{$case}: decoration.layout survives intact"
	);
	assert_true(
		false !== strpos( $stored, '"flexType":"3_5","minHeight":"450px","size":["flexShrink"]' ),
		"{$case}: the touched leaf changed and its own siblings (minHeight, size) survived alongside it"
	);
}

// -- FIXTURE 1 -- canonical serialization -----------------------------------
// One dotted path, one scalar leaf. The bytes at risk are three attributes
// away from anything the caller named.

$live217_page_1 = live_create_scratch_page( $live217_doc_before, 'DiviOps live-suite #217 canonical serialization' );
assert_same(
	$live217_doc_before,
	live_get_post_content( $live217_page_1 ),
	'fixture 1: the pre-write document landed byte-for-byte, before module_update runs'
);

$live217_resp_1 = live_rest_call(
	'POST',
	'diviops/v1/module/update/' . $live217_page_1,
	array(
		'label'  => $live217_label,
		'attrs'  => array( 'module.decoration.sizing.desktop.value.flexType' => '3_5' ),
		'backup' => true,
	)
);
assert_same( 200, $live217_resp_1['status'], 'fixture 1: module/update returns HTTP 200' );
assert_true( ! empty( $live217_resp_1['body']['ok'] ), 'fixture 1: response envelope reports ok:true' );

// `write_applied` is the snapshot state that distinguishes "the write landed"
// from `write_failed_restored` -- the state #206 produced, where the integrity
// guard read WordPress own canonicalization as corruption and reverted a
// correct write.
$live217_backup_1 = $live217_resp_1['body']['data']['backup'] ?? array();
assert_same(
	'write_applied',
	$live217_backup_1['status'] ?? '',
	'fixture 1: the rollback snapshot reports write_applied -- the integrity guard did NOT revert the write'
);
assert_same(
	-2,
	(int) ( $live217_backup_1['after']['byte_length'] ?? 0 ) - (int) ( $live217_backup_1['before']['byte_length'] ?? 0 ),
	'fixture 1: the plugin own before/after byte lengths differ by exactly -2 -- the string-length change and nothing else'
);

$live217_stored_1 = live_get_post_content( $live217_page_1 );
assert_same(
	-2,
	strlen( $live217_stored_1 ) - strlen( $live217_doc_before ),
	'fixture 1: post_content as WordPress actually stored it is -2 bytes; a plain-encoded re-write of this module would be -146'
);
assert_same(
	$live217_doc_after,
	$live217_stored_1,
	'fixture 1: stored post_content is byte-for-byte the expected document -- the whole block comment round-tripped canonically'
);
live217_assert_siblings_survived( $live217_stored_1, 'fixture 1' );

// Snapshots are options, not posts, so harness.php scratch-post cleanup does
// not reach them. Delete it explicitly rather than leaking one per run.
$live217_snapshot_id = (string) ( $live217_backup_1['snapshot_id'] ?? '' );
assert_true( '' !== $live217_snapshot_id, 'fixture 1: a snapshot id was returned, so it can be cleaned up' );
if ( '' !== $live217_snapshot_id ) {
	$live217_deleted = live_rest_call( 'DELETE', 'diviops/v1/rollback-snapshot/delete/' . rawurlencode( $live217_snapshot_id ) );
	assert_same( 200, $live217_deleted['status'], 'fixture 1: the rollback snapshot created by this test was deleted again' );
}

// -- FIXTURE 2 -- merge semantics --------------------------------------------
// Same module, same resulting bytes, but submitted as a NESTED OBJECT under a
// dotless `module` key. Pre-#206 this took split_dot_path() one-segment leaf
// branch and assigned straight over $block_attrs['module'], destroying
// meta.adminLabel, decoration.layout, decoration.spacing and sizing.tablet in a
// call that reported success.

$live217_page_2 = live_create_scratch_page( $live217_doc_before, 'DiviOps live-suite #217 merge semantics' );
assert_same(
	$live217_doc_before,
	live_get_post_content( $live217_page_2 ),
	'fixture 2: the pre-write document landed byte-for-byte, before module_update runs'
);

$live217_resp_2 = live_rest_call(
	'POST',
	'diviops/v1/module/update/' . $live217_page_2,
	array(
		'label' => $live217_label,
		'attrs' => array(
			'module' => array(
				'decoration' => array(
					'sizing' => array(
						'desktop' => array(
							'value' => array( 'flexType' => '3_5' ),
						),
					),
				),
			),
		),
	)
);
assert_same( 200, $live217_resp_2['status'], 'fixture 2: module/update with a nested object returns HTTP 200' );
assert_true( ! empty( $live217_resp_2['body']['ok'] ), 'fixture 2: response envelope reports ok:true' );

$live217_stored_2 = live_get_post_content( $live217_page_2 );
assert_same(
	-2,
	strlen( $live217_stored_2 ) - strlen( $live217_doc_before ),
	'fixture 2: a nested-object write moves exactly the same -2 bytes as the dotted-path write -- it merged, it did not replace'
);
assert_same(
	$live217_doc_after,
	$live217_stored_2,
	'fixture 2: stored post_content is byte-for-byte identical to fixture 1 result -- the two payload shapes are equivalent'
);
live217_assert_siblings_survived( $live217_stored_2, 'fixture 2' );

echo "PASS: module-update-live-shape (31 assertions)\n";
