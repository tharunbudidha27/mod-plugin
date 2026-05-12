#!/usr/bin/env bash
# Enforces rule CG1 / PR-6:
# Gradebook writes go ONLY through grade_update(). Direct INSERT/UPDATE/DELETE
# on mdl_grade_grades or mdl_grade_items is forbidden.
#
# Test fixtures (under tests/) are exempt.

set -euo pipefail

ROOT="${1:-mod/fastpix}"

if [ ! -d "$ROOT" ]; then
    echo "ci-check: directory not found: $ROOT" >&2
    exit 2
fi

PATTERNS=(
    '\$DB->insert_record\([^)]*[\x27"]grade_grades'
    '\$DB->insert_record\([^)]*[\x27"]grade_items'
    '\$DB->update_record\([^)]*[\x27"]grade_grades'
    '\$DB->update_record\([^)]*[\x27"]grade_items'
    '\$DB->set_field\([^)]*[\x27"]grade_grades'
    '\$DB->set_field\([^)]*[\x27"]grade_items'
    '\$DB->delete_records\([^)]*[\x27"]grade_grades'
    '\$DB->delete_records\([^)]*[\x27"]grade_items'
)

EXIT=0

for pat in "${PATTERNS[@]}"; do
    matches=$(grep -rE "$pat" "$ROOT" \
        --include='*.php' \
        --exclude-dir=tests --exclude-dir=.claude --exclude-dir=node_modules \
        2>/dev/null || true)
    if [ -n "$matches" ]; then
        echo "BLOCK — PR-6 — direct gradebook table write (use grade_update()): $pat"
        echo "$matches"
        echo
        EXIT=1
    fi
done

if [ "$EXIT" -eq 0 ]; then
    echo "ci-check grep-no-grade-grades-write.sh: PASS"
fi

exit "$EXIT"
