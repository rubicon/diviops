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

### Making the release commit yours instead of the bot's

A squash merge attributes the commit to the **PR author**, and a release PR's author is
`rubicon-release-please[bot]`. Squash-merging a release PR therefore lands the release
commit under the bot's name and puts the bot in the repository's contributor list.

Rebase merge is enabled on this repo for exactly this case. Re-author the commit **on the
release branch**, before the merge:

```bash
git fetch origin release-please--branches--main
git checkout -B release-fix origin/release-please--branches--main
git commit --amend --reset-author --no-edit -S
git push --force-with-lease origin release-fix:release-please--branches--main
gh pr merge <release-pr> --rebase
```

Doing this before the merge is the entire point. release-please creates its tags *after*
the merge, against whatever SHA `main` ends up carrying, so the tags land on the right
commit and there is nothing to repair afterwards.

One thing to confirm the first time: `main` requires signed commits, and GitHub rewrites
commits when it rebase-merges. Whether the rewritten commit still satisfies that check has
not been verified on this repo.

```bash
gh api repos/rubicon/diviops/commits/$(git rev-parse origin/main) --jq .commit.verification
```

If that returns unverified, the merge is blocked. `enforce_admins` is off so an admin can
still push it through, but do not let that become the routine.

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
