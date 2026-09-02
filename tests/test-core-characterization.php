<?php
// SPDX-License-Identifier: MIT
/**
 * Characterization of the shared substrate (trait-core.php).
 *
 * Written for the upstream reconciliation triage
 * (https://github.com/rubicon/diviops/issues/328), which measured
 * `trait-core.php` as one of the eleven plugin traits upstream's 2026-08-31
 * sync changes and that merge cleanly. "Merges cleanly" is a statement about
 * text, not about behaviour: this file is what turns a clean merge into a
 * checkable one.
 *
 * `trait-core.php` is 69 functions over 2,441 lines and is the substrate every
 * other capability domain routes through — the block-comment walkers, the
 * response envelope, the marker census that decides whether a full-content
 * write proceeds. Measured before this file was written, 53 of those 69
 * functions were named in no test under `tests/`, including every envelope
 * helper (`envelope_success`, `envelope_error`, `envelope_from_wp_error`,
 * `envelope_from_helper_error`, `envelope_post_load_error`,
 * `envelope_from_content_write_error`, `envelope_object_read_forbidden`,
 * `dry_run_response`, `truncate_envelope_message`), the whole empty-object
 * round-trip guard, and `resolve_content_or_page_id`.
 *
 * These are characterization tests, not correctness tests. They pin what the
 * code does today, quirks and all, so an adoption that moves the behaviour
 * fails loudly instead of passing quietly. Assertions marked `QUIRK:` pin
 * behaviour that is believed to be wrong or trap-shaped; a later deliberate
 * fix to one of those will fail here, and that failure is the point — it wants
 * a human decision, not a silently updated expectation. Nothing in this file
 * is an endorsement.
 *
 * ── How this file relates to the tests it overlaps ──────────────────────
 *
 * `tests/test-block-attr-pseudo-escape.php` (#97) owns the tokenizer trio's
 * ORIGIN story: it proves `find_malformed_block_attr_escape()` no longer
 * false-positives on Divi's own `$variable({...})$` wrapper in either observed
 * live form, and it holds the quadratic-time regression guard. Its direct
 * coverage of the walkers themselves is deliberately thin — one round-trip
 * identity check on `json_string_segments()`, three `variable_token_end()`
 * cases, two `strip_variable_tokens()` cases. This file does not repeat any of
 * that. It pins the walkers' exact OUTPUT SHAPE (the full segment list, not
 * just its concatenation) and drives them with the adversarial inputs that
 * broke the four superseded regex implementations: an escaped quote at a
 * string boundary, an escaped backslash immediately before a real boundary
 * quote, a `-->` sequence inside a JSON string value, a nested-object token
 * payload, a `}` and an escaped `"` inside a token payload's own string, a
 * canonical `<` next to a bare `u003c`, an unterminated string, and a
 * token payload that never balances inside the scan bound.
 *
 * `tests/test-parse-blocks-for-write-coverage.php` (#11, #99) is a STRUCTURAL
 * guard over call sites — it reads method source with Reflection and asserts
 * no write-path function calls bare `parse_blocks()`. It deliberately does not
 * execute anything, because `parse_blocks()` is unshimmed on purpose. This
 * file inherits that constraint: `parse_blocks_for_write()` is not executed
 * here either (see "Deliberately not covered" below), and the block trees fed
 * to the empty-object family are hand-built to match what core's real parser
 * emits rather than produced by calling it.
 *
 * The four existing characterization suites — `test-canvas-characterization.php`,
 * `test-library-characterization.php`, `test-tb-characterization.php`,
 * `test-validate-rule-characterization.php` — each pin one REST-handler domain
 * from the outside. Every one of them routes through helpers defined here:
 * their envelopes are built by `envelope_error()`/`envelope_success()`, their
 * refusals shaped by `envelope_from_helper_error()`. They therefore cover these
 * helpers incidentally, at whatever inputs their own handlers happen to
 * produce, and they assert on the handler's answer rather than the helper's
 * contract. This file covers the helpers directly and at their edges, so a
 * change to a status code or an omitted key is attributed here rather than
 * showing up as four unrelated domain failures. `test-validate-rule-
 * characterization.php` also supplied this file's shape: append-only fixtures,
 * a stated derivation for every non-obvious expected value, and a completeness
 * guard that fails when it inspected nothing.
 *
 * ── Where the expected values come from ─────────────────────────────────
 *
 * Nothing below was produced by running the function and copying its output.
 * Each expected value is derived from one of:
 *
 *   - WordPress core, read at
 *     `wp-includes/class-wp-block-parser.php` and `wp-includes/blocks.php` on
 *     the reference install, and — for the block trees — EXECUTED there:
 *     core's real `WP_Block_Parser` was run over the fixture markup in this
 *     file to obtain the tree shape and the `{}`-collapses-to-`[]` attrs the
 *     empty-object family exists to repair. Cited inline as "core".
 *   - The function's own documented contract, walked by hand over the fixture
 *     byte by byte. Used for the fork-authored walkers, which have no core
 *     equivalent. Cited inline as "hand-walked".
 *   - Arithmetic over the fixture itself (`strlen`, `strpos`), so the
 *     expectation cannot drift from the fixture it describes.
 *
 * ── Deliberately not covered, and what each would need ──────────────────
 *
 *   - `parse_blocks_for_write()`, and everything reached only through it.
 *     `parse_blocks()` is unshimmed ON PURPOSE — several suites
 *     (test-variable-ref-scan-post-types.php, test-global-font-ref-scan-post-
 *     types.php, test-module-fallback-trigger-wiring.php) use "Call to
 *     undefined function parse_blocks()" as a positive probe signal. Defining
 *     it here would silently disarm those probes. Structural coverage lives in
 *     test-parse-blocks-for-write-coverage.php.
 *   - `read_storage_path()`'s `et_divi.<sub>` branch, and therefore
 *     `get_d5_presets()`, `get_d5_presets_with_meta()`, `detect_d5_legacy_path()`,
 *     `d5_preset_write_meta()` and `audit_d5_preset_storage()`. They reach
 *     Divi's `et_get_option()`, which is unshimmed for exactly the same
 *     load-bearing reason (test-variable-ref-scan-post-types.php:140 matches on
 *     its absence). Would need an `et_get_option()` stub with
 *     `et_options_stored_in_one_row()` routing — and that stub must NOT be a
 *     global function definition, so it needs a seam those probe tests can
 *     still see through. The path-list, per-path read, priority probe,
 *     audit-row flattening and shape comparison that those functions compose
 *     ARE covered below, at the top-level-option paths that avoid `et_get_option()`.
 *   - `update_post_content_with_integrity_guard()` — already exercised by
 *     test-module-update-write-safety.php, test-global-layout-write-guard.php
 *     and test-page-duplicate.php.
 *   - `global_layout_wrapper_identities()` / `global_layout_write_refusal_reason()`
 *     / `global_layout_wrapper_drift()` — owned by test-global-layout-write-guard.php.
 *   - `block_opener_is_self_closing()` — owned by
 *     test-scanner-json-aware-terminators.php.
 *   - `invalidate_divi_cache()` — deletes files under `WP_CONTENT_DIR` and
 *     touches post_modified. Would need a filesystem sandbox; running it in
 *     this harness risks deleting real paths.
 *   - `serialize_block_attrs_canonical()`'s no-core fallback branch. The shim
 *     defines `serialize_block_attributes()`, so only the delegating branch is
 *     reachable. The fallback's substitution table was verified by reading core
 *     (`wp-includes/blocks.php:1705-1719`) and is byte-identical; that reading
 *     is recorded here, not asserted, because asserting it would require
 *     un-defining a shim function this file may not touch. This function is
 *     owner-gated: read and characterized, never modified.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/*
 * Every test file runs in one shared process, so anything written to the shim's
 * global stores outlives this file. Snapshot what is touched and put it back at
 * the bottom, so a later suite sees the store it would have seen without this
 * one.
 */
$diviops_core_char_saved_options = $GLOBALS['diviops_test_options'];
$diviops_core_char_saved_posts   = $GLOBALS['diviops_test_posts'];
$diviops_core_char_saved_uned    = $GLOBALS['diviops_test_uneditable_ids'] ?? array();

/** Assertion counter, so the completeness guard at the bottom can prove it ran. */
$diviops_core_char_sections = array();

/**
 * Record that a named section actually executed.
 *
 * The runner counts assertions globally; this records SECTIONS, so the guard at
 * the bottom can fail when a whole area of this file stopped running (an early
 * fatal, a rename, a block commented out) rather than only when an assertion
 * inside one disagrees.
 *
 * @param string $name Section identifier.
 */
function diviops_core_char_section( string $name ): void {
	$GLOBALS['diviops_core_char_sections'][] = $name;
}

/**
 * Body + status of an envelope response, as one comparable array.
 *
 * Every envelope assertion below compares this whole shape rather than probing
 * individual keys, so a key that APPEARS is as visible as a key that changes —
 * the envelope's failure mode is an extra or missing key, not usually a wrong
 * value.
 *
 * @param mixed $response Whatever the helper returned.
 * @return array{status:int,body:mixed}
 */
function diviops_core_char_envelope( $response ): array {
	if ( ! ( $response instanceof WP_REST_Response ) ) {
		return array( 'status' => -1, 'body' => $response );
	}
	return array( 'status' => $response->get_status(), 'body' => $response->get_data() );
}

// ═══════════════════════════════════════════════════════════════════════
// 1. json_string_segments() — the string-boundary walker
// ═══════════════════════════════════════════════════════════════════════
//
// Contract (trait-core.php): split into segments alternating between "inside a
// JSON string literal's content" — the bytes STRICTLY BETWEEN its real,
// unescaped quotes — and "outside one", which carries the structural JSON
// INCLUDING the quote characters themselves. `\X` is always consumed as one
// unit while inside a string.
//
// Every expected segment list below is hand-walked from that contract over the
// fixture's bytes. The concatenation invariant (segments rebuild the input
// exactly) is asserted alongside each, because it is the one property that
// holds for every input and is what makes the walker safe to compose.

$json_simple = '{"a":"b"}';

/*
 * Hand-walked over `{"a":"b"}`:
 *   offsets 0-1  `{"`  structural, and the opening quote belongs to it
 *   offset  2    `a`   in-string: the key's content, quotes excluded
 *   offsets 3-5  `":"` structural: closing quote, colon, opening quote
 *   offset  6    `b`   in-string: the value's content
 *   offsets 7-8  `"}`  structural tail
 */
assert_same(
	array(
		array( 'in_string' => false, 'text' => '{"' ),
		array( 'in_string' => true,  'text' => 'a' ),
		array( 'in_string' => false, 'text' => '":"' ),
		array( 'in_string' => true,  'text' => 'b' ),
		array( 'in_string' => false, 'text' => '"}' ),
	),
	diviops_call( 'json_string_segments', array( $json_simple ) ),
	'json_string_segments(): a two-token object splits into five segments, with each string\'s quotes staying on the structural side'
);

/*
 * ADVERSARIAL — `-->` inside a JSON string value.
 *
 * This is the sequence that terminates a block comment, so a scanner that
 * treats it as a boundary anywhere loses the rest of the attrs. The walker has
 * no notion of `-->` at all; the whole sequence must stay inside the one
 * in-string segment.
 */
$json_terminator_in_string = '{"a":"x-->y"}';
assert_same(
	array(
		array( 'in_string' => false, 'text' => '{"' ),
		array( 'in_string' => true,  'text' => 'a' ),
		array( 'in_string' => false, 'text' => '":"' ),
		array( 'in_string' => true,  'text' => 'x-->y' ),
		array( 'in_string' => false, 'text' => '"}' ),
	),
	diviops_call( 'json_string_segments', array( $json_terminator_in_string ) ),
	'json_string_segments(): a --> sequence inside a string value stays inside that string\'s segment and never opens a new one'
);

