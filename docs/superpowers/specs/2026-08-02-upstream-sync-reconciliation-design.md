# Upstream sync reconciliation (ba008d2 / PR #114) — design spec

Date: 2026-08-02
Related: [#88](https://github.com/rubicon/diviops/issues/88) (closed — announcement tracked, superseded by this),
[#131](https://github.com/rubicon/diviops/issues/131) (deferred launcher-sidecar pieces, already filed),
upstream sync PR [#114](https://github.com/rubicon/diviops/pull/114) (not merged as-is — reconciled piecemeal instead)
Status: design — pending owner review

## Revision history

- 2026-08-02, initial draft.
- 2026-08-02, amended after a Codex adversarial review (`/codex:adversarial-review`,
  verdict `needs-attention`) surfaced three findings, all independently verified against
  the actual upstream diff before being accepted: (1) `health-tools.ts` was wrongly
  classified as entirely launcher-only and deferred — three of its exports
  (`META_PING_CONFIG`/`META_INFO_CONFIG`/`requestAbortSignal`) are consumed by upstream's
  own production `diviops_meta_ping`/`diviops_meta_info` registrations and needed to move
  into PR 3; (2) PR 3's stated test gate (`verify-server-builds-and-starts.test.mjs`) does
  not exercise `CanonicalToolRegistry` behavior at all — a new registry-specific test is
  now required; (3) the `package.json` "adopt as-is" scope was ambiguous — upstream's own
  `test` script hunk references `scripts/check-release-boundary.mjs`, which does not exist
  even on `upstream/main` itself, confirmed via `git cat-file -e`. All three corrected
  below in place; nothing removed from the review's findings without a verification step
  first.
- 2026-08-02, corrected during implementation planning (`writing-plans`, PR 2): the
  "TDD, failing-first" step this spec originally specified for the `page_create` `post_type`
  bug is not achievable — `current_user_can()`/`get_post_type_object()` are unshimmed in this
  repo's test harness, so a controlling test would be mocked-behavior, which this project's
  engineering policy prohibits outright, not merely discourages. Caught before any test was
  written, per the same policy's explicit "STOP and warn Dax" instruction. Corrected to
  inspection + full-regression-suite verification, matching PR 1's already-established
  precedent for the same class of unshimmed-primitive change. Also newly found in the same
  pass: `page_update_status_permission_result()` narrows that route to `page`-type posts only,
  a real (if likely desirable) behavior change the original research pass didn't surface —
  now called out explicitly in PR 2 rather than folded silently into "adopt with
  modification."

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
- `diviops-server/package.json`, **scoped to exactly one sub-hunk**: add
  `@modelcontextprotocol/{client,core,server}@2.0.0` as **devDependencies only** (not
  shipped in the npm tarball — confirmed excluded from `package.json`'s `files` array).
  Cost is near-zero (dev-time only); having it available means a future verification test
  for `CanonicalToolRegistry`'s multi-target claim (PR 3) doesn't need its own
  dependency-bump PR later. Does not imply building the v2 target itself — that stays
  #131-scoped.

  **Explicitly do NOT adopt the rest of this file's upstream hunk**, verified line-by-line:
  the version bump (`1.5.38`→`1.5.39`, upstream's own version — irrelevant, we run
  release-please), the `files` array's `launcher-bootstrap`/`launcher-sidecar` exclusions
  (moot — we don't have those files), and — this is the one that would actually break
  something — the `test` script's added `&& node scripts/check-release-boundary.mjs`.
  Confirmed via `git cat-file -e`: **that script does not exist even on `upstream/main`
  itself**, in this same sync commit. Adopting the script line verbatim would break `npm
  test` immediately (`ENOENT`) with no such file anywhere to reconcile it against —
  this isn't an integration gap on our side, upstream's own sync commit references a file
  it never shipped. Do not adopt this line under any circumstance until upstream actually
  publishes the script (revisit opportunistically on the next sync, not tracked separately).

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
  Re-apply by hand against current `main` — not upstream's stale base.
- **Also part of this PR** (moved here from the deferred bucket, see correction above):
  `health-tools.ts`'s production-consumed exports — `META_PING_CONFIG`, `META_INFO_CONFIG`,
  `requestAbortSignal()` — wired into the existing `diviops_meta_ping`/`diviops_meta_info`
  registrations exactly as upstream's own diff does it. The file's launcher-only exports
  (`LAUNCHER_HEALTH_TOOL_NAMES`, `modelVisibleHealthResult`, `registerLauncherHealthTools`)
  are NOT part of this PR — vendor only the three production-consumed exports (as a new
  `health-tools.ts` containing just those three, or inline into `index.ts` — implementer's
  call, either is fine as long as `target-evidence.ts`/`TargetEvidence` is not introduced as
  a dependency here).

**Acceptance gate — corrected.** The existing `verify-server-builds-and-starts.test.mjs`
(from PR #129) is necessary but **not sufficient** here, verified by reading the test
directly: its three assertions are build success, the vendored cross-env-preflight files
existing in `dist/`, and the server reaching the missing-credentials refusal message. It
never reaches handshake finalization, `CanonicalToolRegistry.install()`, Pro-conditional
registration, resource registration, or duplicate-registration detection — none of the
actual behavior this refactor changes. A hand-port could compile, pass this test, and still
silently drop tools/resources or mis-gate Pro tools against the wrong handshake state.

This PR needs its **own new test** (in addition to re-running `verify-server-builds-and-
starts.test.mjs` for the build/startup regression it does cover) that: imports the built
module directly, finalizes the registry against both a synthetic "ok" and a synthetic
"failed" handshake state, installs into a recording/fake registrar, and asserts — by name —
that the expected tool and resource sets are present, `_meta.idempotent` metadata matches
`annotations.idempotentHint` for every registered tool (the #128 invariant, re-verified
under the new registration path), Pro-gated tools register only under the "ok" +
Pro-capable state, and a deliberately duplicated registration throws instead of silently
overwriting.

### Already decided, out of scope here

- `target-evidence.ts` in full, and **only the launcher-specific exports of
  `health-tools.ts`** — `LAUNCHER_HEALTH_TOOL_NAMES`, `modelVisibleHealthResult()` (takes a
  `TargetEvidence`, which doesn't exist without adopting `target-evidence.ts` too), and
  `registerLauncherHealthTools()` (the stripped-down 2-tool launcher-mode server). Deferred
  to [#131](https://github.com/rubicon/diviops/issues/131) with an explicit trigger condition
  ("if/when this fork builds or adopts a multi-site launcher"). Not a lazy skip — these
  implement drift detection for a multi-profile launcher concept this fork doesn't have (we
  run one target off `WP_URL`/`WP_USER`/`WP_APP_PASSWORD` env vars); there is no consumer to
  adopt them into and no way to verify they'd even function correctly for us.

  **Correction from an earlier version of this spec** (caught by adversarial review, verified
  against the actual diff before amending): the earlier draft classified the *entire*
  `health-tools.ts` file as launcher-only and deferred it wholesale. That was wrong.
  `health-tools.ts` also exports `META_PING_CONFIG`, `META_INFO_CONFIG`, and
  `requestAbortSignal()` — plain tool-metadata objects and a context-unwrapping utility with
  no `TargetEvidence`/launcher dependency — and upstream's own `index.ts` diff imports
  exactly those three into its **existing production** `diviops_meta_ping`/`diviops_meta_info`
  registrations (confirmed: `git show upstream/main:diviops-server/src/index.ts` — those
  registrations still call plain `serializeEnvelope()`, never `modelVisibleHealthResult()` or
  anything `TargetEvidence`-shaped). Those three exports are genuinely production-consumed by
  the `CanonicalToolRegistry` refactor and move to **PR 3** (below), not #131. #131's issue
  body needs a one-line update reflecting this split before PR 3 starts. Issue #88 closed in
  favor of #131 carrying the genuinely-deferred pieces forward.
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

1. **Verification approach corrected** (see Revision history): `current_user_can()` and
   `get_post_type_object()` — everything this permission family depends on — are not shimmed
   in `tests/wp-shim.php`, the same boundary `trait-core.php`'s registry helpers hit in PR 1.
   A "failing-first unit test" for the `post_type` bug would require faking
   `current_user_can()`'s return value to control which capability check passes, which is a
   mocked-behavior test — prohibited outright ("NEVER write tests that 'test' mocked
   behavior — if you find some, STOP and warn Dax"), not a judgment call to make unilaterally.
   Verification for this PR is **inspection + full regression suite**, matching PR 1's already-
   established precedent for every other unshimmed-primitive change in this reconciliation —
   not a new unit test.
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

**Also found during implementation planning, not in the original research pass**:
`page_update_status_permission_result()` adds `'page' !== (string) $post->post_type` to the
not-found check. Today `page_update_status()` accepts any post type the id resolves to; this
narrows it to pages only. Given the route (`/page/update-status`) and its MCP tool
(`diviops_page_update_status`) are both explicitly page-scoped, and no known caller relies on
using this route for non-page types, this narrowing is adopted — but it is a real behavior
change, not a no-op refactor like the rest of this PR, and is called out explicitly here
rather than folded silently into "adopt with modification."

**PR 3 — `CanonicalToolRegistry` hand-port + `health-tools.ts`'s production exports.**
Sequenced last since it's central plumbing best applied after PR 1/PR 2 are merged and
stable, not because it's deferred. Re-apply the ~5 `index.ts` edit points against current
`main`, plus the `META_PING_CONFIG`/`META_INFO_CONFIG`/`requestAbortSignal` wiring. Update
#131's issue body to reflect that only `health-tools.ts`'s launcher-only exports remain
deferred. Acceptance gate is the existing `verify-server-builds-and-starts.test.mjs` (build/
startup regression) **plus** a new registry-behavior test — see "Testing approach" below;
the existing test alone is not sufficient for this PR.

## Testing approach

- PR 1: existing suites are sufficient (pure refactors + additive fields, no new behavior
  to specify).
- PR 2: no new unit test — `current_user_can()`/`get_post_type_object()` are unshimmed (same
  boundary as PR 1's `trait-core.php` helpers), so a test controlling their return values
  would be mocked-behavior, prohibited outright. Verified by inspection (the fix reads
  `$post_type` from the request instead of a hardcoded string, mirroring the already-correct
  sibling pattern in the same upstream diff) plus a full `php tests/run.php` regression run
  confirming #31's existing tests still pass unmodified. A live `tests-live/` test against
  colleyvillelions.local remains a real option for later, deliberately deferred rather than
  bundled into this PR (needs a second, lower-capability WP user provisioned first — a site
  change requiring separate confirmation per this repo's site-constraints).
- PR 3: `verify-server-builds-and-starts.test.mjs` still gates the "builds and starts from a
  clean checkout" guarantee (catches `isDirectEntryPoint()`/registry-indirection regressions
  in that specific path), but does not exercise `CanonicalToolRegistry` behavior at all — a
  new, PR-3-specific test is required: finalize the registry against synthetic ok/failed
  handshake states, install into a recording registrar, and assert tool/resource names,
  `_meta.idempotent`/`annotations.idempotentHint` agreement (the #128 invariant, re-verified
  under the new path), Pro-gating correctness, and duplicate-registration failure. Written
  failing-first against the pre-refactor registration code where meaningful (e.g. duplicate-
  registration detection didn't exist before this PR, so that assertion should fail against
  `main` prior to the hand-port and pass after).

## Out of scope

- Building a multi-site launcher or a v2 SDK compatibility target (tracked, not decided,
  in #131).
- Any FluentCart Pro tool-description changes (flagged for separate review if needed).
- Any further upstream sync beyond this single commit (`ba008d2`/`6546f93`) — the next sync
  gets its own review when it lands.
