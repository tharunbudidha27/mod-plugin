<?php
namespace mod_fastpix\completion;

use core_completion\activity_custom_completion;
use local_fastpix\service\asset_service;
use mod_fastpix\service\playback_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Custom completion for FastPix Video — exactly one rule (CG3 / PR-19):
 * `completionwatchedpercent`. Adding a second rule fails CI.
 *
 * Completion is "sticky" once granted (mdl_fastpix_attempt.has_completed = 1).
 * This guards against the edge case where a teacher edits the activity to
 * raise the threshold AFTER a student already qualified — the student keeps
 * their completion. The threshold-vs-coverage comparison only runs when
 * has_completed is still 0.
 */
class custom_completion extends activity_custom_completion {

    public static function get_defined_custom_rules(): array {
        return ['completionwatchedpercent'];
    }

    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $activity = $DB->get_record('fastpix', ['id' => (int)$this->cm->instance], '*', MUST_EXIST);

        $attempt = $DB->get_record('fastpix_attempt', [
            'userid'      => (int)$this->userid,
            'activity_id' => (int)$activity->id,
        ]);

        if (!$attempt) {
            return COMPLETION_INCOMPLETE;
        }

        // Sticky completion (CG4) — once has_completed=1, never re-evaluate.
        // Threshold changes by teachers do not retroactively revoke completion.
        if (!empty($attempt->has_completed)) {
            return COMPLETION_COMPLETE;
        }

        // Re-derive coverage from the stored intervals. Asset duration is the
        // only thing we need from local_fastpix; deleted / unavailable asset
        // is treated as incomplete (never block completion on an integration
        // failure, but never grant it either).
        $asset = asset_service::get_by_id((int)$attempt->asset_id);
        if ($asset === null) {
            return COMPLETION_INCOMPLETE;
        }
        $duration = (int)($asset->duration ?? 0);

        $percent = playback_service::compute_initial_coverage_percent(
            (string)($attempt->watched_intervals ?? ''),
            $duration
        );

        $threshold = (int)($activity->completion_watch_percent ?? 90);
        return ($percent >= $threshold) ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    public function get_custom_rule_descriptions(): array {
        $threshold = isset($this->cm->customdata['customcompletionrules']['completionwatchedpercent'])
            ? (int)$this->cm->customdata['customcompletionrules']['completionwatchedpercent']
            : 90;
        return [
            'completionwatchedpercent' => get_string('completionwatchedpercent_desc', 'mod_fastpix', $threshold),
        ];
    }

    public function get_sort_order(): array {
        return ['completionwatchedpercent'];
    }
}
