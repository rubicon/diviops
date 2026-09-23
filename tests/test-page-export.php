<?php
// SPDX-License-Identifier: MIT
/**
 * page_export() — raw Divi portability payload plus manifest (#382).
 *
 * The handler is fork-authored (`plugins/diviops-agent/includes/trait-portability.php`)
 * and wraps ONE Divi seam: `ET_Core_Portability::serialize_layout()`. Divi is
 * modelled by `tests/portability-shim.php`, a dedicated stub file per
 * CONTRIBUTING.md's shim contract, whose every method is transcribed from the
 * real `core/components/Portability.php` at Divi 5.13.1 with the line cited.
 *
 * WHAT IS COVERED. The eight keys Divi's serializer emits plus the `presets`
 * key this handler attaches; that the artifact survives into `data.artifact`
 * byte-for-byte; the sha256 contract; the image-pagination filter being
 * installed for the duration of the call and removed afterwards; the
 * `ready`/`chunks` backstop; a silently dropped image showing up as
 * `manifest.images.skipped`; third-party namespace detection and its empty
 * case; and the four refusals (absent portability system, unknown post,
 * non-exportable post type, uninspectable row).
 *
 * WHAT IS NOT COVERED, AND WHY.
 *
 *   - The real `serialize_layout()`. CI has no Divi, and the point of a stub
 *     transcribed from the source is that it is checkable against that source
 *     by a reader; it is not a substitute for a live run. The live half of
 *     #382 is an end-to-end export against staging, which needs a write to
 *     nothing but still needs a Divi install.
 *   - The `et_divi.builder_global_presets_d5` legacy preset path.
 *     `read_storage_path()` reaches Divi's `et_get_option()` there, which is
 *     unshimmed on purpose (test-variable-ref-scan-post-types.php matches on
 *     its absence). Every preset case below seeds the CANONICAL top-level
 *     option, which `probe_storage_paths()` short-circuits on, so the nested
 *     path is never read. A fixture with preset references and an EMPTY
 *     canonical option would fall through to `et_get_option()` and fatal —
 *     that is a harness limit, stated rather than papered over.
 *   - `portability_referenced_image_count()`'s null branch. It returns null
 *     when `ET_Core_Portability::get_data_images()` cannot be reached, and a
 *     class cannot be redefined mid-process, so exercising it would need a
 *     child process. The FIRST assertion block below runs before the
 *     portability stub is loaded and does cover the whole-system-absent case,
 *     which is the branch that actually ships a refusal.
 *
 * ORDERING IS LOAD-BEARING. `tests/portability-shim.php` is required partway
 * down this file, not at the top, because `class_exists( 'ET_Core_Portability' )`
 * cannot be made false again once it is true. Everything above that require
 * runs against a site with no Divi portability system.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

function diviops_pe_request( int $id ) {
	return new DiviOps_Test_Request( array( 'id' => $id ) );
}

function diviops_pe_export( int $id ) {
	return diviops_call( 'page_export', array( diviops_pe_request( $id ) ) );
}

/* -------------------------------------------------------------------------
 * Fixtures.
 *
 * The markup is block-comment shaped because the handler's own scan walks
 * openers with next_block_opener()/block_opening_comment_end() rather than
 * parse_blocks(), which is unshimmed on purpose. `difl/faq` is the real
 * third-party module name page 900390 carries on the reference install, and
 * `d5bgo/bg-overlay` the second namespace on that same page.
 *
 * The preset uuid is referenced via `modulePreset`, the attr name
 * walk_blocks_for_preset_refs() (trait-preset.php) reads.
 * ---------------------------------------------------------------------- */

const DIVIOPS_PE_PRESET_UUID = 'p-11111111-2222-3333-4444-555555555555';

$diviops_pe_content = '<!-- wp:divi/section {"modulePreset":"' . DIVIOPS_PE_PRESET_UUID . '"} -->'
	. '<!-- wp:divi/text {"module":{"advanced":{"text":{"desktop":{"value":"hello"}}}}} /-->'
	. '<!-- wp:difl/faq {"src":"https://example.test/attachment-77/hero.png"} /-->'
	. '<!-- wp:d5bgo/bg-overlay {"foo":"bar"} /-->'
	. '<!-- /wp:divi/section -->';

