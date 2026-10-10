<?php
// SPDX-License-Identifier: MIT
/**
 * The write guard's marker scan on block comments, at any attribute size (#548).
 *
 * `assert_divi_full_content_safe_for_write()` refuses a write unless
 * `divi_content_marker_counts()` balances and `validate_divi_marker_sequence()`
 * reports ok. Both once found a comment's end with a lazy tempered dot,
 * `(?:(?!-->).)*?`, which costs PCRE one backtracking frame per byte of
 * attribute JSON, so past some size the match gave up instead of answering: the
 * sequence check reported `scan_failed`, and the self-closer census read
 * `(int) false` as 0, turning a self-closing block into an unclosed container.
 * The guard then refused valid markup, naming unbalanced markers.
 *
 * Where that happened, measured with a standalone probe on PHP 8.5.11 and
 * PCRE 10.49 at default `pcre.backtrack_limit` 1000000 and
 * `pcre.recursion_limit` 100000, varying one attribute string's length inside
 * one `divi/text` block:
 *
 *   pcre.jit=1  sequence from 24,488 bytes for a self-closing block and
 *               24,489 for a container ("JIT stack limit exhausted"),
 *               self-closer census from 24,495. Raising both limits to
 *               100,000,000 moved neither; PHP's only PCRE settings are those
 *               two and `pcre.jit`, so nothing configurable sizes the JIT stack.
 *   pcre.jit=0  sequence from 249,918 ("Backtrack limit exhausted"),
 *               self-closer census from 499,919. Both scale with
 *               `pcre.backtrack_limit` (x10 put the sequence cliff at
 *               2,499,918); `pcre.recursion_limit` moved nothing.
 *
 * The opener and closer census patterns had no tempered dot and passed at
 * 4,000,000 bytes everywhere, which is why a container block's counts looked
 * healthy while a self-closing block's did not.
 *
 * The large fixtures are 512 KiB: the smallest power of two past every cliff
 * above at default limits, so a regression to a backtracking scan fails here
 * whether or not the runner has JIT. Toggling `pcre.jit` inside the test is not
 * an option: after an in-process `ini_set()`, already-compiled patterns still
 * failed at 30,000 bytes, where a `-d pcre.jit=0` process passes.
 *
 * Below the large fixtures, what the scan has to keep: where a comment ends
 * (the first `-->` after the whole name, even inside an attribute string),
 * `preg_match_all()`'s non-overlap, names whose trailing dashes run into `-->`,
 * the whitespace WordPress allows after `<!--`, markers nothing terminates, the
 * error preview, a failed scan's refusal, and a census that stays linear.
 * Apart from the large fixtures and the failed-scan message, every assertion
 * here also passes against the backtracking patterns; the expected values are
 * theirs.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$diviops_msz_bytes   = 524288;
$diviops_msz_attrs   = '{"builderVersion":"5.0.0","content":{"innerContent":{"desktop":{"value":"' . str_repeat( 'a', $diviops_msz_bytes ) . '"}}}}';
$diviops_msz_self    = '<!-- wp:divi/text ' . $diviops_msz_attrs . ' /-->';
$diviops_msz_contain = '<!-- wp:divi/text ' . $diviops_msz_attrs . ' --><!-- /wp:divi/text -->';

// Valid, balanced markup: one self-closing block. Scanned to its real end.
assert_same(
	array( 'ok' => true ),
	diviops_call( 'validate_divi_marker_sequence', array( $diviops_msz_self ) ),
	'validate_divi_marker_sequence() accepts a self-closing block carrying 512 KiB of attributes'
);

assert_same(
	array( 'ok' => true ),
	diviops_call( 'validate_divi_marker_sequence', array( $diviops_msz_contain ) ),
	'validate_divi_marker_sequence() accepts a container block carrying 512 KiB of attributes'
);

assert_same(
	array(
		'openers'           => 1,
		'self_closers'      => 1,
		'container_openers' => 0,
		'closers'           => 0,
	),
	diviops_call( 'divi_content_marker_counts', array( $diviops_msz_self ) ),
	'divi_content_marker_counts() counts a self-closing block carrying 512 KiB of attributes as a self-closer, not as an unclosed container'
);

assert_same(
	array(
		'openers'           => 1,
		'self_closers'      => 0,
		'container_openers' => 1,
		'closers'           => 1,
	),
	diviops_call( 'divi_content_marker_counts', array( $diviops_msz_contain ) ),
	'divi_content_marker_counts() balances a container block carrying 512 KiB of attributes'
);

foreach ( array( 'self-closing' => $diviops_msz_self, 'container' => $diviops_msz_contain ) as $diviops_msz_label => $diviops_msz_markup ) {
	assert_same(
		true,
		diviops_call( 'assert_divi_full_content_safe_for_write', array( $diviops_msz_markup ) ),
		"assert_divi_full_content_safe_for_write() lets a valid {$diviops_msz_label} block carrying 512 KiB of attributes through"
	);
}

/*
 * Where a block comment ends: the first `-->` after the block name, whatever
 * surrounds it, and a self-closer is an opener whose `-->` follows a `/`. That
 * is what the backtracking patterns matched, and the scan keeps it on purpose.
 * WordPress's parser does not track JSON strings either: its attribute group
 * ends at a `}` followed by whitespace and `/-->` or `-->`
 * (wp-includes/class-wp-block-parser.php, next_token(), WordPress 7.1), so a
 * string-aware scan accepts markup WordPress splits differently.
 */

