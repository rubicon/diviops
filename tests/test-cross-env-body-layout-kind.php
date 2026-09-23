<?php
// SPDX-License-Identifier: MIT
/**
 * `tb_body_layout` is a first-class cross-environment kind (#472).
 *
 * `cross_env_target_context_get` and `cross_env_source_export_get` refused any
 * kind but header and footer, because `cross_env_layout_kind_map()` carried two
 * entries. Upstream carries three. Body layouts are where a Theme Builder
 * template puts the page content itself, so refusing them left the most
 * substantial of the three slots unable to move between environments at all.
 *
 * ── The trap this file exists to catch ────────────────────────────────────
 *
 * Slot derivation was a ternary:
 *
 *     $slot = 'tb_header_layout' === $kind ? 'header' : 'footer';
 *
 * That was SAFE only because the kind map gate upstream of it admitted exactly
 * two values, so "not header" really did mean footer. Adding `tb_body_layout`
 * to the map without replacing the ternary makes a body layout silently derive
 * slot `footer` and read `_et_footer_layout_id` / `_et_footer_layout_enabled`.
 * It would not error. It would return a well-formed linkage describing the
 * wrong slot, with a sha256 digest over it that a consumer is meant to trust.
 *
 * So the load-bearing assertions below are not "body is accepted" — they are
 * that a body kind reads BODY meta, pinned against a fixture whose body and
 * footer slots hold deliberately different layout ids. With identical ids the
 * whole file would pass against the broken ternary.
 *
 * ── What is deliberately NOT adopted here ─────────────────────────────────
 *
 * Upstream pairs this with `class-cross-env-staff-body.php`, which emits a
 * `staff_body` proof on both handlers for body layouts. That class hardcodes a
 * `diviops_staff` post type and an ACF field named `diviops_staff_role`, and
 * measured on staging: the post type does not exist, ACF is not installed, and
 * there are zero `et_body_layout` posts. It could only ever refuse here. It is
 * a separate decision and is not in this file — see #472.
 *
 * The consequence is stated rather than hidden: without it, a body-layout
 * target context carries no `staff_body` field, so DiviOps Agent Pro's
 * `tb_body_layout` apply still refuses. This change makes the kind readable,
 * not applicable through Pro.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
// The export path reaches cross_env_global_colors(), which calls et_get_option().
// Only reachable now that a body kind is no longer refused before it.
require_once __DIR__ . '/divi-active-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

$GLOBALS['diviops_cebl_saved_posts'] = $GLOBALS['diviops_test_posts'] ?? array();
$GLOBALS['diviops_test_posts']       = array();

/** Register a post fixture. */
function diviops_cebl_post( int $id, string $content, string $type, string $title, string $status = 'publish' ) {
	$post = diviops_test_register_post( $id, $content, $type, $title );
	$post->post_status = $status;
	return $post;
}

/** Invoke a handler with a request built from the given params. */
function diviops_cebl_call( string $method, array $params ) {
	return diviops_call( $method, array( new DiviOps_Test_Request( $params ) ) );
}

// ── 1. The kind map admits all three slots ────────────────────────────────

$map = diviops_call( 'cross_env_layout_kind_map' );
assert_same(
	array(
		'tb_header_layout' => 'et_header_layout',
		'tb_footer_layout' => 'et_footer_layout',
		'tb_body_layout'   => 'et_body_layout',
	),
	$map,
	'#472: all three Theme Builder layout kinds map to their post types'
);

// ── 2. Slot derivation, the part the ternary got wrong ────────────────────
//
// Fixture: ONE template whose three slots point at three DIFFERENT layout ids,
// and whose body and footer slots differ in enabled-state too. A body kind that
// fell through to the footer branch would report footer's id and footer's
// enabled flag, and both are distinguishable here.

$master_id   = 7400;
$template_id = 7401;
$header_id   = 7402;
$body_id     = 7403;
$footer_id   = 7404;

diviops_cebl_post( $master_id, '', 'et_theme_builder', 'Master' );
diviops_cebl_post( $template_id, '', 'et_template', 'Template' );
diviops_cebl_post( $header_id, '<!-- wp:divi/section /-->', 'et_header_layout', 'Header' );
diviops_cebl_post( $body_id, '<!-- wp:divi/section /-->', 'et_body_layout', 'Body' );
diviops_cebl_post( $footer_id, '<!-- wp:divi/section /-->', 'et_footer_layout', 'Footer' );

add_post_meta( $master_id, '_et_template', $template_id );
update_post_meta( $template_id, '_et_enabled', '1' );
update_post_meta( $template_id, '_et_default', '1' );
update_post_meta( $template_id, '_et_header_layout_id', $header_id );
update_post_meta( $template_id, '_et_header_layout_enabled', '1' );
update_post_meta( $template_id, '_et_body_layout_id', $body_id );
// Deliberately DIFFERENT from the footer slot below: if a body kind read footer
// meta it would report enabled=false here, and the assertion would catch it.
update_post_meta( $template_id, '_et_body_layout_enabled', '1' );
update_post_meta( $template_id, '_et_footer_layout_id', $footer_id );
update_post_meta( $template_id, '_et_footer_layout_enabled', '0' );

