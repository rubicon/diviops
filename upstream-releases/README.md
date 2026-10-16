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
| `diviops-pro-skills-<hash>.zip` | The paid skill-knowledge layer alone (`divi-5-builder` Tier 2 + Tier 3, and the `diviops-scf` skill). | Commercial, third party | Read. Never commit, never transcribe. |

Current layout:

```
upstream-releases/
  README.md          tracked
  agent/             diviops-agent-<version>.zip
  agent-pro/         diviops-agent-pro-<version>-beta.zip       (commercial)
  design-library/    diviops-design-library-<version>.zip
  skills-pro/        diviops-pro-skills-<hash>.zip              (commercial)
  suite/             diviops-<version>.zip
  suite-pro/         diviops-pro-suite-v<version>-beta.zip      (commercial)
  desktop/           diviops-desktop.zip, diviops-divi-5-desktop.zip
```

Two things about that listing are worth stating rather than inferring. The free
suite is published as `diviops-<version>.zip`, with no `suite` in the filename,
so the directory is what distinguishes it from the standalone agent plugin. And
the Pro skills archives are named by content hash rather than by version, so
their order is not recoverable from the filenames: modification time is the only
way to tell which one is newest, and moving them between machines loses even
that.

**Where these files come from.** Most upstream releases publish no `assets` at
all, and the source archive is the way in for those. Do not read that as "assets
are always empty" and skip the field: on 2026-10-12, 6 of 83 releases carried real
assets, and acting on the old premise silently skipped every one of them.

| Release | Assets |
| --- | --- |
| `v1.5.87` | `diviops-agent-1.5.45.zip` |
| `v1.5.86` | `diviops-agent-1.5.44.zip` |
| `v1.5.85` | `diviops-desktop.zip`, `diviops-divi-5-desktop.zip` |
| `v1.5.84` | `diviops-agent-1.5.43.zip` |
| `v1.5.81`, `v1.5.77`, `v1.5.76` | a bare `diviops-agent.zip`, one per release |
| `v1.5.65` | `diviops-design-library-1.0.0-beta.24.zip` |

Re-derive that list when syncing rather than trusting this table; it is a live API
and no test here can check it:

```bash
gh api repos/oaris-dev/diviops/releases --paginate \
  --jq '.[] | select(.assets|length>0) | "\(.tag_name) \([.assets[].name]|join(", "))"'
```

For the 77 releases that carry nothing, the payload still carries
`tarball_url` and `zipball_url`: GitHub's auto-generated source archives, which are
not members of `assets` and render at the bottom of the release page as "Source
code". That is where the archive's contents come from, and it is fetchable:

```bash
gh release download <tag> --repo oaris-dev/diviops --archive=zip
```

Verified on v1.5.56, which produced a file byte-identical in size to the
`diviops-1.5.56.zip` in `suite/`.

**So `suite/*.zip` is a repository source archive, not a built distribution.** Its
top-level entries are `.claude-plugin/`, `.github/`, `CONTRIBUTING.md`, `LICENSE` -
the repo tree at that tag. The built plugin zips ride inside it, because upstream
tracks `diviops-agent.zip` and `diviops-design-library.zip` at its repository root;
`agent/` and `design-library/` hold those, extracted.

Nothing about the Pro tiers changes: those are not on GitHub in any form, and the
rules above govern them.

## WordPress.org is a second channel

GitHub is not the only place upstream publishes. Verified 2026-10-12 against
`https://api.wordpress.org/plugins/info/1.0/diviops-agent.json`:

- **32 versioned builds, 1.5.13 through 1.5.45, plus `trunk`** (33 keys in
  `versions`), re-counted 2026-10-14; stable is 1.5.45.
- **The channel can omit a version outright.** Its list goes 1.5.43 then 1.5.45:
  **1.5.44 was never published to WordPress.org**, though GitHub released it as
  `v1.5.86`. So WP.org is not GitHub lagging by a constant, and
  `diviops-agent.1.5.44.zip` does not exist to be filed — do not go looking for it.
- **Only `diviops-agent` is published.** `diviops-design-library`, `diviops`,
  `diviops-suite` and `diviops-agent-pro` each return HTTP 404 with
  `{"error":"Plugin not found."}`. Stated so nobody re-checks all four.

**Same-version builds from the two channels are not byte-identical**, and the
difference is not a reliable channel marker. 1.5.45 is the worked example: the GitHub
asset is 331,405 bytes and WordPress.org's is 329,117, same declared version.

