# Bulk / site-wide content operations (issue #38) — design spec

Date: 2026-08-14
Issue: [#38](https://github.com/rubicon/diviops/issues/38)
Status: design — pending owner review. **No implementation authorized by this document.**

## Revision history

- 2026-08-14, initial design. Authored against the code at `origin/main` `53922e8`.
- 2026-08-14, revised after an independent adversarial design review (design only, no
  code). Thirty-two findings; every accepted one was re-verified against the source
  before it changed anything here. Eight were blocking, and they materially changed the
  design:
  1. **The plan token was unkeyed**, therefore forgeable by any caller who can read this
     open-source repo, so it did not provide the "cannot apply without a plan" property
     claimed for it. Now an HMAC over `wp_salt()`, in a two-part `<ts>.<mac>` form —
     the original put the timestamp *inside* the hash, which the server could not have
     recomputed.
  2. **The token bound only content checksums**, making it inert for
     `bulk_status_change` — the one payload phase 2 uses to prove the harness. Now binds
     status and `post_modified_gmt` too.
  3. **The risk table claimed the guarded write would revert a concurrent edit.** It
     would not, for exactly the reason this spec's own headline fact states. Replaced
     with an explicit per-target loop contract.
  4. **Defaulting `bulk_find_replace` to body-text matches found nothing**: in Divi 5,
     module text lives inside the block comment's attribute JSON. The "safe default" was
     the one nobody could use, so every real run would have taken the weakest-guarded
     path — where a replacement containing `"` or `\` emits invalid JSON that every
     listed guard passes. Decision 6 is rewritten around decoding exactly one opener's
     attrs rather than an absolutist "never parse".
  5. **`bulk_status_change` could escalate privilege**: the publish/private capability
     check lives in a single-target route permission callback, not in the handler.
     Now re-checked per target inside the loop.
  6. **Every snapshot the design created would have been unrestorable** —
     `rollback_snapshot_restore()` refuses without an `after.checksum`, which only
     `rollback_snapshot_mark_post_write()` sets, and the design never called it. Added,
     along with the request-dies-mid-run case it exposed.
  7. **Snapshots never expire** — nothing in the plugin schedules cron — and the managed
     inventory's truncation drops the *newest* records. Both change the cost model, and
     the cap dropped from 50 to 25.
  8. **`invalidate_divi_cache()` was absent from the design entirely**, which both left
     N pages serving stale CSS and understated the per-target request cost.
  Two recommendations reversed on the reviewer's argument: `on_error` now defaults to
  `continue`, and phase 0 gained falsifiable exit criteria instead of "record the
  results." Full disposition in the PR.

## Summary of the recommendation

Ship the read half of #38 and the one write that cannot corrupt content. Hold the
content-mutating half behind a proven harness, and refuse several operations
permanently.

| # | Operation | Verdict |
| - | --------- | ------- |
| 1 | `content_search` — site-wide literal search over `post_content`, read-only | **Build first, ships alone** |
| 2 | `bulk_status_change` — post status only, never touches content | **Build second, on a new write harness** |
| 3 | `bulk_find_replace` — literal, one-opener attrs decode, never a block tree | **Build third, gated on 1+2 having lived on a real site** |
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

## Verified facts (each traceable to a command run while writing or reviewing this spec)

Cited before anything is built on them. Read the code, not this list, before
implementing.

### The existing safety machinery, and exactly what each piece does and does not cover

- **`update_post_content_with_integrity_guard()`** (`trait-core.php:585`, comparison at
  `:633`) writes `post_content`, reads it straight back, and reverts to
  `$previous_content` if the stored bytes differ from the requested bytes. It compares
  **stored vs. requested**. It does **not** compare requested vs. original. A write that
  faithfully persists content the caller never meant to produce passes this guard
  cleanly. This is the single most important fact in this document, and everything in
  decisions 3 and 6 follows from it.
- **`assert_divi_full_content_safe_for_write()`** (`trait-core.php:547`) checks
  opener/closer marker balance and marker sequence only.
- **`divi_content_marker_counts()`** (`:693`) counts `<!--\s+wp:` openers, self-closers,
  container openers, and `<!--\s+/wp:` closers by regex. Nothing about attribute
  content.
- **`find_malformed_block_attr_escape()`** (`:881`) is a **pseudo-escape detector, not a
  JSON validator**. It walks string segments, strips `$variable()$` tokens, and matches
  bare `u00(3c|3e|26|22|5c|2d)`. It never calls `json_decode`.
- **`global_layout_write_refusal_reason()`** (`:383`) compares the *multiset of
  `globalModule` ids* between two content strings and returns `identity_lost`,
  `scan_unreliable`, or `null`. It is **a raw-string scan, not a parse** — it walks
  openers via `next_block_opener()` / `block_opening_comment_end()` and takes
  `string $content` on both sides. It fails closed: an unresolvable opener anywhere in
  the document, or an undecodable `divi/global-layout` opener, refuses the write.
  Undecodable JSON in any *other* block's opener is invisible to it.
- **`parse_blocks_for_write()`** (`:259`) routes a write-path parse through Divi's
  `BlockParserUtils::parse_blocks_with_layout_context( $content, 'saving_content' )`
  when that class exists, else plain `parse_blocks()`.
- **`block_opening_comment_end()`** (`trait-page.php`) is the JSON-string-aware scan that
  finds a block opener's true comment terminator without being fooled by a `-->` inside
  an attribute value (#5/#6).
- **`serialize_block_attrs_canonical()`** (`trait-core.php:847`) re-encodes a block's
  attrs to the canonical serialized form.
- **`rollback_snapshot_*`** (`trait-rollback.php`) stores **one `wp_option` row per
  snapshot**, each holding the target's full prior `post_content` (`before.value`),
  written with `add_option( …, '', 'no' )` — not autoloaded (`:207`). The record's
  `target` is a **single post** (`:137-143`). There is no multi-target snapshot shape.
- **Snapshots are never automatically deleted.** `rollback_snapshot_expiry_seconds()`
  (`:84`) stamps an `expires_at` string and `rollback_snapshot_normalize_record()`
  computes an `expired` boolean, but `grep -rn "wp_schedule_event\|wp_cron\|wp_next_scheduled"
  plugins/diviops-agent/` returns **nothing**. Deletion is manual
  (`rollback_snapshot_delete`) or Pro's business. "7-day expiry" is a label, not a
  lifecycle.
- **`rollback_snapshot_restore()`** (`:880`) restores **one** snapshot, refuses with
  `conflict` (409) if the live target drifted from the recorded `after` checksum or
  side-effect meta, and ships **no force override** (`:924`, `force.supported: false` at
  `:1020`). It also refuses outright unless `after.checksum` is non-empty (`:910`) —
  and the only thing that sets it is `rollback_snapshot_mark_post_write()` (`:243`).
  **A snapshot that is created but never marked is permanently unrestorable.**
- **`rollback_snapshot_managed_inventory()`** (`:513`) — the PHP service seam Pro's
  managed recovery reads — fetches at most 1001 rows `ORDER BY option_name ASC`
  (`:528`) and returns the first 1000 plus a `truncated` flag. It does not itself
  return an error on truncation; its comment (`:525`) says the flag exists "so
  orchestration can fail closed", and that orchestration lives in Pro, which this fork
  does not own. Two consequences: the fail-closed behavior is **Pro's, not the
  plugin's**; and because option names embed `gmdate('YmdHis')` (`:110`), ASC ordering
  means the records dropped past 1000 are the **most recent** ones — precisely the run
  you just performed and most need to recover.
- **`module_lock` / `module_unlock`** (`trait-page.php:4017`, `:4117`) set and remove
  `attrs.locked = { desktop: { value: "on" } }`. The docblock at `:4012` states the lock
  gates **VB-side editing only**. A full-plugin grep for `locked` finds only those two
  handlers, the read-side summary at `:2988`, and an unrelated
  `customizer_locked_color_ids`: **no REST or MCP write path refuses a locked module.**
- **The publish capability gate is not in the handler.** `page_update_status`'s
  publish/private check lives in the route permission callback
  `page_update_status_permission_result()` (`diviops-agent.php:586-645`), which resolves
  `$post_type->cap->publish_posts` for the single `$request['id']` and refuses with
  `rest_cannot_publish`. A single route-level callback structurally cannot express this
  for a batch spanning mixed post types.
- **`invalidate_divi_cache()`** (`trait-core.php:1806`) is called by every content-write
  path in the plugin — 22 call sites, including `preset_reassign` (`trait-preset.php:2354`)
  and `page_update_status` (`trait-page.php:4653`). It sweeps
  `wp-content/et-cache/<id>/` with `glob()` + `wp_delete_file()`, then runs a **second
  `wp_update_post`** to touch `post_modified` and force Divi's style regeneration, then
  clears two Divi caches.
- **Rate limiting** (`diviops-agent.php:313`) is per-user, per-minute, bucketed
  read/write, defaulting to **30 writes/min** (`:304`), configurable by constant, env
  var, or the `diviops_rate_limits` filter. It counts **REST requests, not affected
  posts**.
- **`query_inspectable_post_ids()`** (`trait-core.php:1977`) is the canonical row-level
  read filter, wrapping `WP_Query` with `perm => 'editable'`, scanning at most 5000
  candidates and reporting `truncated`. `WP_Query` has no `post_content LIKE` argument.
- **`dry_run_response()`** (`:2386`) emits `plan.warnings` only when non-empty.
- **`REASSIGN_MAX_PAGES = 1000`** and **`VARIABLES_SCAN_MAX_POSTS = 2000`**
  (`diviops-agent.php:172-173`) are the existing site-wide scan caps.
- **The plugin has no multisite awareness** (`grep -rn "is_multisite\|switch_to_blog"
  plugins/` → no hits). Snapshot options, the manifest, and rate-limit transients are
  all per-blog, so bulk is per-site by construction.

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

- **It creates no rollback snapshot at all.** No `rollback_snapshot_*` reference appears
  anywhere in the function. A 200-page apply is unrecoverable except through WordPress
  revisions, which `wp_update_post` may or may not have created per page.
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
- **Omitting `page_ids` targets every `publish`/`draft`/`private` page and post on the
  site** (`:2097-2108`), and the set is resolved fresh at apply time, so the apply can
  touch pages the dry-run never showed.

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
   the tree.** `FORK.md:111` records that duplicating page 900390 through
   `parse_blocks_for_write()` → `serialize_blocks()` produced **62,167 → 61,855 bytes** —
   312 bytes lost on a page that parses and renders fine — and that `page_duplicate`
   (#35) was consequently rewritten as a byte copy so the hazard became "structurally
   impossible rather than merely guarded". That number is quoted from the committed
   divergence record; it was **not re-measured while writing this spec** (the reference
   site is read-only for this work).

The reason no existing guard catches (2) is the fact stated at the top of this section:
`update_post_content_with_integrity_guard()` verifies that WordPress stored what we
asked for. A silently normalized re-serialization *is* what we asked for. Marker
balance is preserved. Global-layout identity is preserved. The write is clean by every
check the plugin has, and 312 bytes are gone.

On one page, a human looks at the result. Across 200 pages, nobody does. **This is why
no bulk operation in this design ever builds a block tree.**

### Baseline

`php tests/run.php` at `origin/main` `53922e8`: **PASS 1122 assertion(s) in 34
file(s)**. (The task brief cited 1109/33; that baseline predates a test added since.)

## The seven decisions

### 1. Blast-radius containment — what bounds a single operation

**Decision: an explicit id list, and only an explicit id list, for every write. Hard
cap of 25 targets per apply call. Never a query.**

A query as a write target is re-evaluated at apply time. Between the dry-run the caller
read and the apply the caller authorized, a post can be published, edited, restored
from trash, or have its status changed — by a VB session, by a scheduled publish, by a
second MCP session. The set that applies is then not the set that was reviewed.
`preset_reassign` has exactly this shape today, and it is the least safe thing in the
repository.

Discovery and mutation are therefore split into two tools. `content_search` answers
"which posts match"; the caller passes the resulting ids back into the write. The extra
round trip is the feature: it forces the target set to become a value the caller has
seen, rather than a predicate the server re-evaluates.

**The cap is 25.** The first draft said 50, computed from snapshot bytes and a
readback-only cost model. Both legs were wrong in the same direction:

- **Snapshot cost is cumulative and permanent, not per-run.** Nothing deletes expired
  snapshots (verified: no cron anywhere in the plugin), so the 1000-record ceiling on
  `rollback_snapshot_managed_inventory()` is approached monotonically across every tool
  that ever passed `backup: true`, not just within a 7-day window. And past the ceiling,
  ASC-by-option-name ordering drops the **newest** records — the run you most need. A
  "5% of the ceiling per run" framing was answering the wrong question.
- **The per-target request cost is roughly double what was computed.** Each target needs
  the guarded write *and* `invalidate_divi_cache()`, which is itself a second
  `wp_update_post` plus a filesystem sweep. That second write re-fires `save_post` — SEO
  plugins, caches, indexers — which on a real client site is the dominant cost, not the
  readback. The run also writes the manifest per target (decision 4).

25 targets is a plan a human can actually read, a workload that finishes inside a
normal PHP request on constrained hosting, and half the snapshot pressure. Phase 0 may
lower it further; it may not raise it.

Over the cap returns `bulk.too_many_targets` (400) with `error.data = { received,
max_targets }`, mirroring `preset.too_many_pages`'s shape. Never truncate silently, and
never truncate-with-a-flag: `preset_reassign`'s `truncated` flag means the apply covered
a different set than the caller believes. An oversized bulk request is a refusal.

Additional bounds, all cheap:

- Allowed post types are an explicit allowlist (`page`, `post`), excluding Theme Builder
  layout types by construction (see decision 5).
- **Rate limiting counts targets, not requests.** Today's 30 writes/min is a de-facto
  blast-radius ceiling: an agent that goes wrong damages at most 30 pages a minute. One
  batch request covering 25 posts would silently raise that ceiling 25×. Bulk write
  routes therefore consume `count( targets )` from the write bucket rather than 1, and
  refuse the whole run rather than applying partially when the bucket cannot cover it.
  This is a small, deliberate addition to `check_rate_limit`'s contract and it must be
  in the same PR as the first bulk write route.
- Per-target permission is re-checked **inside the loop**, immediately before that
  target's write — see decision 3's loop contract.

### 2. Dry-run — mandatory, and bound to the apply

**Decision: mandatory, and bound to the apply by a keyed plan token.**

"Dry-run defaults to true" is not mandatory dry-run. `preset_reassign` defaults to
`mode=dry-run`, and a caller can still pass `mode=apply` on the very first call, having
seen nothing.

The contract:

- `dry_run: true` returns the standard harness plan shape (`data.plan = { summary,
  changes[], warnings[] }` per the `diviops` primer) **plus** a `plan_token`. Bulk plans
  always emit `warnings`, even empty — `dry_run_response()` omits the key when empty, so
  the bulk handlers add it explicitly rather than leaving callers to branch on its
  absence.
- `dry_run: false` (or omitted) **without** a `plan_token` is `invalid_input`, not an
  implicit plan. There is exactly one way to preview and exactly one way to apply.
- The token is **`<issued_at>.<mac>`**, where `mac = hash_hmac( 'sha256', $canonical,
  wp_salt( 'diviops_bulk' ) )` and `$canonical` covers: the operation name, `issued_at`,
  the ordered target id list, **each target's `post_content` checksum, `post_status`,
  and `post_modified_gmt`**, the normalized operation parameters, and the acting user
  id.

Three things about that shape are deliberate and each was wrong in the first draft:

- **Keyed, not a bare hash.** A plain SHA-256 over inputs the caller already holds is
  computable by any caller who reads this repo — which includes the LLM callers the gate
  exists for. An unkeyed token would have reduced "you cannot apply without a plan" to
  "you must have read each target's content." `wp_salt()` is site-local and never leaves
  the server.
- **`issued_at` in the clear, covered by the MAC.** The first draft put the timestamp
  inside the hash and then required the server to recompute the token and enforce a
  15-minute staleness window — impossible, since the server cannot know or invert the
  timestamp. Staleness is checked against the plaintext half; forgery is prevented by
  the MAC over both halves. Tokens older than **15 minutes** are rejected, matching
  `rollback_snapshot_created_stale_seconds()`'s existing horizon.
- **Status and `post_modified_gmt`, not just the content checksum.** A content-only
  binding is *inert* for `bulk_status_change`, whose whole mutation is the status —
  a target whose status drifted between plan and apply would produce a matching token,
  and the same token would stay replayable for its full 15 minutes. Phase 2 exists to
  prove the harness; a harness whose central drift detector cannot fire on phase 2's
  payload proves nothing.

At apply, the handler recomputes the MAC from live state and compares with
`hash_equals`. A mismatch returns `bulk.plan_stale` (409) whose `error.data` names
exactly which targets drifted and on which field. **A target that no longer exists at
apply time surfaces through this same mechanism** — no checksum is obtainable, so the
token cannot match — and is reported as `bulk.plan_stale`, not as a second refusal path.
One condition, one code.

**What the plan must show for a caller to judge it.** Per target:

| Field | Why it must be there |
| ----- | ------------------- |
| `id`, `post_type`, `title`, `status`, permalink | The caller has to recognize the page |
| `content.checksum`, `content.byte_length`, `post_modified_gmt` | Identity of what is about to be modified, and the drift binding |
| `uses_divi` | A non-Divi post in a Divi bulk op is a signal, not a detail |
| `matches[]` — for each match: byte offset, a bounded context window either side, the exact replacement, and the **decoded** value where the match is in attrs | The only way to judge a find/replace is to see the actual matches as a human would read them on the page |
| `match_location` per match — `body` or `block_attrs`, with the owning block name | These are two different operations (decision 6) |
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
the token matched.

The `will_refuse` vocabulary must include `bulk.global_layout_scan_unreliable` with a
message that says what it means: the page's block markup could not be scanned reliably,
which happens on pages with malformed comments **and no global layout at all** (the #23
case). A caller who sees a global-layout error code on a page that has no global layout
will conclude the tool is broken.

### 3. Atomicity — the honest failure model

**WordPress has no cross-post transaction, and this design does not pretend otherwise.
Partial application is the honest default. What is designed is how it is bounded,
reported, and resumed.**

**The per-target loop contract.** This is the most important implementation constraint in
the document, and the first draft omitted it:

For each target, in order:

1. **Re-`get_post()` the target inside the loop.** Never reuse the post object loaded at
   plan time.
2. **Re-verify** that its `post_content` checksum, `post_status`, and
   `post_modified_gmt` still match what the plan recorded. On mismatch, refuse **this
   target** with `bulk.target_drifted` and do not write it.
3. **Re-check permission** for this target: `current_user_can( 'edit_post', $id )`, and
   — for status changes — `page_status_requires_publish_capability( $status )` plus the
   target post type's own `publish_posts` capability. The route-level permission callback
   cannot cover a mixed-type batch (verified: `diviops-agent.php:586-645` resolves the
   capability for a single `$request['id']`), so omitting this per-target re-check is a
   privilege-escalation path, not a nicety.
4. **Recompute** the intended new content and require it to hash to `predicted.checksum`.
5. **Snapshot**, from the post object loaded in step 1 — never a stale one. A snapshot
   built from plan-time content would record bytes the page never had at write time, so
   restoring it would destroy a concurrent edit rather than recover from the bulk write.
6. **Write** through `update_post_content_with_integrity_guard()`.
7. **Mark the snapshot** (decision 4) and **invalidate the Divi cache** for that post.
8. **Record the outcome in the run manifest** before moving to the next target.

Steps 1–4 exist because the guarded write cannot help here. It compares stored against
requested; a bulk write that overwrites a concurrent edit with stale content is stored
exactly as requested, matches on readback, and reverts nothing. **The only defense
against a concurrent edit is the pre-write re-verification, not the guard.**

**Concurrency.** Two bulk runs with overlapping targets, or a bulk run racing a Visual
Builder save, are not prevented by the token — it is checked once at the top of the run.
The loop contract narrows the window to a single target's steps 2–6, which is the
smallest it can be made without a lock. On top of that, each target takes a short-lived
transient lock (`diviops_bulk_lock_<post_id>`, a few seconds, released after step 8);
a target whose lock is already held is refused as `bulk.target_locked` rather than
queued. This does not serialize against the Visual Builder, which takes no such lock —
that residual is real and stated in the risk table rather than papered over.

**Error policy.**

- **Default `on_error: "continue"`.** The first draft defaulted to `stop`, reasoning that
  a first failure is usually evidence about the operation. Review showed that reasoning
  does not survive this design's own guard list: every refusal a bulk run can produce is
  page-specific (marker-census inequality, global-layout drift or unreliable scan,
  `module_locked`, target drift, per-target permission). Operation-wide faults — bad
  parameters, bad token, oversized batch — are all caught before the first write. So the
  modal first failure *is* about one page, and stopping converts a recoverable "23
  applied, 2 refused with reasons" into "11 applied, 14 unattempted, site now
  inconsistent." For find/replace especially, a half-changed site is worse than a
  fully-attempted one with named refusals. Snapshots are what make `continue` safe;
  `preset_reassign` continues *without* them, which is a different thing entirely.
- **`on_error: "stop"` remains available** for callers who would rather inspect the first
  failure before touching anything else.
- **Per target it is genuinely atomic.** Each write goes through
  `update_post_content_with_integrity_guard()` with readback and auto-revert. That is
  real atomicity and it already exists. `preset_reassign` gave it up to fit a batch
  shape; this design keeps it by making the batch a loop over guarded single writes.

**Reporting.** The response is a run record, not a status:

```jsonc
{
  "run_id": "bulkrun_20260814T…_<hash>",
  "operation": "bulk_find_replace",
  "on_error": "continue",
  "targets": [
    { "id": 123, "status": "applied",  "snapshot_id": "snap_…",
      "before": { "checksum": "sha256:…" }, "after": { "checksum": "sha256:…" } },
    { "id": 456, "status": "skipped",  "reason": "already_applied" },
    { "id": 789, "status": "failed",   "code": "bulk.attrs_reencode_invalid",
      "snapshot_id": "snap_…", "reverted": true },
    { "id": 790, "status": "failed",   "code": "bulk.target_drifted" }
  ],
  "counts": { "applied": 1, "skipped": 1, "failed": 2, "not_attempted": 0 }
}
```

**The envelope's `ok` is `false` whenever any target is `failed`**, with the complete run
record in `error.data`. This is a deliberate divergence from `preset_reassign`, which
returns `ok: true` carrying a nested `success: false`. The harness primer tells every
caller to branch on the envelope; a partial application that satisfies that branch as a
success is a design defect, and repeating it here would multiply it.

**Resume.** The run record names `not_attempted` ids (populated under `on_error: "stop"`,
or by a request that died mid-run). Resuming is a fresh `dry_run` over exactly those
ids, producing a fresh plan and a fresh token. There is deliberately **no stored resume
cursor**: a persisted cursor goes stale for the same reason a persisted query does, and
re-planning is both cheap and honest.

Resumption is safe because every operation is idempotent on its own output — a
find/replace whose `search` string no longer occurs in a target returns
`skipped: already_applied`, not an error, per the primer's `already_<state>` convention.

**If the PHP request dies mid-run** (fatal, `max_execution_time`, connection reset), the
manifest written incrementally in step 8 is the record of how far it got. Targets with
no manifest entry were not attempted. A target whose snapshot exists but was never
marked (step 7 never ran) is left at `status: created`, `after: null` — which
`rollback_snapshot_restore()` refuses as not-restorable. `bulk_run_get` must report such
targets as `indeterminate` and say plainly that the snapshot is unrestorable by the
shipped tool and the page must be inspected by hand. This is an ugly outcome; it is
also the true one, and a design that does not name it will be discovered to have it.

### 4. Rollback — per-target snapshots, a run manifest, manual restore

**Decision: reuse `rollback_snapshot_*` unchanged. One snapshot per target, forced on,
created *and marked*. Add a thin run manifest that indexes them. Do not build a bulk
restore tool.**

- **Snapshots are mandatory, not requested.** `rollback_snapshot_requested()` reads a
  `backup` param defaulting to `false`. Bulk write handlers do not consult it; they
  behave as though `backup: true` were always passed. An operation whose whole risk
  profile is "many pages at once" does not get an off switch for its recovery data.
- **Created *and marked*.** `rollback_snapshot_create_for_post_write( $post, $tool,
  $operation )` before the write (from the freshly loaded post object, per the loop
  contract), then **`rollback_snapshot_mark_post_write( $record, 'applied',
  $after_content )` after it**. The mark is not optional bookkeeping:
  `rollback_snapshot_restore()` refuses any snapshot whose `after.checksum` is empty
  (`trait-rollback.php:910`), so an unmarked snapshot is permanently unrestorable. The
  first draft of this spec never called the mark, which would have made every snapshot
  it created useless — the single worst defect the review found. On a write error,
  `rollback_snapshot_mark_from_write_error()` is the existing correct path.
  `operation` carries the `run_id`, the operation name, and the target's ordinal.
- A snapshot creation failure (`rollback_snapshot.storage_failed`) refuses that target
  **before** it is written.
- **The run manifest** is one option, `diviops_bulk_run_<run_id>`, holding the run
  parameters, a truncated token fingerprint (for correlation only — **not the token
  itself**, which is a MAC and does not belong in a readable option), and the ordered
  list of `{ target_id, snapshot_id, status, before_checksum, after_checksum }`. It
  stores **no content bytes**. It is written **before the first write** and updated after
  each target, so an interrupted run is legible. Reachable through `bulk_run_get`,
  listed by `bulk_run_list`, deleted by `bulk_run_delete`.

**Exactly how to restore.** Deliberately manual:

1. `bulk_run_get( run_id )` → the ordered target/snapshot list.
2. For each target with `status: applied`, call the existing
   `diviops_rollback_snapshot_restore( snapshot_id )`. Order does not matter: snapshots
   here are one-per-target across distinct posts and are independent.
3. Each restore independently verifies that the live target still matches the recorded
   `after` checksum and side-effect meta, and refuses with `conflict` (409) if not.

**There is no `bulk_run_restore` tool in this design.** The honest version of this
argument is narrower than the first draft's. A bulk restore does *not* avoid a
half-restored state — 25 sequential restores are 25 independent writes and can be
interrupted exactly as the manual sequence can. What the manual path actually buys is
that each refusal reaches the caller as its own decision point rather than being
consumed by a loop's error policy, and that no new stop-or-force policy has to be
invented for a case (`rollback_snapshot_restore` refusing mid-run) whose correct
handling is genuinely unclear. If a bulk restore is ever built, its hard part is that
policy, not the loop, and it needs its own issue.

Two consequences that are not solved here and should not be pretended away:

- **A cross-chunk recovery story does not exist.** The named use case — a company name
  across a site — routinely exceeds 25 targets, so the normal case is several chunks,
  several manifests, and no index across them. The caller holds that map.
- **The snapshot store has no retention.** Nothing expires snapshots, the manifest sits
  outside `rollback_snapshot_option_prefix()` so Pro's inventory and cleanup cannot see
  it either, and the managed inventory's 1000-record truncation hides the newest
  records. Bulk runs accelerate a pre-existing, unbounded accumulation. **Snapshot and
  manifest retention is a follow-up issue this design does not solve**, and the 25-cap is
  mitigation, not a fix.

### 5. Which operations are in scope

**In scope, in build order:**

**(1) `content_search` — read-only, site-wide.**
Literal substring search over `post_content` across an allowlisted post-type set,
returning per-post match counts and bounded context windows.

One mechanism, not two — the first draft specified both `query_inspectable_post_ids()`
and a raw `LIKE`, which are incompatible (`WP_Query` has no `post_content LIKE`
argument). The mechanism is: a bounded, prepared `$wpdb` query selecting post ids where
`post_content LIKE` the `$wpdb->esc_like()`-escaped needle, restricted to the post-type
and status allowlist, `LIMIT` one row beyond a fixed ceiling; then row-level filtering of
those ids through the same `edit_post` discipline
`filter_inspectable_post_objects()` applies; then per-post confirmation and occurrence
counting in PHP with `strpos`/`substr_count` against the raw content. `truncated` means
"more posts matched the LIKE than the ceiling returned" and must be documented as that,
not as "more posts were scanned."

Gated by `check_read_permission`. Zero blast radius; delivers the issue's "site-wide
content search" outright; and it is what makes the explicit-id model usable rather than
tedious.

**Escaping matters even here.** Divi stores some content escaped in attribute JSON
(`<`, `&`, `\/`). A literal search over raw `post_content` will miss text a
human reads on the page, and will report counts that diverge from what
`bulk_find_replace` later changes. `content_search` must therefore search **both** the
raw bytes and, for matches classified as attribute content, the decoded value — and
report which form matched. Search and replace agreeing on what a match is has to be
built, not assumed.

**(2) `bulk_status_change` — post status only.**
Transitions among `publish` / `draft` / `private` / `pending`. It **never reads or
writes `post_content`**: no parse, no serialize, no round trip, no marker census, no
global-layout exposure. It is the safest possible payload for the *content* risk the
harness exists to contain, and it reuses `page_update_status`'s own per-post transition
logic rather than reimplementing it — with the publish-capability check moved into the
per-target loop (decision 3, step 3).

**It is not, however, "trivially reversible", and the first draft was wrong to say so.**
`publish` fires `transition_post_status` / `publish_post`: pingbacks, feeds, and whatever
notification, newsletter, or social-publishing plugin a client site runs. Publishing 25
drafts sends 25 outbound events that setting the status back does not recall.
`page_update_status` also mutates `post_date`/`post_date_gmt` on some transitions
(`trait-page.php:4606-4611`), which re-running does not restore. The plan must warn
explicitly on any transition into `publish`, and the run record must capture prior
`post_date`/`post_date_gmt` alongside prior status so a manual repair is at least
possible.

**(3) `bulk_find_replace` — literal, one-opener attrs decode.**
Deferred behind (1) and (2). Mechanics in decision 6.

**Out of scope, with reasons:**

- **Regex find/replace.** A caller-supplied pattern over serialized block markup is an
  arbitrary-corruption primitive: it can rewrite block-comment delimiters, span block
  boundaries, and mangle attribute JSON, and no post-hoc validation meaningfully
  constrains what it may already have destroyed. Literal only. A caller who needs pattern
  matching runs `content_search`, reads the matches, and passes literal replacements —
  which is also the only form that can be shown honestly in a plan.
- **Bulk trash / delete.** `page_trash` exists per page. Bulk deletion has the worst
  ratio of convenience gained to damage possible of anything in this space, and
  WordPress's own admin already does it behind a UI a human is looking at, with its own
  undo.
- **Bulk module-attribute edits** ("set every Button module's border radius"). This is
  the operation that genuinely requires a whole block tree, i.e. exactly the hazard
  decision 6 exists to avoid. It is also what presets are for: `preset_create` +
  `preset_reassign` is the supported path, and improving *that* path is better value
  than building a second, more dangerous one beside it.
- **Bulk operations over Theme Builder layouts** (`et_header_layout`, `et_body_layout`,
  `et_footer_layout`). One TB layout already applies site-wide. A bulk operation across
  TB layouts is a multiplier on a multiplier.
- **Query-as-write-target** in any form (decision 1).
- **Bulk restore** (decision 4).
- **Cross-site / cross-environment bulk.** Pro territory; reference remapping is already
  tracked separately as #96.

**One additional guard, scoped to (3).** Content-mutating bulk operations **skip any
target containing a module with `attrs.locked` on**, reporting
`skipped: module_locked`, overridable only by an explicit `include_locked: true` that is
surfaced in the plan. Verified: nothing in the plugin currently refuses a write to a
locked module — the lock is VB-side only. That is defensible for single-module tools,
where the caller named one specific module and can be presumed to have meant it. A bulk
operation names no module, so the lock should mean "not without naming me." Detection
uses the same per-opener attrs decode as decision 6 — **not** a `strpos` for
`"locked"`, which is wrong in both directions (it matches a `locked` key nested under an
unrelated attribute, and misses key-order and whitespace variants). This deliberately
does **not** change single-module behavior.

### 6. Interaction with the global-layout and round-trip hazards

**Decision: never build a block tree. Decode exactly one block opener's attribute JSON,
and only when the operation targets that opener.**

The first draft said "do not parse. At all." That absolutism was wrong, and it was wrong
in a way that produced a corruption path:

- In Divi 5, **module text lives inside the block comment's attribute JSON**, not in body
  text — `<!-- wp:divi/text {"content":{"desktop":{"value":"…"}}} /-->` (the shape used
  throughout `tests/test-find-block-comment-span.php` and
  `tests/test-find-all-sections-self-closing.php`). The three use cases this operation
  exists for — a phone number, a company name, a URL — are all attribute matches. A
  design defaulting to body-text-only matches would find nothing, so every real run would
  have passed `scope: "attrs"`, i.e. the path with the weakest guards.
- And that path, guarded only by a raw splice plus the checks the first draft listed,
  corrupts. Replacing `Acme Co` with `Tom's "Best" Deals` inside an attribute value emits
  `"value":"Tom's "Best" Deals"` — invalid JSON. Every listed guard passes:
  `divi_content_marker_counts()` counts comment markers, not quotes;
  `assert_divi_full_content_safe_for_write()` checks marker balance only;
  `find_malformed_block_attr_escape()` is a pseudo-escape detector that never calls
  `json_decode`; `global_layout_wrapper_identities()` only decodes
  `divi/global-layout` openers. The readback matches, because WordPress stored exactly
  what was requested. Divi then parses the block with empty attrs and the module's
  content is gone — across up to 25 pages, silently. That is R1 realized by *syntactic*
  corruption, which the first draft's risk table claimed was covered.

The corrected primitive, and the distinction that matters:

> **Never build a block tree.** The #11 materialization hazard and the lossy round trip
> both come from `parse_blocks*()` → `serialize_blocks()` over a whole document. Decoding
> one opener's attribute JSON, editing a decoded string value, and re-encoding that one
> opener touches nothing else in the document and cannot materialize a
> `divi/global-layout` wrapper.

So:

- **`bulk_status_change`** never touches content; the question does not arise.
- **`bulk_find_replace`** classifies every match by location using the existing opener
  scanners (`next_block_opener()` + `block_opening_comment_end()`, both JSON-string-aware
  per #5/#6), and then:
  - **Body-text matches** are a literal splice of the raw string.
  - **Attribute matches** are `json_decode`d from that opener's attrs span, replaced on
    the decoded string value, re-encoded through the existing
    `serialize_block_attrs_canonical()`, and the result required to `json_decode` cleanly
    before it is spliced back over that opener's span. A re-encode that does not decode
    cleanly refuses the target with `bulk.attrs_reencode_invalid`.
  - Default `scope` is **both**, because restricting to body text is a default that
    cannot do the job.

Guards that still apply, all of them existing helpers:

1. Refuse any body-text match whose span contains `<!--` or `-->`.
2. `divi_content_marker_counts()` equality before and after — openers, self-closers,
   container openers, closers. This is the invariant a bad splice violates.
3. `assert_divi_full_content_safe_for_write()`, which runs inside the integrity guard.
4. `find_malformed_block_attr_escape()` on every opener the write touched — as a
   pseudo-escape check, which is what it is, *in addition to* the `json_decode`
   validation, not instead of it.
5. `update_post_content_with_integrity_guard( …, $check_global_layout_drift = true )`.
   `global_layout_write_refusal_reason()` is a string scan, so this costs nothing and
   catches a splice that chewed a wrapper's opening comment.

Point 5 needs one honest note. That parameter's docblock (`trait-core.php:575-583`)
states it is opt-in for round-trip sites specifically, because raw-content callers like
`page_update_content` may legitimately drop a wrapper on purpose. A literal find/replace
never legitimately does, so passing `true` is correct on the merits — but it changes a
documented invariant, and **the docblock must be updated in the same PR**, not left
contradicting the code. It also means any target with malformed markup *anywhere*,
wrapper or not, refuses with `scan_unreliable`; decision 2 requires that refusal to be
worded so a caller does not conclude the tool is broken.

**The round-trip fidelity gate — a precondition on any future whole-tree operation.**
Nothing in this design builds a block tree, so this gate is not needed in phases 1–3. It
is specified here because the next person to propose one will need it, and because its
absence is a live defect in `preset_reassign` today:

> Before mutating a parsed tree, serialize the **unmodified** parse and require it to be
> byte-identical to the original. If `serialize_blocks( parse_blocks_for_write( $c ) ) !== $c`
> (through the same `enrich_blocks_with_empty_object_paths()` /
> `restore_blocks_empty_objects()` pipeline the mutating path uses), refuse that target.
> A page whose round trip is not identity cannot have a tree-based edit applied to it
> safely, because no downstream guard distinguishes the intended change from the
> incidental normalization.

Whether real Divi pages pass that gate is an **open empirical question** and phase 0
exists to answer it. The one committed data point (page 900390, 312 bytes) says at least
one real page does not.

`preset_reassign` round-trips every matched page with no such check, no snapshot, and no
readback. That should be its own issue; it is out of scope here.

### 7. Phasing

Each phase is a separate issue, a separate branch, a separate PR, and a separate owner
review. No phase begins before the prior one has merged and been used against a real
site.

**Phase 0 — measurement. No code ships.** Read-only, against the reference site.

| Measure | Falsifiable exit criterion |
| ------- | -------------------------- |
| (a) Count of Divi posts and the `post_content` byte-size distribution | If p95 size × 25 exceeds 2 MB of snapshot per run, the cap drops until it does not |
| (b) Byte-identity rate of `serialize_blocks( parse_blocks_for_write( $c ) )` vs `$c` across those posts, through the enrich/restore pipeline | **If identity is anything less than 100%, the whole-tree path stays permanently closed** and the round-trip fidelity gate becomes mandatory for any future proposal. If it is 100%, decisions 6 and 5's exclusion of bulk attribute edits should be *revisited*, not kept out of inertia |
| (c) Existing `diviops_rollback_snapshot_*` option count on a real site | If it is already above ~500, retention must be solved *before* phase 2 ships, not after — bulk runs would otherwise push a live site past the point where its newest snapshots stop being visible to recovery |
| (d) Count of posts carrying a `divi/global-layout` wrapper, a locked module, or malformed markup that trips `scan_unreliable` | Sets the expected refusal rate. If `scan_unreliable` is common, phase 3 needs a markup-repair story first |

This table is the gate. A phase-0 that reports what it measured and derives no
pass/fail from it is the exact anti-pattern `CLAUDE.md` names — a gate that passes while
inspecting nothing — and the first draft of this spec had precisely that shape.

**Phase 1 — `content_search`.** Ships alone. Plugin: one read route, one capability key,
`check_read_permission`. Server: one tool. Tests: match counting, raw-vs-decoded match
reporting, context windows, row-level filtering (a post the user cannot edit must not
appear), truncation semantics, empty-result shape, multibyte-safe context windows. No
write harness, no plan, no snapshots — a read tool does not take `dry_run` per the
primer.

**Phase 2 — the write harness + `bulk_status_change`.** Everything genuinely novel lives
here: target resolution and the 25-cap, the keyed plan token and its three bindings, the
per-target loop contract, the transient lock, the run record and its
`ok: false`-on-partial rule, forced create-*and-mark* snapshots, the incremental run
manifest, `on_error` semantics, target-counting rate limiting, and the per-target
publish-capability re-check. Tests must exercise the harness independently of the
payload: forged token rejected, stale token rejected, replayed token rejected,
**status drift detected** (the binding that makes phase 2 prove anything), target
vanished, target drifted mid-run, snapshot-creation failure refusing before the write,
snapshot marked so it is actually restorable, an unmarked snapshot reported as
`indeterminate`, both `on_error` modes, and the partial-run envelope being `ok: false`.

**Phase 3 — `bulk_find_replace`.** New: match classification, the one-opener attrs
decode/re-encode, and the splice. The harness is proven. Gate: phase 2 has been used on
a real site by the owner, not merely merged. Tests: body-vs-attrs classification, a
replacement containing `"` and `\` round-tripping through decode/re-encode and being
rejected if it does not, the block-boundary refusal, marker-census equality, the
locked-module skip via decode (not `strpos`), idempotent re-run, and the global-layout
drift refusal on a spliced wrapper.

Between every phase: `php tests/run.php` green, live end-to-end on a scratch page,
never page 900390, and owner sign-off.

## Both sides

#38 says "Both sides." Concretely, each phase touches:

- `plugins/diviops-agent/includes/trait-bulk.php` — a new fork-authored trait, absent
  upstream, so it never conflicts on merge. Wired via `require_once` + `use` in
  `diviops-agent.php` alongside the other `trait-*.php`, matching `trait-media.php` /
  `trait-revision.php` / `trait-dynamic-content.php`.
- `plugins/diviops-agent/diviops-agent.php` — route registration, the matching
  `CAPABILITIES` keys, and (phase 2) the target-counting change to `check_rate_limit`.
  The capability key is mandatory, not optional: a route with no capability key has no
  MCP tool to attach to, because a failed capability gate removes a tool silently rather
  than reporting a problem.
- `plugins/diviops-agent/includes/trait-core.php` — phase 3 updates
  `update_post_content_with_integrity_guard()`'s `$check_global_layout_drift` docblock,
  which currently states only round-trip sites pass `true`.
- `diviops-server/src/index.ts` — one `registerPluginTool` per tool, capability-gated.
- `README.md` and `diviops-server/README.md` — the stated tool counts.
  `tests/test-tool-count-sync.php` derives the real number from the registration call
  sites and fails if either README disagrees, so the counts move in the same PR.
- `tests/test-bulk-*.php` — new coverage. Upstream ships no tests; nothing here inherits
  a safety net.

## Risks

| # | Risk | Severity | Mitigation in this design | Residual |
| - | ---- | -------- | ------------------------- | -------- |
| R1 | A bulk write corrupts many pages at once | Critical | No block tree; attribute edits go through decode → replace → re-encode → re-validate rather than a raw splice; marker-census equality; per-target guarded write with readback + auto-revert; mandatory create-and-mark snapshots; hard 25 cap | A replacement that is syntactically valid but semantically wrong (correct JSON, wrong words) is undetectable by any guard. This is why the plan shows every match in its decoded form, and why the cap is a number a human will actually read |
| R2 | The applied set differs from the reviewed set | High | Explicit ids only; keyed token binding content checksum + status + `post_modified_gmt` per target; 15-minute staleness; **per-target re-verification inside the loop immediately before its write**; `predicted.checksum` re-check | A change landing inside a single target's own steps 2–6. Narrowed by the per-target transient lock, but the Visual Builder takes no such lock, so a VB save can still land in that window. The guarded write does **not** catch this — it compares stored against requested |
| R3 | Partial application read as success | High | Envelope `ok: false` whenever any target failed; full run record in `error.data`; `not_attempted` named explicitly | A caller that ignores the envelope entirely |
| R4 | Snapshot accumulation degrades recovery | Medium-High | 25-target cap; manifest carries snapshot byte totals; phase 0 (c) measures the live baseline and can block phase 2 | **Unmitigated and monotonic.** Nothing expires snapshots (no cron in the plugin), the manifest sits outside the prefix Pro's cleanup scans, and past 1000 records the managed inventory hides the *newest* snapshots. Retention is an unsolved follow-up issue |
| R5 | A restore is impossible because the target drifted | Medium | Snapshots are per-target and checksum-bound; each refusal surfaces individually | A target edited after the run genuinely cannot be auto-restored, by design. The refusal is correct |
| R5b | A snapshot exists but is unrestorable | Medium | Snapshots are marked immediately after the write; `bulk_run_get` reports unmarked ones as `indeterminate` | A request that dies between write and mark leaves a real, unrestorable snapshot and a page needing manual inspection |
| R6 | Publishing side effects cannot be undone | Medium | The plan warns explicitly on any transition into `publish`; the run record captures prior status **and** prior `post_date`/`post_date_gmt` | Outbound notifications, pingbacks, and feed entries fired by 25 publishes are not recallable by any means this design has |
| R7 | Rate limiting stops being a blast-radius ceiling | Medium | Bulk routes consume `count( targets )` from the write bucket, not 1 | An operator who raises `DIVIOPS_RATE_LIMIT_WRITE` for unrelated reasons widens it again |
| R8 | The caps and guards are calibrated from few data points | Medium | Phase 0 has falsifiable exit criteria that can block phase 2 or close the whole-tree path permanently | If phase 0 is skipped, every number here is a guess wearing a justification |
| R9 | The harness gets used for the operations we excluded | Medium | The exclusions are written down here with reasons; each new operation is its own issue | Nothing structural prevents a future session adding `bulk_delete` to a working harness. That is what this section is for |
| R10 | `preset_reassign` remains unguarded beside a carefully guarded new surface | Medium | Named explicitly; flagged for its own issue | Not fixed by this work |
| R11 | WordPress revisions multiply | Low-Medium | Named as a decision below rather than left implicit | Each target produces a revision for the content write and another for `invalidate_divi_cache()`'s own `wp_update_post`, interacting with `WP_POST_REVISIONS`. The design neither relies on revisions as a recovery path nor suppresses them; phase 0 should record the real per-run row cost |
| R12 | Two bulk runs, or a bulk run and a VB save, interleave | Medium | Per-target transient lock; per-target pre-write re-verification | The lock is advisory and the Visual Builder does not take it. Full serialization is out of scope |

## What we deliberately will NOT build

Restating in one place, because a list of non-goals that is only implied gets built
anyway:

1. **Regex find/replace.** Literal only, permanently.
2. **Bulk trash, delete, or permanent removal of anything.**
3. **Bulk module-attribute editing over a whole block tree.** Use presets.
4. **Bulk operations over Theme Builder layouts.**
5. **Query-as-write-target.** Discovery and mutation stay separate tools; ids are values,
   not predicates.
6. **`bulk_run_restore`.** Restore is per-snapshot, manual, using the existing tool.
7. **A stored resume cursor.** Resume by re-planning the `not_attempted` ids.
8. **A `backup: false` escape hatch on bulk writes.** Snapshots are forced.
9. **Silent truncation of an oversized target list.** Over the cap is a refusal.
10. **Any operation that builds a whole block tree**, unless and until the round-trip
    fidelity gate exists and phase 0 (b) supports it.
11. **A raw splice into attribute JSON.** Attribute edits decode, replace, re-encode, and
    re-validate, or they refuse.
12. **Any change to single-module write behavior** — including the `attrs.locked` guard,
    which is scoped to bulk operations only.
13. **Cross-site / cross-environment bulk.** Pro territory; #96 owns remapping.
14. **A retention or pruning policy for the snapshot store.** Needed, named, and
    deliberately a separate issue — solving it inside a bulk-operations PR would bury a
    site-wide data-lifecycle decision inside a feature.

## Honest assessment

Operation 1 (`content_search`) should be built. It is read-only, it is the piece of #38
with the clearest value, and it costs nothing in risk.

Operation 2 (`bulk_status_change`) is worth building, mostly because it is the only
honest way to prove the write harness before anything dangerous rides on it. Its
standalone value is modest, and the review corrected an overstatement worth repeating:
it is the safest payload for *content*, and among the least reversible for *side
effects*.

Operation 3 (`bulk_find_replace`) is the one to be skeptical about, and the review
sharpened why. The first draft believed a raw splice was safer than parsing; it is not,
because the content that actually needs replacing lives in JSON, and a splice into JSON
produces corruption that every existing guard passes. The corrected design — decode one
opener, replace, re-encode, re-validate — is genuinely safe against *syntactic*
corruption. It is still no help at all against the real failure: the replacement being
wrong. That judgment lives entirely in a human reading the plan, which is why the plan
shows every match in decoded form, and why the 25-target cap matters more than it looks.
A plan nobody reads makes every other guard here decorative.

For this repository's actual user — one maintainer, a handful of client sites — the
recommendation is to ship phases 0–2, live with them, and only then decide whether
phase 3 earns its risk. It may not. Deciding that later, with real usage data, is a
better outcome than deciding it now in either direction.

## Open questions for owner review

1. Is 25 the right cap, or should phase 0 (a) set it outright? (Recommendation: 25 as the
   ceiling regardless; phase 0 may lower it, not raise it.)
2. `on_error` now defaults to `continue`, reversed from the first draft on review
   argument: every refusal this design produces is page-specific, and snapshots are what
   make continuing safe. Does that match how you would actually want a half-failing run
   to behave?
3. Should phase 3 be authorized at all, now or ever? (Recommendation: decide after
   phase 2 has been used on a real site.)
4. Does `bulk_find_replace` need `post` in the post-type allowlist, or is `page` enough?
5. Snapshot retention (R4) is unmitigated and pre-existing — bulk runs accelerate it
   rather than cause it. Should it become a blocking prerequisite for phase 2, or a
   parallel issue?
6. Should `preset_reassign`'s five defects get their own issue now, or wait? They are
   pre-existing and unrelated to this work, but the new surface will make the contrast
   conspicuous.

## FORK.md

This spec is documentation only and adds no divergence row. `FORK.md`'s tables record
changes to files that originated upstream and fork-owned files that ship behavior;
`docs/superpowers/specs/` is neither, and no `FORK.md` row is keyed on a `docs/` path.
When an implementation phase lands it will need rows — a new `trait-bulk.php` entry in
the fork-owned table, and additions to the existing `diviops-agent.php`,
`trait-core.php`, and `diviops-server` rows.
