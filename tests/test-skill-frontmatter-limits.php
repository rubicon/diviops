<?php
// SPDX-License-Identifier: MIT
/**
 * Every `skills/*\/SKILL.md` must satisfy claude.ai's upload limits (#601).
 *
 * #599 documents zip-and-upload through claude.ai Settings > Features as the
 * Claude Desktop route for `divi-5-builder`, which turns these limits from
 * someone else's trivia into a contract this repository has to keep. claude.ai
 * refuses an upload whose frontmatter breaks one, and the failure lands on a
 * user following our own instructions, after the change has shipped -- not on a
 * red test here.
 *
 * Measured when this gate was written: `divi-5-builder`'s description was 973
 * characters against a 1024 limit. Fifty-one characters of headroom, on the one
 * field that gets edited whenever the skill's trigger wording is tuned, because
 * `description` is what Claude matches a request against.
 *
 * The limits are transcribed once below with their source beside them, so a
 * later reader can tell a cited constraint from an invented one.
 *
 * Non-vacuity is asserted rather than assumed. A gate that reports what it
 * inspected but derives pass/fail only from problems-found passes while
 * inspecting nothing, which happened three times on the predecessor repository.
 * The fixture half proves every verdict is reachable and the live half fails if
 * it discovered no skills at all.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * claude.ai's documented frontmatter limits.
 *
 * Source: https://platform.claude.com/docs/en/agents-and-tools/agent-skills/overview
 * Read 2026-10-13. These are a third party's numbers; re-read that page before
 * changing one here.
 *
 * @return array<string,int|array<int,string>>
 */
function diviops_skf_limits(): array {
	return array(
		'name_max'        => 64,
		'description_max' => 1024,
		'name_pattern'    => '/^[a-z0-9-]+$/',
		'reserved'        => array( 'anthropic', 'claude' ),
	);
}

/**
 * Top-level scalar keys of a SKILL.md YAML frontmatter block.
 *
 * Deliberately shallow: only the fenced block, only `key: value` at column zero,
 * which is the shape both shipped skills use. A nested key such as `metadata:`
 * is skipped rather than guessed at, so this never invents a value it did not
 * read.
 *
 * @param string $text File contents.
 * @return array<string,string>
 */
function diviops_skf_frontmatter( string $text ): array {
	$lines = explode( "\n", $text );
	if ( ! isset( $lines[0] ) || '---' !== rtrim( $lines[0] ) ) {
		return array();
	}
	$out = array();
	$count = count( $lines );
	for ( $i = 1; $i < $count; $i++ ) {
		$line = rtrim( $lines[ $i ] );
		if ( '---' === $line ) {
			break;
		}
		if ( 1 === preg_match( '/^([a-z][a-z0-9_-]*): ?(.*)$/i', $line, $m ) ) {
			$out[ $m[1] ] = $m[2];
		}
	}

	return $out;
}

/**
 * Which documented limits a frontmatter block breaks.
 *
 * @param array<string,string> $fm Parsed frontmatter.
 * @return array<int,string> Violation labels, empty when compliant.
 */
function diviops_skf_violations( array $fm ): array {
	$limits = diviops_skf_limits();
	$bad    = array();
	$name   = (string) ( $fm['name'] ?? '' );
	$desc   = (string) ( $fm['description'] ?? '' );

	if ( '' === $name ) {
		$bad[] = 'name missing or empty';
	} else {
		if ( strlen( $name ) > $limits['name_max'] ) {
			$bad[] = sprintf( 'name is %d chars, over the %d limit', strlen( $name ), $limits['name_max'] );
		}
		if ( 1 !== preg_match( $limits['name_pattern'], $name ) ) {
			$bad[] = 'name must be lowercase letters, digits and hyphens only';
		}
		foreach ( $limits['reserved'] as $word ) {
			if ( false !== stripos( $name, $word ) ) {
				$bad[] = sprintf( 'name contains the reserved word "%s"', $word );
			}
		}
	}

	if ( '' === $desc ) {
		$bad[] = 'description missing or empty';
	} elseif ( strlen( $desc ) > $limits['description_max'] ) {
		$bad[] = sprintf( 'description is %d chars, over the %d limit', strlen( $desc ), $limits['description_max'] );
	}

	if ( false !== strpos( $name . $desc, '<' ) ) {
		$bad[] = 'name/description must not contain XML tags';
	}

	return $bad;
}

/* -------------------------------------------------------------------------
 * 1. Fixture half. Every verdict has to be reachable, or the live half below
 *    could be passing because the checker cannot fail.
 * ---------------------------------------------------------------------- */

