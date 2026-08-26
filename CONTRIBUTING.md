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

Merge the release PR with **neither** GitHub merge button. Both discard one of
the two properties, and which one they discard was measured, not assumed:

| Merge method | Author taken from | Signed |
| --- | --- | --- |
| squash | the **PR author**, which is the release-please App — the branch commit's author is discarded | yes, committer `GitHub` |
| rebase | the branch commit | **no** |

Commit and sign locally, then fast-forward instead:

```bash
git fetch origin release-please--branches--main
git checkout -B release-fix origin/release-please--branches--main
git commit --amend --reset-author --no-edit -S
git push --force-with-lease origin release-fix:release-please--branches--main
git push origin "$(git rev-parse HEAD):main"
```

The final push is a genuine fast-forward, so the exact signed object lands
unmodified. The merge buttons rewrite the commit; a fast-forward does not.
Result: `author=Dax Davis  committer=Dax Davis  verified=true`.

Three things about this are worth knowing before you run it.

**It is not an admin bypass.** Probed in a throwaway repository with
`enforce_admins: true`, so no bypass was available, alongside
`required_signatures` and `required_linear_history`. The push succeeded. The
negative control — the same fast-forward carrying an unsigned commit — was
rejected:

```
remote: error: GH006: Protected branch update failed for refs/heads/main.
remote: - Commits must have verified signatures.
```

`main` here requires no PR reviews, so nothing objects to the direct push. If
that ever changes, this recipe stops working and the trade-off comes back.

**The release PR closes itself.** GitHub marks it `MERGED` once it notices the
head commit is reachable from `main`. That is what lets release-please tag; an
open release PR reproduces the deadlock described below. It is asynchronous, so
a check run immediately after the push can still report `OPEN`. Give it a moment
before concluding anything went wrong.

**Force-push the branch first, then fast-forward.** Amending changes the SHA, so
the branch has to carry the amended commit before `main` can fast-forward onto
it. Skipping that step leaves the PR pointing at a commit that never landed, and
GitHub will not close it.

Tags need no special handling here. release-please creates them after the merge
against whatever SHA `main` carries, so they land on the signed commit and there
is nothing to repair afterwards — unlike re-authoring a commit that has already
merged, which is the failure documented below.

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
