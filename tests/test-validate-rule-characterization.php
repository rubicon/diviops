<?php
// SPDX-License-Identifier: MIT
/**
 * Characterization net for every rule `validate_block_tree()` emits (#328).
 *
 * This is not a correctness test. It captures what the validator does today,
 * right or wrong, so that a later edit to `trait-validate.php` — an upstream
 * adoption in particular — cannot quietly change or drop a rule. Anything this
 * file pins that starts reporting differently is a real behaviour change and
 * wants a human decision, not an updated expectation.
 *
 * It exists because the file was almost entirely unpinned. Measured before it
 * was written: `trait-validate.php` emits 26 distinct finding codes, and only
 * two of them (`unknown_block_type`, `missing_builder_version`) were asserted
 * anywhere under `tests/` or `tests-live/`. Two more —
 * `responsive_flex_child_missing_full_width` and `flextype_wrong_path` —
 * appeared only inside a docblock in `tests/test-validate-parser-level-blocks.php`,
 * which is a mention, not coverage.
 *
 * What is pinned, per finding, is `(index, block, code)` in emission order,
 * plus the total block count. Message wording is deliberately not pinned: it is
 * operator-facing prose that is expected to be improved, whereas a code that
 * stops firing, fires on the wrong block, or lands at the wrong index is a
 * regression in the read/write index parity the rest of the plugin depends on.
 *
 * The fixture is append-only. Every block below owns a fixed index, and new
 * cases go on the end so existing expectations stay byte-identical — which is
 * what makes a diff of this file readable as "nothing was lost".
 *
 * The completeness guard at the bottom is the "assert the gate inspected
 * something" rule from `CLAUDE.md`: it fails when `trait-validate.php` emits a
 * code the fixture never trips, so a rule added later cannot slip in unpinned.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Build one parsed block in the shape `parse_blocks()` produces.
 *
 * Named apart from `tests/test-validate-parser-level-blocks.php`'s equivalent on
 * purpose: the runner requires every test file into one process, and a single
 * file can also be run alone via the filter argument, so neither file may depend
 * on the other having been loaded.
 *
 * @param string $name          Block name.
 * @param array  $attrs         Block attrs.
 * @param array  $inner         Inner blocks.
 * @param array  $inner_content Raw inner content fragments.
 * @return array
 */
function diviops_char_block( string $name, array $attrs = array(), array $inner = array(), array $inner_content = array() ): array {
	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => $inner,
		'innerContent' => $inner_content,
	);
}

/**
 * The characterization fixture: one block per rule cluster, in index order.
 *
 * @return array
 */
