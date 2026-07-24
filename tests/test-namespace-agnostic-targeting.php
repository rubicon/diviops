<?php
/**
 * Third-party Divi 5 modules must be addressable by every targeting operation.
 *
 * Upstream hardcodes the `divi/` block namespace in every write and targeting
 * path while `page_get_layout` reads namespace-agnostically, so the plugin hands
 * out a target identifier (`difl/faq:1`) that it then refuses to accept. These
 * tests pin both halves of that contract: the identifier the read path emits is
 * exactly the identifier every other operation resolves.
 *
 * Fixture shapes are taken from real markup on the reference page: `difl/faq` is
 * a container with a closer, `difl/faqitem`, `difl/counter` and `d5bgo/bg-overlay`
 * are self-closing, and third-party blocks sit nested inside a `divi/section`.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * A page carrying both Divi and third-party modules.
 */
$mixed = implode(
	'',
	array(
		'<!-- wp:divi/section {"builderVersion":"5.9.0"} -->',
		'<!-- wp:divi/row {"builderVersion":"5.9.0"} -->',
		'<!-- wp:divi/column {"builderVersion":"5.9.0"} -->',
		'<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->',
		'<!-- wp:difl/faq {"module":{"meta":{"adminLabel":{"desktop":{"value":"Eyeglass FAQ"}}}},"builderVersion":"5.9.0"} -->',
		'<!-- wp:difl/faqitem {"builderVersion":"5.9.0"} /-->',
		'<!-- wp:difl/faqitem {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:difl/faq -->',
		'<!-- wp:d5bgo/bg-overlay {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:divi/column -->',
		'<!-- /wp:divi/row -->',
		'<!-- /wp:divi/section -->',
	)
);

// ── The identifier contract: read path and write path agree ──────────

assert_same(
	'text',
	diviops_call( 'block_identifier_from_name', array( 'divi/text' ) ),
	'a divi block identifier drops the divi/ prefix'
);
assert_same(
	'difl/faq',
	diviops_call( 'block_identifier_from_name', array( 'difl/faq' ) ),
	'a third-party block identifier keeps its namespace'
);
assert_same(
	'divi/text',
	diviops_call( 'block_name_from_identifier', array( 'text' ) ),
	'a bare identifier resolves back to the divi namespace'
);
assert_same(
	'difl/faq',
	diviops_call( 'block_name_from_identifier', array( 'difl/faq' ) ),
	'a namespaced identifier resolves back to itself'
);

