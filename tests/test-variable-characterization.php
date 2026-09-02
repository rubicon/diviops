<?php
// SPDX-License-Identifier: MIT
/**
 * Characterization of the variable domain (trait-variable.php).
 *
 * Written for the upstream reconciliation triage
 * (https://github.com/rubicon/diviops/issues/328). trait-variable.php is 2,575
 * lines across 33 functions and, before this file, two tests touched it:
 * tests/test-variable-update.php exercised two pure helpers
 * (`build_updated_variable_record`, `build_gradient_variable_value`), and
 * tests/test-variable-ref-scan-post-types.php pinned the post-type scope of the
 * two reference scanners. Six REST handlers, the whole fluid-clamp generator,
 * and every refusal path in the domain had nothing. Upstream's 2026-08-31 sync
 * changes this file and merges cleanly, which means it would land unexamined;
 * this suite is what makes that a decision instead of an accident.
 *
 * These are characterization tests, not correctness tests. They pin what the
 * code does today, quirks included, so an adoption that moves the behaviour
 * fails loudly instead of passing quietly. Assertions that encode behaviour
 * believed to be WRONG are tagged `QUIRK:` inline — a later fix to one of those
 * is expected to fail here, and that failure is the fix being noticed, not a
 * regression.
 *
 * Expected values are derived from the system, never read off a run:
 *   - clamp() arithmetic is worked through from `build_fluid_clamp()`'s own
 *     formula in the comment above each assertion;
 *   - the customizer-colour id set comes from Divi 5's GlobalData.php:58-84
 *     (see tests/variable-characterization-stubs.php);
 *   - the seven storage buckets and the `$variable()` token grammar come from
 *     skills/divi-5-builder/references/variable-bindings.md, itself authored
 *     from Divi's own source;
 *   - error codes, HTTP statuses and message text come from the handler's
 *     documented envelope contract in the trait's file docblock.
 *
 * ── What is not reachable here, and why it is not faked ──────────────────
 *
 * Two primitives are deliberately absent from this harness and MUST stay that
 * way: `et_get_option()` and `parse_blocks()`.
 * tests/test-variable-ref-scan-post-types.php distinguishes "this post was
 * scanned" from "this post was filtered out" by which of those two undefined
 * functions the scan dies on. Defining either would not fail that file — it
 * would silently make its probe vacuous while it kept reporting PASS. So the
 * following is named rather than covered:
 *
 *   - the entire `colors` bucket end-to-end (`et_divi.et_global_data.global_colors`
 *     is read through `et_get_option()`): `variable_list` without a `type`
 *     filter, `variable_create` type=colors, and `variable_update` /
 *     `variable_delete` on a `gcid-` id past the customizer guard;
 *   - `get_defined_variable_ids()`, and therefore `variable_scan_orphans()`
 *     entirely — it calls `et_get_option('et_global_data')` unconditionally at
 *     trait-variable.php:300;
 *   - `collect_variable_refs()` scanning *post content*: a post whose content
 *     contains `$variable(` reaches `parse_blocks()`. Every post fixture below
 *     is therefore either free of that substring, or carries a bare id with no
 *     token — which is itself a documented case (the delete fast path is
 *     explicitly false-positive tolerant). The preset-registry half of the same
 *     scan needs neither primitive and IS covered;
 *   - `variable_used_on_page()` past its guards: the body needs Divi 5's
 *     `DetectFeature`, `OffCanvasHooks` and `DynamicAssetsUtils`. The four
 *     refusal paths in front of that body are covered, including the one that
 *     reports Divi's absence;
 *   - `scan_truncated`: reaching it needs VARIABLES_SCAN_MAX_POSTS + 1 = 2001
 *     post fixtures.
 *
 * Closing any of those needs a real Divi 5 install (the shape tests-live/ takes),
 * not a stub — a stub for `et_get_option` would be this suite writing the
 * storage behaviour it claims to characterize.
 *
 * ── Why the readers below are total ──────────────────────────────────────
 *
 * Every dynamic lookup here goes through a helper that returns an empty value
 * rather than indexing straight into a result. That is not defensiveness for
 * its own sake: a suite that dies on `array_keys( null )` when a behaviour
 * moves still exits non-zero, but it reports nothing about WHICH behaviour
 * moved, so it cannot say that a particular assertion is doing any work. Total
 * readers turn every such change into a named failure. Each behaviour pinned
 * below was checked by breaking it in the plugin, one change at a time, and
 * confirming the named assertion failed; the matrix is recorded on the PR.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/variable-characterization-stubs.php';

/**
 * $wpdb double that EXECUTES `variable_id_appears_anywhere()`'s existence probe
 * against the fixture post registry, rather than returning a canned answer.
 *
 * The shim's own DiviOps_Test_wpdb models the options table only and has no
 * `get_var()`, so the probe cannot run against it at all. A double that always
 * answered "hit" (the shape test-variable-ref-scan-post-types.php uses, where
 * the query text is the artifact under test) would make every assertion below
 * about the delete fast path pass whatever the query said. This one parses the
 * prepared SQL and applies it, so narrowing `post_status`, narrowing
 * SCANNABLE_POST_TYPES, or changing the LIKE needle all change the answer.
 *
 * Anything it cannot apply raises rather than guessing, for the same reason
 * DiviOps_Test_wpdb raises: an unmodelled query shape must fail loudly, not
 * return a wrong-but-plausible answer.
 */
final class DiviOps_Variable_Char_wpdb {

	/** @var string Posts table name, matching $wpdb->posts. */
	public $posts = 'wp_posts';

	/** @var array<int, string> Every query prepared through this double. */
	public $prepared = array();

	/**
	 * Model $wpdb::esc_like(): backslash-escape LIKE's wildcards.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * Model $wpdb::prepare()'s %s substitution, including core's
	 * single-array-argument form, with SQL's doubled-quote escaping.
	 *
	 * @param string $query   Query carrying %s placeholders.
	 * @param mixed  ...$args Values, or one array of values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		$query  = (string) $query;
		foreach ( $values as $value ) {
			$quoted = "'" . str_replace( "'", "''", (string) $value ) . "'";
			// Callback rather than a replacement string: esc_like() puts
			// backslashes in the value, and a replacement string would read
			// those (and any $n) as references.
			$query = preg_replace_callback(
				'/%s/',
				static function () use ( $quoted ) {
					return $quoted;
				},
				$query,
				1
			);
		}
		$this->prepared[] = $query;
		return $query;
	}

	/**
	 * Apply the prepared existence probe to $GLOBALS['diviops_test_posts'].
	 *
	 * @param string $query Prepared SQL.
	 * @return string|null '1' when a row matches, null otherwise.
	 * @throws RuntimeException When the query is not the shape this double applies.
	 */
	public function get_var( $query ) {
		$query = (string) $query;

		if (
			! preg_match( "/post_status\s+IN\s*\(([^)]*)\)/i", $query, $status_match )
			|| ! preg_match( "/post_type\s+IN\s*\(([^)]*)\)/i", $query, $type_match )
			|| ! preg_match( "/post_content\s+LIKE\s+'((?:[^']|'')*)'/i", $query, $like_match )
		) {
			throw new RuntimeException( 'DiviOps_Variable_Char_wpdb cannot apply this query shape: ' . $query );
		}

		$statuses = self::unquote_list( $status_match[1] );
		$types    = self::unquote_list( $type_match[1] );
		$pattern  = self::like_to_regex( str_replace( "''", "'", $like_match[1] ) );

		foreach ( (array) ( $GLOBALS['diviops_test_posts'] ?? array() ) as $post ) {
			if ( ! in_array( (string) $post->post_status, $statuses, true ) ) {
				continue;
			}
			if ( ! in_array( (string) $post->post_type, $types, true ) ) {
				continue;
			}
			if ( preg_match( $pattern, (string) $post->post_content ) ) {
				return '1';
			}
		}
		return null;
	}

	/**
	 * Split a SQL quoted-string list into its values.
	 *
	 * @param string $list Contents of an IN (...) clause.
	 * @return array<int, string>
	 */
	private static function unquote_list( string $list ): array {
		preg_match_all( "/'((?:[^']|'')*)'/", $list, $matches );
		return array_map(
			static function ( $value ) {
				return str_replace( "''", "'", (string) $value );
			},
			$matches[1]
		);
	}

	/**
	 * Translate a SQL LIKE pattern into an unanchored regex, honouring the
	 * backslash escapes esc_like() introduces.
	 *
	 * @param string $like LIKE pattern.
	 * @return string
	 */
	private static function like_to_regex( string $like ): string {
		$regex  = '';
		$length = strlen( $like );
		for ( $index = 0; $index < $length; $index++ ) {
			$character = $like[ $index ];
			if ( '\\' === $character && $index + 1 < $length ) {
				++$index;
				$regex .= preg_quote( $like[ $index ], '/' );
				continue;
			}
			if ( '%' === $character ) {
				$regex .= '.*';
				continue;
			}
			if ( '_' === $character ) {
				$regex .= '.';
				continue;
			}
			$regex .= preg_quote( $character, '/' );
		}
		return '/^' . $regex . '$/s';
	}
}

/**
 * A stringable value, so a guard that asks `is_string()` can be told apart from
 * one that would have cast. Used only by the rem-involvement checks below.
 */
final class DiviOps_Variable_Char_Stringy {

	/** @var string */
	private $text;

	/**
	 * @param string $text String form.
	 */
	public function __construct( string $text ) {
		$this->text = $text;
	}

	public function __toString(): string {
		return $this->text;
	}
}

$diviops_variable_saved_wpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb']             = new DiviOps_Variable_Char_wpdb();

/**
 * Reset every store these handlers read or write.
 *
 * The preset registry is seeded non-empty on purpose. `collect_variable_refs()`
 * reads it through `get_d5_presets()` → `probe_storage_paths()`, which stops at
 * the first non-empty path; leaving the canonical top-level option empty sends
 * the probe on to `et_divi.builder_global_presets_d5`, which is read through the
 * deliberately-absent `et_get_option()`. A seeded canonical path is what keeps
 * the scan inside this harness.
 *
 * @param array $presets Preset registry contents.
 */
function diviops_variable_reset( array $presets = array( 'module' => array() ) ) {
	$GLOBALS['diviops_test_posts']          = array();
	$GLOBALS['diviops_test_post_meta']      = array();
	$GLOBALS['diviops_test_uneditable_ids'] = array();
	$GLOBALS['diviops_test_options']        = array(
		'et_divi_global_variables'         => array(),
		'et_divi_builder_global_presets_d5' => $presets,
	);
}

/**
 * @param array $params Request parameters.
 * @return DiviOps_Test_Request
 */
function diviops_variable_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

/**
 * The non-color Variable Manager registry as currently stored.
 *
 * @return array
 */
function diviops_variable_registry(): array {
	return (array) $GLOBALS['diviops_test_options']['et_divi_global_variables'];
}

/**
 * Seed one non-color variable straight into storage, bypassing the handlers.
 *
 * @param string $type   Bucket.
 * @param string $id     Variable id.
 * @param array  $fields Stored record.
 */
function diviops_variable_seed( string $type, string $id, array $fields ) {
	$registry                        = diviops_variable_registry();
	$registry[ $type ][ $id ]        = array_merge( array( 'id' => $id, 'type' => $type ), $fields );
	$GLOBALS['diviops_test_options']['et_divi_global_variables'] = $registry;
}

/**
 * The stored record for one non-colour variable, or an empty array when there
 * is none.
 *
 * Total on purpose. Every reader below goes through this rather than indexing
 * the registry directly, so a change that stops a write from happening reports
 * as a named assertion failure instead of killing the run on array_keys( null )
 * — a run that dies tells you something moved but not what.
 *
 * @param string $type Bucket.
 * @param string $id   Variable id.
 * @return array
 */
function diviops_variable_record( string $type, string $id ): array {
	$registry = diviops_variable_registry();
	return isset( $registry[ $type ][ $id ] ) && is_array( $registry[ $type ][ $id ] )
		? $registry[ $type ][ $id ]
		: array();
}

/**
 * One whole bucket of the non-colour registry, or an empty array.
 *
 * @param string $type Bucket.
 * @return array
 */
function diviops_variable_bucket( string $type ): array {
	$registry = diviops_variable_registry();
	return isset( $registry[ $type ] ) && is_array( $registry[ $type ] ) ? $registry[ $type ] : array();
}

/**
 * A thrown exception's message, or '' when nothing was thrown — so an assertion
 * about a refusal fails with its own message when the refusal stops happening.
 *
 * @param mixed $error Caught value, or null.
 * @return string
 */
function diviops_variable_error_text( $error ): string {
	return $error instanceof Throwable ? (string) $error->getMessage() : '';
}

/**
 * An envelope's body, or an empty array when the value is not an envelope at
 * all — the shape a helper returns when it accepted input it should have
 * refused.
 *
 * @param mixed $maybe Envelope or value.
 * @return array
 */
function diviops_variable_envelope( $maybe ): array {
	return $maybe instanceof WP_REST_Response ? (array) $maybe->get_data() : array();
}

/**
 * Build a decoded `$variable()` token of the shape parse_blocks() hands the
 * walker, per Conversion::formatDynamicContent() (Conversion.php:668-676) as
 * documented in skills/divi-5-builder/references/variable-bindings.md.
 *
 * @param string $name Variable id, or a dynamic-content option name.
 * @param string $type Token type field.
 * @return string
 */
function diviops_variable_token( string $name, string $type = 'content' ): string {
	return '$variable({"type":"' . $type . '","value":{"name":"' . $name . '","settings":{}}})$';
}

// ── walk_value_for_variable_refs: which references are seen at all ────────
//
// This is the resolution layer every other answer in the domain rests on:
// variable_delete's 409, variable_scan_orphans' orphan list, and the location
// records both report all come from what this walker collects.

$walk_all   = array();
$walk_local = array();
$walk_args  = array( diviops_variable_token( 'gvid-abc123' ), &$walk_all, &$walk_local );
diviops_call_ref( 'walk_value_for_variable_refs', $walk_args );
assert_same( array( 'gvid-abc123' => 1 ), $walk_all, 'a gvid- token contributes one reference' );
assert_same( array( 'gvid-abc123' ), $walk_local, 'the local list carries the same id for the location record' );

// (2) gcid- shares the wrapper and the same collector. variable-bindings.md's
// namespace catalog: colours are `type:"color"`, `gcid-*`; global variables are
// `type:"content"`, `gvid-*`. The regex keys on the id prefix, not on `type`,
// so both are collected and the type field is never consulted.
$walk_all   = array();
$walk_local = array();
$walk_args  = array( diviops_variable_token( 'gcid-9zz', 'color' ), &$walk_all, &$walk_local );
diviops_call_ref( 'walk_value_for_variable_refs', $walk_args );
assert_same( array( 'gcid-9zz' => 1 ), $walk_all, 'a gcid- colour token is collected by the same walker' );

