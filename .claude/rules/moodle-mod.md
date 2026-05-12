# Moodle Activity Module Rules (M1–M10)

These are the Moodle-specific conventions for `mod_*` plugins that we treat as non-negotiable. Cited by every agent that touches `lib.php`, `mod_form.php`, `view.php`, `db/*`, or `backup/*`.

---

## M1 — `lib.php` only holds Moodle-required callbacks (bare-name prefix)

**Rule.** Activity-module `lib.php` callbacks use the **bare module name** as prefix, NOT the frankenstyle. For `mod_fastpix` that means `fastpix_*`, not `mod_fastpix_*`. Verify by checking `mod/forum/lib.php` (`forum_add_instance`, `forum_supports`) or `mod/quiz/lib.php`. Moodle core invokes these via `call_user_func("{$modname}_add_instance", ...)` where `$modname` is `fastpix`. Naming them `mod_fastpix_add_instance` causes a `Call to undefined function fastpix_add_instance()` fatal at the moment a teacher saves an activity.

This is the same `mod_*`-specific exception as M7 (lang file naming). Other plugin types (`local_*`, `block_*`, `filter_*`) DO use the frankenstyle for their callbacks.

`mod/fastpix/lib.php` contains ONLY:
- `fastpix_supports($feature)` — feature flags
- `fastpix_add_instance($data, $mform)` — activity create
- `fastpix_update_instance($data, $mform)` — activity edit
- `fastpix_delete_instance($id)` — activity delete
- `fastpix_grade_item_update($activity, $grades=null)` — gradebook integration (CG2)
- `fastpix_update_grades($activity=null, $userid=0, $nullifnone=true)` — gradebook bulk update (CG2)
- `fastpix_pre_course_module_delete($cm)` — recycle bin hook
- `fastpix_get_completion_active_rule_descriptions($cm)` — completion API hook (CG3)
- `fastpix_extend_settings_navigation(...)` — only if needed for activity-level admin actions

