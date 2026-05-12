<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_fastpix_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026050801) {
        // ADR-012: register mod/fastpix:uploadmedia. The capability declaration
        // lives in db/access.php; Moodle's access scanner picks it up on upgrade.
        // No schema change required.
        upgrade_mod_savepoint(true, 2026050801, 'fastpix');
    }

    if ($oldversion < 2026050802) {
        // Phase C: bootstrap session_secret if missing on existing installs.
        // No schema change. db/install.php handles fresh installs.
        if (empty(get_config('mod_fastpix', 'session_secret'))) {
            set_config('session_secret', bin2hex(random_bytes(32)), 'mod_fastpix');
        }
        upgrade_mod_savepoint(true, 2026050802, 'fastpix');
    }

    return true;
}
