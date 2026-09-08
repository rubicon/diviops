<?php
// SPDX-License-Identifier: MIT
/**
 * Optional body_content on tb_template_create (#420).
 *
 * This fork wrote a literal '0' into `_et_body_layout_id` on every template it
 * created while setting `_et_body_layout_enabled` to '1', so a template claimed
 * an enabled body slot it did not have and a custom body layout could only be
 * built by leaving the tool for the Visual Builder. Upstream added the third
 * region in `oaris-dev/diviops@8cb630f` (release v1.5.56, plugin 1.5.17).
 *
 * The behaviour is new here, so this is not characterization. The upstream hunk
 * is not cherry-pickable either: it routes through `authoring_shape_preflight()`
 * and `post_type_permission_refusal()`, neither of which this fork carries (both
 * declined in #328). What is adopted is the contract a client sees — the
 * parameter name, the `tb_template_create_body` capability key, the
 * `body_layout_id` field, the `et_body_layout` post type, and the dry-run plan
 * rows — because an MCP client refuses a nonempty body request against a plugin
 * that does not advertise that key, and a fork that spelled any of it
 * differently would look like an older plugin forever.
 *
 * Covered here: the shape refusal, the omitted-body path preserving today's
 * behaviour exactly, the created-and-linked path, the dry-run plan in both
 * states, the capability key, and the permission callback widening its post-type
 * list only when body content is actually present.
 *
 * NOT covered: whether Divi's Theme Builder router renders the resulting body
 * layout. That is a live-site property; `tests-live/` is where it would go, and
 * upstream is explicit that its own change is a source contract rather than
 * Visual Builder qualification.
 *
 * @package DiviOps
 */

// wp-date-hierarchy-shim.php carries get_post_type_object(), which the shared
// shim does not define and which published_post_types_permission_result() calls
// unguarded. It requires wp-shim.php itself.
require_once __DIR__ . '/wp-date-hierarchy-shim.php';

$GLOBALS['diviops_tbb_fixture_ids'] = array();

$diviops_tbb_saved_types = $GLOBALS['diviops_test_post_types'];

/**
 * Register a fixture post and remember it for teardown.
 *
 * @param int    $id      Post id.
 * @param string $content post_content.
 * @param string $type    post_type.
 * @param string $title   post_title.
 * @return object The registered post.
 */
function diviops_tbb_post( int $id, string $content, string $type, string $title ) {
	$GLOBALS['diviops_tbb_fixture_ids'][] = $id;
	$post                                 = diviops_test_register_post( $id, $content, $type, $title );
	$post->post_status = 'publish';
	return $post;
}

/**
 * Invoke a handler with a request built from the given params.
 *
 * @param string $method Handler name on DiviOps_Agent.
 * @param array  $params Request params.
 * @return mixed WP_REST_Response.
 */
function diviops_tbb_call( string $method, array $params = array() ) {
	return diviops_call( $method, array( new DiviOps_Test_Request( $params ) ) );
}

/**
 * The `kind` of every change in a dry-run plan, in order.
 *
 * @param array $data Envelope data.
 * @return array<int, string>
 */
function diviops_tbb_kinds( array $data ): array {
	return array_map(
		static function ( array $change ): string {
			return (string) $change['kind'];
		},
		$data['plan']['changes'] ?? array()
	);
}

/**
 * The post ids currently registered under a post type.
 *
 * @param string $type post_type.
 * @return array<int, int>
 */
function diviops_tbb_ids_of_type( string $type ): array {
	$ids = array();
	foreach ( $GLOBALS['diviops_test_posts'] as $id => $post ) {
		if ( $type === $post->post_type ) {
			$ids[] = (int) $id;
		}
	}
	sort( $ids );
	return $ids;
}

$diviops_tbb_body   = '<!-- wp:divi/section {"attrs":{}} --><!-- /wp:divi/section -->';
$diviops_tbb_header = '<!-- wp:divi/section /-->';

// A published master with a higher id than any other file's fixtures wins
// find_active_master() under the shim's id ordering.
diviops_tbb_post( 5910, '', 'et_theme_builder', 'Body-suite master' );

// ══ Shape refusal ═════════════════════════════════════════════════════════

$diviops_tbb_data = diviops_tbb_call(
	'tb_template_create',
	array( 'title' => 'T', 'condition' => 'default', 'body_content' => 42 )
)->get_data();

assert_same( 'invalid_input', $diviops_tbb_data['error']['code'] ?? null, 'a non-string body_content is refused' );
assert_true(
	false !== strpos( (string) ( $diviops_tbb_data['error']['message'] ?? '' ), 'body_content' ),
	'and the message names the field the caller got wrong'
);

