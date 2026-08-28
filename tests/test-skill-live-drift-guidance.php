<?php
// SPDX-License-Identifier: MIT
/**
 * Primer guard: the staleness guidance names fields that actually ship (#220).
 *
 * The DiviOps primer skill now tells operators how to answer "which build am I
 * actually talking to?" — a question three sessions in one day could only answer
 * with folklore (counting tools, comparing another session's version string).
 * The answer is `diviops_meta_info`'s `live` block (#215), so the prose names
 * specific response fields.
 *
 * Prose that names a field is a claim about the code. Rename `stale` or drop
 * `code_fingerprint` from `LiveHandshakeReport` and the primer keeps confidently
 * instructing readers to look at something that is no longer there — which is
 * the same failure the guidance exists to prevent, one layer up. So the field
 * list is read out of `diviops-server/src/compatibility.ts` rather than restated
 * here, and every field must appear in the primer as a qualified `live.<field>`
 * code span. The qualification is not decoration: a bare-substring check for
 * `state` passes against the primer's existing "handshake state" prose, so the
 * loose form of this guard reports green while the guidance says nothing.
 *
 * Both checks count what they inspected and assert the count is non-zero: a gate
 * that derives pass/fail only from problems-found passes while inspecting
 * nothing.
 *
 * @package DiviOps
 */

$root        = dirname( __DIR__ );
$primer_file = $root . '/skills/diviops/SKILL.md';
$compat_file = $root . '/diviops-server/src/compatibility.ts';

assert_true( is_file( $primer_file ), 'skills/diviops/SKILL.md exists where this test expects it' );
assert_true( is_file( $compat_file ), 'diviops-server/src/compatibility.ts exists where this test expects it' );

$primer_src = (string) file_get_contents( $primer_file );
$compat_src = (string) file_get_contents( $compat_file );

/*
 * The primer must route the reader to the tool that can answer for itself,
 * rather than to a number they would have to take on faith.
 */
assert_true(
	false !== strpos( $primer_src, 'diviops_meta_info' ),
	'the primer names diviops_meta_info as the way to check the running build'
);

/*
 * Field names come from the shipped interface. Anchored on the `live` block's
 * own type so a rename on either side has to update the other.
 */
$matched = preg_match(
	'/export interface LiveHandshakeReport \{(.*?)\n\}/s',
	$compat_src,
	$interface_match
);
assert_true( 1 === $matched, 'LiveHandshakeReport is declared in compatibility.ts' );

$field_count = preg_match_all( '/^\s*([a-z_]+)\??:/m', $interface_match[1], $field_matches );
assert_true( $field_count > 0, 'LiveHandshakeReport declares at least one field to inspect' );

$missing = array();
foreach ( $field_matches[1] as $field ) {
	if ( false === strpos( $primer_src, '`live.' . $field . '`' ) ) {
		$missing[] = $field;
	}
}
assert_same(
	array(),
	$missing,
	'the primer names every field of the live block it tells readers to read'
);

/*
 * The operator-facing half of the same fact (#220): the handshake is negotiated
 * once at spawn, and an orphaned server process keeps serving it after the
 * client quits. Without that note, "restart the client" is advice that silently
 * fails. Matched on the full phrase — a bare `orphan` collides with the
 * idempotency section's orphan *references*, which are an unrelated subject.
 */
assert_true(
	false !== stripos( $primer_src, 'orphaned server process' ),
	'the primer warns that orphaned server processes survive a client restart'
);