/*
 * ADVERSARIAL — escaped quote mid-string.
 *
 * The case that defeated round 2's lookbehind-anchored regex (#97): the `"` in
 * `\"` is not a boundary, so `b\"c` is ONE string's content. Built with chr(92)
 * rather than a typed backslash, following test-block-attr-pseudo-escape.php's
 * precedent — a mistyped backslash here is invisible in a diff.
 */
$bs                    = chr( 92 );
$json_escaped_quote    = '{"a":"b' . $bs . '"c"}';
$segments_escaped      = diviops_call( 'json_string_segments', array( $json_escaped_quote ) );
assert_same(
	array(
		array( 'in_string' => false, 'text' => '{"' ),
		array( 'in_string' => true,  'text' => 'a' ),
		array( 'in_string' => false, 'text' => '":"' ),
		array( 'in_string' => true,  'text' => 'b' . $bs . '"c' ),
		array( 'in_string' => false, 'text' => '"}' ),
	),
	$segments_escaped,
	'json_string_segments(): an escaped quote is consumed as one unit, so b\\"c is a single string segment and not two'
);

/*
 * ADVERSARIAL — backslash PARITY at a boundary.
 *
 * `\\` is an escaped backslash, so the quote that follows it IS a real closing
 * boundary. This is the same byte pattern as the case above with one more
 * backslash, and getting it wrong in the other direction (treating the boundary
 * quote as escaped) runs the string to end-of-input. Hand-walked: the pair at
 * offsets 7-8 is consumed as one unit, leaving offset 9's quote as the close.
 */
$json_escaped_backslash = '{"a":"c' . $bs . $bs . '"}';
assert_same(
	array(
		array( 'in_string' => false, 'text' => '{"' ),
		array( 'in_string' => true,  'text' => 'a' ),
		array( 'in_string' => false, 'text' => '":"' ),
		array( 'in_string' => true,  'text' => 'c' . $bs . $bs ),
		array( 'in_string' => false, 'text' => '"}' ),
	),
	diviops_call( 'json_string_segments', array( $json_escaped_backslash ) ),
	'json_string_segments(): an escaped backslash does not escape the quote after it, so the string still closes at its real boundary'
);

/*
 * ADVERSARIAL — unterminated string. `find_malformed_block_attr_escape()` runs
 * BEFORE the json_decode() validity check in
 * normalize_divi_full_content_for_write(), so the walker is reached with input
 * that is not valid JSON and must degrade predictably rather than throw or
 * loop. Hand-walked: the escape consumes `\"`, nothing closes the string, so
 * the trailing segment is emitted with the open flag still set.
 */
$json_unterminated = '{"a":"c' . $bs . '"}';
assert_same(
	array(
		array( 'in_string' => false, 'text' => '{"' ),
		array( 'in_string' => true,  'text' => 'a' ),
		array( 'in_string' => false, 'text' => '":"' ),
		array( 'in_string' => true,  'text' => 'c' . $bs . '"}' ),
	),
	diviops_call( 'json_string_segments', array( $json_unterminated ) ),
	'json_string_segments(): an unterminated string degrades to a final in_string segment carrying the remainder, not an exception'
);

assert_same(
	array( array( 'in_string' => false, 'text' => '' ) ),
	diviops_call( 'json_string_segments', array( '' ) ),
	'json_string_segments(): empty input still yields exactly one structural segment, so callers can fold unconditionally'
);

/*
 * The concatenation invariant, over every fixture above at once. This is the
 * property the composition in find_malformed_block_attr_escape() depends on:
 * it rebuilds a scannable string from the segments, so any byte the walker
 * drops is a byte that never gets scanned for a pseudo-escape.
 */
foreach (
	array(
		'simple'            => $json_simple,
		'terminator'        => $json_terminator_in_string,
		'escaped_quote'     => $json_escaped_quote,
		'escaped_backslash' => $json_escaped_backslash,
		'unterminated'      => $json_unterminated,
		'unicode_escape'    => '{"a":"' . $bs . 'u003c"}',
		'lone_backslash'    => '{"a":"c' . $bs,
		'empty_object'      => '{}',
	) as $label => $fixture
) {
	$rebuilt = '';
	foreach ( diviops_call( 'json_string_segments', array( $fixture ) ) as $segment ) {
		$rebuilt .= $segment['text'];
	}
	assert_same(
		$fixture,
		$rebuilt,
		"json_string_segments(): concatenating the segments of the {$label} fixture reproduces it byte for byte, so no input byte escapes the scan"
	);
}

diviops_core_char_section( 'json_string_segments' );

// ═══════════════════════════════════════════════════════════════════════
// 2. variable_token_end() — the token-payload brace walker
// ═══════════════════════════════════════════════════════════════════════
//
// Contract: given `$variable({...})$` starting at exactly $start, return the
// index one past its closing `)$`; otherwise null. Brace counting is
// string-aware, and a single scan is bounded by
// DiviOps_Variable_Token_Limits::MAX_SCAN (4096) so an unbalanced payload
// cannot make the caller's outer loop quadratic.
//
// Expected values are `strlen()` of the fixture rather than typed integers:
// the token IS the whole fixture in each positive case, so the expectation
// cannot drift away from the fixture it describes.

// ADVERSARIAL — nested object payload. A depth-1 scanner stops at the inner
// brace and never sees the real close.
$token_nested = '$variable({"a":{"b":1}})$';
assert_same(
	strlen( $token_nested ),
	diviops_call( 'variable_token_end', array( $token_nested, 0 ) ),
	'variable_token_end(): a nested-object payload balances to its own outer brace, not the first inner one'
);

// ADVERSARIAL — a structural-looking `}` inside the payload's own string. The
// docblock names this as the reason the brace count is string-aware.
$token_brace_in_string = '$variable({"a":"}"})$';
assert_same(
	strlen( $token_brace_in_string ),
	diviops_call( 'variable_token_end', array( $token_brace_in_string, 0 ) ),
	'variable_token_end(): a closing brace inside the payload\'s own quoted string is not counted as structural'
);

// ADVERSARIAL — escaped quote inside the payload's string, followed by a brace
// that would close the token early if the escape were mishandled.
$token_escaped_quote = '$variable({"a":"' . $bs . '"}"})$';
assert_same(
	strlen( $token_escaped_quote ),
	diviops_call( 'variable_token_end', array( $token_escaped_quote, 0 ) ),
	'variable_token_end(): an escaped quote inside the payload keeps the string open, so the brace after it stays non-structural'
);

// The scan bound, observed rather than asserted as a constant: a payload that
// WOULD balance, but only past MAX_SCAN bytes, returns null. 5,000 filler bytes
// against a documented 4,096-byte bound.
$token_over_bound = '$variable({' . str_repeat( ' ', 5000 ) . '})$';
assert_same(
	null,
	diviops_call( 'variable_token_end', array( $token_over_bound, 0 ) ),
	'variable_token_end(): a payload whose braces balance only beyond MAX_SCAN is abandoned rather than scanned to the end'
);

// A payload that balances just inside the bound still resolves, so the bound is
// a bound and not an unconditional refusal of large tokens.
$token_under_bound = '$variable({' . str_repeat( ' ', 4000 ) . '})$';
assert_same(
	strlen( $token_under_bound ),
	diviops_call( 'variable_token_end', array( $token_under_bound, 0 ) ),
	'variable_token_end(): a payload that balances inside MAX_SCAN still resolves, so the bound does not reject large legitimate tokens'
);

// Balanced payload, but not closed with `)$`. The trailing `)` alone is not
// enough — this is what stops a token-shaped prefix from swallowing the text
// that follows it.
assert_same(
	null,
	diviops_call( 'variable_token_end', array( '$variable({"a":1})x', 0 ) ),
	'variable_token_end(): a balanced payload closed with ) but not )$ is not a token'
);

assert_same(
	null,
	diviops_call( 'variable_token_end', array( '$variable({"a":1}', 0 ) ),
	'variable_token_end(): a payload that simply runs out of input before balancing returns null'
);

// $start is honoured as an exact anchor, not a search hint.
$token_offset_text = 'lead-in ' . $token_nested . ' trailing';
assert_same(
	strlen( 'lead-in ' ) + strlen( $token_nested ),
	diviops_call( 'variable_token_end', array( $token_offset_text, strlen( 'lead-in ' ) ) ),
	'variable_token_end(): a token starting at a non-zero offset returns an absolute index one past its close'
);
assert_same(
	null,
	diviops_call( 'variable_token_end', array( $token_offset_text, 0 ) ),
	'variable_token_end(): $start is an exact anchor — a token later in the text is not found by scanning forward from 0'
);

diviops_core_char_section( 'variable_token_end' );

// ═══════════════════════════════════════════════════════════════════════
// 3. strip_variable_tokens() — every token, wherever it occurs
// ═══════════════════════════════════════════════════════════════════════
//
// Round 4 of #97: the question is not "is this string exactly one token" but
// "remove every well-formed token and leave everything else". The
// multiple-tokens-with-text-between case is the one that a greedy
// exactly-one-token regex answered wrongly in both directions.

// ADVERSARIAL — two tokens with a genuine pseudo-escape typo between them. The
// typo must survive, or the scan that runs afterwards cannot see it.
$two_tokens_with_typo = 'A' . $token_nested . 'u003c' . $token_brace_in_string . 'B';
assert_same(
	'Au003cB',
	diviops_call( 'strip_variable_tokens', array( $two_tokens_with_typo ) ),
	'strip_variable_tokens(): with two tokens and a typo between them, both tokens go and the typo stays — the round-4 case'
);

assert_same(
	'',
	diviops_call( 'strip_variable_tokens', array( $token_escaped_quote ) ),
	'strip_variable_tokens(): a token whose payload carries an escaped quote is removed whole, leaving nothing behind'
);

// A `$` that begins nothing removable is ordinary text. Includes the
// over-bound token, which variable_token_end() refuses: an abandoned scan must
// leave the text in place, not delete it.
assert_same(
	'cost: $5 and $variable(x)',
	diviops_call( 'strip_variable_tokens', array( 'cost: $5 and $variable(x)' ) ),
	'strip_variable_tokens(): a bare $ and a $variable( that never resolves are both left as ordinary text'
);
assert_same(
	$token_over_bound,
	diviops_call( 'strip_variable_tokens', array( $token_over_bound ) ),
	'strip_variable_tokens(): a token abandoned at the scan bound is left in place rather than partially consumed'
);

diviops_core_char_section( 'strip_variable_tokens' );

// ═══════════════════════════════════════════════════════════════════════
// 4. find_malformed_block_attr_escape() — the trio composed
// ═══════════════════════════════════════════════════════════════════════
//
// The six bytes the scan cares about are the six core's serialize_block_
// attributes() produces: < > & " \ -, read from
// wp-includes/blocks.php:1705-1719 on the reference install. The check fires on
// those six spellings WITHOUT their backslash — a caller who typed the escape
// and forgot the slash.

assert_same(
	null,
	diviops_call( 'find_malformed_block_attr_escape', array( '{"a":"' . $bs . 'u003c"}' ) ),
	'find_malformed_block_attr_escape(): a canonical \\u003c escape is what core emits and is never flagged'
);
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( '{"a":"u003c"}' ) ),
	'find_malformed_block_attr_escape(): the same escape with its backslash missing is flagged, and the matched text is returned'
);

// ADVERSARIAL — both spellings in one attrs blob, canonical first. A scan that
// stops at the first `u00` sequence without checking the preceding byte reports
// the wrong one; a scan whose lookbehind is wrong reports the canonical one.
assert_same(
	'u0026',
	diviops_call( 'find_malformed_block_attr_escape', array( '{"ok":"' . $bs . 'u003c","bad":"u0026"}' ) ),
	'find_malformed_block_attr_escape(): with a canonical escape before a malformed one, the malformed one is what gets reported'
);

