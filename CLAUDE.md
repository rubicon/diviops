# Agent Instructions: rubicon/diviops

This file carries only what is unique to this repository. It does not restate the
maintainer's general policies; read those first.

- `~/.claude/CLAUDE.md`
- `~/.claude/policies/general-repository-process-policy.md`
- `~/.claude/policies/software-engineering-practices-policy.md`
- `~/.claude/policies/agent-orchestration-and-verification-policy.md`

Read `FORK.md` before changing anything. It explains why this fork exists, what
diverges from upstream, and what is deliberately out of scope.

## This is a maintained fork (we own it)

`origin` is `rubicon/diviops`, `upstream` is `oaris-dev/diviops`. Owner decision
(2026-07-27): this is a **maintained fork we own** — we set the version, roadmap,
README, skills, and release process. Upstream syncs from a private dev repo, takes no
outside PRs, and is treated as an occasional sync source, not something we actively
track. We are not bound to minimize divergence from upstream. See `FORK.md`'s
"Maintained-fork posture".

Two things stay non-negotiable regardless: the four frozen identifiers (below; Pro +
MCP compatibility) and honoring the fork base's license + attribution to `oaris-dev`.
Files that originated upstream (`plugins/`, `diviops-server/`, `skills/`, `README.md`,
etc.) may be edited or taken over as needed; record intentional changes to them in
`FORK.md`'s divergence table (kept as history + a cherry-pick reconciliation aid, not a
divergence-minimization constraint).

Versioning: standard rubicon release-please automation + `CHANGELOG.md` + signed
`vX.Y.Z` releases (this is a GitHub/rubicon repo; upstream ships no release automation
to collide with). Each feature is a Conventional Commit; release-please computes the
bump and curates the changelog on a release PR. Do NOT hand-bump the version or
hand-write `CHANGELOG.md` — release-please owns both. The version lives in two spots in
`diviops-agent.php` (header `Version:` + `const VERSION`), both carrying release-please
markers; `tests/test-version-sync.php` guards that they stay in sync.

## Never rename these four

Plugin slug `diviops-agent`, class `DiviOps_Agent`, REST namespace `diviops/v1`,
filter `diviops_agent_handshake_extensions`.

DiviOps Agent Pro (separate, commercial, not forked) attaches through
`class_exists( 'DiviOps_Agent' )` and that filter. The published
`@rubicontv/diviops-mcp` calls `/diviops/v1/*`. Renaming any of the four silently
disables Pro and makes MCP tools vanish without an error, because a failed capability
gate removes a tool rather than reporting a problem. The maintainer's default `rtv_`
vendor prefix does not apply here; see `FORK.md`.

## Upstream ships no tests

The plugin is roughly 24,000 lines across 17 PHP files with no test files and no CI
of any kind. There is no inherited safety net. Anything this fork touches needs
coverage written here.

Layout: `plugins/diviops-agent/includes/trait-*.php` — one file per REST capability
domain (canvas, page, preset, variable, menu, theme-builder, etc.), mixed into the
main `DiviOps_Agent` class in `plugins/diviops-agent/diviops-agent.php`.

```bash
php tests/run.php
```

No Composer, no PHPUnit, no build step. Test files are `tests/test-*.php`.

The runner fails when it discovers no test files, and when a filter matches nothing.
That guard is deliberate: a gate that reports what it inspected but derives pass or
fail only from problems-found will pass while inspecting nothing. That exact failure
happened three times on the predecessor repository. Any gate added here must assert
that it actually inspected something.

## DiviOps Agent Pro artifacts in `upstream-releases/`

`upstream-releases/` is a local archive of published DiviOps releases, ignored by
git except its `README.md`. The free distributions (`diviops-suite-*`,
`diviops-agent-*`, `diviops-design-library-*`) are MIT. The Pro distributions
(`diviops-pro-suite-*`, `diviops-agent-pro*`) are a third party's commercial
plugin this project neither forks nor licenses.

**Reading a Pro artifact is allowed.** The clean-room rule that forbade it was
rescinded by the owner on 2026-09-02: "I'm rescinding the rule about the clean
room. Let's take it a different way. Any time we are stepping on the Pro plugin,
let me know." Three rules survive it.

