<?php
// SPDX-License-Identifier: MIT
/**
 * Localization: the header that makes a bundled translation loadable, and the
 * domain-drift this fork currently has none of (#491).
 *
 * The audit behind #491 found the strings already correct -- every translatable
 * string in `diviops-agent` is marked against the `diviops-agent` domain, with no
 * second domain and no bare user-facing text. What was missing was the one header
 * that makes those strings DISTRIBUTABLE: without `Domain Path`, core has nowhere
 * to look for a translation shipped inside the plugin, so a `.mo` bundled with a
 * release can never load. A translation dropped in the global directory
 * (`wp-content/languages/plugins/`) still loads -- core has auto-loaded those since
 * WP 4.6 -- which is exactly why the gap is easy to miss: nothing is broken for the
 * only distribution channel anyone had tested.
 *
 * Two things are asserted, for two different reasons.
 *
 * The header and the directory are asserted because they are the fix, and a fix
 * with no gate is a fix that survives until the next person tidies an unused
 * directory away.
 *
 * The domain scan is asserted because the audit found zero drift and the point is
 * to keep it at zero. It uses PHP's own tokenizer rather than a regex: a regex over
 * call sites has to guess where a string literal ends, and this repository has
 * already paid for that guess more than once (#97). `token_get_all()` answers with
 * the parser's own lexing instead.
 *
 * The scan asserts it INSPECTED something before it reports that it found nothing
 * wrong. A gate that derives pass/fail only from problems-found passes while
 * scanning an empty file list, which has happened three times on the predecessor
 * repository and is why the runner itself fails on empty discovery.
 *
 * NOT asserted: that a `.pot` file ships. Recorded decision (#491): it does not.
 * A `.pot` is a snapshot of the strings at the moment it was generated, so shipping
 * one without a regeneration step in CI means shipping a file that rots silently and
 * hands translators strings the plugin no longer emits. `languages/README.md`
 * carries the command to generate one on demand instead. Revisit when a translation
 * is actually being produced -- that is the point at which a stale `.pot` would
 * cost something, and also the point at which someone will notice it rotting.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$i18n_plugin_dir  = dirname( __DIR__ ) . '/plugins/diviops-agent';
$i18n_plugin_file = $i18n_plugin_dir . '/diviops-agent.php';
$i18n_header      = (string) file_get_contents( $i18n_plugin_file );
$i18n_header      = substr( $i18n_header, 0, 2048 );

// ── 1. The header, and the directory it points at ──────────────────────

assert_true(
	1 === preg_match( '/^\s*\*\s*Text Domain:\s*diviops-agent\s*$/m', $i18n_header ),
	'the plugin header declares the diviops-agent text domain'
);
assert_true(
	1 === preg_match( '/^\s*\*\s*Domain Path:\s*\/languages\s*$/m', $i18n_header ),
	'and Domain Path, without which a translation bundled with the plugin has nowhere to be found'
);
assert_true(
	is_dir( $i18n_plugin_dir . '/languages' ),
	'and the directory that header names exists -- a Domain Path pointing at nothing is the same gap with a header on top'
);
assert_true(
	is_file( $i18n_plugin_dir . '/languages/README.md' ),
	'carrying the note that records why no .pot ships and how to generate one'
);

// `load_plugin_textdomain()` is deliberately absent: core auto-loads translations
// for plugins on WP 6.5+ (this plugin's floor is 6.5), so the call is dead weight
// and, called on the wrong hook, is the usual cause of "notice: translation loaded
// too early". Asserted as absent so it is not added by reflex later.
assert_same(
	0,
	substr_count( (string) file_get_contents( $i18n_plugin_file ), 'load_plugin_textdomain' ),
	'and no load_plugin_textdomain() call, which core has made unnecessary at this plugin\'s 6.5 floor'
);

// ── 2. Every translatable string uses this plugin's own domain ─────────
//
// Tokenized, not pattern-matched. The functions whose LAST argument is the domain,
// with the argument index the domain occupies (0-based, counting top-level commas).

$i18n_functions = array(
	'__'              => 1,
	'_e'              => 1,
	'esc_html__'      => 1,
	'esc_html_e'      => 1,
	'esc_attr__'      => 1,
	'esc_attr_e'      => 1,
	'_x'              => 2,
	'_ex'             => 2,
	'esc_html_x'      => 2,
	'esc_attr_x'      => 2,
	'_n'              => 3,
	'_nx'             => 4,
	'_n_noop'         => 2,
	'_nx_noop'        => 3,
);

$i18n_files = array();
$i18n_iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $i18n_plugin_dir ) );
foreach ( $i18n_iter as $i18n_entry ) {
	if ( $i18n_entry->isFile() && 'php' === strtolower( $i18n_entry->getExtension() ) ) {
		$i18n_files[] = $i18n_entry->getPathname();
	}
}
sort( $i18n_files );

$i18n_calls   = 0;
$i18n_wrong   = array();
$i18n_dynamic = array();

foreach ( $i18n_files as $i18n_file ) {
	$tokens = token_get_all( (string) file_get_contents( $i18n_file ) );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
			continue;
		}
		$name = $token[1];
		if ( ! isset( $i18n_functions[ $name ] ) ) {
			continue;
		}

		// A method call or a declaration is not an i18n call site.
		for ( $back = $i - 1; $back >= 0; $back-- ) {
			$prev = $tokens[ $back ];
			if ( is_array( $prev ) && ( T_WHITESPACE === $prev[0] || T_COMMENT === $prev[0] || T_DOC_COMMENT === $prev[0] ) ) {
				continue;
			}
			break;
		}
		$prev = $back >= 0 ? $tokens[ $back ] : null;
		if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}

		// Find the opening paren.
		for ( $open = $i + 1; $open < $count; $open++ ) {
			$next = $tokens[ $open ];
			if ( is_array( $next ) && T_WHITESPACE === $next[0] ) {
				continue;
			}
			break;
		}
		if ( $open >= $count || '(' !== $tokens[ $open ] ) {
			continue;
		}

		// Walk to the matching close paren, collecting each top-level argument's
		// tokens. Depth counts every bracket kind, so a nested call or an array
		// literal in an earlier argument cannot be mistaken for a comma at this
		// level.
		$depth  = 0;
		$args   = array( array() );
		$closed = false;
		for ( $j = $open; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			$s = is_array( $t ) ? $t[1] : $t;

			if ( in_array( $s, array( '(', '[', '{' ), true ) ) {
				$depth++;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( in_array( $s, array( ')', ']', '}' ), true ) ) {
				$depth--;
				if ( 0 === $depth ) {
					$closed = true;
					break;
				}
			} elseif ( ',' === $s && 1 === $depth ) {
				$args[] = array();
				continue;
			}

			if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$args[ count( $args ) - 1 ][] = $t;
		}

		if ( ! $closed ) {
			continue;
		}

		$i18n_calls++;
		$index = $i18n_functions[ $name ];
		$where = str_replace( dirname( __DIR__ ) . '/', '', $i18n_file ) . ':' . $token[2];

		if ( ! isset( $args[ $index ] ) || 1 !== count( $args[ $index ] ) ) {
			$i18n_dynamic[] = $where . ' (' . $name . ')';
			continue;
		}
		$arg = $args[ $index ][0];
		if ( ! is_array( $arg ) || T_CONSTANT_ENCAPSED_STRING !== $arg[0] ) {
			$i18n_dynamic[] = $where . ' (' . $name . ')';
			continue;
		}
		$domain = trim( $arg[1], "'\"" );
		if ( 'diviops-agent' !== $domain ) {
			$i18n_wrong[] = $where . ' uses ' . $domain;
		}
	}
}

// The control. Without it every assertion below is satisfied by a scan that found
// no files, parsed nothing, and reported no problems.
assert_true(
	count( $i18n_files ) >= 15,
	'the scan walked the plugin tree and found PHP files to parse (' . count( $i18n_files ) . ' found)'
);
assert_true(
	$i18n_calls >= 100,
	'and resolved the domain argument of that many i18n call sites (' . $i18n_calls . ' resolved) -- the audit counted 102'
);

assert_same( array(), $i18n_wrong, 'every translatable string uses the diviops-agent domain' );
assert_same(
	array(),
	$i18n_dynamic,
	'and every domain argument is a literal, not a variable or a constant -- a computed domain is invisible to every string-extraction tool, so it is indistinguishable from an unmarked string'
);

// ── 3. The sibling plugin declares a domain it does not use ───────────
//
// `diviops-design-library` carries `Text Domain: diviops-design-library` and emits
// no user-visible text at all -- it is CSS, JS and registration. #491 called that
// cosmetic and offered two fixes: drop the header, or leave it as a placeholder.
//
// Left in place, deliberately. The file originated upstream, so removing a header
// that costs nothing buys a permanent divergence row and a conflict the next time
// upstream touches that block. What the issue actually asks for is that the header
// not be READ as "this plugin is localized", and a header cannot say that about
// itself -- so the coupling is asserted here instead, where it cannot be misread.
//
// The invariant is conditional rather than absolute, so it survives the plugin
// growing UI: zero translatable strings and no Domain Path, OR translatable
// strings that all use its own declared domain, at which point the Domain Path
// decision comes due the same way it did for diviops-agent.

$i18n_dl_file = dirname( __DIR__ ) . '/plugins/diviops-design-library/diviops-design-library.php';
$i18n_dl_src  = (string) file_get_contents( $i18n_dl_file );

$i18n_dl_calls = 0;
$i18n_dl_wrong = array();
$dl_tokens     = token_get_all( $i18n_dl_src );
foreach ( $dl_tokens as $dl_index => $dl_token ) {
	if ( is_array( $dl_token ) && T_STRING === $dl_token[0] && isset( $i18n_functions[ $dl_token[1] ] ) ) {
		$i18n_dl_calls++;
		$i18n_dl_wrong[] = $dl_token[1] . ' at line ' . $dl_token[2];
	}
}

// The control: the SAME detector, over the plugin known to carry 102 call sites.
// Without it, "zero calls found" is indistinguishable from a detector that cannot
// see a call at all -- which is the category of mistake this repository keeps
// paying for, most recently a BSD-grep alternation that matched nothing anywhere.
assert_true(
	$i18n_calls >= 100,
	'the detector used on the design library is the one that resolved ' . $i18n_calls . ' call sites in diviops-agent'
);

if ( 0 === $i18n_dl_calls ) {
	assert_true(
		1 !== preg_match( '/^\s*\*\s*Domain Path:/m', substr( $i18n_dl_src, 0, 2048 ) ),
		'diviops-design-library emits no translatable strings, so it declares no Domain Path either -- a translations directory for a plugin with no strings is a claim, not a feature'
	);
} else {
	assert_same(
		array(),
		$i18n_dl_wrong,
		'diviops-design-library has grown translatable strings, so they must use its own declared domain -- and its Domain Path decision is now due'
	);
}

printf(
	"i18n: design library emits %d translatable string(s)\n",
	$i18n_dl_calls
);

printf(
	"i18n: %d call site(s) across %d PHP file(s), all on the diviops-agent domain\n",
	$i18n_calls,
	count( $i18n_files )
);
