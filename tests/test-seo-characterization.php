<?php
// SPDX-License-Identifier: MIT
/**
 * Characterization of trait-seo.php's refusal branches (#328).
 *
 * SCOPE NOTE -- read this before adding to either SEO file.
 *
 * tests/test-seo.php already characterizes the three REST handlers end to end
 * (provider discovery/list, the read path, and the write path's checksum,
 * dry-run, noop, readback and rollback machinery), and tests/tsf-shim.php is
 * already a faithful stub of The SEO Framework 5.1.4 built by reading the real
 * `autodescription` plugin source. Neither is re-derived here.
 *
 * What this file adds is the set of guard branches those suites leave
 * unexercised. seo_validate_plain_text() alone has seven distinct refusal
 * branches and test-seo.php reaches two of them (markup, dynamic tokens); the
 * provider-selection guard, the empty-after-sanitization guard, and
 * seo_metadata_update()'s own pre-flight gates are likewise unreached. Every
 * one of those is a path that decides whether a write proceeds, which is
 * exactly the class of behavior a characterization test exists to freeze.
 *
 * Expected values are derived from plugin source, never read off a run:
 * status codes from seo_validation_error_response()'s `$error['status'] ?? 400`
 * (trait-seo.php:999), the length ceilings from the literal limits at
 * trait-seo.php:767-769, the error codes and `reason` strings from the branch
 * bodies themselves. Fixtures are chosen to reach one specific branch, and the
 * earlier guards each fixture must survive to get there are noted inline,
 * because these checks run in a fixed order and a careless fixture silently
 * characterizes the wrong branch.
 *
 * BEHAVIOR BELIEVED WRONG, pinned here as-is -- see the PR body:
 *   - (B2) a non-string scalar is refused as 'seo.serialized_value_unsupported'
 *     with the message "must be a scalar plain-text string", though an integer
 *     IS scalar and is not serialized data.
 *   - The byte half of every length ceiling is unreachable; see (B7).
 *
 * This file sorts BEFORE tests/test-seo.php ('-' 0x2D precedes '.' 0x2E) and
 * tests/run.php requires every test file into one shared process, so its
 * helpers carry a distinct diviops_seochar_ prefix rather than reusing
 * test-seo.php's diviops_seo_ ones, which would be a redeclare fatal. State is
 * reset at both ends for the same reason.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/tsf-shim.php';

function diviops_seochar_reset(): void {
	$GLOBALS['diviops_test_posts']          = array();
	$GLOBALS['diviops_test_post_meta']      = array();
	$GLOBALS['diviops_test_denied_caps']    = array();
	$GLOBALS['diviops_test_uneditable_ids'] = array();
	// Restores active_plugins, supported_post_type, effective values and the two
	// write seams to their defaults.
	diviops_test_tsf_reset();
}

function diviops_seochar_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

/**
 * Read a post's real checksum through the get handler, so no test hand-computes
 * one and no test hard-codes a digest that a fixture change would silently
 * invalidate.
 */
function diviops_seochar_checksum( int $post_id ): string {
	$resp = diviops_call( 'seo_metadata_get', array( diviops_seochar_request( array( 'id' => $post_id ) ) ) );
	return $resp->get_data()['data']['checksum'];
}

/**
 * Attempt a single-field `set` against post 700 with a valid, freshly-read
 * checksum, so what the assertion observes is the value guard and never a
 * drift rejection standing in for one.
 */
function diviops_seochar_set( $value, string $field = 'seo_title' ) {
	return diviops_call( 'seo_metadata_update', array( diviops_seochar_request( array(
		'id'                => 700,
		'expected_checksum' => diviops_seochar_checksum( 700 ),
		'changes'           => array( array( 'field' => $field, 'action' => 'set', 'value' => $value ) ),
	) ) ) );
}

diviops_seochar_reset();
diviops_test_register_post( 700, 'content', 'page', 'Page 700' );

// ── (A) seo_resolve_provider: the provider parameter ────────────────────────
// trait-seo.php:487 accepts only the strings 'auto' and DiviOps_SEO_TSF_Adapter::ID.
// Both handlers route through it (trait-seo.php:304 and :346), so both are checked:
// a guard that only holds on the read path would leave the write path open.

$resp = diviops_call( 'seo_metadata_get', array( diviops_seochar_request( array( 'id' => 700, 'provider' => 'yoast' ) ) ) );
$data = $resp->get_data();
assert_true( false === $data['ok'], 'an unknown provider is refused on the read path' );
assert_same( 'seo.provider_unsupported', $data['error']['code'], 'an unknown provider is seo.provider_unsupported' );
assert_same( 400, $resp->get_status(), 'provider_unsupported carries HTTP 400' );
assert_same( 'yoast', $data['error']['data']['provider'], 'the error echoes the rejected provider string' );

