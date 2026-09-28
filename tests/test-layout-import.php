<?php
// SPDX-License-Identifier: MIT
/**
 * page_layout_import() — import a Divi portability layout JSON onto a page (#490).
 *
 * The handler is fork-authored (`plugins/diviops-agent/includes/trait-layout-import.php`)
 * and, unlike its export sibling, it reaches **no Divi seam at all**. That is a
 * design decision recorded on #490 rather than a convenience: Divi 5's own
 * `PortabilityPost::import()` (`includes/builder-5/server/Framework/Portability/PortabilityPost.php:2133`,
 * read on staging at Divi 5.13.1) has **no dry-run parameter** and writes global
 * colours, presets and `_et_pb_custom_css` as side effects of the same call that
 * parses the payload. Calling it would mean a "dry run" had already merged the
 * payload's global data into the site, outside any rollback snapshot — breaking two
 * of #490's stated non-negotiables at once. So this handler parses the payload
 * itself, and consequently this suite needs no Divi stub.
 *
 * THIS FILE COVERS SLICE 1: everything decidable from the payload alone, before any
 * block parsing or any write. Those are the refusals, and they are the half that
 * runs first on every call, so they are the half a malformed payload meets.
 *
 * WHAT IS NOT COVERED HERE, AND WHY. Reference classification and the write path
 * need `parse_blocks_for_write()`, which falls through to core's `parse_blocks()`
 * on a site without Divi's `BlockParserUtils`. `parse_blocks()` is deliberately
 * absent from `tests/wp-shim.php` and is stubbed per-suite by the three files that
 * need it, so those slices carry their own stub rather than widening the shared
 * shim (CONTRIBUTING.md's shim contract). They are separate assertions, not absent
 * ones — a slice landing without its tests is the thing this repository has no
 * safety net for.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/*
 * A Divi-ACTIVE site, because the canonical global-colour read walks
 * `et_divi.et_global_data.global_colors` first and that path goes through Divi's
 * `et_get_option()`. The base harness models a Divi-INACTIVE site on purpose, so
 * this is the existing opt-in stub rather than a widening of the shared shim, and
 * it must be loaded after wp-shim.php and before the plugin file — hence the
 * explicit plugin require below, which wp-shim.php otherwise does lazily.
 *
 * Reading only the top-level path instead would have avoided this, and would have
 * been wrong: see layout_import_live_global_colors()'s docblock.
 */
require_once __DIR__ . '/divi-active-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

/**
 * One import request. Only `artifact_json` is required by the route; every other
 * parameter is optional and defaulted by the handler, so the fixtures below pass
 * exactly the keys each case is about.
 *
 * @param array $params Request parameters.
 * @return DiviOps_Test_Request
 */
function diviops_li_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

/**
 * Invoke the handler and return its envelope.
 *
 * @param array $params Request parameters.
 * @return mixed
 */
function diviops_li_import( array $params ) {
	return diviops_call( 'page_layout_import', array( diviops_li_request( $params ) ) );
}

/**
 * The error code out of an envelope, or a describing string when there is none.
 *
 * Returning a string rather than null on the success path matters: a missing code
 * asserted against an expected one would otherwise read as `null !== 'x'`, which
 * says nothing about what actually came back.
 *
 * @param mixed $envelope Handler return.
 * @return string
 */
function diviops_li_code( $envelope ): string {
	if ( ! is_object( $envelope ) || ! method_exists( $envelope, 'get_data' ) ) {
		return '<not a response object>';
	}
	$data = (array) $envelope->get_data();
	if ( isset( $data['error']['code'] ) ) {
		return (string) $data['error']['code'];
	}
	if ( isset( $data['ok'] ) && $data['ok'] ) {
		return '<ok:no error>';
	}
	return '<no code in envelope>';
}

/* -------------------------------------------------------------------------
 * Fixtures.
 *
 * A minimal artifact in the shape this fork's own page_export() emits: Divi's
 * serialize_layout() keys plus the `presets` key the export attaches, with `data`
 * keyed by source post id. Only the keys each case needs are populated -- an
 * over-specified fixture hides which key the handler actually read.
 * ---------------------------------------------------------------------- */

const DIVIOPS_LI_D5_CONTENT = '<!-- wp:divi/placeholder {"attrs":{}} --><!-- /wp:divi/placeholder -->';
const DIVIOPS_LI_D4_CONTENT = '[et_pb_section][et_pb_row][/et_pb_row][/et_pb_section]';

/**
 * An artifact array with `data` keyed by a source post id.
 *
 * @param string $content Layout content for that id.
 * @param array  $extra   Extra top-level artifact keys.
 * @return array
 */