// All six spellings, each proven flaggable on its own. u002d is the `-` half of
// the `--` core escapes; a narrowed alternation that dropped it would still
// pass every other case here.
foreach ( array( 'u003c', 'u003e', 'u0026', 'u0022', 'u005c', 'u002d' ) as $pseudo ) {
	assert_same(
		$pseudo,
		diviops_call( 'find_malformed_block_attr_escape', array( '{"a":"' . $pseudo . '"}' ) ),
		"find_malformed_block_attr_escape(): the bare {$pseudo} spelling is one of the six core escapes the scan reports"
	);
}

// JSON escapes are case-insensitive (`<` is the same character as
// `<`), so the malformed spelling is too.
assert_same(
	'U003C',
	diviops_call( 'find_malformed_block_attr_escape', array( '{"a":"U003C"}' ) ),
	'find_malformed_block_attr_escape(): an upper-case pseudo-escape is flagged too, matching JSON\'s own case-insensitive \\u escapes'
);

// ADVERSARIAL — a typo sitting between two tokens inside one string value. This
// is the shape that a "the whole string is one token, so skip it" test misses.
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( '{"grad":"' . $two_tokens_with_typo . '"}' ) ),
	'find_malformed_block_attr_escape(): a typo between two legitimate $variable() tokens in one value is still found'
);

// ADVERSARIAL — a `-->` in the same value as a token. Neither the walker nor
// the token scanner may treat it as a boundary.
assert_same(
	null,
	diviops_call( 'find_malformed_block_attr_escape', array( '{"a":"x-->y' . $token_nested . '"}' ) ),
	'find_malformed_block_attr_escape(): a --> beside a well-formed token yields no finding — the token is still skipped and the rest is clean'
);

/*
 * ADVERSARIAL — the live gcid- reference form, whose payload embeds its quotes
 * as BARE u0022 with no backslash at all, composed with a `-->` and a second
 * token in the same value.
 *
 * The shape matters: this is the only fixture in this file whose SKIPPED region
 * itself contains one of the six flagged spellings, so it is the only one that
 * can observe whether token-stripping happens at all. Without it, every
 * assertion here passes with the strip removed, because the typos they look for
 * live outside the tokens either way. (Found by mutation: an earlier version of
 * this section survived the strip being deleted.)
 *
 * test-block-attr-pseudo-escape.php pins this token form on its own, as the
 * #97 false-positive it was; the composition with a --> and a second token is
 * this file's addition.
 */
$bare_u0022_token = '$variable({u0022typeu0022:u0022coloru0022,u0022valueu0022:{u0022nameu0022:u0022gcid-myuw5vpz20u0022,u0022settingsu0022:{}}})$';
assert_true(
	false !== strpos( $bare_u0022_token, 'u0022' ) && false === strpos( $bare_u0022_token, '"' ),
	'fixture sanity: the bare-form token embeds u0022 spellings and contains no literal quote, so it can only pass the scan by being skipped'
);
assert_same(
	null,
	diviops_call( 'find_malformed_block_attr_escape', array( '{"bg":"' . $bare_u0022_token . '-->' . $token_nested . '"}' ) ),
	'find_malformed_block_attr_escape(): a bare-u0022 token, a --> and a second token in one value produce no finding — every well-formed token is skipped, however many and whatever sits between them'
);

// A pseudo-escape in structural position, outside any string. The composition
// scans structural segments verbatim, so it is reachable there too.
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( '{u003c:1}' ) ),
	'find_malformed_block_attr_escape(): a pseudo-escape outside any string is scanned too, because structural segments pass through unstripped'
);

diviops_core_char_section( 'find_malformed_block_attr_escape' );

// ═══════════════════════════════════════════════════════════════════════
// 5. The marker census and sequence check — what lets a write proceed
// ═══════════════════════════════════════════════════════════════════════

$balanced_markup = '<!-- wp:divi/section --><!-- wp:divi/text /--><!-- /wp:divi/section -->';

/*
 * Hand-walked over the three patterns in divi_content_marker_counts():
 *   openers      `<!--\s+wp:NAME`          matches the section and the text
 *                                          openers; the closer reads `<!-- /wp:`
 *                                          so its `/` blocks the match. => 2
 *   self_closers `...(?:(?!-->).)*?/-->`   matches only the void text block; the
 *                                          section opener's own ` -->` stops the
 *                                          scan before it can reach a later
 *                                          `/-->`. => 1
 *   closers      `<!--\s+/wp:NAME`         => 1
 *   container_openers = max(0, 2 - 1) = 1, which equals closers, so balanced.
 */
assert_same(
	array(
		'openers'           => 2,
		'self_closers'      => 1,
		'container_openers' => 1,
		'closers'           => 1,
	),
	diviops_call( 'divi_content_marker_counts', array( $balanced_markup ) ),
	'divi_content_marker_counts(): a self-closing block counts as an opener and a self-closer, and is excluded from container_openers'
);

assert_same(
	array(
		'openers'           => 0,
		'self_closers'      => 0,
		'container_openers' => 0,
		'closers'           => 0,
	),
	diviops_call( 'divi_content_marker_counts', array( 'plain html, no blocks at all' ) ),
	'divi_content_marker_counts(): content with no block comments counts zero of everything rather than erroring'
);

assert_same(
	array( 'ok' => true ),
	diviops_call( 'validate_divi_marker_sequence', array( $balanced_markup ) ),
	'validate_divi_marker_sequence(): a balanced document reports ok with no other keys, and the self-closer never enters the stack'
);

$unexpected_closer_markup = '<!-- /wp:divi/text -->';
assert_same(
	array(
		'ok'      => false,
		'reason'  => 'unexpected_closer',
		'actual'  => 'divi/text',
		'offset'  => 0,
		'preview' => $unexpected_closer_markup,
	),
	diviops_call( 'validate_divi_marker_sequence', array( $unexpected_closer_markup ) ),
	'validate_divi_marker_sequence(): a closer with an empty stack reports unexpected_closer with the offending type, offset and preview'
);

$mismatched_markup = '<!-- wp:divi/section --><!-- /wp:divi/text -->';
assert_same(
	array(
		'ok'              => false,
		'reason'          => 'mismatched_closer',
		'expected'        => 'divi/section',
		'expected_offset' => 0,
		'actual'          => 'divi/text',
		'offset'          => strpos( $mismatched_markup, '<!-- /wp:' ),
		'preview'         => '<!-- /wp:divi/text -->',
	),
	diviops_call( 'validate_divi_marker_sequence', array( $mismatched_markup ) ),
	'validate_divi_marker_sequence(): a closer for the wrong type reports both sides and both offsets, not just the failure'
);

assert_same(
	array(
		'ok'     => false,
		'reason' => 'unclosed_block',
		'type'   => 'divi/section',
		'offset' => 0,
	),
	diviops_call( 'validate_divi_marker_sequence', array( '<!-- wp:divi/section -->' ) ),
	'validate_divi_marker_sequence(): an opener left on the stack reports unclosed_block naming the innermost one'
);

assert_same(
	true,
	diviops_call( 'assert_divi_full_content_safe_for_write', array( $balanced_markup ) ),
	'assert_divi_full_content_safe_for_write(): balanced markup returns boolean true, not a truthy response object'
);

/*
 * The refusal is read through a tolerant accessor rather than by calling
 * WP_Error methods directly: a regression that made this function RETURN TRUE
 * would otherwise fatal on `true->get_error_code()` and abort the whole file,
 * hiding every assertion after this point behind one PHP error. The reason a
 * caller cares is right here — the counts below BALANCE, so only the sequence
 * check can refuse this document.
 */
$unsafe      = diviops_call( 'assert_divi_full_content_safe_for_write', array( $mismatched_markup, 'layout' ) );
$unsafe_data = is_wp_error( $unsafe ) ? (array) $unsafe->get_error_data() : array();
assert_same(
	array(
		'is_error' => true,
		'code'     => 'invalid_input',
		'status'   => 400,
		'field'    => 'layout',
		'counts'   => array(
			'openers'           => 1,
			'self_closers'      => 0,
			'container_openers' => 1,
			'closers'           => 1,
		),
		'marker'   => 'mismatched_closer',
	),
	array(
		'is_error' => is_wp_error( $unsafe ),
		'code'     => is_wp_error( $unsafe ) ? $unsafe->get_error_code() : null,
		'status'   => $unsafe_data['status'] ?? null,
		'field'    => $unsafe_data['field'] ?? null,
		'counts'   => $unsafe_data['counts'] ?? null,
		'marker'   => $unsafe_data['marker']['reason'] ?? null,
	),
	'assert_divi_full_content_safe_for_write(): a mis-nested document whose marker COUNTS balance is still refused, as invalid_input/400 carrying the caller-named field, the census and the sequence reason'
);

diviops_core_char_section( 'marker_census' );

// ═══════════════════════════════════════════════════════════════════════
// 6. normalize_divi_full_content_for_write() — attr canonicalization
// ═══════════════════════════════════════════════════════════════════════
//
// test-module-update-write-safety.php and test-block-attr-pseudo-escape.php
// cover the happy path and the $variable() false-positive. Pinned here: the
// branches neither of them reaches.

// The whitespace-separated `/ -->` self-close variant. The docblock states the
// consequence of losing it: "every self-closing block re-serializes as an
// opener and the block tree scrambles".
$spaced_self_close = '<!-- wp:divi/text {"a":1} / -->';
assert_same(
	array(
		'ok'      => true,
		'content' => '<!-- wp:divi/text {"a":1} /-->',
		'changed' => 1,
	),
	diviops_call( 'normalize_divi_full_content_for_write', array( $spaced_self_close ) ),
	'normalize_divi_full_content_for_write(): a whitespace-separated / --> self-close is re-emitted as the canonical /--> and still counts as self-closing'
);

// A non-Divi block is left byte-identical. `gravityforms/form` is registered in
// the shim registry with no module category, mirroring what the reference
// install reports for unrelated plugins.
$foreign_block = '<!-- wp:gravityforms/form {"id":"1","raw":"a<b"} -->';
assert_same(
	array( 'ok' => true, 'content' => $foreign_block, 'changed' => 0 ),
	diviops_call( 'normalize_divi_full_content_for_write', array( $foreign_block ) ),
	'normalize_divi_full_content_for_write(): a non-Divi block is passed through untouched, so an unrelated plugin\'s attrs are never rewritten'
);

// A closer is never rewritten even when its name is a Divi one.
assert_same(
	array( 'ok' => true, 'content' => '<!-- /wp:divi/text -->', 'changed' => 0 ),
	diviops_call( 'normalize_divi_full_content_for_write', array( '<!-- /wp:divi/text -->' ) ),
	'normalize_divi_full_content_for_write(): a closing comment is skipped by the is_closer branch rather than re-serialized as an opener'
);

/*
 * Attribute-free openers are canonicalized to single-space spacing — but NOT
 * counted.
 *
 * QUIRK: the empty-tail branch returns its rewritten comment directly, above
 * the `if ( $new_comment !== $matches[0] ) { $changed++; }` that every other
 * rewrite passes through. So a write that genuinely changed bytes reports
 * `changed => 0`. Callers that surface `changed` as "this write normalized N
 * openers" under-report by exactly the number of attribute-free openers whose
 * spacing was fixed.
 */
assert_same(
	array( 'ok' => true, 'content' => '<!-- wp:divi/text -->', 'changed' => 0 ),
	diviops_call( 'normalize_divi_full_content_for_write', array( '<!-- wp:divi/text   -->' ) ),
	'QUIRK normalize_divi_full_content_for_write(): an attribute-free opener with extra spacing IS rewritten to the canonical single space, but the early return skips the counter so changed stays 0'
);