// (3) gfid- is this plugin's own global-font namespace, not a Divi one
// (variable-bindings.md, "gfid-: a DiviOps namespace, not a Divi one"). The
// character class is g[vc]id-, so a font reference is out of scope here and is
// scanned by trait-global-font.php instead. A delete that treated fonts as in
// scope would refuse on a reference this domain does not own.
$walk_all   = array();
$walk_local = array();
$walk_args  = array( diviops_variable_token( 'gfid-brandfont' ), &$walk_all, &$walk_local );
diviops_call_ref( 'walk_value_for_variable_refs', $walk_args );
assert_same( array(), $walk_all, 'a gfid- font reference is out of the variable walker scope' );

// (4) A real dynamic-content binding shares the wrapper AND the type field —
// variable-bindings.md's census found 1,543 `type:"content"` tokens naming
// dynamic-content options against 2,786 naming gvid- ids. Only the id prefix
// separates them, and a delete must not count a post_excerpt binding as a
// reference to anything in this registry.
$walk_all   = array();
$walk_local = array();
$walk_args  = array( diviops_variable_token( 'post_excerpt' ), &$walk_all, &$walk_local );
diviops_call_ref( 'walk_value_for_variable_refs', $walk_args );
assert_same( array(), $walk_all, 'a dynamic-content binding sharing the wrapper is not a variable reference' );

// (5) The stored, undecoded form. Divi writes the token into post_content with
// its quotes unicode-escaped, because the attrs blob is itself JSON inside a
// block comment; parse_blocks() decodes that before the walker sees it. The
// walker matches the decoded form ONLY, which is why collect_variable_refs()
// must parse rather than grep, and why variable_id_appears_anywhere() searches
// for the bare id instead of a quoted wrapper.
$walk_all   = array();
$walk_local = array();
// Built by applying Divi's own escaping to the decoded token rather than typed
// out, so the six-character " sequence cannot be normalised back to a
// quote by an editor. This is the byte shape post_content actually holds — the
// same one tests/test-variable-ref-scan-post-types.php uses for its fixtures.
$escaped    = str_replace( '"', chr( 92 ) . 'u0022', diviops_variable_token( 'gvid-abc123' ) );
$walk_args  = array( $escaped, &$walk_all, &$walk_local );
diviops_call_ref( 'walk_value_for_variable_refs', $walk_args );
assert_same( array(), $walk_all, 'the unicode-escaped stored form is not matched — the walker requires decoded attrs' );

// (6) The `$variable(` test is a pre-filter over the WHOLE string, not a scope.
// A string that contains a token anywhere has every `"name":"g[vc]id-…"` in it
// collected, token or not.
// QUIRK: the docblock says the walker collects "from $variable(...)$ tokens";
// it actually collects from the whole string once one token is present. Pinned
// as-is. A fix that scoped the match to the token span would fail this line.
$walk_all   = array();
$walk_local = array();
$walk_args  = array(
	diviops_variable_token( 'gvid-real' ) . ' and a bare fragment "name":"gvid-notatoken" after it',
	&$walk_all,
	&$walk_local,
);
diviops_call_ref( 'walk_value_for_variable_refs', $walk_args );
assert_same(
	array( 'gvid-real' => 1, 'gvid-notatoken' => 1 ),
	$walk_all,
	'QUIRK: a name fragment outside any token is collected once the string carries one token'
);

// (7) …and the converse: the same fragment with no token anywhere is skipped by
// the pre-filter, so the over-collection above needs a token to ride along with.
$walk_all   = array();
$walk_local = array();
$walk_args  = array( 'a bare fragment "name":"gvid-notatoken" with no token', &$walk_all, &$walk_local );
diviops_call_ref( 'walk_value_for_variable_refs', $walk_args );
assert_same( array(), $walk_all, 'without a $variable( substring the string is never pattern-matched at all' );

// (8) Counting: all_ids aggregates a running count across calls, local_ids
// appends every occurrence including duplicates. variable_delete's 409 reports
// the all_ids number as `ref_count`, and collect_variable_refs() de-duplicates
// local_ids before building location records — so the two lists disagreeing on
// duplicates is load-bearing, not incidental.
$walk_all   = array();
$walk_local = array();
$walk_args  = array(
	array(
		'a' => diviops_variable_token( 'gvid-abc123' ),
		'b' => array( 'deep' => diviops_variable_token( 'gvid-abc123' ) . diviops_variable_token( 'gcid-x1' ) ),
	),
	&$walk_all,
	&$walk_local,
);
diviops_call_ref( 'walk_value_for_variable_refs', $walk_args );
assert_same( array( 'gvid-abc123' => 2, 'gcid-x1' => 1 ), $walk_all, 'references are counted, not merely flagged, across a nested attrs tree' );
assert_same( array( 'gvid-abc123', 'gvid-abc123', 'gcid-x1' ), $walk_local, 'the local list keeps duplicates for the caller to de-duplicate' );

// (9) Objects are walked like arrays — attrs decoded as stdClass still resolve.
$walk_all   = array();
$walk_local = array();
$walk_args  = array( (object) array( 'v' => diviops_variable_token( 'gvid-obj' ) ), &$walk_all, &$walk_local );
diviops_call_ref( 'walk_value_for_variable_refs', $walk_args );
assert_same( array( 'gvid-obj' => 1 ), $walk_all, 'an object leaf is traversed like an array' );

// ── walk_blocks_for_variable_refs: recursion into innerBlocks ─────────────

$walk_all   = array();
$walk_local = array();
$blocks     = array(
	array(
		'blockName'   => 'divi/section',
		'attrs'       => array( 'x' => diviops_variable_token( 'gvid-outer' ) ),
		'innerBlocks' => array(
			array(
				'blockName'   => 'divi/text',
				'attrs'       => array( 'y' => diviops_variable_token( 'gvid-inner' ) ),
				'innerBlocks' => array(),
			),
		),
	),
	// A block with no attrs key at all — parse_blocks() emits these for
	// freeform HTML — must not fatal the walk.
	array( 'blockName' => null, 'innerBlocks' => array() ),
	// A block whose attrs is not an array is skipped — the string below
	// carries a real token, so "skipped" is distinguishable from "walked".
	// Its children are still descended into.
	array(
		'blockName'   => 'divi/row',
		'attrs'       => '{"z":"' . diviops_variable_token( 'gvid-strattr' ) . '"}',
		'innerBlocks' => array(
			array( 'blockName' => 'divi/text', 'attrs' => array( diviops_variable_token( 'gvid-deep' ) ), 'innerBlocks' => array() ),
		),
	),
);
$walk_args = array( $blocks, &$walk_all, &$walk_local );
diviops_call_ref( 'walk_blocks_for_variable_refs', $walk_args );
assert_same(
	array( 'gvid-outer' => 1, 'gvid-inner' => 1, 'gvid-deep' => 1 ),
	$walk_all,
	'the block walker descends innerBlocks, skips a non-array attrs value even when it carries a token, and survives an attr-less block'
);

// ── value_contains_substring: the cheap registry probe ────────────────────

assert_same( false, diviops_call( 'value_contains_substring', array( 'anything', '' ) ), 'an empty needle never matches, whatever the haystack' );
assert_same( true, diviops_call( 'value_contains_substring', array( 'xx gvid-a yy', 'gvid-a' ) ), 'a string leaf containing the needle matches' );
assert_same( false, diviops_call( 'value_contains_substring', array( array( 'a', 'b' ), 'gvid-a' ) ), 'no leaf containing the needle is a miss' );
assert_same(
	true,
	diviops_call( 'value_contains_substring', array( array( 'a' => array( 'b' => (object) array( 'c' => 'has gvid-a' ) ) ), 'gvid-a' ) ),
	'the probe walks nested arrays and objects to the string leaves'
);
assert_same( false, diviops_call( 'value_contains_substring', array( 12345, '234' ) ), 'a non-string scalar is not stringified for the substring test' );

// ── variable_id_appears_anywhere: the delete fast path ────────────────────

diviops_variable_reset();

// (11) An empty id short-circuits before any query — otherwise a LIKE '%%'
// would match every row and refuse every delete.
$GLOBALS['wpdb']->prepared = array();
assert_same( false, diviops_call( 'variable_id_appears_anywhere', array( '' ) ), 'an empty id is never "appearing anywhere"' );
assert_same( array(), $GLOBALS['wpdb']->prepared, 'the empty-id short circuit runs before the SQL is even prepared' );

// (12) Nothing anywhere: both surfaces miss, so variable_delete takes its fast
// path and skips the structured scan entirely.
diviops_test_register_post( 700100, 'plain content, no ids here', 'page', 'Unrelated' );
assert_same( false, diviops_call( 'variable_id_appears_anywhere', array( 'gvid-ghost' ) ), 'an id present on neither surface reports absent' );

// (13) A bare id in post content is a hit, with no token wrapper anywhere. The
// probe searches for the bare id precisely because the two surfaces escape it
// differently (unicode escapes in post_content, raw quotes in the serialized
// preset registry); the source calls this false-positive tolerant by design.
diviops_test_register_post( 700101, 'incidental mention of gvid-bare in prose', 'page', 'Prose' );
assert_same( true, diviops_call( 'variable_id_appears_anywhere', array( 'gvid-bare' ) ), 'a bare id anywhere in post content is a hit, token or not' );

// (14) The probe covers draft and private, not just published — matching the
// structured scan it short-circuits. A draft carrying the only reference must
// still block a delete.
diviops_variable_reset();
$draft              = diviops_test_register_post( 700102, 'references gvid-draftonly', 'page', 'Draft' );
$draft->post_status = 'draft';
assert_same( true, diviops_call( 'variable_id_appears_anywhere', array( 'gvid-draftonly' ) ), 'a draft post is inside the existence probe' );

// (15) …and a trashed one is not: the scan is scoped to live statuses, so a
// reference surviving only in the trash is not a reason to refuse a delete.
diviops_variable_reset();
$trashed              = diviops_test_register_post( 700103, 'references gvid-trashed', 'page', 'Trashed' );
$trashed->post_status = 'trash';
assert_same( false, diviops_call( 'variable_id_appears_anywhere', array( 'gvid-trashed' ) ), 'a trashed post is outside the existence probe' );

// (16) A post type outside SCANNABLE_POST_TYPES is invisible to the probe.
diviops_variable_reset();
diviops_test_register_post( 700104, 'references gvid-tbonly', 'et_theme_builder', 'Assignment record' );
assert_same( false, diviops_call( 'variable_id_appears_anywhere', array( 'gvid-tbonly' ) ), 'et_theme_builder is outside the probe, matching the structured scan' );

// (17) …while a Theme Builder LAYOUT is inside it.
diviops_variable_reset();
diviops_test_register_post( 700105, 'references gvid-footer', 'et_footer_layout', 'Footer' );
assert_same( true, diviops_call( 'variable_id_appears_anywhere', array( 'gvid-footer' ) ), 'et_footer_layout is inside the probe' );

// (18) Posts miss, preset registry hits. This is the branch the source calls
// out as the one a naive is_string() guard would drop: the option comes back
// already unserialized, so the check has to walk the tree.
diviops_variable_reset(
	array(
		'module' => array(
			'divi/text' => array(
				'items' => array(
					'p1' => array( 'name' => 'Body', 'attrs' => array( 'color' => diviops_variable_token( 'gvid-presetonly', 'color' ) ) ),
				),
			),
		),
	)
);
assert_same( true, diviops_call( 'variable_id_appears_anywhere', array( 'gvid-presetonly' ) ), 'a reference living only in the preset registry is found by the fast path' );
assert_same( false, diviops_call( 'variable_id_appears_anywhere', array( 'gvid-elsewhere' ) ), 'an id absent from both surfaces still reports absent with a populated registry' );

// ── collect_variable_refs: location records and dangling references ───────
//
// The post half of this scan needs parse_blocks() and is out of reach (see the
// file docblock). The preset half needs neither primitive, and it is where the
// richer location record lives.

diviops_variable_reset(
	array(
		'module' => array(
			'divi/text'   => array(
				'items' => array(
					'uuid-a' => array(
						'name'  => 'Body Copy',
						'attrs' => array( 'color' => diviops_variable_token( 'gcid-brand', 'color' ) ),
					),
					'uuid-b' => array(
						'name'  => 'Lead',
						// The same id twice inside one preset: ref_count counts
						// occurrences, the location record de-duplicates.
						'attrs'      => array( 'color' => diviops_variable_token( 'gcid-brand', 'color' ) ),
						'styleAttrs' => array( 'x' => diviops_variable_token( 'gcid-brand', 'color' ) ),
					),
				),
			),
			'divi/button' => array(
				'items' => array(
					'uuid-c' => array(
						// No `name` key at all — the location record must not fatal.
						'attrs' => array( 'pad' => diviops_variable_token( 'gvid-dangling' ) ),
					),
				),
			),
		),
		'group'  => array(
			'divi/section' => array(
				'items' => array(
					'uuid-d' => array( 'name' => 'Hero Group', 'attrs' => array( 'g' => diviops_variable_token( 'gvid-dangling' ) ) ),
				),
			),
		),
		// A third bucket Divi does not use for presets: the scan reads `module`
		// and `group` only, so anything filed elsewhere is not a reference.
		'other'  => array(
			'divi/text' => array( 'items' => array( 'uuid-e' => array( 'attrs' => array( diviops_variable_token( 'gvid-ignored' ) ) ) ) ),
		),
	)
);
// Two posts that carry no token, so the scan counts them without parsing.
diviops_test_register_post( 700200, 'nothing here', 'page', 'One' );
diviops_test_register_post( 700201, 'nor here', 'et_pb_layout', 'Two' );

$refs = diviops_call( 'collect_variable_refs' );

