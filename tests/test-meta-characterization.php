<?php
// SPDX-License-Identifier: MIT
/**
 * Characterization net for `trait-meta.php` — the plugin's front door (#328).
 *
 * This is not a correctness test. It captures what the trait does today, quirks
 * and defects included, so a later edit — an upstream adoption in particular —
 * cannot change it silently. Anything pinned here that starts reporting
 * differently is a real behaviour change and wants a human decision, not an
 * updated expectation. Assertions that pin something believed to be WRONG are
 * marked `SUSPECTED DEFECT` inline; a later fix to one of those is expected to
 * fail this file, and that failure is the signal, not a regression.
 *
 * Why this file, and what it deliberately does not repeat:
 *
 *   - `site_identity()` and `code_fingerprint()` are already characterized by
 *     `tests/test-meta-site-identity.php` and
 *     `tests/test-handshake-code-fingerprint.php`. This file asserts only that
 *     the handshake still CARRIES those two keys (a key-set assertion the
 *     others do not make) and leaves their contents to those files.
 *   - `dashboard_capabilities()` / `dashboard_client_runtime()` live in
 *     `diviops-agent.php`, not this trait, and are covered by
 *     `tests/test-dashboard-capabilities.php` and
 *     `tests/test-client-runtime-report.php`. The one thing added here is the
 *     ORDERING fact those two cannot see: `record_client_runtime()` runs before
 *     the handshake's version gate, so an outdated MCP server still gets its
 *     runtime report stored.
 *
 * `meta_ping`, `meta_info` and `meta_wp_cli` are NOT in this trait. All three
 * are server-local MCP tools implemented in TypeScript
 * (`diviops-server/src/index.ts`, `diviops-server/src/wp-cli.ts`,
 * `diviops-server/src/health-tools.ts`) and registered via `registerLocalTool`,
 * which by construction has no plugin route and no capability key. `meta_ping`
 * and `meta_info` reach WordPress only through `/handshake` and
 * `/schema/settings`, both of which this file characterizes. `meta_wp_cli`
 * never touches the plugin at all — the plugin contains no
 * shell_exec/proc_open/passthru/WP_CLI::, which
 * `tests/test-dashboard-capabilities.php` already asserts and which is the
 * whole reason #123 removed WP-CLI from the dashboard list.
 *
 * @package DiviOps
 */

require_once __DIR__ . '/wp-shim.php';

/*
 * Two Divi/WordPress primitives this trait calls are absent from wp-shim.php,
 * and the shim is off-limits to this change (three agents are editing it
 * concurrently; bending a shared harness to make one file green is how a false
 * green outlives the test that produced it). Both are declared here instead,
 * guarded by function_exists so the real symbol always wins.
 *
 * Neither leaks into another test file's behaviour. `get_template_directory()`
 * has exactly one call site in the whole plugin (trait-meta.php:144) and none
 * under tests/. `et_update_option()` is called by trait-variable.php,
 * trait-global-font.php and trait-global-color.php as well as here — but the
 * suite passes today with the function undefined, which proves no existing test
 * reaches any of those call sites; a defined stub cannot change a call that
 * never happens.
 */
if ( ! function_exists( 'get_template_directory' ) ) {
	/**
	 * Active theme root. `search_icons()` joins
	 * `/includes/builder/feature/icon-manager/full_icons_list.json` onto this —
	 * the path Divi actually ships the catalog at, confirmed on the dev site.
	 */
	function get_template_directory() {
		return (string) ( $GLOBALS['diviops_test_template_dir'] ?? __DIR__ . '/.diviops-nonexistent-theme' );
	}
}

if ( ! function_exists( 'et_update_option' ) ) {
	/**
	 * Divi's option writer. Records rather than persists: this file asserts on
	 * WHAT `update_theme_options()` hands Divi (key, sanitized value, call
	 * count), which is the contract the handler owns. What Divi then does with
	 * it is Divi's.
	 */
	function et_update_option( $key, $value ) {
		$GLOBALS['diviops_test_et_options'][ (string) $key ] = $value;
		$GLOBALS['diviops_test_et_option_writes'][]          = array( (string) $key, $value );
		return true;
	}
}

$GLOBALS['diviops_test_et_options']       = array();
$GLOBALS['diviops_test_et_option_writes'] = array();

/**
 * Unwrap a handler return value to its envelope body.
 *
 * @param mixed $response Handler return value.
 * @return array
 */
function diviops_metac_body( $response ): array {
	$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
	return is_array( $data ) ? $data : array();
}

/**
 * Read a handler's WP_Error code and data without assuming it returned one.
 *
 * A handler that stops refusing where this file expects a refusal returns a
 * WP_REST_Response instead, and calling get_error_code() on that is a fatal that
 * aborts the whole file — which reads as a crashed harness rather than as the
 * assertion this file exists to make. Nulls make the failure legible instead.
 *
 * @param mixed $response Handler return value.
 * @return array{code: mixed, data: mixed}
 */
function diviops_metac_error( $response ): array {
	if ( ! is_wp_error( $response ) ) {
		return array( 'code' => null, 'data' => null );
	}
	return array( 'code' => $response->get_error_code(), 'data' => $response->get_error_data() );
}

/**
 * Call a handler on DiviOps_Agent with a request built from $params.
 *
 * @param string $method Handler name.
 * @param array  $params Request params.
 * @return mixed
 */
function diviops_metac_call( string $method, array $params = array() ) {
	return diviops_call( $method, array( new DiviOps_Test_Request( $params ) ) );
}

/**
 * Run the handshake with a Pro-extension filter callback attached, then detach it.
 *
 * The hook name is spelled out at every call site on purpose. It is one of the
 * four frozen identifiers in CLAUDE.md, and a helper that took it as a variable
 * would let a rename pass by changing one string.
 *
 * @param callable|null $contributor Filter callback, or null for a free-only site.
 * @param array         $params      Handshake request params.
 * @return array The handshake response body.
 */
function diviops_metac_handshake( ?callable $contributor = null, array $params = array( 'mcp_server_version' => '99.0.0' ) ): array {
	remove_all_filters( 'diviops_agent_handshake_extensions' );
	if ( null !== $contributor ) {
		add_filter( 'diviops_agent_handshake_extensions', $contributor );
	}
	$response = diviops_metac_call( 'handshake', $params );
	remove_all_filters( 'diviops_agent_handshake_extensions' );
	return diviops_metac_body( $response );
}

// ══ A. The capability handshake ═══════════════════════════════════════════
//
// The highest-value surface in the trait. DiviOps Agent Pro — separate,
// commercial, not forked — attaches through `class_exists( 'DiviOps_Agent' )`
// and the `diviops_agent_handshake_extensions` filter, and the published
// `@rubicontv/diviops-mcp` gates every plugin-backed tool on a key in the
// `capabilities` map. Both failure modes are SILENT: a failed capability gate
// removes a tool rather than reporting a problem, and a filter nobody fires
// simply produces a free-only handshake. Nothing errors, so nothing tells you.

// ── A1. The frozen filter name, behaviourally ────────────────────────────
//
// Asserted by attaching to the real hook AND to a plausible rename of it. A
// structural grep for the string would pass on a plugin that read the right
// name and then ignored the result; this pair cannot.

$body = diviops_metac_handshake(
	static function ( $extensions ) {
		return array( 'pro_active' => true, 'pro_version' => '2.4.0' );
	}
);
assert_same( true, $body['pro_active'] ?? null, 'a callback on diviops_agent_handshake_extensions contributes pro_active to the handshake' );
assert_same( '2.4.0', $body['pro_version'] ?? null, 'and pro_version alongside it' );