/*
 * QUIRK — a literal `-->` inside an attribute string is reported as "not a JSON
 * object", not as the terminator collision it actually is.
 *
 * The scanner here is a regex whose tail is lazy to the FIRST `-->`, so the
 * match ends inside the JSON string and the captured tail is the truncated
 * `{"a":"x`. That fails the "starts with { and ends with }" shape check, and
 * the caller is told their attributes are not a JSON object — which is false;
 * they are, and the scanner cut them in half.
 *
 * Pinned as-is because it fails CLOSED (the write is refused, not silently
 * truncated) and because canonical serialization can never produce this input:
 * core's serialize_block_attributes() rewrites every `--` to `--`
 * (wp-includes/blocks.php:1712), so only hand-authored raw content reaches it.
 * The message is still wrong, and a fix that reports the real cause will fail
 * this assertion. That failure is the intended signal, not a regression.
 */
$terminator_in_attrs = '<!-- wp:divi/text {"a":"x-->y"} --><!-- /wp:divi/text -->';
$terminator_result   = diviops_call( 'normalize_divi_full_content_for_write', array( $terminator_in_attrs ) );
assert_same(
	array(
		'ok'    => false,
		'error' => array(
			'message' => 'Divi block attributes must be a JSON object.',
			'block'   => 'divi/text',
			'preview' => '{"a":"x',
		),
	),
	$terminator_result,
	'QUIRK normalize_divi_full_content_for_write(): a --> inside an attribute string truncates the scan mid-JSON and is misreported as "not a JSON object" — refused, but for the wrong stated reason'
);

// A genuine non-object tail, for contrast: the same message, but here it is true.
assert_same(
	'Divi block attributes must be a JSON object.',
	diviops_call( 'normalize_divi_full_content_for_write', array( '<!-- wp:divi/text notjson -->' ) )['error']['message'],
	'normalize_divi_full_content_for_write(): a tail that is genuinely not an object is refused with the same message, so the quirk above is indistinguishable from the real case'
);

// Malformed-escape refusal carries the offending escape and a hint.
$pseudo_result = diviops_call( 'normalize_divi_full_content_for_write', array( '<!-- wp:divi/text {"a":"u003c"} -->' ) );
assert_same(
	array( false, 'Divi block attributes contain a malformed JSON unicode escape.', 'divi/text', 'u003c' ),
	array( $pseudo_result['ok'], $pseudo_result['error']['message'], $pseudo_result['error']['block'], $pseudo_result['error']['escape'] ),
	'normalize_divi_full_content_for_write(): a pseudo-escape refusal names the exact escape found, so the caller can locate it'
);

// Invalid JSON that still has the right outer shape reaches json_decode and is
// reported as a JSON error rather than a shape error.
$badjson_result = diviops_call( 'normalize_divi_full_content_for_write', array( '<!-- wp:divi/text {"a":} -->' ) );
assert_same(
	array( false, 'Divi block attributes are not valid JSON.', '{"a":}' ),
	array( $badjson_result['ok'], $badjson_result['error']['message'], $badjson_result['error']['preview'] ),
	'normalize_divi_full_content_for_write(): object-shaped but invalid JSON is reported as a JSON error with the raw tail as preview'
);

diviops_core_char_section( 'normalize_full_content' );

// ═══════════════════════════════════════════════════════════════════════
// 7. serialize_block_attrs_canonical() — owner-gated, read only
// ═══════════════════════════════════════════════════════════════════════
//
// This function and module_update's dot-path merge are owner-gated: read and
// characterized here, never modified. Only the delegating branch is reachable
// under the shim, which defines serialize_block_attributes(). The expected
// string below is derived from core's substitution table
// (wp-includes/blocks.php:1708-1717, read on the reference install), applied by
// hand to the encoded JSON:
//
//   json_encode({"h":"a<b>c&d--e"}, UNESCAPED_SLASHES|UNESCAPED_UNICODE)
//     => {"h":"a<b>c&d--e"}
//   then  <  -> < ,  >  -> > ,  &  -> & ,  -- -> --
//
// test-module-update-write-safety.php asserts only that canonical output
// DIFFERS from plain wp_json_encode; this pins what it differs INTO.
assert_same(
	'{"h":"a' . $bs . 'u003cb' . $bs . 'u003ec' . $bs . 'u0026d' . $bs . 'u002d' . $bs . 'u002de"}',
	diviops_call( 'serialize_block_attrs_canonical', array( json_decode( '{"h":"a<b>c&d--e"}' ) ) ),
	'serialize_block_attrs_canonical(): <, >, & and -- are rewritten to core\'s exact escape spellings from wp-includes/blocks.php'
);

// The reason callers must hand this a stdClass and not an assoc array: an empty
// object survives as `{}` only on the object side.
assert_same(
	'{"decoration":{}}',
	diviops_call( 'serialize_block_attrs_canonical', array( json_decode( '{"decoration":{}}' ) ) ),
	'serialize_block_attrs_canonical(): a stdClass empty object re-encodes as {}, which is why callers decode without assoc'
);
assert_same(
	'{"decoration":[]}',
	diviops_call( 'serialize_block_attrs_canonical', array( json_decode( '{"decoration":{}}', true ) ) ),
	'serialize_block_attrs_canonical(): the same input decoded WITH assoc re-encodes as [], the collapse the empty-object sidecar family exists to repair'
);

diviops_core_char_section( 'serialize_canonical' );

// ═══════════════════════════════════════════════════════════════════════
// 8. The empty-object round-trip guard (#901)
// ═══════════════════════════════════════════════════════════════════════
//
// scan_block_opener_attrs()'s pattern is a verbatim copy of the token grammar
// in WP_Block_Parser::next_token() (wp-includes/class-wp-block-parser.php:248
// on the reference install), so its openers align 1:1 with the preorder of
// parse_blocks() output. Every alignment expectation below was obtained by
// RUNNING core's real WP_Block_Parser over these exact fixtures, not by running
// this scanner:
//
//   '<!-- wp:paragraph -->hi<!-- /wp:paragraph -->'
//        => 1 top-level entry, named core/paragraph          (implicit ns)
//   'lead<!-- wp:divi/text -->x<!-- /wp:divi/text -->tail'
//        => 3 top-level entries, only ONE of them named      (freeform)
//   the nested fixture below
//        => preorder divi/section, divi/text; and core decodes
//           {"s":{}} to ['s' => []] and {"t":{"u":{}}} to ['t' => ['u' => []]]

assert_same(
	array( array( 'name' => 'core/paragraph', 'attrs' => null ) ),
	diviops_call( 'scan_block_opener_attrs', array( '<!-- wp:paragraph -->hi<!-- /wp:paragraph -->' ) ),
	'scan_block_opener_attrs(): an omitted namespace resolves to core/, exactly as WP_Block_Parser::next_token() defaults it'
);

assert_same(
	array(
		array( 'name' => 'divi/section', 'attrs' => '{"s":{}}' ),
		array( 'name' => 'divi/void', 'attrs' => null ),
		array( 'name' => 'divi/text', 'attrs' => '{"t":{"u":{}},"k":1}' ),
	),
	diviops_call(
		'scan_block_opener_attrs',
		array( '<!-- wp:divi/section {"s":{}} --><!-- wp:divi/void /-->lead<!-- wp:divi/text {"t":{"u":{}},"k":1} -->x<!-- /wp:divi/text --><!-- /wp:divi/section -->' )
	),
	'scan_block_opener_attrs(): openers come back in document order with attrs trimmed, self-closing blocks included and closers excluded — the 1:1 alignment with core\'s preorder'
);

assert_same(
	array(),
	diviops_call( 'scan_block_opener_attrs', array( 'no blocks here' ) ),
	'scan_block_opener_attrs(): content with no openers returns an empty list, which enrich_blocks_with_empty_object_paths() treats as "nothing to align"'
);

assert_same(
	'__diviops_empty_object_paths',
	diviops_call( 'empty_object_paths_key' ),
	'empty_object_paths_key(): the sidecar key is the literal serialize_blocks() ignores; renaming it silently disables the guard'
);

// collect_empty_object_paths() operates on an assoc=false decode, where {} is a
// stdClass with no properties and [] is a PHP array.
assert_same(
	array( array( 'a', 'b' ), array( 'c' ) ),
	diviops_call( 'collect_empty_object_paths', array( json_decode( '{"a":{"b":{},"keep":1},"c":{}}' ) ) ),
	'collect_empty_object_paths(): every empty-object path is collected in document order as a key list'
);
assert_same(
	array(),
	diviops_call( 'collect_empty_object_paths', array( json_decode( '{}' ) ) ),
	'collect_empty_object_paths(): depth 0 is excluded — core\'s serialize_blocks() omits empty attrs entirely either way'
);
assert_same(
	array( array( 'list', 0 ) ),
	diviops_call( 'collect_empty_object_paths', array( json_decode( '{"list":[{},{"x":1}]}' ) ) ),
	'collect_empty_object_paths(): an empty object inside a JSON array is recorded with its integer index, not its string form'
);

// restore_empty_objects() puts stdClass back only where the value is STILL an
// empty array — a path a mutator filled keeps whatever the mutator wrote.
$restored = diviops_call(
	'restore_empty_objects',
	array( array( 'a' => array( 'b' => array() ), 'c' => array( 'filled' => 1 ) ), array( array( 'a', 'b' ), array( 'c' ) ) )
);
assert_true(
	$restored['a']['b'] instanceof stdClass,
	'restore_empty_objects(): a surviving empty array at a recorded path becomes stdClass again, so it re-encodes as {}'
);
assert_same(
	array( 'filled' => 1 ),
	$restored['c'],
	'restore_empty_objects(): a recorded path a mutator filled with real data is left alone rather than blanked back to {}'
);
assert_same(
	array( 'a' => 1 ),
	diviops_call( 'restore_empty_objects', array( array( 'a' => 1 ), array( array( 'gone', 'missing' ), array(), 'not-a-path' ) ) ),
	'restore_empty_objects(): a path invalidated by mutation, an empty path and a non-array path are all skipped without error'
);

/*
 * The enrich/restore round trip, on the tree core's own parser produced for
 * this markup (var_export of WP_Block_Parser::parse(), reference install):
 * `{"s":{}}` arrives as `['s' => []]` and `{"t":{"u":{}}}` as
 * `['t' => ['u' => []]]`. That collapse IS the bug this family repairs, so the
 * fixture has to carry it rather than an idealised tree.
 */
$core_tree = array(
	array(
		'blockName'    => 'divi/section',
		'attrs'        => array( 's' => array() ),
		'innerBlocks'  => array(
			array(
				'blockName'    => 'divi/text',
				'attrs'        => array( 't' => array( 'u' => array() ), 'k' => 1 ),
				'innerBlocks'  => array(),
				'innerHTML'    => 'x',
				'innerContent' => array( 'x' ),
			),
		),
		'innerHTML'    => '',
		'innerContent' => array( null ),
	),
);
$core_markup = '<!-- wp:divi/section {"s":{}} --><!-- wp:divi/text {"t":{"u":{}},"k":1} -->x<!-- /wp:divi/text --><!-- /wp:divi/section -->';

$enriched = diviops_call( 'enrich_blocks_with_empty_object_paths', array( $core_tree, $core_markup ) );
assert_same(
	array( array( 's' ) ),
	$enriched[0]['__diviops_empty_object_paths'],
	'enrich_blocks_with_empty_object_paths(): the outer block gets a sidecar naming its own collapsed path'
);
assert_same(
	array( array( 't', 'u' ) ),
	$enriched[0]['innerBlocks'][0]['__diviops_empty_object_paths'],
	'enrich_blocks_with_empty_object_paths(): the preorder walk reaches inner blocks and pairs each with its own opener token'
);

