<?php
// SPDX-License-Identifier: MIT
/**
 * The read-only Design System admin view, adopted from upstream v1.5.64 (#465).
 *
 * ── What this file can and cannot cover ───────────────────────────────────
 *
 * The adoption brings 251 lines of browser JavaScript (`assets/design-system.js`)
 * that this harness cannot execute — there is no DOM, no `fetch`, and no JS
 * runtime in `php tests/run.php`. Saying so explicitly matters more than the
 * assertions below: a reader must not mistake a green suite for the dashboard
 * having been exercised.
 *
 * So coverage here is deliberately three things the harness CAN decide:
 *
 *   1. The view predicate — `admin_is_design_system_view()` — including the
 *      normalisation variants an attacker would try, since `$_GET['view']`
 *      selects which assets load and which branch renders.
 *   2. The wiring — the include and both assets exist, and the enqueue is
 *      guarded on capability, Divi presence and the view together.
 *   3. The response-key contract the JS depends on. The dashboard reads
 *      specific keys out of `preset/audit-storage` and `variable/list`. Those
 *      are OUR responses, so a future change to them would break the dashboard
 *      silently in a browser. Pinning the keys here means it breaks in CI
 *      instead.
 *
 * Not covered, and known: rendering, DOM behaviour, the fetch/nonce round trip,
 * pagination, search, and every error path inside the asset. Those need a live
 * page and a browser.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

$plugin_dir = dirname( __DIR__ ) . '/plugins/diviops-agent';

/**
 * Read a method's exact source text via Reflection.
 *
 * Named for this file specifically: every test file in tests/ is require'd into
 * ONE process by tests/run.php, so a shared global name would collide with the
 * identically-purposed helpers in test-preset-reassign-write-safety.php and
 * test-parse-blocks-for-write-coverage.php.
 *
 * @param string $method Method name on DiviOps_Agent.
 * @return string
 */
function design_system_method_source( string $method ): string {
	$reflection = new ReflectionMethod( 'DiviOps_Agent', $method );
	$file       = $reflection->getFileName();
	$start      = $reflection->getStartLine() - 1;
	$length     = $reflection->getEndLine() - $start;
	$lines      = file( $file );
	return implode( '', array_slice( $lines, $start, $length ) );
}

// ── 1. The view predicate rejects normalisation variants ──────────────────
//
// The guard matches the token twice: raw, and through
// `sanitize_key( wp_unslash( ... ) )`. A value that only BECOMES the token after
// sanitising must not select the view, or the predicate that decides which
// assets load would disagree with the literal a reader sees in the URL.
$GLOBALS['diviops_ds_saved_get'] = $_GET;

$cases = array(
	// value                     => is the Design System view?
	array( 'design-system',         true,  'the exact token selects the view' ),
	array( 'Design-System',         false, 'a case variant is rejected — it only becomes the token after sanitize_key()' ),
	array( 'design_system',         false, 'an underscore variant is rejected' ),
	array( 'design-system ',        false, 'a trailing space is rejected rather than trimmed into a match' ),
	array( ' design-system',        false, 'a leading space is rejected' ),
	array( 'design-system!',        false, 'a trailing byte sanitize_key() would strip is rejected' ),
	array( 'DESIGN-SYSTEM',         false, 'an uppercase variant is rejected' ),
	array( 'overview',              false, 'an unrelated token is not the view' ),
	array( '',                      false, 'an empty string is not the view' ),
);

foreach ( $cases as $case ) {
	list( $value, $expected, $why ) = $case;
	$_GET['view'] = $value;
	assert_same(
		$expected,
		diviops_call_static( 'admin_is_design_system_view', array() ),
		'#465: ' . $why
	);
}

// A non-string must not reach `sanitize_key()`, which expects a string.
$_GET['view'] = array( 'design-system' );
assert_same(
	false,
	diviops_call_static( 'admin_is_design_system_view', array() ),
	'#465: an array value is rejected before sanitize_key() is reached'
);

unset( $_GET['view'] );
assert_same(
	false,
	diviops_call_static( 'admin_is_design_system_view', array() ),
	'#465: an absent view parameter is not the Design System view'
);

$_GET = $GLOBALS['diviops_ds_saved_get'];

// ── 2. The wiring exists ──────────────────────────────────────────────────
assert_true(
	is_file( $plugin_dir . '/includes/admin-design-system.php' ),
	'#465: the dashboard shell include ships with the plugin'
);
assert_true(
	is_file( $plugin_dir . '/assets/design-system.js' ),
	'#465: the inspector asset ships with the plugin'
);
assert_true(
	is_file( $plugin_dir . '/assets/design-system.css' ),
	'#465: the dashboard stylesheet ships with the plugin'
);

