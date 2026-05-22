<?php
namespace mod_fastpix\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Watch milestone reached (25/50/75/100 percent of asset duration).
 *
 * Idempotency contract (CG5): fires exactly once per (user, activity,
 * milestone) tuple. watch_tracker_service guards re-fire by checking
 * mdl_fastpix_attempt.milestone_<n>_at inside a delegated transaction.
 * The reached percent is carried in $other['milestone'] for observers.
 */
class watch_milestone extends \core\event\base {

    /**
     * @param int $attemptid mdl_fastpix_attempt.id (used as objectid)
     * @param int $milestone One of 25, 50, 75, 100
     */
    public static function create_from_attempt(int $attemptid, int $milestone): self {
        global $DB;
        $attempt = $DB->get_record('fastpix_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        // Load the activity row so we can scope the cm lookup by course id.
        // Bare instance-only lookup is ambiguous when orphan course_modules
        // rows exist (raw-SQL resets that didn't cascade through cm cleanup).
        $activity = $DB->get_record('fastpix', ['id' => (int)$attempt->activity_id], 'id, course', MUST_EXIST);
        $cm = get_coursemodule_from_instance('fastpix', (int)$activity->id, (int)$activity->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $event = self::create([
            'objectid' => $attemptid,
            'context'  => $context,
            'userid'   => (int)$attempt->userid,
            'other'    => [
                'milestone' => $milestone,
            ],
        ]);
        return $event;
    }

    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'fastpix_attempt';
    }

    public static function get_name() {
        return get_string('event_watch_milestone', 'mod_fastpix');
    }

    public function get_description() {
        $milestone = isset($this->other['milestone']) ? (int)$this->other['milestone'] : 0;
        return "The user with id '$this->userid' reached the {$milestone}% watch milestone for FastPix Video attempt id '$this->objectid'.";
    }

    public function get_url() {
        return new \moodle_url('/mod/fastpix/view.php', ['id' => $this->contextinstanceid]);
    }
}