remove_all_filters( 'diviops_agent_handshake_extensions_decoy' );
add_filter(
	'diviops_agent_handshake_extensions_decoy',
	static function ( $extensions ) {
		return array( 'pro_active' => true );
	}
);
$body = diviops_metac_handshake();
assert_same(
	false,
	array_key_exists( 'pro_active', $body ),
	'a callback on any hook name OTHER than diviops_agent_handshake_extensions contributes nothing — the name is frozen because renaming it disables Pro without an error'
);
remove_all_filters( 'diviops_agent_handshake_extensions_decoy' );

// ── A2. The response shape a free-only site reports ──────────────────────
//
// Key ORDER is pinned, not just membership. The list below is the literal
// order of the `$response` array in handshake() (trait-meta.php:1861-1883);
// it is what JSON-encodes onto the wire and what `wp-client.ts` reads.

$free = diviops_metac_handshake();

assert_same(
	array(
		'compatible',
		'plugin_version',
		'code_fingerprint',
		'min_server',
		'authenticated_user',
		'site_url',
		'site_identity',
		'divi',
		'capabilities',
	),
	array_keys( $free ),
	'a free-only handshake reports exactly these nine keys, in this order — a dropped key silently costs the MCP server a fact it branches on'
);

assert_same( true, $free['compatible'] ?? null, 'compatible is true once the version gate passes' );
assert_same( DiviOps_Agent::VERSION, $free['plugin_version'] ?? null, 'plugin_version is the plugin constant' );
assert_same( DiviOps_Agent::MIN_SERVER_VERSION, $free['min_server'] ?? null, 'min_server is the plugin constant the gate compares against' );
assert_same(
	array( 'id', 'login' ),
	array_keys( (array) ( $free['authenticated_user'] ?? array() ) ),
	'authenticated_user carries id and login'
);
assert_same(
	array( 'active', 'version' ),
	array_keys( (array) ( $free['divi'] ?? array() ) ),
	'divi carries active and version'
);
/*
 * `function_exists( 'et_get_option' )` is the Divi-active probe, and that
 * primitive is deliberately unshimmed across this suite (see
 * tests/test-preset-reassign-write-safety.php). So the harness is a
 * Divi-inactive site, and this is what the handshake says about one.
 */
assert_same( false, $free['divi']['active'] ?? null, 'divi.active is false when et_get_option() is absent — the harness is a Divi-inactive site' );
assert_same( true, array_key_exists( 'version', (array) $free['divi'] ), 'divi.version is present as a key even when Divi is inactive' );
assert_same( null, $free['divi']['version'], 'divi.version is null, not the string "unknown" schema_get_settings uses for the same fact' );

// ── A3. The capability map ───────────────────────────────────────────────

assert_true( is_object( $free['capabilities'] ?? null ), 'capabilities is an object, so it JSON-encodes as {} rather than [] when empty' );

$capabilities = (array) $free['capabilities'];

assert_same(
	DiviOps_Agent::CAPABILITIES,
	array_keys( $capabilities ),
	'the capability map advertises every key in CAPABILITIES, in constant order, and nothing else'
);
assert_same(
	array(),
	array_keys( array_filter( $capabilities, static function ( $value ) { return true !== $value; } ) ),
	'every advertised capability is boolean true — the server gate reads truthiness, so a non-true value removes the tool'
);

/*
 * The assertion above compares the response to the constant, so it cannot see a
 * RENAME: both sides move together. The tripwire for that is the MCP server's
 * own gate. `registerPluginTool( "diviops_x", … )` derives its capability key as
 * `name.replace(/^diviops_/, "")` (diviops-server/src/index.ts:603-624) and
 * `requireCapability()` throws MissingCapabilityError when the key is absent —
 * which the SDK turns into a tool that is simply not there. So every
 * registerPluginTool name, minus the prefix, MUST exist in CAPABILITIES.
 *
 * Measured at authoring time: 104 registerPluginTool call sites, 123 capability
 * keys, zero missing. The surplus 19 are sub-feature and contract keys
 * (variable_create_gradient, *_backup, storage_multipath_probe_v1, …) gated
 * inside handlers rather than at registration, so the containment is one-way.
 */
$index_ts = dirname( __DIR__ ) . '/diviops-server/src/index.ts';
assert_true( is_file( $index_ts ), 'diviops-server/src/index.ts exists where this cross-check expects it' );

$matched = preg_match_all(
	'/^[ \t]*registerPluginTool\(\s*"([^"]+)"/m',
	(string) file_get_contents( $index_ts ),
	$tool_names
);
assert_true(
	is_int( $matched ) && $matched >= 100,
	'the scan found the registerPluginTool call sites (' . (int) $matched . ' of the 104 measured at authoring time) — a regex that matched nothing would certify containment while inspecting nothing'
);

$required = array();
foreach ( $tool_names[1] as $tool_name ) {
	$required[] = 0 === strpos( $tool_name, 'diviops_' ) ? substr( $tool_name, strlen( 'diviops_' ) ) : $tool_name;
}
assert_same(
	array(),
	array_values( array_diff( $required, DiviOps_Agent::CAPABILITIES ) ),
	'every capability key the MCP server gates a tool on exists in CAPABILITIES — a rename on either side makes that tool vanish with no error at all'
);

// The four meta-domain keys this trait owns, spelled out so a rename of one of
// them fails here even if the server were renamed in the same commit.
foreach ( array( 'meta_find_icon', 'meta_flush_cache', 'theme_options_update', 'schema_get_settings' ) as $key ) {
	assert_same( true, $capabilities[ $key ] ?? null, "the handshake advertises {$key}, the capability trait-meta.php's own routes are gated on" );
}

// ── A4. The extension whitelist: a filter may not overwrite a core field ──
//
// `apply_filters` returns whatever the callback returns, and handshake() copies
// only five named keys out of it. A full array_merge would let any Pro plugin —
// or any buggy third-party callback on a public hook — rewrite the fields the
// server's own compatibility logic depends on.

$hostile = static function ( $extensions ) {
	return array(
		'compatible'         => false,
		'plugin_version'     => 'HIJACKED',
		'min_server'         => '0.0.1',
		'code_fingerprint'   => 'deadbeef',
		'site_url'           => 'http://evil.test',
		'site_identity'      => array( 'home_url' => 'http://evil.test' ),
		'authenticated_user' => array( 'id' => 1, 'login' => 'admin' ),
		'divi'               => array( 'active' => true, 'version' => '9.9.9' ),
		'pro_active'         => true,
		'pro_version'        => '2.4.0',
		'available_targets'  => array( 'fluentcart' => array( 'present' => true, 'version' => '1.0.0' ) ),
		'active_modules'     => array( 'fluentcart' => true ),
		'plugins'            => array( 'fluentcart' => '1.2.3' ),
	);
};

$body = diviops_metac_handshake( $hostile );

foreach (
	array(
		'compatible'         => $free['compatible'],
		'plugin_version'     => $free['plugin_version'],
		'min_server'         => $free['min_server'],
		'code_fingerprint'   => $free['code_fingerprint'],
		'site_url'           => $free['site_url'],
	) as $protected => $expected
) {
	assert_same( $expected, $body[ $protected ] ?? null, "a filter callback cannot overwrite the handshake's {$protected}" );
}
assert_same( $free['divi'], $body['divi'] ?? null, 'a filter callback cannot overwrite the handshake\'s divi block' );
assert_same( $free['site_identity'], $body['site_identity'] ?? null, 'a filter callback cannot overwrite the handshake\'s site_identity block' );
assert_same( $free['authenticated_user'], $body['authenticated_user'] ?? null, 'a filter callback cannot overwrite the handshake\'s authenticated_user block' );

