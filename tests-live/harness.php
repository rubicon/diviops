<?php
/**
 * Live-WP integration harness (#20).
 *
 * Shared by every tests-live/test-*.php file. Deliberately independent of
 * tests/run.php and its DiviOps_Test_Runner — that suite is a fast, hermetic,
 * always-on gate with no WordPress and no network. This one is the opposite: it
 * makes real HTTP calls against a real WordPress install, and its whole reason to
 * exist is three bugs (#28's namespaced Safe SVG class, #36's dynamic-content
 * write guard, #35/#97's validator false-positive and lossy round trip) that
 * passed 870+ shimmed assertions and failed the instant they touched real
 * WordPress. See #20's "PROMOTED" comment for the full postmortem. Sharing code
 * with tests/run.php would risk the live harness quietly depending on the same
 * kind of assumption-shaped stub that caused those three bugs in the first
 * place — every primitive here either shells out to the real `wp` binary or
 * makes a real HTTP request, nothing here is a model of WordPress' behavior.
 *
 * Two-credential model, matching how the write paths under test actually
 * validate: fixture setup and read-only inspection go through WP-CLI (direct
 * DB access, no REST-layer content filtering to confound the fixture), and the
 * diviops operations actually being tested go through real HTTP REST calls
 * with Application Password auth — the same transport and auth a real MCP
 * client uses. Never the other way around: a test that fixtures via REST and
 * asserts via REST cannot tell "the write guard is broken" apart from "core's
 * own KSES already mangled my fixture before the guard ever ran."
 *
 * @package DiviOps
 */

declare( strict_types = 1 );

// ── page 900390 is read-only, full stop — see CLAUDE.md's site constraints ──
//
// Actual guard coverage, precisely (a prior version of this comment overclaimed
// this and was corrected after review):
//   - live_create_scratch_page() checks after every create (defense in depth —
//     a freshly minted autoincrement id cannot structurally collide with an
//     existing id, but the check costs nothing and stays as a backstop anyway).
//   - live_rest_call() checks a BEST-EFFORT extraction of the last numeric
//     path segment in $route for any non-GET call, since every diviops route
//     that targets a specific post/module follows exactly that
//     `.../{id}`-suffix convention. This does NOT catch a target id passed as
//     a body parameter (e.g. a hypothetical route taking `post_id` in JSON
//     rather than the URL) or any other shape.
//   - live_wp_cli() is a general WP-CLI passthrough with no id detection at
//     all — WP-CLI command shapes vary too much to reliably guess which
//     argument, if any, names a target post. A test that calls it directly
//     for a mutating command (not through live_create_scratch_page) MUST call
//     live_assert_not_forbidden_post_id() itself first — see
//     test-page-duplicate-real-markup.php's handling of its derived $new_id
//     for the pattern to follow.
// None of this is a substitute for care: a test file that hand-rolls its own
// curl call instead of using these helpers bypasses all of it. Read-only
// helpers (live_get_post_content) never call the guard, on purpose — reading
// 900390 to build fixtures FROM its real content is the entire point of
// several tests here.
const DIVIOPS_LIVE_FORBIDDEN_POST_IDS = array( 900390 );

/**
 * Refuse to proceed if $post_id is one this harness must never write to.
 *
 * @param int $post_id Target post id.
 */
function live_assert_not_forbidden_post_id( int $post_id ): void {
	if ( in_array( $post_id, DIVIOPS_LIVE_FORBIDDEN_POST_IDS, true ) ) {
		fwrite( STDERR, "\nREFUSING: post {$post_id} is in DIVIOPS_LIVE_FORBIDDEN_POST_IDS and must never be written to.\n" );
		exit( 1 );
	}
}

/**
 * Live-suite configuration, read from environment variables only — no
 * committed file, no hardcoded default pointing at anyone's machine. See
 * tests-live/README.md for exact setup commands.
 */
final class Live_Config {

	/** @var array<string,string>|null */
	private static $values = null;

	private const REQUIRED = array(
		'DIVIOPS_LIVE_URL',
		'DIVIOPS_LIVE_USER',
		'DIVIOPS_LIVE_APP_PASSWORD',
		'DIVIOPS_LIVE_WP_PATH',
	);

	/**
	 * @return array<string,string>
	 */
	public static function load(): array {
		if ( null !== self::$values ) {
			return self::$values;
		}

		$missing = array();
		$values  = array();
		foreach ( self::REQUIRED as $key ) {
			$val = getenv( $key );
			if ( false === $val || '' === $val ) {
				$missing[] = $key;
				continue;
			}
			$values[ $key ] = $val;
		}

		if ( ! empty( $missing ) ) {
			fwrite( STDERR, "\nNot configured — missing: " . implode( ', ', $missing ) . "\n" );
			fwrite( STDERR, "This suite hits a real WordPress site over HTTP and is opt-in on purpose.\n" );
			fwrite( STDERR, "See tests-live/README.md for the exact export commands.\n" );
			exit( 1 );
		}

		// Optional overrides, both with defaults matching the documented local
		// reference environment (CLAUDE.md) — but never assumed silently for
		// the REQUIRED connection values above.
		$values['DIVIOPS_LIVE_WP_CLI_BIN']  = getenv( 'DIVIOPS_LIVE_WP_CLI_BIN' ) ?: '/opt/homebrew/bin/wp';
		$values['DIVIOPS_LIVE_WP_CLI_SOCK'] = getenv( 'DIVIOPS_LIVE_WP_CLI_SOCK' ) ?: '';

		self::$values = $values;
		return $values;
	}
}

