# `@divi/*` npm scope evaluation (issue #386) — design spec

Date: 2026-09-03
Issue: [#386](https://github.com/rubicon/diviops/issues/386)
Status: Phase 1 complete (licence + package evaluation). Phase 2 waits on
[#387](https://github.com/rubicon/diviops/pull/387) merging, not on a decision.

## Revision history

- 2026-09-03, initial evaluation. Licence re-confirmed against four independent
  sources. Four candidate packages measured rather than assumed. Recommendation:
  vendor **two** of the four, drop one outright, defer two.
- 2026-09-03, revised after #384 opened [#387](https://github.com/rubicon/diviops/pull/387)
  and settled the mechanism. Two corrections and one adoption:
  - **Module count corrected, 112 → 111.** The original figure counted every directory
    under `src/module/library` holding an `index.ts`, minus the library root. That still
    includes `internal/`, which is not a module. Re-derived independently before
    accepting the correction; §2.3's derived figures move with it (union 120 → 119,
    `types \ ai-agent` 20 → 19). **No conclusion changes** — the eight names
    `@divi/ai-agent` adds, including all four structural wrappers, are unaffected.
  - §3 reframed. It was written while the mechanism was open; it is not an alternative
    to #387's and is now reconciled with it.
  - §4 and §5 rewritten against #387's actual mechanism, read from its diff rather than
    from a description of it.

## Summary

Issue #386 nominated four `@divi/*` packages to vendor beyond `@divi/types`. Measuring
them changes the list:

| # | Package | #386 verdict | Measured verdict |
| - | ------- | ------------ | ---------------- |
| 1 | `@divi/module-utils` | vendor, highest value | **vendor** — claim holds |
| 2 | `@divi/style-library` | vendor | **drop** — content lives in `@divi/types` |
| 3 | `@divi/ai-agent` | vendor | **vendor** — for a reason #386 did not name |
| 4 | `@divi/module-library` | vendor | **defer** — 1.09 MB, wrong vocabulary |

The licence holds up. It is in fact better evidenced than #386 assumed, though
`dev.elegantthemes.com` contributes nothing to it.

## 1. Licence verdict

**Confirmed: GPL-2.0-or-later.** Safe to derive from. Four independent strands, the
weakest of which is still the one #386 relied on.

### Strand 1 — npm registry metadata (all eight packages checked)

`license: "GPL-2.0-or-later"` on both the registry document and the `latest` version
manifest. Tarballs downloaded and SHA-1 verified against the registry's own `shasum`;
all eight matched.

| Package | Version | `license` | Published | Integrity |
| ------- | ------- | --------- | --------- | --------- |
| `@divi/module-utils` | 1.0.13 | GPL-2.0-or-later | 2026-08-20 | `sha512-KhwcDsRIOrx6GdFUGHYl64nzxRhQjaFR/2GXyrQayht1vn+EHcdMqIoE3d+adGRAReb2PQOY8lkYnU91Hddgmw==` |
| `@divi/style-library` | 1.0.9 | GPL-2.0-or-later | 2026-08-27 | `sha512-AwDyUstMOvNykhkyrBfv2ZnBnP5hhhIM0lIpNtqndbDfL1X2qVy7Ve2UJePJ+Kx5CJFrntL+7bZRHlXmxgo3Ww==` |
| `@divi/ai-agent` | 1.0.11 | GPL-2.0-or-later | 2026-08-20 | `sha512-bibSqHuXKEOp1vbsg+YSplJbtH5nIZGbYbYkExy4dezMGf9hPdsJHX7H6Bz2GaPq+5OOVE7X7eV4FYH0JlHEUw==` |
| `@divi/module-library` | 1.0.14 | GPL-2.0-or-later | 2026-08-27 | `sha512-s83EC8JFT3QulXPjPg/jHGDEEAJQLdJLvE0GbP6eKwG9pKVdyj1sgN+KuLUVx3qG/HFy2CIuk+mQNHH94/WPAQ==` |
| `@divi/types` | 1.0.12 | GPL-2.0-or-later | 2026-08-27 | `sha512-NYKa2n5oJByUhE7Q8+fC6vVDxYJwZpySBD7cMlxhFAuksycLbIF2Qq3QT6lXIFzHVXf3wY5BKyjE0u7atFn6ag==` |

`@divi/field-library@1.0.12`, `@divi/constant-library@1.0.6` and
`@divi/divider-library@1.0.1` were checked too and carry the same declaration.
Maintainers are identical across all eight: `lots0logs`, `et_nick`, `joshronk`,
`fikrirasyid`.

### Strand 2 — README licence sections (new evidence, not in #386)

Seven of the eight ship a `## License` section reading `GPL-2.0-or-later`. #386 treated
`package.json` as the only declaration; it is not. `@divi/module-utils`' README also
states the intent directly:

> These declarations are generated from Divi source packages and are published for
> external TypeScript consumers.

`package.json` additionally carries an `author` field absent from `@divi/types`:
`Elegant Themes, Inc. <support@elegantthemes.com> (https://www.elegantthemes.com)`,
plus `homepage: https://www.elegantthemes.com`.

**Asymmetry worth recording: `@divi/types` is the weakest-evidenced of the set.** It has
no `## License` section, no `author`, and no `homepage` — only the `package.json`
`license` field. That is the package #384 and #385 both build on. It is still a valid
declaration; it is simply the one strand rather than three.

### Strand 3 — `dev.elegantthemes.com`: silent, neither corroborating nor contradicting

Mined as #386 asked. It yields **no licence statement of any kind**. `/`, `/docs`,
`/docs/intro` and `/api/js/divi-module-utils/` were fetched, stripped to text, and
scanned with a pattern proven against a known positive first. The only match on every
page is the footer, `Copyright © 2026 Elegant Themes ®, Inc.` `/license` and
`/docs/license` are both 404; there is no sitemap.

Report this plainly: **the official developer docs do not state a licence for the
JavaScript API packages.** They also do not assert proprietary terms. They are silent.

### Strand 4 — the installed Divi theme (strongest strand, and it is local)

Divi 5.11.1 on the reference install declares in `style.css`:

```
License: GNU General Public License v2
License URI: http://www.gnu.org/licenses/gpl-2.0.html
```

and ships a real `LICENSE.md` (16,621 bytes) whose operative sentence is verbatim
GPL-2.0-**or-later**:

> This program is free software; you can redistribute it and/or modify it under the
> terms of the GNU General Public License as published by the Free Software Foundation;
> either version 2 of the License, or (at your option) any later version.

That matters because the npm packages are not free-standing artifacts — each one
declares the type surface of a bundle Elegant Themes ships *inside* that GPL'd theme.
All eight have a corresponding bundle at
`themes/Divi/includes/builder-5/visual-builder/build/<name>.js`, checked individually.

So the npm declaration is not a lone assertion: it agrees with the licence on the
distribution the code actually ships in. **This is stronger than "declared
GPL-2.0-or-later on npm."**

### Residual risk

No `LICENSE` / `COPYING` / `NOTICE` file ships in any of the eight tarballs — confirmed
by walking every extracted file. That remains true and is the one weakness. It is a
packaging omission rather than a contradiction: nothing anywhere asserts different
terms, and the theme these describe carries the full text.

## 2. Per-package evaluation

### Method

#386's caveat is that `.d.ts` declarations are only useful where they carry
string-literal unions. That is the right test but not sufficient, because **a candidate
can carry a union that it merely re-exports from `@divi/types`** — which #384 already
vendors. Every union was therefore compared against a 315-union `@divi/types` baseline.

| Package | Unions (≥3) | Unique | Shared with `@divi/types` | Bare re-exports of a `@divi/types` symbol |
| ------- | ----------: | -----: | ------------------------: | ----------------------------------------: |
| `@divi/module-utils` | 17 | **13** | 4 | 10/174 (5%) |
| `@divi/style-library` | 8 | 4 | 4 | **24/48 (50%)** |
| `@divi/ai-agent` | 8 | **7** | 1 | 0/14 (0%) |
| `@divi/module-library` | 23 | 12 | 11 | 27/218 (12%) |
| `@divi/field-library` | 9 | 6 | 3 | 12/101 (11%) |

### 1. `@divi/module-utils@1.0.13` — VENDOR. #386's claim holds.

466 KB unpacked, 574 `.d.ts`, 13 unions `@divi/types` does not carry. The headline claim
is exact. `types/get-attr-value-with-defaults/types.d.ts`:

```ts
/**
 * Getter and inheritance mode for getAttrValue.
 */
export type GetAttrMode =
  | 'get'
  | 'getAndInheritAll'
  | 'getAndInheritClosest'
  | 'getOrInheritAll'
  | 'getOrInheritClosest'
  | 'inheritAll'
  | 'inheritClosest';
```

The same file specifies the whole resolution contract, and it is the read-side mirror of
what `module_update` writes:

- `attrPath` is **dot-notation** — the doc comment's own example is
  `'module.decoration.layout'`, the identical addressing scheme `module_update` uses.
- Resolution is two-axis: `breakpoint: Breakpoint.Name` × `state: AttrState`
  (`'value' | 'hover' | 'sticky'`), with `baseBreakpoint` defaulting to `'desktop'`.
- **The default mode is `'getAndInheritAll'`** — not a bare `get`. A reader that finds
  nothing at a path does not conclude the value is unset; it inherits.
- A fourth input exists that we do not currently model at all:
  `defaultPrintedStyleAttrs`, which `index.d.ts` says comes from
  `module-default-printed-style-attributes.json`.

`types/merge-attrs/index.d.ts` gives the layering precedence, lowest to highest:
`defaultAttrs` → `presetAttrs` → `attrs`. `types/get-attr-by-mode/index.d.ts` fixes the
cascade direction — the value is inherited **"from larger breakpoints"** (desktop →
tablet → phone), not the reverse.

Also unique and directly useful:

- `types/is-grid-module/index.d.ts` — the six grid modules: `divi/blog`,
  `divi/filterable-portfolio`, `divi/fullwidth-portfolio`, `divi/gallery`,
  `divi/portfolio`, `divi/woocommerce-product-gallery`.
- `types/get-modules-in-scope/types.d.ts` — the scope vocabulary: `'group' | 'column' |
  'row' | 'section' | 'page' | 'self' | 'children' | 'descendants'`.
- `types/is-nested-preset-group/index.d.ts` — the eight groups that nest inside a preset.
- `types/get-preset-attrs-names/types.d.ts` — `'meta' | 'content' | 'style' | 'html' |
  'script'`.

**Honest limit.** These are declarations. They give us the *vocabulary and the contract
shape* — seven named modes, the parameter set, the default, the cascade direction, the
merge precedence. They do **not** give the algorithm: `getInheritBreakpoint` and
`getInheritState` declare their signatures and return types and nothing about the rule.
The implementation is on disk under GPL at
`themes/Divi/includes/builder-5/visual-builder/build/module-utils.js`, but it is 343,571
bytes on a single line — minified, and readable only by hand. Vendoring this package
buys a specification to write against, not a derivation of Divi's behaviour.

### 2. `@divi/style-library@1.0.9` — DROP. It is a re-export shell.

**This is the one finding that changes #386's plan.** The claim is that it "maps each
decoration group to the CSS properties it emits." Measured across all 29 groups under
`types/declarations/`: **only 5 enumerate their CSS properties** (`background`,
`overlay-icon`, `text-effects`, `text-shadow`, `z-index`). The other 24 declare a
`*StyleCssProperties` symbol with no members of its own. `spacing/types.d.ts` in full:

```ts
export type SpacingStyleShorthandCssProperties = StyleLibrary.SpacingStyleShorthandCssProperties;
export type SpacingStyleCssProperties = StyleLibrary.SpacingStyleCssProperties;
```

Following that reference: **the real map lives in `@divi/types`**, at
`src/style-library/index.ts` — a single 162-line `namespace StyleLibrary` carrying
`FontStyleCssProperties` (13 members), `BorderStyleCssProperties`,
`SpacingStyleCssProperties`, and the rest. Half of `@divi/style-library`'s exported type
aliases (24 of 48) are bare re-exports of it.

The decoration-group → CSS-property mapping #386 wants is real and worth having. It
arrives with `@divi/types`, which #384 is already vendoring. **Vendoring
`@divi/style-library` adds a second package for four unique unions, one of which
(`text-effects`, 11 members) is a superset of the `@divi/types` copy by two entries.**
Not worth a package. Take the two extra members as a documented note if they matter.

### 3. `@divi/ai-agent@1.0.11` — VENDOR, but for a different reason than #386 gives.

194 KB, 280 `.d.ts`, 7 unique unions, and **zero re-exports** — everything in it is its
own. #386 values it as "Divi's own vocabulary for what a page tree contains," which is
true: `types/tools/module/select-module/index.d.ts` carries the 15-member structural
taxonomy (`'module' | 'group' | 'layout' | 'root' | 'section' | 'fullwidth-section' |
'specialty-section' | 'row' | 'row-inner' | 'column' | 'specialty-column' |
'column-inner' | 'fullwidth-module' | 'child-module' | 'unsupported'`).

The stronger reason is narrower and specific to this fork. Comparing the three module
name sources we have:

| Source | Module names |
| ------ | -----------: |
| `@divi/types` (module dirs, excluding the library root and `internal/`) | 111 |
| `@divi/ai-agent` (`get-child-modules` union) | 100 |
| Divi 5.11.1 theme (`module.json` `name`) | 89 |
| **union of all three** | **119** |

111 is the same figure #387 generates against, arrived at independently here rather
than taken from it.

`@divi/ai-agent` carries **8 names `@divi/types` does not**, and four of them are the
structural wrappers:

```
divi/root, divi/global-layout, divi/row-inner, divi/column-inner
```

(the other four: `divi/shop`, `divi/shortcode-module`,
`divi/social-media-follow-network`, `divi/woocommerce-checkout-additional-info`.)

That is precisely the area where this fork already had a real bug. `divi/global-layout`
is the wrapper behind the index divergence fixed in #13/#14 and the write guard in #11 —
see FORK.md, "Fixed: the `divi/global-layout` index divergence". `@divi/types` omits all
four, because they are structural containers rather than modules with attribute types.
The installed theme's `module.json` set confirms this independently: the only four names
it has that `@divi/types` lacks are the same four.

**So `@divi/ai-agent` is the only npm source for the structural vocabulary this fork's
tree-walking code actually reasons about.** That is worth 194 KB.

Its `get-child-modules` union also runs the other way — it is missing 19
modules `@divi/types` has (`divi/lottie`, `divi/timeline`, `divi/group-carousel`,
`divi/before-after-image`, `divi/icon-list`, `divi/svg`, `divi/dropdown` and others),
so the two are complementary and **neither is a complete module list on its own**. Any
artifact claiming to enumerate Divi modules should take the union and say which source
each name came from.

### 4. `@divi/module-library@1.0.14` — DEFER. Right data, wrong vocabulary for us.

1.09 MB unpacked, 2,443 `.d.ts` — by far the largest candidate. It does carry 12 unique
unions including the per-module element lists #386 names, e.g.
`types/components/filterable-portfolio/types.d.ts`:

```
'portfolioFilters' | 'activePortfolioFilter' | 'portfolioImage' | 'overlay'
  | 'overlayIcon' | 'portfolioTitle' | 'portfolioPostMeta' | 'portfolioPagination'
  | 'portfolioPaginationActive'
```

But these are **style-render element keys, not attribute paths.** `@divi/types`'
`FilterablePortfolioAttrs` for the same module declares an entirely different set of
top-level keys — `title`, `meta`, `filter`, `pagination`, `portfolio`, `portfolioGrid`,
`portfolioItem`, `image`, `overlay` — and those are the ones a caller writes to through
a dot path. The two vocabularies overlap on exactly one name (`overlay`).

DiviOps writes attributes. The write surface is `@divi/types`'. `@divi/module-library`'s
list becomes relevant only for CSS-selector work, which nothing currently does. 1.09 MB
for a vocabulary we do not address is not a good trade today. Revisit if selector-level
styling lands.

`@divi/field-library@1.0.12` (526 KB, 6 unique unions) is declined on the same reasoning
#386 already gives: we do not render settings UI.

## 3. A fourth GPL source, and how it sits under #387's mechanism

**Not an alternative mechanism.** #387 settled that question and this evaluation adopts
it (§5). What follows is a fourth *input* that already exists on disk, recorded because
it is unmined and because §2.3 uses it as the independent check on the module-name
comparison — not because it should displace anything.

The installed Divi theme ships **508 JSON data files** under
`themes/Divi/includes/builder-5/visual-builder/packages/`, all GPL, already on disk,
no npm fetch involved:

| File | Count |
| ---- | ----: |
| `module.json` | 115 |
| `module-default-render-attributes.json` | 113 |
| `module-default-printed-style-attributes.json` | 113 |
| `conversion-outline.json` | 92 |

`module.json` is richer than the corresponding `.d.ts` — it carries CSS selectors,
`styleProps`, and a `settings` tree with labels and descriptions, stamped
`"!!! THIS IS AN AUTOMATICALLY GENERATED FILE - DO NOT EDIT !!!"`. And
`module-default-printed-style-attributes.json` is by name the exact input
`getAttrValueWithDefaults` takes as `defaultPrintedStyleAttrs`.

Two caveats, both measured, both arguing against treating this as a replacement for the
npm packages:

- **It is not leaf-resolvable on its own.** `button/module.json` contains only two
  `attrName` dot-paths (`button.innerContent`, `module.advanced.alignment`); 496 across
  all 89 modules. `attrName` marks group-item overrides, not the attribute surface. The
  full surface is implicit in the `settings` tree — which is #119/#385's problem exactly.
- **It tracks the installed site, not a pinned version.** The reference install is Divi
  5.11.1 while staging is on 5.12.0, and its WooCommerce modules are absent because
  WooCommerce is not installed there (26 of the 111 `@divi/types` modules are missing
  from it for that reason alone).

So it is a **cross-check**, not a substitute — and #387's own design already says how a
cross-check is carried: it renders every path where `@divi/types` and the recorded schema
dump disagree (28 of 30 comparable modules disagree somewhere) rather than preferring one
input. A theme-JSON lane would be a third column in that same comparison.

If it is ever wired in, it inherits #387's constraint unchanged and for the identical
reason. CI reaches neither npm nor a WordPress install, so #387 fetches `@divi/types` at
regen time, throws the package away, and commits only a distilled
`__fixtures__/divi-types.json`. The theme JSON is behind the same wall — it lives on a
developer's Local install, not in CI — so it would need its own recorded fixture
alongside `dump-all.json` and `divi-types.json`, captured by `__fixtures__/capture.mjs`
under the existing rule that fixture and doc come from the same install in the same
sitting. Reading it directly from a theme path at generation time would work on a
maintainer's machine and fail in CI, which is the failure mode that pattern exists to
prevent. Out of scope for #386 either way.

## 4. `FORK.md` provenance text — an amendment, not a new row

#386 asked for a divergence *row*. #387 has since established that `@divi/*` provenance
does not live in one: it recorded `@divi/types` by **extending two existing rows**
(`regen-module-formats.mjs …` and `skills/divi-5-builder/references/module-formats.md`)
rather than adding a third, on the reasoning that the rows already covered every path
touched. Adding a standalone `@divi/*` row on top would split the same provenance across
three places and put this evaluation in conflict with a sibling PR for no gain.

So the proposal is an **amendment to the `regen-module-formats.mjs` row #387 already
edits**, appended after its `DIVI_TYPES_VERSION` / `DIVI_TYPES_DIR` sentence, and to be
written only once #387 is merged and its final wording is fixed:

> Extended again (#386) to two further `@divi/*` packages on the same terms and through
> the same fetch-and-discard path: **`@divi/module-utils`**, which supplies the
> `GetAttrMode` attribute-resolution vocabulary (`get` | `getAndInheritAll` |
> `getAndInheritClosest` | `getOrInheritAll` | `getOrInheritClosest` | `inheritAll` |
> `inheritClosest`), the `defaultAttrs` → `presetAttrs` → `attrs` layering precedence,
> and the grid-module and scope enumerations; and **`@divi/ai-agent`**, which is the only
> published source for the four structural container names `@divi/types` omits —
> `divi/root`, `divi/global-layout`, `divi/row-inner`, `divi/column-inner` — the same
> `divi/global-layout` wrapper behind the index divergence fixed in #13/#14 and the write
> guard in #11. Both are GPL-2.0-or-later, pinned by version in the generated header and
> distilled into `__fixtures__/`, exactly as `@divi/types` is; neither package's source
> tree is committed. `@divi/style-library` and `@divi/module-library` were evaluated and
> **declined** — style-library re-exports its decoration-group → CSS-property map from
> `@divi/types` (half its exported aliases are bare re-exports, and only 5 of 29 groups
> enumerate anything themselves), and module-library's 1.09 MB per-module element lists
> are style-render keys rather than the attribute paths this project writes. The licence
> was re-confirmed independently for the whole scope before anything derived shipped
> (#386): declared `GPL-2.0-or-later` in `package.json` on the registry and in every
> tarball, restated in a `## License` section in seven of the eight packages' READMEs,
> and matching the `LICENSE.md` of the Divi theme these packages describe, which grants
> GPL "version 2 of the License, or (at your option) any later version". No `LICENSE`
> file ships inside the tarballs, and `dev.elegantthemes.com` states no licence for the
> JS API packages; neither contradicts the declaration, and both are recorded in
> `docs/superpowers/specs/2026-09-03-divi-npm-scope-evaluation-design.md`. This licence
> is unrelated to the DiviOps Agent Pro rule in `CLAUDE.md`, which concerns a different
> third party's commercial code.

## 5. What Phase 2 needs

Phase 2 is **not blocked on a decision any more — only on a merge.** #387 is open, not
merged; `main` at `e96c377` has no `@divi/types` wiring (`grep` for `divi/types` across
`skills/` and `diviops-server/scripts/` returns nothing, with a control proving the same
grep finds `divi/button` in three skill files). Building Phase 2 now would mean branching
off an unmerged branch and coupling two open PRs, which is worse than waiting.

The mechanism is settled and this evaluation adopts it as-is. Read from #387's diff:

- `@divi/types` is **fetched at regen time and discarded** — no third-party source tree
  is committed.
- Only a **distilled** `diviops-server/scripts/__fixtures__/divi-types.json` is
  committed, mirroring the existing `dump-all.json` pattern, because CI reaches neither
  npm nor a WordPress install.
- `DIVI_TYPES_VERSION` / `DIVI_TYPES_DIR` pin or bypass the fetch.
- Resolution goes through `ts.createProgram` + `getTypeChecker`, not a text scanner,
  because the declarations reach their group lists through `Pick<>`, generic arguments,
  intersections and aliases.
- Output lands in **one** sentinel-bounded region (`GENERATED:types-index`), not
  per-module sentinel pairs.

Once #387 merges, Phase 2 is:

1. Rebase on `main` and extend #387's fetch-and-distil path to
   `@divi/module-utils@1.0.13` and `@divi/ai-agent@1.0.11`. Same shape, same
   fetch-and-discard, same distilled-fixture rule, same env-var escape hatches. **No
   second mechanism.**
2. Do **not** add `@divi/style-library` (§2.2) or `@divi/module-library` (§2.4).
3. Record each package name + exact version in the generated header, per #386 scope
   item 2 and #50 — #387 already does this for `@divi/types`; extend the same header.
4. Append the §4 amendment to the `regen-module-formats.mjs` row, trimmed to what
   actually shipped, once #387's own wording of that row is final.
5. The installed-theme JSON (§3) stays out of scope.

One caution carried forward, **reproduced by mutation before this spec was finalized.**
#387's source comment at `regen-module-formats.mjs:103-105` reasoned that
`lib: ['lib.es5.d.ts']` is what stops the decoration lists resolving silently empty.
Three mutations against #387's own branch settle it: removing the line leaves 55/55
green, `lib: []` leaves 55/55 green — TypeScript falls back to its default lib in both
cases, which already supplies `Pick` — and only `noLib: true` reproduces the failure,
at which point two named `buildTypesIndex` cases fail. So the option is insulation
against a future default-lib change, not the guard; the guard is those two cases.
#387 has since corrected both its source comment and its `FORK.md` row. Recorded here so
Phase 2 does not inherit the original reasoning by citation: whatever `ts` program
options Phase 2 needs must be pinned by a mutation that actually fails, and a mutation
that merely restores a library default proves nothing.

Owner decisions this evaluation surfaces:

- **`@divi/style-library` should come off the list.** Its content is in `@divi/types`.
- **`@divi/module-library` should come off the list for now** — 1.09 MB for a
  style-render vocabulary nothing currently uses.
- `@divi/types` is the least-evidenced package on licence (§1.2) and the most depended
  on. Nothing blocks it; worth knowing.

## 6. Reproduction

Nothing here rests on a remembered figure. Every count came from a script over the
extracted tarballs and the installed theme:

```
scratchpad/divi386/fetch-386.py       # fetch + SHA-1 verify + extract 8 packages
scratchpad/divi386/inventory-386.py   # licence files, README licence text, union yield
scratchpad/divi386/overlap-386.py     # unique vs re-exported from @divi/types
scratchpad/divi386/cssprops-386.py    # per-decoration-group CSS property enumeration
scratchpad/divi386/modnames2-386.py   # module-name coverage across three sources
scratchpad/divi386/scan-html-386.py   # dev.elegantthemes.com licence scan
```

Each script that reports a negative asserts a known-positive control first, because an
empty result from a pattern that could not have matched is not a finding.

Two figures were corrected mid-evaluation and both are recorded rather than quietly
fixed, because the correction is the evidence that the measurement was checked:

- `@divi/types` module count 112 → **111**. `internal/` holds an `index.ts` but is not a
  module. Re-derived here before the correction was accepted.
- An earlier pass reported 88 modules, undercounting because the WooCommerce modules nest
  one level deep under `woocommerce/`. That pass would have concluded `@divi/ai-agent`
  adds 31 names it does not add. The corrected figure is 8.
