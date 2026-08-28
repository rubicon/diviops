<?php
// SPDX-License-Identifier: MIT
/**
 * Repo-wide relative-link resolution guard (#195).
 *
 * Upstream's private dev repo nests this tree one level deeper than the published
 * fork, so every relative depth authored there lands one level too high on arrival.
 * The result is always the same signature -- `../../../docs/*.md` -- and it has now
 * been found by hand three times, in three different trees: `diviops-server/README.md`
 * (#90, 14 links), `plugins/diviops-agent/README.md` (#173, 5 links), and `skills/`
 * (#189, 2 links). That is a property of the sync process, not three coincidences, so
 * it is guaranteed to recur and the scope has to be the whole repository.
 *
 * Two conditions are asserted for every relative link, not one:
 *
 *   1. the target resolves on disk, and
 *   2. the resolved path stays inside the repository root.
 *
 * The second is the one that catches this class. `../../../docs/x.md` from
 * `skills/divi-5-builder/` escapes the root, and a plain existence check would report
 * only "missing" -- indistinguishable from a typo, and it would fall silent entirely
 * on a machine where a file of that name happens to exist above the checkout. The
 * resolution below is therefore lexical (segment walking), never `realpath()`:
 * `realpath()` returns false for anything that does not exist, which is exactly the
 * case where the escape needs reporting.
 *
 * External URLs are deliberately out of scope. Network-dependent CI is flaky and this
 * defect class is entirely local relative paths.
 *
 * Non-vacuity, per CLAUDE.md: a gate that reports what it inspected but derives
 * pass/fail only from problems-found will pass while inspecting nothing, and that
 * failure happened three times on the predecessor repository. Three guards here --
 * a non-empty file corpus, a non-zero link count, and synthetic cases that exercise
 * the escape branch even when every real link is clean -- plus a printed count so a
 * silently shrinking corpus is visible in the CI log.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * List every Markdown file git tracks, repository-relative.
 *
 * Asks git rather than walking the filesystem so the corpus is exactly what a push
 * would publish: no `node_modules/`, no build output, and -- importantly -- nothing
 * inside `upstream-releases/`, whose Pro artifacts must never be opened by any tool.
 *
 * @param string $root Repository root.
 * @return array<int, string> Tracked Markdown paths, repository-relative.
 */
function diviops_link_tracked_markdown( string $root ): array {
	$command = sprintf( 'git -C %s ls-files -z -- %s', escapeshellarg( $root ), escapeshellarg( '*.md' ) );
	$output  = shell_exec( $command );
	if ( ! is_string( $output ) || '' === $output ) {
		return array();
	}
	$paths = array_values( array_filter( explode( "\0", $output ), 'strlen' ) );
	sort( $paths );
	return $paths;
}

/**
 * Resolve a repository-relative path lexically, reporting whether it escapes the root.
 *
 * Pure string work: no filesystem access, so a target that does not exist is still
 * classified correctly as inside or outside the tree.
 *
 * @param string $path Repository-relative path, possibly containing `.` and `..`.
 * @return array{path: string, escapes: bool} Normalized path and whether it left the root.
 */
function diviops_link_resolve( string $path ): array {
	$segments = array();
	$escapes  = false;
	foreach ( explode( '/', $path ) as $segment ) {
		if ( '' === $segment || '.' === $segment ) {
			continue;
		}
		if ( '..' === $segment ) {
			if ( array() === $segments ) {
				// Popping with nothing left to pop is the escape: this step went above
				// the repository root. Recorded rather than returned early so the
				// normalized remainder is still reported in the failure message.
				$escapes = true;
				continue;
			}
			array_pop( $segments );
			continue;
		}
		$segments[] = $segment;
	}
	return array(
		'path'    => implode( '/', $segments ),
		'escapes' => $escapes,
	);
}

