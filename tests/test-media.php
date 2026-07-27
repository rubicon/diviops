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
