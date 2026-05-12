# Completion & Grading Rules (CG1–CG5)

These rules govern how `mod_fastpix` integrates with Moodle's gradebook and completion APIs. Cited by `@completion-grading`, `@watch-tracker`, and `@pr-reviewer`.

---

## CG1 — Gradebook writes ONLY through `grade_update()`

**Rule.** Every grade write goes through the documented Moodle API:

```php
grade_update(
    'mod/fastpix',          // source — must match the plugin frankenstyle
    $courseid,
    'mod',
    'fastpix',
    $iteminstance,          // mdl_fastpix.id
    0,                      // itemnumber
    $grades,                // array with userid, rawgrade, dategraded
    $itemdetails            // array with itemname, gradetype, grademax, grademin
);
```

Direct `INSERT`/`UPDATE`/`DELETE` on `mdl_grade_grades` or `mdl_grade_items` is forbidden. The Moodle gradebook has callbacks, observers, and integrity checks that `grade_update()` triggers — bypassing them corrupts gradebook state in ways that surface weeks later in reports.

**Enforcement.** CI script `.claude/ci-checks/grep-no-grade-grades-write.sh` — `grep -rE '\\\$DB->(insert_record|update_record|delete_records)\\(.*grade_(grades|items)' mod/fastpix/` returns zero matches.

**Failure routing.** `@completion-grading`.

---

## CG2 — `grade_item_update` and `update_grades` are mandatory `lib.php` callbacks

**Rule.** `lib.php` must define BOTH:

```php
function mod_fastpix_grade_item_update($activity, $grades = null) {
    // Called by Moodle when activity is created/edited.
    // Creates/updates the grade_item row.
    // Delegates to the grade API; do NOT touch grade_items directly.
}

function mod_fastpix_update_grades($activity = null, $userid = 0, $nullifnone = true) {
    // Called by Moodle for bulk regrading.
    // Iterates attempts and calls grade_update().
    // Used by the gradebook recompute UI.
}
```

Forgetting either one breaks the gradebook recompute flow. Forgetting the signature defaults breaks Moodle's bulk operations.

**Enforcement.** `tests/lib_test.php` verifies both functions exist with correct signatures.

**Failure routing.** `@completion-grading`.

---

## CG3 — Custom completion implements ONE rule: `completionwatchedpercent`

**Rule.** `\mod_fastpix\completion\custom_completion` extends `\core_completion\activity_custom_completion` and implements exactly one custom rule:

```php
public static function get_defined_custom_rules(): array {
    return ['completionwatchedpercent'];
}

public function get_state(string $rule): int {
    if ($rule !== 'completionwatchedpercent') {
        throw new \coding_exception("Unknown rule: $rule");
    }
    // Read attempt.watched_seconds and asset.duration.
    // Return COMPLETION_COMPLETE if watched_seconds / duration >= threshold,
    // else COMPLETION_INCOMPLETE.
}
```

Do NOT add additional rules ("completionseekcount", "completionnoresume", etc.). They are out of scope for v1.0.

`get_custom_rule_descriptions()`, `get_sort_order()`, and `mod_fastpix_get_completion_active_rule_descriptions()` (in lib.php) all reference exactly this one rule.

**Enforcement.** `tests/custom_completion_test.php` asserts the rule set.

**Failure routing.** `@completion-grading`.

---

## CG4 — Completion + grade transitions happen together, on every progress callback

**Rule.** When `record_view_progress` accepts a non-fraud callback:

```php
// inside watch_tracker_service::record_progress()
$this->update_attempt($attempt, $watched_seconds);

$completion_info = new \completion_info($course);
$cm = get_coursemodule_from_instance('fastpix', $activity_id);
if ($completion_info->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
    $completion_info->update_state($cm, COMPLETION_UNKNOWN, $userid);
}

// And separately:
if ($completion_state_changed_to_complete) {
    $this->write_grade($activity, $userid);  // calls grade_update()
}
```

The `update_state(COMPLETION_UNKNOWN)` call is correct — it asks Moodle to recompute, which calls our `custom_completion::get_state()`. We do NOT pass `COMPLETION_COMPLETE` directly; that bypasses our rule.

The grade write happens only on the transition to complete. Subsequent callbacks past 100% do NOT re-write the grade (idempotent).

**Enforcement.** `tests/record_view_progress_test.php` asserts: completion transitions exactly once; grade is written exactly once per transition.

**Failure routing.** `@completion-grading`.

---

## CG5 — Watch milestone events are idempotent

**Rule.** `\mod_fastpix\event\watch_milestone` fires exactly once per (user, activity, milestone) pair where milestone ∈ {25, 50, 75, 100}. Idempotency is enforced by:

1. `mdl_fastpix_attempt` has columns `milestone_25_at`, `milestone_50_at`, `milestone_75_at`, `milestone_100_at` (nullable timestamps).
2. Before firing, `record_view_progress` checks if the timestamp is null. If null, set it AND fire the event in a transaction.
3. The event class extends `\core\event\base` per Moodle convention; `eventname` is `\mod_fastpix\event\watch_milestone`.

**Enforcement.** `tests/record_view_progress_test.php` asserts: progressing past 25% twice fires the event once; progressing through 100% fires four events total (25, 50, 75, 100); no event re-fires after `delete_data_for_user`.

**Failure routing.** `@completion-grading`.
