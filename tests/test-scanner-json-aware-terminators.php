<?php
// SPDX-License-Identifier: MIT
/**
 * Every remaining `strpos($content, '-->', $pos)` comment-terminator site must
 * be as string/escape-aware as block_opening_comment_end() (added for
 * find_block()'s own opening-comment scan). A `content` attribute can legally
 * hold example markup whose text contains `-->`, or even a literal closing
 * comment for the block's ancestor, so a raw strpos for the terminator can
 * match that inner sequence instead of the real one.
 *
 * This file covers the sites find_block()'s own fix left standing:
 *   - find_block()'s container depth-scan (the ancestor/descendant case,
 *     the highest-exposure site because it sits in the dominant
 *     section > row > column > module nesting shape)
 *   - the same depth-scan's sibling-denial failure mode (an unbalanced fake
 *     opener in a descendant must not deny a later, unrelated sibling)
 *   - module_update()'s own copy of the pre-fix arithmetic
 *   - extract_attrs_from_block_markup(), reached by module_get()
 *   - block_opener_is_self_closing(), which the depth-scans call to classify
 *     a nested opener
 *   - find_all_sections()'s own opening-comment scan and depth-scan
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Return the markup a start/end pair spans.
 *
 * @param string $content Full page markup.
 * @param int    $start   Start offset.
 * @param int    $end     End offset.
 * @return string
 */
function diviops_slice( string $content, int $start, int $end ): string {
	return substr( $content, $start, $end - $start );
}

// ── Site 1: find_block()'s container depth-scan ──────────────────────
//
// A divi/section whose CHILD module's attribute JSON contains the literal
// text of the section's own closing comment. The child is self-closing, so
// find_block()'s own opening-comment scan (already fixed) resolves the child
// fine; the bug is the section's depth-scan, which independently re-scans
// the same bytes for its own closer with a raw strpos.

$child_with_ancestor_poison =
	'<!-- wp:divi/text {"content":{"desktop":{"value":"See: <!-- /wp:divi/section -->"}}} /-->';

$section_with_poisoned_child =
	'<!-- wp:divi/section {"builderVersion":"5.9.0"} -->'
	. $child_with_ancestor_poison
	. 'REAL BODY AFTER THE POISON.'
	. '<!-- /wp:divi/section -->';

$container = diviops_call( 'find_block', array( $section_with_poisoned_child, '', '', 'section:1' ) );
assert_true(
	! is_wp_error( $container ),
	'find_block resolves a container whose descendant attr JSON contains the container\'s own closing comment'
);
if ( ! is_wp_error( $container ) ) {
	assert_same(
		$section_with_poisoned_child,
		diviops_slice( $section_with_poisoned_child, $container['start'], $container['end'] ),
		'the container span reaches its own real closer, not the closer text embedded in a descendant'
	);
	assert_true(
		false !== strpos(
			diviops_slice( $section_with_poisoned_child, $container['start'], $container['end'] ),
			'REAL BODY AFTER THE POISON.'
		),
		'the container span includes the body that follows the poisoned descendant'
	);
}

// ── Site 1: sibling-denial ────────────────────────────────────────────
//
// A descendant carrying an UNBALANCED fake same-name opener (no real closer
// for it) must not corrupt the depth count for the rest of the document: it
// must not manufacture a spurious parse_error, and a later, unrelated,
// well-formed sibling must still resolve.

$child_with_fake_opener =
	'<!-- wp:divi/text {"content":{"desktop":{"value":"Nested: <!-- wp:divi/section fake -->"}}} /-->';

$poisoned_section =
	'<!-- wp:divi/section {"builderVersion":"5.9.0"} -->'
	. $child_with_fake_opener
	. 'Body of section one.'
	. '<!-- /wp:divi/section -->';

$clean_sibling_section =
	'<!-- wp:divi/section {"content":{"desktop":{"value":"Section two"}}} -->'
	. '<!-- wp:divi/text {"content":{"desktop":{"value":"Section two body"}}} /-->'
	. '<!-- /wp:divi/section -->';

$two_sections = $poisoned_section . $clean_sibling_section;

$first = diviops_call( 'find_block', array( $two_sections, '', '', 'section:1' ) );
assert_true(
	! is_wp_error( $first ),
	'an unbalanced fake same-name opener embedded in a descendant does not produce a spurious parse_error'
);
if ( ! is_wp_error( $first ) ) {
	assert_same(
		$poisoned_section,
		diviops_slice( $two_sections, $first['start'], $first['end'] ),
		'the first section still spans exactly its own markup despite the embedded fake opener'
	);
}

$second = diviops_call( 'find_block', array( $two_sections, '', '', 'section:2' ) );
assert_true(
	! is_wp_error( $second ),
	'a later, unrelated, well-formed sibling still resolves despite an earlier fake opener'
);
if ( ! is_wp_error( $second ) ) {
	assert_same(
		$clean_sibling_section,
		diviops_slice( $two_sections, $second['start'], $second['end'] ),
		'the sibling span is exactly the sibling, unaffected by the poison in the section before it'
	);
}

// ── Site 4: block_opener_is_self_closing() ────────────────────────────
//
// The nearest raw `-->` from $pos sits inside a quoted string and happens to
// be preceded by `/`, so a naive check misreads a container as self-closing.
// The real terminator, reached only by skipping the quoted string, is a
// plain `-->`.

$looks_self_closing_but_isnt =
	'<!-- wp:divi/text {"content":{"desktop":{"value":"fake self-close: X/-->"}}} -->'
	. 'Body.'
	. '<!-- /wp:divi/text -->';

