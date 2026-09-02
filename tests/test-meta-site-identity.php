<?php
// SPDX-License-Identifier: MIT
/**
 * Site identity in the preflight surface (#343).
 *
 * `meta_ping` proved that *a* Divi install answered and never said which one.
 * `meta_info` reported server version, plugin versions, capabilities and
 * handshake state, and also never said which site. That is a safety gap rather
 * than a missing nicety, because `cross_env_header_apply` and
 * `cross_env_layout_apply` write layouts across environments: a misidentified
 * source or target makes those writes destructive and hard to reverse, and the
 * preflight that exists to prevent it could not answer "which site is this".
 * The session that reported it had to invent a discriminator — query
 * `variable_list` for a token known to exist on one environment and not the
 * other — to confirm a repoint had landed.
 *
 * Both tools read an endpoint they already call, so the identity block is
 * produced once and attached to both: `/handshake` (which `meta_info`
 * re-reads per call) and `/schema/settings` (which `meta_ping`'s connection
 * test already fetches). No extra round trip on either path.
 *
 * `environment_type` is ADVISORY and nothing may branch on it. WordPress
 * answers `production` whenever `WP_ENVIRONMENT_TYPE` is undefined, so a
 * staging site reports itself as production — measured on
 * staging.colleyvillelions.com, which reports `production` with the constant
 * undefined. Two assertions pin that:
 *
 *   1. Behaviourally: varying the environment type across all four of core's
 *      values changes that field and nothing else in either response. A gate,
 *      warning or write guard that consulted it would perturb something.
 *   2. Structurally: the identifier appears in shipping source only where the
 *      block is produced, typed, or described. A gate written anywhere else
 *      has to name the field, and naming it fails this test.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/**
 * Unwrap a handler return value to its payload array.
 *
 * @param mixed $response Handler return value.
 * @return array
 */
function diviops_identity_payload( $response ): array {
	$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
	if ( is_array( $data ) && isset( $data['ok'] ) && array_key_exists( 'data', $data ) ) {
		$data = $data['data'];
	}
	return is_array( $data ) ? $data : array();
}

/**
 * Call the handshake with the params it requires and return its payload.
 *
 * @return array
 */
function diviops_identity_handshake(): array {
	return diviops_identity_payload(
		DiviOps_Agent::handshake( new DiviOps_Test_Request( array( 'mcp_server_version' => '99.0.0' ) ) )
	);
}

/**
 * Call the settings reader `meta_ping`'s connection test hits, and return its payload.
 *
 * @return array
 */
function diviops_identity_settings(): array {
	return diviops_identity_payload(
		DiviOps_Agent::schema_get_settings( new DiviOps_Test_Request( array() ) )
	);
}

$GLOBALS['diviops_test_home_url']         = 'https://staging.example.test';
$GLOBALS['diviops_test_blogname']         = 'Identity Fixture';
$GLOBALS['diviops_test_wp_version']       = '6.8.1';
$GLOBALS['diviops_test_environment_type'] = 'staging';

// ── The block both preflight tools carry ─────────────────────────────────

$expected_identity = array(
	'home_url'         => 'https://staging.example.test',
	'site_name'        => 'Identity Fixture',
	'environment_type' => 'staging',
	'is_multisite'     => false,
	'wp_version'       => '6.8.1',
);

$handshake = diviops_identity_handshake();

assert_same(
	$expected_identity,
	$handshake['site_identity'] ?? null,
	'the handshake carries the identity block, so meta_info can say which site answered'
);

$settings = diviops_identity_settings();

assert_same(
	$expected_identity,
	$settings['site_identity'] ?? null,
	'the settings endpoint carries the same block, so meta_ping can say it too without a second round trip'
);

/*
 * home_url() and get_site_url() are different addresses — they diverge on any
 * install where WordPress lives in a subdirectory — and the issue names
 * home_url as the authoritative identifier. Reading the wrong one would still
 * produce a plausible URL, which is why this is asserted rather than assumed.
 */
assert_same(
	home_url(),
	$handshake['site_identity']['home_url'] ?? null,
	'home_url comes from home_url(), not from get_site_url()'
);
assert_true(
	home_url() !== get_site_url(),
	'the harness distinguishes the two addresses, so the assertion above can fail'
);

/*
 * The handshake's existing top-level `site_url` predates this block and the MCP
 * server's HandshakeResult still declares it. Adding identity must not quietly
 * retire it.
 */
assert_same(
	get_site_url(),
	$handshake['site_url'] ?? null,
	'the handshake keeps its existing site_url field'
);

// ── environment_type is advisory: nothing branches on it ─────────────────

$environment_types = array( 'production', 'staging', 'local', 'development' );
$baselines         = array();
$reported          = array();