Everything else lives in autoloaded `classes/` (and those CAN use the `mod_fastpix\` namespace — the legacy bare-name rule applies only to lib.php callbacks invoked by Moodle core).

Do NOT add helper functions to `lib.php`.

### The naming-convention cheat sheet for mod_fastpix

| Surface | Prefix | Example |
|---|---|---|
| `lib.php` callbacks (called by Moodle core via `call_user_func`) | bare module name | `fastpix_add_instance`, `fastpix_supports` |
| `db/upgrade.php` upgrade function | bare module name | `xmldb_fastpix_upgrade($oldversion)` (NOT `xmldb_mod_fastpix_upgrade`) |
| `lang/en/<file>.php` filename | bare module name | `lang/en/fastpix.php` |
| `mod_form.php` class name | frankenstyle | `class mod_fastpix_mod_form` |
| Autoloaded classes under `classes/` | frankenstyle namespace | `\mod_fastpix\service\watch_tracker_service` |
| Capability strings in `db/access.php` | bare module path | `mod/fastpix:view`, `mod/fastpix:addinstance` |
| `version.php` `$plugin->component` | frankenstyle | `'mod_fastpix'` |
| `get_string($key, $component)` calls | frankenstyle | `get_string('modulename', 'mod_fastpix')` |
| Database table names in `install.xml` | bare module name | `<TABLE NAME="fastpix">`, `<TABLE NAME="fastpix_attempt">` |
| Web service method names in `db/services.php` | frankenstyle | `mod_fastpix_record_view_progress` |
| Mustache template references | frankenstyle | `$OUTPUT->render_from_template('mod_fastpix/view', $data)` |
| AMD module names | frankenstyle | `require(['mod_fastpix/watch_tracker'])` |
| AMD build artifacts | required, not optional | every `amd/src/<name>.js` MUST have a matching `amd/build/<name>.min.js`. Moodle's loader hits `build/` first; missing the build file = `Script error for "mod_fastpix/<name>"` and the AMD module silently fails. **`cp src/<name>.js build/<name>.min.js` does NOT work** — the source uses ES6 `import`/`export`, but Moodle's RequireJS expects AMD `define([deps], function(...) {...})`. Run `grunt amd` to transpile, OR write the build file manually as AMD-wrapped ES5. |
| External web components (custom HTML elements with a hyphen, e.g. `<fastpix-player>`) | Loaded via `$PAGE->requires->js(new moodle_url(...), true)` — **NOT** raw `<script src=...>` in mustache | `view.php`: `if ($state instanceof view_state_player) { $PAGE->requires->js(new moodle_url(playback_service::PLAYER_LIB_URL), true); }` before `$OUTPUT->header()` |

If a mustache template emits a custom HTML element (any tag with a hyphen), the plugin MUST also register the JavaScript that defines that custom element. The element will render as an inert empty box if not defined; **this fails silently with no console error** — `customElements.get('<tag-name>')` returns `undefined` and the only visible symptom is "the player is a blue rectangle that does nothing." `$PAGE->requires->js(new moodle_url(...), true)` loads the registration script in `<head>` (the second-arg `true` is the in-head flag) before the body renders the element. Do NOT inline `<script src=...>` in mustache: it bypasses Moodle's CSP, lint, and JS registry; it also runs after the element is already in the DOM, so the upgrade callback path differs. Skip the load on non-player states (processing/error) — those don't emit the custom element and the bytes are wasted.

If you can't remember which is which: **anything that Moodle core invokes by name from a string-built path uses the bare name** (`{modname}_callback`, `lang/en/{modname}.php`). **Anything resolved by Moodle's autoloader or component registry uses the frankenstyle.**

**Enforcement.** PR review.

**Failure routing.** `@backend-architect`.

---

## M2 — Feature flags must declare exactly what we support

**Rule.** `mod_fastpix_supports($feature)` returns:

```php
case FEATURE_GRADE_HAS_GRADE:        return true;
case FEATURE_COMPLETION_HAS_RULES:    return true;
case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
case FEATURE_BACKUP_MOODLE2:          return true;
case FEATURE_GROUPS:                   return true;
case FEATURE_GROUPINGS:                return true;
case FEATURE_MOD_INTRO:               return true;
case FEATURE_SHOW_DESCRIPTION:        return true;
case FEATURE_MOD_PURPOSE:             return MOD_PURPOSE_ASSESSMENT;
default:                              return null;
```

Do NOT enable: `FEATURE_USES_QUESTIONS`, `FEATURE_RATE`, `FEATURE_PLAGIARISM`, `FEATURE_COMMENT`. They are out of scope for v1.0.

**Enforcement.** `tests/lib_test.php` asserts exact return values.

**Failure routing.** `@privacy-security`.

---

## M3 — Capabilities are exactly five (4 + ADR-012)

**Rule.** `db/access.php` declares exactly:
- `mod/fastpix:addinstance` — archetype `editingteacher`, contextlevel `CONTEXT_COURSE`, riskbitmask `RISK_XSS` (intro field)
- `mod/fastpix:view` — archetype `student`, contextlevel `CONTEXT_MODULE`
- `mod/fastpix:viewallattempts` — archetype `editingteacher`, contextlevel `CONTEXT_MODULE`
- `mod/fastpix:graderoverride` — archetype `editingteacher`, contextlevel `CONTEXT_MODULE` (per D4)
- `mod/fastpix:uploadmedia` — archetype `editingteacher`, contextlevel `CONTEXT_COURSE` (per ADR-012; consumed by `local_fastpix_create_upload_session` and `local_fastpix_create_url_pull_session` web services)

The 5th capability (`uploadmedia`) is owned by `mod_fastpix` per ADR-012 (`local/fastpix/docs/adr/ADR-012-capability-ownership.md`, accepted 2026-05-05). It is consumed by `local_fastpix` web services but defined here, because `local_fastpix` defines exactly one capability (`local/fastpix:configurecredentials`) and any FastPix-related capabilities outside that one are mod-side.

Do NOT introduce a SIXTH capability without a fresh ADR. PR-14 still applies for any addition beyond these five.

**Enforcement.** PR review; `tests/lib_test.php` asserts the capability set.

**Failure routing.** `@privacy-security`.

---

## M4 — Schema lives in `db/install.xml`, never in upgrade.php for v1.0

**Rule.** `db/install.xml` is the source of truth for v1.0 schema. `db/upgrade.php` exists but is empty until v1.1 (per Moodle convention — must exist for the upgrade machinery, even if no migrations).

Every column has `TYPE`, `LENGTH`, `NOTNULL`, `SEQUENCE` (where applicable). Every table has `KEY` for primary, foreign keys, and the documented UNIQUE constraints. Every index is named `idx_<table>_<columns>`.

**Enforcement.** `xmldb-editor` validation; `moodle-plugin-ci` schema check.

**Failure routing.** `@backend-architect` for design; the relevant specialist for the column.

---

## M5 — Schema changes require version.php bump + upgrade.php step

**Rule.** Adding/removing/renaming a column or table:
1. Edit `db/install.xml` (for fresh installs).
2. Add an upgrade block to `db/upgrade.php` with a `savepoint` matching the new version (for existing installs).
3. Bump `$plugin->version` in `version.php` to today's date in YYYYMMDDXX format.

Skipping step 2 means existing pilot installs break on upgrade. Skipping step 3 means Moodle won't run upgrade.php at all.

**Enforcement.** `moodle-plugin-ci savepoints` check; PR review.

**Failure routing.** `@backend-architect`.

---

## M6 — Coverage gates per surface

**Rule.** Per design doc §12.5, mandatory PHPUnit coverage:
- `record_view_progress` external function → ≥ 90%
- `watch_tracker_service` (the 6 fraud checks) → ≥ 90%
- `custom_completion` → ≥ 85%
- `mod_form` validation → ≥ 85%
- `session_token_service` → ≥ 90%
- `playback_service` → ≥ 85%
- `lib.php` callbacks → ≥ 85%

Coverage MUST be verified by `moodle-plugin-ci` reports, not self-reported.

**Enforcement.** PR coverage gate.

**Failure routing.** `@testing`.

---

## M7 — Lang strings are in `lang/en/fastpix.php`, no exceptions

**Rule.** The lang file for an activity module lives at `lang/en/<modulename>.php` — for `mod_fastpix` that means `lang/en/fastpix.php`. **NOT** `lang/en/mod_fastpix.php`. Verify by checking any core mod plugin: `mod/forum/lang/en/forum.php`, `mod/quiz/lang/en/quiz.php`. Putting it at `lang/en/mod_fastpix.php` makes Moodle reject the plugin as "defective" during install with `[[modulename]]` placeholder errors — the failure mode looks like a string-cache bug but is actually a path bug.

Other plugin types (`local_*`, `filter_*`, `block_*`) DO use the frankenstyle in the path. This is a `mod_*`-specific exception baked into Moodle since forever.

Every user-visible string MUST come from `get_string('key', 'mod_fastpix')`. No literal English in PHP, mustache, or AMD source. Mustache uses `{{#str}}key, mod_fastpix{{/str}}`. AMD uses `core/str` requirement. The frankenstyle component name (`mod_fastpix`) IS the right argument to `get_string()` even though the file is named `fastpix.php` — Moodle aliases the bare name to the frankenstyle for activity modules.

Capability descriptions are in the same `lang/en/fastpix.php` with keys `fastpix:addinstance`, `fastpix:view`, etc.

**Enforcement.** `moodle-plugin-ci stringscheck`; `tests/lib_test.php` asserts no `[[lang_key]]` placeholders render.

**Failure routing.** `@privacy-security`.

---

## M8 — DML uses parameterized queries only

**Rule.** All database operations go through `$DB` with parameter placeholders. No string concatenation into SQL, no `mysqli_*`, no `PDO`.

**Enforcement.** PR review; CI grep for `mysqli_` and `PDO` (zero matches required).

**Failure routing.** `@backend-architect`.

---

## M9 — Backup/restore preserves activity reference, not asset bytes

**Rule.** `backup_fastpix_stepslib` captures: activity row from `mdl_fastpix`, all `mdl_fastpix_attempt` rows for the activity, `fastpix_id` reference. Does NOT capture asset bytes (FastPix owns those).

Restore on a different FastPix account hits the asset lookup miss path → "Video unavailable" per ADR-010. This is the correct behavior; do NOT try to copy assets across accounts (BR1).

**Enforcement.** `tests/backup_restore_test.php` covers both same-account and cross-account paths.

**Failure routing.** `@backup-restore`.

---

## M10 — `mod_form.php` validation runs server-side, not just client-side

**Rule.** `validation($data, $files)` in `mod_form.php` enforces every business rule:
- Both upload and URL empty → reject
- URL malformed → reject (delegate to `local_fastpix` SSRF guard via service call)
- Completion threshold outside `(0, 100]` → reject
- Asset swap on activity with attempts → reject (per D5: forbid changing the asset on an activity that has any attempts; cleaner than soft-reset)

Client-side AMD validation is cosmetic only. The server is authoritative.

**Enforcement.** `tests/mod_form_test.php` covers every rejection path.

**Failure routing.** `@activity-form`.
