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
 * Adversarial review of the first version of this fix (a naive non-greedy
 * `.*?` between `$variable(` and the nearest later `)$`) found it could cross
 * into an entirely unrelated LATER property's value and silently swallow a
 * genuine pseudo-escape typo sitting in between — a masking false negative
 * that did not exist before #97's fix. See the cross-property test below and
 * the fix's own comment in trait-core.php for why matching a complete JSON
 * string literal, not just the loosest span between two markers, closes it.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

// ── exact shapes observed live on page 900390 ──────────────────────────

// Global color/variable reference (gcid-/gvid-): bare u0022, no backslash.
$bare_variable_token = '$variable({u0022typeu0022:u0022coloru0022,u0022valueu0022:{u0022nameu0022:u0022gcid-myuw5vpz20u0022,u0022settingsu0022:{}}})$';

// Dynamic content binding: backslash preserved, exactly as WordPress core's
// serialize_block_attributes() emits it (\" -> ") — never a bare `"`.
$escaped_variable_token = '$variable({"type":"content","value":{"name":"post_title","settings":{}}})$';

assert_true(
	null === diviops_call( 'find_malformed_block_attr_escape', array( '{"color":"' . $bare_variable_token . '"}' ) ),
	'bare u0022 inside a $variable({...})$ wrapper (global color/variable form) is not flagged'
);
assert_true(
	null === diviops_call( 'find_malformed_block_attr_escape', array( '{"name":"' . $escaped_variable_token . '"}' ) ),
	'backslash-escaped " inside a $variable({...})$ wrapper (dynamic content form) is not flagged'
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

// ── regression: a genuine typo in a DIFFERENT property than the token must ──
// not be masked by that other property's own unrelated closing text landing
// on a coincidental )$ shape (the false negative adversarial review found in
// the first version of this fix — see file header) ─────────────────────

$cross_property_masking = '{"a":"' . $bare_variable_token . '","b":"u003c typo","c":"ends with )$"}';
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( $cross_property_masking ) ),
	'a typo in one property is not masked by an unrelated )$-shaped ending in a LATER property, even with a real token earlier in the blob'
);

// ── malformed/unterminated $variable( must not swallow the rest of the scan ──

$unterminated = '{"color":"$variable({broken","label":"u003c still scanned"}';
assert_same(
	'u003c',
	diviops_call( 'find_malformed_block_attr_escape', array( $unterminated ) ),
	'an unterminated $variable( with no closing )$ anywhere does not suppress scanning of the rest of the string'
);

// ── integration: the full write-guard no longer rejects real Divi markup ──

$block_with_variable_color = '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"'
	. $bare_variable_token . '"}}}}},"builderVersion":"5.1.1"} --><!-- /wp:divi/section -->';

$normalized = diviops_call( 'normalize_divi_full_content_for_write', array( $block_with_variable_color ) );
assert_true(
	$normalized['ok'],
	'normalize_divi_full_content_for_write() accepts a real block carrying a global-color $variable() reference'
);

echo "PASS: block-attr-pseudo-escape (9 assertions)\n";