function diviops_char_fixture(): array {
	$bv = array( 'builderVersion' => '5.11.1' );

	return array(
		// index 1 (reported as $index + 1; a freeform block consumes no index):
		// malformed block comment carrying divi markup.
		diviops_char_block( '', array(), array(), array( '<!-- wp:divi/text -->broken' ) ),

		// index 1 + 2: the #288 exempt structural wrappers. Neither may report.
		diviops_char_block( 'divi/global-layout', array( 'globalModule' => '900296' ) ),
		diviops_char_block( 'divi/root', array() ),

		// index 3: container with no layout display and no flex properties.
		diviops_char_block( 'divi/section', $bv ),

		// index 4: heading with no explicit headingLevel.
		diviops_char_block( 'divi/heading', $bv ),

		// index 5: text module with no content at all.
		diviops_char_block( 'divi/text', $bv ),

		// index 6: button styled on the no-render-consumer path, padding on the
		// wrong bucket, and a plain-string innerContent.
		diviops_char_block(
			'divi/button',
			$bv + array(
				'button' => array(
					'decoration'   => array(
						'border'  => array( 'desktop' => array( 'value' => array( 'radius' => array( 'all' => '4px' ) ) ) ),
						'button'  => array( 'desktop' => array( 'value' => array( 'backgroundColor' => '#fff' ) ) ),
						'spacing' => array( 'desktop' => array( 'value' => array( 'padding' => array( 'top' => '10px' ) ) ) ),
					),
					'innerContent' => array( 'desktop' => array( 'value' => 'Click me' ) ),
				),
			)
		),

		// index 7: button whose content sits entirely on the content bucket.
		diviops_char_block(
			'divi/button',
			$bv + array(
				'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'text' => 'Go' ) ) ) ),
			)
		),

		// index 8: blurb with a string title and an icon that never renders.
		diviops_char_block(
			'divi/blurb',
			$bv + array(
				'title'     => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Plain string' ) ) ),
				'imageIcon' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'icon' => array( 'unicode' => '&#xe01d;' ) ) ) ) ),
			)
		),

		// index 9: contact field carrying an object where Divi requires a string.
		diviops_char_block(
			'divi/contact-field',
			$bv + array(
				'fieldItem' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'fieldId' => 'email' ) ) ) ),
			)
		),

		// index 10: the any-module rules — double-nested bodyFont, and flexType
		// on the layout bucket of a module that is not a column.
		diviops_char_block(
			'divi/code',
			$bv + array(
				'content' => array( 'decoration' => array( 'bodyFont' => array( 'bodyFont' => array( 'desktop' => array( 'value' => array( 'color' => '#000' ) ) ) ) ) ),
				'module'  => array( 'decoration' => array( 'layout' => array( 'desktop' => array( 'value' => array( 'flexType' => '12_24' ) ) ) ) ),
			)
		),

		// index 11: absolutely positioned group pinned centre-right with no
		// horizontal offset.
		diviops_char_block(
			'divi/group',
			$bv + array(
				'module' => array(
					'decoration' => array(
						'position' => array(
							'desktop' => array(
								'value' => array(
									'mode'   => 'absolute',
									'origin' => array( 'absolute' => 'center right' ),
									'offset' => array( 'vertical' => '0px' ),
								),
							),
						),
					),
				),
			)
		),

		// index 12 + 13: a flex row that stacks from tablet down, holding a
		// half-width child that never widens. The child warns once per stacking
		// breakpoint — tablet is declared, phone inherits it.
		diviops_char_block(
			'divi/row',
			$bv + array(
				'module' => array(
					'decoration' => array(
						'layout' => array(
							'desktop' => array(
								'value' => array(
									'display'       => 'flex',
									'flexDirection' => 'row',
								),
							),
							'tablet'  => array( 'value' => array( 'flexDirection' => 'column' ) ),
						),
					),
				),
			),
			array(
				diviops_char_block(
					'divi/text',
					$bv + array(
						'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => 'Half width' ) ) ),
						'module'  => array( 'decoration' => array( 'sizing' => array( 'desktop' => array( 'value' => array( 'flexType' => '12_24' ) ) ) ) ),
					)
				),
			)
		),

		// index 14: navigation link with elementType "li", an empty custom
		// attribute, and an aria-controls that resolves to nothing.
		//
		// Its content bucket is deliberately canonical — text/linkUrl/linkTarget
		// under content.innerContent, no `link` bucket and no legacy url/target
		// keys — so a rule added for those legacy shapes must leave this block's
		// findings untouched.
		diviops_char_block(
			'divi/link',
			$bv + array(
				'module'  => array(
					'advanced'   => array( 'html' => array( 'desktop' => array( 'value' => array( 'elementType' => 'li' ) ) ) ),
					'decoration' => array(
						'attributes' => array(
							'desktop' => array(
								'value' => array(
									'attributes' => array(
										array(
											'name'  => 'data-flag',
											'value' => '',
										),
										array(
											'name'  => 'aria-controls',
											'value' => 'panel-nowhere',
										),
									),
								),
							),
						),
					),
				),
				'content' => array(
					'innerContent' => array(
						'desktop' => array(
							'value' => array(
								'text'       => 'Home',
								'linkUrl'    => '/',
								'linkTarget' => 'off',
							),
						),
					),
				),
			)
		),

		// index 15: icon with a gradient that cannot render, a unit-string stop
		// position, and decoration on the non-editable inner ring.
		diviops_char_block(
			'divi/icon',
			$bv + array(
				'icon'   => array( 'decoration' => array( 'border' => array( 'desktop' => array( 'value' => array( 'radius' => array( 'all' => '50%' ) ) ) ) ) ),
				'module' => array(
					'decoration' => array(
						'background' => array(
							'desktop' => array( 'value' => array( 'gradient' => array( 'stops' => array( array( 'position' => '50%', 'color' => '#000' ) ) ) ) ),
						),
					),
				),
			)
		),

		// index 16: hover on the top-level bucket, and no builderVersion at all.
		diviops_char_block(
			'divi/image',
			array(
				'module' => array(
					'decoration' => array(
						'background' => array(
							'desktop' => array( 'value' => array( 'color' => '#fff' ) ),
							'hover'   => array( 'value' => array( 'color' => '#eee' ) ),
						),
					),
				),
			)
		),

		// index 17: a divi block genuinely absent from the registry, so the #288
		// exemption stays provably a list rather than a switch.
		diviops_char_block( 'divi/not-a-real-module', $bv ),
	);
}

