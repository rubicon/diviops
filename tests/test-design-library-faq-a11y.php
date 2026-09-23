<?php
// SPDX-License-Identifier: MIT
/**
 * The FAQ/toggle accessibility layer ships and is wired correctly (#476).
 *
 * Divi's native toggle markup gives the title no button semantics, so a keyboard
 * user cannot reach or operate one. This asset adds `role`, `tabindex`,
 * `aria-expanded` and `aria-controls`, driven off the open/close class Divi
 * already maintains.
 *
 * ── What this file can and cannot cover ───────────────────────────────────
 *
 * 109 lines of browser JavaScript that this harness cannot execute — no DOM, no
 * `MutationObserver`, no Divi. Saying so plainly matters more than the
 * assertions below: a green suite here does NOT mean the accessibility
 * behaviour was exercised. `tests/test-admin-design-system-view.php` states the
 * same limit for the dashboard asset, for the same reason.
 *
 * So coverage is the three things the harness CAN decide:
 *
 *   1. The assets ship.
 *   2. The Divi-owned dependency is declared — the load-bearing detail below.
 *   3. All three enqueue gates are present, since dropping any one changes who
 *      gets the asset.
 *
 * ── Why the dependency is load-bearing ───────────────────────────────────
 *
 * The script is registered against `divi-script-library-toggle`. That handle is
 * DIVI's, not ours and not upstream's — it comes from
 * `includes/builder-5/server/FrontEnd/Assets/DynamicAssetsUtils.php`, confirmed
 * on staging. Divi 5 registers it through dynamic assets only when a toggle or
 * accordion module is actually on the page, so the dependency is what scopes
 * this script to pages that have something to fix.
 *
 * The consequence, recorded because it is invisible: when Divi does NOT register
 * that handle, `wp_enqueue_script()` silently does nothing. That is correct here
 * — no toggles, nothing to fix — but it is a silent no-op, and someone debugging
 * "why didn't my a11y script load" should find this note rather than spend an
 * afternoon on it.
 *
 * ── Not covered, and known ───────────────────────────────────────────────
 *
 * Every DOM behaviour: focus handling, the open/close class observer, aria
 * state transitions, keyboard activation. Those need a browser and a rendered
 * Divi toggle.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
// The plugin file calls DiviOps_Design_Library::init() at the bottom, which only
// registers hooks — the shim's add_action() absorbs those, so loading it here is
// side-effect free and gives Reflection something to read.
require_once dirname( __DIR__ ) . '/plugins/diviops-design-library/diviops-design-library.php';

$library_dir  = dirname( __DIR__ ) . '/plugins/diviops-design-library';
$plugin_file  = $library_dir . '/diviops-design-library.php';
$plugin_src   = (string) file_get_contents( $plugin_file );

// ── 1. The assets ship ────────────────────────────────────────────────────

assert_true(
	is_file( $library_dir . '/assets/js/faq-toggle-a11y.js' ),
	'#476: the accessibility script ships with the design library'
);
assert_true(
	is_file( $library_dir . '/assets/css/faq-toggle-a11y.css' ),
	'#476: and its stylesheet'
);

$js = (string) file_get_contents( $library_dir . '/assets/js/faq-toggle-a11y.js' );

// ── 2. It is doubly opt-in ────────────────────────────────────────────────
//
// The page meta turns the ASSET on; a per-module class decides which toggles it
// touches. Both halves matter: without the class gate the script would rewrite
// the semantics of every native toggle on an opted-in page, including ones
// whose markup someone else is already managing.
assert_true(
	false !== strpos( $js, 'ddl-faq-a11y' ),
	'#476: the script only adjusts toggles explicitly marked with the ddl-faq-a11y class'
);
assert_true(
	false !== strpos( $js, 'et_pb_toggle' ),
	'#476: and it targets the native Divi toggle markup, which Divi 5 still emits'
);

// It must leave the Visual Builder alone from the inside too, not only via the
// enqueue gate — an asset already on the page when VB opens would otherwise
// keep running.
assert_true(
	false !== strpos( $js, 'et-fb' ),
	'#476: the script itself bails inside the Visual Builder, independently of the enqueue gate'
);

// ── 3. Registration declares the Divi-owned dependency ────────────────────

assert_true(
	false !== strpos( $plugin_src, "'divi-faq-a11y'" ),
	'#476: the script and style are registered under a handle'
);
assert_true(
	false !== strpos( $plugin_src, "'divi-script-library-toggle'" ),
	'#476: registered against Divi\'s own toggle handle — that dependency is what scopes the asset to pages with a toggle on them'
);
assert_true(
	false !== strpos( $plugin_src, 'faq-toggle-a11y.js' ) && false !== strpos( $plugin_src, 'faq-toggle-a11y.css' ),
	'#476: both files are referenced by the registration'
);

// ── 4. All three enqueue gates ────────────────────────────────────────────
//
// Read from the real method source. Each gate changes WHO receives the asset,
// so each is asserted on its own rather than as one blob.

$reflection = new ReflectionMethod( 'DiviOps_Design_Library', 'maybe_enqueue' );
$lines      = file( $reflection->getFileName() );
$enqueue    = implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );

assert_true(
	false !== strpos( $enqueue, '_divi_design_faq_a11y' ),
	'#476: the asset is opt-in per page, via post meta — it does not load site-wide'
);
assert_true(
	false !== strpos( $enqueue, 'et_pb_is_pagebuilder_used' ),
	'#476: and only on a Divi builder page, since there is no native toggle to fix anywhere else'
);
assert_true(
	false !== strpos( $enqueue, 'et_fb_is_enabled' ),
	'#476: and never inside the Visual Builder — VB manages its own toggle state, and a front-end a11y layer fighting it is worse than none'
);
assert_true(
	false !== strpos( $enqueue, "wp_enqueue_script( 'divi-faq-a11y' )" )
		&& false !== strpos( $enqueue, "wp_enqueue_style( 'divi-faq-a11y' )" ),
	'#476: both the script and the style are enqueued — the CSS carries the focus-visible affordance the script\'s tabindex makes reachable'
);

// The gates must be conjunctive. Three independent `if`s would load the asset on
// a page that satisfied only one of them, which is the opposite of the contract
// above and would still satisfy every assertion so far.
assert_same(
	1,
	preg_match( '/_divi_design_faq_a11y.*?&&.*?et_pb_is_pagebuilder_used.*?&&.*?et_fb_is_enabled/s', $enqueue ),
	'#476: the three gates are ANDed into one condition, not three independent checks'
);

// ── 5. The pre-existing design-fx path is untouched ───────────────────────
assert_true(
	false !== strpos( $enqueue, "wp_enqueue_script( 'divi-design-fx' )" ),
	'#476: the existing design-fx enqueue still runs — the adoption added a branch rather than replacing one'
);
assert_true(
	false !== strpos( $enqueue, 'threejs' ),
	'#476: and so does the Three.js branch'
);
