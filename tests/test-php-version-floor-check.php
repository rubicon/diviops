<?php
/**
 * The `Requires PHP: 7.4` floor is asserted by nothing that can actually detect a
 * PHP 8-only *function call* -- `php -l` on 7.4 is a syntax lint, and
 * `str_contains()`/etc. are syntactically valid on 7.4 (they only fatal with
 * "Call to undefined function" at runtime). The test-suite jobs that could catch a
 * runtime fatal only run on 8.3 and 8.5, where the function already exists.
 *
 * #180 shipped exactly that: three str_contains() calls in trait-variable.php,
 * inside a branch gated by `class_exists( '...\OffCanvasHooks' )`, a Divi class no
 * test in this suite loads. Running the suite on 7.4 would not have caught it
 * either -- that branch is never reached at all under the current test shims. A
 * static scan of the source text has no such blind spot: it does not care whether
 * the branch runs, only whether the call exists in the file.
 *
 * @package DiviOps
 */

require_once dirname( __DIR__ ) . '/scripts/lib/php-version-floor-check.php';

$fixtures = __DIR__ . '/fixtures/php-version-floor';

/*
 * diviops_php8_only_functions() -- the reviewed, versioned table the scan runs
 * against. Spot-check a few entries rather than the whole table, so this test
 * doesn't have to be updated every time the table grows.
 */
$table = diviops_php8_only_functions();
assert_true( isset( $table['str_contains'] ) && '8.0' === $table['str_contains'], 'str_contains is tabled as PHP 8.0' );
assert_true( isset( $table['array_is_list'] ) && '8.1' === $table['array_is_list'], 'array_is_list is tabled as PHP 8.1' );
assert_true( isset( $table['json_validate'] ) && '8.3' === $table['json_validate'], 'json_validate is tabled as PHP 8.3' );

/*
 * diviops_parse_requires_php() -- reads the declared floor out of a plugin main
 * file's header block, the same header WordPress itself reads.
 */
assert_same(
	'7.4',
	diviops_parse_requires_php( " * Plugin Name: Example\n * Requires PHP: 7.4\n * Version: 1.0.0\n" ),
	'reads the Requires PHP value out of a standard plugin header block'
);
assert_same(
	null,
	diviops_parse_requires_php( " * Plugin Name: Example\n * Version: 1.0.0\n" ),
	'returns null when the header carries no Requires PHP line'
);

/*
 * diviops_scan_source_for_forbidden_calls() -- the actual detector. Table has one
 * entry throughout: str_contains => 8.0.
 */
$forbidden = array( 'str_contains' => '8.0' );

// The exact shape #180 shipped: a bare, unguarded call.
$bare_call_hits = diviops_scan_source_for_forbidden_calls(
	"<?php\nif ( str_contains( \$haystack, 'needle' ) ) {\n\techo 'yes';\n}\n",
	$forbidden
);
assert_same( 1, count( $bare_call_hits ), 'a bare str_contains() call is flagged exactly once' );
assert_same( 'str_contains', $bare_call_hits[0]['function'] ?? null, 'the flagged function name is str_contains' );
assert_same( '8.0', $bare_call_hits[0]['introduced_in'] ?? null, 'the flagged violation carries the version it was introduced in' );
assert_same( 2, $bare_call_hits[0]['line'] ?? null, 'the flagged violation carries the correct source line' );

// A fully-qualified call to the same builtin must still be flagged.
$fq_call_hits = diviops_scan_source_for_forbidden_calls(
	"<?php\n\\str_contains( \$a, \$b );\n",
	$forbidden
);
assert_same( 1, count( $fq_call_hits ), 'a fully-qualified \\str_contains() call is still flagged' );

// A call on a case-different spelling must still be flagged (PHP functions are
// case-insensitive).
$case_hits = diviops_scan_source_for_forbidden_calls(
	"<?php\nStr_Contains( \$a, \$b );\n",
	$forbidden
);
assert_same( 1, count( $case_hits ), 'a differently-cased call to the same builtin is still flagged' );

// A method call sharing the name must NOT be flagged -- it calls something else.
$method_hits = diviops_scan_source_for_forbidden_calls(
	"<?php\n\$formatter->str_contains( \$a, \$b );\n",
	$forbidden
);
assert_same( array(), $method_hits, 'a method call named str_contains is not the global builtin and is not flagged' );

// A static call sharing the name must NOT be flagged either.
$static_hits = diviops_scan_source_for_forbidden_calls(
	"<?php\nFoo::str_contains( \$a, \$b );\n",
	$forbidden
);
assert_same( array(), $static_hits, 'a static call named str_contains is not the global builtin and is not flagged' );

