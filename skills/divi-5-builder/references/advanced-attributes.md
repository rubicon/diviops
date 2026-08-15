# Divi 5 Advanced Attributes — Shared Decoration System

Reference for the shared decoration families that live outside Tier 1's everyday
border/background/spacing set. Two groups, documented in the same shape
[module-formats.md](module-formats.md) uses for the common set:

- **The advanced `module.decoration.*` set** — box shadow, filters, transform, sticky,
  transition, scroll, animation.
- **Tier 2 element families** — button, font, text effects, text shadow, icon. These
  attach to an element rather than the module wrapper, so their paths start with the
  element name (`button.…`, `title.decoration.font.…`) rather than `module.`.

Every other shared family Divi ships is listed and classified in
[Shared family inventory](#shared-family-inventory-47-maps) below, so the ones that are
not documented here are visibly not documented rather than silently missing.

**Clean-room provenance**: every path and value shape below is derived from Divi 5's
own source — the shared `Module/Options/<Group>/<Group>PresetAttrsMap.php` classes, the
matching `StyleLibrary/Declarations/<Group>/<Group>.php` CSS-emission classes, and (for
enums/units not exposed in either) Divi's own compiled Visual Builder JS
(`visual-builder/build/*.js`) — cross-referenced against the repo's own extractors
(`scripts/extract-shared-preset-paths.php` for the Tier 2 families,
`scripts/extract-decoration-paths.php <builder5> --shared` for the seven advanced ones),
which read those same `PresetAttrsMap` classes programmatically.
None of this was read from, derived from, or cross-checked against
`diviops-agent-pro` — that plugin was never opened while authoring this file. See
`SKILL.md`'s [Verification convention](../SKILL.md#verification-convention) for what the
`*(verified …)*` / `*(VB-verified …)*` / `<!-- UNVERIFIED -->` tags below mean.

## How the shared families are structured

Divi splits every advanced decoration family into two layers:

1. **A shared subfield vocabulary**, defined once per family in
   `server/Packages/Module/Options/<Group>/<Group>PresetAttrsMap.php` via a static
   `get_map( string $attr_name )` method. That method returns an array keyed
   `"{$attr_name}__<subField>"` for every subfield the family exposes — e.g. BoxShadow's
   map yields `__style`, `__horizontal`, `__vertical`, `__blur`, `__spread`, `__color`,
   `__position` regardless of which element uses it.
2. **A per-element prefix**, supplied by the *module*, not the family. The module wraps
   the shared vocabulary with an element path, producing the final attr key:

   ```
   <elementPath>.decoration.<family>__<subField>
   ```

   `module.decoration.<family>` — the prefix used throughout this document — targets the
   **module wrapper** itself and is universal: every Divi 5 module has a `module` root
   element, so `module.decoration.boxShadow__*` and `module.decoration.filters__*` are
   valid on every module. Other elements get the *same* subfield vocabulary under a
   *different* prefix — `imageIcon.decoration.boxShadow__*` on Blurb's icon element,
   `content.decoration.filters__*` on Blurb's body text element, `button.decoration.boxShadow__*`
   on Button's button element, and so on. Enumerating those per-module element prefixes
   is out of scope here — that's a later, module-specific reference. This document only
   covers the `module.decoration.*` (module-wrapper) prefix.

**Why the shared Options classes, not any one module's `PresetAttrsMap.php`, are the
definition of record**: individual modules vary in how much of the shared vocabulary
they *register* for preset save/load, even when the attribute itself is declared
available. Text's `TextPresetAttrsMap.php` enumerates the full
`module.decoration.boxShadow__*` / `module.decoration.filters__*` set explicitly
(confirmed at `server/Packages/ModuleLibrary/Text/TextPresetAttrsMap.php:2096-2190`).
Blurb's `BlurbPresetAttrsMap.php` declares **none** of the `module.decoration.*` shared
families (confirmed: `extract-decoration-paths.php <builder5> Blurb` errors with "zero
decoration paths matched", by design — see `task-1-report.md`) — **but this is a
preset-map registration gap, not an attribute-availability gap.** Blurb's own
`module.json` still declares `attributes.module.settings.decoration.boxShadow` and
`.filters` as available option groups on its `module` element (confirmed: both keys
present, as empty `{}` placeholders, same as Text's). In other words: `module.decoration.boxShadow`
and `module.decoration.filters` are safe to send to a Blurb module — Blurb just doesn't
list their subfields in its own `PresetAttrsMap.php`, so its preset system won't
save/restore them by name the way it does for Text. Reading Text alone would
over-generalize the *registration* completeness; reading Blurb alone would miss the
subfield vocabulary entirely. The shared
`Module/Options/<Group>/<Group>PresetAttrsMap.php` classes are the one place that
vocabulary is guaranteed complete for every family, independent of which modules
register it for presets and under what prefix.

### Two shapes of family map — and why they have to be run, not read

The seven advanced families (Box Shadow through Entrance Animation, documented below)
are all *flat*: their `get_map()` writes out every key it contributes as a literal
`"{$attr_name}__<subField>"` string, so reading the strings out of the file gives the
right answer. That is what `scripts/extract-decoration-paths.php --shared` does, and it
stays correct for those seven.

The Tier 2 families are not flat. `ButtonPresetAttrsMap::get_map()` spells six of the
149 keys it contributes and gets the other 143 by calling seven sibling family maps
(`Background`, `Border`, `Font`, `Sizing`, `Spacing`, `BoxShadow`, `AttributesRel`),
none of whose keys appear anywhere in its own source. `FontPresetAttrsMap` does the same
with `TextEffects` and `TextShadow`. A text scan of either file reports a vocabulary
that is missing most of itself, so those families are resolved by *running* `get_map()`:

```bash
php scripts/extract-shared-preset-paths.php <builder5> --family Button --attr button
php scripts/extract-shared-preset-paths.php <builder5> --list
```

Families also differ in arity. Most take the element prefix they are keyed on as their
one argument. Three (`FieldDecoration`, `PositionSettings`, `VisibilitySettings`) take
none and return absolute `module.decoration.*` paths, because they serve exactly one
element. The extractor refuses a prefix for those rather than ignoring it.

### Shared family inventory (47 maps)

Every `Module/Options/<Group>/<Group>PresetAttrsMap.php` file Divi 5.11.0 ships, with the
key count `get_map()` actually resolves to and where (if anywhere) it is documented.
"Keys" is the resolved count under a single prefix, delegated keys included — counted by
`php scripts/extract-shared-preset-paths.php <builder5> --family <Name> --attr x`.

Status values: **documented** — full leaf-path depth in this file; **Tier 1 (group
level)** — [module-formats.md](module-formats.md) documents the family and its everyday
leaves, but not every leaf at this file's depth; **deferred** — a genuine decoration
family still undocumented at either depth; **not a decoration family** — content, query,
or behavior configuration, out of this document's scope.

| Family | Keys | Status | What it is |
|---|---|---|---|
| AdminLabel | 1 | not a decoration family | The builder-only label shown on the module in the VB. |
| Animation | 12 | documented — [Entrance Animation](#entrance-animation) | Entrance animation. |
| Attributes | 1 | not a decoration family | Arbitrary HTML attributes bag. |
| AttributesRel | 1 | documented — [Button](#button) | The `rel` list for a link; reached through Button. |
| Background | 50 | Tier 1 (group level) | Color, gradient, image, video, pattern, mask. |
| Border | 17 | Tier 1 (group level) | Radius and per-side width/style/color. |
| BoxShadow | 7 | documented — [Box Shadow](#box-shadow) | Drop and inner shadow. |
| Button | 149 | documented — [Button](#button) | A whole button element: its own icon group plus seven delegated families. |
| Conditions | 1 | not a decoration family | Display-conditions rule set. |
| DisabledOn | 1 | Tier 1 (group level) | Per-breakpoint visibility toggle. |
| Dividers | 12 | Tier 1 (group level) | Section top/bottom divider shapes. |
| Elements | 1 | not a decoration family | Which sub-elements a module renders (`structure`). |
| EmailService | 2 | not a decoration family | Email-provider account binding for opt-in modules. |
| FieldDecoration | 209 | deferred | Form-field styling, absolute-path variant of FormField. |
| Filters | 9 | documented — [Filters](#filters) | CSS filter stack plus blend mode. |
| Fit | 2 | deferred | `object-fit` / `object-position` for media. |
| Font | 44 | documented — [Font](#font) | Typography, plus delegated TextEffects and TextShadow. |
| FontBody | 246 | deferred | The body-text font group: body/link/ul/ol/quote/dropCap sub-elements, each a full Font family. |
| FontHeader | 264 | deferred | The heading font group: h1–h6, each a full Font family. |
| FormField | 178 | deferred | Form-field styling under an element prefix. |
| Gutter | 2 | deferred | Column gutter width and alignment. |
| Html | 3 | not a decoration family | Element tag plus raw before/after HTML. |
| Icon | 3 | documented — [Icon](#icon) | Icon color and custom size. |
| IdClasses | 2 | not a decoration family | CSS id and class attributes. |
| Image | 55 | deferred | An image element: border, box shadow, filters, spacing, sizing. |
| Interactions | 1 | not a decoration family | The interactions rule set. |
| Layout | 25 | deferred | Flex and grid container layout. |
| LinkUtils | 2 | not a decoration family | Module-level link url and target. |
| Loop | 14 | not a decoration family | Query-loop configuration. |
| Meta | 3 | not a decoration family | Admin label, force-visible, ToC heading. |
| NativeChoice | 1 | deferred | Accent color for native radio/checkbox controls. Added in Divi 5.11.0; the **third** map in `Module/Options/FormField/`, alongside FormField and FieldDecoration. |
| Overflow | 2 | Tier 1 (group level) | `overflow-x` / `overflow-y`. |
| Position | 6 | deferred | Positioning mode, origin, offset. |
| PositionSettings | 7 | deferred | Absolute-path variant of Position for the module wrapper. |
| Scroll | 13 | documented — [Scroll Effects](#scroll-effects) | Scroll-triggered motion effects. |
| ScrollSettings | 20 | deferred | Absolute-path variant of Scroll plus its `.enable` siblings. |
| Sizing | 20 | Tier 1 (group level) | Width, height, alignment, flex and grid placement. |
| Spacing | 2 | Tier 1 (group level) | Margin and padding. |
| SpamProtection | 5 | not a decoration family | Captcha provider binding. |
| Sticky | 7 | documented — [Sticky Position](#sticky-position) | Sticky positioning. |
| Text | 7 | deferred | Module text color and orientation, plus a text shadow. |
| TextEffects | 19 | documented — [Text Effects](#text-effects) | Gradient, image fill, and stroke on text. |
| TextShadow | 5 | documented — [Text Shadow](#text-shadow) | Text shadow presets. |
| Transform | 5 | documented — [Transform](#transform) | Scale, translate, rotate, skew, origin. |
| Transition | 3 | documented — [Transition](#transition) | State-change timing. |
| VisibilitySettings | 3 | Tier 1 (group level) | Absolute-path pairing of DisabledOn and Overflow. |
| ZIndex | 1 | Tier 1 (group level) | Stacking order. |

Counts and family names produced by
`php scripts/extract-shared-preset-paths.php <builder5> --list` plus one
`--family <Name>` run each; 47 files, matching the count asserted in
`tests/test-shared-preset-attrs-map.php`.

Family maps are **parameterized by the element prefix**, so the inventory's key names are
templates rather than literal paths. NativeChoice, for example, resolves to
`<attrName>.decoration.accentColor` — it becomes `field.decoration.accentColor` only when
the module supplies `field` as the prefix. Reading a family's key as a fixed path is the
same mistake as reading a map file instead of running it (see
[Two shapes of family map](#two-shapes-of-family-map--and-why-they-have-to-be-run-not-read)).

**Why there are far fewer maps than modules.** Divi 5.11.0 ships **90** module directories
under `server/Packages/ModuleLibrary/` but only **65** `*PresetAttrsMap.php` files there.
The 25-module gap is not drift and not missing coverage — those modules declare no
per-module preset vocabulary and compose entirely from the 47 shared families above. The
six modules added since 5.9.0 (Charts, Gravity Forms, Payment Button, Post Filter, Post
Filter Item, Imagely Gallery) are all in that group. Counting ModuleLibrary directories
against map files and expecting agreement will keep reproducing a phantom 25-module
discrepancy; they measure different things.

---

## Box Shadow

7 subfields, from `module.decoration.boxShadow__{style,horizontal,vertical,blur,spread,color,position}`
*(VB-verified 2026-07-28)*.

| Path | Value shape | Notes |
|---|---|---|
| `module.decoration.boxShadow__style` | enum: `"none"` \| `"preset1"`–`"preset7"` | Selects one of 7 built-in shadow presets (offset/blur/spread/color bundles) or disables the shadow. There is no literal `"custom"` value — a custom shadow is authored by setting `style` to a preset and then overriding individual subfields (`horizontal`/`vertical`/`blur`/`spread`/`color`/`position`), which take precedence over the preset's own values. Missing/omitted key ≠ explicit `"none"`: `BoxShadow::value()` in the StyleLibrary declaration distinguishes "key absent" (let preset CSS apply) from "key present and `none`" (explicit override to no shadow). **No hover, no responsive override**: the VB field config for `style` sets `features:{hover:false,sticky:false,responsive:false}` — it's a single value for the whole module, not per-breakpoint or per-state. |
| `module.decoration.boxShadow__horizontal` | length, e.g. `"0px"` | Horizontal offset. Presets use `px`; not unit-restricted. |
| `module.decoration.boxShadow__vertical` | length, e.g. `"2px"` | Vertical offset. |
| `module.decoration.boxShadow__blur` | length, e.g. `"18px"` | Blur radius. |
| `module.decoration.boxShadow__spread` | length, e.g. `"0px"` or negative (`"-6px"`) | Spread radius; negative values are used by some built-in presets (preset3). |
| `module.decoration.boxShadow__color` | color string, e.g. `"rgba(0,0,0,0.3)"` or hex | Any CSS color syntax Divi accepts elsewhere (hex, rgba, hsl, `$variable()$` global color token). |
| `module.decoration.boxShadow__position` | enum: `"outer"` \| `"inner"` | `"inner"` emits CSS `inset`; `"outer"` (default) is a normal drop shadow. **Do not confuse with the separate `module.decoration.position` family** (absolute/relative/fixed positioning + origin/offset) — this `position` subfield only controls inner-vs-outer shadow rendering. **No hover**: the VB field config sets `features:{hover:false,sticky:false}` (responsive is still allowed). |

The 7 built-in presets and their subfield bundles (from
`StyleLibrary/Declarations/BoxShadow/BoxShadow.php`'s `$_presets`, all `position: "outer"`
except preset6/preset7 which are `"inner"`):

| Preset | horizontal | vertical | blur | spread | position |
|---|---|---|---|---|---|
| preset1 | `0px` | `2px` | `18px` | `0px` | outer |
| preset2 | `6px` | `6px` | `18px` | `0px` | outer |
| preset3 | `0px` | `12px` | `18px` | `-6px` | outer |
| preset4 | `10px` | `10px` | `0px` | `0px` | outer |
| preset5 | `0px` | `6px` | `0px` | `10px` | outer |
| preset6 | `0px` | `0px` | `18px` | `0px` | inner |
| preset7 | `10px` | `10px` | `0px` | `0px` | inner |

All presets default `color: "rgba(0,0,0,0.3)"`.

**Responsive + hover**: standard shape — `tablet`/`phone` siblings of `desktop` for
responsive overrides (phone inherits tablet, tablet inherits desktop); hover values go
under `desktop.hover` (a sibling of `desktop.value`), never as a top-level `hover` key.
**Exceptions**: `style` supports neither hover nor responsive (see table above);
`position` supports responsive but not hover. The remaining subfields
(`horizontal`/`vertical`/`blur`/`spread`/`color`) support both. Where hover is
supported, inheritance is handled by `ModuleUtils::use_attr_value()` (called with
`mode: 'getAndInheritAll'` from `BoxShadowStyle.php:218-225`) — **not** by
`StyleDeclarations` (`server/Packages/StyleLibrary/Utils/StyleDeclarations.php` contains
no inheritance logic at all; it only assembles the final CSS declaration string/array).
A partial hover override (e.g. hover-only `color`) resolves the rest of the shadow from
`value` via that same utility.

Minimal copy-paste `attrs` fragment (custom shadow, hover color swap):

```json
{
  "module": {
    "decoration": {
      "boxShadow": {
        "desktop": {
          "value": {
            "style": "preset1",
            "horizontal": "0px",
            "vertical": "4px",
            "blur": "12px",
            "spread": "0px",
            "color": "rgba(15,23,42,0.35)",
            "position": "outer"
          },
          "hover": {"color": "rgba(99,102,241,0.45)"}
        }
      }
    }
  }
}
```

**Provenance**: shared subfield vocabulary from
`server/Packages/Module/Options/BoxShadow/BoxShadowPresetAttrsMap.php` (`get_map()`);
CSS emission (units, preset table, inner/outer → `inset`) from
`server/Packages/StyleLibrary/Declarations/BoxShadow/BoxShadow.php`; hover/responsive
inheritance mechanism from `server/Packages/Module/Options/BoxShadow/BoxShadowStyle.php:218-225`
(`ModuleUtils::use_attr_value( mode: 'getAndInheritAll' )`); per-subfield hover/responsive
`features` flags (`style`: none of either, `position`: no hover) from the VB field
definitions in compiled `visual-builder/build/module.js`; `module.decoration.*` prefix
cross-checked against `server/Packages/ModuleLibrary/Text/TextPresetAttrsMap.php:2096-2145`;
`style` enum values (`none`, `preset1`–`preset7`) confirmed against the VB's
`divi.module.options.boxShadow.styleOptions` filter default and preset label strings in
compiled `visual-builder/build/module.js`. Path list cross-verified against
`php scripts/extract-decoration-paths.php <builder5> --shared` (see verification below).
**VB round-trip** (2026-07-28): all 7 subfields plus a `desktop.hover.color` override
written via `diviops_module_update` to a scratch Text module (page 900587, trashed
after) at the exact `module.decoration.boxShadow.desktop.value.*` / `.hover.color`
paths shown in this section's copy-paste fragment; `diviops_module_get` and an
independent raw `wp post get --field=post_content` read both returned the identical
serialized shape — no rewrite, no dropped keys. Matches the documented path exactly.

---

## Filters

9 subfields, from `module.decoration.filters__{blur,brightness,contrast,hueRotate,invert,opacity,saturate,sepia,blendMode}`
*(VB-verified 2026-07-29)*.

`blur`/`brightness`/`contrast`/`hueRotate`/`invert`/`opacity`/`saturate`/`sepia` compose
into a single CSS `filter` shorthand; `blendMode` is emitted as the separate CSS
property `mix-blend-mode` (confirmed in
`StyleLibrary/Declarations/Filters/Filters.php::style_declaration()` — `blendMode` is
excluded from the `filter:` value-building loop and added via its own
`$style_declarations->add( 'mix-blend-mode', ... )` call). All 9 still share the same
`module.decoration.filters__*` subfield map and the same responsive/hover shape.

The "Default" column below is the VB **control** default (`defaultAttr` shown when a
user opens the field with no value set) — not a fallback value the renderer substitutes
when the key is omitted. `Filters::value()` skips any subfield that isn't `isset()` on
the attr array entirely, so an omitted `saturate` key emits no `saturate(...)` term in
the `filter:` shorthand at all, rather than emitting `saturate(100%)`. Writing the
default explicitly and omitting the key are only equivalent for the *rendered CSS
output* in the specific case where the browser's own initial value for that filter
function matches Divi's control default (true for all 8 numeric filter functions here).

| Path | Value shape | VB control default | Notes |
|---|---|---|---|
| `module.decoration.filters__blur` | length, e.g. `"4px"` | `"0px"` | CSS `blur()` — length unit (`px`), NOT a percentage. |
| `module.decoration.filters__brightness` | percentage, e.g. `"120%"` | `"100%"` | `100%` = unchanged; below dims, above brightens. |
| `module.decoration.filters__contrast` | percentage, e.g. `"110%"` | `"100%"` | `100%` = unchanged. |
| `module.decoration.filters__hueRotate` | angle, e.g. `"45deg"` | `"0deg"` | **Degrees, not percent** — the one filter subfield with an angle unit. |
| `module.decoration.filters__invert` | percentage, e.g. `"100%"` | `"0%"` | `100%` = fully inverted colors. |
| `module.decoration.filters__opacity` | percentage, e.g. `"80%"` | `"100%"` | This is the CSS *filter* `opacity()` function (part of the `filter:` shorthand), not the module's own opacity/background-opacity setting. |
| `module.decoration.filters__saturate` | percentage, e.g. `"150%"` | `"100%"` | `100%` = unchanged; `0%` = grayscale. |
| `module.decoration.filters__sepia` | percentage, e.g. `"60%"` | `"0%"` | `100%` = full sepia tone. |
| `module.decoration.filters__blendMode` | enum (CSS `mix-blend-mode` keyword) | `"normal"` | `normal`, `multiply`, `screen`, `overlay`, `darken`, `lighten`, `color-dodge`, `color-burn`, `hard-light`, `soft-light`, `difference`, `exclusion`, `hue`, `saturation`, `color`, `luminosity`. This is Divi's own first-party option list for the field (a 16-entry labeled map — "Normal", "Multiply", "Screen", … — wired directly to the `blendMode` select in the Filters group definition in compiled `visual-builder/build/module.js`), not a generic CSS reference table; it happens to equal the full CSS `mix-blend-mode` keyword set. **No hover, no sticky**: the VB field config sets `features:{hover:false,sticky:false}` for this subfield specifically — the other 8 filter subfields don't carry that restriction. |

**Units at a glance**: `blur` = length (`px`); `hueRotate` = angle (`deg`); everything
else (`brightness`, `contrast`, `invert`, `opacity`, `saturate`, `sepia`) = percentage
(`%`); `blendMode` = keyword enum, not a numeric unit at all.

**Responsive + hover**: same shape as Box Shadow — `tablet`/`phone` siblings of
`desktop`, hover under `desktop.hover`. **Exception**: `blendMode` supports neither
hover nor sticky (see table above); the other 8 subfields support both. Filters' style
declaration explicitly threads breakpoint/state through
`ModuleUtils::use_attr_value(... mode: 'getAndInheritAll')` (`Filters.php:118-126`,
same utility and mode as Box Shadow — not `StyleDeclarations`, which has no inheritance
logic), so a hover-only override of one subfield (e.g. hover-only `saturate`) correctly
inherits the rest of the filter stack from `value` rather than resetting it.

Minimal copy-paste `attrs` fragment (desaturate + brighten on hover):

```json
{
  "module": {
    "decoration": {
      "filters": {
        "desktop": {
          "value": {
            "saturate": "40%",
            "brightness": "105%",
            "blendMode": "normal"
          },
          "hover": {"saturate": "100%", "brightness": "115%"}
        }
      }
    }
  }
}
```

**Provenance**: shared subfield vocabulary from
`server/Packages/Module/Options/Filters/FiltersPresetAttrsMap.php` (`get_map()`); CSS
emission (`filter:` shorthand composition — including the isset-only iteration that
skips omitted subfields — and the `mix-blend-mode` split-out) and the
`ModuleUtils::use_attr_value()` inheritance call from
`server/Packages/StyleLibrary/Declarations/Filters/Filters.php:35-72` (value-building
loop) and `:97-146` (`style_declaration()`, the inheritance call at `:118-126`); VB
control-default values and units (`hueRotate: "0deg"`, `blur: "0px"`, all others `%`,
`blendMode: "normal"`) confirmed against the defaults object in compiled
`visual-builder/build/module.js`; `blendMode`'s first-party option map (the 16-entry
labeled list wired to its `divi/select` field, matching the CSS `mix-blend-mode`
keyword set) and its `hover:false,sticky:false` field-feature flags both confirmed in
the same compiled `visual-builder/build/module.js`; `module.decoration.*` prefix
cross-checked against `server/Packages/ModuleLibrary/Text/TextPresetAttrsMap.php:2146-2190`.
Path list cross-verified against `php scripts/extract-decoration-paths.php <builder5> --shared`
(see verification below).
**VB round-trip** (2026-07-29, via the MCP write path, not a literal browser Visual
Builder session): all 9 `desktop.value.*` subfields (`blur`, `brightness`, `contrast`,
`hueRotate`, `invert`, `opacity`, `saturate`, `sepia`, `blendMode`) plus a
`desktop.hover.{saturate,brightness}` override written via `diviops_module_update` to
a scratch Text module (page 900592, trashed after); `diviops_module_get` and an
independent raw `wp post get --field=post_content` read both returned the identical
serialized shape — no rewrite, no dropped keys, no renamed keys. Matches every
documented path exactly.

---

## Path verification

Every path documented in all seven family sections below was checked against the
authoritative extractor output (`php scripts/extract-decoration-paths.php <builder5>
--shared`, which reads the `Module/Options/<Group>/<Group>PresetAttrsMap.php` classes
directly). This is the single consolidated verification for the whole document — each
family section's own Provenance paragraph points back here rather than repeating the
extractor output inline.

The extractor's own raw output groups each family under a `## <family> (N)` markdown
header (that's its native output format, meant for pasting straight into a doc). Inside
this document those header lines are reformatted to `<family> (N):` below so they don't
themselves read as `##` document headings; the family name, path count, and every path
are otherwise reproduced verbatim:

```
$ php scripts/extract-decoration-paths.php <builder5> --shared
boxShadow (7):
  module.decoration.boxShadow__blur
  module.decoration.boxShadow__color
  module.decoration.boxShadow__horizontal
  module.decoration.boxShadow__position
  module.decoration.boxShadow__spread
  module.decoration.boxShadow__style
  module.decoration.boxShadow__vertical
filters (9):
  module.decoration.filters__blendMode
  module.decoration.filters__blur
  module.decoration.filters__brightness
  module.decoration.filters__contrast
  module.decoration.filters__hueRotate
  module.decoration.filters__invert
  module.decoration.filters__opacity
  module.decoration.filters__saturate
  module.decoration.filters__sepia
transform (5):
  module.decoration.transform__origin
  module.decoration.transform__rotate
  module.decoration.transform__scale
  module.decoration.transform__skew
  module.decoration.transform__translate
sticky (7):
  module.decoration.sticky__limit.bottom
  module.decoration.sticky__limit.top
  module.decoration.sticky__offset.bottom
  module.decoration.sticky__offset.surrounding
  module.decoration.sticky__offset.top
  module.decoration.sticky__position
  module.decoration.sticky__transition
transition (3):
  module.decoration.transition__delay
  module.decoration.transition__duration
  module.decoration.transition__speedCurve
scroll (13):
  module.decoration.scroll__blur
  module.decoration.scroll__blur.enable
  module.decoration.scroll__fade
  module.decoration.scroll__fade.enable
  module.decoration.scroll__horizontalMotion
  module.decoration.scroll__horizontalMotion.enable
  module.decoration.scroll__motionTriggerStart
  module.decoration.scroll__rotating
  module.decoration.scroll__rotating.enable
  module.decoration.scroll__scaling
  module.decoration.scroll__scaling.enable
  module.decoration.scroll__verticalMotion
  module.decoration.scroll__verticalMotion.enable
animation (12):
  module.decoration.animation__delay
  module.decoration.animation__direction
  module.decoration.animation__duration
  module.decoration.animation__intensity.flip
  module.decoration.animation__intensity.fold
  module.decoration.animation__intensity.roll
  module.decoration.animation__intensity.slide
  module.decoration.animation__intensity.zoom
  module.decoration.animation__repeat
  module.decoration.animation__speedCurve
  module.decoration.animation__startingOpacity
  module.decoration.animation__style
```

All 7 boxShadow, 9 filters, 5 transform, 7 sticky, 3 transition, 13 scroll, and 12
animation paths documented in this file — 56 total — appear in that output, none
invented. Cross-checked against the per-module extractor (`php
scripts/extract-decoration-paths.php <builder5> Text`), which additionally confirms
the `module.decoration.` prefix is real for every one of these seven families (Text's
own `TextPresetAttrsMap.php` declares the identical subfield sets under that exact
prefix — boxShadow/filters at `:2096-2190`, transform at `:2191-2215`, animation at
`:2216-2276`, transition at `:2478-2493`, scroll at `:2527-2591`, sticky at
`:2592-2626`).

---

## Transform

5 subfields, from `module.decoration.transform__{origin,rotate,scale,skew,translate}`
*(VB-verified 2026-07-28)*.

| Path | Value shape | Notes |
|---|---|---|
| `module.decoration.transform__scale` | object `{x, y}` percentages, e.g. `{"x":"110%","y":"110%"}` | VB control default `{x:"100%",y:"100%",linked:"on"}` — `100%`/`100%` = no scaling. The `linked` key only drives the VB's proportional x/y-lock UI convenience; `Transform::value()` reads only `x`/`y` and ignores `linked`, so it can be omitted from hand-authored attrs. |
| `module.decoration.transform__translate` | object `{x, y}` lengths, e.g. `{"x":"20px","y":"-10px"}` | VB control default `{x:"0px",y:"0px",linked:"on"}`. Any CSS length unit is accepted, not just `px`; `linked` is VB-UI-only, same as scale. |
| `module.decoration.transform__rotate` | object `{x, y, z}` angles, e.g. `{"z":"15deg"}` | VB control default `{x:"0deg",y:"0deg",z:"0deg"}`. Three axes: `x`/`y` are 3D flips, `z` is the familiar 2D spin. |
| `module.decoration.transform__skew` | object `{x, y}` angles, e.g. `{"x":"8deg","y":"0deg"}` | VB control default `{x:"0deg",y:"0deg",linked:"on"}`. `linked` is VB-UI-only, same as scale/translate. |
| `module.decoration.transform__origin` | object `{x, y}` percentages, e.g. `{"x":"50%","y":"50%"}` | VB control default `{x:"50%",y:"50%"}` (dead center). When `origin` is present but only one axis is set, `Transform::style_declaration()` merges the missing axis in from a server-side default of `{x:"50%",y:"50%"}` before emitting `transform-origin` — confirmed `TransformTraits/StyleDeclarationTrait.php:319-327`. |

**Composition**: unlike Box Shadow's independent subfields, `scale`/`translate`/`rotate`/`skew`
all compose into a single `transform:` CSS shorthand in a fixed order —
`scaleX() scaleY() translateX() translateY() rotateX() rotateY() rotateZ() skewX() skewY()`
— confirmed in `StyleLibrary/Declarations/Transform/TransformTraits/ValueTrait.php::value()`
(`:98-183`). Percentage strings for `scale` are converted to a decimal literal before being
embedded (`"110%"` → `scaleX(1.1)`) via `render_percentage()` (`:42-48`); `translate`/`rotate`/`skew`
values are emitted as literal strings with whatever unit was supplied, no conversion. If none
of the four transform-function subfields are present for a given breakpoint/state, no `transform`
property is emitted for that state at all (the caller's `Utils::style_statements` loop produces
nothing to print).

**Responsive + hover**: same tablet/phone-siblings-of-desktop responsive shape as Box Shadow and
Filters (shared `Utils::style_statements` breakpoint machinery —
`Module/Layout/Components/Style/Utils/UtilsTraits/GetStatementsTrait.php:323`); hover values live
under `desktop.hover`. **Unlike Box Shadow/Filters**, Transform's CSS emission is *not* built via
`ModuleUtils::use_attr_value(... 'mode' => 'getAndInheritAll')` — that call does not appear
anywhere under `Module/Options/Transform` or `StyleLibrary/Declarations/Transform` (confirmed by
grepping both directories for `getAndInheritAll`: zero matches). Hover behavior is instead
hand-rolled in `TransformTraits/StyleDeclarationTrait.php::style_declaration()`: when the hover
state carries no scale/translate/rotate/skew values of its own, the declaration explicitly emits
`transform: none` (`:311-316`) rather than falling back to the non-hover value — a hover-only
`attrs` fragment that sets nothing under `transform` therefore *resets* the transform on hover, it
does not inherit desktop's transform. The same trait reads an `$additional['normalStateOrigin']`
fallback for hover `origin` inheritance (`:254`) — and this is a genuine **VB-vs-PHP-front-end
divergence**, not dead code. In PHP, the only caller
(`Module/Options/Transform/TransformStyle.php::style()`) populates `$additional` with only
`positionAttrs`, never `normalStateOrigin`, so PHP front-end render never inherits hover `origin`
from the non-hover value — a hover state with no `origin` of its own renders with no
`transform-origin` override at all. The Visual Builder does wire it up: compiled
`visual-builder/build/module.js`'s hover-specific `declarationFunction` reads the non-hover origin
directly off attrs (`p?.[e.breakpoint]?.value?.origin`) and threads it through as
`normalStateOrigin`, and the JS mirror of `style_declaration()` in compiled
`visual-builder/build/style-library.js` consumes it (`e.origin||o?.normalStateOrigin`), so the VB
preview *does* fall back to the non-hover origin. Because PHP front-end render and the VB
disagree here, set `origin` explicitly under `hover` whenever the rendered page needs to match
what the Visual Builder previews.

Minimal copy-paste `attrs` fragment (scale + rotate, distinct hover transform):

```json
{
  "module": {
    "decoration": {
      "transform": {
        "desktop": {
          "value": {
            "scale": {"x": "100%", "y": "100%"},
            "rotate": {"z": "0deg"}
          },
          "hover": {
            "scale": {"x": "105%", "y": "105%"},
            "rotate": {"z": "3deg"}
          }
        }
      }
    }
  }
}
```

**Provenance**: shared subfield vocabulary from
`server/Packages/Module/Options/Transform/TransformPresetAttrsMap.php` (`get_map()`); CSS
emission (shorthand composition order, scale's percentage-to-decimal conversion,
transform-origin default-axis merge) from
`server/Packages/StyleLibrary/Declarations/Transform/TransformTraits/ValueTrait.php` and
`server/Packages/StyleLibrary/Declarations/Transform/TransformTraits/StyleDeclarationTrait.php`;
the hover-resets-to-`none` behavior is confirmed in the latter file. The VB-vs-PHP divergence in
hover-`origin` inheritance (PHP never populates `normalStateOrigin`; the VB does) is confirmed by
contrasting `server/Packages/Module/Options/Transform/TransformStyle.php::style()` against the
hover `declarationFunction` in compiled `visual-builder/build/module.js` and its JS-side consumer
in compiled `visual-builder/build/style-library.js`. VB control-default values (`{x:"100%",y:"100%",linked:"on"}` scale,
`{x:"0px",y:"0px",linked:"on"}` translate, `{x:"0deg",y:"0deg",z:"0deg"}` rotate,
`{x:"0deg",y:"0deg",linked:"on"}` skew, `{x:"50%",y:"50%"}` origin) and per-field labels,
placeholders, and `defaultUnit`s (`px` for translate, `deg` for rotate/skew) confirmed against the
`Bg` defaults object and the `divi/transform-scale` / `divi/transform-translate` /
`divi/transform-rotate` / `divi/transform-skew` / `divi/transform-origin` field definitions in
compiled `visual-builder/build/module.js`; `module.decoration.*` prefix and the full 5-subfield
set cross-checked against
`server/Packages/ModuleLibrary/Text/TextPresetAttrsMap.php:2191-2215`. Path list cross-verified
against `php scripts/extract-decoration-paths.php <builder5> --shared` (see the "Path
verification" section).
**VB round-trip** (2026-07-28): all 5 subfields (`scale`/`translate`/`rotate`/`skew`/`origin`)
plus a `desktop.hover.{scale,rotate}` override written via `diviops_module_update` to the
same scratch Text module (page 900587, trashed after) at the exact
`module.decoration.transform.desktop.value.*` / `.hover.*` object-with-`{x,y[,z]}` paths shown
in this section's copy-paste fragment; `diviops_module_get` and an independent raw
`wp post get --field=post_content` read both returned the identical serialized shape. Matches
the documented path exactly.

---

## Sticky Position

7 subfields (note the nested dot-paths — this is the one shared family whose subnames are
themselves paths, not flat identifiers), from `module.decoration.sticky__position`,
`sticky__offset.{top,bottom,surrounding}`, `sticky__limit.{top,bottom}`, `sticky__transition`
*(VB-verified 2026-07-28)*.

| Path | Value shape | Notes |
|---|---|---|
| `module.decoration.sticky__position` | enum: `"none"` \| `"top"` \| `"bottom"` \| `"topBottom"` | Default `"none"` (not sticky). VB option labels: "Do Not Stick" / "Stick to Top" / "Stick to Bottom" / "Stick to Top and Bottom" (compiled `visual-builder/build/module.js` option map). The three non-`"none"` values match `StickyUtils::get_valid_sticky_position()` exactly; `"none"` is the implicit off-state default, not part of that helper's return array. |
| `module.decoration.sticky__offset.top` | length, e.g. `"20px"` | Default `"0px"`. `divi/range` field — vertical offset from the top edge of the viewport while stuck. |
| `module.decoration.sticky__offset.bottom` | length, e.g. `"20px"` | Default `"0px"`. Same as above, from the bottom edge. |
| `module.decoration.sticky__offset.surrounding` | enum: `"on"` \| `"off"` | Default `"on"`. `divi/toggle` field. Governs whether this element's own sticky offset accounts for a neighboring sticky element already stuck above/below it, so stacked sticky elements don't overlap. |
| `module.decoration.sticky__limit.top` | enum: `"none"` \| `"body"` \| `"section"` \| `"row"` \| `"column"` | Default `"none"`. **Not a length**, despite the `"none"` default reading like an open-ended value — this is a `divi/select` of container-type keywords (confirmed option map in compiled `visual-builder/build/module.js`). It does not release the element from stickiness — it **re-anchors the sticky reference to that container's edge**: Divi's own field description reads *"If defined, this element will stick to the top of this container, overriding its stickiness edge of the browser"* (compiled `visual-builder/build/module.js`). |
| `module.decoration.sticky__limit.bottom` | enum: `"none"` \| `"body"` \| `"section"` \| `"row"` \| `"column"` | Default `"none"`. Same container-type enum, re-anchoring to that container's bottom edge instead: *"If defined, this element will stick to the bottom of this container, overriding its stickiness to the edge of the browser"* (compiled `visual-builder/build/module.js`). |
| `module.decoration.sticky__transition` | enum: `"on"` \| `"off"` | Default `"on"`. `divi/toggle` field. Animates the element's transition between its normal and stuck styles when it becomes (or stops being) sticky. |

Every default above does double duty: it's both the VB control's displayed default and the value
actually used when the attrs key is completely absent —
`StickyUtils::format_sticky_setting()` returns its `$default_value` argument outright whenever
`get_formatted_subname_values()` found no value at all for that subfield
(`StickyUtils.php:167-169`), and those `$default_value` arguments are exactly the `defaultAttr`
values shown in the table above (`StickyUtils.php:296-313`).

**Dot-paths, not double-underscores**: Sticky's `offset.*`/`limit.*` sub-paths use a literal dot
inside the `subName` (`offset.top`, never `offset__top`) — confirmed both server-side
(`StickyPresetAttrsMap.php::get_map()` `:39-63`) and in the VB field configs' own `subName` props
(e.g. `subName:"limit.bottom"`, compiled `visual-builder/build/module.js`). Do not flatten these
to a double-underscore form when authoring attrs.

**Mechanism — this family is not a CSS declaration at all**: there is no
`StyleLibrary/Declarations/Sticky` directory in this Divi build (confirmed: no such path exists
under `server/Packages/StyleLibrary/Declarations`). Instead, `module.decoration.sticky__*` values
are read by `StickyScriptData::set()`
(`server/Packages/Module/Options/Sticky/StickyScriptData.php:64-125`) into a runtime record
registered via `ScriptData::add_data_item(['data_name' => 'sticky', ...])` (`:118-124`), which
Divi's own compiled sticky JS (`visual-builder/build/script-library-sticky-elements.js`,
`script-library-utils-sticky.js`) reads to attach scroll listeners at runtime. There is no
`ModuleUtils::use_attr_value(... 'mode' => 'getAndInheritAll')` call anywhere under
`Module/Options/Sticky` either (confirmed by grep) — that mechanism, used by Box Shadow/Filters,
does not apply to this family.

**Responsive + hover**: no hover variant exists for any sticky subfield — every one is declared
with `features:{hover:false, sticky:false}` in its VB field config (confirmed for `position`,
`offsetTop`, `offsetBottom`, `limitTop`, `limitBottom`, `offsetSurrounding`, and `transition`,
compiled `visual-builder/build/module.js`), so there is no `desktop.hover` key to author for this
family. Responsive (tablet/phone) overrides, in contrast, ARE read and resolved — this is a
genuinely supported variant, not merely a stored-but-inert value:
`StickyUtils::get_formatted_subname_values()` walks every breakpoint key present under
`attrs.module.decoration.sticky` and returns a breakpoint-keyed value map
(`StickyUtils.php:120-127`), and `format_sticky_setting()` passes that map through to the runtime
sticky-setting payload as-is whenever more than one breakpoint is present, only collapsing to a
bare scalar when `desktop` is the sole key (`StickyUtils.php:167-173`). The hardcoded
`'breakpoint' => 'desktop', 'state' => 'value'` in `StickyScriptData.php:111-113` does **not**
gate this walk — it is consumed by the `affectingAttrs['position']` reads (the incompatible-position
guard at `:323-330`, plus the `position.origin` read at `:466-467` and the `position.offset` read
at `:484`), none of which gate the sticky-subfield walk at `:338-382`. At runtime, Divi's compiled
sticky JS resolves the correct breakpoint's value itself: the sticky store's `getSetting()`
(`visual-builder/build/script-library-sticky-elements.js`) implements the literal cascade —
`case"phone":return n?.phone??n?.tablet??n?.desktop??e;case"tablet":return n?.tablet??n?.desktop??e;default:return n?.desktop??e`
— gated on a `responsiveOptions` list (`["position","topOffset","bottomOffset","topLimit","bottomLimit","offsetSurrounding","transition","topOffsetModules","bottomOffsetModules"]`,
`visual-builder/build/script-library-stores-sticky.js`) that mostly, but not exactly,
overlaps the settings `StickyUtils::get_sticky_setting()` produces: 7 of the 9 entries
(`position`/`topOffset`/`bottomOffset`/`topLimit`/`bottomLimit`/`offsetSurrounding`/`transition`)
match that PHP method's own output keys one-for-one, but the remaining two,
`topOffsetModules`/`bottomOffsetModules`, are never produced by `get_sticky_setting()` at
all — they're computed entirely client-side by this same store's own
`generateOffsetModules()` (confirmed in `script-library-stores-sticky.js`), which measures
sibling sticky modules' rendered positions in the browser rather than reading anything
out of server-side attrs. That same sticky store's own `getProp()` (also
`visual-builder/build/script-library-stores-sticky.js`) is **not** an equivalent
breakpoint-keyed lookup: for a `responsiveOptions` entry it does a direct single-breakpoint
read — `_.get(o, currentBreakpoint, default)` — with no phone→tablet→desktop fallback
chain at all; a breakpoint with no explicit value returns the caller's plain default
straight away rather than inheriting from a larger breakpoint the way `getSetting()`'s
`??` chain does. Only `getSetting()` implements the cascade. Author tablet/phone sticky
overrides with confidence regardless — `getSetting()` is what the runtime actually calls
to resolve them, and they are read and resolved there, not inert.

Minimal copy-paste `attrs` fragment (stick to top, offset by 20px, re-anchored to its section's
bottom edge instead of the browser window):

```json
{
  "module": {
    "decoration": {
      "sticky": {
        "desktop": {
          "value": {
            "position": "top",
            "offset": {"top": "20px", "bottom": "0px", "surrounding": "on"},
            "limit": {"top": "none", "bottom": "section"},
            "transition": "on"
          }
        }
      }
    }
  }
}
```

**Provenance**: shared subfield vocabulary, including the literal `offset.top`/`limit.bottom`
dot-paths, from `server/Packages/Module/Options/Sticky/StickyPresetAttrsMap.php` (`get_map()`);
default values (`position:"none"`, `offset.top`/`offset.bottom:"0px"`, `offset.surrounding:"on"`,
`limit.top`/`limit.bottom:"none"`, `transition:"on"`) and the valid-position enum
(`top`/`bottom`/`topBottom`) confirmed in
`server/Packages/Module/Options/Sticky/StickyUtils.php` (`get_sticky_setting()`'s `defaultAttr`
at `:296-313`, `get_valid_sticky_position()` at `:509-515`); the runtime-not-CSS mechanism
confirmed in `server/Packages/Module/Options/Sticky/StickyScriptData.php`; `limit.*`'s
container-type enum (`none`/`body`/`section`/`row`/`column`), `offset.surrounding`'s and
`transition`'s toggle field type, the `position` enum's option labels, and every sticky
subfield's `hover:false` field flag are all confirmed in compiled
`visual-builder/build/module.js`. `module.decoration.*` prefix and the full 7-subfield set
cross-checked against `server/Packages/ModuleLibrary/Text/TextPresetAttrsMap.php:2592-2626`. Path
list cross-verified against `php scripts/extract-decoration-paths.php <builder5> --shared` (see
the "Path verification" section).
**VB round-trip** (2026-07-28): all 7 subfields (`position`, `offset.{top,bottom,surrounding}`,
`limit.{top,bottom}`, `transition`) written via `diviops_module_update` to the same scratch Text
module (page 900587, trashed after) at the exact `module.decoration.sticky.desktop.value.*`
dot-paths shown in this section's copy-paste fragment (including the literal `offset.top` /
`limit.bottom` inner dots, not double-underscores); `diviops_module_get` and an independent raw
`wp post get --field=post_content` read both returned the identical serialized shape. Matches
the documented path exactly.

---

## Transition

3 subfields, from `module.decoration.transition__{duration,delay,speedCurve}`
*(VB-verified 2026-07-28)*.

Unlike every other family in this document, Transition doesn't apply a visual style to
the module itself — it configures **how other families' hover/sticky/focus/active/checked
state changes animate**. `TransitionUtils::get_transition_states()` defines five
trigger states (`hover`, `sticky`, `focus`, `active`, `checked`), not just the
hover/sticky pair used elsewhere in this document. Divi auto-detects which CSS
properties actually have a state-specific value set across the module's other
decoration families and only then emits a `transition-property` /
`transition-duration` / `transition-timing-function` / `transition-delay` block for
those properties — confirmed in `TransitionStyle::style()`: it bails immediately if
`attrs` and `advancedStyles` are both empty (`:135-137`), and each candidate prop is
skipped unless `has_multi_state_attr()` finds a `hover`/`sticky`/`focus`/`active`/`checked`
key on it (`:218-220`). Setting `module.decoration.transition__*` alone, with no other
family carrying a state-specific value, emits no CSS at all.

| Path | Value shape | Notes |
|---|---|---|
| `module.decoration.transition__duration` | length (ms), e.g. `"500ms"` | VB `divi/range` field, `defaultUnit:"ms"`, `min:0`, `max:2000` (2s ceiling). Default `"300ms"` — confirmed both in the VB `defaultAttr` and in `TransitionStyle.php`'s `$duration_default_value` (`:143`, substituted via `??` for an absent/null key at `:164`); the CSS-emission layer additionally substitutes it for an explicit empty string (`StyleDeclarationTrait.php:69`), so absent, `null`, and `""` all resolve to `"300ms"`. |
| `module.decoration.transition__delay` | length (ms), e.g. `"0ms"` | VB `divi/range` field, `defaultUnit:"ms"`, `min:0`, `max:300` — **an order of magnitude lower ceiling than `duration`'s 2000ms**; don't assume the two range fields share limits. Default `"0ms"` (`TransitionStyle.php:144`, `??` fallback at `:165`; empty-string fallback at `StyleDeclarationTrait.php:70`). |
| `module.decoration.transition__speedCurve` | enum, **camelCase**: `"ease"` \| `"easeIn"` \| `"easeOut"` \| `"easeInOut"` \| `"linear"` | VB `divi/select` with these exact camelCase option keys (labels "Ease" / "Ease-In" / "Ease-Out" / "Ease-In-Out" / "Auto"). `Transition::style_declaration()` maps each camelCase value to the kebab-case CSS `transition-timing-function` keyword via an explicit switch (`easeInOut`→`ease-in-out`, `easeIn`→`ease-in`, `easeOut`→`ease-out`, `ease`/`linear` pass through); any unrecognized value — including an absent key — falls back to CSS `ease` (`TransitionStyleDeclarationTrait.php` → `StyleDeclarationTrait.php:80-98`). Default stored value is `"ease"` (`TransitionStyle.php:145`), which is coincidentally already valid, unmapped CSS. **Do not confuse with Animation's `speedCurve`, which is stored directly in kebab-case** — see the Entrance Animation section below. |

**Responsive, no hover**: `duration`/`delay`/`speedCurve` all declare `features:{hover:false,sticky:false}` in their VB field configs (compiled `visual-builder/build/module.js`) — meaningless to give the timing controls their own hover value, since they *drive* other properties' hover transitions. None of the three declares `responsive:false`, so tablet/phone breakpoint overrides are supported: `TransitionStyle::style()` builds `$transition_attr` from `array_keys($attr)` (`:157-169`), i.e. whatever breakpoints are actually present in the attrs.

Minimal copy-paste `attrs` fragment (paired with some other family's own hover value elsewhere on the module — Transition alone renders no CSS):

```json
{
  "module": {
    "decoration": {
      "transition": {
        "desktop": {
          "value": {
            "duration": "500ms",
            "delay": "0ms",
            "speedCurve": "easeInOut"
          }
        }
      }
    }
  }
}
```

**Provenance**: shared subfield vocabulary from
`server/Packages/Module/Options/Transition/TransitionPresetAttrsMap.php` (`get_map()`);
defaults, breakpoint handling, and the empty-attrs bail-out from
`server/Packages/Module/Options/Transition/TransitionStyle.php` (defaults `:143-145`,
per-breakpoint loop `:157-169`, bail-early `:135-137`); the five transition states from
`server/Packages/StyleLibrary/Declarations/Transition/TransitionUtils.php::get_transition_states()`
(`:30-32`) and the animatable-CSS-property allowlist from the same file
(`get_animatable_options_array()`, `:135-212`); the camelCase→kebab `speedCurve` mapping
and its `ease` fallback from
`server/Packages/StyleLibrary/Declarations/Transition/TransitionTraits/StyleDeclarationTrait.php:80-98`;
per-subfield `hover:false,sticky:false` feature flags, VB range `min`/`max`/`defaultUnit`,
and the camelCase `speedCurve` option-label map (`easeInOut`/`ease`/`easeIn`/`easeOut`/`linear`)
confirmed in compiled `visual-builder/build/module.js`; `module.decoration.*` prefix and
the full 3-subfield set cross-checked against
`server/Packages/ModuleLibrary/Text/TextPresetAttrsMap.php:2478-2493`. Path list
cross-verified against `php scripts/extract-decoration-paths.php <builder5> --shared` (see
the "Path verification" section).

**VB round-trip** (2026-07-28): all 3 subfields (`duration`, `delay`, `speedCurve`) written via
`diviops_module_update` to the same scratch Text module (page 900587, trashed after) at the exact
`module.decoration.transition.desktop.value.*` paths shown in this section's copy-paste fragment;
`diviops_module_get` and an independent raw `wp post get --field=post_content` read both returned
the identical serialized shape. Matches the documented path exactly.

---

## Scroll Effects

13 subfields — six motion effects (`verticalMotion`, `horizontalMotion`, `fade`,
`scaling`, `rotating`, `blur`), each exposing both a value and an `.enable` sibling,
plus one shared trigger field — from
`module.decoration.scroll__{verticalMotion,horizontalMotion,fade,scaling,rotating,blur}`
(each ALSO with a `.enable` sibling, e.g. `scroll__verticalMotion.enable`) and
`module.decoration.scroll__motionTriggerStart` *(VB-verified 2026-07-29)*.

Like Sticky, this family is **not a CSS declaration at all** — there is no
`StyleLibrary/Declarations/Scroll` directory in this Divi build (confirmed: no such
path exists under `server/Packages/StyleLibrary/Declarations`). Instead,
`ScrollEffectsScriptData::set()` reads `module.decoration.scroll__*` into a runtime
record registered via `ScriptData::add_data_item(['data_name' => 'scroll', ...])`,
which Divi's own compiled scroll-effects JS reads to attach scroll listeners and
interpolate each effect's live value. On the front end this is
`visual-builder/build/script-library-motion-effects.js`, the one script the theme
actually enqueues for this purpose
(`DynamicAssetsUtils::enqueue_scroll_script()`, `:1037-1051`); its
interpolation/resolver logic is authored in the source modules
`script-library-utils-scroll-effects.js` (`getEffectValue()`) and this same
`script-library-motion-effects.js` (the resolver map below), bundled together by the
build.

| Path | Value shape | Notes |
|---|---|---|
| `module.decoration.scroll__motionTriggerStart` | enum: `"top"` \| `"middle"` \| `"bottom"` | Default `"middle"`. VB `divi/select`, labels "Top of Element" / "Middle of Element" / "Bottom of Element". Confirmed identical in `ScrollEffectsUtils::$scroll_effect_default_group_attr` and the VB's own enum map. |
| `module.decoration.scroll__verticalMotion.enable` / `__horizontalMotion.enable` / `__fade.enable` / `__scaling.enable` / `__rotating.enable` / `__blur.enable` | enum: `"on"` \| `"off"` | Default `"off"` for all six. VB `divi/toggle`. |
| `module.decoration.scroll__verticalMotion` / `__horizontalMotion` / `__fade` / `__scaling` / `__rotating` / `__blur` | object `{viewport: {top, bottom, start, end}, offset: {start, mid, end}}`, all six values plain numeric strings (no unit suffix in the attrs value itself) | See per-effect table below for the shape and defaults. VB custom `divi/scroll-effect` field. |

**Each effect resolves to one specific CSS-affecting property** — confirmed identical
in `ScrollEffectsUtils::$scroll_effect_resolver_map` (PHP) and the VB's own resolver map:
`verticalMotion`→`translateY`, `horizontalMotion`→`translateX`, `fade`→`opacity`,
`scaling`→`scale`, `rotating`→`rotate`, `blur`→`blur`. `viewport.{top,bottom,start,end}`
are numeric strings on a 0–100 scale representing scroll-progress trigger points; they
are sorted and clamped server-side by `ScrollEffectsUtils::get_sorted_range()`
(start ≥ 0, end ≤ 100, midpoints clamped between start/end, `:686-734`).
`offset.{start,mid,end}` are the effect's value at each of those three checkpoints,
linearly interpolated at render time by the front-end's `getEffectValue()`
(`script-library-utils-scroll-effects.js`) into a plain, unitless number — the unit
(and, for four of the six resolvers, an additional scaling multiplier) is applied
afterward, per resolver, by the map shipped in compiled
`visual-builder/build/script-library-motion-effects.js` (confirmed, ≈byte 2150):

```js
blur:       (t,e) => ({ filter: `blur(${Math.round(t)}${getUnit(e.startValue||"","px")})` }),
opacity:    (t,e) => ({ opacity: (.01*t).toFixed(3) }),
rotate:     (t,e) => ({ transform: `rotate3d(0,0,1,${t.toFixed(3)}deg)` }),
scale:      (t,e) => ({ transform: `scale3d(${(.01*t).toFixed(3)}, ${(.01*t).toFixed(3)}, ${(.01*t).toFixed(3)})` }),
translateX: (t,e) => ({ transform: `translateX(${Math.round(100*t)}px)` }),
translateY: (t,e) => ({ transform: `translateY(${Math.round(100*t)}px)` }),
```

**Magnitude gotcha — read this before authoring `offset` values**: `translateX`/
`translateY` multiply the interpolated number by **100** before appending `px`, so
`verticalMotion`'s own shipped default `offset.start: "4"` does not render as
`translateY(4px)` — it renders as `translateY(400px)`. `fade` (`opacity`) and
`scaling` (`scale`) instead multiply by **0.01** and emit a unitless decimal — `fade`'s
0–100-scale `offset` becomes an opacity fraction 0–1, and `scaling`'s default
`offset.start: "70"` becomes `scale3d(0.700, 0.700, 0.700)`, **not** `70%`. `rotate`
passes the number straight through as degrees with no multiplier at all
(`rotating`'s default `offset.start: "90"` → `rotate3d(0,0,1,90.000deg)`). `blur`
rounds the number and appends a unit via `getUnit(e.startValue||"","px")` — but on
the PHP-rendered front end that call always falls back to `"px"`: `startValue`
originates from `ScrollEffectsUtils::get_start_value()`, which returns
`(float) $value['offset']['start']` (`ScrollEffectsUtils.php:641-644`), and the float
cast strips any unit suffix before `getUnit()` ever sees it, so `blur` is `px` on the
front end regardless of what's authored in `offset.start` — the shipped default
`"10"` → `blur(10px)`. The unit is never something you encode in the attrs value —
it, and for four of the six resolvers (`translateX`/`translateY` ×100,
`opacity`/`scale` ×0.01) the rescale as well, is applied entirely by this resolver
map at render time.

Per-effect defaults (identical in `ScrollEffectsUtils::$scroll_effect_default_group_attr`
and the VB's own default map — note `blur`'s viewport default genuinely differs from
the other five, it is not a typo):

| Effect | viewport default | offset default |
|---|---|---|
| verticalMotion | `top:100, start:50, end:50, bottom:0` | `start:4, mid:0, end:-4` |
| horizontalMotion | `top:100, start:50, end:50, bottom:0` | `start:4, mid:0, end:-4` |
| fade | `top:100, start:50, end:50, bottom:0` | `start:0, mid:100, end:100` |
| scaling | `top:100, start:50, end:50, bottom:0` | `start:70, mid:100, end:100` |
| rotating | `top:100, start:50, end:50, bottom:0` | `start:90, mid:0, end:0` |
| blur | `top:100, start:60, end:40, bottom:0` | `start:10, mid:0, end:0` |

**`.enable` is a tri-state inheritance signal, not a plain boolean**: at each
breakpoint, `ScrollEffectsUtils::get_scroll_setting()` distinguishes "key absent"
(inherit the enable status from the next-larger breakpoint) from "explicit `"off"`"
(intentionally cancel an inherited `"on"`, emitted as an id-only stub entry so the
front end knows to stop the effect rather than silently falling through) — confirmed
`:309-398`. Setting `.enable` to `"off"` at `tablet` when `desktop` is `"on"` is
meaningfully different from simply omitting the `tablet` key.

**Responsive, no hover/sticky**: the `set` (per-effect value), `enable`, and
`motionTriggerStart` VB fields all declare `features:{hover:false,sticky:false}` with
no `responsive:false` override, and — unlike Sticky — the responsive values are
genuinely read into front-end behavior: `get_scroll_setting()` loops
`Breakpoint::get_enabled_breakpoint_names()` and produces a separate settings array
per breakpoint (`:290-297`), each resolved through
`ModuleUtils::use_attr_value(... 'mode' => 'getAndInheritAll')` (`:299-307`). There is
no hover or sticky-state variant for any scroll subfield — an on-scroll effect has no
discrete state to key a hover value off of.

Minimal copy-paste `attrs` fragment (vertical motion + fade, both at their own
defaults, middle-of-element trigger):

```json
{
  "module": {
    "decoration": {
      "scroll": {
        "desktop": {
          "value": {
            "motionTriggerStart": "middle",
            "verticalMotion": {
              "enable": "on",
              "viewport": {"top": "100", "start": "50", "end": "50", "bottom": "0"},
              "offset": {"start": "4", "mid": "0", "end": "-4"}
            },
            "fade": {
              "enable": "on",
              "viewport": {"top": "100", "start": "50", "end": "50", "bottom": "0"},
              "offset": {"start": "0", "mid": "100", "end": "100"}
            }
          }
        }
      }
    }
  }
}
```

**Provenance**: shared subfield vocabulary from
`server/Packages/Module/Options/Scroll/ScrollPresetAttrsMap.php::get_map()` (`:32-107`);
per-effect defaults and resolver map from
`server/Packages/Module/Options/Scroll/ScrollEffectsUtils.php`
(`$scroll_effect_default_group_attr` `:36-126`, `$scroll_effect_resolver_map`
`:135-142`); the enable/inherit/intentional-disable mechanism and per-breakpoint loop
from the same file's `get_scroll_setting()` (`:213-426`, esp. `:290-307` and
`:309-398`); viewport sort/clamp from `get_sorted_range()` (`:686-734`); the
runtime-not-CSS mechanism from
`server/Packages/Module/Options/Scroll/ScrollEffectsScriptData.php` (confirmed no
`StyleLibrary/Declarations/Scroll` directory exists in this Divi build); the actual
front-end enqueue target
(`server/FrontEnd/Assets/DynamicAssetsUtils.php::enqueue_scroll_script()`,
`:1037-1051`, confirming `script-library-motion-effects.js` — not the two
`*scroll-effects*.js` source-module filenames — is the one script the theme
registers); the unitless interpolation from compiled
`visual-builder/build/script-library-utils-scroll-effects.js`'s `getEffectValue()`;
the per-resolver unit/multiplier map (`blur`/`opacity`/`rotate`/`scale`/`translateX`/
`translateY`) and `getUnit()`'s unit-suffix extraction from compiled
`visual-builder/build/script-library-motion-effects.js`; the float cast that strips
any unit suffix from `blur`'s own `startValue` on the PHP-rendered front end from
`server/Packages/Module/Options/Scroll/ScrollEffectsUtils.php::get_start_value()`
(`:641-644`); VB field configs
(`features:{hover:false,sticky:false}`, `motionTriggerStart` enum, per-effect default
viewport/offset objects — including `blur`'s distinct `60/40` viewport default)
confirmed in compiled `visual-builder/build/module.js`. `module.decoration.*` prefix
and the full 13-path set cross-checked against
`server/Packages/ModuleLibrary/Text/TextPresetAttrsMap.php:2527-2591`. Path list
cross-verified against `php scripts/extract-decoration-paths.php <builder5> --shared` (see
the "Path verification" section).
**VB round-trip** (2026-07-29, via the MCP write path, not a literal browser Visual
Builder session): `motionTriggerStart` plus all six effects' `.enable` and full
`{viewport:{top,start,end,bottom}, offset:{start,mid,end}}` objects (representative
values, not necessarily each effect's own documented default) written via
`diviops_module_update` to a scratch Text module (page 900592, trashed after); all 13
paths round-tripped identically through `diviops_module_get` and an independent raw
`wp post get --field=post_content` read — no rewrite, no dropped keys, no renamed
keys. Matches every documented path exactly.

---

## Entrance Animation

12 subfields, from
`module.decoration.animation__{style,direction,duration,delay,repeat,speedCurve,startingOpacity}`
plus `animation__intensity.{slide,zoom,flip,fold,roll}` *(VB-verified 2026-07-29)*.

Like Sticky and Scroll, this family is **not a CSS declaration** — no
`StyleLibrary/Declarations/Animation` directory exists in this Divi build (confirmed
via directory listing). `AnimationScriptData::set()` reads
`module.decoration.animation__*` (gated on `AnimationUtils::is_enabled()` and the
`anim` feature flag) into a runtime record consumed by Divi's own compiled
entrance-animation JS. Separately, at PHP render time, the server-side helper
`AnimationUtils::classnames()` adds the `et_animated` CSS class to the module's
markup under that same `is_enabled()` + `anim`-feature gate.

| Path | Value shape | Notes |
|---|---|---|
| `module.decoration.animation__style` | enum: `"none"` \| `"fade"` \| `"slide"` \| `"bounce"` \| `"zoom"` \| `"flip"` \| `"fold"` \| `"roll"` | Default `"none"`. VB `divi/button-options` (icon grid — every option in the map carries an `icon`). **The only animation subfield with `features:{responsive:false}` explicitly set** (compiled `visual-builder/build/module.js`) — every other subfield below is responsive; `style` cannot vary per breakpoint in the VB. |
| `module.decoration.animation__direction` | enum: `"center"` \| `"left"` \| `"right"` \| `"top"` \| `"bottom"` | Default `"center"` (`AnimationUtils::_get_presets()`'s `direction` validator falls back to `"center"` for anything outside this five-value list). VB `divi/select`. **The displayed labels are inverted from the stored keys**: the VB option map (confirmed in compiled `visual-builder/build/module.js`) shows key `left` labeled "Right", key `right` labeled "Left", key `bottom` labeled "Top", key `top` labeled "Bottom" — the key you write to attrs is not the word shown in the dropdown. |
| `module.decoration.animation__duration` | length (ms), e.g. `"1000ms"` | Default `"1000ms"` — **differs from Transition's 300ms default**; don't assume the two families share timing defaults. VB `divi/range`, `defaultUnit:"ms"`, `min:0`, `max:2000`. |
| `module.decoration.animation__delay` | length (ms), e.g. `"0ms"` | Default `"0ms"`. VB `divi/range`, `defaultUnit:"ms"`, `min:0`, `max:3000` — **a 3000ms ceiling, an order of magnitude above Transition's own `delay` max of 300ms.** |
| `module.decoration.animation__intensity.slide` / `.zoom` / `.flip` / `.fold` / `.roll` | percentage, e.g. `"50%"` | Default `"50%"` each. VB `divi/range`, `allowedUnits:["%"]`. Rendered only while `style` is set to that same value — one intensity field is visible at a time. **`fade` and `bounce` have no corresponding `intensity.*` path at all**: `AnimationUtils::_get_presets()`'s `intensity` filter hard-codes `"50%"` for those two styles regardless of any attrs value (`styles_without_intensity = ['fade','bounce']`), so there is nothing to author for them — 5 `intensity.*` paths, not 7, and that is correct, not an omission. |
| `module.decoration.animation__startingOpacity` | percentage, e.g. `"0%"` | Default `"0%"`. VB `divi/range`, `cssProperty:"filter:opacity"`, `max:100`, `minLimit:0`, `maxLimit:100`. Raising it reduces or removes the fade-in that otherwise accompanies every style. |
| `module.decoration.animation__speedCurve` | enum, **kebab-case**: `"ease-in-out"` \| `"ease"` \| `"ease-in"` \| `"ease-out"` \| `"linear"` | Default `"ease-in-out"`. VB `divi/select`. **Do not confuse with Transition's `speedCurve`**, which stores camelCase (`easeInOut`) and is server-side mapped to this same kebab-case CSS form — Animation's value is already kebab-case and is used as-is by the front-end animation runtime; no PHP mapping switch exists for it (confirmed: no such switch anywhere under `Module/Options/Animation`, and no `StyleLibrary/Declarations/Animation` directory exists to hold one). |
| `module.decoration.animation__repeat` | enum: `"once"` \| `"loop"` | Default `"once"`. VB `divi/select`. |

**Responsive is read into front-end behavior for every subfield except `style`**:
`AnimationUtils::generate_data()` loops every breakpoint present in the attrs
(`foreach (array_keys($args['attr']) as $breakpoint)`), resolves each through
`ModuleUtils::use_attr_value(... 'mode' => 'getAndInheritAll')`, and emits
breakpoint-suffixed data keys (e.g. `duration_tablet`) — unlike Sticky, there is no
hardcoded-desktop shortcut here. `style` is the one exception, gated off at the VB
field level as noted above.

**Implicit interaction with Transform** (derived, not authorable): for the five
"directional" styles (`slide`/`zoom`/`flip`/`fold`/`roll`),
`AnimationUtils::has_transformed_animation_for_breakpoint()` swaps the generated
`style` data value to `'transformAnim'` so the animation composes with the module's
own transform instead of fighting it, under either of two conditions checked at the
same breakpoint: (1) `module.decoration.transform` carries any value at all
(`AnimationUtils.php:647-649`), or (2) `module.decoration.position` is in a non-
`"default"` mode **and** that mode's `origin` string contains `"center"` as one of its
two space-separated axis words — e.g. `"center left"`, `"top center"`, or
`"center center"` all qualify, but a fully off-center origin like `"top left"` does
**not** (`AnimationUtils.php:665-675`). This is computed automatically from the
module's other attrs at render time — there is no `module.decoration.animation__*`
key to set it directly.

**Cross-link**: this family is Divi's native, attrs-driven entrance animation —
configured entirely through `module.decoration.animation__*` and executed by Divi's
own compiled JS runtime plus the server-side `et_animated` class toggle above. Don't
confuse it with [design-effects.md](design-effects.md)'s
["Entrance Animations (scroll-triggered)"](design-effects.md#entrance-animations-scroll-triggered)
— those are a completely different mechanism: Divi Design Library `ddl-*` CSS classes
detected by an `IntersectionObserver`, with no `module.decoration.animation__*` attrs
involved at all. Use this family for the builder's built-in "Animation" settings
panel; use the DDL classes in `design-effects.md` for its effects catalog.

Minimal copy-paste `attrs` fragment (slide in, moderate intensity, once):

```json
{
  "module": {
    "decoration": {
      "animation": {
        "desktop": {
          "value": {
            "style": "slide",
            "direction": "left",
            "duration": "800ms",
            "delay": "100ms",
            "intensity": {"slide": "60%"},
            "startingOpacity": "0%",
            "speedCurve": "ease-in-out",
            "repeat": "once"
          }
        }
      }
    }
  }
}
```

**Provenance**: shared subfield vocabulary from
`server/Packages/Module/Options/Animation/AnimationPresetAttrsMap.php::get_map()`
(`:32-96`, confirming the 12-path set with 5, not 7, `intensity.*` entries); style/
direction/timing defaults, validation fallbacks, and the fade/bounce intensity
hard-code from
`server/Packages/Module/Options/Animation/AnimationUtils.php::_get_presets()`
(`:316-522`) and `::_get_options()` (`:239-284`); enablement check from `is_enabled()`
(`:571-574`); the Transform-interaction swap from
`has_transformed_animation_for_breakpoint()` / `_is_transformable_animation_style()` /
`_has_default_transform_values()` (`:586-676`); the runtime-not-CSS mechanism from
`server/Packages/Module/Options/Animation/AnimationScriptData.php` (confirmed no
`StyleLibrary/Declarations/Animation` directory exists); VB field configs — the
explicit `style` `responsive:false` flag, every other subfield's absence of that
flag, the 8-value style option map (including `bounce`), the direction option map's
key/label inversion, and the kebab-case `speedCurve` option-label map — all confirmed
in compiled `visual-builder/build/module.js`. `module.decoration.*` prefix and the
12-path set cross-checked against
`server/Packages/ModuleLibrary/Text/TextPresetAttrsMap.php:2216-2276`. Path list
cross-verified against `php scripts/extract-decoration-paths.php <builder5> --shared` (see
the "Path verification" section).
**VB round-trip** (2026-07-29, via the MCP write path, not a literal browser Visual
Builder session): all 7 named `desktop.value.*` subfields (`style`, `direction`,
`duration`, `delay`, `startingOpacity`, `speedCurve`, `repeat`) plus all 5
`intensity.{slide,zoom,flip,fold,roll}` entries written via `diviops_module_update`
to a scratch Text module (page 900592, trashed after); all 12 paths round-tripped
identically through `diviops_module_get` and an independent raw
`wp post get --field=post_content` read — no rewrite, no dropped keys, no renamed
keys. Matches every documented path exactly.

---

## Button

The largest shared family Divi ships: 149 preset-attribute keys under a single element
prefix <!-- UNVERIFIED --> *(paths extractor-corroborated 2026-08-14, not VB round-tripped; see [Round-trip status](#round-trip-status))*.

Unlike every family above, Button is a **composite**. Its own `get_map()` contributes
six keys — five `icon.*` subfields and one alignment subfield — and obtains the other
143 by calling seven sibling family maps. The element prefix is the bare element name,
not a `.decoration.` path:

```
php scripts/extract-shared-preset-paths.php <builder5> --family Button --attr button
```

| Delegate | Keys | Prefix it lands under |
|---|---|---|
| Background | 50 | `button.decoration.background__*` |
| Sizing | 20 | `button.decoration.sizing__*` |
| Font | 44 | `button.decoration.font.font__*`, `.textEffects__*`, `.textShadow__*` |
| Border | 17 | `button.decoration.border__*` |
| BoxShadow | 7 | `button.decoration.boxShadow__*` |
| Spacing | 2 | `button.decoration.spacing__*` |
| AttributesRel | 1 | `button.innerContent__rel` |

50 + 20 + 44 + 17 + 7 + 2 + 1 = 141 delegated, plus Button's own 6 and its three
`innerContent` keys = 150 — but the resolved total is **149**, not 150, because
`button.decoration.sizing__alignment` is contributed twice (once by Button's own group,
once by the Sizing delegate) and `array_merge` collapses it to one key. That is the
whole discrepancy; there is no dropped path.

### Button's own keys

| Path | Value shape | Notes |
|---|---|---|
| `button.decoration.button__icon.enable` | enum: `"on"` \| `"off"` | Master switch for the button icon. **No hover, no sticky, no responsive** — the VB field config sets `features:{hover:false,sticky:false,responsive:false}`. Every other field in this group is hidden in the VB until this is `"on"` — the group's shared `visible` predicate reads `desktop.value.icon.enable`, with one escape hatch: while a preset is being edited the whole group is shown regardless. |
| `button.decoration.button__icon.settings` | object `{unicode, type, weight}` | The glyph itself. `type` is `"divi"` or `"fa"`; `weight` is a numeric string such as `"400"`; `unicode` is the glyph's code point as stored in Divi's own icon list. All three must be present or `IconFont\Utils::find_icon_in_list()` returns null and no icon renders. **No hover**; responsive *is* supported and genuinely rendered — `ButtonComponent::component()` emits separate `data-icon`, `data-icon-tablet`, and `data-icon-phone` attributes and the breakpoint CSS resolves `content: attr(data-icon-tablet)`. |
| `button.decoration.button__icon.color` | color string | Icon color, independent of the button's text color. Hover supported. |
| `button.decoration.button__icon.placement` | enum: `"right"` \| `"left"` | Default `"right"` (the PHP `?? 'right'` fallback, matching the VB's two-entry option map). `"left"` moves the glyph to the `:before` pseudo-element, emits `margin-left: -1.3em`, and hides the right-side glyph with `display: none`. |
| `button.decoration.button__icon.onHover` | enum: `"on"` \| `"off"` | **Reads backwards from its name.** The VB labels it "Only Show Icon On Hover"; `"on"` is Divi's default reveal-on-hover behavior, and `"off"` is what pins the icon permanently visible (it emits `opacity: 1`). Setting `onHover` to `"off"` shows the icon *more*, not less. |
| `button.decoration.sizing__alignment` | enum: `"left"` \| `"center"` \| `"right"` | Emitted by `StyleLibrary/Declarations/Button/Button.php` as plain `text-align` — that class emits nothing else at all. `features:{hover:false,sticky:false}`; responsive is supported. Shared with the Sizing family, which is why it is one key rather than two. |
| `button.innerContent__text` | string | The button label. May carry an HTML tag, in which case `ButtonComponent` extracts the title text from it. |
| `button.innerContent__linkUrl` | URL string | **No hover, no sticky, no responsive.** With no `text` and no `linkUrl`, the component renders nothing at all unless the module passes `allowEmptyUrl` or `forceRender`. |
| `button.innerContent__linkTarget` | enum: `"off"` \| `"on"` | `"on"` opens in a new tab. Default `"off"`. No hover/sticky/responsive. When `linkTarget` is `"on"` and `rel` is empty, the component adds `rel="noreferrer"` on its own. |
| `button.innerContent__rel` | array of strings | Any of `bookmark`, `external`, `nofollow`, `noreferrer`, `noopener`. A list, not a single value. No hover/sticky/responsive. |

**The `enable`-only case emits no CSS.** `ButtonIcon::style_declaration()` returns an
empty string unless `enable` is `"on"` *and* at least one of: a glyph is chosen, a color
is set, `placement` is `"left"`, or `onHover` is `"off"`. Divi's theme stylesheet already
carries the baseline button-icon appearance, so writing `enable: "on"` and nothing else
is a no-op at the module-CSS level rather than a bug.

**Hover inherits `enable` and `settings` from the value state.** Where Box Shadow and
Filters route inheritance through `ModuleUtils::use_attr_value( mode: 'getAndInheritAll' )`,
Button's icon does it inline: the hover state's `icon.enable` and `icon.settings` fall
back through `defaultAttrValue`, then the current breakpoint's `value` state, then
`desktop.value`. A hover fragment that sets only `icon.color` therefore keeps the glyph
rather than losing it.

Minimal copy-paste `attrs` fragment (labelled link button, always-visible left icon,
hover color swap):

```json
{
  "button": {
    "innerContent": {
      "desktop": {
        "value": {
          "text": "Read the case study",
          "linkUrl": "https://example.com/case-study",
          "linkTarget": "on",
          "rel": ["noopener", "noreferrer"]
        }
      }
    },
    "decoration": {
      "button": {
        "desktop": {
          "value": {
            "icon": {
              "enable": "on",
              "settings": {"unicode": "&#x24;", "type": "divi", "weight": "400"},
              "color": "#6366f1",
              "placement": "left",
              "onHover": "off"
            }
          },
          "hover": {"icon": {"color": "#f59e0b"}}
        }
      },
      "sizing": {"desktop": {"value": {"alignment": "center"}}}
    }
  }
}
```

**Provenance**: composition, the six own keys, and the delegate list from
`server/Packages/Module/Options/Button/ButtonPresetAttrsMap.php::get_map()` (`:40-130`,
own group at `:70-101`, delegates at `:103-127`); the `rel` key from
`server/Packages/Module/Options/AttributesRel/AttributesRelPresetAttrsMap.php`; icon CSS
emission, the `enable`+custom-styles gate, the value-state fallback chain, the `left`
placement's `-1.3em` margin and `display:none` sibling rule, and the
`onHover: "off"` → `opacity: 1` rule from
`server/Packages/StyleLibrary/Declarations/ButtonIcon/ButtonIcon.php`
(`style_declaration()` `:46-169`, `hover_style_declaration()` `:192-219`,
`right_style_declaration()` `:241-269`, `disable_style_declaration()` `:291-317`);
`alignment` → `text-align` (and nothing else) from
`server/Packages/StyleLibrary/Declarations/Button/Button.php:44-69`; the
`data-icon` / `data-icon-tablet` / `data-icon-phone` responsive rendering and the
empty-`rel` + new-tab `noreferrer` behavior from
`server/Packages/Module/Options/Button/ButtonComponent.php` (`:183-194`); the
`{unicode, type, weight}` requirement and the `divi`/`fa` type set from
`server/Packages/IconLibrary/IconFont/Utils.php::find_icon_in_list()` (`:60-81`),
`::process_font_icon()` (`:134-175`), and `::is_fa_icon()` (`:102-104`); the canonical
`button.decoration.button` group attrName, the `icon.enable` visibility predicate, every
`features` flag quoted above, the two-entry `placement` option map, the `linkTarget`
option map, and the five-entry `rel` option list all confirmed in compiled
`visual-builder/build/module.js`. Path list cross-verified against
`php scripts/extract-shared-preset-paths.php <builder5> --family Button --attr button`
(see [Path verification — Tier 2 families](#path-verification--tier-2-families)).

---

## Font

44 keys under an element's font attribute <!-- UNVERIFIED --> *(paths extractor-corroborated 2026-08-14, not VB round-tripped; see
[Round-trip status](#round-trip-status))*: 20 of its own plus 19 delegated to
[Text Effects](#text-effects) and 5 to [Text Shadow](#text-shadow).

**The doubled `font.font` is not a typo.** `FontPresetAttrsMap::get_map()` is handed the
element's *font attribute* and appends its own `.font` segment, so the canonical leaf is:

```
<element>.decoration.font.font__<subField>
```

`title.decoration.font.font__size`, `button.decoration.font.font__color`,
`module.decoration.font.font__lineHeight`. The VB's font group likewise defaults its
attrName to `module.decoration.font`, and its fields append `.font` to it.

The single-`font` form (`title.decoration.font__size`) is what this family never
produces, and Divi's own module maps treat it as a mistake to clean up: of the 23
single-`font` strings in the whole of `ModuleLibrary/`, 22 sit inside `unset()` or
`$keys_to_unset` lists — Menu and Fullwidth Menu strip six between them, Pricing Tables
another six. The lone survivor is Gallery's `pagination.decoration.font__textAlign`,
which Gallery hand-registers with `attrName: 'pagination.decoration.font'` instead of
routing that element through this family at all. Treat a single-`font` path as wrong
unless the module's own map is where you read it.

| Path (under `<element>.decoration.font`) | Value shape | Notes |
|---|---|---|
| `.font__family` | font-name string | `"none"` and `"default"` (case-insensitive) are treated as unset. A `$variable()$` global-font token is resolved first. Divi appends a websafe fallback stack (`Helvetica, Arial, Lucida, sans-serif` for sans-serif, `Georgia, "Times New Roman", serif` for serif, and so on) unless the name already ends in a CSS generic keyword. |
| `.font__weight` | numeric string `"100"`–`"900"`, or `"variable"` | The VB's base option map is 100/200/…/900 (labels Thin … Heavy), narrowed at runtime to the weights the chosen family actually ships. `"variable"` is a mode switch, not a weight: it hands control to `weightFineTune` / the `WGHT` variable axis. |
| `.font__weightFineTune` | numeric string | Variable-font weight on the `WGHT` axis, clamped to the family's own min/max. Visible in the VB only for families exposing `WGHT`. Setting it puts the field into variable-weight mode even if `weight` is a discrete number. |
| `.font__opticalSizing` | enum: `"auto"` \| `"none"` | Only `"none"` (or the legacy `"off"`) emits anything — `font-optical-sizing: none`. Visible only for families exposing an `OPSZ` axis. |
| `.font__style` | **array** of `"italic"` \| `"underline"` \| `"overline"` \| `"strikethrough"` | Multi-select, so the value is a list. `underline`/`overline`/`strikethrough` compose into one `text-decoration-line`. An **empty array is an explicit reset**, emitting `font-style: normal` and `text-decoration-line: none` to override an inherited or preset value — different from omitting the key. A bare string is tolerated and wrapped into a one-element array. |
| `.font__capitalization` | enum: `"uppercase"` \| `"lowercase"` \| `"capitalize"` \| `"smallCaps"` \| `"allSmallCaps"` | Single-choice, despite sharing an icon map with `style`. The first three emit `text-transform`; the last two emit `font-variant-caps: small-caps` / `all-small-caps`. An **empty string is an explicit reset** to `text-transform: none` / `font-variant-caps: normal`, again only when an inherited or default value would otherwise apply. |
| `.font__color` | color string | `color`. |
| `.font__size` | length, e.g. `"18px"` | A **unitless numeric value gets `px` appended** by the renderer, which is how migrated Divi 4 layouts survive. Any of Divi's 12 allowed units otherwise (`%`, `em`, `rem`, `px`, `cm`, `mm`, `in`, `pt`, `pc`, `ex`, `vh`, `vw`). |
| `.font__letterSpacing` | length, e.g. `"0.05em"` | `letter-spacing`. |
| `.font__lineHeight` | length or unitless number, e.g. `"1.6em"` | VB `defaultUnit:"em"`, `step:0.1`. |
| `.font__textAlign` | enum: `"left"` \| `"center"` \| `"right"` \| `"justify"` | **No hover, no sticky** (`features:{hover:false,sticky:false}`); responsive supported. |
| `.font__textWrap` | enum: `"wrap"` \| `"balance"` \| `"pretty"` | CSS `text-wrap`. Labels are "Default" / "Balanced" / "Pretty"; the stored key for the default is `"wrap"`, not `"default"`. |
| `.font__writingMode` | enum: `"horizontal-tb"` \| `"vertical-rl"` \| `"vertical-lr"` | **`"vertical-lr"` does not emit `writing-mode: vertical-lr`.** It emits `writing-mode: vertical-rl` plus `transform: rotate(180deg)`, which is how Divi fakes left-to-right vertical text. That rotation collides with anything else writing `transform` on the same element. |
| `.font__hyphens` | enum: `"on"` \| `"off"` | `"on"` emits `hyphens: auto` **and** `word-wrap: break-word`; `"off"` emits `hyphens: none`. Any other string is passed through to `hyphens` verbatim. |
| `.font__columnCount` | numeric string, e.g. `"2"` | Unitless (`allowedUnits:[""]`, `min:1`). **`"1"` normally emits nothing** — the single-column default is treated as UI-only; it emits `column-count: 1` only when an inherited or default value was greater than 1, i.e. as a deliberate override. |
| `.font__columnGap` | length, e.g. `"2em"` | VB default `"1em"`. **A zero gap emits nothing**: `"0"`, `"0px"`, `"0em"`, `"0rem"`, and `"0%"` are all suppressed. |
| `.font__lineColor` | color string | `text-decoration-color`. |
| `.font__lineStyle` | enum: `"solid"` \| `"double"` \| `"dotted"` \| `"dashed"` \| `"wavy"` | `text-decoration-style`. **Only emitted when `style` contains one of `underline`/`overline`/`strikethrough`** — on its own it produces nothing. Defaults to `solid` when a decoration line is present and this key is absent. |
| `.font__lineThickness` | length, e.g. `"2px"` | `text-decoration-thickness`, `minLimit:1`. |
| `.font__underlineOffset` | length, e.g. `"3px"` | `text-underline-offset`. |

**Hover and responsive**: `weight`, `style`, `capitalization`, `lineColor`, `lineStyle`,
`lineThickness`, `underlineOffset`, `weightFineTune`, and `opticalSizing` carry explicit
`features:{hover:true,sticky:true}`; `textAlign` is the one subfield with
`features:{hover:false,sticky:false}`; the rest declare no restriction and so take both.
Nothing in this family sets `responsive:false`.

**Two sibling keys are rendered but not preset-registered.** `Font::style_declaration()`
also reads `variationSettings` (an object keyed by four-letter variable-font axis tags —
`WGHT`, `WDTH`, `SLNT`, and custom axes — emitted as `font-variation-settings`) and the
`columnRuleWidth` / `columnRuleStyle` / `columnRuleColor` trio (emitted only while
`columnCount > 1`). Neither appears in any of the 47 shared maps, so neither participates
in preset save/load; they are still valid to write into a module's attrs.

**Four opt-in flags add up to 8 more keys.** `get_map()` takes a second `$args` argument:
`has_heading_level` adds `.font__headingLevel`, `has_list` adds four `.list__*` keys
(`type`, `position`, `itemIndent`, `listSpacing`), `has_paragraph` adds
`.list__paragraphSpacing`, and `has_border` adds `.border__styles.left.{width,color}`
(the blockquote bar). All four default to false, which is why the base resolution is 44.
The dropCap subfields (`.font__dropCapLineSize`, `.font__dropCapSpacing`) are not reached
through this argument at all — they arrive through the `FontBody` group's own `dropCap`
sub-element, which is [deferred](#shared-family-inventory-47-maps).

Minimal copy-paste `attrs` fragment (module-wrapper font, hover color swap):

```json
{
  "module": {
    "decoration": {
      "font": {
        "font": {
          "desktop": {
            "value": {
              "family": "Inter",
              "weight": "600",
              "style": ["italic"],
              "capitalization": "uppercase",
              "color": "#0f172a",
              "size": "18px",
              "letterSpacing": "0.04em",
              "lineHeight": "1.6em",
              "textAlign": "left",
              "lineStyle": "solid",
              "lineColor": "#6366f1",
              "lineThickness": "2px",
              "underlineOffset": "3px"
            },
            "hover": {"color": "#6366f1"}
          }
        }
      }
    }
  }
}
```

**Provenance**: the 20-key vocabulary, the appended `.font` segment, the delegation to
TextEffects and TextShadow, and the four `$args` flags from
`server/Packages/Module/Options/Font/FontPresetAttrsMap.php::get_map()` (`:36-205`, own
keys `:46-147`, flags `:149-199`, delegates `:201-204`); every CSS-emission rule quoted
above from `server/Packages/StyleLibrary/Declarations/Font/Font.php::style_declaration()`
(`:211-741`) — websafe fallback stacks `:120-188`, variable-axis clamping and
`font-variation-settings` `:335-478`, `opticalSizing` `:480-487`, the array-valued
`style` and its empty-array reset `:489-541`, capitalization and its empty-string reset
`:543-579`, `lineStyle`'s decoration-line gate `:585-596`, `hyphens` `:610-619`, the
zero-`columnGap` suppression `:621-626`, the `columnCount > 1` gate and the
`columnRule*` trio `:628-668`, the unitless-`size` px fallback `:674-687`, and
`writingMode`'s `vertical-rl` + `rotate(180deg)` substitution `:709-734`; the weight
option map (100–900 with Thin…Heavy labels), the 12-entry allowed-unit list, the
`style` and `capitalization` option maps, the `lineStyle` / `textWrap` / `writingMode` /
`textAlign` / `opticalSizing` option maps, the `module.decoration.font` group default
attrName, and every `features` flag quoted above all confirmed in compiled
`visual-builder/build/module.js`. Path list cross-verified against
`php scripts/extract-shared-preset-paths.php <builder5> --family Font --attr module.decoration.font`
(see [Path verification — Tier 2 families](#path-verification--tier-2-families)).

---

## Text Effects

19 keys under `<element>.decoration.font.textEffects__*` <!-- UNVERIFIED --> *(paths
extractor-corroborated 2026-08-14, not VB round-tripped;
see [Round-trip status](#round-trip-status))*. Reached through the Font family, which
calls this map with the element's font attribute and lets it append its own
`.textEffects` segment.

Three independent capabilities share one map: a **fill** (gradient or image clipped to
the glyphs), a **stroke**, and the `fillType` switch that selects between them.

| Path (under `<element>.decoration.font`) | Value shape | Notes |
|---|---|---|
| `.textEffects__fillType` | enum: `"none"` \| `"gradient"` \| `"image"` \| `"transparent"` | The switch. `"transparent"` emits `-webkit-text-fill-color: transparent` with no fill behind it (invisible text, used with a stroke). Resolved through `getAndInheritAll`, so a breakpoint inherits the larger breakpoint's fill type. |
| `.textEffects__gradient` | object | The whole gradient object, including its `stops` array. `stops` has **no leaf path of its own** — it round-trips only as part of this bare key. Fewer than two stops emits nothing. |
| `.textEffects__gradient.type` | enum: `"linear"` \| `"circular"` \| `"elliptical"` \| `"conic"` | Renderer fallback `"linear"`. The VB labels them Linear / Circular / Elliptical / Conical; there is no `"radial"` key. |
| `.textEffects__gradient.direction` | angle, e.g. `"180deg"` | Renderer fallback `"180deg"`. Linear and conic gradients. |
| `.textEffects__gradient.directionRadial` | position keyword: `"center"` \| `"top left"` \| `"top"` \| `"top right"` \| `"right"` \| `"bottom right"` \| `"bottom"` \| `"bottom left"` \| `"left"` | Renderer fallback `"center"`. Note the two-word keys carry a literal space. Circular and elliptical gradients. |
| `.textEffects__gradient.repeat` | enum: `"on"` \| `"off"` | Renderer fallback `"off"`. |
| `.textEffects__gradient.length` | length/percentage, e.g. `"100%"` | Renderer fallback `"100%"`. |
| `.textEffects__imageFill.url` | image URL string | Without a resolvable URL the whole image fill emits nothing. |
| `.textEffects__imageFill.size` | enum/length | Fed to the same background-size helper the Background family uses. |
| `.textEffects__imageFill.width` / `.height` | lengths | Only consulted for the custom size mode. |
| `.textEffects__imageFill.position` | position keyword | Renderer fallback `"center"`. |
| `.textEffects__imageFill.horizontalOffset` / `.verticalOffset` | percentages | Renderer fallback `"0%"` each. |
| `.textEffects__imageFill.repeat` | CSS `background-repeat` keyword | Renderer fallback `"no-repeat"`. |
| `.textEffects__imageFill.blend` | CSS `background-blend-mode` keyword | Emitted **only when it differs from the Background family's own default blend**; matching the default is treated as "not set". |
| `.textEffects__strokeWidth` | length, e.g. `"2px"` | `-webkit-text-stroke-width`. Allowed units are a narrower set than the font family's: `px`, `em`, `rem`, `vh`, `vw`, default `px`. Independent of `fillType` — a stroke works with any fill, including `"none"`. |
| `.textEffects__strokeColor` | color string | `-webkit-text-stroke-color`. |
| `.textEffects__strokePosition` | enum: `"stroke-fill"` \| `"fill-stroke"` | **The stored keys read backwards from the VB labels**: key `"stroke-fill"` is labelled "Outside" and emits `paint-order: stroke`; key `"fill-stroke"` is labelled "Center" and emits `paint-order: fill`. With this key absent and `strokeWidth` numerically greater than 1, the renderer picks `paint-order: stroke` on its own. |

**Fills clip to the text.** Both the gradient and image branches emit the same
supporting declarations alongside the `background-image` — `background-repeat`,
`-webkit-background-clip: text`, `background-clip: text`, and
`-webkit-text-fill-color: transparent`. That last one is why a text effect overrides
`font__color`: the glyph fill is switched off so the clipped background shows through.
Switching `fillType` back to `"none"` **explicitly** (rather than omitting the key)
emits the reset triple `background-image: none`, `background-clip: border-box`,
`-webkit-text-fill-color: initial`.

Minimal copy-paste `attrs` fragment (gradient fill plus an outside stroke):

```json
{
  "module": {
    "decoration": {
      "font": {
        "textEffects": {
          "desktop": {
            "value": {
              "fillType": "gradient",
              "gradient": {
                "type": "linear",
                "direction": "135deg",
                "repeat": "off",
                "length": "100%",
                "stops": [
                  {"position": "0", "color": "#6366f1"},
                  {"position": "100", "color": "#f59e0b"}
                ]
              },
              "strokeWidth": "1px",
              "strokeColor": "#0f172a",
              "strokePosition": "stroke-fill"
            }
          }
        }
      }
    }
  }
}
```

**Provenance**: the 19-key vocabulary and the appended `.textEffects` segment from
`server/Packages/Module/Options/TextEffects/TextEffectsPresetAttrsMap.php::get_map()`
(`:32-130`); the `fillType` inheritance through
`ModuleUtils::get_attr_subname_value( mode: 'getAndInheritAll' )`, the stroke
declarations and the automatic `paint-order` at stroke width > 1, the two-stop minimum,
the background-clip declarations, the image-fill renderer fallbacks
(`position: center`, offsets `0%`, `repeat: no-repeat`), the differs-from-default blend
gate, and the explicit-`none` reset triple all from
`server/Packages/StyleLibrary/Declarations/TextEffects/TextEffects.php::style_declaration()`
(`:80-225`); the gradient sub-field list and its renderer fallbacks (`linear`, `180deg`,
`center`, `off`, `100%`) from the same file's `GradientUtils` call (`:148-187`); the
`module.decoration.font.textEffects` group default attrName, the four-entry `fillType`
option map, the two-entry `strokePosition` option map with its inverted "Outside" /
"Center" labels, and the stroke-width unit list all confirmed in compiled
`visual-builder/build/module.js`. Path list cross-verified against
`php scripts/extract-shared-preset-paths.php <builder5> --family TextEffects --attr module.decoration.font`
(see [Path verification — Tier 2 families](#path-verification--tier-2-families)).

---

## Text Shadow

5 keys under `<element>.decoration.font.textShadow__*` <!-- UNVERIFIED --> *(paths extractor-corroborated 2026-08-14, not VB round-tripped; see
[Round-trip status](#round-trip-status))*. The same preset-plus-overrides shape as
[Box Shadow](#box-shadow), with five presets instead of seven and `em` units instead of
`px`.

| Path (under `<element>.decoration.font`) | Value shape | Notes |
|---|---|---|
| `.textShadow__style` | enum: `"none"` \| `"preset1"`–`"preset5"` | The gate. **With no `style` and no preset match, the family emits nothing at all**, whatever the other four keys hold — the one exception being a responsive breakpoint (below). `"none"` returns an empty string outright. **No hover, no sticky, no responsive**: `features:{hover:false,responsive:false,sticky:false}`. |
| `.textShadow__horizontal` | length, e.g. `"0.08em"` | VB `divi/range`, `defaultUnit:"em"`, `min:-2`, `max:2`, `step:0.01`. Overrides the preset's own value. |
| `.textShadow__vertical` | length, e.g. `"0.1em"` | Same range and unit as `horizontal`. |
| `.textShadow__blur` | length, e.g. `"0.3em"` | Same unit and step, but `min:0` — blur cannot be negative. |
| `.textShadow__color` | color string | **Omitting it is meaningful**: CSS `text-shadow` with no color falls back to the element's own `color`, and the renderer emits the three lengths with no color term rather than substituting one. |

The five built-in presets (from `TextShadow::$_presets`, all
`color: "rgba(0,0,0,0.4)"`):

| Preset | horizontal | vertical | blur |
|---|---|---|---|
| preset1 | `0em` | `0.1em` | `0.1em` |
| preset2 | `0.08em` | `0.08em` | `0.08em` |
| preset3 | `0em` | `0em` | `0.3em` |
| preset4 | `0em` | `0.08em` | `0em` |
| preset5 | `0.08em` | `0.08em` | `0em` |

**Responsive breakpoints break the style gate on purpose.** On tablet or phone, explicit
`horizontal` / `vertical` / `blur` values emit CSS even with no `style` on that
breakpoint, and all-zero dimensions emit `0em 0em 0em` rather than nothing — both so a
smaller breakpoint can cancel the desktop shadow. On desktop the same input emits
nothing. Since `style` itself is not responsive, cancelling a shadow at a breakpoint
means writing explicit zero lengths there, not writing `style: "none"`.

Minimal copy-paste `attrs` fragment (preset 3, custom color, cancelled on phone):

```json
{
  "module": {
    "decoration": {
      "font": {
        "textShadow": {
          "desktop": {
            "value": {
              "style": "preset3",
              "horizontal": "0em",
              "vertical": "0.06em",
              "blur": "0.25em",
              "color": "rgba(15,23,42,0.45)"
            }
          },
          "phone": {
            "value": {"horizontal": "0em", "vertical": "0em", "blur": "0em"}
          }
        }
      }
    }
  }
}
```

**Provenance**: the five-key vocabulary from
`server/Packages/Module/Options/TextShadow/TextShadowPresetAttrsMap.php::get_map()`; the
preset table, the merge-preset-then-override order, the no-style-no-CSS gate, the
missing-color behavior, and both responsive-breakpoint exceptions from
`server/Packages/StyleLibrary/Declarations/TextShadow/TextShadow.php` (`$_presets`
`:40-71`, `value()` `:105-171`, `style_declaration()` `:212-231`); the
`module.decoration.font.textShadow` group default attrName, the
`none`/`preset1`–`preset5` option map (registered through the
`divi.module.options.textShadow.styleOptions` filter), the `style` field's
`hover:false,responsive:false,sticky:false` flags, and the `em` unit with its
`min`/`max`/`step` bounds all confirmed in compiled `visual-builder/build/module.js`.
Path list cross-verified against
`php scripts/extract-shared-preset-paths.php <builder5> --family TextShadow --attr module.decoration.font.textShadow`
(see [Path verification — Tier 2 families](#path-verification--tier-2-families)).

---

## Icon

3 keys under `<element>.decoration.icon__*` <!-- UNVERIFIED --> *(paths extractor-corroborated 2026-08-14, not VB round-tripped; see
[Round-trip status](#round-trip-status))*. The smallest documented family, and the one
whose preset map most understates what the attribute actually holds.

| Path | Value shape | Notes |
|---|---|---|
| `<element>.decoration.icon__color` | color string | Emitted as `color`, except on a default radio indicator (below), where it becomes `background-color`. Hover supported. |
| `<element>.decoration.icon__useSize` | enum: `"on"` \| `"off"` | **`size` is ignored unless this is `"on"`.** `features:{sticky:false}`; hover and responsive supported. |
| `<element>.decoration.icon__size` | length, e.g. `"32px"` | VB `divi/range`, `defaultUnit:"px"`, `min:1`, `max:120`. Emits **both** `font-size` and `line-height` at the same value, so an icon stays vertically centred in its own box. |

**The glyph has no preset path — it lives at the attribute root.** The VB's icon-picker
field for this group carries no `subName`, so it writes `unicode`, `type`, and `weight`
as siblings of `color`/`useSize`/`size` inside the same value object.
`IconPresetAttrsMap` even carries a commented-out `"{$attr_name}__style_html"` entry at
exactly that spot. Consequences worth knowing:

- A hand-written `attrs` fragment sets the glyph at
  `<element>.decoration.icon.desktop.value.{unicode,type,weight}`, not under any
  `__`-suffixed path.
- Presets save and restore the icon's color and size but **not which glyph it is**,
  because only the three `__` keys are registered.
- `indicatorShape` is another such root-level sibling: the value `"radio-default"`
  selects Divi's circle-based radio indicator, which styles `background-color` plus
  `width`/`height` from `size` instead of `color` plus `font-size`. Choosing a glyph
  alongside it overrides the circle (`background: none`, `border-radius: initial`,
  `width`/`height`: `initial`).

`Icon::style_declaration()` returns an empty string when `attrValue` is missing
entirely, and emits `font-family: "ETmodules"` or `"FontAwesome"` plus the escaped
`content` only when the glyph resolves against Divi's own icon list.

Minimal copy-paste `attrs` fragment (module-wrapper icon, custom size, hover color):

```json
{
  "module": {
    "decoration": {
      "icon": {
        "desktop": {
          "value": {
            "unicode": "&#x24;",
            "type": "divi",
            "weight": "400",
            "color": "#6366f1",
            "useSize": "on",
            "size": "32px"
          },
          "hover": {"color": "#f59e0b"}
        }
      }
    }
  }
}
```

**Provenance**: the three-key vocabulary, and the commented-out fourth entry showing the
glyph deliberately has no subfield path, from
`server/Packages/Module/Options/Icon/IconPresetAttrsMap.php::get_map()` (`:32-55`); the
`useSize` gate, the paired `font-size` + `line-height` emission, the `color` →
`background-color` swap under `indicatorShape: "radio-default"`, the glyph-override
reset, and the `ETmodules` / `FontAwesome` font-family selection from
`server/Packages/StyleLibrary/Declarations/Icon/Icon.php::style_declaration()`
(`:46-131`); the glyph's `{unicode, type, weight}` shape from
`server/Packages/IconLibrary/IconFont/Utils.php::find_icon_in_list()` (`:60-81`); the
`module.decoration.icon` group default attrName, the icon-picker field's absent
`subName`, `useSize`'s `sticky:false` flag, and the size range's `px` unit with
`min:1`/`max:120` all confirmed in compiled `visual-builder/build/module.js`. Path list
cross-verified against
`php scripts/extract-shared-preset-paths.php <builder5> --family Icon --attr module.decoration.icon`
(see [Path verification — Tier 2 families](#path-verification--tier-2-families)).

---

## Path verification — Tier 2 families

The seven advanced families above are cross-checked in
[Path verification](#path-verification). The five Tier 2 families are cross-checked here,
against `scripts/extract-shared-preset-paths.php`, which resolves a family by running its
`get_map()` rather than reading it — the only way Button's and Font's delegated keys are
visible at all.

Every path in the Button section, and every one of the 44 Font paths, appears in the
output below; none was invented. The three families Font delegates to are shown at their
own canonical prefixes rather than repeated inside Font's listing.

The extractor also prints four `#`-prefixed header lines (family, class, file, attr)
ahead of the paths. Those are omitted below to keep the blocks readable; the paths and
the trailing summary line are reproduced verbatim.

```
$ php scripts/extract-shared-preset-paths.php <builder5> --family Icon --attr module.decoration.icon
module.decoration.icon__color
module.decoration.icon__size
module.decoration.icon__useSize
resolved 3 preset attr key(s) for family Icon

$ php scripts/extract-shared-preset-paths.php <builder5> --family TextShadow --attr module.decoration.font.textShadow
module.decoration.font.textShadow__blur
module.decoration.font.textShadow__color
module.decoration.font.textShadow__horizontal
module.decoration.font.textShadow__style
module.decoration.font.textShadow__vertical
resolved 5 preset attr key(s) for family TextShadow

$ php scripts/extract-shared-preset-paths.php <builder5> --family Font --attr module.decoration.font
module.decoration.font.font__capitalization
module.decoration.font.font__color
module.decoration.font.font__columnCount
module.decoration.font.font__columnGap
module.decoration.font.font__family
module.decoration.font.font__hyphens
module.decoration.font.font__letterSpacing
module.decoration.font.font__lineColor
module.decoration.font.font__lineHeight
module.decoration.font.font__lineStyle
module.decoration.font.font__lineThickness
module.decoration.font.font__opticalSizing
module.decoration.font.font__size
module.decoration.font.font__style
module.decoration.font.font__textAlign
module.decoration.font.font__textWrap
module.decoration.font.font__underlineOffset
module.decoration.font.font__weight
module.decoration.font.font__weightFineTune
module.decoration.font.font__writingMode
module.decoration.font.textEffects__fillType
module.decoration.font.textEffects__gradient
module.decoration.font.textEffects__gradient.direction
module.decoration.font.textEffects__gradient.directionRadial
module.decoration.font.textEffects__gradient.length
module.decoration.font.textEffects__gradient.repeat
module.decoration.font.textEffects__gradient.type
module.decoration.font.textEffects__imageFill.blend
module.decoration.font.textEffects__imageFill.height
module.decoration.font.textEffects__imageFill.horizontalOffset
module.decoration.font.textEffects__imageFill.position
module.decoration.font.textEffects__imageFill.repeat
module.decoration.font.textEffects__imageFill.size
module.decoration.font.textEffects__imageFill.url
module.decoration.font.textEffects__imageFill.verticalOffset
module.decoration.font.textEffects__imageFill.width
module.decoration.font.textEffects__strokeColor
module.decoration.font.textEffects__strokePosition
module.decoration.font.textEffects__strokeWidth
module.decoration.font.textShadow__blur
module.decoration.font.textShadow__color
module.decoration.font.textShadow__horizontal
module.decoration.font.textShadow__style
module.decoration.font.textShadow__vertical
resolved 44 preset attr key(s) for family Font
```

Button's own ten documented keys, filtered out of its 149-key resolution (the other 139
belong to the seven delegates, each of which keeps its own prefix, listed in the
[delegate table](#button)):

```
$ php scripts/extract-shared-preset-paths.php <builder5> --family Button --attr button
button.decoration.button__icon.color
button.decoration.button__icon.enable
button.decoration.button__icon.onHover
button.decoration.button__icon.placement
button.decoration.button__icon.settings
button.decoration.sizing__alignment
button.innerContent__linkTarget
button.innerContent__linkUrl
button.innerContent__rel
button.innerContent__text
resolved 149 preset attr key(s) for family Button
```

The same 149-key resolution grouped by the attribute each key hangs off, which is where
the delegate table's counts come from:

```
50  button.decoration.background
20  button.decoration.sizing
20  button.decoration.font.font
19  button.decoration.font.textEffects
17  button.decoration.border
 7  button.decoration.boxShadow
 5  button.decoration.font.textShadow
 5  button.decoration.button
 4  button.innerContent
 2  button.decoration.spacing
```

The path sets asserted here are also asserted mechanically in
`tests/test-shared-preset-attrs-map.php`, which runs against a real Divi tree whenever
`DIVIOPS_DIVI_BUILDER5_PATH` points at one.

## Round-trip status

The seven advanced families carry a **VB round-trip** line in their Provenance
paragraphs: the documented paths were written to a scratch page through
`diviops_module_update`, read back through both `diviops_module_get` and an independent
`wp post get`, and the page trashed.

The five Tier 2 families above have **not** been round-tripped, and carry
`<!-- UNVERIFIED -->` accordingly — the tier
[SKILL.md's verification convention](../SKILL.md#verification-convention) defines as
"neither VB-tested nor render-confirmed", which is exactly what they are. That marker is
the floor, not the whole story: every path, value shape, enum, unit, and feature flag
here is traced to Divi's own PHP or compiled VB JS and cross-checked against the
extractor, but none has been written to a live page and read back. Two consequences:

- The **paths** are as solid as the advanced families' — same source, same extractor,
  plus a test asserting them.
- The **serialized round-trip** is unproven: nothing here rules out the write path
  reshaping one of these fragments (the doubled `font.font` segment and Button's nested
  `icon.settings` object are the two most plausible candidates).

Round-tripping requires creating and trashing scratch pages on the maintainer's live
working site, which this repository's `CLAUDE.md` gates behind the maintainer's explicit
approval. That approval was not available in the session this was written, so the work
was left undone rather than done unapproved. Promoting these five to *VB-verified* is a
follow-up, not a rewrite.