// DEFECT, pinned as-is; the same class as #555. WordPress reads this as one
// self-closing block, because the `-->` inside the string does not follow `}`
// and whitespace. The guard stops at that `-->`, finds no `/` before it, and
// reports a container that is never closed. Fixing #555 has to move this too.
$diviops_msz_arrow = '<!-- wp:divi/text {"builderVersion":"5.0.0","v":"Step 1 --> Step 2"} /-->';
assert_same(
	array(
		'ok'     => false,
		'reason' => 'unclosed_block',
		'type'   => 'divi/text',
		'offset' => 0,
	),
	diviops_call( 'validate_divi_marker_sequence', array( $diviops_msz_arrow ) ),
	'DEFECT: validate_divi_marker_sequence() ends a self-closing block at a `-->` inside an attribute string and reports it unclosed'
);
assert_same(
	array(
		'openers'           => 1,
		'self_closers'      => 0,
		'container_openers' => 1,
		'closers'           => 0,
	),
	diviops_call( 'divi_content_marker_counts', array( $diviops_msz_arrow ) ),
	'DEFECT: divi_content_marker_counts() counts a self-closing block with a `-->` inside an attribute string as a container'
);

// An unbalanced quote must not hide the next marker. WordPress reads two
// unclosed openers here, so the guard has to refuse it, which a scan that
// tracked JSON strings would not: it would read one self-closing block.
$diviops_msz_quote = '<!-- wp:difl/code {"v":"a} --><!-- wp:difl/row -->"} /-->';
assert_same(
	array(
		'ok'     => false,
		'reason' => 'unclosed_block',
		'type'   => 'difl/row',
		'offset' => strpos( $diviops_msz_quote, '<!-- wp:difl/row' ),
	),
	diviops_call( 'validate_divi_marker_sequence', array( $diviops_msz_quote ) ),
	'validate_divi_marker_sequence() does not let an unbalanced quote swallow the next opener'
);

// The census counts the start of every marker whether or not anything
// terminates it, so it disagrees with the sequence check when a closer with no
// `-->` of its own runs into the next opener. WordPress reads two openers and
// no closer: it needs whitespace and `-->` after a closer's name.
$diviops_msz_swallow = '<!-- wp:divi/text --><p>a</p><!-- /wp:divi/text <!-- wp:divi/row --><p>b</p>';
assert_same(
	array(
		'openers'           => 2,
		'self_closers'      => 0,
		'container_openers' => 2,
		'closers'           => 1,
	),
	diviops_call( 'divi_content_marker_counts', array( $diviops_msz_swallow ) ),
	'divi_content_marker_counts() counts an opener that an unterminated closer runs into'
);
assert_same(
	'invalid_input',
	is_wp_error( $diviops_msz_swallow_guard = diviops_call( 'assert_divi_full_content_safe_for_write', array( $diviops_msz_swallow ) ) ) ? $diviops_msz_swallow_guard->get_error_code() : $diviops_msz_swallow_guard,
	'assert_divi_full_content_safe_for_write() refuses an opener that an unterminated closer runs into'
);

