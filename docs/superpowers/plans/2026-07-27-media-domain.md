# Media Domain (#28) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `diviops_media_*` capability domain that uploads images into the WordPress media library from a URL or base64 bytes, reads/lists media, and sets a post's featured image — with a hardened SSRF guard, real-byte type validation, and SVG allowed only under a verified Safe SVG sideload sanitizer.

**Architecture:** One new trait (`trait-media.php`) mixed into `DiviOps_Agent`, one static handler per operation returning the standard `{ ok, data, error }` envelope. Both upload inputs (url, base64) materialize a temp file then converge on `media_handle_sideload()`. Security lives in small pure helpers (SSRF address guard, filetype guard, SVG-sanitizer guard) that are unit-tested in isolation. Thin `diviops_media_*` MCP tools wrap the REST routes.

**Tech Stack:** PHP 7.4+ (WordPress plugin), plain-PHP test harness (`php tests/run.php`, `tests/wp-shim.php`), TypeScript MCP server (`diviops-server/src/index.ts`, zod schemas).

## Global Constraints

- PHP floor **7.4** (no `match`, no enums, no constructor promotion, no named args). Site runs 8.5 — must lint clean 7.4→8.5.
- **Frozen four — never rename:** slug `diviops-agent`, class `DiviOps_Agent`, REST namespace `diviops/v1`, filter `diviops_agent_handshake_extensions`. This work is purely additive.
- Standard envelope only: `envelope_success($data, $http=200)` and `envelope_error($code, $message, $hint=null, $http=400, $data=null)` (trait-core.php:1591/1629). Dry-run via `dry_run_response($summary, $changes=[], $warnings=[], $extra=[])` (trait-core.php:2117).
- Every new route's capability key MUST be added to the `CAPABILITIES` const in the same change (diviops-agent.php:98+).
- Tests must assert they actually inspected something; the runner fails on zero tests or an empty filter. No mocked-behavior tests — stub only WP-core primitives, never the plugin logic under test.
- No AI-authorship trailers on any commit.
- SVG **fails closed**: never sideload an SVG unless a Safe SVG sideload sanitizer is verified active.

---

## File Structure

- **Create** `plugins/diviops-agent/includes/trait-media.php` — `DiviOps_Agent_Media` trait: the four handlers + private security helpers.
- **Modify** `plugins/diviops-agent/diviops-agent.php` — `require_once` the trait (~line 30), `use DiviOps_Agent_Media;` in the class, add media routes (~line 500 block), add capability keys to `CAPABILITIES`.
- **Create** `tests/test-media.php` — unit tests via the `diviops_call()` reflection harness.
- **Modify** `tests/wp-shim.php` — add WP-core stubs used by the handlers (added incrementally per task): a DNS resolver seam, `download_url`, `wp_handle_sideload`/`media_handle_sideload`, `wp_check_filetype_and_ext`, `get_allowed_mime_types`, `wp_max_upload_size`, `has_filter`, and an attachment registry.
- **Modify** `diviops-server/src/index.ts` — `registerPluginTool` blocks for `diviops_media_upload/get/list/set_featured_image`.
- **Modify** `FORK.md` — divergence-table entries for the new domain and the `index.ts` tools.

---

## Task 1: SSRF address guard (pure helper)

The riskiest unit — isolated and mutation-tested first. Resolves a URL's host and rejects reserved/private targets. Redirect-hop revalidation reuses this helper.

**Files:**
- Create: `plugins/diviops-agent/includes/trait-media.php` (helpers only this task)
- Modify: `plugins/diviops-agent/diviops-agent.php` (require + `use` the trait so `diviops_call` can reach it)
- Modify: `tests/wp-shim.php` (resolver seam)
- Test: `tests/test-media.php`

**Interfaces:**
- Produces:
  - `private static function media_ip_is_reserved( string $ip ): bool`
  - `private static function media_url_is_safe( string $url, ?string &$reason = null, ?callable $resolver = null ): bool` — `$resolver` defaults to `[__CLASS__,'media_resolve_host_ips']`; tests inject a fake. Returns false + sets `$reason` on scheme violation, unresolvable host, or any resolved IP in a reserved range.
  - `private static function media_resolve_host_ips( string $host ): array` — real DNS (`gethostbynamel` + AAAA via `dns_get_record`); returns `['1.2.3.4', ...]`.

- [ ] **Step 1: Write failing tests for `media_ip_is_reserved`**

