# PR Rejection Rules (PR-1..PR-22)

These are the **auto-reject** conditions. `@pr-reviewer` rejects the PR — no human discretion, no "we'll fix it next sprint." Each one references the underlying rule it enforces.

When a PR fails any of these, `@pr-reviewer` returns BLOCK with the rule ID and the routing target.

---

| ID | Reject if… | Underlying rule | Route fix to |
|---|---|---|---|
| **PR-1** | Any file in `mod/fastpix/` contains `fastpix.io` or `api.fastpix`. | A3 | `@local-fastpix-contract` |
| **PR-2** | Any file uses `curl_*`, `\core\http_client`, raw Guzzle, or `file_get_contents('http...')`. | A2 | `@local-fastpix-contract` |
| **PR-3** | Any code imports `\local_fastpix\api\gateway`, `\local_fastpix\service\jwt_signing_service`, or any `\local_fastpix\webhook\*` namespace. | A4 / CC1 | `@local-fastpix-contract` |
| **PR-4** | Any code references `filter_fastpix` or `tinymce_fastpix` (test fixtures excepted). | A4 | `@backend-architect` |
| **PR-5** | Any `$DB->insert_record`, `update_record`, or `delete_records` against `local_fastpix_*` tables. | A5 | `@local-fastpix-contract` |
| **PR-6** | Any `$DB->insert_record`, `update_record`, or `delete_records` against `grade_grades` or `grade_items`. | CG1 | `@completion-grading` |
| **PR-7** | Any external function omits `validate_context`, `require_capability`, or session token verification. | S3 | `@watch-tracker` or `@playback-view` |
| **PR-8** | Any session token comparison uses `===` or `==` instead of `hash_equals`. | S2 | `@privacy-security` or `@watch-tracker` |
| **PR-9** | A change to `record_view_progress` or `watch_tracker_service` that drops, reorders, or short-circuits any of the six fraud checks. | S4 | `@watch-tracker` |
| **PR-10** | The 10-second tolerance in fraud checks 2 or 4 is changed to a different value. | S4 / §10.4 | `@backend-architect` (requires ADR) |
| **PR-11** | A new behavior without test coverage. | M6 | `@testing` |
| **PR-12** | A new column or table without `db/upgrade.php` step + `version.php` bump. | M5 | `@backend-architect` (or whoever changed schema) |
| **PR-13** | A new dependency added via `composer.json`. | — (we don't have one) | `@backend-architect` |
| **PR-14** | A new capability defined other than the FIVE in M3 (the four design-doc caps + `mod/fastpix:uploadmedia` per ADR-012). | M3 | `@backend-architect` (requires fresh ADR) |
| **PR-15** | A "reconciler" task or service introduced. | ADR-003 deferred | `@backend-architect` |
| **PR-16** | A "watermark" / "burn-in" feature introduced. | ADR-005 withdrawn | `@backend-architect` |
| **PR-17** | A `mod_fastpix` external function calls `local_fastpix_test_connection` or `local_fastpix_send_test_event`. | CC3 | `@local-fastpix-contract` |
| **PR-18** | Direct `$DB->get_record('local_fastpix_asset', ...)` or `local_fastpix_track` (outside test fixtures). | CC5 | `@local-fastpix-contract` |
| **PR-19** | An additional custom completion rule beyond `completionwatchedpercent`. | CG3 | `@backend-architect` |
| **PR-20** | A skipped or commented-out test (`markTestSkipped` without ticket reference, `/* test_... */`). | M6 | `@testing` |
| **PR-21** | A log line includes raw `userid`, raw `session_token`, raw `playback-token`, or `session_secret` material. | S6 | `@privacy-security` |
| **PR-22** | A "fix" for cross-FastPix-account restore that copies asset bytes or attempts to recreate the asset on the target account. | M9 / ADR-010 | `@backend-architect` |

---

## How `@pr-reviewer` runs these

For each PR:

1. Run every grep / static-analysis script in `.claude/ci-checks/`.
2. Map each non-zero exit to a PR-rule ID.
3. Run the coverage gate; map any failure to PR-11.
4. Manual review for the rules that can't be greped (S4 fraud-check order, CG3 single rule, etc.).

If any rule triggers:
```
BLOCK — PR-<N> — <rule statement>
Route fix to: @<agent-name>
Rationale: <one-line summary citing the source>
```

No PR merges past a BLOCK. The fix goes back through the routing target's normal process.

---

## What `@pr-reviewer` does NOT reject for

- Style preferences not in `moodle-plugin-ci`'s phpcs config
- Comment density (the rules say "default to no comments" but enforcement is by review, not auto-reject)
- Variable naming (subjective — flag in review, don't block)
- Line count / file length (no hard limit)
- Whether a method "could be simpler" (subjective — flag in review)

The reject list is for objective, mechanical failures that map to a documented rule. Subjective concerns get flagged as "consider" comments and don't block merge.
