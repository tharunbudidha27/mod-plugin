<?php
/**
 * Tests for mod/fastpix/lib.php — Moodle activity callbacks.
 *
 * Covers M2 (FEATURE matrix), M3 (5 capabilities), CG2 (grade callback
 * signatures). Phase A/B contracts only; Phase D will add tests for the
 * grade callback bodies.
 *
 * @package    mod_fastpix
 * @category   test
 */

namespace mod_fastpix;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/fastpix/lib.php');

/**
 * @covers ::fastpix_supports
 */
class lib_test extends \advanced_testcase {

    public function test_fastpix_supports_returns_documented_matrix(): void {
        $this->assertTrue(fastpix_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertTrue(fastpix_supports(FEATURE_COMPLETION_HAS_RULES));
        $this->assertTrue(fastpix_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
        $this->assertTrue(fastpix_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertTrue(fastpix_supports(FEATURE_GROUPS));
        $this->assertTrue(fastpix_supports(FEATURE_GROUPINGS));
        $this->assertTrue(fastpix_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(fastpix_supports(FEATURE_SHOW_DESCRIPTION));
        $this->assertSame(MOD_PURPOSE_ASSESSMENT, fastpix_supports(FEATURE_MOD_PURPOSE));
    }

    public function test_fastpix_supports_returns_null_for_unsupported(): void {
        // Out-of-scope features per design doc §1 / mod/fastpix exclusions.
        $this->assertNull(fastpix_supports(FEATURE_USES_QUESTIONS));
        $this->assertNull(fastpix_supports(FEATURE_RATE));
        $this->assertNull(fastpix_supports(FEATURE_COMMENT));
    }

    public function test_grade_callback_signatures_present(): void {
        // CG2 — both callbacks must exist with the right argument shape;
        // Phase D fills in the bodies.
        $this->assertTrue(function_exists('fastpix_grade_item_update'));
        $this->assertTrue(function_exists('fastpix_update_grades'));

        $ref = new \ReflectionFunction('fastpix_grade_item_update');
        $this->assertSame('activity', $ref->getParameters()[0]->getName());
        $this->assertCount(2, $ref->getParameters());

        $ref = new \ReflectionFunction('fastpix_update_grades');
        $this->assertCount(3, $ref->getParameters());
        $this->assertSame('activity', $ref->getParameters()[0]->getName());
        $this->assertSame('userid', $ref->getParameters()[1]->getName());
    }

    public function test_capabilities_declared(): void {
        // M3 / PR-14 — exactly five capabilities.
        global $CFG;
        $capabilities = [];
        require($CFG->dirroot . '/mod/fastpix/db/access.php');

        $expected = [
            'mod/fastpix:addinstance',
            'mod/fastpix:view',
            'mod/fastpix:viewallattempts',
            'mod/fastpix:graderoverride',
            'mod/fastpix:uploadmedia',
        ];
        $actual = array_keys($capabilities);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_lifecycle_add_update_delete_instance(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $data = (object)[
            'course'       => $course->id,
            'name'         => 'Test FastPix Activity',
            'intro'        => 'desc',
            'introformat'  => FORMAT_HTML,
        ];
        $id = fastpix_add_instance($data);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $data->instance = $id;
        $data->name = 'Renamed';
        $this->assertTrue(fastpix_update_instance($data));

        $this->assertTrue(fastpix_delete_instance($id));
        // Deleting again returns false (no row).
        $this->assertFalse(fastpix_delete_instance($id));
    }
}
