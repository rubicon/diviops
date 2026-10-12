<?php
// SPDX-License-Identifier: MIT
/**
 * update_theme_options() must report the DATABASE, not the request (#576).
 *
 * The handler calls `et_update_option()` and discards its return value, records
 * `$updated[ $key ]` from the value it SENT, and returns `'success' => true`
 * unconditionally — no branch can falsify it once the payload shape validates. Two
 * different failures hide behind that, and only the first is caught by reading back:
 *
 *   1. A key was allowlisted, written, and the write did not land.
 *   2. A key was never eligible, was dropped, and the caller was told `success: true`.
 *
 * (2) matters because an MCP caller cannot distinguish "wrote nothing because you asked
 * for nothing" from "wrote nothing because I rejected everything you sent". The owner's
 * decision (2026-10-12) is that `success` means every key you sent is now the state of
 * the database, so this file pins all three parts: readback-derived `updated`, an
 * explicit report of dropped keys, and `success: false` when any requested key was not
 * written.
 *
 * Coverage measured before writing: `tests/test-meta-characterization.php` section B2
 * is the only place that touches this handler's allowlist, and nothing anywhere asserts
 * the `success` VALUE — `grep -n "\$body\['success'\]" tests/` returns no match against
 * a positive control that finds `$body['updated']`. So the assertions below are new
 * ground rather than a second opinion, and B2's own pins move deliberately with them.
 *
 * NOT covered here: whether `et_update_option()`'s return value is a trustworthy
 * success signal. The readback deliberately goes through `get_option( 'et_divi' )`, the
 * same surface the read path uses, because that function's contract is not established.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/*
 * Declared BEFORE divi-active-shim.php, whose own et_update_option() is guarded by
 * function_exists() and would otherwise win. This models the same one-row storage path
 * — read `et_<shortname>`, set the single key, write the whole array back — and adds
 * the one thing that path cannot express: a write that reports success and stores
 * nothing. The real function returns a bool the handler throws away, so a write that
 * never landed is today indistinguishable from one that did. That seam is the only way
 * to assert the handler reads back rather than echoing its own request.
 *
 * Child mode (#395) runs every test file in its own process, so this declaration cannot
 * reach another file's function table.
 */
if ( ! function_exists( 'et_update_option' ) ) {
	/**
	 * Divi's one-row option writer, with a test-controlled silent-failure set.
	 *
	 * @param string $option_name Option key.
	 * @param mixed  $new_value   Value to store.
	 * @return bool
	 */
	function et_update_option( $option_name, $new_value, $is_new_global_setting = false, $global_setting_main_name = '', $global_setting_sub_name = '', $is_product_setting = false ) {
		if ( in_array( (string) $option_name, (array) ( $GLOBALS['diviops_tor_drop'] ?? array() ), true ) ) {
			return true;
		}
		$name          = 'et_' . ( $GLOBALS['diviops_test_et_shortname'] ?? 'divi' );
		$theme_options = get_option( $name );
		if ( ! is_array( $theme_options ) ) {
			$theme_options = array();
		}
		$theme_options[ $option_name ] = $new_value;

		return update_option( $name, $theme_options );
	}
}

require_once __DIR__ . '/divi-active-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

/**
 * Call the handler with an `options` payload and return its body.
 *
 * @param array $options The options map to send.
 * @return array
 */
function diviops_tor_call( array $options ): array {
	$response = diviops_call( 'update_theme_options', array( new DiviOps_Test_Request( array( 'options' => $options ) ) ) );
	$data     = $response instanceof WP_REST_Response ? $response->get_data() : $response;

	return is_array( $data ) ? $data : array();
}

/**
 * Read one key straight out of Divi's stored option row.
 *
 * @param string $key Option key.
 * @return mixed Null when absent, so an absent key is distinguishable from a stored ''.
 */
function diviops_tor_stored( string $key ) {
	$row = get_option( 'et_' . ( $GLOBALS['diviops_test_et_shortname'] ?? 'divi' ) );

	return is_array( $row ) && array_key_exists( $key, $row ) ? $row[ $key ] : null;
}

/** Clear the stored row and the silent-failure set between cases. */
function diviops_tor_reset(): void {
	$GLOBALS['diviops_tor_drop'] = array();
	update_option( 'et_' . ( $GLOBALS['diviops_test_et_shortname'] ?? 'divi' ), array() );
}

$GLOBALS['diviops_tor_drop'] = array();

