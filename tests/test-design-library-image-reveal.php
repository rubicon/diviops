<?php
// SPDX-License-Identifier: MIT
/**
 * The opt-in Image wipe reveal ships and cannot leave an image hidden (#487).
 *
 * Adopted from upstream, which carries `ddl-image-reveal` across four files
 * while this fork carried it in none. A `clip-path` animates
 * `inset(0 100% 0 0)` -> `inset(0 0 0 0)` on a Divi Image module marked with
 * the `ddl-image-reveal` class.
 *
 * ── What this file can and cannot cover ───────────────────────────────────
 *
 * The reveal itself needs a browser: an IntersectionObserver, a real layout, a
 * loaded `<img>` and a CSS animation event. This harness has none of those, so
 * a green suite here does NOT mean the animation was exercised. The sibling
 * `tests/test-design-library-faq-a11y.php` states the same limit for the same
 * reason.
 *
 * What IS decidable here is the part that can hurt someone: the **refusal
 * conditions**. Every gate below answers "when does this NOT run", and the
 * failure mode of a reveal effect is never "it didn't animate" — it is "the
 * image stayed invisible". So the gates are the coverage, not a consolation
 * prize for lacking a browser.
 *
 * ── The load-bearing property ────────────────────────────────────────────
 *
 * **The baseline is visible.** The clip is attached to `ddl-image-reveal-active`,
 * a class only JavaScript adds, so an image is fully visible with CSS alone,
 * with JS disabled, before the observer fires, and if the script never loads.
 * Section 2 asserts no rule clips an image without that class — which is the
 * one mistake that would ship an invisible image to someone with JS off, and
 * the one a screenshot in a working browser would never reveal.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-design-library/diviops-design-library.php';

$library_dir = dirname( __DIR__ ) . '/plugins/diviops-design-library';
$plugin_src  = (string) file_get_contents( $library_dir . '/diviops-design-library.php' );
$js          = (string) file_get_contents( $library_dir . '/assets/js/design-fx.js' );

// The repository rule: a gate that reports what it inspected but derives pass
// or fail only from problems-found will pass while inspecting nothing. Prove
// both sources were actually read before asserting anything about them.
assert_true(
	strlen( $plugin_src ) > 1000 && strlen( $js ) > 1000,
	'#487: the plugin source and design-fx.js were both read (guards against asserting over empty strings)'
);

// ── 1. The effect ships: keyframes plus the class that triggers it ────────

assert_true(
	false !== strpos( $plugin_src, '@keyframes ddl-image-reveal' ),
	'#487: the reveal keyframes are emitted with the design library CSS'
);
assert_true(
	false !== strpos( $plugin_src, 'inset(0 100% 0 0)' ),
	'#487: the wipe starts fully clipped from one edge'
);
assert_true(
	false !== strpos( $plugin_src, 'ddl-image-reveal-active' ),
	'#487: the animation is bound to the JS-added active class, not to the opt-in class alone'
);
assert_true(
	false !== strpos( $js, 'setupImageReveals' ),
	'#487: design-fx.js defines the reveal setup'
);
assert_true(
	1 === preg_match( '/function init\(\)\s*\{[^}]*setupImageReveals\(\)/', $js ),
	'#487: and init() actually calls it — a defined-but-uncalled function is dead code that still reads as shipped'
);

// ── 2. The baseline is visible (the load-bearing property) ────────────────
//
// Find every CSS rule that APPLIES the animation, and require each selector to
// be scoped to the active class. A rule targeting `.ddl-image-reveal` alone
// would hide the image for anyone whose JS never runs.
//
// Scanning for `clip-path` instead would prove nothing: the clip lives only in
// the `@keyframes` stops, so such a scan never examines a selector at all. A
// mutation moving the animation onto a bare `img` survived exactly that way
// before this was corrected.

preg_match_all( '/([^{}]*)\\{([^{}]*animation:\\s*ddl-image-reveal[^{}]*)\\}/', $plugin_src, $clip_rules, PREG_SET_ORDER );

assert_true(
	count( $clip_rules ) > 0,
	'#487: at least one rule applies the reveal animation (otherwise the scan below proves nothing)'
);

$unscoped = [];
foreach ( $clip_rules as $rule ) {
	foreach ( explode( ',', $rule[1] ) as $selector ) {
		$selector = trim( $selector );
		if ( '' === $selector ) {
			continue;
		}
		if ( false === strpos( $selector, 'ddl-image-reveal-active' ) ) {
			$unscoped[] = $selector;
		}
	}
}

assert_same(
	[],
	$unscoped,
	'#487: every selector that applies the animation is scoped to the JS-added active class — the image is visible with CSS alone'
);

// The keyframes must actually clip, or the rule above animates nothing and the
// scan above would be guarding an effect that does not exist.
assert_true(
	1 === preg_match( '/@keyframes\\s+ddl-image-reveal\\s*\\{[^}]*clip-path/', $plugin_src ),
	'#487: and the keyframes are what carry the clip'
);

// ── 3. Every refusal condition ────────────────────────────────────────────
//
// Each of these changes WHO sees a static image instead of an animated one.
// Asserted individually, because dropping any single one is invisible in a
// browser where the others still hold.

assert_true(
	false !== strpos( $js, 'prefers-reduced-motion' ),
	'#487: the script bails for a visitor who asked for reduced motion'
);
assert_true(
	1 === preg_match(
		'/@media\\s*\\(prefers-reduced-motion:\\s*reduce\\)\\s*\\{[^{}]*ddl-image-reveal-active[^{}]*\\{[^{}]*animation:\\s*none/',
		$plugin_src
	),
	'#487: and a reduced-motion media query disables THIS effect specifically, so the promise holds even if the script is stale or cached'
);
assert_true(
	false !== strpos( $js, 'IntersectionObserver' ),
	'#487: it refuses without IntersectionObserver rather than clipping an image it can never un-clip'
);
assert_true(
	false !== strpos( $js, 'clip-path' ) && false !== strpos( $js, 'supports' ),
	'#487: and refuses where the browser does not support clip-path, for the same reason'
);
assert_true(
	false !== strpos( $js, 'et-fb' ),
	'#487: the script bails inside the Visual Builder'
);
assert_true(
	false !== strpos( $plugin_src, '#et-fb-app' ),
	'#487: and the CSS force-disables it there too — VB re-renders modules, and a half-run animation is a corrupted editing surface'
);

// ── 4. It cannot get stuck clipped ───────────────────────────────────────
//
// Between an `error` on the image, an animation event that never arrives, and
// a `naturalWidth` of 0, there are three ways to start a reveal that never
// finishes. Each one would leave the image clipped to nothing, which is a
// blank space on a published page.

assert_true(
	false !== strpos( $js, 'setTimeout' ),
	'#487: a timer clears the active class if no animation event is ever delivered'
);
assert_true(
	false !== strpos( $js, "'error'" ) || false !== strpos( $js, '"error"' ),
	'#487: an image that fails to load is cleaned up rather than left clipped'
);
assert_true(
	false !== strpos( $js, 'naturalWidth' ),
	'#487: and an image that loaded with no dimensions is cleaned up too'
);
assert_true(
	false !== strpos( $js, "addEventListener('animationcancel', onAnimationDone)" ),
	'#487: a cancelled animation cleans up — otherwise the class survives and the image stays clipped'
);
assert_true(
	false !== strpos( $js, "removeEventListener('animationcancel', onAnimationDone)" ),
	'#487: and the listener is torn down, so a re-revealed image does not accumulate handlers'
);

// ── 5. Opt-in, and scoped to the image itself ────────────────────────────

assert_true(
	false !== strpos( $js, 'ddl-image-reveal' ),
	'#487: only modules explicitly marked with the class are touched'
);
assert_true(
	false !== strpos( $js, 'et_pb_image' ),
	'#487: and it targets the native Divi Image module markup'
);
// Clipping the module rather than the <img> would clip the link box and focus
// ring with it, so a keyboard user would lose the focus outline mid-animation.
assert_true(
	false !== strpos( $plugin_src, '.et_pb_image_wrap img.ddl-image-reveal-active' ),
	'#487: only the <img> is clipped, so link targets and focus outlines stay intact'
);