/*
 * Alignment failure falls back to the unenriched tree — today's lossy
 * behaviour, never worse.
 *
 * The mismatch fixture keeps the SAME NUMBER of openers as the tree has named
 * blocks and differs only in the first one's NAME, so the name check is the only
 * thing that can catch it. A shorter fixture does not test what it appears to:
 * with fewer openers than blocks, the walk aborts on the missing-index check
 * instead and the assertion passes even with the name comparison deleted.
 * (Found by mutation: an earlier fixture here survived exactly that deletion.)
 */
$misnamed_markup = '<!-- wp:divi/other {"s":{}} --><!-- wp:divi/text {"t":{"u":{}},"k":1} -->x<!-- /wp:divi/text --><!-- /wp:divi/other -->';
assert_same(
	2,
	count( diviops_call( 'scan_block_opener_attrs', array( $misnamed_markup ) ) ),
	'fixture sanity: the mismatch markup yields exactly as many openers as the tree has named blocks, so only the name comparison can reject it'
);
assert_same(
	$core_tree,
	diviops_call( 'enrich_blocks_with_empty_object_paths', array( $core_tree, $misnamed_markup ) ),
	'enrich_blocks_with_empty_object_paths(): a name mismatch at any position abandons enrichment and returns the original tree untouched'
);
assert_same(
	$core_tree,
	diviops_call( 'enrich_blocks_with_empty_object_paths', array( $core_tree, $core_markup . '<!-- wp:divi/extra /-->' ) ),
	'enrich_blocks_with_empty_object_paths(): more openers than blocks is also treated as misalignment, not as a partial success'
);

$restored_tree = diviops_call( 'restore_blocks_empty_objects', array( $enriched ) );
assert_true(
	! array_key_exists( '__diviops_empty_object_paths', $restored_tree[0] )
		&& ! array_key_exists( '__diviops_empty_object_paths', $restored_tree[0]['innerBlocks'][0] ),
	'restore_blocks_empty_objects(): the sidecar is stripped at every depth before serialization, so it can never reach stored markup'
);
assert_true(
	$restored_tree[0]['attrs']['s'] instanceof stdClass
		&& $restored_tree[0]['innerBlocks'][0]['attrs']['t']['u'] instanceof stdClass,
	'restore_blocks_empty_objects(): both collapsed positions are stdClass again, so wp_json_encode re-emits {} rather than []'
);
assert_same(
	1,
	$restored_tree[0]['innerBlocks'][0]['attrs']['k'],
	'restore_blocks_empty_objects(): sibling attrs the guard never recorded are carried through unchanged'
);

diviops_core_char_section( 'empty_object_guard' );

// ═══════════════════════════════════════════════════════════════════════
// 9. Envelope shapes and status propagation
// ═══════════════════════════════════════════════════════════════════════
//
// Documented contract (trait-core.php): success is `{ok:true, data:<payload>}`,
// error is `{ok:false, error:{code, message, hint?}}`, and HTTP status stays
// orthogonal to envelope shape — 200 on ok:true, the real upstream status on
// ok:false.

assert_same(
	array( 'status' => 200, 'body' => array( 'ok' => true, 'data' => array( 'x' => 1 ) ) ),
	diviops_core_char_envelope( diviops_call( 'envelope_success', array( array( 'x' => 1 ) ) ) ),
	'envelope_success(): a plain payload is wrapped as {ok:true,data} at status 200'
);
assert_same(
	array( 'status' => 201, 'body' => array( 'ok' => true, 'data' => null ) ),
	diviops_core_char_envelope( diviops_call( 'envelope_success', array( null, 201 ) ) ),
	'envelope_success(): a null payload is still wrapped, and a caller-supplied status is used verbatim'
);
assert_same(
	array( 'status' => 200, 'body' => array( 'ok' => true, 'data' => 'already' ) ),
	diviops_core_char_envelope( diviops_call( 'envelope_success', array( array( 'ok' => true, 'data' => 'already' ) ) ) ),
	'envelope_success(): an already-enveloped payload passes through instead of being wrapped twice'
);

/*
 * QUIRK — the double-wrap guard is exact-shape, so it recognises only a
 * two-key envelope. A caller that hands over `{ok, data, _meta}` — the shape
 * attach_meta() produces — gets it wrapped as the DATA of a new envelope, and
 * the `_meta` disappears a level down where no consumer looks for it.
 *
 * Pinned because it is a live trap, not because it is right. No current caller
 * does this (attach_meta() runs on the RESPONSE, after envelope_success()), but
 * the guard reads as "don't double-wrap" and behaves as "don't double-wrap
 * exactly these two keys".
 */
assert_same(
	array(
		'status' => 200,
		'body'   => array(
			'ok'   => true,
			'data' => array( 'ok' => true, 'data' => 'x', '_meta' => array( 'a' => 1 ) ),
		),
	),
	diviops_core_char_envelope( diviops_call( 'envelope_success', array( array( 'ok' => true, 'data' => 'x', '_meta' => array( 'a' => 1 ) ) ) ) ),
	'QUIRK envelope_success(): an enveloped payload carrying a third key is NOT recognised and gets wrapped a second time, burying it under data'
);
assert_same(
	array( 'status' => 200, 'body' => array( 'ok' => true, 'data' => array( 'ok' => 1, 'data' => 'x' ) ) ),
	diviops_core_char_envelope( diviops_call( 'envelope_success', array( array( 'ok' => 1, 'data' => 'x' ) ) ) ),
	'envelope_success(): the passthrough requires ok to be boolean true, so a truthy 1 is wrapped rather than recognised'
);

assert_same(
	array( 'status' => 400, 'body' => array( 'ok' => false, 'error' => array( 'code' => 'invalid_input', 'message' => 'nope' ) ) ),
	diviops_core_char_envelope( diviops_call( 'envelope_error', array( 'invalid_input', 'nope' ) ) ),
	'envelope_error(): code and message only, defaulting to status 400, with no hint or data key present'
);
assert_same(
	array( 'status' => 400, 'body' => array( 'ok' => false, 'error' => array( 'code' => 'c', 'message' => 'm' ) ) ),
	diviops_core_char_envelope( diviops_call( 'envelope_error', array( 'c', 'm', '' ) ) ),
	'envelope_error(): an empty-string hint is omitted rather than emitted as an empty key'
);
assert_same(
	array( 'status' => 409, 'body' => array( 'ok' => false, 'error' => array( 'code' => 'c', 'message' => 'm', 'hint' => 'h', 'data' => array() ) ) ),
	diviops_core_char_envelope( diviops_call( 'envelope_error', array( 'c', 'm', 'h', 409, array() ) ) ),
	'envelope_error(): an EMPTY ARRAY data payload is attached — the test is null-ness, not emptiness, so error.data can be present and empty'
);
assert_same(
	array( 'status' => 400, 'body' => array( 'ok' => false, 'error' => array( 'code' => 'c', 'message' => 'm', 'data' => false ) ) ),
	diviops_core_char_envelope( diviops_call( 'envelope_error', array( 'c', 'm', null, 400, false ) ) ),
	'envelope_error(): a literal false data payload is attached too, for the same reason'
);

assert_same(
	array( 'status' => 403, 'body' => array(
		'ok'    => false,
		'error' => array(
			'code'    => 'forbidden',
			'message' => 'Cannot inspect module #42.',
			'hint'    => 'Authenticate as a user with edit_post capability for this object.',
			'data'    => array( 'target_kind' => 'module', 'post_id' => 42 ),
		),
	) ),
	diviops_core_char_envelope( diviops_call( 'envelope_object_read_forbidden', array( 42, 'module' ) ) ),
	'envelope_object_read_forbidden(): the canonical row-level read denial is a 403 forbidden carrying the target kind and id'
);

// envelope_from_wp_error(): code, message and hint come from the WP_Error;
// status falls back to 500, and error data other than hint/status is dropped.
$wp_error_full = new WP_Error( 'preset.locked', 'Preset is locked.', array( 'status' => 409, 'hint' => 'Unlock it first.', 'extra' => 'dropped' ) );
assert_same(
	array( 'status' => 409, 'body' => array(
		'ok'    => false,
		'error' => array( 'code' => 'preset.locked', 'message' => 'Preset is locked.', 'hint' => 'Unlock it first.' ),
	) ),
	diviops_core_char_envelope( diviops_call( 'envelope_from_wp_error', array( $wp_error_full ) ) ),
	'envelope_from_wp_error(): the upstream code survives verbatim, hint and status are lifted out, and every other data key is dropped'
);
assert_same(
	array( 'status' => 500, 'body' => array( 'ok' => false, 'error' => array( 'code' => 'boom', 'message' => 'bang' ) ) ),
	diviops_core_char_envelope( diviops_call( 'envelope_from_wp_error', array( new WP_Error( 'boom', 'bang' ) ) ) ),
	'envelope_from_wp_error(): a WP_Error with no data defaults to 500, treating an unclassified failure as server-side'
);
assert_same(
	'wp_error',
	diviops_call( 'envelope_from_wp_error', array( new WP_Error() ) )->get_data()['error']['code'],
	'envelope_from_wp_error(): a codeless WP_Error falls back to the wp_error vocabulary code rather than emitting an empty one'
);

// envelope_from_content_write_error(): same lift, but the REMAINING data is
// preserved — these errors carry the corruption diagnostics.
$write_error = new WP_Error(
	'page.content_write_corruption',
	'Refused page write.',
	array( 'status' => 500, 'hint' => 'Inspect first.', 'bytes' => array( 'expected' => 10 ), 'issues' => array( array( 'type' => 'byte_mismatch' ) ) )
);
assert_same(
	array( 'status' => 500, 'body' => array(
		'ok'    => false,
		'error' => array(
			'code'    => 'page.content_write_corruption',
			'message' => 'Refused page write.',
			'hint'    => 'Inspect first.',
			'data'    => array( 'bytes' => array( 'expected' => 10 ), 'issues' => array( array( 'type' => 'byte_mismatch' ) ) ),
		),
	) ),
	diviops_core_char_envelope( diviops_call( 'envelope_from_content_write_error', array( $write_error ) ) ),
	'envelope_from_content_write_error(): status and hint are lifted out of data and the diagnostics that remain are attached under error.data'
);
assert_same(
	array( 'status' => 500, 'body' => array( 'ok' => false, 'error' => array( 'code' => 'c', 'message' => 'm', 'hint' => 'h' ) ) ),
	diviops_core_char_envelope( diviops_call( 'envelope_from_content_write_error', array( new WP_Error( 'c', 'm', array( 'hint' => 'h' ) ) ) ) ),
	'envelope_from_content_write_error(): when lifting status and hint empties the data, no empty error.data key is emitted'
);
assert_same(
	array( 'status' => 500, 'body' => array( 'ok' => false, 'error' => array( 'code' => 'c', 'message' => 'm' ) ) ),
	diviops_core_char_envelope( diviops_call( 'envelope_from_content_write_error', array( new WP_Error( 'c', 'm', 'a string, not an array' ) ) ) ),
	'envelope_from_content_write_error(): non-array error data is discarded rather than attached raw'
);

diviops_core_char_section( 'envelope_core' );

// ── envelope_from_helper_error(): the three code families ──────────────

foreach ( array( 'module_not_found', 'block_not_found', 'section_not_found', 'no_match' ) as $miss_code ) {
	assert_same(
		array( 'status' => 404, 'body' => array(
			'ok'    => false,
			'error' => array(
				'code'    => 'not_found',
				'message' => 'nothing there',
				'hint'    => 'Use diviops_page_get_layout to verify available admin labels and auto_index targets.',
				'data'    => array(
					'target_kind'  => 'module',
					'target_mode'  => 'admin_label',
					'target_value' => 'Hero',
					'page_id'      => 7,
					'context'      => 'section 2',
				),
			),
		) ),
		diviops_core_char_envelope(
			diviops_call(
				'envelope_from_helper_error',
				array(
					new WP_Error( $miss_code, 'nothing there', array( 'target_mode' => 'admin_label', 'target_value' => 'Hero', 'context' => 'section 2' ) ),
					'module',
					7,
				)
			)
		),
		"envelope_from_helper_error(): {$miss_code} collapses onto not_found/404 with the target discriminators and page id in a fixed key order"
	);
}

