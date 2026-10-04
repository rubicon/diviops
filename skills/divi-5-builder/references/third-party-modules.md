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

> A path in this file is **observed on a real, working, VB-saved module**, and its path SHAPE is
> **storage-verified** — the write proof at the end of this file round-tripped one probe per shape
> through `page_create` → `page_get` and every one came back unchanged. That is storage fidelity
> and nothing more. The proof's control, a deliberately wrong path, survived just as cleanly,
> because WordPress stores unknown block attributes verbatim. So **no path here is verified to
> have any visual effect**, and a round trip is not the evidence that would establish one. Read
> the write proof's own caveat before treating any leaf as guaranteed to do something, and do not
> silently upgrade storage fidelity to proven writability.

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
| Round-trip | **run 2026-09-27** on scratch DRAFT page `901566` — see the write proof at the end of this file. It storage-verifies every path SHAPE documented here and establishes nothing about visual effect: the proof's deliberately wrong control survived too |

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

### The `*_obj_settings` convention — this module's, NOT the vendor's

**19 of the child's 21 element names end in `_obj_settings`.** It is tempting to read that as a
DiviFlash-wide naming convention. It is not: `difl/advanced-blurb` below uses **zero** of them
across 28 element names, and `difl/faq` used one. Naming is per module, so read the registry for
the names of the module in front of you rather than extrapolating from any one family. The full
set observed on this child:

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
   family had three, and `difl/advanced-blurb` below has three again. **The count varies per
   module in both directions**, so neither "always one" nor "always three" is safe: search the
   instance for every spelling before writing one.

### Provenance

`staging.colleyvillelions.com`, 2026-09-27, Divi 5.13.1 + DiviFlash 5.5.0. Extracted with
WordPress's own `parse_blocks()` under WP-CLI — not a hand-rolled scanner, because the
authoritative parser is already loaded there and a second one would drift. Posts carrying the
family: `396`, `462`, `282281`, `282290`, `333857`, `333861`, `333868`, `900335`. Counts are
block instances, not posts.

**Every path here is observed in live content, and the path SHAPES are storage-verified** by the
write proof at the end of this file — one probe per shape survived a real `page_create` →
`page_get` round trip. What that does NOT establish is visual effect: the proof's control, a
deliberately wrong path, survived just as cleanly, because WordPress stores unknown block
attributes verbatim. Read the proof's own caveat before treating any leaf as guaranteed to do
something.

---

## `difl/advanced-blurb` (DiviFlash Advanced Blurb)

The largest single third-party module on the reference site — **38 instances across 9 posts** — and
the richest: **256 distinct leaf paths**, more than the whole advanced-menu family combined. It is
standalone: no child block, and **all 38 instances are self-closing**, so every last thing about a
blurb lives in its attributes.

Its 28 element names are plain, with no suffix convention at all:

`module`, `title`, `sub_title`, `content`, `image`, `icon_image`, `badge`, `button`,
`button_icon_object`, `button_spacing`, `button_width_alignment`, `content_area_alignment`,
`content_spacing`, `content_width`, `image_container_width`, `image_icon_alignment`,
`image_icon_item_align`, `image_icon_wrapper_spacing`, `item_order`, `wrapper_spacing`,
`badge_font_both`, `title_url`, `alt_text`, `locked`, `css`, `modulePreset`, `builderVersion`.

### What this family adds to the grammar

1. **Responsive breakpoints are really used here.** 198 leaves on `desktop`, **24 on `tablet` and
   24 on `phone`**. The advanced-menu family was desktop-only, which could easily read as "vendor
   modules do not do responsive". They do.
2. **The same group can appear WITH and WITHOUT the breakpoint layer.** Both of these are live on
   the same module:

   ```
   module.decoration.attributes.attributes.0.adminLabel
   module.decoration.attributes.desktop.value.attributes.0.adminLabel
   ```

   The whole `module.decoration.attributes.*` group does this — `id`, `name`, `targetElement`,
   `value` all appear in both shapes. So a writer cannot assume `<group>` is always followed by a
   breakpoint; check the instance.
3. **`hover` again** — 9 leaves, confirming it is not an advanced-menu peculiarity.
4. **The doubled `font.font` segment, 34 times.** Fourth module, four confirmations. Treat it as
   the rule.
5. **`adminLabel` has three spellings here**, including the two dual-shape forms above plus
   `module.meta.adminLabel.desktop.value`. See trap 5 in the advanced-menu section: the count is a
   per-module fact.
6. **30 array-valued leaves**, the most of any family measured — `attributes.N.*` and gradient
   stops among them.

### Provenance

`staging.colleyvillelions.com`, 2026-09-27, Divi 5.13.1 + DiviFlash 5.5.0, via WordPress's own
`parse_blocks()` under WP-CLI. Posts: `396`, `900015`, `900062`, `900073`, `900111`, `900112`,
`900133`, `900271`, `900275`. **Observed, and storage-verified** — see the write proof at the end of this file, including what
it does NOT establish.

