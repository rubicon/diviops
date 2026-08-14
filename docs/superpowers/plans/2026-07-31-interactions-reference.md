# Divi 5 Interactions Reference — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Author `skills/divi-5-builder/references/interactions.md` — a clean-room, exhaustively-cited reference for Divi 5's Interactions system — and wire it into the skill's navigation.

**Architecture:** A single new Markdown reference file following the section shape `advanced-attributes.md` established in #62, plus three small cross-link edits (`SKILL.md`, `module-formats.md`, `FORK.md`). No code changes anywhere.

**Tech Stack:** Markdown only. Verification is by reading Divi 5.9.0's own PHP and compiled JS with `grep`/`python3`; there is nothing to compile or execute.

**Spec:** [`docs/superpowers/specs/2026-07-30-interactions-reference-design.md`](../specs/2026-07-30-interactions-reference-design.md)

## Global Constraints

- **Clean-room IP boundary (non-negotiable):** derive content ONLY from Divi's own source, Divi's public docs, and the free MIT skill. **Never** read, open, or derive from `diviops-agent-pro`. Every task below cites Divi source exclusively.
- **Verification tier vocabulary is fixed** by `skills/divi-5-builder/SKILL.md:50-58`. Use only `*(VB-verified YYYY-MM-DD)*`, `*(verified YYYY-MM-DD)*` / `*(empirically verified …)*`, and `<!-- UNVERIFIED -->`. **Do not invent a `*(source-verified)*` tier** — that was rejected during spec review. Source-only claims are `<!-- UNVERIFIED -->`.
- **Divi version of record:** `5.9.0`. State it in the file header.
- **Cite compiled JS by file + quoted literal, never by byte offset or minified identifier.** Minified names (`CW`, `LW`, `Dd`, `Ld`, `Fd`, `Bd`, `Gd`) change on every Divi rebuild; a quoted string literal like `case"breakpointEnter"` survives.
- **No AI-authorship trailers** on any commit (no `Co-Authored-By: Claude`, no "Generated with" footer).
- **Commits must be signed** (the repo's default; do not pass `--no-gpg-sign`).
- **Branch:** `dev/64-interactions-reference` (already exists, already pushed).
- **The PR must NOT use `Closes #64`.** #64 covers two references and only the Interactions half ships here. Use `Part of #64`.
- **Do not modify** `skills/divi-5-builder/references/advanced-attributes.md` or any generated block in `module-formats.md`.

**Divi source root** — every verification command below assumes:

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
```

---

## File Structure

| File | Responsibility |
|---|---|
| `skills/divi-5-builder/references/interactions.md` | **Create.** The entire deliverable — attribute shape, four enums, marker attrs, per-effect mechanics, surprises, copy-paste fragments. |
| `skills/divi-5-builder/SKILL.md` | **Modify** (one table row at `:29`). Route "interactions / triggers / effects" to the new file. |
| `skills/divi-5-builder/references/module-formats.md` | **Modify** (one table row at `:115`). Repoint the existing stub row at the new file. |
| `FORK.md` | **Modify** (fork-owned table, after `:93`). Divergence entry for the new fork-owned file. |

Tasks 1–6 build `interactions.md` section by section; Task 7 does the wiring and the PR.

---

### Task 1: Scaffold, provenance header, and attribute shape

**Files:**
- Create: `skills/divi-5-builder/references/interactions.md`

**Interfaces:**
- Produces: the `# Divi 5 Interactions` H1 and the section anchors `#attribute-shape`, which Tasks 2–6 append after and Task 7 links to.

- [ ] **Step 1: Verify the attribute shape and the desktop-only claim before writing them**

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
sed -n '128,140p' "$DIVI/server/Packages/Module/Options/Interactions/InteractionsScriptData.php"
grep -n "tablet\|phone" "$DIVI/server/Packages/Module/Options/Interactions/InteractionsScriptData.php" || echo "NO tablet/phone lookup — desktop-only CONFIRMED"
```

Expected: the `desktop`/`value`/`interactions` read is visible, and the `grep` prints the "desktop-only CONFIRMED" line (no responsive lookup exists).

- [ ] **Step 2: Verify every payload key and its PHP default**

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
sed -n '146,172p' "$DIVI/server/Packages/Module/Options/Interactions/InteractionsScriptData.php"
```

Expected: shows `'trigger'`, `'effect'`, `'target'`, `'attributeName'`, `'attributeValue'`, `'cookieName'`, `'cookieValue'`, `'timeDelay' => … '0ms'`, `'presetId'`, `'replaceExistingPreset' => … false`, `'sensitivity' => … 50`, `'mouseMovementType' => … 'translate'`, `'breakpointName'`, `'enabled' => true`.

- [ ] **Step 3: Create the file with header, provenance, and attribute shape**

Write `skills/divi-5-builder/references/interactions.md`:

````markdown
# Divi 5 Interactions

Reference for `module.decoration.interactions` — Divi 5's trigger/effect system for
click, hover, viewport, and breakpoint-driven behavior. Documented against Divi
**5.9.0**.

**Clean-room provenance**: every value, enum, and mechanism below is derived from
Divi 5's own source — the server-side `Module/Options/Interactions/*.php` classes,
`Module/Module.php`'s HTML-attribute emission, and the compiled front-end runtime
`visual-builder/build/script-library-interactions.js` — cross-referenced against the
Visual Builder's own option maps in `visual-builder/build/field-library.js` and
`modal-library.js`. None of it was read from, derived from, or cross-checked against
`diviops-agent-pro`; that plugin was never opened while authoring this file. See
`SKILL.md`'s [Verification convention](../SKILL.md#verification-convention) for what
the tags below mean.

**Evidence tier — read this before relying on anything here.** Unlike
[advanced-attributes.md](advanced-attributes.md), whose claims were confirmed by
round-tripping real edits, this file is almost entirely **`<!-- UNVERIFIED -->`** in
the repo's sense: read from source, not observed in a live browser. That is a
deliberate, honest label rather than a weak one — Interactions are a client-side JS
runtime, so the PHP-render check used elsewhere in this skill
(`apply_filters('the_content', …)`) cannot observe their behavior at all. Every claim
still carries a file + quoted-literal citation, so the evidence is inspectable even
where the tier is conservative.

**Compiled-JS citation policy**: compiled bundles are cited by *file plus quoted
literal* (e.g. `case"breakpointEnter"`), never by byte offset or minified identifier.
Minified names change on every Divi rebuild; the string literals do not.

## Attribute shape

Interactions live in one array under the module wrapper:

```
module.decoration.interactions.desktop.value.interactions[]
```

**Desktop-only — there are no responsive variants.** `InteractionsScriptData::generate_data()`
reads `$attr['desktop']['value']` and nothing else; the file contains no `tablet` or
`phone` lookup anywhere, and carries the comment "interactions don't support
responsive breakpoints." Authoring a `tablet` or `phone` sibling here is silently
inert — it will not error, and it will not do anything.

Each entry in the array:

| Key | Type | Default | Applies to |
|---|---|---|---|
| `id` | string | `""` | all |
| `triggerClass` | string | auto-generated `et-interaction-trigger-<hash>` | all — see [Marker attributes](#marker-attributes-the-part-that-silently-breaks) |
| `trigger` | enum | *(see below)* | all |
| `effect` | enum | *(see below)* | all |
| `target.targetClass` | string | `""` | all |
| `target.label` | string | `""` | all (VB display only) |
| `target.targetType` | enum | `"module"` | all |
| `attributeName` | string | `""` | `*Attribute` effects |
| `attributeValue` | string | `""` | `*Attribute` effects |
| `cookieName` | string | `""` | `*Cookie` effects |
| `cookieValue` | string | `""` | `*Cookie` effects |
| `timeDelay` | string | `"0ms"` | all |
| `presetId` | string | `""` | `*Preset` effects |
| `replaceExistingPreset` | bool | `false` | `*Preset` effects |
| `sensitivity` | int 0–100 | `50` | `mirrorMouseMovement` |
| `mouseMovementType` | enum | `"translate"` | `mirrorMouseMovement` |
| `breakpointName` | string | `""` | `breakpointEnter` / `breakpointExit` |

**`enableInteraction` is inert on the front end.** The Visual Builder's default
interaction object carries `enableInteraction: "on"`
(`modal-library.js`, literal `enableInteraction:"on"`), but the server never reads it:
`InteractionsScriptData.php` hardcodes `'enabled' => true` when building the payload.
The front-end runtime *gates* on that payload field
(`t.enabled&&window.et_setup_interaction(...)`) but never produces it — the string
`enableInteraction` does not appear in `script-library-interactions.js` at all. Since
PHP is the sole producer of the front-end payload and always emits `true`,
**`enableInteraction: "off"` cannot disable an interaction on a PHP-rendered page.**
Whether it suppresses the interaction inside the builder's own preview (which runs
through `module.js`, a separate payload generator) is not covered here.
<!-- UNVERIFIED -->

**Preset binding is opaque.** `InteractionsPresetAttrsMap::get_map()` returns a single
entry with `'preset' => [ 'script' ]` — the whole interactions array binds as one
unit. Unlike `boxShadow` or `filters`, there are no `__subField` preset paths to
target individually.
<!-- UNVERIFIED -->
````

- [ ] **Step 4: Verify the file is valid Markdown with the expected anchors**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
grep -c "^#" skills/divi-5-builder/references/interactions.md
grep -n "^## " skills/divi-5-builder/references/interactions.md
```

Expected: at least one `^#` heading; `## Attribute shape` listed.

- [ ] **Step 5: Commit**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
git add skills/divi-5-builder/references/interactions.md
git commit -m "docs(#64): scaffold Interactions reference with attribute shape

Header, clean-room provenance, and the payload-key table with every PHP
default. Records that interactions are desktop-only (no responsive lookup
exists in InteractionsScriptData.php) and that enableInteraction is inert
on the front end, since PHP hardcodes enabled=true and is the sole
producer of the front-end payload."
```

---

### Task 2: The four enum tables

**Files:**
- Modify: `skills/divi-5-builder/references/interactions.md` (append)

**Interfaces:**
- Consumes: the `## Attribute shape` table from Task 1, which references `trigger`, `effect`, `targetType`, `mouseMovementType` as "see below".
- Produces: anchors `#trigger`, `#effect`, `#targettype`, `#mousemovementtype`, referenced by Tasks 4–6.

- [ ] **Step 1: Verify the trigger enum in BOTH the runtime and the VB option maps**

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
for t in click mouseEnter mouseExit viewportEnter viewportExit load breakpointEnter breakpointExit; do
  r=$(grep -c "case\"$t\"" "$DIVI/visual-builder/build/script-library-interactions.js")
  v=$(grep -c "$t:" "$DIVI/visual-builder/build/field-library.js")
  echo "$t  runtime:$r  vb:$v"
done
```

Expected: every trigger appears in both columns with a non-zero count. Two independent sources agreeing is the evidence claim made in the text below.

- [ ] **Step 2: Verify the effect enum and the label inversion**

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
python3 - <<'PY'
import re,os
d=os.environ.get('DIVI') or "/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
c=open(f"{d}/visual-builder/build/field-library.js",encoding="utf-8",errors="replace").read()
for e in ["toggleVisibility","addVisibility","removeVisibility","toggleAttribute","addAttribute",
          "removeAttribute","toggleCookie","addCookie","removeCookie","togglePreset","addPreset",
          "removePreset","scrollToElement","mirrorMouseMovement"]:
    i=c.find(e+":{")
    lab=re.search(r'"([^"]{2,40})"',c[i:i+260]) if i>-1 else None
    print(f"{e:22} {'FOUND' if i>-1 else 'MISSING':8} label~ {lab.group(1) if lab else '?'}")
PY
```

Expected: all 14 FOUND. `addVisibility` shows a "Show Element"-style label and `removeVisibility` a "Hide Element"-style label — the inversion documented below.

- [ ] **Step 3: Verify the prefix-stripping dispatcher and the missing `default:` arm**

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
python3 - <<'PY'
import os
d=os.environ.get('DIVI') or "/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
c=open(f"{d}/visual-builder/build/script-library-interactions.js",encoding="utf-8",errors="replace").read()
i=c.find('"scrollToElement"===')
print("DECOMPOSER:", c[i-40:i+330] if i>-1 else "NOT FOUND")
j=c.find('case"mirrorMouseMovement"')
tail=c[j:j+520]
print("\nHAS default: ARM?", "default:" in tail)
PY
```

Expected: the decomposer shows the `startsWith("toggle"/"add"/"remove")` chain ending in `return [t,"toggle"]`; `HAS default: ARM? False`.

- [ ] **Step 4: Verify targetType and mouseMovementType**

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
grep -o 'module:"",parentContainer:"[^"]*",rootContainer:"[^"]*",children:"[^"]*"' "$DIVI/visual-builder/build/modal-library.js"
grep -c 'case"translate"\|case"scale"\|case"opacity"\|case"tilt"\|case"rotate"' "$DIVI/visual-builder/build/script-library-interactions.js"
grep -o 'parentContainer\|rootContainer\|children' "$DIVI/visual-builder/build/script-library-interactions.js" | sort -u
```

Expected: the 4-entry `targetType` map prints; the mouseMovementType case count is ≥1; the runtime shows `parentContainer`/`rootContainer`/`children` branches — and notably **no** `module` branch, which is the fall-through claim below.

- [ ] **Step 5: Append the four enum tables**

Append to `skills/divi-5-builder/references/interactions.md`:

````markdown
## `trigger`

Eight values. Enumerated independently from two sources that agree exactly — the
runtime's own `switch(t.trigger)` dispatch in `script-library-interactions.js` and the
VB option map in `field-library.js` — with no extras or omissions on either side.
<!-- UNVERIFIED -->

| Value | VB label | Fires when |
|---|---|---|
| `click` | On Click | The trigger element is clicked. Sets `cursor:pointer` on the element. Has an Accordion special case — see [Surprises](#surprises). |
| `mouseEnter` | On Hover | `mouseenter`. With effect `mirrorMouseMovement` it wires up immediately instead of waiting for the event. |
| `mouseExit` | On Hover Out | `mouseleave`. |
| `viewportEnter` | On Viewport Enter | `IntersectionObserver` reports intersecting. **No-ops entirely if `IntersectionObserver` is undefined.** |
| `viewportExit` | On Viewport Exit | `IntersectionObserver` reports not-intersecting. |
| `load` | On Page Load | Initialization — **unless** `skipLoadTriggerExecution` is set (the VB sets it on remount and view-state restore). |
| `breakpointEnter` | On Breakpoint Enter | Current breakpoint is at-or-below `breakpointName` — **an order comparison, not a name match**. See [Surprises](#surprises). |
| `breakpointExit` | On Breakpoint Exit | The inverse of the above. |

## `effect`

Fourteen values, and the dispatcher does **not** match them against a flat list. It
strips a `toggle`/`add`/`remove` prefix off the front, then switches on the remaining
noun (`Visibility`, `Attribute`, `Cookie`, `Preset`), which is why 12 of the 14 are
verb×noun combinations:

```js
if ("scrollToElement" === t) return ["scrollToElement", "toggle"];
if (t.startsWith("toggle"))  return [t.replace("toggle", ""),  "toggle"];
if (t.startsWith("add"))     return [t.replace("add", ""),     "add"];
if (t.startsWith("remove"))  return [t.replace("remove", ""),  "remove"];
return [t, "toggle"];
```

Two distinct failure modes follow from that, and they are easy to conflate:

- An unrecognized **verb** on a recognized noun falls through to `toggle` via the final
  `return [t,"toggle"]`.
- An unrecognized **noun** reaches a `switch` that has **no `default:` arm** — it
  silently no-ops. Nothing runs, nothing throws.

<!-- UNVERIFIED -->

| Value | VB label | Notes |
|---|---|---|
| `toggleVisibility` | Toggle Visibility | Default effect in the VB's own new-interaction object. |
| `addVisibility` | **Show Element** | ⚠ Label reads opposite to the value — see [Surprises](#surprises). |
| `removeVisibility` | **Hide Element** | ⚠ To hide something on click you want `removeVisibility`. |
| `toggleAttribute` | Toggle Attribute | Needs `attributeName` **and** `attributeValue`. |
| `addAttribute` | Add Attribute | |
| `removeAttribute` | Remove Attribute | |
| `toggleCookie` | Toggle Cookie | Needs `cookieName` **and** `cookieValue`. |
| `addCookie` | Add Cookie | |
| `removeCookie` | Remove Cookie | |
| `togglePreset` | Toggle Preset | Needs `presetId`. Fragile module-name derivation — see [Surprises](#surprises). |
| `addPreset` | Add Preset | |
| `removePreset` | Remove Preset | |
| `scrollToElement` | Scroll To Element | Standalone; does not decompose. `scrollIntoView({behavior:"smooth",block:"start"})`. |
| `mirrorMouseMovement` | Mirror Mouse Movement | Standalone; ignores the verb entirely. Uses `sensitivity` + `mouseMovementType`. |

## `targetType`

Four values, from the VB's own map
(`module:"",parentContainer:"et-interaction-target-parent",rootContainer:"et-interaction-target-root",children:"et-interaction-target-children"`).
<!-- UNVERIFIED -->

| Value | Resolves to | Needs a target marker? |
|---|---|---|
| `module` *(default)* | Elements matching `target.targetClass` | **Yes** — see [Marker attributes](#marker-attributes-the-part-that-silently-breaks) |
| `parentContainer` | `element.parentElement` (one level up) | No — DOM traversal |
| `rootContainer` | Nearest ancestor `.et_pb_section`, falling back to `<body>`/`<html>` | No — DOM traversal |
| `children` | **All** descendant `.et_pb_module, .et_pb_row, .et_pb_column, .et_pb_section` (excluding VB chrome `.et-vb-ui`) — every match receives the effect | No — DOM traversal |

There is **no literal `module` branch** in the runtime's resolver. `module` is simply
what an unset or unrecognized `targetType` falls through to, landing on the
`targetClass` lookup. Writing a typo'd `targetType` therefore behaves like `module`
rather than erroring.

## `mouseMovementType`

Five values, used only by the `mirrorMouseMovement` effect. Each maps to a distinct
transform computed from the cursor's offset from the trigger element, scaled by
`sensitivity` (0–100).
<!-- UNVERIFIED -->

| Value | Applied as |
|---|---|
| `translate` *(default)* | `translate(Xpx, Ypx)` |
| `scale` | Uniform `scale()` driven by cursor distance |
| `opacity` | Opacity fade by cursor distance (sets `style.opacity`, emits no transform) |
| `tilt` | `perspective(1000px) rotateX(...) rotateY(...)` |
| `rotate` | `rotate(...)` from `atan2(y, x)` |
````

- [ ] **Step 6: Confirm all four enum sections landed**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
grep -n "^## \`trigger\`\|^## \`effect\`\|^## \`targetType\`\|^## \`mouseMovementType\`" skills/divi-5-builder/references/interactions.md
```

Expected: four lines.

- [ ] **Step 7: Commit**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
git add skills/divi-5-builder/references/interactions.md
git commit -m "docs(#64): document the four Interactions enums

trigger (8), effect (14), targetType (4), mouseMovementType (5). The
trigger and effect sets were enumerated independently from the runtime
dispatch and the VB option maps, which agree exactly.

Records two things a flat enum list would hide: effects are parsed by
prefix-stripping rather than list-matching, so an unknown verb falls back
to toggle while an unknown noun hits a switch with no default arm and
silently no-ops; and targetType has no literal module branch, so a typo'd
value behaves like the default instead of erroring."
```

---

### Task 3: Marker attributes — the section that prevents silent breakage

**Files:**
- Modify: `skills/divi-5-builder/references/interactions.md` (append)

**Interfaces:**
- Produces: anchor `#marker-attributes-the-part-that-silently-breaks`, linked from Tasks 1, 2, 5, and 6.

This is the highest-value section in the file. Everything else describes what the
system *can* do; this describes why a correct-looking `interactions[]` does nothing.

- [ ] **Step 1: Verify PHP emits the markers and how the trigger side backfills**

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
sed -n '483,517p' "$DIVI/server/Packages/Module/Module.php"
```

Expected: reads `$attrs['module']['decoration']['interactionTrigger']` and `['interactionTarget']`; backfills `interaction_trigger` from `$interactions[0]['triggerClass']` via `/et-interaction-trigger-([a-zA-Z0-9]+)/`; sets `data-interaction-trigger` and `data-interaction-target`. Confirm **no** equivalent backfill block exists for the target.

- [ ] **Step 2: Verify the runtime bails without a trigger marker**

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
python3 - <<'PY'
import os
d=os.environ.get('DIVI') or "/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
c=open(f"{d}/visual-builder/build/script-library-interactions.js",encoding="utf-8",errors="replace").read()
i=c.find('[data-interaction-trigger=')
print(c[max(0,i-420):i+260])
print("\n--- target side ---")
j=c.find('[data-interaction-target=')
print(c[max(0,j-300):j+200])
PY
```

Expected: near the trigger lookup, an early `if(!a)return` — no marker means setup never happens. The target side shows the runtime adding the target class to elements carrying `[data-interaction-target="…"]`.

- [ ] **Step 3: Append the marker-attributes section**

Append to `skills/divi-5-builder/references/interactions.md`:

````markdown
## Marker attributes — the part that silently breaks

**Authoring `interactions[]` alone is not enough.** The runtime does not find modules
by walking the block tree; it finds them by two DOM attributes that PHP emits from two
*separate* decoration keys:

| Decoration key | Emitted DOM attribute | Backfills? |
|---|---|---|
| `module.decoration.interactionTrigger` | `data-interaction-trigger` | **Yes** — from `interactions[0].triggerClass` |
| `module.decoration.interactionTarget` | `data-interaction-target` | **No** |

`Module.php` reads both and adds the corresponding attribute only when the value is
non-empty. The front-end runtime then looks up
`document.querySelectorAll('[data-interaction-trigger="<id>"]')` and, finding nothing,
**`return`s before setup** — the interaction is never wired at all. No console error,
no visual hint.

The two sides are **asymmetric**, and the target side is where authoring goes wrong:

- **Trigger side backfills.** If `interactionTrigger` is unset, PHP extracts the id
  from the first interaction's `triggerClass` using
  `/et-interaction-trigger-([a-zA-Z0-9]+)/`. Two constraints ride along: only
  `interactions[0]` is consulted (a differing `triggerClass` on a later entry is
  ignored for marker purposes), and the class must match that exact shape — a custom
  `triggerClass` outside it yields no marker and therefore no interaction.
- **Target side does not backfill.** `interactionTarget` must be authored explicitly
  **on the target module**, which is a *different block* from the trigger. Without it,
  the runtime never applies the target class, so the later
  `querySelectorAll('.<targetClass>')` matches nothing and the effect never fires.

**Exception:** `targetType` of `parentContainer`, `rootContainer`, or `children`
resolves purely by DOM traversal from the trigger element and needs no target marker.
If you want a targetless interaction, those three are the reliable choices.

The id in the marker is the class suffix: `triggerClass`
`et-interaction-trigger-abc123` pairs with `data-interaction-trigger="abc123"`, and
`targetClass` `et-interaction-target-xyz789` with `data-interaction-target="xyz789"`.

<!-- UNVERIFIED -->

### Minimum viable pair

A trigger module and a target module, wired by hand. Note `interactionTarget` on the
*second* block — omitting it is the single most common way to get a silent no-op:

```json
// Trigger module attrs
{
  "module": {
    "decoration": {
      "interactionTrigger": "abc123",
      "interactions": {
        "desktop": {
          "value": {
            "interactions": [
              {
                "id": "i1",
                "triggerClass": "et-interaction-trigger-abc123",
                "trigger": "click",
                "effect": "toggleVisibility",
                "target": {
                  "targetClass": "et-interaction-target-xyz789",
                  "targetType": "module",
                  "label": "Panel"
                },
                "timeDelay": "0ms"
              }
            ]
          }
        }
      }
    }
  }
}
```

```json
// Target module attrs — REQUIRED, and it does not backfill
{
  "module": {
    "decoration": {
      "interactionTarget": "xyz789"
    }
  }
}
```
````

- [ ] **Step 4: Confirm the section and its JSON fences are intact**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
grep -n "^## Marker attributes" skills/divi-5-builder/references/interactions.md
python3 - <<'PY'
import json,re
c=open("skills/divi-5-builder/references/interactions.md",encoding="utf-8").read()
for b in re.findall(r'```json\n(.*?)```', c, re.S):
    json.loads(re.sub(r'^\s*//.*$', '', b, flags=re.M))
print("all json fences parse OK")
PY
```

Expected: the heading line prints, then `all json fences parse OK`.

- [ ] **Step 5: Commit**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
git add skills/divi-5-builder/references/interactions.md
git commit -m "docs(#64): document the marker attrs that gate every interaction

The runtime locates participating modules via data-interaction-trigger /
data-interaction-target, emitted from module.decoration.interactionTrigger
and interactionTarget, and returns before setup when the trigger marker is
absent. Authoring interactions[] alone therefore does nothing.

The two sides are asymmetric and that is the part worth knowing: the
trigger backfills from interactions[0].triggerClass (first entry only,
and only when it matches the et-interaction-trigger-<alnum> shape), while
the target has no backfill at all and must be authored on the target
module, which is a different block. Includes a minimum viable trigger +
target pair."
```

---

### Task 4: Per-effect mechanics

**Files:**
- Modify: `skills/divi-5-builder/references/interactions.md` (append)

**Interfaces:**
- Consumes: `#effect` from Task 2.
- Produces: anchor `#per-effect-mechanics`.

- [ ] **Step 1: Verify Visibility's side effects and the debounce**

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
grep -o 'autoplay' "$DIVI/visual-builder/build/script-library-interactions.js" | head -3
grep -o 'data-divi-original-display' "$DIVI/visual-builder/build/script-library-interactions.js" | head -2
grep -o 'performance.now()' "$DIVI/visual-builder/build/script-library-interactions.js" | head -2
```

Expected: `autoplay` (the iframe strip/restore), `data-divi-original-display` (display save/restore), and `performance.now()` (the dedupe timestamp) all appear.

- [ ] **Step 2: Verify Cookie mechanics**

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
grep -o 'expires=[^`"]*' "$DIVI/visual-builder/build/script-library-interactions.js" | head -3
grep -o '31536e6' "$DIVI/visual-builder/build/script-library-interactions.js" | head -2
```

Expected: an `expires=` cookie string and the `31536e6` millisecond constant (365 days).

- [ ] **Step 3: Append the per-effect mechanics section**

Append to `skills/divi-5-builder/references/interactions.md`:

````markdown
## Per-effect mechanics

What each effect actually does once its trigger fires. All `<!-- UNVERIFIED -->`
(read from `script-library-interactions.js`).

### Visibility

Toggles/sets `display:none !important` on the target — but with substantially more
machinery than a class flip:

- **On hide:** pauses every `<video>` in the target (skipping section-background
  video), and for embedded iframes from YouTube/Vimeo/Dailymotion/Facebook, blanks the
  `src`, strips the `autoplay` parameter, and restores it ~100 ms later. This is how
  hiding a panel stops its video rather than leaving audio playing.
- **On show:** restores the previous display value from `data-divi-original-display`,
  re-runs entrance animations, and recalculates slider/carousel heights (Divi
  components that measure themselves at paint time render broken if first laid out
  while hidden).
- **Debounce:** `toggleVisibility` is deduped per element + interaction signature
  within a 50 ms window, and a toggle to the state the element is already in is
  suppressed entirely. Rapid repeated triggers will not thrash.

### Attribute

Adds/removes/toggles a single space-separated token within an attribute's value —
it does not overwrite the whole attribute. **Requires both `attributeName` and
`attributeValue` to be non-empty**; an empty string is a no-op, not a removal.
Attribute names are **sanitized, not used literally** — see [Surprises](#surprises).

### Cookie

Sets or removes a browser cookie with a **365-day** expiry at `path=/`. Requires both
`cookieName` and `cookieValue`. `toggle` removes the cookie when its current value
already matches, and sets it otherwise.

### Preset

Adds/removes/toggles a `preset--module--<kebab-module-name>--<presetId>` class. This
is the most fragile effect and the least like a simple class toggle:

- The module name is **derived by regex from the target's own class list**, not passed
  in. No match → the entire effect silently no-ops. See [Surprises](#surprises).
- It stashes and restores prior state in `data-divi-original-preset-*` and
  `data-divi-preset-attributes-*`, and strips/reapplies classes, inline styles, and
  data-attributes carried by the preset.
- It dispatches `ETDiviInteractionsPresetAfterHandle` on `window` and applies the
  `divi.interactions.preset.afterHandle` filter inside the builder, so other code can
  react to a preset swap.

### scrollToElement

`target.scrollIntoView({behavior:"smooth", block:"start", inline:"nearest"})`. The
decomposed verb is ignored.

### mirrorMouseMovement

Tracks cursor offset from the **trigger** element and applies a transform to the
**target**, scaled by `sensitivity` (0–100, default 50) with the shape chosen by
[`mouseMovementType`](#mousemovementtype). On `mouseleave` it animates back to the
element's original transform over 0.3 s. Unlike every other effect it wires up on
`mouseEnter` immediately rather than waiting for a discrete event.
````

- [ ] **Step 4: Confirm the section landed**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
grep -n "^## Per-effect mechanics\|^### Visibility\|^### Preset" skills/divi-5-builder/references/interactions.md
```

Expected: three lines.

- [ ] **Step 5: Commit**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
git add skills/divi-5-builder/references/interactions.md
git commit -m "docs(#64): document per-effect runtime mechanics

Visibility carries real machinery beyond a display flip — it pauses video,
strips and restores iframe autoplay, and recalculates slider/carousel
heights on show, with a 50ms dedupe. Cookie effects use a 365-day expiry.
Preset effects are stateful rather than a class toggle: they stash prior
state in data-divi-original-preset-* and dispatch an event on swap."
```

---

### Task 5: Surprises catalog

**Files:**
- Modify: `skills/divi-5-builder/references/interactions.md` (append)

**Interfaces:**
- Produces: anchor `#surprises`, linked from Tasks 2 and 4.

- [ ] **Step 1: Verify the breakpoint order values and comparison**

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
grep -o 'order:50,name:"desktop"' "$DIVI/visual-builder/build/script-library-interactions.js"
grep -o 'order:30,name:"tablet"' "$DIVI/visual-builder/build/script-library-interactions.js"
grep -o 'order:10,name:"phone"' "$DIVI/visual-builder/build/script-library-interactions.js"
```

Expected: all three print. (If the literal spacing differs, widen with `grep -o 'order:[0-9]*,name:"[a-z]*"'`.)

- [ ] **Step 2: Verify attribute-name sanitization and the guard conditions**

```bash
DIVI="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5"
grep -o 'a-z0-9\\-_:' "$DIVI/visual-builder/build/script-library-interactions.js" | head -2
grep -o 'substring(0,200)' "$DIVI/visual-builder/build/script-library-interactions.js" | head -2
grep -o 'typeof IntersectionObserver' "$DIVI/visual-builder/build/script-library-interactions.js" | head -2
grep -o 'skipLoadTriggerExecution' "$DIVI/visual-builder/build/script-library-interactions.js" | head -2
grep -o 'et_pb_accordion_item_expand' "$DIVI/visual-builder/build/script-library-interactions.js" | head -2
```

Expected: all five print at least once.

- [ ] **Step 3: Append the surprises catalog**

Append to `skills/divi-5-builder/references/interactions.md`:

````markdown
## Surprises

Everything here is a **silent** failure or a counter-intuitive behavior — nothing in
this list throws, warns, or logs. All `<!-- UNVERIFIED -->`.

**1. Missing marker attributes kill the interaction.** The single most common cause of
"my interaction does nothing." See [Marker attributes](#marker-attributes-the-part-that-silently-breaks).

**2. Desktop-only.** `tablet`/`phone` siblings under
`module.decoration.interactions` are read by nothing.

**3. `removeVisibility` is labeled "Hide Element."** Every other add/remove pair is
labeled literally ("Add Preset"/"Remove Preset"). Visibility's two invert: `addVisibility`
= "Show Element", `removeVisibility` = "Hide Element". Translating "hide this on click"
into `addVisibility` because it reads like adding a hide is exactly backwards.

**4. An unknown effect noun no-ops; an unknown verb falls back to toggle.** Two
different outcomes from one typo, depending on which half you got wrong. See
[`effect`](#effect).

**5. Preset effects derive the module name from the target's own classes.** The
runtime scans the target's `et_pb_*` classes for one ending in a UUID
(`_[a-f0-9]{8}-…-[a-f0-9]{12}`) or a numeric id (`_\d+`, optionally
`_tb_header`/`_tb_body`/`_tb_footer`), strips the suffix, and rewrites
`et_pb_<name>` → `divi/<name>` → kebab-case. **If neither pattern matches, the whole
effect silently returns.** A target whose classes were customized away from Divi's
generated shape will never receive a preset swap.

**6. Breakpoint triggers compare *order*, not name.** Breakpoints carry numeric
orders — desktop 50, tablet 30, phone 10 — and the comparison is `<=` for
below-desktop targets. So `breakpointEnter` with `breakpointName: "tablet"` **also
fires on phone**, because phone's 10 is `<=` tablet's 30. It is "tablet and narrower,"
not "tablet exactly."

**7. Empty `attributeName`/`attributeValue` or `cookieName`/`cookieValue` no-op.** The
runtime requires both halves truthy. An empty string does **not** mean "remove the
attribute/cookie" — it means nothing happens at all.

**8. Attribute names are sanitized, not used literally.** The name is lowercased,
stripped of anything outside `[a-z0-9\-_:]`, truncated at 200 characters, and prefixed
with `data-` if the result does not start with a letter, underscore, or colon.
Authoring `Foo Bar` does not set `Foo Bar`.

**9. Multiple targets: loop-item narrowing.** When several elements match
`targetClass`, the runtime narrows to the one sharing the trigger's `data-loop-item`
value. Inside a loop this is what makes per-item interactions work. **Outside a loop,
all matches receive the effect** — a `targetClass` that matches more than one element
fans out.

**10. `viewportEnter`/`viewportExit` require `IntersectionObserver`.** The runtime
returns early when it is undefined, so both triggers no-op entirely.

**11. `load` does not always fire on initialization.** It is skipped when
`skipLoadTriggerExecution` is set, which the VB does on remount and view-state
restore — so a load-triggered interaction can appear not to work while you are editing.

**12. Accordion's click-to-expand runs through this same runtime.** The `click` handler
special-cases `.et_pb_accordion_item`: when the click did not land on the toggle title
and the item is not already open, it calls `window.et_pb_accordion_item_expand()` and
dispatches a `divi:accordion:open` event. Interactions on or inside an Accordion share
machinery with the Accordion's own behavior. (Related: Accordion's structural
requirements are documented in
[module-formats.md → Exceptions Quick Reference](module-formats.md#exceptions-quick-reference).)

**13. `enableInteraction: "off"` does not disable a front-end interaction.** See
[Attribute shape](#attribute-shape).
````

- [ ] **Step 4: Confirm all 13 entries are present**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
awk '/^## Surprises/,/^## /' skills/divi-5-builder/references/interactions.md | grep -c "^\*\*[0-9]"
```

Expected: `13`.

- [ ] **Step 5: Commit**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
git add skills/divi-5-builder/references/interactions.md
git commit -m "docs(#64): catalog 13 silent-failure modes in Interactions

None of these throw, warn, or log — which is the whole reason to write
them down. Highlights: breakpoint triggers compare numeric order rather
than name, so 'enter tablet' also fires on phone; attribute names are
sanitized rather than used literally; empty attribute/cookie values no-op
instead of removing; and outside a loop a targetClass matching several
elements fans the effect out to all of them."
```

---

### Task 6: Copy-paste fragments

**Files:**
- Modify: `skills/divi-5-builder/references/interactions.md` (append)

**Interfaces:**
- Consumes: every prior section.
- Produces: anchor `#copy-paste-fragments`.

Each fragment must include the marker attrs, or it would demonstrate the exact silent
failure Task 3 documents.

- [ ] **Step 1: Append the fragments section**

Append to `skills/divi-5-builder/references/interactions.md`:

````markdown
## Copy-paste fragments

Each fragment is complete and includes the marker attributes. Replace the `abc123` /
`xyz789` ids with your own (any `[a-zA-Z0-9]` string), keeping the `triggerClass` /
`targetClass` suffixes matched to them.

### Click to show/hide a panel

Trigger module:

```json
{
  "module": {
    "decoration": {
      "interactionTrigger": "abc123",
      "interactions": {
        "desktop": {
          "value": {
            "interactions": [
              {
                "id": "toggle-panel",
                "triggerClass": "et-interaction-trigger-abc123",
                "trigger": "click",
                "effect": "toggleVisibility",
                "target": {
                  "targetClass": "et-interaction-target-xyz789",
                  "targetType": "module",
                  "label": "Panel"
                },
                "timeDelay": "0ms"
              }
            ]
          }
        }
      }
    }
  }
}
```

Target module (**required** — no backfill):

```json
{"module": {"decoration": {"interactionTarget": "xyz789"}}}
```

### Swap a preset when the module scrolls into view

Self-targeting via `parentContainer` — no target marker needed:

```json
{
  "module": {
    "decoration": {
      "interactionTrigger": "def456",
      "interactions": {
        "desktop": {
          "value": {
            "interactions": [
              {
                "id": "scroll-preset",
                "triggerClass": "et-interaction-trigger-def456",
                "trigger": "viewportEnter",
                "effect": "addPreset",
                "presetId": "REPLACE_WITH_PRESET_UUID",
                "replaceExistingPreset": true,
                "target": {"targetClass": "", "targetType": "parentContainer", "label": "Parent"},
                "timeDelay": "150ms"
              }
            ]
          }
        }
      }
    }
  }
}
```

`presetId` must be a real preset UUID for the **target's own module type** — retrieve
one with `diviops_preset_audit` or `diviops_preset_inspect`. The preset effect derives
the module name from the target's classes, so a mismatched preset silently no-ops.

### Cursor-follow (mirror mouse movement)

```json
{
  "module": {
    "decoration": {
      "interactionTrigger": "ghi789",
      "interactions": {
        "desktop": {
          "value": {
            "interactions": [
              {
                "id": "cursor-follow",
                "triggerClass": "et-interaction-trigger-ghi789",
                "trigger": "mouseEnter",
                "effect": "mirrorMouseMovement",
                "sensitivity": 35,
                "mouseMovementType": "tilt",
                "target": {
                  "targetClass": "et-interaction-target-jkl012",
                  "targetType": "module",
                  "label": "Card"
                },
                "timeDelay": "0ms"
              }
            ]
          }
        }
      }
    }
  }
}
```

Target module: `{"module": {"decoration": {"interactionTarget": "jkl012"}}}`

### Verifying a write landed

`diviops_module_update` reports success on any well-formed dot-path write, including
one that will never fire. Read back and confirm **both** halves:

```
diviops_module_get   → trigger module carries module.decoration.interactionTrigger
                       AND the interactions[] entry
diviops_module_get   → target module carries module.decoration.interactionTarget
```

If the target marker is missing, the write "succeeded" and the interaction is dead.
````

- [ ] **Step 2: Verify every JSON fence in the whole file parses**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
python3 - <<'PY'
import json,re
c=open("skills/divi-5-builder/references/interactions.md",encoding="utf-8").read()
b=re.findall(r'```json\n(.*?)```', c, re.S)
for i,x in enumerate(b):
    try: json.loads(re.sub(r'^\s*//.*$','',x,flags=re.M))
    except Exception as e: print(f"FENCE {i} FAILED: {e}"); raise SystemExit(1)
print(f"all {len(b)} json fences parse OK")
PY
```

Expected: `all N json fences parse OK` with N ≥ 6.

- [ ] **Step 3: Verify every internal anchor link resolves**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
python3 - <<'PY'
import re
c=open("skills/divi-5-builder/references/interactions.md",encoding="utf-8").read()
heads={re.sub(r'[^a-z0-9 -]','',h.lower()).replace(' ','-') for h in re.findall(r'^#{2,3} (.+)$',c,re.M)}
bad=[a for a in re.findall(r'\]\(#([a-z0-9-]+)\)',c) if a not in heads]
print("BROKEN ANCHORS:",bad) if bad else print("all internal anchors resolve")
PY
```

Expected: `all internal anchors resolve`.

- [ ] **Step 4: Commit**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
git add skills/divi-5-builder/references/interactions.md
git commit -m "docs(#64): add copy-paste Interactions fragments

Click-to-toggle, viewport-triggered preset swap, and cursor-follow. Every
fragment includes the marker attrs — a fragment without them would
demonstrate the exact silent failure this reference exists to prevent —
and the preset example uses parentContainer targeting to show the case
where no target marker is needed. Closes with a read-back procedure,
since module_update reports success on writes that can never fire."
```

---

### Task 7: Wire into navigation, record divergence, open the PR

**Files:**
- Modify: `skills/divi-5-builder/SKILL.md:29` (insert a row after)
- Modify: `skills/divi-5-builder/references/module-formats.md:115`
- Modify: `FORK.md` (fork-owned table, after the `advanced-attributes.md` row at `:93`)

**Interfaces:**
- Consumes: `skills/divi-5-builder/references/interactions.md` complete from Tasks 1–6.

- [ ] **Step 1: Add the SKILL.md routing row**

In `skills/divi-5-builder/SKILL.md`, immediately after the `advanced-attributes.md`
row (line 29), insert:

```markdown
| Interactions (click/hover/viewport/breakpoint triggers, show-hide, preset swaps) | [interactions.md](references/interactions.md) |
```

- [ ] **Step 2: Repoint the module-formats.md stub row**

In `skills/divi-5-builder/references/module-formats.md`, replace line 115 entirely:

```markdown
| `interactions` | ✅ Verified | VB roundtrip confirmed. `module.decoration.interactions.desktop.value.interactions[]` + `interactionTrigger`/`interactionTarget` markers. **Full reference: [interactions.md](interactions.md)** — the markers are mandatory and the target side does not backfill; `interactions[]` alone silently does nothing. |
```

- [ ] **Step 3: Add the FORK.md divergence entry**

In `FORK.md`'s **fork-owned files** table, immediately after the
`advanced-attributes.md` row, insert:

```markdown
| `skills/divi-5-builder/references/interactions.md` | Clean-room reference for Divi 5's Interactions system — the `module.decoration.interactions` trigger/effect engine — documenting the payload shape and every PHP default, the four enums (`trigger` 8, `effect` 14, `targetType` 4, `mouseMovementType` 5), the mandatory `interactionTrigger`/`interactionTarget` marker attributes and their asymmetric backfill behavior, per-effect runtime mechanics, 13 silent-failure modes, and copy-paste fragments. Authored clean-room from Divi's own source only — `server/Packages/Module/Options/Interactions/*.php`, `server/Packages/Module/Module.php`, and the compiled front-end runtime `visual-builder/build/script-library-interactions.js`, cross-referenced against the VB option maps in `field-library.js`/`modal-library.js`; `diviops-agent-pro` was never opened while authoring it (#64). Claims are labeled `<!-- UNVERIFIED -->` per `SKILL.md`'s existing convention — read from source, not observed live, because Interactions are a client-side JS runtime that the PHP-render check used elsewhere in this skill cannot observe. Documented against Divi 5.9.0. Ships the Interactions half of #64; the `$variable()` per-module binding half remains open |
```

- [ ] **Step 4: Verify all three edits landed and no links are broken**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
grep -c "interactions.md" skills/divi-5-builder/SKILL.md skills/divi-5-builder/references/module-formats.md FORK.md
test -f skills/divi-5-builder/references/interactions.md && echo "deliverable exists"
python3 - <<'PY'
import re,os
for f in ["skills/divi-5-builder/SKILL.md","skills/divi-5-builder/references/module-formats.md"]:
    base=os.path.dirname(f)
    for link in re.findall(r'\]\((?!http)([^)#]+\.md)', open(f,encoding="utf-8").read()):
        p=os.path.normpath(os.path.join(base,link))
        if not os.path.exists(p): print(f"BROKEN in {f}: {link}")
print("relative link check done")
PY
```

Expected: each file reports ≥1; `deliverable exists`; no `BROKEN` lines.

- [ ] **Step 5: Run the repo test suite (must stay green)**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
php tests/run.php 2>&1 | tail -3
```

Expected: `PASS` with a non-zero assertion count. Docs-only change — any failure here is unrelated to this work and must be investigated before proceeding, not waved through.

- [ ] **Step 6: Commit**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
git add skills/divi-5-builder/SKILL.md skills/divi-5-builder/references/module-formats.md FORK.md
git commit -m "docs(#64): wire Interactions reference into skill navigation

Adds the SKILL.md routing row, repoints module-formats.md's one-line
interactions stub at the full reference (that row already named the
marker attrs but gave an author nothing actionable about them), and
records the FORK.md divergence entry for the new fork-owned file."
```

- [ ] **Step 7: Push and open the PR**

```bash
cd /Users/daxdavis/Developer/github.com/rubicon/diviops
git push
gh pr create --repo rubicon/diviops --base main --head dev/64-interactions-reference \
  --title "docs(#64): clean-room Divi 5 Interactions reference" \
  --body "Part of #64. Ships the Interactions half; the \$variable() per-module binding half remains open and #64 stays open with it.

## What this adds

\`skills/divi-5-builder/references/interactions.md\` — the fork's first real coverage of Divi 5's Interactions system, which previously had exactly one line in this skill (a single row in module-formats.md's verification table).

- Payload shape with every PHP default; interactions are **desktop-only**
- Four enums: \`trigger\` (8), \`effect\` (14), \`targetType\` (4), \`mouseMovementType\` (5) — trigger and effect enumerated independently from the runtime dispatch and the VB option maps, which agree exactly
- **Marker attributes** — the section that matters most. \`interactions[]\` alone does nothing; the runtime finds modules via \`data-interaction-trigger\`/\`data-interaction-target\` and returns before setup when the trigger marker is absent. The sides are asymmetric: the trigger backfills from \`interactions[0].triggerClass\`, the target does not backfill at all and must be authored on a different block
- Per-effect runtime mechanics, 13 silent-failure modes, copy-paste fragments

## Provenance (clean-room, #50)

Divi's own source only — \`server/Packages/Module/Options/Interactions/*.php\`, \`server/Packages/Module/Module.php\`, and the compiled runtime \`visual-builder/build/script-library-interactions.js\`, cross-referenced against the VB option maps. \`diviops-agent-pro\` was never opened.

## Verification posture

Claims are labeled \`<!-- UNVERIFIED -->\` per SKILL.md's existing three-tier convention — read from source, not observed live. Interactions are a client-side JS runtime, so the PHP-render check used for #63 cannot observe them. Conservative on purpose; every claim still carries a file + quoted-literal citation. Compiled JS is cited by quoted literal rather than byte offset or minified identifier, which change on every Divi rebuild.

## Design + review trail

Spec: \`docs/superpowers/specs/2026-07-30-interactions-reference-design.md\`. An adversarial review of the first draft caught four defects — a missing marker-attr requirement, a wrong \`enableInteraction\` claim that conflated the VB-side payload generator with the front-end runtime, an invented verification tier, and bad source paths — each re-verified against source before the fix was accepted.

## Test plan

- [x] Docs-only; \`php tests/run.php\` green
- [x] Every JSON fence parses
- [x] Every internal anchor and relative link resolves"
```

Expected: a PR URL. Confirm the body does **not** contain `Closes #64`.

---

## Self-Review

**Spec coverage** — each spec section maps to a task:

| Spec section | Task |
|---|---|
| Attribute shape + every key/default | 1 |
| Four enums | 2 |
| Marker attrs (the added surprise) | 3 |
| Per-effect mechanics | 4 |
| Surprises (all 13) | 5 |
| Copy-paste fragments | 6 |
| Cross-links + FORK.md | 7 |
| `enableInteraction` corrected, no browser test | 1 (documented in Attribute shape) |
| Verification posture = existing tiers, no invented one | Global Constraints + 1 |
| Compiled-JS citation policy | Global Constraints + 1 |
| PR must not say `Closes #64` | Global Constraints + 7 |

No spec requirement is unassigned.

**Placeholder scan:** no `TBD`/`TODO`/"add appropriate…"/"similar to Task N". Every
content step carries the literal Markdown to write; every verification step carries a
runnable command with an expected result. The one intentional placeholder token,
`REPLACE_WITH_PRESET_UUID`, is inside a copy-paste fragment where the reader must
supply their own value, and the surrounding prose says how to obtain it.

**Type/name consistency:** ids are used consistently across tasks — `abc123`/`xyz789`
in Tasks 3 and 6, `def456` and `ghi789`/`jkl012` unique to their own fragments.
`triggerClass` suffixes always match the `interactionTrigger` value; `targetClass`
suffixes always match `interactionTarget`. Anchor names used in cross-links
(`#marker-attributes-the-part-that-silently-breaks`, `#surprises`, `#effect`,
`#mousemovementtype`, `#attribute-shape`) match the headings that create them, and
Task 6 Step 3 verifies this mechanically rather than by eye.