assert_same( array( 'gcid-brand' => 3, 'gvid-dangling' => 2 ), $refs['all_ids'], 'the preset scan counts every occurrence across module and group buckets' );
assert_same( false, $refs['scan_truncated'], 'a fixture set under the cap is not truncated' );
assert_same( 2, $refs['scanned_posts'], 'scanned_posts counts the posts the query returned, parsed or skipped' );
assert_same( 2, count( (array) ( $refs['locations']['gcid-brand'] ?? array() ) ), 'two presets reference gcid-brand, so it has two location records — not three, despite three occurrences' );
assert_same(
	array(
		'type'        => 'preset',
		'bucket'      => 'module',
		'module'      => 'divi/text',
		'preset_uuid' => 'uuid-a',
		'preset_name' => 'Body Copy',
	),
	( $refs['locations']['gcid-brand'][0] ?? array() ),
	'a preset location record carries bucket, module, uuid and name in that key order'
);
assert_same( '', ( $refs['locations']['gvid-dangling'][0]['preset_name'] ?? '(absent)' ), 'a preset with no name reports an empty name rather than fataling' );
assert_same( 'group', ( $refs['locations']['gvid-dangling'][1]['bucket'] ?? '(absent)' ), 'the group bucket is scanned as well as module' );
// A dangling reference — no such id exists in any Variable Manager bucket — is
// collected exactly like a live one. Distinguishing the two is
// variable_scan_orphans' job, not this scanner's, and this is the input that
// job would consume.
assert_true( isset( $refs['all_ids']['gvid-dangling'] ), 'a reference to an id that is defined nowhere is still collected' );
assert_true( ! isset( $refs['all_ids']['gvid-ignored'] ), 'buckets other than module/group are not scanned' );

// ── variable_list ─────────────────────────────────────────────────────────
//
// Every call here passes a `type`, because an unfiltered list reads the colours
// bucket through the absent et_get_option(). The seven valid types come from
// Divi's own store: GlobalData::get_global_variables() (GlobalData.php:921-929)
// per variable-bindings.md's "Storage buckets" section.

diviops_variable_reset();

$resp = diviops_call( 'variable_list', array( diviops_variable_request( array( 'type' => 'bogus' ) ) ) );
$data = $resp->get_data();
assert_same( false, $data['ok'], 'an unknown type returns an error envelope' );
assert_same( 'invalid_input', $data['error']['code'], 'an unknown type is invalid_input' );
assert_same( 400, $resp->get_status(), 'invalid_input carries HTTP 400' );
assert_same(
	'type must be one of: colors, numbers, strings, images, links, fonts, gradients.',
	$data['error']['message'],
	'the type message lists all seven buckets in Divi storage order'
);
assert_same(
	array( 'colors', 'numbers', 'strings', 'images', 'links', 'fonts', 'gradients' ),
	$data['error']['data']['allowed'],
	'error.data repeats the allowed set as an array'
);
assert_same( 'bogus', $data['error']['data']['received'], 'error.data echoes what was received' );

// (19) Sorting. The comparator is active-first, then numeric `order` ascending,
// then entries with no order at all, with a strcmp(label) tiebreak. Labels below
// are distinct so the expected order is total — uasort is only stable from PHP
// 8.0 and CI runs 7.4 as well.
diviops_variable_seed( 'numbers', 'gvid-n-c', array( 'label' => 'C label', 'value' => '3px', 'order' => 3 ) );
diviops_variable_seed( 'numbers', 'gvid-n-a', array( 'label' => 'A label', 'value' => '1px', 'order' => 1 ) );
diviops_variable_seed( 'numbers', 'gvid-n-noorder', array( 'label' => 'Z label', 'value' => '9px' ) );
diviops_variable_seed( 'numbers', 'gvid-n-archived', array( 'label' => 'B label', 'value' => '2px', 'order' => 2, 'status' => 'archived' ) );
diviops_variable_seed( 'numbers', 'gvid-n-b', array( 'label' => 'D label', 'value' => '4px', 'order' => 2 ) );
// A non-array entry is dropped outright by the array_filter( …, 'is_array' ).
$registry                       = diviops_variable_registry();
$registry['numbers']['gvid-junk'] = 'not-a-record';
$GLOBALS['diviops_test_options']['et_divi_global_variables'] = $registry;

$resp = diviops_call( 'variable_list', array( diviops_variable_request( array( 'type' => 'numbers' ) ) ) );
$data = $resp->get_data();
assert_same( true, $data['ok'], 'a valid type filter succeeds' );
assert_same(
	array( 'gvid-n-a', 'gvid-n-b', 'gvid-n-c', 'gvid-n-noorder', 'gvid-n-archived' ),
	array_column( (array) ( $data['data']['variables'] ?? array() ), 'id' ),
	'active entries sort by order ascending, then order-less entries, then non-active ones'
);
assert_same( 5, $data['data']['count'], 'count matches the emitted rows, with the non-array entry dropped' );

// (20) The per-row projection. `__id`, stamped on a local copy for the colour
// comparator, must not leak; `order`/`lastUpdated` default to null rather than
// being omitted, so a consumer can rely on the key set.
$row = (array) ( $data['data']['variables'][3] ?? array() );
assert_same( 'gvid-n-noorder', $row['id'], 'the order-less row is where the sort put it' );
assert_same(
	array( 'id', 'type', 'label', 'value', 'order', 'status', 'lastUpdated' ),
	array_keys( (array) $row ),
	'every row carries exactly these seven keys, in this order'
);
assert_same( null, $row['order'], 'a missing order is reported as null, not omitted' );
assert_same( null, $row['lastUpdated'], 'a missing lastUpdated is reported as null' );
assert_same( 'active', $row['status'], 'a missing status defaults to active' );
assert_same( 'numbers', $row['type'], 'the row carries its bucket as `type`' );

// (21) A record with neither a label nor a value falls back to its own id and
// to an empty string — the `?? $id` / `?? ''` coercions in the projection.
diviops_variable_seed( 'strings', 'gvid-s-bare', array() );
$resp = diviops_call( 'variable_list', array( diviops_variable_request( array( 'type' => 'strings' ) ) ) );
$data = $resp->get_data();
assert_same( 'gvid-s-bare', $data['data']['variables'][0]['label'], 'a record with no label reports its id as the label' );
assert_same( '', $data['data']['variables'][0]['value'], 'a record with no value reports an empty string' );

// (22) The prefix filter is a plain strpos-at-0 test, not an id match, and it
// is applied AFTER the sort — so it narrows the result without reordering it,
// and the demoted `archived` entry stays last.
$resp = diviops_call( 'variable_list', array( diviops_variable_request( array( 'type' => 'numbers', 'prefix' => 'gvid-n-a' ) ) ) );
$data = $resp->get_data();
assert_same(
	array( 'gvid-n-a', 'gvid-n-archived' ),
	array_column( (array) ( $data['data']['variables'] ?? array() ), 'id' ),
	'prefix is a raw string prefix, so gvid-n-a also matches gvid-n-archived, and the sort order survives the filter'
);
assert_same( 2, $data['data']['count'], 'count reflects the filtered set' );
$resp = diviops_call( 'variable_list', array( diviops_variable_request( array( 'type' => 'numbers', 'prefix' => 'gvid-n-c' ) ) ) );
assert_same( array( 'gvid-n-c' ), array_column( (array) ( $resp->get_data()['data']['variables'] ?? array() ), 'id' ), 'a prefix matching one id narrows to it' );

// (23) A type with no bucket in storage is an empty list, not an error.
$resp = diviops_call( 'variable_list', array( diviops_variable_request( array( 'type' => 'gradients' ) ) ) );
$data = $resp->get_data();
assert_same( true, $data['ok'], 'a type with nothing stored still succeeds' );
assert_same( 0, $data['data']['count'], 'an absent bucket lists zero variables' );

// (24) The `_meta` block is stamped onto the envelope body after
// envelope_success() built it, and documents that this tool reads two parallel
// storages rather than probing one path.
assert_same(
	'et_divi.et_global_data.global_colors+et_divi_global_variables',
	$data['_meta']['source_path'],
	'_meta names both storages as the source path'
);
assert_same(
	array( 'et_divi.et_global_data.global_colors', 'et_divi_global_variables' ),
	$data['_meta']['probed_paths'],
	'_meta lists the two probed paths'
);
assert_same(
	'variable_list reads from two parallel storages by type (colors vs others); single-path probe contract does not apply. Surfaced for #719 symmetry.',
	$data['_meta']['note'],
	'_meta explains why the single-path probe contract does not apply'
);
assert_same( array( 'ok', 'data', '_meta' ), array_keys( $data ), '_meta sits beside ok/data at the envelope top level, not inside data' );

// ── The fluid-clamp primitives ────────────────────────────────────────────

assert_same( 20.0, diviops_call( 'parse_fluid_px', array( '20px' ) ), 'a plain px value parses to a float' );
assert_same( 20.0, diviops_call( 'parse_fluid_px', array( '  20px  ' ) ), 'surrounding whitespace is trimmed before matching' );
assert_same( 0.5, diviops_call( 'parse_fluid_px', array( '.5px' ) ), 'a leading-dot CSS value parses' );
assert_same( -0.2, diviops_call( 'parse_fluid_px', array( '-.2px' ) ), 'a negative leading-dot value parses' );
assert_same( null, diviops_call( 'parse_fluid_px', array( '20' ) ), 'a unitless number is not a px value' );
assert_same( null, diviops_call( 'parse_fluid_px', array( '1.25rem' ) ), 'rem is not accepted where px is required' );
assert_same( null, diviops_call( 'parse_fluid_px', array( 20 ) ), 'a non-string is refused rather than cast' );

// A viewport anchor must be positive: a viewport of 0 or less has no slope.
assert_same( 320.0, diviops_call( 'parse_fluid_viewport', array( '320px' ) ), 'a positive px viewport parses' );
assert_same( null, diviops_call( 'parse_fluid_viewport', array( '0px' ) ), 'a zero viewport is refused' );
assert_same( null, diviops_call( 'parse_fluid_viewport', array( '-5px' ) ), 'a negative viewport is refused' );

assert_same( array( 'num' => 20.0, 'unit' => 'px' ), diviops_call( 'parse_size_with_unit', array( '20px' ) ), 'px sizes carry their unit through' );
assert_same( array( 'num' => 1.25, 'unit' => 'rem' ), diviops_call( 'parse_size_with_unit', array( '1.25rem' ) ), 'rem sizes carry their unit through' );
assert_same( null, diviops_call( 'parse_size_with_unit', array( '2em' ) ), 'em is not one of the two accepted units' );
assert_same( null, diviops_call( 'parse_size_with_unit', array( '20' ) ), 'a unitless size is refused' );

// number_format to 3 decimals, trailing zeros trimmed, then a bare trailing dot
// trimmed, then negative zero normalised.
assert_same( '2.5', diviops_call( 'format_fluid_decimal', array( 2.5 ) ), 'a trailing zero is trimmed' );
assert_same( '12', diviops_call( 'format_fluid_decimal', array( 12.0 ) ), 'a whole number emits no decimal point' );
assert_same( '100', diviops_call( 'format_fluid_decimal', array( 100.0 ) ), 'integral zeros before the point survive the rtrim' );
assert_same( '1.001', diviops_call( 'format_fluid_decimal', array( 1.0005 ) ), 'the fourth decimal is rounded, not truncated' );
// This pins the emitted string, not the `'-0' === $s` guard behind it. PHP 8.0
// stopped letting number_format() return a signed zero, so on the 8.3/8.5 legs
// `number_format( -0.0001, 3, '.', '' )` is already "0.000" and that guard never
// runs; on the 7.4 leg CI also runs it does. Verified on PHP 8.5.10: no input
// that rounds to zero produces a "-0.000" to normalise.
assert_same( '0', diviops_call( 'format_fluid_decimal', array( -0.0001 ) ), 'a value that formats to -0 is normalised to 0' );
assert_same( '0', diviops_call( 'format_fluid_decimal', array( 0.0 ) ), 'zero formats as a bare 0' );

// 1rem = root px. 32px at a 16px root is 2rem; at a 10px root (the 62.5% reset)
// it is 3.2rem — this is the whole reason root_font_size_px exists.
assert_same( '32px', diviops_call( 'format_fluid_size', array( 32.0, 'px', 16.0 ) ), 'px output ignores the root font size' );
assert_same( '2rem', diviops_call( 'format_fluid_size', array( 32.0, 'rem', 16.0 ) ), 'rem output divides by the default 16px root' );
assert_same( '3.2rem', diviops_call( 'format_fluid_size', array( 32.0, 'rem', 10.0 ) ), 'a declared 10px root changes the emitted rem value' );

// The slope term is viewport-pixel-rooted, so it stays vw whatever the output
// unit is — converting it would break the arithmetic against the rem anchors.
assert_same( '2.5vw', diviops_call( 'format_fluid_slope', array( 2.5 ) ), 'the slope is always emitted in vw' );

// ── build_fluid_clamp ─────────────────────────────────────────────────────
//
// slope_vw = (v2 - v1) / (w2 - w1) * 100 ; base_px = v1 - slope_vw * w1 / 100
// min/max are ordered by VALUE, not by viewport, so a negative slope still
// emits a well-formed clamp.

// (320,20) → (1920,60): slope = 40/1600*100 = 2.5 ; base = 20 - 2.5*320/100 = 12
assert_same(
	'clamp(20px, 12px + 2.5vw, 60px)',
	diviops_call( 'build_fluid_clamp', array( 320.0, 20.0, 1920.0, 60.0, 'px', 16.0 ) ),
	'a rising clamp emits min, base + slope, max'
);
// Same anchors in rem at a 16px root: 20/16 = 1.25 ; 12/16 = 0.75 ; 60/16 = 3.75
assert_same(
	'clamp(1.25rem, 0.75rem + 2.5vw, 3.75rem)',
	diviops_call( 'build_fluid_clamp', array( 320.0, 20.0, 1920.0, 60.0, 'rem', 16.0 ) ),
	'rem output converts every size term but leaves the vw slope alone'
);
// (320,60) → (1920,20): slope = -40/1600*100 = -2.5 ; base = 60 + 2.5*320/100 = 68
// min_v = 20 and max_v = 60, i.e. ordered by value rather than by anchor.
assert_same(
	'clamp(20px, 68px - 2.5vw, 60px)',
	diviops_call( 'build_fluid_clamp', array( 320.0, 60.0, 1920.0, 20.0, 'px', 16.0 ) ),
	'a falling clamp flips the operator and orders min/max by value, not by viewport'
);
// |v2 - v1| < 0.01 collapses to a scalar: there is nothing to interpolate.
assert_same(
	'20px',
	diviops_call( 'build_fluid_clamp', array( 320.0, 20.0, 1920.0, 20.005, 'px', 16.0 ) ),
	'two anchors with effectively equal values collapse to a scalar, not a degenerate clamp'
);
// |w2 - w1| < 0.01 is the genuine algorithmic failure: the slope is undefined.
// It raises a PLAIN InvalidArgumentException, deliberately not the input-shape
// marker, so the handler routes it to variable.fluid_*_failed.
$clamp_error = null;
try {
	diviops_call( 'build_fluid_clamp', array( 320.0, 20.0, 320.005, 60.0, 'px', 16.0 ) );
} catch ( Throwable $e ) {
	$clamp_error = $e;
}
assert_true( $clamp_error instanceof InvalidArgumentException, 'collapsed viewports raise InvalidArgumentException' );
assert_same( false, $clamp_error instanceof DiviOps_Variable_Input_Exception, 'the collapsed-viewport failure is NOT the input-shape marker — that distinction is what picks the error code' );
assert_same( 'Fluid anchors cannot share the same viewport.', diviops_variable_error_text( $clamp_error ), 'the collapsed-viewport message is stable' );

