# DiviOps MCP Server

**An AI harness for WordPress site authoring — Divi-native today, WordPress-wide by design.**

The Node.js MCP server inside the DiviOps harness. It gives Claude Code, Codex, Claude Desktop, and other MCP clients a typed control layer over WordPress site state, dispatching to the DiviOps Agent plugin for Divi 5 page authoring, design tokens, presets, library and Theme Builder templates, and site audits. SCF and CPT data models are handled by the server's own WP-CLI tools rather than by the plugin, alongside the general-purpose WP-CLI passthrough. Pairs with the `divi-5-builder` skill so the agent applies Divi's block format and design rules correctly.

```
Claude Code <-> MCP Server (stdio) <-> WordPress REST API <-> DiviOps Agent plugin
```

## Use cases

DiviOps fits multiple WordPress workflows where AI-driven authoring + management is the value:

- **Page building (Divi authoring)** — create + edit Divi pages, sections, modules, canvases via prompt; preset-driven design system reuse; Theme Builder layouts and templates.
- **SCF setup + management** — provision Secure Custom Fields field groups, sync schemas, export/import field group definitions; SCF data model becomes a tool surface, not an admin-UI flow.
- **CPT + post population** — register custom post types via wp-cli passthrough; bulk-populate posts and pages across any post type, not just Divi-built ones.
- **Data model reasoning** — schema introspection across Divi modules + SCF field groups + post meta; ask Claude what fields a post type carries, what attributes a module accepts, what tokens are defined.
- **WordPress site auditing** — preset audits, design-token usage scans, orphan detection (presets, variables, dangling references); broader site surveys via wp-cli (`wp option list`, `wp post list --format=json`, `wp post term list <id> <taxonomy> --format=json`, `wp user list`).
- **Hybrid sites (Divi + custom PHP)** — Divi authors the marketing pages; custom PHP templates handle dynamic ones (CPT listings, single-post views, member portals); design tokens harmonized across both surfaces via CSS custom properties driven from the Divi variable system.

## Quick start

Three steps to your first tool call.

### 1. Install the WordPress plugin

