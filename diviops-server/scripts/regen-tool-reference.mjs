#!/usr/bin/env node
// Regenerates the "Per-tool reference" section of diviops-server/README.md from
// the registerPluginTool / registerLocalTool / registerProTool call sites in
// diviops-server/src/index.ts.
//
// Usage:
//   node diviops-server/scripts/regen-tool-reference.mjs
//
// Needs no WordPress site and no credentials: the tool surface is entirely
// declared in source. Reads the call sites through the TypeScript compiler's
// own parser rather than a regex, because the registrations carry shapes a
// pattern match cannot follow safely — a description built by string
// concatenation or a template literal, a config assembled by object spread from
// another module, an input field whose optionality lives in a shared constant.
//
// See diviops-server/CONTRIBUTING.md for the sentinel convention and how this
// script fits the docs workflow.

import ts from 'typescript';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const HELPER_KINDS = new Map([
  ['registerPluginTool', 'plugin'],
  ['registerLocalTool', 'server_local'],
  ['registerProTool', 'pro'],
]);

const KIND_LABELS = new Map([
  ['plugin', 'plugin'],
  ['server_local', 'server-local'],
]);

// A Zod chain link that makes the field non-mandatory in the emitted JSON
// schema. `default` counts: a defaulted field is one the caller may omit.
const OPTIONAL_CHAIN_METHODS = new Set(['optional', 'default', 'nullish']);

// Word endings that carry a period without ending a sentence. Checked against
// the token immediately before a candidate sentence break, since these
// descriptions use "e.g." and "i.e." freely mid-sentence.
const ABBREVIATIONS = new Set(['e.g', 'i.e', 'etc', 'vs', 'cf', 'approx', 'incl', 'resp', 'no', 'fig']);

const SCRIPT_DIR = path.dirname(fileURLToPath(import.meta.url));
export const ENTRY_FILE = path.resolve(SCRIPT_DIR, '../src/index.ts');
export const TARGET_FILE = path.resolve(SCRIPT_DIR, '../README.md');

function parse(fileName, text) {
  return ts.createSourceFile(fileName, text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS);
}

/**
 * Read the registration file plus every sibling module it imports by relative
 * path, so a config or schema constant defined elsewhere still resolves.
 * Imports that don't resolve to a file on disk are skipped; a symbol that
 * genuinely can't be found fails loudly later, at the call site that needs it.
 */
export function loadSourceSet(entryFile) {
  const entryText = readFileSync(entryFile, 'utf8');
  const sources = [{ fileName: entryFile, text: entryText }];
  const entryDir = path.dirname(entryFile);

  for (const statement of parse(entryFile, entryText).statements) {
    if (!ts.isImportDeclaration(statement)) continue;
    if (!ts.isStringLiteral(statement.moduleSpecifier)) continue;
    const specifier = statement.moduleSpecifier.text;
    if (!specifier.startsWith('.')) continue;
    const resolved = path.resolve(entryDir, specifier.replace(/\.js$/, '.ts'));
    if (!existsSync(resolved)) continue;
    if (sources.some((source) => source.fileName === resolved)) continue;
    sources.push({ fileName: resolved, text: readFileSync(resolved, 'utf8') });
  }

  return sources;
}

/**
 * Map every top-level `const NAME = <initializer>` across the source set. First
 * declaration wins, so the registration file's own constants shadow an
 * identically named one in a module it imports.
 */
function buildConstantTable(sourceFiles) {
  const constants = new Map();
  for (const sourceFile of sourceFiles) {
    for (const statement of sourceFile.statements) {
      if (!ts.isVariableStatement(statement)) continue;
      for (const declaration of statement.declarationList.declarations) {
        if (!ts.isIdentifier(declaration.name) || !declaration.initializer) continue;
        if (!constants.has(declaration.name.text)) {
          constants.set(declaration.name.text, declaration.initializer);
        }
      }
    }
  }
  return constants;
}

function unwrap(node) {
  if (ts.isAsExpression(node) || ts.isParenthesizedExpression(node) || ts.isSatisfiesExpression(node)) {
    return unwrap(node.expression);
  }
  return node;
}

