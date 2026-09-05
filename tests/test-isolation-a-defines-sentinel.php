<?php
// SPDX-License-Identifier: MIT
/**
 * Process-isolation probe, part A of two (#395).
 *
 * Defines a sentinel function. Its partner file asserts the sentinel is NOT visible.
 * The two together prove each test file gets its own PHP process, which is what makes
 * `function_exists()` a per-file fact rather than a one-way global latch.
 *
 * Why this matters concretely: the plugin decides Divi is installed with
 * `function_exists( 'et_get_option' )` (`diviops-agent.php:755`, `:2621`,
 * `trait-meta.php:1847`). Without isolation, ANY test that defines the Divi option
 * accessors flips every later file to "Divi active" and destroys the Divi-inactive
 * coverage in `tests/test-meta-characterization.php`, plus the fatal-as-signal probes
 * in the ref-scan and preset characterization files. That is what blocks #380.
 *
 * These two files are deliberately named `isolation-a` / `isolation-b` so glob order
 * puts A first. If they are ever renamed, keep A sorting before B or the probe proves
 * nothing.
 */

require_once __DIR__ . '/wp-shim.php';

if ( ! function_exists( 'diviops_isolation_sentinel' ) ) {
	function diviops_isolation_sentinel(): string {
		return 'defined-in-file-a';
	}
}

assert_true(
	function_exists( 'diviops_isolation_sentinel' ),
	'part A defines the sentinel — if this fails the probe is broken, not the runner'
);
assert_same(
	'defined-in-file-a',
	diviops_isolation_sentinel(),
	'the sentinel is the one this file defined'
);
