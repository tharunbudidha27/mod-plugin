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
 * Gradebook item registration / write. Bare-name per M1 — Moodle core
 * invokes this via call_user_func("{$modname}_grade_item_update", ...).
 *
 * @param \stdClass $activity Row from mdl_fastpix.
 * @param array|string|null $grades Array of {userid, rawgrade, dategraded}
 *        objects, or the literal string 'reset' to clear all grades, or null
 *        for item-only registration.
 * @return int GRADE_UPDATE_OK / GRADE_UPDATE_FAILED / GRADE_UPDATE_MULTIPLE.
 */
function fastpix_grade_item_update($activity, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname'  => $activity->name,
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => (float)($activity->grademax ?? 100),
        'grademin'  => 0,
        'idnumber'  => $activity->cmidnumber ?? '',
    ];

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update(
        'mod/fastpix',
        $activity->course,
        'mod',
        'fastpix',
        $activity->id,
        0,
        $grades,
        $params
    );
}

/**
 * Bulk regrade entry point — invoked by Moodle's gradebook recompute UI.
 * Iterates attempts where has_completed=1 and writes rawgrade = grademax
 * (CG4: completion is binary — no partial credit).
 *
 * @param \stdClass|null $activity NULL → walk all fastpix instances.
 * @param int $userid 0 → all enrolled users; otherwise filter.
 * @param bool $nullifnone If true and no qualifying attempt exists,
 *                        write rawgrade=null to clear stale gradebook rows.
 */
function fastpix_update_grades($activity = null, $userid = 0, $nullifnone = true) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    if ($activity === null) {
        // Walk all fastpix activities + recurse for each.
        $rs = $DB->get_recordset('fastpix');
        foreach ($rs as $row) {
            fastpix_update_grades($row, $userid, $nullifnone);
        }
        $rs->close();
        return;
    }

    $sql = "SELECT userid FROM {fastpix_attempt}
             WHERE activity_id = :aid AND has_completed = 1";
    $params = ['aid' => $activity->id];
    if ($userid > 0) {
        $sql .= ' AND userid = :uid';
        $params['uid'] = $userid;
    }
    $completedusers = $DB->get_fieldset_sql($sql, $params);

    // grade_update REQUIRES the $grades array to be keyed by user id — same
    // constraint applies to watch_tracker_service::record_progress (CG1).
    $grades = [];
    foreach ($completedusers as $uid) {
        $uid = (int)$uid;
        $grades[$uid] = (object)[
            'userid'     => $uid,
            'rawgrade'   => (float)($activity->grademax ?? 100),
            'dategraded' => time(),
        ];
    }

    if (empty($grades)) {
        if ($nullifnone && $userid > 0) {
            // Single-user clear path — write rawgrade=null for that user.
            $grades = [
                $userid => (object)['userid' => $userid, 'rawgrade' => null],
            ];
        } else if (!$nullifnone) {
            return;
        } else {
            // No completions yet on this activity — register the item only.
            fastpix_grade_item_update($activity);
            return;
        }
    }

    fastpix_grade_item_update($activity, $grades);
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
 * Surface custom-completion rules + threshold value on cm_info->customdata
 * so the completion-rules UI (and our custom_completion class) can read the
 * configured threshold for each activity. Pattern matches mod_quiz / mod_forum.
 */
function fastpix_get_coursemodule_info($coursemodule) {
    global $DB;

    $activity = $DB->get_record(
        'fastpix',
        ['id' => $coursemodule->instance],
        'id, name, intro, introformat, completion_watch_percent'
    );
    if (!$activity) {
        return false;
    }

    $info = new \cached_cm_info();
    $info->name = $activity->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('fastpix', $activity, $coursemodule->id, false);
    }
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionwatchedpercent'] =
            (int)$activity->completion_watch_percent;
    }
    // Force Moodle to render the brand-coloured icon.svg as an <img> rather
    // than applying the monologo→mask-image→purpose-tint pipeline. Without
    // this, Moodle 4.x recolours the icon to the assessment-purpose tint
    // (pink/red in Boost) and the brand greens are lost.
    $info->iconurl = new \moodle_url('/mod/fastpix/pix/icon.svg');
    return $info;
}

/**
 * Localized descriptions for the activity-completion-rules UI (CG3).
 * Reads the threshold stamped onto cm->customdata by
 * fastpix_get_coursemodule_info().
 */
function fastpix_get_completion_active_rule_descriptions($cm) {
    if (empty($cm->customdata['customcompletionrules']['completionwatchedpercent'])) {
        return [];
    }
    $threshold = (int)$cm->customdata['customcompletionrules']['completionwatchedpercent'];
    return [
        get_string('completionwatchedpercent_desc', 'mod_fastpix', $threshold),
    ];
}
