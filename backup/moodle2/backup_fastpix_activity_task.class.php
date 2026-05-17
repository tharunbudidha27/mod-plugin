<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/fastpix/backup/moodle2/backup_fastpix_stepslib.php');

/**
 * Activity-level backup task for mod_fastpix. Wires the structure step
 * defined in stepslib + handles URL encoding for intro/content.
 */
class backup_fastpix_activity_task extends backup_activity_task {

    protected function define_my_settings() {
    }

    protected function define_my_steps() {
        $this->add_step(new backup_fastpix_activity_structure_step(
            'fastpix_structure',
            'fastpix.xml'
        ));
    }

    /**
     * Encode any course-module-internal URLs inside the activity intro so
     * `restore_decode_processor` can rewrite them on restore. Pattern matches
     * mod_url / mod_page; we only own /mod/fastpix/view.php URLs.
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        $content = preg_replace(
            '/(' . $base . '\/mod\/fastpix\/index\.php\?id\=)([0-9]+)/',
            '$@FASTPIXINDEX*$2@$',
            $content
        );
        $content = preg_replace(
            '/(' . $base . '\/mod\/fastpix\/view\.php\?id\=)([0-9]+)/',
            '$@FASTPIXVIEWBYID*$2@$',
            $content
        );

        return $content;
    }
}
