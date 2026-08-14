<?php
/**
 * Admin-configurable capability gate for SVG uploads (#73).
 *
 * #28 gates every media upload at `upload_files` and additionally refuses an SVG
 * unless Safe SVG's own callback is verified active on wp_handle_sideload_prefilter.
 * The sanitizer is the primary defense; this adds a second, independent one, so a
 * site can require a higher capability for SVG specifically (sanitizer bypasses have
 * shipped before — enshrined/svg-sanitize <= 0.15.4, SVG Support <= 2.5.7).
 *
 * The two layers are deliberately independent: neither can satisfy the other. The
 * cases below prove that in both directions — a caller holding the configured
 * capability still cannot upload an SVG without a verified sanitizer, and a site
 * running Safe SVG still refuses a caller without the capability.
 *
 * Configuration follows this plugin's established surface for operator settings
 * (the rate-limit constants): a `DIVIOPS_SVG_UPLOAD_CAPABILITY` wp-config.php
 * constant, else the env var of the same name, else the shipped default
 * `upload_files`, which preserves #28 behavior unchanged.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

// Safe SVG stand-ins. Named/aliased exactly as test-media.php does — Safe SVG 2.x's
// real class is namespaced (SafeSvg\safe_svg), 1.x's is global — and guarded so the
// two files can share one PHP process whichever runs first.
if ( ! class_exists( 'SafeSvg\\safe_svg' ) ) {
	class DiviOps_Test_SafeSvg_Namespaced {
		public function check_for_svg( $f ) { return $f; }
	}
	class_alias( 'DiviOps_Test_SafeSvg_Namespaced', 'SafeSvg\\safe_svg' );
}

/** Bind Safe SVG's own callback to the sideload prefilter, as an active install does. */
function diviops_svg_gate_sanitizer_active() {
	$GLOBALS['wp_filter']['wp_handle_sideload_prefilter'] = array(
		10 => array( 'safe_svg' => array( 'function' => array( new SafeSvg\safe_svg(), 'check_for_svg' ), 'accepted_args' => 1 ) ),
	);
}

/** Leave the sideload prefilter with nothing bound to it (no sanitizer installed). */
function diviops_svg_gate_sanitizer_absent() {
	$GLOBALS['wp_filter']['wp_handle_sideload_prefilter'] = array();
}

/** Set the operator-configured capability via the env var, or clear it entirely. */
function diviops_svg_gate_configure( $value ) {
	if ( null === $value ) {
		putenv( 'DIVIOPS_SVG_UPLOAD_CAPABILITY' );
		return;
	}
	putenv( 'DIVIOPS_SVG_UPLOAD_CAPABILITY=' . $value );
}

$GLOBALS['diviops_test_allowed_mimes'] = array( 'png' => 'image/png', 'svg' => 'image/svg+xml' );
$GLOBALS['diviops_test_filetype']      = array(
	'logo.svg' => array( 'ext' => 'svg', 'type' => 'image/svg+xml', 'proper_filename' => false ),
	'ok.png'   => array( 'ext' => 'png', 'type' => 'image/png', 'proper_filename' => false ),
);

// ── Resolution: constant > env var > shipped default ──────────────────────

diviops_svg_gate_configure( null );
assert_same(
	'upload_files',
	diviops_call_static( 'media_svg_upload_capability' ),
	'unconfigured: default capability is upload_files (#28 behavior preserved)'
);

diviops_svg_gate_configure( 'manage_options' );
assert_same(
	'manage_options',
	diviops_call_static( 'media_svg_upload_capability' ),
	'configured: env var raises the required capability'
);

diviops_svg_gate_configure( 'Manage_Options' );
assert_same(
	'manage_options',
	diviops_call_static( 'media_svg_upload_capability' ),
	'configured: capability is normalized through sanitize_key (case)'
);

// An empty env var is not an operator expressing intent — containers and CI set
// variables to the empty string routinely — so it falls through to the default,
// matching how the rate-limit constants treat an empty env value.
diviops_svg_gate_configure( '' );
assert_same(
	'upload_files',
	diviops_call_static( 'media_svg_upload_capability' ),
	'empty env var falls through to the default'
);