// ── plain_exception_value: what a rejection is allowed to echo back ───────

assert_same( 'array', diviops_call( 'plain_exception_value', array( array( 1, 2 ) ) ), 'an array is described, not dumped' );
assert_same( 'stdClass', diviops_call( 'plain_exception_value', array( new stdClass() ) ), 'an object with no __toString reports its class' );
assert_same( 'NULL', diviops_call( 'plain_exception_value', array( null ) ), 'null reports its gettype spelling' );
assert_same( '1', diviops_call( 'plain_exception_value', array( true ) ), 'a boolean casts through PHP string semantics' );
assert_same( 'a\\nb', diviops_call( 'plain_exception_value', array( "a\nb" ) ), 'a newline is escaped to a literal \\n so the message stays one line' );
assert_same( 'ab', diviops_call( 'plain_exception_value', array( "a\x00b" ) ), 'control bytes are stripped rather than escaped' );
assert_same( 'x', diviops_call( 'plain_exception_value', array( '  x  ' ) ), 'the result is trimmed' );

// ── generate_fluid_clamp_from_minmax / _from_targets ──────────────────────

// The shorthand's anchors are fixed at 320/1920 — the "wide" convention.
assert_same(
	'clamp(20px, 12px + 2.5vw, 60px)',
	diviops_call( 'generate_fluid_clamp_from_minmax', array( '20px', '60px', 'px', 16.0 ) ),
	'the min/max shorthand uses 320px and 1920px anchors'
);
// rem inputs are converted to px BEFORE the slope math, using the declared root:
// 1.25rem @16 = 20px, 3.75rem @16 = 60px — the same clamp as above.
assert_same(
	'clamp(20px, 12px + 2.5vw, 60px)',
	diviops_call( 'generate_fluid_clamp_from_minmax', array( '1.25rem', '3.75rem', 'px', 16.0 ) ),
	'rem inputs are converted to px through the declared root before the slope is computed'
);
$minmax_error = null;
try {
	diviops_call( 'generate_fluid_clamp_from_minmax', array( 'twenty', '60px', 'px', 16.0 ) );
} catch ( Throwable $e ) {
	$minmax_error = $e;
}
assert_true( $minmax_error instanceof DiviOps_Variable_Input_Exception, 'an unparseable min is an input-shape rejection, not an algorithmic failure' );
assert_same( "Invalid min: 'twenty' — expected e.g. '20px' or '1.25rem'.", diviops_variable_error_text( $minmax_error ), 'the min rejection names the field and shows an example' );

$targets_error = null;
try {
	diviops_call( 'generate_fluid_clamp_from_targets', array( array( '320px' => '20px' ), 'px', 16.0 ) );
} catch ( Throwable $e ) {
	$targets_error = $e;
}
assert_true( $targets_error instanceof DiviOps_Variable_Input_Exception, 'a one-entry targets map is an input-shape rejection' );
assert_same(
	"targets must contain exactly 2 entries keyed by viewport width (e.g. {'320px':'20px','1920px':'60px'}).",
	diviops_variable_error_text( $targets_error ),
	'the targets arity message shows the expected shape'
);
assert_same(
	'clamp(20px, 12px + 2.5vw, 60px)',
	diviops_call( 'generate_fluid_clamp_from_targets', array( array( '320px' => '20px', '1920px' => '60px' ), 'px', 16.0 ) ),
	'explicit targets reproduce the shorthand clamp when they name the same anchors'
);
$targets_error = null;
try {
	diviops_call( 'generate_fluid_clamp_from_targets', array( array( '20rem' => '20px', '1920px' => '60px' ), 'px', 16.0 ) );
} catch ( Throwable $e ) {
	$targets_error = $e;
}
assert_same( "Invalid viewport key '20rem' — expected px (e.g. '320px').", diviops_variable_error_text( $targets_error ), 'a rem viewport key is refused — a viewport in rem depends on the root at evaluation time' );

// ── fluid_request_has_rem_involvement ─────────────────────────────────────

assert_same( true, diviops_call( 'fluid_request_has_rem_involvement', array( null, null, null, 'rem' ) ), 'an explicit rem output_unit is rem involvement on its own' );
assert_same( true, diviops_call( 'fluid_request_has_rem_involvement', array( '1rem', '60px', null, null ) ), 'a rem min is rem involvement' );
assert_same( true, diviops_call( 'fluid_request_has_rem_involvement', array( null, null, array( '320px' => '1.5REM' ), null ) ), 'the check is case-insensitive, so 1.5REM counts' );
assert_same( false, diviops_call( 'fluid_request_has_rem_involvement', array( '20px', '60px', null, 'px' ) ), 'an all-px request is not rem involvement' );
// A stringable object is the only non-string that could carry "rem" into the
// check, so it is what makes the is_string() guard observable rather than
// merely believed.
assert_same(
	false,
	diviops_call( 'fluid_request_has_rem_involvement', array( new DiviOps_Variable_Char_Stringy( '1rem' ), '60px', null, null ) ),
	'a non-string min is skipped rather than stringified, even when its string form would say rem'
);

// ── validate_name_prefix / resolve_modular_ratio / resolve_fluid_anchors ──

/**
 * validate_name_prefix()'s accepted value, or the text it refused with — total,
 * so a change that turns an accepted prefix into a refusal reports as a named
 * failure rather than an uncaught exception.
 *
 * @param mixed  $input   Caller-supplied prefix.
 * @param string $field   Field name for the message.
 * @param string $default Fallback.
 * @return string
 */
function diviops_variable_prefix( $input, string $field, string $default ): string {
	try {
		return (string) diviops_call( 'validate_name_prefix', array( $input, $field, $default ) );
	} catch ( Throwable $e ) {
		return 'refused: ' . $e->getMessage();
	}
}

assert_same( 'h', diviops_variable_prefix( null, 'typography.name_prefix', 'h' ), 'a null prefix falls back to the default' );
assert_same( 'h', diviops_variable_prefix( '', 'typography.name_prefix', 'h' ), 'an empty prefix falls back to the default' );
assert_same( 'hd', diviops_variable_prefix( 'HD', 'typography.name_prefix', 'h' ), 'a prefix is lowercased, because Divi lowercases when it resolves ids' );
assert_same( 'a_b-c9', diviops_variable_prefix( 'a_b-c9', 'x', 'h' ), 'underscore, hyphen and digits are all inside the accepted charset' );
$prefix_error = null;
try {
	diviops_call( 'validate_name_prefix', array( 'has space', 'typography.name_prefix', 'h' ) );
} catch ( Throwable $e ) {
	$prefix_error = $e;
}
assert_true( $prefix_error instanceof DiviOps_Variable_Input_Exception, 'an out-of-charset prefix is an input-shape rejection' );
assert_true(
	false !== strpos( diviops_variable_error_text( $prefix_error ), "typography.name_prefix 'has space' contains characters outside [a-z0-9-_]" ),
	'the charset rejection names both the field and the offending value'
);
$prefix_error = null;
try {
	diviops_call( 'validate_name_prefix', array( 42, 'namespace', 'oa' ) );
} catch ( Throwable $e ) {
	$prefix_error = $e;
}
assert_same( 'namespace must be a string.', diviops_variable_error_text( $prefix_error ), 'a non-string prefix is refused before the charset check' );

// The eight named ratios. Numeric ratios bypass the map entirely.
assert_same( 1.25, diviops_call( 'modular_scale_ratio', array( 'major-third' ) ), 'major-third is 1.25' );
assert_same( 1.618, diviops_call( 'modular_scale_ratio', array( 'golden' ) ), 'golden is 1.618' );
assert_same( null, diviops_call( 'modular_scale_ratio', array( 'not-a-scale' ) ), 'an unknown name resolves to null for the caller to reject' );
assert_same( 1.5, diviops_call( 'resolve_modular_ratio', array( '1.5' ) ), 'a numeric string resolves as a number' );
assert_same( 1.333, diviops_call( 'resolve_modular_ratio', array( 'perfect-fourth' ) ), 'a named scale resolves through the map' );
$ratio_error = null;
try {
	diviops_call( 'resolve_modular_ratio', array( 0 ) );
} catch ( Throwable $e ) {
	$ratio_error = $e;
}
assert_same( 'Modular ratio must be a positive number.', diviops_variable_error_text( $ratio_error ), 'a zero ratio is refused' );
$ratio_error = null;
try {
	diviops_call( 'resolve_modular_ratio', array( 'diminished-ninth' ) );
} catch ( Throwable $e ) {
	$ratio_error = $e;
}
assert_true(
	false !== strpos( diviops_variable_error_text( $ratio_error ), "Unknown modular ratio name 'diminished-ninth'." ),
	'an unknown ratio name is echoed back with the accepted list'
);

// divi-default 360/1350 mirrors Divi 5.4.0's Variable Generator Modal;
// wide 320/1920 matches variable_create's own shorthand anchors.
assert_same( array( 360.0, 1350.0 ), diviops_call( 'resolve_fluid_anchors', array( 'divi-default', null ) ), 'divi-default anchors are 360/1350' );
assert_same( array( 320.0, 1920.0 ), diviops_call( 'resolve_fluid_anchors', array( 'wide', null ) ), 'wide anchors are 320/1920' );
assert_same(
	array( 400.0, 1600.0 ),
	diviops_call( 'resolve_fluid_anchors', array( 'custom', array( 'min_viewport_px' => 400, 'max_viewport_px' => 1600 ) ) ),
	'custom anchors are cast to float and returned in min/max order'
);
$anchor_error = null;
try {
	diviops_call( 'resolve_fluid_anchors', array( 'custom', null ) );
} catch ( Throwable $e ) {
	$anchor_error = $e;
}
assert_same( 'profile="custom" requires custom_anchors: {min_viewport_px, max_viewport_px}.', diviops_variable_error_text( $anchor_error ), 'custom without anchors is refused' );
$anchor_error = null;
try {
	diviops_call( 'resolve_fluid_anchors', array( 'custom', array( 'min_viewport_px' => 1600, 'max_viewport_px' => 400 ) ) );
} catch ( Throwable $e ) {
	$anchor_error = $e;
}
assert_same( 'custom_anchors must provide positive min_viewport_px and max_viewport_px with max > min.', diviops_variable_error_text( $anchor_error ), 'an inverted custom range is refused' );
$anchor_error = null;
try {
	diviops_call( 'resolve_fluid_anchors', array( 'narrow', null ) );
} catch ( Throwable $e ) {
	$anchor_error = $e;
}
assert_same( "Unknown profile 'narrow' — expected 'divi-default', 'wide', or 'custom'.", diviops_variable_error_text( $anchor_error ), 'an unknown profile is echoed back with the accepted set' );

// ── compute_typography_scale / compute_size_scale ─────────────────────────
//
// Step N = base_px × ratio^(steps - N), so step 1 is the LARGEST size and step
// `steps` is the base body size — h1 down to hN, mirroring HTML headings.

$typography = diviops_call(
	'compute_typography_scale',
	array( array( 'base_px' => 16, 'steps' => 3 ), array( 320.0, 1920.0 ), 'px', 16.0, 'oa' )
);
// ratio defaults to 1.25 and fluid_growth to 1.0, so small == large at every
// step and each clamp collapses to a scalar: 16×1.25² = 25, 16×1.25 = 20, 16.
assert_same(
	array(
		array( 'id' => 'gvid-oa-size-h1', 'value' => '25px', 'label' => 'Size H1' ),
		array( 'id' => 'gvid-oa-size-h2', 'value' => '20px', 'label' => 'Size H2' ),
		array( 'id' => 'gvid-oa-size-h3', 'value' => '16px', 'label' => 'Size H3' ),
	),
	$typography,
	'the default typography scale runs largest-first and is discrete until fluid_growth opts in'
);

// fluid_growth = 2.0 at wide anchors with one step: small 16, large 32.
// slope = 16/1600*100 = 1 ; base = 16 - 1*320/100 = 12.8
$typography = diviops_call(
	'compute_typography_scale',
	array( array( 'base_px' => 16, 'steps' => 1, 'fluid_growth' => 2.0 ), array( 320.0, 1920.0 ), 'px', 16.0, 'oa' )
);
assert_same( 'clamp(16px, 12.8px + 1vw, 32px)', $typography[0]['value'], 'fluid_growth turns a discrete step into a clamp between base and base×growth' );
assert_same( 'gvid-oa-size-h1', $typography[0]['id'], 'typography ids are gvid-{namespace}-size-{prefix}{n}' );

// name_prefix flows into both the id and the label, uppercased in the label.
$typography = diviops_call(
	'compute_typography_scale',
	array( array( 'base_px' => 16, 'steps' => 1, 'name_prefix' => 'title' ), array( 320.0, 1920.0 ), 'px', 16.0, 'brand' )
);
assert_same( 'gvid-brand-size-title1', $typography[0]['id'], 'the namespace and name_prefix both land in the id' );
assert_same( 'Size TITLE1', $typography[0]['label'], 'the label uppercases the prefix' );

foreach (
	array(
		array( array( 'base_px' => 0 ), 'typography.base_px must be a positive number (px).' ),
		array( array( 'base_px' => 16, 'steps' => 0 ), 'typography.steps must be between 1 and 20.' ),
		array( array( 'base_px' => 16, 'steps' => 21 ), 'typography.steps must be between 1 and 20.' ),
		array( array( 'base_px' => 16, 'fluid_growth' => 0 ), 'typography.fluid_growth must be a positive number (e.g. 1.0 for non-fluid, 1.25 for moderate growth).' ),
	) as $typography_index => $typography_case
) {
	$typography_error = null;
	try {
		diviops_call( 'compute_typography_scale', array( $typography_case[0], array( 320.0, 1920.0 ), 'px', 16.0, 'oa' ) );
	} catch ( Throwable $e ) {
		$typography_error = $e;
	}
	assert_same( $typography_case[1], diviops_variable_error_text( $typography_error ), 'typography bounds (case ' . $typography_index . '): ' . $typography_case[1] );
}

