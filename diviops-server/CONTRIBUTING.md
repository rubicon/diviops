# Contributing to diviops-server

This file documents `diviops-server`-specific developer conventions. For the
project's overall contribution process (issues, branches, PRs), see the
[repository CLAUDE.md](../CLAUDE.md) and the root [CONTRIBUTING.md](../CONTRIBUTING.md).

## Skill-doc regeneration: `regen-module-formats.mjs`

`skills/divi-5-builder/references/module-formats.md` documents Divi 5's module
attribute paths in three tiers: Tier 1 (universal `module.decoration.*`,
hand-written), Tier 2 (shared pattern families, hand-written), and a
per-module "Generated path index" — the free tier's Tier-3-equivalent: which
`{element}.decoration.{group}` paths each module actually declares. That last
section is mechanically maintained by
`diviops-server/scripts/regen-module-formats.mjs` — this document is the
"full convention" its own header comment points to.

### The sentinel convention

Two kinds of HTML-comment sentinel pairs bound regen-owned regions. Content
between a BEGIN/END pair is replaced wholesale on every run; content outside
them (the Tier 1/2 prose, the "Exceptions Quick Reference" table, footnotes)
is never touched by the script and is safe to hand-edit.

- `<!-- BEGIN GENERATED:header --> ... <!-- END GENERATED:header -->` — the
  "Generated path index" heading, its explanatory prose, and the
  `` > Generated against Divi `X`, schema `Y…`. `` provenance line.
- `<!-- BEGIN GENERATED:module:divi/<slug> --> ... <!-- END GENERATED:module:divi/<slug> -->`
  — one pair per module, wrapping a `<!-- TIER: free -->` marker, the
  `` #### `divi/<slug>` `` heading, and one bullet per indexable element.

**Do not hand-edit anything between a BEGIN/END pair.** The next regen run
overwrites it silently — that is the contract the header line states
explicitly, and it is why PR #115's stopgap note (added when this script
didn't exist yet) lived inside the header sentinel and disappeared the
moment regen ran again after this script was written.

### What "regenerate" means

The script's only mode refreshes every module block **that already exists**
as a sentinel pair. It does not discover and add every module the live
site's schema knows about.

This is deliberate, not an oversight. Verified directly against a live
reference install: Divi ships 84 native module-library components, and the
same site has 109 registered third-party modules (`difl/*`, `d5bgo/*`) —
all structurally identical (`attributes.<key>.settings.decoration.*`). The
committed index covers a curated 28 core modules. Issue #63 frames that set
as an intentionally curated "starter batch... expand iteratively," not a
full mechanical dump. So which modules belong in the index is an editorial
call, made by hand-adding a new sentinel pair for that module (see "Adding a
new module" below) — the script's job is keeping already-curated blocks
honest against the live schema, not choosing what to curate.

### Running it

```bash
cd diviops-server
WP_URL=http://your-site.local \
  WP_USER=your-wp-username \
  WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" \
  node scripts/regen-module-formats.mjs
```

Same three env vars the MCP server itself uses (see
[README.md#environment-variables](README.md#environment-variables)) — an
Application Password for a user with at least `edit_posts` (the schema
routes' permission callback), on a site running this fork's plugin
(`schema_get_module_dump_all` merging native modules into the dump is a fork
addition, #61 — a stock/upstream plugin's dump won't include most core
modules).

The script prints how many blocks it touched and whether the file's content
actually changed. Review the diff before committing — a change is expected
whenever `divi_version`/`schema_version` moved (Divi itself updated) or a
documented module's schema genuinely changed; treat a Tier-3 diff for an
*unexpected* reason as a signal to go verify what changed in the Visual
Builder, not as busywork to rubber-stamp.

### Adding a new module

The script has no "add" mode. To bring a new module into the generated
index, add its empty sentinel pair by hand at the correct alphabetical
position:

```markdown
<!-- BEGIN GENERATED:module:divi/<slug> -->

<!-- END GENERATED:module:divi/<slug> -->
```

then run the regen script — it fills that pair in like any other, and fails
loudly (naming the module) if the slug doesn't resolve against the live
schema, so a typo can't silently produce an empty block.

### The per-module algorithm

For each module, for every top-level `attributes` key except the four
universal Gutenberg/Divi keys (`metadata`, `className`, `style`, `lock`):

- List `{key}.decoration.{name}` for every key present under that
  attribute's `settings.decoration` — **presence, not population**: an
  empty `{}` still counts, since the schema dump lists an option as
  *available*, not necessarily *populated* with fields. Zero keys renders
  as `_(no decoration groups)_` rather than an empty list.
- Append `_(+innerContent)_` and/or `_(+advanced)_` when those keys are
  present under that attribute's `settings` (same presence rule, in that
  order when both apply).

Elements and decoration-group names are both sorted alphabetically for
deterministic output. `<!-- TIER: free -->` is a constant, not derived from
the schema — Divi's own module schema carries no free/pro distinction (this
fork's dump-all response has no such field), and this file only ever
documents this fork's free-tier reference.

### Tests

`scripts/regen-module-formats.test.mjs` (`npm run test:regen-skill`) covers
the transform logic (schema JSON to markdown) against synthetic fixtures —
modeled on real Divi module shapes but not copied from Divi's own
`module.json` (which carries Divi's own labels and descriptions), matching
the `tests/fixtures/divi-module-library/` convention on the PHP side. The
network/file-I/O orchestration (`fetchDumpAll`, `main`) has no
unit-testable logic of its own; it's verified by actually running the
script against a live reference site and diffing the result, the same way
PR #115 verified its hand-written blocks.
