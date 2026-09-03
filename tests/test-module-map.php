<?php
// SPDX-License-Identifier: MIT
/**
 * The batched per-module map artifact (#385).
 *
 * `scripts/extract-module-preset-paths.php` (#119) answers one module at a time and
 * prints text. This suite covers the batching layer that turns it into a committed,
 * queryable artifact, plus the second source it is cross-checked against.
 *
 * Three failure shapes are what this exists to catch, and each is asserted directly
 * rather than inferred from a green run:
 *
 * 1. **A silently smaller artifact.** A regeneration that loses a module, or loses
 *    paths from one, must refuse to write. A generated artifact whose gate only
 *    reports what it inspected would pass while shrinking to nothing.
 * 2. **A types parse that finds nothing.** `@divi/types` is read as text. A parser
 *    that returns an empty element map for a file it did not understand hands the
 *    disagreement report a false negative, so it throws instead.
 * 3. **An indexed module that no map file actually serves.** `resolve()` throws on
 *    those by design; a batcher that lets the exception escape produces no artifact
 *    at all, and one that swallows it silently drops the module. It is recorded.
 *
 * Everything runs against hand-authored fixtures under tests/fixtures/module-map/
 * and tests/fixtures/preset-attrs-map/. Neither Divi nor `@divi/types` is needed,
 * because CI has neither. The committed artifact itself is checked for internal
 * consistency, which needs neither source either.
 *
 * @package DiviOps
 */

require_once dirname( __DIR__ ) . '/scripts/lib/module-map.php';

$packages   = __DIR__ . '/fixtures/preset-attrs-map/Packages';
$types_root = __DIR__ . '/fixtures/module-map/types';

/**
 * Run a callable and return the exception message it threw, or '' when it did not.
 *
 * @param callable $callback Callback expected to throw.
 */
function diviops_module_map_test_thrown( callable $callback ): string {
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		return $error->getMessage();
	}
	return '';
}

/*
 * ── The `@divi/types` element parser ────────────────────────────────────────
 *
 * A module's types file declares the element map and, inline, the decoration
 * groups each element picks. Reading it is text work, so every way of reading it
 * wrongly is asserted against, not just the happy path.
 */

$cta_elements = diviops_module_map_parse_types(
	(string) file_get_contents( $types_root . '/src/module/library/fixture-cta-shaped/index.ts' ),
	'fixture-cta-shaped'
);

assert_same(
	array( 'button', 'contentContainer', 'css', 'module', 'title' ),
	array_keys( $cta_elements ),
	'the element map is the members of the interface extending InternalAttrs, sorted'
);
assert_same(
	array( 'animation', 'spacing', 'zIndex' ),
	$cta_elements['module']['decoration_groups'],
	'a multi-line PickedAttributes<> union is read as that element decoration groups'
);
assert_same(
	array( 'button', 'sizing' ),
	$cta_elements['button']['decoration_groups'],
	'PickedAttributes<> inside an intersection type is still found'
);

/*
 * The decoy interface in the same file picks 'neverMine'. A parser that scanned the
 * whole file rather than one interface body would leak it into some element.
 */
$leaked = array();
foreach ( $cta_elements as $key => $element ) {
	if ( in_array( 'neverMine', $element['decoration_groups'], true ) ) {
		$leaked[] = $key;
	}
}
assert_same(
	array(),
	$leaked,
	'a PickedAttributes<> in a sibling interface never leaks into the module element map'
);

/*
 * `Wrapper.Attributes<'sizing'>` is a generic carrying a string literal that is not a
 * decoration pick. Reading every quoted string inside every generic would report it
 * as one.
 */
assert_same(
	array(),
	$cta_elements['contentContainer']['decoration_groups'],
	'a non-PickedAttributes generic carrying string literals contributes no decoration groups'
);

/*
 * An element whose decoration groups live behind a type name yields no groups, but
 * the reference is kept so a later pass can resolve it. Absent and unresolved are
 * different answers and the artifact must not conflate them.
 */
assert_same(
	array(),
	$cta_elements['title']['decoration_groups'],
	'a reference-only element declares no inline decoration groups'
);
assert_same(
	'Element.Types.TitleLink.Attributes',
	$cta_elements['title']['type_ref'],
	'a reference-only element keeps the type name its groups live behind'
);
assert_same(
	null,
	$cta_elements['module']['type_ref'],
	'an element declared as an inline object literal has no type reference'
);

