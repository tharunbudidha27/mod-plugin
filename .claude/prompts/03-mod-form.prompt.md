# Prompt — Generate mod_form.php (Phase B)

```
You are @activity-form working on mod_fastpix.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §3 Phase B
- docs/01-local-fastpix.md (upload_service signatures)
- .claude/skills/03-mod-form.md
- .claude/rules/moodle-mod.md (M10 — server-side validation)
- .claude/rules/consumer-contract.md (CC1, CC2, CC8)

TASK: Generate:
- mod/fastpix/mod_form.php (full)
- mod/fastpix/amd/src/upload_widget.js
- mod/fastpix/templates/upload_widget.mustache
- Updated mod/fastpix/lib.php — fill in mod_fastpix_add_instance + mod_fastpix_update_instance bodies
- Lang strings for every form field, every error, every help icon

REQUIREMENTS:
1. mod_form has three fieldsets per Skill 03: standard, video source (two-tab control), playback options. Plus standard completion + grade.
2. Source-type select: 'upload' (default) or 'urlpull'.
3. Source URL field is hidden when source_type=upload (use $mform->hideIf).
4. Hidden upload_session_id field, populated by AMD after chunked upload.
5. Per-activity playback options: no_skip_required (advcheckbox), default_show_captions (advcheckbox).
6. Custom completion: completionwatchedpercent (int 1-100, default 90), with completionwatchedpercentenabled toggle.
7. validation($data, $files) implements per M10:
   - Both-empty: source_type=upload but no upload_session_id, OR source_type=urlpull but no source_url → error.
   - Threshold range: completionwatchedpercent must be in (0, 100].
   - Asset swap protection: if existing activity has any fastpix_attempt rows AND fastpix_asset_id is being changed → reject (per D5).
8. lib.php callbacks:
   - mod_fastpix_add_instance: INSERT row, return id.
   - mod_fastpix_update_instance: UPDATE row, return true.
   - mod_fastpix_delete_instance: DELETE attempts then activity, return true.
9. AMD upload_widget.js: calls local_fastpix_create_upload_session via core/ajax (CC2). Chunked upload with progress UI. On completion, sets upload_session_id on the hidden form field.

DO NOT:
- Call \local_fastpix\api\gateway anywhere (PR-3).
- Construct raw fetch() to /lib/ajax/service.php (CC2).
- Implement SSRF check yourself — delegate to local_fastpix's upload_service.
- Add fields not in §3 Phase B (route to @backend-architect for ADR if needed).
- Allow saving without an asset (UX is "draft mode" — out of scope).

VALIDATION:
- Both-tab paths persist correctly (mock local_fastpix in tests).
- Both-empty rejects.
- Threshold outside (0, 100] rejects.
- Asset swap on attempts-existing rejects.
- All visible strings come from lang file.
- tests/mod_form_test.php ≥ 85% coverage.
- Behat: add_activity.feature happy path passes.
```
