<?php
// SPDX-License-Identifier: MIT
/**
 * Drive the module write handlers end to end against WordPress core's own
 * block parser, and assert on what was STORED (#604).
 *
 * #598 gave the harness core's parser behind `DIVIOPS_WP_SOURCE` and proved it
 * on one handler (`test-wp-source-block-parser.php`, `page_block_insert`).
 * Every other handler that parses before it writes was still reachable only up
 * to its parse call, and two suites say so in their own headers:
 * `test-global-layout-write-guard.php` covers "the pure detection helper only"
 * and states the `module_lock`/`module_clone` round trip "cannot be exercised in
 * this harness"; `test-parse-blocks-for-write-coverage.php` asserts its
 * invariant structurally from source for the same reason. Structural coverage
 * proves the right parser is called. It cannot prove the handler does the right
 * thing with the result, so a defect living PAST the parse call stayed invisible.
 *
 * ## What is covered here
 *
 * `module_lock`, `module_unlock` and `module_clone`, which reach their parse
 * through the shared `load_post_for_module_op()` helper, and `module_move`,
 * which does NOT — its primary path is a non-parser string scanner and its parse
 * site is a separate fallback, `move_block_with_parser()`. That distinction is
 * not cosmetic: an earlier version of `test-parse-blocks-for-write-coverage.php`
 * assumed `module_move` used the shared helper, and adversarial review proved the
 * omission left `module_move`'s real parse site with zero regression coverage by
 * reverting its guard and watching the suite stay green. The fallback is driven
 * here deliberately, not incidentally.
 *
 * `tb_layout_block_insert` is covered too. It parses the stored layout at
 * `trait-theme-builder.php:1500` and had no invocation anywhere in the suite —
 * measured by counting real calls (`diviops_call[a-z_]*( *'<handler>'`), not
 * mentions, with `page_block_insert` (5 calls) and `preset_reassign` (1) as the
 * positive control that the pattern matches the shape an answer takes.
 *
 * ## How the fallback is reached
 *
 * `find_block()`'s only `parse_error` path is the missing-closing-tag branch — a
 * container opener whose matching `<!-- /wp:name -->` never appears before EOF —
 * and that branch always sets `parser_fallbackable => true`, so it is also the
 * only route into `move_block_with_parser()`. `test-module-fallback-trigger-wiring.php`
 * establishes that wiring and stops exactly where this file starts: it proves
 * execution REACHES `parse_blocks()` by catching the undefined-function fatal, and
 * says in its own header that whether the fallback "then produce[s] the *correct*
 * result once parse_blocks() actually runs ... is NOT asserted here". It is
 * asserted here. The fixture is deliberately the same unterminated `divi/text`
 * container, so the two files describe one mechanism rather than two.
 *
 * ## What is NOT covered
 *
 * The behavioural difference between `parse_blocks_for_write()` and bare
 * `parse_blocks()`. That needs Divi's own parser registered through the
 * `block_parser_class` filter, which needs a Divi install rather than a
 * WordPress one; `test-parse-blocks-for-write-coverage.php` keeps that
 * invariant structural, and this file does not duplicate it.
 *
 * `preset_reassign` is the remaining parse-before-write handler and is NOT here:
 * its parse sits behind `et_get_option()`, which the base harness deliberately
 * leaves undefined, so it needs `divi-active-shim.php` as well and is covered in
 * `test-wp-source-preset-reassign.php`.
 *
 * `authoring_shape_preflight` is excluded on purpose. It guards its own parse
 * with `if ( ! function_exists( 'parse_blocks' ) ) { continue; }`
 * (`trait-authoring-shape.php:107`), a documented deliberate degradation, so it
 * is reached either way and needs nothing from this file.
 *
 * @package DiviOps
 */

// Order matters, and is the inverse of the other opt-in stubs': the registry
// stub must define its class BEFORE wp-shim.php guards its own with
// class_exists(), and wp-block-parser-shim.php requires wp-shim.php at its top.
require_once __DIR__ . '/wp-block-registry-rest-shim.php';
require_once __DIR__ . '/wp-block-parser-shim.php';

