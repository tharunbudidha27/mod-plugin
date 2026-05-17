<?php
defined('MOODLE_INTERNAL') || die();

// db/services.php loads at install/upgrade time, before lang files are
// available — descriptions stay as literal English here. For translators:
// see lang/en/fastpix.php (web-service description strings live there if
// future tooling supports lookup).
$functions = [
    'mod_fastpix_refresh_playback_token' => [
        'classname'    => '\mod_fastpix\external\refresh_playback_token',
        'methodname'   => 'execute',
        'description'  => 'Mint a fresh playback JWT before the current one expires.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/fastpix:view',
    ],
    'mod_fastpix_record_view_progress' => [
        'classname'    => '\mod_fastpix\external\record_view_progress',
        'methodname'   => 'execute',
        'description'  => 'Persist client-reported watch progress with server-side fraud checks.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/fastpix:view',
    ],
];

// mod_fastpix does not define its own service group; functions hook into Moodle's mobile + REST services.
$services = [];