// A page carrying only first-party blocks, for the empty-namespaces case.
$diviops_pe_plain = '<!-- wp:divi/section -->'
	. '<!-- wp:core/paragraph {"x":1} /-->'
	. '<!-- /wp:divi/section -->';

diviops_test_register_post( 91001, $diviops_pe_content, 'page', 'Export Fixture' );
diviops_test_register_post( 91002, $diviops_pe_plain, 'page', 'Plain Fixture' );
diviops_test_register_post( 91003, $diviops_pe_content, 'attachment', 'Not Exportable' );
diviops_test_register_post( 91004, $diviops_pe_content, 'page', 'Uneditable' );

/* -------------------------------------------------------------------------
 * Divi's portability system absent.
 *
 * MUST RUN FIRST — see the header. Nothing below this block can restore this
 * runtime shape, because tests/portability-shim.php defines the class.
 * ---------------------------------------------------------------------- */

assert_true(
	! class_exists( 'ET_Core_Portability' ),
	'the absent-portability case really does run before the stub defines the class'
);

$resp = diviops_pe_export( 91001 );
$data = $resp->get_data();
assert_same( false, $data['ok'], 'a site without Divi portability refuses rather than exporting' );
assert_same( 'portability.unavailable', $data['error']['code'], 'the refusal is named, not a generic error' );
assert_same( 503, $resp->get_status(), 'an absent dependency is 503, not a client error' );
assert_same(
	array( 'ET_Core_Portability', 'et_core_portability_load()', 'et_core_portability_register()' ),
	$data['error']['data']['missing'],
	'the refusal names every missing symbol, so an operator knows which half is absent'
);

/* -------------------------------------------------------------------------
 * Refusals that do not depend on Divi being present.
 * ---------------------------------------------------------------------- */

$resp = diviops_pe_export( 98765 );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'an unknown page id is not_found' );
assert_same( 404, $resp->get_status(), 'not_found is HTTP 404' );