/**
 * One Divi row carrying the adminLabel the module selectors resolve on.
 *
 * `builderVersion` is present because trait-validate.php requires it on every
 * non-parser-level `divi/*` block, exactly as a real saved page carries it.
 *
 * @param string $label adminLabel value.
 * @return string
 */
function diviops_wswo_row( string $label ): string {
	return '<!-- wp:divi/row {"builderVersion":"5.0.0","module":{"meta":{"adminLabel":{"desktop":{"value":"'
		. $label . '"}}}}} --><!-- /wp:divi/row -->';
}

/**
 * Every adminLabel in a post's STORED content, in document order.
 *
 * Read back out of the post registry the handler wrote to, so every assertion
 * below is about what was stored rather than about what the response claimed.
 *
 * @param int $post_id Post id.
 * @return string[]
 */
function diviops_wswo_stored_labels( int $post_id ): array {
	$post = get_post( $post_id );
	preg_match_all(
		'/"adminLabel":\{"desktop":\{"value":"([^"]+)"\}\}/',
		(string) ( $post->post_content ?? '' ),
		$m
	);
	return $m[1];
}

/**
 * A post's stored content, straight from the registry.
 *
 * @param int $post_id Post id.
 * @return string
 */
function diviops_wswo_stored( int $post_id ): string {
	$post = get_post( $post_id );
	return (string) ( $post->post_content ?? '' );
}

/*
 * How many assertions the guarded block contains, declared as a literal and
 * checked against the block's real contents in BOTH branches — the idiom
 * test-wp-source-block-parser.php and test-shared-preset-attrs-map.php share,
 * for the reason given there: a guard that silently does nothing makes a skipped
 * run and a real run print the same PASS line. The sentinel halves are
 * concatenated so the needle written here cannot find itself.
 */
$diviops_wswo_declared_skips = 30;

$diviops_wswo_self  = (string) file_get_contents( __FILE__ );
$diviops_wswo_begin = strpos( $diviops_wswo_self, 'WP-SOURCE-WRITE' . '-BEGIN' );
$diviops_wswo_end   = strpos( $diviops_wswo_self, 'WP-SOURCE-WRITE' . '-END' );
assert_true(
	false !== $diviops_wswo_begin && false !== $diviops_wswo_end && $diviops_wswo_end > $diviops_wswo_begin,
	'both block sentinels are present and ordered — the positive control for the count below'
);
assert_same(
	$diviops_wswo_declared_skips,
	preg_match_all(
		'/\bassert_(?:same|true)\s*\(/',
		substr( $diviops_wswo_self, (int) $diviops_wswo_begin, (int) $diviops_wswo_end - (int) $diviops_wswo_begin )
	),
	'the declared skip count matches the assertions actually inside the WordPress-source block'
);

