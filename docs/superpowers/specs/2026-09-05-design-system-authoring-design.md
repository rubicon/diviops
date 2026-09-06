# Design-system authoring: style guide to live Divi tokens

Design for [#392](https://github.com/rubicon/diviops/issues/392). Written 2026-09-05, after
its two blockers landed: [#380](https://github.com/rubicon/diviops/pull/400) (the palette
writer now merges instead of replacing) and
[#381](https://github.com/rubicon/diviops/pull/404) (global token writes now invalidate the
compiled CSS site-wide).

## Purpose

Turn a style guide, brand guideline, or design-token table into a live Divi 5 design system —
global colours, fonts and variables — through the fork's own validated REST write path.

Today every authoring tool takes one concrete token at a time and assumes the caller already
knows its value. Nothing ingests "here are the brand colours and the type scale", which is the
step a site build actually starts with.

## Measured ground truth

Everything below was measured on `staging.colleyvillelions.com` (Divi 5.12.0) on 2026-09-05,
not inferred. These numbers drive the design.

| Fact | Value |
| --- | --- |
| Global colours | 99 — **52 flat hex, 47 `$variable(...)` references** |
| Colours missing `order` | 0 |
| Colour ids | all `gcid-*`, zero `gvid-*` |
| Non-colour buckets | `numbers` 143, `images` 18, `gradients` 3 |
| Colour statuses | 57 `active`, 42 `inactive`, 0 `archived` |

**A derived colour, exactly as stored:**

```
$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{"lightness":7}}})$
```

Observed `settings` keys: `lightness`, `opacity` (and `saturation`, per the ADR). Values are
integers.

**A flat colour, exactly as stored:**

```json
{"color":"#333232","folder":"","label":"black","lastUpdated":"2026-09-02T23:46:10.000Z",
 "status":"active","usedInPosts":[],"id":"gcid-775khiq0hu","order":"13"}
```

The single most consequential number is **47 of 99**. Nearly half the live palette is a
*relationship*, not a literal. A generator emitting only flat hex would be unusable here, and
re-importing over such a palette would silently flatten every one of those relationships.

## Architecture

**Hybrid, decided 2026-09-05.** Two halves with one contract between them.

```
style guide (prose / markdown / CSV / JSON)
        │
        ▼   skill: divi-5-builder  ── parsing, in the model
   token set (strict JSON)
        │
        ▼   NEW: diviops_design_system_apply  ── applying, in PHP
   live Divi tokens
```

**The skill parses.** Reading free-form brand guidelines is model work, and PHP is the wrong
place for an unbounded parser — especially one sitting in a write path.

**The tool applies.** One call, one plan, one dry-run, one cache invalidation, deterministic
ids, and explicit overwrite/skip accounting.

The reason to split here rather than let the skill make N calls to the existing single-token
tools is that it makes the **never-invent-a-token rule testable**. As a prompt instruction it
is unenforceable and untestable. As a boundary contract it is neither: the tool refuses a
token that carries no value, so a skill that hallucinates one produces a loud error instead of
a quietly wrong palette. That is the whole reason the boundary sits where it does.

A skill-only design was rejected for three further reasons: it is not atomic, so a failure
halfway leaves a half-built palette; there is no dry-run across the whole set, only per token;
and it is N round trips.

### Precedent to copy, not reinvent

`variable_create_fluid_system()` already solves most of the mechanics and its shape is adopted
wholesale:

- a computed `$plan` built before anything is written
- an `overwrite` flag, with `skipped[]` entries carrying a `reason`
- `created[]` / `skipped[]` plus `*_count` fields
- `dry_run` emitting `before`/`after` per change
- `order` preserved on overwrite, freshly assigned on create
- `validate_name_prefix()` for the namespace

That last one carries a rule this design inherits verbatim, and its existing comment states
why: `sanitize_key()` silently rewriting `"oa!"` or `"o a"` to `"oa"` would alias bogus input
onto the default namespace, and **under `overwrite=true` that means silently rewriting the
wrong token set**. Slug derivation therefore **rejects explicitly** rather than sanitising.

## The token-set contract

The skill emits this; the tool accepts only this. One shape, strictly validated.

```json
{
  "namespace": "acme",
  "colors": [
    { "name": "primary",     "value": "#1B4D8F", "label": "Primary" },
    { "name": "primary-600", "derived_from": "primary",
      "settings": { "lightness": -10 }, "label": "Primary 600" },
    { "name": "muted",       "derived_from": "primary",
      "settings": { "saturation": -30, "opacity": 60 } }
  ],
  "fonts": [
    { "name": "body", "family": "Inter", "source": "google", "label": "Body" }
  ],
  "variables": [
    { "name": "radius-md", "type": "numbers", "value": "8px" }
  ]
}
```

Rules:

- Exactly one of `value` or `derived_from` per colour. Both, or neither, is `invalid_input`.
- `settings` is only legal alongside `derived_from`, and only accepts `lightness`,
  `saturation`, `opacity`, each an integer. An unrecognised key is refused rather than dropped
  — a dropped setting is a silently different colour.
- `derived_from` must resolve to another token **in the same payload** or to an id already on
  the site. An unresolvable reference is `invalid_input`, never a flattened literal.
- `status` follows the per-surface vocabulary [#393](https://github.com/rubicon/diviops/pull/407)
  established: colours `active|inactive|temporary`, fonts `active|inactive`. Omitted means
  `active` on create and **the stored value** on update.
- No token is invented. A colour with no `value` and no `derived_from` is refused. This is the
  contract the DoD test asserts.

## Deterministic ids and idempotency

`id = "gcid-" + namespace + "-" + slug(name)` for colours, `gfid-`/`gvid-` for the other
surfaces.

- `slug()` accepts `[a-z0-9-]` only, derived from `name`, and **rejects** anything that would
  require rewriting — matching `validate_name_prefix()`'s reasoning above.
- Re-running the same style guide produces the same ids, so an updated guide **updates** rather
  than duplicating. That is the second DoD test.
- `overwrite=false` (default) skips an existing id and reports it in `skipped[]` with
  `reason: "exists"`. `overwrite=true` updates in place, preserving `order` and every key the
  writer does not own — which is exactly what [#380](https://github.com/rubicon/diviops/pull/400)
  made safe. Bulk authoring over the pre-#380 writer would have wrecked a palette rather than
  damaging one entry, which is why that blocker was real.
- A **renamed** token yields a new id and leaves the old one in place. That is correct — a
  rename is a new token, not a mutation — and `variable_scan_orphans` already exists to find
  what the rename stranded. The tool reports the orphan candidates rather than deleting
  anything.

## Derived colours

The tool builds the reference in Divi's own grammar, measured above, rather than resolving to
a literal:

```
$variable({"type":"color","value":{"name":"<target gcid>","settings":{...}}})$
```

Two ordering consequences, both handled in the plan phase before any write:

1. **Topological ordering.** A derived colour must be written after its target so the reference
   resolves. The plan sorts by dependency.
2. **Cycle detection.** `a → b → a` is refused as `invalid_input` naming the cycle. Divi will
   not resolve it, and writing it produces a palette that renders wrong with no error.

## Cache invalidation

One site-wide invalidation for the whole apply, not one per token — reusing
`invalidate_divi_cache_sitewide()` from [#381](https://github.com/rubicon/diviops/pull/404).
A per-token call would mean N full-site CSS sweeps for one authoring run, which is the
pathological shape [#403](https://github.com/rubicon/diviops/issues/403) records for presets.
Skipped entirely on `dry_run` and on a run that wrote nothing.

## Error semantics

Standard envelope. Every refusal is `invalid_input` with `error.data` naming the offending
token by `name` **and** by index, so a caller applying 60 tokens can find the one that failed —
the same reasoning behind `colors_index` in the colour writer.

Validation is **whole-payload and up front**: nothing is written until every token validates.
A partial apply is worse than a refusal here, because the caller cannot tell which half landed.

## Testing

Following the characterization convention in `CONTRIBUTING.md`, asserting **storage** rather
than the response — the class of bug this repository keeps paying for is a response that says
success over a store that says something else.

The two Definition-of-Done tests from the issue:

1. **Invention.** A token with neither `value` nor `derived_from` is refused, and nothing is
   written. Fails if the generator invents a value.
2. **Idempotency.** Applying the same payload twice produces one entry, not two, with `order`
   preserved across the second run.

Plus: the derived-colour grammar matches the measured literal byte for byte; a dependency cycle
is refused; a topologically out-of-order payload still writes correctly; an unresolvable
`derived_from` is refused rather than flattened; slug rejection rather than rewriting; the
status vocabulary per surface; one cache invalidation per run, zero on dry-run.

Mutation-checked per the convention, with the matrix in the PR body.

## Out of scope for v1

- **Prose parsing itself.** The skill half is a separate, later change; this spec defines the
  contract it must emit. The tool is independently useful and independently testable.
- **Deleting stranded tokens after a rename.** Report orphan candidates; deleting on the
  caller's behalf during an authoring run is not something to do implicitly.
- **Fonts beyond create/update.** No font-file upload.
- **Presets.** A design system that also authored presets would land in
  [#403](https://github.com/rubicon/diviops/issues/403)'s uninvalidated-cache territory.

## Pro overlap

None. Pro 1.0.13-beta's three feature modules are cross-environment preflight, FluentCart, and
managed rollback recovery; none ingests a style guide. Flagged per `CLAUDE.md` rule 3 because
this lands in [#50](https://github.com/rubicon/diviops/issues/50)'s neighbourhood, which is
where that rule most applies.

## Prior art

[`16wells/divi-styleguide-variables`](https://github.com/16wells/divi-styleguide-variables) is
MIT and the **input** idea is worth borrowing. Its output is not, measured against this site:
it emits every colour twice — as `gcid-*` and again as `gvid-*` with
`variableType: "colors"` — where staging holds 99 colours, all `gcid-*` and zero `gvid-*`; it
emits `order: ""` where every live entry carries an ordinal; and it declares no Divi version,
from one commit dated 2026-03-17, months before 5.12.0. Take the parsing idea, not the
artifact.