// Linear spacing: t = (n-1)/(steps-1), value = min + t × (max - min).
// 8 → 40 over 5 steps: 8, 16, 24, 32, 40.
$spacing = diviops_call(
	'compute_size_scale',
	array( array( 'min_px' => 8, 'max_px' => 40, 'steps' => 5 ), 'spacing', 'space', array( 320.0, 1920.0 ), 'px', 16.0, 'oa' )
);
assert_same( array( '8px', '16px', '24px', '32px', '40px' ), array_column( $spacing, 'value' ), 'a linear spacing scale is evenly spaced and discrete by default' );
assert_same(
	array( 'gvid-oa-space-1', 'gvid-oa-space-2', 'gvid-oa-space-3', 'gvid-oa-space-4', 'gvid-oa-space-5' ),
	array_column( $spacing, 'id' ),
	'size-scale ids are gvid-{namespace}-{prefix}-{n} — hyphenated, unlike the typography form'
);
assert_same( 'Space 1', $spacing[0]['label'], 'the size-scale label ucfirsts the prefix' );

// Geometric: value = min × (max/min)^t. 4 → 16 over 3 steps: 4, 8, 16.
$radius = diviops_call(
	'compute_size_scale',
	array( array( 'min_px' => 4, 'max_px' => 16, 'steps' => 3, 'scale' => 'geometric' ), 'radius', 'rounded', array( 320.0, 1920.0 ), 'px', 16.0, 'oa' )
);
assert_same( array( '4px', '8px', '16px' ), array_column( $radius, 'value' ), 'a geometric scale is evenly spaced multiplicatively' );
assert_same( 'Rounded 1', $radius[0]['label'], 'the radius default prefix is "rounded"' );

// fluid_growth on a multi-step scale makes each step a clamp from its own value
// to that value × growth — a different shape from the single-step min→max span
// below, and the only place the growth multiplier is observable on a size scale.
// Step 1 = 8 → 16: slope = 8/1600*100 = 0.5 ; base = 8 - 0.5*320/100 = 6.4
// Step 2 = 40 → 80: slope = 40/1600*100 = 2.5 ; base = 40 - 2.5*320/100 = 32
$grown = diviops_call(
	'compute_size_scale',
	array( array( 'min_px' => 8, 'max_px' => 40, 'steps' => 2, 'fluid_growth' => 2.0 ), 'spacing', 'space', array( 320.0, 1920.0 ), 'px', 16.0, 'oa' )
);
assert_same(
	array( 'clamp(8px, 6.4px + 0.5vw, 16px)', 'clamp(40px, 32px + 2.5vw, 80px)' ),
	array_column( $grown, 'value' ),
	'fluid_growth scales each step from its own value to value × growth'
);

// A single step ignores `scale` and `fluid_growth` and spans min→max across the
// viewport instead — the most useful shape when there is no scale to walk.
// slope = (40-8)/1600*100 = 2 ; base = 8 - 2*320/100 = 1.6
$single = diviops_call(
	'compute_size_scale',
	array( array( 'min_px' => 8, 'max_px' => 40, 'steps' => 1, 'fluid_growth' => 3.0 ), 'spacing', 'space', array( 320.0, 1920.0 ), 'px', 16.0, 'oa' )
);
assert_same( 'clamp(8px, 1.6px + 2vw, 40px)', $single[0]['value'], 'a single step spans min→max across the viewport and ignores fluid_growth' );

foreach (
	array(
		array( array( 'min_px' => 8, 'max_px' => 0 ), 'spacing.min_px must be ≥ 0 and spacing.max_px must be > 0.' ),
		array( array( 'min_px' => -1, 'max_px' => 40 ), 'spacing.min_px must be ≥ 0 and spacing.max_px must be > 0.' ),
		array( array( 'min_px' => 40, 'max_px' => 8 ), 'spacing.max_px must be ≥ spacing.min_px.' ),
		array( array( 'min_px' => 8, 'max_px' => 40, 'steps' => 31 ), 'spacing.steps must be between 1 and 30.' ),
		array( array( 'min_px' => 8, 'max_px' => 40, 'scale' => 'log' ), "spacing.scale must be 'linear' or 'geometric'." ),
		array( array( 'min_px' => 0, 'max_px' => 40, 'scale' => 'geometric' ), "spacing.scale='geometric' requires min_px > 0 (geometric step from 0 is undefined)." ),
		array( array( 'min_px' => 8, 'max_px' => 40, 'fluid_growth' => 0 ), 'spacing.fluid_growth must be a positive number (1.0 = discrete, > 1 = fluid).' ),
	) as $size_index => $size_case
) {
	$size_error = null;
	try {
		diviops_call( 'compute_size_scale', array( $size_case[0], 'spacing', 'space', array( 320.0, 1920.0 ), 'px', 16.0, 'oa' ) );
	} catch ( Throwable $e ) {
		$size_error = $e;
	}
	assert_same( $size_case[1], diviops_variable_error_text( $size_error ), 'size-scale bounds (case ' . $size_index . '): ' . $size_case[1] );
}

// ── build_gradient_variable_value ─────────────────────────────────────────
//
// The canonical definition-form token, per variable-bindings.md's Namespace 4:
// value.name is the literal string "gradient" and settings carries the whole
// gradient. A raw CSS string is the #921 footgun — Divi never defines the
// --gvid-… custom property for it, so it renders nothing.

$gradient = diviops_call(
	'build_gradient_variable_value',
	array(
		null,
		array( 'stops' => array( array( 'position' => '0', 'color' => '#0d2240' ), array( 'position' => '100', 'color' => '#7a2582' ) ) ),
	)
);
assert_same(
	'$variable({"type":"gradient","value":{"name":"gradient","settings":{"enabled":"on","stops":[{"position":"0","color":"#0d2240"},{"position":"100","color":"#7a2582"}],"length":"100%","type":"linear","direction":"180deg","directionRadial":"center","repeat":"off","overlaysImage":"off"}}})$',
	$gradient,
	'the built token carries Divi\'s eight settings keys with the documented defaults'
);
$gradient = diviops_call(
	'build_gradient_variable_value',
	array(
		null,
		array(
			'stops'           => array( array( 'position' => '0', 'color' => '#000' ), array( 'position' => '100', 'color' => '#fff' ) ),
			'type'            => 'conic',
			'direction'       => '90deg',
			'directionRadial' => 'top left',
			'length'          => '50%',
			'repeat'          => 'on',
			'overlaysImage'   => 'on',
		),
	)
);
assert_true( false !== strpos( $gradient, '"type":"conic"' ), 'an explicit gradient type is honoured' );
assert_true( false !== strpos( $gradient, '"repeat":"on"' ), 'repeat is on only for the literal string "on"' );
assert_true( false !== strpos( $gradient, '"overlaysImage":"on"' ), 'overlaysImage is on only for the literal string "on"' );
$gradient = diviops_call(
	'build_gradient_variable_value',
	array(
		null,
		array(
			'stops'  => array( array( 'position' => '0', 'color' => '#000' ), array( 'position' => '100', 'color' => '#fff' ) ),
			'repeat'        => true,
			'overlaysImage' => 1,
		),
	)
);
assert_true( false !== strpos( $gradient, '"repeat":"off"' ), 'a boolean true is not the literal "on", so repeat stays off' );
assert_true( false !== strpos( $gradient, '"overlaysImage":"off"' ), 'a truthy 1 is not the literal "on" either, so overlaysImage stays off' );

foreach (
	array(
		array( array( 'stops' => array( array( 'position' => '0', 'color' => '#000' ) ) ), 'gradient.stops must be an array of at least 2 {position, color} entries.', 'gradient.stops' ),
		array( array( 'stops' => array() ), 'gradient.stops must be an array of at least 2 {position, color} entries.', 'gradient.stops' ),
		array( array( 'stops' => array( array( 'position' => '0', 'color' => '#000' ), array( 'position' => '100' ) ) ), 'gradient.stops[1] requires both position and color.', 'gradient.stops[1]' ),
	) as $gradient_index => $gradient_case
) {
	$rejected = diviops_call( 'build_gradient_variable_value', array( null, $gradient_case[0] ) );
	assert_true( $rejected instanceof WP_REST_Response, 'a malformed gradient is an envelope, not a value (case ' . $gradient_index . '): ' . $gradient_case[1] );
	$body = diviops_variable_envelope( $rejected );
	assert_same( $gradient_case[1], $body['error']['message'], 'gradient rejection message (case ' . $gradient_index . '): ' . $gradient_case[1] );
	assert_same( $gradient_case[2], $body['error']['data']['field'], 'gradient rejection names the failing field (case ' . $gradient_index . '): ' . $gradient_case[2] );
}

// "radial" is the spelling callers reach for and is explicitly NOT accepted;
// Divi's four are linear, circular, elliptical, conic.
$rejected = diviops_call(
	'build_gradient_variable_value',
	array( null, array( 'stops' => array( array( 'position' => '0', 'color' => '#000' ), array( 'position' => '100', 'color' => '#fff' ) ), 'type' => 'radial' ) )
);
$body = diviops_variable_envelope( $rejected );
assert_same( 'gradient.type must be one of: linear, circular, elliptical, conic (not "radial").', $body['error']['message'], 'the gradient type message calls out "radial" by name' );

// Path 2 — a ready-made token is accepted verbatim, but only when it satisfies
// all four shape tests at once.
$verbatim = '$variable({"type":"gradient","value":{"name":"gradient","settings":{}}})$';
assert_same( $verbatim, diviops_call( 'build_gradient_variable_value', array( $verbatim, null ) ), 'a ready-made gradient token passes through byte for byte' );
assert_same( $verbatim, diviops_call( 'build_gradient_variable_value', array( '  ' . $verbatim . '  ', null ) ), 'a token is trimmed before the shape tests, and the trimmed form is what is stored' );
$rejected = diviops_call( 'build_gradient_variable_value', array( '$variable({"type":"color","value":{"name":"gcid-x","settings":{}}})$', null ) );
assert_true( $rejected instanceof WP_REST_Response, 'a well-formed token of the wrong namespace is refused' );
$rejected = diviops_call( 'build_gradient_variable_value', array( 'linear-gradient(90deg, #000, #fff)', null ) );
$body     = $rejected->get_data();
assert_same( 'invalid_input', $body['error']['code'], 'a raw CSS gradient string is invalid_input — the #921 footgun' );
assert_same( 'gradient|value', $body['error']['data']['field'], 'the CSS-string rejection names both accepted input fields' );
assert_same( 'linear-gradient(90deg, #000, #fff)', $body['error']['data']['received_value'], 'the CSS-string rejection echoes what was received' );

// ── variable_create: refusal paths ────────────────────────────────────────

diviops_variable_reset();

$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'colours', 'label' => 'X', 'value' => '1', 'id' => 'gvid-r15' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an unknown type is invalid_input on create' );
assert_same( 'colours', $data['error']['data']['received'], 'the type rejection echoes the misspelling' );

// output_unit and root_font_size_px are meaningless without fluid params, and
// are refused rather than ignored — an ignored parameter is a caller who thinks
// they asked for something.
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'X', 'value' => '10px', 'output_unit' => 'rem', 'id' => 'gvid-r1' ) ) ) );
$data = $resp->get_data();
assert_same( 'output_unit is only meaningful alongside min/max/targets. Remove it or add fluid params.', $data['error']['message'], 'output_unit without fluid params is refused' );
assert_same( 'output_unit', $data['error']['data']['rejected_field'], 'the refusal names the rejected field, not a `field` key' );
assert_same( array( 'min', 'max', 'targets' ), $data['error']['data']['missing'], 'the refusal lists what would make it meaningful' );

$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'X', 'value' => '10px', 'root_font_size_px' => 10, 'id' => 'gvid-r2' ) ) ) );
assert_same( 'root_font_size_px is only meaningful alongside min/max/targets. Remove it or add fluid params.', $resp->get_data()['error']['message'], 'root_font_size_px without fluid params is refused' );

$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'X', 'min' => '20px', 'max' => '60px', 'output_unit' => 'em', 'id' => 'gvid-r3' ) ) ) );
assert_same( "output_unit must be 'rem' or 'px', got 'em'.", $resp->get_data()['error']['message'], 'output_unit accepts exactly rem or px' );

$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'X', 'min' => '20px', 'max' => '60px', 'root_font_size_px' => 0, 'id' => 'gvid-r4' ) ) ) );
assert_same( "root_font_size_px must be a positive number (px), got '0'.", $resp->get_data()['error']['message'], 'a zero root font size is refused' );

$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'strings', 'label' => 'X', 'min' => '20px', 'max' => '60px', 'id' => 'gvid-r5' ) ) ) );
$data = $resp->get_data();
assert_same( 'Fluid clamp generation (min/max/targets) is only valid for type="numbers".', $data['error']['message'], 'fluid generation is numbers-only' );
assert_same( 'fluid_only', $data['error']['data']['context'], 'the numbers-only refusal is tagged fluid_only so it is distinguishable from the plain type rejection' );

$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'X', 'value' => '10px', 'min' => '20px', 'max' => '60px', 'id' => 'gvid-r6' ) ) ) );
$data = $resp->get_data();
assert_same( 'Cannot pass both value and min/max/targets — use one input mode.', $data['error']['message'], 'value and fluid params are mutually exclusive' );
assert_same( array( 'value', 'fluid_inputs' ), $data['error']['data']['conflict'], 'the conflict payload names both modes' );

$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'X', 'min' => '20px', 'max' => '60px', 'targets' => array( '320px' => '20px', '1920px' => '60px' ), 'id' => 'gvid-r7' ) ) ) );
assert_same( array( 'shorthand', 'targets' ), $resp->get_data()['error']['data']['conflict'], 'shorthand and targets are mutually exclusive' );

$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'X', 'min' => '20px', 'id' => 'gvid-r8' ) ) ) );
$data = $resp->get_data();
assert_same( 'Shorthand requires both min and max.', $data['error']['message'], 'a half-supplied shorthand is refused' );
assert_same( array( 'max' ), $data['error']['data']['missing'], 'the refusal names only the missing half' );

// The rem opt-in gate: rem emission bakes a root-font-size assumption into the
// vw slope, so the caller has to state which assumption they are accepting.
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'X', 'min' => '1.25rem', 'max' => '3.75rem', 'id' => 'gvid-r9' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'rem inputs without an opt-in are refused' );
assert_same( array( 'output_unit|root_font_size_px' ), $data['error']['data']['requires'], 'the gate names the two ways to opt in' );
assert_same(
	"Pass output_unit='rem' to accept the 1rem=16px default, or root_font_size_px:N to declare your site's actual root font-size.",
	$data['error']['hint'],
	'the rem gate is one of the few variable errors carrying a hint'
);

