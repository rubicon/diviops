<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

trait DiviOps_Agent_Media {

	/** IPv4/IPv6 reserved-range test. Unwraps IPv4-mapped IPv6 and re-checks. */
	private static function media_ip_is_reserved( string $ip ): bool {
		$packed = @inet_pton( $ip );
		if ( false === $packed ) { return true; } // unparseable => unsafe
		// IPv4-mapped IPv6 (::ffff:a.b.c.d) -> re-check embedded v4.
		if ( 16 === strlen( $packed ) && "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" === substr( $packed, 0, 12 ) ) {
			$ip = inet_ntop( substr( $packed, 12 ) );
			$packed = inet_pton( $ip );
		}
		if ( 4 === strlen( $packed ) ) {
			$n = unpack( 'Nip', $packed )['ip'];
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
	 * $wp_filter directly for a callback bound to a `safe_svg` instance. Fail
	 * closed: if Safe SVG isn't loaded, the `safe_svg` class is undefined and
	 * `instanceof safe_svg` is false (safe in PHP even against an undefined
	 * class), so an unrecognized or absent callback is rejected.
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
				// instanceof against a possibly-undefined class is safe in PHP (returns false).
				if ( is_array( $fn ) && isset( $fn[0] ) && is_object( $fn[0] ) && $fn[0] instanceof safe_svg ) {
					return true;
				}
			}
		}
		return false;
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
		$dry_run   = (bool) $request->get_param( 'dry_run' );
		$attach_to = absint( $request->get_param( 'attach_to' ) );

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
			// Extensibility seam: hosts may override host resolution (also how tests inject a fake resolver).
			$resolver = apply_filters( 'diviops_media_host_resolver', null );
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
			return self::envelope_error( 'fetch_failed', $tmp->get_error_message(), 'The source could not be retrieved.', 502, array( 'source' => $source ) );
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
	 * @return string|WP_Error Temp file path on success, WP_Error otherwise.
	 */
	private static function media_fetch_to_temp( string $url, ?string &$reason = null, ?callable $resolver = null ) {
		$max_hops = 5;
		for ( $hop = 0; $hop <= $max_hops; $hop++ ) {
			if ( ! self::media_url_is_safe( $url, $reason, $resolver ) ) {
				return new WP_Error( 'forbidden_target', $reason );
			}
			$resp = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 0 ) ); // do NOT auto-follow
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
				$url = self::media_absolutize_url( $loc, $url );
				continue; // revalidate the new target on the next loop iteration
			}
			if ( $code < 200 || $code >= 300 ) {
				$reason = "Upstream returned HTTP {$code}.";
				return new WP_Error( 'fetch_failed', $reason );
			}
			$body = wp_remote_retrieve_body( $resp );
			$tmp  = wp_tempnam();
			if ( false === file_put_contents( $tmp, $body ) ) {
				$reason = 'Could not write temp file.';
				return new WP_Error( 'fetch_failed', $reason );
			}
			return $tmp;
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
			$url     = (string) wp_get_attachment_url( $post->ID );
			$items[] = array(
				'attachment_id' => (int) $post->ID,
				'url'           => $url,
				'mime'          => (string) get_post_mime_type( $post->ID ),
				'title'         => (string) $post->post_title,
				'filename'      => wp_basename( $url ),
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
	 * Resolve a redirect's Location header against the URL it was returned
	 * from. An already-absolute target (has both scheme and host) passes
	 * through unchanged; a root-relative target (`/path`) inherits the base
	 * URL's scheme/host/port. Anything else is returned as-is — the next
	 * hop's media_url_is_safe() call will reject it via the scheme/host check
	 * if it isn't a valid absolute http(s) URL, which is the correct
	 * rejection path for a malformed or unsupported Location value.
	 */
	private static function media_absolutize_url( string $location, string $base ): string {
		$loc_parts = wp_parse_url( $location );
		if ( ! empty( $loc_parts['scheme'] ) && ! empty( $loc_parts['host'] ) ) {
			return $location;
		}
		$base_parts = wp_parse_url( $base );
		$host       = $base_parts['host'] ?? '';
		if ( '' === $host ) {
			return $location;
		}
		$scheme = $base_parts['scheme'] ?? 'https';
		$port   = isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '';
		if ( 0 === strpos( $location, '/' ) ) {
			return "{$scheme}://{$host}{$port}{$location}";
		}
		return $location;
	}
}
