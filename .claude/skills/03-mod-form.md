# Skill 03 — mod_form (Two-Tab Upload UX)

**Owner agent:** `@activity-form`.

**When to invoke:** Phase B.

---

## Inputs

- `docs/02-mod-fastpix.md` §3 Phase B.
- `\local_fastpix\service\upload_service` signatures from `01-local-fastpix.md`.
- `.claude/rules/moodle-mod.md` (M10 — server-side validation).
- `.claude/rules/consumer-contract.md` (CC1, CC2).

## Outputs

- `mod/fastpix/mod_form.php` (full)
- `mod/fastpix/amd/src/upload_widget.js`
- `mod/fastpix/templates/upload_widget.mustache`
- `mod/fastpix/lib.php`'s `mod_fastpix_add_instance` and `mod_fastpix_update_instance` (filled in)
- Lang strings for every form field, every error, every help icon

## Steps

### 1. mod_form definition() — three fieldsets

```php
public function definition() {
    $mform = $this->_form;

    // Fieldset 1: Standard (handled by parent via standard_intro_elements + standard_coursemodule_elements).
    $mform->addElement('text', 'name', get_string('activityname', 'mod_fastpix'));
    $mform->setType('name', PARAM_TEXT);
    $mform->addRule('name', null, 'required', null, 'client');
    $this->standard_intro_elements();

    // Fieldset 2: Video source (two-tab control).
    $mform->addElement('header', 'videosource', get_string('videosource', 'mod_fastpix'));

    $mform->addElement('select', 'source_type', get_string('sourcetype', 'mod_fastpix'),
        ['upload' => get_string('sourcetype_upload', 'mod_fastpix'),
         'urlpull' => get_string('sourcetype_urlpull', 'mod_fastpix')]);
    $mform->setDefault('source_type', 'upload');

    // Upload widget (rendered via AMD when source_type=upload).
    $mform->addElement('html', '<div data-region="fastpix-upload-widget"></div>');

    // URL field (visible when source_type=urlpull).
    $mform->addElement('url', 'source_url', get_string('sourceurl', 'mod_fastpix'),
        ['size' => 80]);
    $mform->setType('source_url', PARAM_URL);
    $mform->hideIf('source_url', 'source_type', 'eq', 'upload');

    // Hidden field carrying the upload_session_id once the AMD finishes.
    $mform->addElement('hidden', 'upload_session_id');
    $mform->setType('upload_session_id', PARAM_INT);

    // Fieldset 3: Playback options.
    $mform->addElement('header', 'playbackoptions', get_string('playbackoptions', 'mod_fastpix'));

    $mform->addElement('advcheckbox', 'no_skip_required',
        get_string('noskip', 'mod_fastpix'), get_string('noskip_help', 'mod_fastpix'));

    $mform->addElement('advcheckbox', 'default_show_captions',
        get_string('autocaptions', 'mod_fastpix'), get_string('autocaptions_help', 'mod_fastpix'));

    // Standard completion + grade.
    $this->standard_coursemodule_elements();
    $this->add_action_buttons();
}
```

### 2. Custom completion rule on the form

```php
public function add_completion_rules() {
    $mform = $this->_form;
    $group = [];
    $group[] = $mform->createElement('checkbox', 'completionwatchedpercentenabled', '',
        get_string('completionwatchedpercent', 'mod_fastpix'));
    $group[] = $mform->createElement('text', 'completionwatchedpercent', '', ['size' => 3]);
    $mform->setType('completionwatchedpercent', PARAM_INT);
    $mform->addGroup($group, 'completionwatchedpercentgroup',
        get_string('completionwatchedpercent', 'mod_fastpix'), ' ', false);
    $mform->setDefault('completionwatchedpercent', 90);
    return ['completionwatchedpercentgroup'];
}

public function completion_rule_enabled($data) {
    return !empty($data['completionwatchedpercentenabled'])
        && $data['completionwatchedpercent'] > 0;
}
```