// A configured value that cannot be a capability is a misconfiguration, and the one
// thing it must never do is silently restore the permissive default: that would turn
// a typo in an operator's hardening into a security downgrade they cannot see. It
// resolves to do_not_allow, which WordPress core denies to everyone, including a
// multisite super admin (wp-includes/class-wp-user.php, WP_User::has_cap()).
diviops_svg_gate_configure( '!!!' );
assert_same(
	'do_not_allow',
	diviops_call_static( 'media_svg_upload_capability' ),
	'unusable configured value fails closed to do_not_allow, never back to the default'
);

// ── The wp-config.php constant wins over the env var ──────────────────────
// Run in a child process on purpose: a constant cannot be undefined once set, so
// defining it here would silently pin every later case in this shared process. The
// child loads the same shim and calls the same production method, so this is the
// real resolution path, not a restatement of it.

$constant_probe = sprintf(
	'define( "DIVIOPS_SVG_UPLOAD_CAPABILITY", "manage_options" );'
	. ' putenv( "DIVIOPS_SVG_UPLOAD_CAPABILITY=edit_posts" );'
	. ' require %s;'
	. ' echo diviops_call_static( "media_svg_upload_capability" );',
	var_export( __DIR__ . '/wp-shim.php', true )
);
$constant_result = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $constant_probe ) . ' 2>&1' );
assert_same(
	'manage_options',
	trim( (string) $constant_result ),
	'wp-config.php constant takes precedence over the env var'
);

// ── The gate denies, and denies before disclosing sanitizer state ─────────

diviops_svg_gate_configure( 'manage_options' );
diviops_svg_gate_sanitizer_active();
$GLOBALS['diviops_test_denied_caps'] = array( 'manage_options' );

$err = diviops_call_static( 'media_filetype_error', array( 'logo.svg', '/tmp/x' ) );
assert_true( is_array( $err ), 'capability denied: svg rejected even with Safe SVG active' );
assert_same( 'svg_capability_required', $err['code'] ?? null, 'capability denied: svg_capability_required code' );
assert_same( 403, $err['http'] ?? null, 'capability denied: 403' );
assert_same( 'manage_options', $err['data']['required_capability'] ?? null, 'capability denied: names the required capability' );

// Capability is checked before the sanitizer, so a caller who is not allowed to
// upload SVG at all learns nothing about which security plugins the site runs.
diviops_svg_gate_sanitizer_absent();
$err = diviops_call_static( 'media_filetype_error', array( 'logo.svg', '/tmp/x' ) );
assert_same(
	'svg_capability_required',
	$err['code'] ?? null,
	'capability denied + no sanitizer: capability is reported, not the site sanitizer state'
);

// Non-SVG media is untouched by the setting: the same denied capability, same
// request shape, an ordinary PNG — allowed.
assert_same(
	null,
	diviops_call_static( 'media_filetype_error', array( 'ok.png', '/tmp/x' ) ),
	'non-svg upload is unaffected by the svg capability gate'
);

unset( $GLOBALS['diviops_test_denied_caps'] );

// ── Granting the capability does NOT satisfy the Safe SVG requirement ─────
// The layers are independent. This is the case that would silently regress if a
// future change folded one check into the other.

diviops_svg_gate_sanitizer_absent();
$err = diviops_call_static( 'media_filetype_error', array( 'logo.svg', '/tmp/x' ) );
assert_true( is_array( $err ), 'capability granted, no sanitizer: still rejected' );
assert_same( 'svg_sanitizer_required', $err['code'] ?? null, 'capability granted, no sanitizer: svg_sanitizer_required code' );
assert_same( 415, $err['http'] ?? null, 'capability granted, no sanitizer: 415' );

diviops_svg_gate_sanitizer_active();
assert_same(
	null,
	diviops_call_static( 'media_filetype_error', array( 'logo.svg', '/tmp/x' ) ),
	'capability granted + sanitizer active: svg accepted'
);

// ── Default configuration still accepts SVG for an upload_files caller ────
// The gate is opt-in. On an unconfigured site a caller holding only upload_files
// (explicitly denied manage_options here) uploads SVG exactly as it did in #28.

diviops_svg_gate_configure( null );
$GLOBALS['diviops_test_denied_caps'] = array( 'manage_options' );
assert_same(
	null,
	diviops_call_static( 'media_filetype_error', array( 'logo.svg', '/tmp/x' ) ),
	'unconfigured site: upload_files caller still uploads svg (#28 unchanged)'
);
unset( $GLOBALS['diviops_test_denied_caps'] );

