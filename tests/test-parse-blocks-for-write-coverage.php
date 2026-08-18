<?php
// SPDX-License-Identifier: MIT
/**
 * Structural regression guard: every function that actually parses block
 * markup for a save/validate round trip must call parse_blocks_for_write()
 * (or parse_blocks_with_layout_context() directly), never bare parse_blocks()
 * (#11, #99).
 *
 * A bare parse_blocks() call on a page carrying a divi/global-layout wrapper
 * silently materializes the wrapper into the resolved content of the layout
 * it references, unless Divi's own _is_rest_update_request() skip condition
 * happens to hold — confirmed to hold for genuine REST dispatch via one live
 * HTTP trial against the reference site (see trait-core.php's docblock for
 * the exact mechanism) — but NOT for wp eval/CLI invocation, which is how
 * both #11 and #99 were found. parse_blocks_for_write() (trait-core.php)
 * makes the skip unconditional regardless of invocation context.
 *
 * #99 found two functions that had drifted from this pattern
 * (normalize_and_validate_divi_markup_before_write, validate_blocks) despite
 * it being established and documented since #11. This test exists so a THIRD
 * function drifting the same way fails loudly here instead of needing to be
 * independently rediscovered via a live wp-eval reproduction, the way both
 * #11 and #99 were.
 *
 * The plain-PHP harness cannot exercise the BEHAVIORAL difference between
 * parse_blocks_for_write() and bare parse_blocks() — parse_blocks() itself is
 * deliberately unshimmed (see test-global-layout-write-guard.php's header),
 * and parse_blocks_for_write()'s Divi-class branch never activates without a
 * real Divi install. This test instead verifies the STRUCTURAL invariant
 * directly from source, matching how #99 itself was actually found and
 * fixed. Live behavior (materialization does not occur, in either a real
 * REST call or wp eval, after the fix) was verified separately against the
 * reference site — see #99's PR description.
 *
 * Checks the functions that DIRECTLY contain the parse call, not every
 * top-level REST handler that eventually triggers one:
 *   - module_lock/unlock/clone delegate their actual parsing to the shared
 *     load_post_for_module_op() helper rather than parsing inline.
 *   - module_move does NOT go through that helper (an earlier version of
 *     this test incorrectly assumed it did, and the omission gave zero
 *     regression coverage to module_move's actual parse site — caught by
 *     adversarial review, which proved it by reverting that site's guard
 *     and confirming the full suite still passed). module_move's primary
 *     path is a non-parser string scanner; its parse site is a distinct
 *     fallback function, move_block_with_parser(), used only when the
 *     scanner can't resolve a target unambiguously.
 *   - page_block_insert() and parse_divi_blocks_for_insert() (the latter
 *     backing tb_layout_block_insert's *inserted* content specifically, a
 *     separate parse from the layout being inserted into) round out the
 *     full set. See FORK.md's divergence table for the authoritative list
 *     this test's own list must stay in sync with.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Extract a method's exact source text via Reflection, so this test reads
 * the real compiled method body rather than re-parsing the file by hand.
 */
function get_method_source( string $method ): string {
	$reflection = new ReflectionMethod( 'DiviOps_Agent', $method );
	$file       = $reflection->getFileName();
	$start      = $reflection->getStartLine() - 1;
	$length     = $reflection->getEndLine() - $start;
	$lines      = file( $file );
	return implode( '', array_slice( $lines, $start, $length ) );
}

/**
 * Strip comments and strings from PHP source via the real tokenizer, so a
 * comment merely mentioning "parse_blocks(" in prose (several call sites
 * document the guard this way) can never be mistaken for an actual call —
 * exactly the false positive a text/regex search hits here, confirmed while
 * writing this test against preset_reassign() and tb_layout_block_insert(),
 * both of which document the guard in a comment immediately above the real
 * call and were flagged by a first, comment-blind version of this check.
 */
function strip_comments_and_strings( string $source ): string {
	$code = '<?php ' . $source;
	$out  = '';
	foreach ( token_get_all( $code ) as $token ) {
		if ( is_array( $token ) ) {
			if ( in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ], true ) ) {
				continue;
			}
			$out .= $token[1];
		} else {
			$out .= $token;
		}
	}
	return $out;
}

/**
 * PHP function calls are case-insensitive at the language level (Parse_Blocks()
 * genuinely invokes parse_blocks() at runtime) — the /i modifier here isn't
 * decorative, adversarial review proved a case-varied bare call slips past a
 * case-sensitive version of this pattern undetected.
 *
 * The negative lookbehinds against "_for_write"/"_with_layout_context" are
 * NOT what prevents this from matching inside those two sanctioned wrapper
 * names — \bparse_blocks\( already can't match a substring of a longer
 * identifier with more characters between "parse_blocks" and "(" than the
 * literal pattern allows (proven by removing them and confirming identical
 * output on every guarded call site in this codebase). They're kept anyway
 * as an explicit, self-documenting statement of intent for a future reader,
 * not as functional protection — don't rely on them to prevent a false
 * match; \b already does that.
 */
function has_bare_parse_blocks_call( string $source ): bool {
	$code_only = strip_comments_and_strings( $source );
	return (bool) preg_match( '/(?<!function )(?<!_for_write)(?<!_with_layout_context)\bparse_blocks\(/i', $code_only );
}

// Sanity checks on the checker itself, since a silently-broken check would
// make every assertion below pass for the wrong reason.
assert_true(
	! has_bare_parse_blocks_call( '// calls parse_blocks_for_write(), not bare parse_blocks()' ),
	'strip_comments_and_strings(): a bare parse_blocks( mentioned only in a comment is not flagged as a real call'
);
assert_true(
	has_bare_parse_blocks_call( '$blocks = parse_blocks( $content );' ),
	'strip_comments_and_strings(): a genuine bare parse_blocks( call in real code is still flagged'
);
assert_true(
	has_bare_parse_blocks_call( '$blocks = Parse_Blocks( $content );' ),
	'has_bare_parse_blocks_call(): a case-varied bare call is still flagged (PHP function calls are case-insensitive)'
);

// Every function that DIRECTLY parses block markup as part of a save or
// validate round trip. #11's fix wired the first four; #99 added the last two.
$write_path_functions = [
	'load_post_for_module_op',
	'move_block_with_parser',
	'preset_reassign',
	'tb_layout_block_insert',
	'parse_divi_blocks_for_insert',
	'page_block_insert',
	'normalize_and_validate_divi_markup_before_write',
	'validate_blocks',
];

foreach ( $write_path_functions as $method ) {
	$source = get_method_source( $method );
	assert_true(
		false !== strpos( $source, 'parse_blocks_for_write(' ) || false !== strpos( $source, 'parse_blocks_with_layout_context(' ),
		"{$method}() calls parse_blocks_for_write() (or the layout-context parser directly), not bare parse_blocks()"
	);
	assert_true(
		! has_bare_parse_blocks_call( $source ),
		"{$method}() contains no bare parse_blocks() call anywhere in its own body"
	);
}

echo 'PASS: parse-blocks-for-write-coverage (' . ( 3 + count( $write_path_functions ) * 2 ) . " assertions)\n";