**Do not use `Plugin URI` to decide where a build came from.** Read the two channels at
the same version and the field is ambiguous rather than version-derived (measured
2026-10-14 from each zip's own `diviops-agent.php` header):

| Build | `Plugin URI` |
| --- | --- |
| `agent/diviops-agent-1.5.43.zip` (GitHub asset) | `https://github.com/oaris-dev/diviops` |
| `agent/diviops-agent.1.5.43.zip` (WP.org) | `https://diviops.com/` |
| `agent/diviops-agent-1.5.45.zip` (GitHub asset) | `https://diviops.com/` |
| `agent/diviops-agent.1.5.45.zip` (WP.org) | `https://diviops.com/` |

So at 1.5.43 the URI *was* a property of the channel, and the two converge by 1.5.45.
An earlier version of this file concluded the opposite — that `diviops.com` was simply a
change upstream made at 1.5.43 — from a survey of the 31 dot-named builds alone, which
cannot see a difference between channels because every build in it came from one (#605).

Which means **this archive cannot attribute a build's channel from its bytes or its
filename alone.** A 2026-10-12 triage claimed 1.5.15, 1.5.28 and 1.5.29 exist only
on WordPress.org; the archive holds a dash-named build for each of those versions,
and dash-named `agent/` builds are suite-harvested under the provenance rule above,
so that claim is not supported here and is not repeated as fact. If a sync needs
channel provenance, it has to record it at fetch time — nothing recovers it later.

**`trunk` is deliberately not filed.** WP.org serves a versionless
`diviops-agent.zip` for it: the name carries no version, it collides with the
GitHub bare-asset naming below, and it is a moving target. Current trunk is 1.5.43,
already filed. Recorded so it is not re-litigated; revisit only if a dated snapshot
is actually wanted.

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

`git show upstream/main:<path>` answers questions about tracked source at HEAD.
These zips answer questions about a *tag*, and which zip depends on what is being
asked:

- **A free `suite/` zip is upstream's tree at that tag.** It confirms what a
  version's source actually said, which is what a `FORK.md` divergence claim is
  checked against and what reproduces a reported bug at the version a user is
  running. It is not evidence about a built artifact, because it is not one - and
  `git show <tag>:<path>` against the `upstream` remote answers the same question
  without a zip, whenever the tag is fetched.
- **An `agent/` or `design-library/` zip is the published plugin**, extracted from
  the root-tracked zip inside a suite archive. That is the artifact tier: what a
  site actually installs, at the exact bytes it installs.

## Naming

Keep the publisher's own filename. It carries the version and the distribution
tier, which is the only provenance these files have — and under the table above,
the name is what decides whether it may be committed or copied from.

"The publisher's own filename" underdetermines, because there are three publishers
and one of them ships no usable name at all:

1. **A GitHub release asset** uses a dash: `diviops-agent-1.5.43.zip`.
2. **WordPress.org** uses a dot: `diviops-agent.1.5.43.zip`. This is that
   publisher's convention and **not a typo** — `agent/diviops-agent.1.5.17.zip` is
   byte-identical to WP.org's build (md5 `4d56049e52afda137893362bfd2799ec`,
   verified 2026-10-12). An earlier review called it an error before checking.
3. **An inner distribution harvested from a suite bundle** has no name of its own
   beyond a bare `diviops-agent.zip`. File it under the tier's versioned name, with
   the version read from the zip's own `const VERSION`. Where that collides with an
   existing name and the bytes differ, suffix the source release:
   `diviops-agent-1.5.4-from-diviops-1.5.37.zip`. 13 files currently use that
   suffix, and the convention is **blessed**: the filename is the only provenance
   these artifacts carry, and a content hash carries none.

Two consequences worth stating rather than inferring.

**A dash alone does not distinguish a release asset from a suite-harvested build.**
Only the `-from-<release>` suffix records provenance, and only where a collision
forced it. Do not infer a channel from a dash.

**The three bare `diviops-agent.zip` assets collide and are resolved by content.**
`v1.5.76`, `v1.5.77` and `v1.5.81` each publish an asset named plainly
`diviops-agent.zip`. Each was filed by reading its own `const VERSION` — 1.5.35,
1.5.36 and 1.5.40, all distinct — under the tier's `diviops-agent-<version>.zip`.
Without that rule the release-to-asset mapping is unrecoverable from the filenames.

**A free file may legitimately carry `-pro-` in its name, and the gate should still
block it on commit.** `design-library/diviops-design-library-1.0.0-beta.22-from-diviops-pro-suite-v1.5.40-beta.zip`
is a free, GPL Design Library build that was harvested from a Pro bundle, so its
filename carries a hyphen-delimited `pro` and matches the Pro-name pattern in
`tests/test-upstream-releases-untracked.php`, which tests every segment of every
path. It does not fire today, because that assertion runs over git-tracked paths
rather than the filesystem and the file is gitignored. **That is intended, and so is the block if it ever were force-added:** a
free artifact named after a Pro bundle is a genuine ambiguity, and a commit is
precisely when to stop and look at it. Renaming is not the fix — the name is the
provenance.
