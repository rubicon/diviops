<?php
/**
 * The drop-in constraint is the one thing in this fork that breaks silently.
 *
 * DiviOps Agent Pro is a separate commercial plugin that is not forked. It attaches
 * by calling class_exists( 'DiviOps_Agent' ) and hooking the
 * diviops_agent_handshake_extensions filter. The published @diviops/mcp-server
 * package calls /diviops/v1/* routes and gates every tool on the capability keys the
 * handshake returns.
 *
 * Rename any of those four and Pro stops contributing, and MCP tools disappear
 * without an error, because a failed capability gate removes a tool rather than
 * reporting a problem. Nothing else in this repository would notice. This test is
 * the alarm. See FORK.md.
 *
 * @package DiviOps
 */

$plugin_dir  = dirname( __DIR__ ) . '/plugins/diviops-agent';
$plugin_file = $plugin_dir . '/diviops-agent.php';

assert_true( is_file( $plugin_file ), 'the forked plugin main file is where the fork expects it' );

$main = (string) file_get_contents( $plugin_file );

// 1. The main class name Pro probes with class_exists().
assert_true(
	1 === preg_match( '/^\s*(final\s+)?class\s+DiviOps_Agent\b/m', $main ),
	'class DiviOps_Agent is declared; Pro probes for it with class_exists()'
);

// 2. The REST namespace the published MCP server calls.
assert_true(
	false !== strpos( $main, "'diviops/v1'" ),
	'the REST namespace is still diviops/v1; the MCP server calls /diviops/v1/*'
);

// 3. The filter Pro hooks to inject its capability keys into the handshake.
$handshake_present = false;
foreach ( glob( $plugin_dir . '/includes/*.php' ) ?: array() as $include ) {
	if ( false !== strpos( (string) file_get_contents( $include ), 'diviops_agent_handshake_extensions' ) ) {
		$handshake_present = true;
		break;
	}
}
assert_true(
	$handshake_present,
	'the diviops_agent_handshake_extensions filter still exists; Pro hooks it to advertise its capabilities'
);

// 4. The plugin slug, which is the install directory and the update key.
assert_same(
	'diviops-agent',
	basename( $plugin_dir ),
	'the plugin slug is unchanged; it is the install directory and the update key'
);

// The plugin header must keep declaring a name and version WordPress can read.
assert_true(
	1 === preg_match( '/^\s*\*\s*Plugin Name:\s*\S/m', $main ),
	'the plugin header still declares a Plugin Name'
);