$resp = diviops_call( 'seo_metadata_update', array( diviops_seochar_request( array(
	'id'                => 700,
	'provider'          => 'yoast',
	'expected_checksum' => diviops_seochar_checksum( 700 ),
	'changes'           => array( array( 'field' => 'seo_title', 'action' => 'set', 'value' => 'Blocked' ) ),
) ) ) );
assert_same( 'seo.provider_unsupported', $resp->get_data()['error']['code'], 'the write path refuses an unknown provider too' );

// The guard runs BEFORE request-shape validation (trait-seo.php:346 precedes
// :355), so a request that is malformed in both ways reports the provider.
$resp = diviops_call( 'seo_metadata_update', array( diviops_seochar_request( array(
	'id'                => 700,
	'provider'          => 'yoast',
	'expected_checksum' => 'not-a-checksum',
	'changes'           => array(),
) ) ) );
assert_same( 'seo.provider_unsupported', $resp->get_data()['error']['code'], 'the provider guard precedes request-shape validation' );

// is_scalar() decides how the rejected value is echoed (trait-seo.php:493).
$resp = diviops_call( 'seo_metadata_get', array( diviops_seochar_request( array( 'id' => 700, 'provider' => 123 ) ) ) );
assert_same( '123', $resp->get_data()['error']['data']['provider'], 'a scalar non-string provider is echoed cast to string' );

$resp = diviops_call( 'seo_metadata_get', array( diviops_seochar_request( array( 'id' => 700, 'provider' => array( 'tsf' ) ) ) ) );
assert_true( null === $resp->get_data()['error']['data']['provider'], 'a non-scalar provider is echoed as null, not coerced' );

// 'tsf' is accepted explicitly, not only via the 'auto' default -- proves the
// allowlist really has two members rather than passing everything but 'auto'.
$resp = diviops_call( 'seo_metadata_get', array( diviops_seochar_request( array( 'id' => 700, 'provider' => 'tsf' ) ) ) );
assert_true( $resp->get_data()['ok'], "provider 'tsf' is accepted explicitly" );

$resp = diviops_call( 'seo_metadata_get', array( diviops_seochar_request( array( 'id' => 700, 'provider' => 'auto' ) ) ) );
assert_true( $resp->get_data()['ok'], "provider 'auto' is accepted explicitly" );

// ── (B) seo_validate_plain_text: the five unreached refusal branches ────────
// Order of checks in the source: type (704), UTF-8 (712), control characters
// (720), serialized (728), markup (736), dynamic tokens (744), secret-like
// (752), length (767). Every fixture below is chosen to survive the guards
// above its target and trip only its own. All of these return no 'status', so
// seo_validation_error_response() defaults them to 400 (trait-seo.php:999).

// (B1) Non-string, non-scalar.
$resp = diviops_seochar_set( array( 'nope' ) );
$data = $resp->get_data();
assert_same( 'seo.serialized_value_unsupported', $data['error']['code'], 'an array value is refused before any provider call' );
assert_same( 'array', $data['error']['data']['received_type'], 'the error reports the received gettype()' );
assert_true( false === $data['error']['data']['mutated'], 'a rejected value never reports a mutation' );
assert_same( 400, $resp->get_status(), 'a value-guard refusal defaults to HTTP 400' );

// (B2) Non-string but scalar. Pinned as-is; believed wrong -- 42 is scalar and
// is not serialized data, yet the branch reports 'seo.serialized_value_unsupported'
// with the message "must be a scalar plain-text string".
$data = diviops_seochar_set( 42 )->get_data();
assert_same( 'seo.serialized_value_unsupported', $data['error']['code'], 'an integer value takes the same branch as an array (believed wrong: 42 is scalar)' );
assert_same( 'integer', $data['error']['data']['received_type'], 'the integer case reports received_type integer' );

// (B3) Invalid UTF-8. 0xC3 opens a two-byte sequence and 0x28 is not a valid
// continuation byte, so preg_match('//u') fails (trait-seo.php:712). Reached
// before the control-character check, which would otherwise also be a candidate.
$data = diviops_seochar_set( "Title \xC3\x28 here" )->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'invalid UTF-8 is invalid_input' );
assert_same( 'invalid_encoding', $data['error']['data']['reason'], 'the invalid-UTF-8 reason is invalid_encoding' );

// (B4) Control characters. Valid UTF-8 (so it survives B3's guard) but carries
// a C0 byte. The range at trait-seo.php:720 is \x00-\x1F plus \x7F-\x9F.
$data = diviops_seochar_set( "Title\x01Here" )->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a C0 control character is invalid_input' );
assert_same( 'control_character', $data['error']['data']['reason'], 'the control-character reason is control_character' );

