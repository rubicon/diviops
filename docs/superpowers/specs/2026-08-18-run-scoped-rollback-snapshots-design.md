# Run-Scoped Rollback Snapshots — Design

**Issue:** #199
**Status:** design, approved to plan (owner, 2026-08-18)
**Supersedes on one point:** `2026-08-14-bulk-site-wide-operations-design.md` (#38), which
recorded "there is no multi-target snapshot shape" as a fixed constraint. This design
creates one. The #38 batch cap of 25 is **not** changed — see "Relationship to #38".

---

## The problem, stated correctly

`preset_reassign` takes one rollback snapshot per applied page. The snapshot store is
capped at 500 rows (`rollback_snapshot_retention_limit()`), enforced after every write by
`rollback_snapshot_enforce_retention()`, which deletes oldest-first.
`REASSIGN_MAX_PAGES` is 1000.

So an apply of 501–1000 pages **keeps only its last 500 snapshots**. The first pages of
the run are unrecoverable, and the run reports success for every one of them, because the
snapshots genuinely were created. The caller has no signal.

### What is NOT the problem

An earlier analysis on #199 claimed the trigger was `stored + planned > limit` — that a
300-page apply against a store holding 400 snapshots evicts 200 of its own. **That is
false, and a fix built on it was closed unmerged (PR #242).**

Eviction selects `ORDER BY option_id DESC` and deletes `array_slice($rows, $limit)` — the
oldest tail. A run's own snapshots are the newest rows written, so they sit at the head of
that list and survive as long as the run itself creates no more than the limit. The
retention code says so in its own comment: *"the snapshot just created — always first —
can never evict itself."* `tests/test-rollback-retention.php` already asserts it.

Simulation driving the real eviction function:

    stored=400  planned=300  | OWN surviving=300/300 | pre-existing surviving=200/400
    stored=400  planned=500  | OWN surviving=500/500 | pre-existing surviving=0/400
    stored=400  planned=501  | OWN surviving=500/501 | pre-existing surviving=0/400
    stored=0    planned=1000 | OWN surviving=500/1000| pre-existing surviving=0/0
    stored=499  planned=1    | OWN surviving=1/1     | pre-existing surviving=499/499

`stored` is irrelevant to a run's own recoverability. Only `planned > limit` matters.
What a large run evicts is *other tools' older* snapshots, which is #194 behaving as
designed.

## Why not simply bound the run

Three bounding fixes were considered and rejected. Each trades away capability to fit a
storage shape that is itself the problem.

**Refuse an apply over 500 pages.** Closes the window, costs the ability to reassign a
preset across a large site in one call. Also over-strict: the per-page loop skips any page
whose content does not mention `old_uuid` *before* snapshotting, so a 900-page apply that
would only snapshot 12 is refused anyway.

**Lower `REASSIGN_MAX_PAGES` to 500.** Same loss, plus it caps dry-run scanning, which
takes no snapshots at all. A full-site audit on a larger site would silently truncate —
losing a capability that has nothing to do with snapshots.

**Stop the run at 500 snapshots and report the rest as skipped.** Exact, but introduces a
partial-apply outcome and still refuses to finish work the caller asked for.

All three answer "how do we live within 500 slots." The real question is why one run
consumes 1000 slots.

## The design

**A bulk run stores one snapshot record per chunk of pages, not one per page.**

A 1000-page apply costs 10 rows at a chunk bound of 100, instead of 1000. The retention
cap is untouched and keeps doing its job; the run simply stops being 1000 rows wide.

### What this wins

- **No refusal.** A 1000-page apply runs.
- **No truncation.** `REASSIGN_MAX_PAGES` stays 1000; dry-run audits stay full-site.
- **No partial apply.**
- **Strictly more recoverable than today.** Today a 1000-page apply leaves 500 pages with
  no restore path. Under this design all 1000 are restorable.
- **Run-level restore**, which is what you actually want after a bad reassign, instead of
  replaying up to 1000 individual restores.
- **Per-page restore is retained** — see below. Batching is a storage decision, not a
  granularity decision.
- **Relieves the ceiling pressure #38 identified as dominant**, without raising any cap.

### What it costs

- A second record schema and a version-aware read path.
- A restore path that walks entries and applies the integrity guard per page.
- Coarser *deletion* granularity: the unit that can be deleted is a chunk, not a page.
- Real storage in a single row — addressed by the chunk bound below.

---

## Decisions

### D1. Per-page restore is preserved

The record holds a list of per-page entries — id, `before` checksum/bytes/value, captured
side effects, and its own `after` checksum. Restore accepts a run id alone ("undo the
whole reassign") or a run id plus specific page ids ("undo these six").

Rationale: a bad reassign usually goes wrong on a subset — a few pages where the preset
resolved differently than expected — and those must be revertible without discarding the
pages that came out right. Batching storage must not cost the caller that choice. Today's
per-page snapshots already allow it, so anything less would be a regression.

### D2. Chunk bound is 100 pages per record

A run writes `ceil(count(pages) / 100)` records.

Rationale: `before.value` is a page's full prior `post_content`. A thousand of them in one
option row could reach tens of megabytes, and `get_option()` loads the row whole — a
memory cliff on constrained hosting, and a `max_allowed_packet` risk on write. 100 keeps a
worst-case record in the low single-digit megabytes while still reducing a 1000-page run
from 1000 rows to 10 — a 100× reduction against the retention cap, which is the entire
point. Records are written with `add_option( …, '', 'no' )`, so they are never autoloaded.

The bound is a named constant, not a literal, so it can be lowered without hunting call
sites. It may be lowered on evidence; it may not be raised without re-testing the memory
and packet ceilings.

### D3. `schema_version` 2, and v2 records are invisible to the legacy seam

Run records carry `schema_version => 2` and a `targets` array. They deliberately do **not**
carry a positive scalar `target.id`.

`rollback_snapshot_normalize_record()` returns `null` today for any record whose
`target.id` is not positive. That is the compatibility mechanism, and it is load-bearing:

- `rollback_snapshot_managed_inventory()` is the PHP service seam **DiviOps Agent Pro's
  managed recovery reads**. Pro is closed-source, separately shipped, and cannot be
  updated or tested from this repository.
- Normalizing to `null` is **necessary but not sufficient**, and the first implementation
  of this design got that wrong. Most readers treat a null normalization as "skip"
  (`rollback_snapshot_scan_records()` does), but `rollback_snapshot_managed_inventory()`
  — the one seam that matters here — does not: it emits a synthetic
  `{ malformed: true, viability_reasons: [ 'record_identity_invalid' ] }` record instead.
  Left alone, a single 1000-page reassign would have shown Pro ten records it reads as
  store corruption, and those records would also have been undeletable through
  `rollback_snapshot_managed_delete_exact()`, which refuses a malformed record.
- So `managed_inventory()` carries an **explicit `continue` for run records**, placed
  before normalization. With that guard, Pro's view is what it was before v2 existed.
- The test asserts the **property** — that the inventory excludes a seeded v2 record and
  reports zero malformed rows — and not merely the mechanism. Asserting the mechanism
  alone is exactly what let the defect through: `normalize_record()` returned `null` as
  designed while the guarantee it stood proxy for was false.
- Pro loses nothing: it cannot recover a >500-page run's early snapshots today either,
  because they do not exist.

**This is a hard constraint, not a preference.** No change in this work may make a v2
record normalize successfully through the v1 path. New capability is served by additive,
v2-aware code paths.

### D4. Single-page writes keep per-page snapshots

The other twelve snapshotting call sites (`page_update_content`, `module_update`,
`section_*`, `tb_layout_*`, and the rest) are unchanged. A single-page write costs one row
either way, and a run record for one page would be strictly worse: a new shape, invisible
to Pro, for no gain.

Run records are for bulk runs only. `preset_reassign` is the only current caller.

### D5. Every entry is marked, or it is unrestorable

`rollback_snapshot_restore()` refuses outright unless `after.checksum` is non-empty, and
only `rollback_snapshot_mark_post_write()` sets it. A snapshot created but never marked is
permanently unrestorable — a live footgun the #38 spec found the hard way.

So each per-page entry carries its own `after` block, written after that page's guarded
write completes, and the v2 restore path refuses per entry on the same terms the v1 path
refuses per record. An entry that was created but never marked reports as unrestorable
rather than silently doing nothing.

### D6. Drift refusal stays per page

v1 restore refuses with `conflict` (409) when the live target has drifted from the
recorded `after` checksum or side-effect meta, and ships no force override. v2 keeps that
per entry: a drifted page is refused and named; its siblings still restore. A partial
restore reports which entries applied, which were refused, and why.

Rationale: the alternative — refusing the whole run because one page drifted — makes the
common recovery case unusable, since any page edited after the reassign would block it.

### D7. Retention counts run records like any other row

No exemption. A run record is one row against the 500-row cap, evicted oldest-first like
anything else. #194's bound is preserved exactly.

This is what makes the design honest: the earlier attempt (PR #242) tried to protect a run
by reasoning about the budget, which both misread eviction and would have refused every
apply forever once the store saturated. Making the run cheap removes the tension instead of
negotiating with it.

---

## Relationship to #38

`2026-08-14-bulk-site-wide-operations-design.md` recorded two things this design touches.

**"There is no multi-target snapshot shape"** — a statement of fact at the time, and this
design creates one. That spec should be amended to point here.

**The batch cap of 25 is unchanged.** One of its two justifications was that snapshot cost
is cumulative and permanent against the 1000-record inventory ceiling; run-scoped records
relieve that leg by roughly the batch size. But the cap's other legs are untouched and
still decisive: per-target request cost is dominated by `invalidate_divi_cache()`'s second
`wp_update_post` and the `save_post` handlers it re-fires; the run writes a manifest per
target; rate limiting counts targets rather than requests; and 25 is "a plan a human can
actually read." Nothing here argues for raising it.

**The atomicity argument does not conflict.** #38 keeps per-target atomicity by making a
batch a loop over guarded single writes. That is unchanged — the integrity guard is a
property of each write, not of how snapshots are stored. This design changes storage
shape, not write shape.

---

## Out of scope

- Automatic expiry. Snapshots are still never deleted on age; `expires_at` remains a label,
  not a lifecycle. Adding cron-based cleanup is its own decision with its own blast radius.
- Bulk delete of snapshots. Deletion remains one id per call, now with chunk granularity
  for run records. Worth filing separately; it is the reason a saturated store is painful
  to clear.
- Changing `REASSIGN_MAX_PAGES`, the retention limit, or the inventory ceiling.
- Migrating existing v1 records. They stay v1 and keep working; there is nothing to
  migrate.
- Applying run records to the other twelve call sites (D4).

## Risks

**Pro compatibility.** Mitigated by D3. The test must assert the **property** — seed a v2
record, call `rollback_snapshot_managed_inventory()`, and confirm it is absent and that no
row is reported malformed — and not only the mechanism that a v2 record normalizes to
`null`. The mechanism-only version of this assertion passed against an implementation
where the guarantee was false, which is why the distinction is written into the spec
rather than left to reviewer judgement. Any new reader added later must be checked
against this property; a null normalization does not automatically mean "skipped".

**Record size.** Mitigated by D2. The plan must include an explicit test at the chunk
bound with realistic `post_content` sizes, not a token fixture — a size test that passes on
100-byte pages proves nothing.

**Partial-restore semantics are new.** D6 introduces an outcome that did not exist:
"some entries restored, some refused." The response shape must make that unambiguous, and
the skill docs and MCP tool description must describe it, or an agent will treat a partial
restore as a success.

**Live verification gap.** The PHP harness cannot drive `preset_reassign` end to end —
the registry probe goes through `et_get_option()`, which is unshimmed. Restore of a real
multi-page run has to be verified against a real Divi install before this is trusted.
