# SCF (Secure Custom Fields) — Discovery, JSON Sync, and Divi 5 Dynamic Content

Reference for the six `diviops_scf_*` MCP tools and for the one thing a page author
actually needs from them: getting a custom-field value onto a Divi 5 module. It
documents what the shipped code does, not what ACF's documentation says in general.

**Clean-room provenance**: every claim below is derived from one of four sources, each
named inline — (1) this repository's own code (`diviops-server/src/index.ts`,
`src/wp-cli.ts`, `src/wp-cli-fs-validator.ts`, `plugins/diviops-agent/includes/trait-dynamic-content.php`),
(2) Divi 5.9.0's own server source on the reference install, (3) live read-only calls
against the reference site on 2026-08-14, (4) the shipped tool descriptions in
`index.ts`, cited as descriptions rather than as behavior. The `diviops-agent-pro`
plugin was **never opened** while authoring this file, and nothing here is derived
from it.

## How to read the verification markers

This file uses the same three tiers as
[SKILL.md → Verification convention](../SKILL.md#verification-convention). No fourth
tier is invented for "read from source":

| Marker | Meaning here |
|---|---|
| `*(verified 2026-08-14)*` | Observed live: a `diviops_*` call or a read-only `wp` command against the reference site returned this. |
| `<!-- UNVERIFIED -->` | Read from a named source file at named lines, but **not** exercised on a live install. Source-only claims are UNVERIFIED by this skill's own convention. |
| `*(VB-verified …)*` | **Not used in this file.** Nothing here was round-tripped through the Visual Builder. |

**Reference-site status, and what it costs this document**: SCF is **not installed** on
the reference site. `wp plugin list` carries no `secure-custom-fields`,
`advanced-custom-fields`, or `advanced-custom-fields-pro` entry, the
`acf-field-group` post type is unregistered, and `wp scf …` is not a registered
wp-cli command *(all verified 2026-08-14)*. Every claim about what SCF itself does
once installed — file locations, sync semantics, export filenames — is therefore
UNVERIFIED. Claims about **our** tools, about Divi's integration code, and about the
live dynamic-content registry are verified where marked.

## 1. The SCF domain is MCP-local, not a plugin capability

The most common wrong mental model. SCF is **not** a `diviops-agent` capability
domain:

- There is no `plugins/diviops-agent/includes/trait-scf.php`, no `/diviops/v1/scf/*`
  route, and no `scf_*` key in the handshake's `CAPABILITIES` array. A case-insensitive
  search for `scf` across `plugins/diviops-agent/` matches only the plugin README and
  one comment in `trait-dynamic-content.php` *(verified 2026-08-14)*.
- All six tools are registered with `registerLocalTool` in
  `diviops-server/src/index.ts` (SCF section, ~L4600-5040). They run wp-cli in a child
  process from the MCP server host and never touch the plugin. <!-- UNVERIFIED -->

Consequences that bite:

1. **The capability handshake does not gate these tools.** They are registered whether
   or not SCF exists on the site. A missing SCF surfaces as a per-call failure, not as
   an absent tool — the opposite of the Pro-tool convention described in the
   [diviops/](../../diviops/SKILL.md) primer.
2. **They need `WP_PATH` (Local by Flywheel) or `WP_CLI_CMD` (containerized).** Without
   one, every SCF tool short-circuits to `scf.not_configured` before running anything.
   Application-Password REST credentials are irrelevant here. <!-- UNVERIFIED -->
3. **Two of the six do not use SCF's CLI at all.** `diviops_scf_field_group_list` and
   `diviops_scf_field_group_get` query the `acf-field-group` post type through
   `wp post list` / `wp post get`, so they work against older ACF installs that never
   had the `wp scf json` family. The other four require SCF 6.8.4+. <!-- UNVERIFIED -->

### Exact wp-cli argv each tool builds

Read from `index.ts` directly, in build order. `--path=<WP_PATH>` and `--no-color` are
appended by `wp-cli.ts`'s runner (or replaced by the `WP_CLI_CMD` prefix in wrapper
mode). <!-- UNVERIFIED -->