---

## The rest of the site's vendor surface

The five families above and below account for the reference site's entire `difl/*` usage. All of it
was censused with WordPress's own `parse_blocks()` under WP-CLI on 2026-09-27
(`staging.colleyvillelions.com`, Divi 5.13.1 + DiviFlash 5.5.0).

| module | instances | self-closing | leaves | element names | `_obj_settings` | `font.font` | `adminLabel` spellings |
|---|---|---|---|---|---|---|---|
| `difl/advanced-blurb` | 38 | 38 | 256 | 28 | 0 | 34 | 3 |
| `difl/advancedmenuitem` | 35 | 35 | 162 | 21 | **19** | 13 | 1 |
| `difl/faqitem` | 38 | yes | 13 | — | 0 | — | 3 (family) |
| `difl/iconlistitem` | 27 | 27 | 90 | 31 | 0 | 4 | 0 |
| `difl/counter` | 17 | 17 | 20 | 10 | 0 | 4 | 2 |
| `difl/imagehotspotitem` | 14 | 14 | 40 | 7 | 0 | 4 | 2 |
| `difl/advancedmenu` | 9 | 0 | 33 | — | most | — | — |
| `difl/faq` | 6 | 0 | 42 | — | 1 | yes | — |
| `difl/iconlist` | 6 | 0 | 82 | 20 | 0 | 5 | 0 |
| `difl/df-adh-heading` | 6 | 6 | 35 | 15 | 0 | 11 | 1 |
| `difl/postitem` | 3 | 3 | 12 | 4 | **3** | 9 | 0 |
| `difl/imagehotspot` | 2 | 0 | 36 | 7 | 0 | 0 | 1 |

### What nine modules agree on, and what they do not

**The doubled `font.font` segment is the one real constant** — present in every module that has a
font group at all. The single zero, `difl/imagehotspot`, has no font group. Treat it as the rule.

**`_obj_settings` is used by two modules out of nine** (`difl/advancedmenuitem` 19-of-21,
`difl/postitem` 3-of-4) and by none of the other seven. Not a vendor convention.

**`adminLabel` count ranges from 0 to 3** across modules — `difl/iconlist`, `difl/iconlistitem` and
`difl/postitem` carry none at all. Never assume it is present, and never assume one spelling.

**Responsive leaves are sporadic**: real `tablet`/`phone` entries in `difl/advanced-blurb` (24 each),
`difl/iconlist` (15 each) and `difl/imagehotspotitem` (1 each); desktop-only everywhere else.
Absence in one module says nothing about another.

**`hover` appears in three families** — `difl/advancedmenuitem` (9), `difl/advanced-blurb` (9),
`difl/iconlistitem` (1).

### The strongest possible case that element names are not guessable

`difl/imagehotspot` names its own elements **`hotsopt_image`** and **`hotsopt_image_alignment`** —
the vendor typed "hotspot" wrong, and shipped it. An agent reasoning from the module's name writes
`hotspot_image`, which is not an attribute, so the write silently does nothing.

There is no convention to infer and no spelling to trust. Read the registry for names, and this
file for leaves.

### Element names, remaining families

- **`difl/iconlist`** (parent): `child_content_text`, `child_title_text`, `child_wrapper_element`,
  `layout_object`, `list_item_content`, `list_item_elements_align`, `list_item_equal_width`,
  `list_item_gap`, `list_item_icon`, `list_item_icon_size`, `list_item_icon_text_gap`,
  `list_item_icon_vertical_placement`, `list_item_image_height`, `list_item_image_width`,
  `list_item_title`, `list_item_vertical_alignment`, `list_item_wrapper`, plus `locked`,
  `modulePreset`, `builderVersion`.
- **`difl/iconlistitem`** (child, self-closing): adds `list_item_icon_lottie_src_remote` /
  `_upload`, `list_item_tooltip_content`, `tooltip_custom_maxwidth`, `tooltip_interactive_border`,
  `tooltip_interactive_debounce`, `tooltip_offset_distance`, `tooltip_offset_skidding`,
  `list_item_title_tag`, `list_item_title_url`, `list_item_wrapper_background`, `admin_label`,
  `alt`, `content`, `module`.
- **`difl/counter`**: `animation_settings`, `counter_settings`, `style_settings`, `number_font`,
  `suffix_font`, `module`, `meta`, `css`, `modulePreset`, `builderVersion`.
- **`difl/imagehotspot`** (parent): `hotsopt_image` *(sic)*, `hotsopt_image_alignment` *(sic)*,
  `spot`, `tooltip`, `tooltip_settings`, `meta`, `builderVersion`.
