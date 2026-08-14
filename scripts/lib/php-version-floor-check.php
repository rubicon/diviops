<?php
/**
 * Detect calls to PHP builtin functions newer than this plugin's declared
 * `Requires PHP` floor.
 *
 * Exists because a PHP 8-only *function call* is syntactically valid on PHP 7.4 --
 * the 7.4 `php -l` lint job in .github/workflows/test.yaml cannot see it -- and
 * only fatals at runtime with "Call to undefined function", which the test-suite
 * jobs cannot see either, since they run on 8.3 and 8.5, where the function
 * already exists (#180).
 *
 * The table below is deliberately scoped to core/standard-library global
 * functions -- the shape of function a general-purpose PHP file reaches for by
 * habit, which is exactly how #180 happened (str_contains() reads like it has
 * always existed). Functions gated behind extensions this plugin never touches
 * (GD, MySQLi, PCNTL, Sodium, Intl, LDAP, ODBC, OCI8, Sockets, POSIX
 * file-descriptor calls) are excluded; enumerating every extension function in
 * every PHP release would bloat the table without reducing risk for code that
 * never loads those extensions. New syntax (match, enums, readonly properties,
 * the nullsafe operator, named arguments, union/intersection types) is out of
 * scope here on purpose: it is invalid grammar on 7.4, so the 7.4 `php -l` job
 * already catches it, unlike a plain function call.
 *
 * Verified against the official PHP manual's migration guides
 * (php.net/manual/en/migration{80,81,82,83,84}.new-functions.php) for PHP 8.0
 * through 8.4. Revisit this table when a new PHP minor ships, or when the
 * declared floor changes.
 *
 * @package DiviOps
 */

/**
 * PHP 8.0+ builtin functions this plugin's declared PHP floor cannot assume
 * exist, keyed by lowercase function name, valued by the PHP version that
 * introduced them.
 *
 * @return array<string, string>
 */
function diviops_php8_only_functions(): array {
	return array(
		// PHP 8.0.
		'str_contains'                     => '8.0',
		'str_starts_with'                  => '8.0',
		'str_ends_with'                    => '8.0',
		'fdiv'                              => '8.0',
		'get_debug_type'                   => '8.0',
		'preg_last_error_msg'              => '8.0',
		'get_resource_id'                  => '8.0',
		// PHP 8.1.
		'array_is_list'                    => '8.1',
		// PHP 8.2.
		'memory_reset_peak_usage'          => '8.2',
		'ini_parse_quantity'               => '8.2',
		// PHP 8.3.
		'str_increment'                    => '8.3',
		'str_decrement'                    => '8.3',
		'stream_context_set_options'       => '8.3',
		'json_validate'                    => '8.3',
		// PHP 8.4.
		'request_parse_body'               => '8.4',
		'http_get_last_response_headers'   => '8.4',
		'http_clear_last_response_headers' => '8.4',
		'fpow'                              => '8.4',
		'array_all'                        => '8.4',
		'array_any'                        => '8.4',
		'array_find'                       => '8.4',
		'array_find_key'                   => '8.4',
	);
}

/**
 * Parse the `Requires PHP:` header value out of a plugin main-file's contents.
 *
 * @param string $contents Plugin main-file contents (the docblock header).
 *
 * @return string|null The declared floor (e.g. "7.4"), or null when absent.
 */
function diviops_parse_requires_php( string $contents ): ?string {
	if ( 1 === preg_match( '/^[ \t\/*#@]*Requires PHP:\s*([0-9]+(?:\.[0-9]+)*)/mi', $contents, $matches ) ) {
		return $matches[1];
	}
	return null;
}

/**
 * Return the next non-whitespace, non-comment token at or after $start, or null
 * when the stream is exhausted.
 *
 * @param array<int, mixed> $tokens Token stream from token_get_all().
 * @param int                $start  Index to start scanning from (inclusive).
 *
 * @return mixed
 */
function diviops_next_significant_token( array $tokens, int $start ) {
	$count = count( $tokens );
	for ( $i = $start; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $token;
	}
	return null;
}

/**
 * Return the previous non-whitespace, non-comment token at or before $start, or
 * null when the stream is exhausted.
 *
 * @param array<int, mixed> $tokens Token stream from token_get_all().
 * @param int                $start  Index to start scanning from (inclusive).
 *
 * @return mixed
 */
