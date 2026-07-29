# FORK.md

This repository is a fork of [oaris-dev/diviops](https://github.com/oaris-dev/diviops).
This file is fork-owned. Upstream does not have it, so it never collides on a merge,
and it is the single record of how this fork differs and why.

## Why this fork exists

DiviOps Agent hardcodes the `divi/` block namespace throughout its block-targeting
code. `page_get_layout` is namespace-agnostic and will happily report a third-party
module as `difl/faq:1`, but every targeting path then refuses that same identifier.
The result is a read/write asymmetry: the plugin hands you a target it will not
accept.

The practical cost is that editing a third-party Divi 5 module's attributes requires
hand-reconstructing raw block markup. On a real page that corrupted 414
`$variable({...})$` tokens, which made the plugin's own validator reject every
subsequent write to that page.

This fork makes block targeting namespace-agnostic so third-party modules
(`difl/*`, `decm/*`, `d5bgo/*`) can be addressed by every operation, not just read.

A companion "bridge" plugin was built and abandoned first. It intercepted REST
responses and repaired them from outside. It was retired because it can only fix one
endpoint family at a time and structurally cannot fix Theme Builder insert, which
rejects third-party content with a 400 before any response a bridge could see.

## The drop-in constraint

**Four identifiers must never change.** They are the entire reason this fork loses
nothing relative to running stock DiviOps:

| Identifier | Value |
| ---------- | ----- |
| Plugin slug | `diviops-agent` |
| Main class | `DiviOps_Agent` |
| REST namespace | `diviops/v1` |
| Handshake filter | `diviops_agent_handshake_extensions` |

DiviOps Agent Pro is a separate commercial plugin that is **not** forked. It attaches
by calling `class_exists( 'DiviOps_Agent' )` and hooking
`diviops_agent_handshake_extensions`. The published `@diviops/mcp-server` npm package
calls `/diviops/v1/*` routes and gates each tool on the capability keys the handshake
returns.

Rename any of the four and Pro silently stops contributing its capabilities, and MCP
tools silently disappear rather than erroring, because the server's design is
"capability gate fails, tool is simply absent." The breakage is silent. Treat these
as frozen.

This is a deliberate exception to the maintainer's default `rtv_` vendor-prefix rule.
Preserving upstream identity is required for compatibility.

## Maintained-fork posture

Owner decision (2026-07-27): this is a **fork we own and maintain**, not a
divergence-minimizing upstream tracker. We set the version, roadmap, README, skills,
and release process. Upstream `oaris-dev/diviops` syncs from a private dev repo, takes
no outside PRs, and is treated as an occasional sync source we may cherry-pick from,
not something we actively track. We do not constrain edits to minimize divergence from
upstream.

Two things stay non-negotiable regardless of this posture: the four frozen identifiers
above (Pro + MCP compatibility), and honoring the fork base's license and attribution
to `oaris-dev`. The divergence tables below are retained as a record of what we've
changed relative to the fork base — history, and a reconciliation aid if we ever
cherry-pick an upstream change — not a constraint to keep divergence small.

Versioning follows the standard rubicon originating-repo baseline (release-please +
`CHANGELOG.md` + signed `vX.Y.Z` releases), not the manual-release pattern used by
upstream-tracking forks: this repo is a GitHub/rubicon repo and upstream ships no
release automation or changelog to collide with (#48).

## Divergence from upstream

Fork-owned files, added here, absent upstream. These never conflict on merge:

| Path | Purpose |
| ---- | ------- |
| `FORK.md` | This file |
| `CLAUDE.md` | Fork-owned agent instructions |
| `AGENTS.md` | Pointer stub to `CLAUDE.md` |
| `.gitignore` | Upstream ships none; ignores `worktrees/`, OS junk, and `diviops-server`'s Node build artifacts (`node_modules/`, `dist/`, `dist-test/`) |
| `.github/workflows/test.yaml` | Upstream ships no CI; adds the PHP lint/test jobs plus a `diviops-server` security-test lane (#27) |
| `tests/` | Upstream ships no tests |
| `plugins/diviops-agent/includes/trait-revision.php` | Fork-authored trait (`DiviOps_Agent_Revision`) adding the `revision` capability domain: read/diff/restore over WordPress's own native post revisions (`wp_get_post_revisions` / `wp_restore_post_revision`), complementary to the existing option-backed `rollback` snapshot store. A wholly new file, absent upstream, so it never conflicts on merge; wired into `diviops-agent.php` via a `require_once` + `use DiviOps_Agent_Revision;` alongside the other `trait-*.php`. `revision_list`/`_get`/`_diff` are `check_read_permission` + row-level `can_inspect_post_object` gated on the parent post; `revision_restore` is `check_write_permission` + `current_user_can('edit_post', parent)` gated, dry-run planned, and busts the Divi cache after a successful restore (#34) |
| `plugins/diviops-agent/includes/trait-media.php` | Fork-authored trait (`DiviOps_Agent_Media`) adding the `media` capability domain, absent upstream: `media_upload` (from a public URL, server-fetched, or from base64 bytes; exactly one of the two), `media_get`, `media_list`, `media_set_featured_image` (by existing attachment id or by uploading from a URL first), and `media_update_meta` (set/clear an attachment's alt text and/or caption; omitted field is left untouched, an explicit empty string clears it, idempotent no-op when the resulting values already match, dry-run supported) (#33). Security posture: URL uploads are SSRF-guarded — `media_url_is_safe()` rejects non-http(s) schemes and hosts that resolve to a reserved/private IPv4 or IPv6 range, and `media_fetch_to_temp()` re-runs that same check on every redirect hop (not just the caller's initial URL) with a bounded hop count, since WP's own auto-following `download_url()` would otherwise let a public origin 302 straight to a blocked target. `media_filetype_error()` validates the real file bytes via `wp_check_filetype_and_ext()` against the site's allowed mime types, rejecting an extension/content mismatch (type-spoofing) independent of the declared filename. SVG is rejected with `svg_sanitizer_required` unless `media_svg_sideload_sanitizer_active()` verifies Safe SVG's own callback (a `safe_svg` instance) is bound to `wp_handle_sideload_prefilter` — `has_filter()` alone would only prove something is listening, not that it sanitizes, so this scans `$wp_filter` for the specific class and fails closed when Safe SVG isn't installed. `media_get`/`media_list`/`media_update_meta` apply the same per-object `edit_post` read/write gate (`can_inspect_post_object()` / `query_inspectable_post_ids()` for the reads, direct `current_user_can('edit_post', $id)` for the `media_update_meta` write) as every other read/list/write handler, so a caller only sees or edits attachments it can edit. A wholly new file, absent upstream, so it never conflicts on merge; wired into `diviops-agent.php` via a `require_once` + `use DiviOps_Agent_Media;` alongside the other `trait-*.php` (#28, #33) |
| `diviops-server/tsconfig.test.json`, `diviops-server/src/__tests__/` | Upstream ships no tests for `diviops-server`. A `node:test` suite scoped to `wp-cli.ts` (the WP-CLI command allowlist) and `wp-cli-fs-validator.ts` (the filesystem safe-root validator) — the two most security-relevant modules in the MCP server, previously untested (#27). Compiled via a dedicated `tsconfig.test.json` (outputs to `dist-test/`) rather than the project-wide `build`/`test` scripts, because `src/index.ts` currently fails to build for unrelated reasons (missing `cross-env-preflight` modules) that would otherwise block this suite from running at all |
| `release-please-config.json`, `.release-please-manifest.json` | Upstream ships no release automation; release-please config + manifest for maintained-fork versioning — release-type `simple`, bare `vX.Y.Z` tags (`include-component-in-tag: false`), version bumped in `diviops-agent.php` via the generic updater's block/inline markers. The first fork release is bootstrapped to `1.6.0` via a one-time `Release-As: 1.6.0` commit footer on `main`, NOT the deprecated `release-as` config key (which would force that same version on every subsequent release); after the bootstrap, versions are conventional-commit-driven (#48) |
| `.github/workflows/release.yaml` | Upstream ships no CI; the release-please workflow. Runs under the org-wide `rubicon-release-please` GitHub App (verified/signed release commits), pulling the app credentials at runtime from 1Password so only `OP_SERVICE_ACCOUNT_TOKEN` is a repo secret (#48) |
| `CHANGELOG.md` | Upstream ships none; the fork's Keep-a-Changelog changelog, generated by release-please from Conventional Commits and curated per release (#48) |
| `skills/divi-5-builder/references/advanced-attributes.md`, `scripts/extract-decoration-paths.php` | Clean-room reference for Divi 5's seven "advanced" `module.decoration.*` families outside Tier 1's everyday border/background/spacing set — box shadow, filters, transform, sticky position, transition, scroll effects, and entrance animation — documenting canonical paths, value shapes, responsive/hover support, copy-paste `attrs` fragments, and provenance, in the same shape `module-formats.md` uses for the common set. `extract-decoration-paths.php` is the accompanying extraction helper: it reads Divi's own `Module/Options/<Group>/<Group>PresetAttrsMap.php` classes directly and prints the canonical `module.decoration.*` dot-paths each family declares, used to cross-verify every path documented in the reference. Both authored clean-room from Divi's own source only — the shared `PresetAttrsMap.php` classes, the matching `StyleLibrary/Declarations/<Group>/<Group>.php` CSS-emission classes, compiled Visual Builder JS (`visual-builder/build/*.js`), and this fork's own site for VB round-trip verification; `diviops-agent-pro` was never opened while authoring either file (#62) |

Modified upstream files. Each carries fork changes reconciled by hand only if we ever
cherry-pick an upstream change (we do not actively track upstream — see Maintained-fork
posture):

| Path | What diverges | Issue |
| ---- | ------------- | ----- |
| `diviops-server/package.json` | Adds a `test:server-security` npm script (`tsc -p tsconfig.test.json && env -u DIVIOPS_WP_CLI_ALLOW node --test dist-test/__tests__/*.test.js`) that runs the new WP-CLI allowlist / filesystem path validator suite independently of the existing `build`/`test` scripts, so it isn't blocked by the unrelated `src/index.ts` build failure (#27) | #27 |
| `plugins/diviops-agent/diviops-agent.php` | Adds namespace-agnostic block-comment constants (`BLOCK_OPEN_PREFIX`, `BLOCK_CLOSE_PREFIX`, `BLOCK_NAME_PATTERN`, `DEFAULT_BLOCK_NS`). The `divi/`-specific `SECTION_OPEN`, `SECTION_CLOSE`, and `BLOCK_PREFIX` constants are retained, unused, because they are public class constants external code may reference (#2). Adds `GLOBAL_LAYOUT_BLOCK_NAME` for the `divi/global-layout` wrapper name every counting site checks against (#13). Registers `POST /variable/update` (admin-only, same permission callback as `/variable/create` and `/variable/delete`) and adds the `variable_update` capability key so the handshake advertises it (#25). Registers `POST /library/delete/(?P<id>\d+)` (admin-only, same permission callback as `/library/save`) and adds the `library_delete` capability key (#26). Adds an optional `post_type` arg (default `page`) to the `/page/create` route (#31). Registers the four menu-CRUD write routes (`POST /menu/delete/(?P<id>\d+)`, `POST /menu/item/remove`, `POST /menu/item/reorder`, `POST /menu/location/unassign`), all gated by the existing `check_menu_permission` callback, and adds the `menu_delete`, `menu_item_remove`, `menu_item_reorder`, and `menu_location_unassign` capability keys to the handshake (#30). Registers `POST /page/block-insert/(?P<id>\d+)` (write-gated, same `check_write_permission` as `/page/create`) and adds the `page_block_insert` capability key (#32). Adds release-please version markers so the maintained-fork release automation bumps both version locations: `x-release-please-start-version` / `x-release-please-end` block markers wrap the WP header `Version:` line (a block form, so no inline comment corrupts WP's header parser — verified WP still reads the value cleanly), and an inline `// x-release-please-version` marks `const VERSION` (#48). Registers the four native-revision routes (`GET /revision/list/(?P<id>\d+)`, `GET /revision/get/(?P<revision_id>\d+)`, `POST /revision/restore/(?P<revision_id>\d+)`, `GET /revision/diff`) — list/get/diff `check_read_permission`, restore `check_write_permission` — and adds the `revision_list`, `revision_get`, `revision_restore`, and `revision_diff` capability keys to the handshake (#34). Registers the four media routes (`POST /media/upload`, `GET /media/get/(?P<id>\d+)`, `GET /media/list`, `POST /media/set-featured-image`) — upload and set-featured-image gated by `check_authenticated_permission` (both mutate; set-featured-image adds its own per-object `edit_post` check inside the handler), get and list by `check_read_permission` — and adds the `media_upload`, `media_get`, `media_list`, and `media_set_featured_image` capability keys to the handshake (#28). Registers `POST /media/update/(?P<id>\d+)` (`check_authenticated_permission`, plus its own per-object `edit_post` check inside the handler, matching `media_set_featured_image`'s pattern) and adds the `media_update_meta` capability key (#33). Adds the `theme_options_update` capability key to `CAPABILITIES` so the handshake advertises the pre-existing `POST /theme-options` route (registered since before this fork, gated by the same `manage_options` check inside its handler, but never listed in `CAPABILITIES` — so `diviops_theme_options_update` (#29) would otherwise have no MCP tool to attach to) — the one-line mechanical addition the array's own docblock requires for any route a tool is added for; no route, handler, or permission behavior changes (#29). | #2, #13, #25, #26, #31, #30, #32, #48, #34, #28, #33, #29 |
| `plugins/diviops-agent/includes/trait-core.php` | Adds the `block_identifier_from_name()` / `block_name_from_identifier()` pair that defines the targeting-identifier contract, plus `next_block_opener()`. Makes the write-safety marker census, the marker-sequence validator, and block-attr normalization namespace-aware (#2). `block_opener_is_self_closing()` delegates to `block_opening_comment_end()` instead of a raw `strpos` for `-->`, so it no longer misreads a container as self-closing when an attribute value contains a `/-->`-shaped sequence (#6). Adds `counted_block_name()` / `counted_block_identifier()`, which resolve a `divi/global-layout` wrapper's counted type from its own `attrs.blockName` (falling back to the wrapper's literal name when that attr is absent), so every counting site agrees with what `page_get_layout` counts the wrapper as on read (#13). Adds the layered write-safety guard for the global-layout materialization hazard: `parse_blocks_for_write()` routes a write-path parse through Divi's own `BlockParserUtils::parse_blocks_with_layout_context( $content, 'saving_content' )` when available, falling back to plain `parse_blocks()` otherwise; `global_layout_wrapper_identities()` is a JSON-aware scan that reads each wrapper's `globalModule` id in document order (falling back to a `no_global_module_id_sentinel()` token for a wrapper with no readable id) and returns `null` — an untrustworthy scan — the moment any opener anywhere in the content can't be resolved to its own comment terminator; `global_layout_wrapper_drift()` compares the two sides' id multisets (identity-aware, not count-only, so a wrapper swapped for a different one is drift even though the overall count is unchanged) and treats a `null` scan on either side as drift, refusing the write rather than risking a malformed opener silently masking a real loss; `update_post_content_with_integrity_guard()` gains an opt-in `$check_global_layout_drift` parameter so only the parse/serialize round-trip call sites enforce it, not the raw-content writers that share the same function (#11). `global_layout_wrapper_drift()` is split into `global_layout_write_refusal_reason()`, which returns which of the two refusal reasons applies (`identity_lost` or `scan_unreliable`) instead of a single bool, so `update_post_content_with_integrity_guard()` can emit a distinct error code and message for each; `global_layout_wrapper_drift()` itself becomes a thin bool wrapper kept for `preset_reassign()`'s direct call (#23). | #2, #6, #11, #13, #23 |
| `plugins/diviops-agent/includes/trait-page.php` | Namespace-agnostic raw scanners in `module_update()` and `find_block()`, `*/section` matching in `find_all_sections()`, shared identifier derivation in `parse_block_tree()` and `walk_and_mutate()`, and namespace-agnostic parser-backed collectors for the `module_get` / `module_move` fallbacks (#2). Adds `block_opening_comment_end()`, a JSON-string-aware scan that keeps `find_block()` from truncating a module's span when a `-->` appears inside one of its attribute values (#5). Routes the remaining raw `strpos($content, '-->', $pos)` sites through that same helper: `find_block()`'s own container depth-scan, `module_update()`'s attribute-span scan, `extract_attrs_from_block_markup()`, and both the opening-comment scan and depth-scan in `find_all_sections()` — closing the class of bug where a descendant module's attribute JSON contains an ancestor's closing comment, or a block's own attribute JSON contains a `-->` (#6). `find_block()`, `module_update()`'s inline scanner, `find_all_sections()`, `parse_block_tree()`, and `walk_and_mutate()` all route their type/section resolution through `counted_block_name()` / `counted_block_identifier()`, so a `divi/global-layout` wrapper counts as the type it resolves to instead of counting literally as `global-layout:N` (#13). `collect_readable_divi_blocks()` (`module_get`'s parser fallback) and `collect_parser_move_blocks()` (`module_move`'s parser fallback) now route through the same `counted_block_identifier()` resolution, closing the one gap #13 left out of scope (#14). `find_all_sections()` now checks `is_self_closing` (via `block_opening_comment_end()`) before its own nesting depth-scan, mirroring `find_block()`: a self-closing section opener, wrapper or not, is a complete one-comment span rather than routed through the depth-scan, which previously consumed the enclosing (or a later) section's real closer and reported a bogus overlapping match (#12). `load_post_for_module_op()` (shared by `module_lock`/`module_unlock`/`module_clone`) and `move_block_with_parser()` (`module_move`'s parser fallback) now parse through `parse_blocks_for_write()` instead of bare `parse_blocks()`, and both writer call sites (`save_mutated_blocks()`, and `module_move()`'s own `update_post_content_with_integrity_guard()` call) pass `$check_global_layout_drift = true` (#11). `page_create()` gains an optional `post_type` param (default `page`): it reads and `sanitize_key()`s the param, rejects an unregistered type with `invalid_input` (a deliberate divergence from `page_list`, which silently falls back — creation must not silently retarget a write), and threads the resolved type into the dry-run plan, `wp_insert_post`, and `initialize_divi_page_meta()` (whose `_et_pb_built_for_post_type` meta now reflects the real type instead of a hardcoded `page`; the helper gains a `$post_type` param defaulting to `page`) (#31). Adds `page_block_insert()` — the page counterpart to `tb_layout_block_insert`, inserting a block (row/column/module) at a `parent_path` or `parent_selector` position (append/prepend/before/after) without rebuilding the section. It REUSES the generic tree helpers (`find_tb_block_by_path`/`find_tb_block_by_selector`/`apply_tb_block_insert`/`tb_insert_sequence_matches`/the stable-labeled-sequence checks — named `tb_*` by birthplace but not TB-specific) rather than duplicating them, and leaves the #11-critical TB handler untouched. Being a `parse_blocks_for_write()` → `serialize_blocks()` round trip, it passes `$check_global_layout_drift = true` to `update_post_content_with_integrity_guard()` so a page carrying a `divi/global-layout` wrapper cannot have it materialized by the write (#32). `page_update_content()` no longer re-stamps the Divi page meta on every content write: it gated the `initialize_divi_page_meta()` call behind a new `should_init_divi_page_meta_on_write()` (Divi content AND not already a Divi page) and passes the post's real `post_type`, fixing the bug where every edit reset `_et_pb_page_layout` to full-width and mis-keyed `_et_pb_built_for_post_type` to `page` on a non-page post (#45). | #2, #5, #6, #11, #12, #13, #14, #31, #32, #45 |
| `plugins/diviops-agent/includes/trait-module-schema.php` | Adds `is_divi_module_block()` so schema listing and dumping recognize third-party Divi modules, and accepts a namespaced name in `schema_get_module()` (#2). Adds native-module schema introspection: native Divi 5 core modules (`divi/text`, `divi/section`, …) are largely absent from `WP_Block_Type_Registry`, so `schema_get_module()` now falls back to reading Divi's own on-disk `module.json` (under `includes/builder-5/visual-builder/packages/module-library/src/components/<slug>/`, resolved via `get_theme_file_path()`), and `schema_list_modules()` augments its list from the same files. New helpers: `native_module_slug_from_name()` maps `divi/<slug>` to a dir slug and is the hard path-traversal guard (slug must be `^[a-z0-9]+(?:-[a-z0-9]+)*$`, so nothing with `.`/`/`/`..` reaches the filesystem); `native_module_schema_from_dir()` adds a realpath-containment check on top; `parse_native_module_json()` rejects a file whose declared `name` mismatches the request. Responses carry a `source` (`divi_module_json` vs `block_registry`). Live-verified on Divi 5.9 (native modules resolve with their attribute trees; traversal names return not_found) (#42). `schema_get_module_dump_all()` (the build-time bulk source for the skill-regen pipeline) now also merges native modules' full schemas via a `native_module_schemas_all_from_dir()` bulk read of the same `module.json` files (reusing `parse_native_module_json`), so the bulk dump is complete; registered entries win on a name collision (#61). | #2, #42, #61 |
| `plugins/diviops-agent/includes/trait-theme-builder.php` | Theme Builder insert accepts any namespaced block, `parse_tb_parent_selector()` accepts any namespace, the cross-env preset and attachment scanners no longer skip third-party blocks, and malformed-comment detection covers every namespace (#2). `tb_layout_block_insert()` parses the stored layout through `parse_blocks_for_write()` instead of bare `parse_blocks()`, and its `update_post_content_with_integrity_guard()` call passes `$check_global_layout_drift = true`. `parse_divi_blocks_for_insert()` also parses the caller-supplied INSERTION content through `parse_blocks_for_write()` — a second write-path parse in the same operation that the initial version of this fix missed, since a wrapper arriving in the inserted content itself is expanded before it ever reaches the tree, ahead of anything the drift-guard's baseline could compare against. `tb_layout_update()` (a raw-content write, not a parse/serialize round trip) is unchanged (#11). | #2, #11 |
| `plugins/diviops-agent/includes/trait-preset.php` | `preset_reassign()` parses through `parse_blocks_for_write()` instead of bare `parse_blocks()`. It bypasses `update_post_content_with_integrity_guard()` (a batch operation across many pages does not fit that function's single-post readback/revert contract), so it calls `global_layout_wrapper_drift()` directly before its own `wp_update_post()`, refusing only the affected page — with an error recorded in the batch summary — rather than the whole batch (#11). | #11 |
| `plugins/diviops-agent/includes/trait-library.php` | Adds `library_delete()` — the `library` domain had list/get/save but no removal path, the only content-holding domain without one (removal meant wp-admin or a raw `wp post delete`). Mirrors the sibling `page_trash`: soft-trash by default (reversible), opt-in `force` for permanent `wp_delete_post`, dry-run planning, and idempotent no-op semantics on an already-trashed item (returns `already_trashed`). Guards on post existence AND `post_type === 'et_pb_layout'` so it cannot trash a non-library post that shares the id space, then a per-object `delete_post` capability check. Trash is safe for the domain's re-save contract because `library_existing_id_by_title()` queries `post_status => 'any'`, which excludes trash, so a trashed item never blocks re-saving its title. No Divi-cache invalidation, matching the sibling `library_save`. | #26 |
| `plugins/diviops-agent/includes/trait-variable.php` | Adds `variable_update()` — the `variable` domain had create/delete/create-fluid-system/scan-orphans/used-on-page but no update, so changing a token's value meant delete-then-recreate, which mints a fresh id unless the caller explicitly re-supplies the old one, and even then overwrites unconditionally with no not-found/partial-merge contract. `variable_update()` looks up the existing record by id (auto-detecting the `colors` vs `et_divi_global_variables` bucket from the `gcid-`/`gvid-` prefix, same as `variable_delete()`), rejects an unknown id with `not_found`, and merges only the caller-supplied fields (label/value/status) via the new pure helper `build_updated_variable_record()`. That helper never receives `id` or `type` in its override set, so both are structurally immutable through this endpoint — the property existing `$variable({...})$` page references depend on, since the token embeds the id, not the value. `value` validation mirrors `variable_create()`'s per-type rules, reusing `build_gradient_variable_value()` for `type=gradients`. Does not regenerate a fluid `clamp()` from min/max/targets — that shorthand stays create-only. | #25 |
| `plugins/diviops-agent/includes/trait-menu.php` | Adds the four menu-CRUD handlers the `menu` domain was missing — it shipped create/get/list/item-add-page/item-add-custom/location-assign but no removal, reorder, or location-clear path. `menu_delete()` permanently deletes a menu (nav menus have no trash, so no `force`), capturing the theme locations assigned to it before `wp_delete_nav_menu()` frees them and reporting them in `freed_locations`. `menu_item_remove()` removes one item: by default it re-parents the target's direct children to the target's own parent (via the `_menu_item_menu_item_parent` post meta) so the tree stays connected, then deletes the target; `cascade=true` removes the target and every descendant, walked within the menu. `menu_item_reorder()` renumbers `menu_order` 1..N across one level, rejecting any `order` that is not an exact duplicate-free permutation of the ids whose parent equals `parent`. `menu_location_unassign()` mirrors `menu_location_assign()` in reverse — validates the location is registered, no-ops idempotently when it is not assigned, and otherwise unsets it from `nav_menu_locations` via `set_theme_mod()`. All four follow the existing menu patterns (the `menu_can_manage`/`menu_forbidden` gate first, `menu_object_by_id`/`menu_not_found`, inline dry-run plans, `menu_readback` success readback). | #30 |
| `diviops-server/src/index.ts` | Adds the `diviops_variable_update` tool, mirroring `diviops_variable_create`'s per-type `value`/`gradient` input shape and `diviops_global_font_update`'s partial-update framing, calling `POST /variable/update` (#25). Adds the `diviops_library_delete` tool, mirroring `diviops_page_trash`'s `force`/`dry_run`/idempotent shape, calling `POST /library/delete/{id}` (#26). Adds an optional `post_type` input (default `page`) to `diviops_page_create`, forwarded to `POST /page/create` (#31). Adds the four menu-CRUD tools — `diviops_menu_delete` (mirrors `diviops_menu_get`'s id shape, POST + dry_run, no force since nav menus have no trash), `diviops_menu_item_remove` (menu_id/item_id/cascade/dry_run), `diviops_menu_item_reorder` (menu_id/order[]/parent/dry_run), and `diviops_menu_location_unassign` (location/dry_run, the mirror of `diviops_menu_location_assign`) — and refreshes the `diviops_menu_item_add_page` / `_add_custom` / `_location_assign` descriptions that previously advertised no delete/reorder/unassign path (#30). Adds the `diviops_page_block_insert` tool, mirroring `diviops_tb_layout_block_insert`'s selector/path/position/backup shape, calling `POST /page/block-insert/{id}` (#32). Adds the four native-revision tools — `diviops_revision_list` (post `id` → newest-first metadata), `diviops_revision_get` (`revision_id` → raw content), `diviops_revision_diff` (`from` required, optional `to`, else vs current), and `diviops_revision_restore` (`revision_id`/`dry_run`, mirroring `diviops_rollback_snapshot_restore`'s POST+dry_run shape) — calling `/revision/*`; inspected-only, since the server does not build from this repo (#41) (#34). Adds the four media tools — `diviops_media_upload` (exactly one of `url` or `data_base64`+`filename`, plus `attach_to`/`title`/`alt`/`caption`/`dry_run`), `diviops_media_get` (`attachment_id`), `diviops_media_list` (`page`/`per_page`/`mime`/`search`), and `diviops_media_set_featured_image` (exactly one of `attachment_id` or `url`, `dry_run`; idempotent only on the `attachment_id` path, since the `url` path uploads a fresh attachment on every call) — calling `POST /media/upload`, `GET /media/get/{id}`, `GET /media/list`, and `POST /media/set-featured-image` (#28). Adds the `diviops_theme_options_update` tool for the pre-existing `POST /theme-options` route, which had no MCP tool. The route predates the envelope convention (`WP_Error`/`rest_ensure_response`, not `{ok,data}`) — no client-side translation was needed because `wp.requestEnveloped`'s existing legacy-`WP_Error` and 2xx-non-enveloped-wrap branches already normalize it. `dry_run` is computed entirely client-side (the route has no `dry_run` support): reads current values via the `/schema/settings` route diviops_schema_get_settings also calls, then diffs against the requested `options` restricted to the write route's 9-key allowlist (`heading_font`, `body_font`, `accent_color`, `secondary_accent_color`, `font_color`, `header_color`, `link_color`, `heading_font_size`, `body_font_size`) — a key list that is NOT identical to `filter_public_theme_options()`'s 10-key read allowlist (which also includes `body_header_size`); unrecognized keys surface as a dry_run warning rather than a change, mirroring the write route's own silent-drop behavior (#29). | #25, #26, #31, #30, #32, #34, #28, #29 |
| `README.md` | Taken over as the maintained fork's README (#49). Reframes the intro as `rubicon/diviops` with explicit credit to `oaris-dev/diviops` and a maintained-fork note (fork capabilities + the drop-in guarantee that keeps Pro and the MCP server working). Clarifies the repository's dual-licensing in the License section and badge — MIT for the server/skills/templates/docs/tests, GPL-2.0-or-later for the WP plugins — replacing the prior "MIT" oversimplification (no code license change; the split is upstream's own, per `LICENSE`). Adds a Pro-compatibility + clean-room-skills note to the Free/Pro section (#49, #50). | #49 |

### Deliberately unchanged: `post_uses_divi()` / `content_uses_divi()`

These decide "is this Divi content" on the literal substring `<!-- wp:divi/`, and issue
#2's inventory lists them. They are intentionally left alone. Loosening them to accept
any namespace would misclassify ordinary Gutenberg pages as Divi content, because
unrelated third-party blocks (`gravityforms/*`, `pdfemb/*`, `tec/*`) are registered
alongside the Divi ones. The check is also not actually failing: across all 108 posts
on the reference install that carry `difl/*` or `d5bgo/*` blocks, every one also
contains a `<!-- wp:divi/` marker and every one opens with a `divi/` block, because
third-party modules nest inside Divi sections rather than replacing them.

## Upstream tracking

```bash
git remote -v
# origin    git@github.com:rubicon/diviops.git
# upstream  https://github.com/oaris-dev/diviops.git

git fetch upstream
git merge upstream/main        # through the normal issue, branch, PR flow
```

Files that originated upstream (`README.md`, the other repo docs, and everything
under `plugins/`, `diviops-server/`, and `skills/`) may be edited or taken over as
needed under the Maintained-fork posture above — we are not minimizing divergence
from upstream. Record intentional changes in the divergence tables above so a future
upstream cherry-pick stays predictable. Fork operating instructions still belong in
this file or `CLAUDE.md`, never inline in a doc that also serves end users.

## Deliberately out of scope

### Fixed: the `divi/global-layout` index divergence

`page_get_layout` walks the parsed block tree and relabels a `global-layout`
wrapper as the `blockName` it resolves to, counting it as `section:1`. The
raw-string scanners and parsed-tree walkers behind `module_update`, `module_get`
(via `find_block()`), the section operations (via `find_all_sections()`), and
`module_lock` / `module_unlock` / `module_clone` (via `walk_and_mutate()`) used to
count the wrapper literally as `global-layout:1` instead, so every real block after
it landed one `auto_index` position off from what the read path reported. Verified
live against reference page 900390.

Fixed in #13 by resolving the wrapper's counted type from its own
`attrs.blockName` — the wrapper carries the type it resolves to right in its own
opening comment (`{"globalModule":"900296","blockName":"divi/section",...}`), so
reading that attr is enough to agree with the read path. `counted_block_name()` /
`counted_block_identifier()` in trait-core.php are the shared resolution; every
site above routes through them. This is a string/attrs-level fix with no
`parse_blocks()` / `serialize_blocks()` round-trip and no new write blast-radius,
which is what the original scoping had assumed a fix would require.

`module_move`'s parser-backed collector (`collect_parser_move_blocks()`) and
`module_get`'s parser-backed fallback (`collect_readable_divi_blocks()`, reached
only after the raw scanner already fails on malformed markup) were not brought
into #13's fix and still counted a wrapper literally. Fixed in #14 by routing
both through the same `counted_block_identifier()` resolution, so the divergence
is now closed for the parser-backed fallback paths the same way #13 closed it
for the raw-scanner and parsed-tree paths. The real-world exposure was narrow
either way: both fallbacks are reached only when the raw `find_block()` scanner
already returns a `parse_error` on malformed markup.

### Fixed: `find_all_sections()` missing the self-closing check

`find_all_sections()` did not check `is_self_closing` before starting its
nesting depth-scan, unlike `find_block()`, which already did. #13's fix gave
this pre-existing gap a new way to surface: a **self-closing**
`divi/global-layout` wrapper that resolves to a `*/section` name passes the
namespace-agnostic section filter, but since it has no closer of its own the
unguarded depth-scan either consumed an unrelated later closer or found none.
More generally, `find_all_sections()`'s outer loop only advances past
whichever opener it just processed, not past that opener's whole matched
span, so it revisits every descendant opener again as its own top-level
candidate. When a revisited self-closing same-name opener reached the
unguarded depth-scan, it consumed the enclosing (or a later) section's real
closer and reported a bogus match whose bounds overlapped a real one.
`find_block()` was unaffected, since it already checked `is_self_closing`.

Fixed in #12 by giving `find_all_sections()` the same `is_self_closing` check
`find_block()` already had: a self-closing section opener, wrapper or not, is
now a complete one-comment span, matched directly rather than routed through
the depth-scan.

### Fixed: the `module_lock` / `module_unlock` / `module_clone` write hazard

Six write paths round-trip a page's content through `parse_blocks()`, mutate the
parsed tree, and write it back with `serialize_blocks()`: `module_lock`,
`module_unlock`, and `module_clone` (shared via `load_post_for_module_op()` /
`save_mutated_blocks()`), `module_move`'s parser fallback
(`move_block_with_parser()`), `tb_layout_block_insert()`, and `preset_reassign()`.
On a page carrying a `divi/global-layout` wrapper, re-serializing the whole tree
risked materializing the wrapper's resolved content into the page: Divi 5's own
block parser expands that wrapper unless a skip condition holds, and one of those
conditions is `_is_rest_update_request()`, a `$_SERVER['REQUEST_URI']` string match
that fails open outside a genuine REST dispatch (`wp eval`, a raw PHP include, a
non-default REST route prefix, and so on) — a data-integrity hazard independent of
the numbering question #13 fixed. Verified live against reference page 900390: a
bare `wp eval` parse collapsed its one wrapper to zero.

Fixed in #11 with two layers. Layer 1 prevents the expansion outright:
`parse_blocks_for_write()` (trait-core.php) routes every one of the six sites'
parse through Divi's own
`ET\Builder\FrontEnd\BlockParser\BlockParserUtils::parse_blocks_with_layout_context( $content, 'saving_content' )`,
which sets an unconditional skip signal independent of request shape, confirmed
by reading `BlockParser::parse()` on the reference Divi install. It falls back to plain
`parse_blocks()` via `class_exists()`/`method_exists()` when that Divi class is
unavailable. This also covers `tb_layout_block_insert()`'s caller-supplied
INSERTION content (`parse_divi_blocks_for_insert()`), not only the stored layout
being inserted into — adversarial review found that a wrapper arriving in the
inserted content itself would otherwise be expanded before it ever reached the
tree, a loss the drift-guard cannot catch because its baseline never contained
that wrapper to begin with.

Layer 2 is the backstop for when Layer 1 could not apply. `global_layout_wrapper_
identities()` is a JSON-aware scan (same discipline as `block_opener_is_self_
closing()`) that reads each wrapper's `globalModule` id in document order, falling
back to a sentinel token for a wrapper with no readable id so it is still tracked
by count. `global_layout_wrapper_drift()` compares the two sides' id multisets —
identity-aware, not count-only, because a wrapper swapped for a different one
(layout A replaced by layout B) is drift even though the overall wrapper count is
unchanged; Divi's non-recursive expansion can produce exactly that shape for
nested or chained global layouts, and a count-only comparison would miss it.
Either side's scan returning `null` — an opener anywhere in the document that
can't be resolved to its own comment terminator, or a wrapper's own attrs failing
to decode — is itself treated as drift: a malformed prefix ahead of a genuinely
removed wrapper would otherwise truncate both scans to the same wrong count and
mask the loss, so an unreadable scan refuses the write rather than approving it
by default. `update_post_content_with_integrity_guard()` carries this as an
opt-in parameter so it does not also gate the raw-content writers
(`page_update_content`, `tb_layout_update`) that share the same function and are
legitimately allowed to drop a wrapper on purpose; `preset_reassign()` bypasses
that shared function for its own batch write and calls the drift check directly.

With Layer 1 active (the common case), no expansion occurs and Layer 2 never
fires — full capability is preserved everywhere, including `wp eval`. Layer 2 only
matters when Layer 1 could not apply, refusing that one write rather than
corrupting it.

`global_layout_wrapper_drift()` is unit-tested
(`tests/test-global-layout-write-guard.php`): a lost wrapper, a benign reserialize
with the same id, no wrapper on either side, one of two wrappers lost, an
id-swap that a count-only comparison would miss, an unterminated opener ahead of
a removed wrapper (fail-closed catches what a partial count would mask), identical
malformed markup on both sides (a documented deliberate over-refusal — an
unreliable scan cannot prove nothing was lost), and a JSON-embedded decoy
substring that must not be miscounted, plus in-file mutation checks proving each
of those three properties is load-bearing rather than incidental: the
identity-aware comparison (a naive same-count check would call the id-swap
fixture "no drift"), fail-closed (a naive "unscannable equals no drift" check
would allow the malformed-ahead-of-a-removed-wrapper fixture), and the
JSON-aware scan (a naive `substr_count` comparison would misread the decoy
fixture as a lost wrapper). Layer 1 was verified live and read-only against
reference page 900390: `parse_blocks_with_layout_context()` held the wrapper
count at 1 where a bare `parse_blocks()` round trip collapsed it to 0. The full
write path on all six sites (an actual `module_lock`/`clone`/etc. call against a
live database) is not exercised by either the unit tests or that read-only check
and remains gated on the maintainer's own live verification before merge.

`update_post_content_with_integrity_guard()`'s refusal carries a distinct error
code for each of the two reasons the guard can refuse a write (#23): the
comparison is a fail-closed `null` scan on either side (a malformed/unterminated
block comment makes the content unverifiable, whether or not a wrapper is even
present) versus an actual `globalModule` id going missing between the two
sides. `global_layout_write_refusal_reason()` returns `null` / `'identity_lost'`
/ `'scan_unreliable'` so the two cases surface as `{namespace}.global_layout_
drift` (the original wrapper-materialization message, accurate only for an
actual identity loss) and the new `{namespace}.global_layout_scan_unreliable`
(the content could not be verified, so the guard refused rather than risk a
masked loss — nothing was necessarily lost). Before #23, both cases produced the
wrapper-materialization message, which was false on a page with no wrapper at
all whose markup happened to be malformed elsewhere.
`global_layout_wrapper_drift()` is now a thin bool wrapper around the reason
function, kept for `preset_reassign()`'s own direct call, which only needs the
fail/pass verdict.

Third-party targeting does not depend on either of the above: third-party counters
are per block name and are unaffected by the wrapper.

## Licensing

The WordPress plugins are GPL-2.0-or-later, stated in the upstream `LICENSE`, in each
plugin header, and in `readme.txt`. Forking and modifying them is expressly
permitted. The MCP server, skills, templates, and docs in this repository are MIT.
Both notices are preserved as upstream wrote them.

## Contributing back

The namespace-agnostic change is a generic fix that benefits every DiviOps user, not
just this fork. The intent is to offer it upstream as a pull request once it is
proven here. An upstream PR is gated on the maintainer's explicit per-PR approval and
follows the Outbound PR Authorship Standard.
