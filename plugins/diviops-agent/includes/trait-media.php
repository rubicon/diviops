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
}
