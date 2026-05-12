# Prompt — Generate Plugin Skeleton (Phase A)

Use this prompt to ask Claude (acting as `@privacy-security` + `@activity-form` + `@playback-view`) to produce the empty `mod_fastpix` skeleton.

---

```
You are working on the mod_fastpix Moodle activity plugin.

CONTEXT YOU HAVE READ:
- docs/02-mod-fastpix.md §3 Phase A
- .claude/skills/01-skeleton.md
- .claude/rules/moodle-mod.md (M1, M2, M7)
- .claude/rules/architecture.md (A1)

TASK: Generate the Phase A skeleton:
- mod/fastpix/version.php
- mod/fastpix/lib.php (with all M1 callbacks; bodies stubbed for later phases)
- mod/fastpix/mod_form.php (title + intro only)
- mod/fastpix/view.php (placeholder render with auth dance)
- mod/fastpix/lang/en/fastpix.php (Phase A strings only — NOT lang/en/mod_fastpix.php; activity modules use the bare module name, see mod/forum/lang/en/forum.php as canonical example)
- mod/fastpix/pix/icon.svg
- mod/fastpix/pix/monologo.svg

REQUIREMENTS:
1. version.php: $plugin->component='mod_fastpix', $plugin->version=today YYYYMMDDXX, $plugin->requires=2024100700, $plugin->maturity=MATURITY_ALPHA, $plugin->dependencies=['local_fastpix' => <pinned version>].
2. lib.php: declare ALL M1 callbacks (add_instance, update_instance, delete_instance, supports, grade_item_update, update_grades, pre_course_module_delete, get_completion_active_rule_descriptions). Stub bodies that are obviously incomplete are fine; add a `// TODO Phase X` comment.
3. mod_fastpix_supports() returns exactly per M2 — verify against the table.
4. mod_form has title (PARAM_TEXT, required) + standard_intro_elements + standard_coursemodule_elements + add_action_buttons. Nothing else for Phase A.
5. view.php runs require_login → require_capability('mod/fastpix:view') → render placeholder. NO playback yet.
6. lang file: pluginname, modulename, modulenameplural, modulename_help, activityname, plus the four capability description strings (fastpix:addinstance, fastpix:view, fastpix:viewallattempts, fastpix:graderoverride). NO empty `[[...]]` placeholders allowed.
7. pix/icon.svg and pix/monologo.svg: square play-button glyph, single color.

DO NOT:
- Add any FastPix HTTP calls (Phase A has none).
- Add any DRM/playback logic.
- Reference \local_fastpix\api\gateway anywhere (forbidden in any phase).
- Put helper functions in lib.php beyond the M1 callbacks.

VALIDATION:
- moodle-plugin-ci install passes.
- Activity appears under "Add an activity" → "Assessment" section.
- An empty activity can be added, saved, and clicked.
- No [[lang_key]] placeholders rendered in UI.
```
