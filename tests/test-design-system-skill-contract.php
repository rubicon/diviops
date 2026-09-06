<?php
// SPDX-License-Identifier: MIT
/**
 * The style-guide skill reference must not drift from the contract the tool enforces (#392).
 *
 * `skills/divi-5-builder/references/style-guide-ingestion.md` tells the model what
 * `diviops_design_system_apply` accepts and refuses. A skill that documents a vocabulary the
 * handler does not implement sends the model to build payloads the tool will reject; one that
 * omits a value the handler DOES accept makes a legal input look illegal and quietly narrows
 * the feature.
 *
 * Both directions have precedent, and the second is not hypothetical. The first revision of
 * this feature shipped a settings vocabulary of three keys — lightness, saturation, opacity —
 * derived from the 47 reference entries on the live palette rather than from Divi's own
 * `GlobalData.php:177`, which documents four. `hue` was refused as unrecognised. Nothing
 * caught it, because nothing compared the documented list against anything.
 *
 * ## How this avoids being a gate that inspects nothing
 *
 * The expected vocabularies are **read out of the running plugin** — `diviops_call()` on the
 * same private statics the handler itself calls — and then searched for in the document. The
 * assertion is therefore about agreement between two live sources, not about a list restated
 * here in a third place, which would just be a fourth thing to drift.
 *
 * Every check counts what it inspected and asserts the count is non-zero first. A `strpos`
 * gate over a document that failed to load reports "no problems found" exactly like a clean
 * one.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/divi-active-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

$diviops_dss_path = dirname( __DIR__ ) . '/skills/divi-5-builder/references/style-guide-ingestion.md';
$diviops_dss_doc  = is_file( $diviops_dss_path ) ? (string) file_get_contents( $diviops_dss_path ) : '';

// The positive control. Everything below is a substring search, and a substring search over
// an empty string finds no problems.
assert_true( strlen( $diviops_dss_doc ) > 2000, 'the style-guide reference loaded and carries content' );

/**
 * Count occurrences of a needle, so a check can prove it inspected something.
 */
function diviops_dss_has( string $doc, string $needle ): bool {
	return false !== strpos( $doc, $needle );
}

// ---------------------------------------------------------------------------
// Settings vocabulary: the doc must name every key the handler accepts, and no key
// it rejects. Read from the plugin, not restated here.
// ---------------------------------------------------------------------------

$diviops_dss_settings = diviops_call( 'design_system_color_settings_keys', array() );
assert_true(
	is_array( $diviops_dss_settings ) && count( $diviops_dss_settings ) > 0,
	'the handler declares a non-empty settings vocabulary to check against'
);

// Checked per ENUMERATION SITE, not across the whole document.
//
// A whole-document substring search was the first version of this check, and a mutation
// killed it: the reference states the vocabulary twice — once in the field table and once
// in the refusals table — so dropping `hue` from one still left the string present in the
// other and the gate passed. That is the "a grep can succeed and still be blind" failure
// this repository already records: the command worked, the result was real, and the pattern
// simply could not see the shape the answer takes.
//
// A vocabulary-enumerating line is one naming saturation AND opacity together. Every such
// line must carry every key the handler accepts, so the document cannot disagree with
// itself either.
$diviops_dss_sites = array();
foreach ( explode( "\n", $diviops_dss_doc ) as $line ) {
	if ( false !== strpos( $line, '`saturation`' ) && false !== strpos( $line, '`opacity`' ) ) {
		$diviops_dss_sites[] = $line;
	}
}
assert_true(
	count( $diviops_dss_sites ) >= 2,
	'the reference enumerates the settings vocabulary in at least the two places it is stated'
);

$diviops_dss_checks = 0;
foreach ( $diviops_dss_sites as $site_no => $line ) {
	foreach ( $diviops_dss_settings as $key ) {
		$diviops_dss_checks++;
		assert_true(
			false !== strpos( $line, '`' . $key . '`' ),
			"enumeration site {$site_no} names the '{$key}' setting the handler accepts"
		);
	}
}
assert_same(
	count( $diviops_dss_sites ) * count( $diviops_dss_settings ),
	$diviops_dss_checks,
	'every enumeration site was checked against every key the handler accepts'
);

