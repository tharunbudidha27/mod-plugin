<?php
namespace mod_fastpix\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Fired on every view.php load (rule M2 FEATURE_COMPLETION_TRACKS_VIEWS).
 */
class activity_viewed extends \core\event\base {

    public static function create_from_activity(\stdClass $activity, \context_module $context): self {
        $event = self::create([
            'objectid' => $activity->id,
            'context'  => $context,
        ]);
        $event->add_record_snapshot('fastpix', $activity);
        return $event;
    }

    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'fastpix';
    }

    public static function get_name() {
        return get_string('eventactivityviewed', 'mod_fastpix');
    }

    public function get_description() {
        return "The user with id '$this->userid' viewed the FastPix Video activity with course module id '$this->contextinstanceid'.";
    }

    public function get_url() {
        return new \moodle_url('/mod/fastpix/view.php', ['id' => $this->contextinstanceid]);
    }
}
