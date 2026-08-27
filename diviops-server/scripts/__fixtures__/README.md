# `dump-all.json`

A recorded `GET /diviops/v1/schema/module/dump-all` payload, trimmed to exactly the
modules `skills/divi-5-builder/references/module-formats.md` curates as sentinel pairs.

It exists so `regen-module-formats.test.mjs` can assert that the committed generated
sections are what the generator actually produces. CI has no live WordPress, so the
input has to be recorded. This mirrors what `regen-tool-reference.test.mjs` does with
`src/index.ts`, except that its input lives in the repo and ours does not.

**Do not hand-edit it, and do not derive it from `module-formats.md`.** It is the
generator's *input*; the markdown is its *output*. Editing the fixture to make a test
pass inverts the direction the guard is supposed to check and makes it vacuous.

## Refreshing it

Only when Divi's schema legitimately moves. The fixture and `module-formats.md` are
refreshed together, in this order, or the guard fails:

```bash
# 1. regenerate the doc from the live site
WP_URL=... WP_USER=... WP_APP_PASSWORD=... npm run regen:skill --prefix diviops-server

# 2. re-record the fixture from the same site, same session
WP_URL=... WP_USER=... WP_APP_PASSWORD=... node diviops-server/scripts/__fixtures__/capture.mjs

# 3. confirm the guard agrees
npm run test:regen-skill --prefix diviops-server
```

Record both from the **same** install in the **same** sitting. A fixture from one Divi
version against a doc from another is exactly the drift this guard exists to catch, and
it will fail loudly rather than silently disagreeing.

`divi_version` and `schema_version` in the fixture record which install it came from.
