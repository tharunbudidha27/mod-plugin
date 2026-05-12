#!/usr/bin/env bash
# Enforces rule A5 / PR-5:
# mod_fastpix MUST NOT INSERT, UPDATE, or DELETE rows in mdl_local_fastpix_*
# tables. Reads (via asset_service) are allowed. Direct $DB->get_record on
# local_fastpix tables also fails CC5 / PR-18.
#
# Test fixtures (under tests/) are exempt.

set -euo pipefail

ROOT="${1:-mod/fastpix}"

if [ ! -d "$ROOT" ]; then
    echo "ci-check: directory not found: $ROOT" >&2
    exit 2
fi

# Direct write patterns against local_fastpix tables.
WRITE_PATTERNS=(
    '\$DB->insert_record\([^)]*[\x27"]local_fastpix'
    '\$DB->insert_records\([^)]*[\x27"]local_fastpix'
    '\$DB->update_record\([^)]*[\x27"]local_fastpix'
    '\$DB->set_field\([^)]*[\x27"]local_fastpix'
    '\$DB->delete_records\([^)]*[\x27"]local_fastpix'
    '\$DB->delete_records_select\([^)]*[\x27"]local_fastpix'
    '\$DB->execute\([^)]*UPDATE\s+\{?local_fastpix'
    '\$DB->execute\([^)]*DELETE\s+FROM\s+\{?local_fastpix'
    '\$DB->execute\([^)]*INSERT\s+INTO\s+\{?local_fastpix'
)

# Direct read patterns (CC5 / PR-18).
READ_PATTERNS=(
    '\$DB->get_record\([^)]*[\x27"]local_fastpix_asset'
    '\$DB->get_record\([^)]*[\x27"]local_fastpix_track'
    '\$DB->get_records\([^)]*[\x27"]local_fastpix_asset'
    '\$DB->get_records\([^)]*[\x27"]local_fastpix_track'
)

EXIT=0

for pat in "${WRITE_PATTERNS[@]}"; do
    matches=$(grep -rE "$pat" "$ROOT" \
        --include='*.php' \
        --exclude-dir=tests --exclude-dir=.claude --exclude-dir=node_modules \
        2>/dev/null || true)
    if [ -n "$matches" ]; then
        echo "BLOCK — PR-5 — direct write to local_fastpix table: $pat"
        echo "$matches"
        echo
        EXIT=1
    fi
done

for pat in "${READ_PATTERNS[@]}"; do
    matches=$(grep -rE "$pat" "$ROOT" \
        --include='*.php' \
        --exclude-dir=tests --exclude-dir=.claude --exclude-dir=node_modules \
        2>/dev/null || true)
    if [ -n "$matches" ]; then
        echo "BLOCK — PR-18 — direct read of local_fastpix table (use asset_service): $pat"
        echo "$matches"
        echo
        EXIT=1
    fi
done

if [ "$EXIT" -eq 0 ]; then
    echo "ci-check grep-no-direct-table-write.sh: PASS"
fi

exit "$EXIT"
