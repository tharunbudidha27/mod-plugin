# Prompt — Generate record_view_progress + 6 Fraud Checks (Phase D)

```
You are @watch-tracker working on mod_fastpix.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §3 Phase D and §10.3 (the six fraud checks verbatim from the design doc)
- .claude/skills/06-record-view-progress.md
- .claude/rules/security.md (S2, S3, S4, S5)
- .claude/rules/architecture.md (A1, A6)
- .claude/rules/consumer-contract.md (CC1, CC5, CC8)

TASK: Generate:
- mod/fastpix/classes/external/record_view_progress.php
- mod/fastpix/classes/service/watch_tracker_service.php
- mod/fastpix/classes/dto/record_progress_result.php
- Updated mod/fastpix/db/services.php registering the external function

REQUIREMENTS — EXTERNAL FUNCTION (no business logic; A6):
1. Parameters: activity_id (PARAM_INT), watched_seconds (PARAM_INT), client_seek_count (PARAM_INT), session_token (PARAM_ALPHANUMEXT).
2. self::validate_parameters → context_module → self::validate_context (login + sesskey) → require_capability('mod/fastpix:view').
3. Delegate to \mod_fastpix\service\watch_tracker_service::record_progress.
4. Return: { accepted: bool, fraud_reason: string|null, completion_state: string }.
5. db/services.php entry: methodname mod_fastpix_record_view_progress, type=write, capabilities=mod/fastpix:view, ajax=true.

REQUIREMENTS — SERVICE (the six checks IN THIS ORDER, S4):
1. TOLERANCE_SECONDS = 10. CONST. Do NOT make this configurable. PR-10 auto-rejects loosening.
2. Load activity, attempt, asset (asset via asset_service::get_by_internal_id — CC1).
3. Verify session token (S2 — hash_equals via session_token_service::verify).
4. Compute elapsed_session = now - attempt.session_start_ts; elapsed_since_last = now - (attempt.last_callback_ts ?? attempt.session_start_ts).
5. Run ALL six checks (do NOT short-circuit; record all violations, return the first reason):
   - Check 1: watched_seconds > asset.duration → 'exceeds_duration'.
   - Check 2: watched_seconds > elapsed_session + TOLERANCE_SECONDS → 'exceeds_wall_clock'.
   - Check 3: watched_seconds < attempt.watched_seconds → 'regression'.
   - Check 4: (watched_seconds - attempt.watched_seconds) > elapsed_since_last + TOLERANCE_SECONDS → 'implausible_gain'.
   - Check 5: !has_capability('mod/fastpix:view', context, userid) → 'capability_lost'.
   - Check 6: asset.no_skip_required && client_seek_count > attempt.seek_count → 'seek_on_noskip'.
6. If any reasons fired:
   - Increment fraud_count by 1 (NOT by reason count).
   - Set last_fraud_reason = reasons[0].
   - Do NOT update watched_seconds.
   - Return rejected result with fraud_reason and current completion_state.
7. If clean:
   - UPDATE attempt: watched_seconds, seek_count, last_callback_ts.
   - Fire milestones (CG5 — idempotent; only if crossing 25/50/75/100% boundary AND timestamp column is NULL).
   - Recompute completion via completion_info::update_state(COMPLETION_UNKNOWN) (CG4).
   - On transition to complete, write grade via grade_update (CG1, CG4 — only on transition).

DO NOT:
- Reorder or skip any check (PR-9).
- Loosen the 10-second tolerance (PR-10).
- Short-circuit at the first failing check (record all violations).
- Update watched_seconds on a fraud callback.
- Write grade_grades directly (PR-6 / CG1).
- Call the gateway (PR-3 / CC1).
- Log session_token, userid, or other PII (S6 / PR-21).
- Stop accepting callbacks at fraud_count > 20 (S5 — keep accepting; surface a badge).

VALIDATION:
- Boundary tests for all 6 checks per Skill 12.
- record_view_progress external coverage ≥ 90% (M6).
- watch_tracker_service coverage ≥ 90% (M6).
- ci-check grep-no-grade-grades-write.sh passes.
- ci-check grep-session-token-on-progress.sh passes (session_token verified before any state mutation).
```
