<?php
// SPDX-License-Identifier: MIT
/**
 * Preset reference scan post-type scope (#314).
 *
 * The preset reference scan enumerated a literal `page`/`post` pair in five
 * places, so every Theme Builder layout post type was invisible to it. A preset
 * used only in a global header, footer, or body layout reported zero references
 * — which is precisely the signal preset_cleanup's `remove_orphans` and the
 * spam-unreferenced pass delete on. Confirmed on the reference install: preset
 * `llgpo8h7zc` returned `references.total: 0` while three live
 * `et_footer_layout` rows carried it and not one `page` or `post` row did.
 *
 * The five literals were the root cause, not a symptom: duplication is how the
 * two write-path copies in preset_reassign() drifted out of sync with the read
 * path. The fix points all five at `DiviOps_Agent::SCANNABLE_POST_TYPES`, the
 * list trait-variable.php and trait-global-font.php already scan by.
 *
 * How each claim here is proved, and why not more directly:
 *
 *   (1) The constant itself, and the whole trait's freedom from the literal —
 *       plain assertions over real values and the real file.
 *
 *   (2) collect_page_preset_refs() reaching an et_footer_layout post — proved
 *       behaviorally, by the post entering the scan at all. This harness
 *       deliberately does not shim parse_blocks() (see tests/wp-shim.php's
 *       docblock, test-global-layout-write-guard.php's header, and
 *       test-module-fallback-trigger-wiring.php, which uses this same probe): a
 *       faithful reimplementation is a parser state machine, and a partial one
 *       would mock the behavior under test. So a fixture whose content contains
 *       "modulePreset" makes the scanner call parse_blocks(), which throws a
 *       plain catchable `Error`. Catching it is proof the post was fetched;
 *       returning normally is proof it was filtered out. Before the fix the
 *       et_footer_layout fixture threw nothing — that is the RED state.
 *
 *   (3) The SQL collect_preset_consumer_samples() actually builds — captured
 *       from a recording $wpdb, so this asserts the query the code emits at
 *       runtime rather than the text of the source.
 *
 *   (4) preset_reassign()'s two query shapes — structural, read from the real
 *       method source via Reflection, in the shape test-preset-reassign-write-safety.php
 *       already uses for this handler's wiring. preset_reassign() cannot be
 *       driven to its get_posts() call in this harness: it returns first at the
 *       preset registry probe, which goes through Divi's et_get_option(), also
 *       deliberately unshimmed.
 *
 * What remains for live verification, and is covered by
 * tests-live/test-preset-refs-theme-builder-live.php: that a real preset
 * referenced only from a real et_footer_layout post reports total >= 1 through
 * the REST surface, with real parse_blocks() and real Divi block markup.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/* -------------------------------------------------------------------------
 * (1) One list, and it covers the Theme Builder types.
 * ---------------------------------------------------------------------- */

$scannable = DiviOps_Agent::SCANNABLE_POST_TYPES;

foreach ( array( 'page', 'post', 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'et_template', 'et_pb_layout' ) as $type ) {
	assert_true(
		in_array( $type, $scannable, true ),
		"SCANNABLE_POST_TYPES covers '{$type}', so a preset used only there cannot report as unreferenced"
	);
}

$trait_source = file_get_contents( dirname( __DIR__ ) . '/plugins/diviops-agent/includes/trait-preset.php' );
assert_true(
	is_string( $trait_source ) && '' !== $trait_source,
	'trait-preset.php is readable, so the literal check below inspects something'
);
assert_same(
	0,
	preg_match_all( "/'post_type'\s*=>\s*\[\s*'page'\s*,\s*'post'\s*\]/", $trait_source ),
	'no hard-coded page/post post_type array survives anywhere in trait-preset.php — the list lives in SCANNABLE_POST_TYPES only'
);
assert_same(
	0,
	preg_match_all( "/post_type\s+IN\s*\(\s*'page'\s*,\s*'post'\s*\)/", $trait_source ),
	'no hard-coded page/post SQL IN list survives in trait-preset.php either'
);

