<?php
// SPDX-License-Identifier: MIT
/**
 * Characterization of the Theme Builder handlers that had no test coverage.
 *
 * `plugins/diviops-agent/includes/trait-theme-builder.php` declares eight public
 * static handlers. Measured by searching `tests/` and `tests-live/` for each
 * handler name (102 files scanned, positive control run first), three were
 * referenced before this file: `tb_template_list` and `tb_layout_get`
 * behaviourally, and `tb_layout_block_insert` structurally — the last only as a
 * name in `tests/test-parse-blocks-for-write-coverage.php`'s list of methods that
 * must parse through `parse_blocks_for_write()`, plus prose mentions elsewhere.
 * The other five had zero references of any kind:
 *
 *   cross_env_source_export_get, cross_env_target_context_get,
 *   tb_layout_update, tb_template_create, tb_template_trash
 *
 * This file characterizes those five. A characterization test records what the
 * code does today, right or wrong, so that a later edit — an upstream adoption in
 * particular — cannot change it silently. Two of the behaviours pinned below are
 * defects, and they are pinned AS defects, with the defect named in the comment
 * above the assertion. Do not "fix" one by editing the expectation here: the
 * assertion failing is the signal it exists to produce.
 *
 * Two handlers cannot be driven end to end past their permission gates in this
 * harness, and both stop for reasons this suite has settled before:
 *
 *   - `cross_env_target_context_get()` reaches Divi's `et_get_option()` through
 *     `cross_env_global_colors()`. That primitive is deliberately unshimmed here
 *     (see tests/test-preset-reassign-write-safety.php), so this file drives the
 *     handler's refusal branches directly and then characterizes the asset-hint,
 *     candidate, and remap chain its `attachments` / `attachment_remaps` fields
 *     are built from by calling those helpers directly.
 *   - `tb_layout_block_insert()` is out of scope here; it already has the
 *     structural coverage named above.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

// ── Fixture bookkeeping ───────────────────────────────────────────────────
//
// The post/meta registries are process-wide and shared with every other test
// file. This file registers Theme Builder masters, and `find_active_master()`
// resolves the highest-id published `et_theme_builder`, so leaving one behind
// would silently retarget tests/test-tb-template-list-master-scoping.php, which
// runs later and expects its own master to win. Everything created here — fixed
// fixtures and posts minted by `wp_insert_post()` alike — is removed at the end,
// and the shim's id counter is restored so later files stay deterministic.

// Note: run.php requires each test file inside a closure, so a variable written
// at this file's top level is local to that closure and NOT a global. The
// bookkeeping list therefore lives in $GLOBALS explicitly, which is what lets
// diviops_tbc_post() append to the same array this file's teardown reads.
$GLOBALS['diviops_tbc_fixture_ids'] = array();
$diviops_tbc_first_minted_id = (int) ( $GLOBALS['diviops_test_next_id'] ?? 9000 );

/**
 * Register a fixture post and remember it for teardown.
 *
 * @param int    $id      Post id.
 * @param string $content post_content.
 * @param string $type    post_type.
 * @param string $title   post_title.
 * @param string $status  post_status.
 * @return object The registered post.
 */
function diviops_tbc_post( int $id, string $content, string $type, string $title, string $status = 'publish' ) {
	$GLOBALS['diviops_tbc_fixture_ids'][] = $id;
	$post = diviops_test_register_post( $id, $content, $type, $title );
	$post->post_status = $status;
	return $post;
}

/**
 * Register an attachment fixture the way the cross-env matcher reads one: an
 * `inherit` post of type `attachment` whose `_wp_attached_file` meta is the
 * uploads-relative path, plus the shim's attachment-url registry entry.
 *
 * @param int    $id            Attachment id.
 * @param string $attached_file Uploads-relative path stored in `_wp_attached_file`.
 * @param string $mime          post_mime_type.
 */
function diviops_tbc_attachment( int $id, string $attached_file, string $mime = 'image/jpeg' ): void {
	$post = diviops_tbc_post( $id, '', 'attachment', basename( $attached_file ), 'inherit' );
	$post->post_mime_type = $mime;
	update_post_meta( $id, '_wp_attached_file', $attached_file );
	$GLOBALS['diviops_test_attachments'][ $id ] = array(
		'url'      => 'http://example.test/wp-content/uploads/' . $attached_file,
		'filename' => basename( $attached_file ),
	);
}