assert_same(
	array( 'pro_active', 'pro_version', 'available_targets', 'active_modules', 'plugins' ),
	array_values( array_diff( array_keys( $body ), array_keys( $free ) ) ),
	'exactly five extension keys are adopted, in the whitelist order they are copied in — the ADR-003/ADR-007 Pro surface and nothing else'
);

// ── A5. Capability merge: Free always wins a collision ───────────────────

$body = diviops_metac_handshake(
	static function ( $extensions ) {
		return array(
			'pro_active'   => true,
			'capabilities' => array(
				// A Pro-only key: must be added.
				'fluentcart_product_list' => true,
				// A collision with a Free key: Free's value must survive.
				'page_list'               => false,
			),
		);
	}
);
$merged = (array) $body['capabilities'];

assert_same( true, $merged['fluentcart_product_list'] ?? null, 'a Pro-only capability key is added to the map' );
assert_same( true, $merged['page_list'] ?? null, 'a Pro key colliding with a Free key does NOT shadow it — Free wins, so Pro cannot switch off a Free tool' );
assert_same(
	'fluentcart_product_list',
	array_key_first( $merged ),
	'the Pro contribution is merged UNDER the Free map, so Pro keys sort first — array_merge( $extensions, $response ) order (trait-meta.php:1920-1923)'
);
assert_same(
	count( DiviOps_Agent::CAPABILITIES ) + 1,
	count( $merged ),
	'the merged map is every Free key plus the one Pro key — nothing dropped on either side'
);

// ── A6. Empty maps serialize as objects, not arrays ──────────────────────
//
// PHP's json_encode emits `[]` for an empty associative array. wp-client.ts and
// any third-party MCP server read these three fields as maps uniformly, so
// array-or-map polymorphism on an empty Pro install is a parse hazard.

$body = diviops_metac_handshake(
	static function ( $extensions ) {
		return array(
			'pro_active'        => true,
			'available_targets' => array(),
			'active_modules'    => array(),
			'plugins'           => array(),
		);
	}
);
foreach ( array( 'available_targets', 'active_modules', 'plugins' ) as $map ) {
	assert_true( is_object( $body[ $map ] ?? null ), "an empty {$map} is cast to an object" );
	assert_same( '{}', (string) json_encode( $body[ $map ] ?? null ), "an empty {$map} JSON-encodes as {} rather than []" );
}

// ── A7. Filter returns that are not a usable array ───────────────────────

foreach (
	array(
		'an empty array'  => array(),
		'null'            => null,
		'a scalar string' => 'pro',
	) as $label => $return
) {
	$body = diviops_metac_handshake(
		static function ( $extensions ) use ( $return ) {
			return $return;
		}
	);
	assert_same(
		array_keys( $free ),
		array_keys( $body ),
		"a filter callback returning {$label} contributes nothing and does not disturb the free-only shape"
	);
}

// ── A8. The version gate ─────────────────────────────────────────────────
//
// `version_compare( $server_version, MIN_SERVER_VERSION, '<' )`, so the minimum
// itself passes and anything below it is refused. Boundary derived from PHP's
// version_compare semantics, not from a captured output.

$minimum = DiviOps_Agent::MIN_SERVER_VERSION;

$response = diviops_metac_call( 'handshake', array( 'mcp_server_version' => $minimum ) );
assert_true( ! is_wp_error( $response ), 'exactly MIN_SERVER_VERSION is accepted — the gate is <, not <=' );

foreach (
	array(
		'1.0.99'                 => 'a version below the minimum',
		'0.9.0'                  => 'an older major',
		''                       => 'an omitted mcp_server_version, which compares below every real version',
	) as $version => $label
) {
	$response = diviops_metac_call( 'handshake', array( 'mcp_server_version' => $version ) );
	$error    = diviops_metac_error( $response );
	assert_true( is_wp_error( $response ), "{$label} is refused" );
	assert_same( 'upgrade_required', $error['code'], "{$label} is refused with code upgrade_required" );
	assert_same( array( 'status' => 426 ), $error['data'], "{$label} is refused with HTTP 426 Upgrade Required" );
}

// A refused handshake must not run the Pro filter at all: an outdated server
// gets no Pro surface, and a Pro callback must not do work for a caller that
// is about to be told to upgrade.
$fired = 0;
remove_all_filters( 'diviops_agent_handshake_extensions' );
add_filter(
	'diviops_agent_handshake_extensions',
	static function ( $extensions ) use ( &$fired ) {
		++$fired;
		return array( 'pro_active' => true );
	}
);
diviops_metac_call( 'handshake', array( 'mcp_server_version' => '1.0.0' ) );
assert_same( 0, $fired, 'the extension filter does not fire when the version gate refuses — Pro contributes nothing to a 426' );
diviops_metac_call( 'handshake', array( 'mcp_server_version' => '99.0.0' ) );
assert_same( 1, $fired, 'the same filter DOES fire once on an accepted handshake — the count above had something to observe' );
remove_all_filters( 'diviops_agent_handshake_extensions' );

// ── A9. Client-runtime recording happens BEFORE the version gate ─────────
//
// The ordering fact tests/test-client-runtime-report.php cannot see: an MCP
// server too old to pass the gate still gets its WP-CLI report stored, so the
// dashboard can say "a client reported, and it was outdated" rather than
// reverting to unknown. Restored afterwards — the transient is process-wide.

$saved_runtime = get_transient( DiviOps_Agent::CLIENT_RUNTIME_TRANSIENT );
delete_transient( DiviOps_Agent::CLIENT_RUNTIME_TRANSIENT );

$response = diviops_metac_call(
	'handshake',
	array( 'mcp_server_version' => '0.9.0', 'client_runtime' => array( 'wp_cli' => true ) )
);
assert_true( is_wp_error( $response ), 'the outdated-server call used for this check really was refused' );

$stored = get_transient( DiviOps_Agent::CLIENT_RUNTIME_TRANSIENT );
assert_true( is_array( $stored ) && isset( $stored['wp_cli'] ), 'client_runtime is recorded even when the version gate refuses the handshake' );
assert_same( true, $stored['wp_cli']['available'] ?? null, 'the recorded report carries the reported wp_cli availability' );
assert_same( '0.9.0', $stored['wp_cli']['mcp_server_version'] ?? null, 'and the version of the server that reported it, refused or not' );

delete_transient( DiviOps_Agent::CLIENT_RUNTIME_TRANSIENT );
if ( false !== $saved_runtime ) {
	set_transient( DiviOps_Agent::CLIENT_RUNTIME_TRANSIENT, $saved_runtime, MONTH_IN_SECONDS );
}

// ══ B. schema_get_settings + the theme-options allowlists ═════════════════

$saved_et_divi = $GLOBALS['diviops_test_options']['et_divi'] ?? null;

update_option(
	'et_divi',
	array(
		// All ten read-allowlisted keys, in a deliberately scrambled order so
		// the response order below proves the allowlist drives it, not the input.
		'link_color'             => '#0a58ca',
		'body_font_size'         => '16',
		'heading_font'           => 'Poppins',
		'body_font'              => 'Inter',
		'accent_color'           => '#2ea3f2',
		'secondary_accent_color' => '#1a1a1a',
		'font_color'             => '#333333',
		'header_color'           => '#111111',
		'body_header_size'       => '30',
		'heading_font_size'      => '30',
		// Not on the read allowlist: `et_divi` also holds admin-only script
		// injection fields, which is why the surface is a whitelist and not a
		// blacklist.
		'divi_custom_css'        => 'body{}',
		'et_pb_static_css_file'  => 'on',
		// On the allowlist but non-scalar: must be dropped, not emitted.
		'heading_font_size_tablet' => array( 'nope' ),
	)
);
// A non-scalar sitting on an allowlisted key, which is the case the
// `is_scalar` guard exists for.
$GLOBALS['diviops_test_options']['et_divi']['header_color'] = array( '#111111' );

