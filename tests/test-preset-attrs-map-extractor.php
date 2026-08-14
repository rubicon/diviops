<?php
/**
 * The merge-aware per-module preset-attrs extractor.
 *
 * A per-module `PresetAttrsMap.php` both adds and removes keys. Divi's CTA map unsets
 * 151 keys and merges 161 back, and the unset ones sit in the file as quoted strings
 * indistinguishable, to a text scanner, from the valid ones. So the failure this suite
 * exists to catch is not "no paths came out" but "paths came out that the module's own
 * source says are invalid".
 *
 * Everything here runs against hand-authored fixtures under
 * tests/fixtures/preset-attrs-map/. The real Divi tree is exercised too, but only when
 * DIVIOPS_DIVI_BUILDER5_PATH points at one, because CI has no Divi install.
 *
 * @package DiviOps
 */

require_once dirname( __DIR__ ) . '/scripts/lib/preset-attrs-map.php';

$fixtures = __DIR__ . '/fixtures/preset-attrs-map/Packages';

/**
 * Resolve a fixture module and return its final key list.
 *
 * @param string $packages    Packages root.
 * @param string $module_name Module name.
 * @param array  $base        Base map keys.
 */
function diviops_test_final_keys( string $packages, string $module_name, array $base = array() ): array {
	$resolved = diviops_preset_attrs_map_resolve( $packages, $module_name, $base );
	return $resolved['final'];
}

/**
 * Run a callable and return the exception message it threw, or '' when it did not.
 *
 * @param callable $callback Callback expected to throw.
 */
function diviops_test_thrown_message( callable $callback ): string {
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		return $error->getMessage();
	}
	return '';
}

/*
 * The index. Discovery has to find every map file and every module name declared in
 * one, including a file that serves two names.
 */
$index = diviops_preset_attrs_map_index( $fixtures );

assert_true(
	isset( $index['divi/fixture-add-only'] ),
	'the index finds a module name declared in a map file'
);
assert_true(
	isset( $index['divi/fixture-two-names'] ) && isset( $index['divi/fixture-two-names-alt'] ),
	'one map file serving two module names is indexed under both'
);
assert_same(
	$index['divi/fixture-two-names'],
	$index['divi/fixture-two-names-alt'],
	'both names of a two-name map resolve to the same file'
);
assert_true(
	isset( $index['divi/fixture-early-return-decoy'] ),
	'a module name quoted outside the guard is still indexed, which is what makes the early return reachable'
);

/*
 * A scan that finds nothing must fail rather than report an empty result, the same
 * discipline tests/run.php applies to its own file discovery.
 */
assert_true(
	false !== strpos(
		diviops_test_thrown_message(
			static function () {
				diviops_preset_attrs_map_index( __DIR__ );
			}
		),
		'no PresetAttrsMap'
	),
	'a Packages root with no map files is an error, not an empty index'
);

/* Add-only: nothing is removed, both additions land on top of the base. */
assert_same(
	array( 'title.decoration.font__size', 'title.innerContent' ),
	diviops_test_final_keys( $fixtures, 'divi/fixture-add-only' ),
	'an add-only map against an empty base returns exactly its additions'
);

$add_only_over_base = diviops_preset_attrs_map_resolve(
	$fixtures,
	'divi/fixture-add-only',
	array( 'module.decoration.spacing__padding' => true )
);
assert_same(
	array( 'module.decoration.spacing__padding', 'title.decoration.font__size', 'title.innerContent' ),
	$add_only_over_base['final'],
	'an add-only map keeps every base key'
);
assert_same( array(), $add_only_over_base['removed'], 'an add-only map removes nothing' );
assert_same(
	array( 'title.decoration.font__size', 'title.innerContent' ),
	$add_only_over_base['added'],
	'added reports the keys the map contributed over the base'
);
assert_same( array(), $add_only_over_base['invalidates'], 'an add-only map invalidates nothing' );
assert_same( false, $add_only_over_base['wipes_base'], 'an add-only map keeps the base it is handed' );
assert_same( false, $add_only_over_base['inert'], 'a map that contributes keys is not inert' );