assert_true(
	$body_id !== $footer_id,
	'#472: the fixture gives body and footer different layout ids, so reading the wrong slot is detectable'
);

$body_linkage = diviops_call( 'cross_env_template_linkage', array( $body_id, 'tb_body_layout', 'et_body_layout' ) );
$link         = $body_linkage['evidence']['links'][0] ?? array();

assert_same(
	1,
	count( $body_linkage['evidence']['links'] ?? array() ),
	'#472: the body layout is linked to exactly one template, so the assertions below inspect a real link'
);
assert_same(
	'body',
	$link['slot'] ?? null,
	'#472: a tb_body_layout kind derives the body slot — NOT footer by falling through a two-way ternary'
);
assert_same(
	$body_id,
	$link['layout_id'] ?? null,
	'#472: and reports the body layout id'
);
assert_same(
	true,
	$link['layout_enabled'] ?? null,
	'#472: reading _et_body_layout_enabled, which differs from the footer slot in this fixture'
);

// The other two kinds must be unchanged by the rewrite.
$header_linkage = diviops_call( 'cross_env_template_linkage', array( $header_id, 'tb_header_layout', 'et_header_layout' ) );
assert_same(
	'header',
	$header_linkage['evidence']['links'][0]['slot'] ?? null,
	'#472: a header kind still derives the header slot'
);
$footer_linkage = diviops_call( 'cross_env_template_linkage', array( $footer_id, 'tb_footer_layout', 'et_footer_layout' ) );
assert_same(
	'footer',
	$footer_linkage['evidence']['links'][0]['slot'] ?? null,
	'#472: and a footer kind still derives the footer slot'
);
assert_same(
	false,
	$footer_linkage['evidence']['links'][0]['layout_enabled'] ?? null,
	'#472: reading the footer slot\'s own disabled flag, which proves the two slots are really distinguishable'
);

// The digest covers the evidence, so a wrong slot would also produce a
// confidently-signed wrong answer. Pinning that the three differ at all.
assert_true(
	$body_linkage['digest']['computed'] !== $footer_linkage['digest']['computed']
		&& $body_linkage['digest']['computed'] !== $header_linkage['digest']['computed'],
	'#472: each kind produces its own digest — a slot mix-up would be signed as confidently as a correct one'
);

// ── 3. Both handlers accept the kind ──────────────────────────────────────

$target = diviops_cebl_call( 'cross_env_target_context_get', array(
	'destination_id'   => $body_id,
	'destination_kind' => 'tb_body_layout',
) )->get_data();
assert_true(
	! empty( $target['ok'] ),
	'#472: cross_env_target_context_get accepts a body layout instead of refusing the kind'
);
assert_same(
	'body',
	$target['data']['template_linkage']['links'][0]['slot'] ?? null,
	'#472: and the template_linkage it returns names the body slot'
);

$source = diviops_cebl_call( 'cross_env_source_export_get', array(
	'source_id'   => $body_id,
	'source_kind' => 'tb_body_layout',
) )->get_data();
assert_true(
	! empty( $source['ok'] ),
	'#472: cross_env_source_export_get accepts a body layout too — an export with no matching import is half a feature'
);
assert_same(
	'tb_body_layout',
	$source['data']['object_kind'] ?? null,
	'#472: and reports the kind it exported'
);

// ── 4. An unsupported kind still refuses, and says what IS supported ──────
//
// The refusal messages named only header and footer. Left alone they would now
// be false — the most common way documentation starts lying is a widened gate
// whose error text nobody updated.

$bad_target = diviops_cebl_call( 'cross_env_target_context_get', array(
	'destination_id'   => $body_id,
	'destination_kind' => 'tb_sidebar_layout',
) )->get_data();
assert_same(
	'invalid_input',
	$bad_target['error']['code'] ?? null,
	'#472: an unknown destination kind is still refused'
);
assert_true(
	false !== strpos( (string) ( $bad_target['error']['message'] ?? '' ), 'tb_body_layout' ),
	'#472: and the refusal names tb_body_layout as supported, rather than listing only the original two'
);

$bad_source = diviops_cebl_call( 'cross_env_source_export_get', array(
	'source_id'   => $body_id,
	'source_kind' => 'tb_sidebar_layout',
) )->get_data();
assert_true(
	false !== strpos( (string) ( $bad_source['error']['message'] ?? '' ), 'tb_body_layout' ),
	'#472: the source-export refusal names it too'
);

// ── 5. The staff_body proof is NOT emitted, and that is deliberate ────────
//
// Asserted rather than left unstated: a later reader must be able to tell that
// its absence is a decision recorded in #472, not an oversight. If the class is
// ever adopted, this assertion is the one that should fail and be rewritten.
assert_true(
	! array_key_exists( 'staff_body', (array) ( $target['data'] ?? array() ) ),
	'#472: no staff_body proof is emitted — the class that builds it hardcodes a diviops_staff CPT and ACF, and is a separate decision'
);

$GLOBALS['diviops_test_posts'] = $GLOBALS['diviops_cebl_saved_posts'];