/**
 * Remove fenced code blocks and inline code spans from Markdown source.
 *
 * Link-shaped text inside code is a quotation, not a link. The plan documents under
 * `docs/superpowers/` are full of it: they quote the exact table row a later task must
 * paste into `SKILL.md`, links and all. Checking those would report a "broken link" for
 * a path that is correct relative to the file the row is destined for -- a gate that
 * cries wolf on correct documentation is a gate that gets switched off.
 *
 * @param string $content Markdown source.
 * @return string Source with code fences and code spans removed.
 */
function diviops_link_strip_code( string $content ): string {
	$lines  = explode( "\n", $content );
	$kept   = array();
	$fence  = '';
	foreach ( $lines as $line ) {
		if ( '' === $fence ) {
			if ( preg_match( '/^ {0,3}(`{3,}|~{3,})/', $line, $open ) ) {
				$fence = $open[1];
				continue;
			}
			$kept[] = $line;
			continue;
		}
		// A closing fence is the same character, at least as long, and carries no info
		// string -- so a ```` ```php ```` line inside a ```` ~~~ ```` block cannot close it.
		if ( preg_match( '/^ {0,3}(`{3,}|~{3,})\s*$/', $line, $close )
			&& $close[1][0] === $fence[0]
			&& strlen( $close[1] ) >= strlen( $fence ) ) {
			$fence = '';
		}
	}
	$stripped = implode( "\n", $kept );

	/*
	 * Inline spans: a run of backticks closed by a run of the same length on the SAME
	 * line. Bounding it to one line is not a simplification, it is the correctness
	 * condition -- a multi-line pattern pairs an opening backtick with whatever
	 * backtick comes next anywhere in the document, and in a file with unbalanced
	 * backtick runs (every long reference document here) that swallows paragraphs of
	 * prose, links included. This exact bug hid three real broken links in
	 * docs/superpowers/plans/2026-07-31-interactions-reference.md while the gate
	 * reported a clean pass. An unpaired backtick now leaves the rest of its line
	 * intact, which fails toward checking too much rather than too little.
	 */
	return (string) preg_replace( '/(`+)[^\n]*?\1/', '', $stripped );
}

/**
 * Extract the link targets of every inline Markdown link and image in a document.
 *
 * Covers `[text](target)` and `![alt](target)`, which is the form every instance of
 * this defect class has taken. An optional `"Title"` is stripped; a fragment is
 * stripped by the caller. Code is removed first -- see diviops_link_strip_code().
 *
 * @param string $content Markdown source.
 * @return array<int, string> Raw link targets, in document order.
 */
function diviops_link_targets( string $content ): array {
	$matched = preg_match_all( '/\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', diviops_link_strip_code( $content ), $matches );
	if ( ! is_int( $matched ) || 0 === $matched ) {
		return array();
	}
	return $matches[1];
}

/**
 * Run the relative-link gate.
 *
 * Everything lives inside a function so no top-level `$file`/`$files` of this test can
 * collide with the runner's own state -- the collision that once corrupted run.php's
 * "in N file(s)" count from tests/test-spdx-headers.php.
 */