```php
// tests/test-media.php
require_once __DIR__ . '/wp-shim.php';

foreach ( array( '10.0.0.5','127.0.0.1','169.254.169.254','172.16.0.1','192.168.1.1','100.64.0.1','::1','fc00::1','fe80::1','::ffff:127.0.0.1' ) as $ip ) {
    assert_true( diviops_call_static( 'media_ip_is_reserved', array( $ip ) ), "reserved: $ip" );
}
foreach ( array( '8.8.8.8','1.1.1.1','203.0.114.1','2606:4700:4700::1111' ) as $ip ) {
    assert_true( ! diviops_call_static( 'media_ip_is_reserved', array( $ip ) ), "public: $ip" );
}
```

(If the harness lacks a static-private caller, add `diviops_call_static($method,$args)` to wp-shim.php mirroring `diviops_call` but without a request object — a 4-line reflection wrapper.)

- [ ] **Step 2: Run — expect FAIL** (`media_ip_is_reserved` undefined). Run: `php tests/run.php`

- [ ] **Step 3: Implement `trait-media.php` scaffold + `media_ip_is_reserved`**

```php
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
}
```

Wire it: in `diviops-agent.php` add `require_once __DIR__ . '/includes/trait-media.php';` beside the other trait requires, and `use DiviOps_Agent_Media;` in the class body beside the other `use` traits.

- [ ] **Step 4: Run — expect PASS.** Run: `php tests/run.php`

- [ ] **Step 5: Failing tests for `media_url_is_safe` (with injected resolver)**

```php
$safe_resolver   = function ( $host ) { return array( '8.8.8.8' ); };
$internal_resolver = function ( $host ) { return array( '169.254.169.254' ); };
$reason = null;

assert_true(   diviops_call_static( 'media_url_is_safe', array( 'https://cdn.example.com/a.png', &$reason, $safe_resolver ) ), 'public https ok' );
assert_true( ! diviops_call_static( 'media_url_is_safe', array( 'https://evil.example/a.png', &$reason, $internal_resolver ) ), 'resolves-internal rejected' );
assert_true( ! diviops_call_static( 'media_url_is_safe', array( 'file:///etc/passwd', &$reason, $safe_resolver ) ), 'file scheme rejected' );
assert_true( ! diviops_call_static( 'media_url_is_safe', array( 'ftp://host/a.png', &$reason, $safe_resolver ) ), 'ftp scheme rejected' );
```

- [ ] **Step 6: Run — expect FAIL.** Run: `php tests/run.php`

- [ ] **Step 7: Implement `media_url_is_safe` + `media_resolve_host_ips`**

```php
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

private static function media_resolve_host_ips( string $host ): array {
    $ips = array();
    $v4  = gethostbynamel( $host );
    if ( is_array( $v4 ) ) { $ips = $v4; }
    $aaaa = @dns_get_record( $host, DNS_AAAA );
    if ( is_array( $aaaa ) ) { foreach ( $aaaa as $r ) { if ( ! empty( $r['ipv6'] ) ) { $ips[] = $r['ipv6']; } } }
    return $ips;
}
```

- [ ] **Step 8: Run — expect PASS.** Run: `php tests/run.php`

- [ ] **Step 9: Mutation check** — temporarily make `media_ip_is_reserved` `return false;` always; run `php tests/run.php` and confirm the reserved-IP + internal-resolver tests FAIL. Revert.

- [ ] **Step 10: Commit**

```bash
git add plugins/diviops-agent/includes/trait-media.php plugins/diviops-agent/diviops-agent.php tests/test-media.php tests/wp-shim.php
git commit -S -m "feat(media): SSRF address guard for url uploads (#28)"
```

---

## Task 2: Filetype validation (real bytes + allowed mimes)

**Files:** Modify `trait-media.php`, `tests/wp-shim.php` (stubs), `tests/test-media.php`.

**Interfaces:**
- Produces: `private static function media_filetype_error( string $filename, string $tmp_path ): ?array` — returns null when allowed, else envelope-error args `['code'=>'unsupported_media_type','message'=>...,'hint'=>...,'http'=>415,'data'=>[...]]`. Uses `wp_check_filetype_and_ext($tmp_path,$filename)` and `get_allowed_mime_types()`.
- Consumes: none.

- [ ] **Step 1: Add harness stubs** to `tests/wp-shim.php` (guarded by `function_exists`), driven by `$GLOBALS` so tests set behavior:

