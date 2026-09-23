<?php
// SPDX-License-Identifier: MIT
/**
 * A preset registry write invalidates Divi's compiled CSS site-wide (#403).
 *
 * The same defect #381 fixed for global colours, fonts and variables, in the one
 * domain #381 deliberately left out. Preset definitions persist through a single
 * funnel, `save_d5_presets()` (`trait-core.php`), reached from nine call sites in
 * six handlers. `trait-preset.php` called `invalidate_divi_cache()` exactly once,
 * inside `preset_reassign`'s per-page loop — correct for the post it rewrites,
 * and silent about the preset DEFINITION every one of those nine writes changes.
 *
 * A preset is shared across posts by definition. Editing one can restyle every
 * module bound to it, so this was the widest blast radius in the plugin
 * invalidating nothing.
 *
 * ## One premise of #403 is wrong, and the fix does not rest on it
 *
 * The issue argues against putting the call in `save_d5_presets()` because
 * "`preset_cleanup` calls it up to three times per request, and
 * `preset_set_default` twice". That counts call SITES. Traced here: all three of
 * `preset_cleanup`'s are in mutually exclusive `action` branches that each
 * return, and both of `preset_set_default`'s likewise — so **every handler
 * performs at most one registry write per request** and the funnel would not
 * have multiplied anything.
 *
 * The fix still belongs in the handlers, for the reason that survives: the
 * funnel returns nothing a handler could report, and #381 established that the
 * envelope carries a `cache` report so a caller can tell an invalidated write
 * from one where `WP_Filesystem` was unavailable. A funnel-level call would
 * invalidate silently and leave every envelope unable to say so.
 *
 * ## What is asserted
 *
 * The physical effect — which files survive a real `et-cache` tree on disk —
 * never the envelope's own description of itself. `skills/divi-5-builder/SKILL.md`
 * claimed this behaviour existed while it did not, and that is exactly what made
 * #381 invisible for as long as it was. An assertion on the response text would
 * have passed against the documentation the whole time.
 *
 * The preserved-file assertions carry as much weight as the deleted ones: a
 * sweep that deleted everything under `et-cache/` would satisfy "the stale CSS
 * is gone" while unstyling an open Visual Builder session, so `-vb-` runtime CSS
 * and non-Divi files are pinned as survivors on every handler.
 *
 * ## Not covered here, and known
 *
 * `preset_reassign`'s chain-swap write is asserted at its call site rather than
 * driven end to end — it needs a page corpus, a group chain and a rollback run
 * record, which `tests/test-preset-reassign-write-safety.php` also declines to
 * build for the same reason. Its per-page `invalidate_divi_cache()` is
 * untouched by this change and was already correct.
 *
 * @package DiviOps
 */

// Claimed BEFORE wp-shim.php, which would otherwise define it as nonexistent.
$diviops_pci_root = sys_get_temp_dir() . '/diviops-pci-' . getmypid();
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', $diviops_pci_root );
}

require_once __DIR__ . '/wp-shim.php';
require_once __DIR__ . '/divi-active-shim.php';
require_once __DIR__ . '/wp-filesystem-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

const DIVIOPS_PCI_OPTION = 'et_divi_builder_global_presets_d5';

/** Recursively delete a directory tree. Fixtures only — never a real cache root. */
function diviops_pci_rmtree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			diviops_pci_rmtree( $path );
		} else {
			unlink( $path );
		}
	}
	rmdir( $dir );
}

/**
 * Build an et-cache tree covering every class of file the sweep must decide about.
 *
 * Returns absolute paths keyed by the verdict each one pins, so an assertion
 * reads as the rule it enforces rather than as a path.
 */
function diviops_pci_seed_cache(): array {
	$root = WP_CONTENT_DIR . '/et-cache';
	diviops_pci_rmtree( $root );

	$paths = array(
		// Compiled per-post CSS: under et-cache/{post_id}/, `et-` prefix, `.css`
		// suffix — the two conditions is_divi_css_basename() checks.
		'post_css'    => $root . '/900390/et-core-unified-900390.css',
		// Visual Builder runtime CSS. Preserved on purpose.
		'vb_css'      => $root . '/900390/et-core-unified-vb-900390.css',
		// Not Divi's: fails the `et-` prefix test. Nothing may touch it.
		'foreign_css' => $root . '/900390/theme-overrides.css',
		// Divi compiles archive/taxonomy/home/global CSS outside any post dir, and
		// a preset change reaches those pages as much as it reaches a post.
		'global_css'  => $root . '/global/et-divi-customizer-global.css',
	);

	foreach ( $paths as $path ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		file_put_contents( $path, "/* compiled */\n" );
	}
	return $paths;
}