/**
 * Invoke a handler with a request built from the given params.
 *
 * @param string $method Handler name on DiviOps_Agent.
 * @param array  $params Request params.
 * @return WP_REST_Response
 */
function diviops_tbc_call( string $method, array $params ) {
	return diviops_call( $method, array( new DiviOps_Test_Request( $params ) ) );
}

// ══ cross_env_source_export_get ═══════════════════════════════════════════

$response = diviops_tbc_call( 'cross_env_source_export_get', array( 'source_id' => 0 ) );
$body     = $response->get_data();
assert_same( false, $body['ok'] ?? null, 'source export refuses a non-positive source_id' );
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'the refusal code is invalid_input' );
assert_same( 400, $response->get_status(), 'a non-positive source_id is a 400' );
assert_same( 'source_id', $body['error']['data']['field'] ?? null, 'the refusal names the offending field' );

diviops_tbc_post( 5400, '', 'et_header_layout', 'Site Header' );
$body = diviops_tbc_call(
	'cross_env_source_export_get',
	array( 'source_id' => 5400, 'source_kind' => 'tb_body_layout' )
)->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'only header and footer kinds are exportable' );
assert_same( 'tb_body_layout', $body['error']['data']['received'] ?? null, 'the refusal echoes the rejected kind' );

diviops_tbc_post( 5401, '', 'page', 'Not a layout' );
$response = diviops_tbc_call( 'cross_env_source_export_get', array( 'source_id' => 5401 ) );
$body     = $response->get_data();
assert_same( 'not_found', $body['error']['code'] ?? null, 'a post of the wrong type is not_found, not forbidden' );
assert_same( 404, $response->get_status(), 'the wrong-type refusal is a 404' );

$GLOBALS['diviops_test_uneditable_ids'] = array( 5400 );
$response = diviops_tbc_call( 'cross_env_source_export_get', array( 'source_id' => 5400 ) );
$body     = $response->get_data();
assert_same( 'forbidden', $body['error']['code'] ?? null, 'the row-level edit_post gate refuses an uneditable layout' );
assert_same( 403, $response->get_status(), 'the row-level refusal is a 403' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// Happy path. The markup names attachment 5402 by id AND carries the same
// file's absolute uploads URL, which is the shape a real Divi image module
// emits — so it exercises both inventory sources at once and the dedup between
// them.
diviops_tbc_attachment( 5402, '2024/05/hero.jpg' );
$diviops_tbc_markup = '<!-- wp:divi/image {"module":{"advanced":{"image":{"desktop":{"value":'
	. '{"imageId":5402,"src":"http://example.test/wp-content/uploads/2024/05/hero.jpg"}}}}}} /-->';
diviops_tbc_post( 5403, $diviops_tbc_markup, 'et_header_layout', 'Exportable Header' );

$response = diviops_tbc_call( 'cross_env_source_export_get', array( 'source_id' => 5403 ) );
$body     = $response->get_data();
$data     = $body['data'] ?? array();

assert_true( ! empty( $body['ok'] ), 'source export returns a success envelope' );
assert_same( 'tb_header_layout', $data['object_kind'] ?? null, 'the export echoes the requested kind' );
assert_same( 'et_header_layout', $data['object_post_type'] ?? null, 'the export names the resolved post type' );
assert_same( 'http://example.test', $data['origin'] ?? null, 'origin is the scheme+host+port site origin' );
assert_same(
	hash( 'sha256', (string) ( $data['markup'] ?? '' ) ),
	$data['checksum'] ?? null,
	'checksum is sha256 over the exported (sanitized) markup, not the raw post_content'
);
assert_same(
	'best_effort_markup_upload_urls_and_attachment_ids',
	$data['_meta']['attachment_inventory'] ?? null,
	'the export declares how its attachment inventory was derived'
);
assert_same( true, $data['_meta']['read_only'] ?? null, 'the export declares itself read-only' );
assert_same( false, $data['_meta']['write_apply_media_import'] ?? null, 'the export imports no media' );

$attachments = $data['attachments'] ?? array();
assert_same(
	1,
	count( $attachments ),
	'the id-derived row and the URL-derived row for one file collapse to a single entry'
);
// The `path` field is site-rooted, not uploads-relative. That shape is what
// makes the dedup above work: cross_env_source_attachment_payload_from_id()
// builds `/wp-content/uploads/` . $attached_file and the markup-URL branch
// keys on cross_env_upload_path_from_url(), which returns the same rooted
// form. Change one without the other and the same file is inventoried twice.
assert_same(
	'/wp-content/uploads/2024/05/hero.jpg',
	$attachments[0]['path'] ?? null,
	'an exported attachment path is site-rooted (/wp-content/uploads/...)'
);
assert_same( 5402, $attachments[0]['id'] ?? null, 'the id-derived row wins the dedup and keeps its attachment id' );
assert_same( 'hero.jpg', $attachments[0]['filename'] ?? null, 'filename is the basename of that path' );
assert_same( 'image/jpeg', $attachments[0]['mime'] ?? null, 'the id-derived row carries the mime type' );

// ══ cross_env_target_context_get ══════════════════════════════════════════
//
// Refusal branches only — see the file docblock for why the success path stops
// at Divi's unshimmed et_get_option().

$response = diviops_tbc_call( 'cross_env_target_context_get', array( 'destination_id' => 0 ) );
$body     = $response->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'target context refuses a non-positive destination_id' );
assert_same( 'destination_id', $body['error']['data']['field'] ?? null, 'the refusal names destination_id' );