$settings = diviops_metac_body( diviops_metac_call( 'schema_get_settings' ) );
assert_same( true, $settings['ok'] ?? null, 'schema_get_settings returns the success envelope' );
$data = $settings['data'];

assert_same(
	array( 'site_identity', 'theme_options', 'site', 'builder' ),
	array_keys( $data ),
	'schema_get_settings reports exactly these four blocks, in this order'
);

assert_true( is_object( $data['theme_options'] ), 'theme_options is an object, so an install with no readable options encodes as {} not []' );
assert_same(
	array(
		'heading_font',
		'body_font',
		'accent_color',
		'secondary_accent_color',
		'font_color',
		'link_color',
		'body_header_size',
		'heading_font_size',
		'body_font_size',
	),
	array_keys( (array) $data['theme_options'] ),
	'the read surface emits allowlisted scalar keys in ALLOWLIST order, not stored order; header_color is absent because its stored value is an array, and divi_custom_css / et_pb_static_css_file are absent because they are not allowlisted at all'
);
assert_same( 'Poppins', ( (array) $data['theme_options'] )['heading_font'], 'an allowlisted scalar is passed through verbatim' );

assert_same(
	array( 'name', 'description', 'url', 'language' ),
	array_keys( $data['site'] ),
	'the site block carries name, description, url and language'
);
assert_same( get_site_url(), $data['site']['url'], 'site.url is get_site_url() — the WordPress address, NOT site_identity.home_url' );

assert_same(
	array( 'version', 'is_divi5', 'active_modules' ),
	array_keys( $data['builder'] ),
	'the builder block carries version, is_divi5 and active_modules'
);
/*
 * Both branches are asserted because which one runs depends on the invocation:
 * `php tests/run.php meta-characterization` leaves ET_BUILDER_PRODUCT_VERSION
 * undefined, while a full run has already loaded tests/divi-compat-shim.php
 * (via test-divi-compatibility.php, which sorts earlier) and defined it. A
 * constant cannot be undefined once set, so the fallback is only observable in
 * the filtered run — which is where its mutation was verified.
 */
if ( defined( 'ET_BUILDER_PRODUCT_VERSION' ) ) {
	assert_same( ET_BUILDER_PRODUCT_VERSION, $data['builder']['version'], 'builder.version is ET_BUILDER_PRODUCT_VERSION when Divi defines it' );

	/*
	 * SUSPECTED DEFECT. With the constant defined but `et_get_option()` absent,
	 * the two preflight endpoints disagree about the same fact in the same
	 * request: /handshake reports `divi.version => null` (it also requires
	 * `$divi_active`), and /schema/settings reports the constant's value (it
	 * checks only `defined()`). An operator reconciling meta_ping against
	 * meta_info sees one of them claim a Divi version on a site the other says
	 * has no Divi.
	 */
	assert_same( null, $free['divi']['version'], 'SUSPECTED DEFECT: /handshake says divi.version null while /schema/settings reports ' . ET_BUILDER_PRODUCT_VERSION . ' — the two endpoints gate the same constant on different conditions' );
} else {
	assert_same( 'unknown', $data['builder']['version'], 'builder.version is the literal string "unknown" when ET_BUILDER_PRODUCT_VERSION is undefined — not null, and not an empty string' );
}

/*
 * SUSPECTED DEFECT. `is_divi5` is the literal `true` (trait-meta.php:52),
 * computed from nothing. The same response reports `builder.version =>
 * "unknown"`, and the handshake in section A reports `divi.active => false`,
 * on this very same Divi-inactive install — so a consumer that branches on
 * is_divi5 is told Divi 5 is present on a site with no Divi at all. Pinned as
 * it stands; a fix that derives it from ET_BUILDER_PRODUCT_VERSION (or drops
 * the field) is expected to fail this assertion, and that failure is the point.
 */
assert_same( true, $data['builder']['is_divi5'], 'SUSPECTED DEFECT: builder.is_divi5 is hardcoded true even on a site where builder.version is "unknown" and the handshake reports divi.active false' );

// ── B2. Read allowlist vs write allowlist ────────────────────────────────
//
// Not a defect: `diviops_theme_options_update`'s own tool description
// (diviops-server/src/index.ts:1764) states the write allowlist is 9 keys and
// "is NOT identical to diviops_schema_get_settings's 10-key read allowlist,
// which also includes body_header_size". Pinned because it is a documented
// asymmetry an upstream adoption could quietly erase in either direction.

$GLOBALS['diviops_test_et_option_writes'] = array();
$body = diviops_metac_body(
	diviops_metac_call(
		'update_theme_options',
		array(
			'options' => array(
				'heading_font'      => '  Open   Sans  ',
				'body_header_size'  => '30',
				'divi_custom_css'   => 'body{}',
				'link_color'        => array( '#fff' ),
			),
		)
	)
);

assert_same(
	array( 'heading_font' ),
	array_keys( $body['updated'] ),
	'the write allowlist accepts heading_font, drops body_header_size (readable but not writable), drops the unlisted divi_custom_css, and drops the non-scalar link_color'
);
assert_same(
	'Open Sans',
	$body['updated']['heading_font'],
	'the value is sanitize_text_field()-ed: runs of [\r\n\t ] collapse to one space and the result is trimmed (wp-includes/formatting.php:5730-5732)'
);
assert_same(
	array( array( 'heading_font', 'Open Sans' ) ),
	$GLOBALS['diviops_test_et_option_writes'],
	'exactly one et_update_option() call is made, carrying the sanitized value — a dropped key must not reach Divi at all'
);

/*
 * The response body is NOT the standardized { ok, data, error } envelope every
 * other handler in this trait returns — it is a bare { success, updated,
 * message }, normalized into an envelope on the server side rather than here.
 * Pinned as an inconsistency, not corrected.
 */
assert_same(
	array( 'success', 'updated', 'message' ),
	array_keys( $body ),
	'update_theme_options returns a bare { success, updated, message } body, not the { ok, data } envelope the rest of the trait uses'
);
assert_same( '1 option(s) updated.', $body['message'], 'the message counts the options actually written, not the options submitted' );

// Nothing allowlisted: a 0-count success rather than a refusal.
$GLOBALS['diviops_test_et_option_writes'] = array();
$body = diviops_metac_body( diviops_metac_call( 'update_theme_options', array( 'options' => array( 'divi_custom_css' => 'body{}' ) ) ) );
assert_same( array(), $body['updated'], 'a request of entirely unlisted keys updates nothing' );
assert_same( '0 option(s) updated.', $body['message'], 'and reports success with a zero count rather than refusing' );
assert_same( array(), $GLOBALS['diviops_test_et_option_writes'], 'and makes no et_update_option() call at all' );

// `options` must be an array. `null` (the param omitted) lands here too.
foreach ( array( 'omitted' => array(), 'a string' => array( 'options' => 'heading_font' ) ) as $label => $params ) {
	$response = diviops_metac_call( 'update_theme_options', $params );
	$error    = diviops_metac_error( $response );
	assert_true( is_wp_error( $response ), "options {$label} is refused" );
	assert_same( 'invalid_options', $error['code'], "options {$label} is refused with code invalid_options" );
	assert_same( array( 'status' => 400 ), $error['data'], "options {$label} is refused with HTTP 400" );
}

/*
 * The admin gate, checked before the payload is even looked at. The seam is
 * wp-shim.php's `$GLOBALS['diviops_test_denied_caps']`, which denies a named
 * capability outright — so this fails if the handler stops asking for
 * manage_options, including by asking for a weaker capability instead.
 */
