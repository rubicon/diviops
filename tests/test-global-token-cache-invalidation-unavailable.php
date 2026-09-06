<?php
// SPDX-License-Identifier: MIT
/**
 * A cache clear that could not run must not render as one that did (#381).
 *
 * The sibling file `tests/test-global-token-cache-invalidation.php` proves the sweep
 * deletes compiled CSS when `WP_Filesystem` is available. This file covers the other
 * branch, and it exists because of the failure this repository keeps paying for: a step
 * that reports what it inspected but derives its status only from problems-found passes
 * while inspecting nothing.
 *
 * Two properties are pinned here, and they pull against each other:
 *
 *   1. **The write must still succeed.** Invalidation happens after the token is already
 *      persisted. Turning a filesystem problem into a failed response would report a
 *      failure for an operation that worked, and a caller retrying it would write twice.
 *   2. **It must not claim to have cleared anything.** `status` is `unavailable`, not a
 *      missing key and not `invalidated` with a zero count — a zero count is what a
 *      genuine sweep of an already-clean cache also returns.
 *
 * ## Why this asserts the report rather than the filesystem
 *
 * Everywhere else in this pair the assertions are physical: which files survive. Here the
 * physical observable — every cache file still present — is byte-identical between "the
 * clear could not run" and "the clear ran and found nothing", which is exactly the
 * confusion the `status` field exists to resolve. Asserting the field is the only way to
 * tell the two apart, so it is asserted deliberately rather than by default.
 *
 * ## How the branch is reached
 *
 * By NOT requiring `tests/wp-filesystem-shim.php`. Leaving a primitive undefined is a
 * legitimate runtime shape for code that calls it behind `function_exists()`, which is
 * what `invalidate_divi_cache_sitewide()` does. This needs #396's per-file processes: in
 * a single shared process the sibling file's `WP_Filesystem()` declaration would still be
 * in scope here, since functions are process-global once declared, and this branch would
 * be unreachable.
 *
 * @package DiviOps
 */

$diviops_gtcu_root = sys_get_temp_dir() . '/diviops-gtcu-' . getmypid();
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', $diviops_gtcu_root );
}

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/divi-active-shim.php';
// wp-filesystem-shim.php is deliberately NOT required — see the docblock.
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

/** Recursively delete a directory tree. Fixtures only. */
function diviops_gtcu_rmtree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			diviops_gtcu_rmtree( $path );
		} else {
			unlink( $path );
		}
	}
	rmdir( $dir );
}

// The precondition this whole file rests on. If some other require pulled a
// WP_Filesystem() into scope, every assertion below would be characterizing the
// available branch while claiming to characterize the unavailable one.
assert_true(
	! function_exists( 'WP_Filesystem' ),
	'this file runs in a process where WP_Filesystem() was never declared'
);

$cache_file = WP_CONTENT_DIR . '/et-cache/900390/et-core-unified-900390.css';
if ( ! is_dir( dirname( $cache_file ) ) ) {
	mkdir( dirname( $cache_file ), 0700, true );
}
file_put_contents( $cache_file, "/* compiled */\n" );
assert_true( is_file( $cache_file ), 'a compiled CSS file exists before the handler runs' );

et_update_option( 'et_global_data', array(
	'global_colors' => array(
		'gcid-cachetest' => array(
			'id'          => 'gcid-cachetest',
			'label'       => 'brand',
			'color'       => '#FFFFFF',
			'order'       => '3',
			'status'      => 'active',
			'folder'      => '',
			'usedInPosts' => array(),
			'lastUpdated' => '2026-09-01T00:00:00.000Z',
		),
	),
) );

$req = new WP_REST_Request();
$req->set_param( 'colors', array( array( 'id' => 'gcid-cachetest', 'color' => '#FAFAFA' ) ) );
$req->set_param( 'mode', 'merge' );
$response = diviops_call( 'global_color_upsert', array( $req ) );
$body     = $response->get_data();

// 1. The write succeeded.
assert_same( true, $body['ok'] ?? null, 'the write still succeeds when the cache cannot be cleared' );
assert_same(
	'#FAFAFA',
	( et_get_option( 'et_global_data' )['global_colors']['gcid-cachetest']['color'] ?? null ),
	'and the colour is persisted'
);

// 2. It did not claim to have cleared anything.
assert_same(
	'unavailable',
	$body['data']['cache']['status'] ?? null,
	'the cache report says unavailable, not invalidated'
);
assert_true(
	'' !== ( $body['data']['cache']['reason'] ?? '' ),
	'and states a reason, so the caller can act on it'
);
assert_same(
	'sitewide',
	$body['data']['cache']['scope'] ?? null,
	'the intended scope is still reported — a global token is never per-post'
);

// 3. Nothing was deleted, which is what makes (2) load-bearing rather than cosmetic.
assert_true(
	is_file( $cache_file ),
	'the compiled CSS is untouched — the report is the only thing distinguishing this from a clear'
);

diviops_gtcu_rmtree( WP_CONTENT_DIR );