$skf_ok = array( 'name' => 'divi-5-builder', 'description' => 'Does a thing, and says when to use it.' );
assert_same( array(), diviops_skf_violations( $skf_ok ), 'a compliant frontmatter reports no violations' );

assert_same(
	array( 'name missing or empty' ),
	diviops_skf_violations( array( 'description' => 'x' ) ),
	'a missing name is reported'
);
assert_same(
	array( 'description missing or empty' ),
	diviops_skf_violations( array( 'name' => 'ok-name' ) ),
	'a missing description is reported'
);
assert_same(
	1,
	count( diviops_skf_violations( array( 'name' => str_repeat( 'a', 65 ), 'description' => 'x' ) ) ),
	'a 65-character name is over the limit'
);
assert_same(
	array(),
	diviops_skf_violations( array( 'name' => str_repeat( 'a', 64 ), 'description' => 'x' ) ),
	'a 64-character name is exactly at the limit and allowed'
);
assert_same(
	1,
	count( diviops_skf_violations( array( 'name' => 'ok-name', 'description' => str_repeat( 'd', 1025 ) ) ) ),
	'a 1025-character description is over the limit'
);
assert_same(
	array(),
	diviops_skf_violations( array( 'name' => 'ok-name', 'description' => str_repeat( 'd', 1024 ) ) ),
	'a 1024-character description is exactly at the limit and allowed'
);
assert_same(
	1,
	count( diviops_skf_violations( array( 'name' => 'Divi_Builder', 'description' => 'x' ) ) ),
	'an uppercase/underscore name breaks the charset rule'
);
assert_true(
	in_array( 'name contains the reserved word "claude"', diviops_skf_violations( array( 'name' => 'claude-helper', 'description' => 'x' ) ), true ),
	'a reserved word in the name is reported by name'
);
assert_true(
	in_array( 'name contains the reserved word "anthropic"', diviops_skf_violations( array( 'name' => 'anthropic-tools', 'description' => 'x' ) ), true ),
	'both reserved words are checked, not just the first'
);
assert_true(
	in_array( 'name/description must not contain XML tags', diviops_skf_violations( array( 'name' => 'ok-name', 'description' => 'has <b>markup</b>' ) ), true ),
	'an XML tag is reported'
);

// The parser reads only the fenced block, and only flat keys.
$skf_parsed = diviops_skf_frontmatter( "---\nname: a-skill\ndescription: Some text.\nmetadata:\n  author: X\n---\n\n# Body\nname: not-frontmatter\n" );
assert_same( 'a-skill', $skf_parsed['name'] ?? '', 'the parser reads name from the fenced block' );
assert_same( 'Some text.', $skf_parsed['description'] ?? '', 'the parser reads description from the fenced block' );
assert_true( ! array_key_exists( 'author', $skf_parsed ), 'an indented key under metadata is not promoted to a top-level value' );
assert_same( array(), diviops_skf_frontmatter( "# No frontmatter\nname: nope\n" ), 'a file with no fenced block yields nothing rather than a guess' );

/* -------------------------------------------------------------------------
 * 2. The live half, against this repository's shipped skills.
 * ---------------------------------------------------------------------- */

$skf_root  = dirname( __DIR__ );
$skf_files = glob( $skf_root . '/skills/*/SKILL.md' );
$skf_files = is_array( $skf_files ) ? $skf_files : array();

assert_true(
	count( $skf_files ) > 0,
	'the live half found at least one skills/*/SKILL.md — a run that inspected nothing is not a pass'
);

$skf_bad    = array();
$skf_report = array();
foreach ( $skf_files as $skf_file ) {
	$skf_fm   = diviops_skf_frontmatter( (string) file_get_contents( $skf_file ) );
	$skf_name = basename( dirname( $skf_file ) );
	foreach ( diviops_skf_violations( $skf_fm ) as $skf_v ) {
		$skf_bad[] = $skf_name . ': ' . $skf_v;
	}
	$skf_report[] = sprintf(
		'%s name=%d/%d description=%d/%d',
		$skf_name,
		strlen( (string) ( $skf_fm['name'] ?? '' ) ),
		diviops_skf_limits()['name_max'],
		strlen( (string) ( $skf_fm['description'] ?? '' ) ),
		diviops_skf_limits()['description_max']
	);
}

printf( "skill frontmatter: %s\n", implode( '; ', $skf_report ) );

assert_same(
	array(),
	$skf_bad,
	'every shipped skill satisfies claude.ai\'s frontmatter limits: ' . implode( '; ', $skf_bad )
);