assert_same(
	array( 'target_kind' => 'section' ),
	diviops_call( 'envelope_from_helper_error', array( new WP_Error( 'section_not_found', 'gone' ), 'section', 0 ) )->get_data()['error']['data'],
	'envelope_from_helper_error(): with no helper data and page_id 0, only target_kind is emitted — optional discriminators are omitted, not nulled'
);

foreach ( array( 'missing_target', 'ambiguous_target', 'unsupported_selector', 'invalid_auto_index' ) as $field_code ) {
	assert_same(
		array( 'status' => 400, 'body' => array(
			'ok'    => false,
			'error' => array(
				'code'    => 'invalid_input',
				'message' => 'bad request',
				'data'    => array( 'reason' => $field_code, 'context' => 'ctx', 'fields_provided' => array( 'a', 'b' ) ),
			),
		) ),
		diviops_core_char_envelope(
			diviops_call(
				'envelope_from_helper_error',
				array(
					new WP_Error( $field_code, 'bad request', array( 'context' => 'ctx', 'fields_provided' => array( 'x' => 'a', 'y' => 'b' ) ) ),
					'module',
					9,
				)
			)
		),
		"envelope_from_helper_error(): {$field_code} collapses onto invalid_input/400 with reason, and fields_provided is reindexed to a list — no hint, and page_id is NOT forwarded for this family"
	);
}

assert_same(
	array( 'status' => 400, 'body' => array(
		'ok'    => false,
		'error' => array(
			'code'    => 'invalid_input',
			'message' => 'only 2 matches',
			'data'    => array(
				'reason'        => 'invalid_occurrence',
				'field'         => 'occurrence',
				'target_kind'   => 'module',
				'target_mode'   => 'block_type',
				'target_value'  => 'divi/text',
				'received'      => 5,
				'total_matches' => 2,
			),
		),
	) ),
	diviops_core_char_envelope(
		diviops_call(
			'envelope_from_helper_error',
			array(
				new WP_Error( 'invalid_occurrence', 'only 2 matches', array( 'target_mode' => 'block_type', 'target_value' => 'divi/text', 'received' => 5, 'total_matches' => 2 ) ),
				'module',
				9,
			)
		)
	),
	'envelope_from_helper_error(): invalid_occurrence adds the occurrence-specific fields on top of the field-level shape, symmetric with the direct module_update path'
);

assert_same(
	array( 'status' => 500, 'body' => array(
		'ok'    => false,
		'error' => array(
			'code'    => 'divi_error',
			'message' => 'could not parse',
			'hint'    => 'Re-save the page through the Visual Builder to regenerate canonical block markup.',
			'data'    => array( 'page_id' => 11 ),
		),
	) ),
	diviops_core_char_envelope( diviops_call( 'envelope_from_helper_error', array( new WP_Error( 'parse_error', 'could not parse' ), 'module', 11 ) ) ),
	'envelope_from_helper_error(): parse_error becomes divi_error at 500 — a malformed stored document is treated as server-side, not caller error'
);
assert_true(
	! array_key_exists( 'data', diviops_call( 'envelope_from_helper_error', array( new WP_Error( 'parse_error', 'x' ), 'module', 0 ) )->get_data()['error'] ),
	'envelope_from_helper_error(): parse_error with no page id omits error.data entirely rather than emitting page_id 0'
);
assert_same(
	array( 'status' => 403, 'body' => array( 'ok' => false, 'error' => array( 'code' => 'forbidden', 'message' => 'no' ) ) ),
	diviops_core_char_envelope( diviops_call( 'envelope_from_helper_error', array( new WP_Error( 'forbidden', 'no', array( 'status' => 403 ) ), 'module', 3 ) ) ),
	'envelope_from_helper_error(): a code outside all three families falls through to the generic adapter, preserving forbidden as distinct from capability_missing'
);

// envelope_post_load_error(): the shared module_lock/unlock/clone post-load
// normalization, whose messages are built from the page id rather than the
// helper's own message.
assert_same(
	array( 'status' => 404, 'body' => array(
		'ok'    => false,
		'error' => array(
			'code'    => 'not_found',
			'message' => 'Page #55 not found.',
			'hint'    => 'Verify the page id via diviops_page_list.',
			'data'    => array( 'target_kind' => 'page', 'page_id' => 55 ),
		),
	) ),
	diviops_core_char_envelope( diviops_call( 'envelope_post_load_error', array( new WP_Error( 'not_found', 'ignored upstream text' ), 55 ) ) ),
	'envelope_post_load_error(): not_found is rewritten with its own page-id message, discarding the helper\'s wording'
);
assert_same(
	array( 'status' => 403, 'body' => array(
		'ok'    => false,
		'error' => array(
			'code'    => 'forbidden',
			'message' => 'Cannot edit page #55.',
			'hint'    => 'Authenticate as a user with edit rights to this post.',
			'data'    => array( 'page_id' => 55 ),
		),
	) ),
	diviops_core_char_envelope( diviops_call( 'envelope_post_load_error', array( new WP_Error( 'forbidden', 'ignored' ), 55 ) ) ),
	'envelope_post_load_error(): forbidden is a 403 with the page id but no target_kind, unlike the not_found shape'
);
assert_same(
	'other',
	diviops_call( 'envelope_post_load_error', array( new WP_Error( 'other', 'x' ), 55 ) )->get_data()['error']['code'],
	'envelope_post_load_error(): any other code falls through to envelope_from_wp_error with its slug intact'
);

diviops_core_char_section( 'envelope_adapters' );

// ── truncate_envelope_message(): multibyte-safe capping ────────────────
//
// The fixture is deliberately multibyte: 'é' is two bytes and one character, so
// a byte-based implementation sees length 20 where a character-based one sees
// 10. That difference is the whole point of the function.

$accented = str_repeat( 'é', 10 );
assert_same(
	20,
	strlen( $accented ),
	'fixture sanity: the accented fixture is 20 bytes, so a byte-based cap and a character-based cap cannot agree on it'
);
assert_same(
	$accented,
	diviops_call( 'truncate_envelope_message', array( $accented, 10 ) ),
	'truncate_envelope_message(): a message exactly $max CHARACTERS long is returned unchanged, though it is twice $max bytes'
);
assert_same(
	str_repeat( 'é', 9 ) . '…',
	diviops_call( 'truncate_envelope_message', array( $accented, 9 ) ),
	'truncate_envelope_message(): truncation cuts on a character boundary and appends the ellipsis, never splitting a codepoint'
);
assert_same(
	'short',
	diviops_call( 'truncate_envelope_message', array( 'short' ) ),
	'truncate_envelope_message(): a message under the 500-character default is returned as-is'
);
assert_same(
	501,
	mb_strlen( diviops_call( 'truncate_envelope_message', array( str_repeat( 'a', 600 ) ) ) ),
	'truncate_envelope_message(): the default cap yields 500 characters plus the one-character ellipsis'
);

diviops_core_char_section( 'truncate_message' );

// ── dry_run_response(): the uniform plan slot ──────────────────────────

assert_same(
	array( 'status' => 200, 'body' => array(
		'ok'   => true,
		'data' => array(
			'dry_run' => true,
			'plan'    => array( 'summary' => 'Would update 1 module.', 'changes' => array( array( 'kind' => 'attr' ) ) ),
		),
	) ),
	diviops_core_char_envelope( diviops_call( 'dry_run_response', array( 'Would update 1 module.', array( array( 'kind' => 'attr' ) ) ) ) ),
	'dry_run_response(): the plan carries summary and changes only — warnings is omitted, not emitted empty'
);
assert_same(
	array( 'summary' => 'x', 'changes' => array( array( 'kind' => 'a' ) ), 'warnings' => array( 'careful' ) ),
	diviops_call( 'dry_run_response', array( 'x', array( 7 => array( 'kind' => 'a' ) ), array( 3 => 'careful' ) ) )->get_data()['data']['plan'],
	'dry_run_response(): changes and warnings are reindexed to lists, so a sparse caller array cannot serialize as a JSON object'
);
assert_same(
	array( 'dry_run' => true, 'plan' => array( 'summary' => '', 'changes' => array() ), 'preview' => 'p' ),
	diviops_call( 'dry_run_response', array( '', array(), array(), array( 'preview' => 'p' ) ) )->get_data()['data'],
	'dry_run_response(): extra keys are merged alongside dry_run and plan rather than nested inside the plan'
);

/*
 * QUIRK — $extra is merged SECOND, so an extra key named `plan` or `dry_run`
 * silently replaces the plan slot the docblock calls uniform ("the plan slot
 * itself stays uniform so callers can pattern-match without per-tool
 * branching"). No caller does this today; the guarantee is a convention, not
 * an enforcement.
 */
assert_same(
	array( 'dry_run' => true, 'plan' => 'clobbered' ),
	diviops_call( 'dry_run_response', array( 'real summary', array(), array(), array( 'plan' => 'clobbered' ) ) )->get_data()['data'],
	'QUIRK dry_run_response(): an $extra key named plan overwrites the plan it just built, so the "uniform plan slot" guarantee is not enforced'
);

diviops_core_char_section( 'dry_run' );

// ═══════════════════════════════════════════════════════════════════════
// 10. resolve_content_or_page_id() — the exactly-one-of input gate
// ═══════════════════════════════════════════════════════════════════════

/**
 * Build a request carrying only the params a case actually supplies.
 *
 * @param array $params Params to set.
 * @return WP_REST_Request
 */
function diviops_core_char_request( array $params ): WP_REST_Request {
	$request = new WP_REST_Request( 'POST', '/diviops/v1/validate/blocks' );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return $request;
}

assert_same(
	'<!-- wp:divi/text /-->',
	diviops_call( 'resolve_content_or_page_id', array( diviops_core_char_request( array( 'content' => '<!-- wp:divi/text /-->' ) ) ) ),
	'resolve_content_or_page_id(): an inline content string is returned as-is, so the caller gets a string not an envelope'
);
assert_same(
	'',
	diviops_call( 'resolve_content_or_page_id', array( diviops_core_char_request( array( 'content' => '' ) ) ) ),
	'resolve_content_or_page_id(): an EMPTY content string still counts as supplied — the test is is_string, not truthiness'
);
assert_same(
	array( 'status' => 400, 'body' => array(
		'ok'    => false,
		'error' => array(
			'code'    => 'invalid_input',
			'message' => 'Provide exactly one of {content, page_id}, not both.',
			'hint'    => 'Use `content` for ad-hoc/pre-save markup; use `page_id` to read post_content from the DB.',
		),
	) ),
	diviops_core_char_envelope( diviops_call( 'resolve_content_or_page_id', array( diviops_core_char_request( array( 'content' => 'x', 'page_id' => 1 ) ) ) ) ),
	'resolve_content_or_page_id(): supplying both is refused before either is looked at, with the both-supplied wording'
);
assert_same(
	array( 'status' => 400, 'body' => array(
		'ok'    => false,
		'error' => array( 'code' => 'invalid_input', 'message' => 'Provide exactly one of {content, page_id}.' ),
	) ),
	diviops_core_char_envelope( diviops_call( 'resolve_content_or_page_id', array( diviops_core_char_request( array() ) ) ) ),
	'resolve_content_or_page_id(): supplying neither is refused with the shorter wording and no hint'
);
assert_same(
	array( 'status' => 400, 'body' => array(
		'ok'    => false,
		'error' => array( 'code' => 'invalid_input', 'message' => 'page_id must be a positive integer.' ),
	) ),
	diviops_core_char_envelope( diviops_call( 'resolve_content_or_page_id', array( diviops_core_char_request( array( 'page_id' => 0 ) ) ) ) ),
	'resolve_content_or_page_id(): page_id 0 is rejected as non-positive rather than looked up'
);

