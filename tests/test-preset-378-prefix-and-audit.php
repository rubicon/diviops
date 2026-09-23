<?php
// SPDX-License-Identifier: MIT
/**
 * Regression: the two remaining #378 defects in `trait-preset.php`.
 *
 * ── 1. `rename_strip_prefix` could not strip a prefix ending in a separator ──
 *
 * `preset_cleanup()` read the parameter as display text:
 *
 *     $prefix = sanitize_text_field( (string) ( $request->get_param( 'prefix' ) ?? '' ) );
 *
 * WordPress core's `_sanitize_text_fields()` ends with `trim( $filtered )`
 * (`wp-includes/formatting.php`), so `"DiviOps "` arrived as `"DiviOps"`,
 * `$prefix_len` was 7 instead of 8, and `substr( $name, 7 )` left the separator
 * behind: `"DiviOps Hero"` became `" Hero"` while the handler reported a clean
 * rename. Stripping a trailing separator is the NORMAL case for this feature —
 * a prefix without one is the exception — and the damage was invisible in the
 * response, showing up only in the Divi UI.
 *
 * `prefix` is a literal to MATCH, not text to display, so it is now validated
 * rather than sanitized: rejected if it is not a string, not valid UTF-8, or
 * carries control bytes, and otherwise passed through byte-for-byte. This is the
 * same choice `seo_validate_plain_text()` makes in `trait-seo.php` for the same
 * reason.
 *
 * ── 2. `preset_audit` and `preset_cleanup` disagreed about the bucket default ──
 *
 * `preset_audit` filed a bucket's default preset under `spam_unreferenced` — the
 * list an operator reads as "safe to delete" — while `preset_cleanup` refused to
 * delete it, correctly, via its `is_default` gate. The two computed "safe to
 * delete" independently and drifted. Audit now derives the bucket from the same
 * predicate cleanup removes on, so the list cannot disagree with the action.
 *
 * Note both handlers already REPORTED `is_default` on the entry; the defect was
 * that the bucket ignored it. The fix changes which list an entry lands in, not
 * what the entry says.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

$GLOBALS['diviops_378_saved_options']     = $GLOBALS['diviops_test_options'];
$GLOBALS['diviops_378_saved_option_rows'] = $GLOBALS['diviops_test_option_rows'];
$GLOBALS['diviops_378_saved_posts']       = $GLOBALS['diviops_test_posts'] ?? array();
$GLOBALS['diviops_test_posts']            = array();

const DIVIOPS_378_OPTION = 'et_divi_builder_global_presets_d5';

/**
 * Seed the canonical registry.
 *
 * @param array $registry Registry payload.
 * @return array
 */
function diviops_378_seed( array $registry ): array {
	update_option( DIVIOPS_378_OPTION, $registry, false );
	return $registry;
}

/**
 * Read the canonical registry back.
 *
 * @return mixed
 */
function diviops_378_stored() {
	return get_option( DIVIOPS_378_OPTION, null );
}

/**
 * Invoke a handler and return its envelope body.
 *
 * @param string $method Handler name on DiviOps_Agent.
 * @param array  $params Request params.
 * @return array
 */
function diviops_378_body( string $method, array $params = array() ): array {
	return diviops_call( $method, array( new DiviOps_Test_Request( $params ) ) )->get_data();
}

/**
 * One module bucket whose presets all carry a `"DiviOps "` display prefix.
 *
 * @return array
 */
function diviops_378_prefixed_fixture(): array {
	return array(
		'module' => array(
			'divi/heading' => array(
				'default' => '',
				'items'   => array(
					'p1' => array( 'name' => 'DiviOps Hero', 'attrs' => array( 'a' => 1 ) ),
					'p2' => array( 'name' => 'DiviOps Card Body', 'attrs' => array( 'b' => 2 ) ),
				),
			),
		),
		'group'  => array(),
	);
}

// ── 1. A prefix ending in a separator strips cleanly ──────────────────────
//
// Before the fix the trailing space was trimmed off the parameter, so the
// separator survived on every renamed preset and the name kept a leading space.
diviops_378_seed( diviops_378_prefixed_fixture() );

$body = diviops_378_body(
	'preset_cleanup',
	array( 'action' => 'rename_strip_prefix', 'prefix' => 'DiviOps ', 'dry_run' => false )
);

$stored = diviops_378_stored();
assert_same(
	'Hero',
	$stored['module']['divi/heading']['items']['p1']['name'] ?? null,
	'#378: a prefix ending in a space strips the space too — no leading separator is left behind'
);
assert_same(
	'Card Body',
	$stored['module']['divi/heading']['items']['p2']['name'] ?? null,
	'#378: and the same for every other preset carrying it'
);

