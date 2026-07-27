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

// Resolver used by every redirect-hop test below: a literal-IP host (a
// redirect Location can point straight at an IP, as the SSRF finding's
// scenario does) resolves to itself; anything else resolves to a public
// address. This lets one resolver drive an entire hop chain, including a
// hop that lands on a raw internal IP, without per-hostname bookkeeping.
function diviops_media_resolver_ip_literal_or_public( $host ) {
	return array( false !== filter_var( $host, FILTER_VALIDATE_IP ) ? $host : '8.8.8.8' );
}

function diviops_media_http_200( $body = 'fake-image-bytes' ) {
	return array(
		'response' => array( 'code' => 200 ),
		'headers'  => array(),
		'body'     => $body,
	);
}

function diviops_media_http_redirect( $location ) {
	return array(
		'response' => array( 'code' => 302 ),
		'headers'  => array( 'location' => $location ),
		'body'     => '',
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
// A direct 200 is one hop: no redirect involved.
diviops_media_set_resolver(
	function ( $h ) {
		return array( '8.8.8.8' );
	}
);
$GLOBALS['diviops_test_http_responses'] = array( diviops_media_http_200() );
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

// ── Redirect-hop SSRF revalidation (#28 fix) ───────────────────────────────
// The initial-URL SSRF test above proves the guard catches a directly-blocked
// URL. These prove it also catches a URL that is safe on its face but 302s to
// an internal target — the exact bypass download_url()'s auto-follow allowed.

// A public origin 302s straight to the cloud-metadata IP: the redirect hop
// must be revalidated and rejected, not silently followed.
diviops_media_set_resolver( 'diviops_media_resolver_ip_literal_or_public' );
$GLOBALS['diviops_test_http_responses'] = array(
	diviops_media_http_redirect( 'http://169.254.169.254/latest/meta-data/' ),
);
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url' => 'https://cdn.example.com/pic.png' ) ) ) );
$d = $r->get_data();
assert_true(
	false === $d['ok'] && 'forbidden_target' === $d['error']['code'],
	'redirect hop to an internal target is blocked, not silently followed'
);

// A public origin 302s to another public origin, which serves the file: the
// chain is followed and the upload succeeds.
diviops_media_set_resolver( 'diviops_media_resolver_ip_literal_or_public' );
$GLOBALS['diviops_test_http_responses']    = array(
	diviops_media_http_redirect( 'https://cdn2.example.com/final.png' ),
	diviops_media_http_200(),
);
$GLOBALS['diviops_test_remote_get_calls']  = array();
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url' => 'https://cdn.example.com/pic.png' ) ) ) );
$d = $r->get_data();
assert_true(
	true === $d['ok'] && ! empty( $d['data']['attachment_id'] ),
	'a public-to-public redirect chain is followed and succeeds'
);
// Guards against a regression that re-enables WP's own redirect-following
// (which would silently bypass the per-hop revalidation this fix exists
// for): every hop must be requested with redirection disabled.
$redirection_disabled_every_hop = true;
foreach ( $GLOBALS['diviops_test_remote_get_calls'] as $call ) {
	if ( 0 !== ( $call['args']['redirection'] ?? null ) ) {
		$redirection_disabled_every_hop = false;
	}
}
assert_true(
	2 === count( $GLOBALS['diviops_test_remote_get_calls'] ) && $redirection_disabled_every_hop,
	'every hop is fetched with redirection disabled (no auto-follow bypass)'
);

// A redirect chain that never terminates within the bounded hop count fails
// closed rather than looping or following indefinitely.
diviops_media_set_resolver( 'diviops_media_resolver_ip_literal_or_public' );
$GLOBALS['diviops_test_http_responses'] = array_fill( 0, 6, diviops_media_http_redirect( 'https://cdn.example.com/pic.png' ) );
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url' => 'https://cdn.example.com/pic.png' ) ) ) );
$d = $r->get_data();
assert_true(
	false === $d['ok'] && 'fetch_failed' === $d['error']['code'],
	'a redirect chain exceeding the bounded hop count is rejected'
);

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

// network failure (wp_remote_get returns a WP_Error) surfaces as fetch_failed
diviops_media_set_resolver(
	function ( $h ) {
		return array( '8.8.8.8' );
	}
);
$GLOBALS['diviops_test_http_responses'] = array( new WP_Error( 'http_request_failed', 'connection refused' ) );
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url' => 'https://cdn.example.com/pic.png' ) ) ) );
$d = $r->get_data();
assert_true( false === $d['ok'] && 'fetch_failed' === $d['error']['code'], 'network failure surfaces as fetch_failed' );

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
	$GLOBALS['diviops_test_http_responses'],
	$GLOBALS['diviops_test_remote_get_calls'],
	$GLOBALS['diviops_test_sideload_mime']
);
$GLOBALS['diviops_test_allowed_mimes'] = array( 'png' => 'image/png' );

// ── media_get() / media_list(): read + pagination (#28 Task 5) ────────────
// Test-global hygiene: this suite sets diviops_test_posts, diviops_test_
// attachments, diviops_test_post_meta, diviops_test_attachment_metadata;
// every one is reset/unset at the end so nothing leaks into files that share
// this PHP process.

$GLOBALS['diviops_test_posts']                = array();
$GLOBALS['diviops_test_attachments']          = array();
$GLOBALS['diviops_test_post_meta']            = array();
$GLOBALS['diviops_test_attachment_metadata']  = array();

/**
 * Register a fixture attachment in BOTH stores the handlers read from: the
 * shared post registry (post_type/post_status/post_mime_type/post_title —
 * what WP_Query scans) and the attachments registry (url/mime — what
 * wp_get_attachment_url()/get_post_mime_type() read). Real WordPress keeps
 * all of this on one post row; this harness splits it across two stubs (see
 * wp-shim.php), so a fixture has to satisfy both.
 */
function diviops_media_register_attachment( int $id, string $title, string $mime, string $filename, int $parent = 0 ) {
	$post                 = diviops_test_register_post( $id, '', 'attachment', $title );
	$post->post_status    = 'inherit';
	$post->post_mime_type = $mime;

	$GLOBALS['diviops_test_attachments'][ $id ] = array(
		'id'       => $id,
		'filename' => $filename,
		'parent'   => $parent,
		'url'      => "https://site/wp-content/uploads/{$filename}",
		'mime'     => $mime,
	);

	return $post;
}

// ── media_get(): found ─────────────────────────────────────────────────
diviops_media_register_attachment( 501, 'Photo One', 'image/jpeg', 'photo-one.jpg' );
$GLOBALS['diviops_test_posts'][501]->post_excerpt = 'A lovely photo';
update_post_meta( 501, '_wp_attachment_image_alt', 'A descriptive alt' );
$GLOBALS['diviops_test_attachment_metadata'][501] = array(
	'width'  => 1200,
	'height' => 800,
	'sizes'  => array(
		'thumbnail' => array( 'file' => 'photo-one-150x150.jpg', 'width' => 150, 'height' => 150 ),
	),
);

$r = diviops_call( 'media_get', array( diviops_media_req( array( 'id' => 501 ) ) ) );
$d = $r->get_data();
assert_true( true === $d['ok'], 'media_get found: ok' );
assert_true( 501 === $d['data']['attachment_id'], 'media_get found: attachment_id' );
assert_true( 'https://site/wp-content/uploads/photo-one.jpg' === $d['data']['url'], 'media_get found: url' );
assert_true( 'image/jpeg' === $d['data']['mime'], 'media_get found: mime' );
assert_true( 'Photo One' === $d['data']['title'], 'media_get found: title' );
assert_true( 'A descriptive alt' === $d['data']['alt'], 'media_get found: alt' );
assert_true( 'A lovely photo' === $d['data']['caption'], 'media_get found: caption' );
assert_true(
	isset( $d['data']['sizes']['thumbnail']['file'] ) && 'photo-one-150x150.jpg' === $d['data']['sizes']['thumbnail']['file'],
	'media_get found: sizes'
);

// ── media_get(): not_found (missing id) ────────────────────────────────
$r = diviops_call( 'media_get', array( diviops_media_req( array( 'id' => 999999 ) ) ) );
$d = $r->get_data();
assert_true( false === $d['ok'], 'media_get missing id: not ok' );
assert_true( 'not_found' === $d['error']['code'], 'media_get missing id: not_found code' );
assert_true( 404 === $r->get_status(), 'media_get missing id: 404' );
assert_true( 999999 === $d['error']['data']['attachment_id'], 'media_get missing id: error data attachment_id' );

// ── media_get(): not_found (id exists but is not an attachment) ───────
diviops_test_register_post( 502, '', 'page', 'A Regular Page' );
$r = diviops_call( 'media_get', array( diviops_media_req( array( 'id' => 502 ) ) ) );
$d = $r->get_data();
assert_true( false === $d['ok'], 'media_get non-attachment: not ok' );
assert_true( 'not_found' === $d['error']['code'], 'media_get non-attachment: not_found code' );

// ── media_list(): pagination ────────────────────────────────────────────
// Reset: the media_get fixtures above (501 attachment, 502 non-attachment)
// must not leak into these counts.
$GLOBALS['diviops_test_posts']       = array();
$GLOBALS['diviops_test_attachments'] = array();

for ( $i = 1; $i <= 25; $i++ ) {
	diviops_media_register_attachment( 600 + $i, "Item {$i}", 'image/png', "item-{$i}.png" );
}

$r = diviops_call( 'media_list', array( diviops_media_req( array( 'page' => 1, 'per_page' => 10 ) ) ) );
$d = $r->get_data();
assert_true( true === $d['ok'], 'media_list page 1: ok' );
assert_true( 25 === $d['data']['total'], 'media_list page 1: total' );
assert_true( 1 === $d['data']['page'], 'media_list page 1: page' );
assert_true( 10 === $d['data']['per_page'], 'media_list page 1: per_page' );
assert_true( 10 === count( $d['data']['items'] ), 'media_list page 1: item count' );

$r = diviops_call( 'media_list', array( diviops_media_req( array( 'page' => 3, 'per_page' => 10 ) ) ) );
$d = $r->get_data();
assert_true( 5 === count( $d['data']['items'] ), 'media_list page 3: remainder item count' );
assert_true( 3 === $d['data']['page'], 'media_list page 3: page' );

// default per_page
$r = diviops_call( 'media_list', array( diviops_media_req( array() ) ) );
$d = $r->get_data();
assert_true( 20 === $d['data']['per_page'], 'media_list default per_page is 20' );
assert_true( 20 === count( $d['data']['items'] ), 'media_list default per_page: item count' );

// per_page clamp
$r = diviops_call( 'media_list', array( diviops_media_req( array( 'per_page' => 0 ) ) ) );
assert_true( 1 === $r->get_data()['data']['per_page'], 'media_list per_page clamps up to 1' );
$r = diviops_call( 'media_list', array( diviops_media_req( array( 'per_page' => 500 ) ) ) );
assert_true( 100 === $r->get_data()['data']['per_page'], 'media_list per_page clamps down to 100' );

// item shape
$r     = diviops_call( 'media_list', array( diviops_media_req( array( 'page' => 1, 'per_page' => 1 ) ) ) );
$item  = $r->get_data()['data']['items'][0];
assert_true(
	isset( $item['attachment_id'], $item['url'], $item['mime'], $item['title'], $item['filename'] ),
	'media_list item shape has attachment_id/url/mime/title/filename'
);

// ── media_list(): mime prefix filter ────────────────────────────────────
diviops_media_register_attachment( 701, 'A PDF report', 'application/pdf', 'report.pdf' );
diviops_media_register_attachment( 702, 'A PNG banner', 'image/png', 'banner.png' );

$r = diviops_call( 'media_list', array( diviops_media_req( array( 'mime' => 'application/pdf' ) ) ) );
$d = $r->get_data();
$ids = array_column( $d['data']['items'], 'attachment_id' );
assert_true( in_array( 701, $ids, true ) && ! in_array( 702, $ids, true ), 'media_list mime filter: exact application/pdf' );

$r = diviops_call( 'media_list', array( diviops_media_req( array( 'mime' => 'image/', 'per_page' => 100 ) ) ) );
$d = $r->get_data();
$ids = array_column( $d['data']['items'], 'attachment_id' );
assert_true( in_array( 702, $ids, true ) && ! in_array( 701, $ids, true ), 'media_list mime filter: image/ prefix excludes pdf' );

// ── media_list(): search ────────────────────────────────────────────────
diviops_media_register_attachment( 703, 'Unique Search Target', 'image/png', 'target.png' );
$r = diviops_call( 'media_list', array( diviops_media_req( array( 'search' => 'Unique Search' ) ) ) );
$d = $r->get_data();
assert_true( 1 === $d['data']['total'], 'media_list search: matches exactly one' );
assert_true( 703 === $d['data']['items'][0]['attachment_id'], 'media_list search: matched id' );

$r = diviops_call( 'media_list', array( diviops_media_req( array( 'search' => 'no-such-title-anywhere' ) ) ) );
assert_true( 0 === $r->get_data()['data']['total'], 'media_list search: no match yields zero total' );

// Test-global hygiene: unset everything this suite set so later test files
// start from a clean slate.
unset(
	$GLOBALS['diviops_test_posts'],
	$GLOBALS['diviops_test_attachments'],
	$GLOBALS['diviops_test_post_meta'],
	$GLOBALS['diviops_test_attachment_metadata']
);
