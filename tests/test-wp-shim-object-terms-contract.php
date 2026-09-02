<?php
// SPDX-License-Identifier: MIT
/**
 * wp-shim wp_get_object_terms() vs core's contract (#358).
 *
 * The stub recognized `fields => 'ids'` and fell through to a WP_Term-shaped
 * default for everything else, which is the defect
 * https://github.com/rubicon/diviops/issues/318,
 * https://github.com/rubicon/diviops/issues/326 and
 * https://github.com/rubicon/diviops/issues/330 closed one level up: an argument
 * the harness does not model is accepted, dropped, and answered with something
 * plausible instead of a refusal.
 *
 * The value it was dropping has a live caller. get_term_slug()
 * (trait-library.php:30) asks for `fields => 'slugs'` and returns `$terms[0]`
 * into the `layout_type` and `scope` fields of library_get() and
 * library_list(). Under the old stub that element was
 * `(object) [ 'term_id' => 11 ]`, so both handlers would have reported an object
 * where the response contract promises a slug string.
 *
 * Nothing was red, because no test registered a term against an et_pb_layout:
 * get_term_slug()'s `empty()` guard returned '' before the shape could matter.
 * That is the cost the issue records. The gap was not a crash waiting to happen,
 * it was the reason a slice of library output could not be characterized here at
 * all.
 *
 * The id-to-slug map lives in its own global rather than alongside the object's
 * term ids. wp_set_object_terms() writes bare ids into
 * $GLOBALS['diviops_test_object_terms'], so a slug carried in that store would be
 * erased the first time a handler under test wrote its own terms. Core has the
 * same split for the same reason: terms are rows, and object-term relationships
 * are just ids pointing at them. The last assertion below is the one that pins
 * it.
 *
 * Every assertion here fails against the pre-fix shim except the two labelled as
 * controls, which exist so a later regression that empties the fixture set shows
 * up as a control failure rather than as a filter that looks clean.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Register a fixed term/object-term fixture set and run one wp_get_object_terms()
 * call against it.
 *
 * Both registries are replaced outright and restored: every test file in tests/
 * shares one process, so a fixture left behind by another file would make these
 * results depend on discovery order.
 *
 * @param array<string, mixed> $args      wp_get_object_terms() arguments.
 * @param callable|null        $extra     Optional extra fixture setup, run after
 *                                        the base fixtures and before the call.
 * @param int|array<int, int>  $object_id Object id(s) to query.
 * @param string|array         $taxonomy  Taxonomy/taxonomies to query.
 * @return mixed Whatever the stub returns.
 */
function wp_shim_object_terms_call( array $args, ?callable $extra = null, $object_id = 987901, $taxonomy = 'layout_type' ) {
	$saved_terms  = $GLOBALS['diviops_test_terms'] ?? array();
	$saved_object = $GLOBALS['diviops_test_object_terms'];

	$GLOBALS['diviops_test_terms']        = array();
	$GLOBALS['diviops_test_object_terms'] = array();

	diviops_test_register_term( 61, 'section' );
	diviops_test_register_term( 62, 'non_global' );
	diviops_test_register_term( 63, 'row' );
	diviops_test_register_object_terms( 987901, 'layout_type', array( 61 ) );
	diviops_test_register_object_terms( 987901, 'scope', array( 62 ) );
	diviops_test_register_object_terms( 987902, 'layout_type', array( 63, 61 ) );

	if ( null !== $extra ) {
		$extra();
	}

	try {
		return wp_get_object_terms( $object_id, $taxonomy, $args );
	} finally {
		$GLOBALS['diviops_test_terms']        = $saved_terms;
		$GLOBALS['diviops_test_object_terms'] = $saved_object;
	}
}

/**
 * Return the message of the RuntimeException a wp_get_object_terms() call raises,
 * or '' when it returns normally.
 *
 * @param array<string, mixed> $args      wp_get_object_terms() arguments.
 * @param callable|null        $extra     Optional extra fixture setup.
 * @param int|array<int, int>  $object_id Object id(s) to query.
 * @return string Exception message, or ''.
 */
function wp_shim_object_terms_error( array $args, ?callable $extra = null, $object_id = 987901 ): string {
	try {
		wp_shim_object_terms_call( $args, $extra, $object_id );
		return '';
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	}
}

