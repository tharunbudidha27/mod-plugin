#!/usr/bin/env bash
# Enforces rules S2 / PR-7 / PR-8:
# 1. record_view_progress external function MUST verify session_token.
# 2. session_token comparisons MUST use hash_equals (constant-time).
#    === or == on session_token fails PR-8.
#
# This check is heuristic but catches the common failures.

set -euo pipefail

ROOT="${1:-mod/fastpix}"

if [ ! -d "$ROOT" ]; then
    echo "ci-check: directory not found: $ROOT" >&2
    exit 2
fi

EXIT=0

# 1. record_view_progress.php must reference session_token verification.
RVP_FILE="$ROOT/classes/external/record_view_progress.php"
if [ -f "$RVP_FILE" ]; then
    if ! grep -qE 'session_token' "$RVP_FILE"; then
        echo "BLOCK — PR-7 — record_view_progress.php does not reference session_token"
        EXIT=1
    fi
    # And the service it delegates to must call session_token_service::verify
    SVC_FILE="$ROOT/classes/service/watch_tracker_service.php"
    if [ -f "$SVC_FILE" ] && ! grep -qE 'session_token_service.*verify' "$SVC_FILE"; then
        echo "BLOCK — PR-7 — watch_tracker_service does not call session_token_service::verify"
        EXIT=1
    fi
fi

# 2. session_token comparisons must use hash_equals, not === or ==.
EQUALITY_PATTERNS=(
    'session_token\s*===\s*'
    'session_token\s*==\s*'
    '===\s*[^;]*session_token'
    '==\s*[^;]*session_token'
)

for pat in "${EQUALITY_PATTERNS[@]}"; do
    matches=$(grep -rE "$pat" "$ROOT" \
        --include='*.php' \
        --exclude-dir=tests --exclude-dir=.claude --exclude-dir=node_modules \
        2>/dev/null || true)
    if [ -n "$matches" ]; then
        echo "BLOCK — PR-8 — session_token compared with ==/=== (use hash_equals): $pat"
        echo "$matches"
        echo
        EXIT=1
    fi
done

if [ "$EXIT" -eq 0 ]; then
    echo "ci-check grep-session-token-on-progress.sh: PASS"
fi

exit "$EXIT"
