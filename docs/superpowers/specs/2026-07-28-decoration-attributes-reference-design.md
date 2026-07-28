# Clean-room decoration-attributes skill reference (#62) — design spec

Date: 2026-07-28
Issue: [#62](https://github.com/rubicon/diviops/issues/62) (part of epic [#50](https://github.com/rubicon/diviops/issues/50))
Status: design — pending owner review

## Problem

For the fork's mission — let an agent author/overhaul any Divi 5 site with full
design fidelity — the write path is already complete (free-form dot-path merge).
The gap is **knowledge**: the agent must know the canonical attribute dot-paths.
Divi Pro sells this as tiered skill knowledge; the free `divi-5-builder` skill
covers Tier 1 only (`module-formats.md` notes "Tier 2 patterns + Tier 3
per-module are Pro"). #62 builds the highest-leverage slice of that missing
knowledge, clean-room.

## Scope

A single new reference documenting the **shared decoration system** —
the `module.decoration.*` attribute families that apply to **every** module
(hence highest leverage):

- `boxShadow` — box shadows (incl. responsive/hover/inset)
- `filters` — CSS filters + blend mode
- `transform` — translate/scale/rotate/skew/origin (incl. responsive/hover)
- `sticky` — sticky positioning (position, offsets, limits, z-index)
- `transition` — transition duration/delay/curve
- `scroll` — scroll-based effects (vertical/horizontal/fade/scale/rotate/blur)
- `animation` — entrance animations (style, direction, duration, delay, intensity)

Explicitly **out of scope** for #62 (later slices / issues): per-module element
maps (#63), `$variable()` binding + Interactions (#64), deeper SCF (#65), and the
CSS-class / WebGL material already covered by `design-effects.md`.

## Non-negotiable: clean-room IP boundary

Every path is derived from **primary sources only**:
- Divi's own on-disk `module.json` decoration schema, read via the #42
  introspection tooling on our own site.
- Divi's official public documentation.
- Our own Visual Builder round-trips on our own site.

**Never** read, copy, paraphrase, or derive from Pro (`diviops-agent-pro`),
even though it is installed. Provenance is cited per section so the clean-room
origin is auditable.

## Methodology — hybrid (owner-approved 2026-07-28)

The skill already defines a verification-tier convention (`SKILL.md`); #62
follows it exactly:

| Marker | Meaning |
|---|---|
| `*(VB-verified DATE)*` | Saved in the Visual Builder on our site; observed in the registry/markup as written. Canonical. |
| `*(verified DATE)*` | Schema-extracted from real `module.json` (and/or renders), but VB round-trip not exercised. |
| `<!-- UNVERIFIED -->` | Neither. Must be marked; not shipped as authoritative. |

Hybrid plan:
1. **Schema backbone** — extract the full decoration attribute set from the real
   `module.json` decoration schema on our live Divi install (via the #42
   `schema_*` introspection), tag each path `*(verified 2026-07-28)*` with its
   provenance (source path + Divi doc reference). Covers all seven families.
2. **VB-verify the top four** — round-trip `boxShadow`, `transform`, `transition`,
   and `sticky` through our own Visual Builder, observe the saved shape, and
   promote those to `*(VB-verified DATE)*` gold.
3. The comprehensive "VB-verify everything" pass is tracked separately as
   [#82](https://github.com/rubicon/diviops/issues/82) (scheduled after
   Wed 2026-07-29 9 AM CT).

## Deliverable shape

`skills/divi-5-builder/references/advanced-attributes.md`, structured per family:

- The canonical dot-path(s) under `module.decoration.<family>.*`.
- The value shape (types / enums / units).
- Responsive, `:hover`, and `sticky` state variants where applicable.
- A minimal copy-paste example (an attributes fragment an MCP caller can send).
- The verification-tier marker + provenance (the exact `module.json` path it came
  from and/or the VB round-trip date; Divi doc link where relevant).

Plus:
- A new row in `SKILL.md`'s Reference Files table pointing to it.
- A `FORK.md` divergence entry recording the new clean-room reference.

## "Testing" a documentation artifact

There is no PHP/test-code here; the artifact is knowledge, and its correctness
guarantee is the verification tier itself:
- Every shipped path is either schema-confirmed against the real `module.json`
  or VB-round-trip-confirmed on our own site, with the date + source cited.
- A lightweight check that each cited dot-path resolves through the
  introspection tooling (`schema_get_module` / the on-disk `module.json`) — i.e.
  no path is documented that the schema doesn't actually define.
- No `<!-- UNVERIFIED -->` paths ship as authoritative; anything that can't be
  confirmed is either omitted or explicitly marked.

## Out of scope / non-goals

- Not touching plugin PHP or the write path (already complete).
- Not re-documenting CSS classes / WebGL (that's `design-effects.md`).
- Not the per-module element maps (#63) — this is the cross-module shared system.
- Not a design-system/preset guide (`presets.md` / `design-guide.md` own that).

## Follow-ups

- #63 (per-module element maps), #64 ($variable + Interactions), #65 (deeper SCF)
  reuse this doc's format + methodology.
- #82 promotes the full set to the VB-verified gold tier.
