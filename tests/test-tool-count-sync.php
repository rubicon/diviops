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

$server_dir     = dirname( __DIR__ ) . '/diviops-server';
$index_ts       = $server_dir . '/src/index.ts';
$root_readme    = dirname( __DIR__ ) . '/README.md';
$srv_readme     = $server_dir . '/README.md';
$plugin_dir     = dirname( __DIR__ ) . '/plugins/diviops-agent';
$plugin_readme  = $plugin_dir . '/README.md';
$plugin_main_php = $plugin_dir . '/diviops-agent.php';

assert_true( is_file( $index_ts ), 'diviops-server/src/index.ts exists where this test expects it' );
assert_true( is_file( $root_readme ), 'README.md exists where this test expects it' );
assert_true( is_file( $srv_readme ), 'diviops-server/README.md exists where this test expects it' );
assert_true( is_file( $plugin_readme ), 'plugins/diviops-agent/README.md exists where this test expects it' );
assert_true( is_file( $plugin_main_php ), 'plugins/diviops-agent/diviops-agent.php exists where this test expects it' );

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

/*
 * The plugin README (#168) states the same always-on figure plus a capability-key
 * count of its own, and #90 left it out of this guard — which is why it still read
 * "91 always-on tools" and "98 capability keys" long after both were wrong. It also
 * carried five links to `../../../docs/*.md`, a directory that has never existed in
 * this repo's history, at a depth that escapes the repo root even for targets that
 * do exist. Both classes are asserted below against the real source and filesystem.
 */
$plugin_readme_content = (string) file_get_contents( $plugin_readme );

assert_same(
	$always_on,
	diviops_find_always_on_count( 'plugins/diviops-agent/README.md', $plugin_readme_content ),
	'plugins/diviops-agent/README.md\'s stated always-on tool count matches registerPluginTool + registerLocalTool call sites in index.ts'
);

$plugin_backed_found = preg_match( '/gates its (\d+) plugin-backed tools/', $plugin_readme_content, $plugin_backed_match );
assert_true( 1 === $plugin_backed_found, 'plugins/diviops-agent/README.md states a "gates its N plugin-backed tools" figure' );
assert_same(
	$plugin_count,
	1 === $plugin_backed_found ? (int) $plugin_backed_match[1] : -1,
	'plugins/diviops-agent/README.md\'s stated plugin-backed tool count matches registerPluginTool call sites in index.ts'
);

$server_local_found = preg_match( '/adds (\d+) server-local tools/', $plugin_readme_content, $server_local_match );
assert_true( 1 === $server_local_found, 'plugins/diviops-agent/README.md states an "adds N server-local tools" figure' );
assert_same(
	$local_count,
	1 === $server_local_found ? (int) $server_local_match[1] : -1,
	'plugins/diviops-agent/README.md\'s stated server-local tool count matches registerLocalTool call sites in index.ts'
);

/*
 * Capability keys are the plugin's own figure, derived from the CAPABILITIES const
 * the handshake emits verbatim (trait-meta.php copies every entry into the response
 * without filtering). Comments are stripped before counting so a commented-out key
 * is not counted as live.
 */
$plugin_php    = (string) file_get_contents( $plugin_main_php );
$const_matched = preg_match( '/const CAPABILITIES = \[(.*?)\n\t\];/s', $plugin_php, $const_match );
assert_true( 1 === $const_matched, 'the CAPABILITIES const literal was located in diviops-agent.php' );

$const_body    = 1 === $const_matched ? preg_replace( '~//[^\n]*~', '', $const_match[1] ) : '';
$keys_matched  = preg_match_all( '/\'([a-z0-9_]+)\'/', (string) $const_body, $key_names );
assert_true( is_int( $keys_matched ) && $keys_matched > 0, 'at least one capability key was found in the CAPABILITIES const' );
assert_same(
	$keys_matched,
	count( array_unique( $key_names[1] ) ),
	'every capability key in CAPABILITIES is distinct, so the stated count is not inflated by a duplicate'
);

$stated_keys_found = preg_match( '/advertises (\d+) capability keys/', $plugin_readme_content, $stated_keys_match );
assert_true( 1 === $stated_keys_found, 'plugins/diviops-agent/README.md states an "advertises N capability keys" figure' );
assert_same(
	$keys_matched,
	1 === $stated_keys_found ? (int) $stated_keys_match[1] : -1,
	'plugins/diviops-agent/README.md\'s stated capability-key count matches the CAPABILITIES const in diviops-agent.php'
);

// No SCF capability key exists, which is the claim the README's own SCF section rests on.
assert_same(
	0,
	preg_match( '/\'scf[a-z0-9_]*\'/', (string) $const_body ),
	'CAPABILITIES contains no scf_* key, so the README is right that SCF is not a plugin capability'
);

/*
 * Every relative markdown link in the plugin README must resolve on disk. Anchors are
 * stripped before the existence check; external and absolute URLs are skipped. The
 * matched-nothing guard matters here as much as the assertions: a regex that stops
 * matching links would otherwise report a green "all links resolve" having checked none.
 */
$link_matched = preg_match_all( '/\]\(([^)]+)\)/', $plugin_readme_content, $link_matches );
assert_true( is_int( $link_matched ) && $link_matched > 0, 'at least one markdown link was found in plugins/diviops-agent/README.md' );

$relative_links_checked = 0;
foreach ( $link_matches[1] as $link_target ) {
	if ( preg_match( '~^([a-z][a-z0-9+.-]*:|//|#)~i', $link_target ) ) {
		continue;
	}
	$path_only = (string) preg_replace( '/#.*$/', '', $link_target );
	if ( '' === $path_only ) {
		continue;
	}
	++$relative_links_checked;
	assert_true(
		file_exists( $plugin_dir . '/' . $path_only ),
		"plugins/diviops-agent/README.md's link target '$link_target' resolves on disk"
	);
}
assert_true( $relative_links_checked > 0, 'plugins/diviops-agent/README.md contains relative links for this guard to check' );