// WP-SOURCE-WRITE-BEGIN
if ( '' !== DIVIOPS_WPBP_ROOT ) {

	// ── module_lock ───────────────────────────────────────────────────────
	// Expected attr shape is read off the handler's own branch body:
	// trait-page.php sets $block['attrs']['locked'] = [ 'desktop' => [ 'value' => 'on' ] ].
	diviops_test_register_post(
		8801,
		'<!-- wp:divi/section {"builderVersion":"5.0.0"} -->'
		. diviops_wswo_row( 'Keep Me' ) . diviops_wswo_row( 'Lock Me' )
		. '<!-- /wp:divi/section -->',
		'page',
		'Lock Target'
	);
	$diviops_wswo_lock = diviops_call(
		'module_lock',
		array( new DiviOps_Test_Request( array( 'id' => 8801, 'label' => 'Lock Me' ) ) )
	)->get_data();
	assert_true( true === $diviops_wswo_lock['ok'], 'module_lock runs to completion end to end' );
	assert_same(
		1,
		substr_count( diviops_wswo_stored( 8801 ), '"locked":{"desktop":{"value":"on"}}' ),
		'the stored page carries exactly one locked attribute, so only the targeted row was locked'
	);
	// `locked` lands AFTER `module` in the serialized attrs because the handler
	// assigns a NEW array key, PHP appends new keys, and json_encode preserves
	// insertion order. Asserting the pair as one adjacent string is what ties
	// the attribute to the row the label selected rather than to its sibling.
	assert_true(
		false !== strpos(
			diviops_wswo_stored( 8801 ),
			'"adminLabel":{"desktop":{"value":"Lock Me"}}}},"locked":{"desktop":{"value":"on"}}'
		),
		'and it is attached to the row the label selected, not to its sibling'
	);
	assert_same(
		array( 'Keep Me', 'Lock Me' ),
		diviops_wswo_stored_labels( 8801 ),
		'locking rewrites attributes without reordering or dropping a sibling'
	);

	// ── module_unlock ─────────────────────────────────────────────────────
	// Expected absence is read off the handler's own docblock and body:
	// "unlocked = attribute absent (NOT {value: 'off'})" — it unsets the field
	// rather than writing a falsy one.
	$diviops_wswo_unlock = diviops_call(
		'module_unlock',
		array( new DiviOps_Test_Request( array( 'id' => 8801, 'label' => 'Lock Me' ) ) )
	)->get_data();
	assert_true( true === $diviops_wswo_unlock['ok'], 'module_unlock runs to completion end to end' );
	assert_same(
		0,
		substr_count( diviops_wswo_stored( 8801 ), '"locked"' ),
		'unlocking removes the attribute entirely rather than storing an off value'
	);
	assert_same(
		array( 'Keep Me', 'Lock Me' ),
		diviops_wswo_stored_labels( 8801 ),
		'and leaves both rows in place'
	);

	// ── module_clone ──────────────────────────────────────────────────────
	// position defaults to 'after' in the handler body, and the clone is a
	// value copy of the matched block, so the duplicate carries the same label.
	diviops_test_register_post(
		8802,
		'<!-- wp:divi/section {"builderVersion":"5.0.0"} -->'
		. diviops_wswo_row( 'Original' ) . diviops_wswo_row( 'Tail' )
		. '<!-- /wp:divi/section -->',
		'page',
		'Clone Target'
	);
	$diviops_wswo_clone = diviops_call(
		'module_clone',
		array( new DiviOps_Test_Request( array( 'id' => 8802, 'label' => 'Original' ) ) )
	)->get_data();
	assert_true( true === $diviops_wswo_clone['ok'], 'module_clone runs to completion end to end' );
	assert_same(
		array( 'Original', 'Original', 'Tail' ),
		diviops_wswo_stored_labels( 8802 ),
		'the clone is stored immediately after its source, inside the same parent'
	);

	// A nested clone is the case the handler splices an extra null placeholder
	// into the parent's innerContent for — without it serialize_blocks() drops
	// the clone silently, which is the failure its own comment describes.
	assert_same(
		3,
		substr_count( diviops_wswo_stored( 8802 ), '<!-- wp:divi/row' ),
		'and serialize_blocks() emitted the spliced clone rather than dropping it'
	);

	// ── module_move, through move_block_with_parser() ─────────────────────
	// The unterminated divi/text container is test-module-fallback-trigger-wiring.php's
	// fixture: find_block()'s forward scan never finds its closing comment, returns
	// parse_error with parser_fallbackable => true, and module_move falls through to
	// move_block_with_parser(). Core's parser then closes the container implicitly at
	// EOF, so both sections parse as its innerBlocks at paths [0,0] and [0,1].
	$diviops_wswo_malformed = '<!-- wp:divi/text {"builderVersion":"5.0.0"} -->'
		. '<p>Body content with no matching closing comment anywhere on this page.</p>';
	$diviops_wswo_move_fixture = $diviops_wswo_malformed
		. diviops_wswo_row( 'Move Source' ) . diviops_wswo_row( 'Move Target' );

	diviops_test_register_post( 8803, $diviops_wswo_move_fixture, 'page', 'Move Target Page' );
	$diviops_wswo_move = diviops_call(
		'module_move',
		array(
			new DiviOps_Test_Request(
				array(
					'id'           => 8803,
					'source_label' => 'Move Source',
					'target_label' => 'Move Target',
					'position'     => 'after',
				)
			),
		)
	)->get_data();
	assert_true(
		true === $diviops_wswo_move['ok'],
		'module_move completes through its parser fallback, which no suite could drive before'
	);
	// With source at index 0 and target at index 1 under the same parent,
	// 'after' is not the no-op case (that is 'before'), so the handler removes
	// the source, decrements the target path past the gap, and re-inserts at
	// target+1 — leaving the two in the opposite order.
	assert_same(
		array( 'Move Target', 'Move Source' ),
		diviops_wswo_stored_labels( 8803 ),
		'the stored page carries the source after its target, so the parser-backed move really wrote'
	);
	// Round-tripping unterminated markup through core's parser and
	// serialize_blocks() REPAIRS the missing closing comment, because the parser
	// closes the container implicitly at EOF and the serializer emits it. This
	// is a real side effect of taking the fallback, not an artifact of the test.
	assert_same(
		1,
		substr_count( diviops_wswo_stored( 8803 ), '<!-- /wp:divi/text -->' ),
		'and the fallback repaired the unterminated container by emitting its closing comment'
	);

	// 'before' on the same adjacency IS the no-op branch: source_index + 1 ===
	// target_index. The handler must report it without rewriting content.
	diviops_test_register_post( 8804, $diviops_wswo_move_fixture, 'page', 'Move Noop Page' );
	$diviops_wswo_noop = diviops_call(
		'module_move',
		array(
			new DiviOps_Test_Request(
				array(
					'id'           => 8804,
					'source_label' => 'Move Source',
					'target_label' => 'Move Target',
					'position'     => 'before',
				)
			),
		)
	)->get_data();
	assert_true( true === $diviops_wswo_noop['ok'], 'the adjacent no-op move is reported as success' );
	// move_block_with_parser() returns noop => true from its own early return,
	// and module_move carries that flag out on the legacy apply-path shape.
	// Without this the no-op branch is unobservable: for this adjacency the
	// move is its own inverse, so the written bytes would be identical anyway.
	assert_true( true === $diviops_wswo_noop['data']['noop'], 'and is reported as a no-op rather than as a rewrite' );
	assert_same(
		$diviops_wswo_move_fixture,
		diviops_wswo_stored( 8804 ),
		'and the no-op branch returns before the write, so the stored content is untouched byte for byte'
	);

	// ── refusal paths ─────────────────────────────────────────────────────
	// A label that is genuinely absent on a well-formed page is block_not_found,
	// which envelope_from_helper_error collapses onto the canonical code
	// not_found. The write must not happen.
	diviops_test_register_post(
		8805,
		'<!-- wp:divi/section {"builderVersion":"5.0.0"} -->' . diviops_wswo_row( 'Only Row' ) . '<!-- /wp:divi/section -->',
		'page',
		'Refusal Target'
	);
	$diviops_wswo_before_refusal = diviops_wswo_stored( 8805 );
	$diviops_wswo_missing        = diviops_call(
		'module_lock',
		array( new DiviOps_Test_Request( array( 'id' => 8805, 'label' => 'Does Not Exist' ) ) )
	)->get_data();
	assert_true( false === $diviops_wswo_missing['ok'], 'module_lock refuses a label that is not on the page' );
	assert_same( 'not_found', $diviops_wswo_missing['error']['code'], 'and reports not_found' );
	assert_same(
		$diviops_wswo_before_refusal,
		diviops_wswo_stored( 8805 ),
		'and wrote nothing — the refusal is reached after the parse but before the save'
	);

	// resolve_module_target() refuses before load_post_for_module_op() is ever
	// called, so this one never reaches the parser at all.
	$diviops_wswo_no_target = diviops_call(
		'module_clone',
		array( new DiviOps_Test_Request( array( 'id' => 8805 ) ) )
	)->get_data();
	assert_true( false === $diviops_wswo_no_target['ok'], 'module_clone refuses a request naming no target at all' );
	assert_same(
		$diviops_wswo_before_refusal,
		diviops_wswo_stored( 8805 ),
		'and that refusal also leaves the page unwritten'
	);

	// load_post_for_module_op() returns not_found for an unregistered id, which
	// envelope_post_load_error carries out as a 404.
	$diviops_wswo_absent_page = diviops_call(
		'module_unlock',
		array( new DiviOps_Test_Request( array( 'id' => 999777, 'label' => 'Only Row' ) ) )
	)->get_data();
	assert_true( false === $diviops_wswo_absent_page['ok'], 'module_unlock refuses an id with no post behind it' );
	assert_same( 'not_found', $diviops_wswo_absent_page['error']['code'], 'and reports not_found for the missing page' );

	// ── tb_layout_block_insert ────────────────────────────────────────────
	// Valid post types are listed in the handler body:
	// et_header_layout, et_body_layout, et_footer_layout.
	diviops_test_register_post(
		8806,
		'<!-- wp:divi/section {"builderVersion":"5.0.0","module":{"meta":{"adminLabel":{"desktop":{"value":"Layout Root"}}}}} -->'
		. diviops_wswo_row( 'Existing' )
		. '<!-- /wp:divi/section -->',
		'et_header_layout',
		'Header Layout'
	);
	$diviops_wswo_tb = diviops_call(
		'tb_layout_block_insert',
		array(
			new DiviOps_Test_Request(
				array(
					'id'              => 8806,
					'content'         => diviops_wswo_row( 'Appended' ),
					'position'        => 'append',
					'parent_selector' => 'divi/section[adminLabel="Layout Root"]',
				)
			),
		)
	)->get_data();
	assert_true( true === $diviops_wswo_tb['ok'], 'tb_layout_block_insert runs to completion end to end' );
	assert_same(
		array( 'Layout Root', 'Existing', 'Appended' ),
		diviops_wswo_stored_labels( 8806 ),
		'the stored layout carries the appended row last inside the selected section'
	);

	// A page is not a Theme Builder layout, and the handler checks post_type
	// against its own allowlist before it loads anything.
	diviops_test_register_post( 8807, diviops_wswo_row( 'Not A Layout' ), 'page', 'Ordinary Page' );
	$diviops_wswo_tb_wrong_type = diviops_call(
		'tb_layout_block_insert',
		array(
			new DiviOps_Test_Request(
				array(
					'id'              => 8807,
					'content'         => diviops_wswo_row( 'Nope' ),
					'position'        => 'append',
					'parent_selector' => 'divi/row',
				)
			),
		)
	)->get_data();
	assert_true( false === $diviops_wswo_tb_wrong_type['ok'], 'tb_layout_block_insert refuses an ordinary page' );
	assert_same( 'not_found', $diviops_wswo_tb_wrong_type['error']['code'], 'and reports not_found for a non-layout post type' );

	// Exactly one of parent_selector / parent_path, per the handler's own check.
	$diviops_wswo_tb_both = diviops_call(
		'tb_layout_block_insert',
		array(
			new DiviOps_Test_Request(
				array(
					'id'              => 8806,
					'content'         => diviops_wswo_row( 'Nope' ),
					'position'        => 'append',
					'parent_selector' => 'divi/section',
					'parent_path'     => '0',
				)
			),
		)
	)->get_data();
	assert_true( false === $diviops_wswo_tb_both['ok'], 'tb_layout_block_insert refuses both parent_selector and parent_path together' );
	assert_same( 'invalid_input', $diviops_wswo_tb_both['error']['code'], 'and reports invalid_input' );
	assert_same(
		array( 'Layout Root', 'Existing', 'Appended' ),
		diviops_wswo_stored_labels( 8806 ),
		'and the layout is unchanged by the refused call'
	);

	/*
	 * Printed so a full-suite run says WHICH core was loaded, and so CI has a
	 * positive control to grep for. Without it this file contributes no line to
	 * a full run, and a job whose DIVIOPS_WP_SOURCE silently failed to take
	 * effect would print a green PASS having inspected none of the assertions
	 * above — the blind-gate failure this repository fails loudly on elsewhere.
	 */
	printf(
		"wp-source module write ops: core at %s; module_lock/unlock/clone, module_move via move_block_with_parser(), tb_layout_block_insert driven end to end\n",
		DIVIOPS_WPBP_ROOT
	);
	// WP-SOURCE-WRITE-END
} else {
	diviops_skip(
		'no WordPress source: DIVIOPS_WP_SOURCE is unset or does not point at a directory containing wp-includes/class-wp-block-parser.php',
		$diviops_wswo_declared_skips
	);
}
