# Prompt — Generate Behat Scenarios

```
You are @testing working on mod_fastpix.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §3 (each phase's exit criterion) and §5 (test strategy)
- .claude/skills/13-behat-scenarios.md

TASK: Generate the relevant feature file:
- tests/behat/add_activity.feature (Phase B + Phase E for backup/restore)
- tests/behat/student_view.feature (Phase C)
- tests/behat/completion_grade.feature (Phase D)
- tests/behat/no_skip_enforcement.feature (Phase D)

REQUIREMENTS — applicable to every feature file:
1. Top-level annotations: @mod @mod_fastpix and additional context tags (@completion, @gradebook).
2. Background: define users, course, role enrollments, mocked FastPix sandbox state.
3. Scenarios cover happy path AND critical sad paths.
4. Use existing Moodle Behat steps where possible ("I add a ... activity to section ...", "I follow ...", "I should see ...").
5. Custom steps for FastPix-specific actions ("the FastPix sandbox is mocked with a ready asset", "the player records N watched seconds") live in tests/behat/behat_mod_fastpix.php.

REQUIREMENTS — add_activity.feature (Phase B):
- Teacher adds activity via URL-pull happy path.
- Teacher cannot save with empty source (validates M10).
- Teacher cannot save with threshold outside (0, 100].
- Backup-restore round-trip preserves activity reference (Phase E).

REQUIREMENTS — student_view.feature (Phase C):
- Student opens ready video → player visible.
- Student opens processing video → "This video is still processing" message.
- Student opens deleted video → "Video unavailable" (ADR-010).

REQUIREMENTS — completion_grade.feature (Phase D):
- Watching threshold + → completion check appears, gradebook shows max grade.
- Watching threshold − → no completion, no grade.
- Re-entering completed activity does NOT re-write grade (CG4 — idempotent).

REQUIREMENTS — no_skip_enforcement.feature (Phase D):
- Forward seek on no-skip asset increments fraud_count with reason 'seek_on_noskip'; watched_seconds NOT updated.
- Backward seek (replay) does NOT flag fraud.

DO NOT:
- Make real HTTP calls to FastPix in CI (mock the sandbox).
- Depend on real time (use Moodle's mocked time / frozen clock).
- Reference real user emails or passwords (use Moodle's standard test users).
- Skip scenarios; if blocked, comment with a ticket reference.

VALIDATION:
- All four .feature files pass via `vendor/bin/behat` against a clean Moodle 4.5.
- Each phase's exit criterion has a corresponding scenario.
- behat_mod_fastpix.php steps are documented (one-liner per step).
```