function diviops_li_artifact( string $content, array $extra = array() ) {
	return array_merge(
		array(
			'context' => 'et_builder',
			'data'    => array( '4242' => array( 'post_content' => $content ) ),
		),
		$extra
	);
}

/**
 * That artifact as the JSON string the route accepts.
 *
 * @param string $content Layout content.
 * @param array  $extra   Extra artifact keys.
 * @return string
 */
function diviops_li_json( string $content, array $extra = array() ): string {
	return (string) wp_json_encode( diviops_li_artifact( $content, $extra ) );
}

/* -------------------------------------------------------------------------
 * 1. The payload has to be there, and has to be JSON.
 * ---------------------------------------------------------------------- */

assert_same(
	'invalid_input',
	diviops_li_code( diviops_li_import( array() ) ),
	'an absent artifact_json is invalid_input, not a crash on a missing parameter'
);

assert_same(
	'invalid_input',
	diviops_li_code( diviops_li_import( array( 'artifact_json' => '' ) ) ),
	'and an empty artifact_json is refused rather than decoded to null and treated as an empty layout'
);

assert_same(
	'layout_import.invalid_json',
	diviops_li_code( diviops_li_import( array( 'artifact_json' => '{"data":' ) ) ),
	'truncated JSON gets its own code, so a caller can tell a transport truncation from a shape problem'
);

/*
 * `json_decode` returns a scalar for a bare scalar document, and `"[]"` decodes to
 * an array that is not an artifact. Both are valid JSON, so neither may report as
 * invalid_json -- that distinction is the reason these two cases exist separately.
 */
assert_same(
	'layout_import.malformed_artifact',
	diviops_li_code( diviops_li_import( array( 'artifact_json' => '"a string"' ) ) ),
	'valid JSON that is not an object is malformed_artifact, NOT invalid_json'
);

assert_same(
	'layout_import.malformed_artifact',
	diviops_li_code( diviops_li_import( array( 'artifact_json' => '{"context":"et_builder"}' ) ) ),
	'an artifact with no data key is malformed_artifact'
);

assert_same(
	'layout_import.malformed_artifact',
	diviops_li_code( diviops_li_import( array( 'artifact_json' => '{"data":{}}' ) ) ),
	'and an artifact whose data is empty is malformed too -- an import that would write nothing is a caller mistake, not a no-op success'
);

/* -------------------------------------------------------------------------
 * 2. A Divi 4 shortcode payload is refused by name.
 *
 * The only thing Divi's own import() does that this handler cannot is convert D4
 * shortcodes to D5 blocks (ShortcodeMigration::maybe_migrate_legacy_shortcode()
 * then Conversion::maybeConvertContent()). Writing such a payload unconverted
 * would store shortcodes into a D5 page, which renders as literal text rather
 * than failing -- so it is refused with a code that says which capability is
 * missing, instead of succeeding into a broken page.
 * ---------------------------------------------------------------------- */

assert_same(
	'layout_import.shortcode_payload_unsupported',
	diviops_li_code( diviops_li_import( array( 'artifact_json' => diviops_li_json( DIVIOPS_LI_D4_CONTENT ) ) ) ),
	'a D4 shortcode payload is refused by name rather than written unconverted'
);

/* -------------------------------------------------------------------------
 * 3. The artifact checksum, when the caller pins it.
 *
 * page_export() returns `manifest.sha256` over exactly the bytes it returns as
 * `artifact_json`. A caller that carries both can pin them together, which is the
 * only way to catch a payload mangled in transit -- re-encoding it here would
 * hash a different byte sequence and always agree with itself.
 * ---------------------------------------------------------------------- */

$diviops_li_good = diviops_li_json( DIVIOPS_LI_D5_CONTENT );

assert_same(
	'layout_import.artifact_drift',
	diviops_li_code( diviops_li_import( array(
		'artifact_json'            => $diviops_li_good,
		'expected_artifact_sha256' => 'sha256:' . str_repeat( '0', 64 ),
	) ) ),
	'a pinned artifact checksum that does not match the bytes refuses as artifact_drift'
);

assert_same(
	'invalid_input',
	diviops_li_code( diviops_li_import( array(
		'artifact_json'            => $diviops_li_good,
		'expected_artifact_sha256' => 'not-a-checksum',
	) ) ),
	'and a malformed checksum is invalid_input rather than being compared and reported as drift'
);