/**
 * Walk the fixture and reduce each finding to its pinned triple.
 *
 * @return array{errors: array, warnings: array, total_blocks: int, codes: array}
 */
function diviops_char_findings(): array {
	$errors   = array();
	$warnings = array();
	$index    = 0;
	$nav_refs = null;

	$args = array(
		diviops_char_fixture(),
		WP_Block_Type_Registry::get_instance(),
		array( 'divi/section', 'divi/row', 'divi/column', 'divi/group', 'divi/group-carousel', 'divi/dropdown' ),
		&$errors,
		&$warnings,
		&$index,
		&$nav_refs,
		null,
	);
	diviops_call_ref( 'validate_block_tree', $args );

	$reduce = static function ( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array( $row['index'] ?? 0, $row['block'] ?? '', $row['code'] ?? '' );
		}
		return $out;
	};

	$codes = array();
	foreach ( array_merge( $errors, $warnings ) as $row ) {
		$codes[ $row['code'] ?? '' ] = true;
	}

	return array(
		'errors'       => $reduce( $errors ),
		'warnings'     => $reduce( $warnings ),
		'total_blocks' => $index,
		'codes'        => array_keys( $codes ),
	);
}

$found = diviops_char_findings();

// ── Block accounting ────────────────────────────────────────────────────────

assert_same(
	17,
	$found['total_blocks'],
	'the walk consumes one index per named block and none for the freeform one — reported positions must keep matching the read path'
);

// ── Errors, in emission order ───────────────────────────────────────────────

assert_same(
	array(
		array( 1, '(freeform)', 'parse_failure' ),
		array( 3, 'divi/section', 'unknown_block_type' ),
		array( 4, 'divi/heading', 'unknown_block_type' ),
		array( 5, 'divi/text', 'unknown_block_type' ),
		array( 5, 'divi/text', 'empty_text_module' ),
		array( 6, 'divi/button', 'unknown_block_type' ),
		array( 6, 'divi/button', 'button_innercontent_string' ),
		array( 7, 'divi/button', 'unknown_block_type' ),
		array( 7, 'divi/button', 'button_content_wrong_bucket' ),
		array( 8, 'divi/blurb', 'unknown_block_type' ),
		array( 8, 'divi/blurb', 'blurb_title_string' ),
		array( 8, 'divi/blurb', 'blurb_icon_missing_use_icon' ),
		array( 9, 'divi/contact-field', 'unknown_block_type' ),
		array( 9, 'divi/contact-field', 'field_item_content_object' ),
		array( 10, 'divi/code', 'unknown_block_type' ),
		array( 10, 'divi/code', 'body_font_double_nested' ),
		array( 11, 'divi/group', 'unknown_block_type' ),
		array( 12, 'divi/row', 'unknown_block_type' ),
		array( 13, 'divi/text', 'unknown_block_type' ),
		array( 14, 'divi/link', 'unknown_block_type' ),
		array( 14, 'divi/link', 'nav_link_elementtype_li' ),
		array( 15, 'divi/icon', 'unknown_block_type' ),
		array( 16, 'divi/image', 'unknown_block_type' ),
		array( 16, 'divi/image', 'missing_builder_version' ),
		array( 17, 'divi/not-a-real-module', 'unknown_block_type' ),
	),
	$found['errors'],
	'every error rule fires on the block and index it fires on today'
);

