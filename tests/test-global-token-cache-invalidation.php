<?php
// SPDX-License-Identifier: MIT
/**
 * A global colour, font or variable write invalidates Divi's compiled CSS site-wide (#381).
 *
 * `invalidate_divi_cache()` is called from eight traits. The three that write global design
 * tokens — `trait-global-color.php`, `trait-global-font.php`, `trait-variable.php` — called
 * it zero times, measured at `43393d9`. `trait-page.php`'s 12 calls are the positive control
 * for that search, so the three zeros were real absences rather than a bad pattern.
 *
 * Those are the wrong three to miss. A page write invalidates one post's compiled CSS and is
 * correctly scoped. **A global token has no owning post** — changing one colour can restyle
 * every page on the site — so the three domains with the widest blast radius were the three
 * invalidating nothing, leaving stale CSS sitewide until something else happened to clear it.
 * Reported from `staging.colleyvillelions.com`: after four `global_color_update` calls a
 * subsequent `meta_flush_cache {all:true}` still freed 18 files / 1,176,093 bytes.
 *
 * ## What is asserted
 *
 * The physical effect, not the response text. These assertions build a real `et-cache` tree
 * on disk and check which files survive the handler call. Asserting the envelope's own
 * report would be asserting the code's description of itself; `skills/divi-5-builder/SKILL.md`
 * already claimed this behaviour existed while it did not, which is the failure mode.
 *
 * The preserved-file assertions matter as much as the deleted ones. A sweep that deleted
 * everything under `et-cache/` would satisfy "the stale CSS is gone" and unstyle an open
 * Visual Builder session, so `-vb-` runtime CSS and non-Divi files are pinned as survivors.
 *
 * ## Why WP_CONTENT_DIR is defined here
 *
 * `wp-shim.php` points `WP_CONTENT_DIR` at a path that does not exist, so the cache branch is
 * skipped everywhere else. A constant cannot be redefined, so this file claims it first —
 * which only became possible with #396, where each test file gained its own process. Before
 * that, whichever file loaded first would have fixed the value for the whole run.
 *
 * The companion file `tests/test-global-token-cache-invalidation-unavailable.php` covers the
 * opposite case: no `WP_Filesystem`, where the write must still succeed and must report that
 * it could not invalidate rather than looking like it did.
 *
 * @package DiviOps
 */

// Claimed BEFORE wp-shim.php, which would otherwise define it as nonexistent.
$diviops_gtc_root = sys_get_temp_dir() . '/diviops-gtc-' . getmypid();
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', $diviops_gtc_root );
}

require_once __DIR__ . '/wp-shim.php';
// The handlers under test sit behind Divi's option layer; the shim docblock explains why
// et_get_option() must stay opt-in rather than joining wp-shim.php.
require_once __DIR__ . '/divi-active-shim.php';
// The sweep routes every delete through WP_Filesystem, as Divi's own clearer does.
require_once __DIR__ . '/wp-filesystem-shim.php';
require_once dirname( __DIR__ ) . '/plugins/diviops-agent/diviops-agent.php';

/**
 * Build an et-cache tree covering every class of file the sweep must decide about.
 *
 * Returns absolute paths keyed by the verdict each one pins, so an assertion reads as the
 * rule it enforces rather than as a path.
 */
function diviops_gtc_seed_cache(): array {
	$root = WP_CONTENT_DIR . '/et-cache';
	diviops_gtc_rmtree( $root );

	$paths = array(
		// Compiled per-post CSS. Divi's own naming: files under et-cache/{post_id}/
		// begin `et-` and end `.css` — the two conditions is_divi_css_basename()
		// checks (trait-meta.php:1549-1557).
		'post_css'      => $root . '/900390/et-core-unified-900390.css',
		'post_min_css'  => $root . '/900390/et-core-unified-900390.min.css',
		// Visual Builder runtime CSS. Preserved on purpose: deleting it unstyles an
		// open VB session, which is why every Divi save path passes
		// preserve_vb_files=true.
		'vb_css'        => $root . '/900390/et-core-unified-vb-900390.css',
		// Not Divi's: fails the `et-` prefix test. Nothing may touch it.
		'foreign_css'   => $root . '/900390/theme-overrides.css',
		// Non-post subtree. Divi compiles archive/taxonomy/home/notfound/global CSS
		// here too, and a global token change reaches those pages as much as it
		// reaches a post.
		'global_css'    => $root . '/global/et-divi-customizer-global.css',
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

/**
 * Recursively delete a directory tree. Fixtures only — never a real cache root.
 */
function diviops_gtc_rmtree( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) ?: array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			diviops_gtc_rmtree( $path );
		} else {
			unlink( $path );
		}
	}
	rmdir( $dir );
}

