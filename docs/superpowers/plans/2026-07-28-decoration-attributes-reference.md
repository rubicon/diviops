# Decoration-Attributes Skill Reference (#62) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Author `skills/divi-5-builder/references/advanced-attributes.md` — a clean-room reference for the shared Divi 5 `module.decoration.*` system (boxShadow, filters, transform, sticky, transition, scroll, animation), with canonical dot-paths verified against ground truth.

**Architecture:** A documentation artifact, not code. Each decoration family's canonical dot-paths are extracted from Divi's own `*PresetAttrsMap.php` (the canonical preset-attr map, one per module) and the shared `StyleLibrary/Declarations/*` group shapes on our live Divi install; the four highest-use families are additionally VB-round-tripped on our site for the gold tier. A small committed extraction script makes the provenance reproducible and is reused by #63–#65.

**Tech Stack:** Markdown docs; a PHP or Python extraction helper reading a Divi install; the diviops schema introspection (#42) for cross-checking; the live Visual Builder for verification.

## Global Constraints

- **Clean-room, primary sources only:** derive every path from Divi's own `*PresetAttrsMap.php` + `StyleLibrary/Declarations/*` + `module.json` + Divi public docs + our own VB round-trips. NEVER read/copy/paraphrase/derive from Pro (`diviops-agent-pro`), even though it is installed.
- **Verification-tier convention (from `SKILL.md`):** every path tagged `*(VB-verified YYYY-MM-DD)*` (VB round-trip observed), `*(verified YYYY-MM-DD)*` (schema-extracted from PresetAttrsMap/StyleLibrary), or `<!-- UNVERIFIED -->`. No UNVERIFIED path ships as authoritative.
- **Provenance cited per family:** name the exact source file(s) each family's paths came from.
- **Canonical dot-path form:** `module.decoration.<family>__<subField>` (double-underscore separates the group from its sub-attribute), plus nested `.enable` etc. where the map shows them. Use the paths verbatim as they appear in `*PresetAttrsMap.php`.
- **Scope = the shared cross-module decoration families only:** boxShadow, filters, transform, sticky, transition, scroll, animation. NOT per-module element maps (#63), NOT $variable/Interactions (#64), NOT CSS-classes/WebGL (`design-effects.md`).
- Signed commits; NO AI-authorship trailer. Local Divi install path: `…/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5`.

## Ground truth (confirmed 2026-07-28)

- Canonical source: `server/Packages/ModuleLibrary/<Module>/<Module>PresetAttrsMap.php` — contains `'module.decoration.<family>__<sub>' => [...]` entries. Confirmed families/subfields on `Text`: `boxShadow__{style,horizontal,vertical,blur,spread,color,position}`, `filters__{blur,brightness,contrast,hueRotate,invert,opacity,saturate,sepia,blendMode}`, `animation__{style,direction,duration,delay,intensity.{slide,zoom,flip,fold,roll},repeat,speedCurve,startingOpacity}`, `scroll__{blur,fade,horizontalMotion,...}(.enable)`, plus `transform`/`transition`/`sticky`.
- Value shapes live in `server/Packages/StyleLibrary/Declarations/<Group>/` (e.g. `Transform/`) and `server/Packages/Module/Options/<Group>/`.
- `module.json` `attributes.module.settings.decoration` enumerates which families a module supports.

## File Structure

- **Create** `skills/divi-5-builder/references/advanced-attributes.md` — the reference (one `##` section per family).
- **Create** `scripts/extract-decoration-paths.php` — dev helper: given a Divi builder-5 path + module name, prints the `module.decoration.*` paths from that module's `PresetAttrsMap.php` (grouped by family). Reusable for #63–#65; makes provenance reproducible. Not loaded by WP.
- **Modify** `skills/divi-5-builder/SKILL.md` — add a Reference Files row.
- **Modify** `FORK.md` — divergence entry (new clean-room reference).

---

## Task 1: Reproducible extraction helper + provenance capture

**Files:** Create `scripts/extract-decoration-paths.php`; capture output to the scratchpad for authoring.

**Interfaces:**
- Produces: a CLI script `php scripts/extract-decoration-paths.php <builder5-path> <ModuleName>` that prints, grouped by family, the unique `module.decoration.<family>...` keys found in that module's `PresetAttrsMap.php`. Later tasks paste its output as the verified path list.

- [ ] **Step 1: Write the extraction script.** It resolves `<builder5-path>/server/Packages/ModuleLibrary/<ModuleName>/<ModuleName>PresetAttrsMap.php`, reads it, regex-matches `'(module\.decoration\.[A-Za-z0-9_.]+)'`, filters to the seven in-scope families, dedupes, groups by family (`boxShadow`, `filters`, `transform`, `sticky`, `transition`, `scroll`, `animation`), and prints them. Fail loudly (non-zero exit, message) if the file is missing or zero paths match — a silent empty result is forbidden (matches the repo's "a gate that inspects nothing must fail" rule).

```php
<?php
// Usage: php scripts/extract-decoration-paths.php <builder5-path> <ModuleName>
// Clean-room provenance helper: prints canonical module.decoration.* dot-paths
// from Divi's own PresetAttrsMap.php. Reads Divi source only; never Pro.
list(, $b5, $module) = array_pad($argv, 3, null);
if (!$b5 || !$module) { fwrite(STDERR, "usage: <builder5-path> <ModuleName>\n"); exit(2); }
$file = rtrim($b5,'/')."/server/Packages/ModuleLibrary/$module/{$module}PresetAttrsMap.php";
if (!is_file($file)) { fwrite(STDERR, "not found: $file\n"); exit(2); }
$src = file_get_contents($file);
$families = ['boxShadow','filters','transform','sticky','transition','scroll','animation'];
$byFam = array_fill_keys($families, []);
if (preg_match_all("/'(module\\.decoration\\.[A-Za-z0-9_.]+)'/", $src, $m)) {
    foreach (array_unique($m[1]) as $path) {
        foreach ($families as $f) {
            if (strpos($path, "module.decoration.$f") === 0) { $byFam[$f][] = $path; break; }
        }
    }
}
$total = 0;
foreach ($families as $f) {
    sort($byFam[$f]); $total += count($byFam[$f]);
    echo "## $f (".count($byFam[$f]).")\n";
    foreach ($byFam[$f] as $p) echo "  $p\n";
}
if ($total === 0) { fwrite(STDERR, "ERROR: zero decoration paths matched in $file — refusing silent empty result\n"); exit(1); }
fwrite(STDERR, "extracted $total decoration paths from $module\n");
```

- [ ] **Step 2: Run it against a core module + confirm non-empty.** Run: `php scripts/extract-decoration-paths.php "/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/themes/Divi/includes/builder-5" Text`. Expected: prints boxShadow/filters/animation/etc. groups with the known subfields; stderr "extracted N…". Capture stdout to the scratchpad as the authoritative path list. Also run for `Blurb` and `Button` to confirm the shared families are consistent across modules (they should be — this is the cross-module system).

- [ ] **Step 3: Cross-check with #42 introspection.** For one family, confirm the same family appears in `module.json` `attributes.module.settings.decoration` (already confirmed: the decoration key lists all families). Note any path the script emits that the schema doesn't recognize (should be none).

- [ ] **Step 4: Commit.**
```bash
git add scripts/extract-decoration-paths.php
git commit -S -m "chore(skills): reproducible decoration dot-path extractor from PresetAttrsMap (#62)"
```

---

## Task 2: Reference scaffold + boxShadow + filters (visual-effects families)

**Files:** Create `skills/divi-5-builder/references/advanced-attributes.md` (header + `## Box Shadow` + `## Filters`). Consumes Task 1's extracted path list.

**Interfaces:**
- Produces: the doc header (title, clean-room provenance note, tier-legend link back to SKILL.md) and the first two family sections. Later tasks append sections in the same shape.

- [ ] **Step 1: Author the header + legend.** Title, one-paragraph purpose, an explicit "Clean-room provenance" line naming the sources (`*PresetAttrsMap.php`, `StyleLibrary/Declarations`, `module.json`, Divi docs, our own VB), and a pointer to `SKILL.md`'s verification-tier legend. State that these paths are the shared `module.decoration.*` system, valid on every module.

- [ ] **Step 2: Author `## Box Shadow`.** From the extracted list: `module.decoration.boxShadow__{style,horizontal,vertical,blur,spread,color,position}`. For each: the value shape (read the corresponding `StyleLibrary/Declarations/BoxShadow` + the `PresetAttrsMap` entry's array), units/enums, and the responsive/`:hover` variant note. One minimal copy-paste `attrs` fragment. Tag `*(verified 2026-07-28)*` (boxShadow is promoted to VB-verified in Task 5). Provenance line: the exact `TextPresetAttrsMap.php` path + the StyleLibrary group.

- [ ] **Step 3: Author `## Filters`.** From the extracted list: `module.decoration.filters__{blur,brightness,contrast,hueRotate,invert,opacity,saturate,sepia,blendMode}`. Value shapes + units (%, deg) + blendMode enum, from `StyleLibrary/Declarations/Filters`. Minimal example. Tag `*(verified 2026-07-28)*` + provenance.

- [ ] **Step 4: Verify each documented path exists in the extractor output** (no invented paths). Run: `php scripts/extract-decoration-paths.php <b5> Text | grep -E 'boxShadow|filters'` and diff against the paths you documented — every documented path must appear. Fix any mismatch.

- [ ] **Step 5: Commit.**
```bash
git add skills/divi-5-builder/references/advanced-attributes.md
git commit -S -m "docs(skills): advanced-attributes reference — boxShadow + filters (#62)"
```

---

## Task 3: transform + sticky (positional families)

**Files:** Modify `skills/divi-5-builder/references/advanced-attributes.md` (append `## Transform` + `## Sticky Position`).

- [ ] **Step 1: Extract the transform + sticky paths.** Run the extractor for `Text` (and cross-check `Button`), capture the `transform`/`sticky` groups. Read `StyleLibrary/Declarations/Transform` + `Module/Options/Transform` for the value shapes.

- [ ] **Step 2: Author `## Transform`.** Document `module.decoration.transform__*` (translate/scale/rotate/skew/origin as the map shows), value shapes, responsive/`:hover` variants, one example. Tag `*(verified 2026-07-28)*` (promoted in Task 5) + provenance.

- [ ] **Step 3: Author `## Sticky Position`.** Document `module.decoration.sticky__*` (position, top/bottom offsets, limits, z-index, transition-on-scroll as the map shows), value shapes, one example. Tag `*(verified 2026-07-28)*` (promoted in Task 5) + provenance.

- [ ] **Step 4: Verify documented paths appear in the extractor output** (as Task 2 Step 4, grep `transform|sticky`).

- [ ] **Step 5: Commit.**
```bash
git commit -S -am "docs(skills): advanced-attributes reference — transform + sticky (#62)"
```
(Explicit `git add` of the reference file; no `-am` sweeping untracked files — use `git add skills/... && git commit -S -m`.)

---

## Task 4: transition + scroll + animation (motion families)

**Files:** Modify `skills/divi-5-builder/references/advanced-attributes.md` (append `## Transition`, `## Scroll Effects`, `## Entrance Animation`).

- [ ] **Step 1: Extract transition/scroll/animation paths** (extractor `Text`, cross-check `Blurb`). Note `scroll__<effect>.enable` nesting and `animation__intensity.{slide,zoom,flip,fold,roll}`.

- [ ] **Step 2: Author `## Transition`.** `module.decoration.transition__{duration,delay,speedCurve}` (per the map), value shapes/units, one example. `*(verified 2026-07-28)*` (promoted in Task 5) + provenance.

- [ ] **Step 3: Author `## Scroll Effects`.** `module.decoration.scroll__{verticalMotion,horizontalMotion,fade,scaling,rotating,blur}` each with its `.enable` + start/end/trigger fields as the map shows. Value shapes, one example. `*(verified 2026-07-28)*` + provenance.

- [ ] **Step 4: Author `## Entrance Animation`.** `module.decoration.animation__{style,direction,duration,delay,intensity.*,repeat,speedCurve,startingOpacity}`. Enum values for style/direction from `StyleLibrary/Declarations/Animation` (or `module.json`). One example. `*(verified 2026-07-28)*` + provenance. Cross-link `design-effects.md` (scroll-triggered CSS-class animations are the DDL alternative).

- [ ] **Step 5: Verify documented paths appear in extractor output** (grep `transition|scroll|animation`).

- [ ] **Step 6: Commit.**
```bash
git add skills/divi-5-builder/references/advanced-attributes.md
git commit -S -m "docs(skills): advanced-attributes reference — transition + scroll + animation (#62)"
```

---

## Task 5: VB-verify the four highest-use families (gold tier)

**Files:** Modify `skills/divi-5-builder/references/advanced-attributes.md` (promote markers).

**REQUIRES Dax's go-ahead to write to the live site** (a scratch module in the VB / an MCP write on a scratch page — never page 900390). Confirm before writing.

- [ ] **Step 1: For each of boxShadow, transform, transition, sticky:** set a representative value on a scratch module via the Visual Builder (or `module_update` on a scratch page), save, and read the saved shape back (`module_get` / inspect the serialized `module.decoration.*` in the post content). Record the observed dot-path + value shape.
- [ ] **Step 2: Compare observed vs documented.** If the VB-saved path matches what Task 2/3 documented → promote that family's marker to `*(VB-verified YYYY-MM-DD)*`. If it differs (schema-canonical vs VB-canonical mismatch — the exact failure the convention guards) → correct the documented path to the VB-observed one and note the discrepancy.
- [ ] **Step 3: Clean up the scratch module/page** (trash, reversible). Record any leftover attachment/page IDs for Dax.
- [ ] **Step 4: Commit.**
```bash
git add skills/divi-5-builder/references/advanced-attributes.md
git commit -S -m "docs(skills): VB-verify boxShadow/transform/transition/sticky to gold tier (#62)"
```

---

## Task 6: Wire-up (SKILL.md + FORK.md) + final consistency pass

**Files:** Modify `skills/divi-5-builder/SKILL.md`, `FORK.md`, `skills/divi-5-builder/references/advanced-attributes.md`.

- [ ] **Step 1: Add a `SKILL.md` Reference Files row:** `| Advanced decoration attributes (shadows, filters, transform, sticky, transition, scroll, animation) | [advanced-attributes.md](references/advanced-attributes.md) |`.
- [ ] **Step 2: Add a `FORK.md` divergence entry** recording the new clean-room reference + the extractor script, sources cited.
- [ ] **Step 3: Final consistency pass:** every family section has canonical path list + value shapes + at least one example + a tier marker + a provenance line; no `<!-- UNVERIFIED -->` ships as authoritative; all seven in-scope families present; markdown lint clean (no broken links). Run the extractor once more and confirm every documented path is in its output (the doc's "test").
- [ ] **Step 4: Commit.**
```bash
git add skills/divi-5-builder/SKILL.md FORK.md skills/divi-5-builder/references/advanced-attributes.md
git commit -S -m "docs(skills): link advanced-attributes reference + record fork divergence (#62)"
```

---

## Task 7: PR

- [ ] **Step 1:** Push `dev/62-decoration-attributes-reference`; open a PR titled for #62 with `Closes #62`, summarizing the families covered, the clean-room provenance (PresetAttrsMap + StyleLibrary + our VB, never Pro), the tier breakdown (4 VB-verified gold, 3 verified), and a note that #82 promotes the full set to gold later.
- [ ] **Step 2:** Adversarial review (fresh agent): spot-check 5–10 documented paths against the real `*PresetAttrsMap.php` for accuracy; confirm zero Pro-derived content; confirm no UNVERIFIED path is presented as authoritative. Squash-merge only after Dax's explicit review.

---

## Self-Review

**Spec coverage:** clean-room boundary → Global Constraints + Task 1 script (Divi-source-only); hybrid methodology → Tasks 2–4 (schema `*(verified)*`) + Task 5 (VB-verified gold); all seven families → Tasks 2–4; deliverable shape (paths + shapes + variants + example + tier + provenance) → each family task; SKILL.md row + FORK.md → Task 6; "testing" via extractor path-existence check → Tasks 2/3/4 Step "verify" + Task 6 Step 3; #82 follow-up noted → Task 7. All spec sections mapped.

**Placeholder scan:** the extractor code is complete; family sections cite concrete paths already confirmed from ground truth; Task 5 value specifics are observed-at-runtime by design (VB round-trip), not placeholders. No TBD/TODO.

**Consistency:** path form `module.decoration.<family>__<sub>` used throughout; the extractor's family list matches the seven documented families; tier markers use the SKILL.md convention verbatim.
