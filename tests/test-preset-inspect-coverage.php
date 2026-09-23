<?php
// SPDX-License-Identifier: MIT
/**
 * preset_inspect reports what it bound to, and whether it could look (#470).
 *
 * Adopted from upstream `f644d11` (v1.5.64) half (a). Three things: the
 * variable-reference IDs a preset binds to, a coverage block stating what the
 * reference scan did and did not reach, and — the one that matters — a `scan`
 * status on `collect_preset_consumer_samples()`.
 *
 * ── Why the scan status is the load-bearing half ──────────────────────────
 *
 * `collect_preset_consumer_samples()` returned `count => 0` for three
 * different situations and gave the caller no way to tell them apart:
 *
 *   - it scanned and there genuinely are no references
 *   - `$wpdb` was unusable, or the query errored — it never looked
 *   - the caller narrowed the scope to nothing
 *
 * `preset_inspect` exists to tell an operator whether a preset is safe to
 * delete. A zero meaning "I could not look" rendering identically to a zero
 * meaning "I looked and there is nothing" is this repository's oldest rule — a
 * skip must not look like a pass — on the surface where being wrong costs the
 * most. Every assertion below drives the function into a specific one of those
 * paths and pins which status comes back; none of them reads the source.
 *
 * ── Why this is not upstream's patch ──────────────────────────────────────
 *
 * `collect_preset_consumer_samples()` diverged in #314, which parameterised it
 * on post types and statuses. So:
 *
 *   - Upstream's two-valued `scan` is short by one. Our version returns before
 *     the query on an empty scope, which upstream has no path for, and folding
 *     it into `unavailable` would report a database problem that did not happen.
 *   - Upstream's `coverage.blocks` string hardcodes "page/post post_content with
 *     publish, draft or private status". Ours scans SCANNABLE_POST_TYPES, and
 *     preset_inspect calls the function a SECOND time with revision/inherit for
 *     the #314 revision split — so a hardcoded string would describe neither
 *     call. It is derived from the arguments actually used, and the assertions
 *     below pin that it tracks them rather than restating a constant.
 *
 * The harness's own `$wpdb` stand-in has no `posts` property and no `get_col()`,
 * so the DEFAULT path in this suite is the unavailable one. That is worth
 * stating plainly: every pre-existing test asserting `count: 0` here was
 * asserting the could-not-look case without saying so. The complete-scan and
 * query-error paths below install their own double to reach the other branches.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';
// preset_inspect walks every storage path, including the et_divi-nested ones, so
// et_get_option() must exist. The shim docblock explains why it stays opt-in.
require_once __DIR__ . '/divi-active-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

const DIVIOPS_PIC_OPTION = 'et_divi_builder_global_presets_d5';

$GLOBALS['diviops_pic_saved_wpdb'] = $GLOBALS['wpdb'] ?? null;

/**
 * A $wpdb double that can answer the consumer prefilter.
 *
 * Deliberately minimal and explicit about what it returns: the production guard
 * checks `posts`, `get_col`, `prepare` and `esc_like` together, so a double
 * missing any one of them exercises the unavailable path instead — which is a
 * different test, and is covered separately below.
 */
final class DiviOps_PIC_Posts_wpdb {
	/** @var string */
	public $posts = 'wp_posts';
	/** @var string */
	public $last_error = '';
	/** @var array<int,int> */
	public $return_ids = array();

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		return (string) $query;
	}

	public function get_col( $query ) {
		return $this->return_ids;
	}
}

/** Seed one preset carrying variable references in all three attribute bags. */
function diviops_pic_seed(): void {
	update_option( DIVIOPS_PIC_OPTION, array(
		'module' => array(
			'divi/heading' => array(
				'default' => '',
				'items'   => array(
					'pres1' => array(
						'name'        => 'Hero',
						'type'        => 'module',
						'moduleName'  => 'divi/heading',
						// The walker only looks inside strings containing `$variable(`
						// and pulls the gvid-/gcid- name out of the embedded JSON.
						'attrs'       => array(
							'decoration' => array(
								'background' => array( 'desktop' => array( 'value' => array(
									'color' => '$variable({"name":"gcid-brand-primary","settings":{}})$',
								) ) ),
							),
						),
						'styleAttrs'  => array(
							'spacing' => array( 'padding' => array(
								'top' => '$variable({"name":"gvid-space-md","settings":{}})$',
							) ),
						),
						'renderAttrs' => array(
							'font' => array( 'size' => '$variable({"name":"gvid-text-lg","settings":{}})$' ),
						),
					),
					// No variable references at all: the empty case has to be
					// distinguishable from the populated one, or an assertion that
					// three ids come back would pass on a walker that returned
					// everything it ever saw.
					'pres2' => array(
						'name'       => 'Plain',
						'type'       => 'module',
						'moduleName' => 'divi/heading',
						'attrs'      => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => '#FFFFFF' ) ) ) ) ),
					),
				),
			),
		),
		'group'  => array(),
	), false );
}

