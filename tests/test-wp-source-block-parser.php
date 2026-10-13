<?php
// SPDX-License-Identifier: MIT
/**
 * Drive a block-parsing write handler end to end against WordPress core's own
 * block parser (#598).
 *
 * Until this suite, nothing in this repository could. `parse_blocks()` is
 * deliberately absent from `tests/wp-shim.php` — five suites use
 * `Call to undefined function parse_blocks()` as a positive probe signal that
 * execution reached a given point — so every handler that parses markup before
 * it writes was reachable only up to its parse call. The cost was concrete, and
 * stated in the suites themselves: `test-page-block-insert.php` says
 * `page_block_insert` "cannot be driven end to end here", so #592's
 * retry-idempotency defect had to be proven through an extracted helper rather
 * than through the tool; `test-parse-blocks-for-write-coverage.php` asserts its
 * invariant structurally from source for the same reason; and #595's free-text
 * slashing left twelve write sites with no round-trip assertion because each one
 * parses before it writes. A defect living PAST the parse call was invisible.
 *
 * What makes this safe to add is that nothing is approximated and nothing shared
 * is widened. The parser is core's own file, loaded from disk
 * (`tests/wp-block-parser-shim.php`); a hand-rolled one was explicitly ruled out
 * because this repository has been bitten six times (#7, #10, #18, #548, #555,
 * #578) by scanners that approximated WordPress's block grammar, and a shim
 * parser kinder or stricter than core would make assertions that pass against a
 * parser no site runs. Both stubs are opt-in per file, and `tests/run.php` gives
 * every test file its own PHP process, so no suite that does not require them can
 * see `parse_blocks()` however `DIVIOPS_WP_SOURCE` is set.
 *
 * Two halves, deliberately:
 *
 *   - The resolver half runs everywhere, including CI with no WordPress, and
 *     reaches every verdict `diviops_wpbp_resolve()` can return. Without it the
 *     path logic would be exercised only on a machine that happens to have a
 *     WordPress tree.
 *   - The parser half needs `DIVIOPS_WP_SOURCE` and SKIPs with a counted reason
 *     when it is unset, following `test-shared-preset-attrs-map.php`'s
 *     `DIVIOPS_DIVI_BUILDER5_PATH` precedent. A guard that merely did nothing
 *     would make `PASS N assertion(s)` read identically whether these ran or not.
 *
 * NOT covered here: the behavioural difference between `parse_blocks_for_write()`
 * and bare `parse_blocks()`. That needs Divi's own parser registered through the
 * `block_parser_class` filter, which needs a Divi install, not a WordPress one —
 * `test-parse-blocks-for-write-coverage.php` keeps that invariant structural.
 *
 * Measured on WordPress 7.1 (local tree) and 7.1.3 (staging), both of which
 * carry `wp-includes/class-wp-block-parser.php`.
 *
 * @package DiviOps
 */

// Order matters, and is the inverse of wp-date-hierarchy-shim.php's: the
// registry stub must define its class BEFORE wp-shim.php guards its own with
// class_exists(), and wp-block-parser-shim.php requires wp-shim.php at its top.
require_once __DIR__ . '/wp-block-registry-rest-shim.php';
require_once __DIR__ . '/wp-block-parser-shim.php';

/**
 * Normalize a function body for comparison: strip comments, collapse runs of
 * whitespace to one space, trim.
 *
 * @param string $body Raw body text.
 * @return string
 */
function diviops_wpsbp_normalize( string $body ): string {
	$body = preg_replace( '#/\*.*?\*/#s', ' ', $body );
	$body = preg_replace( '#//[^\n]*#', ' ', (string) $body );
	return trim( (string) preg_replace( '/\s+/', ' ', (string) $body ) );
}

