<?php
// SPDX-License-Identifier: MIT
/**
 * The shared-family preset-attrs resolver.
 *
 * A shared family map under `Module/Options/` is not a flat table of its own subfields.
 * Divi's `ButtonPresetAttrsMap` contributes six keys of its own and delegates the other
 * 143 to seven sibling family maps, so the vocabulary a module actually gets from
 * `button` is only visible by running `get_map()`. The failure this suite exists to
 * catch is the mirror of the per-module one: not "no paths came out" but "paths that
 * exist were never seen", because a text scan reads only the keys spelled in the file.
 *
 * Everything here runs against hand-authored fixtures under
 * tests/fixtures/preset-attrs-map/. The real Divi tree is exercised too, but only when
 * DIVIOPS_DIVI_BUILDER5_PATH points at one, because CI has no Divi install.
 *
 * @package DiviOps
 */

require_once dirname( __DIR__ ) . '/scripts/lib/preset-attrs-map.php';

$shared_fixtures = __DIR__ . '/fixtures/preset-attrs-map/Packages';

/**
 * Run a callable and return the exception message it threw, or '' when it did not.
 *
 * @param callable $callback Callback expected to throw.
 */
function diviops_test_shared_thrown_message( callable $callback ): string {
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		return $error->getMessage();
	}
	return '';
}

/*
 * The index. Groups are named after the map class rather than its directory, because
 * Divi puts three unrelated family maps in `Module/Options/FormField/` and a directory
 * key would silently drop two of them. It was two until Divi 5.11.0 added
 * `NativeChoicePresetAttrsMap.php` alongside them — the collision this index was
 * designed for is getting worse, not going away.
 */
$shared_index = diviops_preset_attrs_map_shared_index( $shared_fixtures );

assert_true(
	isset( $shared_index['FixtureFamily'] ),
	'the shared index finds a family map by its class name'
);
assert_true(
	isset( $shared_index['FixtureComposite'] ) && isset( $shared_index['FixtureAbsolute'] ),
	'the shared index finds every family map under Module/Options'
);
assert_true(
	false !== strpos(
		diviops_test_shared_thrown_message(
			static function () {
				diviops_preset_attrs_map_shared_index( __DIR__ );
			}
		),
		'no shared PresetAttrsMap'
	),
	'a Packages root with no family maps is an error, not an empty index'
);

/* A family keys its whole return on the prefix it is handed. */
$fixture_family = diviops_preset_attrs_map_shared_resolve( $shared_fixtures, 'FixtureFamily', 'title.decoration' );

assert_same(
	array( 'title.decoration.fixtureFamily__color', 'title.decoration.fixtureFamily__intensity' ),
	$fixture_family['keys'],
	'a family map resolves its subfields under the prefix it is given'
);
assert_same( false, $fixture_family['parameterless'], 'a family map taking a prefix is not parameterless' );
assert_same( 'title.decoration', $fixture_family['attr'], 'the resolved result reports the prefix it was run with' );

assert_same(
	array( 'button.decoration.fixtureFamily__color', 'button.decoration.fixtureFamily__intensity' ),
	diviops_preset_attrs_map_shared_resolve( $shared_fixtures, 'FixtureFamily', 'button.decoration' )['keys'],
	'the same family under a second prefix yields the same vocabulary rekeyed'
);

/*
 * The regression this resolver exists for. A composite family delegates most of its
 * vocabulary to sibling maps, so the delegated keys are nowhere in its own source.
 */
$composite = diviops_preset_attrs_map_shared_resolve( $shared_fixtures, 'FixtureComposite', 'button' );

assert_same(
	array(
		'button.decoration.fixtureComposite__mode',
		'button.decoration.fixtureFamily__color',
		'button.decoration.fixtureFamily__intensity',
		'button.label.fixtureFamily__color',
		'button.label.fixtureFamily__intensity',
	),
	$composite['keys'],
	'a composite family resolves its own keys plus every key its delegates contribute'
);

$composite_source = (string) file_get_contents(
	$shared_fixtures . '/Module/Options/FixtureComposite/FixtureCompositePresetAttrsMap.php'
);
preg_match_all( '/[\'"]([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+(?:__[A-Za-z0-9_.]+)?)[\'"]/', $composite_source, $scanned );
$naively_scanned = array_values( array_unique( $scanned[1] ) );

