<?php
// SPDX-License-Identifier: MIT
/**
 * Variable reference-scan post-type scope (#316).
 *
 * `DiviOps_Agent::SCANNABLE_POST_TYPES` has three consumers. #315 gave
 * trait-preset.php coverage of its scan path; trait-variable.php had none. The
 * question both scanners answer is the same one — is this thing referenced
 * anywhere — and the same destructive decision hangs off the answer:
 * `variable_delete` refuses when `collect_variable_refs()` finds a reference,
 * and short-circuits to the happy path when `variable_id_appears_anywhere()`
 * finds none.
 *
 * The reason nothing here was caught earlier is worth stating: until #315,
 * tests/wp-shim.php's `get_posts()` compared `post_type` with `!==` against
 * whatever it was passed, so the array argument every multi-post-type scanner
 * uses matched nothing and returned an empty result. Any test that had driven
 * this scan would have passed on that empty result. Both halves — the shim and
 * the scanner's post-type list — are pinned below, so neither can regress
 * without a named failure.
 *
 * How each claim is proved, and why not more directly:
 *
 *   (1) The query the scanner actually issues — captured from the recording
 *       `get_posts()` log in tests/wp-shim.php, so this asserts the arguments
 *       the code emits at runtime rather than the text of the source.
 *
 *   (2) An `et_footer_layout` post's content reaching the block walker — proved
 *       behaviourally, by which unshimmed primitive the scan dies on. This
 *       harness deliberately ships no `parse_blocks()` (see tests/wp-shim.php's
 *       docblock and tests/test-preset-ref-scan-post-types.php, which uses the
 *       same probe): a faithful reimplementation is a parser state machine, and
 *       a partial one would mock the behaviour under test.
 *
 *       `collect_variable_refs()` scans posts first and the preset registry
 *       second, and the registry read goes through Divi's `et_get_option()`,
 *       also deliberately unshimmed. So the two outcomes are positively
 *       distinguishable rather than one being an absence: a post that is
 *       fetched AND carries a `$variable(` token dies on `parse_blocks()`, and
 *       one that is filtered out (or skipped by the cheap pre-check) survives
 *       to die on `et_get_option()`. Anything else is re-thrown — swallowing
 *       every Error would turn an unrelated fatal into a passing assertion,
 *       which is the exact shape of gate this repository has been bitten by.
 *
 *   (3) `variable_id_appears_anywhere()`'s SQL — captured from a recording
 *       $wpdb. This is the fast path `variable_delete` short-circuits on, so a
 *       post type missing from it is a delete that proceeds against a live
 *       reference.
 *
 *   (4) That the walker actually recognises the token the fixtures carry —
 *       driven directly over the equivalent hand-built block tree. Without it a
 *       fixture whose token shape the walker does not match would still satisfy
 *       every assertion in (2), because the probe only observes that the post
 *       reached the parser.
 *
 * What is NOT provable here, and is left named rather than faked: that
 * `variable_scan_orphans` omits such a variable from `unused_variables`. That
 * endpoint needs `collect_variable_refs()` to return, which needs both
 * `parse_blocks()` and `et_get_option()`. Shimming either would mean writing
 * the behaviour under test, and a `parse_blocks()` stub would additionally
 * break tests/test-preset-ref-scan-post-types.php, which depends on it being
 * undefined. tests-live/test-preset-refs-theme-builder-live.php is the shape
 * that answer takes; the variable equivalent is not written here because it
 * requires write access to a real install.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/* -------------------------------------------------------------------------
 * (1) The query collect_variable_refs() issues, captured at runtime.
 * ---------------------------------------------------------------------- */

/**
 * Run a scanner and return the arguments it passed to get_posts().
 *
 * The scan is expected to die on an unshimmed primitive — that is fine and
 * irrelevant here, because get_posts() is the first thing it calls and the log
 * is already written by then.
 *
 * @param string $method Scanner method name on DiviOps_Agent.
 * @return array<string, mixed> The first get_posts() argument array.
 */
function variable_ref_scan_query_args( string $method ): array {
	$GLOBALS['diviops_test_get_posts_calls'] = array();
	try {
		diviops_call( $method );
	} catch ( Error $e ) {
		unset( $e );
	}
	return $GLOBALS['diviops_test_get_posts_calls'][0] ?? array();
}

$variable_scan_args = variable_ref_scan_query_args( 'collect_variable_refs' );