| Tool | argv |
|---|---|
| `diviops_scf_status` | `scf json status --format=json` `[--type=…]` `[--detailed]` |
| `diviops_scf_export` | `scf json export` `[--stdout]` `[--dir=…]` `[--field-groups=…]` `[--post-types=…]` `[--taxonomies=…]` `[--options-pages=…]` |
| `diviops_scf_import` | `scf json import <file>` |
| `diviops_scf_sync` | `scf json sync` `[--type=…]` `[--key=…]` `[--dry-run]` |
| `diviops_scf_field_group_list` | `post list --post_type=acf-field-group --post_status=any --fields=ID,post_name,post_title,post_status,post_modified --format=json` |
| `diviops_scf_field_group_get` | `post list --post_type=acf-field-group --post_status=any --name=<key> --fields=ID --format=json`, then `post get <id> --format=json` |

Note what `--format=json` does and does not buy you: `scf_status` and `scf_export`
return wp-cli's raw `stdout` **as a string**, unparsed. Only the two `wp post` tools
call `JSON.parse` on it. Parse `status`/`export` output yourself. <!-- UNVERIFIED -->

## 2. Field-group discovery

Three tools, three different depths. Pick by what you need.

| Need | Tool | Returns |
|---|---|---|
| Inventory: what groups exist, and their ACF keys | `diviops_scf_field_group_list` | `Array<{ ID, post_name, post_title, post_status, post_modified }>`, parsed |
| One group's WP post record | `diviops_scf_field_group_get` | The `wp post get` object, including `post_content` — a serialized fields blob, not a usable tree |
| The parsed field tree, including nested fields | `diviops_scf_export` with `stdout: true` and `field_groups: "<key>"` | SCF's own export JSON on stdout |

The identifier vocabulary is the part people get wrong, and the three names are not
interchangeable:

- **ACF key** (`group_5f8a1b2c3d4e5`) is stored in WordPress's `post_name` column. This
  is what `diviops_scf_field_group_get` matches on, and what `--field-groups=` accepts.
  <!-- UNVERIFIED -->
- **Admin title** is `post_title`; the tool description states SCF's filters also match
  titles case-insensitively. <!-- UNVERIFIED -->
- **Registered post-type / taxonomy slug** (`event`, `book`) is what `wp post list` and
  REST URLs use — and is explicitly **not** what `--post-types=` / `--taxonomies=`
  match. Those match the SCF *definition's* own key (`post_type_xxx`) or title. To
  discover definition keys there is no listing tool: run `diviops_scf_export` with
  `stdout: true` and no filter, then read the top-level entries whose `parent` is
  `post-type`. <!-- UNVERIFIED --> (Source: `index.ts` input descriptions for
  `post_types` / `taxonomies`.)

`diviops_scf_field_group_get` resolves a numeric input straight to `wp post get`, and
anything non-numeric through the `post_name` lookup first. An unresolvable value
returns `not_found` with a hint pointing at `..._field_group_list`. <!-- UNVERIFIED -->

## 3. JSON sync — the workflow, and where the files live

`status` → `sync --dry-run` → `sync` is the whole loop.

1. **`diviops_scf_status`** reports how many field groups, post types, taxonomies, and
   options pages have JSON on disk that is newer than the database, or absent from the
   database. `detailed: true` lists the individual pending items instead of counts.
   Read-only. <!-- UNVERIFIED -->
2. **`diviops_scf_sync` with `dry_run: true`** previews. This is the default — the
   input schema declares `.default(true)`, so a bare call **cannot** mutate; you must
   pass `dry_run: false` explicitly to commit. <!-- UNVERIFIED -->
3. **`diviops_scf_sync` with `dry_run: false`** applies, creating/updating database
   entries from the on-disk JSON.

Two contract details worth internalizing:

- **`dry_run` here is not the DiviOps plan shape.** It is passed straight through as
  wp-cli's `--dry-run` flag, so the preview is SCF's plain-text summary, **not** the
  `data.plan = { summary, changes[] }` shape every plugin-routed `dry_run` tool
  returns. The boolean is echoed back in the success payload
  (`{ dry_run, stdout, stderr }`) so a caller can branch without re-reading its own
  args. <!-- UNVERIFIED --> (This divergence is also called out in the
  [diviops/](../../diviops/SKILL.md) primer.)
