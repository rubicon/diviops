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

All paths below are relative to
`<theme>/includes/builder-5/` on the reference site.

| Source | What it establishes |
|---|---|
| `server/Packages/Module/Options/Interactions/InteractionsScriptData.php` | Server-side attr → runtime-payload shape, every key + PHP default, the preset-CSS registration re-entrancy guard |
| `server/Packages/Module/Options/Interactions/InteractionUtils.php` | `has_interactions()` — the two accepted attr shapes |
| `server/Packages/Module/Options/Interactions/InteractionsPresetAttrsMap.php` | Preset binding is a single opaque `'script'` type, not decomposed into `__subField`s |
| `server/Packages/Module/Module.php` (`:483-517`) | **The marker attrs** — `data-interaction-trigger` / `data-interaction-target` emission, and the trigger-side backfill |
| `visual-builder/build/script-library-interactions.js` (36 KB, readable) | The entire front-end runtime: trigger dispatch, effect decomposition, target resolution, per-effect mechanics |
| `visual-builder/build/field-library.js`, `modal-library.js` | VB option maps (labels) for all four enums; the VB's default interaction object |
| `visual-builder/build/module.js` | The VB-side payload generator — a *mirror* of the PHP, and NOT the front-end runtime (conflating the two produced a factual error in the first draft of this spec) |

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

- **The marker attrs are mandatory, and the target side has no fallback.** This is
  the single most important item in the file, and the first draft of this spec
  missed it entirely. Authoring `interactions[]` alone is *not enough* — the runtime
  locates participating modules by the DOM attributes `data-interaction-trigger` /
  `data-interaction-target`, which PHP emits from
  `module.decoration.interactionTrigger` / `module.decoration.interactionTarget`
  (`Module.php:483-517`). If it cannot find `[data-interaction-trigger="<id>"]`, it
  `return`s before setup and the interaction silently never runs.
  The two sides are **asymmetric**:
  - *Trigger side* backfills — with `interactionTrigger` unset, PHP extracts the id
    from `interactions[0].triggerClass` via `/et-interaction-trigger-([a-zA-Z0-9]+)/`.
    Only the **first** interaction is consulted, and only that exact class format
    matches; a custom `triggerClass` outside that shape yields no marker and no
    interaction.
  - *Target side* does **not** backfill. `interactionTarget` must be authored
    explicitly on the target module — a different block from the trigger. Without
    it the runtime never applies the target class, so
    `querySelectorAll('.<targetClass>')` finds nothing and the effect never fires.
  Exception: `targetType` of `parentContainer` / `rootContainer` / `children`
  resolves by DOM traversal and needs no target marker.
- **Desktop-only.** No responsive variants exist; the PHP reads `attr['desktop']`
  exclusively. Authoring `tablet`/`phone` here is silently inert.
- **`effect` is parsed by prefix-stripping, not list-matching.** The dispatcher
  splits `toggle|add|remove` off the front and switches on the remaining noun. This
  is *why* 12 of the 14 values are verb×noun combinations. Two distinct failure
  modes follow, and the first draft of this spec conflated them:
  - An unrecognized *verb* on a recognized noun falls through to `toggle`
    (`return [t, "toggle"]`).
  - An unrecognized *noun* hits a `switch` with **no `default:` arm** and silently
    no-ops — nothing runs, nothing errors.
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
- **Empty `attributeName`/`attributeValue` or `cookieName`/`cookieValue` silently
  no-op.** The runtime requires *both* halves truthy
  (`i.attributeName && i.attributeValue && …`). An empty string does not mean
  "remove the attribute/cookie" — it means nothing happens.
- **Attribute names are sanitized, not used literally.** `sanitizeAttributeName()`
  lowercases, strips characters outside `[a-z0-9\-_:]`, truncates at 200 chars, and
  prefixes `data-` when the result doesn't start with a letter/underscore/colon.
  Authoring `Foo Bar` does not set `Foo Bar`.
- **Loop-item target narrowing.** With several elements matching `targetClass`, the
  runtime narrows to the one sharing the trigger's `data-loop-item` value. Inside a
  loop this is what makes per-item interactions work; outside one, multiple matches
  return an array and *all* of them receive the effect.
- **Preset effects are stateful, not a class toggle.** They stash
  `data-divi-original-preset-*` / `data-divi-preset-attributes-*`, strip and restore
  classes/styles/data-attrs, and dispatch `ETDiviInteractionsPresetAfterHandle`.
- **`viewportEnter`/`viewportExit` no-op entirely without `IntersectionObserver`**
  (`if ("undefined" == typeof IntersectionObserver) return;`).
