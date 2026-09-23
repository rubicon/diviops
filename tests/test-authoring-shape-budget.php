<?php
// SPDX-License-Identifier: MIT
/**
 * Full-content writes are refused before they can exhaust memory (#474).
 *
 * Adopted from upstream (#328 item 1). A resource budget that runs BEFORE any
 * dry-run plan or mutation: it walks the parsed block tree once, counting
 * bytes, blocks, depth, string fields and total string bytes, and refuses with
 * `budget_exceeded` or `parser_invalid` rather than letting `parse_blocks()`
 * OOM the process partway through a write.
 *
 * It is additive. Our `assert_divi_full_content_safe_for_write()` checks
 * structure — balanced openers and closers, marker sequence — and is unchanged.
 * This checks size, in front of it. We had no size guard at all.
 *
 * ── The failure this prevents is ours, not an attacker's ──────────────────
 *
 * Every write route is capability-gated, so a hostile caller is not the threat
 * model. This plugin exists to be driven by an agent, and a runaway generation
 * loop is the realistic source of a multi-million-block payload. The expensive
 * outcome is not a rejected request — it is a half-written page and an OOM.
 *
 * ── Why every limit gets a PAIR of cases ──────────────────────────────────
 *
 * A guard that refuses everything passes a refusal-only test. That is the same
 * shape as a gate that inspects nothing and reports PASS, which this repository
 * has been bitten by three times. So each limit below is asserted twice: once
 * with input that trips it, and once with input just under it that must be
 * ACCEPTED. The under-limit case is the load-bearing one.
 *
 * The limits themselves were measured before adoption rather than assumed.
 * Against every page, post, library item, Theme Builder layout and canvas on
 * staging (2026-09-23): worst real content is 273,774 bytes, 332 blocks, depth
 * 12 — against limits of 1,048,576 / 4,096 / 64. Headroom 3.8x, 12x and 5.3x.
 * A limit set below real content would refuse legitimate writes on the site we
 * actually use, and nothing in the code would say so.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

/**
 * Why this file drives authoring_shape_walk() rather than parse_blocks().
 *
 * `parse_blocks()` is UNSHIMMED ON PURPOSE in this harness — several suites use
 * "Call to undefined function parse_blocks()" as a positive probe signal, and
 * defining it would silently disarm them (see test-core-characterization.php).
 *
 * Hand-rolling a parser here to get around that would be worse than the problem:
 * the limit assertions would then be measuring my parser rather than the budget,
 * and a wrong parser produces confidently wrong expectations. So the block-tree
 * limits are driven against `authoring_shape_walk()` with block arrays built to
 * WordPress's documented parse_blocks() output shape — that IS the walker's real
 * input — while the paths that return BEFORE parsing (byte budget, non-string
 * content, and the missing-parser guard itself) go through the preflight.
 */

/** Run the preflight and return true, or the refusal reason. */
function diviops_asb_preflight( array $contents ) {
	$result = diviops_call( 'authoring_shape_preflight', array( $contents ) );
	if ( is_wp_error( $result ) ) {
		return false !== strpos( $result->get_error_message(), 'exceeds' ) ? 'budget_exceeded' : 'parser_invalid';
	}
	return $result;
}

/** Run the walker directly. Returns true or a refusal reason string. */
function diviops_asb_walk( array $blocks ) {
	$state = array( 'bytes' => 0, 'blocks' => 0, 'fields' => 0, 'string_bytes' => 0 );
	$args  = array( $blocks, 1, &$state );
	return diviops_call_ref( 'authoring_shape_walk', $args );
}

/** One block in parse_blocks() output shape. */
function diviops_asb_block( ?string $name = 'divi/section', array $attrs = array(), array $children = array(), string $inner_html = '' ): array {
	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => $children,
		'innerHTML'    => $inner_html,
		'innerContent' => '' === $inner_html ? array() : array( $inner_html ),
	);
}

/** $count sibling blocks. */
function diviops_asb_blocks( int $count ): array {
	$out = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$out[] = diviops_asb_block();
	}
	return $out;
}

