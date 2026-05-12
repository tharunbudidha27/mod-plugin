# Skill 09 — Gradebook Integration

**Owner agent:** `@completion-grading`.

**When to invoke:** Phase D, step 4.

---

## Inputs

- `docs/02-mod-fastpix.md` §3 Phase D.
- `.claude/rules/completion-grading.md` (CG1, CG2, CG4).
- Moodle's `grade_update()` API.

## Outputs

- `mod_fastpix_grade_item_update($activity, $grades = null)` in `lib.php`
- `mod_fastpix_update_grades($activity = null, $userid = 0, $nullifnone = true)` in `lib.php`
- Grade-write logic inside `watch_tracker_service::record_progress` on transitions

## Steps

### 1. mod_fastpix_grade_item_update — create/update the grade_item

```php
function mod_fastpix_grade_item_update($activity, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname' => $activity->name,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => $activity->grademax ?? 100,
        'grademin'  => 0,
    ];

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/fastpix', $activity->course, 'mod', 'fastpix',
                        $activity->id, 0, $grades, $params);
}
```

### 2. mod_fastpix_update_grades — bulk regrade entry point

```php
function mod_fastpix_update_grades($activity = null, $userid = 0, $nullifnone = true) {
    global $DB;

    if ($activity === null) {
        // Site-wide regrade — iterate all activities of this type.
        $rs = $DB->get_recordset('fastpix');
        foreach ($rs as $a) {
            mod_fastpix_update_grades($a, $userid, $nullifnone);
        }
        $rs->close();
        return;
    }

    // Per-activity regrade.
    $where = ['activity_id' => $activity->id];
    if ($userid) {
        $where['userid'] = $userid;
    }
    $attempts = $DB->get_records('fastpix_attempt', $where);

    $grades = [];
    foreach ($attempts as $attempt) {
        if ($attempt->completion_state === 'complete') {
            $grades[$attempt->userid] = (object)[
                'userid' => $attempt->userid,
                'rawgrade' => $activity->grademax,
                'dategraded' => $attempt->last_callback_ts,
            ];
        } else if ($nullifnone) {
            $grades[$attempt->userid] = (object)[
                'userid' => $attempt->userid,
                'rawgrade' => null,
            ];
        }
    }

    if (!empty($grades)) {
        mod_fastpix_grade_item_update($activity, $grades);
    }
}
```

### 3. Inside watch_tracker_service — write grade ON TRANSITION ONLY (CG4)

```php
private function recompute_completion(stdClass $activity, int $userid): string {
    $cm = get_coursemodule_from_instance('fastpix', $activity->id);
    $course = $DB->get_record('course', ['id' => $activity->course], '*', MUST_EXIST);

    // Pre-state.
    $attempt = $DB->get_record('fastpix_attempt', [
        'userid' => $userid, 'activity_id' => $activity->id,
    ]);
    $was_complete = ($attempt->completion_state === 'complete');

    // Ask Moodle to recompute (CG4).
    $completion_info = new \completion_info($course);
    if ($completion_info->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
        $completion_info->update_state($cm, COMPLETION_UNKNOWN, $userid);
    }

    // Re-read after recompute.
    $attempt_after = $DB->get_record('fastpix_attempt', [
        'userid' => $userid, 'activity_id' => $activity->id,
    ]);

    // Did we transition to complete? Then write grade.
    if (!$was_complete && $attempt_after->completion_state === 'complete') {
        $grade = (object)[
            'userid' => $userid,
            'rawgrade' => $activity->grademax,
            'dategraded' => time(),
        ];
        \mod_fastpix_grade_item_update($activity, [$userid => $grade]);
    }

    return $attempt_after->completion_state;
}
```

## Validation

- `tests/grade_completion_integration_test.php`:
  - First transition to complete → `grade_update` called once.
  - Subsequent callbacks past 100% → `grade_update` NOT called again (idempotent per CG4).
  - User who never reaches threshold → no grade_grades row.
  - Site-wide `mod_fastpix_update_grades(null)` regrades all activities.
  - Per-user regrade only updates that user.
- CI grep `grep-no-grade-grades-write.sh` passes (no direct table writes).
- Coverage target: ≥ 85%.