// An all-px request bypasses the gate: px emission is root-agnostic.
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'Fluid', 'id' => 'gvid-fluid-1', 'min' => '20px', 'max' => '60px' ) ) ) );
$data = $resp->get_data();
assert_same( true, $data['ok'], 'an all-px fluid request needs no opt-in ceremony' );
assert_same( 'clamp(20px, 12px + 2.5vw, 60px)', $data['data']['value'], 'the stored value is the generated clamp' );

// QUIRK: declaring a root font size on an ALL-PX request silently switches the
// output to rem. The source documents this as case (4) — "caller declared a
// root purely to trigger rem emission" — but nothing in the request said rem,
// and the same parameter on a non-fluid request is a hard refusal. Pinned as
// current behaviour; a change to it should be a deliberate one.
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'Implied', 'id' => 'gvid-fluid-2', 'min' => '20px', 'max' => '60px', 'root_font_size_px' => 10 ) ) ) );
// At a 10px root: 20px = 2rem, base 12px = 1.2rem, 60px = 6rem; slope unchanged.
assert_same( 'clamp(2rem, 1.2rem + 2.5vw, 6rem)', $resp->get_data()['data']['value'], 'QUIRK: root_font_size_px alone flips px-only input to rem output' );

// An explicit output_unit wins over the implication.
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'Explicit', 'id' => 'gvid-fluid-3', 'min' => '20px', 'max' => '60px', 'root_font_size_px' => 10, 'output_unit' => 'px' ) ) ) );
assert_same( 'clamp(20px, 12px + 2.5vw, 60px)', $resp->get_data()['data']['value'], 'an explicit output_unit overrides the root_font_size_px implication' );

// An input-shape failure inside the generator routes to invalid_input, carrying
// the field the caller actually used.
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'X', 'min' => 'twenty', 'max' => '60px', 'id' => 'gvid-r10' ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an unparseable min routes to invalid_input, not to a fluid failure code' );
assert_same( 'min/max', $data['error']['data']['field'], 'the shorthand form reports field=min/max' );
assert_same( 'px or rem string (e.g. "20px" or "1.25rem")', $data['error']['data']['expected'], 'the shorthand form documents the expected value shape' );
assert_same( array( 'min' => 'twenty', 'max' => '60px' ), $data['error']['data']['received'], 'the shorthand form echoes both halves back' );

$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'X', 'targets' => array( '320px' => 'twenty' ), 'id' => 'gvid-r11' ) ) ) );
$data = $resp->get_data();
assert_same( 'targets', $data['error']['data']['field'], 'the targets form reports field=targets' );
assert_same( 'object keyed by px viewport (e.g. {"320px":"20px","1920px":"60px"})', $data['error']['data']['expected'], 'the targets form documents the expected map shape' );

// A GENUINE algorithmic failure keeps its own namespaced code, which is the
// whole reason DiviOps_Variable_Input_Exception exists as a marker.
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'numbers', 'label' => 'X', 'targets' => array( '320px' => '20px', '320.005px' => '60px' ), 'id' => 'gvid-r12' ) ) ) );
$data = $resp->get_data();
assert_same( 'variable.fluid_generation_failed', $data['error']['code'], 'collapsed viewports keep the namespaced algorithmic code, not invalid_input' );
assert_same( 400, $resp->get_status(), 'the algorithmic failure still carries HTTP 400' );
assert_same( 'Fluid anchors cannot share the same viewport.', $data['error']['data']['reason'], 'error.data repeats the underlying reason' );
assert_same( 'Check that min/max/targets produce non-degenerate viewport and value ranges.', $data['error']['hint'], 'the algorithmic failure carries a remediation hint' );

// Non-scalar value with no fluid params and no structured gradient.
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'strings', 'label' => 'X', 'value' => array( 'a' ), 'id' => 'gvid-r13' ) ) ) );
$data = $resp->get_data();
assert_same( 'value must be a scalar string (or supply min/max/targets for type=numbers).', $data['error']['message'], 'an array value is refused' );
assert_same( 'array', $data['error']['data']['received'], 'error.data describes an array value as "array"' );
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'strings', 'label' => 'X', 'id' => 'gvid-r14' ) ) ) );
assert_same( 'null', $resp->get_data()['error']['data']['received'], 'a missing value is described as "null"' );

// The id prefix gate. gvid- for everything except colours, gcid- for colours.
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'strings', 'label' => 'X', 'value' => 'v', 'id' => 'gcid-wrong' ) ) ) );
$data = $resp->get_data();
assert_same( "Non-color variable ID must start with 'gvid-', got 'gcid-wrong'.", $data['error']['message'], 'a colour-prefixed id is refused for a non-colour type' );
assert_same( "string starting with 'gvid-'", $data['error']['data']['expected'], 'the id rejection documents the required prefix' );

// ── variable_create: writes ───────────────────────────────────────────────

diviops_variable_reset();

// dry_run plans the write, names the auto-id placeholder rather than minting a
// real one, and touches nothing.
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'strings', 'label' => 'Tagline', 'value' => 'Hello', 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_same( true, $data['data']['dry_run'], 'dry_run create reports the dry_run flag' );
assert_same( "Would create strings variable 'Tagline' (id: gvid-<auto>, value: Hello).", $data['data']['plan']['summary'], 'the plan summary names type, label, id and value' );
assert_same( 'variable.create', $data['data']['plan']['changes'][0]['kind'], 'the create plan kind is variable.create' );
assert_same( 'variable/strings/gvid-<auto>', $data['data']['plan']['changes'][0]['target'], 'the plan target is variable/{type}/{id}' );
assert_same(
	array( 'id' => 'gvid-<auto>', 'type' => 'strings', 'label' => 'Tagline', 'value' => 'Hello' ),
	$data['data']['plan']['changes'][0]['after'],
	'the plan after-state carries the four fields the response will echo'
);
assert_same( array(), diviops_variable_registry(), 'dry_run create wrote nothing to the registry' );

// The real write. Storage record and response shape are different shapes: the
// record carries order/status/lastUpdated the response never mentions.
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'strings', 'label' => 'Tagline', 'value' => '  Hello   World  ', 'id' => 'gvid-tag' ) ) ) );
$data = $resp->get_data();
assert_same(
	array( 'success' => true, 'id' => 'gvid-tag', 'type' => 'strings', 'label' => 'Tagline', 'value' => 'Hello World' ),
	$data['data'],
	'the create response echoes the sanitized value, not the raw input'
);
$record = diviops_variable_record( 'strings', 'gvid-tag' );
assert_same(
	array( 'id', 'label', 'value', 'order', 'status', 'lastUpdated', 'type' ),
	array_keys( (array) $record ),
	'the stored record carries seven keys in this order'
);
assert_same( 1, $record['order'], 'the first entry in an empty bucket is order 1' );
assert_same( 'active', $record['status'], 'a created variable is active' );
assert_same( 'strings', $record['type'], 'the record carries its bucket as `type`, redundantly with the array key' );
assert_true(
	1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', (string) $record['lastUpdated'] ),
	'lastUpdated is an ISO-8601 UTC stamp with milliseconds'
);

// order is max-existing + 1, so a deletion in the middle cannot cause a
// collision later.
diviops_variable_seed( 'strings', 'gvid-high', array( 'label' => 'High', 'value' => 'v', 'order' => 41 ) );
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'strings', 'label' => 'Next', 'value' => 'v', 'id' => 'gvid-next' ) ) ) );
assert_same( 42, diviops_variable_record( 'strings', 'gvid-next' )['order'] ?? null, 'order continues from the highest existing order, not from the entry count' );

// images/links bypass sanitize_text_field for esc_url_raw. The value below is
// one sanitize_text_field would visibly rewrite (whitespace collapse + trim),
// so the two branches are distinguishable rather than merely believed to differ.
$url = "  https://example.test/a\nb  ";
diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'links', 'label' => 'Link', 'value' => $url, 'id' => 'gvid-link' ) ) ) );
assert_same( $url, diviops_variable_record( 'links', 'gvid-link' )['value'] ?? null, 'a links value goes through esc_url_raw, not sanitize_text_field' );
diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'images', 'label' => 'Img', 'value' => $url, 'id' => 'gvid-img' ) ) ) );
assert_same( $url, diviops_variable_record( 'images', 'gvid-img' )['value'] ?? null, 'an images value goes through esc_url_raw too' );
diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'fonts', 'label' => 'Font', 'value' => $url, 'id' => 'gvid-font' ) ) ) );
assert_same( 'https://example.test/a b', diviops_variable_record( 'fonts', 'gvid-font' )['value'] ?? null, 'every other bucket goes through sanitize_text_field' );

// The gradients bucket routes through build_gradient_variable_value, so a raw
// CSS string is refused on create exactly as it is on update.
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'gradients', 'label' => 'G', 'value' => 'linear-gradient(90deg,#000,#fff)', 'id' => 'gvid-grad' ) ) ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'a CSS gradient string is refused on create' );
assert_same( array(), diviops_variable_bucket( 'gradients' ), 'the refused gradient wrote nothing' );

// A structured gradient reaches storage as the canonical token. `value` is not
// required at all in this mode — the gradient_structured branch is what lets
// the is_scalar guard above be skipped.
$resp = diviops_call(
	'variable_create',
	array(
		diviops_variable_request(
			array(
				'type'     => 'gradients',
				'label'    => 'G',
				'id'       => 'gvid-grad',
				'gradient' => array( 'stops' => array( array( 'position' => '0', 'color' => '#000' ), array( 'position' => '100', 'color' => '#fff' ) ) ),
			)
		),
	)
);
$data = $resp->get_data();
assert_same( true, $data['ok'], 'a structured gradient creates without a `value` param at all' );
assert_true( false !== strpos( (string) $data['data']['value'], '"name":"gradient"' ), 'the stored gradient value is the definition-form token' );

// QUIRK: create is an unconditional upsert. An id that already exists is
// overwritten with no conflict, and its `order` is reassigned to max+1 rather
// than preserved — the behaviour that motivated variable_update (#25). Every
// other create-shaped handler in this plugin reports a conflict instead.
diviops_variable_reset();
diviops_variable_seed( 'strings', 'gvid-dup', array( 'label' => 'Original', 'value' => 'first', 'order' => 1 ) );
diviops_variable_seed( 'strings', 'gvid-other', array( 'label' => 'Other', 'value' => 'x', 'order' => 9 ) );
$resp = diviops_call( 'variable_create', array( diviops_variable_request( array( 'type' => 'strings', 'label' => 'Replacement', 'value' => 'second', 'id' => 'gvid-dup' ) ) ) );
assert_same( true, $resp->get_data()['ok'], 'QUIRK: creating an existing id succeeds instead of reporting a conflict' );
assert_same( 'second', diviops_variable_record( 'strings', 'gvid-dup' )['value'] ?? null, 'QUIRK: the existing record is overwritten' );
assert_same( 10, diviops_variable_record( 'strings', 'gvid-dup' )['order'] ?? null, 'QUIRK: the overwrite reassigns order to max+1, losing the original position' );

// ── variable_create_fluid_system ──────────────────────────────────────────

diviops_variable_reset();