/* Unset-only: the two named base keys go, everything else stays, nothing is added. */
$unset_only = diviops_preset_attrs_map_resolve(
	$fixtures,
	'divi/fixture-unset-only',
	array(
		'body.decoration.body.decoration.font__size'  => true,
		'body.decoration.body.decoration.font__color' => true,
		'body.decoration.font__size'                  => true,
	)
);
assert_same(
	array( 'body.decoration.font__size' ),
	$unset_only['final'],
	'an unset-only map returns the base minus the keys it unsets'
);
assert_same(
	array( 'body.decoration.body.decoration.font__color', 'body.decoration.body.decoration.font__size' ),
	$unset_only['removed'],
	'removed reports the keys the map took out of the base'
);
assert_same( array(), $unset_only['added'], 'an unset-only map adds nothing' );

/*
 * An unset-only map against no base contributes no keys at all, which is a real
 * answer and not an empty one: Divi ships eight such modules, `divi/code` among them.
 * The keys it takes out are still reported, because the resolver probes the map with
 * every path-shaped string in its own source and records which ones do not come back.
 */
$unset_only_bare = diviops_preset_attrs_map_resolve( $fixtures, 'divi/fixture-unset-only' );
assert_same( array(), $unset_only_bare['final'], 'an unset-only map contributes no keys of its own' );
assert_same(
	array( 'body.decoration.body.decoration.font__color', 'body.decoration.body.decoration.font__size' ),
	$unset_only_bare['invalidates'],
	'invalidates reports the keys an unset-only map strips, with no base supplied'
);
assert_same( false, $unset_only_bare['wipes_base'], 'an unset-only map keeps the base keys it does not name' );

/*
 * A map that discards the base wholesale. Divi's `divi/social-media-follow-network`
 * returns an empty array, and its file holds no path strings to probe with, so nothing
 * short of noticing the base itself disappear detects that it did anything.
 */
$wipe = diviops_preset_attrs_map_resolve(
	$fixtures,
	'divi/fixture-wipe',
	array( 'module.decoration.spacing__margin' => true )
);
assert_same( true, $wipe['wipes_base'], 'a map returning an empty array is reported as discarding the base' );
assert_same( array(), $wipe['final'], 'a map returning an empty array resolves to no keys' );
assert_same(
	array( 'module.decoration.spacing__margin' ),
	$wipe['removed'],
	'a wiping map removes every key of the base it was handed'
);

/*
 * Unset-then-add is the ordering case. get_map() unsets first and merges second, so a
 * key in both lists survives and a key in the unset list alone does not. Resolve it in
 * the wrong order and `button.decoration.sizing__width` disappears.
 */
$unset_then_add = diviops_preset_attrs_map_resolve(
	$fixtures,
	'divi/fixture-unset-then-add',
	array(
		'button.decoration.button.decoration.background__color' => true,
		'button.decoration.button.decoration.border__radius'    => true,
		'button.decoration.sizing__width'                       => true,
		'module.decoration.spacing__margin'                     => true,
	)
);
assert_same(
	array(
		'button.decoration.background__color',
		'button.decoration.sizing__width',
		'module.decoration.spacing__margin',
	),
	$unset_then_add['final'],
	'a key that is unset and then merged back survives; keys only unset do not'
);
assert_same(
	array(
		'button.decoration.button.decoration.background__color',
		'button.decoration.button.decoration.border__radius',
	),
	$unset_then_add['removed'],
	'only the keys not merged back count as removed'
);
assert_same(
	array(
		'button.decoration.button.decoration.background__color',
		'button.decoration.button.decoration.border__radius',
	),
	$unset_then_add['invalidates'],
	'a key that is unset and merged back is not reported as invalidated'
);

/*
 * An inert map serves its module and returns the base map untouched, so it is
 * behaviourally identical to one that does not serve it at all. Divi ships five. The
 * resolver falls back to reading the guard for exactly this case, and the truthful
 * answer is that the module's own map contributes nothing rather than that it failed.
 */
