# Upstream release archive

Local archive of published DiviOps releases. **The archive contents are never
committed.** `.gitignore` tracks this README and ignores everything else under
this directory, so a fresh clone gets the rules and none of the binaries.

This directory exists in the main clone only. Per-issue worktrees are removed
when their branch merges, and ignored files inside them go with the worktree.

## The boundary is the artifact name, not the folder

Do not reason about this from directory layout. Three kinds of artifact live
here and two of them are forbidden:

| Artifact | Contents | Rule |
| --- | --- | --- |
| `diviops-<version>.zip`, `diviops-agent-<version>.zip`, `diviops-design-library-*.zip` | Free distribution and the individual free plugins. MIT. | **Readable.** |
| `diviops-pro-suite-*` | Pro distribution: everything in Free **plus** the Pro plugin and the deeper skill-knowledge layer. | **Forbidden.** |
| `diviops-agent-pro*.zip` | The Pro plugin alone. | **Forbidden.** |

Current layout, which follows from that table rather than defining it:

```
upstream-releases/
  README.md   tracked
  free/       readable    — free distribution + individual free plugin zips
  suite/      FORBIDDEN   — holds only diviops-pro-suite-* bundles
  pro/        FORBIDDEN   — diviops-agent-pro-*.zip
```

`suite/` is a misleading name for a directory that holds nothing but Pro
distributions. If it is ever renamed, `pro-suite/` says what it is. The rule does
not depend on the rename: the artifact name decides, and every file in there is
Pro-named.

**Keep zips, never extracted directories.** A zip is opaque — you have to
deliberately unzip it. An extracted tree is one `grep -r` away from
contamination, and it is exactly what a code indexer walks; a zip is a binary it
skips. Storing zips only makes the boundary structural instead of rule-based.

A Pro-suite bundle is not a free bundle with an extra file bolted on. Its own
README states that the Pro distribution adds "the deeper skill knowledge layer —
`divi-5-builder` **Tier 2** + **Tier 3**", and it carries `diviops-agent-pro.zip`
plus an extracted `plugins/diviops-agent-pro/` at its root. Those are precisely
the things this project may not derive from.

**Do not carve out the MIT parts of a Pro bundle.** It does contain MIT pieces —
`diviops-agent.zip`, `diviops-design-library.zip`, `diviops-server/` — but every
one of them is already available from `upstream/main` or from a free zip in
`free/`. Verified: all eleven zips in `free/` contain zero Pro members. Reaching
into a Pro bundle buys nothing and puts you one `cd` away from Tier 2 skill
files. There is no reason to open one at all.

## What "forbidden" means

Never open, unzip, `grep`, `cat`, `Read`, open in an editor, feed to a tool, or
ask an agent to look at a forbidden artifact, and never let its contents inform
anything authored here. Every attribute map and skill reference this project
ships must trace to a primary source it is licensed to use.

Exactly one use is legitimate:

- **Installing the Pro plugin on the development site**, to verify it still
  attaches through `class_exists( 'DiviOps_Agent' )` and the
  `diviops_agent_handshake_extensions` filter, and that its capability keys still
  appear in the handshake.

Install it. Do not read it. If you need to know how Pro behaves, observe it
running through its public REST surface and write down what you observed.
Behaviour is fair game; source is not.

## What the free distribution is good for

Prefer `git show upstream/main:<path>` when the question is about source already
tracked. Reach for a free zip when the question is about a *published artifact*:

- Confirming what upstream actually shipped in a version, rather than inferring
  it from a sync commit.
- Checking a `FORK.md` divergence claim against a real release.
- Reproducing a reported bug at the exact version a user is running.

## Why the guards exist

`rubicon/diviops` is a **public** repository. A committed Pro artifact would
publish a third party's proprietary code to the internet under our name.
A `.gitignore` line alone is one `git add -f` away from that, so
`tests/test-upstream-releases-untracked.php` fails the suite if anything under
this directory other than this README is tracked, and fails separately if
anything Pro-named is tracked anywhere in the repository.

## Naming

Keep the publisher's own filename. It carries the version and the distribution
tier, which is the only provenance these files have — and under the table above,
the name is what decides whether you may open it.
