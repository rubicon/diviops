# cisco-ai-defense/mcp-scanner evaluation (issue #127) — design spec

Date: 2026-08-13
Issue: [#127](https://github.com/rubicon/diviops/issues/127)
Status: evaluation complete — recommendation pending owner review

## Revision history

- 2026-08-13, initial evaluation. Scanner run for real against a locally built
  `diviops-server` over stdio with throwaway credentials. Recommendation: **decline**
  CI adoption, keep the reproduction documented here as an optional manual check.

## Summary

The scanner **does apply** to `diviops-server`, contrary to the concern that a
stdio-only server needing live WordPress credentials could not be introspected. It ran
end to end and enumerated all 115 always-on tools.

The result is 7 HIGH findings out of 115 tools, and **all 7 are false positives**. Each
one is attributable to a specific regex in the scanner's own bundled YARA rules, and
the attribution was confirmed mechanically against all 115 descriptions with zero
mismatches. There are no true positives, and the analyzer produced no finding that the
existing CI lanes do not already cover or that a PR reviewer would not already see.

Recommendation: **decline** adding it to CI. Details and reasoning below.

## Verified facts (each traceable to a command run during this evaluation)

### The scanner

| Fact | Value | Source |
| ---- | ----- | ------ |
| Repository | `cisco-ai-defense/mcp-scanner` | `gh api repos/cisco-ai-defense/mcp-scanner` |
| Licence | Apache-2.0 | same |
| Language | Python (requires 3.11+, `uv`) | same, plus the project README |
| Stars / forks / open issues | 1031 / 133 / 51 | same, 2026-08-13 |
| Created / last push | 2025-09-24 / 2026-08-07 | same |
| Archived | no | same |
| Release cadence | 4.8.3 (2026-08-07), 4.8.2 (2026-07-30), 4.8.1 (2026-07-21), 4.8.0 (2026-07-14) | `gh api .../releases` |
| Commits since 2026-05-15 | 25 | `gh api .../commits?since=` |
| Version exercised here | 4.8.3 | `cisco_ai_mcp_scanner-4.8.3.dist-info` in the throwaway install |

Actively maintained: four releases in the last month and a push six days before this
evaluation.

### Analyzers and where data goes

The issue described three analyzers. Version 4.8.3 ships more than that. Split by
whether anything leaves the machine:

| Analyzer | Key required | Data leaves the machine |
| -------- | ------------ | ----------------------- |
| `yara` | no | no |
| `readiness` | no | no |
| `prompt_defense` | no | no |
| `vulnerable_package` (pip-audit) | no | no |
| `llm` (LLM-as-judge) | `MCP_SCANNER_LLM_API_KEY` | yes — descriptions to the LLM provider |
| `behavioral` (source-code analysis) | `MCP_SCANNER_LLM_API_KEY` | yes — source to the LLM provider |
| `api` (Cisco AI Defense) | paid Cisco key | yes — to Cisco's cloud |
| `virustotal` | `VIRUSTOTAL_API_KEY` | yes — file hashes |

Only the first group is usable under this repository's constraint that no source or
repository content is sent to a third-party service. Everything below was run with
`--analyzers` restricted to that group.

Two capabilities the issue listed as not applicable have since appeared but are still
out of reach: `behavioral` now covers TypeScript, and there is an `npm-scan`
subcommand — but `behavioral` needs an LLM key (so it would ship our source off-box),
and `npm-scan` refused to start without Docker:

```
$ mcp-scanner --analyzers yara npm-scan @diviops/mcp-server --version 1.6.1
Error: Docker is installed but not running.
```

**Not run.** It sandboxes the published package by executing it in a container, which
is not something to start unattended during an evaluation.

### `diviops-server` — the facts that decide applicability

- Transport is stdio only. `src/index.ts` imports `StdioServerTransport` (line 14) and
  instantiates exactly one at line 8093. No `SSEServerTransport` and no
  `StreamableHTTPServerTransport` anywhere in `src/`. The scanner's `remote` subcommand
  is therefore inapplicable; its `stdio` subcommand is the only usable entry point.
- `requireCredentials()` (line 8014) calls `process.exit(1)` unless all three of
  `WP_URL`, `WP_USER`, `WP_APP_PASSWORD` are set. Credentials are mandatory to *start*.
- Credentials are **not** validated at startup. A failed handshake is caught and
  downgraded to `handshakeState = { kind: "failed" }` with a warning on stderr
  (line 8079); the server continues, installs the registry, and connects the transport.
- Therefore the server can be scanned with throwaway credentials pointing at a dead
  address, with no live WordPress site involved. Confirmed with a hand-rolled JSON-RPC
  probe before involving the scanner at all:

```
$ env WP_URL=http://127.0.0.1:1 WP_USER=probe WP_APP_PASSWORD=probe node dist/index.js
Handshake warning (gate disabled): fetch failed
Divi MCP Server running on stdio
→ tools/list returns 115 tools
```

- Scan coverage is the 115 always-on tools only. The 30 `registerProTool` registrations
  are gated behind `handshakeState.kind === "ok"` (line 560), so a credential-free scan
  structurally cannot see them. Counts verified by `grep -c` in `src/index.ts`:
  115 `registerPluginTool(`/`registerLocalTool(` and 30 `registerProTool(`.
- `npm run build` succeeds from a clean `npm ci` in this worktree, so there is a real
  `dist/index.js` to scan. (This was #127's stated blocker via #41; #41 closed
  2026-08-01.)

### Existing security posture, re-verified rather than assumed

- Required status checks on `main`: `PHP syntax (7.4)`, `PHP syntax (8.3)`,
  `Test suite`, `diviops-server security tests (node 22)`,
  `diviops-server security tests (node 24)`
  (`gh api repos/rubicon/diviops/branches/main/protection`).
- PHPStan runs as a CI job in `.github/workflows/test.yaml`, but is not a required
  check.
- CodeQL default setup is `configured` for `actions`, `javascript`,
  `javascript-typescript`, `typescript`
  (`gh api repos/rubicon/diviops/code-scanning/default-setup`). It does not cover PHP.
- `npm audit` in `diviops-server`: **0 vulnerabilities** across 99 dependencies
  (94 prod, 6 dev), on Node v22.22.3.

## What was run, and the real output

Ephemeral, no global install, no dependency added to `diviops-server/package.json`:

```bash
cd diviops-server
npm ci && npm run build

uvx --python 3.13 --from cisco-ai-mcp-scanner mcp-scanner \
  --analyzers yara --format summary --stats \
  stdio --stdio-command node --stdio-arg dist/index.js \
  --stdio-env WP_URL=http://127.0.0.1:1 \
  --stdio-env WP_USER=scanner-probe \
  --stdio-env WP_APP_PASSWORD=scanner-probe
```

`http://127.0.0.1:1` is a closed local port: the handshake fails on connect, nothing
leaves the machine, and no WordPress site is contacted.

```
=== Scan Statistics ===
Total tools: 115
Safe tools: 108
Unsafe tools: 7
Severity breakdown: {'HIGH': 7, 'UNKNOWN': 0, 'MEDIUM': 0, 'LOW': 0, 'SAFE': 108}

=== Unsafe Items ===
1. diviops_global_font_update (tool) - HIGH (1 findings)
2. diviops_theme_options_update (tool) - HIGH (1 findings)
3. diviops_rollback_snapshot_get (tool) - HIGH (1 findings)
4. diviops_variable_update (tool) - HIGH (1 findings)
5. diviops_variable_used_on_page (tool) - HIGH (1 findings)
6. diviops_cross_env_target_context_get (tool) - HIGH (1 findings)
7. diviops_cross_env_source_export_get (tool) - HIGH (1 findings)
```

Five are classified `PROMPT INJECTION` / `AITech-1.1 Direct Prompt Injection`; two are
`CREDENTIAL HARVESTING` / `AITech-8.2 Data Exfiltration`.

## All 7 findings are false positives, with the rule cited

The findings were not dismissed by inspection. The scanner's bundled rules were read
directly from the installed package and the two responsible patterns were applied to
all 115 descriptions independently.

**`coercive_injection.yara`, string `$execution_overrides`** — accounts for 5 findings:

```
/\b(do not execute[^\n]*other[^\n]*tool|must[^\n]*this tool|only[^\n]*this tool|tool[^\n]*will not work)\b/i
```

A tool description is a single line, so `[^\n]*` spans the entire description with no
distance bound. Any description containing the word "only" or "must" *anywhere* and the
phrase "this tool" *anywhere* matches. What actually tripped each one:

| Tool | Matched text |
| ---- | ------------ |
| `diviops_global_font_update` | "must reference an existing record … " through to "via this tool" |
| `diviops_theme_options_update` | "only these 9 allowlisted keys …" through to "by this tool" |
| `diviops_rollback_snapshot_get` | "only when the referenced target still exists …" through to "in this tool" |
| `diviops_variable_update` | "must reference an existing variable …" through to "by this tool" |
| `diviops_variable_used_on_page` | "only — color variables …" through to "this tool" |

Every one is ordinary precision about an allowlist or a strict-update contract. None is
an instruction aimed at the agent's behavior.

Independent bisection confirmed the mechanism before the rule was read: removing only
the sentence "No restore path in this tool." from `diviops_rollback_snapshot_get` turns
it SAFE, while that sentence *alone* is also SAFE — exactly the two-fragment,
unbounded-distance behavior the regex describes.

**`credential_harvesting.yara`, string `$leak_param`** — accounts for 2 findings:

```
/\b(leak|exfiltrate|export|dump) [^\n]*(parameter|context|files?|credentials?|keys?|tokens?|secrets?)\b/i
```

Both `diviops_cross_env_*` descriptions open with "Export read-only, **secret**-free
… **context**". The phrase that trips the credential-harvesting rule is the phrase
promising the tool returns no secrets.

**Attribution check.** Both patterns were applied verbatim to all 115 descriptions and
compared against what the scanner reported: 7 predicted unsafe, 7 actually unsafe,
**0 mismatches** across 115 tools. The two regexes explain the entire finding set. No
finding is left unaccounted for, so there is no residual "maybe one of these is real."

**Do not fix the descriptions.** The obvious way to a clean scan is to reword accurate
documentation until the regex stops matching — dropping "only", or renaming
"secret-free". That trades real precision an agent relies on for a green badge, and it
is the wrong trade. The descriptions are correct; the rule is loose.

This class of false positive is known upstream:
[cisco-ai-defense/mcp-scanner#235](https://github.com/cisco-ai-defense/mcp-scanner/issues/235)
reports YARA firing on forceful-but-legitimate API documentation, and
`credential_harvesting.yara` carries an in-file comment describing a previous
false-positive fix to the same family of patterns.

## The other credential-free analyzers have no discrimination here

| Analyzer | Result | Usable |
| -------- | ------ | ------ |
| `yara` | 7/115 HIGH, all false positives | marginal |
| `readiness` | **115/115 HIGH**, 7–8 findings each | no |
| `prompt_defense` | **115/115 HIGH**, 11–12 findings each | no |

`readiness` flags every tool that "does not specify a timeout"; `prompt_defense` flags
every description that does not contain counter-instructions against its 12 attack
vectors. A check that flags 100% of inputs carries no information. Both also break this
repository's own gate rule from `CLAUDE.md` — a gate must be able to distinguish, not
just report.

(`prompt_defense` additionally printed a self-contradictory tally,
`{'HIGH': 115, …, 'SAFE': 115}` for 115 tools, which is an accounting bug in the stats
output.)

## Operational blockers for CI, independent of finding quality

1. **Exit code is 0 with 7 HIGH findings present.** Verified directly. The scanner is
   not a gate out of the box; CI would have to capture `--format raw` and apply its own
   pass/fail policy.
2. **`--output <file>` writes nothing.** Verified on both the `stdio` and `static`
   paths in 4.8.3 — no file is created and no error is raised. Results must be captured
   from stdout.
3. **The server's own stderr is interleaved into the scan output** ("Handshake warning
   …", "Divi MCP Server running on stdio"), so any CI parser has to tolerate it.
4. **New toolchain.** CI is Node + PHP. This adds Python 3.13 and `uv`, for one check.
5. **Coverage is partial by construction.** The 30 Pro-gated tools cannot be scanned
   without a live authenticated site, so a green result would never mean "the whole tool
   surface is clean" — and saying it did would be a false claim.
6. **Static-mode output mislabels the target** as `https://mcp.deepwiki.com/mcp`
   regardless of what was scanned — cosmetic, but it makes an archived scan artifact
   self-contradicting.

## What it adds beyond the existing pipeline

Honestly: one real thing. CodeQL, PHPStan, the WP-CLI allowlist suite, and `npm audit`
all look at code and dependencies. None of them looks at the **tool-description text**
an agent reads, which is a genuinely different threat model, and #127 is right that a
published npm package other people point their agents at deserves that attention.

But the realized value of that attention here is close to zero:

- The descriptions are authored in `src/index.ts`, in this repository, and reach `main`
  only through a reviewed PR. There is no path by which adversarial description text
  arrives without a human reading the diff.
- The YARA rules detect crude signature-level injection ("always run this tool first",
  "read the contents of ~/.ssh"). Anything that survives PR review would not look like
  that.
- On this codebase the analyzer's measured output is 0 true positives and 7 false
  positives, and the two other credential-free analyzers flag everything.

A recurring check whose only observed output is noise trains the reader to ignore it,
which is worse than not having it.

## Recommendation

**Decline.** Do not add mcp-scanner to CI, as a required or advisory lane.

Do keep the reproduction above. It is cheap (one `uvx` invocation, nothing installed,
nothing transmitted) and worth re-running by hand if the tool-description surface ever
changes shape substantially — a large batch of new tools, or descriptions authored
somewhere other than a reviewed PR. The recorded baseline is: **115 tools, 7 HIGH, all
attributable to `$execution_overrides` and `$leak_param`.** A future run that produces
a finding *not* explained by those two patterns is the signal worth acting on.

Revisit if any of these change:

- the scanner grows a suppression/baseline mechanism and a findings-based exit code,
  removing blockers 1 and 2;
- the two loose patterns are tightened upstream, so a clean run becomes achievable
  without degrading our documentation;
- `diviops-server` grows an HTTP/SSE transport, which would widen the attack surface
  the scanner is actually designed for.

### Optional follow-up, not part of this issue

The two false-positive patterns are worth reporting upstream — an unbounded
`only[^\n]*this tool` will fire on a large share of well-documented MCP servers, and
`$leak_param` matching "export … secret-free" penalizes servers that state their
safety properties. That would be an outbound contribution under the Outbound PR
Authorship Standard and needs its own issue and explicit approval; it is not proposed
here.

## What was deliberately not done

- No global install. The scanner ran via `uvx` (ephemeral) plus one throwaway
  `uv pip install --target` into a scratch directory, used solely to read the bundled
  `.yara` rule files.
- No dependency added to `diviops-server/package.json`.
- Nothing sent to any third-party service. `llm`, `behavioral`, `api`, and
  `virustotal` were never enabled, and no API key of any kind was configured.
- No contact with the local WordPress site, and no live MCP server process was scanned.
  The scan targeted a `dist/` built inside this worktree, with credentials pointing at a
  closed local port.
- `npm-scan` was not run (Docker not available, and container-sandboxed execution of
  the published package is not an unattended-evaluation activity).
