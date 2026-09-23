<?php
// SPDX-License-Identifier: MIT
/**
 * `content_search` — the read half of #38 (#494).
 *
 * Phase 1 of `docs/superpowers/specs/2026-08-14-bulk-site-wide-operations-design.md`.
 * Read-only, so there is no plan, no snapshot and no `dry_run`; what has to be
 * proved here is that it SEES what is on the site, because every later phase
 * takes its target ids from this tool's output. A search that silently misses
 * a post does not fail — it reports "no matches", and the caller believes it.
 *
 * ── The one thing this file is really for ────────────────────────────────
 *
 * In Divi 5 module text lives inside the block comment's attribute JSON, where
 * core's `serialize_block_attributes()` escapes `<`, `>`, `&`, `"`, `--` and
 * backslash. The bytes stored for a phrase a human reads on the page are
 * therefore frequently NOT the bytes of that phrase. A `LIKE` on the literal
 * needle alone never makes such a post a candidate.
 *
 * So the load-bearing assertions below are the ones that would pass if the
 * escaped needle were dropped — and they are written to fail in that case.
 * Section 2 asserts the second `LIKE` reaches the database at all, section 3
 * asserts a post findable ONLY by its escaped form is actually returned, and
 * section 6 asserts the derivation of the escaped form comes from the same
 * canonical serializer the write path uses rather than a hand-copied table.
 *
 * ── What this file deliberately does not cover ───────────────────────────
 *
 * MySQL's collation. `LIKE` is case- and accent-insensitive under the stock
 * `utf8mb4_unicode_ci`, while the PHP `strpos` confirmation is neither, so a
 * live site returns candidate rows this tool then drops. The handler has an
 * explicit branch for that (`0 === match_count` → skip) and section 5 drives
 * it directly by handing the stub a post whose LIKE the harness matches and
 * whose byte scan cannot. What is NOT modelled is the collation itself: the
 * stub's LIKE is case-SENSITIVE, because a case-insensitive fake would be
 * modelling MySQL rather than the handler.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/bulk-content-search-wpdb-stub.php';

/**
 * Call content_search with the given params and return the decoded body.
 *
 * @param array $params REST parameters.
 * @return array
 */
function diviops_bcs_search( array $params ): array {
	$response = diviops_call( 'content_search', array( new DiviOps_Test_Request( $params ) ) );
	return $response->get_data();
}

/**
 * A Divi text module whose `content` attribute carries $text, escaped the way
 * core's serialize_block_attributes() escapes it.
 *
 * Built through the plugin's own canonical serializer rather than by writing
 * the escape sequences out by hand: a hand-written fixture would pin whatever
 * the author believed the escaping to be, and the assertion would then be
 * green against a handler that agreed with the same mistake.
 *
 * @param string $text Text to place in the module's content attribute.
 * @return string Block markup.
 */
function diviops_bcs_text_block( string $text ): string {
	$attrs = diviops_call(
		'serialize_block_attrs_canonical',
		array( (object) array( 'content' => (object) array( 'desktop' => (object) array( 'value' => $text ) ) ) )
	);
	return '<!-- wp:divi/text ' . $attrs . ' /-->';
}

$GLOBALS['diviops_test_posts']          = array();
$GLOBALS['diviops_test_uneditable_ids'] = array();

$bcs_wpdb = diviops_bcs_install();

// ── 0. The fixtures are what this file believes they are ─────────────────
//
// Asserted before anything is searched. Every assertion below is about what a
// scan finds in these bytes, so a fixture that does not carry the escaping it
// is supposed to carry would take the whole file green for the wrong reason.

$bcs_escaped_block = diviops_bcs_text_block( 'Acme & Co <b>partners</b>' );

assert_true(
	false !== strpos( $bcs_escaped_block, '\u0026' ),
	'the attribute fixture really does store & escaped, which is the premise of this entire file'
);
assert_true(
	false === strpos( $bcs_escaped_block, 'Acme & Co' ),
	'and the literal phrase is genuinely absent from the stored bytes, so a literal-only search cannot find it'
);

diviops_test_register_post( 701, $bcs_escaped_block, 'page', 'Escaped attrs' );
diviops_test_register_post( 702, '<p>Acme & Co is mentioned in body text.</p>', 'page', 'Body text' );
diviops_test_register_post( 703, diviops_bcs_text_block( 'Nothing to see' ), 'page', 'No match' );
diviops_test_register_post( 704, diviops_bcs_text_block( 'Acme & Co' ), 'post', 'Post type' );
diviops_test_register_post( 705, diviops_bcs_text_block( 'Acme & Co' ), 'et_body_layout', 'Theme Builder' );

