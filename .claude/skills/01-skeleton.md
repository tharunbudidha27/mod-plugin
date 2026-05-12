# Skill 01 — Create Plugin Skeleton

**Owner agent:** `@privacy-security` (capability + lang) + `@activity-form` (mod_form stub) + `@playback-view` (view stub).

**When to invoke:** Phase A, step 1.

---

## Inputs

- `docs/02-mod-fastpix.md` §3 Phase A.
- Moodle 4.5 LTS (frankenstyle component name `mod_fastpix`).

## Outputs

- `mod/fastpix/version.php`
- `mod/fastpix/lib.php` (with all callbacks listed in M1; bodies stubbed)
- `mod/fastpix/mod_form.php` (title + intro only, extending `moodleform_mod`)
- `mod/fastpix/view.php` (placeholder render)
- `mod/fastpix/lang/en/fastpix.php` (initial strings: pluginname, modulename, modulenameplural, capability descriptions). **NOTE:** Activity modules use the bare module name for the lang file, NOT the frankenstyle. `mod/forum/lang/en/forum.php`, `mod/quiz/lang/en/quiz.php`. Putting it at `lang/en/mod_fastpix.php` causes Moodle to silently fail string lookup with `[[modulename]]` and reject the plugin as "defective" during install.
- `mod/fastpix/pix/icon.svg` and `mod/fastpix/pix/monologo.svg`

## Steps

### 1. version.php

```php
<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_fastpix';
$plugin->version   = 2026XXXXX0;        // YYYYMMDDXX, today's date
$plugin->requires  = 2024100700;        // Moodle 4.5 LTS minimum
$plugin->maturity  = MATURITY_ALPHA;    // bumped to STABLE in Phase F
$plugin->release   = '0.1.0-dev';
$plugin->dependencies = ['local_fastpix' => 2026XXXXXX]; // local_fastpix v0.2.0+ required
```

### 2. lib.php — exactly the M1 callbacks, all with stub bodies

Function names (return `null` or stub for now; fill in later phases):
- `fastpix_supports($feature)` — return per M2
- `fastpix_add_instance($data, $mform)` — INSERT + return id
- `fastpix_update_instance($data, $mform)` — UPDATE + return true
- `fastpix_delete_instance($id)` — DELETE + return true
- `fastpix_grade_item_update($activity, $grades = null)` — stub for Phase D
- `fastpix_update_grades($activity = null, $userid = 0, $nullifnone = true)` — stub for Phase D
- `fastpix_pre_course_module_delete($cm)` — stub for Phase E
- `fastpix_get_completion_active_rule_descriptions($cm)` — stub for Phase D

### 3. mod_form.php

```php
<?php
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_fastpix_mod_form extends moodleform_mod {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('text', 'name', get_string('activityname', 'mod_fastpix'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $this->standard_intro_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }
}
```

### 4. view.php

```php
<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('fastpix', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$activity = $DB->get_record('fastpix', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/fastpix:view', $context);

$PAGE->set_url('/mod/fastpix/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($activity->name));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($activity->name));
echo html_writer::tag('p', 'Phase A placeholder — playback in Phase C.');
echo $OUTPUT->footer();
```

### 5. lang/en/fastpix.php  (NOT `mod_fastpix.php` — see note above)

Required string keys for Phase A:
- `pluginname`, `modulename`, `modulenameplural`, `modulename_help`
- `activityname`
- `fastpix:addinstance`, `fastpix:view`, `fastpix:viewallattempts`, `fastpix:graderoverride`
- `privacy:metadata` (placeholder; filled in Phase E)

### 6. pix/icon.svg + pix/monologo.svg

`monologo.svg` is required for Moodle 4.0+ themes. Use a single-color, square play-button glyph for both.

## Validation

- `moodle-plugin-ci install` passes.
- Activity appears under "Add an activity" → "Assessment."
- Empty activity can be added and saved.
- Empty `view.php` placeholder renders.
- No `[[lang_key]]` placeholders visible anywhere in the UI.