/** Seed the canonical D5 registry. */
function diviops_pci_seed_registry(): void {
	update_option( DIVIOPS_PCI_OPTION, array(
		'module' => array(
			'divi/heading' => array(
				'default' => 'p1',
				'items'   => array(
					'p1' => array( 'name' => 'Hero', 'attrs' => array( 'a' => 1 ) ),
					'p2' => array( 'name' => 'ZZ Orphan', 'attrs' => array( 'b' => 2 ) ),
					// A repeated phrase, which is_spam_preset_name() matches. Present so
					// the default full clean and the spam-scoped remove_orphans both have
					// something real to act on — otherwise they are no-ops, and a test
					// asserting that they swept the cache would be asserting the wrong
					// thing about a branch that correctly did nothing.
					'p3' => array( 'name' => 'Banner Banner', 'attrs' => array( 'c' => 3 ) ),
				),
			),
		),
		'group'  => array(),
	), false );
}

/**
 * Seed a registry with nothing for any cleanup action to act on.
 *
 * Exists because the default full clean is the one branch whose no-op case the
 * prefix fixtures cannot produce: every other no-op here is a prefix that
 * matches nothing, and the full clean does not take a prefix. Without this, a
 * full clean that dropped `$modified` from its gate and swept on every request
 * passed the whole file.
 */
function diviops_pci_seed_clean_registry(): void {
	update_option( DIVIOPS_PCI_OPTION, array(
		'module' => array(
			'divi/heading' => array(
				'default' => 'q1',
				'items'   => array(
					// No repeated phrase, so is_spam_preset_name() does not match; no
					// second preset, so there is nothing to dedupe against.
					'q1' => array( 'name' => 'Hero', 'attrs' => array( 'a' => 1 ) ),
				),
			),
		),
		'group'  => array(),
	), false );
}

/** Invoke a handler and return its envelope body. */
function diviops_pci_body( string $method, array $params = array() ): array {
	$response = diviops_call( $method, array( new DiviOps_Test_Request( $params ) ) );
	return is_object( $response ) && method_exists( $response, 'get_data' )
		? $response->get_data()
		: (array) $response;
}

/**
 * Seed cache + registry, run one handler, and report what survived.
 *
 * Re-seeding per handler is what makes each row independent: a shared tree would
 * let the first handler's sweep satisfy every later assertion.
 *
 * @return array{ok: bool, body: array, post_css: bool, vb_css: bool, foreign_css: bool, global_css: bool, seeded: int}
 */
function diviops_pci_run( string $method, array $params, string $seeder = 'diviops_pci_seed_registry' ): array {
	$paths  = diviops_pci_seed_cache();
	$seeded = 0;
	foreach ( $paths as $path ) {
		if ( is_file( $path ) ) {
			$seeded++;
		}
	}
	$seeder();
	$body = diviops_pci_body( $method, $params );

	return array(
		'ok'          => (bool) ( $body['ok'] ?? false ),
		'body'        => $body,
		'seeded'      => $seeded,
		'post_css'    => file_exists( $paths['post_css'] ),
		'vb_css'      => file_exists( $paths['vb_css'] ),
		'foreign_css' => file_exists( $paths['foreign_css'] ),
		'global_css'  => file_exists( $paths['global_css'] ),
	);
}

// ---------------------------------------------------------------------------
// 1. Every registry write invalidates site-wide.
//
// Table-driven so a handler cannot be quietly dropped: the count is asserted
// against the six writes #403 enumerates, and a filter that matched nothing
// would fail rather than pass.
// ---------------------------------------------------------------------------

$writes = array(
	'preset_update'                     => array( 'preset_update', array( 'preset_id' => 'p1', 'name' => 'Hero 2' ) ),
	'preset_delete'                     => array( 'preset_delete', array( 'preset_id' => 'p2' ) ),
	'preset_set_default'                => array( 'preset_set_default', array( 'preset_id' => 'p2' ) ),
	'preset_create'                     => array( 'preset_create', array( 'module_name' => 'divi/heading', 'name' => 'New One', 'type' => 'module', 'attrs' => array( 'c' => 3 ) ) ),
	'preset_cleanup/rename_strip_prefix' => array( 'preset_cleanup', array( 'action' => 'rename_strip_prefix', 'prefix' => 'ZZ ', 'dry_run' => false ) ),
	'preset_cleanup/remove_orphans'     => array( 'preset_cleanup', array( 'action' => 'remove_orphans', 'dry_run' => false ) ),
	'preset_cleanup/full'               => array( 'preset_cleanup', array( 'dry_run' => false ) ),
);

