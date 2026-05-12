#!/usr/bin/env bash
# Enforces rules A2 / A3 / PR-1 / PR-2:
# mod_fastpix makes ZERO HTTP calls. No curl_*, no \core\http_client, no Guzzle,
# no file_get_contents('http...'). The literal "fastpix.io" or "api.fastpix"
# does not appear anywhere in mod/fastpix/ source.
#
# Test fixtures (under tests/) are exempt.
# Documentation (under .claude/) is exempt.

set -euo pipefail

ROOT="${1:-mod/fastpix}"

if [ ! -d "$ROOT" ]; then
    echo "ci-check: directory not found: $ROOT" >&2
    exit 2
fi

EXIT=0

# HTTP client patterns (A2 / PR-2).
HTTP_PATTERNS=(
    'curl_init'
    'curl_exec'
    'curl_setopt'
    '\\\\core\\\\http_client'
    'core\\\\http_client'
    'http_client::'
    'GuzzleHttp\\\\Client'
    'file_get_contents\([^)]*http'
)

for pat in "${HTTP_PATTERNS[@]}"; do
    matches=$(grep -rE "$pat" "$ROOT" \
        --include='*.php' --include='*.js' \
        --exclude-dir=tests --exclude-dir=.claude --exclude-dir=node_modules --exclude-dir=vendor \
        2>/dev/null || true)
    if [ -n "$matches" ]; then
        echo "BLOCK — PR-2 — HTTP client used in mod_fastpix (forbidden; use local_fastpix services): $pat"
        echo "$matches"
        echo
        EXIT=1
    fi
done

# fastpix.io literal (A3 / PR-1).
LITERAL_PATTERNS=(
    'fastpix\.io'
    'api\.fastpix'
)

for pat in "${LITERAL_PATTERNS[@]}"; do
    matches=$(grep -rE "$pat" "$ROOT" \
        --include='*.php' --include='*.js' --include='*.mustache' \
        --exclude-dir=tests --exclude-dir=.claude --exclude-dir=node_modules \
        2>/dev/null || true)
    if [ -n "$matches" ]; then
        echo "BLOCK — PR-1 — fastpix.io literal in mod_fastpix source: $pat"
        echo "$matches"
        echo
        EXIT=1
    fi
done

if [ "$EXIT" -eq 0 ]; then
    echo "ci-check grep-no-curl.sh: PASS"
fi

exit "$EXIT"