// A block name may end in `-`, so a greedy name runs into a `-->` written with
// no space before it. The pattern this scan replaced extended its lazy dot
// before it gave characters back from the name, so it took the first `-->`
// after the whole name, and only when there was none fell back to the one the
// name's trailing dashes run into, reading the type as the name before it.
// WordPress reads none of these as block comments: it needs whitespace after
// the name. Each fixture puts the dashes on one side only.
assert_same(
	array(
		'ok'     => false,
		'reason' => 'unclosed_block',
		'type'   => 'divi/text--',
		'offset' => 0,
	),
	diviops_call( 'validate_divi_marker_sequence', array( '<!-- wp:divi/text--><p>a</p><!-- /wp:divi/text -->' ) ),
	'validate_divi_marker_sequence() runs an opener whose name touches `-->` on to the next `-->` after the whole name'
);
$diviops_msz_dashes = '<!-- wp:divi/text --><p>a</p><!-- /wp:divi/text--><!-- wp:divi/section --><p>b</p>';
assert_same(
	array(
		'ok'              => false,
		'reason'          => 'mismatched_closer',
		'expected'        => 'divi/text',
		'expected_offset' => 0,
		'actual'          => 'divi/text--',
		'offset'          => strpos( $diviops_msz_dashes, '<!-- /wp:' ),
		'preview'         => '<!-- /wp:divi/text--><!-- wp:divi/section -->',
	),
	diviops_call( 'validate_divi_marker_sequence', array( $diviops_msz_dashes ) ),
	'validate_divi_marker_sequence() runs a closer whose name touches `-->` on to the next `-->`, swallowing the opener after it'
);
assert_same(
	array( 'ok' => true ),
	diviops_call( 'validate_divi_marker_sequence', array( '<!-- wp:divi/section --><!-- /wp:divi/section-->' ) ),
	'validate_divi_marker_sequence() ends a closer at its own trailing dashes when no `-->` follows the name, and reads the name before them'
);

// A marker start inside an earlier comment is part of that comment, not a
// marker of its own: here the closer start sits before the opener's first
// `-->`, so the opener is never closed.
assert_same(
	array(
		'ok'     => false,
		'reason' => 'unclosed_block',
		'type'   => 'divi/text',
		'offset' => 0,
	),
	diviops_call( 'validate_divi_marker_sequence', array( '<!-- wp:divi/text <!-- /wp:divi/text -->' ) ),
	'validate_divi_marker_sequence() does not read a marker start inside an earlier comment as a marker'
);

// An unterminated opener does not stop the census: the closer after it still
// counts. That leaves this document balanced on counts, and the sequence check
// has no complete comment after the self-closer to object to.
// SUSPECTED DEFECT, pinned as-is: the guard accepts it, while WordPress reads
// one void block followed by text that opens an HTML comment it never closes.
// Not introduced by #548; the backtracking patterns accepted it too.
$diviops_msz_tail = '<!-- wp:divi/text /--><!-- wp:divi/row <!-- /wp:divi/row';
assert_same(
	array(
		'openers'           => 2,
		'self_closers'      => 1,
		'container_openers' => 1,
		'closers'           => 1,
	),
	diviops_call( 'divi_content_marker_counts', array( $diviops_msz_tail ) ),
	'divi_content_marker_counts() keeps counting markers after an opener nothing terminates'
);
assert_same(
	true,
	diviops_call( 'assert_divi_full_content_safe_for_write', array( $diviops_msz_tail ) ),
	'SUSPECTED DEFECT: assert_divi_full_content_safe_for_write() accepts an unterminated opener and closer pair after the last `-->`'
);

// preg_match_all() never tries a start inside an earlier match, so an opener
// that sits inside a self-closer's span is not a second self-closer. The
// census keeps that: one self-closer, and an opener left over as a container.
assert_same(
	array(
		'openers'           => 2,
		'self_closers'      => 1,
		'container_openers' => 1,
		'closers'           => 0,
	),
	diviops_call( 'divi_content_marker_counts', array( '<!-- wp:divi/text <!-- wp:divi/code /-->' ) ),
	'divi_content_marker_counts() does not count an opener inside a self-closer as a second self-closer'
);

