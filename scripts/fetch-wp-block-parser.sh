#!/usr/bin/env bash
# SPDX-License-Identifier: MIT
#
# Fetch the five WordPress core files tests/wp-block-parser-shim.php needs, into
# a directory shaped like a WordPress root, and print that root (#598).
#
# Why five files and not a WordPress tarball: core's block parser calls no
# WordPress function at all, so the parser trio loads standalone. blocks.php is
# read (never loaded) by the suite's transcription gate, which compares the two
# wrappers the stub transcribes against the core actually on disk. version.php is
# read so the suite can print which core it loaded. That is roughly 142KB against
# a 25MB tarball, and it pins to an exact tag instead of following whatever
# wordpress.org currently calls latest -- a required status check that can go red
# because WordPress shipped a release is a check that teaches people to ignore it.
#
# Every file is verified by sha256 against the pinned list below, so a moved tag
# or a substituted response fails loudly instead of running different code than
# the one reviewed. The parser trio at this tag was confirmed byte-identical to
# the WordPress 7.1 tree on the maintainer's workstation, and this tag's
# blocks.php carries the same two wrapper bodies as the 7.1.3 install on staging.
#
# Usage: scripts/fetch-wp-block-parser.sh [destination-root]
#
# Bump procedure: change WP_TAG, run this script, and replace the sha256 list
# with what it reports as mismatched. Do not relax the verification to make a
# bump pass.

set -euo pipefail

WP_TAG='7.1.3'
BASE="https://raw.githubusercontent.com/WordPress/WordPress/${WP_TAG}/wp-includes"

# sha256  filename
read -r -d '' PINNED <<'PINS' || true
49fa9b16292e4407c591f292f2515c007b0784c594dd978212ee5faccb499420  class-wp-block-parser.php
7e21f483cf0c9deddd2144e026842bf906c72983908f7bd163c0716e8f6f325e  class-wp-block-parser-block.php
3a66c88fa2397a842884908d83d1d03e5cb9cfb23fbb6d3cdf573bcd1520f0ae  class-wp-block-parser-frame.php
99de93fa9afbf4576d5a34d1e46365980d510de59f565bece9f4db178625a460  blocks.php
7513dacf90ade66347561ffc2e79d27e74b6a3f05d869bd455144ed7cd378a04  version.php
PINS

# shasum ships on both macOS and GitHub's ubuntu images; sha256sum is Linux-only
# and is the fallback rather than the primary so a workstation run behaves the
# same as CI.
if command -v shasum >/dev/null 2>&1; then
	sha256() { shasum -a 256 "$1" | cut -d' ' -f1; }
elif command -v sha256sum >/dev/null 2>&1; then
	sha256() { sha256sum "$1" | cut -d' ' -f1; }
else
	echo "error: neither shasum nor sha256sum is available; cannot verify the download." >&2
	exit 1
fi

root="${1:-$(mktemp -d)}"
inc="${root}/wp-includes"
mkdir -p "${inc}"

# A checksum loop that iterates zero files exits 0 and reads as a pass, so the
# count is asserted before anything is verified -- the same reason the lint steps
# in .github/workflows/test.yaml count before they run.
expected=0
while read -r _sum name; do
	[ -n "${name:-}" ] || continue
	expected=$((expected + 1))
done <<< "${PINNED}"

if [ "${expected}" -ne 5 ]; then
	echo "error: expected 5 pinned files, parsed ${expected}. The pinned list is malformed." >&2
	exit 1
fi

verified=0
while read -r sum name; do
	[ -n "${name:-}" ] || continue
	curl -fsSL --retry 3 --retry-delay 2 -o "${inc}/${name}" "${BASE}/${name}"
	if [ ! -s "${inc}/${name}" ]; then
		echo "error: ${name} downloaded empty from ${BASE}" >&2
		exit 1
	fi
	actual="$(sha256 "${inc}/${name}")"
	if [ "${actual}" != "${sum}" ]; then
		echo "error: ${name} sha256 mismatch at tag ${WP_TAG}" >&2
		echo "  expected ${sum}" >&2
		echo "  actual   ${actual}" >&2
		echo "  If WordPress re-tagged, follow the bump procedure in this script's header." >&2
		exit 1
	fi
	verified=$((verified + 1))
done <<< "${PINNED}"

if [ "${verified}" -ne "${expected}" ]; then
	echo "error: verified ${verified} of ${expected} files. The loop did not run to completion." >&2
	exit 1
fi

# A content control as well as a hash: a matching checksum on a file that is not
# what we think it is would still be wrong, and this names the class the shim
# instantiates.
if ! grep -q 'class WP_Block_Parser' "${inc}/class-wp-block-parser.php"; then
	echo "error: class-wp-block-parser.php does not declare WP_Block_Parser." >&2
	exit 1
fi

echo "fetched and verified ${verified} WordPress ${WP_TAG} file(s) into ${inc}" >&2
printf '%s\n' "${root}"
