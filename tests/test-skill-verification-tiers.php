<?php
// SPDX-License-Identifier: MIT
/**
 * Skill reference integrity guard for `variable-bindings.md` (#64).
 *
 * Two ways a hand-authored skill reference rots without anyone noticing, both of which
 * have precedent in this repo:
 *
 * 1. A fourth verification tier gets invented. `SKILL.md`'s "Verification convention"
 *    defines exactly three markers and downstream readers act on them; a doc that
 *    stamps `*(source-verified)*` or `*(partially verified)*` reads as authoritative
 *    while meaning nothing the convention defines.
 * 2. A cross-reference goes stale. `module-formats.md`'s header pointed at a script and
 *    a CONTRIBUTING doc that had never been committed (#115/#116) — the link looked
 *    fine and resolved to nothing.
 *
 * This asserts both properties against the reference, plus the fact that `SKILL.md`
 * actually indexes it (an unlinked reference is one nothing routes to). Every check
 * counts what it inspected and asserts the count is non-zero, so the gate cannot pass
 * by finding nothing to look at.
 *
 * @package DiviOps
 */

$skill_dir  = dirname( __DIR__ ) . '/skills/divi-5-builder';
$skill_file = $skill_dir . '/SKILL.md';
$ref_rel    = 'references/variable-bindings.md';
$ref_file   = $skill_dir . '/' . $ref_rel;

assert_true( is_file( $skill_file ), 'SKILL.md exists where this test expects it' );
assert_true( is_file( $ref_file ), 'references/variable-bindings.md exists where this test expects it' );

$skill_src = (string) file_get_contents( $skill_file );
$ref_src   = (string) file_get_contents( $ref_file );

/*
 * SKILL.md routes to the reference. Anchored on the relative path the reference-file
 * table uses, so a rename has to update both sides.
 */
assert_true(
	false !== strpos( $skill_src, '(' . $ref_rel . ')' ),
	'SKILL.md links references/variable-bindings.md'
);

/*
 * The convention SKILL.md actually publishes, read from SKILL.md rather than restated
 * here: restating it would let the table change while this test kept enforcing the old
 * wording. Exactly three tiers, so a doc that stamps a fourth is out of contract.
 */
$declared_tiers = array( '*(VB-verified YYYY-MM-DD)*', '*(verified YYYY-MM-DD)*', '<!-- UNVERIFIED -->' );
foreach ( $declared_tiers as $declared ) {
	assert_true(
		false !== strpos( $skill_src, $declared ),
		"SKILL.md's verification convention still declares {$declared}"
	);
}

/*
 * Every `*(… verified …)*`-shaped stamp in the reference resolves to one of the three.
 * `empirically verified` is the convention's own documented alias for tier 2.
 */
$stamps_found = preg_match_all( '/\*\(([A-Za-z][A-Za-z -]*verified)[^)]*\)\*/', $ref_src, $stamp_matches );
assert_true( $stamps_found > 0, 'the reference carries at least one verification stamp to inspect' );

$known_tiers   = array( 'verified', 'vb-verified', 'empirically verified' );
$unknown_tiers = array();
foreach ( $stamp_matches[1] as $stamp ) {
	$normalized = strtolower( trim( $stamp ) );
	if ( ! in_array( $normalized, $known_tiers, true ) ) {
		$unknown_tiers[ $normalized ] = true;
	}
}
assert_same(
	array(),
	array_keys( $unknown_tiers ),
	'variable-bindings.md uses only the verification tiers SKILL.md defines'
);

/*
 * No bare `(verified)` / `(VB-verified)` without a date or an explicit placeholder. The
 * convention's whole value is that a stamp names when it was established.
 */
assert_same(
	0,
	preg_match_all( '/\*\((?:VB-)?verified\)\*/i', $ref_src ),
	'every verified/VB-verified stamp in variable-bindings.md carries a date'
);

/*
 * Every relative markdown link in the reference resolves to a real file. In-page
 * anchors and absolute URLs are out of scope here.
 */
$link_count = preg_match_all( '/\]\((?!https?:)([^)#\s]+)(?:#[^)\s]*)?\)/', $ref_src, $link_matches );
assert_true( $link_count > 0, 'the reference carries relative links to inspect' );

$broken = array();
foreach ( array_unique( $link_matches[1] ) as $target ) {
	if ( ! is_file( dirname( $ref_file ) . '/' . $target ) ) {
		$broken[] = $target;
	}
}
assert_same( array(), $broken, 'every relative link in variable-bindings.md resolves to a file' );

/*
 * The domain fact this reference exists to carry. `$variable(...)$` is a shared wrapper
 * and `type` does not discriminate between its namespaces; an edit that drops that
 * framing turns the doc back into a dynamic-content-only reference, which is the
 * misleading state #64 was opened to fix.
 */
assert_true(
	false !== strpos( $ref_src, 'does **not**' ) && false !== strpos( $ref_src, 'discriminate' ),
	'variable-bindings.md still states that `type` does not discriminate between namespaces'
);

assert_true(
	preg_match_all( '/^## Namespace \d+ — /m', $ref_src ) >= 5,
	'variable-bindings.md documents at least five namespaces sharing the wrapper'
);
