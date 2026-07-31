<?php
/**
 * dashboard_capabilities() — the admin dashboard's Capabilities list (#123).
 *
 * Every row on that list is a claim to a user reading it: "this works" or
 * "this does not." A row that answers wrongly is worse than no row, because
 * a ✗ on a working feature makes people stop trying to use it.
 *
 * WP-CLI was such a row until #123, and it was wrong in a way no threshold
 * tweak could fix. The plugin does not execute WP-CLI at all — there is no
 * exec/shell_exec/proc_open/WP_CLI:: anywhere in it (asserted below, so this
 * stays true). WP-CLI is run by the Node MCP server, a separate process whose
 * environment PHP cannot read. The old check
 *
 *     defined( 'DIVIOPS_WP_CLI_PATH' ) || getenv( 'WP_PATH' ) || getenv( 'WP_CLI_CMD' )
 *
 * therefore reported ✗ on a setup where WP-CLI demonstrably worked (verified
 * live: `diviops_meta_wp_cli { command: "core version" }` returned exit_code 0),
 * and the only lever that could flip it — defining DIVIOPS_WP_CLI_PATH, a
 * constant referenced nowhere else in the codebase — would have pinned it to ✓
 * unconditionally, including when WP-CLI was genuinely broken. Both states lie.
 * The MCP server reports real WP-CLI readiness through diviops_meta_info; that
 * is the side that can actually observe it.
 *
 * The invariant these tests protect: this list only advertises capabilities the
 * plugin itself provides, and every one of them is gated on Divi being active.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$active   = DiviOps_Agent::dashboard_capabilities( true );
$inactive = DiviOps_Agent::dashboard_capabilities( false );

// ── The regression itself ────────────────────────────────────────────────

assert_true(
	! array_key_exists( 'WP-CLI', $active ),
	'dashboard_capabilities() does not advertise WP-CLI — the plugin never executes it, and cannot observe whether the MCP server can (#123)'
);

// ── The invariant that keeps a future row from repeating the mistake ─────

assert_same(
	[ 'Pages', 'Modules', 'Presets', 'Library', 'Theme Builder', 'Canvas', 'Variables' ],
	array_keys( $active ),
	'dashboard_capabilities() advertises exactly the seven Divi-backed capability domains'
);

foreach ( $active as $name => $value ) {
	assert_same(
		true,
		$value,
		"{$name} is reported available when Divi is active"
	);
}

foreach ( $inactive as $name => $value ) {
	assert_same(
		false,
		$value,
		"{$name} is reported unavailable when Divi is inactive — every advertised capability depends on Divi, so none may report true without it"
	);
}

assert_same(
	array_keys( $active ),
	array_keys( $inactive ),
	'the capability list is the same set regardless of Divi state — only the values move, so a row can never silently vanish'
);

// ── The premise the whole fix rests on ───────────────────────────────────
// If the plugin ever gains its own WP-CLI execution path, the reasoning above
// stops holding and a PHP-side readiness row could become legitimate. Failing
// here is the signal to revisit #123 rather than a defect in itself.

$plugin_php = '';
foreach ( array_merge(
	[ __DIR__ . '/../plugins/diviops-agent/diviops-agent.php' ],
	glob( __DIR__ . '/../plugins/diviops-agent/includes/*.php' ) ?: []
) as $file ) {
	$plugin_php .= file_get_contents( $file );
}

// Strip comments so prose mentioning these names (this fix documents them at
// the call site) cannot be mistaken for real execution calls.
$code_only = '';
foreach ( token_get_all( $plugin_php ) as $token ) {
	if ( is_array( $token ) ) {
		if ( in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING ], true ) ) {
			continue;
		}
		$code_only .= $token[1];
	} else {
		$code_only .= $token;
	}
}

foreach ( [ 'shell_exec', 'proc_open', 'passthru', 'WP_CLI::' ] as $exec_call ) {
	assert_true(
		false === strpos( $code_only, $exec_call ),
		"the plugin contains no {$exec_call} — it does not execute WP-CLI, which is why it cannot advertise WP-CLI readiness (#123)"
	);
}

echo 'PASS: dashboard-capabilities (' . ( 3 + count( $active ) + count( $inactive ) + 4 ) . " assertions)\n";
