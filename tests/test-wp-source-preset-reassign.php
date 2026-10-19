<?php
// SPDX-License-Identifier: MIT
/**
 * Drive preset_reassign end to end against WordPress core's own block parser,
 * and assert on what was STORED (#604).
 *
 * preset_reassign is the plugin's only site-wide write, and until #598 gave the
 * harness core's parser it could not be driven past its own parse call.
 * `test-preset-reassign-write-safety.php` says so in its header and covers the
 * handler structurally instead. Its single real invocation asserts the
 * `preset.apply_requires_page_ids` refusal, which fires before the registry is
 * read, let alone before the parse — so counting suites that MENTION
 * preset_reassign (15 files) badly overstates what was exercised, and counting
 * real invocations (`diviops_call[a-z_]*( *'preset_reassign'`, 1 file) is the
 * measurement that matches the category.
 *
 * ## This needs no Divi option shim, and that is worth stating
 *
 * `test-preset-reassign-write-safety.php`'s header attributes the wall to
 * `et_get_option()`, "a Divi option-storage primitive this harness does not
 * [shim]". That is true of the SECOND registry path and irrelevant to the
 * first: `d5_preset_paths()` (`trait-core.php:1647`) probes
 * `et_divi_builder_global_presets_d5` before `et_divi.builder_global_presets_d5`,
 * `read_storage_path()` reads a dotless path with plain `get_option()`, and
 * `probe_storage_paths()` stops at the first non-empty hit. Seeding the
 * canonical option therefore never reaches `et_get_option()` at all, so this
 * file requires only the #598 stubs. `divi-active-shim.php` is deliberately NOT
 * required: pulling it in would be modelling a Divi-active site for no reason
 * the handler under test can observe.
 *
 * ## The round-trip gate is the interesting half
 *
 * preset_reassign refuses any page where `serialize_blocks( parse_blocks( $c ) )`
 * is not byte-identical to `$c` (#188), because on such a page nothing
 * downstream can separate the preset swap from the bytes the round trip itself
 * dropped. That gate is pure parser behaviour, so it was unreachable here
 * before #598 and is covered in both directions below — a page that survives it
 * and a page that does not. The lossy fixture is an empty paired-tag block,
 * which core's serializer re-emits in self-closing form; that is core's own
 * normalization, not a contrivance.
 *
 * @package DiviOps
 */

// Order matters, and is the inverse of the other opt-in stubs': the registry
// stub must define its class BEFORE wp-shim.php guards its own with
// class_exists(), and wp-block-parser-shim.php requires wp-shim.php at its top.
require_once __DIR__ . '/wp-block-registry-rest-shim.php';
require_once __DIR__ . '/wp-block-parser-shim.php';

/**
 * The canonical D5 preset registry option, as `d5_preset_paths()` names it.
 */
const DIVIOPS_WSPR_OPTION = 'et_divi_builder_global_presets_d5';

/**
 * Seed the canonical registry with the two module presets these tests swap
 * between. Shape mirrors `diviops_pci_seed_registry()` in
 * test-preset-cache-invalidation.php, which was itself read off Divi's storage.
 *
 * @return void
 */
function diviops_wspr_seed_registry(): void {
	update_option(
		DIVIOPS_WSPR_OPTION,
		array(
			'module' => array(
				'divi/heading' => array(
					'default' => 'old-uuid-1',
					'items'   => array(
						'old-uuid-1' => array( 'name' => 'Old Heading', 'attrs' => array( 'a' => 1 ) ),
						'new-uuid-2' => array( 'name' => 'New Heading', 'attrs' => array( 'b' => 2 ) ),
					),
				),
			),
			'group'  => array(),
		),
		false
	);
}

/**
 * A post's stored content, straight from the registry the handler wrote to.
 *
 * @param int $post_id Post id.
 * @return string
 */
function diviops_wspr_stored( int $post_id ): string {
	$post = get_post( $post_id );
	return (string) ( $post->post_content ?? '' );
}

/**
 * Invoke preset_reassign and return its envelope body.
 *
 * @param array $params Request parameters.
 * @return array
 */
function diviops_wspr_call( array $params ): array {
	return diviops_call( 'preset_reassign', array( new DiviOps_Test_Request( $params ) ) )->get_data();
}

