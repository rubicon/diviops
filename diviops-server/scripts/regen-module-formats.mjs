#!/usr/bin/env node
// Regenerates the "Generated path index" section of
// skills/divi-5-builder/references/module-formats.md from a live site's
// GET /diviops/v1/schema/module/dump-all output.
//
// Usage:
//   WP_URL=https://example.test WP_USER=admin \
//     WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" \
//     node diviops-server/scripts/regen-module-formats.mjs
//
// See diviops-server/CONTRIBUTING.md for the sentinel convention and what
// this script does and does not do (it refreshes module blocks the file
// already curates; it does not add or remove modules).

import { readFile, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// Universal Divi/Gutenberg block attributes present on every module; not
// part of the module's own visual element set, so never indexed.
const EXCLUDED_ATTRIBUTE_KEYS = new Set(['metadata', 'className', 'style', 'lock']);

export function renderElementLine(key, settings) {
  const decoration = settings.decoration ?? {};
  const decorationNames = Object.keys(decoration).sort();
  const list = decorationNames.length
    ? decorationNames.map((name) => `\`${key}.decoration.${name}\``).join(', ')
    : '_(no decoration groups)_';

  const suffixParts = [];
  if (Object.hasOwn(settings, 'innerContent')) suffixParts.push('+innerContent');
  if (Object.hasOwn(settings, 'advanced')) suffixParts.push('+advanced');
  const suffix = suffixParts.length ? ` _(${suffixParts.join(', ')})_` : '';

  return `- **${key}** — ${list}${suffix}`;
}

export function renderModuleBlock(moduleName, moduleEntry) {
  const attributes = moduleEntry?.attributes;
  if (typeof attributes !== 'object' || attributes === null) {
    throw new Error(`${moduleName}: schema entry has no "attributes" object to index`);
  }

  const keys = Object.keys(attributes)
    .filter((key) => !EXCLUDED_ATTRIBUTE_KEYS.has(key))
    .sort();

  if (keys.length === 0) {
    throw new Error(
      `${moduleName}: no indexable attribute keys remain after excluding metadata/className/style/lock`
    );
  }

  const lines = keys.map((key) => renderElementLine(key, attributes[key]?.settings ?? {}));

  return [
    `<!-- BEGIN GENERATED:module:${moduleName} -->`,
    '',
    '<!-- TIER: free -->',
    `#### \`${moduleName}\``,
    '',
    ...lines,
    '',
    `<!-- END GENERATED:module:${moduleName} -->`,
  ].join('\n');
}

// Length of the human-scannable schema_version fingerprint shown in the
// header — short enough to scan, long enough that two different schema
// states won't visibly collide. Matches the convention already in use in
// module-formats.md's committed header before this script existed to
// regenerate it (a 12-char prefix of the full 40-char SHA-1).
const SCHEMA_VERSION_FINGERPRINT_LENGTH = 12;

export function renderHeader({ diviVersion, schemaVersion }) {
  const fingerprint = schemaVersion.slice(0, SCHEMA_VERSION_FINGERPRINT_LENGTH);

  return [
    '<!-- BEGIN GENERATED:header -->',
    '',
    '## Generated path index',
    '',
    '> Generated mechanically by `diviops-server/scripts/regen-module-formats.mjs` from `diviops_schema_get_module` dump-all output. Each module block lives between `BEGIN GENERATED:module:divi/<slug>` / `END GENERATED:module:divi/<slug>` HTML-comment sentinels (see `diviops-server/CONTRIBUTING.md` for the full convention). Do **not** edit between sentinels — edits are clobbered on regen.',
    '',
    `> Generated against Divi \`${diviVersion}\`, schema \`${fingerprint}…\`.`,
    '',
    'Per CLAUDE.md "Suite architecture coherence": schema dump is the canonical index; VB-verified prose above is the canonical interpretation. The two sections are complementary, not competing — prose explains surprises, this index enumerates paths exhaustively. On conflicts, the prose above wins (per `feedback_vb_first_verification`).',
    '',
    '<!-- END GENERATED:header -->',
  ].join('\n');
}

const MODULE_BEGIN_SENTINEL = /<!-- BEGIN GENERATED:module:(divi\/[a-z0-9-]+) -->/g;

// The set of modules to regenerate is whatever the file already curates as
// sentinel-bounded blocks — see diviops-server/CONTRIBUTING.md for why this
// script doesn't auto-discover every module the live site knows about.
export function parseModuleNames(content) {
  return [...content.matchAll(MODULE_BEGIN_SENTINEL)].map((match) => match[1]);
}

function replaceSentinelRegion(content, sentinelId, replacementBlock) {
  const pattern = new RegExp(
    `<!-- BEGIN GENERATED:${sentinelId} -->[\\s\\S]*?<!-- END GENERATED:${sentinelId} -->`
  );
  if (!pattern.test(content)) {
    throw new Error(`sentinel region not found: ${sentinelId}`);
  }
  return content.replace(pattern, replacementBlock);
}

// Regenerates the header and every module block the file ALREADY curates as
// a sentinel pair. Never adds or removes a module — see
// diviops-server/CONTRIBUTING.md for why membership is an editorial
// decision, not something this script infers from the live schema dump.
export function regenerateContent(content, dumpAllData) {
  const moduleNames = parseModuleNames(content);
  if (moduleNames.length === 0) {
    throw new Error(
      'no module sentinel blocks found in the file — nothing to regenerate (refusing a silent no-op)'
    );
  }

  let result = replaceSentinelRegion(
    content,
    'header',
    renderHeader({ diviVersion: dumpAllData.divi_version, schemaVersion: dumpAllData.schema_version })
  );

  for (const name of moduleNames) {
    const entry = dumpAllData.modules?.[name];
    if (!entry) {
      throw new Error(`${name}: has an existing sentinel block but is missing from the fresh schema dump`);
    }
    result = replaceSentinelRegion(result, `module:${name}`, renderModuleBlock(name, entry));
  }

  return result;
}

const TARGET_FILE = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../../skills/divi-5-builder/references/module-formats.md'
);