/** Resolve an identifier to the initializer it was declared with, or throw. */
function resolveNode(node, constants, context) {
  const bare = unwrap(node);
  if (!ts.isIdentifier(bare)) return bare;
  const declared = constants.get(bare.text);
  if (!declared) {
    throw new Error(`${context}: cannot resolve identifier ${bare.text} to a top-level declaration`);
  }
  return resolveNode(declared, constants, context);
}

function propertyName(node) {
  if (ts.isIdentifier(node) || ts.isStringLiteral(node)) return node.text;
  return node.getText();
}

/**
 * Flatten an object literal's own properties plus anything spread into it, in
 * declaration order, later keys winning as they do at runtime.
 */
function objectProperties(node, constants, context) {
  const resolved = resolveNode(node, constants, context);
  if (!ts.isObjectLiteralExpression(resolved)) {
    throw new Error(`${context}: expected an object literal, found ${ts.SyntaxKind[resolved.kind]}`);
  }

  const properties = new Map();
  for (const property of resolved.properties) {
    if (ts.isPropertyAssignment(property)) {
      properties.set(propertyName(property.name), property.initializer);
    } else if (ts.isSpreadAssignment(property)) {
      for (const [key, value] of objectProperties(property.expression, constants, context)) {
        properties.set(key, value);
      }
    } else {
      throw new Error(`${context}: unsupported object member ${property.getText().slice(0, 40)}`);
    }
  }
  return properties;
}

/**
 * Reconstruct a description's text. A `${...}` placeholder the server fills in
 * at handshake time becomes an ellipsis: its value is not knowable from source,
 * and printing the expression would be noise in a reader-facing table.
 */
function descriptionText(node, constants, context) {
  const resolved = resolveNode(node, constants, context);
  if (ts.isStringLiteral(resolved) || ts.isNoSubstitutionTemplateLiteral(resolved)) return resolved.text;
  if (ts.isBinaryExpression(resolved) && resolved.operatorToken.kind === ts.SyntaxKind.PlusToken) {
    return descriptionText(resolved.left, constants, context) + descriptionText(resolved.right, constants, context);
  }
  if (ts.isTemplateExpression(resolved)) {
    return resolved.head.text + resolved.templateSpans.map((span) => `…${span.literal.text}`).join('');
  }
  throw new Error(`${context}: unsupported description expression ${ts.SyntaxKind[resolved.kind]}`);
}

/** Whether a field's Zod chain makes it optional or defaulted. */
function isOptionalField(node, constants, context) {
  let current = resolveNode(node, constants, context);
  while (ts.isCallExpression(current)) {
    const callee = unwrap(current.expression);
    if (!ts.isPropertyAccessExpression(callee)) break;
    if (OPTIONAL_CHAIN_METHODS.has(callee.name.text)) return true;
    current = unwrap(callee.expression);
    if (ts.isIdentifier(current)) {
      const declared = constants.get(current.text);
      if (!declared) return false;
      current = resolveNode(declared, constants, context);
    }
  }
  return false;
}

function inputFields(node, constants, context) {
  return [...objectProperties(node, constants, context)].map(([name, value]) => ({
    name,
    optional: isOptionalField(value, constants, context),
  }));
}

function toolFromCall(call, kind, constants) {
  const [nameArg, configArg, , gatesArg] = call.arguments;
  if (!nameArg || !ts.isStringLiteral(nameArg)) {
    throw new Error(`a ${kind} registration's first argument is not a literal tool name`);
  }
  const name = nameArg.text;
  const config = objectProperties(configArg, constants, name);

  const description = config.has('description')
    ? descriptionText(config.get('description'), constants, name)
    : null;
  if (!description) throw new Error(`${name}: registration carries no description`);

  const meta = config.has('_meta') ? objectProperties(config.get('_meta'), constants, name) : new Map();
  const idempotentNode = meta.get('idempotent');
  if (!idempotentNode || !ts.isStringLiteral(unwrap(idempotentNode))) {
    throw new Error(`${name}: registration carries no _meta.idempotent marker`);
  }

  const tool = {
    name,
    kind,
    description,
    idempotent: unwrap(idempotentNode).text,
    inputs: config.has('inputSchema') ? inputFields(config.get('inputSchema'), constants, name) : [],
  };

  if (kind === 'pro') {
    const gates = objectProperties(gatesArg, constants, name);
    for (const key of ['target', 'capabilityKey']) {
      const value = gates.get(key);
      if (!value || !ts.isStringLiteral(unwrap(value))) {
        throw new Error(`${name}: Pro registration carries no literal ${key} gate`);
      }
    }
    tool.target = unwrap(gates.get('target')).text;
    tool.capabilityKey = unwrap(gates.get('capabilityKey')).text;
  }

  return tool;
}