- **Where the files live is SCF's business, not ours.** `diviops_scf_sync`'s own
  description names "the theme/plugin acf-json directory"; no path argument is accepted
  and none is validated, because the caller never supplies one — which is exactly why
  `scf json sync` is absent from the filesystem-safe-root list in
  `wp-cli-fs-validator.ts` (`FS_SENSITIVE_COMMANDS` covers only `acf export`, `acf import`,
  `scf json export`, `scf json import`, and the `acf json …` aliases). The safe root
  constrains `import`/`export`, and does not constrain what `sync` reads.
  <!-- UNVERIFIED --> The concrete directory SCF picks, and its precedence rules, could
  not be checked on the reference site. <!-- UNVERIFIED -->

## 4. Import / export round-tripping

### Export

`dir` and `stdout` are mutually exclusive and exactly one is required. Passing neither
or both is rejected **before** wp-cli runs, with code `invalid_input` and
`error.data` naming the offending fields (`{ missing: ["dir","stdout"] }` or
`{ conflict: ["dir","stdout"] }`). <!-- UNVERIFIED -->

- `stdout: true` → nothing touches the filesystem, and the FS validator waives its
  checks for that reason. This is the mode to prefer for inspection, and it is the
  only read-only mode. <!-- UNVERIFIED -->
- `dir: "<abs path>"` → the path must be **absolute** (a relative path is rejected
  outright, because wp-cli would resolve it against the child process's cwd rather
  than the safe root) and must canonicalize to somewhere **under** the safe root,
  default `<WP_PATH>/.diviops-tmp/`, overridable with `DIVIOPS_WP_CLI_SAFE_FS_ROOT`,
  disableable with `DIVIOPS_WP_CLI_UNSAFE_FS=1`. Canonicalization means a symlink
  inside the safe root that points outside it is caught. <!-- UNVERIFIED -->
- **Overwrite hazard**: the tool description states SCF writes a fixed filename
  `acf-export-YYYY-MM-DD.json` inside `dir`, so two exports on the same day silently
  overwrite each other. If you are archiving a pre-change baseline, copy or rename it
  immediately. <!-- UNVERIFIED -->
- Filters (`field_groups`, `post_types`, `taxonomies`, `options_pages`) combine; with
  none, everything is exported. `options_pages` requires ACF PRO. <!-- UNVERIFIED -->

### Import

`file` is a required absolute path under the same safe root, positional at argv index 3
(`scf json import <file>`). Relative paths are rejected for the same cwd reason.
<!-- UNVERIFIED -->

The round trip is `export --stdout` (or `--dir`) → edit → `import <file>` → verify with
`field_group_list` / `export --stdout`. The description states import is idempotent:
existing items with matching keys are updated rather than duplicated, which is also why
the tool carries `annotations.idempotentHint` and `_meta.idempotent: "true"`.
<!-- UNVERIFIED -->

### The safety asymmetry to keep in mind

`scf json import` and `scf json sync` **mutate the database and sit in
`wp-cli.ts`'s `DEFAULT_COMMANDS`**, not in the opt-in extended tier — no
`DIVIOPS_WP_CLI_ALLOW` entry is required to run either. <!-- UNVERIFIED --> The
guardrails that do exist are narrower than they look:

| Guardrail | Covers | Does not cover |
|---|---|---|
| Safe-root FS validation | Where `import` reads from, where `export` writes to | What `sync` reads (no caller-supplied path) |
| `dry_run: true` default | `diviops_scf_sync` | `diviops_scf_import` — it has no dry-run at all; SCF's CLI offers none |

So: to preview an on-disk change use `diviops_scf_sync` with the default `dry_run`.
There is no preview for `diviops_scf_import`; take an `export --stdout` baseline first
if you need a way back.

## 5. How SCF fields reach a Divi 5 module

This is the section that matters for page authoring, and the mechanism is not what
"SCF has an ACF integration" suggests.

Divi's dynamic-content option registry is the live
`divi_module_dynamic_content_options` filter, which is exactly what
`diviops_dynamic_content_list` returns. The registry is **dynamic, not a static
catalog**: it reflects the plugins and data that exist on this site at this moment,
which is why there is no fixed list of SCF options to publish here.

### Divi's activation gate

