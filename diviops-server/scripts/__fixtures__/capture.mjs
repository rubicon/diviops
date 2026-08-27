#!/usr/bin/env node
// Re-records dump-all.json from a live site, trimmed to exactly the modules
// module-formats.md curates as sentinel pairs.
//
// Usage:
//   WP_URL=https://example.test WP_USER=admin \
//     WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" \
//     node diviops-server/scripts/__fixtures__/capture.mjs
//
// See README.md in this directory — the fixture and module-formats.md must be
// recorded from the same install in the same sitting.

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { fetchDumpAll, parseModuleNames, TARGET_FILE } from '../regen-module-formats.mjs';

const OUT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), 'dump-all.json');

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