// An opener that starts inside another opener's span is still tried when that
// span's `-->` falls inside its own dash-ended name: the search starts after
// the whole name, so it reaches the `/-->` beyond. Found by a differential run
// against the backtracking patterns, which count one self-closer here.
assert_same(
	array(
		'openers'           => 2,
		'self_closers'      => 1,
		'container_openers' => 1,
		'closers'           => 0,
	),
	diviops_call( 'divi_content_marker_counts', array( '<!-- wp:divi/section <!-- wp:divi/text--> /-->' ) ),
	'divi_content_marker_counts() searches for an opener\'s `-->` from the end of its own name, past one its trailing dashes run into'
);

/*
 * The census stays linear when many openers share one far `-->`. Reusing the
 * last `-->` found is what keeps it so, and dropping that reuse changes no
 * result, only the time: 24,000 openers before one stray `-->` took 3,473 ms
 * that way against 4.2 ms here, and 24,000 self-closers took 4.3 ms either
 * way (PHP 8.5.11). Time is compared with that self-closer document in the
 * same process, so the bound does not depend on how fast the runner is: the
 * linear scan measured 1.0x, the quadratic one 800x, and the bound is 10x.
 */
function diviops_msz_best_ms( string $markup ): float {
	$best = INF;
	for ( $i = 0; $i < 3; $i++ ) {
		$t    = microtime( true );
		diviops_call( 'divi_content_marker_counts', array( $markup ) );
		$best = min( $best, microtime( true ) - $t );
	}
	return 1000 * $best;
}
$diviops_msz_shared = diviops_msz_best_ms( str_repeat( '<!-- wp:difl/x {"q":1} ', 24000 ) . '" -->' );
$diviops_msz_own    = diviops_msz_best_ms( str_repeat( '<!-- wp:difl/x {"q":1} /-->', 24000 ) );
assert_true(
	$diviops_msz_shared < 10 * max( $diviops_msz_own, 1.0 ),
	sprintf( 'divi_content_marker_counts() stays linear when 24,000 openers share one `-->` (%.1f ms against %.1f ms for 24,000 self-closers)', $diviops_msz_shared, $diviops_msz_own )
);

// Closers take no part in finding self-closers, so an opener inside a closer
// that nothing terminates before it is still tried, as the self-closer pattern
// (which matched openers only) tried it.
assert_same(
	array(
		'openers'           => 1,
		'self_closers'      => 1,
		'container_openers' => 0,
		'closers'           => 1,
	),
	diviops_call( 'divi_content_marker_counts', array( '<!-- /wp:divi/row <!-- wp:divi/text /-->' ) ),
	'divi_content_marker_counts() still finds a self-closer that starts inside an unterminated closer'
);

// The preview is the first 120 bytes of the offending comment, from the
// handler's own `substr( $token, 0, 120 )`.
$diviops_msz_long_closer = '<!-- /wp:divi/text {"x":"' . str_repeat( 'b', 200 ) . '"} -->';
assert_same(
	array(
		'ok'              => false,
		'reason'          => 'mismatched_closer',
		'expected'        => 'divi/section',
		'expected_offset' => 0,
		'actual'          => 'divi/text',
		'offset'          => 24,
		'preview'         => substr( $diviops_msz_long_closer, 0, 120 ),
	),
	diviops_call( 'validate_divi_marker_sequence', array( '<!-- wp:divi/section -->' . $diviops_msz_long_closer ) ),
	'validate_divi_marker_sequence() previews 120 bytes of a long offending comment, not all of it'
);

/*
 * Grammar the scan must keep. WordPress's parser opens a block comment with
 * `<!--\s+`, so a newline, a tab or a doubled space after `<!--` is still a
 * block comment. Each pair below pairs an unusual run with a single space on
 * the other side, so a scan that lost the whitespace run on openers only, or on
 * closers only, leaves a closer without its opener or an opener without its
 * closer, and the sequence check reports it rather than seeing nothing at all.
 */
$diviops_msz_spaced = "<!--\n wp:divi/row --><!-- /wp:divi/row -->"
	. "<!-- wp:divi/section {\"builderVersion\":\"5.0.0\"} --><!--  wp:divi/text /--><!--\t/wp:divi/section -->";
