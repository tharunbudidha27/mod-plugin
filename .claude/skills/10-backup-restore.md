# Skill 10 — Backup / Restore Stepslibs

**Owner agent:** `@backup-restore`.

**When to invoke:** Phase E.

---

## Inputs

- `docs/02-mod-fastpix.md` §3 Phase E.
- ADR-010 (cross-FastPix-account "Video unavailable").
- `.claude/rules/moodle-mod.md` (M9).
- Moodle's backup/restore API documentation.

## Outputs

- `mod/fastpix/backup/moodle2/backup_fastpix_activity_task.class.php`
- `mod/fastpix/backup/moodle2/backup_fastpix_stepslib.php`
- `mod/fastpix/backup/moodle2/restore_fastpix_activity_task.class.php`
- `mod/fastpix/backup/moodle2/restore_fastpix_stepslib.php`
- `mod_fastpix_pre_course_module_delete($cm)` in `lib.php`

## Steps

### 1. backup_fastpix_stepslib

```php
class backup_fastpix_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $fastpix = new backup_nested_element('fastpix', ['id'], [
            'name', 'intro', 'introformat',
            'fastpix_asset_id', 'completion_watch_percent',
            'no_skip_required', 'default_show_captions',
            'grademax', 'timecreated', 'timemodified',
        ]);

        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid', 'asset_id', 'session_token', 'session_start_ts',
            'last_callback_ts', 'watched_seconds', 'seek_count',
            'fraud_count', 'last_fraud_reason', 'completion_state',
            'milestone_25_at', 'milestone_50_at',
            'milestone_75_at', 'milestone_100_at',
        ]);

        $fastpix->add_child($attempts);
        $attempts->add_child($attempt);

        $fastpix->set_source_table('fastpix', ['id' => backup::VAR_ACTIVITYID]);
        if ($userinfo) {
            $attempt->set_source_table('fastpix_attempt', ['activity_id' => backup::VAR_PARENTID]);
        }

        $attempt->annotate_ids('user', 'userid');

        return $this->prepare_activity_structure($fastpix);
    }
}
```

Note: we backup the `fastpix_asset_id` REFERENCE (M9). We do NOT backup asset bytes — FastPix owns those.

### 2. restore_fastpix_stepslib

```php
class restore_fastpix_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {
        $paths = [
            new restore_path_element('fastpix', '/activity/fastpix'),
            new restore_path_element('attempt', '/activity/fastpix/attempts/attempt'),
        ];
        return $this->prepare_activity_structure($paths);
    }

    protected function process_fastpix($data) {
        global $DB;
        $data = (object)$data;

        // Map course id.
        $data->course = $this->get_courseid();
        $oldid = $data->id;
        unset($data->id);

        // Look up the asset on this site.
        $asset_internal_id = $data->fastpix_asset_id;
        if ($asset_internal_id) {
            $asset_exists = \local_fastpix\service\asset_service::instance()
                ->get_by_internal_id($asset_internal_id);
            if (!$asset_exists || $asset_exists->deleted_at) {
                // Cross-account or deleted — set to null. View.php will show "Video unavailable" (ADR-010).
                $data->fastpix_asset_id = null;
            }
        }

        $data->timecreated = $data->timemodified = time();
        $newid = $DB->insert_record('fastpix', $data);

        $this->apply_activity_instance($newid);
    }

    protected function process_attempt($data) {
        global $DB;
        $data = (object)$data;
        $data->activity_id = $this->get_new_parentid('fastpix');
        $data->userid = $this->get_mappingid('user', $data->userid);
        unset($data->id);
        $DB->insert_record('fastpix_attempt', $data);
    }
}
```

### 3. mod_fastpix_pre_course_module_delete (recycle bin)

```php
function mod_fastpix_pre_course_module_delete($cm) {
    global $DB;
    $activity = $DB->get_record('fastpix', ['id' => $cm->instance]);
    if (!$activity || !$activity->fastpix_asset_id) {
        return;
    }

    // Soft-delete the asset only if no other activity references it.
    $other_refs = $DB->count_records('fastpix', [
        'fastpix_asset_id' => $activity->fastpix_asset_id,
    ]);
    if ($other_refs <= 1) {       // counting ourselves
        \local_fastpix\service\asset_service::instance()
            ->soft_delete_if_unreferenced($activity->fastpix_asset_id);
    }
}
```

(Confirm `soft_delete_if_unreferenced` signature with `@local-fastpix-contract` before implementing.)

## Validation

- `tests/backup_restore_test.php`:
  - Backup with `userinfo=true` captures attempt rows.
  - Backup with `userinfo=false` does NOT capture attempt rows.
  - Restore on same FastPix account: video plays after restore (mock asset_service).
  - Restore on cross-FastPix-account (asset_service returns null): activity row created with `fastpix_asset_id = null`; view.php shows "Video unavailable."
  - Restore does NOT throw, does NOT corrupt restore on cross-account scenario.
  - User mappings work: old userid → new userid in restored attempts.
- Behat: backup-restore round-trip in `add_activity.feature`.
- Coverage target: ≥ 85%.
