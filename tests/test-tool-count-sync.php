<?php
/**
 * Tool-count-sync guard (#90).
 *
 * Both READMEs state a hardcoded MCP tool count in prose ("109 always-on tools").
 * That number goes stale on every feature that registers a new tool — #36 added
 * three tools the same day a docs PR had just corrected the count to 109, making it
 * wrong again within hours. Nothing caught that, the same way nothing caught the
 * dangling `../docs/*.md` links #90 also fixes.
 *
 * This mirrors tests/test-version-sync.php's approach for the plugin version: derive
 * the real number from source (`diviops-server/src/index.ts`'s tool-registration call
 * sites), then assert every place a README states that number in prose agrees with it.
 *
 * The three registration helpers are only ever invoked at statement position — each
 * call site is `registerPluginTool(`, `registerLocalTool(`, or `registerProTool(` as
 * the first non-whitespace text on its line. The regexes are anchored to line start
 * for exactly that reason: it excludes the three functions' own `function
 * registerPluginTool<H ...>(` declarations (which start with `function `), doc-comment
 * mentions like `` * as `registerPluginTool` `` (which start with `//` or ` * `), and
 * `registerProTools()` — the plural wrapper function, whose name is a superstring of
 * `registerProTool` but never matches because the character right after
 * `registerProTool` is `s`, not `(`.
 *
 * @package DiviOps
 */

$server_dir  = dirname( __DIR__ ) . '/diviops-server';
$index_ts    = $server_dir . '/src/index.ts';
$root_readme = dirname( __DIR__ ) . '/README.md';
$srv_readme  = $server_dir . '/README.md';

assert_true( is_file( $index_ts ), 'diviops-server/src/index.ts exists where this test expects it' );
assert_true( is_file( $root_readme ), 'README.md exists where this test expects it' );
assert_true( is_file( $srv_readme ), 'diviops-server/README.md exists where this test expects it' );

$src = (string) file_get_contents( $index_ts );

/**
 * Count line-anchored call sites of a tool-registration helper, guarding against a
 * silently-empty match (a renamed helper, a reformatted call site) the same way
 * tests/run.php refuses to report a green suite that discovered nothing.
 *
 * @param string $src  Full diviops-server/src/index.ts source.
 * @param string $name Helper name, e.g. 'registerPluginTool'.
 */
function diviops_count_register_calls( string $src, string $name ): int {
	$matched = preg_match_all( '/^[ \t]*' . preg_quote( $name, '/' ) . '\(/m', $src );
	assert_true( is_int( $matched ) && $matched > 0, "at least one $name( call site was found in index.ts" );
	return (int) $matched;
}

$plugin_count = diviops_count_register_calls( $src, 'registerPluginTool' );
$local_count  = diviops_count_register_calls( $src, 'registerLocalTool' );
$pro_count    = diviops_count_register_calls( $src, 'registerProTool' );
$always_on    = $plugin_count + $local_count;

// registerProTools() (plural, the wrapper function) must never be counted as a
// registerProTool( call site — it would silently inflate $pro_count by one.
assert_true(
	0 === preg_match( '/^[ \t]*registerProTool\(\)/m', $src ),
	'no bare registerProTool() call exists that the plural-wrapper false-positive check would need to exclude'
);

/**
 * Extract the "**N always-on tools**" figure from a README and assert it was found —
 * a regex that silently matches nothing must fail the test, not pass it vacuously.
 *
 * @param string $readme_path Path to the README file, for the failure message.
 * @param string $content     README content.
 */
function diviops_find_always_on_count( string $readme_path, string $content ): int {
	$found = preg_match( '/\*\*(\d+) always-on tools\*\*/', $content, $m );
	assert_true( 1 === $found, "an \"**N always-on tools**\" figure was located in $readme_path" );
	return 1 === $found ? (int) $m[1] : -1;
}

$root_content = (string) file_get_contents( $root_readme );
$srv_content  = (string) file_get_contents( $srv_readme );

assert_same(
	$always_on,
	diviops_find_always_on_count( 'README.md', $root_content ),
	'README.md\'s stated always-on tool count matches registerPluginTool + registerLocalTool call sites in index.ts'
);
assert_same(
	$always_on,
	diviops_find_always_on_count( 'diviops-server/README.md', $srv_content ),
	'diviops-server/README.md\'s stated always-on tool count matches registerPluginTool + registerLocalTool call sites in index.ts'
);

// README.md also states the conditional Pro figure inline in prose.
$root_pro_found = preg_match( '/further (\d+) conditionally-registered Pro tools/', $root_content, $root_pro_match );
assert_true( 1 === $root_pro_found, 'README.md states a "further N conditionally-registered Pro tools" figure' );
assert_same(
	$pro_count,
	1 === $root_pro_found ? (int) $root_pro_match[1] : -1,
	'README.md\'s stated conditional Pro tool count matches registerProTool call sites in index.ts'
);

// diviops-server/README.md states the conditional Pro figure structurally: one table
// row of backtick-quoted `diviops_*` tool names per registered Pro tool, between the
// "conditionally-registered Pro tools" intro and the "gates are not satisfied" line
// that closes the table. Count distinct tool names named there, not table rows —
// one row can (and does) list more than one tool name.
$pro_section_start = strpos( $srv_content, 'conditionally-registered Pro tools' );
$pro_section_end    = strpos( $srv_content, 'gates are not satisfied' );
assert_true(
	false !== $pro_section_start && false !== $pro_section_end && $pro_section_end > $pro_section_start,
	'diviops-server/README.md has a delimited conditional-Pro-tools table section'
);
$pro_section = false !== $pro_section_start && false !== $pro_section_end
	? substr( $srv_content, $pro_section_start, $pro_section_end - $pro_section_start )
	: '';

$named = preg_match_all( '/`(diviops_[a-z0-9_]+)`/', $pro_section, $pro_names );
assert_true( is_int( $named ) && $named > 0, 'diviops-server/README.md\'s conditional-Pro-tools table names at least one tool' );
$unique_pro_names = array_unique( $pro_names[1] );
assert_same(
	$pro_count,
	count( $unique_pro_names ),
	'the distinct diviops_* tool names in diviops-server/README.md\'s conditional-Pro-tools table match registerProTool call sites in index.ts'
);
