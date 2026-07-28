# Media domain (#28) — design spec

Date: 2026-07-27
Issue: [#28](https://github.com/rubicon/diviops/issues/28)
Status: approved (design), pending spec review before implementation

## Problem

DiviOps has no media primitive (`diviops_media_*` is absent). Divi modules that
reference images (hero/background/gallery/featured images) require the caller to
have uploaded the asset elsewhere (WP core `wp/v2/media` or a separate WP MCP)
and pass a URL/attachment id. For a harness positioning itself as a complete
Divi authoring surface, that is a real gap for image-heavy pages.

## Goal / non-goals

**Goal:** a first-class media domain that can put an image into the WordPress
media library from either a URL or raw bytes, read/list media, and set a post's
featured image — safely.

**Non-goals (deferred, tracked):**
- Generic "set an uploaded image into an arbitrary module attr path" helper —
  overlaps `module_update` (already writes any dot-path); belongs with #36/G3.
- Alt-text / caption set/get and replace/regenerate — #33 (sequenced after this).
- Admin-configurable stricter SVG capability gate — #73 (refines this).

## Architecture

One new capability domain, mirroring every existing trait:

- `plugins/diviops-agent/includes/trait-media.php` — a `DiviOps_Agent_Media`
  trait mixed into `DiviOps_Agent`, one static handler per operation.
- Route registration for `/diviops/v1/media/*` alongside the existing route
  block, gated by the standard capability check.
- `diviops_media_*` MCP tools in `diviops-server/src/index.ts` (thin wrappers,
  standard envelope + `dry_run` where applicable).

Response envelope is the existing `{ ok, data, error }` shape used by every
domain. No existing trait is modified. **Frozen-four compliance:** no changes to
the slug, `DiviOps_Agent` class, `diviops/v1` namespace, or the handshake
filter; this is purely additive.

## Operations

| Tool | Input | Output |
| --- | --- | --- |
| `media_upload` | `{ url }` **xor** `{ data_base64, filename }`; optional `attach_to` (post id), `title`, `alt`, `caption`, `dry_run` | attachment id, URL, mime, sizes, metadata |
| `media_get` | `{ attachment_id }` | id, URL, mime, sizes, alt, caption, title |
| `media_list` | `{ page=1, per_page=20, mime?, search? }` | paginated attachments + total |
| `media_set_featured_image` | `{ post_id, attachment_id }` (or `{ post_id, url }` → uploads then sets); optional `dry_run` | prior + new thumbnail id |

`url` and `data_base64` are mutually exclusive on `media_upload`; supplying both
or neither is a validation error. Both paths converge on
`media_handle_sideload()` after the source is materialized to a temp file.

## Security (the crux)

### SSRF guard (URL path)
- **Scheme allowlist:** `http` / `https` only. Reject `file:`, `ftp:`, `gopher:`,
  `data:`, etc.
- **Address blocking:** resolve the host to all A/AAAA records and reject if
  **any** resolves into a reserved/private range:
  - IPv4: `0.0.0.0/8`, `10/8`, `100.64/10`, `127/8`, `169.254/16`, `172.16/12`,
    `192.0.0/24`, `192.0.2/24`, `192.168/16`, `198.18/15`, `198.51.100/24`,
    `203.0.113/24`, `224/4`, `240/4`, `255.255.255.255`.
  - IPv6: `::1/128`, `::/128`, `fc00::/7`, `fe80::/10`, `ff00::/8`, `2001:db8::/32`;
    IPv4-mapped `::ffff:0:0/96` must be unwrapped and the embedded v4 re-checked.
- **Redirect safety:** the HTTP layer's automatic redirect-following is **not**
  used. We follow redirects explicitly with a bounded hop count (e.g. 5),
  re-running the full address guard on **each** hop's resolved IP and rejecting
  if any hop fails. This keeps legitimate CDN redirects working while ensuring a
  public URL that 302s to `169.254.169.254` cannot slip past a one-time check.
- **Known residual (documented, accepted):** full DNS-rebinding protection
  (pinning the validated IP for the actual TCP connection) is not provided —
  WP's HTTP layer resolves again at connect time. This is acceptable because the
  endpoint is authenticated and admin-privileged (not an attacker-reachable
  public surface). A follow-up could pin the resolved IP if warranted.

### Type validation (both paths)
- Honor WordPress's own `get_allowed_mime_types()` (respects site config + user
  caps: images, video, PDF, docs; excludes SVG by default).
- Every upload is verified with `wp_check_filetype_and_ext()` against the **real
  bytes**, not the filename/extension — a `.png` that isn't a PNG is rejected.

