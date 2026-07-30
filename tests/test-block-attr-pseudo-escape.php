<?php
/**
 * find_malformed_block_attr_escape() false-positives on Divi's own
 * `$variable({...})$` token wrapper (#97).
 *
 * The check exists to catch a caller who hand-writes a pseudo-escape like
 * `u003c` (meaning `<`) and forgets the backslash — real broken input,
 * since literal "u003c" text is not what they meant. But Divi's own dynamic
 * content / global-color / global-variable wrapper (see trait-dynamic-content.php,
 * `$variable({"type":...,"value":{...}})$`) legitimately embeds its nested
 * JSON payload's quotes two different ways depending on which Divi code path
 * produced them — and in RAW SERIALIZED TEXT (what this function actually
 * scans), neither ever contains a bare, literal `"`: WordPress core's
 * serialize_block_attributes() turns every `\"` produced by json_encode()-ing
 * the token into `"` (backslash preserved), while gcid-/gvid- global
 * color/variable references specifically arrive as bare `u0022` with no
 * backslash at all. Neither is a caller mistake; both are confirmed live,
 * already-rendering markup (page 900390 on the reference site, read-only; see
 * #97's issue body for the exact repro). The false-positive blocked every
 * write path that runs full-content validation: trait-page.php's final_page
 * write and both trait-theme-builder.php full-layout/per-field writes.
 *
 * Four review rounds, four regressions, each narrower than the last — kept
 * here because the fix's final shape (a boundary tokenizer composed with a
 * token scanner, not a regex) only makes sense in light of what a regex kept
 * getting wrong:
 *   1. A naive non-greedy `.*?` between `$variable(` and the nearest later
 *      `)$` could cross into an entirely unrelated LATER property's value
 *      (attrs routinely carry 6+ tokens alongside ordinary text fields) and
 *      silently swallow a genuine typo sitting in between.
 *   2. Anchoring the span to a complete JSON string literal
 *      (`"\$variable\((?:[^"\\]|\\.)*\)\$"`) closed that, but the anchor
 *      itself — a literal `"` immediately before `$variable(` — could not
 *      tell a true string-opening quote from the second byte of an escaped
 *      `\"` sitting mid-string, so `{"weird":"abc\"$variable(u003c
 *      faketoken)$","tail":"end"}` (valid JSON: "weird" is one string
 *      containing a real `"` character) still masked the typo.
 *   3. Both were the same underlying problem: telling a "real" unescaped
 *      quote apart from an escaped one requires counting the PARITY of
 *      backslashes immediately before it, which no bounded regex lookbehind
 *      can express. json_string_segments() (trait-core.php) closed the
 *      whole class by walking the text and explicitly consuming `\X` as one
 *      unit while inside a string, so it can never mistake an escaped quote
 *      for a boundary — replacing round 2's anchored regex, not just
 *      patching it again.
 *   4. Given a correctly-bounded string segment, the remaining question —
 *      is its content exactly one token? — was still answered with a
 *      regex (`/^\$variable\(.*\)\$$/s`, greedy, DOTALL), satisfied just as
 *      well by TWO tokens concatenated with something else between them
 *      (composing a field that cites two design-token references, e.g. two
 *      gradient stops, is ordinary authoring for a tool built for
 *      programmatic content, not a contrived shape) — masking a typo
 *      sitting between them. The same regex, tightened to require exactly
 *      one token, then created the opposite problem: legitimate multi-token
 *      content with zero typos got wrongly rejected, because "exactly one"
 *      is the wrong question. strip_variable_tokens() (trait-core.php)
 *      answers the right one: scan for and remove EVERY well-formed token
 *      wherever it occurs, however many there are, via variable_token_end()
 *      balance-counting each one's own payload braces to find its true end.
 *      Text before, after, or between tokens — including a genuine typo —
 *      is left for the scan either way.
 *
 *      Round 4's first version had its own bug, caught by adversarial
 *      review before merge rather than a fifth round: variable_token_end(),
 *      on a fragment whose braces never balance, scanned all the way to
 *      end-of-string before giving up, and strip_variable_tokens()' outer
 *      loop retried that full-length scan at every subsequent `$` — a run
 *      of many unclosed `$variable({` fragments was O(n²) (8,000 repeats
 *      of the 11-byte fragment measured at 6.3s, reachable end-to-end
 *      through the real REST write path with no upstream size guard).
 *      MAX_VARIABLE_TOKEN_SCAN bounds a single scan to comfortably more
 *      than the largest real token observed (270 bytes, 15x headroom),
 *      restoring linear-time behavior without narrowing what a legitimate
 *      token can look like.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

// ── exact shapes observed live on page 900390 ──────────────────────────

// Global color/variable reference (gcid-/gvid-): bare u0022, no backslash.
$bare_variable_token = '$variable({u0022typeu0022:u0022coloru0022,u0022valueu0022:{u0022nameu0022:u0022gcid-myuw5vpz20u0022,u0022settingsu0022:{}}})$';

// Dynamic content binding: backslash preserved, exactly as WordPress core's
// serialize_block_attributes() emits it (json_encode()'s own embedded `"`
// becomes `"` — backslash retained, "u0022" as literal text after it).
// Built via str_replace on a human-readable bare-quote version rather than
// hand-typed: this exact 6-byte sequence is easy to mistype back into a
// bare quote (happened twice already in this file's own history — a
// mistyped version here is invisible in a diff and *cannot occur* at this
// function's real call site, since real serialized text never contains a
// bare `"` inside this wrapper), and str_replace makes that class of typo
// structurally impossible rather than relying on careful proofreading.
$escaped_variable_token = str_replace(
	'"',
	chr( 92 ) . 'u0022',
	'$variable({"type":"content","value":{"name":"post_title","settings":{}}})$'
);

assert_true(
	false !== strpos( $escaped_variable_token, chr( 92 ) . 'u0022' ) && false === strpos( $escaped_variable_token, '"' ),
	'sanity check on the fixture construction itself: the escaped-form token contains the literal backslash+u0022 sequence and zero bare quote characters'
);

assert_true(
	null === diviops_call( 'find_malformed_block_attr_escape', array( '{"color":"' . $bare_variable_token . '"}' ) ),
	'bare u0022 inside a $variable({...})$ wrapper (global color/variable form) is not flagged'
);
assert_true(
	null === diviops_call( 'find_malformed_block_attr_escape', array( '{"name":"' . $escaped_variable_token . '"}' ) ),
	'backslash-escaped u0022 inside a $variable({...})$ wrapper (dynamic content form) is not flagged'
);

// The full block-attrs shape as it actually appears in stored content: the
// $variable() token as one attribute value among ordinary sibling attributes.
$full_attrs_tail = '{"module":{"decoration":{"background":{"desktop":{"value":{"color":"' . $bare_variable_token . '"}}}}},"builderVersion":"5.1.1"}';
assert_true(
	null === diviops_call( 'find_malformed_block_attr_escape', array( $full_attrs_tail ) ),
	'a $variable() token embedded among ordinary sibling attributes does not trip the scan'
);

// Multiple tokens in the same attrs blob, EACH IN ITS OWN property (the
// common real-world case — page 900390 carries 6 in a single block's attrs).
$multi_token_tail = '{"a":"' . $bare_variable_token . '","b":"' . $escaped_variable_token . '","c":"' . $bare_variable_token . '","d":"' . $escaped_variable_token . '","e":"' . $bare_variable_token . '","f":"' . $bare_variable_token . '"}';
assert_true(
	null === diviops_call( 'find_malformed_block_attr_escape', array( $multi_token_tail ) ),
	'six $variable() tokens, each its own property (matching real page 900390 shape), are all excluded from the scan'
);

// ── the check must still catch a genuine caller mistake OUTSIDE any $variable() wrapper ──

$genuine_pseudo_escape = '{"content":{"desktop":{"value":"click u003c here, not a real escape"}}}';
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( $genuine_pseudo_escape ) ),
	'a bare pseudo-escape outside any $variable() wrapper is still flagged — the fix narrows scope, it does not disable the check'
);

$genuine_pseudo_escape_after_token = '{"color":"' . $bare_variable_token . '","label":"u0026 forgot the backslash"}';
assert_same(
	'u0026',
	diviops_call( 'find_malformed_block_attr_escape', array( $genuine_pseudo_escape_after_token ) ),
	'a genuine pseudo-escape appearing AFTER a legitimate $variable() token is still caught, not masked by the exclusion'
);

// ── regression 1: a genuine typo must not be masked by an unrelated )$ ──
// shape later in the text, whether that "later" is a different property or
// plain filler within the reach of a naive non-greedy scan (the ORIGINAL
// round-1 repro: adversarial review found the fixture this file previously
// shipped for round 1 happened to still pass even under the broken
// original code, because $bare_variable_token's own payload contains no
// internal )$ — this fixture actually exercises the mechanism) ─────────

$round_1_original_repro = '{"a":"$variable(u003c typo here","b":"more filler text","c":"tail )$ end"}';
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( $round_1_original_repro ) ),
	'round 1 original repro: a typo right after an unterminated $variable( is not masked by an unrelated )$ several properties later'
);

$cross_property_masking = '{"a":"' . $bare_variable_token . '","b":"u003c typo","c":"ends with )$"}';
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( $cross_property_masking ) ),
	'a typo in one property is not masked by an unrelated )$-shaped ending in a LATER property, even with a real token earlier in the blob'
);

// ── regression 2: a genuine typo must not be masked by a fake "token" that ──
// starts at an ESCAPED quote sitting mid-string (valid JSON: this is one
// property whose value legitimately contains a real `"` character,
// immediately followed by text that happens to look like a token opener) ──

$escaped_quote_adjacent_faketoken = '{"weird":"abc\"$variable(u003c faketoken)$","tail":"end"}';
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( $escaped_quote_adjacent_faketoken ) ),
	'a typo following text like $variable( that starts right after an escaped quote (not a real token boundary) is still caught'
);

$escaped_quote_adjacent_with_real_token = '{"a":"' . $bare_variable_token . '","weird":"abc\"$variable(u003c faketoken)$"}';
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( $escaped_quote_adjacent_with_real_token ) ),
	'the same escaped-quote-adjacent case is still caught even with a real, legitimately-excluded token elsewhere in the blob'
);

// ── regression 3: malformed/unterminated $variable( must not swallow the ──
// rest of the scan ───────────────────────────────────────────────────────

$unterminated = '{"color":"$variable({broken","label":"u003c still scanned"}';
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( $unterminated ) ),
	'an unterminated $variable( with no closing )$ anywhere does not suppress scanning of the rest of the string'
);

$unterminated_string = '{"color":"$variable({broken forever with u003c inside';
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( $unterminated_string ) ),
	'a string with no closing quote at all still gets scanned rather than treated as an open-ended exclusion'
);

$nested_braces = '{"color":"$variable({u0022au0022:{u0022bu0022:{u0022cu0022:1}}})$"}';
assert_true(
	null === diviops_call( 'find_malformed_block_attr_escape', array( $nested_braces ) ),
	'a token payload with nested objects (the real shape — settings can nest) is still excluded correctly'
);

// ── regression 4: multiple tokens concatenated in ONE property, with or ──
// without a genuine typo between them ────────────────────────────────────

$two_tokens_with_typo = '{"a":"' . $bare_variable_token . 'u003c' . $bare_variable_token . '"}';
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( $two_tokens_with_typo ) ),
	'a typo sitting between two concatenated real tokens in the same property is not masked'
);

$two_tokens_no_typo = '{"a":"' . $bare_variable_token . $bare_variable_token . '"}';
assert_true(
	null === diviops_call( 'find_malformed_block_attr_escape', array( $two_tokens_no_typo ) ),
	'two concatenated real tokens with no typo between them do not false-positive — "exactly one token" is the wrong question, "how many well-formed tokens" is the right one'
);

$gradient_stops_no_typo = '{"a":"' . $bare_variable_token . ' 0%, ' . $bare_variable_token . ' 100%"}';
assert_true(
	null === diviops_call( 'find_malformed_block_attr_escape', array( $gradient_stops_no_typo ) ),
	'a realistic gradient-stop-shaped value citing two global-color tokens with ordinary text between them does not false-positive'
);

$gradient_stops_with_typo = '{"a":"' . $bare_variable_token . ' 0%, u003c ' . $bare_variable_token . ' 100%"}';
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( $gradient_stops_with_typo ) ),
	'the same gradient-stop shape still catches a genuine typo sitting in the text between two real tokens'
);

// ── regression 5: many unclosed $variable({ fragments in a row must not ──
// make the scan quadratic ────────────────────────────────────────────────
//
// variable_token_end(), on a fragment whose braces never balance, used to
// scan all the way to end-of-string before giving up -- and
// strip_variable_tokens()'s outer loop retries that full-length scan at
// every subsequent `$`, so a run of many unclosed fragments was O(n^2).
// Adversarial review measured 8,000 repeats of the 11-byte fragment
// "$variable({" at 6.3s unbounded; MAX_VARIABLE_TOKEN_SCAN (trait-core.php)
// bounds a single scan's cost, restoring linear-time behavior. This
// threshold (3s) sits with wide margin either side of the measured before
// (~6s at this size) and after (~0.5s) to avoid CI timing flakiness while
// still catching a real regression back to the unbounded scan.
$many_unclosed_fragments = str_repeat( '$variable({', 8000 );
$perf_start               = microtime( true );
diviops_call( 'strip_variable_tokens', array( $many_unclosed_fragments ) );
$perf_elapsed = microtime( true ) - $perf_start;
assert_true(
	$perf_elapsed < 3.0,
	sprintf( '8,000 unclosed $variable({ fragments do not trigger quadratic-time scanning (took %.3fs, must be well under 3s)', $perf_elapsed )
);

// ── integration: the full write-guard no longer rejects real Divi markup ──

$block_with_variable_color = '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"'
	. $bare_variable_token . '"}}}}},"builderVersion":"5.1.1"} --><!-- /wp:divi/section -->';

$normalized = diviops_call( 'normalize_divi_full_content_for_write', array( $block_with_variable_color ) );
assert_true(
	$normalized['ok'],
	'normalize_divi_full_content_for_write() accepts a real block carrying a global-color $variable() reference'
);

// ── direct coverage of the two helpers themselves, not just indirectly ──
// through find_malformed_block_attr_escape() ────────────────────────────

$segments = diviops_call( 'json_string_segments', array( '{"a":"b\"c","d":1}' ) );
$reassembled = '';
foreach ( $segments as $segment ) {
	$reassembled .= $segment['text'];
}
assert_same(
	'{"a":"b\"c","d":1}',
	$reassembled,
	'json_string_segments(): concatenating every segment\'s text reproduces the original input exactly (round-trip identity)'
);
assert_true(
	in_array( array( 'in_string' => true, 'text' => 'b\"c' ), $segments, true ),
	'json_string_segments(): the in-string segment for property "a" carries its content including the escaped quote as one unit'
);

assert_same(
	null,
	diviops_call( 'variable_token_end', array( 'not a token at all', 0 ) ),
	'variable_token_end(): text not starting with the literal $variable( prefix returns null'
);
assert_same(
	null,
	diviops_call( 'variable_token_end', array( '$variable(notanobject)$', 0 ) ),
	'variable_token_end(): a payload not starting with { returns null even though the text ends in )$'
);
$token_end = diviops_call( 'variable_token_end', array( $bare_variable_token . 'trailing', 0 ) );
assert_same(
	strlen( $bare_variable_token ),
	$token_end,
	'variable_token_end(): returns the index exactly one past a well-formed token\'s closing )$, not further'
);

assert_same(
	'AB',
	diviops_call( 'strip_variable_tokens', array( 'A' . $bare_variable_token . 'B' ) ),
	'strip_variable_tokens(): removes a well-formed token and rejoins the surrounding text with nothing left behind'
);
assert_same(
	'$variable(notanobject)$',
	diviops_call( 'strip_variable_tokens', array( '$variable(notanobject)$' ) ),
	'strip_variable_tokens(): a $variable( that never resolves to a balanced token is left as ordinary text, untouched'
);

echo "PASS: block-attr-pseudo-escape (27 assertions)\n";
