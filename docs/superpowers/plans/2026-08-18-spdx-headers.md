# Plan: repo-wide SPDX-License-Identifier headers (#233)

## Context

This repository is dual-licensed and no source file says which license governs it.
`LICENSE` explains the split in prose only, so a reader holding a single file has no
signal. Someone copying `plugins/diviops-agent/includes/trait-media.php` out of this
MIT-looking tree has nothing telling them it is GPL.

| Area | License |
| --- | --- |
| `plugins/diviops-agent/`, `plugins/diviops-design-library/` | GPL-2.0-or-later |
| `diviops-server/src/`, `scripts/`, `tests/` | MIT |

File counts at plan time: 21 GPL PHP, 31 MIT TypeScript, 7 MIT script PHP, 60 MIT test
PHP. 119 total. 8 vendored files excluded (below).

## Global Constraints

- **Identifier only.** Add `SPDX-License-Identifier`. Do NOT add
  `SPDX-FileCopyrightText`. The identifier states which license governs a file, a
  mechanical fact derivable from its path. Copyright text states who owns a file, which
  in this tree is mixed per file (`trait-core.php` is 668 changed lines of 2440). This
  was decided in #231 and is not reopened here.
- **No licensing terms change.** This is labelling. `LICENSE` and
  `diviops-server/LICENSE` are not edited by this plan.
- **Vendored files are excluded, explicitly.** `diviops-server/src/cross-env-preflight/`
  `*.js` and `*.d.ts` (8 files) are upstream's compiled output copied verbatim, with
  provenance recorded in that directory's `README.md`. The gate must carry an explicit
  exclusion so the vendored boundary stays visible rather than being silently stamped.
- **The gate must assert its own coverage.** CLAUDE.md records that a gate reporting
  what it inspected but deriving pass/fail only from problems-found will pass while
  inspecting nothing, and that this happened three times on the predecessor repository.
  The gate asserts a non-zero inspected count and asserts the count per area.
- **WordPress plugin headers must not be corrupted.** The main plugin files carry a
  WordPress header docblock that WP parses. Place the identifier so it cannot be read as
  a header field. Verify by re-reading the `Version:` and `Plugin Name:` lines after
  stamping.
- `php tests/run.php` stays green. No existing assertion count decreases.
- Match surrounding file style. No reformatting of code that is not being stamped.

## Task 1: the gate, written to fail

Add `tests/test-spdx-headers.php`.

It walks the four areas above, and for every file asserts the file contains a correct
`SPDX-License-Identifier` for its area: `GPL-2.0-or-later` under `plugins/`, `MIT` under
`diviops-server/src/`, `scripts/`, and `tests/`.

It must additionally assert:

- a non-zero total inspected count
- a non-zero inspected count per area, so an area silently going empty fails
- that every file under `diviops-server/src/cross-env-preflight/` matching `*.js` or
  `*.d.ts` is in the exclusion list and is NOT required to carry an identifier

Follow the idiom in `tests/test-version-sync.php`: `require_once __DIR__ . '/wp-shim.php';`
then bare `assert_true` / `assert_same` calls.

**Acceptance: run `php tests/run.php spdx` and confirm it FAILS**, naming unstamped
files. Do not stamp anything in this task. Report the exact failure output. A test that
passes here is testing nothing and must be rewritten.

## Task 2: stamp the GPL files

Add `// SPDX-License-Identifier: GPL-2.0-or-later` to all 21 PHP files under
`plugins/`.

The two main plugin files (`plugins/diviops-agent/diviops-agent.php`,
`plugins/diviops-design-library/diviops-design-library.php`) carry a WordPress header
docblock. Place the identifier so WordPress's header parser cannot mistake it for a
field: after the closing `*/` of the header docblock, not inside it.

After stamping, re-read both files' `Plugin Name:` and `Version:` lines and confirm they
are byte-identical to before. Report them.

`php tests/run.php` must stay green, and `tests/test-version-sync.php` in particular
must still pass, since it parses those headers.

## Task 3: stamp the MIT files

Add `// SPDX-License-Identifier: MIT` to:

- 31 TypeScript files under `diviops-server/src/`, EXCLUDING
  `diviops-server/src/cross-env-preflight/*.js` and `*.d.ts`
- 7 PHP files under `scripts/`
- 60 PHP files under `tests/`

PHP files that open with `<?php` followed by a docblock: place the identifier on the
line after `<?php`. TypeScript files: first line.

**Acceptance: `php tests/run.php spdx` now PASSES, and the full `php tests/run.php`
is green with no assertion count decrease** (baseline 1455 in 41 files, plus whatever
Task 1's gate adds).

Also run `cd diviops-server && npm run build` and confirm it still succeeds — a
first-line comment must not break the TypeScript build or any file with a shebang.
