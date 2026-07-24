# FORK.md

This repository is a fork of [oaris-dev/diviops](https://github.com/oaris-dev/diviops).
This file is fork-owned. Upstream does not have it, so it never collides on a merge,
and it is the single record of how this fork differs and why.

## Why this fork exists

DiviOps Agent hardcodes the `divi/` block namespace throughout its block-targeting
code. `page_get_layout` is namespace-agnostic and will happily report a third-party
module as `difl/faq:1`, but every targeting path then refuses that same identifier.
The result is a read/write asymmetry: the plugin hands you a target it will not
accept.

The practical cost is that editing a third-party Divi 5 module's attributes requires
hand-reconstructing raw block markup. On a real page that corrupted 414
`$variable({...})$` tokens, which made the plugin's own validator reject every
subsequent write to that page.

This fork makes block targeting namespace-agnostic so third-party modules
(`difl/*`, `decm/*`, `d5bgo/*`) can be addressed by every operation, not just read.

A companion "bridge" plugin was built and abandoned first. It intercepted REST
responses and repaired them from outside. It was retired because it can only fix one
endpoint family at a time and structurally cannot fix Theme Builder insert, which
rejects third-party content with a 400 before any response a bridge could see.

## The drop-in constraint

**Four identifiers must never change.** They are the entire reason this fork loses
nothing relative to running stock DiviOps:

| Identifier | Value |
| ---------- | ----- |
| Plugin slug | `diviops-agent` |
| Main class | `DiviOps_Agent` |
| REST namespace | `diviops/v1` |
| Handshake filter | `diviops_agent_handshake_extensions` |

DiviOps Agent Pro is a separate commercial plugin that is **not** forked. It attaches
by calling `class_exists( 'DiviOps_Agent' )` and hooking
`diviops_agent_handshake_extensions`. The published `@diviops/mcp-server` npm package
calls `/diviops/v1/*` routes and gates each tool on the capability keys the handshake
returns.

Rename any of the four and Pro silently stops contributing its capabilities, and MCP
tools silently disappear rather than erroring, because the server's design is
"capability gate fails, tool is simply absent." The breakage is silent. Treat these
as frozen.

This is a deliberate exception to the maintainer's default `rtv_` vendor-prefix rule.
Preserving upstream identity is required for compatibility.

## Divergence from upstream

Fork-owned files, added here, absent upstream. These never conflict on merge:

| Path | Purpose |
| ---- | ------- |
| `FORK.md` | This file |
| `CLAUDE.md` | Fork-owned agent instructions |
| `AGENTS.md` | Pointer stub to `CLAUDE.md` |
| `.gitignore` | Upstream ships none; ignores `worktrees/` and OS junk |
| `.github/workflows/test.yaml` | Upstream ships no CI |
| `tests/` | Upstream ships no tests |

Modified upstream files. Each carries fork changes that must be reconciled by hand on
an upstream merge:

| Path | What diverges | Issue |
| ---- | ------------- | ----- |
| `plugins/diviops-agent/diviops-agent.php` | Adds namespace-agnostic block-comment constants (`BLOCK_OPEN_PREFIX`, `BLOCK_CLOSE_PREFIX`, `BLOCK_NAME_PATTERN`, `DEFAULT_BLOCK_NS`). The `divi/`-specific `SECTION_OPEN`, `SECTION_CLOSE`, and `BLOCK_PREFIX` constants are retained, unused, because they are public class constants external code may reference. | #2 |
| `plugins/diviops-agent/includes/trait-core.php` | Adds the `block_identifier_from_name()` / `block_name_from_identifier()` pair that defines the targeting-identifier contract, plus `next_block_opener()`. Makes the write-safety marker census, the marker-sequence validator, and block-attr normalization namespace-aware. | #2 |
| `plugins/diviops-agent/includes/trait-page.php` | Namespace-agnostic raw scanners in `module_update()` and `find_block()`, `*/section` matching in `find_all_sections()`, shared identifier derivation in `parse_block_tree()` and `walk_and_mutate()`, and namespace-agnostic parser-backed collectors for the `module_get` / `module_move` fallbacks. | #2 |
| `plugins/diviops-agent/includes/trait-module-schema.php` | Adds `is_divi_module_block()` so schema listing and dumping recognize third-party Divi modules, and accepts a namespaced name in `schema_get_module()`. | #2 |
| `plugins/diviops-agent/includes/trait-theme-builder.php` | Theme Builder insert accepts any namespaced block, `parse_tb_parent_selector()` accepts any namespace, the cross-env preset and attachment scanners no longer skip third-party blocks, and malformed-comment detection covers every namespace. | #2 |

### Deliberately unchanged: `post_uses_divi()` / `content_uses_divi()`

These decide "is this Divi content" on the literal substring `<!-- wp:divi/`, and issue
#2's inventory lists them. They are intentionally left alone. Loosening them to accept
any namespace would misclassify ordinary Gutenberg pages as Divi content, because
unrelated third-party blocks (`gravityforms/*`, `pdfemb/*`, `tec/*`) are registered
alongside the Divi ones. The check is also not actually failing: across all 108 posts
on the reference install that carry `difl/*` or `d5bgo/*` blocks, every one also
contains a `<!-- wp:divi/` marker and every one opens with a `divi/` block, because
third-party modules nest inside Divi sections rather than replacing them.

## Upstream tracking

```bash
git remote -v
# origin    git@github.com:rubicon/diviops.git
# upstream  https://github.com/oaris-dev/diviops.git

git fetch upstream
git merge upstream/main        # through the normal issue, branch, PR flow
```

Upstream-owned files (`README.md`, `CONTRIBUTING.md`, `SECURITY.md`, `SUPPORT.md`,
`SETUP.md`, `.github/ISSUE_TEMPLATE/`, everything under `plugins/`,
`diviops-server/`, and `skills/`) defer to upstream's conventions. Do not restyle
them to house standard; that only creates merge conflicts. Fork instructions that
contradict upstream's docs belong in this file or `CLAUDE.md`, never inline in an
upstream-owned doc.

## Deliberately out of scope

**The `divi/global-layout` index divergence.** `page_get_layout` walks the parsed
block tree and relabels a `global-layout` wrapper as the `blockName` it resolves to,
counting it as `section:1`. The raw-string scanners behind `module_update`,
`module_get`, and `module_move` count the wrapper literally as `global-layout:1`, so
every `divi/section` index after it is off by one. Verified live against a real page.

This is a genuine upstream defect, but it is not this fork's first problem to solve.
Fixing it means routing writes through `parse_blocks()` and `serialize_blocks()`,
which re-serializes the whole tree and appears to risk materializing a
`global-layout` reference into page-local content. That is a data-integrity hazard
larger than the numbering symptom, and it already exists in `module_lock`,
`module_unlock`, and `module_clone`. It gets its own investigation.

Third-party targeting does not depend on it: third-party counters are per block name
and are unaffected by the wrapper.

## Licensing

The WordPress plugins are GPL-2.0-or-later, stated in the upstream `LICENSE`, in each
plugin header, and in `readme.txt`. Forking and modifying them is expressly
permitted. The MCP server, skills, templates, and docs in this repository are MIT.
Both notices are preserved as upstream wrote them.

## Contributing back

The namespace-agnostic change is a generic fix that benefits every DiviOps user, not
just this fork. The intent is to offer it upstream as a pull request once it is
proven here. An upstream PR is gated on the maintainer's explicit per-PR approval and
follows the Outbound PR Authorship Standard.