/**
 * Run a WP-CLI command against the configured site and return trimmed stdout.
 * Exits the whole run on a non-zero exit code — a broken fixture setup must
 * stop the suite, not silently continue against whatever partial state
 * resulted.
 *
 * @param array<int,string> $args Positional wp-cli arguments (NOT including
 *                                 --path or the socket flag; those are added
 *                                 here from Live_Config).
 * @return string
 */
function live_wp_cli( array $args ): string {
	$config = Live_Config::load();

	$cmd = array(
		'php',
		'-d', 'display_errors=0',
	);
	if ( '' !== $config['DIVIOPS_LIVE_WP_CLI_SOCK'] ) {
		$cmd[] = '-d';
		$cmd[] = 'mysqli.default_socket=' . $config['DIVIOPS_LIVE_WP_CLI_SOCK'];
	}
	$cmd[] = $config['DIVIOPS_LIVE_WP_CLI_BIN'];
	$cmd[] = '--path=' . $config['DIVIOPS_LIVE_WP_PATH'];
	// WP-CLI runs as no user (id 0) by default, and wp_insert_post()'s KSES
	// content filtering keys off current_user_can( 'unfiltered_html' ) — a
	// fixture written without --user would silently pass through KSES and
	// stop being byte-for-byte, defeating the entire point of these fixtures.
	// Reuses DIVIOPS_LIVE_USER: --user accepts a login name, and this is the
	// same identity the REST calls authenticate as, so fixture setup and the
	// operation under test see identical unfiltered_html capability.
	$cmd[] = '--user=' . $config['DIVIOPS_LIVE_USER'];
	foreach ( $args as $arg ) {
		$cmd[] = $arg;
	}

	$escaped = implode( ' ', array_map( 'escapeshellarg', $cmd ) );
	// wp-cli's PHP deprecation notices go to stderr and are noise here, not
	// signal — this suite is not the place to police WP-CLI's own PHP 8.x
	// compatibility. Only stdout is returned; a non-zero exit still aborts.
	$output     = array();
	$exit_code  = 0;
	exec( $escaped . ' 2>/dev/null', $output, $exit_code );

	if ( 0 !== $exit_code ) {
		fwrite( STDERR, "\nwp-cli command failed (exit {$exit_code}): {$escaped}\n" );
		fwrite( STDERR, implode( "\n", $output ) . "\n" );
		exit( 1 );
	}

	return trim( implode( "\n", $output ) );
}

/**
 * Scratch posts created by the running suite, cleaned up on exit regardless
 * of pass/fail/exception — a failed assertion must not leak fixtures into
 * the reference site.
 *
 * @var array<int,int>
 */
$GLOBALS['diviops_live_scratch_post_ids'] = array();

register_shutdown_function(
	static function (): void {
		$ids = $GLOBALS['diviops_live_scratch_post_ids'] ?? array();
		foreach ( $ids as $id ) {
			// --force skips trash: these are throwaway fixtures, and leaving
			// them in trash would accumulate across repeated live-suite runs.
			live_wp_cli( array( 'post', 'delete', (string) $id, '--force' ) );
		}
		if ( ! empty( $ids ) ) {
			fwrite( STDERR, 'cleaned up ' . count( $ids ) . ' scratch post(s): ' . implode( ', ', $ids ) . "\n" );
		}
	}
);

/**
 * Create a scratch draft page with exact byte-for-byte content and register
 * it for cleanup. Uses --post_content from a temp file (not --post_content=
 * inline) because these fixtures carry raw block-comment JSON with quotes,
 * braces, and backslashes no shell-escaping scheme survives reliably at
 * this size.
 *
 * $post_type exists for #314's Theme Builder coverage: the reference scan's
 * blind spot was a post type, so a fixture that can only ever be a `page`
 * cannot reproduce it. Everything else is unchanged — the fixture is still a
 * draft, still registered for forced cleanup, and still checked against the
 * forbidden-id list.
 *
 * @param string $content   Raw post_content.
 * @param string $title     Post title (defaults to a scratch-marked, timestamped label).
 * @param string $post_type Post type to create (defaults to `page`).
 * @return int New post id.
 */
