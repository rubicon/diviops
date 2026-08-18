<?php
// SPDX-License-Identifier: MIT
/**
 * find_all_sections() must not depth-scan a self-closing section opener.
 *
 * find_block() checks whether a matched opener is self-closing before it starts
 * its nesting depth-scan, and skips the scan entirely for a self-closing opener
 * (it has no closer to look for). find_all_sections() did not mirror that check.
 *
 * The bug is not in the depth-scan itself (a same-name self-closing child
 * nested inside a real container was already excluded from raising depth, via
 * block_opener_is_self_closing()). It is that find_all_sections()'s outer loop
 * only advances $offset past whichever opener it just processed, not past that
 * opener's whole matched span. Once a container's real span has been resolved,
 * the outer loop resumes right after the container's own opening comment and
 * revisits every descendant opener again as its own top-level "section"
 * candidate. When that revisited candidate is itself self-closing, the
 * unguarded depth-scan starts counting from its comment_end anyway, finds no
 * closer of its own, and walks forward until it hits the *enclosing* section's
 * real closer (or a later section's), reporting a bogus match whose bounds
 * overlap a real one.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

// ── (a) divi/section container holding a self-closing divi/section child ──

$self_closing_child = implode(
	'',
	array(
		'<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Outer Section"}}}}} -->',
		'<!-- wp:divi/section {"builderVersion":"5.9.0"} /-->',
		'<!-- wp:divi/text {"content":{"desktop":{"value":"trailing marker"}}} /-->',
		'<!-- /wp:divi/section -->',
	)
);

// match_text mode: no label short-circuit, so the revisited self-closing child
// reaches the depth-scan exactly as described above.
$matches = diviops_call( 'find_all_sections', array( $self_closing_child, '', 'trailing marker' ) );
assert_same(
	1,
	count( $matches ),
	'a self-closing same-name section child does not produce a bogus second match'
);
if ( 1 === count( $matches ) ) {
	assert_same( 0, $matches[0]['start'], 'the container section still starts at its own opener' );
	assert_same(
		strlen( $self_closing_child ),
		$matches[0]['end'],
		'the container section still ends at its own real closer'
	);
}

// ── (b) same shape, third-party */section namespace ──────────────────────

$self_closing_child_third_party = implode(
	'',
	array(
		'<!-- wp:d5bgo/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Outer Overlay"}}}}} -->',
		'<!-- wp:d5bgo/section {"builderVersion":"5.9.0"} /-->',
		'<!-- wp:divi/text {"content":{"desktop":{"value":"overlay marker"}}} /-->',
		'<!-- /wp:d5bgo/section -->',
	)
);

$matches_third_party = diviops_call( 'find_all_sections', array( $self_closing_child_third_party, '', 'overlay marker' ) );
assert_same(
	1,
	count( $matches_third_party ),
	'a third-party namespace self-closing same-name section child does not produce a bogus second match'
);
if ( 1 === count( $matches_third_party ) ) {
	assert_same( 0, $matches_third_party[0]['start'], 'the third-party container still starts at its own opener' );
	assert_same(
		strlen( $self_closing_child_third_party ),
		$matches_third_party[0]['end'],
		'the third-party container still ends at its own real closer'
	);
}

// ── (c) regression guard: two genuinely nested (non-self-closing) sections ──
//
// Neither opener here is self-closing, so this is unaffected by the fix — it
// pins that ordinary nested containers keep depth-counting correctly rather
// than collapsing to a one-comment span.

$nested_containers = implode(
	'',
	array(
		'<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Parent Section"}}}}} -->',
		'<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Child Section"}}}}} -->',
		'<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:divi/section -->',
		'<!-- /wp:divi/section -->',
	)
);

$nested_matches = diviops_call( 'find_all_sections', array( $nested_containers, '', '5.9.0' ) );
assert_same(
	2,
	count( $nested_matches ),
	'two genuinely nested non-self-closing same-name sections are both still found'
);
if ( 2 === count( $nested_matches ) ) {
	$parent_markup = substr( $nested_containers, $nested_matches[0]['start'], $nested_matches[0]['end'] - $nested_matches[0]['start'] );
	$child_markup  = substr( $nested_containers, $nested_matches[1]['start'], $nested_matches[1]['end'] - $nested_matches[1]['start'] );

	assert_same( $nested_containers, $parent_markup, 'the parent match still spans the entire document' );
	assert_true(
		false !== strpos( $parent_markup, 'Child Section' ),
		'the parent match still encloses the whole nested child'
	);
	assert_true(
		false === strpos( $child_markup, 'Parent Section' ),
		"the child match does not reach back up into the parent's own opening comment"
	);
}

// ── (d) label mode: a self-closing child sharing the container's own label ──
//
// Label matching is decided on the opening comment alone, so a self-closing
// child carrying the same admin label as its container is not filtered out by
// the short-circuit above — it is a genuinely distinct labeled node, and this
// is what exercises the depth-scan guard under label targeting specifically.
// The count is 2 either way; what the fix changes is the second match's
// bounds, which must stay a one-comment span instead of swallowing the rest
// of the document up to the container's real closer.

$shared_label = 'Shared Label';
$label_page   = implode(
	'',
	array(
		'<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"' . $shared_label . '"}}}}} -->',
		'<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"' . $shared_label . '"}}}}} /-->',
		'<!-- wp:divi/text {"builderVersion":"5.9.0"} /-->',
		'<!-- /wp:divi/section -->',
	)
);

$label_matches = diviops_call( 'find_all_sections', array( $label_page, $shared_label, '' ) );
assert_same(
	2,
	count( $label_matches ),
	'a self-closing section sharing the container label is still counted as its own distinct match'
);
if ( 2 === count( $label_matches ) ) {
	$self_closing_match_markup = substr(
		$label_page,
		$label_matches[1]['start'],
		$label_matches[1]['end'] - $label_matches[1]['start']
	);
	assert_true(
		'/-->' === substr( $self_closing_match_markup, -4 ),
		"the self-closing match in label mode still ends at its own self-closing marker, not the container's real closer"
	);
	assert_same(
		strpos( $label_page, '<!-- wp:divi/text' ),
		$label_matches[1]['end'],
		'the self-closing match in label mode does not consume any of the trailing markup'
	);
}
