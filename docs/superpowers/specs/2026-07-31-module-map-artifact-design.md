# Design: cached module-map artifact (all namespaces)

Date: 2026-07-31
Status: revised after adversarial review; pending spec review
Related: supersedes the ad-hoc "rebuild regen-module-formats.mjs" and "re-derive Tier 3
from PresetAttrsMap" efforts; builds on #120, #63, #62, #42; part of the #50 strand.

## Problem

Authoring against a design comp requires knowing what attributes a module actually
supports. Today that knowledge is either absent or expensive:

- **`difl/*` (108 modules) have no documented element maps at all.** `module-formats.md`'s
  generated index covers `divi/*` only.
- **Discovering them costs a live round-trip per module**, and only works when a live Divi
  site is reachable. Until #120 shipped it did not work for `difl/*` at all.
- **What documentation exists is stale, unenforceably so.** `module-formats.md`'s index
  still says "Generated against Divi `5.8.0`" while the reference site runs `5.9.0`.
- The generator that index credits (`regen-module-formats.mjs`) **does not exist**.

## Goal

One committed, queryable artifact covering **every module the live site actually
registers**, generated once, refreshed deliberately, removing per-module live
introspection from the authoring loop.

## Non-goals

- Replacing hand-written prose. The artifact is an *index*; `module-formats.md`'s
  Exceptions table and `advanced-attributes.md` stay canonical for *surprises* and win on
  conflict.
