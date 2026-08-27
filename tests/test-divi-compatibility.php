<?php
// SPDX-License-Identifier: MIT
/**
 * Divi Post Filter price-range permission repair (#266).
 *
 * Divi 5.10.0 through at least 5.11.1 registers `/divi/v1/loop/product-price-range`
 * with `PostFilterProductPriceRangeController::index_permission()` as its
 * permission callback, and that method calls `UserRole::can_edit_posts()`, which
 * `UserRole` does not declare. Reproduced on the reference install at Divi 5.11.1:
 *
 *   THROWN: Error: Call to undefined method
 *   ET\Builder\Framework\UserRole\UserRole::can_edit_posts()
 *
 * The route is registered, so every request to it is a fatal. That means the
 * endpoint is currently closed to everyone, and this repair OPENS it to
 * visual-builder-capable editors. It is a functional change with a security
 * surface, not merely a crash fix, so these tests pin the refusals as hard as
 * the repair.
 *
 * The properties, not the mechanism:
 *
 *  - The repair applies only on an exact match, and returns the endpoint map
 *    untouched in every other case. It cannot half-apply.
 *  - It self-retires the moment Divi declares `can_edit_posts()`, with no
 *    version list to maintain. Proven in a child process, because a class cannot
 *    be redefined mid-process and that is the whole question here.
 *  - The replacement callback refuses before it consults authority: no nonce, a
 *    wrong nonce, the wrong route, or the wrong method all return false without
 *    reaching `edit_posts`.
 *  - It never grants more than Divi intended: VB authority AND `edit_posts`, not
 *    either.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/divi-compat-shim.php';

$host  = 'DiviOps_Compat_Host';
$route = '/divi/v1/loop/product-price-range';

// ── The defect this exists for ────────────────────────────────────────────────
// If this ever stops throwing, Divi fixed it and the whole trait should go.
$fatal = null;
try {
	call_user_func( array( 'ET\Builder\Packages\ModuleLibrary\PostFilterItem\PostFilterProductPriceRangeController', 'index_permission' ) );
} catch ( \Throwable $e ) {
	$fatal = $e->getMessage();
}
assert_true(
	is_string( $fatal ) && false !== strpos( $fatal, 'can_edit_posts' ),
	'Divi\'s own permission callback fatals on the undefined UserRole::can_edit_posts()'
);

// ── The repair applies, and only where it should ──────────────────────────────
$endpoints = $host::repair_divi_post_filter_price_permission( diviops_test_divi_endpoints() );

assert_same(
	array( $host, 'check_divi_post_filter_price_permission' ),
	$endpoints[ $route ][0]['permission_callback'],
	'the broken permission callback on the exact route is replaced'
);

assert_same(
	array( 'ET\Builder\Packages\ModuleLibrary\PostFilterItem\PostFilterProductPriceRangeController', 'index' ),
	$endpoints[ $route ][0]['callback'],
	'the route\'s own callback is left alone -- only the permission gate is repaired'
);

assert_same(
	array( 'ET\Builder\Packages\ModuleLibrary\PostFilterItem\PostFilterProductPriceRangeController', 'index_permission' ),
	$endpoints['/divi/v1/unrelated'][0]['permission_callback'],
	'an unrelated route sharing the same broken callback is NOT touched -- the repair is keyed on the route'
);

// ── Every refusal returns the map untouched ───────────────────────────────────
$untouched = array(
	'a non-array endpoint map'          => 'not-an-array',
	'an empty endpoint map'             => array(),
);
foreach ( $untouched as $label => $input ) {
	assert_same(
		$input,
		$host::repair_divi_post_filter_price_permission( $input ),
		sprintf( 'the repair returns %s unchanged', $label )
	);
}

// Ambiguity is refused rather than guessed at. Two identical handlers on the
// route means we cannot tell which one Divi meant, and replacing the wrong one
// would leave the fatal in place while reporting success.
$two = diviops_test_divi_endpoints();
$two[ $route ][] = $two[ $route ][0];
assert_same(
	$two,
	$host::repair_divi_post_filter_price_permission( $two ),
	'more than one matching handler leaves the map untouched rather than picking one'
);

// A different permission callback means Divi changed the route and this repair
// no longer describes reality.
$changed = diviops_test_divi_endpoints( array( 'permission_callback' => '__return_true' ) );
assert_same(
	$changed,
	$host::repair_divi_post_filter_price_permission( $changed ),
	'a route whose permission callback is not the known-broken one is left alone'
);

// Same for the action callback: the pair has to match, not just the gate.
$other_cb = diviops_test_divi_endpoints( array( 'callback' => '__return_empty_array' ) );
assert_same(
	$other_cb,
	$host::repair_divi_post_filter_price_permission( $other_cb ),
	'a route whose action callback is not the known controller is left alone'
);

// ── The replacement callback refuses before it consults authority ─────────────
$GLOBALS['diviops_test_valid_nonce']        = 'good-nonce';
$GLOBALS['diviops_test_divi_vb_authority']  = true;

$refusals = array(
	'no request object at all'   => null,
	'a request with no nonce'    => new DiviOps_Test_Divi_Request( $route ),
	'a wrong nonce'              => new DiviOps_Test_Divi_Request( $route, 'GET', array( 'X-ET-Nonce' => 'wrong' ) ),
	'an empty nonce'             => new DiviOps_Test_Divi_Request( $route, 'GET', array( 'X-ET-Nonce' => '' ) ),
	'a different route'          => new DiviOps_Test_Divi_Request( '/divi/v1/something-else', 'GET', array( 'X-ET-Nonce' => 'good-nonce' ) ),
	'a different method'         => new DiviOps_Test_Divi_Request( $route, 'POST', array( 'X-ET-Nonce' => 'good-nonce' ) ),
);
foreach ( $refusals as $label => $request ) {
	assert_same(
		false,
		$host::check_divi_post_filter_price_permission( $request ),
		sprintf( 'the replacement callback refuses %s', $label )
	);
}

$good = new DiviOps_Test_Divi_Request( $route, 'GET', array( 'X-ET-Nonce' => 'good-nonce' ) );

// Non-vacuity. Every assertion above is a refusal, and a callback that returned
// false unconditionally would satisfy all of them while leaving the endpoint as
// dead as the fatal did. This is the one case that must succeed.
assert_same(
	true,
	$host::check_divi_post_filter_price_permission( $good ),
	'a correctly nonced GET on the exact route from an authorised user is ALLOWED -- '
		. 'without this the repair could refuse everything and still pass'
);

// ── Authority is required on top of the nonce, not instead of it ──────────────
$GLOBALS['diviops_test_divi_vb_authority'] = false;
assert_same(
	false,
	$host::check_divi_post_filter_price_permission( $good ),
	'a valid nonce does not substitute for visual-builder authority'
);
$GLOBALS['diviops_test_divi_vb_authority'] = true;

$GLOBALS['diviops_test_denied_caps'] = array( 'edit_posts' );
assert_same(
	false,
	$host::check_divi_post_filter_price_permission( $good ),
	'visual-builder authority does not substitute for edit_posts -- both are required'
);
$GLOBALS['diviops_test_denied_caps'] = array();

// An empty nonce name from Divi's own helper must fail closed rather than
// verifying against nothing.
$GLOBALS['diviops_test_divi_nonce_name_empty'] = true;
assert_same(
	false,
	$host::check_divi_post_filter_price_permission( $good ),
	'an empty nonce name from Divi\'s helper is a refusal, not a skipped check'
);
$GLOBALS['diviops_test_divi_nonce_name_empty'] = false;

// ── Self-retirement, in a child process ───────────────────────────────────────
// `UserRole` cannot be redefined here, and whether `can_edit_posts()` exists is
// precisely what decides whether the repair should still apply. Mirrors the
// child-process probes in test-seo.php and test-media-svg-capability-gate.php.
$probe = sprintf(
	'define( "DIVIOPS_TEST_DIVI_USERROLE_FIXTURE", %s );'
	. ' require %s;'
	. ' $before = diviops_test_divi_endpoints();'
	. ' $after  = DiviOps_Compat_Host::repair_divi_post_filter_price_permission( $before );'
	. ' echo json_encode( [ $before === $after, is_callable( ["ET\\\\Builder\\\\Framework\\\\UserRole\\\\UserRole", "can_edit_posts"] ) ] );',
	var_export( __DIR__ . '/fixtures/divi-userrole-fixed.php', true ),
	var_export( __DIR__ . '/divi-compat-shim.php', true )
);
$retired = json_decode( trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $probe ) . ' 2>&1' ) ), true );

assert_same(
	array( true, true ),
	$retired,
	'once Divi declares can_edit_posts(), the repair stops applying and the endpoint map is returned unchanged'
);

// ── Version floor ─────────────────────────────────────────────────────────────
// Divi below 5.10.0 never had the defect, so the repair must not fire there
// either. Also a child process: the version constant cannot be redefined.
$old_probe = sprintf(
	'$GLOBALS["diviops_test_divi_version"] = "5.9.9";'
	. ' require %s;'
	. ' $before = diviops_test_divi_endpoints();'
	. ' echo json_encode( [ $before === DiviOps_Compat_Host::repair_divi_post_filter_price_permission( $before ), ET_BUILDER_PRODUCT_VERSION ] );',
	var_export( __DIR__ . '/divi-compat-shim.php', true )
);
$old = json_decode( trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $old_probe ) . ' 2>&1' ) ), true );

assert_same(
	array( true, '5.9.9' ),
	$old,
	'on a Divi older than 5.10.0 the repair does not fire -- the defect did not exist there'
);