/* -------------------------------------------------------------------------
 * 1. CONTROLS. These must pass before and after the fix, or nothing below
 *    measures the handler rather than the harness.
 * ---------------------------------------------------------------------- */

diviops_tor_reset();
$tor_clean = diviops_tor_call( array( 'heading_font' => '  Open   Sans  ' ) );

assert_same( true, $tor_clean['success'] ?? null, 'a write that lands reports success' );
assert_same( 'Open Sans', diviops_tor_stored( 'heading_font' ), 'and the sanitized value really reaches Divi\'s stored row' );
assert_same( 'Open Sans', $tor_clean['updated']['heading_font'] ?? null, 'and the response reports the stored value' );

diviops_tor_reset();
$GLOBALS['diviops_tor_drop'] = array( 'heading_font' );
diviops_tor_call( array( 'heading_font' => 'Inter' ) );
assert_same(
	null,
	diviops_tor_stored( 'heading_font' ),
	'the silent-failure seam really prevents storage, so the assertions below are not vacuous'
);

/* -------------------------------------------------------------------------
 * 2. A write that reports success and stores nothing.
 *    This is the readback proof: no amount of request-side bookkeeping can
 *    answer it, because the request and the refusal look identical.
 * ---------------------------------------------------------------------- */

diviops_tor_reset();
$GLOBALS['diviops_tor_drop'] = array( 'heading_font' );
$tor_silent = diviops_tor_call( array( 'heading_font' => 'Inter' ) );

assert_same( false, $tor_silent['success'] ?? null, 'a write that did not land reports success false' );
assert_true(
	! array_key_exists( 'heading_font', (array) ( $tor_silent['updated'] ?? array() ) ),
	'and the response does not claim a key the database does not hold'
);

/* -------------------------------------------------------------------------
 * 3. Keys the handler drops are reported rather than discarded.
 * ---------------------------------------------------------------------- */

diviops_tor_reset();
$tor_unknown = diviops_tor_call( array( 'heading_font' => 'Inter', 'divi_custom_css' => 'body{}' ) );

assert_same( array( 'divi_custom_css' ), $tor_unknown['rejected'] ?? null, 'a key outside the write allowlist is reported as rejected' );
assert_same( false, $tor_unknown['success'] ?? null, 'and a partially-applied request does not report success' );
assert_same( 'Inter', diviops_tor_stored( 'heading_font' ), 'while the eligible key is still written — a rejection is not a transaction rollback' );

diviops_tor_reset();
$tor_nonscalar = diviops_tor_call( array( 'link_color' => array( '#fff' ) ) );

assert_same( array( 'link_color' ), $tor_nonscalar['rejected'] ?? null, 'a non-scalar value is reported as rejected rather than dropped in silence' );
assert_same( false, $tor_nonscalar['success'] ?? null, 'and that request does not report success' );

/* -------------------------------------------------------------------------
 * 4. The zero-count case, which is the whole reason success could not be
 *    trusted: nothing was written and the caller was told it went fine.
 * ---------------------------------------------------------------------- */

diviops_tor_reset();
$tor_none = diviops_tor_call( array( 'divi_custom_css' => 'body{}' ) );

assert_same( false, $tor_none['success'] ?? null, 'a request whose every key was rejected reports success false, not a zero count' );
assert_same( array(), $tor_none['updated'] ?? null, 'and reports nothing as updated' );
assert_same( array( 'divi_custom_css' ), $tor_none['rejected'] ?? null, 'and names what it refused, so the caller can tell this from asking for nothing' );

/* -------------------------------------------------------------------------
 * 5. body_header_size becomes writable, closing the read/write asymmetry.
 *
 *    This REVERSES the #29 decision recorded in FORK.md for exactly this key.
 *    The read allowlist in filter_public_theme_options() has always carried it;
 *    the write allowlist did not, so a caller could read a value it could never
 *    set. Reversed deliberately, with a FORK.md row and a matching edit to
 *    diviops-server/src/index.ts so the client-side dry_run list cannot drift
 *    from the route.
 * ---------------------------------------------------------------------- */

diviops_tor_reset();
$tor_bhs = diviops_tor_call( array( 'body_header_size' => '30' ) );

assert_same( true, $tor_bhs['success'] ?? null, 'body_header_size is writable, so a request carrying only it reports success' );
assert_same( '30', diviops_tor_stored( 'body_header_size' ), 'and the value reaches Divi\'s stored row' );
assert_same( array(), $tor_bhs['rejected'] ?? null, 'and nothing is reported rejected' );