foreach (
	array(
		'button.decoration.fixtureFamily__color',
		'button.decoration.fixtureFamily__intensity',
		'button.label.fixtureFamily__color',
		'button.label.fixtureFamily__intensity',
	) as $delegated_key
) {
	assert_true(
		! in_array( $delegated_key, $naively_scanned, true ),
		sprintf( 'a quoted-string scan of the composite fixture cannot see the delegated key %s', $delegated_key )
	);
	assert_true(
		in_array( $delegated_key, $composite['keys'], true ),
		sprintf( 'the resolver does see the delegated key %s', $delegated_key )
	);
}

/*
 * Three of Divi's own family maps take no argument and return absolute paths.
 * `VisibilitySettingsPresetAttrsMap` is one. Supplying a prefix to one of those would
 * be reporting a prefix that had no effect, so it is refused rather than ignored.
 */
$absolute = diviops_preset_attrs_map_shared_resolve( $shared_fixtures, 'FixtureAbsolute' );

assert_same( true, $absolute['parameterless'], 'a family map taking no prefix is reported as parameterless' );
assert_same( '', $absolute['attr'], 'a parameterless family map reports no prefix' );
assert_same(
	array( 'module.decoration.fixtureAbsolute__x', 'module.decoration.fixtureAbsolute__y' ),
	$absolute['keys'],
	'a parameterless family map resolves to the absolute paths it declares'
);
assert_true(
	false !== strpos(
		diviops_test_shared_thrown_message(
			static function () use ( $shared_fixtures ) {
				diviops_preset_attrs_map_shared_resolve( $shared_fixtures, 'FixtureAbsolute', 'module.decoration' );
			}
		),
		'takes no attribute prefix'
	),
	'passing a prefix to a parameterless family map is refused rather than silently ignored'
);

/* A prefix is required for a map that takes one; an empty one would produce junk keys. */
assert_true(
	false !== strpos(
		diviops_test_shared_thrown_message(
			static function () use ( $shared_fixtures ) {
				diviops_preset_attrs_map_shared_resolve( $shared_fixtures, 'FixtureFamily' );
			}
		),
		'requires an attribute prefix'
	),
	'omitting the prefix for a family map that takes one is an error'
);

/* An unknown family name is an error, not an empty result. */
assert_true(
	false !== strpos(
		diviops_test_shared_thrown_message(
			static function () use ( $shared_fixtures ) {
				diviops_preset_attrs_map_shared_resolve( $shared_fixtures, 'NoSuchFamily', 'module.decoration' );
			}
		),
		'NoSuchFamily'
	),
	'a family with no map file is an error naming that family'
);

/*
 * And a map that returns nothing is a failure rather than a result. Every one of the 47
 * families Divi ships contributes at least one key, so an empty return means the class
 * was not exercised the way it expects.
 */
assert_true(
	false !== strpos(
		diviops_test_shared_thrown_message(
			static function () use ( $shared_fixtures ) {
				diviops_preset_attrs_map_shared_resolve( $shared_fixtures, 'FixtureEmptyFamily', 'module.decoration' );
			}
		),
		'resolved to zero keys'
	),
	'a family map that returns no keys fails rather than reporting an empty vocabulary'
);

/*
 * The real Divi tree, when one is available. Skipped in CI, which has no Divi install,
 * so nothing above depends on it. These are the exact path sets
 * skills/divi-5-builder/references/advanced-attributes.md documents for the Tier 2
 * families, asserted against the source they were derived from.
 */
$shared_builder5 = getenv( 'DIVIOPS_DIVI_BUILDER5_PATH' );

