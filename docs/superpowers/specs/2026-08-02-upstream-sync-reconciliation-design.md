# Upstream sync reconciliation (ba008d2 / PR #114) — design spec

Date: 2026-08-02
Related: [#88](https://github.com/rubicon/diviops/issues/88) (closed — announcement tracked, superseded by this),
[#131](https://github.com/rubicon/diviops/issues/131) (deferred launcher-sidecar pieces, already filed),
upstream sync PR [#114](https://github.com/rubicon/diviops/pull/114) (not merged as-is — reconciled piecemeal instead)
Status: design — pending owner review

## Problem

Upstream (`oaris-dev/diviops`) landed real code on 2026-07-30 (`ba008d2`, sync commit
`6546f93`), one commit ahead of our `main`. `rubicon/diviops` is a maintained fork — we
set our own roadmap and are not bound to minimize divergence from upstream, and upstream
is not expected to accept PRs from us (per FORK.md's "Maintained-fork posture" and the
precedent of `oaris-dev/diviops#11`, our own good-citizen report, sitting open with zero
comments since 2026-07-24). That means adoption is optional in each direction, but the
default posture is **not** "ignore upstream" — it is "evaluate every piece on its own
merits, adopt what's good, fix what's broken before adopting it, and defer only with an
explicit, non-lazy reason." Silently dropping a free correctness or security improvement
because reconciling it takes work is the failure mode this spec exists to avoid.

The bundled commit is large (25 files, 962 insertions) and is not one coherent change —
it interleaves at least four independent bodies of work. Treating it as a single
merge-or-reject unit is wrong; each piece needs its own adopt/fix/defer decision, verified
against what this fork has actually built (per FORK.md), not against upstream's diff in
isolation.

## Verification method (applies to every decision below)

Every file's conflict status was checked with a real `git merge-tree --write-tree
--merge-base=<merge-base> main upstream/main` three-way merge simulation — not diff-line
proximity, which both over- and under-states risk. A file with no conflict markers is not
automatically safe to adopt: `diviops-agent.php`'s `/page/create` permission callback
auto-merges with zero conflict markers yet lands semantically wrong code (see PR 2 below),
because git has no way to know that upstream's new function doesn't account for a
parameter *we* added. The check that catches this is holding both sides in view at once —
what upstream assumes, cross-referenced against what FORK.md says we changed — not
anything git's merge algorithm can surface on its own. This method was applied file-by-file
across the full sync; see the five parallel-agent reports this spec summarizes for the
underlying per-file evidence.

## Non-negotiables

- The four frozen identifiers (plugin slug `diviops-agent`, class `DiviOps_Agent`, REST
  namespace `diviops/v1`, filter `diviops_agent_handshake_extensions`) — confirmed
  untouched by every piece of this sync.
- The `divi/global-layout` write-safety guard (`parse_blocks_for_write()` →
  `global_layout_wrapper_identities()` → `global_layout_write_refusal_reason()`, #11/#23/#99)
  — confirmed untouched and non-adjacent by every piece of this sync (verified via merge-tree
  against `trait-core.php`, the highest-risk file by inspection).
- No net loss of existing fork functionality. Where upstream's new code doesn't account
  for something we added (the `post_type` case below), the adoption plan fixes upstream's
  code before we ship it, rather than skipping the adoption or silently regressing our own
  feature.

## Decisions, by category

### Adopt as-is (no design decision required — upstream's code is correct and disjoint)

- `trait-core.php`: `read/write_divi_global_variables_registry()` and
  `read/write_canonical_d5_preset_registry()` — named wrapper helpers around
  `get_option`/`update_option` for the two Divi-owned registries. **Verified this fork never
  had the hazard these wrappers guard against** (grepped the whole plugin: every read/write
  of `et_divi_global_variables` and `et_divi_builder_global_presets_d5` already goes through
  raw `get_option`/`update_option` directly, never Divi's public `GlobalData` getter, which
  is used elsewhere in this plugin but strictly scoped to the separate colors bucket). For
  this fork specifically, adopting these is a **naming/consistency refactor, not a
  correctness fix** — the underlying data-corruption risk the docblock warns about was never
  present in our code. Worth taking anyway so the pattern has one canonical name instead of
  being repeated inline at 6+ call sites across `trait-variable.php`/`trait-preset.php`.
- `trait-preset.php`: `preset_registry_doctor()` migrated to the new
  `read/write_canonical_d5_preset_registry()` helpers (same option, same args — pure
  extract-method). Our only change to this file (`preset_reassign()`, #11) is 1700+ lines
  away in an unrelated function.
- `trait-variable.php`: 7 call sites migrated to the same helpers. Our addition
  (`variable_update()`, #25) is a disjoint append; while adopting, also migrate
  `variable_update()`'s own 2 raw option calls to the new helpers so it isn't the sole
  holdout still bypassing the convention it now exists.
- `trait-rollback.php`: real bug fix — the batch-target loader was bypassing normal WP
  query filters (e.g. language scoping) and could misclassify a real snapshot target as
  gone. No fork interaction.
- `trait-meta.php`: adds `authenticated_user`/`site_url` to the `handshake()` response.
  Confirmed disjoint from our `record_client_runtime()` addition (#123/PR #124) — both sit
  in different regions of the same function body, neither reads nor writes state the other
  touches.
- `diviops-server/src/compatibility.ts`: the two new `HandshakeResult` fields backing the
  above. Purely additive; confirmed absent from `main` today (clean insert).
- `diviops-server/src/wp-client.ts`: `AbortSignal` plumbing for request cancellation.
  Additive, low-risk, useful independent of anything else in this sync.
- `@modelcontextprotocol/{client,core,server}@2.0.0` as **devDependencies only** (not
  shipped in the npm tarball — confirmed excluded from `package.json`'s `files` array).
  Cost is near-zero (dev-time only); having it available means a future verification test
  for `CanonicalToolRegistry`'s multi-target claim (PR 3) doesn't need its own
  dependency-bump PR later. Does not imply building the v2 target itself — that stays
  #131-scoped.

### Adopt with modification (real value, but upstream's code has a gap our fork's own
additions expose — must be fixed as part of adoption, not after)

- `diviops-agent.php` + `trait-page/canvas/library/theme-builder.php`: the
  `published_post_types_permission_result()` family replaces blanket `edit_pages`/
  `manage_options` gates on `page_create`, `page_update_status`, `canvas_create`,
  `canvas_duplicate`, `library_save`, `tb_template_create` with per-post-type capability
  checks. Real gap closed: today none of these routes verify the caller actually holds the
  target post type's registered `create_posts`/`publish_posts` capability.

  **The gap in upstream's own fix**: `page_create_permission_result()` hardcodes
  `get_post_type_object('page')`. Our fork's `page_create()` (#31) accepts a caller-supplied
  `post_type` and creates arbitrary registered types. Adopted verbatim, a caller creating a
  non-`page` type would be gated against `page`'s capabilities regardless of what's actually
  being created — silently wrong, and git's merge won't flag it (see "Verification method").
  Upstream's own sibling functions for canvas/library/theme-builder don't have this problem
  because those routes don't accept a type override — this is specifically where our
  divergence and upstream's new code intersect.

### Hand-port, sequenced last (real value, central/high-touch rather than risky)

- `diviops-server/src/canonical-tool-registry.ts` + the corresponding `index.ts` refactor:
  replaces direct `server.registerTool()` calls with an indirection layer
  (`CanonicalToolRegistry.install(target)`), adds a duplicate-registration guard, and adds
  `isDirectEntryPoint()` so importing the module doesn't auto-run `main()`. The actual edit
  surface is small (~5 points: new imports, the `server`→`registry` declaration, 3 helper
  function bodies, the entry-point guard), but it touches the three functions
  (`registerPluginTool`/`registerLocalTool`/`registerProTool`) that essentially every one of
  our ~30 feature PRs calls into, in the file our own #41/#128 fix (PR #129) just repaired.
  Re-apply by hand against current `main` — not upstream's stale base — and re-run
  `verify-server-builds-and-starts.test.mjs` afterward to confirm `isDirectEntryPoint()`
  doesn't regress the "builds and starts from a clean checkout" guarantee that test
  establishes.

### Already decided, out of scope here

- `target-evidence.ts`, `health-tools.ts` (the launcher-profile/drift-detection sidecar):
  deferred to [#131](https://github.com/rubicon/diviops/issues/131) with an explicit trigger
  condition ("if/when this fork builds or adopts a multi-site launcher"). Not a lazy skip —
  these files implement drift detection for a multi-profile launcher concept this fork
  doesn't have (we run one target off `WP_URL`/`WP_USER`/`WP_APP_PASSWORD` env vars); there
  is no consumer to adopt them into and no way to verify they'd even function correctly for
  us. Issue #88 closed in favor of #131 carrying this forward.
- FluentCart `diviops_fc_order_mark_paid` tool description rewrite (describes a different
  underlying upstream method, `StatusHelper::syncOrderStatuses` vs. the prior
  `OrderController::markAsPaid`): unrelated payload riding in the same sync commit. Flagged,
  not evaluated here — needs its own check against whether our Pro FluentCart tool's actual
  behavior needs to change to match. Will file separately if it turns out to need one; not
  blocking this reconciliation.

## Implementation plan — three sequenced, issue-first PRs

**PR 1 — Safe refactors + additive fields.** Everything in "Adopt as-is" above. One issue,
one PR. No design decisions of our own required; existing test suite (`php tests/run.php`,
`diviops-server` test scripts) is the verification gate.

**PR 2 — Permission-hardening package, with the post_type gap fixed and upstream informed.**

1. TDD, failing-first: add a test asserting `page_create` with `post_type=post` is gated
   against `post`'s own registered capabilities, not `page`'s. Run it against upstream's
   `page_create_permission_result()` verbatim first, to confirm it fails before any fix is
   applied — proof of the bug, not an assumption of it.
2. Fix `page_create_permission_result()` to resolve `$post_type` from the request, mirroring
   the correct pattern upstream's own `published_post_types_permission_result()` already
   uses for canvas/library/theme-builder in the same diff.
3. Hand-merge `/page/create`'s route `args` array: keep our `post_type` field, take
   upstream's `status` enum + `dry_run`.
4. Full `php tests/run.php`, with explicit re-confirmation that #31's existing tests still
   pass unmodified.
5. File a good-citizen issue on `oaris-dev/diviops` (same precedent as our existing
   `oaris-dev/diviops#11`): report the gap concretely, with the fix, once merged on our
   side, cited as a reference implementation.

**PR 3 — `CanonicalToolRegistry` hand-port.** Sequenced last since it's central plumbing
best applied after PR 1/PR 2 are merged and stable, not because it's deferred. Re-apply the
~5 edit points against current `main`; re-run the #41/#128 regression test
(`verify-server-builds-and-starts.test.mjs`) as the acceptance gate.

## Testing approach

- PR 1: existing suites are sufficient (pure refactors + additive fields, no new behavior
  to specify).
- PR 2: new test written failing-first against unmodified upstream code (see step 1 above),
  proving the regression before the fix exists — not a test written after the fix to
  rubber-stamp it.
- PR 3: the existing `verify-server-builds-and-starts.test.mjs` (from PR #129) is the
  regression gate; if `isDirectEntryPoint()` or the registry indirection breaks the "starts
  from a clean checkout" guarantee, that test already catches it.

## Out of scope

- Building a multi-site launcher or a v2 SDK compatibility target (tracked, not decided,
  in #131).
- Any FluentCart Pro tool-description changes (flagged for separate review if needed).
- Any further upstream sync beyond this single commit (`ba008d2`/`6546f93`) — the next sync
  gets its own review when it lands.
