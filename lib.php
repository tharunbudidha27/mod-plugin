<?php
defined('MOODLE_INTERNAL') || die();

function fastpix_supports($feature) {
    switch ($feature) {
        case FEATURE_GRADE_HAS_GRADE:         return true;
        case FEATURE_COMPLETION_HAS_RULES:    return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        case FEATURE_BACKUP_MOODLE2:          return true;
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_MOD_INTRO:               return true;
        case FEATURE_SHOW_DESCRIPTION:        return true;
        case FEATURE_MOD_PURPOSE:             return MOD_PURPOSE_ASSESSMENT;
        default:                              return null;
    }
}

function fastpix_add_instance($data, $mform = null) {
    global $DB;

    $now = time();
    $record = (object) [
        'course'                   => $data->course,
        'name'                     => $data->name,
        'intro'                    => $data->intro ?? '',
        'introformat'              => $data->introformat ?? FORMAT_HTML,
        'fastpix_asset_id'         => null,
        'upload_session_id'        => !empty($data->upload_session_id) ? (int)$data->upload_session_id : null,
        'completion_watch_percent' => !empty($data->completionwatchedpercentenabled)
            ? (int)$data->completionwatchedpercent
            : 90,
        'no_skip_required'         => !empty($data->no_skip_required) ? 1 : 0,
        'default_show_captions'    => !empty($data->default_show_captions) ? 1 : 0,
        'grademax'                 => isset($data->grade) ? (float)$data->grade : 100,
        'timecreated'              => $now,
        'timemodified'             => $now,
    ];

    return $DB->insert_record('fastpix', $record);
}

function fastpix_update_instance($data, $mform = null) {
    global $DB;

    $record = (object) [
        'id'                       => $data->instance,
        'name'                     => $data->name,
        'intro'                    => $data->intro ?? '',
        'introformat'              => $data->introformat ?? FORMAT_HTML,
        'completion_watch_percent' => !empty($data->completionwatchedpercentenabled)
            ? (int)$data->completionwatchedpercent
            : 90,
        'no_skip_required'         => !empty($data->no_skip_required) ? 1 : 0,
        'default_show_captions'    => !empty($data->default_show_captions) ? 1 : 0,
        'grademax'                 => isset($data->grade) ? (float)$data->grade : 100,
        'timemodified'             => time(),
    ];

    if (!empty($data->upload_session_id)) {
        $record->upload_session_id = (int)$data->upload_session_id;
        // The webhook will populate fastpix_asset_id; clear any stale reference.
        $record->fastpix_asset_id = null;
    }

    return $DB->update_record('fastpix', $record);
}

function fastpix_delete_instance($id) {
    global $DB;

    if (!$DB->record_exists('fastpix', ['id' => $id])) {
        return false;
    }

    $DB->delete_records('fastpix_attempt', ['activity_id' => $id]);
    $DB->delete_records('fastpix', ['id' => $id]);

    return true;
}

/**
 * Gradebook integration — Phase D will call grade_update() here.
 *
 * @phase D — currently a stub returning GRADE_UPDATE_OK; the body lands
 *            with the watch_tracker so completion-state transitions write
 *            grades through the Moodle gradebook API (CG1/CG2).
 */
function fastpix_grade_item_update($activity, $grades = null) {
    return GRADE_UPDATE_OK;
}

/**
 * Bulk regrade — Phase D will iterate attempts and call grade_update().
 *
 * @phase D — used by Moodle's gradebook recompute UI. Currently a no-op.
 */
function fastpix_update_grades($activity = null, $userid = 0, $nullifnone = true) {
    return;
}

/**
 * Recycle-bin hook — Phase E (backup/restore + asset lifecycle).
 *
 * @phase E — soft-delete the asset via local_fastpix when no other activity
 *            references it.
 */
function fastpix_pre_course_module_delete($cm) {
    return;
}

/**
 * Custom-completion rule descriptions — Phase D.
 *
 * @phase D — returns localized descriptions for completionwatchedpercent
 *            so the completion-rules UI can render them.
 */
function fastpix_get_completion_active_rule_descriptions($cm) {
    return [];
}
