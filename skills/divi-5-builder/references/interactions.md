# Divi 5 Interactions — trigger → effect on a target

Divi 5's Interactions option attaches a **trigger** on one module to an **effect** applied
to a **target**. It is stored as module attribute data, so it is written the same way as
any other attribute — no separate API.

Everything here was read from Divi's own source on the staging site
*(verified 2026-09-23, Divi 5.13.1)*. Where a value came from a minified Visual Builder
bundle rather than PHP, the section says so, because those are read by hand and are the
likeliest thing here to drift.

## Where it lives

| file | lines | role |
|---|---|---|
| `Packages/Module/Options/Interactions/InteractionsScriptData.php` | 330 | builds the front-end script payload |
| `Packages/Module/Options/Interactions/InteractionUtils.php` | 52 | helpers |
| `Packages/Module/Options/Interactions/InteractionsPresetAttrsMap.php` | 40 | preset attr mapping (`preset => ['script']`) |
| `Packages/Module/Options/Element/InteractionClassnames.php` | 46 | the classnames an interaction adds |

All under `wp-content/themes/Divi/includes/builder-5/server/`, namespace
`ET\Builder\Packages\Module\Options\Interactions`.

Front-end runtime:
`includes/builder-5/visual-builder/build/script-library-interactions.js` (~40 KB,
minified onto one line).

## The attribute slot is opt-in and empty by default

Module metadata declares the slot as `'interactions' => []`. A module with no
interactions carries an empty array, not a populated default — so reading a module and
finding `[]` means "none configured", not "not supported".

## Entry shape

One interaction entry carries these keys. Not all apply to every trigger/effect pair —
the irrelevant ones are simply absent.

| key | meaning |
|---|---|
| `id` | entry identity |
| `enabled` | whether this entry runs |
| `label` | author-facing name in the UI |
| `trigger` | what starts it — see below |
| `effect` | what it does — see below |
| `target` | what it acts on |
| `targetType` | how `target` is interpreted |
| `targetClass` | class-based targeting |
| `selector` | selector-based targeting |
| `timeDelay` | delay before the effect |
| `sensitivity` | used by the mouse-movement effect |
| `mouseMovementType` | which mouse-movement variant |
| `breakpointName` | which breakpoint, for the breakpoint triggers |
| `cookieName`, `cookieValue` | cookie-conditioned behavior |
| `attributeName`, `attributeValue` | attribute-conditioned behavior |
| `presetId`, `replaceExistingPreset` | preset applied by the effect |
| `storeInstance` | which builder store instance the entry belongs to |

`InteractionsScriptData.php` also threads `attr`, `data_item`, `data_item_id` and
`data_name` through when building the payload.

## Triggers

From the front-end runtime *(read from the minified bundle)*:

| `trigger` | fires when |
|---|---|
| `load` | the page loads |
| `breakpointEnter` | the viewport enters `breakpointName` |
| `breakpointExit` | the viewport leaves `breakpointName` |

Divi's server PHP additionally compares `trigger` against `data`, `hover` and `onLoad`.
Treat `onLoad` as the legacy spelling of `load` and prefer `load`.
<!-- UNVERIFIED -->
<!-- Whether `data` and `hover` are author-selectable in the VB UI or internal-only.
     Confirm in the builder before documenting them as options. -->

## Effects

From the same runtime:

| `effect` | does |
|---|---|
| `toggleVisibility` | shows/hides the target |
| `removeVisibility` | removes the target's visibility |
| `mirrorMouseMovement` | moves the target with the pointer, scaled by `sensitivity` and shaped by `mouseMovementType` |

<!-- UNVERIFIED -->
<!-- This is the set the runtime branches on. Divi may ship additional effects gated
     behind UI this extraction did not reach. Re-read the bundle after a Divi upgrade
     before relying on the list being exhaustive. -->

## Reading the runtime yourself

The bundle is one line, so line-based counting lies:

```bash
grep -c  mouseMovementType script-library-interactions.js   # -> 1   (wrong)
grep -o  mouseMovementType script-library-interactions.js | wc -l   # -> 8  (right)
```

Use a wide context window to read around a known key:

```bash
grep -oE '.{400}mouseMovementType.{400}' script-library-interactions.js | head -1
```

## Relationship to other references

- Interactions are stored as module attributes; for how attribute paths are addressed
  generally, see [module-formats.md](module-formats.md).
- To add a *class* rather than an interaction, see
  [design-effects.md](design-effects.md) — that uses
  `module.decoration.attributes`, not `className`.
- Interactions are not `$variable()` tokens and do not share that grammar; see
  [variable-bindings.md](variable-bindings.md) if what you actually want is a dynamic
  value rather than a behavior.

## What this reference does not cover

- The Visual Builder's Interactions UI, and which combinations it allows an author to
  select. Everything above is the stored shape and the runtime's behavior.
- Whether a given module supports interactions. The slot exists in module metadata; a
  per-module audit has not been done.
- Divi's own `module.decoration.interactions` index entries, which this fork has not
  regenerated — tracked separately.