// Control: the fixture object carries exactly one layout_type term. If this stops
// holding, every expectation below is measuring the wrong thing.
assert_same(
	array( 61 ),
	wp_shim_object_terms_call( array( 'fields' => 'ids' ) ),
	'control: the fixture object carries one layout_type term, id 61'
);

// Control: ids still resolve across several objects and taxonomies at once,
// deduplicated, in registration order. This is the one shape that already worked
// and it must keep working.
assert_same(
	array( 61, 62, 63 ),
	wp_shim_object_terms_call( array( 'fields' => 'ids' ), null, array( 987901, 987902 ), array( 'layout_type', 'scope' ) ),
	'control: ids across several objects and taxonomies are merged, deduplicated and in registration order'
);

// The shape get_term_slug() actually asks for. Pre-fix this returned
// [ (object) [ 'term_id' => 61 ] ], so library_get()/library_list() would have put
// a stdClass in the layout_type field.
assert_same(
	array( 'section' ),
	wp_shim_object_terms_call( array( 'fields' => 'slugs' ) ),
	"fields => 'slugs' returns slug strings, not term objects"
);

assert_same(
	array( 'section', 'non_global', 'row' ),
	wp_shim_object_terms_call( array( 'fields' => 'slugs' ), null, array( 987901, 987902 ), array( 'layout_type', 'scope' ) ),
	"fields => 'slugs' resolves every merged id, in the same order as fields => 'ids'"
);

// An id with no registered slug is a fixture gap, not an empty slug. Core resolves
// every id in the relationship table to a real term row, so returning '' here
// would hand back the same plausible-wrong answer this whole issue is about: an
// item that HAS a layout_type reported as if it had none.
$missing_slug = wp_shim_object_terms_error(
	array( 'fields' => 'slugs' ),
	static function (): void {
		diviops_test_register_object_terms( 987901, 'layout_type', array( 61, 999 ) );
	}
);
assert_true(
	false !== strpos( $missing_slug, 'wp-shim wp_get_object_terms:' ),
	'an object term whose id has no registered slug raises rather than reporting an empty slug'
);
assert_true(
	false !== strpos( $missing_slug, '999' ),
	'the unregistered-slug refusal names the term id the fixture is missing'
);

// A fields value with no caller in this repo is refused rather than answered with
// the default shape. Modelling 'slugs' alone fixes today's caller; refusing the
// rest closes the class that produced it.
$unmodelled = wp_shim_object_terms_error( array( 'fields' => 'names' ) );
assert_true(
	false !== strpos( $unmodelled, "'names' is not modelled" ),
	"an unmodelled fields value raises and names the value it was given"
);
assert_true(
	false !== strpos( $unmodelled, "'ids'" ) && false !== strpos( $unmodelled, "'slugs'" ),
	'the unmodelled-fields refusal says which values the harness does model'
);

// The default shape is modelled but has no caller in this repo. It carries the
// slug now that a registry exists, so reading ->slug off it cannot reproduce the
// same silent null one branch over.
$objects = wp_shim_object_terms_call( array() );
assert_same( 1, count( $objects ), 'the default shape returns one object per term' );
assert_same( 61, $objects[0]->term_id, 'the default shape carries term_id' );
assert_same( 'section', $objects[0]->slug, 'the default shape carries the slug' );

/* =========================================================================
 * The write side. wp_set_object_terms() is the other half of the round trip,
 * and it was intval()ing everything it was handed.
 * ====================================================================== */

/**
 * Run one wp_set_object_terms() call against an empty pair of registries and
 * report what the object ended up holding, plus the slugs it reads back as.
 *
 * @param mixed $terms  Terms to set, in whatever shape the caller passes.
 * @param array $seed   Term ids to pre-register, as id => slug.
 * @return array{returned:mixed, stored:array, slugs:mixed}
 */
function wp_shim_set_object_terms_probe( $terms, array $seed = array() ): array {
	$saved_terms  = $GLOBALS['diviops_test_terms'];
	$saved_object = $GLOBALS['diviops_test_object_terms'];

	$GLOBALS['diviops_test_terms']        = $seed;
	$GLOBALS['diviops_test_object_terms'] = array();

	try {
		$returned = wp_set_object_terms( 987950, $terms, 'layout_type' );
		return array(
			'returned' => $returned,
			'stored'   => $GLOBALS['diviops_test_object_terms'][987950]['layout_type'] ?? array(),
			'slugs'    => wp_get_object_terms( 987950, 'layout_type', array( 'fields' => 'slugs' ) ),
		);
	} finally {
		$GLOBALS['diviops_test_terms']        = $saved_terms;
		$GLOBALS['diviops_test_object_terms'] = $saved_object;
	}
}