// The shell must refuse to render outside WordPress, like every other include.
$shell = (string) file_get_contents( $plugin_dir . '/includes/admin-design-system.php' );
assert_true(
	false !== strpos( $shell, "defined( 'ABSPATH' )" ),
	'#465: the shell carries the ABSPATH guard'
);
// It is a SHELL: registry reads are deferred to the asset, so it must not read
// the registries itself — that is what keeps it cheap on a site with thousands
// of presets, and what makes "read-only" true of the page load as well.
foreach ( array( 'get_d5_presets', 'collect_page_preset_refs', '$wpdb' ) as $forbidden ) {
	assert_same(
		false,
		strpos( $shell, $forbidden ),
		sprintf( '#465: the shell defers registry work to the asset and does not call %s itself', $forbidden )
	);
}

// ── 3. The enqueue is guarded on all three conditions ─────────────────────
//
// Read from the real method source rather than asserted about in prose: an
// enqueue that fired on every admin page, or for a user without the capability,
// would ship the asset and its REST nonce to someone who cannot use it.
$enqueue_src = design_system_method_source( 'enqueue_admin_styles' );

assert_true(
	false !== strpos( $enqueue_src, 'admin_is_design_system_view' ),
	'#465: the enqueue consults the single view predicate rather than re-deriving the token'
);
assert_true(
	false !== strpos( $enqueue_src, "current_user_can( 'manage_options' )" ),
	'#465: the enqueue checks the capability — admin_enqueue_scripts fires before render_admin_page()\'s own gate'
);
assert_true(
	false !== strpos( $enqueue_src, "function_exists( 'et_get_option' )" ),
	'#465: the enqueue checks Divi is active before loading a dashboard that reads Divi registries'
);
assert_true(
	false !== strpos( $enqueue_src, 'design-system.js' ) && false !== strpos( $enqueue_src, 'design-system.css' ),
	'#465: both assets are enqueued'
);

// The token must not be re-derived anywhere else. Two copies of the predicate
// could drift into disagreeing about what the view is — how the preset
// reference scan's post-type filter drifted from its write path (#314).
$plugin_src   = (string) file_get_contents( $plugin_dir . '/diviops-agent.php' );
$raw_token_hits = substr_count( $plugin_src, "'design-system' ===" );
assert_same(
	2,
	$raw_token_hits,
	'#465: the raw token is compared in exactly one place (twice, for the double-match guard) — not repeated per call site'
);

// ── 4. The response keys the JS reads are still emitted ───────────────────
//
// These are OUR response shapes. The dashboard hard-gates on them, so a change
// here breaks it silently in a browser — this makes it break in CI instead.
$core_src = (string) file_get_contents( $plugin_dir . '/includes/trait-core.php' );

foreach ( array( 'entry_sources', 'provenance', 'bucket_key' ) as $key ) {
	assert_true(
		false !== strpos( $core_src, $key ),
		sprintf( '#465: preset audit-storage _meta still carries the %s key the dashboard reads', $key )
	);
}
foreach ( array( 'id_collision', 'shape_inconsistency' ) as $warning ) {
	assert_true(
		false !== strpos( $core_src, $warning ),
		sprintf( '#465: the %s warning token the dashboard renders is still emitted', $warning )
	);
}
foreach ( array( 'd5_top_level', 'd5_nested_scratchpad' ) as $provenance ) {
	assert_true(
		false !== strpos( $core_src, $provenance ),
		sprintf( '#465: the %s provenance value the dashboard switches on is still emitted', $provenance )
	);
}

// ── 5. The Overview path is unchanged ─────────────────────────────────────
//
// The adoption makes the snapshot summary conditional. The Overview branch must
// still compute it — skipping it there would empty the snapshot table on the
// page that actually shows it.
$render_src = design_system_method_source( 'render_admin_page' );
assert_true(
	false !== strpos( $render_src, '$design_system ? [] : self::rollback_snapshot_filtered_summaries(' ),
	'#465: the snapshot summary is skipped ONLY on the Design System view, and still computed for Overview'
);
assert_true(
	false !== strpos( $render_src, "require __DIR__ . '/includes/admin-design-system.php'" ),
	'#465: the shell is required from the view branch'
);
assert_true(
	false !== strpos( $render_src, "current_user_can( 'manage_options' )" ),
	'#465: render_admin_page keeps its own capability gate (#454) — the new view does not bypass it'
);
