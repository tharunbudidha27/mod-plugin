# Prompt — Generate Gradebook Integration (Phase D)

```
You are @completion-grading working on mod_fastpix.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §3 Phase D
- .claude/skills/09-gradebook-integration.md
- .claude/rules/completion-grading.md (CG1, CG2, CG4)

TASK: Update:
- mod/fastpix/lib.php — fill in mod_fastpix_grade_item_update + mod_fastpix_update_grades
- mod/fastpix/classes/service/watch_tracker_service.php — add recompute_completion (called on every clean callback)

REQUIREMENTS:
1. mod_fastpix_grade_item_update($activity, $grades = null):
   - require_once gradelib.php.
   - Build $params: itemname=$activity->name, gradetype=GRADE_TYPE_VALUE, grademax=$activity->grademax (default 100), grademin=0.
   - Handle $grades === 'reset' by setting $params['reset'] = true and $grades = null.
   - Return grade_update('mod/fastpix', $activity->course, 'mod', 'fastpix', $activity->id, 0, $grades, $params).
2. mod_fastpix_update_grades($activity = null, $userid = 0, $nullifnone = true):
   - $activity null → iterate all rows in mdl_fastpix and recurse for each.
   - $userid 0 → all users for the activity; else single user.
   - For each attempt: build $grades array. If completion_state='complete' → rawgrade=$activity->grademax, dategraded=last_callback_ts. If $nullifnone → rawgrade=null.
   - Call mod_fastpix_grade_item_update($activity, $grades).
3. watch_tracker_service::recompute_completion($activity, $userid):
   - Read pre-state attempt.completion_state.
   - Call completion_info::update_state($cm, COMPLETION_UNKNOWN, $userid) (CG4 — pass UNKNOWN, not COMPLETE).
   - Re-read attempt; if transitioned in_progress → complete, call mod_fastpix_grade_item_update with the user's grade.
   - Return final completion_state.

DO NOT:
- Direct INSERT/UPDATE on mdl_grade_grades or mdl_grade_items (PR-6, CG1).
- Pass COMPLETION_COMPLETE to update_state (CG4 — bypasses our rule).
- Re-write the grade on every callback past 100% (idempotent — only on transition).

VALIDATION:
- First transition writes grade once.
- Subsequent callbacks past 100% don't re-write grade (idempotent).
- Site-wide mod_fastpix_update_grades(null) regrades all activities.
- CI grep grep-no-grade-grades-write.sh returns zero matches.
- Coverage ≥ 85%.
```
