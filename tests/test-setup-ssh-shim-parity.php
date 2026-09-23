<?php
// SPDX-License-Identifier: MIT
/**
 * The SSH shim is documented identically in both places that print it (#478).
 *
 * `SETUP.md`'s "Remote hosts over SSH" section and the startup warning in
 * `diviops-server/src/wp-cli.ts` both hand the user a shim to copy. Two copies
 * of the same instruction drift, and the failure mode of a drifted one is
 * invisible: the user copies whichever they saw first and gets an ssh that
 * exits 255 before wp-cli is ever reached.
 *
 * ── What #478 added, and why it is not optional ───────────────────────────
 *
 * Both copies omitted `-o RemoteCommand=none -o RequestTTY=no`. On any host
 * whose `~/.ssh/config` sets a `RemoteCommand`, OpenSSH refuses to run a command
 * argument at all — "Cannot execute command-line and remote command", exit 255 —
 * so the shim fails on every invocation, and the server reports it as a spawn
 * failure, which reads like a missing binary.
 *
 * Our own staging host is configured exactly that way, and this is the same
 * failure that blinded the drift gate through a whole Divi upgrade (#412): the
 * probe exited 255, the gate read it as unreachable, and the suite printed PASS.
 *
 * Both flags are correct on a host with no RemoteCommand configured, so they are
 * unconditional rather than a caveat.
 *
 * ── Why parity is asserted rather than just presence ──────────────────────
 *
 * Asserting only that SETUP.md contains the flags would pass while the
 * `wp-cli.ts` warning still printed the old shim — and that warning is what a
 * user sees at the moment the problem bites, so it is the copy more likely to be
 * acted on.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

$root      = dirname( __DIR__ );
$setup     = (string) file_get_contents( $root . '/SETUP.md' );
$wp_cli_ts = (string) file_get_contents( $root . '/diviops-server/src/wp-cli.ts' );

assert_true(
	'' !== $setup && '' !== $wp_cli_ts,
	'#478: both files were read, so a match below is not two empty strings agreeing'
);

// Locate the shim in each. Both must contain one, or the parity check below is
// comparing something that is not there.
assert_true(
	false !== strpos( $setup, "printf '%q ' \"\$@\"" ),
	'#478: SETUP.md still documents the re-quoting shim'
);
assert_true(
	false !== strpos( $wp_cli_ts, "printf '%q ' " ),
	'#478: and the wp-cli.ts startup warning still prints one'
);

foreach ( array(
	'-o RemoteCommand=none' => 'OpenSSH refuses a command argument when a RemoteCommand is configured',
	'-o RequestTTY=no'      => 'a forced TTY changes how the remote shell reads the command',
) as $flag => $why ) {
	assert_true(
		false !== strpos( $setup, $flag ),
		sprintf( '#478: SETUP.md\'s shim passes %s — %s', $flag, $why )
	);
	assert_true(
		false !== strpos( $wp_cli_ts, $flag ),
		sprintf( '#478: and so does the wp-cli.ts warning\'s copy — %s', $why )
	);
}

// BatchMode was already in both and must stay: without it a host that starts
// prompting hangs the server instead of failing.
assert_true(
	false !== strpos( $setup, '-o BatchMode=yes' ) && false !== strpos( $wp_cli_ts, '-o BatchMode=yes' ),
	'#478: both copies keep BatchMode=yes, so a prompting host fails rather than hanging'
);

// The reason has to travel with the flags. A flag a reader cannot explain is one
// they will drop the next time they simplify the snippet.
assert_true(
	false !== stripos( $setup, 'RemoteCommand' ) && false !== stripos( $setup, '255' ),
	'#478: SETUP.md explains the failure — exit 255 — rather than only showing the flags'
);