/**
 * Extract every registered tool from a set of `{ fileName, text }` sources. The
 * first source is the registration file; the rest supply constants it imports.
 *
 * Throws when it finds nothing, so a renamed helper or a moved registration
 * block fails loudly instead of regenerating an empty reference table.
 */
export function extractTools(sources) {
  const sourceFiles = sources.map((source) => parse(source.fileName, source.text));
  const constants = buildConstantTable(sourceFiles);

  const calls = [];
  (function visit(node) {
    if (ts.isCallExpression(node) && ts.isIdentifier(node.expression) && HELPER_KINDS.has(node.expression.text)) {
      calls.push(node);
    }
    ts.forEachChild(node, visit);
  })(sourceFiles[0]);

  if (calls.length === 0) {
    throw new Error(
      `no tool registration call sites found in ${sources[0].fileName} (refusing to emit an empty reference)`
    );
  }

  return calls.map((call) => toolFromCall(call, HELPER_KINDS.get(call.expression.text), constants));
}

function escapeCell(text) {
  return text.replace(/\|/g, '\\|');
}

/**
 * First sentence of a description, with an ellipsis when it continues past it.
 * The full text stays available in the tool's own MCP `description`; this
 * column exists so the table can be scanned.
 */
export function summarizeDescription(description) {
  const flat = description.replace(/\s+/g, ' ').trim();

  for (let index = 0; index < flat.length; index += 1) {
    if (!'.!?'.includes(flat[index])) continue;
    if (index + 1 < flat.length && flat[index + 1] !== ' ') continue;

    const preceding = flat.slice(0, index).split(' ').pop() ?? '';
    if (preceding.length === 1 || ABBREVIATIONS.has(preceding.toLowerCase())) continue;

    const sentence = flat.slice(0, index + 1);
    return escapeCell(index + 1 < flat.length ? `${sentence} …` : sentence);
  }

  return escapeCell(flat);
}

function renderInputs(inputs) {
  if (inputs.length === 0) return '_(none)_';
  return inputs.map((input) => `\`${input.name}${input.optional ? '?' : ''}\``).join(', ');
}

export function renderToolRow(tool, { pro }) {
  const cells = pro
    ? [`\`${tool.name}\``, `\`${tool.target}\``, `\`${tool.capabilityKey}\``]
    : [`\`${tool.name}\``, KIND_LABELS.get(tool.kind) ?? tool.kind];

  cells.push(renderInputs(tool.inputs), tool.idempotent, summarizeDescription(tool.description));
  return `| ${cells.join(' | ')} |`;
}

function byName(a, b) {
  return a.name.localeCompare(b.name);
}

export function renderAlwaysOnTable(tools) {
  const alwaysOn = tools.filter((tool) => tool.kind !== 'pro').sort(byName);
  if (alwaysOn.length === 0) {
    throw new Error('no always-on tools to render (refusing to emit an empty table)');
  }

  return [
    '<!-- BEGIN GENERATED:tool-reference:always-on -->',
    '',
    '### Always-on tools',
    '',
    '| Tool | Kind | Inputs | Idempotent | Summary |',
    '|---|---|---|---|---|',
    ...alwaysOn.map((tool) => renderToolRow(tool, { pro: false })),
    '',
    '<!-- END GENERATED:tool-reference:always-on -->',
  ].join('\n');
}

