#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
#
# Deploy this repository's diviops-agent onto the local dev site (#292).
#
# The site runs a COPY of the plugin, not a symlink, so cutting a release does not
# reach it. This is the step that makes it reach it. Idempotent: a run that finds
# nothing to change writes nothing, takes no backup, and says so.
#
#   scripts/deploy-local-site.sh
#   DIVIOPS_LOCAL_SITE=/path/to/wp-root scripts/deploy-local-site.sh
#
# The target resolves through scripts/lib/local-site.php — the same resolution
# tests/test-local-site-drift.php uses, so the deploy and the drift check can never
# disagree about which site they mean.
#
# Deliberately NOT run from CI. The site is LocalWP on one machine; CI cannot reach
# it, and a CI step that silently no-ops is worse than no step. Also do not run this
# during an active page-building session without saying so — plugin behaviour
# changing under a running write batch makes a bad payload indistinguishable from a
# semantics change.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/plugins/diviops-agent"

if [ ! -f "$SRC/diviops-agent.php" ]; then
  echo "error: $SRC/diviops-agent.php is missing; this is not a diviops checkout" >&2
  exit 1
fi

command -v rsync >/dev/null 2>&1 || { echo "error: rsync is required" >&2; exit 1; }

# Resolution failure prints its own reason on stderr and exits non-zero.
TARGET="$(php "$REPO/scripts/lib/local-site.php" plugin-dir)"

# Refuse anything that is not a DiviOps plugin directory. --delete is pointed at
# this path, so being wrong about it is expensive.
if [ ! -d "$TARGET" ]; then
  echo "error: $TARGET is not a directory. Nothing is installed there." >&2
  exit 1
fi
if [ ! -f "$TARGET/diviops-agent.php" ] || ! grep -q 'class DiviOps_Agent' "$TARGET/diviops-agent.php"; then
  echo "error: $TARGET is not a DiviOps Agent plugin directory (no diviops-agent.php declaring class DiviOps_Agent)." >&2
  echo "       Refusing to rsync --delete over it." >&2
  exit 1
fi

REPO_VERSION="$(php "$REPO/scripts/lib/local-site.php" repo-version)"
INSTALLED_VERSION="$(php "$REPO/scripts/lib/local-site.php" installed-version)"

echo "source:  $SRC ($REPO_VERSION)"
echo "target:  $TARGET ($INSTALLED_VERSION)"

# What would change? An identical tree itemizes nothing, which is the whole
# idempotency story: no changes means no backup and no write.
CHANGES="$(rsync -a --delete --itemize-changes --dry-run "$SRC/" "$TARGET/")"

if [ -z "$CHANGES" ]; then
  echo "no change: the installed plugin already matches this repository"
  exit 0
fi

# Timestamped sibling, dot-prefixed. WordPress's get_plugins() skips entries
# beginning with a dot, so the backup does not show up as a second "DiviOps Agent"
# in the plugins screen the way a plain sibling would.
BACKUP="$(dirname "$TARGET")/.$(basename "$TARGET").backup-$(date +%Y%m%d-%H%M%S)"
if [ -e "$BACKUP" ]; then
  BACKUP="$BACKUP-$$"
fi
cp -a "$TARGET" "$BACKUP"
echo "backup:  $BACKUP"

rsync -a --delete --itemize-changes "$SRC/" "$TARGET/"

# Verify rather than assume. Both halves must agree or this exits non-zero.
DEPLOYED_VERSION="$(php "$REPO/scripts/lib/local-site.php" installed-version)"
if [ "$DEPLOYED_VERSION" != "$REPO_VERSION" ]; then
  echo "error: deployed $DEPLOYED_VERSION but this repository is at $REPO_VERSION" >&2
  echo "       the previous install is preserved at $BACKUP" >&2
  exit 1
fi

# A linter that finds no files exits 0 and reads as a pass, so count first.
php_count="$(find "$TARGET" -name '*.php' -type f | wc -l | tr -d ' ')"
if [ "$php_count" -lt 1 ]; then
  echo "error: no PHP files found under $TARGET after deploying. This is a blind check, not a pass." >&2
  exit 1
fi
find "$TARGET" -name '*.php' -type f -print0 | xargs -0 -n1 php -l >/dev/null

echo "changed:"
echo "$CHANGES" | sed 's/^/  /'
echo "deployed $REPO_VERSION to $TARGET ($php_count PHP file(s) linted)"
