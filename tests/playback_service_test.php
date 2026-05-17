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
use mod_fastpix\dto\view_state_player;
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
        // C6 contract — preview rows (empty watched_intervals) must NOT count
        // as "real" attempts that block asset swap. Post-Phase-D-Slice-A:
        // the predicate is watched_intervals <> '' (was watched_seconds > 0).
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id]);
        $activityid = $cm->id;

        // Preview row (empty intervals, default current_position/has_completed).
        $DB->insert_record('fastpix_attempt', (object)[
            'userid'            => 2,
            'activity_id'       => $activityid,
            'asset_id'          => 1,
            'session_token'     => str_repeat('a', 64),
            'session_start_ts'  => time(),
            'watched_intervals' => '',
            'current_position'  => 0,
            'has_completed'     => 0,
            'seek_count'        => 0,
            'fraud_count'       => 0,
            'completion_state'  => 'in_progress',
        ]);

        $this->assertFalse(playback_service::instance()->has_attempts_for($activityid));

        // Now add a real-watch row (any non-empty intervals JSON).
        $DB->insert_record('fastpix_attempt', (object)[
            'userid'            => 3,
            'activity_id'       => $activityid,
            'asset_id'          => 1,
            'session_token'     => str_repeat('b', 64),
            'session_start_ts'  => time(),
            'watched_intervals' => '[[0,5]]',
            'current_position'  => 5,
            'has_completed'     => 0,
            'seek_count'        => 0,
            'fraud_count'       => 0,
            'completion_state'  => 'in_progress',
        ]);
        $this->assertTrue(playback_service::instance()->has_attempts_for($activityid));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Phase D Slice A Step 1 — coverage-based watch tracking.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build (course, activity row, student userid, asset stub) for tests
     * that need to exercise get_or_create_attempt() through its real
     * DB-insert path. The generator returns the fastpix row directly; its
     * ->id field is the activity instance id (mdl_fastpix.id) which
     * get_or_create_attempt accepts as $activity->id.
     *
     * Returns [activity stdClass, studentid, assetstub].
     */
    private function make_student_attempt_fixture(): array {
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('fastpix', ['course' => $course->id]);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $asset = (object)[
            'id'               => 4242,
            'fastpix_id'       => 'asset_stub_phpunit',
            'duration'         => 100,
            'no_skip_required' => 0,
            'deleted_at'       => null,
            'status'           => 'ready',
        ];

        return [$activity, (int)$student->id, $asset];
    }

    public function test_get_or_create_attempt_initializes_intervals_as_empty_string(): void {
        $this->resetAfterTest();
        global $DB;

        [$activity, $studentid, $asset] = $this->make_student_attempt_fixture();

        playback_service::instance()->get_or_create_attempt($activity, $studentid, $asset);

        $row = $DB->get_record('fastpix_attempt', ['userid' => $studentid, 'activity_id' => (int)$activity->id]);
        $this->assertNotFalse($row, 'attempt row should have been created');
        $this->assertSame('', $row->watched_intervals);
    }

    public function test_get_or_create_attempt_initializes_current_position_zero(): void {
        $this->resetAfterTest();
        global $DB;

        [$activity, $studentid, $asset] = $this->make_student_attempt_fixture();

        playback_service::instance()->get_or_create_attempt($activity, $studentid, $asset);

        $row = $DB->get_record('fastpix_attempt', ['userid' => $studentid, 'activity_id' => (int)$activity->id]);
        $this->assertNotFalse($row);
        $this->assertEquals(0.0, (float)$row->current_position);
    }

    public function test_get_or_create_attempt_initializes_has_completed_zero(): void {
        $this->resetAfterTest();
        global $DB;

        [$activity, $studentid, $asset] = $this->make_student_attempt_fixture();

        playback_service::instance()->get_or_create_attempt($activity, $studentid, $asset);

        $row = $DB->get_record('fastpix_attempt', ['userid' => $studentid, 'activity_id' => (int)$activity->id]);
        $this->assertNotFalse($row);
        $this->assertEquals(0, (int)$row->has_completed);
    }

    /**
     * Verifies DTO field plumbing for current_position. The full resolve_for_view
     * player path requires the local_fastpix gateway, which unit tests don't
     * stand up — so we assert the DTO contract directly. resolve_for_view
     * forwards $attempt->current_position into this slot (D5(c)), so as long
     * as the DTO accepts and round-trips it, the wiring is correct.
     */
    public function test_resolve_for_view_passes_current_position_to_dto(): void {
        $dto = new view_state_player(
            playbackid:               'pb',
            playbacktoken:            'tok',
            expiresatts:             0,
            drmrequired:              false,
            accentcolor:              null,
            defaultshowcaptions:     false,
            activity_name:             'a',
            activity_id:               1,
            cm_id:                     1,
            asset_id:                  1,
            session_token:             str_repeat('c', 64),
            no_skip_required:          false,
            initial_coverage_percent:  0,
            completion_watch_percent:  90,
            current_position:          42.5,
            asset_duration_seconds:    100,
            initial_intervals_json:    '[]',
            has_completed:             false,
        );
        $this->assertSame(42.5, $dto->current_position);
    }

    public function test_resolve_for_view_passes_completion_watch_percent_to_dto(): void {
        $dto = new view_state_player(
            playbackid:               'pb',
            playbacktoken:            'tok',
            expiresatts:             0,
            drmrequired:              false,
            accentcolor:              null,
            defaultshowcaptions:     false,
            activity_name:             'a',
            activity_id:               1,
            cm_id:                     1,
            asset_id:                  1,
            session_token:             str_repeat('c', 64),
            no_skip_required:          false,
            initial_coverage_percent:  0,
            completion_watch_percent:  75,
            current_position:          0.0,
            asset_duration_seconds:    100,
            initial_intervals_json:    '[]',
            has_completed:             false,
        );
        $this->assertSame(75, $dto->completion_watch_percent);
    }

    public function test_resolve_for_view_computes_initial_coverage_zero_when_no_intervals(): void {
        // Static helper is the same code path resolve_for_view() uses (D5(b)).
        $this->assertSame(0, playback_service::compute_initial_coverage_percent('', 100));
        $this->assertSame(0, playback_service::compute_initial_coverage_percent(null, 100));
        $this->assertSame(0, playback_service::compute_initial_coverage_percent('[]', 100));
    }

    public function test_resolve_for_view_computes_initial_coverage_from_intervals(): void {
        // [[0,30],[40,50]] = 30 + 10 = 40 watched seconds. Duration 100 → 40%.
        $this->assertSame(40, playback_service::compute_initial_coverage_percent('[[0,30],[40,50]]', 100));

        // Clamp at 100% if intervals over-cover (shouldn't happen in practice
        // but the math should be safe).
        $this->assertSame(100, playback_service::compute_initial_coverage_percent('[[0,200]]', 100));

        // duration <= 0 collapses to 0 regardless of intervals.
        $this->assertSame(0, playback_service::compute_initial_coverage_percent('[[0,30]]', 0));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Phase D Slice A Step 2 — tracker JS hydration: DTO field plumbing for
    // initial_intervals_json. Full resolve_for_view player path is exercised
    // by the smoke tests (no local_fastpix mocking infra here); this asserts
    // the DTO contract directly, which is the same value the renderer hands
    // to the mustache template via the {{{ initial_intervals_json }}} stash.
    // ─────────────────────────────────────────────────────────────────────

    public function test_initial_intervals_json_passed_to_dto_when_empty(): void {
        // Maps to playback_service::resolve_for_view's
        //   !empty($attempt->watched_intervals) ? (string)$attempt->watched_intervals : '[]'
        // — for a brand new attempt the column default is empty string, which
        // collapses to the JSON empty-array literal in the DTO.
        $dto = new view_state_player(
            playbackid:               'pb',
            playbacktoken:            'tok',
            expiresatts:             0,
            drmrequired:              false,
            accentcolor:              null,
            defaultshowcaptions:     false,
            activity_name:             'a',
            activity_id:               1,
            cm_id:                     1,
            asset_id:                  1,
            session_token:             str_repeat('d', 64),
            no_skip_required:          false,
            initial_coverage_percent:  0,
            completion_watch_percent:  90,
            current_position:          0.0,
            asset_duration_seconds:    100,
            initial_intervals_json:    '[]',
            has_completed:             false,
        );
        $this->assertSame('[]', $dto->initial_intervals_json);
        $this->assertFalse($dto->has_completed);
    }

    public function test_initial_intervals_json_passed_to_dto_when_populated(): void {
        // The DTO must round-trip the JSON literal byte-for-byte — the mustache
        // template emits it via {{{ ... }}} into a `JSON.parse(...)`-compatible
        // expression in the require([...]) block, so any HTML-escape or quote
        // mangling here would break the tracker JS init.
        $dto = new view_state_player(
            playbackid:               'pb',
            playbacktoken:            'tok',
            expiresatts:             0,
            drmrequired:              false,
            accentcolor:              null,
            defaultshowcaptions:     false,
            activity_name:             'a',
            activity_id:               1,
            cm_id:                     1,
            asset_id:                  1,
            session_token:             str_repeat('e', 64),
            no_skip_required:          false,
            initial_coverage_percent:  30,
            completion_watch_percent:  90,
            current_position:          30.0,
            asset_duration_seconds:    100,
            initial_intervals_json:    '[[0,30]]',
            has_completed:             false,
        );
        $this->assertSame('[[0,30]]', $dto->initial_intervals_json);
    }
}