- **`difl/imagehotspotitem`** (child, self-closing): `spot_content`, `spot_design`,
  `spot_settings`, `content`, `module`, `meta`, `builderVersion`.
- **`difl/postitem`** (self-closing): `post_obj_settings`, `date_obj_settings`,
  `settings_obj_settings`, `builderVersion`.
- **`difl/df-adh-heading`** (self-closing): `title`, `title_prefix`, `title_infix`, `title_suffix`,
  `custom_text_input`, `divider_style`, `divider_image`, `divider_image_alt_text`, `use_divider`,
  `use_divider_icon`, `use_divider_image`, `module`, `css`, `modulePreset`, `builderVersion`.

Posts: iconlist family `900121`, `900122`, `901066`; counter `900390`, `901066`, `901115`, `901559`;
imagehotspot family `306`, `901184`; postitem `901066`; df-adh-heading `396`, `900390`, `901115`,
`901184`. **Observed, and storage-verified by the write proof at the end of this file** — but not verified
to have visual effect; see the control row there.

---

## The write proof — what a round trip does and does not establish

Every path in this file was labelled *observed, not proven writable*. That caveat is now
**partially discharged, and the remaining half is the interesting one.**

Run on `staging.colleyvillelions.com`, 2026-09-27, Divi 5.13.1 + DiviFlash 5.5.0. One scratch
DRAFT page (`901566`, titled so it is obviously disposable; page 900390 was never touched) was
created through this plugin's own `page_create` route, then read back through `page_get`. Seven
probe paths, each taken from the observed `difl/advanced-blurb` map rather than invented, one per
path SHAPE this file documents:

| shape | probe | result |
|---|---|---|
| doubled `font.font` | `badge_font_both.decoration.font.font.desktop.value.family` | survived |
| the other doubled form | `content.decoration.bodyFont.body.font.tablet.value.size` | survived |
| `hover` state + array leaf | `button.decoration.background.desktop.hover.gradient.stops.0.color` | survived |
| responsive `tablet` | `button.decoration.font.font.tablet.value.size` | survived |
| `innerContent` scalar | `alt_text.innerContent.desktop.value` | survived |
| plain spacing | `content_spacing.decoration.spacing.tablet.value.margin.bottom` | survived |
| **the trap form** (control) | `badge_font_both.decoration.font.desktop.value.family` | **survived too** |

**7 of 7 — including the one that was supposed to fail.** That is the finding, not a
disappointment. The single-`font` form is the shape this file warns no-ops, and it round-tripped
perfectly, because **WordPress stores unknown block attributes verbatim.** A write/read round trip
therefore proves *storage fidelity* and says nothing whatever about whether Divi reads the path.

**And `page_create` is not the guarded write path, which narrows the proof a second time.**
`page_create()` (`plugins/diviops-agent/includes/trait-page.php:886`) validates the payload with
`authoring_shape_preflight()`, uses that result only to refuse an oversized or malformed one,
discards the parsed tree, and stores `wp_slash( $content )` through a single `wp_insert_post()`.
It never calls `parse_blocks_for_write()` or `update_post_content_with_integrity_guard()` — the
canonicalise-then-guard pairing the module-editing and import routes use. So the probes above
were stored verbatim by WordPress on a creation, and were never put through the pass that could
have rewritten them.

So the accurate status of every path here is now: **observed in live content, and verified to
survive creation through `page_create` unchanged — but not verified to survive the guarded write
path, and not verified to have any visual effect.** Proving visual effect needs a rendered-output
comparison between a correct path and a deliberately wrong one, which is a separate exercise. Any
file that claimed "proven writable" off a round trip alone would be overstating by exactly the
width of that control row.

### A host gotcha this surfaced

The proof failed three times before it ran, and not because of anything in this repository.
**DiviFlash 5.5.0's `Builder/Server/Utils/Props.php:13` declares `offsetExists(mixed $offset)`,
and the `mixed` type needs PHP 8.0+.** Under PHP 7.4 it resolves as a class name in the current
namespace, producing:

```
Declaration of DIFL\Server\Utils\Props::offsetExists(DIFL\Server\Utils\mixed $offset): bool
must be compatible with ArrayAccess::offsetExists($offset)
```

And **WP-CLI on this host runs PHP 7.4.33** — `wp --info` reports
`PHP binary: /opt/alt/php74/usr/bin/php`, and it re-execs under that regardless of which `php`
invokes it, so neither `php $(command -v wp)` nor `WP_CLI_PHP=` changes it. Meanwhile plain `php`
inside the webroot is **8.3.22**, because the per-directory selector switches versions for the same
`/usr/local/bin/php` path.

So any `wp eval` that loads DiviFlash's server code fatals, while the same operation on native
`divi/*` content succeeds. The workaround is an explicit interpreter:
`/opt/alt/php83/usr/bin/php $(command -v wp) …`, which is how the proof above was run.
