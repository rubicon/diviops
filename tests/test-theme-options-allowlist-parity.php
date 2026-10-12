<?php
// SPDX-License-Identifier: MIT
/**
 * The theme-options write allowlist exists three times and must agree (#581).
 *
 * The list lives in the route (`trait-meta.php`'s `update_theme_options()`), in a
 * client-side copy in `diviops-server/src/index.ts`
 * (`THEME_OPTIONS_WRITE_ALLOWLIST`, which `dry_run` is computed from), and as a
 * stated count in that tool's own description and parameter description. The
 * duplication is deliberate and documented — `dry_run` is computed client-side and
 * must mirror the WRITE route rather than the read route — but nothing asserted the
 * copies agree.
 *
 * When #576 made the list ten keys, the first pass updated the array and the
 * opening sentence of the description and left "restricted to the 9-key write
 * allowlist" LATER IN THE SAME STRING, plus a whole paragraph in
 * `skills/divi-5-builder/references/tools.md` still describing the nine-key
 * contract. An MCP tool description is the runtime interface an agent plans
 * against, so a description that contradicts itself is a behavioural defect rather
 * than a typo. An adversarial review caught it; no gate could have.
 *
 * Deliberately a gate and not a refactor. Deriving one list from the other would
 * couple the MCP server to the plugin's PHP at build time, and `dry_run` being
 * client-side is a recorded decision. If a cheap single source of truth turns up,
 * prefer it to this file.
 *
 * Non-vacuity is asserted, not assumed: the fixture half proves every verdict is
 * reachable, and the live half fails if either list came back empty or if the
 * stated counts were never found.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * The write allowlist as the PHP route declares it.
 *
 * Scoped to `update_theme_options()` on purpose — `filter_public_theme_options()`
 * declares a separate READ allowlist in the same file, and matching that one would
 * invert the very asymmetry this gate exists to watch.
 *
 * @param string $php Contents of trait-meta.php.
 * @return array<int,string>
 */
function diviops_toap_route_list( string $php ): array {
	$at = strpos( $php, 'function update_theme_options' );
	if ( false === $at ) {
		return array();
	}
	$open = strpos( $php, '$allowed', $at );
	if ( false === $open ) {
		return array();
	}
	$close = strpos( $php, '];', $open );
	if ( false === $close ) {
		return array();
	}
	preg_match_all( "/'([a-z0-9_]+)'/", substr( $php, $open, $close - $open ), $m );

	return $m[1];
}

/**
 * The write allowlist as the MCP server copies it.
 *
 * @param string $ts Contents of index.ts.
 * @return array<int,string>
 */
function diviops_toap_server_list( string $ts ): array {
	$at = strpos( $ts, 'THEME_OPTIONS_WRITE_ALLOWLIST = [' );
	if ( false === $at ) {
		return array();
	}
	$close = strpos( $ts, ']', $at );
	if ( false === $close ) {
		return array();
	}
	preg_match_all( '/"([a-z0-9_]+)"/', substr( $ts, $at, $close - $at ), $m );

	return $m[1];
}

/**
 * Every key count the tool's prose states.
 *
 * @param string $ts Contents of index.ts.
 * @return array<int,int>
 */
function diviops_toap_stated_counts( string $ts ): array {
	preg_match_all( '/these (\d+) (?:allowlisted )?keys|(\d+)-key write allowlist/', $ts, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $hit ) {
		$out[] = (int) ( '' !== $hit[1] ? $hit[1] : $hit[2] );
	}

	return $out;
}

/* -------------------------------------------------------------------------
 * 1. Fixture half. Each verdict must be reachable or the live half proves
 *    nothing.
 * ---------------------------------------------------------------------- */

$toap_php = "\tfunction update_theme_options( \$request ) {\n\t\t\$allowed  = [\n\t\t\t'a_one', 'b_two',\n\t\t\t'c_three',\n\t\t];\n\t\t\$updated = [];";
assert_same( array( 'a_one', 'b_two', 'c_three' ), diviops_toap_route_list( $toap_php ), 'the route list is read in declaration order' );
assert_same( array(), diviops_toap_route_list( "\$allowed = [ 'x' ];" ), 'and is empty when update_theme_options() is not present, so a rename cannot silently pass' );

$toap_ts = "const THEME_OPTIONS_WRITE_ALLOWLIST = [\n  \"a_one\",\n  \"b_two\",\n  \"c_three\",\n] as const;";
assert_same( array( 'a_one', 'b_two', 'c_three' ), diviops_toap_server_list( $toap_ts ), 'the server copy is read in declaration order' );
assert_same( array(), diviops_toap_server_list( 'const SOMETHING_ELSE = [ "x" ];' ), 'and is empty when the constant is renamed' );

assert_same( array( 3, 3 ), diviops_toap_stated_counts( 'writes these 3 allowlisted keys ... only these 3 keys are applied' ), 'both prose forms of the count are found' );
assert_same( array( 10, 9 ), diviops_toap_stated_counts( 'these 10 allowlisted keys ... restricted to the 9-key write allowlist' ), 'and the self-contradiction #576 shipped is visible as two different counts' );
assert_same( array(), diviops_toap_stated_counts( 'no count stated anywhere' ), 'a description stating no count yields none, which the live half treats as a failure' );

/* -------------------------------------------------------------------------
 * 2. The live halves.
 * ---------------------------------------------------------------------- */

$toap_root   = dirname( __DIR__ );
$toap_route  = diviops_toap_route_list( (string) file_get_contents( $toap_root . '/plugins/diviops-agent/includes/trait-meta.php' ) );
$toap_server = diviops_toap_server_list( (string) file_get_contents( $toap_root . '/diviops-server/src/index.ts' ) );
$toap_counts = diviops_toap_stated_counts( (string) file_get_contents( $toap_root . '/diviops-server/src/index.ts' ) );

printf(
	"theme-options write allowlist: route %d key(s), server %d key(s), prose states %s\n",
	count( $toap_route ),
	count( $toap_server ),
	$toap_counts ? implode( '/', $toap_counts ) : 'nothing'
);

assert_true( count( $toap_route ) > 0, 'the route list was actually extracted — an empty read is a broken gate, not a pass' );
assert_true( count( $toap_server ) > 0, 'the server copy was actually extracted' );
assert_true( count( $toap_counts ) > 0, 'the tool prose states a key count somewhere' );

assert_same(
	$toap_route,
	$toap_server,
	'diviops-server/src/index.ts copies the route write allowlist exactly, in the same order — dry_run is computed from this copy, so a divergence makes the preview and the write disagree'
);

assert_same(
	array_fill( 0, count( $toap_counts ), count( $toap_route ) ),
	$toap_counts,
	'every key count stated in the tool description and parameter description matches the real allowlist length, including any stated later in the same string'
);
