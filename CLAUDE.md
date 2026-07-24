# Agent Instructions: rubicon/diviops

This file carries only what is unique to this repository. It does not restate the
maintainer's general policies; read those first.

- `~/.claude/CLAUDE.md`
- `~/.claude/policies/general-repository-process-policy.md`
- `~/.claude/policies/software-engineering-practices-policy.md`
- `~/.claude/policies/agent-orchestration-and-verification-policy.md`

Read `FORK.md` before changing anything. It explains why this fork exists, what
diverges from upstream, and what is deliberately out of scope.

## This is an upstream-tracked fork

`origin` is `rubicon/diviops`, `upstream` is `oaris-dev/diviops`. The repository
policy's upstream-tracked-fork overlay applies in full.

Upstream-owned files defer to upstream's conventions to minimize merge divergence:
`README.md`, `CONTRIBUTING.md`, `SECURITY.md`, `SUPPORT.md`, `SETUP.md`,
`.github/ISSUE_TEMPLATE/`, and everything under `plugins/`, `diviops-server/`, and
`skills/`. Do not restyle them to house standard. Fork-owned files (`FORK.md`, this
file, `AGENTS.md`, `.gitignore`, `.github/workflows/`, `tests/`) follow the house
standard in full.

Record every intentional modification to an upstream-owned file in `FORK.md`'s
divergence table, in the same PR that makes it.

## Never rename these four

Plugin slug `diviops-agent`, class `DiviOps_Agent`, REST namespace `diviops/v1`,
filter `diviops_agent_handshake_extensions`.

DiviOps Agent Pro (separate, commercial, not forked) attaches through
`class_exists( 'DiviOps_Agent' )` and that filter. The published
`@diviops/mcp-server` calls `/diviops/v1/*`. Renaming any of the four silently
disables Pro and makes MCP tools vanish without an error, because a failed capability
gate removes a tool rather than reporting a problem. The maintainer's default `rtv_`
vendor prefix does not apply here; see `FORK.md`.

## Upstream ships no tests

The plugin is roughly 24,000 lines across 17 PHP files with no test files and no CI
of any kind. There is no inherited safety net. Anything this fork touches needs
coverage written here.

```bash
php tests/run.php
```

No Composer, no PHPUnit, no build step. Test files are `tests/test-*.php`.

The runner fails when it discovers no test files, and when a filter matches nothing.
That guard is deliberate: a gate that reports what it inspected but derives pass or
fail only from problems-found will pass while inspecting nothing. That exact failure
happened three times on the predecessor repository. Any gate added here must assert
that it actually inspected something.

## Development environment

Local site: `/Users/daxdavis/Local Sites/colleyvillelions/app/public`

WP-CLI on that site needs the Local MySQL socket, and `display_errors=0` to keep a
PHP deprecation notice out of captured stdout:

```bash
SOCK="/Users/daxdavis/Library/Application Support/Local/run/6NaIbVmzy/mysql/mysqld.sock"
php -d display_errors=0 -d mysqli.default_socket="$SOCK" /opt/homebrew/bin/wp \
  --path="/Users/daxdavis/Local Sites/colleyvillelions/app/public" <command>
```

To test this fork on that site, swap it for the installed stock `diviops-agent`.
Keep the stock copy so the swap is reversible, and confirm afterwards that Pro still
attaches by checking the handshake still reports its capability keys.

## Site constraints

- Do not modify or publish page 900390. Reading it is fine, and it is the reference
  page for third-party module behavior: it carries `difl/*`, `d5bgo/*`, and a
  `divi/global-layout` wrapper.
- Verify with Dax before writing any change to the local site, including plugin
  activation and scratch page creation.
- Do not reactivate the legacy ANTHEM child theme or activate the Divi parent theme
  directly.