$body = diviops_tbc_call(
	'cross_env_target_context_get',
	array( 'destination_id' => 5400, 'destination_kind' => 'tb_body_layout' )
)->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'target context supports header and footer kinds only' );
assert_same( 'tb_body_layout', $body['error']['data']['received'] ?? null, 'the refusal echoes the rejected kind' );

$response = diviops_tbc_call( 'cross_env_target_context_get', array( 'destination_id' => 5401 ) );
assert_same( 'not_found', $response->get_data()['error']['code'] ?? null, 'a wrong-type destination is not_found' );
assert_same( 404, $response->get_status(), 'the wrong-type destination refusal is a 404' );

$GLOBALS['diviops_test_uneditable_ids'] = array( 5400 );
$response = diviops_tbc_call( 'cross_env_target_context_get', array( 'destination_id' => 5400 ) );
assert_same( 'forbidden', $response->get_data()['error']['code'] ?? null, 'an uneditable destination is forbidden' );
assert_same( 403, $response->get_status(), 'the uneditable-destination refusal is a 403' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// The hint → candidate → remap chain behind the handler's `attachments` and
// `attachment_remaps` fields. `diviops-server/src/index.ts`'s
// sourceHintsFromPayload() sends an attachment's path, url AND filename as
// hints, so that three-hint set is what the target side actually receives.

$hints = diviops_call_static(
	'cross_env_normalize_asset_hints',
	array(
		array(
			'/wp-content/uploads/2024/05/hero.jpg',
			'http://example.test/wp-content/uploads/2024/05/hero.jpg',
			'hero.jpg',
		),
		array( 77 ),
	)
);
assert_same(
	2,
	count( $hints['assets'] ),
	'the rooted path and the absolute URL normalize to one hint; the bare filename is a second'
);
assert_same( '2024/05/hero.jpg', $hints['assets'][0]['upload_path'] ?? null, 'a path or URL hint yields an uploads-relative upload_path' );
assert_true(
	array_key_exists( 'upload_path', $hints['assets'][1] ) && null === $hints['assets'][1]['upload_path'],
	'a bare filename yields a null upload_path, only a basename'
);
assert_same( 'hero.jpg', $hints['assets'][1]['basename'] ?? null, 'the filename hint keeps its basename' );
assert_same( array( 77 ), $hints['source_ids'], 'source ids pass through as ints' );

$candidates = diviops_call_static( 'cross_env_attachment_candidates', array( $hints['assets'], $hints['source_ids'] ) );
assert_same( 1, count( $candidates ), 'one target attachment matches that hint set' );
assert_same( 5402, $candidates[0]['id'] ?? null, 'the matched candidate is the attachment whose _wp_attached_file matches' );
assert_same(
	'/wp-content/uploads/2024/05/hero.jpg',
	$candidates[0]['path'] ?? null,
	'a target candidate path is site-rooted, matching the source export shape'
);
assert_same( 77, $candidates[0]['source_attachment_id'] ?? null, 'a single source id is attributed onto the candidate' );

// DEFECT, pinned as-is. The candidate is proven by an exact upload-path match,
// but the bare-filename hint is queried afterwards, resolves the same
// attachment, and overwrites the row under the same `id|path` key — so the
// evidence label degrades to the weaker `target_basename_exact`. Because
// sourceHintsFromPayload() always sends a filename alongside the path, the
// stronger proof is never reported in practice.
assert_same(
	'target_basename_exact',
	$candidates[0]['proof'] ?? null,
	'DEFECT: a later basename hint overwrites the exact-upload-path proof'
);

$path_only = diviops_call_static(
	'cross_env_normalize_asset_hints',
	array( array( '/wp-content/uploads/2024/05/hero.jpg' ), array( 77 ) )
);
$path_only_candidates = diviops_call_static(
	'cross_env_attachment_candidates',
	array( $path_only['assets'], $path_only['source_ids'] )
);
assert_same(
	'target_upload_path_exact',
	$path_only_candidates[0]['proof'] ?? null,
	'the same match reports the stronger proof when no basename hint follows it'
);

$remaps = diviops_call_static( 'cross_env_attachment_remaps', array( $candidates, $hints['source_ids'] ) );
assert_same(
	array( '77' => array( 'target_id' => 5402, 'proof' => 'target_basename_exact' ) ),
	$remaps,
	'a unique candidate plus a single source id emits one remap carrying the candidate proof'
);

// DEFECT, pinned as-is. A second attachment sharing the basename in a different
// month is reachable only through the bare-filename hint, but it still lands in
// the candidate set even though the exact upload-path hint already identified
// exactly one attachment. Two target ids then suppress the remap entirely, and
// the preflight reports missing_remap for an asset it could have proven.
diviops_tbc_attachment( 5404, '2023/01/hero.jpg' );
$dup_candidates = diviops_call_static( 'cross_env_attachment_candidates', array( $hints['assets'], $hints['source_ids'] ) );
assert_same( 2, count( $dup_candidates ), 'DEFECT: a duplicate basename in another folder joins the candidate set' );
assert_same(
	array(),
	diviops_call_static( 'cross_env_attachment_remaps', array( $dup_candidates, $hints['source_ids'] ) ),
	'DEFECT: the extra candidate suppresses a remap the exact upload path had already proven'
);

// ══ tb_layout_update ══════════════════════════════════════════════════════

$response = diviops_tbc_call( 'tb_layout_update', array( 'id' => 5401, 'content' => '' ) );
$body     = $response->get_data();
assert_same( 'not_found', $body['error']['code'] ?? null, 'tb_layout_update refuses a post that is not a TB layout' );
assert_same( 404, $response->get_status(), 'the wrong-type refusal is a 404' );

$body = diviops_tbc_call( 'tb_layout_update', array( 'id' => 5400, 'content' => 42 ) )->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'content must be a string' );

