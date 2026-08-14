# Cross-site page export/import with reference remapping (#96) — design spec

Date: 2026-08-14
Issue: [#96](https://github.com/rubicon/diviops/issues/96) (split from [#35](https://github.com/rubicon/diviops/issues/35))
Status: proposed — design only, no implementation in this change

## Problem

`page_duplicate` (#35, `trait-page.php`) copies a page on the **same** site. Every
reference inside its markup — attachment IDs, internal links, `gcid-`/`gvid-`/`gfid-`
global refs, module presets — is already valid in the target, because the target *is*
the source, so #35 ships with **no remapping** and says so explicitly
(`references_remapped: false` + `references_note`).

Moving a page **between** sites is the opposite case: every one of those references may
be wrong in the target, and wrongness is silent. A stale attachment ID renders the wrong
image. A stale `gcid-` renders the wrong brand color. A stale internal link 404s. Nothing
throws, no test fails, and the breakage surfaces visually on someone's live site later.
Issue #96 exists specifically to decide, before any code, what happens to each class of
reference.

## What already exists (read first, verified)

Two free routes already do a narrower version of half of this problem, for **Theme
Builder header/footer layouts only** (`trait-theme-builder.php`):

- `cross_env_source_export_get` — reads one TB layout, returns sanitized markup
  (`cross_env_sanitize_markup_for_export()` strips `/wp-admin/`, `wp-login.php`, and
  local filesystem paths), a sha256 checksum, and a best-effort attachment inventory
  scraped from the markup (`cross_env_source_attachments_from_markup()`).
- `cross_env_target_context_get` — runs **on the target site**, takes caller-supplied
  asset hints (URLs/paths/attachment IDs from the source export), and does live matching
  against the target's own media library by `_wp_attached_file` basename/path
  (`cross_env_attachment_candidates()`). A remap is only ever emitted when **exactly one**
  target candidate matches **exactly one** supplied source ID
  (`cross_env_attachment_remaps()`) — ambiguous matches are left unresolved, never
  guessed. It also reports global-color reconciliation by **digest, not raw value**
  (`cross_env_global_color_value_evidence()`: sha256 of the resolved color per `gcid-`),
  and a target module-preset ID inventory (existence only, not definitions).

Both routes are **read-only**. Neither writes anything. `_meta.write_apply_media_import`
and `_meta.global_color_import_create` are `false` on both. The **apply** side for TB
headers/footers — `diviops_cross_env_header_apply` / `diviops_cross_env_layout_apply` —
is Pro-only, and it is a genuinely heavy piece of machinery: a vendored TypeScript
preflight engine (`diviops-server/src/cross-env-preflight/`), a `confirmation_binding`
fingerprint the caller must echo back, destination-checksum-drift refusal, and a re-run
of the preflight server-side before the Pro route ever writes. This fork does not have
that source and is not attempting to reproduce it — `diviops-agent-pro` was not opened
while writing this spec.

Also verified: `grep -rl "cross_env" tests/` returns nothing. The two existing free
`cross_env_*` routes carry **zero** test coverage today — they predate this fork's
touch (not listed in `FORK.md`'s modified-files table for `trait-theme-builder.php`, so
they're inherited upstream code this fork hasn't had reason to change yet). Reusing
their helpers for #96 does not fix that gap; see Risks.

**The governing lesson from #35's own history:** the first `page_duplicate`
implementation parsed content with `parse_blocks_for_write()` and re-serialized with
`serialize_blocks()`, guarded by `update_post_content_with_integrity_guard()`. Verified
live against page 900390 (62KB, a real `divi/global-layout` wrapper), that round trip
was **lossy** (62,167 → 61,855 bytes) and **false-positived** on a `u003c`-shaped escape
Divi itself legitimately emits. It was corrected to a raw byte copy:
`post_content` goes into `wp_insert_post()` unparsed. Any design here that proposes
rewriting a whole page's block tree has to answer to that history directly, not restate
the naive approach.

## Goal / non-goals

**Goal:** decide, per reference class, whether it can be detected, whether it can be
confidently remapped, and what happens when it can't — then define the smallest slice
that is genuinely useful without writing anything.

**Non-goals (this spec, and mostly this issue):**
- Building the write/apply path. See Phasing — deliberately deferred to a follow-up
  issue, for the same reason #35 was split into #35 (safe, ship now) and #96 (hard,
  needs its own decision) in the first place.
- Reproducing or reading Pro's `cross_env_header_apply`/`cross_env_layout_apply` or its
  vendored preflight engine.
- Divi-version translation. See Transport format.
- Auto-creating missing global colors/fonts/variables/presets on the target site.

## Reference taxonomy and remapping strategy

| Class | Detectable? | Auto-remappable? | Strategy | When it can't be resolved |
| --- | --- | --- | --- | --- |
| **Attachment IDs / upload URLs** | Yes, best-effort — reuse `cross_env_attachment_ids_from_markup()` + `cross_env_upload_urls_from_markup()` | Yes, when exactly one target attachment matches by `_wp_attached_file` basename/path | 1) auto-match, reusing `cross_env_attachment_candidates()`/`cross_env_attachment_remaps()` unchanged; 2) caller-supplied `attachment_id_map` override/supplement; 3) opt-in re-upload from the source's public URL via the existing `media_upload` URL path (`reupload_missing_attachments: true`) | Reported unresolved |
| **Internal links** (absolute URLs on the source's own origin) | Yes — prefix-match against the source's `cross_env_site_origin()`, captured at export time | Only when a same-path post exists on the target (`url_to_postid()`/`get_page_by_path()`-style live lookup) | Match by path/slug on target; caller-supplied `internal_link_map` override | Reported unresolved |
| **Global colors/fonts/variables** (`gcid-`/`gvid-`/`gfid-` inside `$variable(...)$`) | Yes — token scan, same shape `trait-dynamic-content.php`'s write-path guard already uses to distinguish these from real dynamic-content bindings | By ID, three outcomes: same id + matching value digest = already correct; same id + **different** digest = conflict; id absent = missing | Reconcile by ID equality plus a digest comparison (extend `cross_env_global_color_value_evidence()`'s existing pattern to fonts/variables); caller-supplied `global_ref_id_map` override. Never auto-creates a missing token — the caller pre-creates it with the existing `global_color_create`/`global_font_create`/`variable_create` tools and supplies the map | Both conflict and missing are reported unresolved — a same-id-different-value match is exactly the silent-corruption case this issue exists to prevent, so it must never be treated as "already fine" |
| **D5 module presets** (`modulePreset` IDs) | Yes — reuse `cross_env_module_preset_ids_from_markup()` | By ID existence only (no value-digest exists for presets yet) | Existence check against the target's live `get_d5_presets()` registry | **Missing preset — degraded, not blocking, PENDING VERIFICATION.** Working assumption: `modulePreset` records which preset was last applied; the module's `attrs` already carry the literal values a preset would fill in, so a target that lacks the preset ID still renders correctly and only loses the "linked to a design-system preset" editability convenience in the Visual Builder. **This is inferred from this repo's own `preset_reassign`/orphan-scan behavior (presets can go missing from the registry while modules keep rendering), not confirmed against Divi's own preset-application source** — the implementing issue must verify this against Divi's `PresetAttrsMap`/apply-time source before treating a missing preset as non-blocking in code. If verification disproves it, this class moves to the same "unresolved, blocking" bucket as the others. |
| **Menu / term IDs** (Menu module's target menu; loop-module category/tag filters) | Best-effort only — module-schema-dependent (a `menu_id` key on the Menu module, filter arrays on loop modules), not a uniform token like the classes above | No — deferred entirely | Targeted per-module-type key lookups, report-only | **Always unresolved in Phase 1.** Any detected reference in this class blocks the import outright. A future phase can add name-based matching (menu name; term name + taxonomy), the same way attachments match by path today |
| **Dynamic-content bindings** (`$variable(name,{settings})$` naming a real `divi_module_dynamic_content_options` entry) | Yes — reuse `dynamic_content_get_registry()` | Option-name existence: validated live against the **target's own** registry, because the export/target-context call executes on the target site itself — this is a local lookup, not a two-site diff, unlike TB's offline preflight CLI. An embedded `post_id`-shaped setting value is itself an internal-link-class reference and routes through that class's remap | Registry-membership check is free; anything absent from the target's live registry, or an unresolved embedded internal-link value, is reported unresolved. Assumes SCF/ACF field-group sync (`scf_export`/`scf_import`) has already happened as a precondition — this feature does not sync field groups itself |

## Unresolved-reference policy (the non-negotiable)

The issue names three options: silent copy, explicit placeholder, or refuse the import.
**Default is refuse, per reference, not per import** — not "refuse the whole page,"
because a page with one unresolved link and nine resolved attachments shouldn't be
treated identically to a page where nothing resolved. The write path (Phase 2, not
built here) must refuse to write **any** reference class that is unresolved and lacks
an explicit caller override, while still allowing an import where the *only*
unresolved item is, say, a menu reference (always unresolved in Phase 1) to proceed
with that one flagged, IF the caller explicitly acknowledges it (`on_unresolved:
'refuse'` default, `'skip_and_report'` opt-in per class) — never a global "proceed and
hope" flag with no acknowledgment.

Explicit placeholder is deliberately **not** the default for anything except
attachments, and only as an opt-in: a broken-image placeholder is visually obvious and
safe. A "placeholder" link, color, or dynamic-content binding has no equivalent
safe-and-obvious failure mode — a wrong-but-plausible color is worse than a hard
refusal, which is exactly the silent-corruption case #96 exists to prevent. This is a
design decision for Dax to confirm or override before Phase 2 is implemented; it is not
load-bearing for Phase 1, which writes nothing.

## Transport format

JSON, matching every other DiviOps envelope — not a new binary or zip format. The
export payload:

```
{
  origin, object_kind, object_id, object_title, object_post_type,
  markup,                 // raw post_content, sanitized (cross_env_sanitize_markup_for_export),
                           // NOT re-encoded through parse_blocks/serialize_blocks
  checksum,                // sha256 of markup
  reference_inventory: {
    attachments: [...],
    internal_links: [...],
    global_refs: [...],    // id, kind (color/font/variable), value_digest
    module_preset_ids: [...],
    dynamic_content_bindings: [...],
    menu_term_refs: [...]  // best-effort, always flagged unresolved downstream
  },
  diviops_version, divi_version, exported_at
}
```

**Divi-version portability:** none is attempted. Divi owns its own block-format
compatibility (the Visual Builder has its own upgrade routines when it opens an old
page); DiviOps has no basis to judge that matrix and must not try. `divi_version` is
captured on both the export and target-context payloads purely for diagnostic
disclosure — a caller or a future Phase 2 apply MAY warn on mismatch, but must never
attempt translation.

**Large payloads:** `cross_env_source_export_get` already has precedent for this —
`diviops_cross_env_source_export_get`'s MCP tool wrapper supports a bounded
`source_payload_ref` server-local artifact handle for large exports so the model isn't
forced to inline full markup, backed by the server's existing
`.diviops-tmp/cross-env-source-payloads/` store. The new page export tool should reuse
that same mechanism rather than invent a second one — this is server-side (`diviops-
server`) wrapper behavior, not a WP REST route change; the REST route itself returns
markup inline exactly like the existing one does.

## Is block content rewritten? No — not in this design, and not ever via a full round trip

Export never parses. `markup` is the verbatim `post_content` string, run through the
same targeted, already-proven-safe substring redaction
`cross_env_sanitize_markup_for_export()` performs today (URL/path substitution, not a
block-tree rewrite). This mirrors #35's corrected byte-copy design exactly, for the same
reason: a full `parse_blocks_for_write()` → `serialize_blocks()` round trip has already
been shown lossy and prone to false positives on content Divi itself legitimately
emits.

For the future Phase 2 write path (design intent only — not built here): substitution
must be **surgical and string-level**, replacing only the exact matched span each
detector already reports (the export/target-context payload can carry offsets or exact
matched substrings, not just IDs), bounded using this fork's existing JSON-aware span
helpers (`block_opening_comment_end()`, the counted-block-identifier helpers in
`trait-core.php`) so a substitution can never straddle a JSON string boundary — the same
class of bug `block_opening_comment_end()` was built to fix (#6), and the same caution
the "regex JSON scanning is systematically risky" lesson (#97's four-round saga)
demands. The result must still go through `update_post_content_with_integrity_guard()`
with `$check_global_layout_drift = true` as a readback safety net, exactly like every
other parse-adjacent write in this codebase — but the guard is a **verifier** on
already-substituted raw content, never a **generator** that re-serializes a parsed tree.

## Architecture (for the Phase 1 implementation issue)

- New file `plugins/diviops-agent/includes/trait-cross-env.php`
  (`DiviOps_Agent_CrossEnv` trait), mixed into `DiviOps_Agent` alongside every other
  domain trait. The two existing TB-scoped `cross_env_*` handlers stay exactly where
  they are in `trait-theme-builder.php` — this is additive, not a refactor of code
  nobody asked to touch.
- Two new handlers: `cross_env_page_export_get()` and
  `cross_env_page_target_context_get()`. Both reuse the existing private
  `cross_env_*` helpers directly. **Verified, not assumed:** a `private static`
  method defined in one trait is callable from a method defined in a sibling trait once
  both are mixed into the same class — confirmed with a standalone PHP repro
  (two traits, one calling the other's `private static` method via `self::`, both used
  by one class) before writing this into the design. No visibility changes needed on
  the existing `trait-theme-builder.php` helpers.
- Read gate matches `page_get_layout`/`page_duplicate`: `can_inspect_post_object()`
  on the source post for export; `current_user_can('edit_post', $destination_id)` on
  the target for target-context, matching `cross_env_target_context_get`'s existing
  pattern.
- Naming: `cross_env_page_*`, not `page_export_get`/`page_target_context_get` under
  the plain `page` domain. This is deliberate — it signals both that this is
  read-only/preflight in nature, like the two existing `cross_env_*` routes, and that
  it composes with (rather than duplicates) that surface. Naming it as a `page_*`
  operation would imply parity with `page_duplicate`'s completeness, which Phase 1
  explicitly is not.
- REST + MCP tool naming: `POST /diviops/v1/cross-env/page-export` /
  `GET /diviops/v1/cross-env/page-target-context` (read-only despite one being a
  computation over a caller-supplied inventory; follow the existing route's verb
  choice at implementation time), `diviops_cross_env_page_export_get` /
  `diviops_cross_env_page_target_context_get` MCP tools, new `cross_env_page_export_get`
  / `cross_env_page_target_context_get` capability keys in the handshake `CAPABILITIES`
  array.

## Phasing

**Phase 1 (this issue, #96) — read-only export + full remap plan. No write path.**
`cross_env_page_export_get` + `cross_env_page_target_context_get`, covering every
reference class in the taxonomy above at the detection/matching level, returning a
complete disposition (resolved-with-proof / conflict / missing / unresolved) for every
reference found. This alone satisfies #96's acceptance criteria for "a written design
decision" and "dry-run remap plan showing every reference and its disposition" — a
remap plan is exactly what these two routes produce, without needing a write path to
exist yet. It is independently useful: a caller (human, script, or a future consumer)
can see precisely what would break before anything is written, the same value the
already-shipped TB preflight routes provide today.

**Phase 2 (new, separate issue — do not fold into #96) — constrained write path.**
Only after Phase 1 ships and its matching logic has been exercised against real
cross-site data. Scope it narrower than "general apply": start with the two classes
that already have exact-match logic proven in production (attachments, global refs by
digest), require **every** detected reference to be resolved or explicitly overridden
before any write happens, and use the surgical string-substitution approach above, not
a block-tree rewrite. Naming: a distinct `page_import`-style capability, not
`cross_env_page_apply` — keeping the write surface out of the `cross_env_` namespace
that Pro's TB apply tools currently occupy avoids any future name collision with
Pro-side additions to that surface, and is honest that it's new write behavior, not a
preflight tool.

**Explicitly deferred past Phase 2, no issue filed yet:** menu/term name-based
matching; auto-creating missing global tokens/presets on the target; a preset
value-digest comparison analogous to the color one.

## Risks

- **Preset degrade-vs-block assumption is unverified** (see taxonomy table). This is
  the single highest-impact unknown in the whole design — if wrong, a "non-blocking"
  missing preset would in fact be a silent-corruption case exactly like a bad
  attachment ID. Must be confirmed against Divi's own preset-application source before
  Phase 1 code ships, not inferred from this repo's own orphan-scan behavior alone.
- **Reused helpers carry no test coverage today.** `cross_env_attachment_candidates()`,
  `cross_env_sanitize_markup_for_export()`, and friends are inherited upstream code
  this fork has never had to touch, so `grep -rl "cross_env" tests/` is empty. Building
  on them does not retroactively cover them; the Phase 1 implementation issue should
  decide explicitly whether to backfill coverage for the reused helpers as part of
  adopting them, consistent with "anything this fork touches needs coverage written
  here."
- **Basename/path attachment matching has a known false-positive shape** (two
  differently-sourced images that happen to share a filename) — this is an accepted
  residual risk already live in production via the existing TB routes, not a new one
  introduced here.
- **Digest-based conflict detection exists only for global colors today**; fonts and
  variables need the same `cross_env_global_color_value_evidence()` pattern extended,
  not reinvented, and presets have no digest mechanism at all (see the preset risk
  above).
- **Menu/term detection is acknowledged best-effort and incomplete.** A reference this
  fork's targeted per-module-type scan doesn't recognize would pass through
  **undetected**, not merely unresolved — this is a real gap, not fully closed by
  "always unresolved when detected." The Phase 1 response `_meta` must say so plainly
  (e.g. `menu_term_detection: "best_effort, not exhaustive"`) rather than implying full
  coverage.
- **Large-page payload size** — mitigated by reusing the existing artifact-ref pattern,
  but the WP REST route itself still has to hold the full sanitized markup in memory
  and in the HTTP response; no different from the existing TB route's own exposure.
- **Staleness between export and any future apply.** Phase 1 returns a `checksum` so a
  future Phase 2 (or a human) can detect that the source changed after export — the
  same checksum-drift defense Pro's TB apply engine already has, offered here only as
  a value in the response, not enforced, since Phase 1 does not write.

## Deliberately out of scope

- Any write/apply capability (Phase 2, separate issue, not this one).
- Auto-creating missing global colors/fonts/variables/presets on the target.
- Divi-version translation or migration.
- Reproducing, reading, or deriving from Pro's `cross_env_header_apply` /
  `cross_env_layout_apply` or the vendored `cross-env-preflight` engine.
- `diviops-agent-pro` was not opened at any point while researching or writing this
  spec, and no codebase-memory project pointing at it was queried either.
- TB's two-site offline-diff CLI workflow (`diviops-cross-env-preflight`). Pages don't
  need it: `cross_env_page_target_context_get` runs live on the target given the
  export payload directly, so there's no separate client-side fingerprint-binding
  ceremony to replicate.
- Non-Divi (classic/Gutenberg) source content. A non-Divi source page's
  `reference_inventory` is simply empty under this taxonomy (nothing in it is
  Divi-attrs-shaped), so it needs no special-casing here — `page_duplicate`'s existing
  `source_uses_divi` flag already reports which kind of content was copied for the
  same-site case, and Phase 1's export payload should carry the same flag for
  consistency rather than inventing new logic.
- SCF/ACF field-group sync. `scf_export`/`scf_import` already own that; dynamic-content
  binding detection treats a synced target registry as a precondition, not something
  this feature performs.

## Testing (for the Phase 1 implementation issue, not this spec)

Plain-PHP harness (`php tests/run.php`), new `tests/test-cross-env-page-export.php`
and `tests/test-cross-env-page-target-context.php`, following `tests/wp-shim.php`'s
existing conventions. New shim coverage needed: `url_to_postid()`/`get_page_by_path()`,
the `divi_module_dynamic_content_options` filter (already partially shimmed for
`trait-dynamic-content.php`'s own tests — reuse, don't duplicate), and a preset
registry fixture via the existing `get_d5_presets()` shim if one exists, or a new one
mirroring it. Coverage must include: every taxonomy class's detect-and-match happy
path, every unresolved/conflict path, the digest-conflict case specifically (same ID,
different value — the case this whole design exists to catch), and a mutation check
that removing the digest comparison makes that case pass incorrectly (matching this
fork's established "prove the guard actually guards" testing discipline).

## Open questions for review

1. Confirm the preset degrade-vs-block assumption before Phase 1 code starts (see
   Risks) — this may change that row from non-blocking to blocking.
2. Confirm the per-reference (not per-import) unresolved policy, and whether the
   opt-in attachment-placeholder degrade mode is wanted at all for Phase 2, or should
   be dropped in favor of refuse-only everywhere.
3. Confirm Phase 2 should be a new issue rather than folded back into #96 — this spec
   assumes yes, mirroring the #35 → #96 split precedent.