/** Invoke preset_inspect and return its data payload. */
function diviops_pic_inspect( string $preset_id ): array {
	$response = diviops_call( 'preset_inspect', array( new DiviOps_Test_Request( array( 'preset_id' => $preset_id ) ) ) );
	return $response->get_data()['data'] ?? array();
}

/** Call the scanner directly with an explicit scope. */
function diviops_pic_scan( string $preset_id, ?array $types = null, ?array $statuses = null ): array {
	$args = array( $preset_id );
	if ( null !== $types ) {
		$args[] = $types;
		$args[] = null === $statuses ? array( 'publish', 'draft', 'private' ) : $statuses;
	}
	return diviops_call( 'collect_preset_consumer_samples', $args );
}

diviops_pic_seed();

// ── 1. Variable references ────────────────────────────────────────────────

$inspect = diviops_pic_inspect( 'pres1' );
assert_true(
	isset( $inspect['variable_references']['ids'] ) && is_array( $inspect['variable_references']['ids'] ),
	'#470: preset_inspect carries a variable_references.ids list'
);

$ids = (array) ( $inspect['variable_references']['ids'] ?? array() );
sort( $ids );
assert_same(
	array( 'gcid-brand-primary', 'gvid-space-md', 'gvid-text-lg' ),
	$ids,
	'#470: all three attribute bags are walked — attrs, styleAttrs and renderAttrs each contribute one id'
);

// The empty case, so the assertion above is about this preset rather than about
// the walker returning a fixed set.
$plain = diviops_pic_inspect( 'pres2' );
assert_same(
	array(),
	(array) ( $plain['variable_references']['ids'] ?? array() ),
	'#470: a preset binding no variables reports an empty list, not the previous preset\'s'
);

// The response says what the ids do and do not cover. This is the claim that
// stops a caller reading an empty list as "this preset uses no tokens".
assert_true(
	is_string( $inspect['variable_references']['coverage'] ?? null )
		&& false !== stripos( $inspect['variable_references']['coverage'], 'direct' ),
	'#470: the ids carry their own caveat — they are the direct references, not a transitive closure'
);

// ── 2. The scan status distinguishes the three zeros ──────────────────────
//
// Each block below reaches ONE path and pins its status. Without all three,
// a constant string would satisfy any one of them.

// (a) empty scope: returns before the query runs.
$empty_scope = diviops_pic_scan( 'pres1', array(), array( 'publish' ) );
assert_same( 0, $empty_scope['count'], '#470: an empty post-type scope finds nothing' );
assert_same(
	'empty_scope',
	$empty_scope['scan'],
	'#470: and says so — the caller asked for no scope, which is not a database problem'
);
$empty_statuses = diviops_pic_scan( 'pres1', array( 'page' ), array() );
assert_same(
	'empty_scope',
	$empty_statuses['scan'],
	'#470: an empty status scope is the same case'
);

// (b) unavailable: the harness $wpdb has no posts property or get_col().
$GLOBALS['wpdb'] = $GLOBALS['diviops_pic_saved_wpdb'];
$unavailable = diviops_pic_scan( 'pres1' );
assert_same( 0, $unavailable['count'], '#470: an unusable $wpdb finds nothing' );
assert_same(
	'unavailable',
	$unavailable['scan'],
	'#470: and reports unavailable — this zero is not evidence of anything'
);

// (c) complete_within_scope: a real query that returned no rows.
$GLOBALS['wpdb'] = new DiviOps_PIC_Posts_wpdb();
$clean = diviops_pic_scan( 'pres1' );
assert_same( 0, $clean['count'], '#470: a scan that ran and matched nothing also finds nothing' );
assert_same(
	'complete_within_scope',
	$clean['scan'],
	'#470: but reports complete_within_scope — THIS zero is evidence, and the two must not render alike'
);

// The pair above is the whole point, asserted as a pair so neither can drift.
assert_true(
	$unavailable['count'] === $clean['count'] && $unavailable['scan'] !== $clean['scan'],
	'#470: identical counts, different statuses — exactly the distinction that did not exist before'
);

