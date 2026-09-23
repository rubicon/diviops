<?php
// SPDX-License-Identifier: MIT
/**
 * Skill reference integrity guard for `interactions.md` (#64).
 *
 * Mirrors `tests/test-skill-verification-tiers.php`, which guards
 * `variable-bindings.md`, for the second reference #64 adds. Same two rot modes,
 * same reasons — a reference nothing routes to, and an invented verification tier
 * that reads authoritative while meaning nothing the convention defines.
 *
 * ── Why a separate file rather than extending the sibling ─────────────────
 *
 * The sibling carries assertions specific to its subject (it requires at least five
 * `## Namespace N —` headings, which is a fact about the token grammar and not about
 * skill references in general). Parameterising it would mean either weakening that
 * assertion or threading a per-file exception list through it, and both make the
 * stronger guard weaker. Two focused files cost a little duplication and keep each
 * assertion meaningful for its own subject.
 *
 * ── The one rule this file is built around ────────────────────────────────
 *
 * Every check below counts what it inspected and asserts the count is non-zero. A gate
 * that derives pass/fail only from problems-found passes while inspecting nothing, and
 * that exact failure has precedent in this repository.
 *
 * @package DiviOps
 */

$skill_dir  = dirname( __DIR__ ) . '/skills/divi-5-builder';
$skill_file = $skill_dir . '/SKILL.md';
$ref_rel    = 'references/interactions.md';
$ref_file   = $skill_dir . '/' . $ref_rel;

assert_true( is_file( $skill_file ), '#64: SKILL.md exists where this test expects it' );
assert_true( is_file( $ref_file ), '#64: interactions.md ships' );

$ref_src   = (string) file_get_contents( $ref_file );
$skill_src = (string) file_get_contents( $skill_file );

assert_true(
	strlen( $ref_src ) > 1000 && strlen( $skill_src ) > 1000,
	'#64: both files were actually read (guards against asserting over empty strings)'
);

// ── 1. SKILL.md routes to it ──────────────────────────────────────────────
//
// An unindexed reference is one nothing reaches. This is the failure that makes a
// correct document worthless rather than wrong.

assert_true(
	false !== strpos( $skill_src, $ref_rel ),
	'#64: SKILL.md indexes interactions.md — an unlinked reference is one nothing routes to'
);

// ── 2. Verification stamps resolve to a declared tier ─────────────────────
//
// SKILL.md defines exactly three. Anything else reads as authoritative while meaning
// nothing, which is worse than being unmarked.

// NOTE: the prefix is OPTIONAL here, unlike the sibling guard's pattern. The sibling
// requires `[A-Za-z][A-Za-z -]*verified`, which can only match `VB-verified` and
// `empirically verified` — a plain `*(verified YYYY-MM-DD)*` stamp slips past it
// uncounted. That is the declared tier 2 spelling, so it must be counted.
$stamps_found = preg_match_all( '/\*\(((?:[A-Za-z][A-Za-z -]*)?verified)[^)]*\)\*/', $ref_src, $stamp_matches );

assert_true(
	$stamps_found > 0,
	'#64: interactions.md carries at least one verification stamp (otherwise the tier check below inspects nothing)'
);

$known_tiers = array( 'verified', 'vb-verified', 'empirically verified' );
$unknown     = array();
foreach ( $stamp_matches[1] as $tier ) {
	if ( ! in_array( strtolower( trim( $tier ) ), $known_tiers, true ) ) {
		$unknown[] = $tier;
	}
}

assert_same(
	array(),
	$unknown,
	'#64: every verification stamp resolves to one of SKILL.md\'s three declared tiers'
);

// No bare `(verified)` without a date — a stamp with no date cannot go stale visibly,
// which is the entire point of stamping it.
assert_same(
	0,
	preg_match_all( '/\*\((?:VB-)?verified\)\*/i', $ref_src ),
	'#64: every verified stamp carries a date'
);

// The UNVERIFIED marker must be the declared form exactly. A `<!-- UNVERIFIED: … -->`
// variant is the same class of invention as a fourth tier, and greps for the declared
// marker miss it.
assert_true(
	preg_match_all( '/<!-- UNVERIFIED -->/', $ref_src ) > 0,
	'#64: unverified claims use the declared marker verbatim'
);
assert_same(
	0,
	preg_match_all( '/<!--\s*UNVERIFIED\s*:/i', $ref_src ),
	'#64: no `<!-- UNVERIFIED: … -->` variant — the declared marker is `<!-- UNVERIFIED -->`'
);

// ── 3. Relative cross-references resolve ──────────────────────────────────
//
// `module-formats.md`'s header once pointed at a script and a CONTRIBUTING doc that had
// never been committed (#115/#116). The links looked fine and resolved to nothing.

$link_count = preg_match_all( '/\]\((?!https?:)([^)#\s]+)(?:#[^)\s]*)?\)/', $ref_src, $link_matches );

assert_true(
	$link_count > 0,
	'#64: interactions.md contains relative links (otherwise the resolution check inspects nothing)'
);

$broken = array();
foreach ( $link_matches[1] as $target ) {
	if ( ! file_exists( dirname( $ref_file ) . '/' . $target ) ) {
		$broken[] = $target;
	}
}

assert_same(
	array(),
	$broken,
	'#64: every relative cross-reference in interactions.md resolves to a file that exists'
);

// ── 4. The subject is actually covered ────────────────────────────────────
//
// Guards the degenerate case where the file survives as a stub: present, indexed,
// correctly stamped, and empty of the thing it is for. Each key below is one an author
// must write into a module attribute, so a reference missing them cannot be used.

$required = array( 'trigger', 'effect', 'target', 'breakpointName', 'mouseMovementType' );
$missing  = array();
foreach ( $required as $key ) {
	if ( false === strpos( $ref_src, '`' . $key . '`' ) ) {
		$missing[] = $key;
	}
}

assert_same(
	array(),
	$missing,
	'#64: the entry-shape keys an author must write are all documented'
);

// The trigger and effect vocabularies are the part a reader cannot derive from the
// schema, so their absence would make the rest inert.
assert_true(
	false !== strpos( $ref_src, '`breakpointEnter`' ) && false !== strpos( $ref_src, '`breakpointExit`' ),
	'#64: the breakpoint trigger vocabulary is recorded'
);
assert_true(
	false !== strpos( $ref_src, '`toggleVisibility`' ) && false !== strpos( $ref_src, '`mirrorMouseMovement`' ),
	'#64: the effect vocabulary is recorded'
);

// The minified-bundle counting trap. It is recorded here because every future reader who
// re-verifies this file will hit it, and `grep -c` returning 1 reads as "one match".
// Asserted as a CONTRAST PAIR on one line, not as two independent substrings. A plain
// strpos( 'grep -o' ) is satisfied by the unrelated `grep -oE` example further down, so
// deleting the warning itself survived that check when it was written that way.
assert_true(
	preg_match( '/^.*grep -o .*\|\s*wc -l.*$/m', $ref_src ) === 1,
	'#64: the reference shows the correct `grep -o … | wc -l` counting form on one line'
);
assert_true(
	preg_match( '/^.*grep -c .*$/m', $ref_src ) === 1,
	'#64: and shows the `grep -c` form it is warning against, so the contrast is visible'
);
