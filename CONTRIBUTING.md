# Contributing

Thanks for your interest in DiviOps. **Pull requests are welcome here** — this repository
is where the work happens, not a mirror of it.

## What this repository is

`rubicon/diviops` is a **maintained fork** of [`oaris-dev/diviops`](https://github.com/oaris-dev/diviops).
We set the roadmap, the release process, and the test suite. Upstream is an occasional
source of good ideas and fixes, not something this repo syncs from automatically — so
changes made here stay here.

See [FORK.md](FORK.md) for what diverges from upstream and why, and read it before
changing anything that originated upstream.

## Before you open a PR

**Open an issue first.** It does not need to be long — a paragraph of what is wrong or
missing, and how you would approach it. This is so we can agree on the approach before
you spend real effort, not a formality. The only exception is a genuinely trivial change:
a typo, a comment, a metadata field, with no behaviour change.

## Workflow

1. **Issue first**, per above.
2. **Branch** as `dev/<issue-number>-<short-slug>`, e.g. `dev/206-module-update-encode`.
3. **Write the test first.** Every behaviour change needs coverage — see below.
4. **Commit** using [Conventional Commits](https://www.conventionalcommits.org/)
   (`fix(module_update): …`, `feat(preset): …`, `docs(divi): …`). Release automation reads
   these to compute the version bump, so the prefix is load-bearing.
5. **Sign your commits.** `git commit -S`. Unsigned commits will be asked to be re-signed.
6. **Open a PR** with `Closes #<issue>` in the body, and say what you verified and how.
   "Tests pass" is less useful than the actual command and its output.

A maintainer reviews before merge. Nothing self-merges.

## Testing

No Composer, no PHPUnit, no build step. The suite is plain PHP:

```bash
php tests/run.php
```

Test files are `tests/test-*.php`. A few conventions that are not obvious:

- **The runner fails when it discovers no test files, and when a filter matches nothing.**
  That is deliberate. A gate that reports what it inspected but derives pass/fail only
  from problems found will pass while inspecting nothing — which has genuinely happened
  here before. If you add a gate, assert that it actually inspected something.
- **Changing a file under `plugins/diviops-agent/` or `diviops-server/src/` requires a
  `FORK.md` row.** Those files originated upstream, and `FORK.md`'s divergence table is
  what makes a future upstream cherry-pick predictable. The suite fails and names the
  file when one changes without it. Adding a brand-new file under those paths does not
  need a row — upstream does not have it, so there is nothing to reconcile.
- **Divi-dependent assertions are environment-gated.** CI has no Divi install. Set
  `DIVIOPS_DIVI_BUILDER5_PATH` to a Divi `includes/builder-5` directory to run them:

  ```bash
  DIVIOPS_DIVI_BUILDER5_PATH=/path/to/Divi/includes/builder-5 php tests/run.php
  ```

- **Prefer a test that fails for the right reason.** If you are fixing a bug, show the
  test failing before the fix. Mutation-checking a new assertion — break the thing it
  guards, confirm it fails — is cheap and catches assertions that never had teeth.

The MCP server has its own checks:

```bash
cd diviops-server && npm ci && npm run build && npm run smoke
```

## Four identifiers that must never be renamed

Plugin slug `diviops-agent`, class `DiviOps_Agent`, REST namespace `diviops/v1`, filter
`diviops_agent_handshake_extensions`.

DiviOps Agent Pro (separate and commercial) attaches through `class_exists( 'DiviOps_Agent' )`
and that filter, and the published `@rubicontv/diviops-mcp` calls `/diviops/v1/*`. Renaming
any of the four **silently** disables Pro and makes MCP tools vanish without an error,
because a failed capability gate removes a tool rather than reporting a problem. There is a
test guarding this; if it fails, that is why.

## Versioning and releases

Do **not** hand-edit the version or `CHANGELOG.md`. release-please owns both, computes the
bump from your commit prefixes, and curates the changelog on a release PR. The plugin
version lives in two places in `diviops-agent.php`, and `.claude-plugin/plugin.json`
carries the marketplace version that Claude Code uses as its skill-cache key; tests guard
that all of them stay in sync.

### Curating the release object

Merging the release PR is step one of two. release-please generates the release body
mechanically from commit subjects, which reads like commit spam and is not what we
publish. Before announcing or linking a release, edit the release object:

1. **Title.** Plugin releases are version-only, no `v`: `1.19.0`. Server releases carry
   the artifact name: `mcp-server 1.10.0`. The tag keeps its `v` either way
   (`v1.19.0`, `mcp-server-v1.10.0`) — only the title drops it.
2. **Header line**, first line of the body: `DiviOps Agent v1.19.0 (2026-08-21)` or
   `DiviOps MCP Server v1.10.0 (2026-08-21)`. The date is the date it shipped, not the
   date release-please cut the PR — a release PR that sat for a week carries a stale one.
3. **Opening paragraph**, immediately after the header, before any sections. Three to
   five sentences, dry and specific, about what actually shipped. No exclamation points,
   no "our biggest release yet", no puns. The humour comes from the truth of the work —
   the bug that took three tries to die, the feature that should have existed from the
   start. It is skippable, but it should earn the read.
4. **Cut what the standard excludes.** Routine refactors, dependency bumps, test-only
   changes, and CI churn come out unless they affect users, operators, contributors, or
   release integrity.
5. **Disclaimer**, last line, after every section: this plugin rewrites pages, presets,
   and theme-builder layouts, so every release note ends with a one-line warning to back
   up before upgrading, in the same voice as the rest of the note.

Two checks that have each shipped wrong at least once:

- **Compare links resolve.** `gh api repos/rubicon/diviops/compare/vPREV...vTHIS` must
  return 200. Automation writes the link from the version it believes preceded this one,
  and an orphaned or missing tag produces a dead link (see the two failure modes above).
- **`CHANGELOG.md` is not part of this step.** release-please owns it and it must not be
  hand-edited — an edit is clobbered on the next run. The release object is the curated
  artifact; the changelog stays machine-generated. This is a deliberate deviation from
  the maintainer's general policy, which asks for both, and it is resolved this way here
  because the automation owns the file.

Releases published before 2026-08-26 are raw automation output and are deliberately left
that way. Release notes are read at release time, and rewriting six of them would mean
reconstructing retrospective prose from changelogs. The standard applies forward.

### Deploying to the dev site

The dev site is `staging.colleyvillelions.com`, reached over SSH; `CLAUDE.md` carries its
webroot. It runs a **copy** of the plugin, not a symlink, so nothing that happens in git
reaches it. Outside a release, deploy from `main` whenever the drift gate reports the site
behind:

```bash
git checkout main && git pull
scripts/deploy-local-site.sh
```

**During a release, deploy from the signed release commit before pushing it** — step 3 of
the recipe below, not a step after the tag. A release commit raises the repository's
version while the site still runs the old one, so the drift gate fails until the deploy
happens (#310). Deploying first is what makes the suite green on the commit that is about
to ship, and the site then runs exactly what shipped rather than what shipped an hour ago.

The script resolves the target from `DIVIOPS_LOCAL_SITE`, falling back to the
``Local site: `…` `` path in `CLAUDE.md`. Either may be a WordPress root on this machine
or `[user@]host:/absolute/root` for one reached over SSH — the remote shell is
`DIVIOPS_SSH`, defaulting to `ssh -o BatchMode=yes -o ConnectTimeout=10`. It refuses
anything that is not a DiviOps Agent plugin directory, takes a timestamped backup before
writing, verifies the installed `const VERSION` against the repository's, and lints the
deployed PHP — all four on the host when the target is remote, because running them here
would inspect a path this machine does not have and pass for the wrong reason. Running it
twice is safe: the second run reports `no change` and writes nothing.

Two things it deliberately does not do. It is **not** wired into CI: CI holds no key for
the site, and a step that silently no-ops is worse than no step. And it should **not** be
run mid-build-session without saying so — plugin behaviour changing under a running
page-write batch makes a bad payload indistinguishable from a semantics change.

Forgetting this step is the bug (#292): v1.19.2 shipped the #288 validator fix while the
site still ran 1.19.1, and a session building pages kept hitting the exact false positives
that release had fixed. So the step is not the whole guard —
`tests/test-local-site-drift.php` fails the suite whenever the site is present and behind,
naming both versions.

That check reports six states rather than a boolean, because they must not render the
same way: `unconfigured`, `unreachable`, `absent`, `invalid`, `drift`, `current`. The
last three decide pass or fail; the first three print a loud `SKIP` naming the reason,
which is what every CI run does. `unreachable` is the one #340 added — a named host that
did not answer. It is a skip and not a failure because CI and any offline laptop hit it,
and a gate that fails there is a gate people learn to merge past; what it must never do
is round down to `current`. Every one of the six is exercised against synthetic fixtures,
including the remote transport, which is driven through a stub remote shell so the suite
makes real assertions on a machine with no network and no key.

### If a release PR merges but no tag appears

release-please aborts every subsequent run with:

```
⚠ There are untagged, merged release PRs outstanding - aborting
```

The abort happens **before** the tag-creating step, so the condition cannot clear itself
and nothing ships until someone intervenes. Tracked in
[#249](https://github.com/rubicon/diviops/issues/249).

**Recover by producing a release shaped exactly like the bot's** — this part is not
cosmetic. A recovery release that differs in shape is unresolvable to release-please and
causes the *next* cycle to deadlock too, which is how this happened twice:

```bash
SHA=$(git rev-parse main)          # the merge commit of the release PR
git tag vX.Y.Z "$SHA"              # LIGHTWEIGHT, not -a/-s
git push origin vX.Y.Z
gh release create vX.Y.Z --title "vX.Y.Z" --notes-file notes.md   --target "$SHA" --verify-tag     # target a SHA, never a branch name
gh pr edit <release-pr> --remove-label "autorelease: pending"                         --add-label "autorelease: tagged"
```

Two properties matter, and both were learned by getting them wrong:

| Property | Bot produces | A signed `git tag -s` produces |
| --- | --- | --- |
| Tag object | lightweight (`commit`) | annotated (`tag`) |
| Release `targetCommitish` | commit SHA | `main` if you omit `--target` |

Confirmed by execution: re-tagging `v1.17.1` in the bot's shape turned
`⚠ Missing 1 paths: .` / `aborting` into `❯ Found release for path ., v1.17.1` on the very
next run.

This is the one place the repo's signed-tag norm is deliberately not applied. The bot's own
tags are unsigned, so the norm was never upheld by the automation; matching it is what keeps
releases working. Note the shape is *sufficient* to cause the deadlock but has not been
proven to be the whole cause — `v1.16.2` had the same bad shape and did not block the next
release. #249 carries what is still unexplained.

A second, separate failure mode looks nothing like this one: instead of aborting, the run
succeeds and proposes a release that re-lists the entire history. That one is fully
explained, and it is covered under "If you re-author a release commit that already merged"
below.

Verify with `gh workflow run release.yaml --ref main`, then check the run log for
`Found release for path .` rather than `aborting`.

### Cutting a release so it is both yours and signed

> **Never merge a release PR with the GitHub merge button.** Use the recipe in this
> section. The button leaves the PR merged but untagged, and release-please then aborts
> every subsequent run with `There are untagged, merged release PRs outstanding` — a
> deadlock whose abort happens *before* the tag-creating step, so it cannot clear itself
> and nothing ships until someone recovers it by hand. This has now happened **three
> times**: v1.19.1, the case tracked in
> [#249](https://github.com/rubicon/diviops/issues/249), and v1.20.2 on 2026-08-31. The
> recovery is documented below under "If a release PR merges but no tag appears", and it
> is fiddly enough — the replacement tag must be lightweight and the release must target a
> SHA rather than a branch — that avoiding the trigger is much cheaper than knowing the cure.
>
> Ordinary feature PRs are unaffected. This applies only to release PRs, which are the ones
> release-please expects to tag itself.


These two properties are exclusive under either GitHub merge button, and the
reason is measured rather than assumed. Both were probed in a throwaway repo with
the branch commit and the PR deliberately authored by different people:

| Merge method | Author taken from | Signed |
| --- | --- | --- |
| squash | the **PR author** — the branch commit's author is discarded | yes, committer `GitHub`, `verified: true` |
| rebase | the branch commit | **no**, `reason: unsigned` |

A release PR is authored by the `rubicon-release-please` App, and that cannot be
changed to a person. Squash-merging one therefore yields a signed commit authored
by the bot; rebase-merging yields a commit authored by whoever wrote the branch
commit, with no signature at all. Re-authoring the branch before a **squash**
merge does nothing, because squash discards that author.

Signing locally and fast-forwarding gets both. It costs two extra steps, both
found by running it for v1.19.1 rather than by reading:

```bash
# 1. Take the bot's commit, make it yours, sign it.
git fetch origin release-please--branches--main
git checkout -B release-fix origin/release-please--branches--main
git commit --amend --reset-author --no-edit -S

# 2. If main has moved since the release branch was cut, rebase or the
#    fast-forward in step 5 is rejected as non-fast-forward.
git rebase --gpg-sign origin/main

# 3. Deploy the release candidate to the dev site, THEN run the suite. In this
#    order the suite is green; in the other order it fails on drift.
scripts/deploy-local-site.sh
php tests/run.php

# 4. Push the branch and WAIT for CI. main requires status checks, the final
#    push is a direct push, and amending invalidated the bot commit's checks.
git push --force-with-lease origin release-fix:release-please--branches--main
gh pr checks <release-pr> --watch

# 5. Fast-forward. The exact signed object lands; merge buttons rewrite it.
git push origin "$(git rev-parse HEAD):main"
```

**Step 3 is an ordering, not a preference (#310).** The working tree at that point
declares the new version and the site still runs the previous one, so
`tests/test-local-site-drift.php` fails until the deploy happens. Running the suite
first produces a single red assertion on the very commit that is about to ship —
and a red suite that everyone learns to merge past is the erosion that check exists
to prevent. Deploying first was confirmed on v1.20.1: the release commit passed the
full suite, `PASS 2771 assertion(s) in 65 file(s)`, once the FORK.md divergence gate
was also taught to exempt a version-only bump (#321). The cost is that the deploy
writes to the dev site before the tag exists, which is the objection this ordering
was chosen over: an untagged release candidate on the dev site is recoverable, a
gate nobody reads is not.

**Between step 3 and step 5 the site is briefly ahead of `main`.** A branch cut from
`main` in that window compares the site's new version against a repository still on
the old one, and the drift gate reports `drift` naming both. It is an artifact of the
window, not a finding, and it clears the moment the release lands on `main`. Read the
two version numbers in the failure message: the site being *ahead* is this window,
and the site being *behind* is the real bug the gate is for.

Result: `author=Dax Davis  committer=Dax Davis  verified=true`. The release PR
closes itself as merged once GitHub notices the head commit is reachable from
`main`, which is asynchronous — an immediate check can still report `OPEN`.

**release-please then tags it for you — provided no untagged release PR is
outstanding.** On `mcp-server-v1.10.1` (2026-08-27) this recipe was run verbatim
and the automation did the rest unaided: it created the tag, published the
release object, moved the PR to `autorelease: tagged`, and triggered the npm
publish workflow. Nothing was tagged by hand.

**Check that precondition before you cut.** If `.release-please-manifest.json`
records a version with no matching tag, the run aborts *before* the tag-creating
step and cannot clear itself:

```
❯ Found pull request #274: 'chore: release main'
⚠ There are untagged, merged release PRs outstanding - aborting
```

That is the deadlock documented in "If a release PR merges but no tag appears",
and its recovery procedure applies. It is a statement about **backlog**, not
about how the current PR merged.

> **Corrected 2026-08-27 (#285).** This section previously claimed the recipe
> needs a hand-made tag on every release, and attributed the v1.19.1 abort to the
> fast-forward itself — the theory being that release-please looks for a merge
> commit a squash would have produced. `mcp-server-v1.10.1` refutes it. v1.19.1
> was cut while the untagged-PR deadlock was already outstanding, so it aborted
> for that reason; the fast-forward was coincident, not causal. Recorded because
> the wrong version of this paragraph priced the decision in
> [#264](https://github.com/rubicon/diviops/issues/264).

So the real price of a signed, personally-authored release commit is a possible
rebase and a wait for CI. A squash merge costs neither, at the price of
`rubicon-release-please[bot]` as the author. Both are defensible; pick with the
real cost in view.

`main` here requires no PR reviews, which is what lets the direct push through at
all. If that changes, this recipe stops working.

### If you re-author a release commit that already merged

Re-authoring rewrites the commit, which gives it a new SHA and **orphans every tag that
pointed at the old one**. release-please locates the previous release by walking `main` for
its own tags. A tag sitting on a commit that is not reachable from `main` is invisible to
that walk, so it falls back to the start of history and re-accumulates every change ever
made into one enormous proposed release.

This is not hypothetical. On 2026-08-21 the `v1.18.0` tag was moved onto the re-authored
commit and `mcp-server-v1.9.0` was left behind on the bot's original SHA. The next run
proposed `diviops-server` 1.9.0 to 1.10.0 with a changelog re-listing roughly thirty
entries that had already shipped, in
[#257](https://github.com/rubicon/diviops/pull/257).

**A release-please release creates one tag per package in
`.release-please-manifest.json`.** This repo has two: `.` produces `vX.Y.Z` and
`diviops-server` produces `mcp-server-vX.Y.Z`. Move all of them, then prove each one landed:

```bash
NEW_SHA=$(git rev-parse main)
for TAG in vX.Y.Z mcp-server-vA.B.C; do
  git push origin ":refs/tags/$TAG"
  git tag -f "$TAG" "$NEW_SHA"
  git push origin "refs/tags/$TAG"
  gh release edit "$TAG" --draft=false
  git merge-base --is-ancestor "$TAG" origin/main \
    && echo "$TAG reachable" || echo "$TAG ORPHANED"
done
```

Two details that cost time when they were learned:

- Deleting a tag demotes its release object to a draft. That is what the
  `gh release edit --draft=false` is for.
- `gh release view --json targetCommitish` keeps reporting the *old* SHA after a
  successful repair. The repair on 2026-08-21 left that field stale and release-please still
  read the corrected history, so it is not the field the walk uses. Judge the repair by
  `git merge-base --is-ancestor`, not by `targetCommitish`.

## Reporting bugs

Open an issue with the WordPress version, Divi version, MCP server version
(`@rubicontv/diviops-mcp`), plugin version, and reproduction steps. The bug-report template
prompts for these. A minimal reproduction beats a description.

For anything touching write paths, include whether the write reported success — a write
that reports success and does the wrong thing is a different and more serious bug than one
that errors.

## Security

Don't open public issues for security vulnerabilities. See [SECURITY.md](SECURITY.md) for
the disclosure flow.

## Status

DiviOps is **beta software** under active development. Triage is best-effort.
