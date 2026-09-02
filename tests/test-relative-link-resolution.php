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
 * defect class is entirely local relative paths. An absolute URL into this repository's
 * own tree is not external, though (#348): a `.../blob/main/SETUP.md#wp-cli-security`
 * target names a file in this checkout and dangles exactly the same way when that
 * heading is renamed, so it is resolved here rather than skipped. Without that,
 * rewriting a link to absolute form is a way to opt out of the fragment checking added
 * in #336 -- which is precisely what #346 had to do to publish working npm links.
 *
 * Two roots, not one. A README that npm publishes is read from inside the tarball,
 * whose root is the package directory rather than the repository, so a link can resolve
 * here and 404 there. `diviops-server/README.md` linked `../SETUP.md` nine times: valid
 * in this tree, dead on npmjs.com for all nine, because `..` leaves the package and
 * `SETUP.md` is not in the tarball (#348, found by hand while fixing #344). Every
 * relative link in such a README is therefore resolved a second time, against its own
 * package root, with the same lexical resolver.
 *
 * Non-vacuity, per CLAUDE.md: a gate that reports what it inspected but derives
 * pass/fail only from problems-found will pass while inspecting nothing, and that
 * failure happened three times on the predecessor repository. Five guards here --
 * a non-empty file corpus, a non-zero count for each of the three link classes
 * (repo-relative, absolute in-repo, packaged-README), and synthetic cases that exercise
 * the escape branch even when every real link is clean -- plus printed counts so a
 * silently shrinking corpus is visible in the CI log.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * List every file git tracks under a pathspec, repository-relative.
 *
 * Asks git rather than walking the filesystem so the corpus is exactly what a push
 * would publish: no `node_modules/`, no build output, and -- importantly -- nothing
 * inside `upstream-releases/`, whose Pro artifacts must never be opened by any tool.
 *
 * @param string $root     Repository root.
 * @param string $pathspec Git pathspec, e.g. `*.md`.
 * @return array<int, string> Tracked paths, repository-relative.
 */
function diviops_link_tracked_files( string $root, string $pathspec ): array {
	$command = sprintf( 'git -C %s ls-files -z -- %s', escapeshellarg( $root ), escapeshellarg( $pathspec ) );
	$output  = shell_exec( $command );
	if ( ! is_string( $output ) || '' === $output ) {
		return array();
	}
	$paths = array_values( array_filter( explode( "\0", $output ), 'strlen' ) );
	sort( $paths );
	return $paths;
}

/**
 * Every README an npm package in this repository actually publishes.
 *
 * Keyed on a literal `README.md` entry in the manifest's `files` array, which is the
 * declaration this gate is checking against: a README the package ships is read by
 * someone who has only the tarball, and the tarball's root is the package directory.
 *
 * @param string $root Repository root.
 * @return array<int, array{manifest: string, root: string, readme: string}> One entry
 *         per publishing package: its manifest, its package root, and its README, all
 *         repository-relative. The package root is the empty string at the repository
 *         root itself.
 */
function diviops_link_packaged_readmes( string $root ): array {
	$packages = array();
	foreach ( diviops_link_tracked_files( $root, '*package.json' ) as $manifest ) {
		// The pathspec above is a suffix match, so it also matches `my-package.json`.
		if ( 'package.json' !== basename( $manifest ) ) {
			continue;
		}
		$decoded = json_decode( (string) file_get_contents( $root . '/' . $manifest ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['files'] ) || ! is_array( $decoded['files'] ) ) {
			continue;
		}
		if ( ! in_array( 'README.md', $decoded['files'], true ) ) {
			continue;
		}
		$dir        = dirname( $manifest );
		$dir        = '.' === $dir ? '' : $dir;
		$packages[] = array(
			'manifest' => $manifest,
			'root'     => $dir,
			'readme'   => '' === $dir ? 'README.md' : $dir . '/README.md',
		);
	}
	return $packages;
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
 * Remove fenced code blocks from Markdown source, leaving inline code spans alone.
 *
 * Split out from diviops_link_strip_code() for the heading scanner (#336), which needs
 * the fence half and must not have the span half: a code span inside a heading IS the
 * heading text, so `## ``divi/contact-field`` ` anchors at `#divicontact-field`, and
 * stripping the span would slug it to the empty string and silently drop the heading.
 *
 * @param string $content Markdown source.
 * @return string Source with fenced code blocks removed.
 */
function diviops_link_strip_fences( string $content ): string {
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
	return implode( "\n", $kept );
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
	return (string) preg_replace( '/(`+)[^\n]*?\1/', '', diviops_link_strip_fences( $content ) );
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
 * Slugify one heading's text the way GitHub anchors it.
 *
 * The rule is html-pipeline's TableOfContentsFilter, which is what renders every
 * heading anchor a reader of this repository will ever click:
 *
 *     text.downcase.gsub(/[^\p{Word}\- ]/u, '').tr(' ', '-')
 *
 * Ruby's `\p{Word}` is letters, marks, decimal digits and connector punctuation, so
 * `_` survives and every other punctuation mark is **deleted in place** rather than
 * replaced by a separator. That deletion is the whole trap, and it is why a naive
 * slugger reports false positives on this corpus:
 *
 *   - `presets -- broad scope` (em-dash) loses the dash but keeps BOTH spaces around
 *     it, so the anchor carries a double hyphen. Four links in `skills/` wrote a
 *     single one and dangled (#336), while `variable-bindings.md`'s namespace anchors
 *     and `presets.md`'s own table of contents had the doubled form right all along.
 *   - `presets (broad scope)` loses the parenthesis with no space beside it, so that
 *     shape yields a single hyphen. Same words, different anchor.
 *
 * GitHub slugs the *rendered* text, so markup producing no text of its own is removed
 * first: HTML comments, HTML tags, and the URL half of an inline link. Emphasis
 * markers and backticks need no special handling because the strip below deletes
 * them -- and it must not delete what they wrap.
 *
 * Not trimmed, deliberately. A trailing `<!-- UNVERIFIED -->` leaves a real trailing
 * space in the rendered heading, which GitHub turns into a trailing hyphen; trimming
 * would invent an anchor GitHub does not serve and pass a link that 404s.
 *
 * @param string $heading Heading text, with the leading `#` run already removed.
 * @return string GitHub anchor slug, without the leading `#`.
 */
function diviops_link_slug( string $heading ): string {
	$text = (string) preg_replace( '/<!--.*?-->/s', '', $heading );
	$text = (string) preg_replace( '/!?\[([^\]]*)\]\([^)]*\)/', '$1', $text );
	$text = (string) preg_replace( '/<[^>]+>/', '', $text );
	$text = mb_strtolower( $text, 'UTF-8' );
	$text = (string) preg_replace( '/[^\p{L}\p{Nl}\p{M}\p{Nd}\p{Pc}\- ]+/u', '', $text );
	return str_replace( ' ', '-', $text );
}

/**
 * Collect every heading anchor a Markdown document publishes, in document order.
 *
 * ATX headings only (`## Title`). The corpus has no setext headings -- the only
 * `---` underlines in it close YAML front matter -- and inventing support for a form
 * nobody writes would be untested code guarding nothing.
 *
 * Repeated headings are disambiguated the way GitHub does it, by appending `-1`,
 * `-2`, and so on to the second and later occurrences.
 *
 * @param string $content Markdown source.
 * @return array<int, string> Anchor slugs, without the leading `#`.
 */
function diviops_link_heading_anchors( string $content ): array {
	$seen    = array();
	$anchors = array();
	foreach ( explode( "\n", diviops_link_strip_fences( $content ) ) as $line ) {
		if ( ! preg_match( '/^ {0,3}#{1,6}\s+(.*?)\s*#*\s*$/', $line, $heading ) ) {
			continue;
		}
		$slug = diviops_link_slug( $heading[1] );
		if ( '' === $slug ) {
			continue;
		}
		$count         = isset( $seen[ $slug ] ) ? $seen[ $slug ] : 0;
		$seen[ $slug ] = $count + 1;
		$anchors[]     = 0 === $count ? $slug : $slug . '-' . $count;
	}
	return $anchors;
}

/**
 * Anchors published by one document, parsed once per run.
 *
 * `SKILL.md` and its reference files link into each other repeatedly; without the
 * cache the larger references get re-scanned on every inbound link.
 *
 * @param string $root     Repository root.
 * @param string $relative Repository-relative path to a Markdown file.
 * @return array<int, string> Anchor slugs.
 */
function diviops_link_anchors_for( string $root, string $relative ): array {
	static $cache = array();
	if ( ! isset( $cache[ $relative ] ) ) {
		$cache[ $relative ] = diviops_link_heading_anchors( (string) file_get_contents( $root . '/' . $relative ) );
	}
	return $cache[ $relative ];
}

/**
 * Nearest anchor to a fragment, by edit distance.
 *
 * Only ever called on a failure, to make the message actionable. Every dangling
 * anchor found when this check was written was one or two characters off a real
 * heading, so naming the nearest one turns the failure into the fix.
 *
 * @param string             $fragment Fragment that did not match.
 * @param array<int, string> $anchors  Anchors the target document publishes.
 * @return string Closest anchor, or the empty string when the document has none.
 */
function diviops_link_nearest_anchor( string $fragment, array $anchors ): string {
	$nearest  = '';
	$shortest = PHP_INT_MAX;
	foreach ( $anchors as $anchor ) {
		// levenshtein() returns -1 for arguments over 255 bytes before PHP 8.0.
		if ( strlen( $fragment ) > 255 || strlen( $anchor ) > 255 ) {
			continue;
		}
		$distance = levenshtein( $fragment, $anchor );
		if ( $distance < $shortest ) {
			$shortest = $distance;
			$nearest  = $anchor;
		}
	}
	return $nearest;
}

/**
 * Assert that a link's `#fragment` names a heading the target document publishes.
 *
 * Reported through assert_same() rather than assert_true() so the runner prints the
 * fragment against the nearest real anchor. `expected` vs `actual` on two strings
 * one hyphen apart is the entire diagnosis for the em-dash class above; a bare
 * `true` vs `false` would leave the reader to slug the headings by hand.
 *
 * @param string $root        Repository root.
 * @param string $source      Document the link was written in.
 * @param string $target_path Document the link resolves to.
 * @param string $target      Raw link target, for the message.
 * @param string $fragment    Fragment, without the leading `#`.
 */
function diviops_link_assert_fragment( string $root, string $source, string $target_path, string $target, string $fragment ): void {
	$anchors = diviops_link_anchors_for( $root, $target_path );
	$found   = in_array( $fragment, $anchors, true );
	assert_same(
		$fragment,
		$found ? $fragment : diviops_link_nearest_anchor( $fragment, $anchors ),
		sprintf(
			'%s: link target "%s" names a heading that exists in %s (actual = nearest real anchor)',
			$source,
			$target,
			$target_path
		)
	);
}

/**
 * Prefix of an absolute URL that addresses a file in this repository's working tree.
 *
 * Pinned to `blob/main/` on purpose. A blob URL carrying a commit SHA or a tag names a
 * frozen snapshot, and resolving that against the current checkout would report a
 * heading rename as a broken link when the pinned link is doing exactly its job.
 */
const DIVIOPS_LINK_BLOB_PREFIX = 'https://github.com/rubicon/diviops/blob/main/';

/**
 * Assert that a resolved link target exists, and that any fragment names a heading there.
 *
 * Shared by the two paths that reach a real file: a repo-relative target resolved
 * against its own document's directory, and an absolute in-repo target resolved against
 * the repository root. The checks are identical once the path is resolved, and keeping
 * one copy is what stops a later correction from landing on only one of them.
 *
 * @param string $root          Repository root.
 * @param string $source        Document the link was written in.
 * @param string $target        Raw link target, for the message.
 * @param string $resolved_path Repository-relative path the target resolved to.
 * @param string $fragment      Fragment, without the leading `#`, or the empty string.
 * @return int 1 when a fragment was resolved against headings, 0 otherwise.
 */
function diviops_link_assert_target( string $root, string $source, string $target, string $resolved_path, string $fragment ): int {
	$exists = file_exists( $root . '/' . $resolved_path );

	assert_true(
		$exists,
		sprintf( '%s: link target "%s" resolves to %s on disk', $source, $target, $resolved_path )
	);

	// A heading anchor is a property of a rendered Markdown document, so a fragment
	// on anything else -- an image, a PHP file -- is left alone rather than reported
	// against headings that do not exist there.
	if ( ! $exists || '' === $fragment || 1 !== preg_match( '/\.md$/i', $resolved_path ) ) {
		return 0;
	}

	diviops_link_assert_fragment( $root, $source, $resolved_path, $target, $fragment );
	return 1;
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
		// The package-boundary shape: a README at a package root linking one level up.
		// Harmless against the repository root, fatal against the tarball root.
		array( '../SETUP.md', 'SETUP.md', true ),
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

	/*
	 * Same reasoning for the fragment half. Every one of these is a shape that exists
	 * in this repository, and the first three are the exact distinctions a naive
	 * slugger gets wrong -- so a corpus that goes clean still proves the slug rule
	 * itself is the one GitHub applies.
	 */
	$slug_cases = array(
		// [ heading text, expected anchor ].
		// The em-dash: deleted in place, both spaces kept, so the anchor doubles the
		// hyphen. This is the shape four links in skills/ got wrong (#336).
		array( 'Hover-padding gate on Button group presets — broad scope, upstream-tracked', 'hover-padding-gate-on-button-group-presets--broad-scope-upstream-tracked' ),
		// A parenthesis has no space beside it, so the same words yield ONE hyphen.
		array( 'Hover-padding gate on Button group presets (broad scope)', 'hover-padding-gate-on-button-group-presets-broad-scope' ),
		// Code spans are heading text; stripping them would slug this to nothing.
		array( '`divi/contact-field`', 'divicontact-field' ),
		array( 'Namespace 2 — Global colors (`type:"color"`, `gcid-`)', 'namespace-2--global-colors-typecolor-gcid-' ),
		array( 'Shared family inventory (47 maps)', 'shared-family-inventory-47-maps' ),
		// `$` and parentheses go; `_` and `-` are word characters and stay.
		array( 'Divi 5 `$variable()` Bindings', 'divi-5-variable-bindings' ),
		array( 'Keep_the_underscores and-the-hyphens', 'keep_the_underscores-and-the-hyphens' ),
		// A link renders as its text, not its URL.
		array( 'A [linked](https://example.test/) word', 'a-linked-word' ),
	);
	foreach ( $slug_cases as $case ) {
		assert_same( $case[1], diviops_link_slug( $case[0] ), sprintf( 'heading "%s" anchors at "#%s"', $case[0], $case[1] ) );
	}

	assert_same(
		array( 'title', 'section', 'section-1', 'divicontact-field' ),
		diviops_link_heading_anchors(
			"# Title\n"
			. "## Section\n"
			. "```\n### Section in a fence\n```\n"
			. "### Section\n"
			. "#### `divi/contact-field`\n"
			. "Not a heading: #hashtag\n"
			. "#no-space-after-hash\n"
		),
		'heading anchors are collected at every level, deduplicated GitHub-style, and never read out of a code fence'
	);

	$files = diviops_link_tracked_files( $root, '*.md' );

	// A corpus that silently went empty -- a bad pathspec, git missing from PATH --
	// must fail here rather than reporting a clean run over nothing.
	assert_true(
		count( $files ) > 0,
		'git reported at least one tracked Markdown file for this gate to inspect'
	);

	$checked   = 0;
	$fragments = 0;
	$absolute  = 0;
	foreach ( $files as $relative ) {
		$content = (string) file_get_contents( $root . '/' . $relative );
		$dir     = dirname( $relative );
		$dir     = '.' === $dir ? '' : $dir;

		foreach ( diviops_link_targets( $content ) as $target ) {
			// An absolute URL into this repository's own tree is a repo-relative link
			// wearing a hostname: it names a file in this checkout, and its fragment
			// dangles the same way when a heading is renamed. Resolving it here is what
			// stops "rewrite the link as absolute" from being a way to opt out of the
			// fragment checking added in #336. Resolution is against the repository
			// root, not the linking document's directory.
			if ( 0 === strpos( $target, DIVIOPS_LINK_BLOB_PREFIX ) ) {
				$in_repo   = substr( $target, strlen( DIVIOPS_LINK_BLOB_PREFIX ) );
				$hash      = strpos( $in_repo, '#' );
				$path_only = false === $hash ? $in_repo : substr( $in_repo, 0, $hash );
				$fragment  = false === $hash ? '' : substr( $in_repo, $hash + 1 );

				if ( '' !== $path_only ) {
					++$absolute;
					$fragments += diviops_link_assert_target(
						$root,
						$relative,
						$target,
						diviops_link_resolve( $path_only )['path'],
						$fragment
					);
				}
				continue;
			}

			// Every other scheme-qualified or protocol-relative target is out of scope:
			// this gate never touches the network.
			if ( preg_match( '~^([a-z][a-z0-9+.-]*:|//)~i', $target ) ) {
				continue;
			}

			$hash      = strpos( $target, '#' );
			$path_only = false === $hash ? $target : substr( $target, 0, $hash );
			$fragment  = false === $hash ? '' : substr( $target, $hash + 1 );

			// A bare `#fragment` addresses a heading in the document it is written in.
			// Skipped outright before #336, along with the fragment half of every
			// cross-file link -- which is how six dangling anchors were free to be
			// authored under a gate that reported a clean pass.
			if ( '' === $path_only ) {
				if ( '' !== $fragment ) {
					diviops_link_assert_fragment( $root, $relative, $relative, $target, $fragment );
					++$fragments;
				}
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

			$fragments += diviops_link_assert_target( $root, $relative, $target, $resolved['path'], $fragment );
		}
	}

	/*
	 * Second root. Everything above resolved against the repository, which is the tree
	 * a contributor reads. A README that npm publishes is also read from inside the
	 * tarball, whose root is the package directory -- so `../SETUP.md` written in
	 * `diviops-server/README.md` resolves here and 404s there, which is how nine dead
	 * links shipped to npmjs.com under a green suite (#348).
	 */
	$packages       = diviops_link_packaged_readmes( $root );
	$packaged_links = 0;

	foreach ( $packages as $package ) {
		assert_true(
			is_file( $root . '/' . $package['readme'] ),
			sprintf( '%s lists README.md in "files", so %s exists to be published', $package['manifest'], $package['readme'] )
		);

		$package_label = '' === $package['root'] ? 'the repository root' : $package['root'] . '/';

		foreach ( diviops_link_targets( (string) file_get_contents( $root . '/' . $package['readme'] ) ) as $target ) {
			// An absolute URL is the fix for this defect class, not an instance of it:
			// it resolves identically in the repository and in the tarball. It is still
			// checked, by the loop above, which reads the same file as tracked Markdown.
			if ( preg_match( '~^([a-z][a-z0-9+.-]*:|//)~i', $target ) ) {
				continue;
			}

			$hash      = strpos( $target, '#' );
			$path_only = false === $hash ? $target : substr( $target, 0, $hash );

			// A bare `#fragment` addresses the README itself, so it travels with it.
			if ( '' === $path_only ) {
				continue;
			}

			++$packaged_links;

			// The README sits at its package root, so the raw target is already the
			// path to resolve against that root -- the same lexical walk the repository
			// check uses, given a different starting point.
			assert_true(
				false === diviops_link_resolve( $path_only )['escapes'],
				sprintf(
					'%s: link target "%s" stays inside the published package root (%s), which is where npm roots the tarball -- a target above it is dead on npmjs.com however well it resolves in this checkout',
					$package['readme'],
					$target,
					$package_label
				)
			);
		}
	}

	// Files can legitimately hold no relative links, but the whole repository holding
	// none means the extractor stopped matching. That is a blind gate, not a pass.
	assert_true( $checked > 0, 'the gate found relative links to check across the tracked Markdown corpus' );

	// Same guard for each narrower rule. Both scope the corpus down -- one to absolute
	// URLs into this tree, one to the READMEs npm publishes -- and a rule whose corpus
	// silently went empty passes every run without inspecting anything.
	assert_true( $absolute > 0, 'the gate found absolute in-repo links to resolve against the working tree' );
	assert_true(
		count( $packages ) > 0,
		'at least one package.json ships a README.md, so the package-boundary rule inspected a real package'
	);

	// Same guard one level down. The fragment half is checked by a second, narrower
	// path -- Markdown targets only -- and a change that made that path unreachable
	// would leave the link count healthy while every anchor went uninspected.
	assert_true( $fragments > 0, 'the gate found link fragments to resolve against target headings' );

	printf(
		'relative-link-resolution: checked %d relative link(s), %d absolute in-repo link(s) and %d anchor fragment(s) across %d tracked Markdown file(s), plus %d link(s) against %d packaged README root(s)%s',
		$checked,
		$absolute,
		$fragments,
		count( $files ),
		$packaged_links,
		count( $packages ),
		PHP_EOL
	);
}

diviops_link_gate_run();
