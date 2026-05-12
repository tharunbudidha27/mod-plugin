<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Bootstrap the HMAC session_secret on plugin install. Bare-name callback
 * per Moodle activity-module convention (rule M1).
 */
function xmldb_fastpix_install() {
    if (empty(get_config('mod_fastpix', 'session_secret'))) {
        set_config('session_secret', bin2hex(random_bytes(32)), 'mod_fastpix');
    }
}
