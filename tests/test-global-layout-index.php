<?php
/**
 * divi/global-layout wrapper counting parity between read and write paths.
 *
 * page_get_layout (via parse_blocks(), which Divi's own parser expands on GET)
 * counts a divi/global-layout wrapper as whatever it resolves to (its own
 * attrs.blockName, e.g. "section:1"). Every raw scanner and parsed-tree
 * counter that only looked at the wrapper's own literal name counted it as
 * "global-layout:1" instead, so every real block after it landed one
 * auto_index position off from what the read path reported — an agent that
 * read section:1 and wrote section:1 edited a different section.
 *
 * These tests pin the fix (#13, Option A): every counting site resolves a
 * wrapper's counted type from its own attrs.blockName, with a fallback to
 * literal global-layout:N counting when that attr is absent. This is a
 * string/attrs-level fix — none of it routes content through
 * parse_blocks()/serialize_blocks().
 *
 * Fixture shapes are modeled on the real reference page 900390: a
 * divi/placeholder block, then a divi/global-layout wrapper carrying
 * `{"globalModule":"900296","blockName":"divi/section",...}`, followed by two
 * real divi/section blocks with distinct admin labels.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

// ── Raw-content fixture: placeholder, global-layout wrapper (resolves to
// divi/section), then two real sections ──────────────────────────────────

$placeholder = '<!-- wp:divi/placeholder {"builderVersion":"5.9.0"} /-->';

$wrapper = implode(
	'',
	array(
		'<!-- wp:divi/global-layout {"globalModule":"900296","blockName":"divi/section","builderVersion":"5.9.0"} -->',
		'<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:divi/global-layout -->',
	)
);

$section_two = implode(
	'',
	array(
		'<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Second Section"}}}},"builderVersion":"5.9.0"} -->',
		'<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:divi/section -->',
	)
);

$section_three = implode(
	'',
	array(
		'<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Third Section"}}}},"builderVersion":"5.9.0"} -->',
		'<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:divi/section -->',
	)
);

$page = $placeholder . $wrapper . $section_two . $section_three;

// ── find_block(): the wrapper counts as section:1, real sections shift ───

$section1 = diviops_call( 'find_block', array( $page, '', '', 'section:1' ) );
assert_true( ! is_wp_error( $section1 ), 'find_block resolves section:1 when a global-layout wrapper precedes real sections' );
if ( ! is_wp_error( $section1 ) ) {
	assert_same( 'section', $section1['type'], 'section:1 reports the resolved type, not global-layout' );
	$markup = substr( $page, $section1['start'], $section1['end'] - $section1['start'] );
	assert_true(
		0 === strpos( $markup, '<!-- wp:divi/global-layout ' ),
		"section:1 resolves to the wrapper's own span, matching what the read path calls section:1"
	);
	assert_true(
		substr( $markup, -strlen( '<!-- /wp:divi/global-layout -->' ) ) === '<!-- /wp:divi/global-layout -->',
		'the wrapper span still ends at its own literal closer, not a divi/section closer'
	);
}

$section2 = diviops_call( 'find_block', array( $page, '', '', 'section:2' ) );
assert_true( ! is_wp_error( $section2 ), 'find_block resolves section:2 to the first real section' );
if ( ! is_wp_error( $section2 ) ) {
	$markup = substr( $page, $section2['start'], $section2['end'] - $section2['start'] );
	assert_true(
		false !== strpos( $markup, 'Second Section' ),
		'section:2 is the first real section, shifted one position by the wrapper'
	);
}

$section3 = diviops_call( 'find_block', array( $page, '', '', 'section:3' ) );
assert_true( ! is_wp_error( $section3 ), 'find_block resolves section:3 to the second real section' );
if ( ! is_wp_error( $section3 ) ) {
	$markup = substr( $page, $section3['start'], $section3['end'] - $section3['start'] );
	assert_true( false !== strpos( $markup, 'Third Section' ), 'section:3 is the second real section' );
}

// ── Negative test: a wrapper with no blockName attr falls back to
// global-layout:N and does not error ──────────────────────────────────────

$unresolved_wrapper = implode(
	'',
	array(
		'<!-- wp:divi/global-layout {"globalModule":"900297","builderVersion":"5.9.0"} -->',
		'<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:divi/global-layout -->',
	)
);
$page_unresolved = $placeholder . $unresolved_wrapper . $section_two;

$fallback = diviops_call( 'find_block', array( $page_unresolved, '', '', 'global-layout:1' ) );
assert_true( ! is_wp_error( $fallback ), 'a global-layout wrapper with no blockName attr still counts as global-layout:1' );

$still_first = diviops_call( 'find_block', array( $page_unresolved, '', '', 'section:1' ) );
assert_true( ! is_wp_error( $still_first ), 'section:1 still resolves when the wrapper has no blockName' );
if ( ! is_wp_error( $still_first ) ) {
	$markup = substr( $page_unresolved, $still_first['start'], $still_first['end'] - $still_first['start'] );
	assert_true(
		false !== strpos( $markup, 'Second Section' ),
		'without a resolvable blockName, section:1 is still the first real section — no shift, no error'
	);
}

// ── find_all_sections(): the wrapper is a match, ahead of the real sections ──

$matches = diviops_call( 'find_all_sections', array( $page, '', '5.9.0' ) );
assert_same( 3, count( $matches ), 'find_all_sections includes the wrapper alongside the two real sections' );
if ( 3 === count( $matches ) ) {
	$m0 = substr( $page, $matches[0]['start'], $matches[0]['end'] - $matches[0]['start'] );
	$m1 = substr( $page, $matches[1]['start'], $matches[1]['end'] - $matches[1]['start'] );
	$m2 = substr( $page, $matches[2]['start'], $matches[2]['end'] - $matches[2]['start'] );
	assert_true( 0 === strpos( $m0, '<!-- wp:divi/global-layout ' ), 'the first find_all_sections match is the wrapper' );
	assert_true( false !== strpos( $m1, 'Second Section' ), 'the second find_all_sections match is the first real section' );
	assert_true( false !== strpos( $m2, 'Third Section' ), 'the third find_all_sections match is the second real section' );
}

$matches_unresolved = diviops_call( 'find_all_sections', array( $page_unresolved, '', '5.9.0' ) );
assert_same(
	1,
	count( $matches_unresolved ),
	'a global-layout wrapper with no blockName attr is not counted as a section by find_all_sections'
);

// #12 fixed find_all_sections()'s missing is_self_closing check, so a
// SELF-CLOSING wrapper resolving to a section now matches the same way
// find_block() already did: a complete one-comment span of its own, found
// alongside the real section that follows it, rather than silently dropped.
$self_closing_wrapper_page = implode(
	'',
	array(
		'<!-- wp:divi/global-layout {"globalModule":"900298","blockName":"divi/section","builderVersion":"5.9.0"} /-->',
		$section_two,
	)
);
$self_closing_matches = diviops_call( 'find_all_sections', array( $self_closing_wrapper_page, '', '5.9.0' ) );
assert_same(
	2,
	count( $self_closing_matches ),
	'a self-closing wrapper resolving to a section is found alongside the real section that follows it'
);
if ( 2 === count( $self_closing_matches ) ) {
	$wrapper_match_markup = substr(
		$self_closing_wrapper_page,
		$self_closing_matches[0]['start'],
		$self_closing_matches[0]['end'] - $self_closing_matches[0]['start']
	);
	assert_true(
		'/-->' === substr( $wrapper_match_markup, -4 ),
		"the wrapper's own find_all_sections match stops at its own self-closing marker, not the real section's closer"
	);
	$real_section_match_markup = substr(
		$self_closing_wrapper_page,
		$self_closing_matches[1]['start'],
		$self_closing_matches[1]['end'] - $self_closing_matches[1]['start']
	);
	assert_true(
		false !== strpos( $real_section_match_markup, 'Second Section' ),
		'the second find_all_sections match is still the real section that follows the wrapper'
	);
}

// find_block() already checked is_self_closing before #12, so it was and
// remains unaffected — the self-closing wrapper still resolves to its own
// one-comment span.
$self_closing_via_find_block = diviops_call( 'find_block', array( $self_closing_wrapper_page, '', '', 'section:1' ) );
assert_true(
	! is_wp_error( $self_closing_via_find_block ),
	'find_block still resolves section:1 for a self-closing wrapper'
);
if ( ! is_wp_error( $self_closing_via_find_block ) ) {
	$self_closing_markup = substr(
		$self_closing_wrapper_page,
		$self_closing_via_find_block['start'],
		$self_closing_via_find_block['end'] - $self_closing_via_find_block['start']
	);
	assert_true(
		'/-->' === substr( $self_closing_markup, -4 ),
		"find_block's self-closing wrapper span stops at its own self-closing marker"
	);
}

// ── parse_block_tree(): the read path itself, via diviops_call_ref ───────

$tree_blocks = array(
	array(
		'blockName'    => 'divi/placeholder',
		'attrs'        => array( 'builderVersion' => '5.9.0' ),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/global-layout',
		'attrs'        => array(
			'globalModule'   => '900296',
			'blockName'      => 'divi/section',
			'builderVersion' => '5.9.0',
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array(
			'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Second Section' ) ) ) ),
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array(
			'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Third Section' ) ) ) ),
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
);

$tree_counters = array();
$tree_args     = array( $tree_blocks, 0, &$tree_counters, false );
$tree          = diviops_call_ref( 'parse_block_tree', $tree_args );

assert_same( 'placeholder:1', $tree[0]['auto_index'], 'a preceding placeholder counts under its own type, unaffected' );
assert_same( 'section:1', $tree[1]['auto_index'], 'parse_block_tree resolves the wrapper to section:1' );
assert_same( 'section:2', $tree[2]['auto_index'], 'the first real section shifts to section:2' );
assert_same( 'section:3', $tree[3]['auto_index'], 'the second real section shifts to section:3' );
assert_same(
	array(
		'placeholder' => 1,
		'section'     => 3,
	),
	$tree_counters,
	'the wrapper is folded into the section counter, not tracked as its own global-layout type'
);

// Negative: a wrapper with no blockName counts under its own literal type,
// and does not shift the section that follows it.
$tree_blocks_unresolved = array(
	array(
		'blockName'    => 'divi/global-layout',
		'attrs'        => array( 'globalModule' => '900297' ),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array(),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
);
$tree_counters_unresolved = array();
$tree_args_unresolved     = array( $tree_blocks_unresolved, 0, &$tree_counters_unresolved, false );
$tree_unresolved          = diviops_call_ref( 'parse_block_tree', $tree_args_unresolved );

assert_same(
	'global-layout:1',
	$tree_unresolved[0]['auto_index'],
	'a wrapper with no blockName attr falls back to counting as global-layout:1'
);
assert_same(
	'section:1',
	$tree_unresolved[1]['auto_index'],
	'without a resolvable blockName the following real section is not shifted'
);

// Third-party modules nested under a real section, alongside the wrapper:
// global-layout resolution must not perturb an unrelated namespaced counter.
$mixed_tree_blocks = array(
	array(
		'blockName'    => 'divi/global-layout',
		'attrs'        => array(
			'globalModule'   => '900296',
			'blockName'      => 'divi/section',
			'builderVersion' => '5.9.0',
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array( 'builderVersion' => '5.9.0' ),
		'innerContent' => array( null ),
		'innerBlocks'  => array(
			array(
				'blockName'    => 'difl/faq',
				'attrs'        => array( 'builderVersion' => '5.9.0' ),
				'innerBlocks'  => array(),
				'innerContent' => array(),
			),
		),
	),
);
$mixed_counters = array();
$mixed_args     = array( $mixed_tree_blocks, 0, &$mixed_counters, false );
$mixed_tree     = diviops_call_ref( 'parse_block_tree', $mixed_args );

assert_same( 'section:1', $mixed_tree[0]['auto_index'], 'the wrapper still resolves to section:1 alongside a third-party descendant' );
assert_same( 'section:2', $mixed_tree[1]['auto_index'], 'the real section is still section:2' );
assert_same(
	'difl/faq:1',
	$mixed_tree[1]['inner_blocks'][0]['auto_index'],
	"the nested third-party module keeps its own namespaced counter, unperturbed by the wrapper"
);
assert_same(
	array(
		'section'   => 2,
		'difl/faq'  => 1,
	),
	$mixed_counters,
	'global-layout resolution does not perturb the third-party counter'
);

// ── walk_and_mutate(): module_lock / unlock / clone ──────────────────────

$walk_blocks = array(
	array(
		'blockName'    => 'divi/placeholder',
		'attrs'        => array( 'builderVersion' => '5.9.0' ),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/global-layout',
		'attrs'        => array(
			'globalModule'   => '900296',
			'blockName'      => 'divi/section',
			'builderVersion' => '5.9.0',
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array(
			'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Second Section' ) ) ) ),
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array(
			'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Third Section' ) ) ) ),
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
);

$captured = null;
$mutator  = static function ( array &$siblings, $index, array &$block, &$parent_block ) use ( &$captured ) {
	$captured = $block;
};

$walk_counters = array();
$walk_matches  = 0;
$no_parent     = null;
$blocks_copy   = $walk_blocks;
$args          = array( &$blocks_copy, 'auto_index', 'section:1', 1, &$walk_counters, &$walk_matches, $mutator, &$no_parent );
assert_true( (bool) diviops_call_ref( 'walk_and_mutate', $args ), 'walk_and_mutate reaches section:1' );
assert_same( 'divi/global-layout', $captured['blockName'] ?? null, 'section:1 mutates the wrapper block, matching the read path' );
assert_same( '900296', $captured['attrs']['globalModule'] ?? null, 'the mutated block is really the wrapper — only it carries globalModule' );

$captured       = null;
$walk_counters2 = array();
$walk_matches2  = 0;
$no_parent2     = null;
$blocks_copy2   = $walk_blocks;
$args2          = array( &$blocks_copy2, 'auto_index', 'section:2', 1, &$walk_counters2, &$walk_matches2, $mutator, &$no_parent2 );
assert_true( (bool) diviops_call_ref( 'walk_and_mutate', $args2 ), 'walk_and_mutate reaches section:2' );
assert_same( 'divi/section', $captured['blockName'] ?? null, 'section:2 mutates a real section, not the wrapper' );
assert_same(
	'Second Section',
	$captured['attrs']['module']['meta']['adminLabel']['desktop']['value'] ?? null,
	'section:2 is the first real section, shifted one position by the wrapper'
);

$captured       = null;
$walk_counters3 = array();
$walk_matches3  = 0;
$no_parent3     = null;
$blocks_copy3   = $walk_blocks;
$args3          = array( &$blocks_copy3, 'auto_index', 'section:3', 1, &$walk_counters3, &$walk_matches3, $mutator, &$no_parent3 );
assert_true( (bool) diviops_call_ref( 'walk_and_mutate', $args3 ), 'walk_and_mutate reaches section:3' );
assert_same(
	'Third Section',
	$captured['attrs']['module']['meta']['adminLabel']['desktop']['value'] ?? null,
	'section:3 is the second real section'
);

// Negative: a wrapper with no blockName counts under its own literal type,
// and does not shift the section that follows it.
$walk_blocks_unresolved = array(
	array(
		'blockName'    => 'divi/global-layout',
		'attrs'        => array( 'globalModule' => '900297' ),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array(
			'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Only Section' ) ) ) ),
		),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
);

$captured        = null;
$walk_counters4  = array();
$walk_matches4   = 0;
$no_parent4      = null;
$blocks_copy4    = $walk_blocks_unresolved;
$args4           = array( &$blocks_copy4, 'auto_index', 'global-layout:1', 1, &$walk_counters4, &$walk_matches4, $mutator, &$no_parent4 );
assert_true(
	(bool) diviops_call_ref( 'walk_and_mutate', $args4 ),
	'a global-layout wrapper with no blockName attr still counts as global-layout:1 for walk_and_mutate'
);
assert_same( 'divi/global-layout', $captured['blockName'] ?? null, 'global-layout:1 mutates the unresolved wrapper' );

$captured       = null;
$walk_counters5 = array();
$walk_matches5  = 0;
$no_parent5     = null;
$blocks_copy5   = $walk_blocks_unresolved;
$args5          = array( &$blocks_copy5, 'auto_index', 'section:1', 1, &$walk_counters5, &$walk_matches5, $mutator, &$no_parent5 );
assert_true( (bool) diviops_call_ref( 'walk_and_mutate', $args5 ), 'section:1 still resolves when the wrapper has no blockName' );
assert_same(
	'Only Section',
	$captured['attrs']['module']['meta']['adminLabel']['desktop']['value'] ?? null,
	'without a resolvable blockName, section:1 is still the only real section — no shift, no error'
);

// ── Third-party modules AND a global-layout wrapper on the same page ─────
//
// The wrapper resolution must not perturb an unrelated namespaced counter,
// and a third-party module after the wrapper must still resolve by its own
// identifier.

$combined = implode(
	'',
	array(
		$wrapper,
		'<!-- wp:divi/section {"builderVersion":"5.9.0"} -->',
		'<!-- wp:divi/row {"builderVersion":"5.9.0"} -->',
		'<!-- wp:divi/column {"builderVersion":"5.9.0"} -->',
		'<!-- wp:difl/faq {"module":{"meta":{"adminLabel":{"desktop":{"value":"Combined FAQ"}}}},"builderVersion":"5.9.0"} -->',
		'<!-- wp:difl/faqitem {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:difl/faq -->',
		'<!-- /wp:divi/column -->',
		'<!-- /wp:divi/row -->',
		'<!-- /wp:divi/section -->',
	)
);

$faq = diviops_call( 'find_block', array( $combined, '', '', 'difl/faq:1' ) );
assert_true( ! is_wp_error( $faq ), 'a third-party module after a global-layout wrapper still resolves by its own namespaced counter' );
if ( ! is_wp_error( $faq ) ) {
	assert_same( 'difl/faq', $faq['type'], 'the third-party type is untouched by global-layout resolution' );
}

$wrapped_section = diviops_call( 'find_block', array( $combined, '', '', 'section:1' ) );
assert_true( ! is_wp_error( $wrapped_section ), 'section:1 still resolves to the wrapper alongside third-party content' );
if ( ! is_wp_error( $wrapped_section ) ) {
	assert_same( 'section', $wrapped_section['type'], 'section:1 reports the resolved type, not global-layout, alongside third-party content' );
	$markup = substr( $combined, $wrapped_section['start'], $wrapped_section['end'] - $wrapped_section['start'] );
	assert_true(
		0 === strpos( $markup, '<!-- wp:divi/global-layout ' ),
		"section:1 resolves to the wrapper's own span, not the real section that follows it"
	);
	assert_true(
		substr( $markup, -strlen( '<!-- /wp:divi/global-layout -->' ) ) === '<!-- /wp:divi/global-layout -->',
		'the wrapper span still ends at its own literal closer, not a divi/section closer'
	);
}

$real_section_with_faq = diviops_call( 'find_block', array( $combined, '', '', 'section:2' ) );
assert_true( ! is_wp_error( $real_section_with_faq ), 'section:2 resolves to the real section containing the third-party module' );
if ( ! is_wp_error( $real_section_with_faq ) ) {
	$markup = substr( $combined, $real_section_with_faq['start'], $real_section_with_faq['end'] - $real_section_with_faq['start'] );
	assert_true( false !== strpos( $markup, 'difl/faq' ), 'section:2 still encloses its third-party descendant' );
}

// ── module_update(): the REST write path's inline scanner ────────────────

diviops_test_register_post( 9013, $page );

$request_wrapper  = new DiviOps_Test_Request(
	array(
		'id'         => 9013,
		'auto_index' => 'section:1',
		'attrs'      => array( 'globalModule' => 'CHANGED' ),
		'dry_run'    => true,
	)
);
$response_wrapper = diviops_call( 'module_update', array( $request_wrapper ) );
$data_wrapper     = $response_wrapper->get_data();
assert_true( ! empty( $data_wrapper['ok'] ), 'module_update dry-run resolves section:1 without error' );
assert_same(
	'900296',
	$data_wrapper['data']['plan']['changes'][0]['before'] ?? null,
	'section:1 in module_update targets the wrapper itself — only the wrapper carries globalModule'
);

$request_real  = new DiviOps_Test_Request(
	array(
		'id'         => 9013,
		'auto_index' => 'section:2',
		'attrs'      => array( 'module.meta.adminLabel.desktop.value' => 'Renamed' ),
		'dry_run'    => true,
	)
);
$response_real = diviops_call( 'module_update', array( $request_real ) );
$data_real     = $response_real->get_data();
assert_true( ! empty( $data_real['ok'] ), 'module_update dry-run resolves section:2 without error' );
assert_same(
	'Second Section',
	$data_real['data']['plan']['changes'][0]['before'] ?? null,
	'section:2 in module_update targets the first real section, shifted by the wrapper'
);

diviops_test_register_post( 9014, $page_unresolved );

$request_unresolved  = new DiviOps_Test_Request(
	array(
		'id'         => 9014,
		'auto_index' => 'section:1',
		'attrs'      => array( 'module.meta.adminLabel.desktop.value' => 'Renamed' ),
		'dry_run'    => true,
	)
);
$response_unresolved = diviops_call( 'module_update', array( $request_unresolved ) );
$data_unresolved      = $response_unresolved->get_data();
assert_true( ! empty( $data_unresolved['ok'] ), 'module_update dry-run resolves section:1 without error when the wrapper has no blockName' );
assert_same(
	'Second Section',
	$data_unresolved['data']['plan']['changes'][0]['before'] ?? null,
	'without a resolvable blockName, module_update section:1 still targets the only real section'
);