if ( is_string( $shared_builder5 ) && '' !== $shared_builder5 && is_dir( $shared_builder5 . '/server/Packages/Module/Options' ) ) {
	$shared_divi_packages = $shared_builder5 . '/server/Packages';
	$shared_divi_index    = diviops_preset_attrs_map_shared_index( $shared_divi_packages );

	// Pinned deliberately. A count that floats to whatever is on disk asserts nothing,
	// so this is expected to fail on a Divi upgrade — that failure is the signal to go
	// look at what changed, not a reason to soften the assertion. 5.9.0 shipped 46;
	// 5.11.0 added NativeChoice.
	assert_same(
		47,
		count( $shared_divi_index ),
		'Divi 5.11.0 ships 47 shared family maps under Module/Options'
	);
	assert_true(
		isset( $shared_divi_index['FieldDecoration'] )
			&& isset( $shared_divi_index['FormField'] )
			&& isset( $shared_divi_index['NativeChoice'] ),
		'all three map files in Module/Options/FormField are indexed, which a directory-keyed index would not do'
	);

	$unresolvable_families = array();

	foreach ( $shared_divi_index as $family => $unused_file ) {
		try {
			$probe = diviops_preset_attrs_map_shared_resolve( $shared_divi_packages, $family, 'diviops.probe' );
		} catch ( RuntimeException $error ) {
			try {
				$probe = diviops_preset_attrs_map_shared_resolve( $shared_divi_packages, $family );
			} catch ( RuntimeException $inner ) {
				$unresolvable_families[] = $family;
			}
		}
	}

	assert_same( array(), $unresolvable_families, 'every shared family Divi ships resolves' );

	assert_same(
		array(
			'module.decoration.icon__color',
			'module.decoration.icon__size',
			'module.decoration.icon__useSize',
		),
		diviops_preset_attrs_map_shared_resolve( $shared_divi_packages, 'Icon', 'module.decoration.icon' )['keys'],
		'the real Icon family resolves to the three paths advanced-attributes.md documents'
	);

	assert_same(
		array(
			'module.decoration.font.textShadow__blur',
			'module.decoration.font.textShadow__color',
			'module.decoration.font.textShadow__horizontal',
			'module.decoration.font.textShadow__style',
			'module.decoration.font.textShadow__vertical',
		),
		diviops_preset_attrs_map_shared_resolve( $shared_divi_packages, 'TextShadow', 'module.decoration.font.textShadow' )['keys'],
		'the real TextShadow family resolves to the five paths advanced-attributes.md documents'
	);

	$divi_font = diviops_preset_attrs_map_shared_resolve( $shared_divi_packages, 'Font', 'module.decoration.font' );

	assert_same(
		44,
		count( $divi_font['keys'] ),
		'the real Font family resolves to 44 keys: 20 of its own plus TextEffects and TextShadow'
	);

	/*
	 * The doubled `font.font` is not a typo. The family is handed the element's font
	 * attribute and appends its own `.font` segment, which is why every real module map
	 * spells the leaf as `<element>.decoration.font.font__<subField>`.
	 */
	foreach (
		array(
			'module.decoration.font.font__family',
			'module.decoration.font.font__weightFineTune',
			'module.decoration.font.font__writingMode',
			'module.decoration.font.textEffects__fillType',
			'module.decoration.font.textShadow__style',
		) as $font_path
	) {
		assert_true(
			in_array( $font_path, $divi_font['keys'], true ),
			sprintf( 'the real Font family emits its documented path %s', $font_path )
		);
	}

	$divi_button = diviops_preset_attrs_map_shared_resolve( $shared_divi_packages, 'Button', 'button' );

	assert_same(
		149,
		count( $divi_button['keys'] ),
		'the real Button family resolves to 149 keys, six of its own and the rest delegated'
	);

	foreach (
		array(
			'button.decoration.button__icon.enable',
			'button.decoration.button__icon.settings',
			'button.decoration.button__icon.color',
			'button.decoration.button__icon.placement',
			'button.decoration.button__icon.onHover',
			'button.decoration.sizing__alignment',
			'button.innerContent__text',
			'button.innerContent__linkUrl',
			'button.innerContent__linkTarget',
			'button.innerContent__rel',
		) as $button_path
	) {
		assert_true(
			in_array( $button_path, $divi_button['keys'], true ),
			sprintf( 'the real Button family emits its documented path %s', $button_path )
		);
	}

	/*
	 * The delegated half of Button, and the reason the resolver runs the map instead of
	 * reading it: `button.decoration.font__size` is contributed by FontPresetAttrsMap and
	 * appears nowhere in ButtonPresetAttrsMap.php's own text.
	 */
	assert_true(
		in_array( 'button.decoration.font.font__size', $divi_button['keys'], true ),
		'the real Button family emits a delegated Font path'
	);
	assert_true(
		false === strpos(
			(string) file_get_contents( $shared_divi_index['Button'] ),
			'font__size'
		),
		'that delegated Font path is not spelled anywhere in ButtonPresetAttrsMap.php, so a text scan would miss it'
	);
}