assert_same(
	7,
	count( $writes ),
	'#403: all seven registry-writing entry points are exercised — a shorter table would pass while leaving a handler uncovered'
);

foreach ( $writes as $label => $case ) {
	$run = diviops_pci_run( $case[0], $case[1] );

	assert_same(
		4,
		$run['seeded'],
		sprintf( '#403: the cache fixture existed before %s ran — a sweep over an empty tree would satisfy every deletion assertion below for the wrong reason', $label )
	);
	assert_same(
		true,
		$run['ok'],
		sprintf( '#403: %s succeeded, so a missing cache file is invalidation and not an early error return', $label )
	);
	assert_same(
		false,
		$run['post_css'],
		sprintf( '#403: %s deletes compiled per-post CSS — a preset is shared across posts, so its blast radius is not one post', $label )
	);
	assert_same(
		false,
		$run['global_css'],
		sprintf( '#403: %s deletes compiled CSS outside any post dir too', $label )
	);
	assert_same(
		true,
		$run['vb_css'],
		sprintf( '#403: %s preserves Visual Builder runtime CSS, so an open VB session is not unstyled', $label )
	);
	assert_same(
		true,
		$run['foreign_css'],
		sprintf( '#403: %s leaves a file that is not Divi-compiled CSS alone', $label )
	);
}

// ---------------------------------------------------------------------------
// 2. The write itself still happens.
//
// Invalidation is a side effect OF a write. A version that swept the cache and
// then failed to write, or that swallowed the write, satisfies every assertion
// above — so the registry is read back.
// ---------------------------------------------------------------------------

diviops_pci_seed_cache();
diviops_pci_seed_registry();
diviops_pci_body( 'preset_update', array( 'preset_id' => 'p1', 'name' => 'Hero 2' ) );
$stored = get_option( DIVIOPS_PCI_OPTION, null );
assert_same(
	'Hero 2',
	$stored['module']['divi/heading']['items']['p1']['name'] ?? null,
	'#403: the preset was still written alongside the invalidation'
);

// ---------------------------------------------------------------------------
// 3. A run that writes nothing invalidates nothing.
//
// #381 established this shape in variable_create_fluid_system: clearing every
// compiled file for a no-op is a site-wide cost for no change, and makes
// "nothing to do" and "styles changed" indistinguishable to anything watching
// the cache.
// ---------------------------------------------------------------------------

$noops = array(
	// dry_run: reports what it would do and writes nothing.
	'dry_run/rename'   => array( 'preset_cleanup', array( 'action' => 'rename_strip_prefix', 'prefix' => 'ZZ ', 'dry_run' => true ) ),
	'dry_run/orphans'  => array( 'preset_cleanup', array( 'action' => 'remove_orphans', 'dry_run' => true ) ),
	'dry_run/full'     => array( 'preset_cleanup', array( 'dry_run' => true ) ),
	// A live run whose prefix matches no preset: nothing to rename, nothing saved.
	'live/no_matches'  => array( 'preset_cleanup', array( 'action' => 'rename_strip_prefix', 'prefix' => 'NoSuchPrefix ', 'dry_run' => false ), 'diviops_pci_seed_registry' ),
	// A live DEFAULT full clean against a registry with nothing to clean. This is
	// the only no-op that reaches the third action branch, and without it a full
	// clean that swept on every request — gate dropped — passed this whole file.
	'live/clean_full'  => array( 'preset_cleanup', array( 'dry_run' => false ), 'diviops_pci_seed_clean_registry' ),
);

foreach ( $noops as $label => $case ) {
	$run = diviops_pci_run( $case[0], $case[1], $case[2] ?? 'diviops_pci_seed_registry' );

	assert_same(
		4,
		$run['seeded'],
		sprintf( '#403: the cache fixture existed before the %s run, so a surviving file means it was spared rather than never written', $label )
	);
	assert_same(
		true,
		$run['ok'],
		sprintf( '#403: the %s run succeeded — it is a no-op, not a failure', $label )
	);
	assert_same(
		true,
		$run['post_css'],
		sprintf( '#403: the %s run leaves compiled CSS in place — it changed no preset', $label )
	);
	assert_same(
		true,
		$run['global_css'],
		sprintf( '#403: the %s run leaves site-wide compiled CSS in place too', $label )
	);
}

// The no-op control has to be a real one: if the live no-match run had actually
// modified the registry, the assertions above would be pinning the wrong thing.
diviops_pci_seed_registry();
$before_noop = get_option( DIVIOPS_PCI_OPTION, null );
diviops_pci_body( 'preset_cleanup', array( 'action' => 'rename_strip_prefix', 'prefix' => 'NoSuchPrefix ', 'dry_run' => false ) );
assert_same(
	$before_noop,
	get_option( DIVIOPS_PCI_OPTION, null ),
	'#403: the live no-match run really did leave the registry untouched, so it is a valid no-op control'
);