diviops_tbc_post( 5405, '<!-- wp:divi/section /-->', 'et_header_layout', 'Header' );
$body = diviops_tbc_call(
	'tb_layout_update',
	array( 'id' => 5405, 'content' => '<!-- wp:divi/section --><!-- /wp:divi/section -->', 'dry_run' => true )
)->get_data();
$plan = $body['data']['plan'] ?? array();
assert_same( true, $body['data']['dry_run'] ?? null, 'a dry run reports itself as one' );
assert_same( 1, count( $plan['changes'] ?? array() ), 'the plan carries exactly one change' );
assert_same( 'tb_layout.update', $plan['changes'][0]['kind'] ?? null, 'the change is a tb_layout.update' );
assert_same( 'et_header_layout#5405', $plan['changes'][0]['target'] ?? null, 'the target names the post type and id' );
assert_same( 25, $plan['changes'][0]['before']['bytes'] ?? null, 'the plan reports the stored byte count' );
assert_same( 49, $plan['changes'][0]['after']['bytes'] ?? null, 'the plan reports the requested byte count' );
assert_same(
	'<!-- wp:divi/section /-->',
	$GLOBALS['diviops_test_posts'][5405]->post_content,
	'a dry run writes nothing'
);

$body = diviops_tbc_call(
	'tb_layout_update',
	array( 'id' => 5405, 'content' => '<!-- wp:divi/section --><!-- /wp:divi/section -->' )
)->get_data();
assert_true( ! empty( $body['ok'] ), 'the apply returns a success envelope' );
assert_same( 5405, $body['data']['id'] ?? null, 'the response names the updated layout' );
assert_same( 'et_header_layout', $body['data']['type'] ?? null, 'the response names its post type' );
assert_same(
	'<!-- wp:divi/section --><!-- /wp:divi/section -->',
	$GLOBALS['diviops_test_posts'][5405]->post_content,
	'the apply replaced post_content'
);

// ══ tb_template_create ════════════════════════════════════════════════════
//
// A published master with a higher id than any other test file's wins
// find_active_master() under the shim's id ordering, which is what scopes the
// singleton-default check below to this file's own fixtures.