$inert = diviops_preset_attrs_map_resolve(
	$fixtures,
	'divi/fixture-inert',
	array( 'module.decoration.spacing__margin' => true )
);
assert_same( true, $inert['inert'], 'a map that serves its module and changes nothing is reported as inert' );
assert_same(
	array( 'module.decoration.spacing__margin' ),
	$inert['final'],
	'an inert map resolves to the base it was handed'
);
assert_same( array(), $inert['added'], 'an inert map adds nothing' );
assert_same( array(), $inert['removed'], 'an inert map removes nothing' );

/*
 * The early return. Every real map file opens with a guard returning the base map
 * untouched for a module name it does not serve. Because the index finds files by
 * looking for the name in the source, a name quoted anywhere else reaches that guard,
 * and a resolver that reported the base map back as a result would be publishing an
 * answer it never computed.
 */
assert_same(
	array( 'module.decoration.spacing__margin' ),
	diviops_test_final_keys( $fixtures, 'divi/fixture-early-return' ),
	'the module name the guard actually serves resolves normally'
);

$early_return_message = diviops_test_thrown_message(
	static function () use ( $fixtures ) {
		diviops_preset_attrs_map_resolve(
			$fixtures,
			'divi/fixture-early-return-decoy',
			array( 'module.decoration.spacing__padding' => true )
		);
	}
);
assert_true(
	false !== strpos( $early_return_message, 'divi/fixture-early-return-decoy' ),
	'the early-return refusal names the module it was asked for'
);
assert_true(
	false !== strpos( $early_return_message, 'does not serve' ),
	'the early-return refusal says the file does not serve that module rather than reporting a result'
);

/* An unknown module name is an error, not an empty result. */
assert_true(
	false !== strpos(
		diviops_test_thrown_message(
			static function () use ( $fixtures ) {
				diviops_preset_attrs_map_resolve( $fixtures, 'divi/not-a-fixture' );
			}
		),
		'divi/not-a-fixture'
	),
	'a module with no map file is an error naming that module'
);

/*
 * Composition. A module map that calls a shared family map has to be able to load it,
 * and the family keys itself on the element prefix it is handed, so the same family
 * appears twice under different prefixes.
 */
assert_same(
	array(
		'button.decoration.fixtureFamily__color',
		'button.decoration.fixtureFamily__intensity',
		'title.decoration.fixtureFamily__color',
		'title.decoration.fixtureFamily__intensity',
	),
	diviops_test_final_keys( $fixtures, 'divi/fixture-composed' ),
	'a shared family map composed under two element prefixes yields both prefixes'
);

/*
 * The regression. These seven paths are CTA's own, verified against
 * ModuleLibrary/Cta/CTAPresetAttrsMap.php on Divi 5.9.0: each sits in that file's
 * $keys_to_unset and none survives its get_map(). The fixture reproduces them.
 */
$cta_unset_paths = array(
	'button.decoration.font.font__lineHeight',
	'button.decoration.button.innerContent__text',
	'button.decoration.button.decoration.button__icon.enable',
	'button.decoration.button.decoration.background__color',
	'button.decoration.button.decoration.border__radius',
	'button.decoration.button.decoration.font.font__size',
	'button.decoration.button.decoration.sizing__width',
);

$cta_shaped      = diviops_preset_attrs_map_resolve( $fixtures, 'divi/fixture-cta-shaped' );
$cta_shaped_final = $cta_shaped['final'];

foreach ( $cta_unset_paths as $unset_path ) {
	assert_true(
		! in_array( $unset_path, $cta_shaped_final, true ),
		sprintf( 'the unset path %s never reaches the output', $unset_path )
	);
	assert_true(
		in_array( $unset_path, $cta_shaped['invalidates'], true ),
		sprintf( 'the unset path %s is reported as one the map invalidates', $unset_path )
	);
}

assert_same(
	array(
		'button.decoration.button__icon.enable',
		'button.decoration.sizing__width',
		'button.innerContent__text',
		'title.innerContent',
	),
	$cta_shaped_final,
	'the CTA-shaped map yields exactly the corrected shallow paths'
);