Download and activate the **DiviOps Agent** plugin from WordPress.org once it is listed there. For pre-listing test packages or a manual fallback install, take `diviops-agent.zip` from the [latest release](https://github.com/rubicon/diviops/releases/latest), which carries a `SHA256SUMS.txt` alongside it. Requires Divi 5.1+ on WordPress 6.5+.

The npm MCP server updates through npm. WordPress.org installs of the Free plugin update through the normal WordPress plugin update flow. For pre-listing test packages or a manual fallback install, replace `diviops-agent.zip` through **WP Admin → Plugins → Add New → Upload Plugin** and choose **Replace current with uploaded**.

### 2. Create an Application Password

In **WP Admin → Users → Your Profile → Application Passwords**:
- Enter a name (e.g. "MCP Server")
- Click "Add New Application Password"
- Copy the generated password and strip the spaces

### 3. Register the MCP server

Claude Code:

```bash
claude mcp add diviops-mcp \
  --env WP_URL=http://your-site.local \
  --env WP_USER=your-wp-username \
  --env WP_APP_PASSWORD=xxxxXXXXxxxxXXXXxxxxXXXX \
  -- npx -y --package @rubicontv/diviops-mcp diviops-mcp
```

Codex `~/.codex/config.toml`:

```toml
[mcp_servers.diviops-mcp]
command = "npx"
args = ["-y", "--package", "@rubicontv/diviops-mcp", "diviops-mcp"]

[mcp_servers.diviops-mcp.env]
WP_URL = "http://your-site.local"
WP_USER = "your-wp-username"
WP_APP_PASSWORD = "xxxxXXXXxxxxXXXXxxxxXXXX"
```

Then ask your AI client: **"List the pages on my site."** It calls `diviops_page_list` and renders the result. You're authoring with the suite.

For Claude Desktop JSON, use `"command": "npx"` with args `["-y", "--package", "@rubicontv/diviops-mcp", "diviops-mcp"]`. The package also ships `diviops-preset`, so the explicit package/bin form is required; shorthand package invocation cannot reliably infer which bin to run.

For a deeper walkthrough (containerized environments, WP-CLI configuration, troubleshooting installation), see the [Setup Guide](../SETUP.md#step-1-install-the-wordpress-plugin).

## Example workflow

> **You:** Create a hero section on a new page called "Spring Launch" with a heading, subheading, and a CTA button. Use my brand colors.

Claude orchestrates a few tool calls in sequence:

1. `diviops_global_color_list` — discovers your brand palette.
2. `diviops_template_list` / `diviops_template_get` — pulls a verified hero template that matches the request.
3. `diviops_page_create` — creates `Spring Launch` as a draft with the hero block markup.
4. `diviops_validate_blocks` — confirms the markup is well-formed before save. Accepts inline `content` or a `page_id` to validate already-saved markup.
5. `diviops_render_preview` — returns the rendered HTML so you can verify before publishing. Accepts inline `content` or a `page_id` to preview an existing page.

The skill enforces the Divi block format, the design system, and the response contract throughout — you stay at the prompt level.

## Tools at a glance

The server exposes **115 always-on tools** across the categories below. Each category names representative tool prefixes; for one row per tool, with inputs and idempotency, see the [Per-tool reference](#per-tool-reference).

| Category | Use case | Tool prefixes |
|----------|----------|---------------|
| Page authoring | Create, edit, restructure pages | `page_*`, `section_*`, `module_*` |
| Design system | Manage colors, fonts, variables, presets | `variable_*`, `global_color_*`, `global_font_*`, `preset_*` |
| Library + templates | Reusable layouts + Theme Builder | `library_*`, `template_*`, `tb_*` |
| Media | Upload, list, and inspect attachments; alt text/caption; set featured image | `media_*` |
| Revisions | Native WordPress post-revision list/get/diff/restore | `revision_*` |
| WordPress menus | Author reusable nav menus and theme-location assignments | `menu_*` |
| Semantic SEO metadata | Inspect provider support and author two explicit TSF text fields with checksum/readback guards | `seo_*` |
| Schema introspection | Module attribute discovery, including native Divi 5 core modules resolved from Divi's own `module.json` files | `schema_*` |
| Canvas / off-canvas | Popups, modals, menus | `canvas_*` |
| SCF integration | Secure Custom Fields sync | `scf_*` |
| Render + validate | Preview HTML, validate block markup | `render_preview`, `validate_blocks` |
| WP-CLI passthrough | Escape hatch for site ops | `meta_wp_cli` |
| Cache + meta | Connection probe, identity, icons, cache flush | `meta_*` |

**Media domain and SVG uploads.** `media_upload`, `media_get`, `media_list`,
`media_set_featured_image`, and `media_update_meta` (alt text/caption, with
partial-update and clear-via-empty-string semantics) cover the media library.
URL uploads are SSRF-guarded (public `http`/`https` only, reserved/private IPv4
and IPv6 ranges rejected on every redirect hop) and every upload is validated
against its real bytes via `wp_check_filetype_and_ext()`. **SVG uploads require
an active SVG sanitizer** — the plugin verifies [Safe SVG](https://wordpress.org/plugins/safe-svg/)
is actually bound to `wp_handle_sideload_prefilter`, not just that the SVG mime
is allowed, and fails closed with `svg_sanitizer_required` (415) otherwise. A site
can additionally require a higher capability for SVG uploads specifically, via the
`DIVIOPS_SVG_UPLOAD_CAPABILITY` constant or environment variable (default
`upload_files`); a caller without it gets `svg_capability_required` (403). See
the [main README's Media domain section](../README.md#media-domain) for the full
write-up, including deployment hardening notes.

Use `diviops_meta_info` as the S0 preflight before dogfooding or product work. It returns `server_version`, a numeric `tool_count`, a `tools` catalog summary (`registered_total`, always-on count, Pro possible/registered counts by target), `plugins` version records for `diviops-agent`, `diviops-agent-pro`, FluentCart, and FluentCart Pro when available, plus the existing handshake and slice state.

Additional **conditionally-registered Pro tools** appear only on sites that have the Pro plugin (`diviops-agent-pro`) active alongside the target coverage plugin:

| Category | Conditional gate | Tool names |
|----------|------------------|------------|
| FluentCart reads (V1) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_product_list`, `diviops_fc_product_get` |
| FluentCart simple product writes (V2) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_product_create`, `diviops_fc_product_update`, `diviops_fc_product_delete` |
| FluentCart variation read/write (V3) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_variation_list`, `diviops_fc_variation_update` |
| FluentCart license-settings read/write, incl. update-file pointer + readiness (V3/V3.3) | Pro plugin + FluentCart Pro installed + module enabled | `diviops_fc_license_settings_get`, `diviops_fc_license_settings_update` |
| FluentCart managed downloads + license changelog (V3.4) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_download_list`, `diviops_fc_download_attach`, `diviops_fc_license_changelog_get`, `diviops_fc_license_changelog_update` |
| FluentCart order readback + guarded mark-paid (V3.1) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_order_list`, `diviops_fc_order_get`, `diviops_fc_order_mark_paid` |
| FluentCart license readback (V3.1) | Pro plugin + FluentCart Pro installed + module enabled | `diviops_fc_license_list`, `diviops_fc_license_get`, `diviops_fc_license_activations_list` |
| FluentCart checkout readiness / gateway inspection (V3.2) | Pro plugin + FluentCart installed + module enabled | `diviops_fc_status`, `diviops_fc_gateway_list`, `diviops_fc_gateway_get` |
| Cross-env reviewed layout rollout | Pro plugin + `cross_env` module enabled | `diviops_cross_env_header_apply`, `diviops_cross_env_layout_apply` |
| Managed recovery Phase 1A | Pro plugin + opt-in `managed_recovery` module enabled | `diviops_managed_recovery_policy_get`, `diviops_managed_recovery_policy_preview`, `diviops_managed_recovery_policy_update`, `diviops_managed_recovery_retention_preview`, `diviops_managed_recovery_retention_apply`, `diviops_managed_recovery_audit_list` |

When the gates are not satisfied, the tools simply don't appear on the MCP surface — no error envelope, no missing-capability hint. See the `diviops-fluentcart` skill bundle for the operator-side guide.

<!-- BEGIN GENERATED:tool-reference:header -->

## Per-tool reference

> Generated mechanically by `diviops-server/scripts/regen-tool-reference.mjs` from the tool-registration call sites in `diviops-server/src/index.ts`. Everything between the `BEGIN GENERATED:tool-reference:*` / `END GENERATED:tool-reference:*` HTML-comment sentinels is rewritten on regen (see `diviops-server/CONTRIBUTING.md`). Do **not** edit between sentinels — edits are clobbered.

115 always-on tools (104 plugin-backed, 11 server-local) and 30 conditionally-registered Pro tools.

**Inputs** lists each tool's top-level input fields in schema order; a trailing `?` marks a field the schema makes optional or gives a default, and `_(none)_` marks a tool that takes no arguments. **Idempotent** is the tool's own `_meta.idempotent` marker ([what the values mean](#_metaidempotent-markers)). **Summary** is the first sentence of the tool's MCP `description`, which is the full reference for its response payload and error codes; a trailing `…` marks a description that continues, and an `…` inside the text marks a value the server fills in at handshake time. Every tool returns the [standardized envelope](#response-contract).

<!-- END GENERATED:tool-reference:header -->

<!-- BEGIN GENERATED:tool-reference:always-on -->

### Always-on tools

| Tool | Kind | Inputs | Idempotent | Summary |
|---|---|---|---|---|
| `diviops_canvas_create` | plugin | `title`, `parent_page_id`, `content?`, `canvas_id?`, `append_to_main?`, `z_index?`, `dry_run?` | conditional | Create a canvas (off-canvas workspace) linked to a page. … |
| `diviops_canvas_delete` | plugin | `canvas_post_id`, `dry_run?` | true | Delete a canvas. … |
| `diviops_canvas_duplicate` | plugin | `canvas_post_id`, `title?`, `dry_run?` | conditional | Deep-copy a canvas (post_content + canvas-specific meta: parent page, append_to_main, z_index). … |
| `diviops_canvas_get` | plugin | `canvas_post_id` | true | Get a canvas's block content and metadata. … |
| `diviops_canvas_list` | plugin | `parent_page_id?`, `per_page?` | true | List canvases (off-canvas workspaces). … |
| `diviops_canvas_orphan_audit` | plugin | `parent_page_id?`, `include_global?`, `include_context?`, `status?`, `per_page?` | true | Read-only audit of et_pb_canvas posts and off-canvas reference evidence. … |
| `diviops_canvas_update` | plugin | `canvas_post_id`, `content?`, `title?`, `append_to_main?`, `z_index?`, `dry_run?` | conditional | Update a canvas's content and/or metadata. … |
| `diviops_cross_env_source_export_get` | plugin | `source_id`, `source_kind?`, `dry_run?` | true | Export read-only, secret-free source-site payload for an offline cross-environment Theme Builder … preflight. … |
| `diviops_cross_env_target_context_get` | plugin | `destination_id`, `destination_kind?`, `source_asset_hints?`, `source_attachment_ids?`, `dry_run?` | true | Export read-only, secret-free target-site context for an offline cross-environment Theme Builder … preflight. … |
| `diviops_dynamic_content_build` | plugin | `name`, `settings?`, `type?`, `post_id?`, `context?` | true | Validate a dynamic-content `name` + `settings` against the live registry (diviops_dynamic_content_list), then return the exact $variable({...})$ token Divi's own Conversion::formatDynamicContent() would emit for the same inputs — byte-identical encoding, including empty settings serializing as {} not []. … |
| `diviops_dynamic_content_list` | plugin | `post_id?`, `context?` | true | List the live Divi dynamic-content option registry (apply_filters('divi_module_dynamic_content_options', ...)) for this site — includes ACF/SCF fields that actually exist here, not a static catalog. … |
| `diviops_dynamic_content_validate` | plugin | `name?`, `settings?`, `value?`, `post_id?`, `context?` | true | Validate a dynamic-content binding against the live registry. … |
| `diviops_global_color_audit_storage` | plugin | _(none)_ | true | Audit the global_colors STORAGE LOCATION landscape (#719 contract). … |
| `diviops_global_color_create` | plugin | `color`, `label?`, `folder?`, `status?`, `dry_run?` | false | Add a new global color to Divi's palette. … |
| `diviops_global_color_delete` | plugin | `gcid`, `force?`, `dry_run?` | true | Delete a global color from the registry by gcid. … |
| `diviops_global_color_list` | plugin | _(none)_ | true | Get the global color palette defined in Divi. … |
| `diviops_global_color_update` | plugin | `gcid`, `color?`, `label?`, `folder?`, `status?`, `dry_run?` | conditional | Update an existing global color by gcid. … |
| `diviops_global_font_audit_storage` | plugin | _(none)_ | true | Audit the global_fonts STORAGE LOCATION landscape (#719 contract). … |
| `diviops_global_font_create` | plugin | `family`, `source`, `id?`, `weights?`, `subsets?`, `label?`, `fallback?`, `status?`, `dry_run?` | false | Create a new global font in DiviOps's registry under `et_global_data.global_fonts`. … |
| `diviops_global_font_delete` | plugin | `id`, `force?`, `dry_run?` | true | Delete a global font from the registry by gfid. … |
| `diviops_global_font_list` | plugin | _(none)_ | true | List the DiviOps-managed global fonts registered under `et_divi.et_global_data.global_fonts` (gfid-* Google catalog) AND the local-hosted Pattern B fonts registered under `et_uploaded_fonts` (per #719 AC #9). … |
| `diviops_global_font_update` | plugin | `id`, `family?`, `source?`, `weights?`, `subsets?`, `label?`, `fallback?`, `status?`, `dry_run?` | conditional | Update an existing global font by gfid. … |
| `diviops_library_delete` | plugin | `library_id`, `force?`, `dry_run?` | true | Delete a Divi Library item (et_pb_layout). … |
| `diviops_library_get` | plugin | `item_id` | true | Get a Divi Library item's content by ID. … |
| `diviops_library_list` | plugin | `layout_type?`, `scope?`, `per_page?` | true | List saved Divi Library items. … |
| `diviops_library_save` | plugin | `title`, `content`, `layout_type?`, `scope?`, `dry_run?` | conditional | Save Divi block markup to the Divi Library for reuse. … |
| `diviops_media_get` | plugin | `attachment_id` | true | Get a single media library attachment's details: URL, mime type, title, alt text, caption, and available image sizes. … |
| `diviops_media_list` | plugin | `page?`, `per_page?`, `mime?`, `search?` | true | List/paginate media library attachments, optionally filtered by a mime type prefix (e.g. … |
| `diviops_media_set_featured_image` | plugin | `post_id`, `attachment_id?`, `url?`, `dry_run?` | conditional | Set a post's featured image (thumbnail) from either an existing media attachment (attachment_id) or by uploading a new image from a public URL (url) — provide exactly one. … |
| `diviops_media_update_meta` | plugin | `attachment_id`, `alt?`, `caption?`, `dry_run?` | conditional | Update an existing media attachment's alt text and/or caption. … |
| `diviops_media_upload` | plugin | `url?`, `data_base64?`, `filename?`, `attach_to?`, `title?`, `alt?`, `caption?`, `dry_run?` | false | Upload an image into the WordPress media library from a public URL (server fetches, SSRF-guarded) or from base64 bytes. … |
| `diviops_menu_create` | plugin | `name`, `slug?`, `dry_run?` | true | Create a WordPress nav menu by name, optionally with a requested slug. … |
| `diviops_menu_delete` | plugin | `menu_id`, `dry_run?` | true | Permanently delete a WordPress nav menu and its items. … |
| `diviops_menu_get` | plugin | `menu_id` | true | Fetch one WordPress nav menu with normalized flat items and a nested item tree. … |
| `diviops_menu_item_add_custom` | plugin | `menu_id`, `label`, `url`, `parent_item_id?`, `dry_run?` | true | Append a custom URL item to an existing WordPress nav menu. … |
| `diviops_menu_item_add_page` | plugin | `menu_id`, `page_id`, `label?`, `parent_item_id?`, `dry_run?` | true | Append a readable published page to an existing WordPress nav menu. … |
| `diviops_menu_item_remove` | plugin | `menu_id`, `item_id`, `cascade?`, `dry_run?` | true | Remove one item from a WordPress nav menu. … |
| `diviops_menu_item_reorder` | plugin | `menu_id`, `order`, `parent?`, `dry_run?` | true | Reorder the items at one level of a WordPress nav menu (all items sharing a parent). … |
| `diviops_menu_list` | plugin | _(none)_ | true | List WordPress nav menus, registered theme locations, and current location assignments. … |
| `diviops_menu_location_assign` | plugin | `menu_id`, `location`, `dry_run?` | true | Assign an existing WordPress nav menu to a registered theme location discovered from the current theme. … |
| `diviops_menu_location_unassign` | plugin | `location`, `dry_run?` | true | Clear a registered theme location's WordPress nav menu assignment (the mirror of diviops_menu_location_assign). … |
| `diviops_meta_find_icon` | plugin | `query`, `type?`, `limit?` | true | Search for icons by keyword. … |
| `diviops_meta_flush_cache` | plugin | `post_id?`, `all?`, `after?`, `dry_run?`, `cleanup_dynamic_assets?`, `cleanup_canvas_refs?` | true | Flush Divi's compiled static CSS cache under wp-content/et-cache/. … |
| `diviops_meta_info` | server-local | _(none)_ | true | Returns DiviOps MCP server identity, server_version, license type, numeric tool_count, registered tool catalog summary, active plugin version summary, WP-CLI allowlist, and plugin handshake/slice state including Pro and FluentCart target readiness. … |
| `diviops_meta_ping` | server-local | _(none)_ | true | Test the connection to the WordPress site and verify the Divi MCP plugin is active. … |
| `diviops_meta_wp_cli` | server-local | `command` | conditional | Run a WP-CLI command on the WordPress site. … |
| `diviops_module_clone` | plugin | `page_id`, `label?`, `match_text?`, `auto_index?`, `occurrence?`, `position?`, `dry_run?`, `backup?` | false | Clone a module by deep-copying its block JSON and inserting it next to the source within the same parent container. … |
| `diviops_module_get` | plugin | `page_id`, `label?`, `match_text?`, `auto_index?`, `occurrence?`, `full?` | true | Get one targeted Divi module/block from a page, post, or Theme Builder layout by auto_index (e.g. … |
| `diviops_module_lock` | plugin | `page_id`, `label?`, `match_text?`, `auto_index?`, `occurrence?`, `dry_run?`, `backup?` | false | Lock a module so VB users cannot edit it. … |
| `diviops_module_move` | plugin | `page_id`, `source_label?`, `source_match_text?`, `source_auto_index?`, `source_occurrence?`, `target_label?`, `target_match_text?`, `target_auto_index?`, `target_occurrence?`, `position`, `dry_run?`, `backup?` | conditional | Move a module to a new position on the page. … |
| `diviops_module_unlock` | plugin | `page_id`, `label?`, `match_text?`, `auto_index?`, `occurrence?`, `dry_run?`, `backup?` | false | Unlock a module by removing attrs.locked entirely. … |
| `diviops_module_update` | plugin | `page_id`, `label?`, `match_text?`, `auto_index?`, `occurrence?`, `attrs`, `dry_run?`, `backup?` | conditional | Update specific attributes of a module. … |
| `diviops_page_block_insert` | plugin | `page_id`, `parent_selector?`, `parent_path?`, `position?`, `content`, `dry_run?`, `backup?` | conditional | Insert one or more serialized Divi blocks (a new row, column, or module) at a specific position on a page/post, without rebuilding the surrounding section. … |
| `diviops_page_create` | plugin | `title`, `content?`, `status?`, `post_type?`, `dry_run?` | false | Create a new WordPress page — or, via post_type, a post or custom post type — optionally with Divi block content. … |
| `diviops_page_duplicate` | plugin | `page_id`, `title?`, `status?`, `post_type?`, `dry_run?` | false | Duplicate a page/post on the SAME site — a first-class operation instead of hand-rolling diviops_page_get_layout + diviops_page_create. … |
| `diviops_page_get` | plugin | `page_id` | true | Get detailed info about a specific page including its raw Divi block content. … |
| `diviops_page_get_layout` | plugin | `page_id`, `full?` | true | Get the parsed block tree for a page. … |
| `diviops_page_list` | plugin | `post_type?`, `per_page?`, `page?` | true | List pages/posts in the WordPress site. … |
| `diviops_page_trash` | plugin | `post_id`, `force?`, `dry_run?` | true | Trash or permanently delete a page/post. … |
| `diviops_page_update_content` | plugin | `page_id`, `content`, `dry_run?`, `backup?` | conditional | Update the content of a page with Divi block markup. … |
| `diviops_page_update_meta` | plugin | `page_id`, `title?`, `slug?`, `parent?`, `menu_order?`, `preserve_old_slug?`, `dry_run?` | true | Update page/post metadata fields without touching post_content. … |
| `diviops_page_update_status` | plugin | `post_id`, `status`, `date_gmt?`, `dry_run?` | true | Update a page's post_status. … |
| `diviops_preset_audit` | plugin | _(none)_ | true | Audit all Divi presets (module + group). … |
| `diviops_preset_audit_storage` | plugin | _(none)_ | true | Audit the D5 preset STORAGE LOCATION landscape (#719 contract). … |
| `diviops_preset_cleanup` | plugin | `dry_run?`, `dedup?`, `action?`, `prefix?`, `scope?` | false | Clean up presets. … |
| `diviops_preset_create` | plugin | `module_name`, `name`, `attrs`, `type?`, `group_name?`, `group_id?`, `primary_attr_name?`, `make_default?`, `priority?`, `dry_run?` | conditional | Create a new preset in the Divi 5 registry. … |
| `diviops_preset_delete` | plugin | `preset_id`, `force?` | true | Delete a specific preset by ID. … |
| `diviops_preset_inspect` | plugin | `preset_id` | true | Inspect one Divi 5 preset UUID without writing. … |
| `diviops_preset_reassign` | plugin | `old_uuid`, `new_uuid`, `page_ids?`, `mode?`, `strip_inline?`, `scope?` | true | Reassign a preset UUID across page content. … |
| `diviops_preset_registry_doctor` | plugin | `repair?`, `clear_chunk_transients?`, `dry_run?`, `limit?` | conditional | Audit the canonical Divi 5 preset registry for non-integer created/updated metadata and stale or failed preset chunk transients. … |
| `diviops_preset_scan_orphans` | plugin | _(none)_ | true | Scan page content for modulePreset UUIDs that are not in the D5 registry. … |
| `diviops_preset_set_default` | plugin | `preset_id?`, `type?`, `module?`, `unset?`, `dry_run?` | true | Set or clear the per-module/group default preset. … |
| `diviops_preset_update` | plugin | `preset_id`, `name?`, `attrs?`, `priority?`, `dry_run?` | conditional | Update a specific preset by ID. … |
| `diviops_render_preview` | plugin | `content?`, `page_id?` | true | Render Divi block markup to HTML. … |
| `diviops_revision_diff` | plugin | `from`, `to?` | true | Compare two native WordPress revisions, or one revision against the parent post's current content. … |
| `diviops_revision_get` | plugin | `revision_id` | true | Read one native WordPress revision, including its raw stored content. … |
| `diviops_revision_list` | plugin | `id` | true | List a post's native WordPress revisions (posts of type revision whose post_parent is the edited post), newest first. … |
| `diviops_revision_restore` | plugin | `revision_id`, `dry_run?` | false | Restore a post to one of its native WordPress revisions (wp_restore_post_revision), busting the Divi cache afterward. … |
| `diviops_rollback_snapshot_delete` | plugin | `snapshot_id` | true | Hard-delete one rollback snapshot option after operator acceptance. … |
| `diviops_rollback_snapshot_get` | plugin | `snapshot_id`, `include_value?` | true | Inspect one rollback snapshot. … |
| `diviops_rollback_snapshot_list` | plugin | `target_kind?`, `target_id?`, `status?`, `limit?` | true | List DiviOps rollback snapshots from the option-backed store. … |
| `diviops_rollback_snapshot_restore` | plugin | `snapshot_id`, `page_ids?`, `dry_run?` | false | Restore one guarded rollback snapshot to its original post/page or Theme Builder layout target. … |
| `diviops_scf_export` | server-local | `dir?`, `stdout?`, `field_groups?`, `post_types?`, `taxonomies?`, `options_pages?` | true | Export SCF field groups, post types, taxonomies, and options pages as JSON — to a directory under the safe-root (`<WP_PATH>/.diviops-tmp/` by default, override via DIVIOPS_WP_CLI_SAFE_FS_ROOT) or to stdout. … |
| `diviops_scf_field_group_get` | server-local | `key` | true | Fetch a single SCF/ACF field group from the `acf-field-group` post type — by ACF key (`group_abc123`, looked up via `post_name`) or by numeric WP post ID. … |
| `diviops_scf_field_group_list` | server-local | _(none)_ | true | List all SCF/ACF field groups in the database (post_name = ACF key, post_title, post_status, post_modified). … |
| `diviops_scf_import` | server-local | `file` | true | Import SCF field groups, post types, taxonomies, options pages from a JSON file. … |
| `diviops_scf_status` | server-local | `type?`, `detailed?` | true | Show SCF (Secure Custom Fields) sync status — how many field groups, post types, taxonomies, and options pages have JSON-on-disk newer than the database (or absent from DB). … |
| `diviops_scf_sync` | server-local | `type?`, `key?`, `dry_run?` | true | Apply pending JSON-on-disk SCF changes to the database. … |
| `diviops_schema_get_module` | plugin | `mode?`, `module_name?`, `raw?` | true | Get the attribute schema for a Divi module. … |
| `diviops_schema_get_settings` | plugin | _(none)_ | true | Get Divi site settings including site info, builder version, and a narrow public allowlist of non-sensitive Divi theme options (fonts, colors, sizes). … |
| `diviops_schema_list_modules` | plugin | _(none)_ | true | List all available Divi modules (block types) with their names, titles, and categories. … |
| `diviops_section_append` | plugin | `page_id`, `content`, `position?`, `dry_run?`, `backup?` | false | Append a Divi section to an existing page without overwriting other content. … |
| `diviops_section_get` | plugin | `page_id`, `label?`, `match_text?`, `occurrence?` | true | Get the raw block markup of a section. … |
| `diviops_section_remove` | plugin | `page_id`, `label?`, `match_text?`, `occurrence?`, `dry_run?`, `backup?` | true | Remove a section from a page. … |
| `diviops_section_replace` | plugin | `page_id`, `label?`, `match_text?`, `content`, `occurrence?`, `dry_run?`, `backup?` | conditional | Replace a section on a page. … |
| `diviops_seo_metadata_get` | plugin | `post_id`, `provider?` | true | Read explicit and effective semantic SEO metadata for one provider-supported post. … |
| `diviops_seo_metadata_update` | plugin | `post_id`, `provider?`, `expected_checksum`, `changes`, `dry_run?` | conditional | Update explicit TSF SEO metadata on one provider-supported post through the Free/core semantic contract. … |
| `diviops_seo_provider_list` | plugin | _(none)_ | true | List the Free/core semantic SEO provider adapters and their installed, active, version, compatibility, field, and capability evidence. … |
| `diviops_tb_layout_block_insert` | plugin | `layout_id`, `parent_selector?`, `parent_path?`, `position?`, `content`, `dry_run?`, `backup?` | conditional | Insert one or more serialized Divi blocks into an existing Theme Builder layout without replacing the whole layout. … |
| `diviops_tb_layout_get` | plugin | `layout_id` | true | Get a Theme Builder layout's block markup content (header, body, or footer). … |
| `diviops_tb_layout_update` | plugin | `layout_id`, `content`, `dry_run?`, `backup?` | conditional | Update a Theme Builder layout's block markup (header, body, or footer). … |
| `diviops_tb_template_create` | plugin | `title`, `condition`, `header_content?`, `footer_content?`, `dry_run?` | false | Create a Theme Builder template with custom header and/or footer. … |
| `diviops_tb_template_list` | plugin | `per_page?`, `page?` | true | List all Theme Builder templates with their conditions, layout IDs, and enabled status. … |
| `diviops_tb_template_trash` | plugin | `template_id`, `force?`, `dry_run?` | conditional | Trash (or permanently delete) a Theme Builder template AND its linked header/body/footer layouts AND scrub the `_et_template` meta refs on the Theme Builder master post. … |
| `diviops_template_get` | server-local | `template_name` | true | Get a specific Divi template with verified block markup, customizable variables, and usage notes. … |
| `diviops_template_list` | server-local | _(none)_ | true | List available Divi page section templates. … |
| `diviops_theme_options_update` | plugin | `options`, `dry_run?` | true | Update Divi theme options (fonts, colors, sizes) — the WP customizer-backed `et_divi` values Divi's own Theme Options panel edits, written via `et_update_option`. … |
| `diviops_validate_blocks` | plugin | `content?`, `page_id?` | true | Validate Divi block markup before saving. … |
| `diviops_variable_create` | plugin | `type`, `id?`, `label`, `value?`, `gradient?`, `min?`, `max?`, `targets?`, `output_unit?`, `root_font_size_px?`, `dry_run?` | false | Create a design token variable in the Divi Variable Manager. … |
| `diviops_variable_create_fluid_system` | plugin | `profile?`, `custom_anchors?`, `typography?`, `spacing?`, `radius?`, `namespace?`, `output_unit?`, `root_font_size_px?`, `dry_run?`, `overwrite?` | false | Batch-emit a fluid typography + spacing + radius variable set in one call — mirrors Divi 5.4.0's Variable Generator Modal at the algorithm level (clamp() math is identical to diviops_variable_create's fluid mode) but layers profile-selectable anchors over it. … |
| `diviops_variable_delete` | plugin | `id`, `force?`, `dry_run?` | true | Delete a design token variable by ID. … |
| `diviops_variable_list` | plugin | `type?`, `prefix?` | true | List all design token variables from the Divi Variable Manager. … |
| `diviops_variable_scan_orphans` | plugin | _(none)_ | true | Scan pages, Theme Builder layouts (header/body/footer), Divi Library items, canvas pages, and the preset registry for gvid-/gcid- references that have no backing entry in the Variable Manager (orphans), plus variables defined but referenced nowhere (unused). … |
| `diviops_variable_update` | plugin | `id`, `label?`, `value?`, `gradient?`, `status?`, `dry_run?` | conditional | Update an existing design token variable in place by id. … |
| `diviops_variable_used_on_page` | plugin | `post_id` | true | Detect which numeric/font variable IDs a single page actually emits — the exact set Divi 5.4.0+ uses to scope selective `:root{--gvid-*}` CSS variable emission. … |

<!-- END GENERATED:tool-reference:always-on -->

<!-- BEGIN GENERATED:tool-reference:pro -->

### Conditionally-registered Pro tools

These appear on the MCP surface only when their gates are satisfied (see [Tools at a glance](#tools-at-a-glance)). The capability key is the plugin-side key the gate reads, which does not follow the tool name.

| Tool | Target | Capability key | Inputs | Idempotent | Summary |
|---|---|---|---|---|---|
| `diviops_cross_env_header_apply` | `cross_env` | `cross_env_header_apply` | `source_payload?`, `source_payload_ref?`, `destination_id`, `destination_kind?`, `reviewed_fingerprint`, `confirm_apply` | conditional | Apply a reviewed cross-environment Theme Builder header payload into an existing target header layout (Pro tier; requires the cross_env Pro module). … |
| `diviops_cross_env_layout_apply` | `cross_env` | `cross_env_layout_apply` | `source_payload?`, `source_payload_ref?`, `destination_id`, `destination_kind`, `reviewed_fingerprint`, `confirm_apply` | conditional | Apply one reviewed Theme Builder header or footer payload to one existing same-kind target layout (Pro tier; cross_env module). … |
| `diviops_fc_download_attach` | `fluentcart` | `fluentcart_download_attach` | `product_id`, `file_path`, `file_name?`, `title?`, `variation_ids?`, `expected_sha1?`, `expected_size?`, `allow_duplicate?`, `dry_run?` | false | Attach an already-present server-side ZIP as a FluentCart downloadable-file row (Pro tier; V3.4; requires FluentCart Pro installed + activated). … |
| `diviops_fc_download_list` | `fluentcart` | `fluentcart_download_list` | `product_id` | true | List FluentCart downloadable-file rows for a product (Pro tier; V3.4; requires FluentCart Pro installed + activated). … |
| `diviops_fc_gateway_get` | `fluentcart` | `fluentcart_gateway_get` | `method` | true | Fetch a single FluentCart payment gateway by method slug with secrets redacted (Pro tier; V3.2). … |
| `diviops_fc_gateway_list` | `fluentcart` | `fluentcart_gateway_list` | _(none)_ | true | List FluentCart registered payment gateways with secrets redacted (Pro tier; V3.2). … |
| `diviops_fc_license_activations_list` | `fluentcart` | `fluentcart_license_activations_list` | `license_id`, `status?` | true | List a FluentCart license's activation rows (Pro tier; V3.1; requires FluentCart Pro + Licensing module). … |
| `diviops_fc_license_changelog_get` | `fluentcart` | `fluentcart_license_changelog_get` | `product_id` | true | Read the FluentCart Pro software-license changelog HTML for a product (Pro tier; V3.4; requires FluentCart Pro installed + activated). … |
| `diviops_fc_license_changelog_update` | `fluentcart` | `fluentcart_license_changelog_update` | `product_id`, `changelog_html`, `dry_run?` | conditional | Write the FluentCart Pro software-license changelog HTML for a product (Pro tier; V3.4; requires FluentCart Pro installed + activated). … |
| `diviops_fc_license_get` | `fluentcart` | `fluentcart_license_get` | `id`, `include_license_key?`, `confirm_secret_handling?` | true | Fetch a single FluentCart Pro license by ID (Pro tier; V3.1; requires FluentCart Pro + Licensing module). … |
| `diviops_fc_license_list` | `fluentcart` | `fluentcart_license_list` | `page?`, `per_page?`, `product_id?`, `variation_id?`, `order_id?`, `customer_id?`, `status?` | true | List FluentCart Pro licenses (Pro tier; V3.1; requires FluentCart Pro + Licensing module). … |
| `diviops_fc_license_settings_get` | `fluentcart` | `fluentcart_license_settings_get` | `product_id` | true | Read the per-product FluentCart Pro license-settings projection (Pro tier; V3/V3.3; requires FluentCart Pro installed + activated). … |
| `diviops_fc_license_settings_update` | `fluentcart` | `fluentcart_license_settings_update` | `product_id`, `enabled?`, `version?`, `prefix?`, `variations?`, `global_update_file?`, `dry_run?` | conditional | Write the per-product FluentCart Pro license-settings ProductMeta row (Pro tier; V3/V3.3; requires FluentCart Pro installed + activated). … |
| `diviops_fc_order_get` | `fluentcart` | `fluentcart_order_get` | `id` | true | Fetch a single FluentCart order with line items, transactions, and related license IDs (Pro tier; V3.1; requires FluentCart installed + activated). … |
| `diviops_fc_order_list` | `fluentcart` | `fluentcart_order_list` | `page?`, `per_page?`, `status?`, `payment_status?`, `payment_method?`, `product_id?`, `customer_email?`, `mode?` | true | List FluentCart orders for commerce dogfooding / smoke baselines (Pro tier; V3.1; requires FluentCart installed + activated). … |
| `diviops_fc_order_mark_paid` | `fluentcart` | `fluentcart_order_mark_paid` | `id`, `dry_run?`, `confirm_order_id?`, `confirm_payment_method?`, `confirm_due_amount?`, `mark_paid_note?` | conditional | Guarded local/offline mark-paid for a FluentCart order (Pro tier; V3.1; requires FluentCart installed + activated). … |
| `diviops_fc_product_create` | `fluentcart` | `fluentcart_product_create` | `title`, `status?`, `content?`, `excerpt?`, `fulfillment_type?`, `price?`, `compare_price?`, `sku?`, `dry_run?` | false | Create a simple FluentCart Pro product (Pro tier; requires FluentCart Pro installed + activated). … |
| `diviops_fc_product_delete` | `fluentcart` | `fluentcart_product_delete` | `id`, `dry_run?` | conditional | Trash a FluentCart Pro product (Pro tier; requires FluentCart Pro installed + activated). … |
| `diviops_fc_product_get` | `fluentcart` | `fluentcart_product_get` | `id` | true | Fetch a single FluentCart Pro product by ID, including the ProductDetail row, the default-variation read-back fields, and a list of variation IDs (Pro tier; requires FluentCart Pro installed + activated). … |
| `diviops_fc_product_list` | `fluentcart` | `fluentcart_product_list` | `page?`, `per_page?`, `search?`, `type?`, `status?` | true | List FluentCart Pro products (Pro tier; requires FluentCart Pro installed + activated). … |
| `diviops_fc_product_update` | `fluentcart` | `fluentcart_product_update` | `id`, `title?`, `status?`, `content?`, `excerpt?`, `fulfillment_type?`, `price?`, `compare_price?`, `sku?`, `dry_run?` | conditional | Update a simple FluentCart Pro product (Pro tier; requires FluentCart Pro installed + activated). … |
| `diviops_fc_status` | `fluentcart` | `fluentcart_status_get` | `include_table_lists?` | true | Inspect FluentCart checkout/license readiness (Pro tier; V3.2). … |
| `diviops_fc_variation_list` | `fluentcart` | `fluentcart_variation_list` | `product_id` | true | List FluentCart Pro variations for a product (Pro tier; V3; requires FluentCart Pro installed + activated). … |
| `diviops_fc_variation_update` | `fluentcart` | `fluentcart_variation_update` | `product_id`, `variation_id`, `price?`, `compare_price?`, `sku?`, `payment_type?`, `repeat_interval?`, `times?`, `trial_days?`, `manage_setup_fee?`, `dry_run?` | conditional | Update the default variation of a simple FluentCart Pro product (Pro tier; V3; requires FluentCart Pro installed + activated). … |
| `diviops_managed_recovery_audit_list` | `managed_recovery` | `managed_recovery_audit_v1` | `page?`, `per_page?` | true | List immutable checksummed metadata-only managed recovery policy/prune audit events (Pro Phase 1A), newest first with bounded pagination. … |
| `diviops_managed_recovery_policy_get` | `managed_recovery` | `managed_recovery_policy_v1` | _(none)_ | true | Inspect the effective/default one-site managed recovery policy and complete metadata-only rollback snapshot inventory (Pro Phase 1A; managed_recovery module). … |
| `diviops_managed_recovery_policy_preview` | `managed_recovery` | `managed_recovery_policy_v1` | `request_id`, `policy` | true | Pure-read preview of a complete managed-recovery-policy-v1 proposal (Pro Phase 1A). … |
| `diviops_managed_recovery_policy_update` | `managed_recovery` | `managed_recovery_policy_v1` | `request_id`, `policy`, `confirmation_fingerprint`, `confirmation_token`, `dry_run?` | conditional | Apply exactly the one-site managed recovery policy reviewed by policy_preview (Pro Phase 1A). … |
| `diviops_managed_recovery_retention_apply` | `managed_recovery` | `managed_recovery_retention_v1` | `request_id`, `confirmation_fingerprint`, `confirmation_token`, `dry_run?` | conditional | Delete only the exact ordered snapshot IDs reviewed by retention_preview (Pro Phase 1A). … |
| `diviops_managed_recovery_retention_preview` | `managed_recovery` | `managed_recovery_retention_v1` | `request_id` | true | Pure-read deterministic retention plan for the active one-site managed recovery policy (Pro Phase 1A). … |

<!-- END GENERATED:tool-reference:pro -->

## Bundled CLI — `diviops-preset`

> **Not in the published package.** `diviops-preset` is documented here but is not currently
> shipped: the declaration was removed in
> [#223](https://github.com/rubicon/diviops/pull/223) because it pointed at a binary
> the tarball did not contain, and restoring it is tracked in
> [#230](https://github.com/rubicon/diviops/issues/230). The section below describes the
> intended command and is kept as the specification for that work. `diviops-mcp` is the
> only executable this package installs today.

The package also ships a standalone command-line preset emitter, `diviops-preset`,
that produces byte-canonical Divi 5.5.x preset JSON gated by the verified-attrs
registry (`data/verified-attrs.json`). It is independent of the MCP stdio server —
run it directly. Current commands:

| Command | Emits |
|---|---|
| `diviops-preset button [options]` | `divi/button` group preset |
| `diviops-preset heading-font [options]` | `divi/font` group preset for `divi/heading` (Pattern A — Google Fonts — or Pattern B — local-hosted) |
| `diviops-preset text-body-font [options]` | `divi/font-body` group preset for `divi/text` — **Pattern A (Google Fonts) only**; Pattern B for body-text has no registered canonical shape and is refused |
| `diviops-preset spacing [options]` | `divi/spacing` group preset (currently `divi/section` only; padding + margin, desktop state). Other module cells are `SCHEMA_OBSERVED` and refused at the gate |

```bash
diviops-preset button --name "Primary" --bg-color gcid-primary-color \
  --bg-color-hover gcid-secondary-color --radius 8px \
  --font-family Inter --font-weight 600 --font-color gcid-body-color

diviops-preset heading-font --name "Heading H1" --pattern google \
  --font-family Inter --font-weight 700 \
  --font-color gcid-heading-color --font-size 48px

diviops-preset text-body-font --name "Body Text" --pattern google \
  --font-family Inter --font-weight 400 \
  --font-color gcid-body-color --font-size 16px

diviops-preset spacing --name "Section Rhythm" --module divi/section \
  --padding-top 80px --padding-bottom 80px --margin-bottom 40px
```

`--dry-run` (the default) composes and prints the canonical JSON with no
credentials and no network. `--apply` posts to the existing `/preset/create`
REST route, reusing the same `WP_URL` / `WP_USER` / `WP_APP_PASSWORD` env vars.

The CLI's coverage is intentionally narrow: only the (module, group, variant)
combinations whose canonical shape is VB-verified in the registry are
emittable. It is **not** an all-module or all-font-family emitter — each
additional vertical slice lands with its own verified evidence. A full command reference will land with the source, tracked in
[#230](https://github.com/rubicon/diviops/issues/230).

## Bundled CLI — `diviops-cross-env-preflight`

> **Not in the published package.** `diviops-cross-env-preflight` is documented here but is not currently
> shipped: the declaration was removed in
> [#223](https://github.com/rubicon/diviops/pull/223) because it pointed at a binary
> the tarball did not contain, and restoring it is tracked in
> [#230](https://github.com/rubicon/diviops/issues/230). The section below describes the
> intended command and is kept as the specification for that work. `diviops-mcp` is the
> only executable this package installs today.

The package also ships a dry-run-only cross-environment Theme Builder header
sync preflight:

```bash
diviops-cross-env-preflight --source source.json --target target.json --dry-run
```

This command reads two secret-free JSON files and prints a report. It does not
connect to WordPress, does not accept credentials, and has no write/apply path.
The CLI supports `tb_header_layout` and `tb_footer_layout` source payloads
preflighted against existing same-kind targets. Header-only inputs retain the
shipped header-v1 report/fingerprint; footer inputs use the generic layout-v1
binding by default. Pass `--contract layout-v1` to emit the generic binding for
a header rollout through `diviops_cross_env_layout_apply`; `--contract
header-v1` explicitly selects the compatibility contract and accepts header
inputs only. It reports source-domain upload URL
rewrites, attachment remap status, `gcid-*` target resolution including Divi
built-in customizer colors, resolved target global-color value evidence,
referenced `modulePreset` target-presence status, off-canvas/canvas refusal, and
the required cache cleanup plan. Each report also emits a deterministic
`confirmation_binding` fingerprint over the reviewed source identity/checksum,
target identity/current target checksum, rewrite plan, cache plan,
blocker/operator-action codes, reference-resolution summaries, target module
preset resolution, and the per-`gcid` value evidence for target colors
referenced by the source layout.
The fingerprint is reviewed-plan evidence for the Pro-gated compatibility
`diviops_cross_env_header_apply` or generic `diviops_cross_env_layout_apply`
tool. The CLI itself still has no apply path.

The Free plugin advertises `cross_env_footer_layout_evidence` when its source
export and target-context routes support footer kinds. The server selects those
two tools' public enums only after handshake: older or unproven plugins retain
the shipped header-only schemas instead of advertising unsupported footer input.

To collect the source JSON from the source WordPress site, call the Free/core
read-only MCP tool `diviops_cross_env_source_export_get` and save the returned
`data` object as `source.json`. The export includes the source origin, header
layout metadata, sanitized markup, a bare SHA-256 checksum of the exported
markup, export metadata, and best-effort attachment inventory from upload URLs
and attachment IDs. It also inventories referenced `attrs.modulePreset` IDs
without exporting preset definitions. It strips query strings, fragments,
credentials, nonces, cookies, signed URL material, admin URLs, and local
filesystem paths.

The MCP server also writes the same source payload to a bounded local artifact
under `.diviops-tmp/cross-env-source-payloads/` and returns
`data.source_payload_ref`. Use that reference for large real layouts when
calling a Pro layout apply tool; it avoids asking an LLM
to re-emit large markup byte-for-byte. The reference is a server-created handle
plus checksum, not an arbitrary filesystem path.

To collect the target JSON from the target WordPress site, call the Free/core
read-only MCP tool `diviops_cross_env_target_context_get` against the target
server and save the returned `data` object as `target.json`. Optional
`source_asset_hints` and `source_attachment_ids` let the target site search for
exact media-library candidates by upload path or basename. Ambiguous matches
remain candidates only; no media is uploaded and no global colors or layouts are
created. The export includes a SHA-256 checksum of the current target layout
`post_content` as `destination_checksum` so preflight can bind the reviewed plan
to the target state without exposing the raw target content. It also includes
`global_color_value_evidence`, a deterministic SHA-256 digest map for resolved
user global colors and WP Customizer-backed built-ins; preflight binds only the
entries that are referenced by source markup.
It also includes target D5 module preset IDs, without preset definitions, so the
preflight can fail closed when source markup references a module preset the
target site does not have.
For header/footer generic preflight it also includes exact target post type and
a canonical template-linkage digest over the active Theme Builder master ID,
its exact `_et_template` order, and linked-template slot, enabled, condition,
and exclusion evidence. Empty linkage uses `master_template_ids: []` and
`links: []`; assignment state is evidence only and is never mutated by rollout.

Workflow:

1. Call `diviops_cross_env_source_export_get` on the source site and save the
   returned `data` object as `source.json`.
2. Call `diviops_cross_env_target_context_get` on the target site and save the
   returned `data` object as `target.json`.
3. Run `diviops-cross-env-preflight --source source.json --target target.json --dry-run --contract layout-v1`
   for `diviops_cross_env_layout_apply`. Omit the contract flag (or select
   `header-v1`) only for the compatibility header apply tool.

`--apply` is intentionally refused. To mutate a target, use the separate
Pro-gated `diviops_cross_env_layout_apply` tool with the generic reviewed
fingerprint and either inline `source_payload` for small/disposable tests or
`source_payload_ref` for large layouts. The existing header tool remains the
header-v1 compatibility path. Both refuse media
upload/import, global color creation/import, off-canvas reconcile, and new
target layout creation.

## Response contract

Tools return a standardized envelope. The shape lets clients branch on `ok` and machine-readable `error.code` without parsing freeform messages.

```jsonc
// Success
{ "ok": true, "data": <payload> }
// Failure
{ "ok": false, "error": { "code": "<code>", "message": "<human>", "hint": "<optional>" } }
```

### Standard error codes

| code | HTTP | meaning |
|---|---|---|
| `not_found` | 404 | Target ID does not resolve |
| `invalid_input` | 400 | Schema violation, malformed args |
| `validation_failed` | 400 | `validate_blocks`-detected shape error |
| `conflict` | 409 | Uniqueness collision |
| `forbidden` | 403 | Row-level WordPress auth signal |
| `capability_missing` | 412 | Connected plugin does not advertise the capability required by this tool; component versions are independent |
| `wp_error` | 500 | Underlying WordPress error |
| `divi_error` | 500 | Divi-specific error (block parser, validator, etc.) |

### Namespace-specific codes

Namespaces extend the vocabulary using the `<namespace>.<reason>` convention — e.g. `meta_wp_cli.command_failed`, `scf.not_configured`, `preset.bucket_mismatch`, `variable.customizer_default_immutable`, `seo.provider_absent`, and `seo.metadata_drift`. Namespace-prefixed codes carry structured `error.data` documenting the failure (exit codes, conflicting fields, reference counts, checksums, rollback evidence, etc.). Per-tool descriptions name the codes each tool emits and the `error.data` shape that accompanies them.

### Per-tool `error.data` extensions

Some tools attach a structured `error.data` payload alongside the `code`/`message`/`hint` envelope — e.g. `meta_wp_cli` carries `{ exit_code, stdout, stderr }` on `meta_wp_cli.command_failed`, `global_color_delete` carries `{ id, ref_count, locations[], scan_truncated, scanned_posts[] }` on `conflict`, and conflict-class adopters across `canvas_*`/`library_*`/`variable_*` echo the conflicting fields. The shape is per-tool and documented in each tool's description prose, not in this canonical envelope summary. The summary stays terse because (a) most tools never emit `error.data` and advertising it universally would be misleading, and (b) the per-tool shape diverges and `data?: unknown` would be information-free. The runtime mechanism is `withCode`'s 4th `data` argument (server-local) / `envelope_error()`'s `$data` parameter (plugin-routed); both flow through `wrapResponse` to land on `error.data`.

### `dry_run` plan shape

Every write tool accepts `dry_run: boolean` (default `false`). When `true`, the response carries a uniform plan shape and no state is mutated:

```json
{
  "ok": true,
  "data": {
    "dry_run": true,
    "plan": {
      "summary": "Would update 1 attr path(s) on module 'Hero CTA' (page #42, type divi/button).",
      "changes": [
        { "kind": "module.update", "target": "page#42/divi/button/Hero CTA#button.decoration.font.font.desktop.value.color", "before": "#000", "after": "#ff0066" }
      ]
    }
  }
}
```

`meta_wp_cli` and `scf_import` do not accept `dry_run` (raw passthrough / upstream gap respectively). `scf_sync` passes `dry_run` through to upstream `wp scf json sync --dry-run`, so its preview is the upstream plain-text summary rather than a plugin-built `data.plan`. For bulk preview-then-commit flows (preset reassign, preset cleanup), the pattern is the same universal `dry_run` shape documented above — there is no separate safety-patterns document.

Selected guarded post-content write tools also accept `backup: true`. In apply
mode the Free plugin stores an option-backed rollback snapshot before writing
and returns `data.backup` evidence with the snapshot id and status. With
`dry_run: true`, `backup: true` only reports the planned snapshot; it never
creates an option record.

`diviops_rollback_snapshot_restore` restores those snapshots only to their
captured post/page or Theme Builder layout target. It requires row-level edit
permission, refuses content or supported Divi post-meta drift before mutation,
uses the same full-content integrity/readback guard, and has no force override
or second pre-restore snapshot in this MVP.

`diviops_seo_metadata_update` is a separate explicit-metadata-only path. It
accepts no raw provider keys, requires the checksum from
`diviops_seo_metadata_get`, validates plain text before TSF sanitization,
verifies exact stored readback, and performs request-local restoration on a
mismatch. It does not create a persistent rollback snapshot; effective
provider output is verified through a follow-up get request.

### `_meta.idempotent` markers

Every tool's `_meta.idempotent` field documents how it behaves under repeat calls with identical inputs. Some tools are silent-success idempotent (e.g. `page_trash` on an already-trashed post returns `ok: true` with `data.already_trashed = true`); others are side-effect-equivalent (re-running produces the same final state via different intermediate effects). The per-tool `_meta.idempotent` value itself is the per-tool record — read it from the tool's response; there is no separate idempotency-audit document.

## Configuration

### Environment variables

| Variable | Required | Description |
|----------|----------|-------------|
| `WP_URL` | Yes | WordPress site URL (e.g. `http://mysite.local`) |
| `WP_USER` | Yes | WordPress username with Editor or Admin role |
| `WP_APP_PASSWORD` | Yes | Application Password (spaces stripped) |
| `WP_PATH` | No | WordPress filesystem path for Local by Flywheel, or wrapper working directory when `WP_CLI_CMD` needs project context |
| `WP_CLI_CMD` | No | Custom WP-CLI command prefix for containerized environments (e.g. `ddev wp`, `npx wp-env run cli wp`) |
| `LOCAL_SITE_ID` | No | Override auto-detection of Local by Flywheel site ID |
| `DIVIOPS_WP_CLI_ALLOW` | No | Opt-in extended WP-CLI commands — see [SETUP.md#wp-cli-security](../SETUP.md#wp-cli-security) |
| `DIVIOPS_WP_CLI_SAFE_FS_ROOT` | No | Path to constrain filesystem-touching wp-cli commands. **Required** in `WP_CLI_CMD` wrapper mode |
| `DIVIOPS_WP_CLI_UNSAFE_FS` | No | Set to `1` to disable filesystem flag validation entirely |

### Containerized environments

The server connects via standard WordPress REST API and works with any environment that exposes WordPress over HTTP with Application Password support — Local by Flywheel, DDEV, wp-env, WordPress Studio, DevKinsta, custom hosts. See the [Setup Guide's Local Development Environments section](../SETUP.md#local-development-environments) for environment-specific `WP_CLI_CMD` examples and HTTPS / `WP_ENVIRONMENT_TYPE` notes.

## Troubleshooting

Common quick fixes — full reference in [SETUP.md#troubleshooting](../SETUP.md#troubleshooting).

- **"Missing required environment variable(s)"** — ensure `WP_URL`, `WP_USER`, `WP_APP_PASSWORD` are all set on `claude mcp add`.
- **`npx` fails with "could not determine executable to run"** — use `npx -y --package @rubicontv/diviops-mcp diviops-mcp`; this explicitly selects the MCP server bin.
- **"Connection failed"** — verify the plugin is active by visiting `{WP_URL}/wp-json/diviops/v1/schema/settings`; test the credentials with `curl -u "user:pass" …`.
- **"This tool requires plugin capability"** — the connected plugin does not advertise the capability this tool needs. Server and plugin versions are independent; install a compatible plugin from the same DiviOps suite release or a newer supported component, then reconnect or restart the MCP session to refresh the handshake.
- **Preset edits not visible on the frontend** — Divi serves frontend CSS from `wp-content/et-cache/{post_id}/`, which `wp cache flush` doesn't touch. Use `diviops_meta_flush_cache` after preset writes; `post_id` mode also sweeps that exact directory and reports `post_dir_sweep` evidence.

## Learn more

- [SETUP.md](../SETUP.md) — full onboarding walkthrough (containerized envs, HTTPS, Application Passwords)
- [Per-tool reference](#per-tool-reference) — one row per tool (inputs, `_meta.idempotent`, summary), generated from the registration call sites in `src/index.ts`. The full per-tool description, including response payload and error codes, lives in each tool's own MCP `description` field.
- [SETUP.md#wp-cli-security](../SETUP.md#wp-cli-security) — allowlist, extended commands, FS validation
- Pattern A (refuse-with-override) + Pattern B (preview-then-commit) + universal `dry_run` are documented inline above, in [`dry_run` plan shape](#dry_run-plan-shape); there is no separate safety-patterns document.
- [SETUP.md#troubleshooting](../SETUP.md#troubleshooting) — common errors and resolutions
- Per-tool repeat-call semantics are documented inline above, in [`_meta.idempotent` markers](#_metaidempotent-markers); there is no separate idempotency-audit document.
- **`divi-5-builder` skill** — block format rules, design patterns, workflow guidance (ships in the dist repo)

## Requirements

- Node.js >= 22.0.0
- PHP >= 7.4
- WordPress >= 6.5
- Divi 5 theme active
- DiviOps Agent WordPress plugin installed and active

## License

MIT