export function renderProTable(tools) {
  const pro = tools.filter((tool) => tool.kind === 'pro').sort(byName);
  if (pro.length === 0) {
    throw new Error('no Pro tools to render (refusing to emit an empty table)');
  }

  return [
    '<!-- BEGIN GENERATED:tool-reference:pro -->',
    '',
    '### Conditionally-registered Pro tools',
    '',
    'These appear on the MCP surface only when their gates are satisfied (see [Tools at a glance](#tools-at-a-glance)). The capability key is the plugin-side key the gate reads, which does not follow the tool name.',
    '',
    '| Tool | Target | Capability key | Inputs | Idempotent | Summary |',
    '|---|---|---|---|---|---|',
    ...pro.map((tool) => renderToolRow(tool, { pro: true })),
    '',
    '<!-- END GENERATED:tool-reference:pro -->',
  ].join('\n');
}

export function renderHeader({ plugin, serverLocal, pro }) {
  const alwaysOn = plugin + serverLocal;
  if (alwaysOn + pro === 0) {
    throw new Error('no tools to describe (refusing to emit a header claiming an empty surface)');
  }

  return [
    '<!-- BEGIN GENERATED:tool-reference:header -->',
    '',
    '## Per-tool reference',
    '',
    '> Generated mechanically by `diviops-server/scripts/regen-tool-reference.mjs` from the tool-registration call sites in `diviops-server/src/index.ts`. Everything between the `BEGIN GENERATED:tool-reference:*` / `END GENERATED:tool-reference:*` HTML-comment sentinels is rewritten on regen (see `diviops-server/CONTRIBUTING.md`). Do **not** edit between sentinels — edits are clobbered.',
    '',
    `${alwaysOn} always-on tools (${plugin} plugin-backed, ${serverLocal} server-local) and ${pro} conditionally-registered Pro tools.`,
    '',
    "**Inputs** lists each tool's top-level input fields in schema order; a trailing `?` marks a field the schema makes optional or gives a default, and `_(none)_` marks a tool that takes no arguments. **Idempotent** is the tool's own `_meta.idempotent` marker ([what the values mean](#_metaidempotent-markers)). **Summary** is the first sentence of the tool's MCP `description`, which is the full reference for its response payload and error codes; a trailing `…` marks a description that continues, and an `…` inside the text marks a value the server fills in at handshake time. Every tool returns the [standardized envelope](#response-contract).",
    '',
    '<!-- END GENERATED:tool-reference:header -->',
  ].join('\n');
}

function replaceSentinelRegion(content, sentinelId, replacement) {
  const pattern = new RegExp(
    `<!-- BEGIN GENERATED:${sentinelId} -->[\\s\\S]*?<!-- END GENERATED:${sentinelId} -->`
  );
  if (!pattern.test(content)) {
    throw new Error(`sentinel region not found: ${sentinelId}`);
  }
  return content.replace(pattern, replacement);
}

export function regenerateContent(content, tools) {
  if (tools.length === 0) {
    throw new Error('no tools supplied (refusing to regenerate the reference into an empty one)');
  }

  const counts = {
    plugin: tools.filter((tool) => tool.kind === 'plugin').length,
    serverLocal: tools.filter((tool) => tool.kind === 'server_local').length,
    pro: tools.filter((tool) => tool.kind === 'pro').length,
  };

  let result = replaceSentinelRegion(content, 'tool-reference:header', renderHeader(counts));
  result = replaceSentinelRegion(result, 'tool-reference:always-on', renderAlwaysOnTable(tools));
  return replaceSentinelRegion(result, 'tool-reference:pro', renderProTable(tools));
}

function main() {
  const tools = extractTools(loadSourceSet(ENTRY_FILE));
  const before = readFileSync(TARGET_FILE, 'utf8');
  const after = regenerateContent(before, tools);
  writeFileSync(TARGET_FILE, after, 'utf8');

  const counts = tools.reduce((acc, tool) => ({ ...acc, [tool.kind]: (acc[tool.kind] ?? 0) + 1 }), {});
  const driftNote = before === after ? 'no drift' : 'content changed — review before committing';
  console.log(
    `regen-tool-reference: ${tools.length} tool(s) — ${counts.plugin ?? 0} plugin, ` +
      `${counts.server_local ?? 0} server-local, ${counts.pro ?? 0} Pro — ${driftNote}.`
  );
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    main();
  } catch (error) {
    console.error(`regen-tool-reference: ${error.message}`);
    process.exitCode = 1;
  }
}
