#!/usr/bin/env node
// Regenerates the generated regions of
// skills/divi-5-builder/references/module-formats.md from two inputs:
//
//   1. a live site's GET /diviops/v1/schema/module/dump-all output — what a
//      running install actually exposes, for the modules the file curates;
//   2. `@divi/types` (GPL-2.0-or-later), Divi's own published TypeScript
//      definitions — the Tier 3 element map, for every module Divi ships.
//
// Neither is subordinate to the other: where they disagree the disagreement is
// rendered into the doc rather than resolved silently.
//
// Usage:
//   WP_URL=https://example.test WP_USER=admin \
//     WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" \
//     node diviops-server/scripts/regen-module-formats.mjs
//
// Optional: DIVI_TYPES_VERSION (npm version to fetch, default `latest`) and
// DIVI_TYPES_DIR (an already-unpacked package directory, skipping the fetch).
//
// See diviops-server/CONTRIBUTING.md for the sentinel convention and what
// this script does and does not do (it refreshes module blocks the file
// already curates; it does not add or remove modules).

import { readFile, writeFile } from 'node:fs/promises';
import { readdirSync, statSync, readFileSync, mkdtempSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import ts from 'typescript';

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

// ─────────────────────────────────────────────────────────────────────────────
// Second input: `@divi/types`.
//
// The package is plain TypeScript source — one `src/module/library/<slug>/index.ts`
// per module, declaring that module's elements and the decoration groups each
// picks. Resolution is delegated to the TypeScript compiler's own type checker
// rather than pattern-matched out of the text: the declarations reach their
// group lists through `Pick<>`, generic type arguments, intersections and
// aliases into `src/module/element/types/`, and a scanner that reads the source
// as text has to re-implement all four to get the same answer.

// `css` is Divi's per-element Custom CSS attribute rather than a visual element
// of the module, and the schema dump does not expose it as one — excluded for
// the same reason EXCLUDED_ATTRIBUTE_KEYS drops metadata/className/style/lock.
// Every other universal key comes from the package's own `InternalAttrs`.
const TYPES_EXCLUDED_ATTRIBUTE_KEYS = ['css'];

const TYPES_COMPILER_OPTIONS = {
  target: ts.ScriptTarget.ESNext,
  module: ts.ModuleKind.ESNext,
  moduleResolution: ts.ModuleResolutionKind.Bundler,
  // `PickedAttributes<K>` is `Pick<Attributes, K>`, and `Pick` is declared in
  // the standard library — without a lib every decoration list resolves empty.
  lib: ['lib.es5.d.ts'],
  skipLibCheck: true,
  strict: false,
  noEmit: true,
};

function findFilesNamed(dir, basename) {
  const found = [];
  for (const entry of readdirSync(dir)) {
    const full = path.join(dir, entry);
    if (statSync(full).isDirectory()) found.push(...findFilesNamed(full, basename));
    else if (entry === basename) found.push(full);
  }
  return found;
}

// A type's own property names, ignoring the `undefined` an optional property
// unions in. Sorted, because the whole index is committed and diffed.
function propertyNames(checker, type) {
  if (!type) return [];
  const parts = type.isUnion() ? type.types.filter((t) => !(t.flags & ts.TypeFlags.Undefined)) : [type];
  const names = new Set();
  for (const part of parts) for (const symbol of checker.getPropertiesOfType(part)) names.add(symbol.getName());
  return [...names].sort();
}

function propertyType(checker, type, name) {
  const parts = type.isUnion() ? type.types.filter((t) => !(t.flags & ts.TypeFlags.Undefined)) : [type];
  for (const part of parts) {
    const symbol = checker.getPropertyOfType(part, name);
    if (symbol) {
      return checker.getTypeOfSymbolAtLocation(symbol, symbol.valueDeclaration ?? symbol.declarations?.[0]);
    }
  }
  return null;
}

function findExportedAttrsDeclaration(sourceFile) {
  return sourceFile.statements.find(
    (statement) =>
      (ts.isInterfaceDeclaration(statement) || ts.isTypeAliasDeclaration(statement)) &&
      /Attrs$/.test(statement.name.text) &&
      statement.modifiers?.some((modifier) => modifier.kind === ts.SyntaxKind.ExportKeyword)
  );
}

// `src/module/library/woocommerce/product-price/index.ts` -> `divi/woocommerce-product-price`,
// matching the block names Divi registers for those modules.
function moduleNameFromLibraryPath(libraryDir, file) {
  const slug = path
    .relative(libraryDir, path.dirname(file))
    .split(path.sep)
    .join('-');
  return `divi/${slug}`;
}

export function buildTypesIndex({ packageDir, version }) {
  const libraryDir = path.join(packageDir, 'src/module/library');
  const internalFile = path.join(libraryDir, 'internal/index.ts');

  const moduleFiles = findFilesNamed(libraryDir, 'index.ts')
    .filter((file) => path.dirname(file) !== libraryDir && !file.startsWith(path.join(libraryDir, 'internal') + path.sep))
    .sort();

  if (moduleFiles.length === 0) {
    throw new Error(`no module type files found under ${libraryDir} — refusing to build an empty index`);
  }

  const program = ts.createProgram([...moduleFiles, internalFile], TYPES_COMPILER_OPTIONS);
  const checker = program.getTypeChecker();

  const internalSource = program.getSourceFile(internalFile);
  const internalDeclaration =
    internalSource &&
    internalSource.statements.find(
      (statement) => ts.isInterfaceDeclaration(statement) && statement.name.text === 'InternalAttrs'
    );
  if (!internalDeclaration) {
    throw new Error(`${internalFile}: no InternalAttrs declaration to derive the universal key set from`);
  }

  const excluded = new Set([
    ...propertyNames(checker, checker.getTypeAtLocation(internalDeclaration.name)),
    ...TYPES_EXCLUDED_ATTRIBUTE_KEYS,
  ]);

  const modules = {};
  for (const file of moduleFiles) {
    const moduleName = moduleNameFromLibraryPath(libraryDir, file);
    const declaration = findExportedAttrsDeclaration(program.getSourceFile(file));
    if (!declaration) {
      throw new Error(`${path.relative(packageDir, file)}: no exported *Attrs declaration to index`);
    }

    const attrsType = checker.getTypeAtLocation(declaration.name);
    const elements = {};
    for (const key of propertyNames(checker, attrsType)) {
      if (excluded.has(key)) continue;
      const elementType = propertyType(checker, attrsType, key);
      const decorationType = elementType && propertyType(checker, elementType, 'decoration');
      const advancedType = elementType && propertyType(checker, elementType, 'advanced');
      elements[key] = {
        decoration: decorationType ? propertyNames(checker, decorationType) : [],
        innerContent: elementType ? propertyNames(checker, elementType).includes('innerContent') : false,
        advanced: advancedType ? propertyNames(checker, advancedType) : null,
      };
    }
    modules[moduleName] = elements;
  }

  return { version, modules };
}

export function renderModuleTypeLine(moduleName, elements) {
  const keys = Object.keys(elements).sort();
  if (keys.length === 0) {
    throw new Error(`${moduleName}: no elements to render — refusing a silently empty entry`);
  }

  const parts = keys.map((key) => {
    const element = elements[key];
    const groups = element.decoration.length ? [...element.decoration].sort().join(', ') : '(none)';
    const markers = [];
    if (element.innerContent) markers.push('+innerContent');
    if (element.advanced) markers.push(element.advanced.length ? `+advanced: ${element.advanced.join(', ')}` : '+advanced');
    return `\`${key}\`: ${groups}${markers.length ? ` (${markers.join('; ')})` : ''}`;
  });

  return `- **\`${moduleName}\`** — ${parts.join(' · ')}`;
}

// Compares the two inputs over the modules the schema dump carries — the only
// set where both sources have something to say. Reports rather than resolves:
// a difference is a fact about Divi (or about our recording of it), not an
// error to be normalised away.
export function compareTypesToDump(typesIndex, dumpAllData) {
  const rows = [];
  const missingFromTypes = [];

  for (const moduleName of Object.keys(dumpAllData.modules ?? {}).sort()) {
    const typed = typesIndex.modules[moduleName];
    if (!typed) {
      missingFromTypes.push(moduleName);
      continue;
    }

    const dumpAttributes = dumpAllData.modules[moduleName].attributes ?? {};
    const dumpKeys = Object.keys(dumpAttributes).filter((key) => !EXCLUDED_ATTRIBUTE_KEYS.has(key));
    const typesOnly = [];
    const dumpOnly = [];

    for (const element of [...new Set([...Object.keys(typed), ...dumpKeys])].sort()) {
      const inTypes = Object.hasOwn(typed, element);
      const inDump = dumpKeys.includes(element);
      if (inTypes && !inDump) {
        typesOnly.push(`${element}.*`);
        continue;
      }
      if (!inTypes && inDump) {
        dumpOnly.push(`${element}.*`);
        continue;
      }

      const settings = dumpAttributes[element]?.settings ?? {};
      const typeGroups = new Set(typed[element].decoration);
      const dumpGroups = new Set(Object.keys(settings.decoration ?? {}));
      for (const group of [...new Set([...typeGroups, ...dumpGroups])].sort()) {
        if (typeGroups.has(group) && !dumpGroups.has(group)) typesOnly.push(`${element}.decoration.${group}`);
        else if (!typeGroups.has(group) && dumpGroups.has(group)) dumpOnly.push(`${element}.decoration.${group}`);
      }

      for (const [marker, inTypesSide] of [
        ['innerContent', typed[element].innerContent],
        ['advanced', typed[element].advanced !== null],
      ]) {
        const inDumpSide = Object.hasOwn(settings, marker);
        if (inTypesSide && !inDumpSide) typesOnly.push(`${element}.${marker}`);
        else if (!inTypesSide && inDumpSide) dumpOnly.push(`${element}.${marker}`);
      }
    }

    rows.push({ module: moduleName, typesOnly, dumpOnly });
  }

  return { rows, missingFromTypes, comparedCount: rows.length };
}

export function renderTypesIndexRegion(typesIndex, dumpAllData) {
  const moduleNames = Object.keys(typesIndex.modules).sort();
  if (moduleNames.length === 0) {
    throw new Error('the @divi/types index carries no modules — refusing to render an empty Tier 3');
  }

  const { rows, missingFromTypes, comparedCount } = compareTypesToDump(typesIndex, dumpAllData);
  const disagreeing = rows.filter((row) => row.typesOnly.length || row.dumpOnly.length);

  const disagreementLines = disagreeing.map((row) => {
    const halves = [];
    if (row.typesOnly.length) halves.push(`types only: ${row.typesOnly.map((p) => `\`${p}\``).join(', ')}`);
    if (row.dumpOnly.length) halves.push(`dump only: ${row.dumpOnly.map((p) => `\`${p}\``).join(', ')}`);
    return `- \`${row.module}\` — ${halves.join('; ')}`;
  });

  return [
    '<!-- BEGIN GENERATED:types-index -->',
    '',
    '<!-- TIER: free -->',
    `> Generated mechanically by \`diviops-server/scripts/regen-module-formats.mjs\` from \`@divi/types\` \`${typesIndex.version}\` — Divi's own published TypeScript definitions for the Visual Builder, licensed GPL-2.0-or-later, whose README states they exist to be built against. Element names, decoration groups and \`advanced\` sub-keys are read out of each module's \`src/module/library/<slug>/index.ts\` through the TypeScript compiler's type checker, so \`Pick<>\`, generic arguments and aliases into \`src/module/element/types/\` resolve to the same set the compiler sees. Do **not** edit between sentinels — edits are clobbered on regen.`,
    '',
    `Covers ${moduleNames.length} modules — every module \`@divi/types\` declares, not only the ones the generated path index below curates. Universal block attributes are excluded (\`css\`, plus every key of the package's own \`InternalAttrs\`). \`(none)\` means the element declares no decoration groups; groups are listed at the same depth as the generated index below, i.e. \`{element}.decoration.{group}\`.`,
    '',
    "**A listed group means the type permits the path, not that the option is meaningful there.** A few elements are typed as the unrestricted `Element.Decoration.Attributes` / `Element.Advanced.Attributes` rather than a `Pick<>` of them, and those render with the complete group set because that is what the declaration says. Where an element's list looks implausibly complete, treat the disagreement table below and the generated path index as the narrower reading.",
    '',
    '#### Element maps',
    '',
    ...moduleNames.map((name) => renderModuleTypeLine(name, typesIndex.modules[name])),
    '',
    '#### Where `@divi/types` and the live schema dump disagree',
    '',
    `Both sources are recorded here rather than reconciled: \`@divi/types\` describes the attribute surface the Visual Builder's own TypeScript declares, the generated path index below describes what a running install's registered schema exposes, and the two are pinned to different releases. Of the ${comparedCount} module(s) the recorded dump carries, ${disagreeing.length} disagree with the types at some path. \`{element}.*\` means the whole element is absent from the other side.`,
    '',
    ...(disagreementLines.length ? disagreementLines : ['_(no disagreements)_']),
    '',
    ...(missingFromTypes.length
      ? [
          `Modules the dump carries that \`@divi/types\` does not declare: ${missingFromTypes
            .map((name) => `\`${name}\``)
            .join(', ')}.`,
          '',
        ]
      : []),
    '<!-- END GENERATED:types-index -->',
  ].join('\n');
}

// Length of the human-scannable schema_version fingerprint shown in the
// header — short enough to scan, long enough that two different schema
// states won't visibly collide. Matches the convention already in use in
// module-formats.md's committed header before this script existed to
// regenerate it (a 12-char prefix of the full 40-char SHA-1).
const SCHEMA_VERSION_FINGERPRINT_LENGTH = 12;

export function renderHeader({ diviVersion, schemaVersion, typesVersion }) {
  const fingerprint = schemaVersion.slice(0, SCHEMA_VERSION_FINGERPRINT_LENGTH);
  if (!typesVersion) {
    throw new Error('missing @divi/types version — the header states both provenances or neither');
  }

  return [
    '<!-- BEGIN GENERATED:header -->',
    '',
    '## Generated path index',
    '',
    '> Generated mechanically by `diviops-server/scripts/regen-module-formats.mjs` from `diviops_schema_get_module` dump-all output. Each module block lives between `BEGIN GENERATED:module:divi/<slug>` / `END GENERATED:module:divi/<slug>` HTML-comment sentinels (see `diviops-server/CONTRIBUTING.md` for the full convention). Do **not** edit between sentinels — edits are clobbered on regen.',
    '',
    `> Generated against Divi \`${diviVersion}\`, schema \`${fingerprint}…\`; Tier 3 above against \`@divi/types\` \`${typesVersion}\`.`,
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
export function regenerateContent(content, dumpAllData, typesIndex) {
  const moduleNames = parseModuleNames(content);
  if (moduleNames.length === 0) {
    throw new Error(
      'no module sentinel blocks found in the file — nothing to regenerate (refusing a silent no-op)'
    );
  }

  let result = replaceSentinelRegion(
    content,
    'header',
    renderHeader({
      diviVersion: dumpAllData.divi_version,
      schemaVersion: dumpAllData.schema_version,
      typesVersion: typesIndex.version,
    })
  );

  for (const name of moduleNames) {
    const entry = dumpAllData.modules?.[name];
    if (!entry) {
      throw new Error(`${name}: has an existing sentinel block but is missing from the fresh schema dump`);
    }
    result = replaceSentinelRegion(result, `module:${name}`, renderModuleBlock(name, entry));
  }

  return replaceSentinelRegion(result, 'types-index', renderTypesIndexRegion(typesIndex, dumpAllData));
}

export const TARGET_FILE = path.resolve(
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

// The tarball is fetched, read, and thrown away — only the distilled index it
// produces is ever committed (see scripts/__fixtures__/README.md). Vendoring
// 679 TypeScript files to generate one markdown section would be a third-party
// source tree in a repo that ships neither its build nor its tests.
export function loadDiviTypes({ version = 'latest', dir = null } = {}) {
  const packageDir = dir ?? unpackDiviTypes(version);
  const manifest = JSON.parse(readFileSync(path.join(packageDir, 'package.json'), 'utf8'));
  if (manifest.name !== '@divi/types') {
    throw new Error(`${packageDir}: expected an @divi/types package, found ${manifest.name}`);
  }
  return buildTypesIndex({ packageDir, version: manifest.version });
}

function unpackDiviTypes(version) {
  const workDir = mkdtempSync(path.join(tmpdir(), 'divi-types-'));
  const packed = execFileSync('npm', ['pack', `@divi/types@${version}`, '--pack-destination', workDir], {
    encoding: 'utf8',
  })
    .trim()
    .split('\n')
    .pop();
  execFileSync('tar', ['-xzf', path.join(workDir, packed), '-C', workDir]);
  return path.join(workDir, 'package');
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
  const typesIndex = loadDiviTypes({
    version: process.env.DIVI_TYPES_VERSION ?? 'latest',
    dir: process.env.DIVI_TYPES_DIR ?? null,
  });
  const before = await readFile(TARGET_FILE, 'utf8');
  const moduleCount = parseModuleNames(before).length;
  const after = regenerateContent(before, dumpAllData, typesIndex);

  await writeFile(TARGET_FILE, after, 'utf8');

  const fingerprint = dumpAllData.schema_version.slice(0, 12);
  const driftNote = before === after ? 'no drift' : 'content changed — review before committing';
  const { comparedCount, rows } = compareTypesToDump(typesIndex, dumpAllData);
  const disagreeing = rows.filter((row) => row.typesOnly.length || row.dumpOnly.length).length;
  console.log(
    `regen-module-formats: regenerated ${moduleCount} module block(s) against Divi ` +
      `${dumpAllData.divi_version} (schema ${fingerprint}…) and ` +
      `${Object.keys(typesIndex.modules).length} Tier 3 element map(s) against @divi/types ` +
      `${typesIndex.version}; ${disagreeing}/${comparedCount} compared module(s) disagree — ${driftNote}.`
  );
}

const isMainModule = process.argv[1] === fileURLToPath(import.meta.url);
if (isMainModule) {
  main().catch((error) => {
    console.error(`regen-module-formats: ${error.message}`);
    process.exitCode = 1;
  });
}