/** Seed one global colour so the upsert has something to merge into. */
function diviops_gtc_seed_palette(): void {
	et_update_option( 'et_global_data', array(
		'global_colors' => array(
			'gcid-cachetest' => array(
				'id'          => 'gcid-cachetest',
				'label'       => 'brand',
				'color'       => '#FFFFFF',
				'order'       => '3',
				'status'      => 'active',
				'folder'      => '',
				'usedInPosts' => array(),
				'lastUpdated' => '2026-09-01T00:00:00.000Z',
			),
		),
	) );
}

/** Drive global_color_upsert through a request, as the REST layer would. */
function diviops_gtc_upsert_color( array $colors ) {
	$req = new WP_REST_Request();
	$req->set_param( 'colors', $colors );
	$req->set_param( 'mode', 'merge' );
	return diviops_call( 'global_color_upsert', array( $req ) );
}

// ---------------------------------------------------------------------------
// The fixture must actually exist before anything is concluded from a deletion.
// A sweep over an empty tree deletes nothing and would satisfy every "is gone"
// assertion below for the wrong reason.
// ---------------------------------------------------------------------------

$paths = diviops_gtc_seed_cache();
$seeded = 0;
foreach ( $paths as $path ) {
	if ( is_file( $path ) ) {
		$seeded++;
	}
}
assert_same( 5, $seeded, 'the fixture wrote all five cache files before the handler ran' );

// ---------------------------------------------------------------------------
// A colour write invalidates compiled CSS site-wide.
// ---------------------------------------------------------------------------

diviops_gtc_seed_palette();
diviops_gtc_upsert_color( array( array( 'id' => 'gcid-cachetest', 'color' => '#FAFAFA' ) ) );

assert_true(
	! file_exists( $paths['post_css'] ),
	'a colour write deletes compiled per-post CSS'
);
assert_true(
	! file_exists( $paths['post_min_css'] ),
	'and the minified compiled CSS beside it'
);
assert_true(
	! file_exists( $paths['global_css'] ),
	'and CSS outside any post dir — a global token is not scoped to one post'
);
assert_true(
	file_exists( $paths['vb_css'] ),
	'Visual Builder runtime CSS survives, so an open VB session is not unstyled'
);
assert_true(
	file_exists( $paths['foreign_css'] ),
	'a file that is not Divi-compiled CSS is left alone'
);

// ---------------------------------------------------------------------------
// The write itself still succeeds, and still writes. Invalidation is a side
// effect of the write; a version that invalidated and then failed the write, or
// that swallowed the write, would satisfy the assertions above.
// ---------------------------------------------------------------------------

$stored = et_get_option( 'et_global_data' );
assert_same(
	'#FAFAFA',
	$stored['global_colors']['gcid-cachetest']['color'] ?? null,
	'the colour was still written'
);
assert_same(
	'3',
	$stored['global_colors']['gcid-cachetest']['order'] ?? null,
	'and the #380 merge still holds alongside the invalidation'
);

// ---------------------------------------------------------------------------
// The other two traits. #381 is not "the colour handler forgot" — it is that all
// three global-token domains forgot, so one handler passing proves a third of it.
// Each re-seeds the tree, because the previous call swept it.
// ---------------------------------------------------------------------------

$paths = diviops_gtc_seed_cache();
$req   = new WP_REST_Request();
$req->set_param( 'id', 'gfid-cachetest' );
$req->set_param( 'family', 'Inter' );
$req->set_param( 'label', 'Body' );
// Required for a new entry; the enum is 'google'|'system'|'custom'
// (trait-global-font.php:272, valid_font_sources()).
$req->set_param( 'source', 'google' );
$font = diviops_call( 'global_font_create', array( $req ) );

