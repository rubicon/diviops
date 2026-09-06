#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
#
# Deploy this repository's diviops-agent onto the dev site (#292, #340).
#
# The site runs a COPY of the plugin, not a symlink, so cutting a release does not
# reach it. This is the step that makes it reach it. Idempotent: a run that finds
# nothing to change writes nothing, takes no backup, and says so.
#
#   scripts/deploy-local-site.sh
#   DIVIOPS_LOCAL_SITE=/path/to/wp-root scripts/deploy-local-site.sh
#   DIVIOPS_LOCAL_SITE=host:/path/to/wp-root scripts/deploy-local-site.sh
#
# The target resolves through scripts/lib/local-site.php, and so does the comparison
# that decides whether anything needs deploying — the same resolution and the same
# comparison tests/test-local-site-drift.php uses, so the deploy and the drift check
# can never disagree about which site they mean or about what "in sync" means. The
# state machine lives there too: this script deploys on `drift`, says so on `current`,
# and refuses everything else. Adding a sixth state to the library therefore cannot
# leave a stale copy of the decision here.
#
# A `host:` target is reached over $DIVIOPS_SSH. Every step that inspects or writes
# the install — the refuse gate, the backup, the version check, the lint — then runs
# on the host. Running any of them here instead would inspect a path this machine
# does not have and pass for the wrong reason.
#
# Deliberately NOT run from CI. CI holds no key for the site, and a CI step that
# silently no-ops is worse than no step. Also do not run this during an active
# page-building session without saying so — plugin behaviour changing under a running
# write batch makes a bad payload indistinguishable from a semantics change.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO/plugins/diviops-agent"
# RemoteCommand=none / RequestTTY=no keep this working on a host whose ssh_config sets a
# RemoteCommand — OpenSSH otherwise refuses to run a command argument and exits 255. Kept
# byte-identical to diviops_site_remote_shell() in scripts/lib/local-site.php; the drift test
# asserts the two match so a fix to one cannot miss the other (#412).
SSH="${DIVIOPS_SSH:-ssh -o BatchMode=yes -o ConnectTimeout=10 -o RemoteCommand=none -o RequestTTY=no}"

if [ ! -f "$SRC/diviops-agent.php" ]; then
  echo "error: $SRC/diviops-agent.php is missing; this is not a diviops checkout" >&2
  exit 1
fi

command -v rsync >/dev/null 2>&1 || { echo "error: rsync is required" >&2; exit 1; }

# Resolution failure prints its own reason on stderr and exits non-zero.
TARGET="$(php "$REPO/scripts/lib/local-site.php" plugin-dir)"
HOST="$(php "$REPO/scripts/lib/local-site.php" host)"

if [ -n "$HOST" ]; then
  LABEL="$HOST:$TARGET"
  # $SSH is intentionally word-split: it is a command line, not a program name.
  # shellcheck disable=SC2086
  there() { $SSH "$HOST" "$1"; }
else
  LABEL="$TARGET"
  there() { sh -c "$1"; }
fi

QT="$(printf '%q' "$TARGET")"

# Whether the site is reachable and whether it holds a DiviOps plugin are the same
# question here: both answers are "do not point rsync --delete at this". The library
# already separates them, and its reason says which.
STATUS="$(php "$REPO/scripts/lib/local-site.php" status)"
REASON="$(php "$REPO/scripts/lib/local-site.php" reason)"

case "$STATUS" in
  current)
    echo "source:  $SRC"
    echo "target:  $LABEL"
    echo "no change: the installed plugin already matches this repository"
    exit 0
    ;;
  drift) ;;
  *)
    echo "error: $REASON" >&2
    echo "       nothing was deployed to $LABEL" >&2
    exit 1
    ;;
esac

# Refuse anything that is not a DiviOps plugin directory. --delete is pointed at
# this path, so being wrong about it is expensive. The status above already proves a
# const VERSION is there; this proves the class is, which is what makes it the
# plugin rather than any file that happens to be named like it.
if ! there "test -f $QT/diviops-agent.php && grep -q 'class DiviOps_Agent' $QT/diviops-agent.php"; then
  echo "error: $LABEL is not a DiviOps Agent plugin directory (no diviops-agent.php declaring class DiviOps_Agent)." >&2
  echo "       Refusing to rsync --delete over it." >&2
  exit 1
fi

REPO_VERSION="$(php "$REPO/scripts/lib/local-site.php" repo-version)"
INSTALLED_VERSION="$(php "$REPO/scripts/lib/local-site.php" installed-version)"

echo "source:  $SRC ($REPO_VERSION)"
echo "target:  $LABEL ($INSTALLED_VERSION)"

# What would change? This is the same call the drift check makes, not a second copy
# of it that could be edited apart from it. It cannot be empty here — `drift` is what
# got us past the case above — but a failed comparison exits non-zero and `set -e`
# stops the run, which is the branch that matters: a failed comparison and an in-sync
# one both print nothing.
CHANGES="$(php "$REPO/scripts/lib/local-site.php" diff)"

# Timestamped sibling, dot-prefixed. WordPress's get_plugins() skips entries
# beginning with a dot, so the backup does not show up as a second "DiviOps Agent"
# in the plugins screen the way a plain sibling would.
BACKUP="$(dirname "$TARGET")/.$(basename "$TARGET").backup-$(date +%Y%m%d-%H%M%S)"
if there "test -e $(printf '%q' "$BACKUP")"; then
  BACKUP="$BACKUP-$$"
fi
QB="$(printf '%q' "$BACKUP")"
there "cp -a $QT $QB"
echo "backup:  $BACKUP"

if [ -n "$HOST" ]; then
  rsync -a --delete --itemize-changes -e "$SSH" "$SRC/" "$HOST:$TARGET/"
else
  rsync -a --delete --itemize-changes "$SRC/" "$TARGET/"
fi

# Verify rather than assume. Both halves must agree or this exits non-zero.
DEPLOYED_VERSION="$(php "$REPO/scripts/lib/local-site.php" installed-version)"
if [ "$DEPLOYED_VERSION" != "$REPO_VERSION" ]; then
  echo "error: deployed $DEPLOYED_VERSION but this repository is at $REPO_VERSION" >&2
  echo "       the previous install is preserved at $BACKUP" >&2
  exit 1
fi

# A linter that finds no files exits 0 and reads as a pass, so count first.
php_count="$(there "find $QT -name '*.php' -type f | wc -l" | tr -d ' ')"
if [ "$php_count" -lt 1 ]; then
  echo "error: no PHP files found under $LABEL after deploying. This is a blind check, not a pass." >&2
  exit 1
fi
there "find $QT -name '*.php' -type f -print0 | xargs -0 -n1 php -l >/dev/null"

echo "changed:"
echo "$CHANGES" | sed 's/^/  /'
echo "deployed $REPO_VERSION to $LABEL ($php_count PHP file(s) linted)"