$GLOBALS['diviops_test_posts']          = array();
$GLOBALS['diviops_test_uneditable_ids'] = array( 4242, 4343 );
diviops_test_register_post( 4343, 'stored markup', 'page', 'Locked page' );
diviops_test_register_post( 4444, 'readable markup', 'page', 'Open page' );

assert_same(
	'readable markup',
	diviops_call( 'resolve_content_or_page_id', array( diviops_core_char_request( array( 'page_id' => 4444 ) ) ) ),
	'resolve_content_or_page_id(): an existing editable page resolves to its stored post_content'
);

/*
 * The ordering fixture. Post 4242 is BOTH absent and listed as uneditable, so
 * the two orderings give visibly different answers: existence-first (today)
 * reports not_found; capability-first reports forbidden and hides the real
 * cause. The docblock states this ordering explicitly, and a page that does not
 * exist fails current_user_can('edit_post') anyway — which is exactly why a
 * fixture that is only absent cannot tell the two orderings apart.
 */
assert_same(
	array( 'status' => 404, 'body' => array(
		'ok'    => false,
		'error' => array( 'code' => 'not_found', 'message' => 'Post 4242 not found.' ),
	) ),
	diviops_core_char_envelope( diviops_call( 'resolve_content_or_page_id', array( diviops_core_char_request( array( 'page_id' => 4242 ) ) ) ) ),
	'resolve_content_or_page_id(): a page that is both missing and uneditable reports not_found — existence is resolved before capability, so a missing page never masquerades as an auth failure'
);
assert_same(
	array( 'status' => 403, 'body' => array(
		'ok'    => false,
		'error' => array(
			'code'    => 'forbidden',
			'message' => 'Cannot edit post 4343.',
			'hint'    => 'The current user does not have edit_post capability for this page.',
		),
	) ),
	diviops_core_char_envelope( diviops_call( 'resolve_content_or_page_id', array( diviops_core_char_request( array( 'page_id' => 4343 ) ) ) ) ),
	'resolve_content_or_page_id(): a page that exists but is not editable reports forbidden, the only case that should'
);

diviops_core_char_section( 'resolve_content_or_page_id' );

// ═══════════════════════════════════════════════════════════════════════
// 11. Row-level inspection helpers
// ═══════════════════════════════════════════════════════════════════════

assert_same( 4444, diviops_call( 'post_object_id', array( get_post( 4444 ) ) ), 'post_object_id(): a post object resolves through its ID property' );
assert_same( 0, diviops_call( 'post_object_id', array( new stdClass() ) ), 'post_object_id(): an object with no ID resolves to 0, which every caller treats as "not a post"' );
assert_same( 7, diviops_call( 'post_object_id', array( '-7' ) ), 'post_object_id(): a scalar goes through absint, so a negative id becomes its magnitude rather than staying negative' );

assert_true( diviops_call( 'can_inspect_post_object', array( 4444 ) ), 'can_inspect_post_object(): an editable post id passes' );
assert_true( ! diviops_call( 'can_inspect_post_object', array( 4343 ) ), 'can_inspect_post_object(): a post the current user cannot edit is refused' );
assert_true( ! diviops_call( 'can_inspect_post_object', array( 0 ) ), 'can_inspect_post_object(): id 0 is refused on the id check alone, before any capability call' );

/*
 * QUIRK: no de-duplication. The same post supplied twice — once as an id, once
 * as an object, the two shapes this helper accepts — comes back twice. Its only
 * caller, query_inspectable_post_ids(), feeds it one WP_Query page at a time so
 * cannot currently produce a duplicate, but the returned list is what that
 * function reports as `total`, so a duplicate would inflate the pagination
 * count as well as the page.
 */
assert_same(
	array( 4444, 4444 ),
	diviops_call( 'filter_inspectable_post_objects', array( array( 4444, 4343, 999999, get_post( 4444 ) ) ) ),
	'QUIRK filter_inspectable_post_objects(): uneditable and nonexistent rows are dropped, but the same post passed twice (as id and as object) is returned twice — there is no de-duplication'
);

assert_same( 1, diviops_call( 'get_request_page', array( diviops_core_char_request( array() ) ) ), 'get_request_page(): a missing page param floors to 1' );
assert_same( 1, diviops_call( 'get_request_page', array( diviops_core_char_request( array( 'page' => 0 ) ) ) ), 'get_request_page(): page 0 floors to 1' );
assert_same( 2, diviops_call( 'get_request_page', array( diviops_core_char_request( array( 'page' => '-2' ) ) ) ), 'QUIRK get_request_page(): a negative page goes through absint before the floor, so -2 becomes page 2 rather than page 1' );
assert_same( 3, diviops_call( 'get_request_page', array( diviops_core_char_request( array( 'page' => '3' ) ) ) ), 'get_request_page(): a numeric string page is cast to int' );

diviops_core_char_section( 'inspection_helpers' );

// ═══════════════════════════════════════════════════════════════════════
// 12. attach_meta()
// ═══════════════════════════════════════════════════════════════════════

$meta_response = diviops_call( 'attach_meta', array( diviops_call( 'envelope_success', array( array( 'x' => 1 ) ) ), array( 'canonical_path' => 'et_divi_builder_global_presets_d5' ) ) );
assert_same(
	array( 'ok' => true, 'data' => array( 'x' => 1 ), '_meta' => array( 'canonical_path' => 'et_divi_builder_global_presets_d5' ) ),
	$meta_response->get_data(),
	'attach_meta(): _meta lands beside ok and data at the top level of the envelope, not inside data'
);
assert_same(
	array( 'ok' => true, 'data' => 1, '_meta' => array( 'keep' => 'me', 'idempotent' => false, 'new' => 1 ) ),
	diviops_call( 'attach_meta', array( new WP_REST_Response( array( 'ok' => true, 'data' => 1, '_meta' => array( 'keep' => 'me', 'idempotent' => true ) ) ), array( 'idempotent' => false, 'new' => 1 ) ) )->get_data(),
	'attach_meta(): existing _meta keys survive and colliding keys are overwritten by the new value, so a later layer wins'
);
assert_same(
	array( 'not' => 'a response' ),
	diviops_call( 'attach_meta', array( array( 'not' => 'a response' ), array( 'x' => 1 ) ) ),
	'attach_meta(): a non-WP_REST_Response is returned untouched rather than coerced'
);

diviops_core_char_section( 'attach_meta' );

// ═══════════════════════════════════════════════════════════════════════
// 13. Block-name identity helpers
// ═══════════════════════════════════════════════════════════════════════

assert_same( 'text', diviops_call( 'block_identifier_from_name', array( 'divi/text' ) ), 'block_identifier_from_name(): the divi/ namespace is stripped for targeting' );
assert_same( 'difl/faq', diviops_call( 'block_identifier_from_name', array( 'difl/faq' ) ), 'block_identifier_from_name(): a third-party namespace is kept, so difl/faq stays addressable in full' );
assert_same( 'divi/text', diviops_call( 'block_name_from_identifier', array( 'text' ) ), 'block_name_from_identifier(): a bare identifier gets the divi/ namespace back' );
assert_same( 'difl/faq', diviops_call( 'block_name_from_identifier', array( 'difl/faq' ) ), 'block_name_from_identifier(): an identifier that already carries a slash is left alone' );

// counted_block_name(): a divi/global-layout wrapper counts as whatever it
// resolves to, read from its own attrs — the #13 off-by-one fix.
assert_same(
	'divi/section',
	diviops_call( 'counted_block_name', array( 'divi/global-layout', array( 'blockName' => 'divi/section' ) ) ),
	'counted_block_name(): a resolved global-layout wrapper counts as the block its attrs name, keeping counting sites in step with the read path'
);
assert_same(
	'section',
	diviops_call( 'counted_block_identifier', array( 'divi/global-layout', array( 'blockName' => 'divi/section' ) ) ),
	'counted_block_identifier(): the resolved name goes through the same identifier mapping, so it counts under section not divi/section'
);
assert_same(
	'divi/global-layout',
	diviops_call( 'counted_block_name', array( 'divi/global-layout', array( 'blockName' => 123 ) ) ),
	'counted_block_name(): a non-string blockName falls back to the wrapper\'s own name rather than coercing the value'
);
assert_same(
	'divi/global-layout',
	diviops_call( 'counted_block_name', array( 'divi/global-layout', null ) ),
	'counted_block_name(): a wrapper with no attrs at all counts predictably as itself'
);
assert_same(
	'divi/text',
	diviops_call( 'counted_block_name', array( 'divi/text', array( 'blockName' => 'divi/section' ) ) ),
	'counted_block_name(): a blockName attr on any block OTHER than the wrapper is ignored'
);

// is_divi_module_block_name(): namespace trust, then registry category.
assert_true( diviops_call( 'is_divi_module_block_name', array( 'divi/text' ) ), 'is_divi_module_block_name(): the divi namespace is trusted without a registry lookup, because Divi\'s own modules are largely unregistered' );
assert_true( diviops_call( 'is_divi_module_block_name', array( 'difl/faq' ) ), 'is_divi_module_block_name(): a third-party block registered under the module category counts' );
assert_true( diviops_call( 'is_divi_module_block_name', array( 'difl/faqitem' ) ), 'is_divi_module_block_name(): child-module counts too' );
assert_true( ! diviops_call( 'is_divi_module_block_name', array( 'gravityforms/form' ) ), 'is_divi_module_block_name(): a registered block from an unrelated plugin does not count, so its attrs are never rewritten' );
assert_true( ! diviops_call( 'is_divi_module_block_name', array( 'never/registered' ) ), 'is_divi_module_block_name(): an unregistered non-divi block does not count' );

// next_block_opener(): the anchored name scan.
$opener_markup = 'lead<!-- wp:difl/faq {"a":1} -->';
assert_same(
	array( 'pos' => 4, 'name' => 'difl/faq', 'name_end' => 4 + strlen( '<!-- wp:difl/faq' ) ),
	diviops_call( 'next_block_opener', array( $opener_markup, 0 ) ),
	'next_block_opener(): a namespaced name is captured whole, and name_end points just past it rather than at the first slash'
);
assert_same(
	null,
	diviops_call( 'next_block_opener', array( 'no openers here', 0 ) ),
	'next_block_opener(): content with no opener prefix returns null'
);

/*
 * A `<!-- wp:` prefix whose following text is not a valid block name must not
 * stop the scan. The first candidate here is upper-case, which the lowercase-
 * only name pattern rejects; the real opener is later in the string. This is
 * the fixture that separates "retry at the next prefix" from "give up", AND
 * the one that separates an anchored name match from an unanchored one — an
 * unanchored search from the same offset would find the LATER lowercase name
 * and report it with the wrong position.
 */
$decoy_markup = '<!-- wp:Divi/Text --><!-- wp:divi/text /-->';
assert_same(
	array(
		'pos'      => strpos( $decoy_markup, '<!-- wp:divi/text' ),
		'name'     => 'divi/text',
		'name_end' => strpos( $decoy_markup, '<!-- wp:divi/text' ) + strlen( '<!-- wp:divi/text' ),
	),
	diviops_call( 'next_block_opener', array( $decoy_markup, 0 ) ),
	'next_block_opener(): a <!-- wp: prefix followed by an invalid name is skipped and the scan retries at the next prefix, reporting the real opener\'s own position'
);

diviops_core_char_section( 'block_identity' );

