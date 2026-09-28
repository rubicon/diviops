<?php
// SPDX-License-Identifier: MIT
/**
 * Every capability key that names a ROUTE has an MCP tool that reaches it (#38).
 *
 * `bulk_find_replace` shipped as a finished REST route — registered at
 * `/bulk/find-replace`, a `check_write_permission` gate, a plan-token two-step, an
 * all-or-nothing preflight, a forced rollback-snapshot run and a marker-census
 * guard — with **no MCP tool anywhere**. The capability was advertised in the
 * handshake and unreachable from any client. It sat that way while #38 was read as
 * an open design decision, when in fact three of its four pieces had shipped.
 *
 * Nothing in the suite could see it. `tests/test-tool-count-sync.php` keeps the
 * README's counts honest, but it counts the tools that EXIST — a route with no tool
 * simply is not in the number, so the number stays correct. `test-skill-tool-coverage.php`
 * asks whether each existing tool is documented, which a nonexistent tool trivially
 * is not asked about. Both gates were green the whole time. The missing comparison
 * was the plugin's own capability list against the server's tool list, which is this
 * file.
 *
 * `DiviOps_Agent::CAPABILITIES` is the right left-hand side because the plugin's own
 * docblock makes it the maintenance contract: "any new route added below must add its
 * capability key here in the same PR." So a key with no tool is either a route nobody
 * can call, or a key that is not a route at all.
 *
 * The second kind is real and legitimate: many keys advertise a PARAMETER or MODE on
 * a route that already has a tool, not a route of their own. Those are excluded two
 * ways. By pattern, `*_backup` and `*_v1` — a backup-parameter marker and a versioned
 * behaviour marker respectively, both of which a client feature-detects rather than
 * calls. And by an explicit list below, each entry named with the route it modifies,
 * because a pattern broad enough to cover them would also cover a genuinely missing
 * tool. Guessing which kind a key is from its name is exactly the mistake that let
 * `bulk_find_replace` through, so nothing here is inferred from spelling alone.
 *
 * The explicit list is a ratchet, not an allowlist: every entry is asserted to still
 * lack a tool, so one that gains a tool fails this file until it is removed.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$ck_root   = dirname( __DIR__ );
$ck_plugin = (string) file_get_contents( $ck_root . '/plugins/diviops-agent/diviops-agent.php' );
$ck_index  = (string) file_get_contents( $ck_root . '/diviops-server/src/index.ts' );

assert_true( '' !== $ck_plugin, 'read the plugin bootstrap' );
assert_true( '' !== $ck_index, 'read the server entry point' );

/*
 * The capability block, delimited rather than pattern-matched across the whole file.
 * A `'quoted_key'` regex run over the entire bootstrap would also collect post-type
 * names, field names and error codes, and the extra entries would then be reported as
 * tools that do not exist -- noise that makes a gate get disabled.
 */
$ck_after = explode( 'const CAPABILITIES = [', $ck_plugin, 2 );
assert_true( 2 === count( $ck_after ), 'located the CAPABILITIES declaration' );
$ck_block = explode( "\n\t];", $ck_after[1], 2 );
assert_true( 2 === count( $ck_block ), 'located the end of the CAPABILITIES array' );
$ck_block = $ck_block[0];

/*
 * Both halves of the delimiter check. A start that matched too early or an end that
 * matched too late produces a block that still parses and still yields keys, so the
 * count alone cannot tell. A known member proves the block is the right one; the
 * absence of a token that only ever appears OUTSIDE it proves we did not run past it.
 */
assert_true(
	false !== strpos( $ck_block, "'page_get'" ),
	'the extracted block is the capability list (it contains a known member)'
);
assert_true(
	false === strpos( $ck_block, 'register_rest_route' ),
	'and stops before the route registrations, so no non-capability token is collected'
);

preg_match_all( "/'([a-z0-9_]+)'/", $ck_block, $ck_matches );
$ck_keys = array_values( array_unique( $ck_matches[1] ) );
sort( $ck_keys );

assert_true(
	count( $ck_keys ) >= 100,
	'extracted the capability keys (' . count( $ck_keys ) . ' found)'
);

/*
 * An independent count of the same thing, for the reason `test-skill-tool-coverage.php`
 * records: a floor passes while a handful go missing. Every capability line in that
 * block is a comma-separated run of quoted keys, so counting the commas plus the lines
 * is a different arithmetic over the same text. They do not have to be equal -- trailing
 * commas and comment lines make that false -- but a quoted-key count far BELOW the
 * number of quote pairs means the key regex is dropping entries.
 */