`DynamicContentOptionACFGroups::register_option_callback()` (Divi 5.9.0,
`server/Packages/Module/Layout/Components/DynamicContent/DynamicContentOptionACFGroups.php:84-100`)
returns the options array untouched unless `is_plugin_active()` reports one of exactly
three plugin paths: `advanced-custom-fields/acf.php`,
`advanced-custom-fields-pro/acf.php`, or
`secure-custom-fields/secure-custom-fields.php`. `DynamicContentACFUtils::is_acf_active()`
(`DynamicContentACFUtils.php:82-96`) applies the same three-way check and caches it.
Divi's own comment states SCF is treated as ACF because it forks the same function
names. <!-- UNVERIFIED -->

Live confirmation of the negative side of that gate: with none of the three installed,
`diviops_dynamic_content_list` returned 91 options at `post_id: 0, context: edit`, of
which **zero** had a top-level key containing `acf`, and the option groups present were
Default (27), Divi Library Layouts (DDCH) (29), Loop (12), Loop Menus (7), Loop Terms
(6), Loop Users (6), Global Dynamic Content Sources (DDCH) (1), and one each of Loop
Post / Term / User Custom Fields *(verified 2026-08-14)*.

### Two very different surfaces, depending on field type

**Repeater fields become their own top-level registry entries.**
`DynamicContentOptionACFGroups` iterates only
`ET_Builder_Plugin_Compat_Advanced_Custom_Fields::get_repeater_fields()` and registers
one option per repeater **sub-field**, keyed
`loop_acf_<repeaterName>|||<subFieldName>`, with `group` set to
`Loop ACF <repeaterName>`, `label` set to `<groupKey>: <field label>`, and an extra
`acf_type` carrying the raw ACF type
(`DynamicContentOptionACFGroups.php:118-158`). Note the literal `|||` separator inside
the option name — it lands verbatim inside the `$variable({...})$` token's `name`.
Field types are collapsed for Divi's purposes: `page_link`/`link`/`url` → `url`,
`image` → `image`, `color_picker` → `color`, everything else → `text`
(`:54-68`). <!-- UNVERIFIED -->

**Every non-repeater field surfaces only inside the `post_meta_key` option's
dropdown, never as its own registry entry.**
`DynamicContentACFUtils::get_acf_field_info()` walks `acf_get_field_groups()` /
`acf_get_fields()` and **skips** `repeater`, `group`, and `flexible_content` types
(`DynamicContentACFUtils.php:153-169`). The survivors are merged with the site's
most-used meta keys and handed to `build_meta_key_options()`, which sorts them into
three subgroups under the `custom_meta_` prefix: `custom_meta_group_acf` (labeled
"Advanced Custom Fields"), `custom_meta_group_standard`, and
`custom_meta_group_underscore` (`:444-559`). Underscore-prefixed duplicates are
normalized to the non-underscore key. <!-- UNVERIFIED -->

Live, with SCF absent, the same `post_meta_key` option showed exactly the two
non-ACF subgroups — `custom_meta_group_manual` and `custom_meta_group_standard` — and
its fields were `before`, `after`, `select_meta_key`, `meta_key`, `date_format`,
`custom_date_format`, `enable_html` *(verified 2026-08-14)*. With SCF installed, the
`custom_meta_group_acf` subgroup joins them.

### The authoring trap: `custom_meta_<field>` is a valid Divi name and a rejected DiviOps name

Divi's render callback accepts **two** name shapes for a custom field
(`DynamicContentOptionPostMetaKey.php:220-301`):

1. The shape its own comments call "simple format (preferred)": the token's `name` is
   `custom_meta_<meta_key>`, and Divi strips the `custom_meta_` prefix to get the meta
   key.
2. The shape its own comments call "legacy complex format": the token's `name` is
   `post_meta_key` and the field is carried in `settings.select_meta_key` (either
   `custom_meta_<meta_key>`, or `custom_meta_manual_custom_field_value` paired with
   `settings.meta_key`). A bare `settings.meta_key` with no `select_meta_key` is also
   still honored. <!-- UNVERIFIED -->

**Only shape 2 is authorable through DiviOps**, because shape 1's names are never
registered in the options registry — `custom_meta_*` keys exist only *inside*
`post_meta_key`'s `select_meta_key` dropdown, not as top-level registry entries
*(verified 2026-08-14: zero top-level keys start with `custom_meta`)*. Everything
downstream validates names against that registry:

```
diviops_dynamic_content_validate { name: "custom_meta_footnotes" }
→ ok:true, data.valid:false,
  errors:[{ code:"unknown_option",
            message:"'custom_meta_footnotes' is not a registered dynamic content option
                     (post_id: 0, context: edit)." }]
```
*(verified 2026-08-14 — `footnotes` is a real meta key on this site, listed inside the
`custom_meta_group_standard` subgroup, and it still fails as a top-level name.)*

`diviops_dynamic_content_build` refuses the same name outright — `ok:false`,
`invalid_input`, with the identical `unknown_option` entry under `error.data.errors`
*(verified 2026-08-14)* — and the write path refuses it too: `module_update`'s guard
(`trait-dynamic-content.php:417-448`, `dynamic_content_write_path_rejection()`) fails
open on almost everything, but a well-formed `type: "content"` token whose name is
confirmed absent from a non-empty registry and does not match the
`gcid-`/`gvid-`/`gfid-` global-variable namespace is exactly the one case it rejects,
with `invalid_input` naming the attr path. <!-- UNVERIFIED (source read; not exercised
as a live write) --> Net effect: a `custom_meta_price` token that Divi would render
fine cannot be written through `diviops_module_update`.

### The shape that works

Build the `post_meta_key` form and let `diviops_dynamic_content_build` emit the token —
it reproduces `Conversion::formatDynamicContent()` byte-for-byte, including the empty
settings `{}`-not-`[]` cast:

```
diviops_dynamic_content_build {
  name: "post_meta_key",
  settings: {
    select_meta_key: "custom_meta_manual_custom_field_value",
    meta_key: "price",
    before: "$",
    after: ""
  }
}
→ $variable({"type":"content","value":{"name":"post_meta_key","settings":{"select_meta_key":"custom_meta_manual_custom_field_value","meta_key":"price","before":"$","after":""}}})$
```
*(verified 2026-08-14 — built against the live registry on a site with no SCF; the
manual-input path validates without the field having to exist.)*

Then write that string to the module attr with `diviops_module_update`. Two variants of
the same form:

- **Manual entry** — `select_meta_key: "custom_meta_manual_custom_field_value"` plus
  `meta_key: "<the SCF field name>"`. This is the portable one: it validates on any
  site, including one where the field does not exist yet, and Divi's render path treats
  it as a manual entry that **bypasses** the `read_dynamic_content_custom_fields`
  permission check (`DynamicContentOptionPostMetaKey.php:303-317`). <!-- UNVERIFIED -->
- **Discovered field** — `select_meta_key: "custom_meta_<field>"` with no `meta_key`.
  Matches what the Visual Builder writes when a user picks from the dropdown, and is
  subject to that permission check in `edit` context. <!-- UNVERIFIED -->

Repeater sub-fields are the exception to all of the above: their
`loop_acf_<name>|||<sub>` keys *are* top-level registry entries, so they validate and
build directly by name once SCF is installed. <!-- UNVERIFIED -->

### Settings you can pass alongside

`post_meta_key` accepts `before`, `after`, `date_format`, `custom_date_format`, and
`enable_html` *(verified 2026-08-14 — read off the live registry)*. Note that
`enable_html` is registered **only** for callers holding `unfiltered_html`
(`DynamicContentOptionPostMetaKey.php:153-164`), and the ACF-derived options add it
under the same condition (`DynamicContentOptionACFGroups.php:134-145`). Because
`dynamic_content_build`/`_validate` check every settings key against the registered
schema, a caller without that capability gets `unknown_setting` for `enable_html` that
a stronger caller would not. <!-- UNVERIFIED -->

Date handling: Divi adds date-format fields for ACF types `date_picker`,
`date_time_picker`, and `time_picker` only
(`DynamicContentOptionACFGroups.php:127-131`, mirrored at
`DynamicContentOptionPostMetaKey.php` render time). <!-- UNVERIFIED -->

## 6. Failure modes you will actually hit