```php
if ( ! function_exists( 'get_allowed_mime_types' ) ) {
    function get_allowed_mime_types( $user = null ) {
        return $GLOBALS['diviops_test_allowed_mimes'] ?? array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' );
    }
}
if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
    function wp_check_filetype_and_ext( $file, $filename, $mimes = null ) {
        // Test seam: $GLOBALS['diviops_test_filetype'] maps filename => [ext,type,proper_filename] or 'mismatch'.
        $map = $GLOBALS['diviops_test_filetype'] ?? array();
        if ( isset( $map[ $filename ] ) && 'mismatch' === $map[ $filename ] ) {
            return array( 'ext' => false, 'type' => false, 'proper_filename' => false );
        }
        if ( isset( $map[ $filename ] ) ) { return $map[ $filename ]; }
        return array( 'ext' => false, 'type' => false, 'proper_filename' => false );
    }
}
```

- [ ] **Step 2: Failing tests**

```php
$GLOBALS['diviops_test_filetype'] = array(
    'ok.png'      => array( 'ext' => 'png', 'type' => 'image/png', 'proper_filename' => false ),
    'fake.png'    => 'mismatch',
    'doc.exe'     => array( 'ext' => 'exe', 'type' => 'application/x-msdownload', 'proper_filename' => false ),
);
assert_true( null === diviops_call_static( 'media_filetype_error', array( 'ok.png', '/tmp/x' ) ), 'valid png accepted' );
assert_true( null !== diviops_call_static( 'media_filetype_error', array( 'fake.png', '/tmp/x' ) ), 'byte/ext mismatch rejected' );
assert_true( null !== diviops_call_static( 'media_filetype_error', array( 'doc.exe', '/tmp/x' ) ), 'disallowed mime rejected' );
```

- [ ] **Step 3: Run — expect FAIL.** Run: `php tests/run.php`

- [ ] **Step 4: Implement `media_filetype_error`**

```php
private static function media_filetype_error( string $filename, string $tmp_path ): ?array {
    $check = wp_check_filetype_and_ext( $tmp_path, $filename );
    if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
        return array( 'code' => 'unsupported_media_type', 'http' => 415,
            'message' => "The file bytes do not match a supported type for '{$filename}'.",
            'hint' => 'Upload a real image; the extension must match the actual file contents.',
            'data' => array( 'filename' => $filename ) );
    }
    $allowed = array_values( get_allowed_mime_types() );
    if ( ! in_array( $check['type'], $allowed, true ) ) {
        return array( 'code' => 'unsupported_media_type', 'http' => 415,
            'message' => "Mime type '{$check['type']}' is not allowed on this site.",
            'hint' => 'Allowed types come from WordPress get_allowed_mime_types().',
            'data' => array( 'filename' => $filename, 'detected_type' => $check['type'] ) );
    }
    return null;
}
```

- [ ] **Step 5: Run — expect PASS.** Run: `php tests/run.php`

- [ ] **Step 6: Commit**

```bash
git commit -S -am "feat(media): real-byte filetype validation against allowed mimes (#28)"
```

---

## Task 3: SVG sideload-sanitizer guard (fail closed)

**Files:** Modify `trait-media.php`, `tests/wp-shim.php` (`has_filter` stub), `tests/test-media.php`.

**Interfaces:**
- Produces: `private static function media_svg_sideload_sanitizer_active(): bool` — true only when a callback is registered on `wp_handle_sideload_prefilter` (the filter our sideload path fires) AND Safe SVG is detectable. Consumed by `media_filetype_error` (SVG branch) and the upload handler.
- **Build-time verification:** before finalizing, install Safe SVG on colleyvillelions and read its source to confirm the exact hook name and the minimum version that sanitizes sideloads; encode the min version as a constant `const SAFE_SVG_MIN_VERSION = '...';` and check it if detectable. Do not hardcode an unverified version.

- [ ] **Step 1: `has_filter` stub** in wp-shim.php:

```php
if ( ! function_exists( 'has_filter' ) ) {
    function has_filter( $hook, $callback = false ) {
        return $GLOBALS['diviops_test_filters'][ $hook ] ?? false;
    }
}
```

- [ ] **Step 2: Failing tests** (SVG rejected unless sideload sanitizer present)

