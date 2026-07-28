# Divi 5 Advanced Attributes — Shared Decoration System

Reference for the "advanced" `module.decoration.*` families that live outside Tier 1's
everyday border/background/spacing set: box shadow, filters, transform, sticky,
transition, scroll, and animation. This file documents them family-by-family, in the
same shape [module-formats.md](module-formats.md) uses for the common set.

**Clean-room provenance**: every path and value shape below is derived from Divi 5's
own source — the shared `Module/Options/<Group>/<Group>PresetAttrsMap.php` classes, the
matching `StyleLibrary/Declarations/<Group>/<Group>.php` CSS-emission classes, and (for
enums/units not exposed in either) Divi's own compiled Visual Builder JS
(`visual-builder/build/*.js`) — cross-referenced against `scripts/extract-decoration-paths.php
<builder5> --shared`, which reads those same `PresetAttrsMap` classes programmatically.
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

---

## Box Shadow

7 subfields, from `module.decoration.boxShadow__{style,horizontal,vertical,blur,spread,color,position}`
*(verified 2026-07-28)*.

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

---

## Filters

9 subfields, from `module.decoration.filters__{blur,brightness,contrast,hueRotate,invert,opacity,saturate,sepia,blendMode}`
*(verified 2026-07-28)*.

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

---

## Path verification

Every path documented above was checked against the authoritative extractor output
(`php scripts/extract-decoration-paths.php <builder5> --shared`, which reads the
`Module/Options/<Group>/<Group>PresetAttrsMap.php` classes directly):

```
$ php scripts/extract-decoration-paths.php <builder5> --shared | grep -E 'boxShadow|filters'
## boxShadow (7)
  module.decoration.boxShadow__blur
  module.decoration.boxShadow__color
  module.decoration.boxShadow__horizontal
  module.decoration.boxShadow__position
  module.decoration.boxShadow__spread
  module.decoration.boxShadow__style
  module.decoration.boxShadow__vertical
## filters (9)
  module.decoration.filters__blendMode
  module.decoration.filters__blur
  module.decoration.filters__brightness
  module.decoration.filters__contrast
  module.decoration.filters__hueRotate
  module.decoration.filters__invert
  module.decoration.filters__opacity
  module.decoration.filters__saturate
  module.decoration.filters__sepia
```

All 7 boxShadow paths and all 9 filters paths documented in this file appear in that
output — none invented. Cross-checked against the per-module extractor
(`php scripts/extract-decoration-paths.php <builder5> Text`), which additionally
confirms the `module.decoration.` prefix is real (Text's own `TextPresetAttrsMap.php`
declares the identical subfield set under that exact prefix).

---

## Transform

5 subfields, from `module.decoration.transform__{origin,rotate,scale,skew,translate}`
*(verified 2026-07-28)*.

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
fallback for hover `origin` inheritance (`:254`), but no caller along the only call path found
(`Module/Options/Transform/TransformStyle.php::style()`, which populates `$additional` with only
`positionAttrs`) was found supplying it — treat automatic hover-`origin` inheritance from the
non-hover value as `<!-- UNVERIFIED -->`; set `origin` explicitly under `hover` if it needs to
differ from (or match) the non-hover value.

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
the hover-resets-to-`none` behavior and the unwired `normalStateOrigin` fallback are both
confirmed in the latter file. VB control-default values (`{x:"100%",y:"100%",linked:"on"}` scale,
`{x:"0px",y:"0px",linked:"on"}` translate, `{x:"0deg",y:"0deg",z:"0deg"}` rotate,
`{x:"0deg",y:"0deg",linked:"on"}` skew, `{x:"50%",y:"50%"}` origin) and per-field labels,
placeholders, and `defaultUnit`s (`px` for translate, `deg` for rotate/skew) confirmed against the
`Bg` defaults object and the `divi/transform-scale` / `divi/transform-translate` /
`divi/transform-rotate` / `divi/transform-skew` / `divi/transform-origin` field definitions in
compiled `visual-builder/build/module.js`; `module.decoration.*` prefix and the full 5-subfield
set cross-checked against
`server/Packages/ModuleLibrary/Text/TextPresetAttrsMap.php:2191-2215`. Path list cross-verified
against `php scripts/extract-decoration-paths.php <builder5> --shared`:

```
$ php scripts/extract-decoration-paths.php <builder5> --shared | grep -E 'transform'
## transform (5)
  module.decoration.transform__origin
  module.decoration.transform__rotate
  module.decoration.transform__scale
  module.decoration.transform__skew
  module.decoration.transform__translate
```

---

## Sticky Position

7 subfields (note the nested dot-paths — this is the one shared family whose subnames are
themselves paths, not flat identifiers), from `module.decoration.sticky__position`,
`sticky__offset.{top,bottom,surrounding}`, `sticky__limit.{top,bottom}`, `sticky__transition`
*(verified 2026-07-28)*.

| Path | Value shape | Notes |
|---|---|---|
| `module.decoration.sticky__position` | enum: `"none"` \| `"top"` \| `"bottom"` \| `"topBottom"` | Default `"none"` (not sticky). VB option labels: "Do Not Stick" / "Stick to Top" / "Stick to Bottom" / "Stick to Top and Bottom" (compiled `visual-builder/build/module.js` option map). The three non-`"none"` values match `StickyUtils::get_valid_sticky_position()` exactly; `"none"` is the implicit off-state default, not part of that helper's return array. |
| `module.decoration.sticky__offset.top` | length, e.g. `"20px"` | Default `"0px"`. `divi/range` field — vertical offset from the top edge of the viewport while stuck. |
| `module.decoration.sticky__offset.bottom` | length, e.g. `"20px"` | Default `"0px"`. Same as above, from the bottom edge. |
| `module.decoration.sticky__offset.surrounding` | enum: `"on"` \| `"off"` | Default `"on"`. `divi/toggle` field. Governs whether this element's own sticky offset accounts for a neighboring sticky element already stuck above/below it, so stacked sticky elements don't overlap. |
| `module.decoration.sticky__limit.top` | enum: `"none"` \| `"body"` \| `"section"` \| `"row"` \| `"column"` | Default `"none"`. **Not a length**, despite the `"none"` default reading like an open-ended value — this is a `divi/select` of container-type keywords (confirmed option map in compiled `visual-builder/build/module.js`): the element stops sticking once the ancestor container of that type scrolls out of view. |
| `module.decoration.sticky__limit.bottom` | enum: `"none"` \| `"body"` \| `"section"` \| `"row"` \| `"column"` | Default `"none"`. Same container-type enum, applied to the bottom edge. |
| `module.decoration.sticky__transition` | enum: `"on"` \| `"off"` | Default `"on"`. `divi/toggle` field. Animates the element's transition between its normal and stuck styles when it becomes (or stops being) sticky. |

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
family. Responsive (tablet/phone) values ARE part of the attrs shape —
`StickyUtils::get_formatted_subname_values()` walks every breakpoint key present under
`attrs.module.decoration.sticky` (`StickyUtils.php:106-130`) — but the one caller that turns this
into actual front-end sticky behavior, `StickyScriptData::set()`, invokes
`StickyUtils::get_sticky_setting()` with a hardcoded `'breakpoint' => 'desktop', 'state' =>
'value'` (`StickyScriptData.php:111-113`; the file's own inline comment flags this breakpoint
value as a `TODO` that "might not be needed", i.e. Divi's own authors flagged it as provisional).
Treat a tablet/phone-only override of any `sticky__*` subfield as **stored in attrs but not
currently read into the front-end sticky behavior**, rather than as a supported responsive
variant.

Minimal copy-paste `attrs` fragment (stick to top, offset by 20px, released once its section
scrolls away):

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
list cross-verified against `php scripts/extract-decoration-paths.php <builder5> --shared`:

```
$ php scripts/extract-decoration-paths.php <builder5> --shared | grep -E 'sticky'
## sticky (7)
  module.decoration.sticky__limit.bottom
  module.decoration.sticky__limit.top
  module.decoration.sticky__offset.bottom
  module.decoration.sticky__offset.surrounding
  module.decoration.sticky__offset.top
  module.decoration.sticky__position
  module.decoration.sticky__transition
```