/*
 * A file the parser did not understand must fail loudly. Returning an empty element
 * map would report every one of that module's paths as absent from types — a
 * disagreement list built entirely out of the parser's own silence.
 */
/*
 * A member the walk does not understand must throw rather than land in the element
 * map as a plausible-looking key. Every wrong element key becomes either a missed
 * disagreement or an invented one, and both read as findings about Divi.
 */
$bad_member = diviops_module_map_test_thrown(
	static function () {
		diviops_module_map_parse_types(
			"export interface XAttrs extends InternalAttrs {\n  a b c: string;\n}\n",
			'fixture-bad-member'
		);
	}
);
assert_true(
	false !== strpos( $bad_member, 'not a plain identifier' ),
	'a member key the walk cannot read as an identifier throws instead of being recorded'
);

$no_interface = diviops_module_map_test_thrown(
	static function () {
		diviops_module_map_parse_types( "export type Nope = string;\n", 'fixture-broken' );
	}
);
assert_true(
	false !== strpos( $no_interface, 'fixture-broken' ),
	'a types file with no InternalAttrs interface throws, naming the module it came from'
);

/*
 * ── Discovery ───────────────────────────────────────────────────────────────
 */

$types_index = diviops_module_map_types_index( $types_root );

assert_same(
	array( 'fixture-composed', 'fixture-cta-shaped', 'fixture-types-only' ),
	array_keys( $types_index ),
	'the types index keys module type files by their directory, not by a guessed block name'
);
assert_same(
	'9.9.9-fixture',
	diviops_module_map_types_version( $types_root ),
	'the package version is read from the package own package.json, never passed in by hand'
);

$empty_index = diviops_module_map_test_thrown(
	static function () use ( $types_root ) {
		diviops_module_map_types_index( $types_root . '/src/module' );
	}
);
assert_true(
	false !== strpos( $empty_index, 'no module' ),
	'a types root holding no module directories throws rather than reporting an empty index'
);

/*
 * ── The build ───────────────────────────────────────────────────────────────
 */

$artifact = diviops_module_map_build( $packages, $types_root, '9.9.9' );

assert_same( '9.9.9', $artifact['sources']['divi']['version'], 'the Divi version is stamped' );
assert_same(
	'9.9.9-fixture',
	$artifact['sources']['divi_types']['version'],
	'the @divi/types version is stamped'
);
assert_true(
	1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $artifact['generated_at'] ),
	'the generation date is stamped'
);

assert_true(
	isset( $artifact['modules']['divi/fixture-cta-shaped'] ),
	'a module with both a preset map and a types file is in the artifact'
);

$cta = $artifact['modules']['divi/fixture-cta-shaped'];

assert_same(
	array(
		'button.decoration.button__icon.enable',
		'button.decoration.sizing__width',
		'button.innerContent__text',
		'title.innerContent',
	),
	$cta['paths'],
	'the module resolved leaf paths are stored'
);
assert_true(
	count( $cta['invalidates'] ) > 0,
	'the invalidates set is stored, not discarded'
);
assert_true(
	in_array( 'button.decoration.button.decoration.background__color', $cta['invalidates'], true ),
	'a key the map proves it strips is recorded as invalidated rather than documented as valid'
);
assert_true(
	array() === array_intersect( $cta['paths'], $cta['invalidates'] ),
	'no key is reported as both valid and invalidated'
);
assert_same(
	array( 'animation', 'spacing', 'zIndex' ),
	$cta['elements']['module']['decoration_groups'],
	'the element map from @divi/types rides alongside the paths from the preset map'
);

/*
 * ── Disagreements ───────────────────────────────────────────────────────────
 *
 * The two sources answer different questions, so most of the time they simply do
 * not overlap. What is worth recording is where one makes a claim the other
 * contradicts, and neither is declared the winner.
 */

$composed = $artifact['modules']['divi/fixture-composed'];

assert_true(
	in_array(
		array(
			'kind'    => 'element_absent_from_types',
			'element' => 'title',
		),
		$composed['disagreements'],
		true
	),
	'an element the preset map roots paths at, that @divi/types does not declare, is recorded'
);
assert_same(
	array(),
	$cta['disagreements'],
	'a module whose path roots are all declared in types records no disagreement'
);

