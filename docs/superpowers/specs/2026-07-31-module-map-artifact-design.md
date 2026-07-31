# Design: cached module-map artifact (all namespaces)

Date: 2026-07-31
Status: approved (shape); pending spec review
Related: supersedes the ad-hoc "rebuild regen-module-formats.mjs" and "re-derive Tier 3
from PresetAttrsMap" efforts; builds on #120, #63, #62, #42; part of the #50 strand.

## Problem

Authoring against a design comp requires knowing what attributes a module actually
supports. Today that knowledge is either absent or expensive:

- **`difl/*` (108 modules) have no documented element maps at all.** `module-formats.md`'s
  generated index covers `divi/*` only.
- **Discovering them costs a live round-trip per module**, and only works when a live
  Divi site is reachable. Until #120 shipped it did not work for `difl/*` at all.
- **What documentation exists is stale and unenforceably so.** `module-formats.md`'s
  index still says "Generated against Divi `5.8.0`" while the reference site runs
  `5.9.0`, and nothing detects that.
- The generator that index credits (`regen-module-formats.mjs`) **does not exist in the
  repo** — it was never committed.

## Goal

One committed, queryable artifact covering **all 194 modules across every namespace**,
generated once, refreshed deliberately, that removes per-module live introspection from
the authoring loop.

## Non-goals

- Replacing hand-written prose. The artifact is an *index*; `module-formats.md`'s
  Exceptions table and `advanced-attributes.md` remain canonical for *surprises* and
  win on conflict, per the existing convention.