assert_true(
	array() !== $variable_scan_args,
	'collect_variable_refs() issued a get_posts() query, so the assertions below inspect something real'
);
assert_same(
	DiviOps_Agent::SCANNABLE_POST_TYPES,
	$variable_scan_args['post_type'] ?? null,
	'collect_variable_refs() scopes by SCANNABLE_POST_TYPES, so a variable used only in a Theme Builder layout cannot report as unreferenced (#316)'
);
assert_same(
	array( 'publish', 'draft', 'private' ),
	$variable_scan_args['post_status'] ?? null,
	'collect_variable_refs() scans live statuses only — a reference surviving in a revision is not a reason to refuse a delete'
);

/* -------------------------------------------------------------------------
 * (2) The scan reaches an et_footer_layout post's content.
 *
 * The registry is replaced outright rather than added to: every test file in
 * tests/ shares one process and one $GLOBALS['diviops_test_posts'], and this
 * probe depends on which posts the scan reaches, so a fixture left behind by
 * another file would make the result depend on file discovery order.
 * ---------------------------------------------------------------------- */

/**
 * Run collect_variable_refs() against exactly one fixture post and report
 * whether the scanner reached that post's content.
 *
 * @param string $post_type Fixture post type.
 * @param string $content   Fixture post_content.
 * @return bool Whether the scanner parsed this post.
 */
function variable_ref_scan_reaches( string $post_type, string $content ): bool {
	$saved                         = $GLOBALS['diviops_test_posts'];
	$GLOBALS['diviops_test_posts'] = array();
	diviops_test_register_post( 987201, $content, $post_type, 'variable ref-scan fixture' );

	try {
		diviops_call( 'collect_variable_refs' );
	} catch ( Error $e ) {
		if ( false !== strpos( $e->getMessage(), 'parse_blocks' ) ) {
			return true;
		}
		if ( false !== strpos( $e->getMessage(), 'et_get_option' ) ) {
			return false;
		}
		throw $e;
	} finally {
		$GLOBALS['diviops_test_posts'] = $saved;
	}

	throw new RuntimeException(
		'collect_variable_refs() returned without touching parse_blocks() or et_get_option(); this probe can no longer tell a scanned post from a filtered one'
	);
}

// The token shape Divi actually stores: a JSON payload inside the token, with its
// quotes unicode-escaped because the whole attrs blob is itself JSON inside the
// block comment. parse_blocks() decodes that escaping, which is why section (4)
// drives the walker over a decoded tree rather than over this string.
$variable_ref_markup = '<!-- wp:divi/text {"builderVersion":"5.9.0","module":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"$variable({\u0022type\u0022:\u0022color\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gvid-abc123\u0022,\u0022settings\u0022:{}}})$"}}}}}}} /-->';

// The gap, stated as an assertion. RED against the pre-#315 shim: get_posts()
// compared the post_type array with !==, matched nothing, and the scan fell
// straight through to the preset registry. RED again if SCANNABLE_POST_TYPES
// narrows back to page/post.
assert_true(
	variable_ref_scan_reaches( 'et_footer_layout', $variable_ref_markup ),
	'a variable referenced only from an et_footer_layout post is fetched and walked by the variable reference scan (#316)'
);

foreach ( array( 'et_header_layout', 'et_body_layout', 'et_template', 'et_pb_layout', 'et_pb_canvas' ) as $variable_tb_type ) {
	assert_true(
		variable_ref_scan_reaches( $variable_tb_type, $variable_ref_markup ),
		"a variable reference inside a {$variable_tb_type} post is fetched by the variable reference scan"
	);
}

// Control: `page` was always in scope. If this ever stops reaching parse_blocks()
// the probe itself has gone vacuous and every assertion above is meaningless.
assert_true(
	variable_ref_scan_reaches( 'page', $variable_ref_markup ),
	'the probe is not vacuous — a plain page carrying the same token is still parsed'
);

// Control: the scan did not simply become "every post type". et_theme_builder is
// the template-set assignment record and is deliberately outside the list.
assert_same(
	false,
	variable_ref_scan_reaches( 'et_theme_builder', $variable_ref_markup ),
	'the variable scan is still scoped — et_theme_builder is not fetched'
);

// Control: the cheap pre-check still short-circuits content that cannot hold a
// variable reference, so the post-type scope did not widen the parse cost.
assert_same(
	false,
	variable_ref_scan_reaches( 'et_footer_layout', '<!-- wp:paragraph --><p>no variables here</p><!-- /wp:paragraph -->' ),
	'content with no $variable( token is still skipped before parse_blocks()'
);

/* -------------------------------------------------------------------------
 * (3) The SQL variable_id_appears_anywhere() emits, captured at runtime.
 *
 * variable_delete() calls this first and skips the full structured scan when it
 * returns false, so a post type missing from this query is a delete that runs
 * against a live reference without ever building the 409 location list.
 * ---------------------------------------------------------------------- */