// ══ Omitting it changes nothing ═══════════════════════════════════════════
//
// The literal '0' below is the pre-existing behaviour, kept deliberately: a
// template with no custom body is what Divi's own "use the default body"
// state looks like, and a caller who never sends the parameter must not be
// able to tell this change happened.

$diviops_tbb_data = diviops_tbb_call(
	'tb_template_create',
	array( 'title' => 'No body', 'condition' => 'singular:post_type:post:all', 'header_content' => $diviops_tbb_header, 'dry_run' => true )
)->get_data();

assert_same(
	array( 'tb_template.create', 'tb_layout.create' ),
	diviops_tbb_kinds( $diviops_tbb_data['data'] ?? array() ),
	'a plan with no body content carries no body rows'
);
assert_same(
	false,
	$diviops_tbb_data['data']['plan']['changes'][0]['after']['will_create_body'] ?? null,
	'and says so explicitly rather than omitting the field'
);
assert_same( 0, $diviops_tbb_data['data']['plan']['changes'][0]['after']['body_bytes'] ?? null, 'with a zero byte count' );

$diviops_tbb_before = diviops_tbb_ids_of_type( 'et_body_layout' );
$diviops_tbb_data   = diviops_tbb_call(
	'tb_template_create',
	array( 'title' => 'No body', 'condition' => 'singular:post_type:post:all', 'header_content' => $diviops_tbb_header )
)->get_data();
$diviops_tbb_id     = (int) ( $diviops_tbb_data['data']['template_id'] ?? 0 );
$GLOBALS['diviops_tbb_fixture_ids'][] = $diviops_tbb_id;
$GLOBALS['diviops_tbb_fixture_ids'][] = (int) ( $diviops_tbb_data['data']['header_layout_id'] ?? 0 );

assert_same( 0, $diviops_tbb_data['data']['body_layout_id'] ?? null, 'the response reports no body layout' );
assert_same( '0', get_post_meta( $diviops_tbb_id, '_et_body_layout_id', true ), 'and the meta still stores the literal 0' );
assert_same( '1', get_post_meta( $diviops_tbb_id, '_et_body_layout_enabled', true ), 'with the slot left enabled, as before' );
assert_same( $diviops_tbb_before, diviops_tbb_ids_of_type( 'et_body_layout' ), 'and no body layout post was created' );

// ══ Nonempty body content ═════════════════════════════════════════════════

$diviops_tbb_data = diviops_tbb_call(
	'tb_template_create',
	array( 'title' => 'With body', 'condition' => 'singular:post_type:page:all', 'body_content' => $diviops_tbb_body, 'dry_run' => true )
)->get_data();

assert_same(
	array( 'tb_template.create', 'tb_layout.create', 'tb_template.link' ),
	diviops_tbb_kinds( $diviops_tbb_data['data'] ?? array() ),
	'the plan describes creating the body layout and linking it'
);
assert_same( true, $diviops_tbb_data['data']['plan']['changes'][0]['after']['will_create_body'] ?? null, 'the summary row says a body will be created' );
assert_same( strlen( $diviops_tbb_body ), $diviops_tbb_data['data']['plan']['changes'][0]['after']['body_bytes'] ?? null, 'and how many bytes it carries' );
assert_same( 'et_body_layout', $diviops_tbb_data['data']['plan']['changes'][1]['target'] ?? null, 'the layout row names the body post type' );
assert_same( 'et_template._et_body_layout_id', $diviops_tbb_data['data']['plan']['changes'][2]['target'] ?? null, 'and the link row names the meta key it will set' );
assert_same( $diviops_tbb_before, diviops_tbb_ids_of_type( 'et_body_layout' ), 'control: the dry run wrote nothing' );

$diviops_tbb_data = diviops_tbb_call(
	'tb_template_create',
	array( 'title' => 'With body', 'condition' => 'singular:post_type:page:all', 'body_content' => $diviops_tbb_body )
)->get_data();
$diviops_tbb_id      = (int) ( $diviops_tbb_data['data']['template_id'] ?? 0 );
$diviops_tbb_body_id = (int) ( $diviops_tbb_data['data']['body_layout_id'] ?? 0 );
$GLOBALS['diviops_tbb_fixture_ids'][] = $diviops_tbb_id;
$GLOBALS['diviops_tbb_fixture_ids'][] = $diviops_tbb_body_id;