- Auto-regeneration. Explicitly rejected — see Staleness.
- Documenting the Interactions system (that is #64, already specced and in flight).

## Sources

All primary Divi/DiviFlash source; `diviops-agent-pro` is never read. Verified present
on the reference site (Divi 5.9.0, DiviFlash installed):

| # | Source | Yields | Covers |
|---|---|---|---|
| 1 | `diviops_schema_get_module` `mode=dump_all` | Per-module element structure, decoration groups, `innerContent`/`advanced` presence, plus `schema_version` + `divi_version` | **all 194** |
| 2 | Divi core `server/Packages/Module/Options/<Group>/<Group>PresetAttrsMap.php` (**46 files**) | Shared decoration-family subfield vocabulary | **universal**, incl. `difl/*` |
| 3 | Divi core `server/Packages/ModuleLibrary/<Module>/<Module>PresetAttrsMap.php` (**65 files**) | Module-specific leaf paths | **65 of the 85 `divi/*` modules** |

Divi core ships 111 `PresetAttrsMap` files total; the split is 46 shared-family + 65
module-specific, and conflating the two overstates module-specific coverage by ~70%.

### Why composition is the primary mechanism, not a fallback

Coverage of source 3, counted rather than assumed:

| Modules | Module-specific leaf paths? |
|---|---|
| 65 `divi/*` | Yes — source 3 |
| **20 `divi/*`** | **No** — fewer maps (65) than modules (85) |
| 108 `difl/*` | No |
| 1 `d5bgo/*` | No |

So **129 of 194 modules — including 20 native `divi/*` ones — have no module-specific
source at all.** Composition is how the majority of the artifact gets any depth; it is not
a DiviFlash-shaped workaround.

DiviFlash ships **no per-module `PresetAttrsMap`** — one global
`Builder/Server/PresetAttrsMapGlobal.php` — so source 3 cannot cover it. Its per-module
data is `module.json` only (112 files), which is what source 1 already surfaces.

But DiviFlash modules use **Divi's own decoration system**, and those families are defined
once in Divi core (source 2). So `difl/flipbox`'s `module.decoration.boxShadow` resolves to
the same 7 subfields as any Divi module. Composing source 1 (which elements exist, which
families each supports) with source 2 (what each family's subfields are) recovers most
leaf-level depth **without the module's own plugin shipping that source**, and means one
vocabulary update propagates everywhere rather than being copied 129 times.

Source 3 then adds genuine module-specific depth for the 65 that have it.

### Naming divergence worth capturing

DiviFlash element names follow a different convention than Divi's: `before_text_obj_settings`,
`after_image_obj_settings`, `cm_obj_settings` (snake_case, `_obj_settings` suffix) versus
Divi's camelCase `closedTitle` / `openToggleIcon`. An author assuming Divi's conventions
on a DiviFlash module guesses wrong every time — capturing real element names per module
is a substantial part of this artifact's value.

## Artifact

A single committed JSON file. Shape:

```
{
  "meta": {
    "schema_version": "<hash from dump_all, over Divi's PresetAttrsMap files>",
    "divi_version": "5.9.0",
    "generated_at": "<ISO-8601>",
    "source_counts": { "modules": 194, "families": 46, "module_preset_maps": 65 }
  },
  "families": {
    "<familyName>": { "subfields": ["…"], "source": "<file path>" }
  },
  "modules": {
    "<namespace/slug>": {
      "title": "…",
      "category": "…",
      "elements": {
        "<elementName>": {
          "decoration": ["<familyName>", …],
          "innerContent": true|false,
          "advanced": true|false
        }
      },
      "leafPaths": ["…"]          // present only where source 3 covers it
    }
  }
}
```

`leafPaths` being **absent** rather than empty is the signal that no module-specific
source existed — distinguishable from "a source existed and declared nothing," which is a
real and different state (see `advanced-attributes.md`'s note on Blurb declaring none of
the shared families in its own preset map while still supporting them).

**Consumption:** read directly from disk. No live site, works offline and against any
environment. Frequently-used modules additionally get generated skill-file prose so the
common path needs no lookup at all; the long tail stays out of context.

## Staleness

Split by what is actually checkable, which departs from the initial "warn at use, fail in
CI" framing because **CI cannot reach a live Divi install** — GitHub Actions has no route
to the local reference site, so no CI job can compare the artifact against live Divi.

| Layer | Detects | Behavior |
|---|---|---|
| **At use** | Real drift — artifact `schema_version`/`divi_version` vs. the live site | Warn loudly, then **fall back to live introspection for that module**. A stale cache degrades to today's behavior; it never silently serves wrong paths. |
| **In CI** | Artifact *integrity* — parses, covers every module it claims, regenerating from a committed fixture is byte-identical | Fail the build. Catches a corrupt or hand-edited artifact. **Cannot** detect that Divi shipped 5.9.1. |
| **Locally, on demand** | Real drift, deliberately | `npm run check:schema-drift` — the thing to run after any Divi or DiviFlash update. |

Never auto-writes. Regeneration is always an explicit human step producing a reviewable
diff.

The CI fixture matters beyond integrity: it makes the generator testable without a live
site, which is the same gap that let `schema-route.ts` ship untested until #120.

## What this absorbs

| Effort | Disposition |
|---|---|
| Rebuild `regen-module-formats.mjs` | Becomes this artifact's generator, scoped to all namespaces rather than `divi/*` |
| Re-derive Tier 3 depth from `PresetAttrsMap.php` | Becomes source 3 |

Both were separately-spawned tasks; their sessions have ended and their scope lives here.
Building them independently would have put three things writing to `module-formats.md` and
produced a generator built on the `divi/*`-only assumption that #120 just disproved.

## Risks

- **Generator drift from Divi's internals.** Sources 2 and 3 are read by parsing PHP.
  Mitigation: parse only the `get_map()` return shape already proven stable by
  `scripts/extract-decoration-paths.php` (#62), and fail loudly on an unrecognized shape
  rather than silently emitting fewer paths.
- **Artifact size.** 194 modules of structure is large but far smaller than the 3.4 MB raw
  dump, since per-element decoration groups collapse to family *names* with the vocabulary
  stored once under `families`. If it still proves unwieldy, split per namespace — deferred
  until measured, not designed around speculatively.
- **Fidelity asymmetry is real, larger than it looks, and must stay visible.** 129 of 194
  modules get composed depth rather than module-specific depth — and that set includes 20
  native `divi/*` modules, so it cannot be described as "the third-party ones." The
  `leafPaths`-absent signal encodes it per module; skill prose must not imply uniform
  depth, and must not imply the split follows namespace.
- **A silent regression here is expensive** — wrong paths are worse than no paths, because
  writes report success. Hence integrity checks in CI and fallback-on-drift at use.

## Test plan

- Generator unit tests against committed fixtures (no live site), covering all four
  coverage states: a `divi/*` module **with** module-specific leaf paths, a `divi/*` module
  **without** (one of the 20 — this is the case a namespace-based assumption would get
  wrong), a `difl/*` module, and the shared-family composition path itself.
- Byte-identical regeneration from fixture — the CI integrity gate.
- A spot-check of generated entries against live introspection for at least one module per
  namespace (`divi/`, `difl/`, `d5bgo/`), which is the only way to prove composition is
  right rather than merely self-consistent.
- Existing suites stay green (`php tests/run.php`, `npm run test:server-security`).
