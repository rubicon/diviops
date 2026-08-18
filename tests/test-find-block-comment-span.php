<?php
// SPDX-License-Identifier: MIT
/**
 * find_block() must span a module correctly when its own attribute JSON carries
 * a `-->` sequence inside a string value.
 *
 * The opening comment of a Divi block is `<!-- wp:NAME {JSON} -->`. A `content`
 * attribute can legally hold example markup whose text contains `-->`, so
 * locating the comment terminator with a JSON-unaware strpos matches that inner
 * sequence first and reports a span that ends in the middle of the module's own
 * JSON. module_move() splices [start, end) out and reinserts it, so a truncated
 * span relocates a fragment and orphans the remainder, corrupting the page. This
 * is the incident class FORK.md cites as the reason the fork exists.
 *
 * These tests pin the correct span for both the self-closing and container
 * forms, for the divi/ namespace and for a third-party namespace (the case PR #4
 * newly exposed), and confirm a poisoned block does not disturb the addressing
 * of the sibling that follows it.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Return the markup find_block() reports for a resolved match.
 *
 * @param string $content Full page markup.
 * @param array  $match   find_block() result.
 * @return string
 */
function diviops_span( string $content, array $match ): string {
	return substr( $content, $match['start'], $match['end'] - $match['start'] );
}

// The poison: literal example markup carrying a `-->`, and a same-name closing
// comment, inside a JSON string value. Both are what a raw strpos mistakes for
// the end of the opening comment / the module's own closer.
$divi_poison = 'Example: <!-- wp:divi/text {} --><!-- /wp:divi/text -->';

// Container form: opener JSON carries the poison, then a real body and a real
// closing comment.
$poisoned_container =
	'<!-- wp:divi/text {"module":{"meta":{"adminLabel":{"desktop":{"value":"FAQ Answer"}}}},"content":{"desktop":{"value":"' . $divi_poison . '"}}} -->'
	. 'The answer body text.'
	. '<!-- /wp:divi/text -->';

// A clean sibling that follows the poisoned block, so its addressability proves
// the poison does not shift the positions module_move() relies on.
$clean_sibling =
	'<!-- wp:divi/text {"content":{"desktop":{"value":"Second module"}}} -->'
	. 'Second body.'
	. '<!-- /wp:divi/text -->';

$page = '<!-- wp:divi/section {"builderVersion":"5.9.0"} -->'
	. $poisoned_container
	. $clean_sibling
	. '<!-- /wp:divi/section -->';

// ── The poisoned container spans its whole self ──────────────────────

$container = diviops_call( 'find_block', array( $page, '', '', 'text:1' ) );
assert_true( ! is_wp_error( $container ), 'find_block resolves a container whose attr JSON contains a comment terminator' );
if ( ! is_wp_error( $container ) ) {
	assert_same(
		$poisoned_container,
		diviops_span( $page, $container ),
		'the span covers the whole module, not a fragment ending inside its own JSON'
	);
	assert_true(
		'<!-- /wp:divi/text -->' === substr( diviops_span( $page, $container ), -22 ),
		'the span ends at the real closing comment, not the one embedded in the attr value'
	);
	assert_true(
		false !== strpos( diviops_span( $page, $container ), 'The answer body text.' ),
		'the span includes the module body that follows the poisoned opener'
	);
}

// ── The sibling after the poison is still addressable and intact ─────

$sibling = diviops_call( 'find_block', array( $page, '', '', 'text:2' ) );
assert_true( ! is_wp_error( $sibling ), 'the sibling after a poisoned block still resolves by index' );
if ( ! is_wp_error( $sibling ) ) {
	assert_same(
		$clean_sibling,
		diviops_span( $page, $sibling ),
		'the sibling span is exactly the sibling, unshifted by the poison in the block before it'
	);
}

// ── Label targeting resolves the poisoned block to a full span ───────

$by_label = diviops_call( 'find_block', array( $page, 'FAQ Answer', '', '' ) );
assert_true( ! is_wp_error( $by_label ), 'label targeting resolves the poisoned container' );
if ( ! is_wp_error( $by_label ) ) {
	assert_same(
		$poisoned_container,
		diviops_span( $page, $by_label ),
		'label targeting reports the full module span, not a truncated fragment'
	);
}