/*
 * How many assertions the guarded block contains, declared as a literal and
 * checked against the block's real contents in BOTH branches — the idiom
 * test-wp-source-block-parser.php established, for the reason given there: a
 * guard that silently does nothing makes a skipped run and a real run print the
 * same PASS line. The sentinel halves are concatenated so the needle written
 * here cannot find itself.
 */
$diviops_wspr_declared_skips = 19;

$diviops_wspr_self  = (string) file_get_contents( __FILE__ );
$diviops_wspr_begin = strpos( $diviops_wspr_self, 'WP-SOURCE-PRESET' . '-BEGIN' );
$diviops_wspr_end   = strpos( $diviops_wspr_self, 'WP-SOURCE-PRESET' . '-END' );
assert_true(
	false !== $diviops_wspr_begin && false !== $diviops_wspr_end && $diviops_wspr_end > $diviops_wspr_begin,
	'both block sentinels are present and ordered — the positive control for the count below'
);
assert_same(
	$diviops_wspr_declared_skips,
	preg_match_all(
		'/\bassert_(?:same|true)\s*\(/',
		substr( $diviops_wspr_self, (int) $diviops_wspr_begin, (int) $diviops_wspr_end - (int) $diviops_wspr_begin )
	),
	'the declared skip count matches the assertions actually inside the WordPress-source block'
);