$body = diviops_tbc_call(
	'tb_template_create',
	array( 'title' => 'T', 'condition' => 'default', 'header_content' => 42 )
)->get_data();
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'header_content must be a string when provided' );

diviops_tbc_post( 5410, '', 'et_theme_builder', 'Active master' );

$body = diviops_tbc_call(
	'tb_template_create',
	array( 'title' => 'Blog', 'condition' => 'singular:post_type:post:all', 'header_content' => '<!-- wp:divi/section /-->', 'dry_run' => true )
)->get_data();
$plan = $body['data']['plan'] ?? array();
$kinds = array_map(
	static function ( array $change ): string {
		return (string) $change['kind'];
	},
	$plan['changes'] ?? array()
);
assert_same(
	array( 'tb_template.create', 'tb_layout.create' ),
	$kinds,
	'with a master already present the plan creates a template and its header layout, and no master'
);
assert_same( false, $plan['changes'][0]['after']['is_default'] ?? null, 'a specific condition is not the default template' );
assert_same( true, $plan['changes'][0]['after']['will_create_header'] ?? null, 'the plan says a header layout will be created' );
assert_same( false, $plan['changes'][0]['after']['will_create_footer'] ?? null, 'and that no footer layout will be' );
assert_same( 'et_header_layout', $plan['changes'][1]['target'] ?? null, 'the layout change names the header post type' );

$body = diviops_tbc_call(
	'tb_template_create',
	array( 'title' => 'Blog', 'condition' => 'singular:post_type:post:all', 'header_content' => '<!-- wp:divi/section /-->' )
)->get_data();
$data = $body['data'] ?? array();
assert_true( ! empty( $body['ok'] ), 'the create returns a success envelope' );
assert_same( 5410, $data['master_post_id'] ?? null, 'the template is linked to the existing active master' );
assert_same( false, $data['master_post_bootstrapped'] ?? null, 'no master was bootstrapped' );
assert_same( false, $data['is_default'] ?? null, 'a specific condition is not the default template' );

$template_id = (int) ( $data['template_id'] ?? 0 );
$header_id   = (int) ( $data['header_layout_id'] ?? 0 );
assert_true( $template_id > 0, 'a template post was created' );
assert_true( $header_id > 0, 'a header layout post was created' );
assert_same( 0, $data['footer_layout_id'] ?? null, 'no footer layout was created' );
assert_same( '0', get_post_meta( $template_id, '_et_default', true ), 'a conditional template stores _et_default = 0' );
assert_same( '1', get_post_meta( $template_id, '_et_enabled', true ), 'the template is enabled' );
assert_same( $header_id, get_post_meta( $template_id, '_et_header_layout_id', true ), 'the header layout id is linked' );
assert_same( '1', get_post_meta( $template_id, '_et_header_layout_enabled', true ), 'the header slot is enabled' );
assert_same( '0', get_post_meta( $template_id, '_et_footer_layout_enabled', true ), 'the empty footer slot is disabled' );
assert_same(
	array( 'singular:post_type:post:all' ),
	get_post_meta( $template_id, '_et_use_on', false ),
	'the condition is stored as an _et_use_on row'
);
assert_same(
	array( $template_id ),
	array_map( 'intval', get_post_meta( 5410, '_et_template', false ) ),
	'the master linked-list names the new template'
);

// The singleton-default constraint is scoped to the active master's linked
// list, and rejects rather than silently repositioning.
update_post_meta( $template_id, '_et_default', '1' );
$response = diviops_tbc_call( 'tb_template_create', array( 'title' => 'Second default', 'condition' => 'default' ) );
$body     = $response->get_data();
assert_same(
	'tb_template.default_already_exists',
	$body['error']['code'] ?? null,
	'a second Default Website Template under the same master is refused'
);
assert_same( 409, $response->get_status(), 'the singleton-default refusal is a 409 conflict' );
assert_same( $template_id, $body['error']['data']['existing_default_id'] ?? null, 'the refusal names the incumbent default' );
assert_same( 5410, $body['error']['data']['master_post_id'] ?? null, 'and the master it is linked to' );
update_post_meta( $template_id, '_et_default', '0' );

// ══ tb_template_trash ═════════════════════════════════════════════════════

$response = diviops_tbc_call( 'tb_template_trash', array( 'id' => 999999 ) );
$body     = $response->get_data();
assert_same( 'not_found', $body['error']['code'] ?? null, 'trashing an unknown template is not_found' );
assert_same( 999999, $body['error']['data']['template_id'] ?? null, 'the refusal names the id it looked for' );