// ── 1. It finds the post whose ONLY occurrence is escaped ────────────────
//
// This is the assertion the whole phase turns on. Drop the escaped needle and
// post 701 never becomes a candidate, and this returns an empty result set
// that looks exactly like "that phrase is not on the site".

$bcs = diviops_bcs_search( array( 'search' => 'Acme & Co' ) );

assert_true( $bcs['ok'], 'a well-formed search succeeds' );

$bcs_ids = array_map(
	static function ( $row ) {
		return $row['id'];
	},
	$bcs['data']['results']
);
sort( $bcs_ids );

assert_same(
	array( 701, 702, 704, 705 ),
	$bcs_ids,
	'a phrase stored escaped inside block attrs is found, alongside the body-text and other-post-type matches'
);
assert_true(
	in_array( 701, $bcs_ids, true ),
	'post 701 in particular, whose only occurrence is the escaped form, is in the result set'
);

// ── 2. Both needles reach the database ───────────────────────────────────
//
// Asserted against the SQL the stub actually executed, not against the source
// text of the handler. A handler that built the escaped form and then failed
// to put it in the query would satisfy a source grep and fail here.

$bcs_sql = end( $bcs_wpdb->queries );
assert_true(
	is_string( $bcs_sql ) && '' !== $bcs_sql,
	'the search issued a query (otherwise every assertion about its shape below is vacuous)'
);
assert_same(
	2,
	preg_match_all( '/post_content LIKE/i', (string) $bcs_sql ),
	'the query carries exactly two LIKE clauses: the literal needle and its escaped form'
);
assert_true(
	false !== strpos( (string) $bcs_sql, '\u0026' ),
	'and the second one is the escaped form, present verbatim in the SQL'
);
assert_true(
	false !== strpos( (string) $bcs_sql, "'et_body_layout'" ),
	'the default read scope reaches Theme Builder layout types — the reader sees further than the writer writes'
);

// ── 3. Every match says which form matched and where it sits ─────────────

$bcs_by_id = array();
foreach ( $bcs['data']['results'] as $row ) {
	$bcs_by_id[ $row['id'] ] = $row;
}

assert_same( 1, $bcs_by_id[701]['match_count'], 'the escaped-attrs post reports its single occurrence' );
assert_same( 'escaped', $bcs_by_id[701]['matches'][0]['form'], 'and reports that it was the escaped form that matched' );
assert_same( 'block_attrs', $bcs_by_id[701]['matches'][0]['location'], 'and that the hit sits inside a block opener' );
assert_same( 'divi/text', $bcs_by_id[701]['matches'][0]['block_name'], 'and names the owning block' );
assert_same(
	'Acme & Co <b>partners</b>',
	$bcs_by_id[701]['matches'][0]['decoded_value'],
	'and reports the DECODED attribute value, which is what the page shows and what a human has to judge'
);

assert_same( 'literal', $bcs_by_id[702]['matches'][0]['form'], 'a body-text hit reports the literal form' );
assert_same( 'body', $bcs_by_id[702]['matches'][0]['location'], 'and is classified as body, not as block attrs' );
assert_same( null, $bcs_by_id[702]['matches'][0]['block_name'], 'a body hit has no owning block' );
assert_same( null, $bcs_by_id[702]['matches'][0]['decoded_value'], 'and no decoded value, because nothing was encoded' );

assert_same(
	array( 'literal', 'escaped' ),
	$bcs['data']['searched_forms'],
	'the response says both forms were searched, so a caller can tell a real zero from a blind one'
);

// ── 4. The row-level edit_post boundary ──────────────────────────────────
//
// A coarse route-level gate is not the contract; raw object content needs the
// same per-row check every other read applies. Driven behaviourally through
// the shim's per-object seam rather than by reading the source.

$GLOBALS['diviops_test_uneditable_ids'] = array( 701 );
$bcs_filtered                           = diviops_bcs_search( array( 'search' => 'Acme & Co' ) );
$bcs_filtered_ids                       = array_map(
	static function ( $row ) {
		return $row['id'];
	},
	$bcs_filtered['data']['results']
);
sort( $bcs_filtered_ids );

assert_same(
	array( 702, 704, 705 ),
	$bcs_filtered_ids,
	'a post the caller cannot edit is absent from the results'
);
assert_same(
	4,
	$bcs_filtered['data']['candidate_posts'],
	'and the candidate count still reports what the query matched, so the filtering is visible rather than silent'
);
$GLOBALS['diviops_test_uneditable_ids'] = array();