// Hand-rolled rather than importing diviops-server/src/wp-client.ts: that
// module only exists as compiled output of `npm run build`, which currently
// fails for unrelated reasons (#41, missing cross-env-preflight modules).
// This script is plain ESM with no build step so it isn't blocked by that;
// duplicating WPClient's few lines of Basic Auth construction is cheaper
// than coupling this script to the broken graph.
export async function fetchDumpAll({ wpUrl, wpUser, wpAppPassword }) {
  const url = `${wpUrl.replace(/\/+$/, '')}/wp-json/diviops/v1/schema/module/dump-all`;
  const authHeader = `Basic ${Buffer.from(`${wpUser}:${wpAppPassword}`).toString('base64')}`;

  const response = await fetch(url, {
    headers: { Authorization: authHeader, Accept: 'application/json' },
  });
  const body = await response.json();

  if (!response.ok || body.ok === false) {
    const message = body?.error?.message ?? `HTTP ${response.status}`;
    throw new Error(`GET /schema/module/dump-all failed: ${message}`);
  }

  return body.data;
}

async function main() {
  const wpUrl = process.env.WP_URL;
  const wpUser = process.env.WP_USER;
  const wpAppPassword = process.env.WP_APP_PASSWORD;

  if (!wpUrl || !wpUser || !wpAppPassword) {
    throw new Error(
      'missing WP_URL, WP_USER, or WP_APP_PASSWORD.\n' +
        'Usage: WP_URL=https://example.test WP_USER=admin ' +
        'WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" ' +
        'node diviops-server/scripts/regen-module-formats.mjs'
    );
  }

  const dumpAllData = await fetchDumpAll({ wpUrl, wpUser, wpAppPassword });
  const before = await readFile(TARGET_FILE, 'utf8');
  const moduleCount = parseModuleNames(before).length;
  const after = regenerateContent(before, dumpAllData);

  await writeFile(TARGET_FILE, after, 'utf8');

  const fingerprint = dumpAllData.schema_version.slice(0, 12);
  const driftNote = before === after ? 'no drift' : 'content changed — review before committing';
  console.log(
    `regen-module-formats: regenerated ${moduleCount} module block(s) against Divi ` +
      `${dumpAllData.divi_version} (schema ${fingerprint}…) — ${driftNote}.`
  );
}

const isMainModule = process.argv[1] === fileURLToPath(import.meta.url);
if (isMainModule) {
  main().catch((error) => {
    console.error(`regen-module-formats: ${error.message}`);
    process.exitCode = 1;
  });
}
