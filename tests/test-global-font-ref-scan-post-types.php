<?php
// SPDX-License-Identifier: MIT
/**
 * Global-font reference-scan post-type scope (#316).
 *
 * This is the first test file for trait-global-font.php. Until it existed, the
 * third consumer of `DiviOps_Agent::SCANNABLE_POST_TYPES` had no coverage of
 * any kind, and the question it answers is the one `global_font_delete` refuses
 * on: is this font referenced anywhere.
 *
 * The font delete has two reference signals and this file pins the one that is
 * unique to it. Its cheap fast-path probe is `variable_id_appears_anywhere()`,
 * shared verbatim with the variable path and pinned in
 * tests/test-variable-ref-scan-post-types.php. Its full walker is
 * `collect_global_font_refs()`, which has no SQL of its own — `get_posts()` is
 * the only query it issues, so the post-type list is the entire scope of what
 * it can ever see.
 *
 * The probe technique, the reason `parse_blocks()` is not shimmed, and what is
 * deliberately left unproved here are all documented in
 * tests/test-variable-ref-scan-post-types.php; this file follows it rather than
 * restating it. The one difference worth naming: `collect_global_font_refs()`
 * pre-checks for the bare `gfid-` substring where the variable scan pre-checks
 * for `$variable(`, so the fixtures are not interchangeable.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/* -------------------------------------------------------------------------
 * (1) The query collect_global_font_refs() issues, captured at runtime.
 * ---------------------------------------------------------------------- */

$font_scan_args                          = array();
$GLOBALS['diviops_test_get_posts_calls'] = array();
try {
	diviops_call( 'collect_global_font_refs' );
} catch ( Error $font_scan_error ) {
	unset( $font_scan_error );
}
$font_scan_args = $GLOBALS['diviops_test_get_posts_calls'][0] ?? array();

assert_true(
	array() !== $font_scan_args,
	'collect_global_font_refs() issued a get_posts() query, so the assertions below inspect something real'
);
assert_same(
	DiviOps_Agent::SCANNABLE_POST_TYPES,
	$font_scan_args['post_type'] ?? null,
	'collect_global_font_refs() scopes by SCANNABLE_POST_TYPES, so a font used only in a Theme Builder layout cannot report as unreferenced (#316)'
);
assert_same(
	array( 'publish', 'draft', 'private' ),
	$font_scan_args['post_status'] ?? null,
	'collect_global_font_refs() scans live statuses only, matching the variable and preset scanners'
);

/* -------------------------------------------------------------------------
 * (2) The scan reaches an et_footer_layout post's content.
 *
 * The registry is replaced outright rather than added to: every test file in
 * tests/ shares one process and one $GLOBALS['diviops_test_posts'], and this
 * probe depends on which posts the scan reaches, so a fixture left behind by
 * another file would make the result depend on file discovery order.
 * ---------------------------------------------------------------------- */

/**
 * Run collect_global_font_refs() against exactly one fixture post and report
 * whether the scanner reached that post's content.
 *
 * A post that is fetched and carries `gfid-` dies on the unshimmed
 * `parse_blocks()`; one that is filtered out, or skipped by the cheap
 * pre-check, survives to die on the unshimmed `et_get_option()` behind the
 * preset-registry pass. Any other Error is re-thrown rather than counted.
 *
 * @param string $post_type Fixture post type.
 * @param string $content   Fixture post_content.
 * @return bool Whether the scanner parsed this post.
 */
function global_font_ref_scan_reaches( string $post_type, string $content ): bool {
	$saved                         = $GLOBALS['diviops_test_posts'];
	$GLOBALS['diviops_test_posts'] = array();
	diviops_test_register_post( 987301, $content, $post_type, 'global-font ref-scan fixture' );

	try {
		diviops_call( 'collect_global_font_refs' );
	} catch ( Error $e ) {
		if ( false !== strpos( $e->getMessage(), 'parse_blocks' ) ) {
			return true;
		}
		if ( false !== strpos( $e->getMessage(), 'et_get_option' ) ) {
			return false;
		}
		throw $e;
	} finally {
		$GLOBALS['diviops_test_posts'] = $saved;
	}

	throw new RuntimeException(
		'collect_global_font_refs() returned without touching parse_blocks() or et_get_option(); this probe can no longer tell a scanned post from a filtered one'
	);
}

// The token shape Divi actually stores: a JSON payload inside the token, with its
// quotes unicode-escaped because the whole attrs blob is itself JSON inside the
// block comment. parse_blocks() decodes that escaping, which is why section (3)
// drives the walker over a decoded tree rather than over this string.
$global_font_markup = '<!-- wp:divi/text {"builderVersion":"5.9.0","module":{"decoration":{"font":{"font":{"desktop":{"value":{"family":"$variable({\u0022type\u0022:\u0022font\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gfid-abc123\u0022,\u0022settings\u0022:{}}})$"}}}}}}} /-->';