foreach ( $environment_types as $environment_type ) {
	$GLOBALS['diviops_test_environment_type'] = $environment_type;

	foreach ( array( 'handshake' => 'diviops_identity_handshake', 'settings' => 'diviops_identity_settings' ) as $surface => $builder ) {
		$payload = $builder();

		$reported[ $surface ][ $environment_type ] = $payload['site_identity']['environment_type'] ?? null;

		// Remove the field itself; everything that remains must be identical
		// across all four environment types, on both surfaces. Compared as
		// encoded JSON rather than with ===, because the handshake casts its
		// capability map to (object) and two stdClass instances are never
		// identical to each other however equal their contents.
		unset( $payload['site_identity']['environment_type'] );
		$encoded = (string) json_encode( $payload );

		if ( ! isset( $baselines[ $surface ] ) ) {
			$baselines[ $surface ] = $encoded;
			continue;
		}

		assert_same(
			$baselines[ $surface ],
			$encoded,
			sprintf(
				'%s responds identically under environment_type=%s apart from the field itself — no gate, warning or guard consults it',
				$surface,
				$environment_type
			)
		);
	}
}

// Positive control for the invariance assertions above: a block that simply
// omitted the field would satisfy them while proving nothing.
assert_same(
	array_combine( $environment_types, $environment_types ),
	$reported['handshake'],
	'the handshake reports each environment type it was given, so the invariance check above had something to vary'
);
assert_same(
	array_combine( $environment_types, $environment_types ),
	$reported['settings'],
	'the settings endpoint reports each environment type it was given'
);

$GLOBALS['diviops_test_environment_type'] = 'production';

// ── Structural pin: only the producing, typing and describing files name it ──

$root = dirname( __DIR__ );

/**
 * Collect shipping source files under a directory, by extension.
 *
 * Test sources are excluded: a test is entitled to name the field, and this
 * file names it dozens of times.
 *
 * @param string $dir       Absolute directory to walk.
 * @param string $extension Extension without the dot.
 * @return string[] Repository-relative paths, sorted.
 */
function diviops_identity_source_files( string $dir, string $extension ): array {
	if ( ! is_dir( $dir ) ) {
		return array();
	}
	$root  = dirname( __DIR__ );
	$files = array();
	$walk  = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $walk as $entry ) {
		if ( ! $entry->isFile() || strtolower( $entry->getExtension() ) !== $extension ) {
			continue;
		}
		$path = str_replace( '\\', '/', $entry->getPathname() );
		if ( false !== strpos( $path, '/__tests__/' ) ) {
			continue;
		}
		$files[] = ltrim( substr( $path, strlen( $root ) ), '/' );
	}
	sort( $files );
	return $files;
}

$plugin_sources = diviops_identity_source_files( $root . '/plugins', 'php' );
$server_sources = diviops_identity_source_files( $root . '/diviops-server/src', 'ts' );
$scanned        = array_merge( $plugin_sources, $server_sources );

// Both halves must have been walked. A gate that derives pass/fail only from
// problems found passes while inspecting nothing, and an empty glob on either
// tree is exactly that.
assert_true(
	count( $plugin_sources ) >= 10,
	'the scan walked the plugin PHP tree (' . count( $plugin_sources ) . ' file(s))'
);
assert_true(
	count( $server_sources ) >= 10,
	'the scan walked the MCP server TypeScript tree (' . count( $server_sources ) . ' file(s))'
);

$naming = array();
foreach ( $scanned as $relative ) {
	$contents = (string) file_get_contents( $root . '/' . $relative );
	if ( false !== strpos( $contents, 'environment_type' ) ) {
		$naming[] = $relative;
	}
}
sort( $naming );

/*
 * Produced in the plugin, typed and normalized in compatibility.ts, described
 * to agents in health-tools.ts. Anywhere else means something consults it, and
 * the one thing this field must never do is decide anything: the environment it
 * names is wrong on any site that has not set WP_ENVIRONMENT_TYPE, which is the
 * default and was the observed case on a real staging host.
 */
assert_same(
	array(
		'diviops-server/src/compatibility.ts',
		'diviops-server/src/health-tools.ts',
		'plugins/diviops-agent/includes/trait-meta.php',
	),
	$naming,
	'environment_type is named only where it is produced, typed, or described — never where a decision is made'
);

// ── The tool descriptions promise what the tools now deliver ─────────────

$health_tools = (string) file_get_contents( $root . '/diviops-server/src/health-tools.ts' );

foreach ( array( 'META_PING_CONFIG', 'META_INFO_CONFIG' ) as $config ) {
	$matched = preg_match(
		'/export const ' . $config . ' = \{(.*?)\n\} as const;/s',
		$health_tools,
		$config_match
	);
	assert_same( 1, $matched, $config . ' is declared in health-tools.ts' );

	$description = $config_match[1];

	assert_true(
		false !== strpos( $description, 'home_url' ),
		$config . ' names home_url — the descriptions are quoted verbatim into agent context, and one that omits the identifying field promises less than the tool returns'
	);
	assert_true(
		false !== strpos( $description, 'environment_type' ),
		$config . ' names environment_type'
	);
	assert_true(
		false !== stripos( $description, 'advisory' ),
		$config . ' labels environment_type advisory — returning it unlabelled would mislead exactly the operators it is meant to help'
	);
}
