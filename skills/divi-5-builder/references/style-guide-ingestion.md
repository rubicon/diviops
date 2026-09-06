# Style guide → live Divi design system

How to turn a brand guideline, a design-token table, or a page of prose into a live Divi 5
colour palette, using `diviops_design_system_apply`.

This is the **parsing** half. The tool is the **applying** half, and the split is deliberate:
reading free-form prose is your job, and the tool's job is to refuse anything you got wrong.
Everything below is written so the two halves cannot drift — `tests/test-design-system-skill-contract.php`
fails when this document names a rule the tool does not enforce, or omits one it does.

## The one rule that matters

**Never invent a token value.**

If the style guide says "Primary — our signature blue" and never gives a hex, you do not get
to pick one. Emit nothing for that token and tell the user which tokens you could not resolve.
The tool refuses a colour carrying neither `value` nor `derived_from`, so a guessed palette
becomes an error rather than a wrong site — but the error is the backstop, not the plan.

The same applies to a value you are *nearly* sure of. "Brand blue (Pantone 285C)" is not a hex
you know; it is a hex someone has to look up. Say so.

## What to emit

One call, one payload:

```json
{
  "namespace": "acme",
  "colors": [
    { "name": "primary",     "value": "#1B4D8F", "label": "Primary" },
    { "name": "primary-600", "derived_from": "primary", "settings": { "lightness": -10 } },
    { "name": "muted",       "derived_from": "primary", "settings": { "saturation": -30, "opacity": 60 } }
  ]
}
```

| Field | Rule |
| --- | --- |
| `name` | Letters, digits, spaces and hyphens **only**. Becomes `gcid-<namespace>-<slug>`. A name needing any other rewriting is refused, not cleaned up. |
| `value` | A literal CSS colour: hex (`#rgb` / `#rrggbb` / `#rrggbbaa`) or functional `rgb()` / `rgba()` / `hsl()` / `hsla()`. |
| `derived_from` | Another `name` in this same payload, or an existing `gcid-*` id on the site. |
| `settings` | Only with `derived_from`. Keys: `hue`, `saturation`, `lightness`, `opacity`. Integers, positive or negative. |
| `label` | What a human sees in the Visual Builder picker. Omit on an update to keep the stored one. |
| `status` | `active`, `inactive` or `temporary`. Omit to keep the stored one. **`archived` is a variable status and is refused here.** |

Supply **exactly one** of `value` or `derived_from`. Both is refused as ambiguous, because
picking a winner would silently discard something the guide told you.

## Derived colours are the point, not an optimisation

A mature Divi palette is mostly relationships. On the reference install, **47 of 99 colours**
are `$variable()` references rather than literals.

So when a style guide expresses a relationship, **preserve it**:

> Primary 600 is Primary darkened 10%.
> Muted is Primary at 30% less saturation and 60% opacity.

```json
{ "name": "primary-600", "derived_from": "primary", "settings": { "lightness": -10 } }
{ "name": "muted",       "derived_from": "primary", "settings": { "saturation": -30, "opacity": 60 } }
```

Do **not** compute the resulting hex and emit a literal. A literal looks identical on the day
you write it and is wrong forever after: the client changes Primary, and every shade you
flattened stays where it was. That is the whole reason this tool speaks Divi's reference
grammar.

Order does not matter — the tool sorts by dependency before writing, so you may list a derived
colour before the one it derives from. Cycles are refused.

## Reading the three input shapes

**A token table** (markdown or CSV) is the easy case: one row per token, and the columns
usually map straight onto the fields above. Read the header row rather than assuming column
order.

**A JSON token payload** (Style Dictionary, Figma export, `tokens.json`) usually nests by
group. Flatten the path into the name: `color.brand.primary` → `brand-primary`. Keep the
grouping in the name, because that is what makes the Visual Builder picker readable.

**Prose** is where judgment is needed. Extract only what is stated:

> Our primary is a deep navy, #1B4D8F. Hover states use it 10% darker. Body copy is
> near-black; headings use the primary.

That yields **two** tokens — `primary` (literal) and a `primary-hover` derived at
`lightness: -10`. It does **not** yield a `body` token: "near-black" is not a value. Report
`body` as unresolved and ask.

## Before you write anything: dry run

```
diviops_design_system_apply { namespace, colors, dry_run: true }
```

Returns the plan — one entry per token, each naming the id it would write and whether it is a
create or an update — and writes nothing. Show the user the plan for a payload of any size, or
whenever you inferred a relationship from prose rather than being told one.

## Re-running against an updated guide

Ids are deterministic, so a second run is an **update**, not a duplicate — provided the
`namespace` and the token `name`s are unchanged. Keep both stable; changing either mints a
whole new token set beside the old one.

- `overwrite: false` (default) — existing ids are skipped and listed, with a reason. Safe.
- `overwrite: true` — existing ids are updated in place. `order`, and every key Divi owns, are
  preserved.

A **renamed** token gets a new id and leaves the old one behind. That is correct — a rename is
a new token — but it does strand the old one. Run `diviops_variable_scan_orphans` afterwards
and tell the user what is now unreferenced; do not delete on their behalf.

## What the tool refuses, and what to do about it

Every refusal names the offending token and its index. None of these is a retry-with-a-tweak
situation; each means something in the input needs a human answer.

| Refusal | What it means |
| --- | --- |
| neither `value` nor `derived_from` | You did not know the value. Ask; do not guess. |
| both of them | The guide gave two answers, or you merged two rows. Re-read it. |
| value is not a CSS colour | A colour name like `navy`, a Pantone code, or prose got through. |
| unrecognised `settings` key | Only `hue`, `saturation`, `lightness`, `opacity` exist. |
| non-integer setting | `"10%"` is not an integer. Strip the unit: `10`. |
| status outside `active`/`inactive`/`temporary` | `archived` belongs to `gvid-*` variables. |
| `derived_from` resolves to nothing | The base token is not in this payload and not on the site. |
| dependency cycle | Two tokens derive from each other. Divi cannot resolve it. |
| name cannot become an id | The name carries characters beyond letters, digits, spaces, hyphens. |
| two names resolve to one id | Ids are case-insensitive: `Brand Blue` and `brand blue` collide. |

**One bad token refuses the whole payload**, and nothing is written. That is deliberate — a
half-applied design system is worse than none, because nobody can tell which half landed. Fix
the named token and re-send the whole set.

## Scope

Colours only. Fonts go through `diviops_global_font_create`, and non-colour variables through
`diviops_variable_create` / `diviops_variable_create_fluid_system` — the last of which is the
right tool for a type scale, since it generates the `clamp()` maths rather than making you
compute each step.