- **`load` does not always fire on init.** It is skipped when
  `skipLoadTriggerExecution` is set — which the VB does on remount and view-state
  restore.
- **`enableInteraction` is inert on the front end** — see below.

## `enableInteraction`: settled from source, no browser test needed

The first draft of this spec asserted that "neither the PHP nor the JS runtime reads
`enableInteraction`, both hardcoding `enabled: true`," and proposed a browser test to
confirm it. **The JS half of that claim was wrong**, and the error is worth recording
because it is a specific, repeatable mistake: `enabled:!0` does appear in
`module.js` and `modal-library.js`, but those are **VB-side payload generators**, not
the front-end runtime. `script-library-interactions.js` — the actual front-end
runtime — contains zero occurrences of either `enabled:!0` or `enableInteraction`. It
*consumes* `t.enabled`; it never produces it. The first draft conflated a mirror of
the producer with the consumer.

Corrected, the picture is simpler and needs no browser test to establish:

- PHP (`InteractionsScriptData.php:168`) hardcodes `'enabled' => true` and never
  reads `enableInteraction`.
- The front-end runtime gates setup on the payload's `enabled`
  (`t.enabled && window.et_setup_interaction(...)`).

Since PHP is the sole producer of the front-end payload and always emits `true`,
`enableInteraction: "off"` in attrs **cannot** disable an interaction on a
PHP-rendered page. That is a source-complete conclusion about the front end, so the
planned browser test is dropped from this design.

What remains genuinely unverified is VB-*internal* behavior — whether toggling it off
inside the builder suppresses the interaction in the builder's own preview, which runs
through `module.js` rather than PHP. That is out of scope here and will be marked
`<!-- UNVERIFIED -->` rather than guessed at.

## Verification posture

The first draft proposed a new `*(source-verified)*` marker. **That was a mistake** —
`SKILL.md:50-58` already defines the repo's three-tier convention
(`*(VB-verified YYYY-MM-DD)*`, `*(verified YYYY-MM-DD)*` / `*(empirically verified)*`,
`<!-- UNVERIFIED -->`) and explicitly instructs contributors to *preserve the existing
tier*. Inventing a fourth tier for this one file would fragment the vocabulary
precisely where consistent evidence-labeling matters most.

Using the existing convention as written:

- Claims established only by reading Divi's source — which is nearly all of this file
  — are **`<!-- UNVERIFIED -->`**. The definition ("neither VB-tested nor
  render-confirmed") describes source-reading exactly, however authoritative the
  source. Each such claim still carries its file + quoted-literal citation, so a
  reader can see the evidence is strong even where the tier is conservative.
- Anything later confirmed by observing real behavior gets promoted to
  `*(verified YYYY-MM-DD)*`, and only a genuine VB round-trip earns `*(VB-verified)*`.

This is a deliberately conservative posture, and it differs from #63 where every claim
was round-tripped live. The difference is inherent to the subject: Interactions are a
client-side JS runtime, so the PHP-render check used in #63
(`apply_filters('the_content', …)`) cannot observe their behavior at all. Better an
honestly-labeled `UNVERIFIED` reference than a falsely-confident one — the tier is
about how the claim was checked, not how likely it is to be right.

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

- No code changes; the existing suite (`php tests/run.php`) must stay green. Assertion
  counts are deliberately not hardcoded here — they move with every PR, and a stale
  number in a spec invites either a false alarm or a rubber stamp.
- Adversarial review by a fresh agent before merge, matching #62 and #63 — with
  explicit instruction to attack unverified claims and compiled-JS citations. (One
  such pass already ran against this spec and produced the corrections recorded above.)

## PR hygiene

The implementation PR must **not** use `Closes #64`. #64 covers two references and
only one ships here; auto-closing it would silently drop the `$variable()` binding
half. Reference the issue without a closing keyword and close it manually once the
follow-up lands.

## Review history

An adversarial review of the first draft (Codex, 2026-07-30) produced four corrections
now folded in above, each independently re-verified against source before acceptance:

1. **Missing marker-attr requirement** — the largest gap. `interactions[]` alone does
   nothing without `data-interaction-trigger`/`data-interaction-target`; the reference
   would have taught a silently-broken pattern.
2. **`enableInteraction` JS claim was factually wrong** — conflated the VB-side
   payload generator with the front-end runtime. Correcting it also *removed* the only
   planned browser test, since source alone settles the front-end question.
3. **`*(source-verified)*` was an invented tier** conflicting with `SKILL.md`'s
   existing three-tier convention.
4. **Provenance paths omitted the `server/` prefix**, and the effect-degradation claim
   conflated unknown-verb (falls back to toggle) with unknown-noun (silent no-op).

Plus six additional silent-failure modes now documented under Surprises.
