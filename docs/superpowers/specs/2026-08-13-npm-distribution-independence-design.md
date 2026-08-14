# npm distribution independence — design spec

Date: 2026-08-13
Related: [#142](https://github.com/rubicon/diviops/issues/142) (this spec),
[oaris-dev/diviops#7](https://github.com/oaris-dev/diviops/issues/7) (upstream's open
multi-site feature request, third-party, unaddressed since 2026-06-05)
Status: design — pending owner review

## Revision history

- 2026-08-13, initial draft.
- 2026-08-13, revised after verifying npm's current publishing mechanics against official
  documentation and after finding a working in-house precedent (`rubicon/forgejo-mcp`).
  Four material changes: the `@rubicontv` scope turned out to be **already owned**, which
  removed the spec's largest open prerequisite; the credential recommendation changed from
  "store a token" to a **two-phase bootstrap-then-OIDC** path, because npm classic
  automation tokens were permanently revoked on 2025-12-09 and the surviving token class is
  itself on a documented removal path; a **token-free smoke gate** was promoted from an
  unmentioned detail to a first-class work item requiring a source change; and a
  **licensing defect** was found that blocks first publish (see Blocking defect).

## Problem

`diviops-server/package.json` declares `@diviops/mcp-server` at `1.6.1`. That name is
owned solely by `oaris <tools@oaris.de>`, whose published line sits at `1.5.39`. We have
no publish rights and will not seek them. The version line we maintain can never be
published under the name we declare.

The cost is not theoretical. It lands in three places.

**Our documentation sends users to someone else's product.** `README.md`, `SETUP.md`,
`diviops-server/README.md`, `plugins/diviops-agent/README.md`, and the plugin's own
wp-admin dashboard all instruct the reader to run
`npx -y --package @diviops/mcp-server diviops-mcp`. That resolves to upstream's 1.5.39
build. Every reader who followed our instructions, including this repository's own
maintainer until 2026-08-13, has been running upstream's server and none of this fork's
server work: not the `schema-route.ts` namespace fix, not the media tools, not the
revision tools, not the dynamic-content tools.

**release-please cuts tags for an artifact that does not exist.**
`release-please-config.json` already treats `diviops-server` as its own package
(`release-type: node`, `component: mcp-server`, `include-component-in-tag: true`), and
`mcp-server-v1.6.0` and `mcp-server-v1.6.1` are already cut. Neither is installable by
anyone.

**The stopgap is bound to one machine and one site.** The `diviops-mcp` client entry now
runs `npm install && npm run build && node dist/index.js` against the working clone. That
was a deliberate single-machine fix, and it is what this spec replaces.

## Driver

DiviOps runs against multiple clients' WordPress sites. This is current, not anticipated.
The server binds to one site at launch through `WP_URL`, `WP_PATH`, `WP_USER`, and
`WP_APP_PASSWORD`, so each site is a separate registration and each machine needs a clone
and a build. Upstream has an open third-party request for the same shape of need
([oaris-dev/diviops#7](https://github.com/oaris-dev/diviops/issues/7)), unaddressed for
over two months, which is evidence both that the need is real beyond this fork and that
waiting on upstream is not a plan.

Two things follow. Distribution is worth solving on its own merits and should not be
gated on the multi-site runtime question. And the multi-site runtime question (transport,
per-site credential resolution, whether one process can serve several sites) is a
separate design problem that this spec deliberately does not answer.

## Constraints

**The four frozen identifiers do not change.** Plugin slug `diviops-agent`, class
`DiviOps_Agent`, REST namespace `diviops/v1`, filter
`diviops_agent_handshake_extensions`. Nothing in this spec touches any of them.

**The npm package name is not frozen.** Verified rather than assumed: `CLAUDE.md` and
`FORK.md` each enumerate exactly the four identifiers above, and
`tests/test-drop-in-constraint.php` mentions `@diviops/mcp-server` only in a header
comment while asserting nothing about it. Renaming is permitted. The `bin` name is
likewise not among the four.

**MCP tool names are a first-class stability constraint.** `diviops-server/src/index.ts`
carries 157 `registerPluginTool` / `registerLocalTool` / `registerProTool` calls. Eight
files under `skills/` reference `diviops_*` tool names, and `skills/` contains **zero**
references to the npm package name. A package rename therefore does not touch skills, so
long as every tool name stays byte-identical. This matters more than it sounds: a tool
whose capability gate fails is *removed*, not errored, so a renamed or dropped tool
manifests as a skill quietly not working rather than as a failure anyone can see.

**License and attribution to `oaris-dev` are honored in whatever we publish.** See
Blocking defect below, which is where this constraint currently fails.

## A methodological note on npm evidence

An earlier pass tried to establish scope availability with the registry's search endpoint
and with `npmjs.com` page fetches. Both are worthless for this purpose and produced a
false negative that nearly shaped the recommendation.
`GET /-/v1/search?text=scope:rubicontv` returns `total: 0` while
`@rubicontv/forgejo-mcp` demonstrably exists, and `npmjs.com` org and user pages return
403 to non-browser clients. **Only `npm view <exact-name>` is evidence**, and it
distinguishes correctly: it returns data for `@rubicontv/forgejo-mcp` and `E404` for
`@rubicontv/definitely-not-real-xyz`. Any future claim in this repository that an npm name
is unused must cite `npm view`, not search.

## Options

### Option A — publish under the `@rubicontv` scope (recommended)

Publish to the public npm registry as `@rubicontv/diviops-mcp`, `bin` name `diviops-mcp`.

The consumer story keeps its exact current shape:

```bash
npx -y --package @rubicontv/diviops-mcp diviops-mcp
```

**The scope is already owned.** `npm owner ls @rubicontv/forgejo-mcp` returns
`daxdavis <dax@daxdavis.com>`, and `@rubicontv/forgejo-mcp@0.4.0` is published under it.
There is no scope to acquire and no account to create; what was the largest open
prerequisite in the first draft of this spec is simply already done.

Cost: a publish workflow, a source change to enable a credential-free smoke gate, a
licensing fix, and a documentation sweep. Details below.

### Option B — GitHub Packages (`npm.pkg.github.com`)

Rejected, definitively. GitHub's own documentation states that an access token is required
to "publish, install, and delete private, internal, and **public** packages"
([docs.github.com](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-npm-registry)),
`read:packages` is required to download, and the npm registry there supports classic PATs
only. Every client machine would need an `.npmrc` carrying a classic personal access token
before `npx` resolved anything. That destroys exactly the one-liner onboarding that
justifies publishing at all.

### Option C — git-tag installs

Rejected. `diviops-server/package.json` is not at the repository root, and npm's documented
git specifier surface — `<protocol>://<path>[#<commit-ish> | #semver:<range>]` plus the
`github:owner/repo#commit-ish` shorthands — contains **no subdirectory syntax** at all
([npm-install](https://docs.npmjs.com/cli/v11/commands/npm-install),
[package-spec](https://docs.npmjs.com/cli/v11/using-npm/package-spec)). The `directory`
field under `repository` in `package.json` is metadata and does not affect install
resolution. Third-party tools exist to work around this, which is itself evidence of the
gap, but that is not an official capability.

> Precision note: this is an argument from documented absence. npm's docs do not contain an
> explicit sentence saying subdirectory installs are unsupported.

A git install would additionally build from source on every client machine, pulling
devDependencies and running `tsc`, and would produce no provenance.

### Option D — stay local-only

Keep the current `npm install && npm run build && node dist/index.js` launcher. Zero work,
zero publishing surface, no new supply-chain responsibility.

This is the honest baseline and it is not absurd. It has been working. But it answers the
distribution question by declining it: every additional machine needs a clone, a build, and
a Node toolchain, and every client site inherits whatever state that clone is in. Given
that the multi-site driver is confirmed and current, Option D is a decision to keep paying
a growing cost. It stays as the fallback during migration, not as the destination.

**Recommendation: Option A.**

## Naming

`@rubicontv/diviops-mcp`, `bin` name `diviops-mcp`.

This mirrors the established in-house pattern rather than inventing one:
`@rubicontv/forgejo-mcp` ships `bin: { "forgejo-mcp": "dist/index.js" }` — scoped package,
unscoped bin. Keeping the bin name `diviops-mcp` also means every existing MCP
registration, every documentation line, and the maintainer's muscle memory keep working;
only the `--package` argument changes.

One caveat for the documentation: a global install (`npm i -g`) of both our package and
upstream's would collide on the `diviops-mcp` bin name. That is an unsupported
configuration and belongs in a documentation line, not in a rename.

## Blocking defect: the published tarball would carry no license

This must be fixed before anything is published, and it was not visible from the file
inventory alone.

`diviops-server/package.json` declares `"license": "MIT"`, and its `files` array is
`["dist/**/*", "!dist/**/__tests__/**", "templates", "data/verified-attrs.json",
"data/verified-attrs-backlog.json", "README.md"]`. npm automatically includes a `LICENSE`
file found **in the package directory** — but there is no `LICENSE` in `diviops-server/`.
The repository's only `LICENSE` is at the repository root, one level up, and nothing in
`files` reaches it.

So a tarball published today would ship MIT-declared code derived from `oaris-dev` with no
copyright notice of any kind. MIT requires the notice be included in "all copies or
substantial portions of the Software." This is both a license violation and a direct breach
of the constraint this fork sets for itself, that the fork base's license and attribution
are honored in whatever we publish.

The fix is small — place an appropriate `LICENSE` in `diviops-server/` (retaining the
`Copyright (c) 2026 oaris.de` notice) and confirm it appears in `npm pack --dry-run` — but
it is a hard gate on first publish, not a cleanup item.

Separately and much less urgently: `gh repo view rubicon/diviops` reports no detected
license. The cause is benign — the root `LICENSE` interleaves an explanatory paragraph and
an appended GPL-2.0-or-later section for the WordPress plugins, so GitHub's matcher cannot
identify it as stock MIT. The licensing itself is stated clearly and correctly. This
affects GitHub's sidebar and discoverability, not publishing, and should not be conflated
with the defect above.

## Versioning and release automation

Most of this already exists, which was checked rather than assumed.

`.release-please-manifest.json` keys versions **by path** (`{".": "1.14.1",
"diviops-server": "1.6.1"}`), and `component: mcp-server` controls the tag name. Neither is
keyed on `package-name`. Changing `package-name` in `release-please-config.json` therefore
**does not reset the version and does not change the tag shape**. `1.6.1` stays `1.6.1`,
the next release is `1.6.2`, and `mcp-server-v*` tags continue unbroken. There is no
version discontinuity to manage and no reason to reset to `1.0.0`.

What is missing is the publish step: the repository has exactly two workflows,
`release.yaml` and `test.yaml`.

**Adopt the shape already proven in `rubicon/forgejo-mcp`.** Its
`.github/workflows/publish.yaml` triggers on `release: [published]` plus
`workflow_dispatch`, sets `permissions: { contents: read, id-token: write }`, uses a
`publish-${{ github.ref }}` concurrency group with `cancel-in-progress: false`, then runs
checkout → `setup-node` (Node 22, `registry-url: https://registry.npmjs.org`) → `npm ci` →
`npm run smoke` → `npm publish --access public --provenance`. That workflow has published
successfully as recently as 2026-08-03, so this is a working pipeline rather than a
plausible one.

**The one thing that must not be copied verbatim.** `forgejo-mcp` is a single-package
repository, so an unguarded `on: release: [published]` is safe there. This repository cuts
**two** release streams: plugin releases (`v*`, the `.` package) and server releases
(`mcp-server-v*`). An unguarded copy would fire `npm publish` on every plugin release,
attempting to publish the server from a commit that never bumped it. **The publish job must
be guarded on the `mcp-server-v` tag prefix**, for example by testing
`github.event.release.tag_name` before doing any work.

Note also that required status checks on `main` are pinned by exact job name, so adding a
new workflow is additive and safe, while renaming an existing job is not.

## The smoke gate is a source change, not CI config

`forgejo-mcp` gates publishing behind `npm run smoke` (`npm run build && node
scripts/smoke.mjs`), which spawns the built server, completes an MCP handshake, lists
tools, and asserts the expected tool surface — with no Forgejo token required.

`diviops-server` cannot do this today. Verified directly: running the built
`dist/index.js` with `WP_URL`, `WP_USER`, and `WP_APP_PASSWORD` unset produces

```
Error: Missing required environment variable(s): WP_URL, WP_USER, WP_APP_PASSWORD.
```

and never reaches a handshake. Making a credential-free smoke test possible therefore
requires changing the server so it can start and answer `initialize` / `tools/list`
without WordPress credentials, deferring the credential requirement to the first call that
actually needs WordPress. That is a real code change with its own design questions (what
does a tool call do when unconfigured, and does deferring the check weaken the current
fail-fast behavior that surfaces misconfiguration immediately).

This is worth doing rather than skipping. Without it the pipeline publishes artifacts that
nothing verified, and this repository's own testing culture exists specifically to prevent
gates that pass while inspecting nothing. It also pairs naturally with the tool-name
stability constraint: a smoke test that asserts the registered tool-name set is exactly the
guard that makes silent tool-name drift impossible.

**Sequencing consequence:** either the smoke gate lands before the first publish, or the
first publish ships with a weaker gate (build-only) and the smoke gate follows. Recommend
the former; note the latter as the acceptable compromise if timing forces it.

## Credentials: bootstrap with a token, then move to OIDC

This is where the in-house precedent and current npm reality disagree, and the resolution
matters.

`forgejo-mcp` publishes with `NODE_AUTH_TOKEN: ${{ secrets.NPM_TOKEN }}`, described in its
own comment as "an npm automation token." **Classic automation tokens no longer exist** —
GitHub permanently revoked all npm classic tokens on 2025-12-09
([changelog](https://github.blog/changelog/2025-12-09-npm-classic-tokens-revoked-session-based-auth-and-cli-token-management-now-available/)).
Since that workflow published successfully on 2026-08-03, its `NPM_TOKEN` must in practice
be a granular access token with "Bypass 2FA" enabled, which is the only remaining class
that can publish non-interactively.

That class is itself being dismantled. As of 2026-07-31 bypass-2FA GATs can no longer
perform sensitive account, org, or package management actions, and **direct publish is
targeted for removal in January 2027**
([changelog](https://github.blog/changelog/2026-07-31-restricting-npm-bypass-2fa-granular-access-tokens/)),
after which such tokens can only *stage* a publish for a maintainer to approve with 2FA.
Write-scoped GATs are additionally capped at 90-day expiry.

The durable answer is npm's OIDC **trusted publishing**, which removes the stored
credential entirely: the workflow proves its identity to npm directly, and provenance
attestations are then generated automatically without the `--provenance` flag
([docs.npmjs.com](https://docs.npmjs.com/trusted-publishers)). It requires
`id-token: write`, a cloud-hosted runner, npm CLI ≥ 11.5.1 and Node ≥ 22.14.0.

**But trusted publishing cannot perform a first publish.** npm's own documentation is
explicit: "The package you're configuring must already exist on the npm registry"
([npm trust](https://docs.npmjs.com/cli/v11/commands/npm-trust)). The package must be
bootstrapped by other means before a trusted publisher can be configured for it.

The bootstrap requirement and the deprecation path resolve each other into a two-phase
plan:

1. **Phase 1 — bootstrap.** Publish `@rubicontv/diviops-mcp@1.6.2` using a granular access
   token with bypass-2FA, `npm publish --access public --provenance`, exactly as
   `forgejo-mcp` does today. Store the token in the 1Password `Automation` vault and
   reference it **by item UUID, not by title** — a title change once silently broke every
   repository pinning a shared item by name. Prefer the shortest workable expiry.
2. **Phase 2 — migrate.** With the package existing, configure the trusted publisher on
   npmjs.com (repository, workflow filename including its extension, allowed action
   `npm publish`), drop `NODE_AUTH_TOKEN` and the `--provenance` flag from the workflow,
   and revoke the bootstrap token. After this there is no long-lived publishing credential
   anywhere.

**The read-only service-account constraint, stated plainly rather than engineered around.**
The `Claude-Code-Agent` 1Password service account is read-only by 1Password's platform
design. It can *read* a token stored in `Automation`; it cannot create one and cannot write
the item. Creating the npm token, storing it, configuring the trusted publisher, and
revoking the token are all maintainer actions. No automation in this repository can perform
them, and this spec proposes no mechanism that pretends otherwise.

One unresolved detail: npm's trusted-publishing documentation does not state whether
scoped packages are supported. No restriction is documented, but no affirmative statement
exists either. Treat this as **unverified** and confirm at Phase 2 rather than assuming it.
Phase 1 is unaffected.

## Provenance

Requirements, all sourced
([generating provenance](https://docs.npmjs.com/generating-provenance-statements),
[trusted publishers](https://docs.npmjs.com/trusted-publishers)): a supported cloud CI
provider on a cloud-hosted runner (GitHub Actions qualifies; CircleCI does not, even though
it supports trusted publishing), npm CLI ≥ 9.5.0, `id-token: write`, a public package, a
public repository, and a `repository` field in `package.json` that matches where publishing
happens.

This repository satisfies these. It is public, `diviops-server/package.json` already
declares `"repository": { "url": "git+https://github.com/rubicon/diviops.git", "directory":
"diviops-server" }`, and the recommended workflow runs on GitHub Actions.

Scoped packages default to private visibility, so `--access public` is required on first
publish (or `publishConfig.access` in `package.json`).

## The rename blast radius

Seventeen files reference `@diviops/mcp-server`. They are **not** all the same kind of
reference, and conflating them would introduce a real error.

**Self-references — these must be renamed.** They describe the package this repository
produces, and every one of them is currently wrong.

| File | Nature |
| ---- | ------ |
| `diviops-server/package.json` | The `name` field itself |
| `diviops-server/package-lock.json` | Regenerated, not hand-edited |
| `release-please-config.json` | `package-name` for the `diviops-server` package |
| `README.md` | Install instructions, tool table |
| `SETUP.md` | ~8 `npx` invocations (lines 76, 87, 166, 185, 455, 481, 488, 496, 510) |
| `diviops-server/README.md` | Install instructions |
| `plugins/diviops-agent/README.md` | Install instructions |
| `diviops-server/src/index.ts` | Line 4462, inside a user-facing error string |
| `CLAUDE.md` | Describes which package calls `/diviops/v1/*` |
| `FORK.md` | Same, plus the divergence table this change adds to |
| `CONTRIBUTING.md` | Bug-report guidance (currently upstream's file) |
| `SUPPORT.md` | Version-checking guidance (currently upstream's file) |
| `.github/ISSUE_TEMPLATE/bug_report.md` | Version prompt (currently upstream's file) |
| `plugins/diviops-agent/diviops-agent.php` | Line 2770, rendered in the wp-admin dashboard |
| `plugins/diviops-agent/readme.txt` | Lines 15, 27, 31, 32 — see below |

**Provenance references — these must NOT be renamed.** They are factual statements about
where vendored bytes came from, and renaming them would turn accurate provenance into
fiction.

| File | Why it stays |
| ---- | ------------ |
| `diviops-server/src/cross-env-preflight/README.md` (lines 21, 31, 75) | Records that the vendored compiled output came from `@diviops/mcp-server@1.5.39`, with checksums. That is upstream's package and always will be. |
| `diviops-server/scripts/copy-vendored-cross-env-preflight.mjs` (line 5) | The same provenance statement in a code comment. |

Two couplings that are not obvious from the file list:

**The plugin ships the instruction in its admin UI.** `diviops-agent.php:2770` renders the
`npx ... @diviops/mcp-server ...` command inside the WordPress dashboard. Fixing it is a
plugin change, so it rides a **plugin** release (`v*` tags), not the server's. The rename
therefore spans both release trains and cannot be a server-only PR if the admin UI is to
stop being wrong.

**`readme.txt` carries a WordPress.org third-party-service disclosure.** Lines 27, 31, and
32 name the npm package and link `https://www.npmjs.com/package/@diviops/mcp-server` as a
service the plugin causes users to contact. That is a WordPress.org plugin-directory
disclosure requirement, not marketing copy. If the plugin is ever submitted, the disclosure
must name the package users will actually download; getting it wrong is review-blocking.

## Client migration

The maintainer performs every step here. This spec does not modify `~/.claude.json`, and no
automation in this repository should.

1. **Today (unchanged).** The `diviops-mcp` entry builds from the local clone. Leave it.
2. **Publish** `@rubicontv/diviops-mcp@1.6.2` (Phase 1 above).
3. **Add a second entry, do not replace the first.** Register a distinct server, e.g.
   `diviops-mcp-npm`, invoking
   `npx -y --package @rubicontv/diviops-mcp diviops-mcp` with the same env vars. Both now
   exist side by side.
4. **Verify the published server matches the local one.** The meaningful check is not "does
   it start" but "does it expose the same tools" — compare the registered tool list against
   the local entry's and confirm `diviops_meta_info` reports the expected version. A tool
   that silently failed to register is precisely the failure this repository's
   capability-gate design hides.
5. **Cut over.** Point the primary `diviops-mcp` entry at the published package.
6. **Retire the local entry** after a full working session against the published one.

Rollback at any point is re-adding the local-build entry. The pre-change backup at
`~/.claude.json.bak-20260813-205618` (166,009 bytes, 2026-08-13 20:56) is the coarser
fallback; being a whole-file snapshot it also reverts unrelated configuration changes made
since, so prefer the targeted re-add.

## Upstream sync impact

The fork is a maintained fork we own, so divergence is a cost to record rather than one to
minimize. This rename adds a small, predictable, permanent cost.

`diviops-server/package.json` is already in `FORK.md`'s modified-upstream table. The rename
adds a one-line permanent conflict on the `name` field: every future cherry-pick from
`oaris-dev` touching `package.json` will conflict there, and the resolution is always the
same (keep ours). `package-lock.json` carries the name in two places and is regenerated
rather than merged. This is cheap and, importantly, *loud* — a conflict that surfaces every
time is far safer than a silent one.

`README.md`, `SETUP.md`, and `diviops-server/README.md` already diverge and already have
rows. `CONTRIBUTING.md`, `SUPPORT.md`, and `.github/ISSUE_TEMPLATE/bug_report.md` are
currently unmodified upstream files, so renaming their references converts three files from
"pure upstream" to "diverged," each needing a new row. That is a real if minor increase in
maintenance surface, and it is the correct trade: leaving them naming upstream's package
means our own bug template asks reporters for the version of a product they are not
running.

`FORK.md` needs, at minimum: a row for each newly-diverged file; an amendment to the
`diviops-server/package.json` row recording the rename and why; a new row for the publish
workflow; an explicit note in the `cross-env-preflight` material that its
`@diviops/mcp-server` references are **provenance and must never be renamed**, so a future
find-and-replace does not quietly falsify them; and a line clarifying that the npm package
name was never one of the four frozen identifiers, so the question does not get
re-litigated.

## Risks

**Confusion with upstream's package.** Two similarly-named MCP servers for the same
WordPress plugin is genuinely confusing, and we create the second. Mitigate in the package
README: state plainly that this is a fork of `oaris-dev/diviops`, credit it, and say what
differs. This is a licensing obligation as much as a courtesy.

**We become a publishing target.** A compromised credential means a malicious version
published under Dax's name to whoever installs it. This is the largest new risk and it is
the argument for completing Phase 2 rather than stopping at Phase 1, and for provenance so
consumers can verify what they got.

**Phase 1 is left in place indefinitely.** The realistic failure mode is not a dramatic one:
it is that the bootstrap token works, nobody revisits it, and the repository is still
carrying a long-lived publishing credential when bypass-2FA publish is withdrawn in January
2027 and releases start failing. Phase 2 should be filed as its own issue at the same time
Phase 1 lands, not left as an intention recorded here.

**Silent tool-name drift.** Nothing currently prevents a future refactor from renaming an
MCP tool, breaking `skills/` invisibly. That risk exists today and is not created by this
change, but publishing widens the blast radius from one machine to every consumer. The
smoke gate above is the natural place to close it.

**The rename spans two release trains.** Server and plugin must both ship for the
documentation and admin UI to be consistent. Sequenced wrong, there is a window where the
dashboard tells users to install a package that is not the one they need. Ship the server
first (so the package exists), then the plugin (so it points at something real).

**Doing nothing.** Evaluated as Option D. It works today for one machine and fails the
confirmed driver. It remains the fallback during migration.

## Out of scope

- **Multi-site runtime design.** Transport (stdio vs HTTP/SSE), per-site credential
  resolution, and whether one process can serve several sites. The larger half of the
  multi-site problem; deserves its own issue and spec. Distribution lands first and is
  useful alone.
- **The local-build launcher's main-clone dependency.** The current `diviops-mcp` entry
  builds from the main clone, so whichever branch that clone sits on becomes the MCP
  runtime at client startup. With client sites depending on the server this is a live
  foot-gun today, entirely independent of any packaging decision, and warrants its own
  issue.
- **Publishing the WordPress plugin anywhere.** Unrelated distribution question.

## Work items

In dependency order. Items 1 and 2 are gates on first publish.

1. **Add a `LICENSE` to `diviops-server/`** retaining the `oaris.de` copyright notice, and
   confirm via `npm pack --dry-run` that it appears in the tarball. Blocking.
2. **Make a credential-free smoke gate possible** — server starts and answers
   `initialize` / `tools/list` without WordPress credentials — then add
   `scripts/smoke.mjs` asserting the registered tool-name set. Source change; see the
   sequencing compromise noted above.
3. **Rename** to `@rubicontv/diviops-mcp` across the self-reference table, leaving the
   provenance references untouched; regenerate `package-lock.json`; update
   `release-please-config.json`'s `package-name`.
4. **Add `.github/workflows/publish.yaml`** modeled on `forgejo-mcp`'s, **guarded on the
   `mcp-server-v` tag prefix** so plugin releases do not trigger it.
5. **Phase 1 publish** with a bootstrap GAT (maintainer action).
6. **Plugin-side rename** for `diviops-agent.php:2770` and `readme.txt`, riding a plugin
   release.
7. **Client migration** per the sequence above (maintainer action).
8. **Phase 2** — configure the trusted publisher, drop the token, revoke it. File as its
   own issue when item 5 lands.
9. **`FORK.md` divergence rows** for everything above.

## Open items requiring the maintainer

1. Approve the recommendation and the `@rubicontv/diviops-mcp` name.
2. Decide whether the smoke gate blocks first publish (recommended) or follows it.
3. Create the bootstrap GAT and store it in `Automation` (referenced by UUID).
4. Configure the trusted publisher and revoke the bootstrap token at Phase 2.
5. Approve filing the follow-up issues named here rather than having them filed
   unilaterally: multi-site runtime design, the main-clone launcher foot-gun, and Phase 2.
