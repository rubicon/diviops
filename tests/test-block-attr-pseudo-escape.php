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
 * Three review rounds, three regressions, each narrower than the last —
 * recorded here because the fix's final shape (a JSON-string-boundary
 * tokenizer, not a regex) only makes sense in light of what a regex kept
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
 *      can express. json_string_segments() (trait-core.php) sidesteps the
 *      whole class by walking the text and explicitly consuming `\X` as one
 *      unit while inside a string, so it can never mistake an escaped quote
 *      for a boundary.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

// ── exact shapes observed live on page 900390 ──────────────────────────

// Global color/variable reference (gcid-/gvid-): bare u0022, no backslash.
$bare_variable_token = '$variable({u0022typeu0022:u0022coloru0022,u0022valueu0022:{u0022nameu0022:u0022gcid-myuw5vpz20u0022,u0022settingsu0022:{}}})$';

// Dynamic content binding: backslash preserved, exactly as WordPress core's
// serialize_block_attributes() emits it (json_encode()'s own `\"` becomes
// `"` — backslash retained, "u0022" as literal text). A prior version
// of this fixture used bare `"` characters, which is not valid JSON once
// embedded and cannot occur at this function's real call site (caught in
// review — see file header, regression 3's sibling finding).
$escaped_variable_token = '$variable({"type":"content","value":{"name":"post_title","settings":{}}})$';

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

// Multiple tokens in the same attrs blob (the common real-world case — page
// 900390 carries 6 in a single block's attrs).
$multi_token_tail = '{"a":"' . $bare_variable_token . '","b":"' . $escaped_variable_token . '","c":"' . $bare_variable_token . '","d":"' . $escaped_variable_token . '","e":"' . $bare_variable_token . '","f":"' . $bare_variable_token . '"}';
assert_true(
	null === diviops_call( 'find_malformed_block_attr_escape', array( $multi_token_tail ) ),
	'six $variable() tokens in the same attrs blob (matching real page 900390 shape) are all excluded from the scan'
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

// ── regression 1: a genuine typo in a DIFFERENT property than the token ──
// must not be masked by that other property's own unrelated closing text
// landing on a coincidental )$ shape ────────────────────────────────────

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

// ── malformed/unterminated $variable( must not swallow the rest of the scan ──

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

// ── real token payloads contain nested braces/objects — must not confuse ──
// segment boundaries ─────────────────────────────────────────────────────

$nested_braces = '{"color":"$variable({u0022au0022:{u0022bu0022:{u0022cu0022:1}}})$"}';
assert_true(
	null === diviops_call( 'find_malformed_block_attr_escape', array( $nested_braces ) ),
	'a token payload with nested objects (the real shape — settings can nest) is still excluded correctly'
);

// ── integration: the full write-guard no longer rejects real Divi markup ──

$block_with_variable_color = '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"'
	. $bare_variable_token . '"}}}}},"builderVersion":"5.1.1"} --><!-- /wp:divi/section -->';

$normalized = diviops_call( 'normalize_divi_full_content_for_write', array( $block_with_variable_color ) );
assert_true(
	$normalized['ok'],
	'normalize_divi_full_content_for_write() accepts a real block carrying a global-color $variable() reference'
);

echo "PASS: block-attr-pseudo-escape (13 assertions)\n";
