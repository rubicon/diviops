# Upstream release archive

Local archive of published DiviOps releases. **The archive contents are never
committed.** `.gitignore` tracks this README and ignores everything else under
this directory, so a fresh clone gets the rules and none of the binaries.

This directory exists in the main clone only. Per-issue worktrees are removed
when their branch merges, and ignored files inside them go with the worktree.

## The boundary is what leaves this machine, not what you open

Every artifact here is readable. What differs between them is the licence, and
therefore what may be committed and what may be copied:

| Artifact | Contents | Licence | Rule |
| --- | --- | --- | --- |
| `diviops-suite-<version>.zip` | Free distribution. | MIT / GPL-2.0-or-later | Read, and reuse under its licence. |
| `diviops-agent-<version>.zip`, `diviops-design-library-*.zip` | Individual free plugins. | MIT / GPL-2.0-or-later | Read, and reuse under its licence. |
| `diviops-pro-suite-*` | Pro distribution: everything in Free **plus** the Pro plugin and the deeper skill-knowledge layer (`divi-5-builder` Tier 2 + Tier 3). | Commercial, third party | Read. Never commit, never transcribe. |
| `diviops-agent-pro*.zip` | The Pro plugin alone. | Commercial, third party | Read. Never commit, never transcribe. |

Current layout:

```
upstream-releases/
  README.md     tracked
  agent/        diviops-agent-*.zip, diviops-design-library-*.zip
  agent-pro/    diviops-agent-pro-*.zip        (commercial)
  suite/        diviops-suite-<version>.zip
  suite-pro/    diviops-pro-suite-*.zip        (commercial)
```

## The clean-room rule was rescinded

Until 2026-09-02 the Pro artifacts were unreadable: nothing here could be
opened, grepped, or allowed to inform anything authored in this repository. The
owner ended that rule on 2026-09-02:

> "FYI, the new Pro plugin and new skills archives are in the upstream-releases
> folder. I'm rescinding the rule about the clean room. Let's take it a
> different way. Any time we are stepping on the Pro plugin, let me know."

Three rules survive it, and they are the whole policy now.

**1. Never commit a Pro artifact, or Pro source, anywhere in this repository.**
`rubicon/diviops` is public. Committing one publishes a third party's
proprietary code under our name — a licensing incident, not a tidiness problem.
A `.gitignore` line is one `git add -f` away from irrelevant and nothing would
report it, so `tests/test-upstream-releases-untracked.php` fails the suite if
anything under this directory other than this README is tracked, and fails
separately if any path segment anywhere in the repository is Pro-named. It
asserts against git's own index rather than the filesystem, because the question
is not what is on disk — the archives are supposed to be on disk — but what a
push would publish. The same reasoning rules out pointing codebase-memory or any
other index at a Pro artifact: an index is a copy, and it is one that every
future query can retrieve.

**2. Do not transcribe Pro source into this repository.** Reading it to
understand a behaviour is fine. Pasting or paraphrasing its code is not: this
fork ships GPL-2.0-or-later plugins and MIT tooling, and neither licence is ours
to grant over someone else's commercial code. Write from understanding, in this
codebase's own shapes, and cite a primary source you are licensed to use — Divi's
own PHP, the free distributions, `upstream/main`, or behaviour observed through
Pro's REST surface on the dev site.

**3. Surface Pro overlap before building, not after.** When work would
reimplement something Pro already does, say so to Dax and let him decide. That is
the replacement for the clean-room rule, and it is the reason reading Pro is
worth anything.

**Keep zips, never extracted directories.** Still the convention, for a narrower
reason than before: an extracted tree is what a bulk indexer walks and what a
stray `git add -f .` sweeps up, and an extracted Pro bundle is the exact shape
that defeated an earlier version of the guard — only the directory carried the
Pro marker and none of the files under it did (#271).

Installing the Pro plugin on the development site, to verify it still attaches
through `class_exists( 'DiviOps_Agent' )` and the
`diviops_agent_handshake_extensions` filter, remains the routine reason to have
it on disk.

## What the free distribution is good for

Prefer `git show upstream/main:<path>` when the question is about source already
tracked. Reach for a free zip when the question is about a *published artifact*:

- Confirming what upstream actually shipped in a version, rather than inferring
  it from a sync commit.
- Checking a `FORK.md` divergence claim against a real release.
- Reproducing a reported bug at the exact version a user is running.

## Naming

Keep the publisher's own filename. It carries the version and the distribution
tier, which is the only provenance these files have — and under the table above,
the name is what decides whether it may be committed or copied from.