$GLOBALS['diviops_test_denied_caps'] = array( 'manage_options' );
$response = diviops_metac_call( 'update_theme_options', array( 'options' => array( 'heading_font' => 'Inter' ) ) );
$GLOBALS['diviops_test_denied_caps'] = array();
$error = diviops_metac_error( $response );

assert_true( is_wp_error( $response ), 'update_theme_options refuses a caller without manage_options' );
assert_same( 'forbidden', $error['code'], 'the refusal code is forbidden' );
assert_same( array( 'status' => 403 ), $error['data'], 'the refusal is HTTP 403' );

if ( null === $saved_et_divi ) {
	delete_option( 'et_divi' );
} else {
	update_option( 'et_divi', $saved_et_divi );
}

// ══ C. search_icons ═══════════════════════════════════════════════════════

/**
 * Build a throwaway theme tree holding an icon catalog at Divi's own path.
 *
 * @param string|null $json Raw JSON to write, or null to omit the file entirely.
 * @return string Theme root.
 */
function diviops_metac_theme( ?string $json ): string {
	$root = sys_get_temp_dir() . '/diviops-meta-char-' . bin2hex( random_bytes( 8 ) );
	$dir  = $root . '/includes/builder/feature/icon-manager';
	mkdir( $dir, 0700, true );
	if ( null !== $json ) {
		file_put_contents( $dir . '/full_icons_list.json', $json );
	}
	return $root;
}

/**
 * Remove a fixture tree.
 *
 * @param string $root Tree root.
 */
function diviops_metac_rmtree( string $root ): void {
	if ( ! is_dir( $root ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $root );
}

/*
 * The fixture records copy the shape of Divi's real catalog, read from
 * wp-content/themes/Divi/includes/builder/feature/icon-manager/full_icons_list.json
 * on the dev site: 1,989 records, keys { search_terms, unicode, name, styles,
 * is_divi_icon, font_weight }, of which 380 carry is_divi_icon true. The first
 * record there is exactly the "Arrow Up" entry reproduced below.
 *
 * 60 Font Awesome records, because the limit clamp is 50 and a fixture smaller
 * than the clamp cannot observe it.
 */
$icons = array(
	array(
		'search_terms' => 'arrow up direction previous collapse',
		'unicode'      => '&#x21;',
		'name'         => 'Arrow Up',
		'styles'       => array( 'divi', 'line' ),
		'is_divi_icon' => true,
		'font_weight'  => 400,
	),
	array(
		'search_terms' => 'widget star favourite',
		'unicode'      => '&#xe031;',
		'name'         => 'Divi Star',
		'styles'       => array( 'divi' ),
		'is_divi_icon' => true,
		'font_weight'  => 700,
	),
	// No font_weight, no styles: pins both defaults.
	array(
		'search_terms' => 'widget cog settings',
		'unicode'      => '&#xe037;',
		'name'         => 'Divi Cog',
		'is_divi_icon' => true,
	),
	// Not an array: skipped without disturbing the walk.
	'not-an-icon',
);
for ( $i = 1; $i <= 60; $i++ ) {
	$icons[] = array(
		'search_terms' => 'widget generic',
		'unicode'      => '&#xf0' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ) . ';',
		'name'         => 'FA Icon ' . $i,
		'styles'       => array( 'solid' ),
		'is_divi_icon' => false,
		'font_weight'  => 900,
	);
}

$theme_root = diviops_metac_theme( (string) json_encode( $icons ) );
$GLOBALS['diviops_test_template_dir'] = $theme_root;

/**
 * Call search_icons and return its envelope body.
 *
 * @param array $params Request params.
 * @return array
 */
function diviops_metac_icons( array $params ): array {
	return diviops_metac_body( diviops_metac_call( 'search_icons', $params ) );
}

// ── C1. type validation ──────────────────────────────────────────────────

foreach ( array( 'all', 'fa', 'divi', 'ALL', 'Divi' ) as $type ) {
	$body = diviops_metac_icons( array( 'q' => 'arrow', 'type' => $type ) );
	assert_same( true, $body['ok'] ?? null, "type={$type} is accepted — sanitize_key() lowercases before the membership check" );
}
foreach ( array( 'bogus', '', 'fa-solid' ) as $type ) {
	$response = diviops_metac_call( 'search_icons', array( 'q' => 'arrow', 'type' => $type ) );
	$body     = diviops_metac_body( $response );
	assert_same( false, $body['ok'] ?? null, "type='{$type}' is refused" );
	assert_same( 'invalid_input', $body['error']['code'] ?? null, "type='{$type}' is refused with invalid_input" );
	assert_same( 400, $response->get_status(), "type='{$type}' is refused with HTTP 400" );
	assert_same( false, isset( $body['error']['hint'] ), 'the type refusal carries no hint' );
}

// ── C2/C3. catalog file problems ─────────────────────────────────────────

$missing_theme = diviops_metac_theme( null );
$GLOBALS['diviops_test_template_dir'] = $missing_theme;
$response = diviops_metac_call( 'search_icons', array( 'q' => 'arrow' ) );
$body     = diviops_metac_body( $response );
assert_same( 'not_found', $body['error']['code'] ?? null, 'a missing full_icons_list.json is not_found' );
assert_same( 404, $response->get_status(), 'a missing catalog is HTTP 404' );
assert_true( isset( $body['error']['hint'] ), 'the not_found refusal carries a remediation hint naming the expected path' );

$broken_theme = diviops_metac_theme( '{ this is not json' );
$GLOBALS['diviops_test_template_dir'] = $broken_theme;
$response = diviops_metac_call( 'search_icons', array( 'q' => 'arrow' ) );
$body     = diviops_metac_body( $response );
assert_same( 'divi_error', $body['error']['code'] ?? null, 'an undecodable catalog is divi_error' );
assert_same( 500, $response->get_status(), 'an undecodable catalog is HTTP 500 — a server-side fault, not the caller\'s' );

$GLOBALS['diviops_test_template_dir'] = $theme_root;

// ── C4. matching, result shape, filtering, limits ────────────────────────

$body = diviops_metac_icons( array( 'q' => 'ARROW' ) );
assert_same( 'arrow', $body['data']['query'], 'the reported query is the lowercased, sanitized input' );
assert_same( 1, $body['data']['count'], 'count is the number of results returned' );
assert_same(
	array(
		array(
			'name'    => 'Arrow Up',
			'unicode' => '&#x21;',
			'type'    => 'divi',
			'weight'  => 400,
			'styles'  => array( 'divi', 'line' ),
		),
	),
	$body['data']['results'],
	'a result record is exactly { name, unicode, type, weight, styles }, in that order, and the query matched search_terms case-insensitively'
);

$body = diviops_metac_icons( array( 'q' => 'divi cog' ) );
assert_same(
	array(
		array(
			'name'    => 'Divi Cog',
			'unicode' => '&#xe037;',
			'type'    => 'divi',
			'weight'  => 400,
			'styles'  => array(),
		),
	),
	$body['data']['results'],
	'the search haystack is "search_terms name", so a query spanning both fields matches; weight defaults to 400 and styles to [] when the record omits them'
);

$body = diviops_metac_icons( array( 'q' => 'widget', 'type' => 'divi' ) );
assert_same( 2, $body['data']['count'], 'type=divi returns only records with is_divi_icon truthy' );

$body = diviops_metac_icons( array( 'q' => 'widget', 'type' => 'fa' ) );
assert_same( 10, $body['data']['count'], 'type=fa excludes the divi records, and the default limit is 10' );
assert_same( 'fa', $body['data']['results'][0]['type'], 'a non-divi record reports type fa' );

$body = diviops_metac_icons( array( 'q' => 'widget', 'type' => 'fa', 'limit' => 3 ) );
assert_same( 3, $body['data']['count'], 'an explicit limit is honoured' );