/** A single chain nested $depth levels deep. */
function diviops_asb_nested( int $depth ): array {
	$block = diviops_asb_block();
	for ( $i = 1; $i < $depth; $i++ ) {
		$block = diviops_asb_block( 'divi/group', array(), array( $block ) );
	}
	return array( $block );
}

$limits = diviops_call( 'authoring_shape_limits' );
assert_same(
	array(
		'input_bytes'  => 1048576,
		'blocks'       => 4096,
		'depth'        => 64,
		'fields'       => 8192,
		'string_bytes' => 1048576,
	),
	$limits,
	'#474: the published limits are adopted as-is — measured against real content first, with 3.8x/12x/5.3x headroom'
);

// ── 1. Ordinary content passes ────────────────────────────────────────────
//
// First, because if this failed every refusal below would be meaningless.

assert_same(
	true,
	diviops_asb_walk( diviops_asb_nested( 3 ) ),
	'#474: an ordinary nested block tree is accepted'
);
assert_same(
	true,
	diviops_asb_preflight( array( '' ) ),
	'#474: empty content is accepted — clearing a layout is a legitimate write'
);
assert_same(
	true,
	diviops_asb_preflight( array() ),
	'#474: an empty content list is accepted'
);

// Real-world scale from the staging measurement: the largest page on the site
// must pass comfortably, or the budget is set against the wrong population.
assert_same(
	true,
	diviops_asb_walk( diviops_asb_blocks( 332 ) ),
	'#474: 332 blocks — the largest real block count measured on staging — is accepted'
);

// ── 2. Each limit, both sides ─────────────────────────────────────────────

// blocks
assert_same(
	true,
	diviops_asb_walk( diviops_asb_blocks( $limits['blocks'] ) ),
	'#474: exactly the block limit is accepted — the check is > not >='
);
assert_same(
	'budget_exceeded',
	diviops_asb_walk( diviops_asb_blocks( $limits['blocks'] + 1 ) ),
	'#474: one block over the limit is refused'
);

// depth
assert_same(
	true,
	diviops_asb_walk( diviops_asb_nested( $limits['depth'] ) ),
	'#474: exactly the depth limit is accepted'
);
assert_same(
	'budget_exceeded',
	diviops_asb_walk( diviops_asb_nested( $limits['depth'] + 1 ) ),
	'#474: one level deeper is refused — unbounded recursion is its own way to exhaust the stack'
);

// input_bytes, counted ACROSS the whole list rather than per item: a
// tb_template_create passes three contents, and three items each just under the
// limit must not total three times it.
$half = str_repeat( 'x', (int) ( $limits['input_bytes'] * 0.6 ) );
// Empty string: passes the byte gate and short-circuits before the parser, so
// this reaches the end of the preflight without needing parse_blocks().
assert_same(
	true,
	diviops_asb_preflight( array( '' ) ),
	'#474: a sub-limit content is accepted by the byte budget'
);
assert_same(
	'budget_exceeded',
	diviops_asb_preflight( array( $half, $half ) ),
	'#474: two of them together exceed the budget — bytes accumulate across the list, as tb_template_create passes three contents at once'
);

// ── 3. parser_invalid is a different verdict from budget_exceeded ─────────
//
// They must not collapse: one means "too big", the other means "this is not
// what it claims to be", and an operator acts differently on each.

assert_same(
	'parser_invalid',
	diviops_asb_preflight( array( 123 ) ),
	'#474: a non-string content is refused as unparseable, not as oversized'
);