// ═══════════════════════════════════════════════════════════════════════
// 14. block_round_trip_drift()
// ═══════════════════════════════════════════════════════════════════════

assert_same(
	null,
	diviops_call( 'block_round_trip_drift', array( 'same bytes', 'same bytes' ) ),
	'block_round_trip_drift(): a byte-identical round trip reports null, the only value callers treat as safe to mutate'
);
assert_same(
	array( 'original_bytes' => 12, 'reserialized_bytes' => 9, 'byte_delta' => -3 ),
	diviops_call( 'block_round_trip_drift', array( 'aaaaaaaaaaaa', 'bbbbbbbbb' ) ),
	'block_round_trip_drift(): byte_delta is reserialized minus original, so a lossy round trip reads negative'
);
assert_same(
	array( 'original_bytes' => 2, 'reserialized_bytes' => 2, 'byte_delta' => 0 ),
	diviops_call( 'block_round_trip_drift', array( 'ab', 'ba' ) ),
	'block_round_trip_drift(): same-length but different bytes still reports drift, with a zero delta — the comparison is identity, not length'
);

diviops_core_char_section( 'round_trip_drift' );

// ═══════════════════════════════════════════════════════════════════════
// 15. Small shared utilities
// ═══════════════════════════════════════════════════════════════════════

$nested_source = array( 'a' => array( 'b' => array( 'c' => 'found' ), 'nullish' => null ), 'scalar' => 'x' );
assert_same( 'found', diviops_call( 'get_nested_array_value', array( $nested_source, array( 'a', 'b', 'c' ) ) ), 'get_nested_array_value(): a full path returns the leaf' );
assert_same( 'D', diviops_call( 'get_nested_array_value', array( $nested_source, array( 'a', 'missing' ), 'D' ) ), 'get_nested_array_value(): a missing key returns the caller default' );
assert_same( 'D', diviops_call( 'get_nested_array_value', array( $nested_source, array( 'scalar', 'deeper' ), 'D' ) ), 'get_nested_array_value(): descending through a scalar returns the default rather than erroring' );
assert_same( null, diviops_call( 'get_nested_array_value', array( $nested_source, array( 'a', 'nullish' ), 'D' ) ), 'get_nested_array_value(): a stored null is returned as null, NOT replaced by the default — array_key_exists, not isset' );
assert_same( $nested_source, diviops_call( 'get_nested_array_value', array( $nested_source, array() ) ), 'get_nested_array_value(): an empty path returns the source unchanged' );

assert_same( array( 'a' => 1 ), diviops_call( 'normalize_storage_array', array( array( 'a' => 1 ) ) ), 'normalize_storage_array(): an array passes through' );
assert_same( array( 'a' => 1 ), diviops_call( 'normalize_storage_array', array( json_decode( '{"a":1}' ) ) ), 'normalize_storage_array(): an object is cast to an array, which is how a serialized stdClass registry becomes readable' );
assert_same( null, diviops_call( 'normalize_storage_array', array( 'a string' ) ), 'normalize_storage_array(): a scalar returns null, letting callers distinguish "unusable" from "empty"' );
assert_same( array(), diviops_call( 'normalize_storage_array', array( array() ) ), 'normalize_storage_array(): an empty array stays an empty array and does NOT become null' );

assert_same(
	array( 'a' => array( 'b' => 1, 'c' => 3 ), 'd' => 4 ),
	diviops_call( '_deep_merge', array( array( 'a' => array( 'b' => 1, 'c' => 2 ) ), array( 'a' => array( 'c' => 3 ), 'd' => 4 ) ) ),
	'_deep_merge(): overrides win at the leaf and untouched sibling keys survive the nested merge'
);
assert_same(
	array( 'a' => 'scalar' ),
	diviops_call( '_deep_merge', array( array( 'a' => array( 'b' => 1 ) ), array( 'a' => 'scalar' ) ) ),
	'_deep_merge(): a scalar override replaces an array wholesale rather than merging into it'
);
assert_same( array( 'o' => 1 ), diviops_call( '_deep_merge', array( 'not an array', array( 'o' => 1 ) ) ), '_deep_merge(): a non-array base yields the overrides' );
assert_same( array( 'b' => 1 ), diviops_call( '_deep_merge', array( array( 'b' => 1 ), 'not an array' ) ), '_deep_merge(): a non-array overrides yields the base' );
assert_same(
	array( 0 => 'b' ),
	diviops_call( '_deep_merge', array( array( 0 => 'a' ), array( 0 => 'b' ) ) ),
	'QUIRK _deep_merge(): list-shaped values merge by INDEX rather than appending, so a two-item override of a one-item base cannot shorten it'
);

diviops_core_char_section( 'utilities' );

// ═══════════════════════════════════════════════════════════════════════
// 16. Preset-storage flattening and probing (top-level paths only)
// ═══════════════════════════════════════════════════════════════════════
//
// The et_divi.<sub> path reaches Divi's unshimmed et_get_option(); see the
// header. Everything below uses top-level option paths, which do not.

assert_same(
	array(
		array( 'path' => 'et_divi_builder_global_presets_d5', 'provenance' => 'd5_top_level' ),
		array( 'path' => 'et_divi.builder_global_presets_d5', 'provenance' => 'd5_nested_scratchpad' ),
	),
	diviops_call( 'd5_preset_paths' ),
	'd5_preset_paths(): the canonical top-level path is probed before the nested scratchpad, and _ng is absent from the READ list entirely'
);

$GLOBALS['diviops_test_options']['diviops_char_absent']  = '';
$GLOBALS['diviops_test_options']['diviops_char_empty']   = array();
$GLOBALS['diviops_test_options']['diviops_char_first']   = array( 'from' => 'first' );
$GLOBALS['diviops_test_options']['diviops_char_second']  = array( 'from' => 'second' );

assert_same( null, diviops_call( 'read_storage_path', array( 'diviops_char_never_set' ) ), 'read_storage_path(): an absent option reads as null, distinguishing "path not present" from "seeded empty"' );
assert_same( null, diviops_call( 'read_storage_path', array( 'diviops_char_absent' ) ), 'read_storage_path(): an empty-string option is also null' );
assert_same( null, diviops_call( 'read_storage_path', array( 'diviops_char_empty' ) ), 'QUIRK read_storage_path(): an option holding an EMPTY ARRAY also reads as null, so the documented "empty array seeded" case is indistinguishable from an absent path here — the distinction is re-made by the caller' );
assert_same( array( 'from' => 'first' ), diviops_call( 'read_storage_path', array( 'diviops_char_first' ) ), 'read_storage_path(): a populated top-level option returns its normalized array' );

assert_same(
	array(
		'data'         => array( 'from' => 'first' ),
		'source_path'  => 'diviops_char_first',
		'probed_paths' => array( 'diviops_char_empty', 'diviops_char_first', 'diviops_char_second' ),
	),
	diviops_call(
		'probe_storage_paths',
		array(
			array(
				array( 'path' => 'diviops_char_empty', 'provenance' => 'p0' ),
				array( 'path' => 'diviops_char_first', 'provenance' => 'p1' ),
				array( 'path' => 'diviops_char_second', 'provenance' => 'p2' ),
			),
		)
	),
	'probe_storage_paths(): the first non-empty path wins and later paths cannot overwrite it, but every candidate is still reported in probed_paths'
);
assert_same(
	array( 'data' => array(), 'source_path' => null, 'probed_paths' => array( 'diviops_char_empty' ) ),
	diviops_call( 'probe_storage_paths', array( array( array( 'path' => 'diviops_char_empty', 'provenance' => 'p0' ) ) ) ),
	'probe_storage_paths(): when nothing holds content the data is an empty array and source_path is null, never a guessed path'
);

// collect_d5_preset_audit_entries(): the module/group bucket flattening.
$d5_registry = array(
	'module' => array(
		'divi/text' => array( 'items' => array( 'uuid-a' => array( 'name' => 'A' ) ) ),
		'divi/bad'  => array( 'no_items_key' => true ),
	),
	'group'  => array(
		'typography' => array( 'items' => array( 'uuid-b' => array( 'name' => 'B' ) ) ),
	),
	'other'  => array( 'ignored' => array( 'items' => array( 'uuid-c' => array() ) ) ),
);
assert_same(
	array(
		array( 'id' => 'uuid-a', 'entry' => array( 'name' => 'A' ), 'bucket' => 'module', 'bucket_key' => 'divi/text' ),
		array( 'id' => 'uuid-b', 'entry' => array( 'name' => 'B' ), 'bucket' => 'group', 'bucket_key' => 'typography' ),
	),
	diviops_call( 'collect_d5_preset_audit_entries', array( $d5_registry ) ),
	'collect_d5_preset_audit_entries(): module is flattened before group, a bucket with no items key is skipped, and a bucket outside those two is never read'
);

assert_same(
	array( array( 'id' => 'p1', 'entry' => array( 'x' => 1 ), 'bucket' => 'legacy_ng', 'bucket_key' => 'et_pb_section' ) ),
	diviops_call(
		'collect_legacy_ng_preset_audit_entries',
		array( array( 'et_pb_section' => array( 'presets' => array( 'p1' => array( 'x' => 1 ) ), 'default' => 'p1' ), 'et_pb_row' => array( 'default' => 'x' ) ) )
	),
	'collect_legacy_ng_preset_audit_entries(): D4 rows are tagged legacy_ng with the module slug as bucket_key, and a module with no presets key contributes nothing'
);

assert_true(
	diviops_call( 'entries_shape_match', array( array( 'b' => 1, 'a' => 2 ), array( 'a' => 'different', 'b' => 'values' ) ) ),
	'entries_shape_match(): the same top-level keys in a different order and with different values still match — key ordering is cosmetic'
);
assert_true(
	! diviops_call( 'entries_shape_match', array( array( 'a' => 1 ), array( 'a' => 1, 'c' => 2 ) ) ),
	'entries_shape_match(): an extra top-level key is a shape divergence the caller must reconcile'
);
assert_true(
	! diviops_call( 'entries_shape_match', array( 'scalar', array( 'a' => 1 ) ) ),
	'entries_shape_match(): an unusable operand is never a match, so a scalar entry surfaces as shape_inconsistency rather than id_collision'
);

diviops_core_char_section( 'preset_storage' );

// ═══════════════════════════════════════════════════════════════════════
// Completeness guard
// ═══════════════════════════════════════════════════════════════════════
//
// A gate that reports what it inspected but derives pass/fail only from
// problems found will pass while inspecting nothing. This asserts the file
// actually ran end to end: every section below must have registered itself, in
// this order. A section that fatals, is renamed, or is commented out fails here
// even if every assertion that DID run passed.

assert_same(
	array(
		'json_string_segments',
		'variable_token_end',
		'strip_variable_tokens',
		'find_malformed_block_attr_escape',
		'marker_census',
		'normalize_full_content',
		'serialize_canonical',
		'empty_object_guard',
		'envelope_core',
		'envelope_adapters',
		'truncate_message',
		'dry_run',
		'resolve_content_or_page_id',
		'inspection_helpers',
		'attach_meta',
		'block_identity',
		'round_trip_drift',
		'utilities',
		'preset_storage',
	),
	$GLOBALS['diviops_core_char_sections'],
	'completeness guard: every section of this file executed, in order — a suite that inspected nothing must fail rather than report a green pass'
);

// Restore the shim's shared stores for whatever suite runs next.
$GLOBALS['diviops_test_options']         = $diviops_core_char_saved_options;
$GLOBALS['diviops_test_posts']           = $diviops_core_char_saved_posts;
$GLOBALS['diviops_test_uneditable_ids']  = $diviops_core_char_saved_uned;

echo 'PASS: core-characterization (' . count( $GLOBALS['diviops_core_char_sections'] ) . " sections)\n";