// ── 5. A candidate the byte scan cannot confirm is dropped ───────────────
//
// Reachable on a live site because MySQL's default collation is case- and
// accent-insensitive while strpos is neither. Driven here by a post that
// matches the harness LIKE and whose bytes the handler's scan cannot find:
// the needle appears only split across the two forms.

diviops_test_register_post( 706, '<p>nothing here matches anything</p>', 'page', 'Unconfirmable' );
$bcs_wpdb->extra_ids = array( 706 );

$bcs_unconfirmed     = diviops_bcs_search( array( 'search' => 'Acme & Co' ) );
$bcs_unconfirmed_ids = array_map(
	static function ( $row ) {
		return $row['id'];
	},
	$bcs_unconfirmed['data']['results']
);

// The seam is proved to fire before the drop is asserted. Without this, the
// assertion below would be satisfied just as well by 706 never having been
// returned as a candidate at all -- which is the shape of gate that passes
// while inspecting nothing.
assert_true(
	in_array( 706, array_map( 'intval', $bcs_wpdb->get_col( (string) end( $bcs_wpdb->queries ) ) ), true ),
	'the seam really does return 706 as a candidate row'
);
assert_true(
	! in_array( 706, $bcs_unconfirmed_ids, true ),
	'a candidate row whose bytes the confirmation scan cannot find is dropped rather than reported with zero matches'
);
assert_same(
	4,
	$bcs_unconfirmed['data']['total_posts'],
	'so the reported posts and the reported matches describe the same thing'
);

$bcs_wpdb->extra_ids = array();
unset( $GLOBALS['diviops_test_posts'][706] );

// ── 6. The escaped form is derived, not hand-copied ──────────────────────
//
// `bulk_needle_stored_form()` round-trips the needle through
// `serialize_block_attrs_canonical()` — core's own serializer when WordPress
// supplies it — precisely so the two cannot drift. A re-listed escape table
// would be a second copy of something that can change, and a stale copy's
// failure mode is a search that silently misses rows.

assert_same(
	'Acme \u0026 Co',
	diviops_call( 'bulk_needle_stored_form', array( 'Acme & Co' ) ),
	'an ampersand takes its escaped stored form'
);
assert_same(
	'\u003cb\u003e',
	diviops_call( 'bulk_needle_stored_form', array( '<b>' ) ),
	'angle brackets take their escaped stored forms'
);
assert_same(
	'em \u002d\u002d dash',
	diviops_call( 'bulk_needle_stored_form', array( 'em -- dash' ) ),
	'a double hyphen is escaped, because it would otherwise close the block comment'
);
assert_same(
	'plain text',
	diviops_call( 'bulk_needle_stored_form', array( 'plain text' ) ),
	'a needle with nothing escapable is its own stored form'
);
assert_same(
	array( 'literal' ),
	diviops_bcs_search( array( 'search' => 'plain text' ) )['data']['searched_forms'],
	'and such a needle is searched once rather than twice for the same bytes'
);

// ── 7. Scope, refusals and bounds ────────────────────────────────────────

$bcs_scoped = diviops_bcs_search( array( 'search' => 'Acme & Co', 'post_types' => array( 'post' ) ) );
assert_same(
	array( 704 ),
	array_map(
		static function ( $row ) {
			return $row['id'];
		},
		$bcs_scoped['data']['results']
	),
	'a post_types filter narrows the scan to the named types'
);

$bcs_bad = diviops_bcs_search( array( 'search' => 'x', 'post_types' => array( 'attachment' ) ) );
assert_true( false === $bcs_bad['ok'], 'an unsupported post type is refused' );
assert_same( 'invalid_input', $bcs_bad['error']['code'], 'and refused as invalid_input' );
assert_true(
	false !== strpos( $bcs_bad['error']['message'], 'attachment' ),
	'the refusal names the type it rejected: ' . $bcs_bad['error']['message']
);

$bcs_empty = diviops_bcs_search( array( 'search' => '' ) );
assert_true( false === $bcs_empty['ok'], 'an empty search is refused rather than matching every post' );
assert_same( 'invalid_input', $bcs_empty['error']['code'], 'and refused as invalid_input' );