/* -------------------------------------------------------------------------
 * 4. Overwriting an existing page needs its content checksum.
 *
 * #490's third policy question, answered: create by default, overwrite only on an
 * explicit target AND expected_checksum, reusing #391's contract. The refusal is
 * asserted here because it is decidable before any parsing.
 * ---------------------------------------------------------------------- */

assert_same(
	'invalid_input',
	diviops_li_code( diviops_li_import( array(
		'artifact_json' => $diviops_li_good,
		'target'        => 4242,
	) ) ),
	'naming an existing target without expected_checksum is refused before anything is read'
);

/* -------------------------------------------------------------------------
 * 5. The capability key exists, spelled exactly as the tool name implies.
 *
 * A route whose capability key is missing is advertised to no client and the tool
 * silently vanishes from the MCP surface rather than erroring --
 * tests/test-capability-key-has-tool.php gates the opposite direction (#38).
 * ---------------------------------------------------------------------- */

assert_true(
	in_array( 'page_layout_import', DiviOps_Agent::CAPABILITIES, true ),
	'page_layout_import is declared in DiviOps_Agent::CAPABILITIES'
);


/* -------------------------------------------------------------------------
 * SLICE 2: reference classification.
 *
 * The artifact already DECLARES what it needs -- page_export() walks the block
 * attrs and emits the referenced subset as `presets`, and Divi's serializer emits
 * `global_colors`. So classification compares those declarations against the live
 * site and never re-parses the content, which is also why this slice needs no
 * `parse_blocks` stub.
 *
 * Three outcomes per reference, and the middle one is the whole point:
 *   resolved  -- present in the target with the same value
 *   collision -- present with a DIFFERENT value
 *   missing   -- absent
 *
 * A collision refuses by default. The page would render with the target's value
 * rather than the exported one: nothing errors, nothing is logged, and the
 * difference shows up visually later. `missing` does not refuse -- Divi falls back
 * to defaults, and refusing would make a same-site re-import fail merely because a
 * preset was deleted after the export.
 * ---------------------------------------------------------------------- */

const DIVIOPS_LI_UUID = 'p-aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

/**
 * Seed the live preset registry and global-colour palette.
 *
 * Both are written to the CANONICAL top-level options, which
 * `probe_storage_paths()` short-circuits on, so the nested scratchpad paths are
 * never read. Those nested paths reach Divi's unshimmed `et_get_option()`; seeding
 * the canonical option is what keeps this suite off it.
 *
 * @param array $presets Live `module` bucket items, keyed by module name.
 * @param array $colors  Live global colours, keyed by colour id.
 */
function diviops_li_seed( array $presets, array $colors ) {
	$GLOBALS['diviops_test_options']['et_divi_builder_global_presets_d5'] = array( 'module' => $presets );
	$GLOBALS['diviops_test_options']['et_global_data'] = array( 'global_colors' => $colors );
}

/**
 * A `presets` artifact key in the D5 registry shape page_export() emits.
 *
 * @param array $entry The preset entry body.
 * @return array
 */
function diviops_li_presets( array $entry ) {
	return array( 'module' => array( 'divi/text' => array( 'items' => array( DIVIOPS_LI_UUID => $entry ) ) ) );
}

/**
 * The plan payload from a dry run, or an empty array when the call refused.
 *
 * @param mixed $envelope Handler return.
 * @return array
 */
function diviops_li_plan( $envelope ): array {
	if ( ! is_object( $envelope ) || ! method_exists( $envelope, 'get_data' ) ) {
		return array();
	}
	$data = (array) $envelope->get_data();
	return isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
}

/**
 * The disposition recorded for one reference id in a plan.
 *
 * @param array  $plan  Plan payload.
 * @param string $group `presets` or `global_colors`.
 * @param string $id    Reference id.
 * @return string
 */
function diviops_li_disposition( array $plan, string $group, string $id ): string {
	foreach ( $plan['references'][ $group ] ?? array() as $row ) {
		if ( isset( $row['id'] ) && $id === $row['id'] ) {
			return (string) ( $row['disposition'] ?? '<no disposition>' );
		}
	}
	return '<id absent from plan>';
}

// --- resolved: the target already has both, with identical values -------------

diviops_li_seed(
	array( 'divi/text' => array( 'items' => array( DIVIOPS_LI_UUID => array( 'name' => 'Body', 'attrs' => array() ) ) ) ),
	array( 'gcid-primary' => array( 'color' => '#123456' ) )
);

$diviops_li_same = diviops_li_import( array(
	'artifact_json' => diviops_li_json( DIVIOPS_LI_D5_CONTENT, array(
		'presets'       => diviops_li_presets( array( 'name' => 'Body', 'attrs' => array() ) ),
		'global_colors' => array( 'gcid-primary' => array( 'color' => '#123456' ) ),
	) ),
) );
$diviops_li_plan_same = diviops_li_plan( $diviops_li_same );