$body = diviops_metac_icons( array( 'q' => 'widget', 'type' => 'fa', 'limit' => 999 ) );
assert_same( 50, $body['data']['count'], 'limit is clamped to 50 — the fixture holds 60 matching records so the clamp is observable' );

/*
 * QUIRK. The `count( $results ) >= $limit` break is evaluated once per catalog
 * record regardless of whether that record matched, so limit=0 satisfies it on
 * the very first record and the search returns nothing at all.
 */
$body = diviops_metac_icons( array( 'q' => 'widget', 'limit' => 0 ) );
assert_same( 0, $body['data']['count'], 'QUIRK: limit=0 returns zero results — the break is evaluated per catalog record, not per match, so it fires before anything can accumulate' );

/*
 * QUIRK. absint() is `abs( (int) $maybeint )` (wp-includes/load.php:1468), not
 * a clamp at zero, so a negative limit becomes its magnitude rather than being
 * refused or floored.
 */
$body = diviops_metac_icons( array( 'q' => 'widget', 'limit' => -5 ) );
assert_same( 5, $body['data']['count'], 'QUIRK: limit=-5 returns FIVE results — absint() takes the absolute value, so a sign error silently becomes a valid limit' );

/*
 * SUSPECTED DEFECT, and a version-dependent one. `q` is the only input on this
 * handler that is never validated, and it reaches `strpos( $search, $query )`
 * with an empty needle, which PHP changed underneath the plugin:
 *
 *   - PHP 8.0+ made an empty needle legal. strpos returns int 0, and 0 !== false,
 *     so EVERY record matches and the caller silently receives the first N
 *     records of the catalog instead of a refusal.
 *   - PHP 7.4 — this plugin's own floor, and a version CI still builds against —
 *     emits `Warning: strpos(): Empty needle` and returns false, so NOTHING
 *     matches and the call returns an empty result set plus one warning per
 *     catalog record.
 *
 * Same request, two different answers and two different log volumes across the
 * supported range. Both are pinned. The 7.4 expectations were read off CI's own
 * PHP 7.4 job (run 33657974910), not guessed: that job is what surfaced the
 * split in the first place.
 *
 * The warnings are captured rather than printed, so the suite's output stays
 * clean on 7.4 — and counting them turns the noise into an assertion about how
 * far the walk got.
 */
$empty_q_warnings = array();
set_error_handler(
	static function ( $errno, $errstr ) use ( &$empty_q_warnings ) {
		$empty_q_warnings[] = $errstr;
		return true;
	}
);
$body = diviops_metac_icons( array() );
restore_error_handler();

assert_same( '', $body['data']['query'], 'an omitted q sanitizes to the empty string' );

$results   = $body['data']['results'];
$first_hit = isset( $results[0]['name'] ) ? $results[0]['name'] : null;

if ( PHP_VERSION_ID >= 80000 ) {
	assert_same( 10, $body['data']['count'], 'SUSPECTED DEFECT (PHP 8+): an omitted q matches every icon — strpos with an empty needle returns 0, not false — and silently returns the first 10 records rather than refusing' );
	assert_same( 'Arrow Up', $first_hit, 'and those records are simply the head of the catalog, in file order' );
	assert_same( array(), $empty_q_warnings, 'PHP 8 raises nothing while doing it, so the only sign is the wrong result' );
} else {
	assert_same( 0, $body['data']['count'], 'SUSPECTED DEFECT (PHP 7.4): the same omitted q returns NOTHING — strpos refuses an empty needle and returns false — so the handler answers differently on the plugin\'s own PHP floor than on PHP 8' );
	assert_same( null, $first_hit, 'there is no first result to read on 7.4' );
	assert_same(
		count( $icons ) - 1,
		count( $empty_q_warnings ),
		'and it raises one "Empty needle" warning per ARRAY record in the catalog (' . ( count( $icons ) - 1 ) . ' of the ' . count( $icons ) . ' fixture entries; the non-array entry is skipped before the strpos) — on Divi\'s real 1,989-record catalog that is a warning flood per call'
	);
	assert_same(
		array(),
		array_values( array_filter( $empty_q_warnings, static function ( $warning ) { return false === strpos( $warning, 'Empty needle' ); } ) ),
		'every captured warning is the empty-needle one, so the count above is not padded by an unrelated notice'
	);
}

// ══ D. flush_static_cache — refusals and the missing-root contract ════════
//
// wp-shim.php fixes WP_CONTENT_DIR at a deliberately non-existent path, so
// resolve_et_cache_root() resolves to a cache root that is not there. That is
// exactly the state the handler's documented idempotency clause covers
// ("Missing cache root returns 200 with empty flushed list"), and it is the
// state every assertion in this section runs against.

/**
 * Call flush_static_cache and return the response plus its body.
 *
 * @param array $params Request params.
 * @return array{0: WP_REST_Response, 1: array}
 */
function diviops_metac_flush( array $params ): array {
	$response = diviops_metac_call( 'flush_static_cache', $params );
	return array( $response, diviops_metac_body( $response ) );
}

// ── D1. Selector arithmetic ──────────────────────────────────────────────

list( $response, $body ) = diviops_metac_flush( array() );
assert_same( 'invalid_input', $body['error']['code'] ?? null, 'no selector is invalid_input' );
assert_same( 400, $response->get_status(), 'no selector is HTTP 400' );
assert_same( 'Exactly one selector required: post_id, all, or after.', $body['error']['message'] ?? null, 'the message disambiguates which selector check failed — the namespaced meta_flush_cache.* selector codes were retired' );
assert_true( isset( $body['error']['hint'] ), 'the no-selector refusal carries a hint listing the three selectors' );

/*
 * QUIRK. `all` is counted through rest_sanitize_boolean, so `all: false` and
 * `all: "0"` are indistinguishable from omitting it — a caller who explicitly
 * asks for "not all" is told they passed no selector at all.
 */
foreach ( array( false, '0', 'false' ) as $falsey ) {
	list( , $body ) = diviops_metac_flush( array( 'all' => $falsey ) );
	assert_same(
		'Exactly one selector required: post_id, all, or after.',
		$body['error']['message'] ?? null,
		'QUIRK: a falsey `all` counts as NO selector (rest_sanitize_boolean, wp-includes/rest-api.php), so it is refused with the no-selector message rather than a "did you mean all:true"'
	);
}

foreach (
	array(
		array( 'post_id' => 12, 'all' => true ),
		array( 'post_id' => 12, 'after' => 1700000000 ),
		array( 'all' => true, 'after' => 1700000000 ),
		array( 'post_id' => 12, 'all' => true, 'after' => 1700000000 ),
	) as $combo
) {
	list( $response, $body ) = diviops_metac_flush( $combo );
	assert_same( 'Only one of post_id, all, after may be provided per call.', $body['error']['message'] ?? null, 'two or more selectors are refused: ' . implode( '+', array_keys( $combo ) ) );
	assert_same( 400, $response->get_status(), 'a multi-selector call is HTTP 400' );
	assert_same( false, isset( $body['error']['hint'] ), 'the multi-selector refusal carries no hint' );
}

// ── D2. Dynamic-assets cleanup preconditions ─────────────────────────────

list( $response, $body ) = diviops_metac_flush( array( 'post_id' => 12, 'cleanup_canvas_refs' => true ) );
assert_same( 'cleanup_canvas_refs requires cleanup_dynamic_assets: true.', $body['error']['message'] ?? null, 'the opt-in canvas meta key cannot be deleted without enabling dynamic-assets cleanup first' );
assert_same( 400, $response->get_status(), 'that precondition failure is HTTP 400' );