/* -------------------------------------------------------------------------
 * (2) collect_page_preset_refs() fetches Theme Builder layouts.
 *
 * The registry is replaced outright rather than added to: every test file in
 * tests/ shares one process and one $GLOBALS['diviops_test_posts'], and this
 * probe depends on which posts the scan reaches, so a fixture left behind by
 * another file would make the result depend on file discovery order.
 * ---------------------------------------------------------------------- */

/**
 * Run collect_page_preset_refs() against exactly one fixture post and report
 * whether the scanner reached that post's content.
 *
 * Reaching it means calling parse_blocks(), which is unshimmed here and throws
 * `Error: Call to undefined function parse_blocks()`. Any other Error is
 * re-thrown rather than counted — swallowing everything would turn an unrelated
 * fatal into a passing assertion, which is the exact shape of gate this
 * repository has been bitten by.
 *
 * @param string $post_type Fixture post type.
 * @param string $content   Fixture post_content.
 * @return bool Whether the scanner parsed this post.
 */
function preset_ref_scan_reaches( string $post_type, string $content ): bool {
	$saved                         = $GLOBALS['diviops_test_posts'];
	$GLOBALS['diviops_test_posts'] = array();
	diviops_test_register_post( 987001, $content, $post_type, 'ref-scan fixture' );

	try {
		diviops_call( 'collect_page_preset_refs' );
		return false;
	} catch ( Error $e ) {
		if ( false === strpos( $e->getMessage(), 'parse_blocks' ) ) {
			$GLOBALS['diviops_test_posts'] = $saved;
			throw $e;
		}
		return true;
	} finally {
		$GLOBALS['diviops_test_posts'] = $saved;
	}
}

$preset_ref_markup = '<!-- wp:difl/vertical-menu {"builderVersion":"5.9.0","modulePreset":["llgpo8h7zc"]} /-->';

// The defect, stated as an assertion. RED before the fix: the scan's post_type
// filter excluded et_footer_layout, so the post was never fetched, parse_blocks()
// was never called, and this returned false.
assert_true(
	preset_ref_scan_reaches( 'et_footer_layout', $preset_ref_markup ),
	'a preset reference inside an et_footer_layout post is fetched by the reference scan (#314)'
);

foreach ( array( 'et_header_layout', 'et_body_layout', 'et_template', 'et_pb_layout' ) as $tb_type ) {
	assert_true(
		preset_ref_scan_reaches( $tb_type, $preset_ref_markup ),
		"a preset reference inside a {$tb_type} post is fetched by the reference scan"
	);
}

// Control: `page` was always in scope. If this ever stops throwing, the probe
// itself has gone vacuous and every assertion above is meaningless.
assert_true(
	preset_ref_scan_reaches( 'page', $preset_ref_markup ),
	'the probe is not vacuous — a plain page carrying the same markup is still parsed'
);

// Control: the scan did not simply become "every post type". et_theme_builder is
// the template-set assignment record and is deliberately outside the list.
assert_same(
	false,
	preset_ref_scan_reaches( 'et_theme_builder', $preset_ref_markup ),
	'the scan is still scoped — et_theme_builder is not fetched'
);

// Control: the cheap pre-check still short-circuits content that cannot hold a
// preset reference, so widening the post types did not widen the parse cost.
assert_same(
	false,
	preset_ref_scan_reaches( 'et_footer_layout', '<!-- wp:paragraph --><p>no presets here</p><!-- /wp:paragraph -->' ),
	'content with no modulePreset/groupPreset key is still skipped before parse_blocks()'
);

/* -------------------------------------------------------------------------
 * (3) The SQL collect_preset_consumer_samples() emits, captured at runtime.
 * ---------------------------------------------------------------------- */

if ( ! class_exists( 'DiviOps_Preset_Ref_Scan_wpdb' ) ) {
	/**
	 * Recording stand-in for $wpdb that captures the prepared posts query.
	 *
	 * Returns no ids, so the caller stops before get_posts()/parse_blocks() and
	 * this file asserts on the query itself — the thing that decides which rows
	 * the scan can ever see.
	 */
	final class DiviOps_Preset_Ref_Scan_wpdb {

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
		 * @param string $query Query with %s placeholders.
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
		 * No matching rows. The query is the artifact under test.
		 *
		 * @param string $query Prepared query.
		 */
		public function get_col( $query ) {
			return array();
		}
	}
}