assert_same( true, $font->get_data()['ok'] ?? null, 'the font write succeeds' );
assert_true(
	! file_exists( $paths['post_css'] ),
	'a global font write invalidates compiled CSS site-wide too'
);
assert_true(
	file_exists( $paths['vb_css'] ),
	'and still preserves Visual Builder runtime CSS'
);

$paths = diviops_gtc_seed_cache();
$req   = new WP_REST_Request();
$req->set_param( 'type', 'strings' );
$req->set_param( 'label', 'Tagline' );
$req->set_param( 'value', 'Hello' );
$req->set_param( 'id', 'gvid-cachetest' );
$var = diviops_call( 'variable_create', array( $req ) );

assert_same( true, $var->get_data()['ok'] ?? null, 'the variable write succeeds' );
assert_true(
	! file_exists( $paths['post_css'] ),
	'a global variable write invalidates compiled CSS site-wide too'
);

// ---------------------------------------------------------------------------
// `variable_create` and `variable_update` fork on type: `colors` writes through
// Divi's et_global_data, everything else through the et_divi_global_variables
// registry. They are separate branches with separate writes, and an assertion
// through one says nothing about the other.
//
// This is not a hypothetical. Two mutations survived the first matrix run — one
// deleting the colours-branch invalidation, one adding an invalidation to the
// colours-branch dry run — because every variable assertion above used
// type=strings. Both died once these were added.
// ---------------------------------------------------------------------------

$paths = diviops_gtc_seed_cache();
$req   = new WP_REST_Request();
$req->set_param( 'type', 'colors' );
$req->set_param( 'label', 'Accent' );
$req->set_param( 'value', '#112233' );
$req->set_param( 'id', 'gcid-viacolorbranch' );
$made = diviops_call( 'variable_create', array( $req ) );

assert_same( true, $made->get_data()['ok'] ?? null, 'a colours-typed variable_create succeeds' );
assert_true(
	! file_exists( $paths['post_css'] ),
	'variable_create through the colours branch invalidates too — it is a separate write'
);

$paths = diviops_gtc_seed_cache();
$req   = new WP_REST_Request();
$req->set_param( 'id', 'gcid-viacolorbranch' );
$req->set_param( 'value', '#445566' );
$upd = diviops_call( 'variable_update', array( $req ) );

assert_same( true, $upd->get_data()['ok'] ?? null, 'the colours-typed variable_update succeeds' );
assert_true(
	! file_exists( $paths['post_css'] ),
	'variable_update through the colours branch invalidates too'
);

// ---------------------------------------------------------------------------
// A dry run changes nothing, so it must clear nothing. Without this, a caller
// could not use dry_run to inspect a change on a live site without paying for a
// full CSS regeneration. Both branches, for the reason stated above.
// ---------------------------------------------------------------------------

$paths = diviops_gtc_seed_cache();
$req   = new WP_REST_Request();
$req->set_param( 'type', 'strings' );
$req->set_param( 'label', 'Planned' );
$req->set_param( 'value', 'Not written' );
$req->set_param( 'dry_run', true );
$plan = diviops_call( 'variable_create', array( $req ) );

// The plan proves the call reached the dry-run branch. Without this, a fixture
// rejected earlier by validation would satisfy the assertion below by never
// having run the code it is about.
assert_same( true, $plan->get_data()['data']['dry_run'] ?? null, 'the dry run reached the dry-run branch' );
assert_true(
	file_exists( $paths['post_css'] ),
	'a dry run leaves the compiled CSS alone — it wrote no token to invalidate'
);

$paths = diviops_gtc_seed_cache();
$req   = new WP_REST_Request();
$req->set_param( 'type', 'colors' );
$req->set_param( 'label', 'Planned accent' );
$req->set_param( 'value', '#778899' );
$req->set_param( 'dry_run', true );
$plan = diviops_call( 'variable_create', array( $req ) );

assert_same( true, $plan->get_data()['data']['dry_run'] ?? null, 'the colours dry run reached the dry-run branch' );
assert_true(
	file_exists( $paths['post_css'] ),
	'and the colours-branch dry run clears nothing either'
);

diviops_gtc_rmtree( WP_CONTENT_DIR );