### 3. validation() — server-authoritative (M10)

```php
public function validation($data, $files) {
    $errors = parent::validation($data, $files);

    // Both-empty check.
    if ($data['source_type'] === 'upload' && empty($data['upload_session_id'])) {
        $errors['upload_session_id'] = get_string('error_uploadrequired', 'mod_fastpix');
    }
    if ($data['source_type'] === 'urlpull' && empty($data['source_url'])) {
        $errors['source_url'] = get_string('error_urlrequired', 'mod_fastpix');
    }

    // Threshold range.
    if (!empty($data['completionwatchedpercentenabled'])) {
        $threshold = (int)$data['completionwatchedpercent'];
        if ($threshold <= 0 || $threshold > 100) {
            $errors['completionwatchedpercentgroup'] = get_string('error_thresholdrange', 'mod_fastpix');
        }
    }

    // Asset-swap-with-attempts check (per D5).
    if (!empty($this->_instance)) {
        $existing = $DB->get_record('fastpix', ['id' => $this->_instance]);
        $hasattempts = $DB->record_exists('fastpix_attempt', ['activity_id' => $this->_instance]);
        if ($hasattempts && $existing->fastpix_asset_id != $data['fastpix_asset_id']) {
            $errors['upload_session_id'] = get_string('error_assetswapblocked', 'mod_fastpix');
        }
    }

    return $errors;
}
```

### 4. lib.php callbacks

```php
function mod_fastpix_add_instance($data, $mform) {
    global $DB;
    $data->timecreated = $data->timemodified = time();
    $data->id = $DB->insert_record('fastpix', $data);
    \mod_fastpix\completion\custom_completion::update_after_save($data);
    return $data->id;
}

function mod_fastpix_update_instance($data, $mform) {
    global $DB;
    $data->id = $data->instance;
    $data->timemodified = time();
    $DB->update_record('fastpix', $data);
    \mod_fastpix\completion\custom_completion::update_after_save($data);
    return true;
}

function mod_fastpix_delete_instance($id) {
    global $DB;
    if (!$DB->record_exists('fastpix', ['id' => $id])) {
        return false;
    }
    $DB->delete_records('fastpix_attempt', ['activity_id' => $id]);
    $DB->delete_records('fastpix', ['id' => $id]);
    return true;
}
```

### 5. AMD upload widget (`amd/src/upload_widget.js` AND `amd/build/upload_widget.min.js`)

**CRITICAL:** Both files MUST exist before Phase B is testable. Moodle's RequireJS loader fetches `amd/build/<name>.min.js` from the browser, NOT `amd/src/<name>.js`. Missing the build artifact produces `Uncaught Error: Script error for "mod_fastpix/upload_widget"` in the console and the upload UI never renders. Run `grunt amd` after writing the source, or `cp src/<name>.js build/<name>.min.js` for quick dev iteration (modern browsers handle ES6 natively).


Calls `local_fastpix_create_upload_session` via `core/ajax`, receives signed URL, PUTs chunks. On completion, sets `upload_session_id` on the hidden form field.

Pseudocode:
```javascript
import {call as ajaxCall} from 'core/ajax';
import {get_string as getString} from 'core/str';

const session = await ajaxCall([{
    methodname: 'local_fastpix_create_upload_session',
    args: { activity_context: cmid }
}])[0];

// chunked upload with progress UI
await uploadChunks(file, session.upload_url, onProgress);

// stash the id on the form
document.querySelector('[name="upload_session_id"]').value = session.upload_session_id;
```

## Validation

- Both-tab paths persist correctly.
- Both-empty rejects with a clear message.
- Threshold outside `(0, 100]` rejects.
- Asset swap on attempts-existing activity rejects.
- All visible strings come from lang file (no `[[lang_key]]`).
- `tests/mod_form_test.php` ≥ 85% coverage.
- Behat: `add_activity.feature` happy path passes.