// parse_block_tree is the read path that emits the identifiers above. Pin that
// it still produces them, so a change to the write path can never silently
// diverge from what callers were handed.
$tree_blocks = array(
	array(
		'blockName'    => 'divi/text',
		'attrs'        => array( 'builderVersion' => '5.9.0' ),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
	array(
		'blockName'    => 'difl/faq',
		'attrs'        => array( 'builderVersion' => '5.9.0' ),
		'innerBlocks'  => array(),
		'innerContent' => array(),
	),
);
// &$tree_counters: parse_block_tree accumulates counters by reference, and
// invokeArgs only binds when the array element is itself a reference.
$tree_counters = array();
$tree_args     = array( $tree_blocks, 0, &$tree_counters, false );
$tree          = diviops_call_ref( 'parse_block_tree', $tree_args );

assert_same( 'text:1', $tree[0]['auto_index'], 'read path emits a bare identifier for a divi module' );
assert_same( 'difl/faq:1', $tree[1]['auto_index'], 'read path emits a namespaced identifier for a third-party module' );
assert_same(
	array(
		'text'     => 1,
		'difl/faq' => 1,
	),
	$tree_counters,
	'the read path counts third-party modules under their namespaced identifier'
);

// ── find_block: module_get and module_move ───────────────────────────

$faq = diviops_call( 'find_block', array( $mixed, '', '', 'difl/faq:1' ) );
assert_true( ! is_wp_error( $faq ), 'find_block resolves a third-party container by auto_index' );
if ( ! is_wp_error( $faq ) ) {
	assert_same( 'difl/faq', $faq['type'], 'the resolved third-party block reports its full namespaced type' );
	$markup = substr( $mixed, $faq['start'], $faq['end'] - $faq['start'] );
	assert_true(
		0 === strpos( $markup, '<!-- wp:difl/faq ' ),
		'the resolved third-party block span starts at its own opening comment'
	);
	assert_true(
		'<!-- /wp:difl/faq -->' === substr( $markup, -21 ),
		'the resolved third-party block span ends at its own closing comment, not the next divi closer'
	);
	assert_true(
		false !== strpos( $markup, '<!-- wp:difl/faqitem ' ),
		'the resolved third-party container span encloses its own children'
	);
}

// A self-closing third-party module, and the Nth occurrence of one.
$item = diviops_call( 'find_block', array( $mixed, '', '', 'difl/faqitem:2' ) );
assert_true( ! is_wp_error( $item ), 'find_block resolves the Nth occurrence of a self-closing third-party module' );
if ( ! is_wp_error( $item ) ) {
	assert_same( 'difl/faqitem', $item['type'], 'the Nth third-party occurrence reports its namespaced type' );
	assert_same(
		'difl/faqitem:2',
		$item['auto_index'],
		'the Nth third-party occurrence echoes back the identifier it was addressed by'
	);
	$item_markup = substr( $mixed, $item['start'], $item['end'] - $item['start'] );
	assert_true(
		'/-->' === substr( $item_markup, -4 ),
		'a self-closing third-party module span stops at its own self-closing marker'
	);
}

$overlay = diviops_call( 'find_block', array( $mixed, '', '', 'd5bgo/bg-overlay:1' ) );
assert_true( ! is_wp_error( $overlay ), 'find_block resolves a second third-party namespace' );

// Third-party blocks are addressable by admin label too.
$by_label = diviops_call( 'find_block', array( $mixed, 'Eyeglass FAQ', '', '' ) );
assert_true( ! is_wp_error( $by_label ), 'find_block resolves a third-party module by admin label' );
if ( ! is_wp_error( $by_label ) ) {
	assert_same( 'difl/faq', $by_label['type'], 'label targeting resolves to the third-party block, not its divi ancestor' );
}

// Divi-namespace behavior is unchanged: bare identifiers still work, and the
// presence of third-party blocks does not perturb divi counters.
$text = diviops_call( 'find_block', array( $mixed, '', '', 'text:1' ) );
assert_true( ! is_wp_error( $text ), 'a bare divi identifier still resolves' );
if ( ! is_wp_error( $text ) ) {
	assert_same( 'text', $text['type'], 'a divi module still reports its bare type' );
}

$section = diviops_call( 'find_block', array( $mixed, '', '', 'section:1' ) );
assert_true( ! is_wp_error( $section ), 'a divi container still resolves alongside third-party siblings' );
if ( ! is_wp_error( $section ) ) {
	$section_markup = substr( $mixed, $section['start'], $section['end'] - $section['start'] );
	assert_same(
		$mixed,
		$section_markup,
		'the divi section span still encloses the whole document including third-party children'
	);
}

// An identifier that does not exist must still miss rather than match loosely.
$missing = diviops_call( 'find_block', array( $mixed, '', '', 'difl/nosuch:1' ) );
assert_true( is_wp_error( $missing ), 'an unknown third-party identifier still reports no match' );

// ── find_all_sections: the section operations ────────────────────────

$sections = diviops_call( 'find_all_sections', array( $mixed, '', 'Eyeglass FAQ' ) );
assert_same( 1, count( $sections ), 'a divi section containing third-party modules is still found by text' );

$third_party_section = implode(
	'',
	array(
		'<!-- wp:divi/section {"builderVersion":"5.9.0"} -->',
		'<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:divi/section -->',
		'<!-- wp:d5bgo/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Overlay Section"}}}}} -->',
		'<!-- wp:difl/counter {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:d5bgo/section -->',
	)
);

$all_sections = diviops_call( 'find_all_sections', array( $third_party_section, '', 'counter' ) );
assert_same( 1, count( $all_sections ), 'a third-party section is addressable by the section operations' );
if ( 1 === count( $all_sections ) ) {
	$found = substr(
		$third_party_section,
		$all_sections[0]['start'],
		$all_sections[0]['end'] - $all_sections[0]['start']
	);
	assert_true(
		0 === strpos( $found, '<!-- wp:d5bgo/section ' ),
		'the third-party section span starts at its own opening comment'
	);
	assert_true(
		'<!-- /wp:d5bgo/section -->' === substr( $found, -26 ),
		'the third-party section span ends at its own closer, not a divi section closer'
	);
}

$divi_only = diviops_call( 'find_all_sections', array( $third_party_section, 'Overlay Section', '' ) );
assert_same( 1, count( $divi_only ), 'a third-party section is addressable by admin label' );

// ── Write safety: the marker guard must SEE third-party markers ──────

$counts = diviops_call( 'divi_content_marker_counts', array( $mixed ) );
assert_same(
	$counts['container_openers'],
	$counts['closers'],
	'balanced mixed-namespace content reports balanced containers'
);
// 8 openers: 4 divi (section, row, column, text) + 4 third-party (faq, two
// faqitem, bg-overlay). Counting only the divi ones is what made a third-party
// imbalance invisible to the write guard.
assert_same( 8, $counts['openers'], 'the marker census counts third-party openers rather than skipping them' );
assert_same( 4, $counts['self_closers'], 'the marker census counts third-party self-closing modules' );
assert_same( 4, $counts['closers'], 'the marker census counts third-party closers' );

// The whole point of the guard: an unbalanced THIRD-PARTY container must be
// visible to it. Upstream counts only divi/ markers, so this imbalance is
// invisible and unsafe content passes the single write-safety gate.
$unbalanced = implode(
	'',
	array(
		'<!-- wp:divi/section {"builderVersion":"5.9.0"} -->',
		'<!-- wp:difl/faq {"builderVersion":"5.9.0"} -->',
		'<!-- wp:difl/faqitem {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:divi/section -->',
	)
);
$bad_counts = diviops_call( 'divi_content_marker_counts', array( $unbalanced ) );
assert_true(
	$bad_counts['container_openers'] !== $bad_counts['closers'],
	'an unclosed third-party container is visible to the marker census'
);

$sequence = diviops_call( 'validate_divi_marker_sequence', array( $mixed ) );
assert_true( ! empty( $sequence['ok'] ), 'balanced mixed-namespace content passes sequence validation' );

$mismatched = implode(
	'',
	array(
		'<!-- wp:divi/section {"builderVersion":"5.9.0"} -->',
		'<!-- wp:difl/faq {"builderVersion":"5.9.0"} -->',
		'<!-- /wp:divi/section -->',
		'<!-- /wp:difl/faq -->',
	)
);
$bad_sequence = diviops_call( 'validate_divi_marker_sequence', array( $mismatched ) );
assert_true( empty( $bad_sequence['ok'] ), 'a mis-nested third-party closer is rejected by sequence validation' );
assert_same(
	'mismatched_closer',
	$bad_sequence['reason'],
	'the mis-nested third-party closer is reported as a mismatch, not silently accepted'
);

// ── Attribute normalization before a write ───────────────────────────

$normalized = diviops_call( 'normalize_divi_full_content_for_write', array( $mixed ) );
assert_true( ! empty( $normalized['ok'] ), 'mixed-namespace content normalizes for write' );

// Upstream returns early for any non-divi block, so malformed third-party attr
// JSON is written through unchecked. It must be caught like a divi block's.
$bad_attrs = '<!-- wp:difl/faq {"module":u003c} -->' . '<!-- /wp:difl/faq -->';
$rejected  = diviops_call( 'normalize_divi_full_content_for_write', array( $bad_attrs ) );
assert_true(
	empty( $rejected['ok'] ),
	'malformed third-party block attributes are rejected rather than written through'
);

// Normalization rewrites what it touches, so it must stop at the Divi module
// boundary. An unrelated plugin's block on the same page is neither rewritten
// nor allowed to fail the write: its attribute values follow that plugin's
// rules, not Divi's escaping rules.
$foreign = '<!-- wp:tec/event {"trackingId":"u003c-random-id"} /-->';
$left_alone = diviops_call( 'normalize_divi_full_content_for_write', array( $foreign ) );
assert_true(
	! empty( $left_alone['ok'] ),
	'an unrelated plugin block does not fail the write guard on Divi escaping rules'
);
assert_same( $foreign, $left_alone['content'], 'an unrelated plugin block is returned byte-for-byte' );

$foreign_escapes = '<!-- wp:gravityforms/form {"cssClass":"foo\/bar"} /-->';
$untouched       = diviops_call( 'normalize_divi_full_content_for_write', array( $foreign_escapes ) );
assert_same( 0, $untouched['changed'], 'an unrelated plugin block is not re-serialized' );

// ── Depth pairing: a same-name self-closing block has no closer ──────

$self_closing_siblings = implode(
	'',
	array(
		'<!-- wp:d5bgo/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Outer"}}}}} -->',
		'<!-- wp:d5bgo/section {"id":"inner-a"} /-->',
		'<!-- wp:d5bgo/section {"id":"inner-b"} /-->',
		'<!-- /wp:d5bgo/section -->',
	)
);

$paired = diviops_call( 'find_all_sections', array( $self_closing_siblings, 'Outer', '' ) );
assert_same(
	1,
	count( $paired ),
	'a self-closing block of the same name does not consume its container closer'
);
if ( 1 === count( $paired ) ) {
	assert_same( 0, $paired[0]['start'], 'the container section still starts at its own opener' );
	assert_same(
		strlen( $self_closing_siblings ),
		$paired[0]['end'],
		'the container section still ends at its own closer'
	);
}

$paired_block = diviops_call( 'find_block', array( $self_closing_siblings, '', '', 'd5bgo/section:1' ) );
assert_true(
	! is_wp_error( $paired_block ),
	'find_block resolves a container holding same-name self-closing children'
);

// ── Theme Builder parent selector ────────────────────────────────────

$parent = diviops_call( 'parse_tb_parent_selector', array( 'difl/faq' ) );
assert_true( ! is_wp_error( $parent ), 'a third-party parent_selector parses' );
if ( ! is_wp_error( $parent ) ) {
	assert_same( 'difl/faq', $parent['block_name'], 'the third-party parent selector keeps its namespace' );
}

$parent_labeled = diviops_call( 'parse_tb_parent_selector', array( 'difl/faq[adminLabel="Eyeglass FAQ"]' ) );
assert_true( ! is_wp_error( $parent_labeled ), 'a third-party parent_selector with an admin label parses' );
if ( ! is_wp_error( $parent_labeled ) ) {
	assert_same( 'Eyeglass FAQ', $parent_labeled['admin_label'], 'the admin label survives third-party selector parsing' );
}

$parent_divi = diviops_call( 'parse_tb_parent_selector', array( 'divi/group[adminLabel="Legal Col"]' ) );
assert_true( ! is_wp_error( $parent_divi ), 'the documented divi parent_selector form still parses' );
if ( ! is_wp_error( $parent_divi ) ) {
	assert_same( 'divi/group', $parent_divi['block_name'], 'divi parent selectors are unchanged' );
	assert_same( 'Legal Col', $parent_divi['admin_label'], 'divi parent selector labels are unchanged' );
}

$parent_bad = diviops_call( 'parse_tb_parent_selector', array( 'not-a-selector' ) );
assert_true( is_wp_error( $parent_bad ), 'a selector without a namespace is still rejected' );

// ── walk_and_mutate: module_clone, module_lock and module_unlock ─────

$walk_blocks = array(
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array( 'builderVersion' => '5.9.0' ),
		'innerContent' => array( null ),
		'innerBlocks'  => array(
			array(
				'blockName'    => 'divi/text',
				'attrs'        => array( 'builderVersion' => '5.9.0' ),
				'innerBlocks'  => array(),
				'innerContent' => array(),
			),
			array(
				'blockName'    => 'difl/faq',
				'attrs'        => array( 'builderVersion' => '5.9.0' ),
				'innerBlocks'  => array(),
				'innerContent' => array(),
			),
		),
	),
);