// DEL (\x7F) is in the second half of that range, which a \x00-\x1F-only guard
// would miss.
$data = diviops_seochar_set( "Title\x7FHere" )->get_data();
assert_same( 'control_character', $data['error']['data']['reason'], 'DEL is refused by the upper half of the control-character range' );

// A tab is a control character (\x09), so it is refused here rather than being
// collapsed to a space by the provider sanitizer downstream.
$data = diviops_seochar_set( "Title\tHere" )->get_data();
assert_same( 'control_character', $data['error']['data']['reason'], 'a tab is refused as a control character, never sanitized' );

// (B5) Serialized PHP. This fixture is genuine serialized data, so it is
// refused whether the guard takes its is_serialized() branch (WP core, in
// production) or its regex fallback (this harness, which defines no
// is_serialized) -- trait-seo.php:784-787. It carries no '<' and no '-->', so
// it survives the markup guard and reaches this one.
$data = diviops_seochar_set( 'a:1:{i:0;s:1:"x";}' )->get_data();
assert_same( 'seo.serialized_value_unsupported', $data['error']['code'], 'serialized PHP is refused' );
assert_true( false === $data['error']['data']['mutated'], 'the serialized refusal reports no mutation' );

// (B6) Secret-like values. Both alternatives of the pattern at
// trait-seo.php:802 are exercised.
//
// These fixtures deliberately use the pattern's 'token' prefix rather than a
// real vendor's key shape. An 'sk_live_'-prefixed fixture matches the trait's
// guard identically but also matches GitHub's Stripe-key scanner, which blocks
// the push on a string that is not a credential at all. Keep it vendor-neutral.
$data = diviops_seochar_set( 'token_abcdefghijklmnopqrstuvwxyz' )->get_data();
assert_same( 'seo.secret_like_value', $data['error']['code'], 'an API-key-shaped value is refused' );

// A PEM header contains no angle brackets and no '-->', so it survives the
// markup guard at :736 and reaches the secret guard at :752.
$data = diviops_seochar_set( '-----BEGIN PRIVATE KEY-----' )->get_data();
assert_same( 'seo.secret_like_value', $data['error']['code'], 'a PEM private-key header is refused' );

// Guard ordering: a value that is BOTH markup and secret-like reports markup,
// because :736 precedes :752. This pins the order, not just the membership.
$data = diviops_seochar_set( '<b>token_abcdefghijklmnopqrstuvwxyz</b>' )->get_data();
assert_same( 'markup', $data['error']['data']['reason'], 'markup is reported ahead of secret-like when a value is both' );

// (B7) Length ceilings. trait-seo.php:767-769 splits on a '_title' suffix:
// title-shaped fields get 512 characters / 2048 bytes, description-shaped
// fields get 2048 / 8192. The comparison is `>` (:771), so the limit itself is
// accepted and limit+1 is refused; both sides are checked so a boundary that
// drifts either way is visible.
//
// The BYTE half of both ceilings is unreachable and therefore unpinned: a
// UTF-8 character is at most 4 bytes and each byte limit is exactly 4x its
// character limit, so strlen() can never exceed it while the character count
// passes. Reported as dead code rather than characterized.
$data = diviops_seochar_set( str_repeat( 'a', 513 ) )->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a 513-character title exceeds the ceiling' );
assert_same( array( 'characters' => 512, 'bytes' => 2048 ), $data['error']['data']['limits'], 'title-shaped fields report the 512/2048 ceiling' );

// A description-shaped field admits the same 513-character value, proving the
// split is real and not one shared limit.
$data = diviops_seochar_set( str_repeat( 'a', 513 ), 'meta_description' )->get_data();
assert_true( $data['ok'], 'a 513-character description is accepted: the two field shapes have different ceilings' );

$data = diviops_seochar_set( str_repeat( 'b', 2049 ), 'meta_description' )->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a 2049-character description exceeds the ceiling' );
assert_same( array( 'characters' => 2048, 'bytes' => 8192 ), $data['error']['data']['limits'], 'description-shaped fields report the 2048/8192 ceiling' );

// The limit value itself is accepted -- the guard is `>`, not `>=`.
$data = diviops_seochar_set( str_repeat( 'c', 512 ) )->get_data();
assert_true( $data['ok'], 'a title of exactly 512 characters is accepted' );
// Read the stored value only if the write actually succeeded. Indexing the
// success payload unconditionally would raise a TypeError the moment this
// boundary regressed, aborting the file and taking every later assertion with
// it -- a crash reports far less than a failed assertion does.
$stored = $data['ok'] ? array_column( $data['data']['fields'], null, 'field' )['seo_title']['stored_value'] : null;
assert_same( str_repeat( 'c', 512 ), $stored, 'the 512-character title is stored whole, not truncated' );

