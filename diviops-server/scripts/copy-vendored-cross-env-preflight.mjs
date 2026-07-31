#!/usr/bin/env node
// Copies the vendored cross-env-preflight module pair into dist/ after tsc runs.
//
// src/cross-env-preflight/*.{js,d.ts} are not TypeScript source -- they are
// compiled output vendored verbatim from the published @diviops/mcp-server
// npm package (see src/cross-env-preflight/README.md for full provenance and
// #41 for why: the source was never present in this repo's sync from
// upstream, in any tag or on upstream's own main branch, despite upstream's
// own compiled dist/ shipping it). With allowJs off, tsc type-checks a
// same-named .js/.d.ts pair via the .d.ts but does not copy the .js into
// outDir, the same way it would not copy a node_modules dependency's own
// compiled output. Without this step `npm run build` succeeds silently while
// dist/index.js fails at import time with ERR_MODULE_NOT_FOUND -- confirmed
// directly while fixing #41, not assumed.
import { cpSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const here = dirname(fileURLToPath(import.meta.url));
const src = join(here, "..", "src", "cross-env-preflight");
const dist = join(here, "..", "dist", "cross-env-preflight");

if (!existsSync(src)) {
  console.error(`copy-vendored-cross-env-preflight: source directory missing: ${src}`);
  process.exit(1);
}

cpSync(src, dist, { recursive: true, force: true });
console.log(`copy-vendored-cross-env-preflight: ${src} -> ${dist}`);
