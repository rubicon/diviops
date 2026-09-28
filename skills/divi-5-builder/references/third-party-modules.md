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
<element>.decoration.<group>.<breakpoint>.value.<leaf>
<element>.innerContent.<breakpoint>.value[.<leaf>]
```

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
