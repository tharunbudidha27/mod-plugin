#!/usr/bin/env bash
# Enforces rule A4 / CC1 / PR-3:
# mod_fastpix MUST NOT import \local_fastpix\api\gateway,
# \local_fastpix\service\jwt_signing_service, or any \local_fastpix\webhook\*.
# It MUST NOT import filter_fastpix or tinymce_fastpix at all.
#
# The allowed consumed surface is exactly:
#   \local_fastpix\service\asset_service
#   \local_fastpix\service\playback_service
#   \local_fastpix\service\upload_service
#   \local_fastpix\service\feature_flag_service
#
# Test fixtures (under tests/) are exempt.

set -euo pipefail

ROOT="${1:-mod/fastpix}"

if [ ! -d "$ROOT" ]; then
    echo "ci-check: directory not found: $ROOT" >&2
    exit 2
fi

# Patterns that must NOT appear outside tests/ and .claude/.
PATTERNS=(
    'local_fastpix\\\\api\\\\gateway'
    'local_fastpix\\\\service\\\\jwt_signing'
    'local_fastpix\\\\webhook\\\\'
    'local_fastpix\\\\dto\\\\'
    'filter_fastpix'
    'tinymce_fastpix'
)

EXIT=0

for pat in "${PATTERNS[@]}"; do
    matches=$(grep -rE "$pat" "$ROOT" \
        --include='*.php' --include='*.js' --include='*.mustache' \
        --exclude-dir=tests --exclude-dir=.claude --exclude-dir=node_modules \
        2>/dev/null || true)
    if [ -n "$matches" ]; then
        echo "BLOCK — PR-3 / PR-4 — forbidden pattern: $pat"
        echo "$matches"
        echo
        EXIT=1
    fi
done

if [ "$EXIT" -eq 0 ]; then
    echo "ci-check grep-no-direct-gateway.sh: PASS"
fi

exit "$EXIT"
