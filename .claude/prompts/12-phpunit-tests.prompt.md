# Prompt — Generate PHPUnit Test Suite

```
You are @testing working on mod_fastpix.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §5
- .claude/skills/12-phpunit-tests.md
- .claude/rules/moodle-mod.md (M6 — coverage gates)
- .claude/rules/security.md (S4 — boundary tests for fraud checks)

TASK: Generate the relevant test class for the surface you're building:
- tests/lib_test.php (Phase A) — _supports() + capability set
- tests/mod_form_test.php (Phase B) — validation rules
- tests/playback_service_test.php (Phase C)
- tests/session_token_service_test.php (Phase C)
- tests/record_view_progress_test.php (Phase D) — boundary tests for ALL 6 fraud checks
- tests/custom_completion_test.php (Phase D)
- tests/grade_completion_integration_test.php (Phase D)
- tests/backup_restore_test.php (Phase E)
- tests/privacy_provider_test.php (Phase E)
- tests/log_redaction_test.php (Phase F)

REQUIREMENTS — applicable to every test class:
1. Extends \advanced_testcase.
2. setUp() calls $this->resetAfterTest(true).
3. Time-dependent tests inject a frozen clock to prevent flake on second-boundary crossings.
4. Mock \local_fastpix\service\* services (CC1) — never the gateway.
5. Test names are descriptive: test_<method>_<scenario>_<expected_outcome>.
6. NO markTestSkipped without a tracked ticket reference (PR-20).

REQUIREMENTS — record_view_progress_test.php specifically (S4):
1. ONE boundary test pair per fraud check (6 checks → 12+ tests minimum):
   - test_check<N>_at_<boundary>_accepts → ACCEPT, watched_seconds updated, fraud_count unchanged.
   - test_check<N>_above_<boundary>_rejects → REJECT, watched_seconds unchanged, fraud_count incremented, last_fraud_reason correct.
2. Tolerance boundary tests for checks 2 and 4 specifically:
   - At gain = elapsed + 10 → accept (≤ tolerance).
   - At gain = elapsed + 11 → reject.
3. Capability-lost test:
   - User starts with capability → callback accepts.
   - Mid-session capability removed → next callback rejects with capability_lost.
4. fraud_count > 20 test:
   - Even at fraud_count = 21 or higher, callbacks continue to be processed (S5 — soft-block, not hard-kill).

REQUIREMENTS — log_redaction_test.php (S6):
1. Send a callback with a known session_token.
2. Capture all debugging() / mtrace / error_log output.
3. Assert NO log line contains the session_token, userid, or playback_token strings.

DO NOT:
- Make HTTP calls in tests (mock at the local_fastpix service boundary).
- Hardcode userids 1, 2 (use generators per Moodle convention).
- Test implementation details ("this calls $DB->update_record") — test outcomes ("watched_seconds is X after the call").

VALIDATION — coverage gates per M6:
- record_view_progress: ≥ 90%
- watch_tracker_service: ≥ 90%
- session_token_service: ≥ 90%
- custom_completion: ≥ 85%
- mod_form validation: ≥ 85%
- privacy_provider: ≥ 85%
```
