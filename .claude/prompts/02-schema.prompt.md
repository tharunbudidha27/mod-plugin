# Prompt — Generate Database Schema (Phase A)

```
You are @privacy-security working on mod_fastpix.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §3 Phase A and §6.5 (schema spec)
- .claude/skills/02-schema.md
- .claude/rules/moodle-mod.md (M3, M4, M5)

TASK: Generate:
- mod/fastpix/db/install.xml
- mod/fastpix/db/upgrade.php (skeleton, empty body for v1.0)
- mod/fastpix/db/access.php (exactly four capabilities per M3)
- mod/fastpix/db/services.php (skeleton — populate in Phases C/D)

REQUIREMENTS:
1. install.xml — table `mdl_fastpix` (activity instances) with fields per Skill 02.
2. install.xml — table `mdl_fastpix_attempt` (per-user attempts) with fields per Skill 02.
3. UNIQUE(userid, activity_id) on attempt table — non-negotiable per design doc §6.5.
4. INDEX (activity_id, completion_state) on attempt table — for gradebook queries.
5. Every column has TYPE, LENGTH, NOTNULL, SEQUENCE/DEFAULT correctly set per Moodle XMLDB conventions.
6. db/access.php declares EXACTLY these four capabilities per M3:
   - mod/fastpix:addinstance — editingteacher + manager, CONTEXT_COURSE, RISK_XSS
   - mod/fastpix:view — student + teacher + editingteacher + manager, CONTEXT_MODULE
   - mod/fastpix:viewallattempts — editingteacher + manager, CONTEXT_MODULE
   - mod/fastpix:graderoverride — editingteacher + manager, CONTEXT_MODULE
7. upgrade.php has the standard skeleton with `xmldb_mod_fastpix_upgrade($oldversion)` returning true. Body comment: "No upgrades for v1.0; install.xml is source of truth."

DO NOT:
- Add a fifth capability (PR-14 auto-rejects).
- Add multi-tenant columns (out of scope per §1).
- Add a `watermark` column (ADR-005 withdrawn).
- Add a `last_position_seconds` column or similar resume fields (v1.1).

VALIDATION:
- xmldb-editor validates the file.
- Plugin installs with all tables and indexes.
- Plugin uninstalls cleanly (no orphan tables).
- tests/lib_test.php asserts the four-capability set matches db/access.php.
```
