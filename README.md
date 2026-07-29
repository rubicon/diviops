# DiviOps

**An AI harness for WordPress site authoring — Divi-native today, WordPress-wide by design.**

[![npm](https://img.shields.io/npm/v/@diviops/mcp-server.svg?label=%40diviops%2Fmcp-server)](https://www.npmjs.com/package/@diviops/mcp-server)
[![License: MIT & GPL-2.0](https://img.shields.io/badge/License-MIT%20%26%20GPL--2.0-yellow.svg)](LICENSE)
[![Divi 5](https://img.shields.io/badge/Divi-5.1.0%2B-7E3DD3.svg)](https://www.elegantthemes.com/gallery/divi/)

DiviOps gives Claude Code, Codex, Claude Desktop, and other MCP clients a typed control layer over WordPress site state. It pairs an MCP server, the DiviOps Agent WordPress plugin, and skill knowledge so AI agents can author Divi pages, inspect schemas, manage design tokens, work with SCF/CPT data models, run safe WP-CLI operations, and extend into target plugin coverage slices.

Divi is a registered trademark of Elegant Themes, Inc. DiviOps Agent is not affiliated with or endorsed by Elegant Themes.

```
Claude Code ◄──► MCP Server (stdio) ◄──► WordPress REST API ◄──► DiviOps Agent plugin
                                                ▲
                                                │
                                       divi-5-builder skill
                                       (block format + design rules)
```

> **Beta software.** DiviOps is under active development. Use on production sites at your own discretion. Always back up your WordPress site before running write operations.

> **Maintained fork.** This is `rubicon/diviops`, a fork of [`oaris-dev/diviops`](https://github.com/oaris-dev/diviops) that we maintain and extend: namespace-agnostic targeting so third-party Divi 5 modules (`difl/*`, `decm/*`, `d5bgo/*`) are addressable by every operation, a complete CRUD surface (library, menu, page, variable, block insertion), a layered global-layout write guard, and release automation. It stays a drop-in replacement for the stock plugin — the plugin slug, main class, REST namespace, and handshake filter are unchanged, so DiviOps Agent Pro and `@diviops/mcp-server` keep working against it. The original project and its design are the work of oaris.de; this fork honors that authorship and its licensing.

## What's in this distribution

| Component | What it is | Where it lives |
|---|---|---|
| **DiviOps Agent** WordPress plugin | REST API endpoints for Divi page data, section targeting, block validation, preset management. The contract layer between WordPress + Divi and the MCP server. | `diviops-agent.zip` at repo root |
| **`diviops-agent-pro`** WordPress plugin | Pro add-on for paid coverage slices, Pro license activation, and update gating. Requires `diviops-agent`. | `diviops-agent-pro.zip` at repo root in the Pro distribution |
| **`@diviops/mcp-server`** | Node.js MCP server that bridges MCP clients to WordPress. Distributed via npm — no clone, no build. | `npx -y --package @diviops/mcp-server diviops-mcp` |
| **`divi-5-builder`** skill | Block format rules, verified attribute paths, design patterns. Without it, agents guess attr formats and produce broken pages. | `skills/divi-5-builder/` (Claude: `claude plugin install oaris-dev/diviops`; Codex: copy `skills/*` into `~/.codex/skills`) |
| **`diviops-design-library`** plugin | Optional. CSS entrance animations, gradient text, glass effects, Three.js WebGL shaders. | `diviops-design-library.zip` at repo root |

## Use cases

DiviOps fits multiple WordPress workflows where AI-driven authoring + management is the value:

- **Page building (Divi authoring)** — create + edit Divi pages, sections, modules, canvases via prompt; preset-driven design system reuse; Theme Builder layouts and templates.
- **SCF setup + management** — provision Secure Custom Fields field groups, sync schemas, export/import field group definitions; SCF data model becomes a tool surface, not an admin-UI flow.
- **CPT + post population** — register custom post types via wp-cli passthrough; bulk-populate posts and pages across any post type, not just Divi-built ones.
- **Data model reasoning** — schema introspection across Divi modules + SCF field groups + post meta; ask Claude what fields a post type carries, what attributes a module accepts, what tokens are defined.
- **WordPress site auditing** — preset audits, design-token usage scans, orphan detection (presets, variables, dangling references); broader site surveys via wp-cli (`wp option list`, `wp post list --format=json`, `wp user list`).
- **Hybrid sites (Divi + custom PHP)** — Divi authors the marketing pages; custom PHP templates handle dynamic ones (CPT listings, single-post views, member portals); design tokens harmonized across both surfaces via CSS custom properties driven from the Divi variable system.

## Quick start

Three steps to your first tool call. For containerized environments, HTTPS configuration, and troubleshooting, see [SETUP.md](SETUP.md).

### 1. Install the WordPress plugin

Upload **`diviops-agent.zip`** (at the root of this repo) via **WP Admin → Plugins → Add New → Upload Plugin**, then activate it. Requires Divi 5.1+ on WordPress 6.5+.

Verify: visit `http://your-site.local/wp-json/diviops/v1/schema/settings` — you should get a 401 (auth required).

**Free plugin updates:** the npm MCP server updates through npm. Once the Free WordPress plugin is published on WordPress.org, WordPress delivers plugin updates through the normal **Dashboard → Updates** and **Plugins** screens. For pre-listing test packages or a manual fallback install, replace `diviops-agent.zip` through **Plugins → Add New → Upload Plugin** and choose **Replace current with uploaded**. Your Application Password and MCP config stay unchanged.

**Purchased Pro:** upload and activate **`diviops-agent-pro.zip`** after the Free plugin, then open **DiviOps → Pro License** and activate your license key. Pro runtime coverage requires the Pro plugin; license activation gates updates and support.

**WordPress.org metadata:** `diviops-agent.zip` includes the plugin-local `readme.txt`, `changelog.txt`, and asset-plan notes so the Free plugin stays WordPress.org-ready at the metadata level. WordPress.org-distributed installs use the standard WordPress.org plugin update flow; upload-based replacement remains a fallback for pre-listing test packages and environments that intentionally install from the public dist repo. A future submission still needs SVN trunk/tags state plus final production banner, icon, and screenshot assets.

### 2. Create an Application Password

In **WP Admin → Users → Your Profile → Application Passwords**:

- Enter a name (e.g. "Claude MCP")
- Click "Add New Application Password"
- **Strip the spaces** from the generated password — WordPress shows `758r WQ1X URcg ...` for readability but accepts the spaceless form, which avoids argument-parsing surprises in `claude mcp add`.

### 3. Register the MCP server

Claude Code:

```bash
claude mcp add diviops-mysite \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-wp-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- npx -y --package @diviops/mcp-server diviops-mcp
```

For Local by Flywheel (enables the `diviops_meta_wp_cli` tool), add `--env "WP_PATH=/Users/you/Local Sites/your-site/app/public"`.

For Claude Desktop, use `"command": "npx"` with args `["-y", "--package", "@diviops/mcp-server", "diviops-mcp"]`. If Claude cannot find `npx`, run `npm install -g @diviops/mcp-server@latest` and use `diviops-mcp`, or use `node "$(npm root -g)/@diviops/mcp-server/dist/index.js"`.

Codex `~/.codex/config.toml`:

```toml
[mcp_servers.diviops-mysite]
command = "npx"
args = ["-y", "--package", "@diviops/mcp-server", "diviops-mcp"]

[mcp_servers.diviops-mysite.env]
WP_URL = "http://your-site.local"
WP_USER = "your-wp-username"
WP_APP_PASSWORD = "xxxxXXXXxxxxXXXXxxxxXXXX"
```

Restart your client, then ask: **"List the pages on my site."** The assistant calls `diviops_page_list` and renders the result. You're authoring with the suite.

### 4. Load the `divi-5-builder` skill

The skill teaches the assistant the correct Divi 5 block format. Without it, the agent guesses attr formats and produces broken pages.

```bash
claude plugin install oaris-dev/diviops
```

Verify with `What skills do you have?` — you should see `divi-5-builder` listed.

This distribution includes a [`.claude-plugin/marketplace.json`](.claude-plugin/marketplace.json) manifest, so the same install command also works from a local clone of this repo (`claude plugin install <path-to-clone>`). The repository is published as a Claude Code plugin marketplace entry.

For alternative skill installation paths (cloned repo, project-local copy), see [SETUP.md](SETUP.md#step-7-load-the-divi-5-builder-skill).

For Codex, run this from the extracted DiviOps distribution or a local repo clone, then restart Codex:

```bash
mkdir -p "$HOME/.codex/skills"
cp -R skills/* "$HOME/.codex/skills/"
```

## Example workflow

> **You:** Create a hero section on a new page called "Spring Launch" with a heading, subheading, and a CTA button. Use my brand colors.

Claude orchestrates a few tool calls in sequence:

1. `diviops_global_color_list` — discovers your brand palette.
2. `diviops_template_list` / `diviops_template_get` — pulls a verified hero template that matches the request.
3. `diviops_page_create` — creates `Spring Launch` as a draft with the hero block markup.
4. `diviops_validate_blocks` — confirms the markup is well-formed before save.
5. `diviops_render_preview` — returns the rendered HTML so you can verify before publishing.

The skill enforces the Divi block format, the design system, and the response contract throughout — you stay at the prompt level.

## Tools at a glance

The suite exposes **109 always-on tools** across the categories below, plus a further set of conditionally-registered Pro tools that only appear on sites with the Pro plugin and a supported target plugin active (see [Free vs Pro](#free-vs-pro)). Per-tool descriptions, request shapes, and response payloads live in the server [README](diviops-server/README.md).

| Category | Use case | Tool prefixes |
|---|---|---|
| Page authoring | Create, edit, restructure pages | `page_*`, `section_*`, `module_*` |
| Design system | Manage colors, fonts, variables, presets | `variable_*`, `global_color_*`, `global_font_*`, `preset_*` |
| Library + templates | Reusable layouts + Theme Builder | `library_*`, `template_*`, `tb_*` |
| Media | Upload, list, and inspect attachments; alt text/caption; set featured image | `media_*` |
| Revisions | Native WordPress post-revision list/get/diff/restore | `revision_*` |
| WordPress menus | Create/edit/delete nav menus and theme-location assignments | `menu_*` |
| Schema introspection | Module attribute discovery, incl. native Divi 5 core modules | `schema_*` |
| Canvas / off-canvas | Popups, modals, menus | `canvas_*` |
| SCF integration | Secure Custom Fields sync | `scf_*` |
| Render + validate | Preview HTML, validate block markup | `render_preview`, `validate_blocks` |
| WP-CLI passthrough | Escape hatch for site ops | `meta_wp_cli` |
| Cache + meta | Connection probe, identity, icons, cache flush | `meta_*` |

### Media domain

`media_upload` creates a new attachment from exactly one of a public `url` **or**
`data_base64`+`filename`; `media_get` and `media_list` (page/per_page/mime/search)
read the media library; `media_set_featured_image` sets a post's featured image
from an existing attachment id or by uploading from a URL first;
`media_update_meta` sets and/or clears an attachment's alt text and caption — an
omitted field is left untouched, an explicit empty string clears it, the call is
idempotent (a no-op when the resulting values already match), and `dry_run` is
supported. All five follow the standard envelope and permission model (`upload_files`
for uploads, per-object `edit_post` for reads/writes on an existing attachment).

**Security.** URL uploads are SSRF-guarded: only `http`/`https` URLs are fetched, and
a host that resolves to a reserved or private IP range is rejected — this covers
plain IPv4/IPv6 ranges plus IPv4-mapped, NAT64, and 6to4/IPv4-compatible embedded-v4
addresses, so a private address can't be smuggled through an IPv6 wrapper. Redirects
are followed with the same check re-run on every hop, not just the caller's original
URL. The accepted residual risk is DNS rebinding between the check and the fetch;
the endpoint is authenticated/admin-only, which is the accepted mitigation. Every
upload — URL or base64 — is validated against its real bytes and the site's own
`get_allowed_mime_types()` via `wp_check_filetype_and_ext()`, rejecting an
extension/content mismatch independent of the declared filename.

**SVG uploads require an active SVG sanitizer.** An SVG file (`image/svg+xml`) is
only accepted when [Safe SVG](https://wordpress.org/plugins/safe-svg/) (10up) is
installed and active, and specifically has its own sanitizing callback bound to
WordPress's `wp_handle_sideload_prefilter` hook — the plugin checks for Safe SVG's
own class identity on that hook (`SafeSvg\safe_svg` for Safe SVG 2.x, the legacy
global `safe_svg` for 1.x), not merely that *something* is listening, since a mime
allowlist alone (e.g. from another plugin) doesn't sanitize the file. Without a
detected sanitizer, an SVG upload fails closed with `svg_sanitizer_required` (HTTP
415) rather than accepting an unsanitized file. If you need SVG support, install and
activate Safe SVG; merely allowing the SVG mime type elsewhere is not sufficient.

If you enable SVG uploads, also harden the deployment itself (server/site
configuration, not something the plugin can do for you): disable PHP execution
inside `wp-content/uploads`, serve uploads with the `X-Content-Type-Options: nosniff`
response header, and consider a restrictive Content-Security-Policy for SVG
responses. A stricter, admin-configurable SVG capability gate is tracked separately
(see [rubicon/diviops#73](https://github.com/rubicon/diviops/issues/73)).

## Response contract

Tools return a standardized envelope. The shape lets clients branch on `ok` and machine-readable `error.code` without parsing freeform messages.

```jsonc
// Success
{ "ok": true, "data": <payload> }
// Failure
{ "ok": false, "error": { "code": "<code>", "message": "<human>", "hint": "<optional>" } }
```

Standard error codes: `not_found` (404), `invalid_input` (400), `validation_failed` (400), `conflict` (409), `forbidden` (403), `capability_missing` (412), `wp_error` (500), `divi_error` (500). Namespaces extend the vocabulary using the `<namespace>.<reason>` convention — e.g. `meta_wp_cli.command_failed`, `scf.not_configured`, `preset.bucket_mismatch`. Namespace-prefixed codes carry structured `error.data` documenting the failure (exit codes, conflicting fields, reference counts, etc.).

Every write tool accepts `dry_run: boolean` (default `false`). When `true`, the response carries a uniform plan shape and no state is mutated. See the server [README](diviops-server/README.md#dry_run-plan-shape) for the plan envelope and per-tool `_meta.idempotent` markers.

## Free vs Pro

DiviOps is a harness. The Free distribution carries the core Divi authoring surface; the Pro distribution adds deeper skill knowledge, the Pro plugin, license/update gating, and paid coverage slices for target plugins.

> **On this fork:** we maintain full **Pro compatibility** — `diviops-agent-pro` (oaris.de's separate commercial add-on, not part of this fork) attaches to our plugin unchanged, through `class_exists('DiviOps_Agent')` and the `diviops_agent_handshake_extensions` filter. Independently, this fork is building its **own** advanced skill knowledge for the free tier — authored clean-room from Divi's own source and public documentation, never derived from Pro — so more of the advanced authoring capability lands in Free over time. The table below describes the upstream Free/Pro split as it stands today; where this fork has already shipped its own free-tier equivalent of a Pro-only row, that row is footnoted.
>
> Versioning on this fork follows [Conventional Commits](https://www.conventionalcommits.org/) + [release-please](https://github.com/googleapis/release-please) automation — every merged PR that ships a user-facing change is reflected in [CHANGELOG.md](CHANGELOG.md), and release-please computes the next `vX.Y.Z` from commit history rather than a hand-picked version. Signed release tags are cut from `main`.

### What ships in Free (v1.x today)

The Free distribution (`oaris-dev/diviops`) carries the core DiviOps execution surface:

- `diviops-agent` WordPress plugin (REST bridge, Divi 5 + SCF + CPT + WP-CLI handlers)
- `diviops-design-library` plugin (CSS effects, gradients, glass, Three.js shaders)
- `@diviops/mcp-server` on npm — the shared MCP server package
- `divi-5-builder` skill, free slice: `SKILL.md`, design patterns, tools reference, preset system, design-effects, mega-menu, minimal snippets, SaaS landing, and the **Tier 1** attribute reference (universal decoration, `innerContent[]` variants, attribute tree layout, design tokens, exceptions quick reference)

### What ships in Pro (v1.x today)

The Pro distribution adds the Pro plugin, license/update gating, target coverage slices, and the deeper skill knowledge layer — `divi-5-builder` **Tier 2** + **Tier 3**:

| | Free | Pro |
|---|:---:|:---:|
| `diviops-agent` WordPress plugin | ✓ | ✓ (same binary) |
| `diviops-agent-pro` WordPress plugin | — | ✓ |
| `diviops-design-library` plugin | ✓ | ✓ (same binary) |
| `@diviops/mcp-server` on npm | ✓ | ✓ (same package) |
| Skill: SKILL.md, design patterns, tools reference, preset system, design-effects, mega-menu, minimal snippets, SaaS landing | ✓ | ✓ |
| Skill: **Tier 1** attribute reference — universal decoration, innerContent variants, attribute tree layout, design tokens, exceptions quick reference | ✓ | ✓ |
| Skill: **Tier 2** — shared pattern families (font, icon, container cascade, module link) | — | ✓ |
| Skill: **Tier 3** — per-module element maps for 20+ verified modules | — | ✓ |
| Skill: Advanced attributes (boxShadow, filters, transform, sticky, transition, scroll, animation) | — (upstream) / ✓¹ (this fork) | ✓ |
| Skill: `$variable()$` per-module binding examples and Interactions reference | — | ✓ |
| Skill: `diviops-fluentcart` coverage guide | — | ✓ |
| Skill: `diviops-scf` deeper SCF guide | — | ✓ |
| Pro license activation + update gating | — | ✓ |
| FluentCart Pro coverage handlers | — | ✓ |

**Practical difference today.** The Free skill is enough to generate pages using universal decoration patterns plus runtime lookups via `diviops_schema_get_module`. Pro adds verified per-module maps, which cuts schema-lookup round-trips and reduces silent-fail risk on quirks only documented in the full maps — e.g., Toggle's `closedTitle.decoration.font.*` (closed-state title styling; without it you'd target the open state only) or Video's `overlay.decoration.background` (the correct background target — not `module.decoration.background`).

¹ This fork ships its own advanced-attributes reference at [`skills/divi-5-builder/references/advanced-attributes.md`](skills/divi-5-builder/references/advanced-attributes.md), authored clean-room from Divi's own source and this fork's own site — `diviops-agent-pro` was never opened while writing it. It covers the same seven decoration families as the upstream Pro row (boxShadow, filters, transform, sticky, transition, scroll, animation) but is not the same document as Pro's own advanced-attributes content, which we have not seen and cannot compare against.

Pro also includes `diviops-agent-pro`. When the Pro plugin and a supported target plugin are active, the MCP handshake exposes conditional Pro tools. For example, a site with FluentCart + FluentCart Pro + DiviOps Agent Pro can expose `diviops_fc_*` product, gateway, order, license, and activation tools. If those gates are not satisfied, those tools are intentionally omitted from the MCP tool list.

### Purchased Pro install path

1. Download the Pro package from your customer account
2. Install and activate `diviops-agent.zip`
3. Install and activate `diviops-agent-pro.zip`
4. Open **DiviOps → Pro License**
5. Paste your license key and confirm the license is active
6. Register or restart the MCP server
7. Verify Pro capabilities with `diviops_meta_info`; with FluentCart + FluentCart Pro active, confirm `diviops_fc_*` tools appear

A license activation represents one active WordPress environment where DiviOps Pro is installed and used, including local development sites such as `localhost`, `.local`, `.test`, and `.lab`. Deactivate old environments from your customer account when they are no longer in active use.

### Current and future Pro coverage

The harness is designed to grow through **per-target execution coverage slices** — skill knowledge + MCP tools + plugin handlers bundled per target plugin. A per-tool capability handshake at MCP server startup queries the WP plugin for installed capabilities and applies two distinct gating modes: tools whose backing Pro plugin is **not installed** on the site are omitted from the MCP server's exposed tool list entirely (Claude never sees them); tools whose backing Pro plugin is installed but does **not advertise the required capability** fail with a clear `capability_missing` error rather than silent breakage. Server and plugin component versions remain independent. Current and planned slices:

- **Current — FluentCart Pro pilot.** Product, variation, license-settings, gateway readiness, order, transaction, license, and activation readback tools (`diviops_fc_*`) backed by the `diviops-fluentcart/` skill slice and Pro-plugin handlers in `diviops-agent-pro`. Sequencing reflects the project's own commerce dogfooding on `diviops.com`.
- **Future slices.** Additional target plugin slices such as Bit Forms Pro, Bit Flows Pro, and deeper Gutenberg interop. Each slice ships as its own `diviops-<target>/` skill plus dedicated handlers, following the same per-target-slice packaging shape.

**MCP tools always ship in the free MCP package.** What separates Free from Pro on a coverage slice is the *curated skill knowledge* and the *Pro-plugin handlers that back the tools*; the dispatch surface itself is universal. A Free-tier user on a site without the Pro plugin installed simply doesn't see Pro-only tools — they're gated by the per-tool capability handshake, not feature-flagged in the MCP server.

Pro upgrade: <https://diviops.com>

## Requirements

- Node.js 18+
- PHP 7.4+
- WordPress 6.5+
- Divi 5.1.0+ theme active
- DiviOps Agent WordPress plugin installed and active

## Troubleshooting

Common quick fixes:

- **401 Unauthorized** — strip spaces from the Application Password; verify `WP_USER` and `WP_APP_PASSWORD`.
- **503 `divi_unavailable`** — Divi 5 theme is not active.
- **MCP not appearing** — `claude mcp list`; if absent, `claude mcp remove` and re-add. Fully restart Claude Code (not just the window).
- **Preset edits not visible on the frontend** — Divi serves frontend CSS from `wp-content/et-cache/{post_id}/`, which `wp cache flush` doesn't touch. Use `diviops_meta_flush_cache` after preset writes.
- **VB shows raw `$variable()$`** — dynamic content binding rendered as text; click the chip to edit it inline.

Full troubleshooting matrix and environment-specific setup (DDEV, wp-env, WordPress Studio, DevKinsta) is in [SETUP.md](SETUP.md).

## Documentation

- **[SETUP.md](SETUP.md)** — full onboarding walkthrough (containerized envs, HTTPS, environment variables, WP-CLI security, design-system bootstrap)
- **[diviops-server/README.md](diviops-server/README.md)** — MCP server reference (response contract, error codes, `dry_run` plan shape, per-tool registration)
- **[skills/divi-5-builder/SKILL.md](skills/divi-5-builder/SKILL.md)** — block format rules, design patterns, workflow guidance
- **[Releases](https://github.com/oaris-dev/diviops/releases)** — release history

## License

This repository is **dual-licensed**, matching the fork base:

- **MIT** — the MCP server (`@diviops/mcp-server`), the skills, templates, documentation, and tests.
- **GPL-2.0-or-later** — the WordPress plugins (`diviops-agent`, `diviops-design-library`), as WordPress requires.

See [LICENSE](LICENSE) for the full text and per-component scope. This fork honors the original licensing and attributes the project to [oaris-dev/diviops](https://github.com/oaris-dev/diviops).