/**
 * Extract one function's body from PHP source by walking braces from its
 * signature to the matching close.
 *
 * A brace walk rather than a regex: #97 and #555 both ended with the finding
 * that a bounded regex characterizing nested text will eventually be fooled, and
 * the remedy adopted in `trait-core.php` was an explicit character walk. This
 * walk does not track string literals, which is sound for the two bodies it is
 * pointed at and nothing else — the caller asserts the extraction is non-empty
 * and carries a distinctive token before comparing anything, so a walk that ran
 * off the end fails loudly instead of comparing two empty strings.
 *
 * Candidate matches are filtered to real declarations by checking what precedes
 * them on their own line. The first version did not, and matched the prose
 * "Call to undefined function parse_blocks()" in a docblock eight lines into the
 * stub — then walked to the next `{` and returned an unrelated function's body,
 * which the comparison reported as a transcription mismatch. Same family as the
 * self-finding needle the sentinel halves below are concatenated to avoid.
 *
 * @param string $src  PHP source.
 * @param string $name Function name.
 * @return string Normalized body, or '' when the function is not declared.
 */
function diviops_wpsbp_function_body( string $src, string $name ): string {
	if ( ! preg_match_all( '/function\s+' . preg_quote( $name, '/' ) . '\s*\(/', $src, $all, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}
	$at = null;
	foreach ( $all[0] as $candidate ) {
		$offset = (int) $candidate[1];
		$line   = strrpos( substr( $src, 0, $offset ), "\n" );
		$prefix = trim( substr( $src, false === $line ? 0 : $line + 1, $offset - ( false === $line ? 0 : $line + 1 ) ) );
		// Anything left on the line before `function` must be a declaration
		// modifier. A docblock line leaves a `*`, and a line comment a `//`.
		if ( '' === preg_replace( '/\b(?:public|private|protected|static|final|abstract)\b|\s+/', '', $prefix ) ) {
			$at = $offset;
			break;
		}
	}
	if ( null === $at ) {
		return '';
	}
	$open = strpos( $src, '{', $at );
	if ( false === $open ) {
		return '';
	}
	$depth = 0;
	$len   = strlen( $src );
	for ( $i = $open; $i < $len; $i++ ) {
		if ( '{' === $src[ $i ] ) {
			++$depth;
		} elseif ( '}' === $src[ $i ] ) {
			--$depth;
			if ( 0 === $depth ) {
				return diviops_wpsbp_normalize( substr( $src, $open + 1, $i - $open - 1 ) );
			}
		}
	}
	return '';
}

/**
 * One Divi row's markup, with the adminLabel the selector resolves on.
 *
 * `builderVersion` is present because the validator requires it on every
 * non-parser-level `divi/*` block (trait-validate.php), exactly as a real saved
 * page carries it.
 *
 * @param string $label adminLabel value.
 * @return string
 */
function diviops_wpsbp_row( string $label ): string {
	return '<!-- wp:divi/row {"builderVersion":"5.0.0","module":{"meta":{"adminLabel":{"desktop":{"value":"'
		. $label . '"}}}}} --><!-- /wp:divi/row -->';
}

/**
 * Every adminLabel in a page's stored content, in document order.
 *
 * Read back out of the registry the handler wrote to, so the assertion is about
 * what was stored rather than about what the response claimed.
 *
 * @param int $post_id Post id.
 * @return string[]
 */
function diviops_wpsbp_stored_labels( int $post_id ): array {
	$post = get_post( $post_id );
	preg_match_all( '/"value":"([^"]+)"/', (string) ( $post->post_content ?? '' ), $m );
	return $m[1];
}

// ── the resolver: every verdict, on every machine ─────────────────────────
// Pure path composition first, which needs no filesystem at all.
assert_same( '', diviops_wpbp_parser_file( '' ), 'an empty root composes no path, so an unset env var cannot resolve to /wp-includes' );
assert_same( '', diviops_wpbp_parser_file( '   ' ), 'a whitespace-only root composes no path' );
assert_same(
	'/srv/wp/wp-includes/class-wp-block-parser.php',
	diviops_wpbp_parser_file( '/srv/wp' ),
	'a root composes the parser path under wp-includes'
);
assert_same(
	'/srv/wp/wp-includes/class-wp-block-parser.php',
	diviops_wpbp_parser_file( '/srv/wp/' ),
	'a trailing slash on the root does not double up in the composed path'
);

assert_same( '', diviops_wpbp_resolve( null ), 'a non-string candidate resolves to no root' );
assert_same( '', diviops_wpbp_resolve( array( '/srv/wp' ) ), 'an array candidate resolves to no root' );
assert_same( '', diviops_wpbp_resolve( '' ), 'an unset DIVIOPS_WP_SOURCE resolves to no root' );

$diviops_wpsbp_tmp = sys_get_temp_dir() . '/diviops-wpsbp-' . getmypid();
mkdir( $diviops_wpsbp_tmp . '/empty', 0700, true );
mkdir( $diviops_wpsbp_tmp . '/bare/wp-includes', 0700, true );
mkdir( $diviops_wpsbp_tmp . '/good/wp-includes', 0700, true );
file_put_contents( $diviops_wpsbp_tmp . '/good/wp-includes/class-wp-block-parser.php', "<?php\n" );

assert_same(
	'',
	diviops_wpbp_resolve( $diviops_wpsbp_tmp . '/nope' ),
	'a root that does not exist resolves to no root'
);
assert_same(
	'',
	diviops_wpbp_resolve( $diviops_wpsbp_tmp . '/empty' ),
	'a directory with no wp-includes resolves to no root'
);
assert_same(
	'',
	diviops_wpbp_resolve( $diviops_wpsbp_tmp . '/bare' ),
	'a wp-includes without the parser file resolves to no root — the shape of a WordPress too old to carry it'
);
assert_same(
	$diviops_wpsbp_tmp . '/good',
	diviops_wpbp_resolve( $diviops_wpsbp_tmp . '/good' ),
	'a root carrying the parser file resolves to itself'
);
assert_same(
	$diviops_wpsbp_tmp . '/good',
	diviops_wpbp_resolve( $diviops_wpsbp_tmp . '/good/' ),
	'a resolved root is reported without its trailing slash'
);

foreach ( array( '/good/wp-includes/class-wp-block-parser.php' ) as $diviops_wpsbp_f ) {
	unlink( $diviops_wpsbp_tmp . $diviops_wpsbp_f );
}
foreach ( array( '/good/wp-includes', '/good', '/bare/wp-includes', '/bare', '/empty' ) as $diviops_wpsbp_d ) {
	rmdir( $diviops_wpsbp_tmp . $diviops_wpsbp_d );
}
rmdir( $diviops_wpsbp_tmp );

// ── the registry stub is the one that loaded, and is strictly additive ────
assert_true(
	defined( 'WP_Block_Type_Registry::DIVIOPS_REST_SHAPED' ),
	'the REST-shaped registry loaded, not the shim\'s own — requiring it after wp-shim.php would silently do nothing'
);

$diviops_wpsbp_registry = WP_Block_Type_Registry::get_instance()->get_all_registered();
$diviops_wpsbp_shim_src = (string) file_get_contents( __DIR__ . '/wp-shim.php' );

/*
 * Read the shim's own registry keys out of its source rather than restating
 * them, so the carried-over half of the REST-shaped registry cannot drift from
 * the thing it is carrying over.
 *
 * The window is anchored on the `$blocks` declaration INSIDE the registry class,
 * found by taking the class declaration's offset first — `private $blocks` alone
 * would be a needle for any similarly named property in a 3,500-line file. The
 * control below is the offset of that declaration, not some other string: an
 * earlier version controlled on locating the class and then counted keys from a
 * separately located anchor, which proves the wrong thing (a control has to
 * match the category being counted).
 */
$diviops_wpsbp_class_at = strpos( $diviops_wpsbp_shim_src, 'class WP_Block_Type_Registry' );
$diviops_wpsbp_arr_at   = false === $diviops_wpsbp_class_at
	? false
	: strpos( $diviops_wpsbp_shim_src, 'private $blocks = array(', (int) $diviops_wpsbp_class_at );
assert_true(
	false !== $diviops_wpsbp_arr_at,
	'the shim\'s registry $blocks declaration was located — the positive control for the key scan below'
);
preg_match_all(
	"/'([a-z0-9-]+\/[a-z0-9-]+)'\s*=>/",
	substr( $diviops_wpsbp_shim_src, (int) $diviops_wpsbp_arr_at, 500 ),
	$diviops_wpsbp_shim_keys
);
/*
 * The count gate. Zero would let the loop below pass while comparing nothing —
 * the failure mode tests/run.php fails on for empty discovery. A non-zero count
 * that is simply wrong is caught too, because a window that truncated the array
 * yields fewer than 7. Expected to fail if the shim gains an eighth type: that
 * failure is the signal to decide whether the new type belongs here as well.
 */
assert_same(
	7,
	count( $diviops_wpsbp_shim_keys[1] ),
	'the shim\'s own registry declares 7 block types'
);
$diviops_wpsbp_missing = array();
foreach ( $diviops_wpsbp_shim_keys[1] as $diviops_wpsbp_key ) {
	if ( ! isset( $diviops_wpsbp_registry[ $diviops_wpsbp_key ] ) ) {
		$diviops_wpsbp_missing[] = $diviops_wpsbp_key;
	}
}
assert_same(
	array(),
	$diviops_wpsbp_missing,
	'the REST-shaped registry carries every type the shim\'s registry declares, so the override is strictly additive'
);
assert_true(
	null === WP_Block_Type_Registry::get_instance()->get_registered( 'divi/global-layout' )
		&& null === WP_Block_Type_Registry::get_instance()->get_registered( 'divi/root' ),
	'the parser-level types stay unregistered, as measured in both contexts, so the validator exemption remains exercisable'
);

// ── the parser half ───────────────────────────────────────────────────────
/*
 * How many assertions the guarded block contains, declared as a literal and
 * checked against the block's real contents in BOTH branches — the idiom
 * test-shared-preset-attrs-map.php established for its Divi-tree block, for the
 * reason given there: a guard that silently does nothing makes a skipped run and
 * a real run print the same line. The sentinel halves are concatenated so the
 * needle written here cannot find itself.
 */
$diviops_wpsbp_declared_skips = 17;

$diviops_wpsbp_self  = (string) file_get_contents( __FILE__ );
$diviops_wpsbp_begin = strpos( $diviops_wpsbp_self, 'WP-SOURCE-BLOCK' . '-BEGIN' );
$diviops_wpsbp_end   = strpos( $diviops_wpsbp_self, 'WP-SOURCE-BLOCK' . '-END' );
assert_true(
	false !== $diviops_wpsbp_begin && false !== $diviops_wpsbp_end && $diviops_wpsbp_end > $diviops_wpsbp_begin,
	'both block sentinels are present and ordered — the positive control for the count below'
);
assert_same(
	$diviops_wpsbp_declared_skips,
	preg_match_all(
		'/\bassert_(?:same|true)\s*\(/',
		substr( $diviops_wpsbp_self, (int) $diviops_wpsbp_begin, (int) $diviops_wpsbp_end - (int) $diviops_wpsbp_begin )
	),
	'the declared skip count matches the assertions actually inside the WordPress-source block'
);

// WP-SOURCE-BLOCK-BEGIN
if ( '' !== DIVIOPS_WPBP_ROOT ) {
	assert_true( function_exists( 'parse_blocks' ), 'parse_blocks() is defined once a WordPress source is resolved' );
	assert_true( class_exists( 'WP_Block_Parser' ), 'core\'s WP_Block_Parser class loaded from the resolved WordPress source' );
	assert_true(
		class_exists( 'WP_Block_Parser_Block' ) && class_exists( 'WP_Block_Parser_Frame' ),
		'core\'s parser pulled in its own two companion classes, so nothing here had to list them'
	);

	// The transcription gate. The two wrappers are the only code in the stub not
	// loaded from core, so they are compared against the core actually on disk
	// rather than trusted against a line number written in a comment (#598).
	$diviops_wpsbp_blocks_php = DIVIOPS_WPBP_ROOT . '/wp-includes/blocks.php';
	$diviops_wpsbp_core_src   = is_file( $diviops_wpsbp_blocks_php ) ? (string) file_get_contents( $diviops_wpsbp_blocks_php ) : '';
	$diviops_wpsbp_stub_src   = (string) file_get_contents( __DIR__ . '/wp-block-parser-shim.php' );
	$diviops_wpsbp_core_parse = diviops_wpsbp_function_body( $diviops_wpsbp_core_src, 'parse_blocks' );
	assert_true(
		false !== strpos( $diviops_wpsbp_core_parse, 'block_parser_class' ),
		'core\'s parse_blocks() body was extracted — the positive control for the comparison below'
	);
	assert_same(
		$diviops_wpsbp_core_parse,
		diviops_wpsbp_function_body( $diviops_wpsbp_stub_src, 'parse_blocks' ),
		'the stub\'s parse_blocks() is still character-for-character core\'s, comments and whitespace aside'
	);
	assert_same(
		diviops_wpsbp_function_body( $diviops_wpsbp_core_src, 'serialize_blocks' ),
		diviops_wpsbp_function_body( $diviops_wpsbp_stub_src, 'serialize_blocks' ),
		'the stub\'s serialize_blocks() is still core\'s'
	);

	// The grammar cases a hand-rolled parser gets wrong, asserted against the
	// real one. `-->` inside attribute JSON is #7/#10/#548's defect class, and a
	// self-closing opener is #18's.
	$diviops_wpsbp_tempered = parse_blocks(
		'<!-- wp:divi/text {"module":{"html":"a --> b"}} -->x<!-- /wp:divi/text -->'
	);
	assert_same( 1, count( $diviops_wpsbp_tempered ), 'attribute JSON containing --> parses as one block, not a truncated span' );
	assert_same( 'a --> b', $diviops_wpsbp_tempered[0]['attrs']['module']['html'], 'and the --> survives inside the decoded attrs' );

	$diviops_wpsbp_selfclose = parse_blocks( '<!-- wp:divi/placeholder /-->' );
	assert_same( 1, count( $diviops_wpsbp_selfclose ), 'a self-closing opener parses as exactly one block' );
	assert_same( array(), $diviops_wpsbp_selfclose[0]['innerBlocks'], 'and carries no inner blocks' );

	$diviops_wpsbp_nested = parse_blocks(
		'<!-- wp:divi/section {"builderVersion":"5.0.0"} -->' . diviops_wpsbp_row( 'A' ) . '<!-- /wp:divi/section -->'
	);
	assert_same( 'divi/section', $diviops_wpsbp_nested[0]['blockName'], 'nested Divi markup parses to a divi/section at the top' );
	assert_same( 'divi/row', $diviops_wpsbp_nested[0]['innerBlocks'][0]['blockName'], 'with the row nested inside it' );

	// ── end to end: page_block_insert, which no suite could drive before ──
	// The retry is #592's defect, driven through the tool for the first time
	// rather than through the extracted tb_insert_idempotency_offset() helper.
	// Labels come from the STORED content, so a handler that reports success
	// without writing fails here.
	diviops_test_register_post(
		7700,
		'<!-- wp:divi/section {"builderVersion":"5.0.0","module":{"meta":{"adminLabel":{"desktop":{"value":"Hero"}}}}} -->'
		. diviops_wpsbp_row( 'Anchor A' ) . diviops_wpsbp_row( 'Anchor B' )
		. '<!-- /wp:divi/section -->',
		'page',
		'Insert Target'
	);
	$diviops_wpsbp_req = new DiviOps_Test_Request(
		array(
			'id'              => 7700,
			'content'         => diviops_wpsbp_row( 'Inserted' ),
			'position'        => 'before',
			'parent_selector' => 'divi/row[adminLabel="Anchor B"]',
		)
	);

	$diviops_wpsbp_first = diviops_call( 'page_block_insert', array( $diviops_wpsbp_req ) )->get_data();
	assert_true( true === $diviops_wpsbp_first['ok'], 'page_block_insert runs to completion end to end' );
	assert_true( false === $diviops_wpsbp_first['data']['noop'], 'the first insert is a real write, not a no-op' );
	assert_same(
		array( 'Hero', 'Anchor A', 'Inserted', 'Anchor B' ),
		diviops_wpsbp_stored_labels( 7700 ),
		'the stored page carries the inserted row before its anchor'
	);

	$diviops_wpsbp_retry = diviops_call( 'page_block_insert', array( $diviops_wpsbp_req ) )->get_data();
	assert_true( true === $diviops_wpsbp_retry['data']['noop'], 'an identical retry is a no-op (#592)' );
	assert_same(
		array( 'Hero', 'Anchor A', 'Inserted', 'Anchor B' ),
		diviops_wpsbp_stored_labels( 7700 ),
		'and the retry wrote nothing, so the row was not inserted twice'
	);
	// WP-SOURCE-BLOCK-END
} else {
	diviops_skip(
		'no WordPress source: DIVIOPS_WP_SOURCE is unset or does not point at a directory containing wp-includes/class-wp-block-parser.php',
		$diviops_wpsbp_declared_skips
	);
}
