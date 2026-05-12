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

        // Pill-toggle (Upload from device | Pull from URL). Visually a segmented
        // control; functionally a thin wrapper that writes into the hidden
        // <select name="source_type"> and dispatches a change event. The AMD
        // module also toggles `.hidden` on the upload picker and the URL pull
        // section based on this select's value (replaces the old hideIf wiring).
        $pillgroup = html_writer::tag('div',
            html_writer::tag('button',
                html_writer::tag('i', '', ['class' => 'fa fa-cloud-arrow-up me-2', 'aria-hidden' => 'true']) .
                html_writer::tag('span', s(get_string('sourcetype_upload', 'mod_fastpix'))),
                [
                    'type'             => 'button',
                    'class'            => 'btn btn-sm rounded-pill px-3 py-2 fw-medium',
                    'data-action'      => 'fastpix-source-tab',
                    'data-source-type' => 'upload',
                    'role'             => 'tab',
                    'aria-selected'    => 'true',
                    'style'            => 'background:#fff;border:1px solid #e5e7eb;box-shadow:0 1px 2px rgba(0,0,0,.05);',
                ]
            ) .
            html_writer::tag('button',
                html_writer::tag('i', '', ['class' => 'fa fa-link me-2', 'aria-hidden' => 'true']) .
                html_writer::tag('span', s(get_string('sourcetype_urlpull', 'mod_fastpix'))),
                [
                    'type'             => 'button',
                    'class'            => 'btn btn-sm rounded-pill px-3 py-2 text-body-secondary fw-medium border-0',
                    'data-action'      => 'fastpix-source-tab',
                    'data-source-type' => 'urlpull',
                    'role'             => 'tab',
                    'aria-selected'    => 'false',
                    'style'            => 'background:transparent;',
                ]
            ),
            [
                'class'      => 'd-inline-flex p-1 mb-3 rounded-pill',
                'style'      => 'background:#f3f4f6;gap:4px;',
                'role'       => 'tablist',
                'aria-label' => s(get_string('sourcetype', 'mod_fastpix')),
            ]
        );
        $mform->addElement('html', $pillgroup);
        $mform->addElement('html', '<style>[data-action="fastpix-source-tab"]:focus-visible{outline:2px solid rgba(255,0,80,.4);outline-offset:2px;}</style>');

        // The <select> remains in the DOM so existing PHP validation reads
        // $data['source_type'] unchanged AND Moodle's hideIf machinery still
        // toggles the URL row. The form-row is hidden via inline CSS — pill
        // clicks (handled in AMD) update its value and dispatch change.
        $mform->addElement('select', 'source_type', get_string('sourcetype', 'mod_fastpix'), [
            'upload'  => get_string('sourcetype_upload', 'mod_fastpix'),
            'urlpull' => get_string('sourcetype_urlpull', 'mod_fastpix'),
        ]);
        $mform->setDefault('source_type', 'upload');
        $mform->addElement('html', '<style>#fitem_id_source_type{display:none !important;}</style>');

        $mform->addElement('html', '<div data-region="fastpix-upload-widget"
            data-fieldname-session="upload_session_id"></div>');

        // URL pull section — entire panel rendered as one HTML block so it
        // matches the dashed-card mockup. The visible <input name="source_url">
        // and <button name="validate_url"> submit/AMD-wire by name. The
        // section's visibility is driven entirely by the pill toggle in AMD;
        // no mform hideIf needed here.
        $dlcode        = html_writer::tag('code', 'dl=1', ['class' => 'bg-body-secondary px-1 rounded small']);
        $supportedmeta = get_string('urlpull_supported_meta', 'mod_fastpix', $dlcode);

        // Preserve the typed URL across server-side re-renders. The input is
        // emitted as raw HTML (not a registered mform element), so QuickForm
        // does not auto-repopulate it on validation failure — without this,
        // a failed save bounces back with the URL field cleared, looping
        // the validation error.
        $submittedurl = optional_param('source_url', '', PARAM_RAW_TRIMMED);

        $urlpullinput = html_writer::empty_tag('input', [
            'type'         => 'url',
            'name'         => 'source_url',
            'value'        => $submittedurl,
            'class'        => 'form-control form-control-lg flex-grow-1',
            'placeholder'  => get_string('urlpull_placeholder', 'mod_fastpix'),
            'autocomplete' => 'off',
            'spellcheck'   => 'false',
            'style'        => 'min-width:16rem;',
        ]);
        $urlpullbtn = html_writer::tag('button',
            html_writer::tag('i', '', ['class' => 'fa fa-cloud-arrow-down me-2', 'aria-hidden' => 'true'])
                . s(get_string('urlpull_start_ingest', 'mod_fastpix')),
            [
                'type'  => 'button',
                'name'  => 'validate_url',
                'class' => 'btn fw-medium px-4 rounded-pill bg-primary-subtle text-primary border-0',
                'style' => 'white-space:nowrap;',
            ]
        );

        $urlpullcard = html_writer::div(
            html_writer::div(
                html_writer::tag('i', '', ['class' => 'fa fa-link me-2', 'aria-hidden' => 'true', 'style' => 'color:#6b7280;'])
                    . html_writer::tag('span', s(get_string('urlpull_card_title', 'mod_fastpix')),
                        ['class' => 'fw-semibold', 'style' => 'font-size:15px;']),
                'd-flex align-items-center mb-2'
            )
            . html_writer::tag('p', s(get_string('urlpull_card_help', 'mod_fastpix')),
                ['class' => 'text-body-secondary small mb-3'])
            . html_writer::div($urlpullinput . $urlpullbtn,
                'd-flex flex-wrap align-items-stretch gap-2')
            . html_writer::div('', 'text-body-secondary small mt-2',
                ['data-region' => 'fastpix-urlpull-status']),
            'rounded-3 p-4',
            ['style' => 'border:2px dashed #d1d5db;background:#fff;']
        );

        $urlpullhelpers =
            html_writer::tag('p',
                html_writer::tag('i', '', ['class' => 'fa fa-circle-info me-2 mt-1', 'aria-hidden' => 'true'])
                    . html_writer::tag('span', $supportedmeta),
                ['class' => 'd-flex align-items-start mt-3 mb-1 text-body-secondary small']
            )
            . html_writer::tag('p',
                html_writer::tag('i', '', ['class' => 'fa fa-shield-halved me-2 mt-1', 'aria-hidden' => 'true', 'style' => 'color:#6b7280;'])
                    . html_writer::tag('span', s(get_string('urlpull_ssrf_meta', 'mod_fastpix'))),
                ['class' => 'd-flex align-items-start mb-3 text-body-secondary small']
            );

        $urlpullsection = html_writer::div(
            $urlpullcard . $urlpullhelpers,
            '',
            ['data-region' => 'fastpix-urlpull-section', 'hidden' => 'hidden']
        );

        $mform->addElement('html', $urlpullsection);

        $mform->addElement('hidden', 'upload_session_id');
        $mform->setType('upload_session_id', PARAM_INT);

        $mform->addElement('header', 'playbackoptions', get_string('playbackoptions', 'mod_fastpix'));

        $mform->addElement('advcheckbox', 'no_skip_required',
            get_string('noskip', 'mod_fastpix'), get_string('noskip_desc', 'mod_fastpix'));
        $mform->addHelpButton('no_skip_required', 'noskip', 'mod_fastpix');

        $mform->addElement('advcheckbox', 'default_show_captions',
            get_string('autocaptions', 'mod_fastpix'), get_string('autocaptions_desc', 'mod_fastpix'));
        $mform->addHelpButton('default_show_captions', 'autocaptions', 'mod_fastpix');

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
                // Real = watched_seconds > 0 (excludes teacher previews).
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
