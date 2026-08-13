#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

if ! command -v git >/dev/null 2>&1; then
	echo "Git is required to build Site Agent." >&2
	exit 1
fi

if ! git diff --quiet || ! git diff --cached --quiet; then
	echo "Commit or stash tracked changes before building so the ZIP matches HEAD." >&2
	exit 1
fi

version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' site-agent.php | head -n 1 | tr -d '\r')"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Could not read a semantic version from site-agent.php." >&2
	exit 1
fi

mkdir -p dist
artifact="dist/site-agent-${version}.zip"
rm -f "$artifact"

git archive \
	--format=zip \
	--prefix=site-agent/ \
	--output="$artifact" \
	HEAD \
	README.md \
	assets \
	includes \
	readme.txt \
	site-agent.php \
	uninstall.php

echo "$artifact"