// The inverse. `brightness` is the shape of a plausible-but-wrong key — it is what a model
// reaches for when it has not read Divi — so documenting it would actively mislead.
assert_true(
	! diviops_dss_has( $diviops_dss_doc, '`brightness`' ),
	'the reference does not document a settings key the handler rejects'
);

// ---------------------------------------------------------------------------
// Status vocabulary, per surface (#393). The reference must carry the COLOUR list and must
// say that `archived` — a real Divi status, on another surface — is refused here.
// ---------------------------------------------------------------------------

$diviops_dss_statuses = diviops_call( 'valid_global_color_statuses', array() );
assert_true(
	is_array( $diviops_dss_statuses ) && count( $diviops_dss_statuses ) > 0,
	'the handler declares a non-empty colour status vocabulary'
);

$diviops_dss_status_named = 0;
foreach ( $diviops_dss_statuses as $status ) {
	$found = diviops_dss_has( $diviops_dss_doc, '`' . $status . '`' );
	assert_true( $found, "the reference documents the '{$status}' colour status" );
	if ( $found ) {
		$diviops_dss_status_named++;
	}
}
assert_same(
	count( $diviops_dss_statuses ),
	$diviops_dss_status_named,
	'every colour status the handler accepts is named in the reference'
);

assert_true(
	! in_array( 'archived', $diviops_dss_statuses, true ),
	'the handler does not accept archived on a colour — the premise of the warning below'
);
assert_true(
	diviops_dss_has( $diviops_dss_doc, 'archived' ),
	'and the reference warns that archived is refused here, since it IS valid on gvid-* variables'
);

// ---------------------------------------------------------------------------
// The never-invent rule is the reason this feature is split across a skill and a tool. A
// reference that omits it documents an API and loses the point.
// ---------------------------------------------------------------------------

assert_true(
	diviops_dss_has( $diviops_dss_doc, 'Never invent a token value' ),
	'the reference states the never-invent rule'
);
assert_true(
	diviops_dss_has( $diviops_dss_doc, 'derived_from' ),
	'and explains the derived_from alternative to a literal'
);
assert_true(
	diviops_dss_has( $diviops_dss_doc, 'dry_run' ),
	'and tells the reader to dry-run before writing'
);

// The all-or-nothing property. A reader who assumes a partial apply will try to "fix the rest"
// after a refusal and write the same tokens twice.
assert_true(
	diviops_dss_has( $diviops_dss_doc, 'refuses the whole payload' ),
	'the reference states that one bad token refuses the entire payload'
);

// ---------------------------------------------------------------------------
// The tool name must be the real one, and the reference must be reachable. An unlinked
// reference is one nothing routes to — the same failure test-skill-verification-tiers.php
// guards for variable-bindings.md.
// ---------------------------------------------------------------------------

assert_true(
	diviops_dss_has( $diviops_dss_doc, 'diviops_design_system_apply' ),
	'the reference names the tool by its real registered name'
);
assert_true(
	method_exists( 'DiviOps_Agent', 'design_system_apply' ),
	'and that tool actually exists on the class'
);

$diviops_dss_skill = (string) file_get_contents( dirname( __DIR__ ) . '/skills/divi-5-builder/SKILL.md' );
assert_true( strlen( $diviops_dss_skill ) > 2000, 'SKILL.md loaded' );
assert_true(
	diviops_dss_has( $diviops_dss_skill, 'references/style-guide-ingestion.md' ),
	'SKILL.md routes to the style-guide reference'
);

// ---------------------------------------------------------------------------
// The id shape the reference teaches must be the one the handler mints. A reader who
// believes a different shape cannot predict what a re-run will update.
// ---------------------------------------------------------------------------

$diviops_dss_id = diviops_call( 'design_system_token_id', array( 'gcid', 'acme', 'primary' ) );
assert_same( 'gcid-acme-primary', $diviops_dss_id, 'the handler mints prefix-namespace-slug' );
assert_true(
	diviops_dss_has( $diviops_dss_doc, 'gcid-<namespace>-<slug>' ),
	'and the reference teaches that exact shape'
);
