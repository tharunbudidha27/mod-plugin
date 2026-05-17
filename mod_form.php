<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_fastpix_mod_form extends moodleform_mod {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('activityname', 'mod_fastpix'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $mform->addElement('header', 'videosource', get_string('videosource', 'mod_fastpix'));

        // Intro paragraph under the section header.
        $mform->addElement('html', html_writer::tag(
            'p',
            s(get_string('videosource_intro', 'mod_fastpix')),
            ['class' => 'text-body-secondary small mb-3', 'style' => 'max-width: 56rem;']
        ));

        // source_type is a registered hidden mform element. AMD sets its value
        // when the user touches one input or the other (file selected →
        // 'upload'; URL Upload button clicked → 'urlpull'). PHP validation
        // reads $data['source_type'] unchanged.
        $mform->addElement('hidden', 'source_type', 'upload');
        $mform->setType('source_type', PARAM_ALPHA);

        // Single unified upload panel — mustache template renders both the
        // drop zone AND the URL row into this div, gradient-framed per mockup.
        $mform->addElement('html', '<div data-region="fastpix-upload-widget"
            data-fieldname-session="upload_session_id"></div>');

        $mform->addElement('hidden', 'upload_session_id');
        $mform->setType('upload_session_id', PARAM_INT);

        $mform->addElement('header', 'playbackoptions', get_string('playbackoptions', 'mod_fastpix'));

        // Resolve current values for edit mode so the cards render with the
        // correct checked state on first paint. Create mode falls through to
        // the conservative 0/0 default.
        $noskipchecked   = 0;
        $captionschecked = 0;
        if (!empty($this->_instance)) {
            global $DB;
            $existing = $DB->get_record('fastpix', ['id' => $this->_instance],
                'no_skip_required, default_show_captions');
            if ($existing) {
                $noskipchecked   = !empty($existing->no_skip_required) ? 1 : 0;
                $captionschecked = !empty($existing->default_show_captions) ? 1 : 0;
            }
        }

        // Intro card — film-strip icon + section description.
        $introtitle = s(get_string('playbackoptions_card_title', 'mod_fastpix'));
        $introdesc  = s(get_string('playbackoptions_intro', 'mod_fastpix'));
        $filmsvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="4" width="18" height="16" rx="2"/>
                <path d="M7 4v16M17 4v16M3 9h4M3 15h4M17 9h4M17 15h4"/>
            </svg>';
        $mform->addElement('html',
            '<div class="fastpix-pb-intro">
                <div class="fastpix-pb-intro-icon">' . $filmsvg . '</div>
                <div class="fastpix-pb-intro-body">
                    <div class="fastpix-pb-intro-title">' . $introtitle . '</div>
                    <p class="fastpix-pb-intro-desc">' . $introdesc . '</p>
                </div>
            </div>'
        );

        // Options panel — two rows separated by a hairline divider.
        $locksvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="4" y="11" width="16" height="10" rx="2"/>
                <path d="M8 11V7a4 4 0 0 1 8 0v4"/>
            </svg>';
        $ccsvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="5" width="18" height="14" rx="3"/>
                <path d="M10 10a2 2 0 0 0-2 2v0a2 2 0 0 0 2 2M16 10a2 2 0 0 0-2 2v0a2 2 0 0 0 2 2"/>
            </svg>';

        $noskiplabel = s(get_string('noskip', 'mod_fastpix'));
        $noskipdesc  = s(get_string('noskip_desc', 'mod_fastpix'));
        $capslabel   = s(get_string('autocaptions', 'mod_fastpix'));
        $capsdesc    = s(get_string('autocaptions_desc', 'mod_fastpix'));

        // Each card row renders a hidden 0-fallback followed by the visible
        // checkbox — same wire format mform's advcheckbox emits, so the save
        // path picks the value up identically. Native input lets us style
        // freely without fighting mform's renderer.
        $mform->addElement('html',
            '<div class="fastpix-pb-options">
                <div class="fastpix-pb-row">
                    <div class="fastpix-pb-row-icon">' . $locksvg . '</div>
                    <div class="fastpix-pb-row-label">' . $noskiplabel . '</div>
                    <div class="fastpix-pb-row-check">
                        <input type="hidden" name="no_skip_required" value="0">
                        <input type="checkbox" id="id_no_skip_required"
                               name="no_skip_required" value="1"' .
                               ($noskipchecked ? ' checked' : '') . '>
                    </div>
                    <div class="fastpix-pb-row-desc">' . $noskipdesc . '</div>
                </div>
                <div class="fastpix-pb-row">
                    <div class="fastpix-pb-row-icon">' . $ccsvg . '</div>
                    <div class="fastpix-pb-row-label">' . $capslabel . '</div>
                    <div class="fastpix-pb-row-check">
                        <input type="hidden" name="default_show_captions" value="0">
                        <input type="checkbox" id="id_default_show_captions"
                               name="default_show_captions" value="1"' .
                               ($captionschecked ? ' checked' : '') . '>
                    </div>
                    <div class="fastpix-pb-row-desc">' . $capsdesc . '</div>
                </div>
            </div>'
        );

        global $PAGE;
        $cmid = !empty($this->_cm->id) ? (int)$this->_cm->id : 0;
        $PAGE->requires->js_call_amd('mod_fastpix/upload_widget', 'init', [[
            'contextId'         => \context_system::instance()->id,
            'fieldnameSession'  => 'upload_session_id',
            'cmid'              => $cmid,
        ]]);

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public function add_completion_rules() {
        $mform = $this->_form;

        $group = [];
        $group[] = $mform->createElement('checkbox', 'completionwatchedpercentenabled', '',
            get_string('completionwatchedpercent', 'mod_fastpix'));
        $group[] = $mform->createElement('text', 'completionwatchedpercent', '', ['size' => 3]);
        $mform->setType('completionwatchedpercent', PARAM_INT);
        $mform->addGroup($group, 'completionwatchedpercentgroup',
            get_string('completionwatchedpercent_group', 'mod_fastpix'), ' ', false);
        $mform->disabledIf('completionwatchedpercent', 'completionwatchedpercentenabled', 'notchecked');
        $mform->setDefault('completionwatchedpercent', 90);

        return ['completionwatchedpercentgroup'];
    }

    public function completion_rule_enabled($data) {
        return !empty($data['completionwatchedpercentenabled'])
            && (int)$data['completionwatchedpercent'] > 0;
    }

    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        if (!empty($defaultvalues['completion_watch_percent'])) {
            $defaultvalues['completionwatchedpercent'] = (int)$defaultvalues['completion_watch_percent'];
            $defaultvalues['completionwatchedpercentenabled'] = 1;
        }
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        return array_merge($errors, $this->validate_fastpix_rules($data));
    }

    /**
     * mod_fastpix-specific validation rules, extracted so they can be
     * exercised by PHPUnit without standing up the full moodleform_mod
     * context (which needs $COURSE, grade_item, etc.).
     *
     * @param array $data Form submission data (uses 'source_type',
     *                    'upload_session_id', 'completionwatchedpercent*').
     *                    'source_url' is read separately from $_POST since
     *                    it is rendered as a raw HTML input.
     * @return array<string,string> Errors keyed by visible mform element name.
     */
    public function validate_fastpix_rules(array $data): array {
        global $DB;
        $errors = [];

        // source_url is rendered as a raw HTML <input>, NOT a registered mform
        // element, so $data never includes it. Read directly from $_POST.
        // PARAM_URL pairs with the local_fastpix SSRF guard on the validate
        // click to catch malformed URLs at the form layer.
        $sourceurl  = optional_param('source_url', '', PARAM_URL);
        $sourcetype = $data['source_type'] ?? 'upload';

        // All errors below attach to 'name' (a visible text element at the
        // top of the form). 'source_url' and 'upload_session_id' have no
        // visible mform row, so errors keyed there are silently dropped.
        if ($sourcetype === 'upload' && empty($data['upload_session_id'])) {
            $errors['name'] = get_string('error_uploadrequired', 'mod_fastpix');
        }
        if ($sourcetype === 'urlpull') {
            if (empty($sourceurl)) {
                $errors['name'] = get_string('error_urlrequired', 'mod_fastpix');
            } else if (empty($data['upload_session_id'])) {
                $errors['name'] = get_string('error_urlnotvalidated', 'mod_fastpix');
            }
        }

        if (!empty($data['completionwatchedpercentenabled'])) {
            $threshold = (int)$data['completionwatchedpercent'];
            if ($threshold <= 0 || $threshold > 100) {
                $errors['completionwatchedpercentgroup'] = get_string('error_thresholdrange', 'mod_fastpix');
            }
        }

        if (!empty($this->_instance)) {
            $existing = $DB->get_record('fastpix', ['id' => $this->_instance]);
            if ($existing) {
                // Service owns the "has any real attempts?" check (A6).
                // Real = watched_intervals non-empty (excludes teacher previews).
                $hasrealattempts = \mod_fastpix\service\playback_service::instance()
                    ->has_attempts_for((int)$this->_instance);
                $newsession = !empty($data['upload_session_id']) ? (int)$data['upload_session_id'] : null;
                $oldsession = !empty($existing->upload_session_id) ? (int)$existing->upload_session_id : null;
                if ($hasrealattempts && $newsession !== null && $newsession !== $oldsession) {
                    $errors['name'] = get_string('error_assetswapblocked', 'mod_fastpix');
                }
            }
        }

        return $errors;
    }
}