if ( ! class_exists( 'DiviOps_Variable_Ref_Scan_wpdb' ) ) {
	/**
	 * Recording stand-in for $wpdb that captures the prepared existence probe.
	 *
	 * get_var() reports a hit, so variable_id_appears_anywhere() returns before
	 * reading the preset registry through Divi's unshimmed et_get_option(), and
	 * this file asserts on the query itself — the thing that decides which rows
	 * the probe can ever see.
	 */
	final class DiviOps_Variable_Ref_Scan_wpdb {

		/** @var string Posts table name, matching $wpdb->posts. */
		public $posts = 'wp_posts';

		/** @var array<int, string> Every query prepared through this double. */
		public $prepared = array();

		/**
		 * Model $wpdb::esc_like(): backslash-escape LIKE's wildcards.
		 *
		 * @param string $text Raw text.
		 */
		public function esc_like( $text ) {
			return addcslashes( (string) $text, '_%\\' );
		}

		/**
		 * Model the %s substitution $wpdb::prepare() performs, including core's
		 * single-array-argument form.
		 *
		 * @param string $query   Query with %s placeholders.
		 * @param mixed  ...$args Values, or one array of values.
		 */
		public function prepare( $query, ...$args ) {
			$values = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
			foreach ( $values as $value ) {
				$query = preg_replace( '/%s/', "'" . addslashes( (string) $value ) . "'", $query, 1 );
			}
			$this->prepared[] = $query;
			return $query;
		}

		/**
		 * Report a hit so the caller stops here. The query is the artifact
		 * under test.
		 *
		 * @param string $query Prepared query.
		 */
		public function get_var( $query ) {
			return '1';
		}
	}
}

$variable_probe_recorder = new DiviOps_Variable_Ref_Scan_wpdb();
$variable_saved_wpdb     = $GLOBALS['wpdb'];
$GLOBALS['wpdb']         = $variable_probe_recorder;
try {
	$variable_probe_hit = diviops_call( 'variable_id_appears_anywhere', array( 'gvid-abc123' ) );
} finally {
	$GLOBALS['wpdb'] = $variable_saved_wpdb;
}

assert_true(
	$variable_probe_hit,
	'variable_id_appears_anywhere() reported the recorded hit, so it stopped at the posts query rather than falling through to the unshimmed preset registry read'
);

$variable_probe_sql = $variable_probe_recorder->prepared[0] ?? '';
assert_true(
	'' !== $variable_probe_sql,
	'variable_id_appears_anywhere() prepared a posts query, so the assertions below inspect something real'
);
// Asserted as one exact IN list rather than per-type substrings: this catches a
// placeholder count that has drifted from the list, which would silently bind
// the tail of it to the wrong argument, as well as a missing type.
assert_true(
	false !== strpos(
		$variable_probe_sql,
		"post_type IN ('" . implode( "','", DiviOps_Agent::SCANNABLE_POST_TYPES ) . "')"
	),
	"variable_delete's existence probe enumerates every scannable post type, one bound value each: {$variable_probe_sql}"
);
assert_true(
	false !== strpos( $variable_probe_sql, "post_status IN ('publish','draft','private')" ),
	'the existence probe restricts to live statuses, matching the structured scan it short-circuits'
);

/* -------------------------------------------------------------------------
 * (4) The walker recognises the reference the fixtures carry.
 *
 * The probe in (2) proves a post reaches parse_blocks(); it cannot prove the id
 * is then extracted, because the block tree parse_blocks() would return does not
 * exist in this harness. This closes that half over the decoded form of the same
 * token, so the fixtures above represent a real reference rather than inert text.
 * ---------------------------------------------------------------------- */

$variable_walk_all_ids   = array();
$variable_walk_local_ids = array();
$variable_walk_blocks    = array(
	array(
		'blockName'   => 'divi/section',
		'attrs'       => array(),
		'innerBlocks' => array(
			array(
				'blockName'   => 'divi/text',
				'attrs'       => array(
					'module' => array(
						'decoration' => array(
							'font' => array(
								'font' => array(
									'desktop' => array(
										'value' => array( 'color' => '$variable({"type":"color","value":{"name":"gvid-abc123","settings":{}}})$' ),
									),
								),
							),
						),
					),
				),
				'innerBlocks' => array(),
			),
		),
	),
);

$variable_walk_args = array( $variable_walk_blocks, &$variable_walk_all_ids, &$variable_walk_local_ids );
diviops_call_ref( 'walk_blocks_for_variable_refs', $variable_walk_args );

assert_same(
	array( 'gvid-abc123' => 1 ),
	$variable_walk_all_ids,
	'the variable walker extracts the id from the same token shape the post fixtures above carry, so those fixtures represent a real reference rather than inert text'
);