$ck_quote_pairs = (int) ( substr_count( $ck_block, "'" ) / 2 );
assert_true(
	count( $ck_matches[1] ) === $ck_quote_pairs,
	'and the key regex matched every quoted token in the block (' . count( $ck_matches[1] ) . ' of ' . $ck_quote_pairs . ' quote pairs)'
);

preg_match_all( '/registerPluginTool\(\s*"([a-z0-9_]+)"/', $ck_index, $ck_tool_matches );
$ck_tools = array_values( array_unique( $ck_tool_matches[1] ) );

assert_true(
	count( $ck_tools ) >= 100,
	'extracted the plugin-routed tool names (' . count( $ck_tools ) . ' found)'
);

/**
 * Keys that advertise a parameter or a mode on a route that already has its own tool.
 * Each is named with that route, because the point of listing them by hand is that
 * the reason is checkable rather than inferred from the spelling.
 */
$ck_pinned_markers = [
	'page_update_content_expected_checksum' => 'the expected_checksum parameter on page_update_content',
	'schema_get_module_dump_all'            => 'the dump_all mode of schema_get_module',
	'cross_env_footer_layout_evidence'      => 'extra evidence in cross_env_source_export_get',
	'tb_template_create_body'               => 'the body parameter on tb_template_create',
	'validate_render_by_page_id'            => 'the page_id form of validate_blocks',
	'variable_create_gradient'              => 'gradient support in variable_create',
];

/**
 * Suffix markers. `*_backup` advertises that a write route accepts a backup request;
 * `*_v1` advertises a versioned storage or encoding behaviour a client feature-detects.
 * Neither is ever a route.
 */
$ck_marker_suffixes = [ '_backup', '_v1' ];

$ck_is_marker = function ( string $key ) use ( $ck_pinned_markers, $ck_marker_suffixes ): bool {
	if ( isset( $ck_pinned_markers[ $key ] ) ) {
		return true;
	}
	foreach ( $ck_marker_suffixes as $suffix ) {
		if ( substr( $key, -strlen( $suffix ) ) === $suffix ) {
			return true;
		}
	}
	return false;
};

// Controls on the classifier itself. Both sets must actually match something in the
// live data, or the exclusion is silently doing nothing and the gate reduces to
// "every key has a tool", which would fail rather than pass -- but the reverse
// mistake, a classifier that excludes EVERYTHING, passes while inspecting nothing.
$ck_suffix_hits = array_values( array_filter( $ck_keys, function ( $k ) use ( $ck_marker_suffixes ) {
	foreach ( $ck_marker_suffixes as $s ) {
		if ( substr( $k, -strlen( $s ) ) === $s ) {
			return true;
		}
	}
	return false;
} ) );
assert_true(
	count( $ck_suffix_hits ) > 0,
	'the suffix markers match real keys (' . count( $ck_suffix_hits ) . '), so the exclusion is live rather than vestigial'
);

$ck_routes = array_values( array_filter( $ck_keys, function ( $k ) use ( $ck_is_marker ) {
	return ! $ck_is_marker( $k );
} ) );
assert_true(
	count( $ck_routes ) >= 80,
	'and most keys are still classified as routes rather than excluded (' . count( $ck_routes ) . ' of ' . count( $ck_keys ) . ')'
);

// The assertion this file exists for.
$ck_unreachable = [];
foreach ( $ck_routes as $key ) {
	if ( ! in_array( 'diviops_' . $key, $ck_tools, true ) ) {
		$ck_unreachable[] = $key;
	}
}
assert_true(
	[] === $ck_unreachable,
	'every route-bearing capability key has an MCP tool that reaches it'
		. ( [] === $ck_unreachable ? '' : ' -- unreachable: ' . implode( ', ', $ck_unreachable ) )
);

// The ratchet. A pinned marker that gains a tool is no longer a marker, and leaving
// it listed would hide a real route from the check above.
foreach ( $ck_pinned_markers as $key => $why ) {
	assert_true(
		in_array( $key, $ck_keys, true ),
		'pinned marker ' . $key . ' is still a capability key (' . $why . ')'
	);
	assert_true(
		! in_array( 'diviops_' . $key, $ck_tools, true ),
		'and still has no tool of its own, so the pin is still earned'
	);
}

printf(
	"capability/tool reachability: %d capability key(s), %d route-bearing, %d marker(s) excluded (%d by suffix, %d pinned), %d plugin-routed tool(s), %d unreachable\n",
	count( $ck_keys ),
	count( $ck_routes ),
	count( $ck_keys ) - count( $ck_routes ),
	count( $ck_suffix_hits ),
	count( $ck_pinned_markers ),
	count( $ck_tools ),
	count( $ck_unreachable )
);