$walk_counters = array();
$walk_matches  = 0;
$mutated_name  = '';
$mutator       = static function ( array &$siblings, $index, array &$block, &$parent_block ) use ( &$mutated_name ) {
	$mutated_name = $block['blockName'];
};
$no_parent     = null;
$walk_args     = array( &$walk_blocks, 'auto_index', 'difl/faq:1', 1, &$walk_counters, &$walk_matches, $mutator, &$no_parent );

assert_true(
	(bool) diviops_call_ref( 'walk_and_mutate', $walk_args ),
	'walk_and_mutate reaches a third-party module by its namespaced identifier'
);
assert_same( 'difl/faq', $mutated_name, 'the mutator runs on the third-party block, not a divi sibling' );

// The same walk must still reach divi modules by their bare identifier.
$walk_counters2 = array();
$walk_matches2  = 0;
$mutated_name   = '';
$no_parent2     = null;
$walk_args2     = array( &$walk_blocks, 'auto_index', 'text:1', 1, &$walk_counters2, &$walk_matches2, $mutator, &$no_parent2 );

assert_true(
	(bool) diviops_call_ref( 'walk_and_mutate', $walk_args2 ),
	'walk_and_mutate still reaches a divi module by its bare identifier'
);
assert_same( 'divi/text', $mutated_name, 'bare identifier targeting is unchanged for divi modules' );