$bcs_none = diviops_bcs_search( array( 'search' => 'nothing on this site says this' ) );
assert_true( $bcs_none['ok'], 'a search with no matches is a success, not an error' );
assert_same( array(), $bcs_none['data']['results'], 'and returns an empty result list' );
assert_same( 0, $bcs_none['data']['total_matches'], 'with a zero total' );
assert_true( false === $bcs_none['data']['truncated'], 'and is not reported as truncated' );

// truncated means "more posts matched the LIKE than the ceiling returned".
$bcs_trunc = diviops_bcs_search( array( 'search' => 'Acme & Co', 'limit' => 2 ) );
assert_same( 2, count( $bcs_trunc['data']['results'] ), 'a limit caps the returned posts' );
assert_true( true === $bcs_trunc['data']['truncated'], 'and sets truncated when more posts matched than were returned' );
assert_true(
	false !== strpos( (string) end( $bcs_wpdb->queries ), 'LIMIT 3' ),
	'by selecting one row beyond the ceiling, so truncated is a fact about the query rather than an inference'
);

// ── 8. Per-post match caps report the true total ─────────────────────────
//
// A capped list that also capped the count would understate how much a later
// find/replace is about to change — the number a human reads when deciding.

diviops_test_register_post(
	707,
	str_repeat( '<p>repeat</p>', 12 ),
	'page',
	'Many matches'
);
$bcs_capped = diviops_bcs_search( array( 'search' => 'repeat', 'max_matches_per_post' => 3 ) );
$bcs_row    = $bcs_capped['data']['results'][0];
assert_same( 707, $bcs_row['id'], 'the many-match fixture is the post returned' );
assert_same( 12, $bcs_row['match_count'], 'match_count is the true number of occurrences, not the number returned' );
assert_same( 3, count( $bcs_row['matches'] ), 'while the match list honours the cap' );
assert_true( true === $bcs_row['matches_capped'], 'and the response says the list was cut' );
unset( $GLOBALS['diviops_test_posts'][707] );

// ── 9. Context windows never split a UTF-8 sequence ──────────────────────
//
// A byte offset into block markup lands mid-sequence on any multibyte page.
// `substr` would emit a lone continuation byte, which is invalid UTF-8 and
// makes the WHOLE json_encode of the response fail — one multibyte page
// taking down a response describing fifty others.

// The window width is chosen so BOTH boundaries land mid-sequence, and that is
// asserted rather than assumed. 'é' is two bytes, so with one space adjacent to
// the needle a width of 8 puts each boundary inside a character; a width of 7
// happens to land cleanly on one, and the first version of this fixture used 7
// and let a raw-substr mutant survive the whole matrix.
$bcs_mb_content = '<p>café ' . str_repeat( 'é', 20 ) . ' TARGET ' . str_repeat( 'é', 20 ) . ' café</p>';
$bcs_mb_offset  = strpos( $bcs_mb_content, 'TARGET' );

assert_true(
	( ord( $bcs_mb_content[ $bcs_mb_offset - 8 ] ) & 0xC0 ) === 0x80,
	'the leading window boundary really does land on a UTF-8 continuation byte, so a raw substr here would emit invalid bytes'
);
assert_true(
	( ord( $bcs_mb_content[ $bcs_mb_offset + 6 + 8 - 1 ] ) & 0xC0 ) !== 0x80
		&& ( ord( $bcs_mb_content[ $bcs_mb_offset + 6 + 8 - 1 ] ) & 0x80 ) !== 0,
	'and the trailing boundary lands on a lead byte whose continuation is outside the window, which a raw substr would emit alone'
);

diviops_test_register_post( 708, $bcs_mb_content, 'page', 'Multibyte' );
$bcs_mb  = diviops_bcs_search( array( 'search' => 'TARGET', 'context_chars' => 8 ) );
$bcs_hit = $bcs_mb['data']['results'][0]['matches'][0];

assert_true(
	false !== mb_check_encoding( $bcs_hit['context_before'], 'UTF-8' ),
	'the leading context window is valid UTF-8 even when the window boundary lands mid-sequence'
);
assert_true(
	false !== mb_check_encoding( $bcs_hit['context_after'], 'UTF-8' ),
	'and so is the trailing one'
);
assert_true(
	false !== json_encode( $bcs_mb ),
	'so the whole response encodes as JSON, which a lone continuation byte anywhere in it would prevent'
);
unset( $GLOBALS['diviops_test_posts'][708] );

