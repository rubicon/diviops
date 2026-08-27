<?php
// SPDX-License-Identifier: MIT
/**
 * Parser-level Divi blocks are not validator errors (#288).
 *
 * `validate_blocks` resolved every `divi/*` block against
 * `WP_Block_Type_Registry`. Divi 5 does not put all of its block types there:
 * some are handled entirely inside its own parser
 * (`includes/builder-5/server/FrontEnd/BlockParser/BlockParser.php`), so
 * `is_registered()` returns false for them by design. The validator was
 * consulting a registry that was never going to contain them.
 *
 * Measured against the live reference install (Divi 5.11.1, agent 1.19.1) via
 * the REST path, rather than assumed:
 *
 *   divi/section, divi/text, divi/canvas-portal, divi/layout   registered
 *   divi/global-layout, divi/root                              never registered
 *
 * Both unregistered ones also legitimately carry no `builderVersion`: they are
 * structural wrappers that own no module attrs — a `global-layout` wrapper
 * defers to the referenced layout post, and `root` is the tree container. So
 * each produced TWO errors per occurrence.
 *
 * The reported symptom was `valid: false` plus two errors on every page using
 * a global section — nine pages checked, identical output. The real cost is not
 * the two lines: a validator that always fails teaches operators and agents to
 * ignore it, which loses the genuine silent-failure classes it exists to catch
 * (`responsive_flex_child_missing_full_width`, `flextype_wrong_path`).
 *
 * `divi/root` is included here although the filed issue named only
 * `global-layout`. It was found to have the identical defect while confirming
 * the report, and exempting one without the other would leave the same false
 * positive reachable from any root-wrapped subtree.
 *
 * The exemption must stay NARROW. Two tests below pin that: a genuinely
 * unknown `divi/*` block still errors, and a real module missing
 * `builderVersion` still errors. Without those, a blanket "stop checking
 * divi/*" would pass every assertion here while silently disabling the check.
 *
 * This is validation-path only. It does not touch `module_update`'s dot-path
 * merge or `serialize_block_attrs_canonical()`.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Build one parsed block in the shape `parse_blocks()` produces.
 *
 * The shim deliberately does not implement WP's block parser, so these tests
 * hand `validate_block_tree()` the tree directly — which is its actual
 * contract. Building the nodes here also keeps each case's shape visible
 * instead of hiding it behind a markup string that has to be mentally parsed.
 *
 * @param string $name  Block name.
 * @param array  $attrs Block attrs.
 * @param array  $inner Inner blocks.
 * @return array
 */
function diviops_block( string $name, array $attrs = array(), array $inner = array() ): array {
	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => $inner,
		'innerContent' => array(),
	);
}

/**
 * Run the validator's tree walk and return its findings.
 *
 * @param array $blocks Parsed block tree.
 * @return array{errors: array, warnings: array}
 */
function diviops_validate_findings( array $blocks ): array {
	$errors   = array();
	$warnings = array();
	$index    = 0;
	$nav_refs = null;

	$args = array(
		$blocks,
		WP_Block_Type_Registry::get_instance(),
		array( 'divi/section', 'divi/row', 'divi/column', 'divi/group', 'divi/group-carousel', 'divi/dropdown' ),
		&$errors,
		&$warnings,
		&$index,
		&$nav_refs,
		null,
	);
	diviops_call_ref( 'validate_block_tree', $args );

	return array(
		'errors'   => $errors,
		'warnings' => $warnings,
	);
}

/**
 * Codes reported against a given block name.
 *
 * @param array  $findings Findings array.
 * @param string $block    Block name.
 * @return array
 */
function diviops_codes_for( array $findings, string $block ): array {
	$codes = array();
	foreach ( $findings as $finding ) {
		if ( ( $finding['block'] ?? '' ) === $block ) {
			$codes[] = $finding['code'] ?? '';
		}
	}
	return $codes;
}

// ── The reported shape: a global-layout wrapper carrying no builderVersion ──

$wrapper_page = array(
	diviops_block( 'divi/placeholder', array( 'builderVersion' => '5.11.1' ) ),
	diviops_block(
		'divi/global-layout',
		array(
			'globalModule' => '900296',
			'blockName'    => 'divi/section',
		),
		array(
			diviops_block(
				'divi/text',
				array(
					'builderVersion' => '5.11.1',
					'content'        => array( 'innerContent' => array( 'desktop' => array( 'value' => 'x' ) ) ),
				)
			),
		)
	),
);

$found = diviops_validate_findings( $wrapper_page );

assert_same(
	array(),
	diviops_codes_for( $found['errors'], 'divi/global-layout' ),
	'a global-layout wrapper reports no errors — Divi\'s own parser owns it, and it carries no module attrs'
);

// ── divi/root, the same defect found while confirming the report ────────────

$root_page = array(
	diviops_block(
		'divi/root',
		array(),
		array( diviops_block( 'divi/section', array( 'builderVersion' => '5.11.1' ) ) )
	),
);

$found_root = diviops_validate_findings( $root_page );

assert_same(
	array(),
	diviops_codes_for( $found_root['errors'], 'divi/root' ),
	'divi/root is a parser-level tree container, not a registered block type'
);

// ── The exemption must not become a blanket pass ───────────────────────────

$unknown_page = array( diviops_block( 'divi/not-a-real-module', array( 'builderVersion' => '5.11.1' ) ) );

$found_unknown = diviops_validate_findings( $unknown_page );

assert_true(
	in_array( 'unknown_block_type', diviops_codes_for( $found_unknown['errors'], 'divi/not-a-real-module' ), true ),
	'a divi block that is genuinely absent from the registry still errors — the exemption is a list, not a switch'
);

$missing_version_page = array( diviops_block( 'divi/heading', array( 'module' => array() ) ) );

$found_missing = diviops_validate_findings( $missing_version_page );

assert_true(
	in_array( 'missing_builder_version', diviops_codes_for( $found_missing['errors'], 'divi/heading' ), true ),
	'a real module missing builderVersion still errors — only the attr-less wrappers are exempt'
);

// ── The walk still counts exempt blocks, so auto_index parity is unaffected ─

$count_probe = diviops_validate_findings(
	array(
		diviops_block( 'divi/global-layout', array( 'globalModule' => '900296' ) ),
		diviops_block( 'divi/heading', array( 'module' => array() ) ),
	)
);

$heading_findings = array();
foreach ( $count_probe['errors'] as $finding ) {
	if ( 'divi/heading' === ( $finding['block'] ?? '' ) ) {
		$heading_findings[] = $finding['index'];
	}
}

assert_true(
	in_array( 2, $heading_findings, true ),
	'an exempt wrapper is skipped for CHECKS but still consumes an index — reporting positions must keep matching the read path'
);
