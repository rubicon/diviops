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
committed index covers a curated 30 core modules. Issue #63 frames that set
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

## Per-tool reference regeneration: `regen-tool-reference.mjs`

`README.md`'s "Per-tool reference" section — one row per MCP tool, with its
inputs, `_meta.idempotent` marker, and a one-sentence summary — is generated
by `scripts/regen-tool-reference.mjs` from the `registerPluginTool` /
`registerLocalTool` / `registerProTool` call sites in `src/index.ts` (#93).

Hand-maintaining it is not viable: there are 145 call sites, and the two
prose tool counts that preceded this table went stale three times in a
single day before #90 corrected them.

### The sentinel convention

Same convention as the skill-doc regen above, with three pairs:

- `<!-- BEGIN GENERATED:tool-reference:header --> ... <!-- END ... -->` — the
  `## Per-tool reference` heading, the provenance note, the counts sentence,
  and the column legend.
- `<!-- BEGIN GENERATED:tool-reference:always-on --> ... <!-- END ... -->` —
  the plugin-backed + server-local table.
- `<!-- BEGIN GENERATED:tool-reference:pro --> ... <!-- END ... -->` — the
  conditionally-registered Pro table.

Content between a pair is replaced wholesale on every run. Everything
outside them, including the hand-curated "Tools at a glance" category table,
is never touched.

### Running it

```bash
cd diviops-server
npm run regen:tool-reference
```

No WordPress site and no credentials: the whole tool surface is declared in
source. Run it in the same commit as any change that adds, removes, or
renames a tool, or that edits a tool's `description`, `inputSchema`, or
`_meta.idempotent`.

### How the call sites are read

Through the TypeScript compiler's own parser, not a regex. The
registrations carry shapes a pattern match cannot follow safely: a
description assembled by string concatenation (`"..." +
DRY_RUN_DESC_SUFFIX`) or by a template literal whose value the server fills
in at handshake time, a config object spread in from another module
(`...META_PING_CONFIG`, declared in `health-tools.ts`), an `inputSchema`
spread in from a shared constant, and an input field whose optionality lives
in a shared `DRY_RUN_FIELD`/`BACKUP_FIELD` constant rather than at the call
site. The script therefore parses `src/index.ts` plus every sibling module
it imports by relative path, and resolves identifiers against the top-level
constants declared across that set.

A field counts as optional when its Zod chain contains `.optional()`,
`.default()`, or `.nullish()`. A `${...}` placeholder in a description
renders as `…`, because its value is a handshake-time decision that source
alone cannot answer.

The script fails loudly rather than emitting a thinner table: an identifier
it cannot resolve, a registration with no `description` or no
`_meta.idempotent`, a Pro registration with no literal gates, and a source
file with zero registration call sites are all errors.

### Tests

`scripts/regen-tool-reference.test.mjs` (`npm run test:tool-reference`)
covers the transform against synthetic TypeScript fixtures written for the
test rather than copied from `src/index.ts`, including every failure mode
above.

Two of its cases run against the real source and are the staleness guard:
one asserts the committed `README.md` is byte-identical to what regen
produces right now (so a tool change that skips the regen step fails CI),
and one cross-checks the AST extraction against the same line-anchored
call-site regex `tests/test-tool-count-sync.php` uses, so a parse that
silently dropped call sites fails rather than quietly shrinking the table.
Neither can pass vacuously: the extraction throws when it finds no call
sites, and the regex cross-check asserts it matched at least one.
