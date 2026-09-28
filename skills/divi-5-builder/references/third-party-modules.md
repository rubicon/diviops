# Third-party module element maps

Verified attribute paths for non-native modules — `difl/*` (DiviFlash), `decm/*`, `d5bgo/*`.

**Why this file exists separately from [module-formats.md](module-formats.md).** That file is
**generated** (`npm run regen:skill`) from a committed Divi-core-only artifact: Divi's per-module
`PresetAttrsMap.php` plus `@divi/types`. Neither source knows anything about third-party modules,
which is why `module-formats.md` covers 114 `divi/*` slugs and zero vendor ones. It is not an
oversight in the generator; it is the generator's boundary.

**Where these paths come from, and what that means.** `diviops_schema_get_module` reaches a
third-party module through the live block registry and returns its top-level attribute **names** —
but every one as `{"type": "object"}`, with no leaf structure. So the registry supplies the
vocabulary and nothing else. Leaf paths here were derived by reading real saved instances out of
`post_content` and flattening their attrs. That has a consequence worth stating plainly:

> A path in this file is **observed on a real, working, VB-saved module**. It is not proven
> writable until a round-trip edit confirms it. Where a round-trip has been run, the section says
> so. Where it has not, the section says that too. Do not silently upgrade one to the other.

---

## The grammar carries over. The element names do not.

This is the single most useful finding, and it generalizes past this one family: **`difl/*`
modules use exactly the same attribute grammar as native `divi/*` modules.**

```
<element>.decoration.<group>.<breakpoint>.<state>.<leaf>
<element>.innerContent.<breakpoint>.<state>[.<leaf>]
```

**`<state>` is a slot, not the literal `value`.** The FAQ family only ever showed `value`, which
made `.value.` look like fixed punctuation; the advanced-menu family below carries `hover` in the
same position — `menu_item_obj_settings.decoration.font.font.desktop.hover.color` alongside
`…desktop.value.color`, 9 such leaves across the family. Writing a hover colour into
`.desktop.value.` silently styles the resting state instead, which renders and is wrong.

So everything the skill already teaches about breakpoints, `.value` nesting, `sync*` keys and the
shared `module.decoration.*` vocabulary applies unchanged. Confirmed directly:
`module.decoration.animation.desktop.value.{style,duration,delay,speedCurve,startingOpacity}` is
live on `difl/faq` instances, i.e. the shared module-level decoration vocabulary is inherited, not
reimplemented.

**What differs is only the element names**, and they are vendor-invented and not guessable. Native
modules use `title`, `content`, `imageIcon`. DiviFlash's FAQ uses `question_text`, `answer_text`,
`faq_que_wrapper`, `faq_item_design_settings`. There is no convention to infer — read the registry
for the names, this file for the leaves.

**The double-font nesting trap applies here too.** `title.decoration.font.font.…` and
`answer_text.decoration.bodyFont.body.font.…` both carry the doubled `font` segment the skill
already warns about for Contact Form. Writing `title.decoration.font.desktop.value.size` no-ops.

---

## `difl/faq` + `difl/faqitem` (DiviFlash FAQ)

**Not to be confused with** Divi's native `divi/accordion` / `divi/toggle`. DiviFlash's FAQ is a
separate module family with its own schema; nothing below applies to the native accordion.

### Family architecture

**Design lives on the parent, content lives on the child.** `difl/faqitem` carries no
`decoration` at all — every one of its 13 observed leaves is `innerContent`. Styling the questions
and answers means writing to the **parent** `difl/faq`, not to the items.

**`difl/faqitem` is self-closing.** It saves as `<!-- wp:difl/faqitem {…} /-->` with no closing
delimiter, unlike the wrapping parent. This is what reconciles the raw counts on the reference
site: 6 `difl/faq` openers plus 6 closers reads as 12 occurrences, while 38 `difl/faqitem` openers
have no closers and read as 38.

### `difl/faq` (parent) — observed leaves