assert_true(
	false === diviops_call( 'block_opener_is_self_closing', array( $looks_self_closing_but_isnt, 0 ) ),
	'block_opener_is_self_closing is not fooled by a `/-->` sequence inside a quoted attribute value'
);

$genuinely_self_closing =
	'<!-- wp:divi/text {"content":{"desktop":{"value":"harmless"}}} /-->';

assert_true(
	true === diviops_call( 'block_opener_is_self_closing', array( $genuinely_self_closing, 0 ) ),
	'block_opener_is_self_closing still recognizes a genuinely self-closing block'
);

// ── Site 3: extract_attrs_from_block_markup(), reached by module_get() ──

$poison = 'Example: <!-- wp:divi/text {} --><!-- /wp:divi/text -->';
$markup_with_own_poison =
	'<!-- wp:divi/text {"content":{"desktop":{"value":"' . $poison . '"}}} -->'
	. 'Body text.'
	. '<!-- /wp:divi/text -->';

$attrs = diviops_call( 'extract_attrs_from_block_markup', array( $markup_with_own_poison ) );
assert_true(
	is_array( $attrs ),
	'extract_attrs_from_block_markup parses a block whose own attribute JSON contains a comment terminator'
);
if ( is_array( $attrs ) ) {
	assert_same(
		$poison,
		$attrs['content']['desktop']['value'] ?? null,
		'the decoded attribute value is byte-faithful, not truncated at the embedded terminator'
	);
}

// ── Site 5: find_all_sections()'s own opening-comment scan ────────────
//
// A section whose OWN attribute JSON (not a descendant's) contains a
// comment terminator, with the section's real adminLabel placed AFTER the
// poison in the JSON. find_all_sections() has its own independent copy of
// the opening-comment scan that PR #7 only fixed inside find_block(): its
// label short-circuit reads the truncated comment, which ends before the
// real adminLabel is ever reached, so the section is skipped even though it
// matches.

$section_with_own_poison =
	'<!-- wp:divi/section {"content":{"desktop":{"value":"' . $poison . '"}},"module":{"meta":{"adminLabel":{"desktop":{"value":"Section With Poison"}}}}} -->'
	. '<!-- wp:divi/text {"content":{"desktop":{"value":"Body"}}} /-->'
	. '<!-- /wp:divi/section -->';

$by_label = diviops_call( 'find_all_sections', array( $section_with_own_poison, 'Section With Poison', '' ) );
assert_same(
	1,
	count( $by_label ),
	'find_all_sections resolves by label a section whose own attribute JSON contains a comment terminator before the label'
);
if ( 1 === count( $by_label ) ) {
	assert_same(
		$section_with_own_poison,
		diviops_slice( $section_with_own_poison, $by_label[0]['start'], $by_label[0]['end'] ),
		'the section span covers the whole section, not a fragment ending inside its own JSON'
	);
}

// ── Site 5: find_all_sections()'s depth-scan ───────────────────────────
//
// The same ancestor/descendant poison as site 1, this time for the section
// operations' own depth-scan.

$section_list_with_poisoned_child =
	'<!-- wp:divi/section {"content":{"desktop":{"value":"Section A"}}} -->'
	. $child_with_ancestor_poison
	. 'REAL SECTION A BODY.'
	. '<!-- /wp:divi/section -->'
	. '<!-- wp:divi/section {"content":{"desktop":{"value":"Section B"}}} -->'
	. '<!-- wp:divi/text {"content":{"desktop":{"value":"Section B body"}}} /-->'
	. '<!-- /wp:divi/section -->';

$section_a_end = strpos( $section_list_with_poisoned_child, '<!-- wp:divi/section {"content":{"desktop":{"value":"Section B"' );

$sections_a = diviops_call( 'find_all_sections', array( $section_list_with_poisoned_child, '', 'REAL SECTION A BODY' ) );
assert_same(
	1,
	count( $sections_a ),
	'find_all_sections resolves a section whose descendant attr JSON contains the section\'s own closing comment'
);
if ( 1 === count( $sections_a ) ) {
	assert_same(
		0,
		$sections_a[0]['start'],
		'section A still starts at its own opener'
	);
	assert_same(
		$section_a_end,
		$sections_a[0]['end'],
		'section A ends at its own real closer, not the closer text embedded in its descendant'
	);
}

$sections_b = diviops_call( 'find_all_sections', array( $section_list_with_poisoned_child, '', 'Section B body' ) );
assert_same(
	1,
	count( $sections_b ),
	'a later section is still found by find_all_sections despite an earlier descendant\'s poison'
);
if ( 1 === count( $sections_b ) ) {
	assert_same(
		strlen( $section_list_with_poisoned_child ),
		$sections_b[0]['end'],
		'section B still spans through to the end of the document'
	);
}

// ── Site 2: module_update()'s own scanning arithmetic ──────────────────

$module_update_page = $markup_with_own_poison;
diviops_test_register_post( 501, $module_update_page );

$update_request = new DiviOps_Test_Request(
	array(
		'id'         => 501,
		'attrs'      => array( 'content.desktop.value' => 'Updated value' ),
		'dry_run'    => true,
		'auto_index' => 'text:1',
	)
);

$update_response = diviops_call( 'module_update', array( $update_request ) );
$update_data      = $update_response->get_data();

assert_true(
	! empty( $update_data['ok'] ),
	'module_update parses a module whose own attribute JSON contains a comment terminator'
);
if ( ! empty( $update_data['ok'] ) ) {
	$before = $update_data['data']['plan']['changes'][0]['before'] ?? null;
	assert_same(
		$poison,
		$before,
		'module_update reads the real, byte-faithful before-value rather than one truncated at the embedded terminator'
	);
}
