# Design: clean-room Interactions skill reference (#64a)

Date: 2026-07-30
Issue: [#64](https://github.com/rubicon/diviops/issues/64) (part of the [#50](https://github.com/rubicon/diviops/issues/50) epic)
Status: approved

## Problem

Issue #64 asks for two clean-room references: `$variable()` per-module binding, and
the Interactions system. Today the fork documents Interactions in exactly one line —
a single row in `module-formats.md`'s Verification Depth table reading
"`interactions` | VB roundtrip confirmed" plus the bare attribute path. Nothing
describes what triggers exist, what effects exist, how targets resolve, or which
of the several silent-failure modes an author will hit first.

This is squarely inside #50's stated goal: Pro sells this knowledge; we build our
own from primary sources.

## Scope

**In scope (this deliverable):** the Interactions system only.

**Deferred to a follow-up:** the `$variable()` per-module binding half of #64.
Splitting was an explicit decision, not an oversight — the Interactions research is
complete and deep enough to ship now, while the binding half is a lighter,
not-yet-started synthesis of already-documented token syntax (`presets.md`) with
already-documented element paths (`module-formats.md`). Shipping them together
would delay a substantial, finished piece of work behind a separate research pass.
#64 stays open until both land.

**Explicitly not in scope:** any change to the existing 28-module generated index in
`module-formats.md` (a separate task is already re-deriving that from
`*PresetAttrsMap.php`), and any broad browser-verification pass of every
trigger/effect combination.

## Sources (clean-room provenance)

All primary Divi source. `diviops-agent-pro` was never opened.

| Source | What it establishes |
|---|---|
| `Packages/Module/Options/Interactions/InteractionsScriptData.php` | Server-side attr → runtime-payload shape, every key + PHP default, the preset-CSS registration re-entrancy guard |
| `Packages/Module/Options/Interactions/InteractionUtils.php` | `has_interactions()` — the two accepted attr shapes |
| `Packages/Module/Options/Interactions/InteractionsPresetAttrsMap.php` | Preset binding is a single opaque `'script'` type, not decomposed into `__subField`s |
| `visual-builder/build/script-library-interactions.js` (36 KB, readable) | The entire front-end runtime: trigger dispatch, effect decomposition, target resolution, per-effect mechanics |
| `visual-builder/build/field-library.js`, `modal-library.js` | VB option maps (labels) for all four enums; the VB's default interaction object |

**Two-source cross-confirmation.** The `trigger` (8) and `effect` (14) enums were
derived independently twice — once from the runtime `switch` dispatch, once from the
VB option maps — and match exactly, with no extras or omissions on either side. Where
this holds it is stated in the reference, because two unrelated sources agreeing is
materially stronger evidence than either alone.

## Deliverable

One new file, `skills/divi-5-builder/references/interactions.md`, following the
structure `advanced-attributes.md` established in #62:

1. **Clean-room provenance header** — sources named, Pro-never-opened statement.
2. **Attribute shape** — `module.decoration.interactions.desktop.value.interactions[]`,
   every key in an entry with its default.
3. **The four enums** — `trigger` (8), `effect` (14), `targetType` (4),
   `mouseMovementType` (5); machine value + VB label per row.
4. **Per-effect mechanics** — what each effect does at runtime, including
   Visibility's video-pause / autoplay-strip / animation-restore behavior and the
   50 ms toggle debounce.
5. **Surprises** — the load-bearing section (see below).
6. **Copy-paste `attrs` fragments** — click-to-toggle-visibility, viewport-triggered
   preset swap, mirror-mouse-movement.

Cross-linked from `SKILL.md` and from `module-formats.md`'s existing Interactions
row. `FORK.md` gets a divergence entry.

## Documented surprises

These are the reason the file exists — each is a silent failure, not an error.

- **Desktop-only.** No responsive variants exist; the PHP reads `attr['desktop']`
  exclusively. Authoring `tablet`/`phone` here is silently inert.
- **`effect` is parsed by prefix-stripping, not list-matching.** The dispatcher
  splits `toggle|add|remove` off the front and switches on the remaining noun. This
  is *why* 12 of the 14 values are verb×noun combinations, and why an unrecognized
  effect degrades to a `toggle` action instead of erroring.
- **`removeVisibility` is labeled "Hide Element."** Every other add/remove pair is
  labeled literally ("Add Preset"/"Remove Preset"); Visibility's two invert. An
  agent translating "hide this on click" needs `removeVisibility`, whose name reads
  backwards relative to what the user sees in the VB.
- **Preset effects derive the module name by regex-matching the target's own class
  list** for a UUID or numeric-id suffix, then rewriting `et_pb_<name>` →
  `divi/<name>` → kebab-case. No match → the entire effect silently no-ops.
- **`breakpointEnter`/`breakpointExit` compare breakpoint *order*, not name**
  (desktop 50, tablet 30, phone 10). "Enter tablet" therefore also fires on phone.
- **Accordion's click-to-expand runs through this same machinery** — the `click`
  handler special-cases `.et_pb_accordion_item`, calling
  `et_pb_accordion_item_expand()` and emitting a `divi:accordion:open` event.
  Cross-referenced to the Accordion rows added in #63.
- **`enableInteraction`** — pending verification, see below.

## The one empirical check: `enableInteraction`

The VB's default interaction object carries `enableInteraction: "on"`, but neither
the PHP (`'enabled' => true`, hardcoded) nor the JS runtime (`enabled:!0`,
hardcoded) ever reads it. The runtime *does* gate setup on `t.enabled` — so if both
producers hardcode it true, then `enableInteraction: "off"` written into attrs is
silently ignored and the interaction still fires on the front end.

That is a significant user-facing claim (toggle it off in the VB, it keeps running
live) currently resting on inference from reading source rather than observed
behavior — the exact shape of claim that #62's adversarial review caught and
rejected. So it gets verified before it gets documented: build a scratch page with
`enableInteraction: "off"`, load it in a real browser, observe whether the
interaction fires. Narrow and targeted, not a general browser-verification pass.

If it can't be confirmed, it is documented as explicitly unverified or dropped —
not asserted.

## Verification posture

Claims are marked `*(source-verified YYYY-MM-DD)*`, **not** `*(VB-verified)*`.
`VB-verified` means something specific in this repo — a real round-tripped edit
observed against the reference site — and it would not be earned by reading source,
however authoritative. The `enableInteraction` check is the sole exception and is
labeled for what it actually is.

This differs from #63, where every claim was round-tripped against the live site.
The difference is inherent to the subject: Interactions are a client-side JS runtime,
so the PHP-render check used in #63 (`apply_filters('the_content', …)`) cannot
observe their behavior at all.

## Risks

- **Compiled-bundle citations are version-fragile.** Byte offsets and minified
  identifiers (`Gd`, `LW`, `Vd`) change on any Divi rebuild. Mitigation: cite by
  file plus the quoted literal (the option map, the `switch` arm), never by byte
  offset alone — matching how `advanced-attributes.md` already cites compiled JS.
- **Divi version drift.** Everything is read against 5.9.0; the header states this
  explicitly, as `module-formats.md` now does after #63.
- **Over-claiming from source.** Addressed by the verification posture above and by
  running an adversarial review before merge, per the #62/#63 precedent.

## Test plan

- No code changes; `php tests/run.php` must stay green (956 assertions).
- The `enableInteraction` browser check, run and reported honestly either way.
- Adversarial review by a fresh agent before merge, matching #62 and #63 — with
  explicit instruction to attack unverified claims and compiled-JS citations.