| Element | Observed paths |
| --- | --- |
| `module` | `.decoration.animation.desktop.value.{style,duration,delay,speedCurve,startingOpacity}` |
| `title` | `.decoration.font.font.desktop.value.{color,family,size,weight,lineHeight}` |
| `question_text` | `.decoration.font.font.desktop.value.textWrap` |
| `answer_text` | `.decoration.bodyFont.body.font.desktop.value.{color,size,lineHeight,textWrap}`<br>`.decoration.bodyFont.link.font.desktop.value.{color,weight,style[]}` |
| `answer_heading` | `.decoration.headingFont.h1.font.desktop.value.textWrap` |
| `faq_item_design_settings` | `.decoration.background.desktop.value.color`<br>`.decoration.border.desktop.value.radius.{topLeft,topRight,bottomLeft,bottomRight,sync}`<br>`.decoration.border.desktop.value.styles.all.{color,width}`<br>`.decoration.spacing.desktop.value.margin.{bottom,syncHorizontal,syncVertical}` |
| `faq_que_wrapper` | `.innerContent.desktop.value.faq_layout` |
| `faq_layout_grid` | `.innerContent.desktop.value` (scalar) |
| `faq_grid_settings` | `.innerContent.desktop.value.{faq_item_gap,faq_item_per_column}` |
| `faq_item_width_settings` | `.innerContent.desktop.value.faq_item_equal_width` |
| `faq_close_icon_color` / `faq_open_icon_color` | `.innerContent.desktop.value` (scalar) |
| `activate_on_first_time` | `.innerContent.desktop.value` (scalar) |
| `enable_schema` | `.innerContent.desktop.value` (scalar) — emits FAQPage schema |
| top level | `builderVersion` |

`faq_item_design_settings` is worth calling out: it is a **pseudo-element that exists only to
carry decoration** for the repeated item, not a content element. Its background, border and
spacing style every item at once.

### `difl/faqitem` (child) — observed leaves

| Element | Observed paths |
| --- | --- |
| `question` | `.innerContent.desktop.value.{question,question_title_tag}`<br>`.innerContent.desktop.value.{open_question_image,close_question_image,open_que_img_alt_txt,close_que_img_alt_txt}` |
| `answer` | `.innerContent.desktop.value.{answer_image,answer_image_alt_text,button_text,button_url}` |
| `content` | `.innerContent.desktop.value` — the answer body |
| `admin_label` | `.innerContent.desktop.value` |
| top level | `builderVersion` |

`question.innerContent.desktop.value.question` and `content.innerContent.desktop.value` appear on
all 38 instances; the image and button leaves appear on 4, so they are optional rather than rare.

### Two traps measured here

**`adminLabel` has two spellings in live data.** Both `meta.adminLabel.desktop.value` and
`module.meta.adminLabel.desktop.value` appear on `difl/faq` instances on the same site — 3 and 2
instances respectively. Read both before concluding a module has no admin label. The child uses a
third spelling entirely, `admin_label.innerContent.desktop.value`.

**One leaf is array-valued.** `answer_text.decoration.bodyFont.link.font.desktop.value.style` is a
list, not a scalar. Writing a bare string there is a shape error.

### Registry vocabulary not observed in use

The live registry declares **51** top-level attributes for `difl/faq`; only **16** appear anywhere
in real content on the reference site. The unexercised 35 are real attributes with unknown leaf
shapes — among them `faq_ans_wrapper`, `faq_ans_button`, `faq_que_active_wrapper`,
`faq_animation_settings`, `que_img_bg`, `active_que_img_bg`, and the whole `*_spacing` family
(`faq_wrapper_spacing`, `que_text_spacing`, `ans_wrapper_spacing`, and six more).

**Do not guess their leaves from the names.** They follow the grammar above, but which decoration
groups each accepts is exactly what the registry does not say. Set one in the Visual Builder and
read the saved attrs back — that is the same method that produced this table.

---

## Provenance

| | |
| --- | --- |
| Measured | 2026-09-27, read-only |
| Site | staging.colleyvillelions.com |
| Versions | Divi 5.13.1, DiviFlash 5.5.0 |
| Method | `post_content` of every non-auto-draft post carrying the family, attrs extracted with an explicit string- and escape-aware brace walk (not a regex), then flattened to dot paths |
| Sample | posts 306, 346, 351, 900390, 901115, 901184 — 6 `difl/faq` and 38 `difl/faqitem` instances, 0 parse failures |
| Leaves | 42 distinct on the parent, 13 on the child, 54 union |
| Controls | walker instance count equals a naive opener count for both slugs; an absent slug returns 0 |
| Registry | `diviops_schema_get_module({ module_name: "difl/faq" })`, `source: "block_registry"`, 51 top-level names, every one `{"type":"object"}` |
| Round-trip | **not yet run** — a write to the site, pending owner authorization on a scratch page. Until then every path here is observed, not proven writable |

Page 900390 is read-only by standing instruction and was read, never modified.