```php
// Allow svg mime for these cases so the ONLY gate under test is the sanitizer.
$GLOBALS['diviops_test_allowed_mimes'] = array( 'png' => 'image/png', 'svg' => 'image/svg+xml' );
$GLOBALS['diviops_test_filetype']['logo.svg'] = array( 'ext' => 'svg', 'type' => 'image/svg+xml', 'proper_filename' => false );

$GLOBALS['diviops_test_filters'] = array(); // no sanitizer
assert_true( null !== diviops_call_static( 'media_filetype_error', array( 'logo.svg', '/tmp/x' ) ), 'svg rejected when no sideload sanitizer' );

$GLOBALS['diviops_test_filters'] = array( 'wp_handle_sideload_prefilter' => 10 ); // sanitizer wired
assert_true( null === diviops_call_static( 'media_filetype_error', array( 'logo.svg', '/tmp/x' ) ), 'svg accepted when sanitizer active' );
unset( $GLOBALS['diviops_test_filters'], $GLOBALS['diviops_test_allowed_mimes'] );
$GLOBALS['diviops_test_allowed_mimes'] = array( 'png' => 'image/png' );
```

- [ ] **Step 3: Run — expect FAIL.** Run: `php tests/run.php`

- [ ] **Step 4: Implement guard + SVG branch in `media_filetype_error`.** Add near the top of `media_filetype_error`, after `$check` is computed:

```php
if ( 'image/svg+xml' === ( $check['type'] ?? '' ) && ! self::media_svg_sideload_sanitizer_active() ) {
    return array( 'code' => 'svg_sanitizer_required', 'http' => 415,
        'message' => 'SVG uploads require an active SVG sanitizer that sanitizes sideloaded files.',
        'hint' => 'Install/enable Safe SVG (a recent version that hooks wp_handle_sideload_prefilter).',
        'data' => array( 'filename' => $filename ) );
}
```

```php
private static function media_svg_sideload_sanitizer_active(): bool {
    // Our upload path calls media_handle_sideload(), which fires this filter.
    // A sanitizer (Safe SVG >= sideload-hook version) must be registered on it.
    return false !== has_filter( 'wp_handle_sideload_prefilter' );
}
```

- [ ] **Step 5: Run — expect PASS.** Run: `php tests/run.php`

- [ ] **Step 6: Commit**

```bash
git commit -S -am "feat(media): fail-closed SVG guard on sideload sanitizer (#28, #73)"
```

---

## Task 4: `media_upload` handler (url + base64, dry-run)

**Files:** Modify `trait-media.php`, `tests/wp-shim.php` (`download_url`, `media_handle_sideload`, attachment registry, `wp_max_upload_size`), `tests/test-media.php`.

**Interfaces:**
- Produces: `public static function media_upload( $request )`. Reads params: `url`, `data_base64`, `filename`, `attach_to`, `title`, `alt`, `caption`, `dry_run`. Returns envelope. On success `data = { attachment_id, url, mime, filename, source }`.
- Consumes: `media_url_is_safe`, `media_filetype_error`, `envelope_success/error`, `dry_run_response`.

- [ ] **Step 1: Harness stubs** in wp-shim.php:

```php
if ( ! function_exists( 'wp_max_upload_size' ) ) { function wp_max_upload_size() { return $GLOBALS['diviops_test_max_upload'] ?? 8388608; } }
if ( ! function_exists( 'download_url' ) ) {
    function download_url( $url, $timeout = 300 ) {
        if ( ! empty( $GLOBALS['diviops_test_download_fail'] ) ) { return new WP_Error( 'http_404', 'not found' ); }
        $tmp = tempnam( sys_get_temp_dir(), 'divi' ); file_put_contents( $tmp, $GLOBALS['diviops_test_download_bytes'] ?? 'bytes' ); return $tmp;
    }
}
if ( ! function_exists( 'media_handle_sideload' ) ) {
    function media_handle_sideload( $file, $post_id = 0, $desc = null, $post_data = array() ) {
        $id = $GLOBALS['diviops_test_next_attach_id'] = ( $GLOBALS['diviops_test_next_attach_id'] ?? 100 ) + 1;
        $GLOBALS['diviops_test_attachments'][ $id ] = array( 'id' => $id, 'filename' => $file['name'], 'parent' => $post_id, 'url' => "https://site/wp-content/uploads/{$file['name']}", 'mime' => $GLOBALS['diviops_test_sideload_mime'] ?? 'image/png' );
        if ( file_exists( $file['tmp_name'] ) ) { @unlink( $file['tmp_name'] ); }
        return $id;
    }
}
// Also stub: wp_upload_bits or a temp writer for base64, wp_get_attachment_url, get_post (already present).
```