// WP-SOURCE-PRESET-BEGIN
if ( '' !== DIVIOPS_WPBP_ROOT ) {
	diviops_wspr_seed_registry();

	// A self-closing block with no inner content is already in the form core's
	// serializer emits, so it survives the round-trip fidelity gate.
	$diviops_wspr_stable = '<!-- wp:divi/heading {"builderVersion":"5.0.0","modulePreset":["old-uuid-1"]} /-->';

	// ── dry-run parses and reports, but must not write ───────────────────
	diviops_test_register_post( 8901, $diviops_wspr_stable, 'page', 'Dry Run Page' );
	$diviops_wspr_dry = diviops_wspr_call(
		array(
			'old_uuid' => 'old-uuid-1',
			'new_uuid' => 'new-uuid-2',
			'mode'     => 'dry-run',
			'page_ids' => array( 8901 ),
		)
	);
	assert_true( true === $diviops_wspr_dry['ok'], 'preset_reassign completes a dry-run end to end' );
	assert_same(
		1,
		$diviops_wspr_dry['data']['summary']['uuid_swaps'],
		'the dry-run counts the swap it would make, which it can only know by parsing'
	);
	assert_same(
		$diviops_wspr_stable,
		diviops_wspr_stored( 8901 ),
		'and the dry-run wrote nothing — the stored page is unchanged byte for byte'
	);

	// ── apply rewrites the stored page ────────────────────────────────────
	diviops_test_register_post( 8902, $diviops_wspr_stable, 'page', 'Apply Page' );
	$diviops_wspr_apply = diviops_wspr_call(
		array(
			'old_uuid' => 'old-uuid-1',
			'new_uuid' => 'new-uuid-2',
			'mode'     => 'apply',
			'page_ids' => array( 8902 ),
		)
	);
	assert_true( true === $diviops_wspr_apply['ok'], 'preset_reassign completes an apply end to end' );
	assert_same( 1, $diviops_wspr_apply['data']['summary']['pages_written'], 'and reports one page written' );
	// The rewrite replaces the UUID inside modulePreset in place, so the stored
	// markup differs from the fixture only in that one token.
	assert_same(
		str_replace( 'old-uuid-1', 'new-uuid-2', $diviops_wspr_stable ),
		diviops_wspr_stored( 8902 ),
		'the stored page carries the new UUID, and nothing else about it changed'
	);
	assert_same(
		0,
		substr_count( diviops_wspr_stored( 8902 ), 'old-uuid-1' ),
		'and no occurrence of the old UUID survives on the page'
	);

	// ── the round-trip fidelity gate (#188) ───────────────────────────────
	// An empty paired-tag block is NOT what core's serializer emits for a block
	// with no inner content — it emits the self-closing form — so parse then
	// serialize is not byte-identical here, and the handler must refuse rather
	// than write a page whose diff it cannot attribute.
	$diviops_wspr_lossy = '<!-- wp:divi/heading {"builderVersion":"5.0.0","modulePreset":["old-uuid-1"]} -->'
		. '<!-- /wp:divi/heading -->';
	assert_true(
		serialize_blocks( parse_blocks( $diviops_wspr_lossy ) ) !== $diviops_wspr_lossy,
		'the lossy fixture really does fail a parse/serialize round trip — the positive control for the refusal below'
	);

	diviops_test_register_post( 8903, $diviops_wspr_lossy, 'page', 'Lossy Page' );
	$diviops_wspr_refused = diviops_wspr_call(
		array(
			'old_uuid' => 'old-uuid-1',
			'new_uuid' => 'new-uuid-2',
			'mode'     => 'apply',
			'page_ids' => array( 8903 ),
		)
	);
	// Every page in the batch failing turns the whole call into a partial
	// failure, so the summary moves under error.data rather than data — the
	// hint the handler itself prints points at error.data.summary.errors.
	assert_true( false === $diviops_wspr_refused['ok'], 'a batch whose only page is refused reports failure' );
	assert_same(
		'preset.reassign_partial_failure',
		$diviops_wspr_refused['error']['code'],
		'and the envelope reports the partial-failure code'
	);
	assert_same(
		0,
		$diviops_wspr_refused['error']['data']['summary']['pages_written'],
		'a page that round-trips lossily is not written'
	);
	assert_same(
		'preset.round_trip_lossy',
		$diviops_wspr_refused['error']['data']['summary']['errors'][0]['code'],
		'and the per-page refusal names the round-trip gate'
	);
	// The gate reports the byte delta it refused on, which is the evidence that
	// separates "nothing to do" from "a rewrite we could not attribute".
	assert_true(
		0 > $diviops_wspr_refused['error']['data']['summary']['errors'][0]['round_trip']['byte_delta'],
		'and records the bytes the round trip would have dropped'
	);
	assert_same(
		$diviops_wspr_lossy,
		diviops_wspr_stored( 8903 ),
		'and the stored page is untouched byte for byte'
	);

	// ── refusals that never reach the parse ───────────────────────────────
	// new_uuid must exist in the registry; old_uuid is allowed to be dangling.
	diviops_test_register_post( 8904, $diviops_wspr_stable, 'page', 'Unknown Target Page' );
	$diviops_wspr_unknown = diviops_wspr_call(
		array(
			'old_uuid' => 'old-uuid-1',
			'new_uuid' => 'not-in-the-registry',
			'mode'     => 'apply',
			'page_ids' => array( 8904 ),
		)
	);
	assert_true( false === $diviops_wspr_unknown['ok'], 'preset_reassign refuses a new_uuid that is not registered' );
	assert_same( 'not_found', $diviops_wspr_unknown['error']['code'], 'and reports not_found' );
	assert_same(
		$diviops_wspr_stable,
		diviops_wspr_stored( 8904 ),
		'and wrote nothing'
	);

	// An apply naming no pages is refused before the registry is read at all —
	// the one path test-preset-reassign-write-safety.php already reaches.
	$diviops_wspr_no_pages = diviops_wspr_call(
		array(
			'old_uuid' => 'old-uuid-1',
			'new_uuid' => 'new-uuid-2',
			'mode'     => 'apply',
		)
	);
	assert_true( false === $diviops_wspr_no_pages['ok'], 'an apply naming no pages is refused' );
	assert_same(
		'preset.apply_requires_page_ids',
		$diviops_wspr_no_pages['error']['code'],
		'and reports the apply-target refusal'
	);

	/*
	 * Printed so a full-suite run says WHICH core was loaded, and so CI has a
	 * positive control to grep for — without it a job whose DIVIOPS_WP_SOURCE
	 * silently failed to take effect would print a green PASS having inspected
	 * none of the assertions above.
	 */
	printf(
		"wp-source preset reassign: core at %s; preset_reassign dry-run, apply and round-trip refusal driven end to end\n",
		DIVIOPS_WPBP_ROOT
	);
	// WP-SOURCE-PRESET-END
} else {
	diviops_skip(
		'no WordPress source: DIVIOPS_WP_SOURCE is unset or does not point at a directory containing wp-includes/class-wp-block-parser.php',
		$diviops_wspr_declared_skips
	);
}