/*
 * Both of Divi's path separators can end the first segment: `.` descends into a
 * sub-object, `__` names a sub-key of the element itself. Splitting on `.` alone read
 * `css__freeForm` as an element name and reported 188 disagreements against real Divi
 * that were purely an artifact of the split — a disagreement channel is only worth
 * having if it is not mostly noise from its own parsing.
 */
$css_element = array( 'css' => array( 'decoration_groups' => array(), 'type_ref' => 'Css.Attributes' ) );

assert_same(
	array(),
	diviops_module_map_disagreements( array( 'css__freeForm', 'css__mainElement' ), $css_element ),
	'a path rooted with __ rather than . resolves to the element before the __'
);
assert_same(
	array( array( 'kind' => 'element_absent_from_types', 'element' => 'ghost' ) ),
	diviops_module_map_disagreements( array( 'ghost__mainElement' ), $css_element ),
	'a __ rooted path at an undeclared element is still a disagreement'
);
/*
 * No path on Divi 5.11.1 carries a `__` before its first `.`, so nothing in the real
 * artifact exercises the case where both separators are present and `__` comes first.
 * Asserted anyway: whichever separator comes first ends the root is the contract, and
 * a shape Divi has not shipped yet would otherwise take a silently wrong root.
 */
assert_same(
	array(),
	diviops_module_map_disagreements( array( 'css__slideImage.decoration.font' ), $css_element ),
	'when both separators are present the earlier one ends the root, whichever it is'
);
assert_same(
	array(),
	diviops_module_map_disagreements( array( 'anything' ), null ),
	'a module with no types file has nothing to disagree with'
);

assert_true(
	in_array( 'divi/fixture-add-only', $artifact['modules_without_types'], true ),
	'a module with a preset map and no types file is recorded, not dropped'
);
assert_same(
	null,
	$artifact['modules']['divi/fixture-add-only']['elements'],
	'a module with no types file has a null element map, distinct from an empty one'
);
assert_same(
	array( 'fixture-types-only' ),
	$artifact['modules_without_preset_map'],
	'a module @divi/types declares that has no preset map is recorded'
);

/*
 * An indexed name no map file serves throws inside resolve(). The batcher records it
 * and carries on; letting it escape would produce no artifact, and swallowing it
 * would drop the module without trace.
 */
assert_true(
	isset( $artifact['unserved']['divi/fixture-early-return-decoy'] ),
	'an indexed module name no map file serves is recorded as unserved'
);
assert_true(
	! isset( $artifact['modules']['divi/fixture-early-return-decoy'] ),
	'an unserved module is not listed among the resolved modules'
);
assert_true(
	'' !== $artifact['unserved']['divi/fixture-early-return-decoy'],
	'the reason a module could not be resolved is kept'
);

/*
 * ── Counts ──────────────────────────────────────────────────────────────────
 *
 * The counts are what the shrink gate compares, so they have to be derived from the
 * contents rather than asserted alongside them.
 */

$paths_total = 0;
$inval_total = 0;
foreach ( $artifact['modules'] as $entry ) {
	$paths_total += count( $entry['paths'] );
	$inval_total += count( $entry['invalidates'] );
}

assert_same( count( $artifact['modules'] ), $artifact['counts']['modules'], 'the module count matches the modules' );
assert_same( $paths_total, $artifact['counts']['paths'], 'the path count matches the paths' );
assert_same( $inval_total, $artifact['counts']['invalidates'], 'the invalidates count matches the invalidates' );

/*
 * ── The shrink gate ─────────────────────────────────────────────────────────
 *
 * A regeneration producing less than the artifact already on disk is the failure
 * this project has been bitten by three times: a gate that reports what it inspected
 * but derives pass or fail only from problems found. Growth is silent; every kind of
 * loss is a reason, and all of them are reported at once rather than the first.
 */

assert_same(
	array(),
	diviops_module_map_shrink_report( $artifact, $artifact ),
	'regenerating the same artifact reports no shrink'
);

$grown = $artifact;
$grown['modules']['divi/fixture-add-only']['paths'][] = 'title.decoration.font__weight';
$grown['counts']['paths']++;
assert_same(
	array(),
	diviops_module_map_shrink_report( $artifact, $grown ),
	'an artifact that gained a path reports no shrink'
);

$lost_module = $artifact;
unset( $lost_module['modules']['divi/fixture-add-only'] );
$lost_module['counts']['modules']--;
$lost_module['counts']['paths'] -= count( $artifact['modules']['divi/fixture-add-only']['paths'] );
$reasons = diviops_module_map_shrink_report( $artifact, $lost_module );
assert_true(
	array() !== $reasons,
	'an artifact that lost a module entirely is a shrink'
);
assert_true(
	false !== strpos( implode( "\n", $reasons ), 'divi/fixture-add-only' ),
	'the shrink report names the module that disappeared'
);