// (d) a query error is unavailable, not a clean empty result.
$erroring             = new DiviOps_PIC_Posts_wpdb();
$erroring->last_error = 'MySQL server has gone away';
$GLOBALS['wpdb']      = $erroring;
assert_same(
	'unavailable',
	diviops_pic_scan( 'pres1' )['scan'],
	'#470: a query that errored reports unavailable even though it returned an array'
);

$GLOBALS['wpdb'] = new DiviOps_PIC_Posts_wpdb();

// ── 3. The coverage block is derived, not restated ────────────────────────
//
// Upstream hardcodes "page/post ... publish, draft or private". Ours must
// describe the scope actually used, because #314 gave the function arguments
// and preset_inspect calls it twice with different ones.

$coverage = $inspect['coverage'] ?? array();
assert_true(
	is_array( $coverage ) && ! empty( $coverage ),
	'#470: preset_inspect carries a coverage block'
);
assert_true(
	isset( $coverage['block_scan'] ) && isset( $coverage['revision_scan'] ),
	'#470: BOTH scans report their own status — preset_inspect runs the live scan and the #314 revision scan separately, and they can differ'
);
$coverage_blocks = (string) ( $coverage['blocks'] ?? '' );
foreach ( DiviOps_Agent::SCANNABLE_POST_TYPES as $type ) {
	assert_true(
		false !== strpos( $coverage_blocks, $type ),
		sprintf( '#470: the coverage text names the %s post type it actually scanned, rather than a hardcoded page/post pair', $type )
	);
}
assert_true(
	is_array( $coverage['excluded'] ?? null ) && in_array( 'Theme Builder', $coverage['excluded'], true ),
	'#470: the block names what it did not reach, so an operator is not left inferring the boundary'
);
assert_same(
	10,
	$coverage['sample_limit'] ?? null,
	'#470: the sample cap is reported — a ten-item list is not evidence there are only ten consumers'
);
assert_true(
	is_string( $coverage['zero_references'] ?? null ) && false !== stripos( $coverage['zero_references'], 'not mean safe to delete' ),
	'#470: and a zero is explicitly not a deletion warrant'
);

// The derived text must track the constant. If SCANNABLE_POST_TYPES ever gains a
// type and the string does not, this fails rather than quietly going stale —
// which is the failure mode a hardcoded string guarantees.
assert_same(
	count( DiviOps_Agent::SCANNABLE_POST_TYPES ),
	count( array_filter(
		DiviOps_Agent::SCANNABLE_POST_TYPES,
		static function ( $type ) use ( $coverage_blocks ) {
			return false !== strpos( $coverage_blocks, $type );
		}
	) ),
	'#470: every scanned post type appears in the coverage text — the string is derived from the constant, not written beside it'
);

// ── 4. coverage.status degrades when a scan could not run ─────────────────
//
// A status frozen at "partial" beside a block_scan of "unavailable" is the same
// looks-fine-while-blind problem one level up.
$GLOBALS['wpdb'] = $GLOBALS['diviops_pic_saved_wpdb'];
$blind = diviops_pic_inspect( 'pres1' );
assert_same(
	'unavailable',
	$blind['coverage']['block_scan'] ?? null,
	'#470: with an unusable $wpdb the block scan reports unavailable'
);
assert_same(
	'unavailable',
	$blind['coverage']['status'] ?? null,
	'#470: and the coverage status follows it down rather than still claiming partial coverage'
);

$GLOBALS['wpdb'] = new DiviOps_PIC_Posts_wpdb();
$seeing = diviops_pic_inspect( 'pres1' );
assert_same(
	'partial',
	$seeing['coverage']['status'] ?? null,
	'#470: a scan that ran reports partial — partial because of the documented exclusions, not because it failed'
);
assert_true(
	( $blind['coverage']['status'] ?? 'a' ) !== ( $seeing['coverage']['status'] ?? 'b' ),
	'#470: the two states really do differ, so the status carries information'
);

// ── 5. What was already here is not replaced ──────────────────────────────
//
// #314's revision split and scanned_post_types are richer than upstream's shape
// and nothing in this adoption removes them.
assert_true(
	array_key_exists( 'revision_ref_count', (array) ( $seeing['references'] ?? array() ) ),
	'#470: the #314 revision split survives the adoption'
);
assert_same(
	DiviOps_Agent::SCANNABLE_POST_TYPES,
	$seeing['references']['scanned_post_types'] ?? null,
	'#470: and references.scanned_post_types is untouched'
);

$GLOBALS['wpdb'] = $GLOBALS['diviops_pic_saved_wpdb'];
