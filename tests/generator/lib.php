<?php
/**
 * PHPUnit/Behat data generator for mod_fastpix.
 *
 * Hooks `$this->getDataGenerator()->create_module('fastpix', ...)` into
 * lib.php's fastpix_add_instance so test code can stand up activities
 * without touching install.xml directly.
 *
 * @package mod_fastpix
 * @category test
 */

defined('MOODLE_INTERNAL') || die();

class mod_fastpix_generator extends testing_module_generator {

    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;
        $defaults = [
            'name'                     => 'FastPix Test',
            'intro'                    => '',
            'introformat'              => FORMAT_HTML,
            'fastpix_asset_id'         => null,
            'upload_session_id'        => null,
            'completion_watch_percent' => 90,
            'no_skip_required'         => 0,
            'default_show_captions'    => 0,
            'grademax'                 => 100,
        ];
        foreach ($defaults as $k => $v) {
            if (!isset($record->{$k})) {
                $record->{$k} = $v;
            }
        }
        return parent::create_instance($record, (array)$options);
    }
}