// A three-byte character, because a two-byte one cannot reach every branch: its
// only mid-sequence boundary leaves a LEAD byte at the window edge. With three
// bytes the boundary can also leave a CONTINUATION byte there, which is the
// separate walk-back. A two-byte-only fixture let that walk-back survive the
// whole mutation matrix.
//
// It also pins the opposite case: a window ending exactly on a character
// boundary must keep that character. Trimming it would be valid UTF-8 and
// still wrong — the caller asked for context and silently got less.
$bcs_wide    = 'START TARGET' . str_repeat( "\u{3042}", 10 ) . 'END';
$bcs_woffset = strpos( $bcs_wide, 'TARGET' ) + strlen( 'TARGET' );

assert_same( 3, strlen( "\u{3042}" ), 'the fixture character really is three bytes' );
assert_true(
	( ord( $bcs_wide[ $bcs_woffset + 5 - 1 ] ) & 0xC0 ) === 0x80,
	'a width of 5 leaves a continuation byte at the window edge, which is the branch a two-byte character cannot reach'
);

diviops_test_register_post( 711, $bcs_wide, 'page', 'Three-byte' );

$bcs_w5 = diviops_bcs_search( array( 'search' => 'TARGET', 'context_chars' => 5 ) )['data']['results'][0]['matches'][0];
assert_true(
	false !== mb_check_encoding( $bcs_w5['context_after'], 'UTF-8' ),
	'a window cut inside a three-byte sequence still yields valid UTF-8'
);
assert_same(
	"\u{3042}",
	$bcs_w5['context_after'],
	'and yields exactly the one complete character that fits, with the half-character dropped'
);

$bcs_w6 = diviops_bcs_search( array( 'search' => 'TARGET', 'context_chars' => 6 ) )['data']['results'][0]['matches'][0];
assert_same(
	"\u{3042}\u{3042}",
	$bcs_w6['context_after'],
	'a window ending exactly on a character boundary keeps that character rather than trimming it away'
);

unset( $GLOBALS['diviops_test_posts'][711] );

// ── 10. An opener whose attrs will not decode is reported, not guessed ───
//
// This is the target phase 3 refuses. Here it is information: the match is
// still located and classified, and `decoded_value` is null rather than a
// fabricated reading of bytes that do not parse.

diviops_test_register_post( 709, '<!-- wp:divi/text {"content":"Acme & Co" /-->', 'page', 'Broken attrs' );
$bcs_broken = diviops_bcs_search( array( 'search' => 'Acme & Co' ) );
$bcs_broken_row = null;
foreach ( $bcs_broken['data']['results'] as $row ) {
	if ( 709 === $row['id'] ) {
		$bcs_broken_row = $row;
	}
}
assert_true( null !== $bcs_broken_row, 'a post whose block attrs do not decode is still reported as a match' );
assert_same( 'block_attrs', $bcs_broken_row['matches'][0]['location'], 'and the hit is still classified by position' );
assert_same( null, $bcs_broken_row['matches'][0]['decoded_value'], 'while the decoded value is null rather than invented' );
unset( $GLOBALS['diviops_test_posts'][709] );

// ── 11. A `-->` inside an attribute value does not truncate the span ─────
//
// The #5/#6 hazard. A raw strpos for the terminator finds the one inside the
// string value and reports a comment end in the middle of the block's own
// JSON, which would classify a later attribute hit as body text.

// Hand-built rather than through diviops_bcs_text_block(), which escapes the
// `--` and would leave no inner terminator to be fooled by at all.
$bcs_tricky = '<!-- wp:divi/text {"content":{"desktop":{"value":"a --> b TRICKY"}}} /-->';
assert_true(
	strpos( $bcs_tricky, '-->' ) < strpos( $bcs_tricky, 'TRICKY' ),
	'the fixture really does carry a comment terminator inside the attribute value, before the needle'
);
diviops_test_register_post( 710, $bcs_tricky, 'page', 'Inner terminator' );
$bcs_tr = diviops_bcs_search( array( 'search' => 'TRICKY' ) );
assert_same( 1, count( $bcs_tr['data']['results'] ), 'the inner-terminator fixture is found' );
assert_same(
	'block_attrs',
	$bcs_tr['data']['results'][0]['matches'][0]['location'],
	'a hit after a `-->` sequence inside an attribute value is still classified as block attrs, not as body'
);
unset( $GLOBALS['diviops_test_posts'][710] );

diviops_bcs_restore();

assert_true(
	! ( $GLOBALS['wpdb'] instanceof DiviOps_BulkContentSearch_Wpdb ),
	'the decorator is uninstalled, so the posts-table primitive does not leak into files that run after this one'
);
