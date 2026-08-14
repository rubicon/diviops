# Divi 5 `$variable()` Bindings — The Shared Token Wrapper

Reference for `$variable({...})$`, the single token syntax Divi 5 uses to bind a module
attribute to *something resolved later*. This file documents the token grammar, every
namespace that shares the wrapper, how each one resolves, and how to tell them apart —
because `type` alone does not.

**Read this before writing any `$variable()` token into module attrs.** The most common
and most expensive mistake in this area is assuming `$variable()` means "dynamic
content". It does not. It is a shared wrapper carrying at least five distinct
namespaces, and treating a design-token binding as a dynamic-content binding produces
confident, wrong diagnostics.

**Clean-room provenance**: every claim below is derived from Divi 5's own source on a
Divi 5.9.0 install — `Packages/Conversion/Conversion.php`,
`Packages/Module/Layout/Components/DynamicData/DynamicData.php`,
`Packages/Module/Layout/Components/DynamicContent/DynamicContentGlobalVariableOptions.php`,
`Packages/StyleLibrary/Utils/Utils.php`, `Packages/StyleLibrary/Utils/GradientUtils.php`,
`Packages/GlobalData/GlobalData.php`, and Divi's own compiled Visual Builder JS
(`visual-builder/build/*.js`) — cross-checked against the live registry and stored page
content of this fork's reference site via the read-only `diviops_dynamic_content_*` and
`diviops_variable_list` tools. None of this was read from, derived from, or cross-checked
against `diviops-agent-pro`; that plugin was never opened while authoring this file. See
`SKILL.md`'s [Verification convention](../SKILL.md#verification-convention) for what the
`*(verified …)*` / `*(VB-verified …)*` / `<!-- UNVERIFIED -->` tags below mean.