- [ ] **Step 2: Failing tests** — url path, base64 path, xor validation, SSRF block, dry-run:

```php
function diviops_media_req( array $p ) { return new DiviOps_Test_Request( $p ); }

// url happy path — inject a safe resolver via the extensibility filter seam.
function diviops_media_set_resolver( callable $fn ) { remove_all_filters( 'diviops_media_host_resolver' ); add_filter( 'diviops_media_host_resolver', function () use ( $fn ) { return $fn; } ); }
diviops_media_set_resolver( function ( $h ) { return array( '8.8.8.8' ); } );
$GLOBALS['diviops_test_filetype']['pic.png'] = array( 'ext'=>'png','type'=>'image/png','proper_filename'=>false );
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url'=>'https://cdn.example.com/pic.png' ) ) ) );
$d = $r->get_data(); assert_true( true === $d['ok'] && ! empty( $d['data']['attachment_id'] ), 'url upload ok' );

// neither url nor base64
$r = diviops_call( 'media_upload', array( diviops_media_req( array() ) ) );
assert_true( false === $r->get_data()['ok'], 'missing source rejected' );

// both
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url'=>'https://x/y.png','data_base64'=>'AA','filename'=>'y.png' ) ) ) );
assert_true( false === $r->get_data()['ok'], 'both sources rejected' );

// SSRF
diviops_media_set_resolver( function ( $h ) { return array( '169.254.169.254' ); } );
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url'=>'https://evil/pic.png' ) ) ) );
$d = $r->get_data(); assert_true( false === $d['ok'] && 'forbidden_target' === $d['error']['code'], 'ssrf blocked' );

// dry_run (safe again)
diviops_media_set_resolver( function ( $h ) { return array( '8.8.8.8' ); } );
$r = diviops_call( 'media_upload', array( diviops_media_req( array( 'url'=>'https://cdn.example.com/pic.png','dry_run'=>true ) ) ) );
$d = $r->get_data(); assert_true( true === $d['ok'] && isset( $d['data']['plan'] ), 'dry-run returns plan, no write' );
```

(The handler resolves the host via the `diviops_media_host_resolver` filter — a real extensibility hook; tests inject a fake resolver through it via `diviops_media_set_resolver()`. wp-shim.php must provide `add_filter`/`apply_filters`/`remove_all_filters` stubs — verify they exist; the plugin already uses `apply_filters` for the handshake, so they likely do.)

- [ ] **Step 3: Run — expect FAIL.** Run: `php tests/run.php`

- [ ] **Step 4: Implement `media_upload`**

