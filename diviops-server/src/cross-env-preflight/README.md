# Vendored: cross-env-preflight

The four files in this directory (`header-preflight.{js,d.ts}`,
`layout-preflight.{js,d.ts}`, `layout-capability.{js,d.ts}`,
`source-payload-ref.{js,d.ts}`) are **not authored in this repository**.

## Why

`src/index.ts` has always imported from `./cross-env-preflight/*`, but the
`.ts` source for these modules was never present anywhere reachable —
confirmed absent from this repo at every commit, from `oaris-dev/diviops`'s
public repo at both its `main` branch HEAD and its latest release tag
(`v1.5.49`), and from every prior sync. `npm run build` from a clean checkout
failed with `ERR_MODULE_NOT_FOUND` as a result (#41). This is Pro
(`cross_env` module) logic; the fork cannot author it without the real
source, and reimplementing a guess at safety-critical preflight/fingerprint
logic from scratch would be actively dangerous — see the Safety note below.

## Where these came from

The published `@diviops/mcp-server` npm package ships the real compiled
output — `dist/cross-env-preflight/*.{js,d.ts}` — even though the `.ts`
source is not public anywhere. That package is **MIT-licensed**
(`"license": "MIT"` in its own `package.json`), publicly and freely
distributed on the npm registry to every installer, so vendoring its
already-compiled, already-public output here is using our own project's own
published distribution — not a clean-room concern, and unrelated to the
`diviops-agent-pro` WordPress plugin (a separate, commercial, non-MIT
component this fork never reads from).

**Vendored from:** `@diviops/mcp-server@1.5.39` (npm registry, `dist/cross-env-preflight/`)
**Verified:** each file's sha256 matches the published tarball exactly (see
below); every call site in `src/index.ts` typechecks against the vendored
`.d.ts` unchanged.

```
d61b52cfcabb675cdefdcdc434e6dbaf691ac374b09d0747c3bd2e207f0e74fb  header-preflight.js
023d6e246357a53454915602bf74579003028bef721eb30b2a01e2d3b4b63260  header-preflight.d.ts
2e2b943142d89cca1edfe4591d28c92bd8017fa137ba3b1d1cf032a210e57267  layout-preflight.js
f58357caece223d36e871e02e2295bd174f03f8927b131b0342091afb0f439d9  layout-preflight.d.ts
ce00441b7d845f95e4a0d51b2d66e2ffc9f97a70731b01f3172034ae546d498e  layout-capability.js
ffe56e1387a4db5b833ba571fbb2c192e6f2abddf19600b56dbabda992d3320f  layout-capability.d.ts
df6c1f27a31e0dfe2ee55277a374131321c6bbfa7ceba5b58827334ae74f3cc8  source-payload-ref.js
11474311601f54b3cba347552fa2ea9c3fe8c534be1aa9c4cefd2ac98d845f63  source-payload-ref.d.ts
```

Regenerate the checksum table with:
`shasum -a 256 src/cross-env-preflight/*.{js,d.ts}`

## How the build finds these

TypeScript (`allowJs: false`, this project's default) does not emit a `.js`
file that already sits beside a same-named `.d.ts` — it type-checks against
the `.d.ts` and treats the `.js` as already-compiled, the same way it treats
a `node_modules` dependency's own build output. So `tsc` alone does **not**
copy these into `dist/`. `npm run build` runs
`scripts/copy-vendored-cross-env-preflight.mjs` immediately after `tsc` to
copy this whole directory into `dist/cross-env-preflight/` — without that
step the build still reports success while `dist/index.js` fails at import
time. `scripts/verify-server-builds-and-starts.test.mjs` regression-tests
this exact failure mode.

## Safety note

`header-preflight.js` and `layout-preflight.js` implement the actual
diff/fingerprint safety checks a cross-environment content apply refuses to
proceed without (`report.confirmation_binding.fingerprint !== reviewed` →
refuse). Do not "simplify," stub, or hand-modify this logic. A stub that
fakes a safe verdict would be strictly worse than the pre-#41 broken-build
state — it would silently approve real cross-environment writes it never
actually checked, rather than merely failing to build.

## Keeping this current

If `@diviops/mcp-server` publishes a newer version with changes to these
four modules, re-vendor by repeating the process above against the new
version and updating the checksum table and pinned version in this file.