// A guarded polyfill DEFINING str_contains must NOT be flagged -- the issue itself
// names this as a valid alternative fix.
$polyfill_hits = diviops_scan_source_for_forbidden_calls(
	"<?php\nif ( ! function_exists( 'str_contains' ) ) {\n\tfunction str_contains( \$haystack, \$needle ) {\n\t\treturn '' === \$needle || false !== strpos( \$haystack, \$needle );\n\t}\n}\n",
	$forbidden
);
assert_same( array(), $polyfill_hits, 'a guarded polyfill defining str_contains is not flagged as a call' );

// The word appearing only in a string literal or a comment must NOT be flagged --
// this is exactly the class of false positive a bare regex would produce and a
// tokenizer will not.
$text_hits = diviops_scan_source_for_forbidden_calls(
	"<?php\n// str_contains is not allowed below PHP 8.0.\n\$msg = 'please avoid str_contains here';\n",
	$forbidden
);
assert_same( array(), $text_hits, 'the function name inside a comment or string literal is not flagged' );

// Two violations in one file must both be reported, with correct line numbers.
$multi_hits = diviops_scan_source_for_forbidden_calls(
	"<?php\nstr_contains( \$a, 'x' );\n\$ok = true;\nstr_contains( \$b, 'y' );\n",
	$forbidden
);
assert_same( 2, count( $multi_hits ), 'every violation in a file is reported, not just the first' );
assert_same( array( 2, 4 ), array( $multi_hits[0]['line'], $multi_hits[1]['line'] ), 'each violation carries its own correct line number' );

/*
 * diviops_find_php_files() -- the recursive walker the CLI script and the
 * repo-wide self-check below both depend on.
 */
$found = diviops_find_php_files( $fixtures );
assert_same(
	array( $fixtures . '/a.php', $fixtures . '/subdir/b.php' ),
	$found,
	'finds every .php file recursively, sorted, and ignores non-php files'
);

/*
 * A scan that finds zero PHP files must fail loudly rather than report a green
 * pass of nothing inspected -- the same discipline tests/run.php applies to its
 * own file discovery, and test-preset-attrs-map-extractor.php applies to its index.
 */
assert_same(
	array(),
	diviops_find_php_files( $fixtures . '/subdir/nested' ),
	'a directory holding only non-php files yields an empty file list (the caller is responsible for treating empty as failure)'
);

/*
 * The load-bearing self-check: run the real detector against the real plugins/
 * tree, under the real declared floor, and assert zero violations. This is what
 * makes `php tests/run.php` itself the closing gate for #180 -- it runs in the
 * existing "Test suite" (php 8.3) and "Test suite (php 8.5)" CI jobs, so no new CI
 * job is needed, and it does not depend on any code path actually executing.
 */
$plugin_root  = dirname( __DIR__ ) . '/plugins';
$plugin_mains = array(
	$plugin_root . '/diviops-agent/diviops-agent.php',
	$plugin_root . '/diviops-design-library/diviops-design-library.php',
);

$floor = null;
foreach ( $plugin_mains as $main_file ) {
	assert_true( is_file( $main_file ), "expected plugin main file exists: {$main_file}" );

	$declared = diviops_parse_requires_php( (string) file_get_contents( $main_file ) );
	assert_true( null !== $declared, "the plugin header at {$main_file} declares Requires PHP" );

	if ( null === $floor || version_compare( (string) $declared, $floor, '<' ) ) {
		$floor = (string) $declared;
	}
}

$real_forbidden = array();
foreach ( diviops_php8_only_functions() as $name => $introduced_in ) {
	if ( version_compare( $introduced_in, (string) $floor, '>' ) ) {
		$real_forbidden[ $name ] = $introduced_in;
	}
}

$real_files = diviops_find_php_files( $plugin_root );
assert_true( array() !== $real_files, 'plugins/ still contains PHP files to scan (a broken glob must not pass silently)' );

$real_violations = array();
foreach ( $real_files as $file ) {
	foreach ( diviops_scan_source_for_forbidden_calls( (string) file_get_contents( $file ), $real_forbidden ) as $hit ) {
		$real_violations[] = sprintf( '%s:%d %s() (PHP %s)', $file, $hit['line'], $hit['function'], $hit['introduced_in'] );
	}
}

assert_same(
	array(),
	$real_violations,
	'no PHP-8-only builtin call exists anywhere under plugins/ given the declared Requires PHP floor'
);