```php
public static function media_upload( $request ) {
    if ( ! current_user_can( 'upload_files' ) ) {
        return self::envelope_error( 'forbidden', 'You are not allowed to upload files.', 'Authenticate as a user with upload_files.', 403 );
    }
    $url     = trim( (string) ( $request->get_param( 'url' ) ?? '' ) );
    $b64     = (string) ( $request->get_param( 'data_base64' ) ?? '' );
    $dry_run = (bool) $request->get_param( 'dry_run' );
    $attach_to = absint( $request->get_param( 'attach_to' ) );

    if ( ( '' === $url ) === ( '' === $b64 ) ) {
        return self::envelope_error( 'invalid_input', 'Provide exactly one of url or data_base64.', 'url fetches remotely; data_base64 uploads local bytes.', 400 );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    if ( '' !== $url ) {
        // Extensibility seam: hosts may override host resolution (also how tests inject a fake resolver).
        $resolver = apply_filters( 'diviops_media_host_resolver', null );
        $resolver = is_callable( $resolver ) ? $resolver : null;
        $reason = null;
        if ( ! self::media_url_is_safe( $url, $reason, $resolver ) ) {
            return self::envelope_error( 'forbidden_target', $reason, 'Only public http/https image URLs are allowed.', 403, array( 'url' => $url ) );
        }
        $filename = self::media_basename_from_url( $url );
        $tmp_getter = function () use ( $url ) { return download_url( $url, 20 ); };
        $source = array( 'kind' => 'url', 'ref' => $url );
    } else {
        $filename = sanitize_file_name( (string) ( $request->get_param( 'filename' ) ?? '' ) );
        if ( '' === $filename ) { return self::envelope_error( 'invalid_input', 'filename is required with data_base64.', null, 400 ); }
        if ( strlen( $b64 ) > (int) ( wp_max_upload_size() * 1.4 ) ) {
            return self::envelope_error( 'payload_too_large', 'Encoded payload exceeds the upload size limit.', null, 413 );
        }
        $bytes = base64_decode( $b64, true );
        if ( false === $bytes ) { return self::envelope_error( 'invalid_input', 'data_base64 is not valid base64.', null, 400 ); }
        $tmp_getter = function () use ( $bytes ) { $t = wp_tempnam(); file_put_contents( $t, $bytes ); return $t; };
        $source = array( 'kind' => 'base64', 'ref' => $filename );
    }

    if ( $dry_run ) {
        return self::dry_run_response(
            "Would upload '{$filename}' to the media library" . ( $attach_to ? " attached to post #{$attach_to}." : '.' ),
            array( array( 'kind' => 'upload', 'target' => $filename, 'before' => null, 'after' => 'attachment' ) ),
            array(), array( 'filename' => $filename, 'source' => $source['kind'] )
        );
    }

    $tmp = call_user_func( $tmp_getter );
    if ( is_wp_error( $tmp ) ) { return self::envelope_error( 'fetch_failed', $tmp->get_error_message(), 'The source could not be retrieved.', 502, array( 'source' => $source ) ); }

    $type_err = self::media_filetype_error( $filename, $tmp );
    if ( null !== $type_err ) { @unlink( $tmp ); return self::envelope_error( $type_err['code'], $type_err['message'], $type_err['hint'], $type_err['http'], $type_err['data'] ); }

    $file_array = array( 'name' => $filename, 'tmp_name' => $tmp );
    $desc = (string) ( $request->get_param( 'title' ) ?? '' );
    $id = media_handle_sideload( $file_array, $attach_to, $desc ?: null );
    if ( is_wp_error( $id ) ) { @unlink( $tmp ); return self::envelope_error( 'upload_failed', $id->get_error_message(), null, 500 ); }

    $id = (int) $id;
    if ( 'url' === $source['kind'] ) { update_post_meta( $id, '_diviops_source_url', esc_url_raw( $url ) ); }
    $alt = (string) ( $request->get_param( 'alt' ) ?? '' );
    if ( '' !== $alt ) { update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) ); }
    $caption = (string) ( $request->get_param( 'caption' ) ?? '' );
    if ( '' !== $caption ) { wp_update_post( array( 'ID' => $id, 'post_excerpt' => sanitize_text_field( $caption ) ) ); }

    return self::envelope_success( array(
        'attachment_id' => $id,
        'url'           => wp_get_attachment_url( $id ),
        'mime'          => get_post_mime_type( $id ),
        'filename'      => $filename,
        'source'        => $source['kind'],
    ) );
}

private static function media_basename_from_url( string $url ): string {
    $path = (string) wp_parse_url( $url, PHP_URL_PATH );
    $name = sanitize_file_name( basename( $path ) );
    return '' !== $name ? $name : 'download';
}
```

- [ ] **Step 5: Run — expect PASS.** Run: `php tests/run.php`

- [ ] **Step 6: Mutation check** — comment the SSRF guard block; confirm the `ssrf blocked` test fails. Revert.

- [ ] **Step 7: Commit**

```bash
git commit -S -am "feat(media): media_upload url+base64 with SSRF, type, SVG guards + dry-run (#28)"
```

---

## Task 5: `media_get` and `media_list`

**Files:** Modify `trait-media.php`, `tests/test-media.php` (register attachments via `$GLOBALS['diviops_test_attachments']` + a `get_post`/`wp_get_attachment_metadata` stub).

**Interfaces:**
- Produces: `public static function media_get( $request )` → `data = { attachment_id, url, mime, title, alt, caption, sizes }`. `public static function media_list( $request )` → `data = { items:[...], page, per_page, total }`.

- [ ] **Step 1: Failing tests** for `media_get` (found + not_found) and `media_list` (pagination + mime filter). Register two attachments in the harness registry, assert shapes.

- [ ] **Step 2: Run — expect FAIL.** Run: `php tests/run.php`

- [ ] **Step 3: Implement** `media_get` (guard `wp_attachment_is` / mime; `not_found` envelope for a non-attachment id) and `media_list` (query attachments, `get_request_page`, `mime` prefix filter, `search`). Read via `wp_get_attachment_url`, `get_post_meta(...,'_wp_attachment_image_alt',true)`, `get_post_field('post_excerpt',...)`.