// The case library_save() actually exercises. trait-library.php:260-261 passes
// the slug strings 'section' and 'non_global' straight to wp_set_object_terms(),
// and core resolves a string through term_exists() / wp_insert_term()
// (wp-includes/taxonomy.php:2888-2896 on the reference install). Before this,
// array_map( 'intval', ... ) turned 'section' into 0 and stored it, so the
// save-then-read round trip could not be asserted at all -- reading the slugs
// back raised, and the refusal told the author to register a slug for term id 0,
// which core can never produce.
$fresh = wp_shim_set_object_terms_probe( 'section' );
assert_same( array( 'section' ), $fresh['slugs'], 'a slug string written through wp_set_object_terms() reads back as that slug' );
assert_same( 1, count( $fresh['stored'] ), 'a slug string is stored as exactly one term id' );
assert_true( 0 !== $fresh['stored'][0], 'a slug string is never stored as term id 0' );

// An already-registered slug resolves to its existing id rather than being
// created a second time, which is term_exists() winning over wp_insert_term().
$known = wp_shim_set_object_terms_probe( 'section', array( 61 => 'section' ) );
assert_same( array( 61 ), $known['stored'], 'a slug that already has a term id resolves to it rather than creating another' );
assert_same( array( 'section' ), $known['slugs'], 'the resolved term reads back as its slug' );

// Mixed input, which is what the two callers look like side by side:
// page_duplicate() passes ids it read with fields => 'ids', library_save() passes
// slugs. Both shapes have to survive the same function.
$mixed = wp_shim_set_object_terms_probe( array( 61, 'row' ), array( 61 => 'section' ) );
assert_same( array( 'section', 'row' ), $mixed['slugs'], 'an int id and a slug string in one call both resolve' );

// Core skips an empty or whitespace-only term rather than storing it
// (wp-includes/taxonomy.php:2884). Storing it here would auto-create a term
// whose slug is the empty string, which reads back as "unclassified" and is
// exactly the plausible-wrong answer this file exists to stop.
$blank = wp_shim_set_object_terms_probe( array( 'row', '', '   ' ) );
assert_same( array( 'row' ), $blank['slugs'], 'an empty or whitespace-only term is skipped rather than stored' );

/* =========================================================================
 * Arguments wp_get_object_terms() was accepting and ignoring.
 * ====================================================================== */

// Same rule this file states for WP_Query at tests/wp-shim.php: an argument
// raises when ignoring it would change the rows returned or their order AND this
// harness cannot compute that answer. None of these has a caller in this repo
// today, which is why the silence had not cost anything yet.
foreach ( array( 'number' => 1, 'offset' => 1, 'meta_query' => array( array( 'key' => 'k' ) ), 'orderby' => 'name' ) as $arg => $value ) {
	$message = wp_shim_object_terms_error( array( 'fields' => 'ids', $arg => $value ) );
	assert_true(
		false !== strpos( $message, "'" . $arg . "'" ),
		sprintf( "wp_get_object_terms refuses '%s' rather than accepting and ignoring it", $arg )
	);
}

// A value that is inert in core is inert here. orderby => 'none' blanks ORDER BY,
// which is the registration order this stub returns anyway, and it takes `order`
// with it -- the same carve-out diviops_test_query_refuse_unmodelled() makes.
assert_same(
	array( 61 ),
	wp_shim_object_terms_call( array( 'fields' => 'ids', 'orderby' => 'none', 'order' => 'ASC' ) ),
	"orderby => 'none' is inert in core, so it passes rather than raising"
);

// The registry split, pinned. A handler that writes its own terms goes through
// wp_set_object_terms(), which stores bare ids; slugs registered beforehand must
// survive that write, or every write-then-read test would silently lose them.
assert_same(
	array( 'row' ),
	wp_shim_object_terms_call(
		array( 'fields' => 'slugs' ),
		static function (): void {
			wp_set_object_terms( 987901, array( 63 ), 'layout_type' );
		}
	),
	'slugs survive a handler writing the object its own terms through wp_set_object_terms()'
);