// ── 2. A prefix with no separator still behaves as before ─────────────────
//
// Guard against "fixing" this by trimming the NAME instead of preserving the
// parameter: stripping "DiviOps" alone must leave the separator, because that
// is what the caller literally asked to match.
diviops_378_seed( diviops_378_prefixed_fixture() );

diviops_378_body(
	'preset_cleanup',
	array( 'action' => 'rename_strip_prefix', 'prefix' => 'DiviOps', 'dry_run' => false )
);

$stored = diviops_378_stored();
assert_same(
	' Hero',
	$stored['module']['divi/heading']['items']['p1']['name'] ?? null,
	'#378: a prefix WITHOUT the separator still strips only what it names — the parameter is a literal, not a hint'
);

// ── 3. The parameter is validated, not sanitized ──────────────────────────
//
// A control byte must be refused rather than silently stripped, which is the
// behaviour difference between validating and sanitizing.
diviops_378_seed( diviops_378_prefixed_fixture() );

$body = diviops_378_body(
	'preset_cleanup',
	array( 'action' => 'rename_strip_prefix', 'prefix' => "DiviOps\x00 ", 'dry_run' => false )
);

assert_same(
	false,
	$body['ok'] ?? null,
	'#378: a prefix carrying a control byte is refused rather than quietly cleaned into a different literal'
);
$stored = diviops_378_stored();
assert_same(
	'DiviOps Hero',
	$stored['module']['divi/heading']['items']['p1']['name'] ?? null,
	'#378: and the refusal wrote nothing'
);

// ── 4. preset_audit does not file a protected default as deletable ────────
//
// `spam_unreferenced` is the list an operator reads as safe to delete.
// `preset_cleanup` refuses to delete a bucket default via its `is_default`
// gate, so audit must not advertise one there.
diviops_378_seed(
	array(
		'module' => array(
			'divi/heading' => array(
				'default' => 'mod-default',
				'items'   => array(
					// Spam-named AND the bucket default: cleanup renames rather
					// than removes it, so it is not deletable.
					'mod-default' => array( 'name' => 'Heading Heading Hero', 'attrs' => array( 'a' => 1 ) ),
					// Spam-named, unreferenced, not default: genuinely deletable.
					'mod-spam'    => array( 'name' => 'Text Text', 'attrs' => array( 'b' => 2 ) ),
				),
			),
		),
		'group'  => array(),
	)
);

$audit     = diviops_378_body( 'preset_audit' )['data'] ?? array();
$spam_unref = array_column( $audit['spam_unreferenced'] ?? array(), 'id' );
$spam_ref   = array_column( $audit['spam_referenced'] ?? array(), 'id' );

assert_same(
	array( 'mod-spam' ),
	$spam_unref,
	'#378: only the genuinely deletable preset is listed as spam_unreferenced — the bucket default is not'
);
assert_same(
	true,
	in_array( 'mod-default', $spam_ref, true ),
	'#378: the protected default is still reported, in the bucket that is not read as a delete list'
);

// ── 5. The audit entry still tells the truth about WHY it is protected ────
//
// The fix must change which list the entry lands in, not what it says: an
// operator needs to see that it is protected by `is_default` rather than by a
// reference, or the bucket move would itself be misleading.
$default_entry = null;
foreach ( ( $audit['spam_referenced'] ?? array() ) as $entry ) {
	if ( 'mod-default' === ( $entry['id'] ?? '' ) ) {
		$default_entry = $entry;
	}
}
assert_same( true, is_array( $default_entry ), '#378: the default entry is present to inspect' );
assert_same( true, $default_entry['is_default'] ?? null, '#378: and it still reports is_default true' );
assert_same( false, $default_entry['referenced'] ?? null, '#378: and honestly reports referenced false — it is protected by the default gate, not by a reference' );
assert_same( 0, $default_entry['ref_count'] ?? null, '#378: with a real ref_count of 0' );

// ── 6. cleanup and audit agree on the same registry ───────────────────────
//
// The defect was a disagreement between two handlers, so the closing assertion
// is that they now agree: everything audit calls deletable is what cleanup
// actually removes.
$removed = array_column(
	( diviops_378_body( 'preset_cleanup', array( 'action' => 'remove_orphans', 'scope' => 'spam', 'dry_run' => true ) )['data'] ?? array() )['removed'] ?? array(),
	'id'
);
sort( $removed );
sort( $spam_unref );
assert_same(
	$spam_unref,
	$removed,
	'#378: preset_audit spam_unreferenced now matches exactly what preset_cleanup scope=spam removes'
);

// ── Restore ───────────────────────────────────────────────────────────────
$GLOBALS['diviops_test_options']     = $GLOBALS['diviops_378_saved_options'];
$GLOBALS['diviops_test_option_rows'] = $GLOBALS['diviops_378_saved_option_rows'];
$GLOBALS['diviops_test_posts']       = $GLOBALS['diviops_378_saved_posts'];