Issue: [#521](https://github.com/rubicon/diviops/issues/521), under epic
[#50](https://github.com/rubicon/diviops/issues/50).

---

## `difl/advancedmenu` + `difl/advancedmenuitem` (DiviFlash Advanced Menu)

The reference site's real navigation, and the largest uncovered family on it: **9 parents and 35
children across 8 posts**. Chosen for that reason and because it does not overlap
[mega-menu-pattern.md](mega-menu-pattern.md), which builds a mega menu from **native** `divi/*`
modules (`divi/text`, `divi/link`, `divi/dropdown`) and names no `difl/*` module at all. The two
are alternatives: that file is how to build one without DiviFlash, this is how to read one that
already exists.

### Family architecture — inverted from the FAQ family

| | `difl/faq` family | `difl/advancedmenu` family |
|---|---|---|
| where the configuration lives | parent (42 leaves) vs child (13) | **child (162) vs parent (33)** |
| child self-closing | yes | yes — **35 of 35** |

So the rule "design on the parent, content on the child" learned from FAQ **does not generalize**.
Here the parent is close to a shell: three row bands plus its own spacing. Check which half carries
the leaves before assuming, per family.

The parent's 33 leaves are almost entirely the three horizontal bands —
`top_row_obj_settings`, `center_row_obj_settings`, `bottom_row_obj_settings` — plus
`module.decoration.spacing` (margin and padding), `css.desktop.value.{freeForm,mainElement}`,
`modulePreset.0`, and two content-ish switches:
`top_row_obj_settings.innerContent.desktop.value.trow_hide_on_sticky` and
`show_mobile_slide_obj_settings.innerContent.desktop.value.show_mobile_slide`.

### The `*_obj_settings` convention

**19 of the child's 21 element names end in `_obj_settings`.** That is this vendor's
pseudo-element naming, and it is a far stronger signal than the FAQ family's lone
`faq_item_design_settings` suggested. The full set observed on the child:

`module`, `menu_item_obj_settings`, `mslide_button_obj_settings`, `content_obj_settings`,
`submenu_container_obj_settings`, `mega_menu_obj_settings`, `icon_btn_obj_settings`,
`search_obj_settings`, `menu_obj_settings`, `logo_obj_settings`, `icon_obj_settings`,
`sticky_logo_obj_settings`, `button_obj_settings`, `top_level_menu_active_obj_settings`,
`mm_obj_settings`, `line_obj_settings`, `df_disabled_obj_settings`, `anim_obj_settings`, plus
`css`, `modulePreset` and `builderVersion`.

Decoration groups actually observed, by element: `module` → `background`, `border`, `box`,
`sizing`, `spacing`; `menu_item_obj_settings` → `font`, `spacing`; `mslide_button_obj_settings` →
`background`, `border`, `font`, `spacing`; `submenu_container_obj_settings` → `border`, `box`;
`mega_menu_obj_settings` → `box`, `spacing`; `content_obj_settings` → `body`.

### Traps measured here

1. **The doubled `font.font` segment recurs** — 13 leaves, e.g.
   `menu_item_obj_settings.decoration.font.font.desktop.value.family`. Third confirmation of this
   trap across three unrelated modules; treat it as the rule for any `font` group, not a quirk.
2. **`hover` is a real state** beside `value`, as above. The FAQ family showed none.
3. **Some `innerContent` values are objects, not scalars.**
   `icon_btn_obj_settings.innerContent.desktop.value.icon_btn_font_icon` carries `.type`,
   `.unicode` and `.weight`. Writing a string there replaces a structure.
4. **Array-valued leaves are ordinary here**, not exceptional:
   `module.decoration.background.desktop.value.gradient.stops.0.color` (and `.1`),
   `module.decoration.sizing.desktop.value.size.0`/`.1`,
   `df_disabled_obj_settings.innerContent.desktop.value.df_disabled_on.0`, `modulePreset.0`.
5. **`adminLabel` has ONE spelling here** — `module.meta.adminLabel.desktop.value` — where the FAQ
   family had three. The three-spelling finding was FAQ-specific and must not be generalized.

### Provenance

`staging.colleyvillelions.com`, 2026-09-27, Divi 5.13.1 + DiviFlash 5.5.0. Extracted with
WordPress's own `parse_blocks()` under WP-CLI — not a hand-rolled scanner, because the
authoritative parser is already loaded there and a second one would drift. Posts carrying the
family: `396`, `462`, `282281`, `282290`, `333857`, `333861`, `333868`, `900335`. Counts are
block instances, not posts.

**Every path here is observed, NOT proven writable.** The round-trip edit #50's method calls for
needs a scratch-page write on staging, which is still awaiting owner authorization. Nothing below
has been written back and confirmed.
