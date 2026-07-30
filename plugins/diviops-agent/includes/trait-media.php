<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DiviOps_Agent_Media {

	/** CIDR-range reserved-address test against a 4-byte packed IPv4 address. */
	private static function media_ipv4_packed_is_reserved( string $packed4 ): bool {
		$n      = unpack( 'Nip', $packed4 )['ip'];
		$ranges = array(
			array( '0.0.0.0', 8 ), array( '10.0.0.0', 8 ), array( '100.64.0.0', 10 ),
			array( '127.0.0.0', 8 ), array( '169.254.0.0', 16 ), array( '172.16.0.0', 12 ),
			array( '192.0.0.0', 24 ), array( '192.0.2.0', 24 ), array( '192.168.0.0', 16 ),
			array( '198.18.0.0', 15 ), array( '198.51.100.0', 24 ), array( '203.0.113.0', 24 ),
			array( '224.0.0.0', 4 ), array( '240.0.0.0', 4 ),
		);
		foreach ( $ranges as $r ) {
			$base = unpack( 'Nip', inet_pton( $r[0] ) )['ip'];
			$mask = $r[1] === 0 ? 0 : ( 0xFFFFFFFF << ( 32 - $r[1] ) ) & 0xFFFFFFFF;
			if ( ( $n & $mask ) === ( $base & $mask ) ) { return true; }
		}
		return false;
	}

	/**
	 * IPv4/IPv6 reserved-range test. Unwraps IPv4-mapped IPv6 (::ffff:a.b.c.d)
	 * and re-checks the embedded v4. Also decodes and re-checks the embedded
	 * v4 for three other IPv6 forms that can smuggle a private/loopback v4
	 * address past a naive IPv6-only check: IPv4-compatible (::a.b.c.d),
	 * NAT64 (64:ff9b::/96), and 6to4 (2002::/16).
	 */
	private static function media_ip_is_reserved( string $ip ): bool {
		$packed = @inet_pton( $ip );
		if ( false === $packed ) { return true; } // unparseable => unsafe
		// IPv4-mapped IPv6 (::ffff:a.b.c.d) -> re-check embedded v4.
		if ( 16 === strlen( $packed ) && "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === substr( $packed, 0, 12 ) ) {
			$ip = inet_ntop( substr( $packed, 12 ) );
			$packed = inet_pton( $ip );
		}
		if ( 4 === strlen( $packed ) ) {
			return self::media_ipv4_packed_is_reserved( $packed );
		}
		// IPv6
		$hex = bin2hex( $packed );
		if ( '00000000000000000000000000000001' === $hex ) { return true; } // ::1
		if ( '00000000000000000000000000000000' === $hex ) { return true; } // ::
		$first = hexdec( substr( $hex, 0, 2 ) );
		if ( ( $first & 0xFE ) === 0xFC ) { return true; }            // fc00::/7 ULA
		if ( 'fe8' === substr( $hex, 0, 3 ) || 'fe9' === substr( $hex, 0, 3 )
			|| 'fea' === substr( $hex, 0, 3 ) || 'feb' === substr( $hex, 0, 3 ) ) { return true; } // fe80::/10
		if ( 'ff' === substr( $hex, 0, 2 ) ) { return true; }         // ff00::/8 multicast
		if ( '20010db8' === substr( $hex, 0, 8 ) ) { return true; }   // 2001:db8::/32 doc

		// Embedded-IPv4 forms: decode the embedded octets and re-run the
		// v4-reserved check so a PRIVATE/loopback v4 smuggled through one of
		// these encodings is still rejected.
		$prefix12 = substr( $packed, 0, 12 );
		if ( "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" === $prefix12 ) {
			// IPv4-compatible (::a.b.c.d).
			if ( self::media_ipv4_packed_is_reserved( substr( $packed, 12, 4 ) ) ) { return true; }
		} elseif ( "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00" === $prefix12 ) {
			// NAT64 (64:ff9b::/96).
			if ( self::media_ipv4_packed_is_reserved( substr( $packed, 12, 4 ) ) ) { return true; }
		} elseif ( "\x20\x02" === substr( $packed, 0, 2 ) ) {
			// 6to4 (2002::/16) -- embedded v4 occupies bytes[2..5].
			if ( self::media_ipv4_packed_is_reserved( substr( $packed, 2, 4 ) ) ) { return true; }
		}

		return false;
	}

	/** Reject non-http(s) schemes, unresolvable hosts, and hosts resolving to a reserved IP. */
	private static function media_url_is_safe( string $url, ?string &$reason = null, ?callable $resolver = null ): bool {
		$parts = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		if ( 'http' !== $scheme && 'https' !== $scheme ) { $reason = 'Only http/https URLs are allowed.'; return false; }
		$host = $parts['host'] ?? '';
		if ( '' === $host ) { $reason = 'URL has no host.'; return false; }
		$resolver = $resolver ?: array( __CLASS__, 'media_resolve_host_ips' );
		$ips = (array) call_user_func( $resolver, $host );
		if ( empty( $ips ) ) { $reason = "Could not resolve host: {$host}."; return false; }
		foreach ( $ips as $ip ) {
			if ( self::media_ip_is_reserved( (string) $ip ) ) { $reason = "Host resolves to a blocked address ({$ip})."; return false; }
		}
		return true;
	}

	/** Real DNS resolution: IPv4 via gethostbynamel(), IPv6 (AAAA) via dns_get_record(). */
	private static function media_resolve_host_ips( string $host ): array {
		$ips = array();
		$v4  = gethostbynamel( $host );
		if ( is_array( $v4 ) ) { $ips = $v4; }
		$aaaa = @dns_get_record( $host, DNS_AAAA );
		if ( is_array( $aaaa ) ) { foreach ( $aaaa as $r ) { if ( ! empty( $r['ipv6'] ) ) { $ips[] = $r['ipv6']; } } }
		return $ips;
	}

	/**
	 * Reject uploads whose real bytes don't match a supported, site-allowed
	 * mime type. Defends against type-spoofing (a .png that isn't a PNG) and
	 * disallowed types. Returns null when the file is allowed, else envelope-
	 * error args.
	 */
	private static function media_filetype_error( string $filename, string $tmp_path ): ?array {
		$check = wp_check_filetype_and_ext( $tmp_path, $filename );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return array(
				'code'    => 'unsupported_media_type',
				'http'    => 415,
				'message' => "The file bytes do not match a supported type for '{$filename}'.",
				'hint'    => 'Upload a real image; the extension must match the actual file contents.',
				'data'    => array( 'filename' => $filename ),
			);
		}
		if ( 'image/svg+xml' === ( $check['type'] ?? '' ) && ! self::media_svg_sideload_sanitizer_active() ) {
			return array(
				'code'    => 'svg_sanitizer_required',
				'http'    => 415,
				'message' => 'SVG uploads require an active SVG sanitizer that sanitizes sideloaded files.',
				'hint'    => 'Install/enable Safe SVG (a recent version that hooks wp_handle_sideload_prefilter).',
				'data'    => array( 'filename' => $filename ),
			);
		}
		$allowed = array_values( get_allowed_mime_types() );
		if ( ! in_array( $check['type'], $allowed, true ) ) {
			return array(
				'code'    => 'unsupported_media_type',
				'http'    => 415,
				'message' => "Mime type '{$check['type']}' is not allowed on this site.",
				'hint'    => 'Allowed types come from WordPress get_allowed_mime_types().',
				'data'    => array( 'filename' => $filename, 'detected_type' => $check['type'] ),
			);
		}
		return null;
	}

	/**
	 * SVG is a stored-XSS vector, so it is allowed only when Safe SVG's OWN
	 * callback is verified active on the exact filter our upload path fires.
	 * Our upload path calls media_handle_sideload(), which fires
	 * wp_handle_sideload_prefilter; has_filter() alone only proves *something*
	 * is listening, not that it sanitizes — an unrelated plugin's callback on
	 * the same hook would otherwise let an unsanitized SVG through. This scans
	 * $wp_filter directly for a callback bound to a Safe SVG instance, matched
	 * by class name via is_a() rather than instanceof so both the current
	 * namespaced class (Safe SVG 2.x: SafeSvg\safe_svg, confirmed live on the
	 * running site by dumping $wp_filter) and the legacy global class (Safe
	 * SVG 1.x: safe_svg) are detected without either needing to be defined at
	 * compile time. Fail closed: if neither class matches, the callback is
	 * rejected.
	 */
	private static function media_svg_sideload_sanitizer_active(): bool {
		global $wp_filter;
		if ( empty( $wp_filter['wp_handle_sideload_prefilter'] ) ) {
			return false;
		}
		$hook      = $wp_filter['wp_handle_sideload_prefilter'];
		$callbacks = ( $hook instanceof WP_Hook ) ? $hook->callbacks : (array) $hook;
		foreach ( (array) $callbacks as $priority_group ) {
			foreach ( (array) $priority_group as $registered ) {
				$fn = ( is_array( $registered ) && isset( $registered['function'] ) ) ? $registered['function'] : $registered;
				if ( is_array( $fn ) && isset( $fn[0] ) && is_object( $fn[0] )
					&& ( is_a( $fn[0], 'SafeSvg\\safe_svg' ) || is_a( $fn[0], 'safe_svg' ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Robustly coerce a request param to bool. A plain `(bool)` cast is truthy
	 * for ANY non-empty string, including the string "false" and "0" — exactly
	 * the values a REST caller sends (query params, form-encoded bodies) to
	 * mean "do not do this". filter_var()/FILTER_VALIDATE_BOOLEAN maps the
	 * common false-ish strings ("false", "0", "no", "off", "") to false and the
	 * common true-ish strings ("true", "1", "yes", "on") to true, leaving a
	 * native bool untouched.
	 *
	 * @param mixed  $request REST request-like object (get_param()).
	 * @param string $key     Param name.
	 * @return bool
	 */
	private static function media_bool_param( $request, string $key ): bool {
		return filter_var( $request->get_param( $key ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Upload an image into the media library from either a public URL (server
	 * fetches it, SSRF-guarded via media_url_is_safe()) or base64-encoded bytes.
	 * Exactly one of `url`/`data_base64` must be supplied. Supports dry-run.
	 *
	 * @param mixed $request REST request-like object (get_param()).
	 * @return WP_REST_Response
	 */
	public static function media_upload( $request ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return self::envelope_error( 'forbidden', 'You are not allowed to upload files.', 'Authenticate as a user with upload_files.', 403 );
		}
		$url       = trim( (string) ( $request->get_param( 'url' ) ?? '' ) );
		$b64       = (string) ( $request->get_param( 'data_base64' ) ?? '' );
		$dry_run   = self::media_bool_param( $request, 'dry_run' );
		$attach_to = absint( $request->get_param( 'attach_to' ) );

		if ( $attach_to > 0 && ! current_user_can( 'edit_post', $attach_to ) ) {
			return self::envelope_error(
				'forbidden',
				"You are not allowed to attach media to post #{$attach_to}.",
				'Authenticate as a user who can edit the target post.',
				403,
				array( 'attach_to' => $attach_to )
			);
		}

		if ( ( '' === $url ) === ( '' === $b64 ) ) {
			return self::envelope_error( 'invalid_input', 'Provide exactly one of url or data_base64.', 'url fetches remotely; data_base64 uploads local bytes.', 400 );
		}

		// Load WP's sideload/media helpers only when not already present. The test
		// harness stubs media_handle_sideload/wp_remote_get/etc., so guarding avoids
		// require_once'ing WP-admin files that don't exist in the harness.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( '' !== $url ) {
			// Extensibility seam, TEST-ONLY: in production (DIVIOPS_TESTING undefined)
			// this filter is never consulted, so a hooked callback cannot replace the
			// real-DNS SSRF address guard. The test harness defines DIVIOPS_TESTING
			// (tests/wp-shim.php) so it can inject a fake resolver without touching DNS.
			$resolver = ( defined( 'DIVIOPS_TESTING' ) && DIVIOPS_TESTING )
				? apply_filters( 'diviops_media_host_resolver', null )
				: null;
			$resolver = is_callable( $resolver ) ? $resolver : null;
			$reason   = null;
			if ( ! self::media_url_is_safe( $url, $reason, $resolver ) ) {
				return self::envelope_error( 'forbidden_target', $reason, 'Only public http/https image URLs are allowed.', 403, array( 'url' => $url ) );
			}
			$filename   = self::media_basename_from_url( $url );
			$tmp_getter = function () use ( $url, $resolver ) {
				$fetch_reason = null;
				return self::media_fetch_to_temp( $url, $fetch_reason, $resolver );
			};
			$source     = array(
				'kind' => 'url',
				'ref'  => $url,
			);
		} else {
			$filename = sanitize_file_name( (string) ( $request->get_param( 'filename' ) ?? '' ) );
			if ( '' === $filename ) {
				return self::envelope_error( 'invalid_input', 'filename is required with data_base64.', null, 400 );
			}
			if ( strlen( $b64 ) > (int) ( wp_max_upload_size() * 1.4 ) ) {
				return self::envelope_error( 'payload_too_large', 'Encoded payload exceeds the upload size limit.', null, 413 );
			}
			$bytes = base64_decode( $b64, true );
			if ( false === $bytes ) {
				return self::envelope_error( 'invalid_input', 'data_base64 is not valid base64.', null, 400 );
			}
			$tmp_getter = function () use ( $bytes ) {
				$t = wp_tempnam();
				file_put_contents( $t, $bytes );
				return $t;
			};
			$source     = array(
				'kind' => 'base64',
				'ref'  => $filename,
			);
		}

		if ( $dry_run ) {
			return self::dry_run_response(
				"Would upload '{$filename}' to the media library" . ( $attach_to ? " attached to post #{$attach_to}." : '.' ),
				array(
					array(
						'kind'   => 'upload',
						'target' => $filename,
						'before' => null,
						'after'  => 'attachment',
					),
				),
				array(),
				array(
					'filename' => $filename,
					'source'   => $source['kind'],
				)
			);
		}

		$tmp = call_user_func( $tmp_getter );
		if ( is_wp_error( $tmp ) ) {
			// A redirect hop that resolves to a blocked target surfaces the SAME
			// forbidden_target/403 as a directly-blocked initial URL — the guard
			// applies per hop, not just to the URL the caller supplied.
			if ( 'forbidden_target' === $tmp->get_error_code() ) {
				return self::envelope_error( 'forbidden_target', $tmp->get_error_message(), 'Only public http/https image URLs are allowed.', 403, array( 'url' => $url ) );
			}
			if ( 'payload_too_large' === $tmp->get_error_code() ) {
				return self::envelope_error( 'payload_too_large', $tmp->get_error_message(), 'Fetch a smaller file, or raise the site upload size limit.', 413, array( 'source' => $source ) );
			}
			return self::envelope_error( 'fetch_failed', $tmp->get_error_message(), 'The source could not be retrieved.', 502, array( 'source' => $source ) );
		}
		// media_fetch_to_temp() (the url path) returns [ 'tmp', 'final_url' ];
		// the base64 path's tmp_getter returns a plain string. When a redirect
		// chain was followed, the filename should reflect the FINAL url, not
		// the one the caller originally supplied.
		if ( is_array( $tmp ) ) {
			if ( ! empty( $tmp['final_url'] ) ) {
				$filename = self::media_basename_from_url( $tmp['final_url'] );
			}
			$tmp = $tmp['tmp'];
		}

		$type_err = self::media_filetype_error( $filename, $tmp );
		if ( null !== $type_err ) {
			@unlink( $tmp );
			return self::envelope_error( $type_err['code'], $type_err['message'], $type_err['hint'], $type_err['http'], $type_err['data'] );
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);
		$desc       = (string) ( $request->get_param( 'title' ) ?? '' );
		$id         = media_handle_sideload( $file_array, $attach_to, $desc ?: null );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return self::envelope_error( 'upload_failed', $id->get_error_message(), null, 500 );
		}

		$id = (int) $id;
		if ( 'url' === $source['kind'] ) {
			update_post_meta( $id, '_diviops_source_url', esc_url_raw( $url ) );
		}
		$alt = (string) ( $request->get_param( 'alt' ) ?? '' );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		$caption = (string) ( $request->get_param( 'caption' ) ?? '' );
		if ( '' !== $caption ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_excerpt' => sanitize_text_field( $caption ),
				)
			);
		}

		return self::envelope_success(
			array(
				'attachment_id' => $id,
				'url'           => wp_get_attachment_url( $id ),
				'mime'          => get_post_mime_type( $id ),
				'filename'      => $filename,
				'source'        => $source['kind'],
			)
		);
	}

	/** Derive a sanitized filename from a URL's path, falling back to 'download'. */
	private static function media_basename_from_url( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( basename( $path ) );
		return '' !== $name ? $name : 'download';
	}

	/**
	 * Fetch a URL to a local temp file, following redirects explicitly with a
	 * bounded hop count and re-running the FULL SSRF/address guard on every
	 * hop — not just the caller's initial URL. WP's HTTP layer's automatic
	 * redirect-following (`download_url()`) is deliberately not used here: a
	 * public origin that 302s to 169.254.169.254 would otherwise sail straight
	 * through a one-time check performed only on the URL we were handed.
	 *
	 * Known residual (documented, accepted): this validates each hop's
	 * resolved address before requesting it, but does not pin that address
	 * for the actual TCP connect — WP's HTTP layer resolves the host again at
	 * connect time, so a DNS-rebinding attacker could still change the answer
	 * in between. Accepted because this endpoint is authenticated and
	 * admin-privileged, not attacker-reachable.
	 *
	 * @return array|WP_Error Array shaped `[ 'tmp' => string, 'final_url' =>
	 *                        string ]` on success (final_url is the URL of
	 *                        the hop that actually served the body, equal to
	 *                        $url when no redirect was followed), WP_Error
	 *                        otherwise.
	 */
	private static function media_fetch_to_temp( string $url, ?string &$reason = null, ?callable $resolver = null ) {
		$max_hops = 5;
		for ( $hop = 0; $hop <= $max_hops; $hop++ ) {
			if ( ! self::media_url_is_safe( $url, $reason, $resolver ) ) {
				return new WP_Error( 'forbidden_target', $reason );
			}
			$resp = wp_remote_get(
				$url,
				array(
					'timeout'             => 20,
					'redirection'         => 0, // do NOT auto-follow
					// One byte ABOVE the cap: WP's HTTP transport truncates the body
					// to this limit, so setting it to the cap exactly would make the
					// strlen() > cap check below unreachable in production (a
					// truncated body always lands at strlen === cap). Setting it to
					// cap+1 lets an over-cap body reach cap+1 bytes, so the check
					// still fires and rejects it instead of silently sideloading a
					// truncated file.
					'limit_response_size' => (int) wp_max_upload_size() + 1,
				)
			);
			if ( is_wp_error( $resp ) ) {
				$reason = $resp->get_error_message();
				return $resp;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( $code >= 300 && $code < 400 ) {
				$loc = (string) wp_remote_retrieve_header( $resp, 'location' );
				if ( '' === $loc ) {
					$reason = 'Redirect with no Location header.';
					return new WP_Error( 'fetch_failed', $reason );
				}
				$absolute  = self::media_absolutize_url( $loc, $url );
				$abs_parts = wp_parse_url( $absolute );
				$abs_parts = is_array( $abs_parts ) ? $abs_parts : array();
				if ( empty( $abs_parts['scheme'] ) || empty( $abs_parts['host'] ) ) {
					// Fail closed: an unresolvable/garbage Location ends the
					// loop here rather than being silently followed or left
					// to fall through to a less-specific rejection path.
					$reason = 'Redirect target could not be resolved to an absolute URL.';
					return new WP_Error( 'fetch_failed', $reason );
				}
				$url = $absolute;
				continue; // revalidate the new target on the next loop iteration
			}
			if ( $code < 200 || $code >= 300 ) {
				$reason = "Upstream returned HTTP {$code}.";
				return new WP_Error( 'fetch_failed', $reason );
			}
			$body = wp_remote_retrieve_body( $resp );
			if ( strlen( $body ) > (int) wp_max_upload_size() ) {
				$reason = 'Fetched response exceeds the upload size limit.';
				return new WP_Error( 'payload_too_large', $reason );
			}
			$tmp  = wp_tempnam();
			if ( false === file_put_contents( $tmp, $body ) ) {
				@unlink( $tmp );
				$reason = 'Could not write temp file.';
				return new WP_Error( 'fetch_failed', $reason );
			}
			return array( 'tmp' => $tmp, 'final_url' => $url );
		}
		$reason = 'Too many redirects.';
		return new WP_Error( 'fetch_failed', $reason );
	}

	/**
	 * Get a single attachment's details.
	 *
	 * @param mixed $request REST request-like object ($request['id']).
	 * @return WP_REST_Response
	 */
	public static function media_get( $request ) {
		$id   = absint( $request['id'] );
		$post = get_post( $id );

		if ( ! $post || 'attachment' !== $post->post_type ) {
			return self::envelope_error(
				'not_found',
				"Attachment #{$id} not found.",
				'Use diviops_media_list to find a valid attachment id.',
				404,
				array( 'attachment_id' => $id )
			);
		}
		if ( ! self::can_inspect_post_object( $post ) ) {
			return self::envelope_error(
				'forbidden',
				"You are not allowed to read attachment #{$id}.",
				'Authenticate as a user who can edit this attachment.',
				403,
				array( 'attachment_id' => $id )
			);
		}

		$metadata = wp_get_attachment_metadata( $id );
		$sizes    = ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) )
			? $metadata['sizes']
			: array();

		return self::envelope_success(
			array(
				'attachment_id' => $id,
				'url'           => (string) wp_get_attachment_url( $id ),
				'mime'          => (string) get_post_mime_type( $id ),
				'title'         => (string) $post->post_title,
				'alt'           => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'caption'       => (string) get_post_field( 'post_excerpt', $id ),
				'sizes'         => $sizes,
			)
		);
	}

	/**
	 * List/paginate media library attachments, optionally filtered by mime
	 * prefix and/or a title search term.
	 *
	 * @param mixed $request REST request-like object (get_param()).
	 * @return WP_REST_Response
	 */
	public static function media_list( $request ) {
		$page_num = self::get_request_page( $request );
		$per_page = max( 1, min( absint( $request->get_param( 'per_page' ) ?? 20 ), 100 ) );
		$mime     = sanitize_text_field( (string) ( $request->get_param( 'mime' ) ?? '' ) );
		$search   = sanitize_text_field( (string) ( $request->get_param( 'search' ) ?? '' ) );

		$args = array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
		);
		if ( '' !== $mime ) {
			$args['post_mime_type'] = $mime;
		}
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		// query_inspectable_post_ids() applies the same per-object edit_post
		// gate as every other list handler (library_list, canvas, page,
		// theme-builder) — a caller only sees attachments it can edit, and
		// `total` reflects the gated count, not the raw query count.
		$page  = self::query_inspectable_post_ids( $args, $per_page, $page_num );
		$items = array();
		foreach ( $page['ids'] as $attachment_id ) {
			$post = get_post( $attachment_id );
			if ( ! $post ) {
				continue;
			}
			$url      = (string) wp_get_attachment_url( $post->ID );
			// get_attached_file() returns the local filesystem path, which
			// never carries a `?query` the way an attachment URL sometimes
			// does in production; fall back to the URL's basename on the
			// rare attachment with no local file on record.
			$filename = wp_basename( (string) get_attached_file( $post->ID ) );
			if ( '' === $filename ) {
				$filename = wp_basename( $url );
			}
			$items[] = array(
				'attachment_id' => (int) $post->ID,
				'url'           => $url,
				'mime'          => (string) get_post_mime_type( $post->ID ),
				'title'         => (string) $post->post_title,
				'filename'      => $filename,
			);
		}

		return self::envelope_success(
			array(
				'items'    => $items,
				'page'     => $page_num,
				'per_page' => $per_page,
				'total'    => $page['total'],
			)
		);
	}

	/**
	 * Set a post's featured image (thumbnail) from either an existing image
	 * attachment id, or by uploading from a URL first (delegates to
	 * media_upload()). Idempotent: setting to the post's current thumbnail is
	 * a no-op. Supports dry_run.
	 *
	 * @param mixed $request REST request-like object (get_param()).
	 * @return WP_REST_Response
	 */
	public static function media_set_featured_image( $request ) {
		$post_id       = absint( $request->get_param( 'post_id' ) );
		$attachment_id = $request->get_param( 'attachment_id' );
		$url           = trim( (string) ( $request->get_param( 'url' ) ?? '' ) );
		$dry_run       = self::media_bool_param( $request, 'dry_run' );

		// Cap-first ordering, matching media_upload(): post-existence and
		// edit_post permission are checked before the attachment_id/url
		// xor-shape validation, so a caller without rights to the post learns
		// that before anything about their input shape. not_found still
		// precedes forbidden.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return self::envelope_error(
				'not_found',
				"Post #{$post_id} not found.",
				'Verify the post id via diviops_page_list.',
				404,
				array( 'post_id' => $post_id )
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::envelope_error(
				'forbidden',
				"Cannot edit post #{$post_id}.",
				'Authenticate as a user with edit rights to this post.',
				403,
				array( 'post_id' => $post_id )
			);
		}

		$has_attachment_id = null !== $attachment_id && '' !== $attachment_id;
		$has_url           = '' !== $url;
		if ( $has_attachment_id === $has_url ) {
			return self::envelope_error(
				'invalid_input',
				'Provide exactly one of attachment_id or url.',
				'attachment_id sets from an existing attachment; url uploads a new one first.',
				400
			);
		}

		$previous_id = absint( get_post_thumbnail_id( $post_id ) );

		if ( $has_attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$attach_err    = self::media_validate_image_attachment( $attachment_id );
			if ( null !== $attach_err ) {
				return $attach_err;
			}

			if ( $dry_run ) {
				return self::media_set_featured_image_dry_run( $post_id, $previous_id, $attachment_id );
			}

			return self::media_apply_featured_image( $post_id, $previous_id, $attachment_id );
		}

		// url path: describe the plan without fetching anything on dry_run.
		if ( $dry_run ) {
			return self::dry_run_response(
				"Would upload '{$url}' and set it as the featured image for post #{$post_id} (current thumbnail: " . ( $previous_id ? "#{$previous_id}" : 'none' ) . ').',
				array(
					array(
						'kind'   => 'set_featured_image',
						'target' => "post#{$post_id}",
						'before' => $previous_id ? $previous_id : null,
						'after'  => 'uploaded-from-url',
					),
				),
				array(),
				array(
					'post_id'               => $post_id,
					'previous_thumbnail_id' => $previous_id,
				)
			);
		}

		$upload_request = new WP_REST_Request( 'POST' );
		$upload_request->set_param( 'url', $url );
		$upload_result  = self::media_upload( $upload_request );
		$upload_data    = $upload_result->get_data();
		if ( empty( $upload_data['ok'] ) ) {
			return $upload_result;
		}

		$attachment_id = absint( $upload_data['data']['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			return self::envelope_error(
				'upload_failed',
				'media_upload did not return an attachment id.',
				null,
				500
			);
		}

		return self::media_apply_featured_image( $post_id, $previous_id, $attachment_id );
	}

	/**
	 * Validate that $attachment_id exists and is an image attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return WP_REST_Response|null Error envelope, or null when valid.
	 */
	private static function media_validate_image_attachment( int $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return self::envelope_error(
				'not_found',
				"Attachment #{$attachment_id} not found.",
				'Use diviops_media_list to find a valid attachment id.',
				404,
				array( 'attachment_id' => $attachment_id )
			);
		}
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( 0 !== strpos( $mime, 'image/' ) ) {
			return self::envelope_error(
				'invalid_input',
				"Attachment #{$attachment_id} is not an image (mime: '{$mime}').",
				'A post featured image must be an image attachment.',
				400,
				array( 'attachment_id' => $attachment_id, 'mime' => $mime )
			);
		}
		return null;
	}

	/** Build the dry_run plan response for the attachment_id path. */
	private static function media_set_featured_image_dry_run( int $post_id, int $previous_id, int $attachment_id ) {
		$noop    = ( $previous_id === $attachment_id );
		$summary = $noop
			? "Post #{$post_id} already has attachment #{$attachment_id} as its featured image — no-op."
			: "Would set post #{$post_id}'s featured image: " . ( $previous_id ? "#{$previous_id}" : 'none' ) . " → #{$attachment_id}.";

		return self::dry_run_response(
			$summary,
			$noop ? array() : array(
				array(
					'kind'   => 'set_featured_image',
					'target' => "post#{$post_id}",
					'before' => $previous_id ? $previous_id : null,
					'after'  => $attachment_id,
				),
			),
			array(),
			array(
				'post_id'               => $post_id,
				'previous_thumbnail_id' => $previous_id,
				'thumbnail_id'          => $attachment_id,
			)
		);
	}

	/** Apply (or no-op) the thumbnail write and return the success envelope. */
	private static function media_apply_featured_image( int $post_id, int $previous_id, int $attachment_id ) {
		if ( $previous_id === $attachment_id ) {
			return self::envelope_success(
				array(
					'post_id'               => $post_id,
					'previous_thumbnail_id' => $previous_id,
					'thumbnail_id'          => $attachment_id,
					'noop'                  => true,
				)
			);
		}

		set_post_thumbnail( $post_id, $attachment_id );

		return self::envelope_success(
			array(
				'post_id'               => $post_id,
				'previous_thumbnail_id' => $previous_id,
				'thumbnail_id'          => $attachment_id,
				'noop'                  => false,
			)
		);
	}

	/**
	 * Set/clear an attachment's alt text and/or caption. At least one of
	 * `alt`/`caption` must be present in the request; the other, if omitted,
	 * is left untouched. An explicit empty string clears that field. Idempotent:
	 * applying values that already match the current stored values is a no-op.
	 * Supports dry_run.
	 *
	 * @param mixed $request REST request-like object ($request['id'], get_param()).
	 * @return WP_REST_Response
	 */
	public static function media_update_meta( $request ) {
		$id   = absint( $request['id'] );
		$post = get_post( $id );

		// Gate ordering matches media_set_featured_image()/media_get(): not_found
		// before forbidden, and an id that resolves to a real-but-wrong-type post
		// (e.g. a page) is reported the same way as a missing id, mirroring
		// media_get()'s type-guard precedent exactly.
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return self::envelope_error(
				'not_found',
				"Attachment #{$id} not found.",
				'Use diviops_media_list to find a valid attachment id.',
				404,
				array( 'attachment_id' => $id )
			);
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return self::envelope_error(
				'forbidden',
				"You are not allowed to edit attachment #{$id}.",
				'Authenticate as a user who can edit this attachment.',
				403,
				array( 'attachment_id' => $id )
			);
		}

		// $request->get_param() returns null for a param that was never sent
		// and '' for one explicitly sent as an empty string — that distinction
		// is what separates "leave untouched" from "clear this field", so it
		// must be read via get_param() here rather than defaulting to '' the
		// way media_upload() does for its own (always-optional) alt/caption.
		$alt_param     = $request->get_param( 'alt' );
		$caption_param = $request->get_param( 'caption' );
		$has_alt       = null !== $alt_param;
		$has_caption   = null !== $caption_param;

		if ( ! $has_alt && ! $has_caption ) {
			return self::envelope_error(
				'invalid_input',
				'Provide at least one of alt or caption.',
				null,
				400
			);
		}

		// Same read logic media_get() uses, so noop detection and the
		// resulting response are consistent with what a caller would see by
		// reading the attachment back afterward.
		$current_alt     = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$current_caption = (string) get_post_field( 'post_excerpt', $id );

		$new_alt     = $has_alt ? sanitize_text_field( (string) $alt_param ) : $current_alt;
		$new_caption = $has_caption ? sanitize_text_field( (string) $caption_param ) : $current_caption;

		$noop = ( $new_alt === $current_alt ) && ( $new_caption === $current_caption );

		$dry_run = self::media_bool_param( $request, 'dry_run' );

		if ( $dry_run ) {
			return self::media_update_meta_dry_run( $id, $has_alt, $has_caption, $current_alt, $new_alt, $current_caption, $new_caption, $noop );
		}

		if ( $noop ) {
			return self::envelope_success(
				array(
					'attachment_id' => $id,
					'alt'           => $new_alt,
					'caption'       => $new_caption,
					'noop'          => true,
				)
			);
		}

		if ( $has_alt ) {
			// An empty alt is CLEARED (meta deleted), not stored as an empty
			// string — this is what makes a cleared alt read back identically
			// to an attachment that never had alt meta set, via media_get().
			if ( '' === $new_alt ) {
				delete_post_meta( $id, '_wp_attachment_image_alt' );
			} else {
				update_post_meta( $id, '_wp_attachment_image_alt', $new_alt );
			}
		}
		if ( $has_caption ) {
			// Unlike alt, an empty post_excerpt is a valid, storable value —
			// WordPress allows an empty excerpt, so clearing the caption is
			// just an ordinary write of ''.
			wp_update_post(
				array(
					'ID'           => $id,
					'post_excerpt' => $new_caption,
				)
			);
		}

		return self::envelope_success(
			array(
				'attachment_id' => $id,
				'alt'           => $new_alt,
				'caption'       => $new_caption,
				'noop'          => false,
			)
		);
	}

	/** Build the dry_run plan response for media_update_meta(). */
	private static function media_update_meta_dry_run( int $id, bool $has_alt, bool $has_caption, string $current_alt, string $new_alt, string $current_caption, string $new_caption, bool $noop ) {
		$changes = array();
		if ( $has_alt && $new_alt !== $current_alt ) {
			$changes[] = array(
				'kind'   => 'update_alt',
				'target' => "attachment#{$id}",
				'before' => $current_alt,
				'after'  => $new_alt,
			);
		}
		if ( $has_caption && $new_caption !== $current_caption ) {
			$changes[] = array(
				'kind'   => 'update_caption',
				'target' => "attachment#{$id}",
				'before' => $current_caption,
				'after'  => $new_caption,
			);
		}

		$summary = $noop
			? "Attachment #{$id}'s alt/caption already match the requested values — no-op."
			: "Would update attachment #{$id}: " . implode( ', ', array_column( $changes, 'kind' ) ) . '.';

		return self::dry_run_response(
			$summary,
			$changes,
			array(),
			array( 'attachment_id' => $id )
		);
	}

	/**
	 * Resolve a redirect's Location header against the URL it was returned
	 * from. Handles: an already-absolute target (has both scheme and host,
	 * passes through unchanged); protocol-relative (`//host/path`, inherits
	 * only the base's scheme); root-relative (`/path`, inherits scheme/host/
	 * port); and bare/document-relative (`file.png`, `../x.png`, resolved
	 * against the base URL's directory with `.`/`..` segments collapsed).
	 * Anything that still can't be resolved to an absolute URL is returned
	 * as-is — the caller (media_fetch_to_temp()) checks for that and fails
	 * closed with `fetch_failed` rather than looping on a broken target. The
	 * result is NOT trusted here: the next hop's media_url_is_safe() call
	 * still fully revalidates it (scheme/host/resolved-address), exactly
	 * like every other redirect target.
	 */
	private static function media_absolutize_url( string $location, string $base ): string {
		$loc_parts = wp_parse_url( $location );
		$loc_parts = is_array( $loc_parts ) ? $loc_parts : array();
		if ( ! empty( $loc_parts['scheme'] ) && ! empty( $loc_parts['host'] ) ) {
			return $location;
		}
		$base_parts = wp_parse_url( $base );
		$base_parts = is_array( $base_parts ) ? $base_parts : array();
		$host       = $base_parts['host'] ?? '';
		if ( '' === $host ) {
			return $location;
		}
		$scheme = $base_parts['scheme'] ?? 'https';
		$port   = isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '';

		// Protocol-relative (//host/path): inherit only the base's scheme.
		if ( 0 === strpos( $location, '//' ) ) {
			return "{$scheme}:{$location}";
		}

		// Root-relative (/path): inherit scheme/host/port.
		if ( 0 === strpos( $location, '/' ) ) {
			return "{$scheme}://{$host}{$port}{$location}";
		}

		// Bare/document-relative (file.png, ../x.png, ./x.png): resolve
		// against the base URL's directory, then collapse ./.. segments.
		$base_path = $base_parts['path'] ?? '/';
		$slash_pos = strrpos( $base_path, '/' );
		$base_dir  = false !== $slash_pos ? substr( $base_path, 0, $slash_pos + 1 ) : '/';
		$combined  = self::media_collapse_relative_path( $base_dir . $location );

		return "{$scheme}://{$host}{$port}{$combined}";
	}

	/**
	 * Collapse `.`/`..` segments out of a URL path, e.g.
	 * `/a/b/../c.png` -> `/a/c.png`. Used by media_absolutize_url() when
	 * resolving a document-relative redirect Location.
	 */
	private static function media_collapse_relative_path( string $path ): string {
		$segments = explode( '/', $path );
		$stack    = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $stack );
				continue;
			}
			$stack[] = $segment;
		}
		return '/' . implode( '/', $stack );
	}

	/**
	 * REST-boundary validator for the media route target-id params
	 * (attach_to, attachment_id, post_id): absent/empty is valid — these
	 * fields are optional and "no target" is a legitimate state — but a
	 * present value must be a positive integer. 0 and negative values
	 * previously passed the route's args schema and reached handler logic
	 * that generally treats an unset/zero target the same as no target,
	 * silently discarding a caller's actual (invalid) intent (#81).
	 *
	 * @param mixed  $value Raw param value as REST delivers it (int or numeric string).
	 * @param string $param Field name, for the error message.
	 * @return true|WP_Error
	 */
	private static function media_validate_positive_id( $value, string $param ) {
		if ( null === $value || '' === $value ) {
			return true;
		}
		if ( ! is_numeric( $value ) || (float) $value !== (float) (int) $value || (int) $value <= 0 ) {
			return new WP_Error( 'rest_invalid_param', "{$param} must be a positive integer.", array( 'status' => 400 ) );
		}
		return true;
	}
}
