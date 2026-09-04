#!/usr/bin/env node
// Re-records both generator inputs: dump-all.json from a live site, trimmed to
// exactly the modules module-formats.md curates as sentinel pairs, and
// divi-types.json, the distilled @divi/types index behind Tier 3.
//
// Usage:
//   WP_URL=https://example.test WP_USER=admin \
//     WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" \
//     node diviops-server/scripts/__fixtures__/capture.mjs
//
// Honours the same DIVI_TYPES_VERSION / DIVI_TYPES_DIR overrides the regen
// script does, so both can be pinned to one package version in one sitting.
//
// See README.md in this directory — the fixtures and module-formats.md must be
// recorded from the same install, and the same package version, in the same
// sitting.

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { fetchDumpAll, loadDiviTypes, parseModuleNames, TARGET_FILE } from '../regen-module-formats.mjs';

const OUT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), 'dump-all.json');
const TYPES_OUT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), 'divi-types.json');

const { WP_URL: wpUrl, WP_USER: wpUser, WP_APP_PASSWORD: wpAppPassword } = process.env;
if (!wpUrl || !wpUser || !wpAppPassword) {
  throw new Error('missing WP_URL, WP_USER, or WP_APP_PASSWORD — see README.md in this directory');
}

const data = await fetchDumpAll({ wpUrl, wpUser, wpAppPassword });
const curated = parseModuleNames(readFileSync(TARGET_FILE, 'utf8'));

if (curated.length === 0) {
  throw new Error('module-formats.md curates no modules — refusing to write an empty fixture');
}

const modules = {};
for (const name of curated) {
  if (!data.modules?.[name]) {
    throw new Error(`live dump is missing curated module ${name} — refusing a partial fixture`);
  }
  modules[name] = data.modules[name];
}

writeFileSync(
  OUT,
  JSON.stringify({ divi_version: data.divi_version, schema_version: data.schema_version, modules }, null, 2) + '\n'
);

console.log(
  `captured ${curated.length} module(s) from Divi ${data.divi_version} ` +
    `(schema ${data.schema_version.slice(0, 12)}…) -> ${path.relative(process.cwd(), OUT)}`
);

const typesIndex = loadDiviTypes({
  version: process.env.DIVI_TYPES_VERSION ?? 'latest',
  dir: process.env.DIVI_TYPES_DIR ?? null,
});

if (Object.keys(typesIndex.modules).length === 0) {
  throw new Error('@divi/types yielded no modules — refusing to write an empty fixture');
}

writeFileSync(TYPES_OUT, JSON.stringify(typesIndex, null, 2) + '\n');

console.log(
  `captured ${Object.keys(typesIndex.modules).length} module element map(s) from ` +
    `@divi/types ${typesIndex.version} -> ${path.relative(process.cwd(), TYPES_OUT)}`
);
