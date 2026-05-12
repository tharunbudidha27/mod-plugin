<?php
/**
 * Tests for \mod_fastpix\service\playback_service.
 *
 * Phase C only — verifies state-routing logic without exercising the
 * local_fastpix consumed surface (which requires a fully configured
 * gateway / signing key). Full integration coverage lands in Phase D
 * alongside the watch_tracker.
 *
 * @package    mod_fastpix
 * @category   test
 * @covers     \mod_fastpix\service\playback_service
 */

namespace mod_fastpix;

use mod_fastpix\dto\view_state_error;
use mod_fastpix\dto\view_state_processing;
use mod_fastpix\service\playback_service;

defined('MOODLE_INTERNAL') || die();

class playback_service_test extends \advanced_testcase {

    public function test_resolve_for_view_returns_processing_when_only_session_set(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $activity = (object)[
            'course'             => $course->id,
            'name'               => 'p',
            'intro'              => '',
            'introformat'        => FORMAT_HTML,
            'upload_session_id'  => 999999,   // pretend session, no asset
            'fastpix_asset_id'   => null,
            'completion_watch_percent' => 90,
            'no_skip_required'   => 0,
            'default_show_captions' => 0,
            'grademax'           => 100,
            'timecreated'        => time(),
            'timemodified'       => time(),
        ];
        $activity->id = $DB->insert_record('fastpix', $activity);

        $cm = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id, 'name' => 'cm']);
        $cminfo = \cm_info::create(get_coursemodule_from_id('fastpix', $cm->cmid));

        $state = playback_service::instance()->resolve_for_view($activity, 2, $cminfo);
        $this->assertInstanceOf(view_state_processing::class, $state);
    }

    public function test_resolve_for_view_returns_error_when_no_session_no_asset(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $activity = (object)[
            'course'             => $course->id,
            'name'               => 'no-asset',
            'intro'              => '',
            'introformat'        => FORMAT_HTML,
            'upload_session_id'  => null,
            'fastpix_asset_id'   => null,
            'completion_watch_percent' => 90,
            'no_skip_required'   => 0,
            'default_show_captions' => 0,
            'grademax'           => 100,
            'timecreated'        => time(),
            'timemodified'       => time(),
        ];
        $activity->id = $DB->insert_record('fastpix', $activity);

        $cm = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id, 'name' => 'cm2']);
        $cminfo = \cm_info::create(get_coursemodule_from_id('fastpix', $cm->cmid));

        $state = playback_service::instance()->resolve_for_view($activity, 2, $cminfo);
        $this->assertInstanceOf(view_state_error::class, $state);
        $this->assertSame('videounavailable', $state->reason_key);
    }

    public function test_has_attempts_for_returns_false_when_only_preview_rows(): void {
        // C6 contract — preview rows (watched_seconds = 0) must NOT count
        // as "real" attempts that block asset swap.
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id]);
        $activityid = $cm->id;

        // Preview row (zero-second).
        $DB->insert_record('fastpix_attempt', (object)[
            'userid'           => 2,
            'activity_id'      => $activityid,
            'asset_id'         => 1,
            'session_token'    => str_repeat('a', 64),
            'session_start_ts' => time(),
            'watched_seconds'  => 0,
            'seek_count'       => 0,
            'fraud_count'      => 0,
            'completion_state' => 'in_progress',
        ]);

        $this->assertFalse(playback_service::instance()->has_attempts_for($activityid));

        // Now add a real-watch row.
        $DB->insert_record('fastpix_attempt', (object)[
            'userid'           => 3,
            'activity_id'      => $activityid,
            'asset_id'         => 1,
            'session_token'    => str_repeat('b', 64),
            'session_start_ts' => time(),
            'watched_seconds'  => 5,
            'seek_count'       => 0,
            'fraud_count'      => 0,
            'completion_state' => 'in_progress',
        ]);
        $this->assertTrue(playback_service::instance()->has_attempts_for($activityid));
    }
}