### SVG (soft-dependency on Safe SVG)
- SVG is allowed **only** when the Safe SVG plugin is active **and** its
  **sideload** sanitization is wired. Our upload path uses
  `media_handle_sideload()`, which fires `wp_handle_sideload_prefilter` — a
  *different* filter from the normal-upload `wp_handle_upload_prefilter`. Older
  Safe SVG versions hooked only the latter, so an SVG through our sideload path
  could land **unsanitized**.
- Guard: require Safe SVG present **and** confirm its sideload sanitization is
  active. The primary check is behavioral and version-agnostic — a runtime
  `has_filter('wp_handle_sideload_prefilter', …)` verifying Safe SVG's sanitizer
  callback is actually registered on the filter our path fires; a minimum-version
  constant is recorded as a secondary sanity signal. **Fail closed:** if the
  guard is not satisfied, reject SVG with an install/upgrade hint; never sideload
  an unsanitized SVG.
- **To verify at build time (not assumed):** the exact Safe SVG hook name and
  the minimum version that added sideload sanitization, read from Safe SVG's own
  source when it is installed for e2e. Perplexity/DeepSeek research points to
  `wp_handle_sideload_prefilter` and `enshrined/svg-sanitize` bumps (0.19.0 →
  0.22.0 for XSS-bypass fixes) — treated as leads to confirm, not ground truth.
- Tuning knob (documented, not built): Safe SVG exposes `svg_allowed_tags` /
  `svg_allowed_attributes` filters for AI-generated SVGs that use gradients or
  filters.

### Size limits
- Cap at `wp_max_upload_size()`. For the base64 path, bound the encoded string
  length **before** decoding (base64 inflates ~33%) so a huge payload is rejected
  without materializing it.

### Capability
- Standard `upload_files` gate, consistent with all media ops. The MCP caller is
  already an authenticated privileged user; the sanitizer is the real SVG
  defense. A stricter, admin-configurable SVG-specific gate is #73.

## Behavior defaults (chosen; override in review if desired)
- **No dedup:** re-uploading a URL creates a new attachment. The source URL is
  stashed in attachment meta (`_diviops_source_url`) so dedup is a cheap
  follow-up.
- **`dry_run`** supported on `media_upload` and `media_set_featured_image`:
  validates + reports what would happen without writing.
- **`attach_to`** optionally sets the attachment's `post_parent`.

## Error handling
- All failures return the standard envelope with a specific `error` code/message
  (validation vs. SSRF-blocked vs. type-rejected vs. SVG-guard vs. WP-core
  failure). Fail closed everywhere; never return partial success.

## Testing

Plain-PHP harness (`php tests/run.php`), new `tests/test-media.php`. Extend
`tests/wp-shim.php` with faithful stubs: `download_url`, `wp_handle_sideload` /
`media_handle_sideload`, `wp_check_filetype_and_ext`, `get_allowed_mime_types`,
`wp_max_upload_size`, `has_filter`, and an attachment registry.

Unit coverage:
- **SSRF guard, hard:** private/reserved IPv4+IPv6 rejection, IPv4-mapped IPv6,
  scheme rejection, and **redirect-to-internal** rejection. Mutation-check:
  dropping the guard must fail these.
- **Type gating:** non-allowed mime rejected; real-bytes mismatch rejected.
- **SVG:** rejection when Safe SVG absent / sideload-hook not wired (**testable
  locally** — Safe SVG is not installed). Happy-path sanitized upload requires
  Safe SVG installed (see e2e).
- **Transport:** `url` xor `data_base64` validation; base64 size bound.
- **Envelope + dry-run + `attach_to`** behavior.

Live e2e on colleyvillelions (now at 1.8.0, Pro attached):
- URL image upload (SSRF guard on a real public image), base64 image upload,
  `media_get` / `media_list`, `media_set_featured_image` on a scratch page.
- SVG happy-path e2e is **blocked on installing Safe SVG** — I will ask Dax to
  add it (or approve installing it) before that leg; the rejection path is
  verified without it.
- Do not modify page 900390; use a scratch page, confirmed with Dax first per
  site constraints.

## Deployment notes (site/server config — not this plugin)
- Disable PHP execution in `wp-content/uploads`.
- Serve uploads with `X-Content-Type-Options: nosniff`.
- Consider a restrictive CSP for SVG responses if inline SVG is ever enabled.

Captured for the docs; out of scope for the plugin code.

## Follow-ups
- #33 alt/caption (after this), #36/G3 module-attr helper, #73 configurable SVG
  gate. Record the new domain + `index.ts` divergence in FORK.md.