// og_title and twitter_title end in '_title' too, so they take the title
// ceiling; twitter_description does not, so it takes the description one.
$data = diviops_seochar_set( str_repeat( 'd', 513 ), 'og_title' )->get_data();
assert_same( array( 'characters' => 512, 'bytes' => 2048 ), $data['error']['data']['limits'], 'og_title is title-shaped by its suffix' );

$data = diviops_seochar_set( str_repeat( 'e', 513 ), 'twitter_description' )->get_data();
assert_true( $data['ok'], 'twitter_description is description-shaped by its suffix' );

// ── (C) seo_prepare_changes: sanitization emptied the value ─────────────────
// A whitespace-only value clears every guard in seo_validate_plain_text (a
// space is \x20, outside the control range) and is then trimmed away by the
// provider sanitizer -- TSF's Sanitize::metadata_content() trims at
// sanitize.class.php:155, modeled at tsf-shim.php:269. trait-seo.php:680 turns
// that empty result into a refusal that names `clear` as the intended route,
// rather than silently storing an empty string.

diviops_seochar_reset();
diviops_test_register_post( 700, 'content', 'page', 'Page 700' );

$resp = diviops_seochar_set( '   ' );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a value the provider sanitizes to empty is refused' );
assert_same( 400, $resp->get_status(), 'the empty-sanitization refusal carries HTTP 400' );
assert_true( false === $data['error']['data']['mutated'], 'the empty-sanitization refusal reports no mutation' );
assert_same( 1, preg_match( '/produced an empty seo_title value/', $data['error']['message'] ), 'the message names the field whose sanitization emptied' );

// The field is genuinely untouched afterwards, not merely reported as such.
$row = array_column( diviops_call( 'seo_metadata_get', array( diviops_seochar_request( array( 'id' => 700 ) ) ) )->get_data()['data']['fields'], null, 'field' )['seo_title'];
assert_true( false === $row['explicit'], 'the refused field is still not explicit' );

// ── (D) seo_metadata_update pre-flight gates ────────────────────────────────
// test-seo.php characterizes these three on the read handler only. They are
// duplicated in seo_metadata_update() (trait-seo.php:328, :331, :336) rather
// than shared, so the write path's copies need their own coverage: the write
// handler's forbidden envelope additionally carries mutated=false, which the
// read handler's does not.

$resp = diviops_call( 'seo_metadata_update', array( diviops_seochar_request( array( 'id' => 0 ) ) ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'the write path rejects post_id 0' );
assert_same( 400, $resp->get_status(), 'the write path invalid_input carries HTTP 400' );

$resp = diviops_call( 'seo_metadata_update', array( diviops_seochar_request( array( 'id' => 4242 ) ) ) );
assert_same( 'not_found', $resp->get_data()['error']['code'], 'the write path rejects a missing post' );
assert_same( 404, $resp->get_status(), 'the write path not_found carries HTTP 404' );

// These two gates precede request-shape validation, so neither request above
// needed a well-formed checksum or changes list to reach them.

$GLOBALS['diviops_test_uneditable_ids'] = array( 700 );
$resp = diviops_call( 'seo_metadata_update', array( diviops_seochar_request( array(
	'id'                => 700,
	'expected_checksum' => 'sha256:' . str_repeat( '0', 64 ),
	'changes'           => array( array( 'field' => 'seo_title', 'action' => 'set', 'value' => 'Blocked' ) ),
) ) ) );
$data = $resp->get_data();
assert_same( 'forbidden', $data['error']['code'], 'the write path refuses a caller without edit rights' );
assert_same( 403, $resp->get_status(), 'the write path forbidden carries HTTP 403' );
assert_true( false === $data['error']['data']['mutated'], 'the write-path forbidden envelope reports no mutation' );
assert_true( false === $data['error']['data']['payload_exposed'], 'a forbidden write never claims payload exposure' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// The capability gate precedes the provider guard (trait-seo.php:336 before
// :346), so an uneditable post with an unknown provider reports forbidden --
// the caller learns nothing about provider state it may not query.
$GLOBALS['diviops_test_uneditable_ids'] = array( 700 );
$resp = diviops_call( 'seo_metadata_update', array( diviops_seochar_request( array( 'id' => 700, 'provider' => 'yoast' ) ) ) );
assert_same( 'forbidden', $resp->get_data()['error']['code'], 'the capability gate precedes the provider guard' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// Leave the shared process clean for tests/test-seo.php, which runs next.
diviops_seochar_reset();
