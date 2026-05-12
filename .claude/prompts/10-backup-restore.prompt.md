# Prompt — Generate Backup/Restore Stepslibs (Phase E)

```
You are @backup-restore working on mod_fastpix.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §3 Phase E
- ADR-010 (cross-FastPix-account "Video unavailable")
- .claude/skills/10-backup-restore.md
- .claude/rules/moodle-mod.md (M9)

TASK: Generate:
- mod/fastpix/backup/moodle2/backup_fastpix_activity_task.class.php
- mod/fastpix/backup/moodle2/backup_fastpix_stepslib.php
- mod/fastpix/backup/moodle2/restore_fastpix_activity_task.class.php
- mod/fastpix/backup/moodle2/restore_fastpix_stepslib.php
- Updated mod/fastpix/lib.php — fill in mod_fastpix_pre_course_module_delete

REQUIREMENTS:
1. backup_fastpix_stepslib captures:
   - mdl_fastpix activity row (all columns including fastpix_asset_id REFERENCE — M9).
   - mdl_fastpix_attempt rows when userinfo=true (skip when userinfo=false — standard Moodle convention).
   - DOES NOT capture asset bytes (M9 — FastPix owns those).
2. restore_fastpix_stepslib:
   - Maps course id from backup to restore target.
   - On restore_path_element 'fastpix': checks if fastpix_asset_id resolves on this site via asset_service::get_by_internal_id. If null or deleted, sets fastpix_asset_id = null. The view.php "Video unavailable" path (ADR-010) handles the rest.
   - On restore_path_element 'attempt': maps userid via $this->get_mappingid('user', $data->userid).
   - Does NOT throw on cross-account scenario.
3. mod_fastpix_pre_course_module_delete:
   - Called by Moodle when activity is deleted (including via recycle bin).
   - Counts other activities referencing the same fastpix_asset_id.
   - If count <= 1 (only us), call \local_fastpix\service\asset_service::soft_delete_if_unreferenced(fastpix_asset_id).
   - Confirm soft_delete_if_unreferenced exists with @local-fastpix-contract before implementing.

DO NOT:
- Copy asset bytes into the backup (PR-22, M9).
- Throw on cross-account restore (ADR-010 specifies graceful "Video unavailable").
- Hard-delete the asset from the recycle bin hook — soft delete only (and only if unreferenced).

VALIDATION:
- Backup with userinfo=true captures attempt rows; userinfo=false does not.
- Same-account restore plays the video.
- Cross-account restore (mocked asset_service returning null) shows "Video unavailable" without throwing.
- User mapping: old userid → new userid in restored attempts.
- backup/restore tests ≥ 85% coverage.
- Behat: backup/restore round-trip in add_activity.feature.
```