function live_create_scratch_page( string $content, string $title = '', string $post_type = 'page' ): int {
	if ( '' === $title ) {
		$title = 'DiviOps live-suite scratch ' . date( 'Y-m-d H:i:s' );
	}

	$tmp = tempnam( sys_get_temp_dir(), 'diviops-live-fixture-' );
	file_put_contents( $tmp, $content );

	$id = (int) live_wp_cli(
		array(
			'post',
			'create',
			$tmp,
			'--post_type=' . $post_type,
			'--post_status=draft',
			'--post_title=' . $title,
			'--porcelain',
		)
	);

	unlink( $tmp );

	live_assert_not_forbidden_post_id( $id );
	$GLOBALS['diviops_live_scratch_post_ids'][] = $id;

	return $id;
}

/**
 * Read a post's raw post_content. Read-only, no cleanup registration, safe
 * to call against 900390 — reading it to build fixtures from real content is
 * the point.
 *
 * @param int $post_id Post id.
 * @return string
 */
function live_get_post_content( int $post_id ): string {
	return live_wp_cli( array( 'post', 'get', (string) $post_id, '--field=post_content' ) );
}

/**
 * Call a DiviOps REST route over real HTTP with Application Password auth —
 * the same transport and auth a real MCP client uses.
 *
 * @param string              $method 'GET'|'POST'|etc.
 * @param string              $route  Route under /wp-json/, e.g. 'diviops/v1/page_duplicate'.
 * @param array<string,mixed> $body   Request body, JSON-encoded (ignored for GET).
 * @return array{status:int, body:mixed, raw:string}
 */
function live_rest_call( string $method, string $route, array $body = array() ): array {
	// Best-effort: every diviops route that targets a specific post/module id
	// puts it as the LAST path segment (.../page/duplicate/{id},
	// .../page/update-content/{id}, ...). Checking it here catches the
	// realistic accident (a scratch id variable holding 900390 by mistake)
	// without pretending to catch every possible shape — see the guard
	// coverage note above DIVIOPS_LIVE_FORBIDDEN_POST_IDS.
	if ( 'GET' !== strtoupper( $method ) && preg_match( '#/(\d+)(?:\?.*)?$#', $route, $trailing_id ) ) {
		live_assert_not_forbidden_post_id( (int) $trailing_id[1] );
	}

	$config = Live_Config::load();
	$url    = rtrim( $config['DIVIOPS_LIVE_URL'], '/' ) . '/wp-json/' . ltrim( $route, '/' );

	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST  => strtoupper( $method ),
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'Accept: application/json',
			),
			CURLOPT_USERPWD        => $config['DIVIOPS_LIVE_USER'] . ':' . $config['DIVIOPS_LIVE_APP_PASSWORD'],
			CURLOPT_TIMEOUT        => 30,
		)
	);
	if ( 'GET' !== strtoupper( $method ) && ! empty( $body ) ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, wp_json_encode_compat( $body ) );
	}

	$raw    = curl_exec( $ch );
	$errno  = curl_errno( $ch );
	$err    = curl_error( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	// No curl_close(): a no-op since PHP 8.0, deprecated (warns) as of 8.5.
	// The handle is garbage-collected when $ch goes out of scope.

	if ( 0 !== $errno ) {
		fwrite( STDERR, "\ncurl error calling {$url}: {$err}\n" );
		exit( 1 );
	}

	$decoded = null !== $raw ? json_decode( (string) $raw, true ) : null;

	return array(
		'status' => $status,
		'body'   => $decoded,
		'raw'    => (string) $raw,
	);
}

/**
 * This harness runs standalone (no WordPress bootstrap — see the file
 * header), so wp_json_encode() does not exist here. json_encode() with the
 * same flags WordPress' own wrapper applies is the faithful equivalent for
 * building a REST request body.
 *
 * @param mixed $data Value to encode.
 * @return string
 */
function wp_json_encode_compat( $data ): string {
	$encoded = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	return false === $encoded ? '{}' : $encoded;
}

// ── minimal assertion helpers, deliberately duplicated rather than shared ──
// with tests/run.php (see file header) ──────────────────────────────────

final class Live_Test_Runner {
	/** @var int */
	public static $passed = 0;

	/** @var array<int,string> */
	public static $failures = array();

	/** @var string */
	public static $current = '';

	public static function assert_same( $expected, $actual, string $message ): void {
		if ( $expected === $actual ) {
			++self::$passed;
			return;
		}
		self::$failures[] = sprintf(
			"%s: %s\n    expected: %s\n    actual:   %s",
			self::$current,
			$message,
			self::render( $expected ),
			self::render( $actual )
		);
	}

	public static function assert_true( $actual, string $message ): void {
		self::assert_same( true, $actual, $message );
	}

	private static function render( $value ): string {
		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return false === $encoded ? gettype( $value ) : $encoded;
	}
}

function assert_same( $expected, $actual, string $message ): void {
	Live_Test_Runner::assert_same( $expected, $actual, $message );
}

function assert_true( $actual, string $message ): void {
	Live_Test_Runner::assert_true( $actual, $message );
}
