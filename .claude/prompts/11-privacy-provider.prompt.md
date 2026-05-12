# Prompt — Generate Privacy Provider (Phase E)

```
You are @privacy-security working on mod_fastpix.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §3 Phase E
- .claude/skills/11-privacy-provider.md
- .claude/rules/security.md (S10)

TASK: Generate:
- mod/fastpix/classes/privacy/provider.php
- Lang strings privacy:metadata:fastpix_attempt and privacy:metadata:fastpix_attempt:<column> for every PII column

REQUIREMENTS:
1. Class implements all three interfaces:
   - core_privacy\local\metadata\provider
   - core_privacy\local\request\plugin\provider
   - core_privacy\local\request\core_userlist_provider
2. get_metadata declares the table 'fastpix_attempt' with all 9 PII columns from Skill 11 (S10).
3. get_contexts_for_userid: SQL joining fastpix_attempt → fastpix → course_modules → context where userid = ?.
4. get_users_in_context: returns all userids with attempts in a CONTEXT_MODULE.
5. export_user_data: produces human-readable output (activity_name not ID; userdate-formatted timestamps).
6. delete_data_for_all_users_in_context: deletes ALL fastpix_attempt rows in the module.
7. delete_data_for_user: deletes user's rows in the contexts on the list.
8. delete_data_for_users: deletes the listed users' rows in a single context.

LANG STRINGS REQUIRED:
- privacy:metadata:fastpix_attempt — table-level description.
- privacy:metadata:fastpix_attempt:userid, :activity_id, :session_token, :session_start_ts, :last_callback_ts, :watched_seconds, :seek_count, :fraud_count, :completion_state.

DO NOT:
- Soft-delete PII on delete_data_for_user (Article 17 means actually deleted).
- Export raw IDs (use names/timestamps for human readability).
- Forget any column on the metadata declaration (S10).

VALIDATION:
- get_metadata declares all 9 columns.
- export_user_data produces human-readable output.
- delete round-trip: export → delete → re-view shows no progress.
- privacy_provider coverage ≥ 85%.
```