- Auto-regeneration. Explicitly rejected — see Staleness.
- Documenting Interactions (that is #64, separately specced).
- Being a substitute for reading back after a write. A static map cannot tell you what
  active presets have already set — see Risks.

## Counting rule (single source of truth)

**A "module" is an entry in live `dump_all` output.** Nothing else. On-disk file counts do
*not* agree with the registry and must never be mixed into the same number:

| Source | Count | Why it differs |
|---|---|---|
| **Live `dump_all`** | **194** (85 `divi/`, 108 `difl/`, 1 `d5bgo/`) | **The definition.** What is actually registered and addressable. |
| Divi native component dirs | 83 | The fork's own schema helper scans only `module-library/src/components`; the broader tree has nested WooCommerce/shortcode JSON too |
| DiviFlash `module.json` files | 112 | Two files declare the same block name — see below |
| DiviFlash unique declared names | 111 | |

Verified live 2026-07-31 against the reference site (Divi 5.9.0). Earlier drafts mixed
file counts with registry counts; the adversarial review was right to reject any number
not tied to one source.

## Sources

All primary Divi/DiviFlash source; `diviops-agent-pro` is never read.

| # | Source | Yields | Covers |
|---|---|---|---|
| 1 | `diviops_schema_get_module` `mode=dump_all` | Per-module element structure, decoration groups, component props, `innerContent`/`advanced` presence | **all 194** |
| 2 | Divi core `Module/Options/<Group>/<Group>PresetAttrsMap.php` (**46 files**) | Shared decoration-family subfield vocabulary | **universal**, incl. `difl/*` |
| 3 | Divi core `ModuleLibrary/<Module>/<Module>PresetAttrsMap.php` (**65 files**) | Module-specific leaf paths | **65 of 85 `divi/*`** |
| 4 | DiviFlash `Builder/Server/modules-json/*/module.json` (112 files) | DiviFlash element names, component props, selectors | 108 `difl/*` |
| 5 | DiviFlash `Builder/Server/PresetAttrsMapGlobal.php` | How DiviFlash composes preset attrs from Divi groups | `difl/*` |
| 6 | `df_active_modules` option | Which DiviFlash modules are actually registered | `difl/*` |

Divi core ships 111 `PresetAttrsMap` files total: 46 shared-family + 65 module-specific.
Conflating them overstates module-specific coverage by ~70%.

### Composition: the primary mechanism, and its real limits

Coverage of source 3, counted against the registry:

| Modules | Module-specific leaf paths? |
|---|---|
| 65 `divi/*` | Yes |
| **20 `divi/*`** | **No** — 65 maps for 85 modules |
| 108 `difl/*` | No |
| 1 `d5bgo/*` | No |

**129 of 194 modules — including 20 native `divi/*` ones — have no module-specific
source.** Composition is how most of the artifact gets depth; it is not a DiviFlash
workaround, and the split does not follow namespace.

Composition is **valid but not sufficient**, and the earlier draft overclaimed it.
Confirmed working: DiviFlash modules genuinely declare Divi's standard decoration groups
(`flip-box/module.json` declares `background`, `boxShadow`, `sizing`, `spacing`, `border`,
`transition`, `zIndex` on its module element), reference Divi's own components by name
(`"name": "divi/box-shadow"`), and render through Divi's shared style pipeline
(`Traits/StyleUtil.php` loops every `decoration` attr into `$elements->style()` →
`Style::add()`).

But the shared vocabulary alone **overstates what is settable**:

- **Component props narrow families.** `AdvancedButton/module.json` declares
  `module.decoration.sizing` with `allowedUnits: ["%"]` and marks `size`/`alignment`
  `render: false`. A flat subfield list would advertise paths that do nothing.
- **DiviFlash adds handling outside the generic pipeline.** `Utils/functions.php` has
  `difl_attr_def_has_hide_panels_background()` and
  `difl_process_bg_like4_declarations()` emitting supplemental background declarations.
  Standard family vocabulary does not fully describe background behavior.
- **Raw keys need resolution, not string-matching.** Some DiviFlash JSON uses `z_index`
  where Divi core has `Options/ZIndex`. *(Observed in `conversion-outline.json` — D4→D5
  conversion mapping — rather than `module.json`, so it may not reach a generator reading
  only `module.json` + `dump_all`. Resolving through `component.name`/`groupName` rather
  than raw key is cheap insurance regardless.)*
- **`PresetAttrsMapGlobal.php` skips the `module` attr entirely**, so it supports
  composition for element groups but does not confirm `module.decoration.*` preset
  coverage.

**Therefore the artifact stores component props, `render` flags, and selectors — not just
paths.** Anything less produces an index that is confidently wrong.

### Naming divergence

DiviFlash element names use a different convention: `before_text_obj_settings`,
`cm_obj_settings` (snake_case, `_obj_settings`) versus Divi's camelCase `closedTitle` /
`openToggleIcon`. Capturing real element names per module is a large part of the value.

## Artifact

```
{
  "meta": {
    "generated_at": "<ISO-8601>",
    "registry_module_count": 194,
    "sources": {
      "divi": { "version": "5.9.0", "packages_hash": "<sha1>" },
      "diviflash": {
        "plugin_version": "<from plugin header>",
        "modules_json_hash": "<sha1 over path+contents of all module.json>",
        "preset_attrs_global_hash": "<sha1>",
        "active_modules_hash": "<sha1 over df_active_modules>"
      },
      "d5bgo": { "plugin_version": "…", "source_hash": "…" }
    }
  },
  "families": { "<familyName>": { "subfields": [...], "source": "<path>" } },
  "modules": {
    "<namespace/slug>": {
      "title": "…", "category": "…",
      "sourceFile": "<path>",              // disambiguates duplicate declared names
      "active": true,                       // difl/*: from df_active_modules
      "coverage": {
        "modulePresetMap": "present" | "present_empty" | "missing",
        "depth": "module_specific" | "composed"
      },
      "elements": {
        "<elementName>": {
          "decoration": {
            "<familyName>": {
              "props": { "allowedUnits": ["%"], ... },   // when present
              "render": true|false,
              "selector": "…"                             // when declared
            }
          },
          "innerContent": true|false,
          "advanced": true|false
        }
      },
      "leafPaths": [ ... ]                  // only where source 3 covers it
    }
  }
}
```

Two changes forced by review:

- **Explicit `coverage` object** replaces the earlier absent-vs-empty `leafPaths`
  convention. That distinction was real (`advanced-attributes.md` documents Blurb
  declaring none of the shared families in its own preset map while still supporting them)
  but far too subtle to survive a consumer. `missing` vs `present_empty` now says it
  outright.
- **Per-family props/render/selector**, not bare subfield lists — see composition limits.

**Consumption:** read directly from disk; no live site, works offline and against any
environment. Frequently-used modules additionally get generated skill prose so the common
path needs no lookup.

Whether the generator should *precompute* effective settable paths per element (rather
than making the consumer join `elements[*].decoration` against `families`) is deferred to
implementation, to be decided by measuring a real lookup. Flagged because if consumers end
up loading the whole artifact to answer one question, the design has failed its purpose.

## Staleness

The single `schema_version` in the earlier draft **does not work**, and the adversarial
review was right to call it a hole. Verified in
`plugins/diviops-agent/includes/trait-module-schema.php`:
`schema_preset_attrs_map_hash()` roots at
`get_theme_file_path('includes/builder-5/server/Packages')` — the Divi **theme** only.
DiviFlash lives in `wp-content/plugins/diviflash/`, entirely outside that root.

**A DiviFlash update therefore cannot change `schema_version`** — the artifact would go
silently stale for exactly the 108 modules it exists to cover. Hence the per-source stamps
in `meta.sources` above.

*(That method's own docblock compounds the confusion: it claims the hash covers
`Packages/ModuleLibrary/` while the code walks all of `Packages`. Filed separately — it is
what misled this design in the first place.)*

| Layer | Detects | Behavior |
|---|---|---|
| **At use** | Real drift, per source stamp | Warn loudly, **fall back to live introspection for that module**. Stale cache degrades to today's behavior; never silently serves wrong paths. |
| **In CI** | Artifact *integrity* — parses, covers every module it claims, regenerates byte-identically from a committed fixture | Fail the build. **Cannot** detect that Divi or DiviFlash shipped an update. |
| **Locally, on demand** | Real drift, deliberately | `npm run check:schema-drift`; run after any Divi or DiviFlash update. |

Never auto-writes. Regeneration is always explicit and produces a reviewable diff.

Fallback-to-live is a *warning* path, not a fix: it gives a correct answer once while the
committed artifact stays stale for offline and future runs. The warning must be loud and
name the drifted source.

The CI fixture also makes the generator testable without a live site — the same gap that
let `schema-route.ts` ship untested until #120.

## Delivery phases

Review flagged the combined scope as too broad for one PR. Three phases, each shippable:

1. **Generator + provenance model.** Source readers, composition, multi-source stamps,
   fixture-based tests. No committed artifact yet.
2. **Artifact + CI.** Commit the generated artifact, wire the integrity gate and
   `check:schema-drift`.
3. **Skill prose.** Generated pages for frequently-used modules; reconcile
   `module-formats.md`'s existing index.

## What this absorbs

`regen-module-formats.mjs` becomes phase 1's generator (all namespaces, not `divi/*`);
the PresetAttrsMap depth work becomes source 3. Both were separately-spawned tasks whose
sessions have ended.

## Risks

- **Duplicate declared block names.** `advanced-video/module.json` and
  `AdvancedVideo/module.json` both declare `difl/advanced-video` (112 files, 111 unique
  names — verified). A generator keyed on block name silently drops one; keyed on path,
  duplicates one module. Hence `sourceFile` in the artifact, and an explicit test.
- **Inactive modules.** DiviFlash registers only modules present in `df_active_modules`.
  An artifact built from all 112 JSON files would advertise modules the live site does not
  register — hence source 6 and the `active` flag.
- **Static maps cannot report resolved state.** Divi merges default attrs, preset render
  attrs, group render attrs, and block attrs before rendering. The artifact says what is
  *settable*, never what is *currently set*. It does not remove the need to read back
  after a write — writes report success on paths that never render.
- **`render: false` is load-bearing and three-valued.** "Settable", "rendered", and
  "preset-saved" are different states; `PresetAttrsMapGlobal.php` exists specifically to
  include render-false fields in presets. Collapsing them produces a misleading index.
- **Selectors matter for comp work.** `AdvancedButton` maps background to
  `.difl_advanced_button_container`. For matching a comp, the target selector is as
  relevant as the attr path.
- **Generator drift from Divi internals.** Sources 2/3 parse PHP. Parse only the
  `get_map()` shape already proven stable by `scripts/extract-decoration-paths.php` (#62),
  and fail loudly on an unrecognized shape rather than silently emitting fewer paths.
- **Artifact size is unmeasured.** DiviFlash raw module JSON alone is ~6.4 MB. Collapsing
  repeated vocabulary should shrink it substantially, but that is a prediction — size
  becomes a measured acceptance criterion in phase 2, not a design assumption.
- **`d5bgo/*` is under-researched.** One module, included for completeness, but it has not
  had the source-stamp and metadata treatment DiviFlash received. Phase 1 must either
  cover it properly or drop it explicitly.

## Test plan

Generator tests run against committed fixtures, no live site:

- A `divi/*` module **with** module-specific leaf paths.
- A `divi/*` module **without** — one of the 20. This is the case a namespace-based
  assumption gets wrong.
- A `difl/*` module (composed depth).
- The shared-family composition path itself.
- **Duplicate declared name** (`difl/advanced-video`) — both entries survive, distinctly.
- **Inactive DiviFlash module** — excluded or flagged, not silently present.
- **`render: false`** and **component props** (`allowedUnits`) preserved, not flattened.
- Each source stamp changes when and only when its own source changes — specifically, a
  DiviFlash-only change must move `diviflash.*` and leave `divi.packages_hash` alone.

Plus: byte-identical regeneration from fixture (the CI gate); a live spot-check of at
least one module per namespace, which is the only way to prove composition is *right*
rather than merely self-consistent; and existing suites green (`php tests/run.php`,
`npm run test:server-security`).

## Adversarial review record

A Codex review of the first draft (2026-07-31) produced the changes above. Each was
re-verified against source here before acceptance:

1. **`schema_version` cannot detect DiviFlash drift** — confirmed; the hash roots at the
   Divi theme directory. This gutted the original staleness design for 108 of 194 modules.
2. **Composition overclaimed** — confirmed partial. Valid for standard groups, but props,
   `render:false`, `hidePanels`, and selectors are load-bearing.
3. **Counts mixed sources** — confirmed; now defined solely against live `dump_all`.
4. **Duplicate `difl/advanced-video`** — confirmed (112 files, 111 unique names).
5. **`df_active_modules` gating** — confirmed as a real coverage hazard.
6. **`leafPaths` absent-vs-empty too subtle** — accepted; replaced with `coverage`.
7. **Scope too broad for one PR** — accepted; three phases.

One claim was *partially* overstated and is recorded as such rather than adopted whole:
the `z_index` raw-key mismatch appears in `conversion-outline.json`, not `module.json`, so
it may never reach the generator. The defensive recommendation (resolve via
`component.name`, not raw key) is adopted anyway; the specific example is not evidence of
a live break.
