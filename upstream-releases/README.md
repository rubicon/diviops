# Upstream release archive

Local archive of published DiviOps releases. **The archive contents are never
committed.** `.gitignore` tracks this README and ignores everything else under
this directory, so a fresh clone gets the rules and none of the binaries.

This directory exists in the main clone only. Per-issue worktrees are removed
when their branch merges, and ignored files inside them go with the worktree.

## The two halves are not the same kind of thing

```
upstream-releases/
  suite/   readable   — upstream DiviOps Suite / diviops-agent releases
  pro/     DO NOT OPEN — DiviOps Agent Pro. Install only.
```

### `suite/` — readable

These are releases of the project this repository is forked from. The fork base
is MIT and `FORK.md` records our divergence from it, so reading these is both
licensed and useful:

- Confirming what upstream actually shipped in a given version, rather than
  inferring it from a sync commit.
- Checking a `FORK.md` divergence claim against a real release artifact.
- Reproducing a reported bug at the exact version a user is running.

Prefer `git show upstream/main:<path>` when the question is about source we
already track. Reach for a zip when the question is about a *published
artifact* — what was in the package, not what was in the tree.

### `pro/` — installable, not readable

DiviOps Agent Pro is a separate commercial plugin. It is **not** forked, and it
is **not** MIT. The clean-room boundary on our own advanced skill knowledge is
absolute: never read, copy, paraphrase, or derive from Pro's code or skill
files, and never let Pro's contents inform anything authored here. Every
attribute map and every skill reference we ship must trace to a primary source
we are licensed to use.

That makes exactly one use of these files legitimate:

- **Installing Pro on the development site** to verify it still attaches
  through `class_exists( 'DiviOps_Agent' )` and the
  `diviops_agent_handshake_extensions` filter, and that its capability keys
  still appear in the handshake.

Everything else is forbidden. Do not unzip, `grep`, `cat`, open in an editor,
feed to a tool, or read these files, and do not ask an agent to. If you need to
know how Pro behaves, observe it running on the dev site through its public
REST surface and write down what you observed. Behaviour is fair game;
source is not.

## Why the guards exist

`rubicon/diviops` is a **public** repository. A committed Pro archive would
publish a third party's proprietary code to the internet under our name. A
`.gitignore` line alone is one `git add -f` away from that, so
`tests/test-upstream-releases-untracked.php` fails the suite if anything under
this directory other than this README is ever tracked.

## Naming

Keep the publisher's own filename. It carries the version, and renaming loses
the only provenance these files have.
