<?php
// SPDX-License-Identifier: MIT
/**
 * The write guard's marker scan on a block comment with a large attribute
 * payload (#548).
 *
 * `assert_divi_full_content_safe_for_write()` refuses a write unless
 * `divi_content_marker_counts()` balances and `validate_divi_marker_sequence()`
 * reports ok. Both scanned for the end of a block comment with a lazy tempered
 * dot, `(?:(?!-->).)*?`, which costs PCRE one backtracking frame per byte of
 * attribute JSON. Past a size the match gives up rather than answering:
 *
 *   - sequence check: `preg_match_all()` returns false and the function
 *     reports `reason: scan_failed`.
 *   - self-closer census: `preg_match_all()` returns false, `(int) false` is 0,
 *     so a self-closing block reads as a container opener with no closer.
 *
 * Measured with a standalone probe on PHP 8.5.11, PCRE 10.49, at default
 * `pcre.backtrack_limit` 1000000 and `pcre.recursion_limit` 100000, varying the
 * length of one attribute string value inside one `divi/text` block:
 *
 *   pcre.jit=1  sequence fails from 24,489 bytes ("JIT stack limit exhausted");
 *               self-closer census fails from 24,495. Raising both limits to
 *               100,000,000 moves neither: PHP's JIT stack size is fixed.
 *   pcre.jit=0  sequence fails from 249,918 bytes ("Backtrack limit
 *               exhausted"); self-closer census from 499,919. Both move
 *               linearly with `pcre.backtrack_limit` (x10 raised the sequence
 *               cliff to 2,499,918); `pcre.recursion_limit` moved nothing.
 *
 * The opener and closer census patterns have no tempered dot and passed at
 * 4,000,000 bytes in every configuration, which is why a container block's
 * counts stayed healthy while a self-closing block's did not.
 *
 * The fixture is 512 KiB because that is the smallest power of two past every
 * cliff above at default limits, so the result does not depend on whether the
 * runner has JIT enabled. Toggling `pcre.jit` at runtime is not an option: an
 * `ini_set()` in-process left already-compiled patterns failing at 30,000 bytes,
 * where a `-d pcre.jit=0` process passes.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$diviops_msz_bytes   = 524288;
$diviops_msz_attrs   = '{"builderVersion":"5.0.0","content":{"innerContent":{"desktop":{"value":"' . str_repeat( 'a', $diviops_msz_bytes ) . '"}}}}';
$diviops_msz_self    = '<!-- wp:divi/text ' . $diviops_msz_attrs . ' /-->';
$diviops_msz_contain = '<!-- wp:divi/text ' . $diviops_msz_attrs . ' --><!-- /wp:divi/text -->';

// DEFECT, pinned as-is (#548). Valid, balanced markup: one self-closing block.
assert_same(
	array( 'ok' => false, 'reason' => 'scan_failed' ),
	diviops_call( 'validate_divi_marker_sequence', array( $diviops_msz_self ) ),
	'DEFECT: validate_divi_marker_sequence() gives up on a self-closing block carrying 512 KiB of attributes'
);

// DEFECT, pinned as-is (#548). The same scan gives up on a container block.
assert_same(
	array( 'ok' => false, 'reason' => 'scan_failed' ),
	diviops_call( 'validate_divi_marker_sequence', array( $diviops_msz_contain ) ),
	'DEFECT: validate_divi_marker_sequence() gives up on a container block carrying 512 KiB of attributes'
);

// DEFECT, pinned as-is (#548). The self-closer census reads 0, so the census
// reports a container opener that does not exist and no closer to match it.
assert_same(
	array(
		'openers'           => 1,
		'self_closers'      => 0,
		'container_openers' => 1,
		'closers'           => 0,
	),
	diviops_call( 'divi_content_marker_counts', array( $diviops_msz_self ) ),
	'DEFECT: divi_content_marker_counts() reports a self-closing block with 512 KiB of attributes as an unclosed container'
);

// Healthy today, and must stay so: the opener and closer patterns carry no
// tempered dot, so a container block's census does not depend on its size.
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

// DEFECT, pinned as-is (#548). The write guard refuses both documents, and its
// message names unbalanced markers when the real cause is a scan that gave up.
foreach ( array( 'self-closing' => $diviops_msz_self, 'container' => $diviops_msz_contain ) as $diviops_msz_label => $diviops_msz_markup ) {
	$diviops_msz_guard = diviops_call( 'assert_divi_full_content_safe_for_write', array( $diviops_msz_markup ) );
	$diviops_msz_data  = is_wp_error( $diviops_msz_guard ) ? (array) $diviops_msz_guard->get_error_data() : array();
	assert_same(
		array(
			'code'    => 'invalid_input',
			'message' => 'Divi block markup has unbalanced or mis-nested opener/closer markers.',
			'marker'  => 'scan_failed',
		),
		array(
			'code'    => is_wp_error( $diviops_msz_guard ) ? $diviops_msz_guard->get_error_code() : $diviops_msz_guard,
			'message' => is_wp_error( $diviops_msz_guard ) ? $diviops_msz_guard->get_error_message() : null,
			'marker'  => $diviops_msz_data['marker']['reason'] ?? null,
		),
		"DEFECT: assert_divi_full_content_safe_for_write() refuses a valid {$diviops_msz_label} block carrying 512 KiB of attributes, naming unbalanced markers"
	);
}