diviops_pci_seed_clean_registry();
$before_clean = get_option( DIVIOPS_PCI_OPTION, null );
diviops_pci_body( 'preset_cleanup', array( 'dry_run' => false ) );
assert_same(
	$before_clean,
	get_option( DIVIOPS_PCI_OPTION, null ),
	'#403: the live full clean against a clean registry really did write nothing, so it too is a valid no-op control'
);

// ---------------------------------------------------------------------------
// 4. The envelope can say what happened.
//
// #381's contract: a caller must be able to tell an invalidated write from one
// where WP_Filesystem was unavailable, because both return ok:true. Asserted
// only AFTER the physical effect above, never instead of it.
// ---------------------------------------------------------------------------

$reported = diviops_pci_run( 'preset_update', array( 'preset_id' => 'p1', 'name' => 'Hero 3' ) );
assert_same(
	'invalidated',
	$reported['body']['data']['cache']['status'] ?? null,
	'#403: the envelope reports the sweep, so a caller is not left inferring it'
);
assert_same(
	'sitewide',
	$reported['body']['data']['cache']['scope'] ?? null,
	'#403: and reports the scope as site-wide rather than per-post'
);

$noop_reported = diviops_pci_run( 'preset_cleanup', array( 'action' => 'rename_strip_prefix', 'prefix' => 'NoSuchPrefix ', 'dry_run' => false ) );
// array_key_exists, not `?? 'MISSING'`: the null coalescing operator treats a
// stored null and an absent key identically, so it cannot express the very
// distinction this pair of assertions exists to make.
assert_true(
	array_key_exists( 'cache', $noop_reported['body']['data'] ),
	'#403: a run that wrote nothing still carries the cache key, so "did not sweep" is distinguishable from "this handler does not report"'
);
assert_same(
	null,
	$noop_reported['body']['data']['cache'],
	'#403: and its value is null — no sweep happened'
);

// ---------------------------------------------------------------------------
// 5. preset_reassign's chain-swap write.
//
// Asserted at the call site, for the reason the docblock gives. The gate is the
// load-bearing part: the chain rewrite only persists when it swapped something
// AND the mode is apply, so the invalidation must sit under the same condition
// rather than beside it.
// ---------------------------------------------------------------------------

$reassign_src = ( new ReflectionMethod( 'DiviOps_Agent', 'preset_reassign' ) );
$reassign_lines = file( $reassign_src->getFileName() );
$reassign_body  = implode(
	'',
	array_slice( $reassign_lines, $reassign_src->getStartLine() - 1, $reassign_src->getEndLine() - $reassign_src->getStartLine() + 1 )
);

assert_true(
	false !== strpos( $reassign_body, 'save_d5_presets' ),
	'#403: preset_reassign really does persist the registry, so the assertion below is about a write that exists'
);
assert_same(
	1,
	substr_count( $reassign_body, 'invalidate_divi_cache_sitewide' ),
	'#403: preset_reassign invalidates site-wide exactly once — its per-page invalidate_divi_cache() calls are a different, correctly-scoped thing'
);
assert_true(
	false !== strpos( $reassign_body, "\$chain_swaps > 0 && 'apply' === \$mode" ),
	'#403: the chain-swap gate is still the one the site-wide call sits under — if this moved, the invalidation may have moved out from under it'
);
$gate_at  = strpos( $reassign_body, "\$chain_swaps > 0 && 'apply' === \$mode" );
$sweep_at = strpos( $reassign_body, 'invalidate_divi_cache_sitewide' );
$save_at  = strpos( $reassign_body, 'save_d5_presets' );
assert_true(
	$sweep_at > $gate_at && $sweep_at > $save_at,
	'#403: the site-wide sweep runs after the chain-swap gate opens and after the write'
);
// Ordering alone is not containment, and the difference is the whole point: a
// sweep moved one line down — past the gate's closing brace — is still "after"
// both and would fire on every reassign including a dry run. The gate's `if` sits
// at three tabs, so its closing brace is the first "\n\t\t\t}" after it; finding
// one before the sweep means the sweep escaped the block.
$between = substr( $reassign_body, $gate_at, $sweep_at - $gate_at );
assert_same(
	false,
	strpos( $between, "\n\t\t\t}" ),
	'#403: the sweep is INSIDE the chain-swap gate, not merely after it — a swap-free or dry-run reassign must not clear the cache'
);

diviops_pci_rmtree( WP_CONTENT_DIR . '/et-cache' );
