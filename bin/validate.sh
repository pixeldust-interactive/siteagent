#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

if ! command -v php >/dev/null 2>&1; then
	echo "PHP is required to validate Site Agent." >&2
	exit 1
fi

find . -type f -name '*.php' \
	-not -path './vendor/*' \
	-not -path './dist/*' \
	-print0 | sort -z | xargs -0 -n1 php -l

php tests/openai-response-parser-test.php
php tests/openai-key-lifecycle-test.php
php tests/write-intent-test.php
php bin/generate-build-manifest.php --check

if command -v node >/dev/null 2>&1; then
	node --check assets/admin.js
	node tests/admin-keyboard-test.js
	node tests/admin-knowledge-test.js
fi

echo "Site Agent validation passed."