// Ordering matters: the row-level gate is checked before the post-type gate and
// before the dependency probe, so an uninspectable row is never told which
// plugins the site runs.
$GLOBALS['diviops_test_uneditable_ids'] = array( 91004 );
$resp = diviops_pe_export( 91004 );
$data = $resp->get_data();
assert_same( 'forbidden', $data['error']['code'], 'a row the caller cannot edit is forbidden' );
assert_same( 403, $resp->get_status(), 'forbidden is HTTP 403' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

$resp = diviops_pe_export( 91003 );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a post type carrying no Divi layout is invalid_input' );
assert_same( 'attachment', $data['error']['data']['post_type'], 'the refusal names the post type it rejected' );

/* -------------------------------------------------------------------------
 * From here on, Divi's portability system exists.
 * ---------------------------------------------------------------------- */

require_once __DIR__ . '/portability-shim.php';

// The canonical top-level preset registry. Seeded in the D5 bucketed shape
// get_d5_presets() reads and collect_d5_preset_audit_entries() flattens:
//   { module: { <moduleName>: { items: { <uuid>: <preset> } } }, group: {...} }
// One referenced uuid and one unreferenced one, so a handler that returned the
// whole registry instead of the used subset is distinguishable from one that
// filtered correctly.
$GLOBALS['diviops_test_options']['et_divi_builder_global_presets_d5'] = array(
	'module' => array(
		'divi/section' => array(
			'items' => array(
				DIVIOPS_PE_PRESET_UUID => array( 'name' => 'Wide Section', 'attrs' => array( 'a' => 1 ) ),
				'p-unreferenced-0000'  => array( 'name' => 'Never Used', 'attrs' => array( 'b' => 2 ) ),
			),
		),
	),
);

/* -------------------------------------------------------------------------
 * Happy path.
 * ---------------------------------------------------------------------- */

diviops_portability_stub_reset( array(
	'referenced'    => array( 'https://example.test/attachment-77/hero.png' ),
	'global_colors' => array( 'gcid-primary' => array( 'color' => '#123456' ) ),
) );

$resp = diviops_pe_export( 91001 );
$data = $resp->get_data();

assert_same( true, $data['ok'], 'the happy path succeeds' );
assert_same( 200, $resp->get_status(), 'a successful export is HTTP 200' );

// The artifact arrives as a STRING — the exact bytes the handler hashed — so the
// consumer can prove what it writes to disk is what was checksummed. Decoded here
// for the structural assertions; the byte-fidelity invariant itself is asserted
// further down, and it is the assertion that makes the rest of this file about
// something real rather than about a shape nobody receives.
$artifact_json = $data['data']['artifact_json'];
$artifact      = json_decode( $artifact_json, true );
$manifest      = $data['data']['manifest'];

assert_true( is_string( $artifact_json ), 'the artifact is returned as a JSON string, not as an object' );
assert_true( is_array( $artifact ), 'and those bytes decode' );
assert_same(
	'sha256:' . hash( 'sha256', $artifact_json ),
	$manifest['sha256'],
	'the manifest checksum is over the EXACT bytes returned -- the whole reason the artifact ships as a string, since a consumer that re-encoded an object would hash a different byte sequence and refuse every export'
);
assert_same(
	strlen( $artifact_json ),
	$manifest['byte_length'],
	'and the byte length describes those same bytes'
);

// The eight keys serialize_layout() emits (Portability.php:1350-1359), plus the
// presets key this handler attaches because that serializer emits none.
assert_same(
	array( 'context', 'data', 'images', 'post_title', 'post_type', 'theme_builder', 'global_colors', 'canvases', 'presets' ),
	array_keys( $artifact ),
	'the artifact carries Divi\'s eight keys in Divi\'s own order, with presets appended'
);

// RAW: the shim's exact returned structure, unmodified. Built here from what
// the stub is known to produce rather than read off the response, so a handler
// that re-keyed or "tidied" the payload fails this.
$expected_images = array(
	'https://example.test/attachment-77/hero.png' => array(
		'encoded' => base64_encode( 'bytes:https://example.test/attachment-77/hero.png' ),
		'url'     => 'https://example.test/attachment-77/hero.png',
		'id'      => 77,
	),
);
assert_same( 'et_builder', $artifact['context'], 'context passes through untouched' );
assert_same( array( 91001 => $diviops_pe_content ), $artifact['data'], 'data passes through as [ post_id => content ]' );
assert_same( $expected_images, $artifact['images'], 'the encoded image map passes through unmodified' );
assert_same( 'Export Fixture', $artifact['post_title'], 'post_title passes through' );
assert_same( 'page', $artifact['post_type'], 'post_type passes through' );
assert_same( array(), $artifact['theme_builder'], 'theme_builder passes through' );
assert_same(
	array( 'gcid-primary' => array( 'color' => '#123456' ) ),
	$artifact['global_colors'],
	'global_colors passes through unmodified'
);
assert_same( array(), $artifact['canvases'], 'canvases passes through' );

// Presets: the USED subset of the registry, in the registry's own shape.
assert_same(
	array(
		'module' => array(
			'divi/section' => array(
				'items' => array(
					DIVIOPS_PE_PRESET_UUID => array( 'name' => 'Wide Section', 'attrs' => array( 'a' => 1 ) ),
				),
			),
		),
	),
	$artifact['presets'],
	'presets carries only the preset the page references, in the D5 registry shape'
);

/* -------------------------------------------------------------------------
 * The manifest.
 * ---------------------------------------------------------------------- */

assert_same( 91001, $manifest['page_id'], 'the manifest names the page id' );
assert_same( 'Export Fixture', $manifest['page_title'], 'the manifest carries the page title' );
assert_same( 'page', $manifest['post_type'], 'the manifest carries the post type' );

// Computed here from the structure the stub is known to return, not read back
// off the response. wp_json_encode() with no flags argument is json_encode()
// at flags 0 / depth 512 — core's own defaults — which is the encoding the
// handler documents as the contract.
$expected_artifact = array(
	'context'       => 'et_builder',
	'data'          => array( 91001 => $diviops_pe_content ),
	'images'        => $expected_images,
	'post_title'    => 'Export Fixture',
	'post_type'     => 'page',
	'theme_builder' => array(),
	'global_colors' => array( 'gcid-primary' => array( 'color' => '#123456' ) ),
	'canvases'      => array(),
	'presets'       => array(
		'module' => array(
			'divi/section' => array(
				'items' => array(
					DIVIOPS_PE_PRESET_UUID => array( 'name' => 'Wide Section', 'attrs' => array( 'a' => 1 ) ),
				),
			),
		),
	),
);
$expected_json = json_encode( $expected_artifact );
assert_same( $expected_artifact, $artifact, 'the artifact is byte-for-byte what the serializer returned plus presets' );
assert_same( strlen( $expected_json ), $manifest['byte_length'], 'byte_length measures the encoded artifact' );
assert_same(
	'sha256:' . hash( 'sha256', $expected_json ),
	$manifest['sha256'],
	'sha256 is the hash of wp_json_encode( artifact ), independently computed'
);

assert_same( array( 'gcid-primary' ), $manifest['global_colors'], 'the manifest lists the global colour ids present' );
assert_same( 1, $manifest['presets'], 'the manifest counts the presets carried' );
assert_same( array( 77 ), $manifest['attachment_ids'], 'the manifest lists the attachment ids in the payload' );
assert_same(
	array( 'd5bgo', 'difl' ),
	$manifest['third_party_namespaces'],
	'third-party namespaces are detected and sorted; divi and core are not third-party'
);
assert_same(
	array( 'global_variables', 'page_settings_meta', 'thumbnails' ),
	$manifest['artifact_omits'],
	'the manifest names the D5 export keys this seam cannot produce'
);

// No image was dropped on the happy path.
assert_same(
	array( 'referenced' => 1, 'encoded' => 1, 'skipped' => 0 ),
	$manifest['images'],
	'an export that dropped nothing reports zero skipped'
);

/* -------------------------------------------------------------------------
 * The pagination filter is installed DURING the call and removed after.
 *
 * Asserted on the filter registry, not on a comment: the stub records
 * has_filter() at the moment serialize_layout() runs (portability-shim.php),
 * and the check after the call reads the live registry.
 * ---------------------------------------------------------------------- */

assert_same(
	true,
	$GLOBALS['diviops_portability_stub']['paginate_filter_at_call'],
	'et_core_portability_paginate_images is forced false for the duration of the call'
);
assert_same(
	false,
	has_filter( 'et_core_portability_paginate_images', '__return_false' ),
	'and the filter is removed again once the call returns, so no site-wide state leaks'
);
assert_same( 1, $GLOBALS['diviops_portability_stub']['calls'], 'serialize_layout() was called exactly once' );

// The registration is our own context with an empty include/exclude, never
// Divi's `et_builder` — registering under that id would clobber the args
// Divi's own admin cached.
$registered = $GLOBALS['diviops_portability_stub']['registered'];
assert_same( 'diviops_page_export', $registered['context'], 'the route registers its own portability context' );
assert_same( 'post', $registered['type'], 'registered as a single-post export' );
assert_same( false, $registered['view'], 'registered with view false, so Divi does not load its admin assets' );
assert_same( array(), $registered['include'], 'an empty include makes apply_query() a passthrough' );
assert_same( array(), $registered['exclude'], 'an empty exclude makes apply_query() a passthrough' );

/* -------------------------------------------------------------------------
 * A silently dropped image is visible.
 *
 * encode_images() (Portability.php:3748-3751) `continue`s past any url every
 * fetch method failed on — the 2-second _encode_remote_image() timeout, or an
 * attachment file that is gone. The payload then simply lacks it.
 * ---------------------------------------------------------------------- */

diviops_portability_stub_reset( array(
	'referenced'  => array(
		'https://example.test/attachment-77/hero.png',
		'https://slow.example.test/attachment-88/timeout.png',
	),
	'undecodable' => array( 'https://slow.example.test/attachment-88/timeout.png' ),
) );

$resp     = diviops_pe_export( 91001 );
$data     = $resp->get_data();
$manifest = $data['data']['manifest'];

assert_same( true, $data['ok'], 'a dropped image does not fail the export' );
assert_same(
	array( 'referenced' => 2, 'encoded' => 1, 'skipped' => 1 ),
	$manifest['images'],
	'an image Divi dropped is counted as skipped rather than vanishing silently'
);
assert_same(
	1,
	count( json_decode( $data['data']['artifact_json'], true )['images'] ),
	'and the artifact genuinely carries one fewer image, which is what makes the count meaningful'
);
assert_same( array( 77 ), $manifest['attachment_ids'], 'the dropped image contributes no attachment id' );

/* -------------------------------------------------------------------------
 * A page with no third-party modules reports an empty list.
 * ---------------------------------------------------------------------- */

diviops_portability_stub_reset();

$resp     = diviops_pe_export( 91002 );
$data     = $resp->get_data();
$manifest = $data['data']['manifest'];

assert_same( true, $data['ok'], 'a first-party-only page exports' );
assert_same( array(), $manifest['third_party_namespaces'], 'a page with no third-party module reports an empty namespace list' );
assert_same( array(), json_decode( $data['data']['artifact_json'], true )['presets'], 'a page referencing no preset carries an empty presets key' );
assert_same( 0, $manifest['presets'], 'and the manifest counts zero presets' );
assert_same(
	array( 'referenced' => 0, 'encoded' => 0, 'skipped' => 0 ),
	$manifest['images'],
	'a page with no images reports zeroes rather than nulls'
);

/* -------------------------------------------------------------------------
 * More than five images — the case where the filter actually changes the answer.
 *
 * Without it, this whole suite could run on one image and never notice a
 * handler that stopped installing the filter, because chunk_images()
 * (Portability.php:3470) only paginates above `$images_per_chunk = 5`. Six
 * images is the smallest fixture that can observe that branch: with pagination
 * off it is one chunk of six, with pagination on it is two chunks of five and
 * one, which the handler refuses.
 * ---------------------------------------------------------------------- */

// Seven urls, deliberately adversarial for the attachment-id reporting as well:
// they arrive in NO id order, two different urls resolve to the SAME attachment
// (a resized variant, which is how WordPress actually stores them), one carries
// id 0, and one is a remote image with no attachment behind it at all. A fixture
// of tidy ascending distinct ids cannot observe an id list that forgot to sort,
// dedupe, or drop a zero.
diviops_portability_stub_reset( array(
	'referenced' => array(
		'https://example.test/a/attachment-6/six.png',
		'https://example.test/a/attachment-2/two.png',
		'https://example.test/a/attachment-6/six-1024x768.png',
		'https://example.test/a/attachment-1/one.png',
		'https://example.test/a/attachment-4/four.png',
		'https://example.test/a/attachment-0/zero.png',
		'https://cdn.example.test/no-attachment/remote.png',
	),
) );

$resp     = diviops_pe_export( 91001 );
$data     = $resp->get_data();

assert_same( true, $data['ok'], 'seven images export in one pass, because pagination is off for the call' );
assert_same(
	array( 'referenced' => 7, 'encoded' => 7, 'skipped' => 0 ),
	$data['data']['manifest']['images'],
	'all seven images are encoded in the single chunk, none deferred to a later request'
);
assert_same(
	array( 1, 2, 4, 6 ),
	$data['data']['manifest']['attachment_ids'],
	'attachment ids are sorted, deduplicated, and exclude id 0 and the url with no attachment'
);

/* -------------------------------------------------------------------------
 * The partial-export backstop.
 *
 * Unreachable with the pagination filter off, which is why it is a backstop
 * and not a branch the happy path exercises. Handing back one chunk of a
 * multi-chunk export as though it were whole is the failure it prevents.
 * ---------------------------------------------------------------------- */

diviops_portability_stub_reset( array( 'force_ready' => false ) );
$resp = diviops_pe_export( 91001 );
$data = $resp->get_data();
assert_same( 'portability.partial_export', $data['error']['code'], 'ready !== true is refused, not returned' );
assert_same( 500, $resp->get_status(), 'a partial export is a server-side 500' );
assert_same( false, $data['error']['data']['ready'], 'the refusal reports the ready value it saw' );

diviops_portability_stub_reset( array( 'force_chunks' => 3 ) );
$resp = diviops_pe_export( 91001 );
$data = $resp->get_data();
assert_same( 'portability.partial_export', $data['error']['code'], 'chunks !== 1 is refused, not returned' );
assert_same( 3, $data['error']['data']['chunks'], 'the refusal reports the chunk count it saw' );

// A refusal must still not leave the filter behind.
assert_same(
	false,
	has_filter( 'et_core_portability_paginate_images', '__return_false' ),
	'the filter is removed even on the path that refuses the result'
);

diviops_portability_stub_reset();