$lost_paths = $artifact;
array_pop( $lost_paths['modules']['divi/fixture-cta-shaped']['paths'] );
$lost_paths['counts']['paths']--;
$reasons = diviops_module_map_shrink_report( $artifact, $lost_paths );
assert_true(
	false !== strpos( implode( "\n", $reasons ), 'divi/fixture-cta-shaped' ),
	'a module that kept its entry but lost paths is a shrink naming that module'
);

$lost_invalidates = $artifact;
array_pop( $lost_invalidates['modules']['divi/fixture-cta-shaped']['invalidates'] );
$lost_invalidates['counts']['invalidates']--;
assert_true(
	array() !== diviops_module_map_shrink_report( $artifact, $lost_invalidates ),
	'losing invalidated keys is a shrink too, because they are half of what the extractor knows'
);

$lost_elements               = $artifact;
$lost_elements['modules']['divi/fixture-cta-shaped']['elements'] = null;
$lost_elements['counts']['modules_without_types']++;
assert_true(
	array() !== diviops_module_map_shrink_report( $artifact, $lost_elements ),
	'a module that lost its element map — the whole @divi/types side going quiet — is a shrink'
);

/*
 * The totals are checked separately from the per-module walk, because a previous
 * artifact that was truncated — counts intact, `modules` gone — presents nothing for
 * the per-module walk to compare and would otherwise pass as growth.
 */
assert_true(
	array() !== diviops_module_map_shrink_report(
		array(
			'modules' => array(),
			'counts'  => array(
				'modules'     => 66,
				'paths'       => 16446,
				'invalidates' => 5130,
			),
		),
		$artifact
	),
	'a previous artifact whose counts alone are larger is a shrink, with no per-module evidence to lean on'
);

/*
 * ── The committed artifact ──────────────────────────────────────────────────
 *
 * Runs in CI, where neither Divi nor @divi/types is installed. It cannot detect that
 * Divi shipped an update; it detects that the file on disk is internally consistent
 * with the counts it states, so a hand edit cannot quietly disagree with them.
 */

$artifact_file = dirname( __DIR__ ) . '/diviops-server/data/module-map.json';

assert_true( is_file( $artifact_file ), 'the committed module map exists where the MCP tool reads it' );

$committed = json_decode( (string) file_get_contents( $artifact_file ), true );

assert_true( is_array( $committed ), 'the committed module map parses as JSON' );
assert_true(
	isset( $committed['modules'] ) && count( $committed['modules'] ) > 0,
	'the committed module map covers at least one module, so this gate is not inspecting nothing'
);

$committed_paths = 0;
$committed_inval = 0;
$unsorted        = array();
$overlapping     = array();
foreach ( $committed['modules'] as $name => $entry ) {
	$committed_paths += count( $entry['paths'] );
	$committed_inval += count( $entry['invalidates'] );

	$sorted = $entry['paths'];
	sort( $sorted );
	if ( $sorted !== $entry['paths'] || count( array_unique( $entry['paths'] ) ) !== count( $entry['paths'] ) ) {
		$unsorted[] = $name;
	}
	if ( array() !== array_intersect( $entry['paths'], $entry['invalidates'] ) ) {
		$overlapping[] = $name;
	}
}

assert_same(
	count( $committed['modules'] ),
	$committed['counts']['modules'],
	'the committed module count matches the modules actually present'
);
assert_same(
	$committed_paths,
	$committed['counts']['paths'],
	'the committed path count matches the paths actually present'
);
assert_same(
	$committed_inval,
	$committed['counts']['invalidates'],
	'the committed invalidates count matches the invalidates actually present'
);
assert_same(
	array(),
	$unsorted,
	'every committed module path list is sorted and free of duplicates, so version diffs stay readable'
);
assert_same(
	array(),
	$overlapping,
	'no committed module reports a key as both valid and invalidated'
);
assert_true(
	'' !== (string) $committed['sources']['divi']['version'],
	'the committed artifact names the Divi version it was generated against'
);
assert_true(
	'' !== (string) $committed['sources']['divi_types']['version'],
	'the committed artifact names the @divi/types version it was cross-checked against'
);
