<?php
// SPDX-License-Identifier: MIT
/**
 * media_validate_positive_id() — REST-boundary positive-integer validation
 * for the media route target-id params (#81).
 *
 * The four /media/* route arg schemas (added in #78) accepted any integer
 * for attach_to (media/upload), attachment_id (media/set-featured-image), and
 * post_id (media/set-featured-image) — 0 and negative values passed the
 * schema and only failed, if at all, deep in handler logic that generally
 * treats an unset/zero target as "no target" rather than rejecting it
 * outright. This tightens the boundary: non-positive values are now rejected
 * by the route's own validate_callback before the handler ever runs.
 *
 * The plain-PHP suite has no register_rest_route()/REST-dispatch machinery
 * (confirmed: no existing validate_callback in this codebase has direct test
 * coverage either — they are inline closures wired only at registration
 * time). This validator is a named, testable method specifically so its
 * logic isn't in that same untested class, even though the route WIRING
 * itself (that WordPress actually invokes this callback for the right args)
 * is, like every other validate_callback here, unverified by this harness.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

// ── absent / empty is valid — these fields are optional; "no target" ──
// is a legitimate state, not an error ───────────────────────────────

assert_true(
	true === diviops_call( 'media_validate_positive_id', array( null, 'attach_to' ) ),
	'null (param entirely absent) passes — optional fields default to no target'
);
assert_true(
	true === diviops_call( 'media_validate_positive_id', array( '', 'attach_to' ) ),
	'empty string passes — same as absent'
);

// ── valid positive integers, in the shapes REST params actually arrive as ──

assert_true(
	true === diviops_call( 'media_validate_positive_id', array( 5, 'attach_to' ) ),
	'a positive int passes'
);
assert_true(
	true === diviops_call( 'media_validate_positive_id', array( '5', 'attach_to' ) ),
	'a positive numeric string passes (REST params commonly arrive as strings)'
);
assert_true(
	true === diviops_call( 'media_validate_positive_id', array( 1, 'attach_to' ) ),
	'1 (the smallest valid positive id) passes'
);

// ── the specific values #81 exists to reject ──

$zero = diviops_call( 'media_validate_positive_id', array( 0, 'attachment_id' ) );
assert_true( $zero instanceof WP_Error, '0 is rejected — not a valid target, and must not be silently treated as "no target"' );
assert_same( 'rest_invalid_param', $zero->get_error_code(), '0 rejection uses the standard REST invalid-param error code' );

$zero_string = diviops_call( 'media_validate_positive_id', array( '0', 'post_id' ) );
assert_true( $zero_string instanceof WP_Error, '"0" as a string is also rejected' );

$negative = diviops_call( 'media_validate_positive_id', array( -5, 'post_id' ) );
assert_true( $negative instanceof WP_Error, 'a negative value is rejected' );
assert_true(
	false !== strpos( $negative->get_error_message(), 'post_id' ),
	'the error message names the actual field that failed'
);

// ── malformed / non-integer input ──

assert_true(
	diviops_call( 'media_validate_positive_id', array( 'not-a-number', 'attach_to' ) ) instanceof WP_Error,
	'a non-numeric string is rejected'
);
assert_true(
	diviops_call( 'media_validate_positive_id', array( 3.5, 'attach_to' ) ) instanceof WP_Error,
	'a non-integer float is rejected — REST params must be whole ids'
);
assert_true(
	diviops_call( 'media_validate_positive_id', array( array( 1 ), 'attach_to' ) ) instanceof WP_Error,
	'an array is rejected rather than triggering a PHP type-juggling surprise'
);

echo "PASS: media-positive-id-validation (13 assertions)\n";