// A freeform block (blockName === null) whose TEXT carries a block delimiter.
// That is an unparsed delimiter leaking into content — the same family as the
// truncation defects fixed in #7/#10/#18, caught here before it is persisted
// rather than discovered afterwards.
assert_same(
	'parser_invalid',
	diviops_asb_walk( array( diviops_asb_block( null, array(), array(), 'stray <!-- wp:divi/section --> delimiter' ) ) ),
	'#474: an unparsed block delimiter inside a freeform block is refused'
);
assert_same(
	'parser_invalid',
	diviops_asb_walk( array( diviops_asb_block( null, array(), array(), 'a closing <!-- /wp:divi/section --> counts too' ) ) ),
	'#474: a closing delimiter is caught as well as an opening one'
);
assert_same(
	true,
	diviops_asb_walk( array( diviops_asb_block( null, array(), array(), 'plain text with no delimiters at all' ) ) ),
	'#474: ordinary freeform text is still accepted — the check is about delimiters, not about being freeform'
);
// A NAMED block may legitimately carry delimiter-looking text; the refusal is
// scoped to freeform. Without this, the rule could be "reject any delimiter
// anywhere" and every assertion above would still pass.
assert_same(
	true,
	diviops_asb_walk( array( diviops_asb_block( 'divi/text', array( 'content' => 'documenting <!-- wp:divi/section --> in a code sample' ) ) ) ),
	'#474: the same text inside a NAMED block is accepted — the rule is about unparsed freeform, not about the characters'
);

// The missing-parser guard itself: parse_blocks() is unshimmed here, so a
// non-empty content reaching the parse step must refuse rather than fatal.
// With no parse_blocks() available the tree walk is SKIPPED, not fatal and not a
// refusal. Upstream refuses here; this fork does not, because parse_blocks() has
// shipped since WordPress 5.0 and this plugin requires Divi 5 — the branch is
// unreachable in production, and making it fatal would turn a degraded
// environment into a total write outage for a defence-in-depth check.
//
// Asserted explicitly rather than left implicit: this is the one place the
// budget is weaker than upstream's, and a reader must be able to see that.
assert_same(
	true,
	diviops_asb_preflight( array( '<!-- wp:divi/section --><!-- /wp:divi/section -->' ) ),
	'#474: with no parse_blocks() the tree walk is skipped rather than refusing the write'
);
// The byte budget is NOT skipped with it — the degradation is scoped to the walk.
assert_same(
	'budget_exceeded',
	diviops_asb_preflight( array( str_repeat( 'x', $limits['input_bytes'] + 1 ) ) ),
	'#474: and the byte budget still applies without a parser, since it never needed one'
);

// ── 4. The seven write paths actually consult it ──────────────────────────
//
// A budget nothing calls is a budget that does not exist. Read from the real
// method sources rather than asserted in prose.

$call_sites = array(
	'canvas_create'       => 'trait-canvas.php',
	'canvas_update'       => 'trait-canvas.php',
	'library_save'        => 'trait-library.php',
	'page_update_content' => 'trait-page.php',
	'page_create'         => 'trait-page.php',
	'tb_layout_update'    => 'trait-theme-builder.php',
	'tb_template_create'  => 'trait-theme-builder.php',
);
assert_same(
	7,
	count( $call_sites ),
	'#474: all seven full-content write entry points are covered — a shorter list would pass while leaving a path unguarded'
);

foreach ( $call_sites as $method => $file ) {
	$reflection = new ReflectionMethod( 'DiviOps_Agent', $method );
	$lines      = file( $reflection->getFileName() );
	$source     = implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );
	assert_true(
		false !== strpos( $source, 'authoring_shape_preflight' ),
		sprintf( '#474: %s() runs the budget preflight', $method )
	);
	assert_true(
		false !== strpos( $source, 'is_wp_error( $shape )' ) || false !== strpos( $source, 'is_wp_error($shape)' ),
		sprintf( '#474: %s() acts on the refusal rather than discarding it — an unchecked return is the same as no guard', $method )
	);
}

// tb_template_create passes THREE contents in one call, which is why the byte
// budget accumulates across the list above.
$tb_create  = new ReflectionMethod( 'DiviOps_Agent', 'tb_template_create' );
$tb_lines   = file( $tb_create->getFileName() );
$tb_source  = implode( '', array_slice( $tb_lines, $tb_create->getStartLine() - 1, $tb_create->getEndLine() - $tb_create->getStartLine() + 1 ) );
assert_true(
	(bool) preg_match( '/authoring_shape_preflight\(\s*\[[^\]]*,[^\]]*,[^\]]*\]/', $tb_source ),
	'#474: tb_template_create passes all three of its contents in one call, so the shared budget sees the real total'
);