**Related, non-overlapping**: [presets.md → Variable Tokens](presets.md#variable-tokens)
owns the Variable Manager's storage buckets and the byte-level escaping pathologies;
[module-formats.md → Design Token References in Attrs](module-formats.md#design-token-references-in-attrs-canonical-variable-only)
owns the "canonical token, never a foreign `var()`" rule. This file owns the token
itself: its grammar, its namespaces, and its resolution.

---

## Table of contents

- [The one rule that matters](#the-one-rule-that-matters)
- [Token grammar](#token-grammar)
- [Namespace catalog](#namespace-catalog)
- [Namespace 1 — Dynamic content](#namespace-1--dynamic-content-typecontent-registered-name)
- [Namespace 2 — Global colors](#namespace-2--global-colors-typecolor-gcid-)
- [Namespace 3 — Global variables](#namespace-3--global-variables-typecontent-gvid--and-customizer-fonts)
- [Namespace 4 — Gradients](#namespace-4--gradients-typegradient-gvid-)
- [Namespace 5 — Images and shortcodes](#namespace-5--images-and-shortcodes)
- [How Divi resolves a token](#how-divi-resolves-a-token)
- [Legacy D4 `@ET-DC@` form](#legacy-d4-et-dc-form)
- [Telling the namespaces apart](#telling-the-namespaces-apart)
- [Worked binding examples](#worked-binding-examples)
- [Tooling: what validates what](#tooling-what-validates-what)
- [Failure modes](#failure-modes)
- [Verification index](#verification-index)

---

## The one rule that matters

> `$variable(...)$` is Divi's **shared** variable wrapper. The `type` field does **not**
> discriminate between namespaces. A global-variable design token and a real
> dynamic-content binding are byte-identical in shape and both carry `"type":"content"`.

Live proof on this fork's reference site, from a complete census of all 192 posts whose
`post_content` contains `$variable(` *(verified 2026-08-14, Divi 5.9.0)*:

| Decoded `type` | Decoded `value.name` | Occurrences | What it actually is |
|---|---|---|---|
| `content` | `gvid-*` | 2786 | Global **variables** (spacing/size/number design tokens) |
| `content` | `post_excerpt`, `home_url`, `post_featured_image`, `post_link_url`, `post_link_url_page` | 1543 | Real **dynamic content** |
| `content` | `--et_global_body_font`, `--et_global_heading_font` | 95 | Customizer **fonts** |
| `content` | `et_global_body_font_weight`, `et_global_heading_font_weight` | 21 | Customizer font **weights** |
| `color` | `gcid-*` | 1960 | Global **colors** |

The first four rows all say `"type":"content"`. Exactly one of them is dynamic content.

The practical consequence, demonstrated live *(verified 2026-08-14)*:

```jsonc
// A perfectly valid, actively-rendering global-variable token…
"$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-4\",\"settings\":{}}})$"
```

```jsonc
// …reported as invalid by the dynamic-content validator, correctly:
{ "valid": false,
  "errors": [ { "code": "unknown_option",
                "message": "'gvid-oa-space-4' is not a registered dynamic content option (post_id: 0, context: edit)." } ],
  "type": "content" }
```

That is not a bug in the validator. Global colors, global variables, and gradients are
**deliberately never registered** in the dynamic-content registry:
`DynamicContentGlobalVariableOptions::register_option_callback()` returns the options
array unchanged (`DynamicContentGlobalVariableOptions.php:66-70`) — they resolve on a
different filter entirely. So "not in the dynamic-content registry" is the *expected*
state for a design token, not evidence of breakage.

This is exactly why the plugin's `module_update()` write-path guard
(`dynamic_content_write_path_rejection()`, `trait-dynamic-content.php`) fails **open** on
the `gcid-`/`gvid-`/`gfid-` namespace and on any non-`content` type. A guard that treated
"absent from the dynamic-content registry" as "invalid" would reject the plugin's own
primary documented design-token write path.

---

## Token grammar

Divi's canonical emitter is one function, `Conversion::formatDynamicContent()`
(`Packages/Conversion/Conversion.php:668-676`):

```php
public static function formatDynamicContent($name, $settings, $type = 'content') {
    return '$variable(' . json_encode([
        'type' => $type,
        'value' => [
            'name' => $name,
            'settings' => (object) $settings,
        ],
    ], JSON_UNESCAPED_UNICODE) . ')$';
}
```

Which yields, for `('post_title', [], 'content')`:

```
$variable({"type":"content","value":{"name":"post_title","settings":{}}})$
```

Grammar rules, each load-bearing:

| Rule | Why |
|---|---|
| Opens with `$variable(` and closes with `)$` | Both extraction regexes require it: `DynamicData::get_variable_values()` uses `/\$variable\((.+?)\)\$/` (`DynamicData.php:391-395`); `Utils::resolve_dynamic_variable()` uses the same (`Utils.php:97-98`). Drop the trailing `$` and the token is never matched — it emits into the page as literal text. |
| Payload is a JSON **object**, never a bare id | `$variable(gvid-abc)$` decodes to `null`, `value.name` is absent, and both resolvers return the original string unchanged. |
| `settings` is cast to a JSON **object** | `(object) $settings` — empty settings must serialize as `{}`, not `[]`. A hand-built `[]` is what `json_encode` produces from an empty PHP array, and it is the single most common hand-authoring defect. |
| `JSON_UNESCAPED_UNICODE` | Divi does not `\u`-escape non-ASCII in the payload. Matching this matters for byte-identical round-trips. |
| One token per attribute value | Multiple inline tokens render on the frontend but the VB destroys them on save — see [SKILL.md → Dynamic Content](../SKILL.md#dynamic-content-variable). |
| Never nest a token inside another token's `settings` | A token placed in a `before`/`after` setting does not resolve. |

The plugin reproduces this encoder byte-for-byte in
`dynamic_content_format_token()` (`trait-dynamic-content.php`), and
`diviops_dynamic_content_build` is the safe way to obtain a token without hand-encoding
it *(verified 2026-08-14)*:

```jsonc
// diviops_dynamic_content_build { "name": "post_title" }
{ "token": "$variable({\"type\":\"content\",\"value\":{\"name\":\"post_title\",\"settings\":{}}})$",
  "name": "post_title", "settings": [], "type": "content" }
```

### Storage escaping

Inside block-attrs JSON the payload's inner quotes are escaped. Two forms are correct and
decode identically; three are pathological and break renders or block parsing. That whole
subject — including the `wp_update_post` slash-stripping hazard and the recovery
`str_replace` — is documented once, in
[presets.md → Variable Tokens](presets.md#variable-tokens). Do not duplicate it; read it
there before writing tokens from anything other than the MCP write tools.

One live data point worth carrying here: the same 192-post census found **3082**
`$variable(` payloads on this site stored in the stripped-backslash `u0022` form (bare
`u0022` with no leading backslash, i.e. undecodable), alongside 6000+ correct ones
*(verified 2026-08-14)*. The corruption class in presets.md is not theoretical; it is
present on a real site right now, and it is why the MCP write tools normalize token
regions on write.

---

## Namespace catalog

Five namespaces share the wrapper. `type` narrows but never fully determines which:

| # | Namespace | `type` | `value.name` shape | Resolves to | Registered in DC registry? |
|---|---|---|---|---|---|
| 1 | Dynamic content | `content` | registered option name (`post_title`, `custom_meta_*`, …) | rendered content string | **Yes** — that registry is its definition |
| 2 | Global colors | `color` | `gcid-*` | `var(--gcid-*)`, optionally HSL-wrapped | No, by design |
| 3 | Global variables | `content` | `gvid-*`, or `--et_global_*` customizer fonts | `var(--gvid-*)` / `var(--et_global_*)` | No, by design |
| 4 | Gradients | `gradient` | `gvid-*` (reference) or the literal `gradient` (definition) | `var(--gvid-*)` / concrete stops | No, by design |
| 5a | Images | `image` | `gvid-*` | image URL / `url(...)` | No, by design |
| 5b | Shortcodes | `shortcode` | *(no `name` at all)* — `value.content` + `value.post_id` | `do_shortcode()` output | N/A |

Namespace 5b is the one shape that breaks the `value.name` assumption entirely. Any
consumer that reads `value.name` unconditionally will mis-handle it.

---

## Namespace 1 — Dynamic content (`type:"content"`, registered name)

The only namespace whose valid names come from a registry. That registry is the live
WordPress filter, not a static class list:

```php
apply_filters( 'divi_module_dynamic_content_options', [], $post_id, $context )
```

Every one of Divi's `DynamicContentOption*` classes registers through it, which is why
ACF/SCF fields that actually exist on the site appear alongside Divi's built-ins. On this
fork's reference site the filter yields **91** options at `post_id=0, context=edit`
*(verified 2026-08-14)*, spread across groups: `Default` (27), `Divi Library Layouts
(DDCH)` (29), `Loop` (12), `Loop Menus` (7), `Loop Users` (6), `Loop Terms` (6), plus four
single-entry groups (`Global Dynamic Content Sources (DDCH)` and the three per-scope
custom-field groups). The count is site-specific by construction — never hard-code it.

Read it with `diviops_dynamic_content_list`. Each entry looks like:

```jsonc
"post_date": {
  "id": "post_date", "label": "Post Publish Date", "type": "text",
  "custom": false, "group": "Default",
  "fields": {
    "before": { "label": "Before", "type": "text", "default": "" },
    "after":  { "label": "After",  "type": "text", "default": "" },
    "date_format": { "label": "Date Format", "type": "select",
                     "options": { "default": "Default", "M j, Y": "…", "custom": "Custom" },
                     "default": "default" },
    "custom_date_format": { "label": "Custom Date Format", "type": "text",
                            "default": "", "show_if": { "date_format": "custom" } }
  }
}
```

`fields` is the settings schema. Keys outside it are rejected by
`diviops_dynamic_content_build` / `_validate`.

**`fields` can be absent entirely.** `post_featured_image` registers with no `fields` key
at all, so its allowed-settings set is empty and *any* setting is an error
*(verified 2026-08-14)*:

```jsonc
// diviops_dynamic_content_validate { "name": "post_featured_image", "settings": { "before": "x" } }
{ "valid": false,
  "errors": [ { "code": "unknown_setting", "field": "before", "allowed": [],
                "message": "'before' is not a valid setting for dynamic content option 'post_featured_image'." } ] }
```

Settings are carried verbatim into the token *(verified 2026-08-14)*:

```jsonc
// diviops_dynamic_content_build
//   { "name": "post_date",
//     "settings": { "date_format": "custom", "custom_date_format": "l, F j, Y",
//                   "before": "Published ", "after": "" } }
"$variable({\"type\":\"content\",\"value\":{\"name\":\"post_date\",\"settings\":{\"date_format\":\"custom\",\"custom_date_format\":\"l, F j, Y\",\"before\":\"Published \",\"after\":\"\"}}})$"
```

`value` may also carry a sibling `post_id` alongside `name`/`settings` — Divi's own VB
emits that for loop-scoped meta options, and `DynamicData` injects the ambient
`$post_id` into `value` when the token does not already carry one
(`DynamicData.php:296-297`). Observed live on this site as
`{"name":"post_link_url_page","settings":{"post_id":"306"}}`, i.e. the same idea
expressed inside `settings` for that option *(verified 2026-08-14)*.

---

## Namespace 2 — Global colors (`type:"color"`, `gcid-`)

Divi emits these through the same encoder with an explicit third argument:
`Conversion::formatDynamicContent( $globalColorId, [], 'color' )` — seven call sites in
`Conversion.php` (lines 379, 414, 433, 464, 481, 501, 2901), all in the D4→D5 color
migration path. The VB's own runtime builds the identical shape
(`global-data.js`, `` `$variable(${JSON.stringify({type:"color",value:{name:e,settings:{}}})})$` ``).

```jsonc
"$variable({\"type\":\"color\",\"value\":{\"name\":\"gcid-oa-primary-500\",\"settings\":{}}})$"
```

### `settings` on a color token is an HSL state transform

This is the one namespace where `settings` changes the *emitted CSS*, not the content.
`Utils::resolve_dynamic_variable()` routes `type:"color"` through
`GlobalData::transform_state_into_global_color_value()` (`GlobalData.php:181-222`), which
reads four optional keys:

| Setting | Meaning | Emitted as |
|---|---|---|
| `hue` | relative hue offset (default `0`) | `calc(h + N)` / `calc(h - N)` |
| `saturation` | relative saturation offset (default `0`) | `calc(s + N)`, clamped to `max(0, …)` when negative |
| `lightness` | relative lightness offset (default `0`) | `calc(l + N)` / `calc(l - N)` |
| `opacity` | percentage, `100` = opaque | ` / <opacity/100>` |

The function returns the bare `var(--gcid-*)` only when `settings` is empty, or when
`hue`/`saturation`/`lightness` are all `0` **and** no `opacity` key is present at all.
Otherwise it emits a relative-color-syntax expression:

```css
hsl(from var(--gcid-oa-primary-500) calc(h + 0) calc(s + 0) calc(l + 0) / 0.05)
```

An explicit `"opacity": 100` is preserved as `/ 1` rather than dropped — the function
distinguishes "absent" from "explicitly 100" via `array_key_exists`. Do not assume
`opacity: 100` is a no-op you can safely add.

`presets.md` carries a worked opacity example
(`{"name":"gcid-oa-white","settings":{"opacity":5}}`); this section is its mechanism.

---

## Namespace 3 — Global variables (`type:"content"`, `gvid-`) and customizer fonts

The design-token namespace, and the one that collides shape-for-shape with dynamic
content. Canonical form:

```jsonc
"$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-4\",\"settings\":{}}})$"
```

Divi's registration side is explicit about the collision being intentional.
`DynamicContentGlobalVariableOptions` declares `get_name(): 'gvid-'`
(`DynamicContentGlobalVariableOptions.php:32-34`), then registers **nothing**:

```php
public function register_option_callback( array $options, $post_id, $context ): array {
    // The global variable option doesn't have any settings. Meanwhile, this method is
    // needed to satisfy the interface. So, we simply return all the options here.
    return $options;
}
```

Resolution happens on the separate `divi_module_dynamic_content_resolved_value` filter
via that class's `render_callback()` instead.

### Storage buckets and what each resolves to

Divi's global-variable store (`GlobalData::get_global_variables()`, `GlobalData.php:921-929`)
has seven buckets: `numbers`, `strings`, `images`, `links`, `colors`, `fonts`,
`gradients`. The import validator accepts six of them —
`[ 'numbers', 'strings', 'images', 'links', 'fonts', 'gradients' ]` (`GlobalData.php:1112`) —
`colors` being handled by the separate `gcid-` path.

`DynamicContentGlobalVariableOptions::get_variable_value_by_id()` (`:81-100` in that
same file) splits resolution by bucket:

| Bucket | Resolves to | Note |
|---|---|---|
| `numbers`, `fonts` | `var(--<id>)` | The CSS custom property is auto-emitted into `:root`; the module attr never carries the literal value |
| everything else (`strings`, `links`, `images`, …) | the stored `value` itself | `strings` additionally goes through `wp_kses_post` rather than `esc_html` in that class's `render_callback()` (`:148-153`) |

Live example of a real `numbers` token on this fork's reference site, from
`diviops_variable_list` *(verified 2026-08-14)*:

```jsonc
{ "id": "gvid-2h9z633u6k", "type": "numbers", "label": "space-3xl",
  "value": "clamp(1.6018rem, calc(0.14266 * (100vw - 360px) + 25.6289px), 10.2803rem)",
  "status": "active" }
```

Referenced from a module attr as
`$variable({"type":"content","value":{"name":"gvid-2h9z633u6k","settings":{}}})$`, which
the style resolver emits as `var(--gvid-2h9z633u6k)`.

`settings` is unused for this namespace. Send `{}`.

### Customizer fonts: `--et_global_*`

Two ids are special-cased and are the only global variables whose `name` legitimately
starts with `--`:

```php
public static $customizer_fonts = [
    '--et_global_heading_font' => [ 'label' => 'Heading', 'option_name' => 'heading_font', … ],
    '--et_global_body_font'    => [ 'label' => 'Body',    'option_name' => 'body_font',    … ],
];
```
(`GlobalData.php:93-104`)

Both resolvers handle the leading `--` explicitly:

- Content path: `DynamicData.php:301-302` short-circuits *before* the type dispatch and
  returns `sprintf( 'var(%s)', $name )` for exactly those two names.
- Style path: `Utils.php:120-123` strips a leading `--` with
  `preg_replace( '/^--/', '', $name )` "to prevent double-prefix (e.g. `var(----name)`)"
  before building `var(--{$normalized_name})`.

Observed live on this site in two storage spellings whose *decoded* names differ:
`--et_global_body_font` (correct) and `\u002d\u002det_global_body_font` (the leading
dashes' own `\u002d` escapes over-escaped one level, so they decode to literal text rather
than to `--`) *(verified 2026-08-14)*. The second is the same corruption class presets.md
documents, here biting the leading dashes rather than the payload's quotes.

The matching **weights** are referenced without the `--`, as
`et_global_body_font_weight` / `et_global_heading_font_weight` (21 occurrences live), and
rely on the style path's `var(--{name})` default branch rather than the two-name
short-circuit. Divi's own VB reads `var(--et_global_heading_font_weight)` /
`var(--et_global_body_font_weight)` back (`visual-builder/build/module.js`).

### `gfid-`: a DiviOps namespace, not a Divi one

`gfid-*` is this plugin's own global-font catalog (`et_global_data.global_fonts`, see
[tools.md](tools.md)), **not** a Divi-native store. It appears in Divi's source exactly
once, in the VB's `normalizeCssVar` guard, which refuses to re-wrap a `var(--X)` back into
a token when `X` starts with `gcid`/`gvid`/`gfid`
(`visual-builder/build/module-utils.js`) *(verified 2026-08-14: one occurrence in the VB
bundles; zero occurrences anywhere in Divi's server PHP)*. That guard is why the plugin's
write-path rejection regex covers all three prefixes: Divi itself treats the trio as one
"already a global CSS variable, leave it alone" family.

---

## Namespace 4 — Gradients (`type:"gradient"`, `gvid-`)

Gradients use the wrapper in **two different roles**, and confusing them is the failure
mode here.

**Definition form** — the *stored value of the gradient variable itself*, where
`value.name` is the literal string `"gradient"` and `settings` carries the whole gradient.
Live from `diviops_variable_list { "type": "gradients" }` on this fork's reference site
*(verified 2026-08-14)*:

```jsonc
{ "id": "gvid-3l7x5tqc84", "type": "gradients", "label": "Lions Solid Gradient",
  "value": "$variable({\"type\":\"gradient\",\"value\":{\"name\":\"gradient\",\"settings\":{\"enabled\":\"on\",\"stops\":[{\"position\":\"0\",\"color\":\"#0d2240\"},{\"position\":\"50\",\"color\":\"#00338d\"},{\"position\":\"100\",\"color\":\"#7a2582\"}],\"length\":\"100%\",\"type\":\"linear\",\"direction\":\"90deg\",\"directionRadial\":\"center\",\"overlaysImage\":\"off\",\"repeat\":\"off\"}}})$" }
```

**Reference form** — what goes into a module attr's `gradient.stops`, where `value.name`
is the `gvid-` id and `settings` is empty:

```jsonc
"$variable({\"type\":\"gradient\",\"value\":{\"name\":\"gvid-3l7x5tqc84\",\"settings\":{}}})$"
```

`GradientUtils::gradient_style_declaration()` (`GradientUtils.php:752-774`) accepts three
reference spellings for `gradient.stops` — `var(--gvid-…)`, a bare `gvid-…`, or the
`$variable(...)$` token — and for the token form requires **both** `type === 'gradient'`
**and** a `gvid-` prefixed name before it will emit `var(--{$id})`. A gradient token whose
`type` says `content` does not take that branch.

The value-shape caveat (a gradient variable stored as a raw CSS `linear-gradient(…)`
string is referenced but never defined, so bound modules render nothing) is documented
with its VB round-trip evidence in
[presets.md → Variable Tokens](presets.md#variable-tokens) *(VB-verified 2026-06-15,
existing stamp — not re-verified here)* and
[module-formats.md](module-formats.md). Create gradient variables via the VB Variable
Manager or `diviops_variable_create({type:"gradients", gradient:{…}})`, never by hand.

**Live coverage gap, stated plainly**: the 192-post census on this fork's reference site
found **zero** `type:"gradient"` tokens in `post_content` — only the two definition-form
values in the variable store. The reference form above is source-verified against
`GradientUtils.php` and corroborated by presets.md's existing VB-verified stamp, but was
not observed in stored page content during this pass.

---

## Namespace 5 — Images and shortcodes

### `type:"image"` (`gvid-`) <!-- UNVERIFIED -->

`Utils::is_global_image_variable()` (`Utils.php:223-267`) treats a token as a global image
when `'image' === $type` **and** `value.name` starts with `gvid-`, **or** when the name
matches a registered entry in the `images` bucket regardless of type. Divi's own VB
constructs `type:"image"` payloads (`visual-builder/build/module.js`,
`module-library.js`).

Marked UNVERIFIED: source-only. Zero `type:"image"` tokens were found in this site's
stored content, and no render was exercised. Do not build downstream tooling on this
shape without a live round-trip first.

### `type:"shortcode"` (no `value.name`) <!-- UNVERIFIED -->

The one shape that omits `name` entirely:

```jsonc
"$variable({\"type\":\"shortcode\",\"value\":{\"content\":\"[my_shortcode]\",\"post_id\":306}})$"
```

`DynamicData.php:306-322` runs `value.content` through
`ShortcodeUtils::get_processed_embed_shortcode()` and then `do_shortcode()`, inside the
post context named by `value.post_id` (falling back to the ambient `$post_id`). Divi's own
comment says it is "Used by the Visual Builder when loop items contain shortcodes in their
content fields", and the constructor lives in the VB bundle, not the server.

Marked UNVERIFIED: source-only, zero live occurrences on this site, no render exercised.

**The consumer-side lesson generalizes even though the namespace is rare**: any code that
reads `value.name` without checking that it exists will mis-classify this token. The
plugin's own modern-token parser requires `isset( $decoded['value']['name'] )` and reports
`malformed_token` otherwise, which — combined with the write-path guard failing open on
`malformed_token` — means a shortcode token passes through `module_update` untouched
rather than being rejected. Correct behavior, reached by the fail-open policy rather than
by explicit handling.

---

## How Divi resolves a token

There is no single resolver. There are two, they dispatch differently, and which one runs
depends on whether the attribute feeds **content** or **CSS**.

### Content path — `DynamicData::get_processed_dynamic_data()`

`Packages/Module/Layout/Components/DynamicData/DynamicData.php:226-377`. Extracts every
token with `/\$variable\((.+?)\)\$/`, decodes, then dispatches in this exact order
(`:301-330`):

1. `name` is `--et_global_body_font` or `--et_global_heading_font` → `var(<name>)` (before any type check)
2. `type === 'content'` → `DynamicContentUtils::get_processed_dynamic_content()`
3. `type === 'shortcode'` → `do_shortcode()` in the resolved post context
4. `type === 'color'` → delegates to the style path's `Utils::resolve_dynamic_variable()`
5. **anything else** → `$resolved_value` stays `null`, and a `null` is explicitly *not*
   substituted, so the raw token survives into the output

Step 5 is the reason a `type:"gradient"` or `type:"image"` token placed in a *content*
field emits as literal text instead of resolving: this path has no branch for it. Those
types belong on CSS-bearing attributes.

The results are memoized in a static `$cache` keyed by the token string plus post/loop
context (`DynamicData.php:235`, `:246-280`). Each *freshly computed* resolution passes
through the `divi_module_dynamic_data_resolved_value` filter; a cache hit returns early
and skips it, so a filter callback must not be relied on to fire once per occurrence.

### Style path — `Utils::resolve_dynamic_variable()`

`Packages/StyleLibrary/Utils/Utils.php:86-141`. Same extraction regex, then:

1. ACF color-picker fields (`custom_meta_*` with `type:"color"`, or legacy
   `post_meta_key` + `select_meta_key: custom_meta_*`) resolve to the raw meta value with
   `before`/`after` concatenation, skipping HSL entirely (`:280-362`). The custom-field
   *authoring* side of that shape — discovering field groups and choosing between the
   `custom_meta_*` and `post_meta_key` + `select_meta_key` forms — belongs to
   [scf-fields.md](scf-fields.md), not here; this section covers only how the style
   resolver treats such a token once it exists
2. Otherwise strip a leading `--` from `name`, build `var(--{$name})`
3. `type === 'color'` → wrap through `transform_state_into_global_color_value()`
4. **default** → return `var(--{$name})` unchanged

Note the shape of step 4: on the style path, *every* type that is not `color` resolves to
a plain CSS variable reference. That is why `gvid-*` design tokens work with
`"type":"content"` — they never needed a type of their own.

`resolve_dynamic_variables_recursive()` (`:58-71`) walks nested attribute arrays and
applies the above to every string containing `$variable(`.

---

## Legacy D4 `@ET-DC@` form

Divi 4 stored dynamic content as a base64 envelope, not a JSON token:

```
@ET-DC@<base64>@
```

`Conversion::DYNAMIC_CONTENT_REGEX = '/@ET-DC@([^@]+)@/'` (`Conversion.php:54`), and
`convertDynamicContent()` (`:634-655`) is the migration:

```php
$decoded = base64_decode($encoded);
if ($encoded !== base64_encode($decoded)) { return $matches[0]; }   // round-trip check
$parsed = json_decode($decoded, true);
if (!isset($parsed['dynamic'], $parsed['content'], $parsed['settings'])) { return $matches[0]; }
return self::formatDynamicContent($parsed['content'], $parsed['settings']);
```

Three things follow from reading that literally:

- The decoded payload is `{dynamic, content, settings}` — **`content` is the option name**,
  which becomes `value.name` in the modern form. Not the rendered content.
- Divi verifies the base64 round-trips (`$encoded !== base64_encode($decoded)`) before
  trusting it, and leaves a non-round-tripping match untouched.
- Migration always produces `type:"content"` — `formatDynamicContent` is called with two
  arguments, so the default applies. **There is no legacy encoding of a global color,
  global variable, or gradient**; those namespaces are Divi-5-only.

`diviops_dynamic_content_validate` auto-detects and decodes both forms, and reports the
exact modern equivalent for a legacy one *(verified 2026-08-14, live)*:

```jsonc
// value: "@ET-DC@eyJkeW5hbWljIjp0cnVlLCJjb250ZW50IjoicG9zdF90aXRsZSIsInNldHRpbmdzIjp7fX0=@"
{ "valid": true, "errors": [], "name": "post_title", "settings": [], "type": "content",
  "legacy_format": true,
  "modern_equivalent": "$variable({\"type\":\"content\",\"value\":{\"name\":\"post_title\",\"settings\":{}}})$" }
```

Detection order matters and the plugin gets it right: it checks the `$variable(` **prefix
first**, then falls back to an `@ET-DC@` substring search. A modern token whose *settings*
contain the literal text `@ET-DC@` (a text field documenting D4 tokens, for instance)
would otherwise be misrouted to the legacy parser.

This fork never emits the legacy form. It recognizes it because a D4-migrated site can
still carry it. The census on this site found **zero** `@ET-DC@` occurrences in
`post_content` *(verified 2026-08-14)*.

---

## Telling the namespaces apart

`type` is necessary but not sufficient. Use this order:

```
1. Does the payload decode as JSON with a `value` object?
   No  → not a token. Leave it alone. (Malformed tokens are NOT a write-time error.)

2. Is `value.name` absent?
   Yes → type:"shortcode" (or a corrupt payload). Do not treat as a binding.

3. type === "color"?
   Yes → global color. Name is gcid-*. settings = HSL state transform.

4. type === "gradient"?
   Yes → name === "gradient"  → gradient DEFINITION (this token IS the variable's value)
         name === "gvid-*"    → gradient REFERENCE

5. type === "image"?
   Yes → global image variable. Name is gvid-*.

6. type === "content" — now the ambiguity is real. Look at the NAME:
   name starts with gcid-/gvid-/gfid-  → design token, NOT dynamic content
   name is "--et_global_heading_font" or "--et_global_body_font" → customizer font
   name matches "et_global_*_font_weight"                        → customizer font weight
   otherwise → look it up in the LIVE dynamic-content registry
       present → dynamic content, validate settings against its `fields`
       absent  → UNKNOWN. Report, do not assume broken.
```

Step 6's final branch is where judgement is required. "Absent from the registry" has at
least three innocent causes:

- the registry is empty or unavailable (a D4-only site, Divi deactivated, or a call made
  outside a REST request where the filter never runs)
- the option is registered only for a specific `post_id`/`context` and the lookup used a
  different one — several options gate on post or Theme-Builder context
- the name belongs to a namespace this procedure does not yet know about

The plugin's write-path guard encodes exactly this caution: it rejects **only** a
well-formed `type:"content"` token whose name is definitively absent from a **non-empty**
registry and does not match `gcid-`/`gvid-`/`gfid-`. Everything else — malformed, other
namespace, empty registry, settings-schema nuance — is allowed through. A stricter guard
would break legitimate page edits; that is the whole design rationale, recorded in
`dynamic_content_write_path_rejection()`'s docblock.

---

## Worked binding examples

All attr paths below follow [module-formats.md](module-formats.md)'s conventions. Tokens
are written in the canonical 2-byte `\"` storage escaping, which is what the MCP write
tools emit; the other escape forms you may encounter in existing content are covered in
[presets.md](presets.md#variable-tokens).

### Bind a heading's text to the post title (dynamic content)

```jsonc
{
  "title": {
    "innerContent": {
      "desktop": {
        "value": "$variable({\"type\":\"content\",\"value\":{\"name\":\"post_title\",\"settings\":{}}})$"
      }
    }
  }
}
```

Obtain the token with `diviops_dynamic_content_build { "name": "post_title" }` rather than
hand-typing it.

### Bind a heading's color to a global color, at 5% opacity

```jsonc
{
  "title": {
    "decoration": {
      "font": { "font": { "desktop": { "value": {
        "color": "$variable({\"type\":\"color\",\"value\":{\"name\":\"gcid-oa-primary-500\",\"settings\":{\"opacity\":5}}})$"
      } } } }
    }
  }
}
```

Emits `hsl(from var(--gcid-oa-primary-500) calc(h + 0) calc(s + 0) calc(l + 0) / 0.05)`.

### Bind module padding to spacing design tokens (global variables)

```jsonc
{
  "module": {
    "decoration": {
      "spacing": { "desktop": { "value": { "padding": {
        "top":    "$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-2\",\"settings\":{}}})$",
        "bottom": "$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-2\",\"settings\":{}}})$",
        "left":   "$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-4\",\"settings\":{}}})$",
        "right":  "$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-oa-space-4\",\"settings\":{}}})$"
      } } } }
    }
  }
}
```

`"type":"content"` here is correct and deliberate. Do not "fix" it to a `spacing` or
`number` type — no such type exists, and the style resolver's default branch is what makes
this work.

### Bind a section background to a gradient variable

```jsonc
{
  "module": {
    "decoration": {
      "background": { "desktop": { "value": { "gradient": {
        "enabled": "on",
        "stops": "$variable({\"type\":\"gradient\",\"value\":{\"name\":\"gvid-3l7x5tqc84\",\"settings\":{}}})$"
      } } } }
    }
  }
}
```

Keep `enabled: "on"`. The referenced `gvid-` must be stored in the structured definition
form — see [Namespace 4](#namespace-4--gradients-typegradient-gvid-).

### Bind body typography to the customizer font

```jsonc
{
  "content": {
    "decoration": {
      "bodyFont": { "body": { "font": { "desktop": { "value": {
        "family": "$variable({\"type\":\"content\",\"value\":{\"name\":\"--et_global_body_font\",\"settings\":{}}})$",
        "weight": "$variable({\"type\":\"content\",\"value\":{\"name\":\"et_global_body_font_weight\",\"settings\":{}}})$"
      } } } } }
    }
  }
}
```

Note the asymmetry, which is Divi's, not a typo: the **family** id carries the leading
`--`, the **weight** id does not. Both `name` values were observed in live stored content
in exactly these token shapes *(verified 2026-08-14)*; the surrounding `bodyFont` attr
path is the triple-nesting documented in
[module-formats.md](module-formats.md#exceptions-quick-reference), not something this
pass re-verified.

---

## Tooling: what validates what

| Tool | Covers | Does **not** cover |
|---|---|---|
| `diviops_dynamic_content_list` | The live registry for a `(post_id, context)` pair | Global colors / variables / gradients — never registered there, by design |
| `diviops_dynamic_content_build` | Emits a byte-identical `formatDynamicContent()` token for a **registered** name; validates settings against `fields` | Cannot build a `gcid-`/`gvid-` token — a design-token name is rejected as `unknown_option` *(verified 2026-08-14)* |
| `diviops_dynamic_content_validate` | Parses either token form, decodes, validates name + settings; reports `modern_equivalent` for legacy | Correctly reports every design token as `unknown_option`. That is not a defect — see [The one rule](#the-one-rule-that-matters) |
| `diviops_variable_list` / `_create` / `_update` | The `gvid-`/`gcid-` design-token store | Dynamic content |
| `diviops_variable_scan_orphans` | `gvid-`/`gcid-` references with no backing variable | Dynamic-content names |
| `diviops_validate_blocks` | Foreign `var(--alias)` refs in attrs (`foreign_css_variable_ref`) | Payload correctness inside a well-formed token |
| `module_update` write-path guard | Rejects only a well-formed `type:"content"` token naming an option definitively absent from a non-empty registry | Everything else, deliberately — including a token missing its trailing `$` |

That last cell is the sharpest edge. A token missing its trailing `$` is classified
`malformed_token` by the validator *(verified 2026-08-14)* —

```jsonc
// value: "$variable({\"type\":\"content\",\"value\":{\"name\":\"post_title\",\"settings\":{}}})"
{ "valid": false,
  "errors": [ { "code": "malformed_token",
                "message": "Value starts with $variable( but is not a well-formed $variable({...})$ token." } ] }
```

— and the write-path guard **fails open** on `malformed_token`, so `module_update` will
write it without complaint. Run `diviops_dynamic_content_validate` yourself before
writing a hand-authored token; the write path is not a linter and was never meant to be.

---

## Failure modes

| Symptom | Cause | Fix |
|---|---|---|
| Token text appears verbatim on the frontend | Missing trailing `$`, or a bare-id payload (`$variable(gvid-x)$`) | Use the full JSON payload and both delimiters. Neither extraction regex matches otherwise |
| Attr value silently falls back to the property's initial value | Foreign `var(--alias)` written instead of a canonical token | [module-formats.md](module-formats.md#design-token-references-in-attrs-canonical-variable-only) |
| Token resolves in CSS but emits literally in a content field | `type` is `gradient`/`image` on a content-bearing attribute | The content path has no branch for those types (`DynamicData.php:301-330`). Move the binding to a CSS-bearing attr |
| `settings` serialized as `[]` instead of `{}` | Hand-built from an empty PHP/JS array | Divi's encoder casts `(object) $settings`. Use `diviops_dynamic_content_build` |
| Literal `0022` leaks into emitted CSS; token loses its effect | Over-escaped or slash-stripped storage bytes | [presets.md → Variable Tokens](presets.md#variable-tokens) — 3082 such payloads exist on this fork's reference site today |
| Only the first of several tokens in one field survives a VB save | More than one token per attribute value | One token per field |
| A token in a `before`/`after` setting never resolves | Nested tokens are not resolved | Resolve the value ahead of time, or split the binding |
| Validator says `unknown_option` for a token that renders fine | It is a design token, not dynamic content | Expected. Check the `name` prefix before believing the error |
| Token still shows the previous value after editing the variable | Both resolvers memoize per request (`Utils.php:87`, `DynamicData.php:235`) | Flush Divi's cache (`diviops_meta_flush_cache`) and hard-refresh |

---

## Verification index

| Claim | Tier | Evidence |
|---|---|---|
| `formatDynamicContent()` token grammar, including the `(object)` cast | *(verified 2026-08-14)* | `Conversion.php:668-676`; reproduced byte-identically by `diviops_dynamic_content_build` against the live site |
| `DYNAMIC_CONTENT_REGEX` and the legacy `{dynamic, content, settings}` payload | *(verified 2026-08-14)* | `Conversion.php:54`, `:634-655`; live `diviops_dynamic_content_validate` round-trip on a hand-built legacy token |
| Global colors use `type:"color"` with `gcid-` | *(verified 2026-08-14)* | `Conversion.php` (7 call sites), `global-data.js`; 1960 live occurrences in stored content |
| Global variables use `type:"content"` with `gvid-` | *(verified 2026-08-14)* | `DynamicContentGlobalVariableOptions.php:32-34`, `Utils.php:77-78`; 2786 live occurrences in stored content |
| Design tokens are deliberately absent from the dynamic-content registry | *(verified 2026-08-14)* | `DynamicContentGlobalVariableOptions.php:66-70`; live `dynamic_content_validate` returning `unknown_option` for a valid `gvid-` token |
| Color `settings` are an HSL state transform, `opacity:100` is not a no-op | *(verified 2026-08-14)* | `GlobalData.php:181-222` read directly |
| Two customizer font ids, `--` prefix asymmetry vs. the weight ids | *(verified 2026-08-14)* | `GlobalData.php:93-104`, `DynamicData.php:301-302`, `Utils.php:120-123`; 116 live occurrences across both spellings |
| Gradient definition form (`name:"gradient"`) | *(verified 2026-08-14)* | Live `diviops_variable_list { type: "gradients" }` on this fork's reference site |
| Gradient reference form (`name:"gvid-*"`) | *(verified 2026-08-14)*, source + prior stamp | `GradientUtils.php:752-774`; prior *(VB-verified 2026-06-15)* stamp in presets.md. **Zero occurrences in this site's stored content** |
| `type:"image"` | `<!-- UNVERIFIED -->` | `Utils.php:223-267` and VB bundles only. No live occurrence, no render exercised |
| `type:"shortcode"` (no `value.name`) | `<!-- UNVERIFIED -->` | `DynamicData.php:306-322` and VB bundles only. No live occurrence, no render exercised |
| `gfid-` is a DiviOps namespace Divi only guards against | *(verified 2026-08-14)* | One occurrence in `visual-builder/build/module-utils.js`; zero in Divi's server PHP |
| Two-resolver dispatch (content vs style) and the `null` fall-through | *(verified 2026-08-14)* | `DynamicData.php:301-330`, `Utils.php:86-141` read directly |
| Live census figures (192 posts, per-shape counts, 3082 corrupt payloads, 0 `@ET-DC@`) | *(verified 2026-08-14)* | Read-only `$wpdb` census over the whole `wp_posts` table on this fork's reference site, Divi 5.9.0 |
| Registry size (91 options) and group breakdown | *(verified 2026-08-14)* | Live `diviops_dynamic_content_list` at `post_id=0, context=edit`. Site-specific by construction |
| Write-path guard fails open on `malformed_token` | *(verified 2026-08-14)* | `dynamic_content_write_path_rejection()` in `trait-dynamic-content.php`; live validator output for a token missing its trailing `$` |

No frontend render or Visual Builder round-trip was performed by this pass. Every
`*(verified …)*` stamp above means "read from Divi's own source and/or observed in live
registry or storage", never "watched it paint". Upgrading any row to `*(VB-verified …)*`
requires a VB save-and-dump cycle as described in
[SKILL.md](../SKILL.md#verification-convention).
