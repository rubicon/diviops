<?php
/**
 * Structural regression guard: every function that actually parses block
 * markup for a save/validate round trip must call parse_blocks_for_write()
 * (or parse_blocks_with_layout_context() directly), never bare parse_blocks()
 * (#11, #99).
 *
 * A bare parse_blocks() call on a page carrying a divi/global-layout wrapper
 * silently materializes the wrapper into the resolved content of the layout
 * it references, unless Divi's own _is_rest_update_request() skip condition
 * happens to hold — which it reliably does for genuine REST dispatch, but
 * NOT for wp eval/CLI invocation, which is how both #11 and #99 were found.
 * parse_blocks_for_write() (trait-core.php) makes the skip unconditional
 * regardless of invocation context.
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
 * top-level REST handler that eventually triggers one — module_move/lock/
 * unlock/clone all delegate their actual parsing to the shared
 * load_post_for_module_op() helper rather than parsing inline, so that
 * helper is what this test targets for those four operations, not the
 * handlers themselves (checking the handlers directly was tried first and
 * false-failed for exactly this reason — parse_blocks_for_write() legitimately
 * doesn't appear in a function that never parses anything itself).
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

function has_bare_parse_blocks_call( string $source ): bool {
	$code_only = strip_comments_and_strings( $source );
	return (bool) preg_match( '/(?<!function )(?<!_for_write)(?<!_with_layout_context)\bparse_blocks\(/', $code_only );
}

// Sanity check on the comment-stripping itself, since a silently-broken
// strip would make has_bare_parse_blocks_call() pass for the wrong reason.
assert_true(
	! has_bare_parse_blocks_call( '// calls parse_blocks_for_write(), not bare parse_blocks()' ),
	'strip_comments_and_strings(): a bare parse_blocks( mentioned only in a comment is not flagged as a real call'
);
assert_true(
	has_bare_parse_blocks_call( '$blocks = parse_blocks( $content );' ),
	'strip_comments_and_strings(): a genuine bare parse_blocks( call in real code is still flagged'
);

// Every function that DIRECTLY parses block markup as part of a save or
// validate round trip. #11's fix wired the first three; #99 added the last two.
$write_path_functions = [
	'load_post_for_module_op',
	'preset_reassign',
	'tb_layout_block_insert',
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

echo 'PASS: parse-blocks-for-write-coverage (' . ( 2 + count( $write_path_functions ) * 2 ) . " assertions)\n";
