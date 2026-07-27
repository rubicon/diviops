<?php
/**
 * media_ip_is_reserved() / media_url_is_safe() — SSRF address guard (#28).
 *
 * The url-upload feature this trait supports lets a user hand the plugin an
 * arbitrary URL to fetch. Without a guard, that is a textbook SSRF vector: a
 * URL that resolves to 169.254.169.254 (cloud metadata), 127.0.0.1, or an
 * RFC1918 address lets an attacker use the site's server as a proxy into
 * infrastructure it should never be able to reach. These are pure helpers —
 * media_ip_is_reserved() classifies a single IP, media_url_is_safe() parses a
 * URL, resolves its host (via an injectable resolver so DNS never touches the
 * network in tests), and rejects anything with a non-http(s) scheme, an
 * unresolvable host, or any resolved address in a reserved range.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

// ── media_ip_is_reserved(): reserved/private ranges are rejected ──────────

foreach (
	array(
		'10.0.0.5',
		'127.0.0.1',
		'169.254.169.254',
		'172.16.0.1',
		'192.168.1.1',
		'100.64.0.1',
		'::1',
		'fc00::1',
		'fe80::1',
		'::ffff:127.0.0.1',
	) as $ip
) {
	assert_true( diviops_call_static( 'media_ip_is_reserved', array( $ip ) ), "reserved: $ip" );
}

// ── media_ip_is_reserved(): public addresses are allowed ──────────────────

foreach (
	array(
		'8.8.8.8',
		'1.1.1.1',
		'203.0.114.1',
		'2606:4700:4700::1111',
	) as $ip
) {
	assert_true( ! diviops_call_static( 'media_ip_is_reserved', array( $ip ) ), "public: $ip" );
}

// ── media_url_is_safe(): scheme, resolution, and reserved-target checks ───
// $resolver is injected so these never touch real DNS.

$safe_resolver      = function ( $host ) { return array( '8.8.8.8' ); };
$internal_resolver  = function ( $host ) { return array( '169.254.169.254' ); };
$reason             = null;

assert_true(
	diviops_call_static( 'media_url_is_safe', array( 'https://cdn.example.com/a.png', &$reason, $safe_resolver ) ),
	'public https ok'
);
assert_true(
	! diviops_call_static( 'media_url_is_safe', array( 'https://evil.example/a.png', &$reason, $internal_resolver ) ),
	'resolves-internal rejected'
);
assert_true(
	! diviops_call_static( 'media_url_is_safe', array( 'file:///etc/passwd', &$reason, $safe_resolver ) ),
	'file scheme rejected'
);
assert_true(
	! diviops_call_static( 'media_url_is_safe', array( 'ftp://host/a.png', &$reason, $safe_resolver ) ),
	'ftp scheme rejected'
);

// ── media_filetype_error(): real-byte type check against allowed mimes ────
// $GLOBALS['diviops_test_filetype'] drives the wp_check_filetype_and_ext()
// shim; 'mismatch' models core's byte/extension-mismatch result.

$GLOBALS['diviops_test_filetype'] = array(
	'ok.png'   => array( 'ext' => 'png', 'type' => 'image/png', 'proper_filename' => false ),
	'fake.png' => 'mismatch',
	'doc.exe'  => array( 'ext' => 'exe', 'type' => 'application/x-msdownload', 'proper_filename' => false ),
);

assert_true(
	null === diviops_call_static( 'media_filetype_error', array( 'ok.png', '/tmp/x' ) ),
	'valid png accepted'
);
assert_true(
	null !== diviops_call_static( 'media_filetype_error', array( 'fake.png', '/tmp/x' ) ),
	'byte/ext mismatch rejected'
);
assert_true(
	null !== diviops_call_static( 'media_filetype_error', array( 'doc.exe', '/tmp/x' ) ),
	'disallowed mime rejected'
);

// ── media_filetype_error(): SVG fail-closed on Safe SVG's own callback (#28, #73) ─
// SVG is a stored-XSS vector, so it is allowed only when Safe SVG's OWN callback is
// verified active on the exact filter our upload path fires (wp_handle_sideload_
// prefilter) — has_filter() alone would only prove *something* is listening, not
// that it sanitizes, so the guard scans $wp_filter for a `safe_svg` instance.
// Allow svg mime for these cases so the ONLY gate under test is the sanitizer.

if ( ! class_exists( 'safe_svg' ) ) {
	class safe_svg {
		public function check_for_svg( $f ) { return $f; }
	}
}

$GLOBALS['diviops_test_allowed_mimes']        = array( 'png' => 'image/png', 'svg' => 'image/svg+xml' );
$GLOBALS['diviops_test_filetype']['logo.svg'] = array( 'ext' => 'svg', 'type' => 'image/svg+xml', 'proper_filename' => false );

// Case 1: Safe SVG's own callback registered — accepted.
$GLOBALS['wp_filter']['wp_handle_sideload_prefilter'] = array(
	10 => array( 'x' => array( 'function' => array( new safe_svg(), 'check_for_svg' ), 'accepted_args' => 1 ) ),
);
assert_true(
	null === diviops_call_static( 'media_filetype_error', array( 'logo.svg', '/tmp/x' ) ),
	'svg accepted when Safe SVG callback active'
);

// Case 2: an unrelated callback on the same filter — rejected (not a sanitizer).
$GLOBALS['wp_filter']['wp_handle_sideload_prefilter'] = array(
	10 => array( 'y' => array( 'function' => 'strlen', 'accepted_args' => 1 ) ),
);
assert_true(
	null !== diviops_call_static( 'media_filetype_error', array( 'logo.svg', '/tmp/x' ) ),
	'svg rejected when only an unrelated callback is on the filter'
);

// Case 3: nothing wired — rejected.
$GLOBALS['wp_filter']['wp_handle_sideload_prefilter'] = array();
assert_true(
	null !== diviops_call_static( 'media_filetype_error', array( 'logo.svg', '/tmp/x' ) ),
	'svg rejected when no sideload sanitizer'
);

unset( $GLOBALS['wp_filter'], $GLOBALS['diviops_test_allowed_mimes'] );
$GLOBALS['diviops_test_allowed_mimes'] = array( 'png' => 'image/png' );

// ── media_upload(): url + base64 sources, xor validation, SSRF, dry-run (#28) ──
// Test-global hygiene: this suite sets diviops_test_allowed_mimes, diviops_test_
// filetype, diviops_test_posts/post_meta, diviops_test_attachments, and the
// diviops_media_host_resolver filter; every one is reset/unset at the end so
// nothing leaks into test files that share this PHP process.

function diviops_media_req( array $p ) {
	return new DiviOps_Test_Request( $p );
}

function diviops_media_set_resolver( callable $fn ) {
	remove_all_filters( 'diviops_media_host_resolver' );
	add_filter(
		'diviops_media_host_resolver',
		function () use ( $fn ) {
			return $fn;
		}
	);
}

$GLOBALS['diviops_test_allowed_mimes'] = array( 'png' => 'image/png' );
$GLOBALS['diviops_test_filetype']      = array(
	'pic.png' => array(
		'ext'             => 'png',
		'type'            => 'image/png',
		'proper_filename' => false,
	),
);
$GLOBALS['diviops_test_posts']         = array();
$GLOBALS['diviops_test_post_meta']     = array();
$GLOBALS['diviops_test_attachments']   = array();

// url happy path — inject a safe resolver via the extensibility filter seam.
diviops_media_set_resolver(
	function ( $h ) {
		return array( '8.8.8.8' );
	}
);
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url' => 'https://cdn.example.com/pic.png' ) ) ) );
$d = $r->get_data();
assert_true( true === $d['ok'] && ! empty( $d['data']['attachment_id'] ), 'url upload ok' );
assert_true( 'url' === ( $d['data']['source'] ?? null ), 'url upload reports source=url' );

// neither url nor base64
$r = diviops_call( 'media_upload', array( diviops_media_req( array() ) ) );
assert_true( false === $r->get_data()['ok'], 'missing source rejected' );
assert_true( 'invalid_input' === $r->get_data()['error']['code'], 'missing source: invalid_input code' );

// both
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url' => 'https://x/y.png', 'data_base64' => 'AA', 'filename' => 'y.png' ) ) ) );
assert_true( false === $r->get_data()['ok'], 'both sources rejected' );
assert_true( 'invalid_input' === $r->get_data()['error']['code'], 'both sources: invalid_input code' );

// SSRF
diviops_media_set_resolver(
	function ( $h ) {
		return array( '169.254.169.254' );
	}
);
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url' => 'https://evil/pic.png' ) ) ) );
$d = $r->get_data();
assert_true( false === $d['ok'] && 'forbidden_target' === $d['error']['code'], 'ssrf blocked' );

// dry_run (safe again)
diviops_media_set_resolver(
	function ( $h ) {
		return array( '8.8.8.8' );
	}
);
$attachments_before_dry_run = count( $GLOBALS['diviops_test_attachments'] );
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url' => 'https://cdn.example.com/pic.png', 'dry_run' => true ) ) ) );
$d = $r->get_data();
assert_true( true === $d['ok'] && isset( $d['data']['plan'] ), 'dry-run returns plan, no write' );
assert_true( $attachments_before_dry_run === count( $GLOBALS['diviops_test_attachments'] ), 'dry-run performs no write' );

// base64 happy path
$GLOBALS['diviops_test_filetype']['photo.png'] = array(
	'ext'             => 'png',
	'type'            => 'image/png',
	'proper_filename' => false,
);
$r = diviops_call(
	'media_upload',
	array( diviops_media_req( array( 'data_base64' => base64_encode( 'fake-png-bytes' ), 'filename' => 'photo.png' ) ) )
);
$d = $r->get_data();
assert_true( true === $d['ok'] && ! empty( $d['data']['attachment_id'] ), 'base64 upload ok' );
assert_true( 'base64' === ( $d['data']['source'] ?? null ), 'base64 upload reports source=base64' );

// base64 missing filename
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'data_base64' => 'AA' ) ) ) );
$d = $r->get_data();
assert_true( false === $d['ok'] && 'invalid_input' === $d['error']['code'], 'base64 without filename rejected' );

// base64 invalid encoding
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'data_base64' => '***not base64***', 'filename' => 'x.png' ) ) ) );
$d = $r->get_data();
assert_true( false === $d['ok'] && 'invalid_input' === $d['error']['code'], 'invalid base64 rejected' );

// base64 payload too large
$GLOBALS['diviops_test_max_upload'] = 10;
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'data_base64' => base64_encode( str_repeat( 'x', 100 ) ), 'filename' => 'big.png' ) ) ) );
$d = $r->get_data();
assert_true( false === $d['ok'] && 'payload_too_large' === $d['error']['code'], 'oversized base64 payload rejected' );
unset( $GLOBALS['diviops_test_max_upload'] );

// type/SVG rejection propagates through media_upload (disallowed mime)
$GLOBALS['diviops_test_filetype']['bad.exe'] = array(
	'ext'             => 'exe',
	'type'            => 'application/x-msdownload',
	'proper_filename' => false,
);
$r = diviops_call(
	'media_upload',
	array( diviops_media_req( array( 'data_base64' => base64_encode( 'MZ...' ), 'filename' => 'bad.exe' ) ) )
);
$d = $r->get_data();
assert_true( false === $d['ok'] && 'unsupported_media_type' === $d['error']['code'], 'disallowed type rejected through media_upload' );

// fetch failure surfaces as fetch_failed
$GLOBALS['diviops_test_download_fail'] = true;
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url' => 'https://cdn.example.com/pic.png' ) ) ) );
$d = $r->get_data();
assert_true( false === $d['ok'] && 'fetch_failed' === $d['error']['code'], 'download failure surfaces as fetch_failed' );
unset( $GLOBALS['diviops_test_download_fail'] );

// Test-global hygiene: unset everything this suite set so later test files
// (which share this PHP process) start from a clean slate.
remove_all_filters( 'diviops_media_host_resolver' );
unset(
	$GLOBALS['diviops_test_allowed_mimes'],
	$GLOBALS['diviops_test_filetype'],
	$GLOBALS['diviops_test_posts'],
	$GLOBALS['diviops_test_post_meta'],
	$GLOBALS['diviops_test_attachments'],
	$GLOBALS['diviops_test_next_attach_id'],
	$GLOBALS['diviops_test_download_bytes'],
	$GLOBALS['diviops_test_sideload_mime']
);
$GLOBALS['diviops_test_allowed_mimes'] = array( 'png' => 'image/png' );