assert_same(
	array( 'ok' => true ),
	diviops_call( 'validate_divi_marker_sequence', array( $diviops_msz_spaced ) ),
	'validate_divi_marker_sequence() accepts any whitespace run between `<!--` and the block name'
);
assert_same(
	array(
		'openers'           => 3,
		'self_closers'      => 1,
		'container_openers' => 2,
		'closers'           => 2,
	),
	diviops_call( 'divi_content_marker_counts', array( $diviops_msz_spaced ) ),
	'divi_content_marker_counts() counts markers with any whitespace run between `<!--` and the block name'
);

// PCRE's `\s` is space, \t, \n, \v (0x0B), \f and \r, so the three rarer ones
// open a block comment too.
assert_same(
	array(
		'openers'           => 2,
		'self_closers'      => 1,
		'container_openers' => 1,
		'closers'           => 1,
	),
	diviops_call( 'divi_content_marker_counts', array( "<!--\r wp:divi/row --><!--\x0B wp:divi/text /--><!--\f/wp:divi/row -->" ) ),
	'divi_content_marker_counts() counts markers opened by \\r, \\v and \\f'
);

// With no whitespace at all it is not a block comment, for WordPress or here.
assert_same(
	array(
		'openers'           => 0,
		'self_closers'      => 0,
		'container_openers' => 0,
		'closers'           => 0,
	),
	diviops_call( 'divi_content_marker_counts', array( '<!--wp:divi/section --><!--/wp:divi/section -->' ) ),
	'divi_content_marker_counts() does not count `<!--wp:` with no whitespace as a marker'
);

/*
 * An opener with no terminator anywhere after it. The census still counts it,
 * as a container, so the write guard refuses the document on the census; the
 * sequence check has no complete comment to read and reports nothing about it.
 */
$diviops_msz_open_end = '<!-- wp:divi/section {"builderVersion":"5.0.0"}';
assert_same(
	array(
		'openers'           => 1,
		'self_closers'      => 0,
		'container_openers' => 1,
		'closers'           => 0,
	),
	diviops_call( 'divi_content_marker_counts', array( $diviops_msz_open_end ) ),
	'divi_content_marker_counts() counts an unterminated opener as an unclosed container'
);
assert_same(
	array( 'ok' => true ),
	diviops_call( 'validate_divi_marker_sequence', array( $diviops_msz_open_end ) ),
	'validate_divi_marker_sequence() skips an opener that has no terminator'
);
assert_same(
	'invalid_input',
	is_wp_error( $diviops_msz_unterm = diviops_call( 'assert_divi_full_content_safe_for_write', array( $diviops_msz_open_end ) ) ) ? $diviops_msz_unterm->get_error_code() : $diviops_msz_unterm,
	'assert_divi_full_content_safe_for_write() refuses an unterminated opener on the census'
);

/*
 * A scan that fails says so. The marker scan still runs one pattern, so a PCRE
 * error is possible in principle even though no input found one: a pattern of
 * that shape returned without error on 8 MB inputs under both JIT settings. A
 * backtrack limit of 1 forces the failure deterministically, under either
 * setting, and is restored in a `finally` before anything else runs.
 */
$diviops_msz_limit = ini_get( 'pcre.backtrack_limit' );
ini_set( 'pcre.backtrack_limit', '1' );
try {
	$diviops_msz_failed = diviops_call( 'assert_divi_full_content_safe_for_write', array( '<!-- wp:divi/section --><!-- /wp:divi/section -->' ) );
} finally {
	ini_set( 'pcre.backtrack_limit', $diviops_msz_limit );
}
$diviops_msz_failed_data = is_wp_error( $diviops_msz_failed ) ? (array) $diviops_msz_failed->get_error_data() : array();
assert_same(
	array(
		'code'    => 'invalid_input',
		'message' => 'Divi block markup could not be scanned for opener/closer markers.',
		'marker'  => 'scan_failed',
	),
	array(
		'code'    => is_wp_error( $diviops_msz_failed ) ? $diviops_msz_failed->get_error_code() : $diviops_msz_failed,
		'message' => is_wp_error( $diviops_msz_failed ) ? $diviops_msz_failed->get_error_message() : null,
		'marker'  => $diviops_msz_failed_data['marker']['reason'] ?? null,
	),
	'assert_divi_full_content_safe_for_write() refuses a failed scan as a failed scan, not as unbalanced markers'
);