assert_same(
	'<ok:no error>',
	diviops_li_code( $diviops_li_same ),
	'an import whose every reference already matches the target is not refused'
);
assert_same(
	'resolved',
	diviops_li_disposition( $diviops_li_plan_same, 'presets', DIVIOPS_LI_UUID ),
	'a preset present with the same value is resolved'
);
assert_same(
	'resolved',
	diviops_li_disposition( $diviops_li_plan_same, 'global_colors', 'gcid-primary' ),
	'and a colour present with the same value is resolved'
);

// --- collision: same id, different value -> refuses --------------------------

$diviops_li_collide_args = array(
	'artifact_json' => diviops_li_json( DIVIOPS_LI_D5_CONTENT, array(
		'global_colors' => array( 'gcid-primary' => array( 'color' => '#ff0000' ) ),
	) ),
);

assert_same(
	'layout_import.reference_collision',
	diviops_li_code( diviops_li_import( $diviops_li_collide_args ) ),
	'a colour id present in the target with a DIFFERENT value refuses the whole import'
);

// The opt-in, and it must not also suppress the reporting -- a caller who waives
// the refusal still needs to know which references it waived.
$diviops_li_waived = diviops_li_import( $diviops_li_collide_args + array( 'allow_reference_collisions' => true ) );
assert_same(
	'<ok:no error>',
	diviops_li_code( $diviops_li_waived ),
	'allow_reference_collisions proceeds instead of refusing'
);
assert_same(
	'collision',
	diviops_li_disposition( diviops_li_plan( $diviops_li_waived ), 'global_colors', 'gcid-primary' ),
	'and the waived reference is still reported as a collision rather than silently reclassified'
);

// --- missing: absent from the target -> reported, NOT refused ----------------

$diviops_li_missing = diviops_li_import( array(
	'artifact_json' => diviops_li_json( DIVIOPS_LI_D5_CONTENT, array(
		'global_colors' => array( 'gcid-absent' => array( 'color' => '#abcdef' ) ),
	) ),
) );

assert_same(
	'<ok:no error>',
	diviops_li_code( $diviops_li_missing ),
	'a reference absent from the target does NOT refuse -- Divi falls back to defaults, and refusing would break same-site re-import after a preset was deleted'
);
assert_same(
	'missing',
	diviops_li_disposition( diviops_li_plan( $diviops_li_missing ), 'global_colors', 'gcid-absent' ),
	'and it is reported as missing so the caller can create it before or after'
);

// --- the summary counts, which are what a caller branches on -----------------

// Two, not one: that fixture declares a preset AND a colour, and both matched.
// The count is derived from the fixture rather than read off a run -- an expected
// value copied from the harness would have agreed with a summary that counted only
// one of the two groups.
assert_same(
	array( 'resolved' => 2, 'collision' => 0, 'missing' => 0 ),
	$diviops_li_plan_same['reference_summary'] ?? array(),
	'the plan carries a summary keyed by disposition, counting every reference class together'
);

/* -------------------------------------------------------------------------
 * SLICE 3: the write.
 *
 * dry_run DEFAULTS TO TRUE, inverting this plugin's usual convention, for the same
 * reason the bulk family inverts it: forgetting the flag on a single-page handler
 * costs one page, and forgetting it here writes a whole layout over one.
 *
 * Overwriting is guarded by expected_checksum (#391's contract, made load-bearing
 * by #516) and every write goes through update_post_content_with_integrity_guard()
 * with the global-layout drift check on, under a rollback snapshot -- the #11 hazard
 * applies to any content write, and an import is the largest one this plugin makes.
 * ---------------------------------------------------------------------- */

diviops_li_seed( array(), array() );

/**
 * How many posts the harness currently holds — the direct way to prove a dry run
 * created nothing, rather than trusting the response's own `dry_run` flag to be
 * telling the truth about what it did.
 *
 * @return int
 */
function diviops_li_post_count(): int {
	return count( (array) ( $GLOBALS['diviops_test_posts'] ?? array() ) );
}

// --- dry run is the default, and it writes nothing ---------------------------

$diviops_li_before = diviops_li_post_count();
$diviops_li_dry    = diviops_li_import( array( 'artifact_json' => diviops_li_json( DIVIOPS_LI_D5_CONTENT ) ) );