// ── Regression gate: no namespace re-prefixing on a targeting identifier ──
//
// A targeting identifier is already fully qualified for a third-party module,
// so concatenating a literal `divi/` onto one produces `divi/difl/faq`. In
// module_update that string is written back into post_content, which corrupts
// the block. Catch the whole class by source inspection, since these sites sit
// inside handlers that need a live WordPress to call.

$targeting_sources = array(
	'trait-page.php'          => dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-page.php',
	'trait-core.php'          => dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-core.php',
	'trait-theme-builder.php' => dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-theme-builder.php',
	'trait-module-schema.php' => dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-module-schema.php',
);

$inspected = 0;
foreach ( $targeting_sources as $label => $path ) {
	assert_true( is_file( $path ), "targeting source {$label} is where the gate expects it" );
	$source = (string) file_get_contents( $path );
	$inspected++;

	// `'divi/' . $var` or `'<!-- wp:divi/' . $var` — a literal namespace glued
	// onto a runtime value. Matching a quoted string ending in `divi/` followed
	// by a concatenation keeps this from firing on whole literal block names
	// such as '<!-- wp:divi/placeholder -->'.
	assert_same(
		0,
		preg_match_all( '/[\'"][^\'"]*divi\/[\'"]\s*\.\s*\$/', $source ),
		"{$label} never concatenates a literal divi/ namespace onto a runtime block identifier"
	);
}

assert_same( 4, $inspected, 'the namespace-concatenation gate inspected every targeting source' );
