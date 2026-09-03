# Recorded generator inputs

`regen-module-formats.mjs` reads two things CI cannot reach — a live WordPress install
and an npm package — so both are recorded here. They exist so
`regen-module-formats.test.mjs` can assert that the committed generated sections of
`skills/divi-5-builder/references/module-formats.md` are what the generator actually
produces. This mirrors what `regen-tool-reference.test.mjs` does with `src/index.ts`,
except that its input lives in the repo and ours does not.

**Do not hand-edit either, and do not derive either from `module-formats.md`.** They are
the generator's *input*; the markdown is its *output*. Editing a fixture to make a test
pass inverts the direction the guard is supposed to check and makes it vacuous.

## `dump-all.json`

A recorded `GET /diviops/v1/schema/module/dump-all` payload, trimmed to exactly the
modules `module-formats.md` curates as sentinel pairs. `divi_version` and
`schema_version` record which install it came from.

## `divi-types.json`

The distilled `@divi/types` index behind Tier 3: one entry per module the package
declares, each listing that module's elements with their decoration groups,
`innerContent` presence and `advanced` sub-keys. `version` records the npm version it
was built from, and the committed header pins the same one.

It is *distilled*, not vendored: the package is 679 TypeScript files, and only the
element maps the generator needs are kept. `@divi/types` is GPL-2.0-or-later, so
deriving from it is licensed; committing its whole source tree into a repo that neither
builds nor tests it would not be a good idea regardless.

## Refreshing them

Only when Divi's schema or the package legitimately moves. The fixtures and
`module-formats.md` are refreshed together, in this order, or the guard fails:

```bash
# 1. regenerate the doc from the live site
WP_URL=... WP_USER=... WP_APP_PASSWORD=... npm run regen:skill --prefix diviops-server

# 2. re-record both fixtures from the same site and the same package version
WP_URL=... WP_USER=... WP_APP_PASSWORD=... node diviops-server/scripts/__fixtures__/capture.mjs

# 3. confirm the guard agrees
npm run test:regen-skill --prefix diviops-server
```

Record from the **same** install and the **same** package version in the **same**
sitting. Both steps default to `@divi/types@latest`; pin `DIVI_TYPES_VERSION` (or
`DIVI_TYPES_DIR`) across both if a run straddles a package release. A fixture from one
Divi version against a doc from another is exactly the drift this guard exists to catch,
and it will fail loudly rather than silently disagreeing.
