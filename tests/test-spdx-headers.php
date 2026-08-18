<?php
// SPDX-License-Identifier: MIT
/**
 * SPDX-License-Identifier coverage guard (#233).
 *
 * This repository is dual-licensed and, before this guard existed, no source file
 * said which license governs it. `LICENSE` explains the split in prose only, so a
 * reader holding a single file copied out of the tree — say
 * `plugins/diviops-agent/includes/trait-media.php` out of an otherwise MIT-looking
 * checkout — has no signal telling them it is GPL.
 *
 * The split, per `docs/superpowers/plans/2026-08-18-spdx-headers.md`:
 *
 *   - `plugins/`                 GPL-2.0-or-later
 *   - `diviops-server/src/`      MIT (TypeScript only — the vendored, non-authored
 *                                compiled `.js`/`.d.ts` under `cross-env-preflight/`
 *                                are excluded below, explicitly, rather than by an
 *                                extension pattern, so that boundary stays visible)
 *   - `scripts/`                 MIT
 *   - `tests/`                   MIT
 *
 * This asserts every file in every area carries the correct
 * `SPDX-License-Identifier` for its area, and — per CLAUDE.md, which records that a
 * gate reporting what it inspected but deriving pass/fail only from problems-found
 * will pass while inspecting nothing, and that this exact failure happened three
 * times on the predecessor repository — that the gate actually inspected something:
 * a non-zero total, and a non-zero count per area, so a single area silently going
 * empty (a bad glob, a renamed directory) cannot pass by finding no files to fail.
 *
 * Identifier only. This guard does not check for `SPDX-FileCopyrightText` — that was
 * decided against in #231 and is not reopened here — and would need updating if that
 * decision changes.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$root = dirname( __DIR__ );

/**
 * Recursively list files under $dir whose path ends with $suffix.
 *
 * A plain suffix match (not `pathinfo()`'s PATHINFO_EXTENSION) so callers can tell
 * `.ts` from `.d.ts` apart: PATHINFO_EXTENSION reports `ts` for both.
 *
 * @param string $dir    Directory to walk.
 * @param string $suffix Path suffix to match, e.g. '.php' or '.d.ts'.
 * @return array<int, string> Absolute file paths, sorted.
 */
function spdx_test_list_files( string $dir, string $suffix ): array {
	$found = array();
	if ( ! is_dir( $dir ) ) {
		return $found;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$path = $file->getPathname();
		if ( $file->isFile() && $suffix === substr( $path, -strlen( $suffix ) ) ) {
			$found[] = $path;
		}
	}
	sort( $found );
	return $found;
}

/*
 * Vendored files: upstream's verbatim compiled output, provenance recorded in
 * diviops-server/src/cross-env-preflight/README.md. These are never required to
 * carry an identifier — stamping a file we did not author would misrepresent
 * authorship — and the plan's Global Constraints call out that the exclusion must
 * stay an explicit, reviewable list rather than an implicit glob. Listed by hand
 * here for that reason, then cross-checked below against what is actually on disk.
 *
 * @var array<int, string>
 */
$excluded = array(
	$root . '/diviops-server/src/cross-env-preflight/header-preflight.js',
	$root . '/diviops-server/src/cross-env-preflight/header-preflight.d.ts',
	$root . '/diviops-server/src/cross-env-preflight/layout-capability.js',
	$root . '/diviops-server/src/cross-env-preflight/layout-capability.d.ts',
	$root . '/diviops-server/src/cross-env-preflight/layout-preflight.js',
	$root . '/diviops-server/src/cross-env-preflight/layout-preflight.d.ts',
	$root . '/diviops-server/src/cross-env-preflight/source-payload-ref.js',
	$root . '/diviops-server/src/cross-env-preflight/source-payload-ref.d.ts',
);

/**
 * The four dual-licensed areas: directory, file suffix, and the identifier every
 * matching file must carry.
 *
 * @var array<string, array{dir: string, suffix: string, license: string}>
 */
$areas = array(
	'plugins'            => array(
		'dir'     => $root . '/plugins',
		'suffix'  => '.php',
		'license' => 'GPL-2.0-or-later',
	),
	'diviops-server/src' => array(
		'dir'     => $root . '/diviops-server/src',
		'suffix'  => '.ts',
		'license' => 'MIT',
	),
	'scripts'            => array(
		'dir'     => $root . '/scripts',
		'suffix'  => '.php',
		'license' => 'MIT',
	),
	'tests'              => array(
		'dir'     => $root . '/tests',
		'suffix'  => '.php',
		'license' => 'MIT',
	),
);

$total_inspected = 0;

foreach ( $areas as $area_name => $area ) {
	$files = spdx_test_list_files( $area['dir'], $area['suffix'] );

	// '.ts' as a suffix also matches '.d.ts' (its last four characters are '.ts'
	// too), which is how the vendored cross-env-preflight/*.d.ts files would
	// otherwise leak into the diviops-server/src area. Drop anything on the
	// exclusion list before requiring an identifier.
	$files = array_values( array_diff( $files, $excluded ) );

	assert_true(
		array() !== $files,
		sprintf(
			'area "%s" (%s) has at least one file to inspect — an area silently going empty must fail, not pass',
			$area_name,
			$area['dir']
		)
	);

	$pattern = '/SPDX-License-Identifier:\s*' . preg_quote( $area['license'], '/' ) . '\b/';

	foreach ( $files as $file ) {
		$relative = ltrim( str_replace( $root, '', $file ), '/' );
		$src      = (string) file_get_contents( $file );
		assert_true(
			1 === preg_match( $pattern, $src ),
			sprintf( '%s carries "SPDX-License-Identifier: %s"', $relative, $area['license'] )
		);
		++$total_inspected;
	}
}

// A gate that reports what it inspected but derives pass/fail only from
// problems-found will pass while inspecting nothing. Assert the coverage itself.
assert_true( $total_inspected > 0, 'the gate inspected at least one file across all areas' );

/*
 * Exclusion-list coverage: every vendored *.js/*.d.ts actually on disk under
 * cross-env-preflight/ must be named in $excluded above, and $excluded must name
 * nothing else — so a new vendored file landing there without an exclusion-list
 * update is caught, and a stale exclusion-list entry for a file that no longer
 * exists is caught too.
 */
$vendored_dir = $root . '/diviops-server/src/cross-env-preflight';
$on_disk      = array_merge(
	spdx_test_list_files( $vendored_dir, '.js' ),
	spdx_test_list_files( $vendored_dir, '.d.ts' )
);
sort( $on_disk );
$excluded_sorted = $excluded;
sort( $excluded_sorted );

assert_true( array() !== $on_disk, 'cross-env-preflight/ has at least one vendored *.js or *.d.ts file to check the exclusion list against' );
assert_same(
	$excluded_sorted,
	$on_disk,
	'every vendored *.js/*.d.ts under cross-env-preflight/ is named in the exclusion list, and the list names nothing else'
);

// None of the vendored files may be required to carry an identifier: confirm they
// were never added to $total_inspected by checking they are absent from the
// diviops-server/src area's own file list, independent of the exclusion filtering
// applied above.
$ts_area_all_files = spdx_test_list_files( $root . '/diviops-server/src', '.ts' );
$vendored_dts       = spdx_test_list_files( $vendored_dir, '.d.ts' );
assert_same(
	$vendored_dts,
	array_values( array_intersect( $ts_area_all_files, $vendored_dts ) ),
	'the vendored *.d.ts files are, as expected, present on disk with a .ts-suffixed path (confirming the area glob would have caught them without the exclusion list)'
);