/*
 * And the proof that the assertion above is load-bearing rather than incidental: the
 * unset paths are all present in the fixture source as quoted strings, so a scanner
 * reading strings out of the file would emit every one of them.
 */
$cta_shaped_source = (string) file_get_contents(
	$fixtures . '/ModuleLibrary/CtaShapedFixture/CtaShapedFixturePresetAttrsMap.php'
);
preg_match_all( "/'([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+)'/", $cta_shaped_source, $naive_matches );
$naively_scanned = array_values( array_unique( $naive_matches[1] ) );

foreach ( $cta_unset_paths as $unset_path ) {
	assert_true(
		in_array( $unset_path, $naively_scanned, true ),
		sprintf( 'a quoted-string scan of the fixture does find %s, which is why merge-awareness is required', $unset_path )
	);
}

/*
 * The real Divi tree, when one is available. Skipped in CI, which has no Divi install,
 * so nothing above depends on it.
 */
$builder5 = getenv( 'DIVIOPS_DIVI_BUILDER5_PATH' );

if ( is_string( $builder5 ) && '' !== $builder5 && is_dir( $builder5 . '/server/Packages/ModuleLibrary' ) ) {
	$divi_packages = $builder5 . '/server/Packages';
	$cta           = diviops_preset_attrs_map_resolve( $divi_packages, 'divi/cta' );

	assert_true( count( $cta['final'] ) > 0, 'the real CTA map resolves to a non-empty key set' );

	foreach ( $cta_unset_paths as $unset_path ) {
		assert_true(
			! in_array( $unset_path, $cta['final'], true ),
			sprintf( 'the real CTA map does not emit its own unset path %s', $unset_path )
		);
		assert_true(
			in_array( $unset_path, $cta['invalidates'], true ),
			sprintf( 'the real CTA map reports %s as a path it invalidates', $unset_path )
		);
	}

	/*
	 * Every module Divi declares a per-module map for has to resolve. Eight of them do
	 * nothing but remove keys, and one returns an empty array outright, so a resolver
	 * that treated "contributed no keys of its own" as a failure would reject them.
	 */
	$divi_index    = diviops_preset_attrs_map_index( $divi_packages );
	$unresolvable  = array();

	foreach ( array_keys( $divi_index ) as $divi_module ) {
		try {
			diviops_preset_attrs_map_resolve( $divi_packages, $divi_module );
		} catch ( RuntimeException $error ) {
			$unresolvable[] = $divi_module;
		}
	}

	assert_true( count( $divi_index ) > 60, 'the real index covers Divi\'s per-module preset maps' );
	assert_same( array(), $unresolvable, 'every module name Divi declares a per-module map for resolves' );

	/*
	 * An unset-only Divi module. `divi/code` removes six text and text-shadow keys and
	 * adds none, so its whole contribution is what it takes away.
	 */
	$code = diviops_preset_attrs_map_resolve( $divi_packages, 'divi/code' );
	assert_same( array(), $code['final'], 'the real divi/code map contributes no keys of its own' );
	assert_true( count( $code['invalidates'] ) > 0, 'the real divi/code map reports the keys it strips' );

	/* And an inert one, which resolves rather than erroring. */
	$canvas_portal = diviops_preset_attrs_map_resolve( $divi_packages, 'divi/canvas-portal' );
	assert_same( true, $canvas_portal['inert'], 'a real Divi pass-through map resolves as inert' );

	foreach (
		array(
			'title.innerContent',
			'button.innerContent__text',
			'button.decoration.sizing__width',
			'button.decoration.button__icon.enable',
		) as $expected_path
	) {
		assert_true(
			in_array( $expected_path, $cta['final'], true ),
			sprintf( 'the real CTA map emits its corrected path %s', $expected_path )
		);
	}

	assert_true(
		isset( diviops_preset_attrs_map_index( $divi_packages )['divi/fullwidth-post-content'] ),
		'the real index covers a Divi map file that serves two module names'
	);
}