function diviops_prev_significant_token( array $tokens, int $start ) {
	for ( $i = $start; $i >= 0; $i-- ) {
		$token = $tokens[ $i ];
		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $token;
	}
	return null;
}

/**
 * Scan PHP source for calls to functions in $forbidden.
 *
 * Skips method calls ($x->str_contains()), static calls (Foo::str_contains()),
 * and function declarations of the same name (e.g. a guarded function_exists()
 * polyfill) -- none of those reach the global builtin. A fully-qualified call
 * (\str_contains()) is still flagged, since this plugin declares no namespaces of
 * its own (verified: `grep -rn '^namespace ' plugins/` finds none), so an
 * unqualified call always resolves to the global builtin with no fallback to
 * chase. A qualified call naming some other namespace (Foo\str_contains()) is
 * NOT flagged: a qualified name resolves only within that namespace at compile
 * time and never falls back to the global function, so it cannot be a disguised
 * call to the builtin.
 *
 * PHP 8.0 merged namespaced names into single T_NAME_* tokens instead of
 * T_NS_SEPARATOR + T_STRING, so `\str_contains` and `str_contains` are handled as
 * two distinct token shapes below, not one. Known limitation: when THIS SCRIPT
 * itself runs under a pre-8.0 tokenizer (e.g. the test-php74 CI job), a genuinely
 * qualified call such as `SomeVendor\str_contains()` tokenizes as separate
 * T_NS_SEPARATOR + T_STRING tokens indistinguishable, at the single-token level,
 * from a bare call -- and would be misflagged. Left unhandled deliberately: this
 * plugin declares zero namespaces of its own, and its only qualified-name usage
 * is `\ET\Builder\...::method()`, always a static call caught by the
 * T_DOUBLE_COLON check above regardless of tokenizer form (verified: no bare,
 * non-static, non-fully-qualified call of shape `Foo\bar(` exists anywhere under
 * plugins/). Revisit if that ever changes.
 *
 * @param string               $source    PHP source code.
 * @param array<string,string> $forbidden Function name (any case) => introduced-in version.
 *
 * @return array<int, array{line:int, function:string, introduced_in:string}>
 */
function diviops_scan_source_for_forbidden_calls( string $source, array $forbidden ): array {
	$forbidden = array_change_key_case( $forbidden, CASE_LOWER );
	$violations = array();
	$tokens     = token_get_all( $source );
	$count      = count( $tokens );

	$skip_prev_kinds = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION );
	if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
		$skip_prev_kinds[] = constant( 'T_NULLSAFE_OBJECT_OPERATOR' );
	}

	$fully_qualified_kind = defined( 'T_NAME_FULLY_QUALIFIED' ) ? constant( 'T_NAME_FULLY_QUALIFIED' ) : null;

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) ) {
			continue;
		}

		$raw = $token[1];

		if ( T_STRING === $token[0] ) {
			// Bare call: str_contains(...).
			$display_name = $raw;
		} elseif ( null !== $fully_qualified_kind && $fully_qualified_kind === $token[0] ) {
			// Fully-qualified call: \str_contains(...). A remaining backslash
			// after stripping the leading one means this names some OTHER
			// namespace's function (\Foo\str_contains), not the global builtin.
			$leaf = ltrim( $raw, '\\' );
			if ( false !== strpos( $leaf, '\\' ) ) {
				continue;
			}
			$display_name = $leaf;
		} else {
			continue;
		}

		$name = strtolower( $display_name );

		if ( ! isset( $forbidden[ $name ] ) ) {
			continue;
		}

		$next = diviops_next_significant_token( $tokens, $i + 1 );
		if ( ! is_string( $next ) || '(' !== $next ) {
			continue;
		}

		$prev = diviops_prev_significant_token( $tokens, $i - 1 );
		if ( is_array( $prev ) && in_array( $prev[0], $skip_prev_kinds, true ) ) {
			continue;
		}

		$violations[] = array(
			'line'          => $token[2],
			'function'      => $display_name,
			'introduced_in' => $forbidden[ $name ],
		);
	}

	return $violations;
}

/**
 * Recursively find every `.php` file under $root, sorted.
 *
 * @param string $root Directory to walk.
 *
 * @return array<int, string>
 */
function diviops_find_php_files( string $root ): array {
	$files = array();

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}

	sort( $files );

	return $files;
}