// ── End to end through media_upload(): both source paths ──────────────────

$GLOBALS['diviops_test_posts']       = array();
$GLOBALS['diviops_test_post_meta']   = array();
$GLOBALS['diviops_test_attachments'] = array();

remove_all_filters( 'diviops_media_host_resolver' );
add_filter(
	'diviops_media_host_resolver',
	function () {
		return function ( $host ) {
			return array( '8.8.8.8' );
		};
	}
);

$svg_bytes = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';

diviops_svg_gate_configure( 'manage_options' );
diviops_svg_gate_sanitizer_active();
$GLOBALS['diviops_test_denied_caps'] = array( 'manage_options' );

// base64 path
$r = diviops_call(
	'media_upload',
	array( new DiviOps_Test_Request( array( 'data_base64' => base64_encode( $svg_bytes ), 'filename' => 'logo.svg' ) ) )
);
$d = $r->get_data();
assert_same( false, $d['ok'], 'base64 svg, capability denied: not ok' );
assert_same( 'svg_capability_required', $d['error']['code'] ?? null, 'base64 svg, capability denied: svg_capability_required' );
assert_same( 403, $r->get_status(), 'base64 svg, capability denied: 403' );

// url path
$GLOBALS['diviops_test_http_responses'] = array(
	array(
		'response' => array( 'code' => 200 ),
		'headers'  => array(),
		'body'     => $svg_bytes,
	),
);
$r = diviops_call(
	'media_upload',
	array( new DiviOps_Test_Request( array( 'url' => 'https://cdn.example.com/logo.svg' ) ) )
);
$d = $r->get_data();
assert_same( false, $d['ok'], 'url svg, capability denied: not ok' );
assert_same( 'svg_capability_required', $d['error']['code'] ?? null, 'url svg, capability denied: svg_capability_required' );
assert_same( 403, $r->get_status(), 'url svg, capability denied: 403' );
assert_same( array(), $GLOBALS['diviops_test_attachments'], 'capability denied: no attachment was created by either path' );

// The gate only ever ADDS a requirement. Configuring a capability weaker than
// upload_files cannot unlock an upload for a caller who lacks upload_files, because
// media_upload()'s own gate runs first and this one is layered on top of it.
diviops_svg_gate_configure( 'read' );
$GLOBALS['diviops_test_denied_caps'] = array( 'upload_files' );
$r = diviops_call(
	'media_upload',
	array( new DiviOps_Test_Request( array( 'data_base64' => base64_encode( $svg_bytes ), 'filename' => 'logo.svg' ) ) )
);
$d = $r->get_data();
assert_same( false, $d['ok'], 'weaker configured capability cannot bypass the upload_files gate' );
assert_same( 'forbidden', $d['error']['code'] ?? null, 'weaker configured capability: upload_files denial still reported' );

unset( $GLOBALS['diviops_test_denied_caps'] );

// Granted end to end: the same base64 SVG uploads once the capability is held and
// the sanitizer is active, so the gate is not simply refusing everything.
diviops_svg_gate_configure( 'manage_options' );
$GLOBALS['diviops_test_sideload_mime'] = 'image/svg+xml';
$r = diviops_call(
	'media_upload',
	array( new DiviOps_Test_Request( array( 'data_base64' => base64_encode( $svg_bytes ), 'filename' => 'logo.svg' ) ) )
);
$d = $r->get_data();
assert_same( true, $d['ok'], 'base64 svg, capability granted + sanitizer active: uploaded' );
assert_true( ! empty( $d['data']['attachment_id'] ), 'base64 svg, capability granted: attachment created' );

// ── Test-global hygiene ───────────────────────────────────────────────────
// Every global and env var this file set is cleared, since the runner shares one
// PHP process across all test files.

diviops_svg_gate_configure( null );
remove_all_filters( 'diviops_media_host_resolver' );
unset(
	$GLOBALS['wp_filter'],
	$GLOBALS['diviops_test_allowed_mimes'],
	$GLOBALS['diviops_test_filetype'],
	$GLOBALS['diviops_test_denied_caps'],
	$GLOBALS['diviops_test_sideload_mime'],
	$GLOBALS['diviops_test_http_responses']
);
$GLOBALS['diviops_test_posts']       = array();
$GLOBALS['diviops_test_post_meta']   = array();
$GLOBALS['diviops_test_attachments'] = array();