list( $response, $body ) = diviops_metac_flush( array( 'after' => 1700000000, 'cleanup_dynamic_assets' => true ) );
assert_same( 'Dynamic-assets postmeta cleanup is only supported with post_id or all selectors.', $body['error']['message'] ?? null, 'after mode cannot carry dynamic-assets cleanup — it has no defensible dry-run target list' );
assert_same( 400, $response->get_status(), 'that combination is HTTP 400' );

// ── D3. Selector-shape checks run AFTER the cache-root/writability gate ──
//
// QUIRK, pinned as an ordering fact. `post_id: 0` and `after: 0` are counted as
// selectors (the check is `null !== $raw`), so their range validation happens
// only inside the per-mode branch, below resolve_et_cache_root() and the
// writability gate. On a site whose et-cache is present but unwritable the
// caller would be told meta_flush_cache.unwritable for a call that was invalid
// input before the filesystem was ever consulted.

list( $response, $body ) = diviops_metac_flush( array( 'post_id' => 0 ) );
assert_same( 'post_id must be a positive integer.', $body['error']['message'] ?? null, 'post_id 0 counts as a selector and is rejected by the per-mode range check, not the selector count' );
assert_same( 400, $response->get_status(), 'a non-positive post_id is HTTP 400' );

/*
 * SUSPECTED DEFECT. `post_id` is normalized with absint(), which is
 * `abs( (int) $maybeint )` (wp-includes/load.php:1468) — so `-3` does not fail
 * the `<= 0` check, it becomes `3`, and the flush silently operates on a
 * different post than the caller named. `after` immediately below is
 * normalized with intval() instead and DOES refuse its negative, so the two
 * selectors on the same handler disagree about what a negative means.
 */
list( $response, $body ) = diviops_metac_flush( array( 'post_id' => -3 ) );
assert_same( 200, $response->get_status(), 'SUSPECTED DEFECT: post_id -3 is not refused' );
assert_same( array( 3 ), $body['data']['missing'] ?? null, 'SUSPECTED DEFECT: absint() turns post_id -3 into post 3, so a sign error silently retargets the flush at a real post instead of being rejected' );

list( $response, $body ) = diviops_metac_flush( array( 'after' => 0 ) );
assert_same( 'after must be a positive unix timestamp.', $body['error']['message'] ?? null, 'after 0 counts as a selector and is rejected by the per-mode range check' );
assert_same( 400, $response->get_status(), 'a non-positive after is HTTP 400' );

list( , $body ) = diviops_metac_flush( array( 'after' => -5 ) );
assert_same( 'after must be a positive unix timestamp.', $body['error']['message'] ?? null, 'a negative after IS refused — intval() preserves the sign where post_id\'s absint() does not' );

// ── D4. The missing-root idempotency contract ────────────────────────────

$cache_root = diviops_call_static( 'resolve_et_cache_root' );
assert_same( WP_CONTENT_DIR . '/et-cache', $cache_root, 'with no Divi present the cache root falls back to WP_CONTENT_DIR/et-cache' );
assert_same( false, is_dir( $cache_root ), 'and that root does not exist in this harness, which is the state the assertions below characterize' );

list( $response, $body ) = diviops_metac_flush( array( 'post_id' => 4242 ) );
assert_same( 200, $response->get_status(), 'a post_id flush against a missing cache root succeeds rather than erroring — the documented idempotency clause' );
assert_same(
	array( 'mode', 'backend', 'cache_root', 'flushed', 'missing', 'files_freed', 'bytes_freed' ),
	array_keys( $body['data'] ),
	'the fs_fallback post_id payload carries exactly these seven fields'
);
assert_same( 'post_id', $body['data']['mode'], 'mode echoes the selector used' );
assert_same( 'fs_fallback', $body['data']['backend'], 'backend is fs_fallback when ET_Core_PageResource is absent' );
assert_same( array(), $body['data']['flushed'], 'nothing is reported flushed' );
assert_same( array( 4242 ), $body['data']['missing'], 'and the id is reported missing — flushed and missing are mutually exclusive, so a caller can tell "cleared" from "was not there"' );
assert_same( 0, $body['data']['files_freed'], 'files_freed is 0' );
assert_same( 0, $body['data']['bytes_freed'], 'bytes_freed is 0' );

list( $response, $body ) = diviops_metac_flush( array( 'all' => true ) );
assert_same( 200, $response->get_status(), 'an all flush against a missing cache root succeeds' );
assert_same(
	array( 'mode', 'backend', 'cache_root', 'flushed', 'skipped', 'files_freed', 'bytes_freed', 'non_post_files_freed', 'non_post_bytes_freed' ),
	array_keys( $body['data'] ),
	'the fs_fallback all payload carries exactly these nine fields — note it reports `skipped`, which the post_id payload does not, and `missing`, which it does not'
);
assert_same( array(), $body['data']['flushed'], 'no post dirs are reported flushed' );
assert_same( 0, $body['data']['non_post_files_freed'], 'the non-post sweep reports zero on a missing root rather than failing' );

list( $response, $body ) = diviops_metac_flush( array( 'after' => 1700000000 ) );
assert_same( 200, $response->get_status(), 'an after flush against a missing cache root succeeds' );
assert_same(
	array( 'mode', 'backend', 'cache_root', 'after', 'flushed', 'skipped', 'files_freed', 'bytes_freed' ),
	array_keys( $body['data'] ),
	'the fs_fallback after payload carries exactly these eight fields, and echoes the cutoff back as `after`'
);
assert_same( 1700000000, $body['data']['after'], 'the cutoff is echoed as an int' );

// ── D5. dry_run plans ────────────────────────────────────────────────────

list( $response, $body ) = diviops_metac_flush( array( 'post_id' => 4242, 'dry_run' => true ) );
assert_same( 200, $response->get_status(), 'a dry-run plan is a 200' );
assert_same( true, $body['data']['dry_run'], 'the payload declares dry_run' );
assert_same(
	array(
		array(
			'kind'   => 'meta.flush_cache',
			'target' => 'post#4242',
			'before' => array( 'files' => 0, 'bytes' => 0 ),
		),
	),
	$body['data']['plan']['changes'],
	'the post_id plan describes one change keyed meta.flush_cache, targeting post#<id>, carrying the pre-flush snapshot'
);
assert_same(
	'Would flush et-cache for post #4242 (0 file(s), 0 bytes; backend=fs_fallback).',
	$body['data']['plan']['summary'],
	'the summary names the target, the snapshot and the backend that would run'
);
assert_same( false, isset( $body['data']['plan']['warnings'] ), 'a plan with nothing to warn about omits the warnings key entirely' );

list( , $body ) = diviops_metac_flush( array( 'after' => 1700000000, 'dry_run' => true ) );
assert_same(
	array( 'after-mode dry_run reports the cutoff only — accurate file count requires the live mtime walk.' ),
	$body['data']['plan']['warnings'],
	'the after-mode plan always warns that it cannot count files without performing the sweep'
);
assert_same(
	array(
		array(
			'kind'   => 'meta.flush_cache',
			'target' => 'et-cache/after',
			'after'  => array( 'after_ts' => 1700000000 ),
		),
	),
	$body['data']['plan']['changes'],
	'and describes the cutoff on an `after` key rather than a `before` snapshot'
);

// ══ E. is_divi_css_basename — the delete-safety filter ════════════════════
//
// This predicate decides what the cache sweeps are allowed to unlink, so a
// change that widens it deletes files Divi did not write. It mirrors Divi's own
// ET_Core_PageResource::_is_valid_divi_css_file, which reads (verbatim, from
// wp-content/themes/Divi/core/components/PageResource.php:1694-1696):
//
//     $basename = basename( $file_path );
//     return strpos( $basename, 'et-' ) === 0
//         && ( str_ends_with( $basename, '.css' ) || str_ends_with( $basename, '.min.css' ) );
//
// Expected values below come from that rule, evaluated by hand — not from
// running the plugin's copy and recording what it said.