**1. Never commit a Pro artifact, or Pro source, anywhere in this repository.**
`rubicon/diviops` is **public**, so committing one publishes a third party's
proprietary code under our name — a licensing incident, not a tidiness problem.
`tests/test-upstream-releases-untracked.php` fails the suite if anything under
`upstream-releases/` other than `README.md` is tracked, and fails separately if
any path segment anywhere in the repository is Pro-named. Neither assertion was
ever about reading, and neither is relaxed. The same reasoning rules out pointing
codebase-memory or any other index at a Pro artifact: an index is a copy.

**2. Do not transcribe Pro source into this repository.** Reading it to
understand a behaviour is fine; pasting or paraphrasing its code is not. This
fork ships GPL-2.0-or-later plugins and MIT tooling, and neither license is ours
to grant over someone else's commercial code. Write from understanding, in this
codebase's own shapes, and cite a primary source you are licensed to use
(Divi's own PHP, the free distributions, `upstream/main`, observed REST
behaviour) when documenting a fact.

**3. Surface Pro overlap before building, not after.** When a task would
reimplement something Pro already does, say so to Dax first and let him decide.
That is the replacement for the clean-room rule, and it is the point of being
allowed to read Pro at all.

Installing the Pro plugin on the dev site to confirm it still attaches through
`class_exists( 'DiviOps_Agent' )` and `diviops_agent_handshake_extensions`
remains the routine reason to have it on disk.

## Development environment

Local site: `staging.colleyvillelions.com:/home/rdeepmh/public_html/staging.colleyvillelions.com`

That line is not prose — `scripts/lib/local-site.php` parses it, and it is the only
thing telling the deploy and the drift gate which site they mean. The `host:path` form
means the site is reached over SSH; a bare path means a site on this machine. Delete
or misspell it and the gate skips instead of checking, which
`tests/test-local-site-drift.php` asserts against.

The value is the **webroot**, which is not `~/public_html` — that path also exists,
and a guess one level too shallow returns nothing and reads as "not installed" rather
than as a wrong path. The plugin lives at
`<webroot>/wp-content/plugins/diviops-agent`; `diviops-agent-pro` and
`diviops-design-library` sit beside it and are **never** a deploy target.

`~/.ssh/config` carries a `Host staging.colleyvillelions.com` entry, so
`ssh -o BatchMode=yes staging.colleyvillelions.com` connects without a prompt. The
host has `/usr/bin/rsync` (3.1.3), `php` 7.4 and `wp` on `PATH` under a non-login
command, so WP-CLI runs directly:

```bash
ssh staging.colleyvillelions.com \
  'cd /home/rdeepmh/public_html/staging.colleyvillelions.com && wp <command>'
```

Deploy this fork onto it with `scripts/deploy-local-site.sh`, which takes a
timestamped backup on the host first. Confirm afterwards that Pro still attaches by
checking the handshake still reports its capability keys.

**The LocalWP install at `/Users/daxdavis/Local Sites/colleyvillelions/app/public` is
retired.** It no longer serves — `https://colleyvillelions.local` refuses the
connection — but its directory survived on disk, still holding a plugin copy. That is
precisely why this had to be fixed rather than left: the drift gate went on comparing
against it and reporting `current`, which renders identically to certifying the site
work actually happens on (#340). Pointing `DIVIOPS_LOCAL_SITE` at a local path still
works if that install is ever revived.

## Site constraints

These apply to **staging**, and to any other install this repository is pointed at.
They were written for the LocalWP site and are not local-only: page 900390 exists on
staging carrying the same layout, and a mistake there is on a host other people reach.

- Do not modify or publish page 900390. Reading it is fine, and it is the reference
  page for third-party module behavior: it carries `difl/*`, `d5bgo/*`, and a
  `divi/global-layout` wrapper.
- Verify with Dax before writing any change to the site, including plugin
  activation, a deploy, and scratch page creation. A dry-run comparison — what the
  drift gate and `scripts/deploy-local-site.sh` do before they write anything — is a
  read and needs no verification.
- Do not reactivate the legacy ANTHEM child theme or activate the Divi parent theme
  directly.