$resp = diviops_call( 'variable_create_fluid_system', array( diviops_variable_request( array( 'namespace' => 'my ns', 'typography' => array( 'base_px' => 16 ) ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a namespace outside the id charset is refused, not silently sanitized' );
assert_same( 'namespace', $data['error']['data']['field'], 'the namespace refusal names the field' );
assert_same( '[a-z0-9_-]+', $data['error']['data']['expected'], 'the namespace refusal documents the charset' );
assert_same( 'my ns', $data['error']['data']['received'], 'the namespace refusal echoes the raw input' );

$resp = diviops_call( 'variable_create_fluid_system', array( diviops_variable_request( array() ) ) );
$data = $resp->get_data();
assert_same( 'At least one of typography/spacing/radius must be provided.', $data['error']['message'], 'a request naming no category is refused' );
assert_same( array( 'typography|spacing|radius' ), $data['error']['data']['requires'], 'the refusal names the three categories' );

$resp = diviops_call( 'variable_create_fluid_system', array( diviops_variable_request( array( 'typography' => array( 'base_px' => 16 ), 'output_unit' => 'em' ) ) ) );
assert_same( "output_unit must be 'rem' or 'px'.", $resp->get_data()['error']['message'], 'output_unit is validated the same way as on create' );
$resp = diviops_call( 'variable_create_fluid_system', array( diviops_variable_request( array( 'typography' => array( 'base_px' => 16 ), 'root_font_size_px' => -1 ) ) ) );
assert_same( 'root_font_size_px must be a positive number (px).', $resp->get_data()['error']['message'], 'root_font_size_px is validated the same way as on create' );

// Unlike variable_create, output_unit and root_font_size_px are NOT refused
// here when no fluid params accompany them — every input to this tool is fluid
// by construction.
$resp = diviops_call( 'variable_create_fluid_system', array( diviops_variable_request( array( 'profile' => 'sideways', 'typography' => array( 'base_px' => 16 ) ) ) ) );
$data = $resp->get_data();
assert_same( "Unknown profile 'sideways' — expected 'divi-default', 'wide', or 'custom'.", $data['error']['message'], 'an unknown profile is refused' );
assert_same( array( 'divi-default', 'wide', 'custom' ), $data['error']['data']['allowed'], 'the profile refusal lists the three profiles' );

// A bounds failure inside a category routes to invalid_input and reports which
// categories were in play — the message names the field, error.data names the
// scope the caller has to look in.
$resp = diviops_call( 'variable_create_fluid_system', array( diviops_variable_request( array( 'typography' => array( 'base_px' => 16, 'steps' => 99 ), 'spacing' => array( 'min_px' => 8, 'max_px' => 40 ) ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'an out-of-bounds category is invalid_input' );
assert_same( 'typography.steps must be between 1 and 20.', $data['error']['message'], 'the message comes from the failing computation' );
assert_same( array( 'typography', 'spacing' ), $data['error']['data']['categories'], 'error.data lists every category the request asked for, not only the failing one' );
assert_same( 'Adjust the failing category (typography/spacing/radius) bounds — see error.data for context.', $data['error']['hint'], 'the bounds failure carries a remediation hint' );

// A degenerate custom anchor pair passes the anchor validation (max > min) but
// collapses inside build_fluid_clamp, so it keeps the namespaced algorithmic
// code rather than being reported as bad input.
$resp = diviops_call(
	'variable_create_fluid_system',
	array(
		diviops_variable_request(
			array(
				'profile'        => 'custom',
				'custom_anchors' => array( 'min_viewport_px' => 320, 'max_viewport_px' => 320.005 ),
				'typography'     => array( 'base_px' => 16, 'steps' => 1, 'fluid_growth' => 2.0 ),
			)
		),
	)
);
$data = $resp->get_data();
assert_same( 'variable.fluid_system_generation_failed', $data['error']['code'], 'a collapsed anchor range is an algorithmic failure, distinct from bad input' );
assert_same( 'custom', $data['error']['data']['profile'], 'error.data names the profile that produced it' );
assert_same( 'Fluid anchors cannot share the same viewport.', $data['error']['data']['reason'], 'error.data carries the underlying reason' );

// A within-plan id collision is caller-caused input, NOT a registry conflict:
// spacing and radius sharing a name_prefix generates the same id twice.
$resp = diviops_call(
	'variable_create_fluid_system',
	array(
		diviops_variable_request(
			array(
				'spacing' => array( 'min_px' => 8, 'max_px' => 40, 'steps' => 2, 'name_prefix' => 'x' ),
				'radius'  => array( 'min_px' => 4, 'max_px' => 16, 'steps' => 2, 'name_prefix' => 'x' ),
			)
		),
	)
);
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a within-plan id collision is invalid_input' );
assert_same( "Two generated entries share ID 'gvid-oa-x-1'. Adjust name_prefix in one of typography/spacing/radius to disambiguate.", $data['error']['message'], 'the collision message names the colliding id' );
assert_same( 'within_plan_id_collision', $data['error']['data']['conflict'], 'the collision is tagged so it is distinguishable from an existing-id skip' );
assert_same( 'gvid-oa-x-1', $data['error']['data']['colliding_id'], 'error.data names the colliding id separately' );
assert_same( array( 'typography' => null, 'spacing' => 'x', 'radius' => 'x' ), $data['error']['data']['received'], 'error.data reports all three name_prefixes, null for the absent category' );
assert_same( array(), diviops_variable_registry(), 'the collision wrote nothing' );

// The happy path. Persistence is a single write at the end, so a mid-batch
// failure leaves no half-written registry.
diviops_variable_reset();
$resp = diviops_call(
	'variable_create_fluid_system',
	array(
		diviops_variable_request(
			array(
				'profile'    => 'wide',
				'typography' => array( 'base_px' => 16, 'steps' => 2 ),
				'spacing'    => array( 'min_px' => 8, 'max_px' => 40, 'steps' => 2 ),
			)
		),
	)
);
$data = $resp->get_data();
assert_same( true, $data['ok'], 'a well-formed fluid system succeeds' );
assert_same( 'wide', $data['data']['profile'], 'the response echoes the profile' );
assert_same( array( 'min_viewport_px' => 320.0, 'max_viewport_px' => 1920.0 ), $data['data']['anchors'], 'the response reports the resolved anchors as floats' );
assert_same( 'px', $data['data']['output_unit'], 'the response reports the effective output unit' );
assert_same( false, $data['data']['dry_run'], 'the apply-mode response still carries a dry_run flag, set false' );
assert_same( 4, $data['data']['created_count'], 'both categories contributed their steps' );
assert_same( 0, $data['data']['skipped_count'], 'nothing was skipped on an empty registry' );
// typography: 16×1.25 = 20 then 16 ; spacing linear 8 → 40 over 2 steps: 8, 40
assert_same(
	array(
		array( 'id' => 'gvid-oa-size-h1', 'value' => '20px', 'label' => 'Size H1', 'overwrote' => false ),
		array( 'id' => 'gvid-oa-size-h2', 'value' => '16px', 'label' => 'Size H2', 'overwrote' => false ),
		array( 'id' => 'gvid-oa-space-1', 'value' => '8px', 'label' => 'Space 1', 'overwrote' => false ),
		array( 'id' => 'gvid-oa-space-2', 'value' => '40px', 'label' => 'Space 2', 'overwrote' => false ),
	),
	$data['data']['created'],
	'created lists typography before spacing, each entry flagged as a create rather than an overwrite'
);
$system_registry = diviops_variable_registry();
assert_same( array( 'numbers' ), array_keys( $system_registry ), 'every generated variable lands in the numbers bucket' );
assert_same( array( 1, 2, 3, 4 ), array_column( diviops_variable_bucket( 'numbers' ), 'order' ), 'orders are assigned sequentially across the whole batch' );
assert_same( 'numbers', $system_registry['numbers']['gvid-oa-size-h1']['type'], 'the stored record declares the numbers bucket' );

// overwrite=false leaves existing ids alone and reports them as skipped, with
// the value they kept.
$resp = diviops_call(
	'variable_create_fluid_system',
	array( diviops_variable_request( array( 'profile' => 'wide', 'typography' => array( 'base_px' => 32, 'steps' => 2 ) ) ) )
);
$data = $resp->get_data();
assert_same( 0, $data['data']['created_count'], 'nothing is created when every id already exists' );
assert_same(
	array(
		array( 'id' => 'gvid-oa-size-h1', 'reason' => 'exists', 'value' => '20px' ),
		array( 'id' => 'gvid-oa-size-h2', 'reason' => 'exists', 'value' => '16px' ),
	),
	$data['data']['skipped'],
	'a skipped entry reports the value it kept, not the value it would have had'
);
assert_same( '20px', diviops_variable_record( 'numbers', 'gvid-oa-size-h1' )['value'] ?? null, 'the existing value survives an overwrite=false run' );

// overwrite=true rewrites in place and PRESERVES the existing order, so the
// Variable Manager's display order survives a regeneration.
$resp = diviops_call(
	'variable_create_fluid_system',
	array( diviops_variable_request( array( 'profile' => 'wide', 'typography' => array( 'base_px' => 32, 'steps' => 2 ), 'overwrite' => true ) ) )
);
$data = $resp->get_data();
assert_same( 2, $data['data']['created_count'], 'overwrite=true reports the rewrites under created' );
assert_same( true, $data['data']['created'][0]['overwrote'], 'the overwrote flag distinguishes an update from a create' );
// 32×1.25 = 40, then 32.
assert_same( '40px', diviops_variable_record( 'numbers', 'gvid-oa-size-h1' )['value'] ?? null, 'the value is rewritten' );
assert_same( 1, diviops_variable_record( 'numbers', 'gvid-oa-size-h1' )['order'] ?? null, 'the original order is preserved across an overwrite' );

// dry_run: the plan carries before/after per entry, the skip count becomes a
// warning, and the created/skipped diagnostics ride alongside the plan.
diviops_variable_reset();
diviops_variable_seed( 'numbers', 'gvid-oa-size-h1', array( 'label' => 'Existing', 'value' => '99px', 'order' => 7 ) );
$registry_before_dry_run = diviops_variable_registry();
$resp = diviops_call(
	'variable_create_fluid_system',
	array( diviops_variable_request( array( 'profile' => 'wide', 'typography' => array( 'base_px' => 16, 'steps' => 2 ), 'dry_run' => true ) ) )
);
$data = $resp->get_data();
assert_same( true, $data['data']['dry_run'], 'dry_run reports the dry_run flag' );
assert_same( 'Would generate 2 fluid variable(s): 1 create/update candidate(s), 1 skipped existing ID(s).', $data['data']['plan']['summary'], 'the plan summary counts plan, created and skipped' );
assert_same( array( '1 existing variable ID(s) would be skipped because overwrite=false.' ), $data['data']['plan']['warnings'], 'the skip count is surfaced as a plan warning' );
assert_same( 1, count( (array) ( $data['data']['plan']['changes'] ?? array() ) ), 'only the entries that would be written appear as changes' );
assert_same( 'variable.create', $data['data']['plan']['changes'][0]['kind'], 'a new id plans as variable.create' );
assert_same( 'variable/numbers/gvid-oa-size-h2', $data['data']['plan']['changes'][0]['target'], 'the change target is variable/numbers/{id}' );
assert_same( null, $data['data']['plan']['changes'][0]['before'], 'a create has no before-state' );
assert_same(
	array( 'id' => 'gvid-oa-size-h2', 'label' => 'Size H2', 'value' => '16px', 'order' => 8, 'status' => 'active', 'type' => 'numbers' ),
	$data['data']['plan']['changes'][0]['after'],
	'the after-state shows the order the entry would take, continuing from the existing maximum'
);
assert_same( 1, $data['data']['skipped_count'], 'the dry_run response keeps the created/skipped diagnostics beside the plan' );
assert_same( $registry_before_dry_run, diviops_variable_registry(), 'dry_run wrote nothing' );
assert_same( array(), diviops_variable_record( 'numbers', 'gvid-oa-size-h2' ), 'dry_run created nothing' );

// dry_run + overwrite=true is the documented full-plan preflight: every entry
// appears, flagged for which would be updates.
$resp = diviops_call(
	'variable_create_fluid_system',
	array( diviops_variable_request( array( 'profile' => 'wide', 'typography' => array( 'base_px' => 16, 'steps' => 2 ), 'dry_run' => true, 'overwrite' => true ) ) )
);
$data = $resp->get_data();
assert_same( 2, count( (array) ( $data['data']['plan']['changes'] ?? array() ) ), 'overwrite=true plans every entry' );
assert_same( 'variable.update', $data['data']['plan']['changes'][0]['kind'], 'an existing id plans as variable.update' );
// `before` is cast to an object by the handler, so it serializes as a JSON
// object rather than an array; the fields are what matter here.
$overwrite_before = (array) $data['data']['plan']['changes'][0]['before'];
assert_same( '99px', $overwrite_before['value'], 'the update plan carries the existing record as its before-state' );
assert_same( 7, $overwrite_before['order'], 'the before-state carries the order the after-state will preserve' );
assert_same( 7, $data['data']['plan']['changes'][0]['after']['order'], 'the overwrite plan preserves the existing order' );
assert_true( ! isset( $data['data']['plan']['warnings'] ), 'with nothing skipped the plan carries no warnings key at all' );

// ── variable_update ───────────────────────────────────────────────────────

diviops_variable_reset();
diviops_variable_seed( 'strings', 'gvid-up', array( 'label' => 'Before', 'value' => 'old', 'order' => 3, 'status' => 'active', 'lastUpdated' => '2020-01-01T00:00:00.000Z' ) );

$resp = diviops_call( 'variable_update', array( diviops_variable_request( array() ) ) );
$data = $resp->get_data();
assert_same( 'id is required for variable_update.', $data['error']['message'], 'a missing id is refused' );
assert_same( 'id', $data['error']['data']['missing'], 'the refusal names the missing field' );

$resp = diviops_call( 'variable_update', array( diviops_variable_request( array( 'id' => 'gvid-nope', 'label' => 'X' ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'an unknown id is not_found — update never creates' );
assert_same( 404, $resp->get_status(), 'not_found carries HTTP 404' );
assert_same( "Variable 'gvid-nope' not found.", $data['error']['message'], 'the update not_found message names the id' );
assert_same( 'Use diviops_variable_list to enumerate existing IDs, or diviops_variable_create to add it.', $data['error']['hint'], 'the update hint points at both list and create' );

// The customizer-bound defaults are theme options, not registry entries. This
// guard runs FIRST, above the bucket resolution, so the refusal is a 403 rather
// than the 404 the registry lookup would otherwise produce.
$resp = diviops_call( 'variable_update', array( diviops_variable_request( array( 'id' => 'gcid-primary-color', 'label' => 'X' ) ) ) );
$data = $resp->get_data();
assert_same( 'variable.customizer_default_immutable', $data['error']['code'], 'a customizer-bound default is refused with its own namespaced code' );
assert_same( 403, $resp->get_status(), 'the customizer refusal carries HTTP 403' );
assert_same( 'wp_customizer', $data['error']['data']['managed_by'], 'error.data names the system that owns the value' );
assert_same( 'Edit the corresponding theme option via WP Customizer instead.', $data['error']['hint'], 'the hint routes the caller to the Customizer' );

$resp = diviops_call( 'variable_update', array( diviops_variable_request( array( 'id' => 'gvid-up', 'status' => 'retired' ) ) ) );
$data = $resp->get_data();
assert_same( 'status must be one of: active, inactive, archived, temporary.', $data['error']['message'], 'status is validated against the four Divi statuses' );
assert_same( array( 'active', 'inactive', 'archived', 'temporary' ), $data['error']['data']['allowed'], 'the status refusal lists the vocabulary' );

$resp = diviops_call( 'variable_update', array( diviops_variable_request( array( 'id' => 'gvid-up', 'value' => array( 'a' ) ) ) ) );
$data = $resp->get_data();
assert_same( 'value must be a scalar string.', $data['error']['message'], 'an array value is refused on update' );
assert_same( 'array', $data['error']['data']['received'], 'the update refusal describes an array as "array"' );

// dry_run reports both states in full, which is what makes the partial-merge
// contract auditable before it is committed.
$resp = diviops_call( 'variable_update', array( diviops_variable_request( array( 'id' => 'gvid-up', 'value' => 'new', 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_same( "Would update strings variable 'gvid-up'.", $data['data']['plan']['summary'], 'the update plan summary names the bucket and id' );
assert_same( 'variable.update', $data['data']['plan']['changes'][0]['kind'], 'the update plan kind is variable.update' );
assert_same( 'variable/strings/gvid-up', $data['data']['plan']['changes'][0]['target'], 'the update plan target is variable/{bucket}/{id}' );
assert_same( 'old', $data['data']['plan']['changes'][0]['before']['value'], 'the plan carries the existing value as before' );
assert_same( 'new', $data['data']['plan']['changes'][0]['after']['value'], 'the plan carries the replacement as after' );
assert_same( 3, $data['data']['plan']['changes'][0]['after']['order'], 'order survives the merge into the after-state' );
assert_same( 'old', diviops_variable_record( 'strings', 'gvid-up' )['value'] ?? null, 'dry_run update wrote nothing' );

// The real update: partial merge, id and type untouched, lastUpdated bumped.
$resp = diviops_call( 'variable_update', array( diviops_variable_request( array( 'id' => 'gvid-up', 'value' => '  new   value  ', 'status' => 'archived' ) ) ) );
$data = $resp->get_data();
assert_same(
	array( 'success' => true, 'id' => 'gvid-up', 'type' => 'strings', 'label' => 'Before', 'value' => 'new value' ),
	$data['data'],
	'the update response reports the merged record, including the label it did not change'
);
$updated = diviops_variable_record( 'strings', 'gvid-up' );
assert_same( 3, $updated['order'], 'order is preserved — variable_update never puts it in the override set' );
assert_same( 'archived', $updated['status'], 'a supplied status is written' );
assert_true( '2020-01-01T00:00:00.000Z' !== $updated['lastUpdated'], 'lastUpdated is bumped on every write' );

// A links value goes through esc_url_raw on update too, matching create.
diviops_variable_seed( 'links', 'gvid-l', array( 'label' => 'L', 'value' => 'x' ) );
diviops_call( 'variable_update', array( diviops_variable_request( array( 'id' => 'gvid-l', 'value' => $url ) ) ) );
assert_same( $url, diviops_variable_record( 'links', 'gvid-l' )['value'] ?? null, 'update routes links through esc_url_raw, same as create' );

// The gradients branch delegates to the same builder create uses, so the #921
// rejection cannot drift between the two handlers.
diviops_variable_seed( 'gradients', 'gvid-g', array( 'label' => 'G', 'value' => '$variable({"type":"gradient","value":{"name":"gradient","settings":{}}})$' ) );
$resp = diviops_call( 'variable_update', array( diviops_variable_request( array( 'id' => 'gvid-g', 'value' => 'linear-gradient(90deg,#000,#fff)' ) ) ) );
assert_same( 'invalid_input', $resp->get_data()['error']['code'], 'a CSS gradient string is refused on update' );
$resp = diviops_call(
	'variable_update',
	array( diviops_variable_request( array( 'id' => 'gvid-g', 'gradient' => array( 'stops' => array( array( 'position' => '0', 'color' => '#111' ), array( 'position' => '100', 'color' => '#222' ) ) ) ) ) )
);
assert_true( false !== strpos( (string) $resp->get_data()['data']['value'], '"color":"#111"' ), 'a structured gradient updates without a `value` param' );

// A label-only update on a gradients variable leaves the token alone — the
// builder is only consulted when value or gradient is actually supplied.
$before_token = '$variable({"type":"gradient","value":{"name":"gradient","settings":{"stops":[]}}})$';
diviops_variable_seed( 'gradients', 'gvid-g', array( 'label' => 'G', 'value' => $before_token ) );
$resp = diviops_call( 'variable_update', array( diviops_variable_request( array( 'id' => 'gvid-g', 'label' => 'Renamed' ) ) ) );
assert_same( true, $resp->get_data()['ok'] ?? null, 'a metadata-only update on a gradient succeeds without supplying the token again' );
assert_same( 'Renamed', diviops_variable_record( 'gradients', 'gvid-g' )['label'] ?? null, 'the metadata-only update wrote the new label' );
assert_same( $before_token, diviops_variable_record( 'gradients', 'gvid-g' )['value'] ?? null, 'a metadata-only update on a gradient does not re-run the token builder' );

// The bucket is resolved by scanning the six non-colour buckets in a fixed
// order, so an id present in two buckets resolves to the first one found.
diviops_variable_reset();
diviops_variable_seed( 'fonts', 'gvid-dupe', array( 'label' => 'Font copy', 'value' => 'f' ) );
diviops_variable_seed( 'numbers', 'gvid-dupe', array( 'label' => 'Number copy', 'value' => '1px' ) );
$resp = diviops_call( 'variable_update', array( diviops_variable_request( array( 'id' => 'gvid-dupe', 'label' => 'Resolved' ) ) ) );
assert_same( 'numbers', $resp->get_data()['data']['type'], 'bucket resolution follows the fixed numbers/strings/images/links/fonts/gradients order' );
assert_same( 'Font copy', diviops_variable_record( 'fonts', 'gvid-dupe' )['label'] ?? null, 'the later bucket is left untouched' );

// ── variable_delete ───────────────────────────────────────────────────────

diviops_variable_reset();
diviops_variable_seed( 'numbers', 'gvid-del', array( 'label' => 'Doomed', 'value' => '10px', 'order' => 1 ) );

$resp = diviops_call( 'variable_delete', array( diviops_variable_request( array( 'id' => 'gvid-missing' ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'deleting an unknown id is not_found' );
assert_same( 404, $resp->get_status(), 'the delete not_found carries HTTP 404' );
assert_same( 'Use diviops_variable_list to enumerate existing IDs.', $data['error']['hint'], 'the delete hint points at list only — there is no create suggestion here' );

$resp = diviops_call( 'variable_delete', array( diviops_variable_request( array( 'id' => 'gcid-link-color' ) ) ) );
$data = $resp->get_data();
assert_same( 'variable.customizer_default_immutable', $data['error']['code'], 'a customizer-bound default cannot be deleted' );
assert_same( 403, $resp->get_status(), 'the customizer delete refusal carries HTTP 403' );
assert_same( 'Edit the corresponding theme option via WP Customizer instead of attempting deletion.', $data['error']['hint'], 'the delete hint differs from the update one' );

// The fast path: nothing references it, so the structured scan never runs.
$resp = diviops_call( 'variable_delete', array( diviops_variable_request( array( 'id' => 'gvid-del', 'dry_run' => true ) ) ) );
$data = $resp->get_data();
assert_same( "Would delete numbers variable 'gvid-del' (label: 'Doomed', value: '10px').", $data['data']['plan']['summary'], 'the delete plan summary names bucket, id, label and value' );
assert_same( 'variable.delete', $data['data']['plan']['changes'][0]['kind'], 'the delete plan kind is variable.delete' );
assert_same( 'variable/numbers/gvid-del', $data['data']['plan']['changes'][0]['target'], 'the delete plan target is variable/{bucket}/{id}' );
assert_same( '10px', $data['data']['plan']['changes'][0]['before']['value'], 'the delete plan carries the record it would remove' );
assert_true( array() !== diviops_variable_record( 'numbers', 'gvid-del' ), 'dry_run delete removed nothing' );

$resp = diviops_call( 'variable_delete', array( diviops_variable_request( array( 'id' => 'gvid-del' ) ) ) );
$data = $resp->get_data();
assert_same( array( 'success' => true, 'deleted' => 'gvid-del', 'forced' => false ), $data['data'], 'an unreferenced delete succeeds and reports it was not forced' );
assert_same( array(), diviops_variable_record( 'numbers', 'gvid-del' ), 'the record is gone from the registry' );

// The blocking path. The reference lives in the preset registry, which both the
// fast probe and the structured scan can see without parse_blocks().
diviops_variable_reset(
	array(
		'module' => array(
			'divi/button' => array(
				'items' => array(
					'uuid-x' => array( 'name' => 'CTA', 'attrs' => array( 'pad' => diviops_variable_token( 'gvid-live' ) ) ),
					'uuid-y' => array( 'name' => 'CTA Alt', 'attrs' => array( 'pad' => diviops_variable_token( 'gvid-live' ) ) ),
				),
			),
		),
	)
);
diviops_variable_seed( 'numbers', 'gvid-live', array( 'label' => 'Live', 'value' => '10px', 'order' => 1 ) );

$resp = diviops_call( 'variable_delete', array( diviops_variable_request( array( 'id' => 'gvid-live' ) ) ) );
$data = $resp->get_data();
assert_same( 'conflict', $data['error']['code'], 'deleting a referenced variable is a conflict' );
assert_same( 409, $resp->get_status(), 'the reference conflict carries HTTP 409' );
assert_same(
	"Variable 'gvid-live' has 2 live reference(s). Pass force=true to delete anyway; orphans will remain — run diviops_variable_scan_orphans to audit them afterwards.",
	$data['error']['message'],
	'the conflict message reports the reference count and the override'
);
assert_same( 2, $data['error']['data']['ref_count'], 'error.data carries the reference count' );
assert_same( 2, count( (array) ( $data['error']['data']['locations'] ?? array() ) ), 'error.data carries one location record per referencing preset' );
assert_same( 'uuid-x', ( $data['error']['data']['locations'][0]['preset_uuid'] ?? '(absent)' ), 'the location records identify the presets by uuid' );
assert_same( false, $data['error']['data']['scan_truncated'], 'error.data reports whether the scan behind it was complete' );
assert_same( 0, $data['error']['data']['scanned_posts'], 'error.data reports how many posts the scan covered' );
assert_true( array() !== diviops_variable_record( 'numbers', 'gvid-live' ), 'the blocked delete left the record in place' );

// dry_run does NOT skip the reference check — a plan that would be refused says
// so instead of planning a delete that cannot happen.
$resp = diviops_call( 'variable_delete', array( diviops_variable_request( array( 'id' => 'gvid-live', 'dry_run' => true ) ) ) );
assert_same( 'conflict', $resp->get_data()['error']['code'], 'dry_run surfaces the conflict rather than planning the delete' );

// force=true skips the check entirely, leaving the references dangling. This is
// the documented orphan-creating escape hatch.
$resp = diviops_call( 'variable_delete', array( diviops_variable_request( array( 'id' => 'gvid-live', 'force' => true ) ) ) );
$data = $resp->get_data();
assert_same( array( 'success' => true, 'deleted' => 'gvid-live', 'forced' => true ), $data['data'], 'force=true deletes despite live references and says it was forced' );
assert_same( array(), diviops_variable_record( 'numbers', 'gvid-live' ), 'the forced delete removed the record' );
// The references it left behind are now dangling, and the scanner still sees
// them — which is exactly what variable_scan_orphans would report.
$refs = diviops_call( 'collect_variable_refs' );
assert_same( 2, ( $refs['all_ids']['gvid-live'] ?? 0 ), 'the forced delete left the references in place, now pointing at nothing' );

// The fast probe is false-positive tolerant by design: an id mentioned in prose
// makes it appear "referenced", the structured scan then finds no real
// reference, and the delete proceeds. Both halves of that are load-bearing.
diviops_variable_reset();
diviops_variable_seed( 'numbers', 'gvid-prose', array( 'label' => 'P', 'value' => '1px', 'order' => 1 ) );
diviops_test_register_post( 700300, 'the docs mention gvid-prose but reference nothing', 'page', 'Docs' );
assert_same( true, diviops_call( 'variable_id_appears_anywhere', array( 'gvid-prose' ) ), 'the fast probe reports a prose mention as an appearance' );
$resp = diviops_call( 'variable_delete', array( diviops_variable_request( array( 'id' => 'gvid-prose' ) ) ) );
assert_same( true, $resp->get_data()['ok'], 'the structured scan overrules the false positive and the delete proceeds' );

// ── variable_used_on_page: the guards in front of the Divi-only body ──────

diviops_variable_reset();

$resp = diviops_call( 'variable_used_on_page', array( diviops_variable_request( array( 'id' => 0 ) ) ) );
$data = $resp->get_data();
assert_same( 'invalid_input', $data['error']['code'], 'a non-positive post id is invalid_input' );
assert_same( 'post_id must be a positive integer.', $data['error']['message'], 'the message names post_id, not the `id` route parameter' );
assert_same( 0, $data['error']['data']['received'], 'error.data echoes the received id' );

$resp = diviops_call( 'variable_used_on_page', array( diviops_variable_request( array( 'id' => 700400 ) ) ) );
$data = $resp->get_data();
assert_same( 'not_found', $data['error']['code'], 'an unknown post is not_found' );
assert_same( 'Post 700400 not found.', $data['error']['message'], 'the used-on-page not_found message names the post id' );
assert_same( 'Use diviops_page_list to find a valid page ID.', $data['error']['hint'], 'the hint points at page discovery' );

diviops_variable_register_wp_post( 700400, 'content', 'page', 'Real Page' );
$GLOBALS['diviops_test_uneditable_ids'] = array( 700400 );
$resp = diviops_call( 'variable_used_on_page', array( diviops_variable_request( array( 'id' => 700400 ) ) ) );
$data = $resp->get_data();
assert_same( 'forbidden', $data['error']['code'], 'a post the caller cannot edit is refused per object' );
assert_same( 403, $resp->get_status(), 'the per-object read denial carries HTTP 403' );
assert_same( 'Cannot inspect page #700400.', $data['error']['message'], 'the refusal names the object it declined' );
$GLOBALS['diviops_test_uneditable_ids'] = array();

// Past all three guards the handler needs Divi 5 itself, and says so rather
// than returning an empty list that would read as "this page uses nothing".
$resp = diviops_call( 'variable_used_on_page', array( diviops_variable_request( array( 'id' => 700400 ) ) ) );
$data = $resp->get_data();
assert_same( 'wp_error', $data['error']['code'], 'a missing Divi 5 is reported, not silently answered as an empty result' );
assert_same( 500, $resp->get_status(), 'the missing-Divi refusal carries HTTP 500' );
assert_same( 'Divi 5 (with DetectFeature class) is required for this endpoint.', $data['error']['message'], 'the message names the class it needs' );
assert_same( 'Activate the Divi 5 theme.', $data['error']['hint'], 'the hint says how to satisfy it' );

// ── get_customizer_color_count ────────────────────────────────────────────
//
// The five ids come from Divi's own GlobalData::$customizer_colors
// (GlobalData.php:58-84). New user colours offset past this count so they do
// not collide with the implicit first slots the Variable Manager renders.

assert_same( 5, diviops_call( 'get_customizer_color_count' ), 'Divi 5 defines five customizer-bound colours' );

// ── Route wiring and method existence ─────────────────────────────────────

$variable_plugin_src = (string) file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php' );

foreach ( array( 'list', 'create', 'create-fluid-system', 'update', 'delete', 'scan-orphans' ) as $variable_route ) {
	assert_true(
		false !== strpos( $variable_plugin_src, "'/variable/{$variable_route}'" ),
		"the /variable/{$variable_route} route is registered"
	);
}
assert_true(
	false !== strpos( $variable_plugin_src, "'/variable/used-on-page/(?P<id>\\d+)'" ),
	'the /variable/used-on-page route is registered with a numeric id segment'
);

foreach (
	array(
		'variable_list',
		'variable_create',
		'variable_create_fluid_system',
		'variable_update',
		'variable_delete',
		'variable_scan_orphans',
		'variable_used_on_page',
		'collect_variable_refs',
		'variable_id_appears_anywhere',
		'walk_value_for_variable_refs',
		'walk_blocks_for_variable_refs',
	) as $variable_handler
) {
	assert_true(
		method_exists( 'DiviOps_Agent', $variable_handler ),
		"DiviOps_Agent::{$variable_handler} exists once the trait is mixed in"
	);
}

// The marker exception is what routes an input-shape failure inside the clamp
// helpers to invalid_input rather than to a namespaced algorithmic code. It
// extends InvalidArgumentException on purpose, so a plain `instanceof` check
// elsewhere still treats it as one.
assert_true( class_exists( 'DiviOps_Variable_Input_Exception' ), 'the marker exception is declared alongside the trait' );
assert_true( is_subclass_of( 'DiviOps_Variable_Input_Exception', 'InvalidArgumentException' ), 'the marker extends InvalidArgumentException' );

diviops_variable_reset();
$GLOBALS['diviops_test_posts']   = array();
$GLOBALS['diviops_test_options'] = array();
$GLOBALS['wpdb']                 = $diviops_variable_saved_wpdb;