| # | Symptom | Cause | What to do |
|---|---|---|---|
| 1 | `scf.command_failed`, stderr `Error: 'scf' is not a registered wp command.` (exit 1) | SCF absent, or older than the 6.8.4 our own wrappers document as introducing the `wp scf json` family | Fall back to `diviops_scf_field_group_list` / `_get`, which use `wp post` and need no SCF CLI. *(verified 2026-08-14 on the reference site)* |
| 2 | `wp_error`, `wp-cli returned non-JSON output for --format=json: Unexpected token 'W'` | Anything PHP prints at startup lands on stdout **ahead of** the JSON, and `field_group_list` calls `JSON.parse` on the whole stream. On the reference site an imagick module-API mismatch warning does exactly this | Fix the PHP startup noise for the wp-cli binary (the repo's own `CLAUDE.md` invokes wp-cli with `-d display_errors=0` for this reason); or fall back to `diviops_meta_wp_cli`, which returns raw stdout for you to slice. *(verified 2026-08-14)* |
| 3 | `not_found` from `diviops_scf_field_group_get` for a key you know exists | Same stdout pollution as #2, but on the internal `post_name` lookup, whose `JSON.parse` sits in a bare `catch` that falls through to `not_found` | Confirm with `diviops_meta_wp_cli` before believing the group is missing. <!-- UNVERIFIED (source read: index.ts field-group-get lookup) --> |
| 4 | `scf.not_configured` on every SCF tool | Neither `WP_PATH` nor `WP_CLI_CMD` is set for the MCP server process | Setup-side fix; REST credentials do not help. <!-- UNVERIFIED --> |
| 5 | `scf.command_failed` with `error.data.failure_kind: "rejected"` | Pre-execution rejection: the command missed the allowlist, or a path resolved outside the safe root | The command never ran. Move the file under `<WP_PATH>/.diviops-tmp/`, or set `DIVIOPS_WP_CLI_SAFE_FS_ROOT`. In `WP_CLI_CMD` wrapper mode `DIVIOPS_WP_CLI_SAFE_FS_ROOT` is **required** for FS-touching commands, because a host-derived path has no meaning inside the container. <!-- UNVERIFIED --> |
| 6 | `invalid_input` from `diviops_scf_export` before anything runs | Neither `dir` nor `stdout`, or both | `error.data` names the fields. <!-- UNVERIFIED --> |
| 7 | Yesterday's export silently gone | Same-day export reuses `acf-export-YYYY-MM-DD.json` | Copy or rename the baseline right after writing it. <!-- UNVERIFIED --> |
| 8 | `unknown_option` when binding a custom field | Token `name` is `custom_meta_<field>` | Use the `post_meta_key` form in §5. *(verified 2026-08-14)* |
| 9 | The registry differs between two callers on the same site | Divi gates the custom-field dropdown on `et_pb_is_allowed('read_dynamic_content_custom_fields')` (unless `context: "display"`), gates user-meta discovery on `manage_users`/`list_users`, and registers `enable_html` only for `unfiltered_html` | Treat `dynamic_content_list` output as caller-scoped, not site-scoped; re-run it as the identity that will do the write. <!-- UNVERIFIED --> |
| 10 | A field group imports fine but no new dynamic-content option appears | Non-repeater fields never become top-level options — they live inside `post_meta_key`'s dropdown | Expected. Bind via `post_meta_key` (§5). <!-- UNVERIFIED --> |

Every `scf.command_failed` carries
`error.data = { exit_code, stdout, stderr, failure_kind, command }`, where
`failure_kind` is one of `exited` (wp-cli ran, non-zero), `killed` (timeout or signal;
partial streams present), `spawn_failed` (the OS refused to start wp-cli; streams
empty), or `rejected` (blocked before execution). `command` is the exact argv, which is
the fastest way to confirm what was actually attempted. <!-- UNVERIFIED (source:
`failScfCommand` in `index.ts`) -->

## See also

- [tools.md](tools.md) — the Divi-authoring tool subset, `diviops_meta_wp_cli`, and the
  namespace-specific error-code table.
- [../../diviops/SKILL.md](../../diviops/SKILL.md) — envelope, capability handshake,
  the standard `dry_run` plan shape that `diviops_scf_sync` deliberately does not use.
- [module-formats.md](module-formats.md) — where the `$variable({...})$` token from §5
  goes once you have it.