// ── module_move splice round-trips without corruption ────────────────
//
// Reproduce what module_move() does with the reported span: cut the source out
// and reinsert it after the sibling. A correct span round-trips to content that
// still holds both modules whole.

if ( ! is_wp_error( $container ) && ! is_wp_error( $sibling ) ) {
	$source_markup = diviops_span( $page, $container );
	$without       = substr( $page, 0, $container['start'] ) . substr( $page, $container['end'] );
	$insert_pos    = $sibling['end'] - ( $container['end'] - $container['start'] );
	$moved         = substr( $without, 0, $insert_pos ) . $source_markup . substr( $without, $insert_pos );

	assert_true(
		false !== strpos( $moved, $poisoned_container ),
		'a move splice keeps the poisoned module whole in the rewritten page'
	);
	assert_true(
		false !== strpos( $moved, $clean_sibling ),
		'a move splice keeps the sibling whole in the rewritten page'
	);
	assert_same(
		strlen( $page ),
		strlen( $moved ),
		'a move splice neither drops nor duplicates bytes'
	);
}

// ── Self-closing form ────────────────────────────────────────────────

$poisoned_self_closing =
	'<!-- wp:divi/text {"content":{"desktop":{"value":"' . $divi_poison . '"}}} /-->';
$sc_page = '<!-- wp:divi/section {"builderVersion":"5.9.0"} -->'
	. $poisoned_self_closing
	. '<!-- wp:divi/text {"content":{"desktop":{"value":"After"}}} /-->'
	. '<!-- /wp:divi/section -->';

$self_closing = diviops_call( 'find_block', array( $sc_page, '', '', 'text:1' ) );
assert_true( ! is_wp_error( $self_closing ), 'find_block resolves a self-closing block whose attr JSON contains a comment terminator' );
if ( ! is_wp_error( $self_closing ) ) {
	assert_same(
		$poisoned_self_closing,
		diviops_span( $sc_page, $self_closing ),
		'the self-closing span stops at its own /--> marker, not the --> inside its JSON'
	);
}

// ── Third-party namespace: the exposure PR #4 opened ─────────────────

$difl_poison = 'Nested example: <!-- wp:difl/faq {} --><!-- /wp:difl/faq -->';
$poisoned_difl =
	'<!-- wp:difl/faq {"content":{"desktop":{"value":"' . $difl_poison . '"}}} -->'
	. 'Real FAQ body.'
	. '<!-- /wp:difl/faq -->';
$difl_page = '<!-- wp:divi/section {"builderVersion":"5.9.0"} -->'
	. $poisoned_difl
	. '<!-- /wp:divi/section -->';

$difl = diviops_call( 'find_block', array( $difl_page, '', '', 'difl/faq:1' ) );
assert_true( ! is_wp_error( $difl ), 'find_block resolves a third-party container whose attr JSON contains a comment terminator' );
if ( ! is_wp_error( $difl ) ) {
	assert_same(
		$poisoned_difl,
		diviops_span( $difl_page, $difl ),
		'the third-party span covers the whole module, not a fragment ending inside its own JSON'
	);
}

// ── Regression: a clean block is spanned exactly as before ───────────

$clean_page = '<!-- wp:divi/section {"builderVersion":"5.9.0"} -->'
	. '<!-- wp:divi/text {"content":{"desktop":{"value":"Nothing unusual here"}}} /-->'
	. '<!-- /wp:divi/section -->';
$clean = diviops_call( 'find_block', array( $clean_page, '', '', 'text:1' ) );
assert_true( ! is_wp_error( $clean ), 'find_block still resolves a block with no comment terminator in its JSON' );
if ( ! is_wp_error( $clean ) ) {
	assert_same(
		'<!-- wp:divi/text {"content":{"desktop":{"value":"Nothing unusual here"}}} /-->',
		diviops_span( $clean_page, $clean ),
		'a clean self-closing block still spans exactly its own markup'
	);
}
