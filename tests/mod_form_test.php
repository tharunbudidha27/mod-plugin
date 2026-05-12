<?php
/**
 * Tests for mod_form.php — Phase B server-side validation paths.
 *
 * Covers M10 (server-side validation) and the documented rejection paths
 * (error_uploadrequired / error_urlrequired / error_urlnotvalidated /
 * error_thresholdrange).
 *
 * Drives the mod_fastpix-specific rules via validate_fastpix_rules(),
 * the testable extract of validation(). parent::validation chains into
 * grade_item / $COURSE state that isn't easily stubbed in unit context;
 * Phase D will add full-stack integration tests.
 *
 * @package    mod_fastpix
 * @category   test
 * @covers     \mod_fastpix_mod_form
 */

namespace mod_fastpix;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/fastpix/mod_form.php');

class mod_form_test extends \advanced_testcase {

    /**
     * Reflection-stamped form instance — bypasses moodleform_mod's
     * constructor (which needs full course/section context).
     */
    private function make_form(int $instanceid = 0): \mod_fastpix_mod_form {
        $ref = new \ReflectionClass(\mod_fastpix_mod_form::class);
        $form = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('_instance');
        $prop->setAccessible(true);
        $prop->setValue($form, $instanceid);
        return $form;
    }

    public function test_validate_rejects_empty_upload(): void {
        $this->resetAfterTest();
        $errors = $this->make_form()->validate_fastpix_rules(
            ['source_type' => 'upload', 'upload_session_id' => '', 'name' => 'x']
        );
        $this->assertArrayHasKey('name', $errors);
    }

    public function test_validate_rejects_empty_url_in_urlpull_mode(): void {
        $this->resetAfterTest();
        $_POST['source_url'] = '';
        $errors = $this->make_form()->validate_fastpix_rules(
            ['source_type' => 'urlpull', 'upload_session_id' => '', 'name' => 'x']
        );
        $this->assertArrayHasKey('name', $errors);
        unset($_POST['source_url']);
    }

    public function test_validate_rejects_url_with_no_session_id(): void {
        $this->resetAfterTest();
        $_POST['source_url'] = 'https://example.com/video.mp4';
        $errors = $this->make_form()->validate_fastpix_rules(
            ['source_type' => 'urlpull', 'upload_session_id' => '', 'name' => 'x']
        );
        $this->assertArrayHasKey('name', $errors);
        unset($_POST['source_url']);
    }

    public function test_validate_rejects_threshold_out_of_range(): void {
        $this->resetAfterTest();
        $form = $this->make_form();

        $errors = $form->validate_fastpix_rules([
            'source_type'                       => 'upload',
            'upload_session_id'                 => 1,
            'name'                              => 'x',
            'completionwatchedpercentenabled'   => 1,
            'completionwatchedpercent'          => 0,
        ]);
        $this->assertArrayHasKey('completionwatchedpercentgroup', $errors);

        $errors = $form->validate_fastpix_rules([
            'source_type'                       => 'upload',
            'upload_session_id'                 => 1,
            'name'                              => 'x',
            'completionwatchedpercentenabled'   => 1,
            'completionwatchedpercent'          => 101,
        ]);
        $this->assertArrayHasKey('completionwatchedpercentgroup', $errors);
    }

    public function test_validate_accepts_valid_upload_submission(): void {
        $this->resetAfterTest();
        $errors = $this->make_form()->validate_fastpix_rules([
            'source_type'                     => 'upload',
            'upload_session_id'               => 1,
            'name'                            => 'x',
            'completionwatchedpercentenabled' => 1,
            'completionwatchedpercent'        => 90,
        ]);
        $this->assertEmpty($errors, 'no errors expected on a clean submission');
    }
}
