# Bulk / site-wide content operations (issue #38) — design spec

Date: 2026-08-14
Issue: [#38](https://github.com/rubicon/diviops/issues/38)
Status: design — pending owner review. **No implementation authorized by this document.**

## Revision history

- 2026-08-14, initial design. Authored against the code at `origin/main` `53922e8`. No
  owner decisions taken yet; every decision below is a recommendation carrying its
  reasoning, and the open questions are named as open.

## Summary of the recommendation

Ship the read half of #38 and the one write that cannot corrupt content. Hold the
content-mutating half behind a proven harness, and refuse several operations
permanently.

| # | Operation | Verdict |
| - | --------- | ------- |
| 1 | `content_search` — site-wide literal search over `post_content`, read-only | **Build first, ships alone** |
| 2 | `bulk_status_change` — post status only, never touches content | **Build second, on a new write harness** |
| 3 | `bulk_find_replace` — literal string, raw-byte splice, no parse | **Build third, gated on 1+2 having lived on a real site** |
| — | Regex find/replace, bulk trash/delete, bulk module-attribute edits, bulk Theme Builder edits, query-as-write-target, bulk restore | **Never** — see "What we deliberately will not build" |

The smallest genuinely useful slice is operation 1 by itself. It delivers the
"site-wide content search" the issue names, at zero blast radius, and it is the
prerequisite that makes the explicit-target-list model in operations 2 and 3 usable.
**If operation 1 ships and nothing after it ever does, that is a good outcome, not an
abandoned project.** Say so out loud, because the failure mode of a spec like this one
is that it silently converts "we designed a safe path to bulk writes" into "we are now
committed to bulk writes."

## Problem

`find_and_replace_section` (`trait-page.php:3454`) is single-page. `preset_reassign`
(`trait-preset.php:1941`) is the only site-wide write the plugin has. Missing: bulk
status change, cross-page find/replace, site-wide content search.

This is the highest-blast-radius item in the repository. A single-page write that goes
wrong damages one page and a human notices immediately. A bulk write that goes wrong
damages many pages, and nobody notices until a client does. The maintainer runs this
against real client sites.

## Verified facts (each traceable to a command run while writing this spec)

Cited before anything is built on them. Read the code, not this list, before
implementing.

### The existing safety machinery, and exactly what each piece does and does not cover

- **`update_post_content_with_integrity_guard()`** (`trait-core.php:585`) writes
  `post_content`, reads it straight back, and reverts to `$previous_content` if the
  stored bytes differ from the requested bytes. It compares **stored vs. requested**.
  It does **not** compare requested vs. original. A write that faithfully persists
  content the caller never meant to produce passes this guard cleanly. This is the
  single most important fact in this document; see "Interaction with the round-trip
  hazard".
- **`assert_divi_full_content_safe_for_write()`** (`trait-core.php:547`) checks
  opener/closer marker balance and marker sequence only.
- **`global_layout_write_refusal_reason()`** (`trait-core.php:383`) compares the
  *multiset of `globalModule` ids* between two content strings and returns
  `identity_lost`, `scan_unreliable`, or `null`. It is **a raw-string scan, not a
  parse** — it walks openers via `next_block_opener()` / `block_opening_comment_end()`
  and takes `string $content` on both sides. It is therefore available to a
  non-parsing bulk write at no structural cost. It fails closed: an unresolvable
  opener anywhere in the document refuses the write.
- **`parse_blocks_for_write()`** (`trait-core.php:259`) routes a write-path parse
  through Divi's `BlockParserUtils::parse_blocks_with_layout_context( $content,
  'saving_content' )` when that class exists, else plain `parse_blocks()`.
- **`rollback_snapshot_*`** (`trait-rollback.php`) stores **one `wp_option` row per
  snapshot**, each holding the target's full prior `post_content` (`before.value`),
  written with `add_option( …, '', 'no' )` — not autoloaded (`:207`). Expiry is 7 days
  (`:84-87`). The record's `target` is a **single post** (`kind`/`id`/`post_type`,
  `:137-143`). There is no multi-target snapshot shape.
- **`rollback_snapshot_restore()`** (`:880`) restores **one** snapshot, refuses with
  `conflict` (409) if the live target drifted from the recorded `after` checksum or
  side-effect meta, and explicitly ships **no force override** (`:924`,
  `force.supported: false` at `:1020`).
- **`rollback_snapshot_managed_inventory()`** (`:513`) — the PHP service seam Pro's
  managed recovery reads — caps at **1000 records** and deliberately **fails closed**
  when a sentinel row beyond the ceiling is present, rather than planning from a
  truncated subset (`:523-534`).
- **`module_lock` / `module_unlock`** (`trait-page.php:4017`, `:4117`) set and remove
  `attrs.locked = { desktop: { value: "on" } }`. The docblock at `:4012-4016` states
  the lock gates **VB-side editing only**. Grepping `trait-page.php` for a write-path
  refusal keyed on `locked` returns nothing: **no REST or MCP write path currently
  refuses a locked module.** `module_update()` does not consult it.
- **Rate limiting** (`diviops-agent.php:313`) is per-user, per-minute, bucketed
  read/write, defaulting to **30 writes/min** (`:304`), configurable by constant, env
  var, or the `diviops_rate_limits` filter. A client-side loop of N single-page writes
  therefore stalls at 30/min; a server-side batch is one write request.
- **`query_inspectable_post_ids()`** (`trait-core.php:1977`) is the canonical row-level
  read filter. It prefilters with `perm => 'editable'` and then re-checks every object,
  scanning at most 5000 candidates and reporting `truncated`.
- **`REASSIGN_MAX_PAGES = 1000`** and **`VARIABLES_SCAN_MAX_POSTS = 2000`**
  (`diviops-agent.php:172-173`) are the existing site-wide scan caps.

### `preset_reassign` — the only existing site-wide write, and what it teaches

Read at `trait-preset.php:1941-2473`. It is prior art in both senses.

What it gets right, and this design reuses:

- `mode` defaults to `dry-run`; `apply` is explicit.
- A hard cap, enforced **both** on an explicit `page_ids` list (rejected outright with
  `preset.too_many_pages`, `:2084`) and on a full-site scan (truncated with a
  `truncated` flag and a dry-run warning, `:2110-2113`, `:2430`).
- A fast path that skips the expensive parse when the raw content does not even mention
  the target (`:2139`).
- Per-post `current_user_can( 'edit_post' )` re-check inside the loop (`:2304`), on top
  of the route-level gate.
- Per-page failure isolation: an error is recorded against that page and the batch
  continues (`:2305`, `:2339-2355`).
- It re-reads the preset registry immediately before the chain rewrite to shrink the
  stale-overwrite window opened by a long scan (`:2379`).

What it gets wrong, and this design deliberately does not copy:

- **It creates no rollback snapshot at all.** No call to any `rollback_snapshot_*`
  helper appears in the function. A 200-page apply is unrecoverable except through
  WordPress revisions, which `wp_update_post` may or may not have created per page.
- **It bypasses `update_post_content_with_integrity_guard()`** and calls
  `wp_update_post` directly (`:2332`), with a comment explaining that the guard's
  single-post readback/revert contract does not fit a batch (`:2314-2320`). The
  consequence is that its per-page writes get **no readback verification and no
  auto-revert**.
- **It round-trips every matched page through `parse_blocks_for_write()` →
  `serialize_blocks()`** (`:2153`, `:2312`) with no check that the round trip preserved
  anything except global-layout wrapper identity.
- **A partial application reports as `ok: true`.** The envelope is
  `envelope_success([...])` with a nested `success` boolean derived from
  `empty( $summary['errors'] )` (`:2462-2472`). A caller branching on the envelope's
  `ok` — which is what the harness primer tells callers to do — sees success on a run
  where pages failed.
- **Omitting `page_ids` targets every page and post on the site**, and the set is
  resolved fresh at apply time, so the apply can touch pages the dry-run never showed.

Those five points are not incidental. They are the specific things a bulk-operations
design has to answer, and #38 exists because nobody has.

### The round-trip hazard, stated precisely

Two separate problems have been conflated in the repo's own history. Separating them
matters, because one is fixed and one is not.

1. **The write-safety validator false positive is fixed.**
   `find_malformed_block_attr_escape()` rejected real Divi-authored markup containing
   `$variable({…})$` tokens with bare `u003c`-shaped payloads. Fixed across four review
   rounds in commit `c2993e6` (#97 / #105), which replaced the regex with the
   `json_string_segments()` / `variable_token_end()` / `strip_variable_tokens()`
   tokenizer trio and re-verified page 900390 as accepted, byte-identical. Do **not**
   cite this as a live hazard.
2. **The lossy round trip is not fixed, and cannot be guarded by anything currently in
   the tree.** `FORK.md`'s `trait-page.php` divergence row records that duplicating page
   900390 through `parse_blocks_for_write()` → `serialize_blocks()` produced
   **62,167 → 61,855 bytes** — 312 bytes lost on a page that parses and renders fine —
   and that `page_duplicate` (#35) was consequently rewritten as a byte copy so the
   hazard became "structurally impossible rather than merely guarded". That number is
   quoted from the committed divergence record; it was **not re-measured while writing
   this spec** (the reference site is read-only for this work).

The reason no existing guard catches (2) is the fact stated at the top of this section:
`update_post_content_with_integrity_guard()` verifies that WordPress stored what we
asked for. A silently normalized re-serialization *is* what we asked for. Marker
balance is preserved. Global-layout identity is preserved. The write is clean by every
check the plugin has, and 312 bytes are gone.

On one page, a human looks at the result. Across 200 pages, nobody does. **This is why
bulk operations in this design do not parse.**

### Baseline

`php tests/run.php` at `origin/main` `53922e8`: **PASS 1122 assertion(s) in 34
file(s)**. (The task brief cited 1109/33; that baseline predates a test added since.)

## The seven decisions

### 1. Blast-radius containment — what bounds a single operation

**Decision: an explicit id list, and only an explicit id list, for every write. Hard
cap of 50 targets per apply call. Never a query.**

A query as a write target is re-evaluated at apply time. Between the dry-run the caller
read and the apply the caller authorized, a post can be published, edited, restored
from trash, or have its status changed — by a VB session, by a scheduled publish, by a
second MCP session. The set that applies is then not the set that was reviewed.
`preset_reassign` has exactly this shape today (omit `page_ids`, get every page and
post on the site, resolved at apply time), and it is the least safe thing in the
repository.

Discovery and mutation are therefore split into two tools. `content_search` answers
"which posts match"; the caller passes the resulting ids back into the write. The extra
round trip is the feature: it forces the target set to become a value the caller has
seen, rather than a predicate the server re-evaluates.

The cap is **50**, not `REASSIGN_MAX_PAGES`'s 1000. Four independent reasons, and they
converge:

- **Snapshot cost.** Every target gets a mandatory snapshot holding its full prior
  content. At a ~60 KB page, 50 targets is ~3 MB of `wp_options` rows per run. At 1000
  targets it is ~60 MB per run, and
  `rollback_snapshot_managed_inventory()`'s 1000-record ceiling — which **fails closed**
  — is breached by a single run, degrading Pro's managed recovery for the whole site.
- **Request budget.** 50 sequential guarded writes (each a `wp_update_post` plus a full
  readback) is a workload a normal PHP request can finish. 1000 is not, on shared
  hosting, and a bulk write that dies at `max_execution_time` is the worst possible
  failure mode.
- **Reviewability.** A dry-run plan a human is expected to actually read has an upper
  bound measured in tens of entries, not thousands. A cap that exceeds what anyone
  reads converts mandatory dry-run into mandatory rubber-stamping.
- **Chunking is not a hardship.** The caller already holds the id list. Splitting it is
  one line, and each chunk gets its own reviewed plan and its own run record.

Over the cap returns `bulk.too_many_targets` (400) with `error.data = { received,
max_targets }`, mirroring `preset.too_many_pages`'s shape. Never truncate silently, and
never truncate-with-a-flag: `preset_reassign`'s `truncated` flag on a full-site scan is
a warning inside a response a caller may not read, and it means the apply covered a
different set than the caller believes. An oversized bulk request is a refusal.

Additional bounds, all cheap:

- Allowed post types are an explicit allowlist (`page`, `post`), matching
  `preset_reassign`'s query and excluding Theme Builder layout types by construction
  (see decision 5).
- Every target is re-checked with `current_user_can( 'edit_post', $id )` inside the
  loop, immediately before its write — not only at plan time.
- A target that no longer exists at apply time is a refusal of the run, not a skip: it
  means the world moved under the plan.

### 2. Dry-run — mandatory, and bound to the apply

**Decision: mandatory, and cryptographically bound to the apply via a stateless plan
token.**

"Dry-run defaults to true" is not mandatory dry-run. `preset_reassign` defaults to
`mode=dry-run`, and a caller can still pass `mode=apply` on the very first call, having
seen nothing. For an operation with this blast radius that is the wrong default
strength.

The contract:

- `dry_run: true` (or the absence of a `plan_token`) returns the standard harness plan
  shape (`data.plan = { summary, changes[], warnings[] }` per the `diviops` primer)
  **plus** a `plan_token`.
- The token is a SHA-256 over a canonical serialization of: the operation name, the
  ordered target id list, each target's current `post_content` checksum, the normalized
  operation parameters, the acting user id, and an issue timestamp. It is a **hash, not
  a stored record** — nothing to persist, nothing to garbage-collect, nothing to leak.
- `apply` **requires** `plan_token`. The handler recomputes the token from live state
  and compares with `hash_equals`. A mismatch returns `bulk.plan_stale` (409) whose
  `error.data` names exactly which targets drifted, by id and by checksum. Tokens older
  than **15 minutes** are rejected as stale, matching
  `rollback_snapshot_created_stale_seconds()`'s existing 15-minute staleness horizon.

This buys three things at once, and each is load-bearing: you cannot apply without
having generated a plan; the plan you read is provably the plan that applies; and the
time-of-check/time-of-use window between preview and apply is detected rather than
raced.

**What the plan must show for a caller to judge it.** Per target:

| Field | Why it must be there |
| ----- | ------------------- |
| `id`, `post_type`, `title`, `status`, permalink | The caller has to recognize the page |
| `content.checksum`, `content.byte_length` | Identity of what is about to be modified |
| `uses_divi` | A non-Divi post in a Divi bulk op is a signal, not a detail |
| `matches[]` — for each match, byte offset, a bounded context window either side, and the exact replacement | The only way to judge a find/replace is to see the actual matches in situ |
| `match_location` per match — `body` or `block_attrs` | A match inside a block comment's attribute JSON is a different operation from a match in body text (decision 6) |
| `has_global_layout_wrapper` | This page's write carries the #11 hazard class |
| `locked_modules[]` | Modules whose `attrs.locked` is on (decision 5) |
| `predicted.checksum`, `predicted.byte_delta` | Makes the plan a contract: at apply time the recomputed content must hash to this, or the target is refused |
| `verdict` — `will_apply` / `will_skip:<reason>` / `will_refuse:<code>` | Refusals and skips are shown in the plan, never discovered at apply time |

Run-level: total targets, counts per verdict, total byte delta, total snapshot bytes
about to be written, and `warnings[]`. Anything the operation will decline to do appears
as an explicit entry with a reason. A target that is silently absent from the plan is
the failure mode this table exists to prevent.

`predicted.checksum` deserves emphasis. It converts the dry-run from a description into
an assertion the apply re-checks. If recomputing the replacement at apply time yields
different bytes than the plan promised, that target is refused — regardless of whether
the plan token matched.

### 3. Atomicity — the honest failure model

**WordPress has no cross-post transaction, and this design does not pretend otherwise.
Partial application is the honest default. What is designed is how it is bounded,
reported, and resumed.**

- **The atomic unit is one target.** Each target's write goes through
  `update_post_content_with_integrity_guard()` with readback and auto-revert. That is
  real atomicity and it already exists. `preset_reassign` gave this up to fit a batch
  shape; this design keeps it by making the batch a loop over guarded single writes
  rather than a bespoke unguarded batch write.
- **Default `on_error: "stop"`.** Stop at the first failing target. A stop at target 47
  of 100 leaves 46 applied, 1 attempted-and-reverted, 53 untouched — a state describable
  in one sentence. Continue-on-error leaves a scattered set that is not.
- **`on_error: "continue"` exists but must be passed explicitly.** It is the right
  choice for the "reassign across the site, three pages are locked" shape. It is the
  wrong default because the first failure is usually evidence about the operation, not
  about the page.
- **Every target is snapshotted before its own write** (decision 4), so "partial" always
  means "partially applied and fully recoverable", never "partially applied and
  unknown".

**Reporting.** The response is a run record, not a status:

```jsonc
{
  "run_id": "bulkrun_20260814T…_<hash>",
  "operation": "bulk_find_replace",
  "on_error": "stop",
  "targets": [
    { "id": 123, "status": "applied",       "snapshot_id": "snap_…",
      "before": { "checksum": "sha256:…" }, "after": { "checksum": "sha256:…" } },
    { "id": 456, "status": "skipped",       "reason": "already_applied" },
    { "id": 789, "status": "failed",        "code": "bulk.marker_census_changed",
      "snapshot_id": "snap_…", "reverted": true },
    { "id": 790, "status": "not_attempted", "reason": "run_stopped_at_prior_failure" }
  ],
  "counts": { "applied": 1, "skipped": 1, "failed": 1, "not_attempted": 1 }
}
```

**The envelope's `ok` is `false` whenever any target is `failed`**, with the complete run
record in `error.data`. This is a deliberate divergence from `preset_reassign`, which
returns `ok: true` carrying a nested `success: false`. The harness primer tells every
caller to branch on the envelope; a partial application that satisfies that branch as a
success is a design defect, and repeating it here would multiply it.

**Resume.** The run record names `not_attempted` ids. Resuming is a fresh
`dry_run` over exactly those ids, producing a fresh plan and a fresh token. There is
deliberately **no stored resume cursor**: a persisted cursor goes stale for exactly the
same reason a persisted query does, and re-planning is both cheap and honest.

Resumption is safe because every operation is idempotent on its own output — a
find/replace whose `search` string no longer occurs in a target returns
`skipped: already_applied`, not an error, per the primer's `already_<state>` convention.

### 4. Rollback — per-target snapshots, a run manifest, manual restore

**Decision: reuse `rollback_snapshot_*` unchanged. One snapshot per target, forced on.
Add a thin run manifest that indexes them. Do not build a bulk restore tool.**

- **Snapshots are mandatory, not requested.** `rollback_snapshot_requested()` reads a
  `backup` param defaulting to `false`. Bulk write handlers do not consult it; they
  behave as though `backup: true` were always passed. An operation whose whole risk
  profile is "many pages at once" does not get an off switch for its recovery data.
- **Created immediately before each target's own write**, via the existing
  `rollback_snapshot_create_for_post_write( $post, $tool, $operation )`, with
  `operation` carrying the `run_id`, the operation name, and the target's ordinal. A
  snapshot creation failure (`rollback_snapshot.storage_failed`) aborts the run
  **before** that target is written — the existing behavior, and correct here.
- **The run manifest** is one additional option, `diviops_bulk_run_<run_id>`, holding
  the run parameters, the plan token, and the ordered list of
  `{ target_id, snapshot_id, status, before_checksum, after_checksum }`. It stores **no
  content bytes** — it is an index into the snapshot store, not a second copy of it.
  Same 7-day expiry as the snapshots it points at, so the index never outlives its
  referents. Reachable through a read tool (`bulk_run_get`) and listed by
  `bulk_run_list`.

**Exactly how to restore.** Deliberately manual:

1. `bulk_run_get( run_id )` → the ordered target/snapshot list.
2. For each target with `status: applied`, **in reverse order**, call the existing
   `diviops_rollback_snapshot_restore( snapshot_id )`.
3. Each restore independently verifies that the live target still matches the recorded
   `after` checksum and side-effect meta, and refuses with `conflict` (409) if not.

**There is no `bulk_run_restore` tool in this design, and that is the decision, not an
omission.** A bulk restore that encounters a drifted target has exactly two options and
both are worse than the caller having them: stop, leaving a half-restored run whose
state is now *neither* the before nor the after; or force, which is precisely what
`rollback_snapshot_restore` refuses to do and ships `force.supported: false` to say so.
Handing the caller an ordered list and letting each refusal surface individually is
strictly more informative and strictly less destructive. If a bulk restore is ever
built, it must be a separate issue with its own design, and its hard part is the
drift-mid-restore policy, not the loop.

**The scaling consequence, stated rather than buried.** N snapshots × page size land in
`wp_options`. They are not autoloaded (verified), so ordinary page loads are unaffected.
But `rollback_snapshot_managed_inventory()` caps at 1000 records and fails closed past
it, so enough bulk runs will degrade Pro's managed recovery until the snapshots expire.
The 50-target cap keeps a single run to 5% of that ceiling. A retention/pruning policy
for the snapshot store is a **follow-up issue this design does not solve** and should
not pretend to.

### 5. Which operations are in scope

**In scope, in build order:**

**(1) `content_search` — read-only, site-wide.**
Literal substring search over `post_content` across an allowlisted post-type set,
returning per-post match counts and bounded context windows. Row-level filtering through
`query_inspectable_post_ids()`, gated by `check_read_permission` — the same discipline
every other read surface uses, so a caller only ever sees posts it could edit. Bounded
by the existing 5000-candidate scan ceiling with the `truncated` flag surfaced.
Zero blast radius; delivers the issue's "site-wide content search" outright; and it is
what makes the explicit-id model in (2) and (3) usable rather than tedious.

Implementation note, because getting this wrong would silently produce a *different*
result set than `bulk_find_replace` later operates on: WordPress's `s` query parameter
is **not** a literal substring match. It splits on whitespace, searches title and
excerpt as well as content, and applies relevance ordering. `content_search` must not
use it. Narrow the candidate set with a bounded, prepared `post_content LIKE` (escaped
via `$wpdb->esc_like()`, as `rollback_snapshot_scan_records()` (`trait-rollback.php:469`)
already does for its own bounded options scan), then confirm each hit and count
occurrences in PHP with `strpos`/`substr_count` against the raw content, so search and
replace agree on what a match is by construction.

**(2) `bulk_status_change` — post status only.**
Transitions among `publish` / `draft` / `private` / `pending`. It **never reads or
writes `post_content`**: no parse, no serialize, no round trip, no marker census, no
global-layout exposure. It is the safest possible payload for the write harness, and
it is trivially reversible — the run record captures each target's prior status, so
"undo" is re-running the operation with those statuses. It reuses `page_update_status`'s
own per-post transition logic rather than reimplementing it.

**(3) `bulk_find_replace` — literal string, raw-content splice.**
Deferred behind (1) and (2). Mechanics in decision 6.

**Out of scope, with reasons:**

- **Regex find/replace.** A caller-supplied pattern over serialized block markup is an
  arbitrary-corruption primitive: it can rewrite block-comment delimiters, span block
  boundaries, and mangle attribute JSON, and no amount of post-hoc validation
  meaningfully constrains what it may have already destroyed in the string. Literal
  only. A caller who needs pattern matching runs `content_search`, reads the matches,
  and passes literal replacements — which is also the only form that can be shown
  honestly in a plan.
- **Bulk trash / delete.** `page_trash` exists per page. Bulk deletion has the worst
  ratio of convenience gained to damage possible of anything in this space, and
  WordPress's own admin already does it behind a UI a human is looking at, with its own
  undo.
- **Bulk module-attribute edits** ("set every Button module's border radius"). This is
  the operation that genuinely cannot avoid parse → mutate → serialize on every page,
  i.e. exactly the hazard decision 6 is built to sidestep. It is also what presets are
  for: `preset_create` + `preset_reassign` is the supported path, and improving *that*
  path is better value than building a second, more dangerous one beside it.
- **Bulk operations over Theme Builder layouts** (`et_header_layout`, `et_body_layout`,
  `et_footer_layout`). One TB layout already applies site-wide. A bulk operation across
  TB layouts is a multiplier on a multiplier, and the blast radius is the whole site per
  target rather than per target.
- **Query-as-write-target** in any form (decision 1).
- **Bulk restore** (decision 4).
- **Cross-site / cross-environment bulk.** Pro territory; reference remapping is already
  tracked separately as #96.

**One additional guard, scoped to (3).** Content-mutating bulk operations **skip any
target containing a module with `attrs.locked` on**, reporting
`skipped: module_locked`, overridable only by an explicit `include_locked: true` that is
surfaced in the plan. Verified above: nothing in the plugin currently refuses a write to
a locked module — the lock is VB-side only. That is defensible for single-module tools,
where the caller named one specific module and can be presumed to have meant it. A bulk
operation names no module, so the lock should mean "not without naming me." This
deliberately does **not** change single-module behavior; changing that is a separate
issue if it is wanted at all.

### 6. Interaction with the global-layout and round-trip hazards

**Decision: bulk write operations do not parse. At all.**

- `bulk_status_change` never touches content, so the question does not arise.
- `bulk_find_replace` operates on the raw `post_content` string via a literal splice,
  then writes through `update_post_content_with_integrity_guard( …, $check_global_layout_drift = true )`.

This is the same conclusion `page_duplicate` (#35) reached by a different route: an
operation that does not need a block tree should not build one, which makes the
materialization hazard *structurally impossible rather than merely guarded*.

Passing `$check_global_layout_drift = true` still costs nothing and still helps, because
`global_layout_write_refusal_reason()` is a string scan (verified above) — it will catch
a splice that happened to chew through a wrapper's opening comment, and it fails closed
on unscannable markup.

**A raw splice has its own hazards, which the parse-based path does not.** All of them
are answerable at the string level with helpers that already exist:

1. **A literal match can land inside a block comment's attribute JSON**, not in body
   text. Classify every match by location using the existing block-opener scanners
   (`next_block_opener()` + `block_opening_comment_end()`), report the classification
   per match in the plan, and **default `scope` to body-text matches only**. An
   attribute-JSON replacement requires an explicit `scope: "attrs"` and additionally
   re-validates the affected opener through `find_malformed_block_attr_escape()` —
   the tokenizer-based one, post-#105.
2. **A match can span a block boundary.** Refuse any match whose span contains `<!--`
   or `-->`.
3. **A splice can silently change the block census.** Run `divi_content_marker_counts()`
   before and after and require exact equality — openers, self-closers, container
   openers, closers. This is the precise invariant a bad splice violates, and the helper
   already exists.
4. `assert_divi_full_content_safe_for_write()` runs anyway, inside the integrity guard.

**The round-trip fidelity gate — a precondition on any future parsing bulk operation.**
No operation in this design parses, so this gate is not needed in phases 1–3. It is
specified here because the next person to propose a parsing bulk operation will need it,
and because the absence of it is a live defect in `preset_reassign` today:

> Before mutating a parsed tree, serialize the **unmodified** parse and require it to be
> byte-identical to the original. If `serialize_blocks( parse_blocks_for_write( $c ) ) !== $c`
> (through the same `enrich_blocks_with_empty_object_paths()` /
> `restore_blocks_empty_objects()` pipeline the mutating path uses), refuse that target
> with an explicit code. A page whose round trip is not identity cannot have a
> parse-based edit applied to it safely, because no downstream guard distinguishes the
> intended change from the incidental normalization.

Whether real Divi pages pass that gate is an **open empirical question**, and phase 0
exists to answer it. The one committed data point (page 900390, 312 bytes lost) says at
least one real page does not.

`preset_reassign` round-trips every matched page with no such check, no snapshot, and no
readback. That should be its own issue; it is out of scope here.

### 7. Phasing

Each phase is a separate issue, a separate branch, a separate PR, and a separate owner
review. No phase begins before the prior one has merged and been used against a real
site.

**Phase 0 — measurement. No code ships.**
Answer three questions against the reference site, read-only:
(a) how many posts carry Divi content, and their `post_content` byte-size distribution
— this is what validates or refutes the 50-target cap's snapshot-cost arithmetic;
(b) the byte-identity rate of `serialize_blocks( parse_blocks_for_write( $c ) )` vs `$c`
across those posts, through the enrich/restore pipeline — this either confirms the
round-trip hazard is general or narrows it to particular content shapes;
(c) how many carry a `divi/global-layout` wrapper, and how many carry a locked module.
Gate: results recorded on the issue. Every cap and guard in phases 1–3 is currently
reasoned from first principles plus one data point; phase 0 is what turns them into
measured choices. **If phase 0 shows the round trip is byte-identical across the site,
several decisions here should be revisited rather than kept out of inertia.**

**Phase 1 — `content_search`.** Ships alone. Plugin: one read route, one capability key,
`check_read_permission` + `query_inspectable_post_ids()`. Server: one tool. Tests: match
counting, context windows, row-level filtering (a post the user cannot edit must not
appear), truncation reporting, empty-result shape, and multibyte-safe context windows.
No write harness, no plan, no snapshots — a read tool does not take `dry_run` per the
primer.

**Phase 2 — the write harness + `bulk_status_change`.** Everything genuinely novel and
risky lives here: target resolution and the 50-cap, the plan and the plan token,
staleness and drift detection, the run record and its `ok: false`-on-partial rule, forced
per-target snapshots, the run manifest, `on_error` semantics, and the per-target
`edit_post` re-check. It is built once, against the one payload that cannot corrupt
content. Tests must cover the harness independently of the payload: token mismatch,
stale token, target vanished, target drifted, mid-run failure under both `on_error`
modes, snapshot-creation failure aborting before the write, and the partial-run envelope
being `ok: false`.

**Phase 3 — `bulk_find_replace`.** Only the splice mechanics and the match classifier
are new; the harness is proven. Gate: phase 2 has been used on a real site by the owner,
not merely merged. Tests: match classification (body vs. attrs), the block-boundary
refusal, the marker-census equality check, the locked-module skip, idempotent re-run,
and the global-layout drift refusal on a spliced wrapper.

Between every phase: `php tests/run.php` green, live end-to-end on a scratch page,
never page 900390, and owner sign-off.

## Both sides

#38 says "Both sides." Concretely, each phase touches:

- `plugins/diviops-agent/includes/trait-bulk.php` — a new fork-authored trait, absent
  upstream, so it never conflicts on merge. Wired via `require_once` + `use` in
  `diviops-agent.php` alongside the other `trait-*.php`, matching `trait-media.php` /
  `trait-revision.php` / `trait-dynamic-content.php`.
- `plugins/diviops-agent/diviops-agent.php` — route registration and the matching
  `CAPABILITIES` keys. The capability key is mandatory, not optional: a route with no
  capability key has no MCP tool to attach to, because a failed capability gate removes a
  tool silently rather than reporting a problem.
- `diviops-server/src/index.ts` — one `registerPluginTool` per tool, capability-gated.
- `README.md` and `diviops-server/README.md` — the stated tool counts.
  `tests/test-tool-count-sync.php` derives the real number from the registration call
  sites and fails if either README disagrees, so the counts move in the same PR.
- `tests/test-bulk-*.php` — new coverage. Upstream ships no tests; nothing here inherits
  a safety net.

## Risks

| # | Risk | Severity | Mitigation in this design | Residual |
| - | ---- | -------- | ------------------------- | -------- |
| R1 | A bulk write corrupts many pages at once | Critical | No parsing; marker-census equality; per-target guarded write with readback + auto-revert; mandatory per-target snapshots; hard 50 cap | A splice that is byte-valid but semantically wrong (correct markup, wrong words) is not detectable by any guard — this is why the plan shows every match in context |
| R2 | The applied set differs from the reviewed set | High | Explicit ids only; plan token bound to per-target content checksums; 15-minute staleness; per-target `predicted.checksum` re-checked at apply | A change landing inside the apply loop itself, after that target's checksum was read. Bounded to one target, and that target's own guarded write still reverts on readback mismatch |
| R3 | Partial application read as success | High | Envelope `ok: false` whenever any target failed; full run record in `error.data`; `not_attempted` targets named explicitly | A caller that ignores the envelope entirely. Nothing in this design can fix that |
| R4 | Snapshot bloat degrades Pro's managed recovery | Medium | 50-target cap keeps one run at 5% of the 1000-record fail-closed ceiling; run manifest reports snapshot bytes | Repeated large runs inside the 7-day window still approach it. Retention/pruning is an unsolved follow-up |
| R5 | A restore is impossible because the target drifted | Medium | Snapshots are per-target and checksum-bound; reverse-order manual restore surfaces each refusal individually | A target edited after the run genuinely cannot be auto-restored, by design. The refusal is the correct outcome |
| R6 | The 50 cap and the guards are calibrated from one data point | Medium | Phase 0 measures before phase 2 is built | If phase 0 is skipped, every number here is a guess wearing a justification |
| R7 | The harness is built and then used for the operations we excluded | Medium | The exclusions are written down here with reasons, and each new operation is its own issue | Nothing structural prevents a future session from adding `bulk_delete` to a working harness. That is what this section is for |
| R8 | `preset_reassign` remains unguarded beside a carefully guarded new surface | Medium | Named explicitly; flagged for its own issue | Not fixed by this work |
| R9 | Rate limiting (30 writes/min) throttles a bulk run | Low | A bulk run is one REST request covering N targets, not N requests — this is a reason to batch server-side rather than loop client-side | A run of 50 guarded writes inside one request may approach `max_execution_time` on constrained hosting; phase 0's size distribution informs whether 50 is still right |

## What we deliberately will NOT build

Restating in one place, because a list of non-goals that is only implied gets built
anyway:

1. **Regex find/replace.** Literal only, permanently.
2. **Bulk trash, delete, or permanent removal of anything.**
3. **Bulk module-attribute editing.** Use presets.
4. **Bulk operations over Theme Builder layouts.**
5. **Query-as-write-target.** Discovery and mutation stay separate tools; ids are values,
   not predicates.
6. **`bulk_run_restore`.** Restore is per-snapshot, reverse order, manual, using the
   existing tool.
7. **A stored resume cursor.** Resume by re-planning the `not_attempted` ids.
8. **A `backup: false` escape hatch on bulk writes.** Snapshots are forced.
9. **Silent truncation of an oversized target list.** Over the cap is a refusal.
10. **A parse-based bulk write of any kind**, unless and until the round-trip fidelity
    gate in decision 6 exists and phase 0's measurement supports it.
11. **Any change to single-module write behavior** — including the `attrs.locked` guard,
    which is scoped to bulk operations only.
12. **Cross-site / cross-environment bulk.** Pro territory; #96 owns remapping.

## Honest assessment

Operation 1 (`content_search`) should be built. It is read-only, it is the piece of #38
with the clearest value, and it costs nothing in risk.

Operation 2 (`bulk_status_change`) is worth building, mostly because it is the only
honest way to prove the write harness before anything dangerous rides on it. Its
standalone value is modest — changing the status of a handful of pages is not the
bottleneck in anyone's day.

Operation 3 (`bulk_find_replace`) is the one to be skeptical about. The value is real
but narrow — a phone number, a company name, a URL, changed across a dozen pages. The
risk is that every guard in decision 6 is a *syntactic* guard: they can prove the markup
is still well-formed, that the block census is unchanged, that no wrapper was detached.
None of them can prove the replacement was the right replacement in all twelve places.
That judgment lives entirely in a human reading the plan, which is why the plan shows
every match in context — and it is also why the 50-target cap matters more than it
looks: a plan nobody reads makes every other guard here decorative.

For this repository's actual user — one maintainer, a handful of client sites — the
recommendation is to ship phases 0–2, live with them, and only then decide whether
phase 3 earns its risk. It may not. Deciding that later, with real usage data, is a
better outcome than deciding it now in either direction.

## Open questions for owner review

1. Is the 50-target cap right, or should phase 0 set it? (Recommendation: 50 as the
   ceiling regardless of what phase 0 shows; phase 0 may lower it, not raise it.)
2. Is `on_error: "stop"` the right default, given `preset_reassign` ships
   continue-on-error today? (Recommendation: yes — `preset_reassign`'s shape is a
   consequence of having no snapshots, not a considered choice.)
3. Should phase 3 be authorized at all, now or ever? (Recommendation: decide after
   phase 2 has been used.)
4. Does `bulk_find_replace` need `post` in the post-type allowlist, or is `page` enough
   for the real use case?
5. Should `preset_reassign`'s five defects get their own issue now, or wait? They are
   pre-existing and unrelated to this work, but the new surface will make the contrast
   conspicuous.

## FORK.md

This spec is documentation only and adds no divergence row. `FORK.md`'s tables record
changes to files that originated upstream and fork-owned files that ship behavior;
`docs/superpowers/specs/` is neither, and no existing spec in that directory has a row.
When an implementation phase lands it will need rows — a new `trait-bulk.php` entry in
the fork-owned table, and additions to the existing `diviops-agent.php` and
`diviops-server/*` rows.