// The gap, stated as an assertion. RED against the pre-#315 shim: get_posts()
// compared the post_type array with !==, matched nothing, and the scan fell
// straight through to the preset registry. RED again if SCANNABLE_POST_TYPES
// narrows back to page/post.
assert_true(
	global_font_ref_scan_reaches( 'et_footer_layout', $global_font_markup ),
	'a font referenced only from an et_footer_layout post is fetched and walked by the global-font reference scan (#316)'
);

foreach ( array( 'et_header_layout', 'et_body_layout', 'et_template', 'et_pb_layout', 'et_pb_canvas' ) as $global_font_tb_type ) {
	assert_true(
		global_font_ref_scan_reaches( $global_font_tb_type, $global_font_markup ),
		"a font reference inside a {$global_font_tb_type} post is fetched by the global-font reference scan"
	);
}

// Control: `page` was always in scope. If this ever stops reaching parse_blocks()
// the probe itself has gone vacuous and every assertion above is meaningless.
assert_true(
	global_font_ref_scan_reaches( 'page', $global_font_markup ),
	'the probe is not vacuous — a plain page carrying the same token is still parsed'
);

// Control: the scan did not simply become "every post type". et_theme_builder is
// the template-set assignment record and is deliberately outside the list.
assert_same(
	false,
	global_font_ref_scan_reaches( 'et_theme_builder', $global_font_markup ),
	'the global-font scan is still scoped — et_theme_builder is not fetched'
);

// Control: the cheap pre-check still short-circuits content that cannot hold a
// font reference, so the post-type scope did not widen the parse cost.
assert_same(
	false,
	global_font_ref_scan_reaches( 'et_footer_layout', '<!-- wp:paragraph --><p>no fonts here</p><!-- /wp:paragraph -->' ),
	'content with no gfid- substring is still skipped before parse_blocks()'
);

/* -------------------------------------------------------------------------
 * (3) The walker recognises the reference the fixture carries.
 *
 * The probe above proves the post reaches parse_blocks(); it cannot prove the
 * id is then extracted, because the block tree parse_blocks() would return does
 * not exist in this harness. Driving the walker directly over the equivalent
 * hand-built tree closes that half: without it, a fixture whose token shape the
 * walker does not actually match would still satisfy every assertion above.
 * ---------------------------------------------------------------------- */

$global_font_all_ids   = array();
$global_font_local_ids = array();
$global_font_blocks    = array(
	array(
		'blockName'   => 'divi/section',
		'attrs'       => array(),
		'innerBlocks' => array(
			array(
				'blockName'   => 'divi/text',
				'attrs'       => array(
					'module' => array(
						'decoration' => array(
							'font' => array(
								'font' => array(
									'desktop' => array(
										'value' => array( 'family' => '$variable({"type":"font","value":{"name":"gfid-abc123","settings":{}}})$' ),
									),
								),
							),
						),
					),
				),
				'innerBlocks' => array(),
			),
		),
	),
);

$global_font_walk_args = array( $global_font_blocks, &$global_font_all_ids, &$global_font_local_ids );
diviops_call_ref( 'walk_blocks_for_gfid_refs', $global_font_walk_args );

assert_same(
	array( 'gfid-abc123' => 1 ),
	$global_font_all_ids,
	'the gfid walker extracts the id from the same token shape the post fixtures above carry, so those fixtures represent a real reference rather than inert text'
);

/* -------------------------------------------------------------------------
 * (4) The destructive path reads both signals.
 *
 * global_font_delete() is what a wrong answer costs. It gates on the shared
 * fast-path probe and, on a hit, on the full walker — so a post type either
 * one of them cannot see is a delete that proceeds against a live reference.
 * ---------------------------------------------------------------------- */

$global_font_delete_reflection = new ReflectionMethod( 'DiviOps_Agent', 'global_font_delete' );
$global_font_delete_start      = $global_font_delete_reflection->getStartLine() - 1;
$global_font_delete_source     = implode(
	'',
	array_slice(
		file( $global_font_delete_reflection->getFileName() ),
		$global_font_delete_start,
		$global_font_delete_reflection->getEndLine() - $global_font_delete_start
	)
);

assert_true(
	false !== strpos( $global_font_delete_source, 'variable_id_appears_anywhere' ),
	'global_font_delete() gates on the shared existence probe, whose post-type scope is pinned in tests/test-variable-ref-scan-post-types.php'
);
assert_true(
	false !== strpos( $global_font_delete_source, 'collect_global_font_refs' ),
	'global_font_delete() builds its refusal from collect_global_font_refs(), the walker whose post-type scope is pinned above'
);