assert_same(
	true,
	diviops_li_plan( $diviops_li_dry )['dry_run'] ?? null,
	'an import with no dry_run parameter is a dry run — the default is TRUE, not false'
);
assert_same(
	$diviops_li_before,
	diviops_li_post_count(),
	'and it created no post, which is asserted against the harness rather than read off the response flag'
);

// --- apply onto a new page ---------------------------------------------------

$diviops_li_new = diviops_li_import( array(
	'artifact_json' => diviops_li_json( DIVIOPS_LI_D5_CONTENT ),
	'dry_run'       => false,
	'title'         => 'Imported layout',
) );
$diviops_li_new_plan = diviops_li_plan( $diviops_li_new );

assert_same(
	'<ok:no error>',
	diviops_li_code( $diviops_li_new ),
	'applying onto a new page succeeds'
);
assert_same(
	false,
	$diviops_li_new_plan['dry_run'] ?? null,
	'and the response says it was not a dry run'
);
assert_true(
	isset( $diviops_li_new_plan['page_id'] ) && $diviops_li_new_plan['page_id'] > 0,
	'the new page id is returned, since the caller cannot know it any other way'
);
assert_same(
	DIVIOPS_LI_D5_CONTENT,
	(string) get_post( (int) $diviops_li_new_plan['page_id'] )->post_content,
	'the layout content is on the new page byte-for-byte, read back from the post rather than from the response'
);
assert_same(
	'Imported layout',
	(string) get_post( (int) $diviops_li_new_plan['page_id'] )->post_title,
	'and the supplied title was used'
);

// --- apply over an existing page, checksum-guarded ---------------------------

const DIVIOPS_LI_TARGET = 7801;
const DIVIOPS_LI_OLD    = '<!-- wp:divi/placeholder {"attrs":{"old":true}} --><!-- /wp:divi/placeholder -->';

diviops_test_register_post( DIVIOPS_LI_TARGET, DIVIOPS_LI_OLD, 'page', 'Existing' );

assert_same(
	'layout_import.content_drift',
	diviops_li_code( diviops_li_import( array(
		'artifact_json'     => diviops_li_json( DIVIOPS_LI_D5_CONTENT ),
		'dry_run'           => false,
		'target'            => DIVIOPS_LI_TARGET,
		'expected_checksum' => 'sha256:' . str_repeat( 'b', 64 ),
	) ) ),
	'a stale expected_checksum refuses the overwrite'
);
assert_same(
	DIVIOPS_LI_OLD,
	(string) get_post( DIVIOPS_LI_TARGET )->post_content,
	'and the target is untouched after that refusal — a refusal that had already written would be the worst outcome here'
);

$diviops_li_over = diviops_li_import( array(
	'artifact_json'     => diviops_li_json( DIVIOPS_LI_D5_CONTENT ),
	'dry_run'           => false,
	'target'            => DIVIOPS_LI_TARGET,
	'expected_checksum' => 'sha256:' . hash( 'sha256', DIVIOPS_LI_OLD ),
) );

assert_same(
	'<ok:no error>',
	diviops_li_code( $diviops_li_over ),
	'the matching checksum allows the overwrite'
);
assert_same(
	DIVIOPS_LI_D5_CONTENT,
	(string) get_post( DIVIOPS_LI_TARGET )->post_content,
	'and the imported layout replaced the old content'
);
assert_same(
	DIVIOPS_LI_TARGET,
	(int) ( diviops_li_plan( $diviops_li_over )['page_id'] ?? 0 ),
	'the response names the page it wrote, which is the named target and not a new page'
);

// --- an unknown target is not_found, not a silent create ---------------------

assert_same(
	'not_found',
	diviops_li_code( diviops_li_import( array(
		'artifact_json'     => diviops_li_json( DIVIOPS_LI_D5_CONTENT ),
		'dry_run'           => false,
		'target'            => 999777,
		'expected_checksum' => 'sha256:' . str_repeat( 'c', 64 ),
	) ) ),
	'a named target that does not exist is not_found rather than being created under that id'
);

// --- content carrying no Divi block at all ----------------------------------
//
// Not merely odd: it would write plain text over a layout and report success. The
// D4 refusal above cannot catch it, because that one only fires on `[et_pb_`.

assert_same(
	'layout_import.not_divi_content',
	diviops_li_code( diviops_li_import( array(
		'artifact_json' => diviops_li_json( 'Just some prose with no blocks at all.' ),
	) ) ),
	'a payload with no Divi block opener is refused rather than written as a layout'
);

printf(
	"layout import: %d assertion group(s) — payload refusals, reference classification, guarded write; no Divi seam reached\n",
	3
);
