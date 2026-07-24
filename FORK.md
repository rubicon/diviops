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
| `plugins/diviops-agent/diviops-agent.php` | Adds namespace-agnostic block-comment constants (`BLOCK_OPEN_PREFIX`, `BLOCK_CLOSE_PREFIX`, `BLOCK_NAME_PATTERN`, `DEFAULT_BLOCK_NS`). The `divi/`-specific `SECTION_OPEN`, `SECTION_CLOSE`, and `BLOCK_PREFIX` constants are retained, unused, because they are public class constants external code may reference (#2). Adds `GLOBAL_LAYOUT_BLOCK_NAME` for the `divi/global-layout` wrapper name every counting site checks against (#13). | #2, #13 |
| `plugins/diviops-agent/includes/trait-core.php` | Adds the `block_identifier_from_name()` / `block_name_from_identifier()` pair that defines the targeting-identifier contract, plus `next_block_opener()`. Makes the write-safety marker census, the marker-sequence validator, and block-attr normalization namespace-aware (#2). `block_opener_is_self_closing()` delegates to `block_opening_comment_end()` instead of a raw `strpos` for `-->`, so it no longer misreads a container as self-closing when an attribute value contains a `/-->`-shaped sequence (#6). Adds `counted_block_name()` / `counted_block_identifier()`, which resolve a `divi/global-layout` wrapper's counted type from its own `attrs.blockName` (falling back to the wrapper's literal name when that attr is absent), so every counting site agrees with what `page_get_layout` counts the wrapper as on read (#13). | #2, #6, #13 |
| `plugins/diviops-agent/includes/trait-page.php` | Namespace-agnostic raw scanners in `module_update()` and `find_block()`, `*/section` matching in `find_all_sections()`, shared identifier derivation in `parse_block_tree()` and `walk_and_mutate()`, and namespace-agnostic parser-backed collectors for the `module_get` / `module_move` fallbacks (#2). Adds `block_opening_comment_end()`, a JSON-string-aware scan that keeps `find_block()` from truncating a module's span when a `-->` appears inside one of its attribute values (#5). Routes the remaining raw `strpos($content, '-->', $pos)` sites through that same helper: `find_block()`'s own container depth-scan, `module_update()`'s attribute-span scan, `extract_attrs_from_block_markup()`, and both the opening-comment scan and depth-scan in `find_all_sections()` — closing the class of bug where a descendant module's attribute JSON contains an ancestor's closing comment, or a block's own attribute JSON contains a `-->` (#6). `find_block()`, `module_update()`'s inline scanner, `find_all_sections()`, `parse_block_tree()`, and `walk_and_mutate()` all route their type/section resolution through `counted_block_name()` / `counted_block_identifier()`, so a `divi/global-layout` wrapper counts as the type it resolves to instead of counting literally as `global-layout:N` (#13). | #2, #5, #6, #13 |
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

### Fixed: the `divi/global-layout` index divergence

`page_get_layout` walks the parsed block tree and relabels a `global-layout`
wrapper as the `blockName` it resolves to, counting it as `section:1`. The
raw-string scanners and parsed-tree walkers behind `module_update`, `module_get`
(via `find_block()`), the section operations (via `find_all_sections()`), and
`module_lock` / `module_unlock` / `module_clone` (via `walk_and_mutate()`) used to
count the wrapper literally as `global-layout:1` instead, so every real block after
it landed one `auto_index` position off from what the read path reported. Verified
live against reference page 900390.

Fixed in #13 by resolving the wrapper's counted type from its own
`attrs.blockName` — the wrapper carries the type it resolves to right in its own
opening comment (`{"globalModule":"900296","blockName":"divi/section",...}`), so
reading that attr is enough to agree with the read path. `counted_block_name()` /
`counted_block_identifier()` in trait-core.php are the shared resolution; every
site above routes through them. This is a string/attrs-level fix with no
`parse_blocks()` / `serialize_blocks()` round-trip and no new write blast-radius,
which is what the original scoping had assumed a fix would require.

`module_move`'s parser-backed collector (`collect_parser_move_blocks()`) and
`module_get`'s parser-backed fallback (`collect_readable_divi_blocks()`, reached
only after the raw scanner already fails on malformed markup) were not brought
into #13's fix and still count a wrapper literally. Flagged as a follow-up rather
than folded in silently.

### Still out of scope: the `module_lock` / `module_unlock` / `module_clone` write hazard

These operations parse a page's full content with `parse_blocks()`, mutate the
parsed tree, and write it back with `serialize_blocks()`. Re-serializing the whole
tree appears to risk materializing a `global-layout` reference into page-local
content — a data-integrity hazard independent of the numbering question #13 fixed.
Tracked in #11.

Third-party targeting does not depend on either of the above: third-party counters
are per block name and are unaffected by the wrapper.

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