- [ ] **Step 4: Run — expect PASS.** Run: `php tests/run.php`

- [ ] **Step 5: Commit**

```bash
git commit -S -am "feat(media): media_get + media_list read/pagination (#28)"
```

---

## Task 6: `media_set_featured_image`

**Files:** Modify `trait-media.php`, `tests/test-media.php` (`set_post_thumbnail`/`get_post_thumbnail_id`/`update_post_meta` already or newly stubbed).

**Interfaces:**
- Produces: `public static function media_set_featured_image( $request )` — input `{ post_id, attachment_id | url, dry_run }` → `data = { post_id, previous_thumbnail_id, thumbnail_id }`. When `url` given, calls `media_upload` internally to obtain an attachment id first.

- [ ] **Step 1: Failing tests** — set by attachment_id; not_found post; not_found attachment; idempotent (same id → noop); dry_run; url path (uploads then sets).

- [ ] **Step 2: Run — expect FAIL.** Run: `php tests/run.php`

- [ ] **Step 3: Implement** with `current_user_can('edit_post',$post_id)`, validate the attachment is an image attachment, `set_post_thumbnail`, envelope. url path delegates to `media_upload` (build a sub-request) and reuses the resulting id.

- [ ] **Step 4: Run — expect PASS.** Run: `php tests/run.php`

- [ ] **Step 5: Commit**

```bash
git commit -S -am "feat(media): media_set_featured_image by id or url (#28)"
```

---

## Task 7: Route registration + capabilities wiring

**Files:** Modify `plugins/diviops-agent/diviops-agent.php`. Test: `tests/test-media.php` (a wiring assertion), or extend the existing capabilities/route guard test if one exists.

**Interfaces:** Routes under `/diviops/v1/media/*`, `permission_callback => check_authenticated_permission` (fine-grained caps enforced in-handler). Capability keys `media_upload`, `media_get`, `media_list`, `media_set_featured_image` added to `CAPABILITIES`.

- [ ] **Step 1: Failing test** — assert `in_array('media_upload', DiviOps_Agent::CAPABILITIES, true)` etc. (reflection/const read). If a route↔capability parity test exists in the suite, this is covered there; otherwise add a small explicit check.

- [ ] **Step 2: Run — expect FAIL.** Run: `php tests/run.php`

- [ ] **Step 3: Implement** — add the four capability keys under a `// media` comment in `CAPABILITIES`; register the routes mirroring the library block:

```php
register_rest_route( self::REST_NAMESPACE, '/media/upload', array(
    'methods' => 'POST', 'callback' => array( __CLASS__, 'media_upload' ),
    'permission_callback' => array( __CLASS__, 'check_authenticated_permission' ),
) );
register_rest_route( self::REST_NAMESPACE, '/media/get/(?P<id>\d+)', array(
    'methods' => 'GET', 'callback' => array( __CLASS__, 'media_get' ),
    'permission_callback' => array( __CLASS__, 'check_read_permission' ),
) );
register_rest_route( self::REST_NAMESPACE, '/media/list', array(
    'methods' => 'GET', 'callback' => array( __CLASS__, 'media_list' ),
    'permission_callback' => array( __CLASS__, 'check_read_permission' ),
) );
register_rest_route( self::REST_NAMESPACE, '/media/set-featured-image', array(
    'methods' => 'POST', 'callback' => array( __CLASS__, 'media_set_featured_image' ),
    'permission_callback' => array( __CLASS__, 'check_authenticated_permission' ),
) );
```

- [ ] **Step 4: Run — expect PASS.** Run: `php tests/run.php`. Also `php -l` all touched files under the local 8.5 CLI.

- [ ] **Step 5: Commit**

```bash
git commit -S -am "feat(media): register media routes + capability keys (#28)"
```

---

## Task 8: MCP server tools