/**
 * Capture the prepared SQL collect_preset_consumer_samples() builds.
 *
 * @param array<int, mixed> $args Arguments after the preset id.
 * @return string The prepared query.
 */
function preset_ref_scan_capture_sql( array $args = array() ): string {
	$saved             = $GLOBALS['wpdb'];
	$recorder          = new DiviOps_Preset_Ref_Scan_wpdb();
	$GLOBALS['wpdb']   = $recorder;
	try {
		diviops_call( 'collect_preset_consumer_samples', array_merge( array( 'llgpo8h7zc' ), $args ) );
	} finally {
		$GLOBALS['wpdb'] = $saved;
	}
	return $recorder->prepared[0] ?? '';
}

$live_sql = preset_ref_scan_capture_sql();

assert_true(
	'' !== $live_sql,
	'collect_preset_consumer_samples() prepared a posts query, so the assertions below inspect something real'
);
foreach ( array( 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'et_template', 'et_pb_layout' ) as $type ) {
	assert_true(
		false !== strpos( $live_sql, "'{$type}'" ),
		"preset_inspect's consumer query enumerates '{$type}': {$live_sql}"
	);
}
assert_true(
	false !== strpos( $live_sql, "'publish'" ) && false !== strpos( $live_sql, "'draft'" ) && false !== strpos( $live_sql, "'private'" ),
	'the consumer query still restricts to live statuses'
);
assert_true(
	false === strpos( $live_sql, "'revision'" ) && false === strpos( $live_sql, "'inherit'" ),
	'the default consumer query does not scan revisions — they are reported separately, not counted as references'
);

// The revision pass asks the same structural question of revision rows only.
$revision_sql = preset_ref_scan_capture_sql( array( array( 'revision' ), array( 'inherit' ) ) );
assert_true(
	false !== strpos( $revision_sql, "'revision'" ) && false !== strpos( $revision_sql, "'inherit'" ),
	"the revision pass scans revision/inherit rows: {$revision_sql}"
);
assert_true(
	false === strpos( $revision_sql, "'et_footer_layout'" ),
	'the revision pass does not also re-scan live post types'
);

// An empty scope must query nothing rather than emit `IN ()`, which is a SQL
// syntax error, or an unbounded scan.
assert_same(
	'',
	preset_ref_scan_capture_sql( array( array(), array( 'inherit' ) ) ),
	'an empty post-type scope prepares no query at all'
);

/* -------------------------------------------------------------------------
 * (4) preset_inspect() reports the revision case distinguishably, and
 *     preset_reassign()'s write-path queries read from the same list.
 * ---------------------------------------------------------------------- */

/**
 * Read a method's exact source text via Reflection.
 *
 * Named for this file: tests/run.php requires every test file into ONE process,
 * so an unqualified name would collide with the identically-purposed helpers in
 * test-preset-reassign-write-safety.php and test-parse-blocks-for-write-coverage.php.
 *
 * @param string $method Method name on DiviOps_Agent.
 */
function preset_ref_scan_method_source( string $method ): string {
	$reflection = new ReflectionMethod( 'DiviOps_Agent', $method );
	$start      = $reflection->getStartLine() - 1;
	$length     = $reflection->getEndLine() - $start;
	return implode( '', array_slice( file( $reflection->getFileName() ), $start, $length ) );
}

$inspect_source = preset_ref_scan_method_source( 'preset_inspect' );
foreach ( array( 'revision_ref_count', 'revision_only', 'scanned_post_types' ) as $field ) {
	assert_true(
		false !== strpos( $inspect_source, $field ),
		"preset_inspect() emits references.{$field}, so 'referenced only in history' and 'referenced nowhere' do not render the same"
	);
}

$reassign_source = preset_ref_scan_method_source( 'preset_reassign' );
assert_same(
	2,
	preg_match_all( "/'post_type'\s*=>\s*self::SCANNABLE_POST_TYPES/", $reassign_source ),
	'both of preset_reassign()\'s get_posts() query shapes — targeted page_ids and full-site — scope by SCANNABLE_POST_TYPES, so the write reaches the Theme Builder consumers the read path now reports'
);