$GLOBALS['diviops_test_denied_caps'] = array( 'delete_post' );
$response = diviops_tbc_call( 'tb_template_trash', array( 'id' => $template_id ) );
assert_same( 'forbidden', $response->get_data()['error']['code'] ?? null, 'the delete_post gate refuses the trash' );
assert_same( 403, $response->get_status(), 'the capability refusal is a 403' );
$GLOBALS['diviops_test_denied_caps'] = array();

$body  = diviops_tbc_call( 'tb_template_trash', array( 'id' => $template_id, 'dry_run' => true ) )->get_data();
$data  = $body['data'] ?? array();
$plan  = $data['plan'] ?? array();
$kinds = array_map(
	static function ( array $change ): string {
		return (string) $change['kind'];
	},
	$plan['changes'] ?? array()
);
assert_same(
	array( 'trash', 'trash', 'meta.scrub' ),
	$kinds,
	'the plan trashes the template, trashes its one linked layout, and scrubs the master meta'
);
assert_same( 5410, $data['master_id'] ?? null, 'the plan names the master it will scrub' );
assert_same( 1, $data['master_meta_refs'] ?? null, 'and how many refs it found there' );
assert_same( 'publish', $data['current_status'] ?? null, 'and the template status it started from' );
assert_same( 1, count( $data['linked_layouts'] ?? array() ), 'the plan lists the linked layouts' );
assert_same( 'header', $data['linked_layouts'][0]['role'] ?? null, 'the linked layout is reported by slot role' );
assert_same( 'publish', $GLOBALS['diviops_test_posts'][ $template_id ]->post_status, 'a dry run trashes nothing' );

$body = diviops_tbc_call( 'tb_template_trash', array( 'id' => $template_id ) )->get_data();
assert_true( ! empty( $body['ok'] ), 'the trash returns a success envelope' );
assert_same( 'trash', $GLOBALS['diviops_test_posts'][ $template_id ]->post_status, 'the template is trashed' );
assert_same( 'trash', $GLOBALS['diviops_test_posts'][ $header_id ]->post_status, 'its linked header layout is trashed too' );
assert_same(
	array(),
	get_post_meta( 5410, '_et_template', false ),
	'the master linked-list ref to the trashed template is scrubbed'
);

$body = diviops_tbc_call( 'tb_template_trash', array( 'id' => $template_id ) )->get_data();
assert_true( ! empty( $body['ok'] ), 'a repeat trash still succeeds' );
assert_same( true, $body['data']['already_trashed'] ?? null, 'and reports the no-op rather than re-running the cleanup' );
assert_same( 0, $body['data']['master_meta_refs_removed'] ?? null, 'with nothing left to scrub' );

// ── Teardown ──────────────────────────────────────────────────────────────

foreach ( $GLOBALS['diviops_tbc_fixture_ids'] as $diviops_tbc_id ) {
	unset(
		$GLOBALS['diviops_test_posts'][ $diviops_tbc_id ],
		$GLOBALS['diviops_test_post_meta'][ $diviops_tbc_id ],
		$GLOBALS['diviops_test_post_meta_rows'][ $diviops_tbc_id ],
		$GLOBALS['diviops_test_attachments'][ $diviops_tbc_id ]
	);
}
for ( $diviops_tbc_id = $diviops_tbc_first_minted_id; $diviops_tbc_id < (int) $GLOBALS['diviops_test_next_id']; $diviops_tbc_id++ ) {
	unset(
		$GLOBALS['diviops_test_posts'][ $diviops_tbc_id ],
		$GLOBALS['diviops_test_post_meta'][ $diviops_tbc_id ],
		$GLOBALS['diviops_test_post_meta_rows'][ $diviops_tbc_id ]
	);
}
$GLOBALS['diviops_test_next_id'] = $diviops_tbc_first_minted_id;

// The teardown above is load-bearing for tests/test-tb-template-list-master-scoping.php,
// which runs later and would resolve this file's master instead of its own. Assert it
// actually emptied, so a future edit that adds a fixture and forgets the bookkeeping
// fails here rather than as a confusing failure in another file.
assert_same( 0, diviops_call( 'find_active_master' ), 'teardown left no Theme Builder master behind' );
assert_same( null, $GLOBALS['diviops_test_posts'][5403] ?? null, 'teardown removed this file\'s fixture posts' );