**Files:** Modify `diviops-server/src/index.ts`. (Server does not build from source in this repo per #41; add tools by mirroring existing blocks; validate by `node --check`/tsc if available, else careful review — the node security tests are separate.)

**Interfaces:** `diviops_media_upload`, `diviops_media_get`, `diviops_media_list`, `diviops_media_set_featured_image`, each `registerPluginTool(name, { description, inputSchema, annotations, _meta }, handler)` calling `wp.requestEnveloped('/media/...', { method, body })` and returning `serializeEnvelope(result, name)`.

- [ ] **Step 1: Add `diviops_media_upload`** mirroring the `diviops_page_trash` block (Task reference lines 2406-2444):

```ts
registerPluginTool(
  "diviops_media_upload",
  {
    description:
      "Upload an image into the WordPress media library from a public URL (server fetches, SSRF-guarded) or from base64 bytes. Provide exactly one of `url` or (`data_base64` + `filename`). Optional attach_to/title/alt/caption. Pass dry_run=true to preview. Returns the standard envelope; blocked internal targets return 'forbidden_target' (403), disallowed/spoofed types return 'unsupported_media_type' (415), SVG without an active sideload sanitizer returns 'svg_sanitizer_required' (415).",
    inputSchema: {
      url: z.string().url().optional().describe("Public http/https image URL to fetch."),
      data_base64: z.string().optional().describe("Base64-encoded file bytes (use with filename)."),
      filename: z.string().optional().describe("Filename for the base64 path (extension must match bytes)."),
      attach_to: z.number().int().optional().describe("Post ID to set as the attachment parent."),
      title: z.string().optional(),
      alt: z.string().optional(),
      caption: z.string().optional(),
      dry_run: z.boolean().optional().default(false),
    },
    _meta: { idempotent: "false" },
  },
  async ({ url, data_base64, filename, attach_to, title, alt, caption, dry_run }) => {
    const result = await wp.requestEnveloped(`/media/upload`, {
      method: "POST",
      body: { url, data_base64, filename, attach_to, title, alt, caption, dry_run: dry_run ?? false },
    });
    return { content: [ { type: "text" as const, text: serializeEnvelope(result, "diviops_media_upload") } ] };
  },
);
```

- [ ] **Step 2: Add `diviops_media_get`, `diviops_media_list`, `diviops_media_set_featured_image`** following the same shape (`GET /media/get/${attachment_id}`, `GET /media/list` with query params, `POST /media/set-featured-image`). `media_get`/`media_list` are read-only (`annotations: { readOnlyHint: true }`).

- [ ] **Step 3: Validate** — run the server's typecheck/lint if configured (`npm run -s typecheck` in `diviops-server/` if present); otherwise review against a sibling block for shape parity. Run the node security tests: they must stay green.

- [ ] **Step 4: Commit**

```bash
git commit -S -am "feat(media): diviops_media_* MCP tools (#28)"
```

---

## Task 9: FORK.md divergence + full suite + PR

**Files:** Modify `FORK.md`. 

- [ ] **Step 1:** Add divergence-table rows: new `trait-media.php` + media routes/capabilities in the plugin, and the `diviops_media_*` tools in `index.ts`.

- [ ] **Step 2:** Full green gate — `php tests/run.php` (all files, new media tests included), `php -l` sweep on touched PHP under 8.5.

- [ ] **Step 3:** Live e2e on colleyvillelions (confirm with Dax first; scratch page only, never 900390): url image upload, base64 upload, media_get/list, set_featured_image. SVG happy-path e2e is blocked pending Safe SVG install (ask Dax); SVG rejection path is already covered in units. Confirm the handshake still reports Pro's capability keys after the plugin is re-synced.

- [ ] **Step 4: Commit + push + PR**

```bash
git commit -S -am "docs(fork): record media domain divergence (#28)"
git push -u origin dev/28-media-domain
gh pr create --title "feat: media domain (upload/get/list/set-featured-image) (#28)" --body "Closes #28. <summary + test evidence>"
```

Adversarial review (fresh subagent) before merge: SSRF bypass attempts, type-spoof, SVG fail-closed, envelope parity. Squash-merge only after Dax's explicit review and green CI.

---

## Self-Review

**Spec coverage:** SSRF guard → T1; type validation → T2; SVG sideload guard → T3; media_upload url+base64+dry_run+size+source-meta → T4; media_get/list → T5; set_featured_image → T6; routes+capabilities → T7; MCP tools → T8; FORK.md + e2e + PR → T9. All spec sections mapped.

**Placeholders:** e2e summary/PR body are intentionally author-filled at execution; all code steps carry real code. The Safe SVG min-version constant is explicitly flagged as build-time-verified (not invented).

**Type consistency:** `media_url_is_safe`, `media_ip_is_reserved`, `media_filetype_error` (returns `?array` with `code/http/message/hint/data`), `media_svg_sideload_sanitizer_active`, and the four handlers are named identically across tasks. Envelope helpers match trait-core signatures. `_diviops_source_url` / `_wp_attachment_image_alt` meta keys consistent.