function diviops_link_gate_run(): void {
	$root = dirname( __DIR__ );

	assert_true(
		is_dir( $root . '/.git' ) || is_file( $root . '/.git' ),
		'the suite is running inside a git checkout, so git can be asked which files are tracked'
	);

	/*
	 * The escape branch below is only reached by a link that actually escapes, and the
	 * whole point of this gate is that no such link survives in the tree. Without these
	 * synthetic cases, a clean corpus would leave the detection logic itself untested --
	 * the gate would pass without ever having proven it can fail. The cases cover the
	 * authored-too-deep signature, an ordinary sibling hop, the boundary where the `..`
	 * count exactly equals the directory depth (lands on the root, does not leave it),
	 * one step past that boundary, and `.` segments.
	 */
	$resolution_cases = array(
		// [ input, expected path, expected escapes ].
		array( 'skills/divi-5-builder/../../../docs/x.md', 'docs/x.md', true ),
		array( 'skills/divi-5-builder/../SKILL.md', 'skills/SKILL.md', false ),
		array( 'docs/superpowers/plans/../../../README.md', 'README.md', false ),
		array( 'docs/superpowers/plans/../../../../README.md', 'README.md', true ),
		array( 'docs/./plans/../plans/a.md', 'docs/plans/a.md', false ),
	);
	foreach ( $resolution_cases as $case ) {
		$resolved = diviops_link_resolve( $case[0] );
		assert_same( $case[1], $resolved['path'], sprintf( '"%s" normalizes to "%s"', $case[0], $case[1] ) );
		assert_same(
			$case[2],
			$resolved['escapes'],
			sprintf( '"%s" is %s the repository root', $case[0], $case[2] ? 'detected as escaping' : 'detected as staying inside' )
		);
	}

	// Same for extraction: prove the pattern still finds links, and still ignores the
	// forms that are not links, independently of what the corpus happens to contain.
	assert_same(
		array( 'a.md', 'img/b.png', 'c.md#frag', 'https://example.test/d' ),
		diviops_link_targets( "[x](a.md) ![y](img/b.png) [z](c.md#frag)\n[q](https://example.test/d \"Title\")" ),
		'the link extractor finds inline links, images, fragments and titled links'
	);
	assert_same(
		array( 'real.md' ),
		diviops_link_targets(
			"[real](real.md)\n"
			. "`| row | [quoted](in-a-span.md) |`\n"
			. "```markdown\n[quoted](in-a-fence.md)\n```\n"
			. "~~~\n[quoted](in-a-tilde-fence.md)\n~~~\n"
		),
		'the link extractor ignores links quoted inside code spans and fenced code blocks'
	);

	$files = diviops_link_tracked_markdown( $root );

	// A corpus that silently went empty -- a bad pathspec, git missing from PATH --
	// must fail here rather than reporting a clean run over nothing.
	assert_true(
		count( $files ) > 0,
		'git reported at least one tracked Markdown file for this gate to inspect'
	);

	$checked = 0;
	foreach ( $files as $relative ) {
		$content = (string) file_get_contents( $root . '/' . $relative );
		$dir     = dirname( $relative );
		$dir     = '.' === $dir ? '' : $dir;

		foreach ( diviops_link_targets( $content ) as $target ) {
			// Scheme-qualified, protocol-relative and pure-fragment targets are out of
			// scope: this gate never touches the network.
			if ( preg_match( '~^([a-z][a-z0-9+.-]*:|//|#)~i', $target ) ) {
				continue;
			}

			$path_only = (string) preg_replace( '/#.*$/', '', $target );
			if ( '' === $path_only ) {
				continue;
			}

			// A leading slash resolves against the *site* root on GitHub, not the
			// repository root, so it is broken wherever the document is actually read.
			assert_true(
				'/' !== $path_only[0],
				sprintf( '%s: link target "%s" is repository-relative, not root-absolute', $relative, $target )
			);

			++$checked;

			$resolved = diviops_link_resolve( ( '' === $dir ? '' : $dir . '/' ) . $path_only );

			assert_true(
				false === $resolved['escapes'],
				sprintf(
					'%s: link target "%s" stays inside the repository root (this is the upstream-sync depth signature)',
					$relative,
					$target
				)
			);

			if ( $resolved['escapes'] ) {
				// Nothing useful left to check on disk; the escape is the finding.
				continue;
			}

			assert_true(
				file_exists( $root . '/' . $resolved['path'] ),
				sprintf( '%s: link target "%s" resolves to %s on disk', $relative, $target, $resolved['path'] )
			);
		}
	}

	// Files can legitimately hold no relative links, but the whole repository holding
	// none means the extractor stopped matching. That is a blind gate, not a pass.
	assert_true( $checked > 0, 'the gate found relative links to check across the tracked Markdown corpus' );

	printf(
		'relative-link-resolution: checked %d relative link(s) across %d tracked Markdown file(s)%s',
		$checked,
		count( $files ),
		PHP_EOL
	);
}

diviops_link_gate_run();