// ── Warnings, in emission order ─────────────────────────────────────────────
//
// The nav aria-controls warning lands last rather than in index order: it is
// resolved once, after the whole tree is walked, because the id it looks for
// may be declared by a later sibling.

assert_same(
	array(
		array( 3, 'divi/section', 'missing_layout_display' ),
		array( 4, 'divi/heading', 'heading_missing_level' ),
		array( 6, 'divi/button', 'button_no_render_consumer' ),
		array( 6, 'divi/button', 'button_missing_icon_enable' ),
		array( 6, 'divi/button', 'button_padding_wrong_path' ),
		array( 10, 'divi/code', 'flextype_on_non_column' ),
		array( 10, 'divi/code', 'flextype_wrong_path' ),
		array( 11, 'divi/group', 'missing_layout_display' ),
		array( 11, 'divi/group', 'absolute_center_right_missing_horizontal_offset' ),
		array( 13, 'divi/text', 'responsive_flex_child_missing_full_width' ),
		array( 13, 'divi/text', 'responsive_flex_child_missing_full_width' ),
		array( 14, 'divi/link', 'nav_empty_custom_attribute' ),
		array( 15, 'divi/icon', 'gradient_missing_enabled' ),
		array( 15, 'divi/icon', 'gradient_string_position' ),
		array( 15, 'divi/icon', 'icon_decoration_not_editable' ),
		array( 16, 'divi/image', 'hover_wrong_path' ),
		array( 14, 'divi/link', 'nav_unresolved_aria_controls' ),
	),
	$found['warnings'],
	'every warning rule fires on the block and index it fires on today'
);

// ── The #288 exemption, restated against the full fixture ───────────────────

$exempt_findings = array();
foreach ( array_merge( $found['errors'], $found['warnings'] ) as $triple ) {
	if ( in_array( $triple[1], array( 'divi/global-layout', 'divi/root' ), true ) ) {
		$exempt_findings[] = $triple;
	}
}

assert_same(
	array(),
	$exempt_findings,
	'divi/global-layout and divi/root report nothing anywhere in a fully populated tree (#288)'
);

// ── The gate must prove it inspected something ──────────────────────────────
//
// Codes are read back out of the trait rather than hardcoded, so a rule added
// to trait-validate.php without a fixture case fails here instead of shipping
// unpinned.

$trait_source = file_get_contents( __DIR__ . '/../plugins/diviops-agent/includes/trait-validate.php' );

assert_true(
	is_string( $trait_source ) && '' !== $trait_source,
	'the trait source was read, so the completeness guard below is comparing against something'
);

preg_match_all( "/'code'\s*=>\s*'([^']+)'/", (string) $trait_source, $matches );
$emitted = array_values( array_unique( $matches[1] ) );
sort( $emitted );

$exercised = $found['codes'];
sort( $exercised );

assert_true(
	count( $emitted ) > 20,
	'the code scan found the validator rule set, not an empty match'
);

assert_same(
	array(),
	array_values( array_diff( $emitted, $exercised ) ),
	'every finding code trait-validate.php can emit is tripped by this fixture — a new rule must arrive with a characterization case'
);
