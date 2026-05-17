<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/fastpix/backup/moodle2/restore_fastpix_stepslib.php');

/**
 * Activity-level restore task for mod_fastpix. Wires the structure step
 * from stepslib + declares which URLs the restore decoder must rewrite
 * back from `$@FASTPIXVIEWBYID*N@$` placeholders to live URLs.
 */
class restore_fastpix_activity_task extends restore_activity_task {

    protected function define_my_settings() {
    }

    protected function define_my_steps() {
        $this->add_step(new restore_fastpix_activity_structure_step(
            'fastpix_structure',
            'fastpix.xml'
        ));
    }

    public static function define_decode_contents() {
        return [
            new restore_decode_content('fastpix', ['intro'], 'fastpix'),
        ];
    }

    public static function define_decode_rules() {
        return [
            new restore_decode_rule('FASTPIXVIEWBYID', '/mod/fastpix/view.php?id=$1', 'course_module'),
            new restore_decode_rule('FASTPIXINDEX',    '/mod/fastpix/index.php?id=$1', 'course'),
        ];
    }

    public static function define_restore_log_rules() {
        return [
            new restore_log_rule('fastpix', 'add',  'view.php?id={course_module}', '{fastpix}'),
            new restore_log_rule('fastpix', 'view', 'view.php?id={course_module}', '{fastpix}'),
        ];
    }

    public static function define_restore_log_rules_for_course() {
        return [
            new restore_log_rule('fastpix', 'view all', 'index.php?id={course}', null),
        ];
    }
}
