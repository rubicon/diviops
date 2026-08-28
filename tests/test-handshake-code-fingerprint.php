<?php
// SPDX-License-Identifier: MIT
/**
 * `/handshake` reports a code_fingerprint over the plugin's own source (#215).
 *
 * A version number cannot answer "which build is actually running." Twice in
 * one week the repo and the installed plugin carried the *same* version with
 * different `trait-page.php` contents — the normal state of affairs between a
 * source change and the release that bumps the version, and the normal state
 * of every rsync deploy to the dev site. A version comparison cannot detect
 * that class of drift at all, so the only available answer was folklore.
 *
 * The fingerprint has to be a fact about the files, which means it must be
 * computed where the files are — the plugin. The MCP server cannot see them.
 *
 * Determinism is the whole value here: two checkouts of the same commit must
 * produce the same digest on any machine, or the field is worse than absent
 * because a spurious mismatch reads as a real one. Hence the properties
 * asserted below — content only, path-sensitive, mtime-blind, order-blind.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/** Minimal WP_REST_Request stand-in carrying handshake params. */
function fingerprint_handshake_request( array $params ) {
	return new DiviOps_Test_Request( $params );
}

// ── The handshake surface ────────────────────────────────────────────────

$response = DiviOps_Agent::handshake(
	fingerprint_handshake_request( [ 'mcp_server_version' => '99.0.0' ] )
);
$data = $response->get_data();

assert_true(
	array_key_exists( 'code_fingerprint', $data ),
	'/handshake reports a code_fingerprint — the server cannot read the plugin files, so only the plugin can answer which build is running (#215)'
);

assert_same(
	1,
	preg_match( '/^[0-9a-f]{64}$/', (string) ( $data['code_fingerprint'] ?? '' ) ),
	'code_fingerprint is a lowercase sha256 hex digest'
);

assert_same(
	$data['code_fingerprint'],
	DiviOps_Agent::code_fingerprint(),
	'the handshake reports exactly what code_fingerprint() computes — no separate code path to drift'
);

assert_same(
	DiviOps_Agent::code_fingerprint(),
	DiviOps_Agent::code_fingerprint(),
	'code_fingerprint() is stable across calls within a request'
);

// ── Determinism properties, over a synthetic tree ────────────────────────
//
// Asserted against a fixture rather than the real plugin directory: proving
// "the digest changes when a file changes" requires changing a file, and the
// files under test are the ones actually shipping.

/**
 * Build a throwaway plugin-shaped tree.
 *
 * @param array<string, string> $files Relative path => contents.
 * @return string Absolute path to the tree root.
 */
function fingerprint_fixture_tree( array $files ): string {
	$root = sys_get_temp_dir() . '/diviops-fingerprint-' . bin2hex( random_bytes( 8 ) );
	foreach ( $files as $relative => $contents ) {
		$path = $root . '/' . $relative;
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		file_put_contents( $path, $contents );
	}
	return $root;
}

/**
 * Remove a fixture tree.
 *
 * @param string $root Tree root.
 */
function fingerprint_fixture_cleanup( string $root ): void {
	if ( ! is_dir( $root ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $root );
}

$baseline_files = [
	'diviops-agent.php'          => "<?php // main\n",
	'includes/trait-page.php'    => "<?php // page\n",
	'includes/trait-meta.php'    => "<?php // meta\n",
	'includes/nested/extra.php'  => "<?php // nested\n",
];

$baseline_root = fingerprint_fixture_tree( $baseline_files );
$baseline      = DiviOps_Agent::code_fingerprint( $baseline_root );

// Identical contents at a different location hash identically: the digest is
// over relative paths and bytes, never absolute paths or the filesystem.
$twin_root = fingerprint_fixture_tree( $baseline_files );
assert_same(
	$baseline,
	DiviOps_Agent::code_fingerprint( $twin_root ),
	'two trees with identical relative paths and contents produce the same fingerprint — otherwise no two machines could ever agree'
);

// mtime must not participate. A deploy that rewrites files with identical
// bytes (rsync without --checksum, a re-unzip) would otherwise report drift
// that does not exist, and a field that cries wolf gets ignored.
touch( $twin_root . '/includes/trait-page.php', time() + 3600 );
clearstatcache();
assert_same(
	$baseline,
	DiviOps_Agent::code_fingerprint( $twin_root ),
	'touching a file without changing its bytes does not change the fingerprint — mtime is not an input'
);

// The case the issue exists for: same version, different code.
file_put_contents( $twin_root . '/includes/trait-page.php', "<?php // page, edited\n" );
$edited = DiviOps_Agent::code_fingerprint( $twin_root );
assert_true(
	$edited !== $baseline,
	'a one-line change inside includes/ changes the fingerprint — this is the same-version-different-code case a version comparison cannot see'
);

// A nested file is covered. includes/ is flat today; a future subdirectory
// must not silently drop out of the digest, because a fingerprint blind to
// part of the code is exactly the false reassurance this field replaces.
$nested_root = fingerprint_fixture_tree( $baseline_files );
file_put_contents( $nested_root . '/includes/nested/extra.php', "<?php // nested, edited\n" );
assert_true(
	DiviOps_Agent::code_fingerprint( $nested_root ) !== $baseline,
	'a change in a nested includes/ subdirectory changes the fingerprint'
);

// Paths participate, so a rename is drift even when every byte is preserved.
$renamed_root = fingerprint_fixture_tree( $baseline_files );
rename(
	$renamed_root . '/includes/trait-page.php',
	$renamed_root . '/includes/trait-renamed.php'
);
assert_true(
	DiviOps_Agent::code_fingerprint( $renamed_root ) !== $baseline,
	'renaming a file changes the fingerprint — file paths are part of the digest, not just the concatenated bytes'
);

// The main plugin file is in scope: it holds VERSION, the route table and the
// capability list, so a build differing only there is a different build.
$main_root = fingerprint_fixture_tree( $baseline_files );
file_put_contents( $main_root . '/diviops-agent.php', "<?php // main, edited\n" );
assert_true(
	DiviOps_Agent::code_fingerprint( $main_root ) !== $baseline,
	'a change to the main plugin file changes the fingerprint'
);

// Non-PHP siblings are out of scope. readme.txt, .zip archives and build
// artifacts differ between a git checkout and an installed copy of the same
// code; including them would make every legitimate install look like drift.
$noise_root = fingerprint_fixture_tree(
	$baseline_files + [
		'readme.txt'            => "not code\n",
		'includes/notes.md'     => "not code\n",
		'assets/diviops.js'     => "not php\n",
	]
);
assert_same(
	$baseline,
	DiviOps_Agent::code_fingerprint( $noise_root ),
	'only the main plugin file and includes/**/*.php are hashed — non-PHP files differ between a checkout and an install of the same build'
);

// A missing includes/ directory must not fatal the handshake. The handshake is
// the one call that has to answer on a broken install, since that is when
// someone is trying to find out what is wrong.
$bare_root = fingerprint_fixture_tree( [ 'diviops-agent.php' => "<?php // main\n" ] );
assert_same(
	1,
	preg_match( '/^[0-9a-f]{64}$/', DiviOps_Agent::code_fingerprint( $bare_root ) ),
	'a tree with no includes/ directory still yields a digest rather than an error'
);

foreach ( [ $baseline_root, $twin_root, $nested_root, $renamed_root, $main_root, $noise_root, $bare_root ] as $tree ) {
	fingerprint_fixture_cleanup( $tree );
}