$css_cases = array(
	// Divi's own compiled output.
	'et-core-unified-42.min.css'      => true,
	'et-core-unified-tb-9-42.css'     => true,
	'et-divi-dynamic-42.css'          => true,
	// Prefix present, suffix present, nothing in between: the rule says yes.
	'et-.css'                         => true,
	// Prefix present, suffix absent.
	'et-core-unified-42'              => false,
	'et-core-unified-42.css.bak'      => false,
	'et-notes.txt'                    => false,
	// Suffix present, prefix absent — including a prefix that merely CONTAINS
	// 'et-'. `strpos(...) === 0` is a starts-with, not a contains.
	'style.css'                       => false,
	'xet-core-unified-42.css'         => false,
	'theme-et-core.min.css'           => false,
	// The sibling files the sweeps must preserve.
	'.cache-cleared-at'               => false,
	'DONOTCACHEPAGE'                  => false,
	'et-core-unified-42.data'         => false,
	// Case-sensitive on both halves.
	'ET-core-unified-42.css'          => false,
	'et-core-unified-42.CSS'          => false,
);

foreach ( $css_cases as $basename => $expected ) {
	assert_same(
		$expected,
		diviops_call_static( 'is_divi_css_basename', array( $basename ) ),
		sprintf(
			'is_divi_css_basename(%s) is %s, matching Divi\'s _is_valid_divi_css_file (PageResource.php:1694)',
			var_export( $basename, true ),
			$expected ? 'true' : 'false'
		)
	);
}

// ══ F. dynamic_assets_postmeta_keys — the opt-in second key ══════════════
//
// Both key names are Divi's, listed together in
// ET_Core_PageResource::clear_post_meta_caches (PageResource.php:1622-1631).
// The plugin deletes only the first by default because the canvas key is only
// appropriate when canvas / off-canvas references are affected.

assert_same(
	array( '_divi_dynamic_assets_cached_feature_used' ),
	diviops_call_static( 'dynamic_assets_postmeta_keys', array( false ) ),
	'without the canvas opt-in, only the feature-used key is targeted'
);
assert_same(
	array( '_divi_dynamic_assets_cached_feature_used', '_divi_dynamic_assets_canvases_used' ),
	diviops_call_static( 'dynamic_assets_postmeta_keys', array( true ) ),
	'the canvas key is appended, never substituted, when the opt-in is set'
);

// ══ G. The read-only cache-inspection helpers ════════════════════════════
//
// `flushed`, `skipped`, `files_freed` and `bytes_freed` are all computed from
// these four helpers, and each takes its cache root as a parameter — so they
// can be driven against a real tree without touching WP_CONTENT_DIR. The
// DELETING helpers cannot: delete_empty_divi_cache_dir() calls
// init_wp_filesystem(), which does `require_once ABSPATH . 'wp-admin/includes/
// file.php'` when WP_Filesystem() is undefined, and the shim's ABSPATH is
// tests/ where that file does not exist. See this file's PR for the gap.

$tree = sys_get_temp_dir() . '/diviops-meta-cache-' . bin2hex( random_bytes( 8 ) );
mkdir( $tree . '/42/nested', 0700, true );
mkdir( $tree . '/7', 0700, true );
mkdir( $tree . '/global', 0700, true );
file_put_contents( $tree . '/42/et-core-unified-42.min.css', str_repeat( 'a', 100 ) );
file_put_contents( $tree . '/42/nested/et-core-unified-tb-9-42.css', str_repeat( 'b', 50 ) );
file_put_contents( $tree . '/42/et-core-unified-42.data', str_repeat( 'c', 10 ) );
file_put_contents( $tree . '/7/et-core-unified-7.min.css', str_repeat( 'd', 25 ) );
file_put_contents( $tree . '/global/et-global.css', str_repeat( 'e', 5 ) );
file_put_contents( $tree . '/.cache-cleared-at', '0' );
// A numeric-named FILE, not a directory: must not be mistaken for a post dir.
file_put_contents( $tree . '/900', 'not a dir' );

$post_ids = diviops_call_static( 'et_cache_numeric_post_ids', array( $tree ) );
sort( $post_ids );
assert_same(
	array( 7, 42 ),
	$post_ids,
	'only numeric-named SUBDIRECTORIES count as post dirs — a non-numeric dir (global/), a dotfile, and a numeric-named regular file are all skipped'
);
assert_same(
	array(),
	diviops_call_static( 'et_cache_numeric_post_ids', array( $tree . '/does-not-exist' ) ),
	'a missing cache root enumerates to an empty list rather than erroring'
);

$snapshot = diviops_call_static( 'et_cache_dir_snapshot', array( $tree, 42 ) );
assert_same(
	array( 'existed' => true, 'files' => 3, 'bytes' => 160 ),
	$snapshot,
	'the per-post snapshot walks DESCENDANTS, not just direct children (100 + 50 + 10 bytes across two levels), and counts non-CSS siblings toward the reported total'
);
assert_same(
	array( 'existed' => false, 'files' => 0, 'bytes' => 0 ),
	diviops_call_static( 'et_cache_dir_snapshot', array( $tree, 999 ) ),
	'a post with no cache dir snapshots as existed:false with zero counts — this is what becomes `missing` in the response'
);

$total = diviops_call_static( 'et_cache_total_snapshot', array( $tree ) );
sort( $total['post_ids'] );
assert_same(
	array( 'files' => 4, 'bytes' => 185, 'post_ids' => array( 7, 42 ) ),
	array( 'files' => $total['files'], 'bytes' => $total['bytes'], 'post_ids' => $total['post_ids'] ),
	'the site-wide snapshot sums the numeric post dirs only — global/ and the root-level files are not counted, which is why bytes_freed for `all` is documented as a lower bound'
);

/*
 * The reason et_cache_dir_latest_mtime() walks files instead of reading the
 * directory's own mtime: Divi rewrites compiled CSS in place via put_contents()
 * on deterministic filenames, which bumps the FILE mtime and leaves the parent
 * directory's mtime untouched (a directory's mtime only moves on create /
 * delete / rename inside it). A helper that read the dir alone would report a
 * re-rendered page as untouched and drop it into `skipped`.
 */
$dir_mtime  = 1600000000;
$file_mtime = 1700000000;
touch( $tree . '/7/et-core-unified-7.min.css', $file_mtime );
touch( $tree . '/7', $dir_mtime );
clearstatcache();

assert_same(
	$dir_mtime,
	filemtime( $tree . '/7' ),
	'the fixture really does have a directory mtime older than the file inside it, so the assertion below has something to observe'
);
assert_same(
	$file_mtime,
	diviops_call_static( 'et_cache_dir_latest_mtime', array( $tree, 7 ) ),
	'the latest mtime is taken across the dir AND its contents, so an in-place CSS rewrite is seen even though the parent dir mtime did not move'
);
assert_same(
	false,
	diviops_call_static( 'et_cache_dir_latest_mtime', array( $tree, 999 ) ),
	'a missing post dir reports false, which the after-mode loop reads as "skip", not as "mtime 0"'
);

// ── Teardown ─────────────────────────────────────────────────────────────

diviops_metac_rmtree( $tree );
diviops_metac_rmtree( $theme_root );
diviops_metac_rmtree( $missing_theme );
diviops_metac_rmtree( $broken_theme );
unset( $GLOBALS['diviops_test_template_dir'] );
$GLOBALS['diviops_test_et_options']       = array();
$GLOBALS['diviops_test_et_option_writes'] = array();
remove_all_filters( 'diviops_agent_handshake_extensions' );
