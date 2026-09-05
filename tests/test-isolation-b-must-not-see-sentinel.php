<?php
// SPDX-License-Identifier: MIT
/**
 * Process-isolation probe, part B of two (#395).
 *
 * Asserts the sentinel defined by `tests/test-isolation-a-defines-sentinel.php` is
 * NOT visible here. Red in a single-process runner, green once each file runs in its
 * own process.
 *
 * This is the assertion that would have caught the #380 blockage before it cost a
 * session: a function defined by one test file leaking into every later one.
 */

require_once __DIR__ . '/wp-shim.php';

assert_same(
	false,
	function_exists( 'diviops_isolation_sentinel' ),
	'each test file runs in its own process — a function defined by another file must not leak here (#395)'
);
