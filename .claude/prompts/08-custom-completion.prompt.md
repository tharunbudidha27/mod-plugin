# Prompt — Generate Custom Completion (Phase D)

```
You are @completion-grading working on mod_fastpix.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §3 Phase D
- .claude/skills/08-custom-completion.md
- .claude/rules/completion-grading.md (CG3, CG4)
- .claude/rules/consumer-contract.md (CC1)

TASK: Generate:
- mod/fastpix/classes/completion/custom_completion.php
- Updated mod/fastpix/lib.php — fill in mod_fastpix_get_completion_active_rule_descriptions

REQUIREMENTS:
1. custom_completion extends \core_completion\activity_custom_completion.
2. get_defined_custom_rules() returns ['completionwatchedpercent'] — EXACTLY ONE rule per CG3.
3. get_state(rule):
   - If rule != 'completionwatchedpercent' → throw coding_exception (defensive).
   - Load activity from mdl_fastpix.
   - threshold = activity.completion_watch_percent. If <=0 or >100 → COMPLETION_INCOMPLETE.
   - Load attempt from mdl_fastpix_attempt by (userid, activity_id). If null → COMPLETION_INCOMPLETE.
   - Load asset via asset_service::get_by_internal_id (CC1). If null or duration is null/0 → COMPLETION_INCOMPLETE (NOT silently complete).
   - percent = (watched_seconds / duration) * 100.
   - Return percent >= threshold ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE.
4. get_custom_rule_descriptions() returns lang string with the threshold interpolated.
5. get_sort_order() returns ['completionview', 'completionwatchedpercent', 'completionusegrade'].
6. lib.php callback: mod_fastpix_get_completion_active_rule_descriptions returns the lang string for the rule when threshold > 0.

DO NOT:
- Add a second rule (PR-19).
- Read from mdl_local_fastpix_asset directly (CC5 — use asset_service).
- Treat NULL duration as complete (silently masks an ingestion bug).
- Cache state across requests (Moodle's completion API has its own caching).

VALIDATION:
- threshold=0 → COMPLETION_INCOMPLETE.
- threshold=80, watched=80% of duration → COMPLETION_COMPLETE (boundary inclusive).
- threshold=80, watched=79.9% → COMPLETION_INCOMPLETE.
- attempt missing → COMPLETION_INCOMPLETE.
- asset.duration NULL or 0 → COMPLETION_INCOMPLETE.
- Calling get_state('other_rule') → coding_exception.
- custom_completion coverage ≥ 85% (M6).
```
