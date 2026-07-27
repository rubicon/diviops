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

// ── media_filetype_error(): SVG fail-closed on the sideload sanitizer (#28, #73) ─
// SVG is a stored-XSS vector, so it is allowed only when a sanitizer is verified
// active on the exact filter our upload path fires (wp_handle_sideload_prefilter).
// Allow svg mime for these cases so the ONLY gate under test is the sanitizer.

$GLOBALS['diviops_test_allowed_mimes']       = array( 'png' => 'image/png', 'svg' => 'image/svg+xml' );
$GLOBALS['diviops_test_filetype']['logo.svg'] = array( 'ext' => 'svg', 'type' => 'image/svg+xml', 'proper_filename' => false );

$GLOBALS['diviops_test_filters'] = array(); // no sanitizer
assert_true(
	null !== diviops_call_static( 'media_filetype_error', array( 'logo.svg', '/tmp/x' ) ),
	'svg rejected when no sideload sanitizer'
);

$GLOBALS['diviops_test_filters'] = array( 'wp_handle_sideload_prefilter' => 10 ); // sanitizer wired
assert_true(
	null === diviops_call_static( 'media_filetype_error', array( 'logo.svg', '/tmp/x' ) ),
	'svg accepted when sanitizer active'
);
unset( $GLOBALS['diviops_test_filters'], $GLOBALS['diviops_test_allowed_mimes'] );
$GLOBALS['diviops_test_allowed_mimes'] = array( 'png' => 'image/png' );