assert_true( ! empty( $diviops_tbb_data['ok'] ), 'the create succeeds' );
assert_true( $diviops_tbb_body_id > 0, 'a body layout post was created' );
assert_same( 'et_body_layout', $GLOBALS['diviops_test_posts'][ $diviops_tbb_body_id ]->post_type, 'under the body post type' );
assert_same( 'With body Body Layout', $GLOBALS['diviops_test_posts'][ $diviops_tbb_body_id ]->post_title, 'titled after the template, matching the header and footer convention' );
assert_same( $diviops_tbb_body, $GLOBALS['diviops_test_posts'][ $diviops_tbb_body_id ]->post_content, 'carrying the supplied markup' );
assert_same( 'publish', $GLOBALS['diviops_test_posts'][ $diviops_tbb_body_id ]->post_status, 'published, like the other two regions' );
assert_same( $diviops_tbb_body_id, (int) get_post_meta( $diviops_tbb_id, '_et_body_layout_id', true ), 'the template links the new layout' );
assert_same( '1', get_post_meta( $diviops_tbb_id, '_et_body_layout_enabled', true ), 'and the slot is enabled' );
assert_same(
	'on',
	get_post_meta( $diviops_tbb_body_id, '_et_pb_use_builder', true ),
	'the body layout got Divi page meta, the same initialization header and footer layouts get'
);

// ══ The permission callback widens only when it must ══════════════════════
//
// et_body_layout is deliberately left unregistered for the first call. An
// unregistered post type is refused by published_post_types_permission_result(),
// so a callback that ignored body_content would return true here and the
// assertion would fail — which is what makes this a test of the list rather
// than of the refusal.

// The three post types the base list always consults have to be registered
// with the capabilities published_post_types_permission_result() reads, or the
// callback refuses on the first one and never reaches the body slot at all.
foreach ( array( 'et_theme_builder', 'et_template', 'et_header_layout', 'et_footer_layout' ) as $diviops_tbb_type ) {
	diviops_test_register_post_type(
		$diviops_tbb_type,
		array( 'cap' => (object) array( 'create_posts' => 'manage_options', 'publish_posts' => 'manage_options' ) )
	);
}
unset( $GLOBALS['diviops_test_post_types']['et_body_layout'] );

assert_same(
	true,
	diviops_call( 'check_tb_template_create_permission', array( new DiviOps_Test_Request( array( 'title' => 'T', 'condition' => 'default' ) ) ) ),
	'control: with no body content the unregistered body post type is never consulted'
);

$diviops_tbb_refusal = diviops_call(
	'check_tb_template_create_permission',
	array( new DiviOps_Test_Request( array( 'title' => 'T', 'condition' => 'default', 'body_content' => $diviops_tbb_body ) ) )
);
assert_same( true, is_wp_error( $diviops_tbb_refusal ), 'nonempty body content puts et_body_layout into the permission list' );
assert_same( 'et_body_layout', $diviops_tbb_refusal->get_error_data()['post_type'] ?? null, 'and the refusal names it' );

assert_same(
	true,
	diviops_call( 'check_tb_template_create_permission', array( new DiviOps_Test_Request( array( 'title' => 'T', 'condition' => 'default', 'body_content' => '' ) ) ) ),
	'an empty string does not, so the legacy call shape is unaffected'
);

// ══ The advertised contract ═══════════════════════════════════════════════

assert_true(
	in_array( 'tb_template_create_body', DiviOps_Agent::CAPABILITIES, true ),
	'CAPABILITIES advertises tb_template_create_body, which is what an updated client gates a nonempty body request on'
);
assert_true(
	in_array( 'tb_template_create', DiviOps_Agent::CAPABILITIES, true ),
	'control: the tool-name key it sits beside is still there'
);

// ══ Teardown ══════════════════════════════════════════════════════════════

foreach ( $GLOBALS['diviops_tbb_fixture_ids'] as $diviops_tbb_fixture_id ) {
	unset(
		$GLOBALS['diviops_test_posts'][ $diviops_tbb_fixture_id ],
		$GLOBALS['diviops_test_post_meta'][ $diviops_tbb_fixture_id ],
		$GLOBALS['diviops_test_post_meta_rows'][ $diviops_tbb_fixture_id ]
	);
}
$GLOBALS['diviops_test_post_types'] = $diviops_tbb_saved_types;

assert_same( null, $GLOBALS['diviops_test_posts'][5910] ?? null, 'teardown removed this file\'s master fixture' );
assert_same( array(), diviops_tbb_ids_of_type( 'et_body_layout' ), 'and every body layout it created' );
assert_same( $diviops_tbb_saved_types, $GLOBALS['diviops_test_post_types'], 'and restored the shared post-type registry' );
