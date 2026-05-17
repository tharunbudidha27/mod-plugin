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

    if ($oldversion < 2026051300) {
        // Phase D Slice A Step 1 — switch fastpix_attempt from scalar
        // watched_seconds to interval-set + resume position + sticky
        // completion flag.
        $table = new xmldb_table('fastpix_attempt');

        $field_old = new xmldb_field('watched_seconds');
        if ($dbman->field_exists($table, $field_old)) {
            $dbman->drop_field($table, $field_old);
        }

        // watched_intervals — Moodle DDL forbids defaults on TEXT columns, so
        // adding it as NOT NULL to a non-empty table fails. Standard 3-step:
        // add nullable, backfill, promote to NOT NULL.
        $f_intervals_nullable = new xmldb_field('watched_intervals', XMLDB_TYPE_TEXT, null, null, null, null, null, 'seek_count');
        if (!$dbman->field_exists($table, $f_intervals_nullable)) {
            $dbman->add_field($table, $f_intervals_nullable);
            $DB->execute("UPDATE {fastpix_attempt} SET watched_intervals = '' WHERE watched_intervals IS NULL");
            $f_intervals_notnull = new xmldb_field('watched_intervals', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null, 'seek_count');
            $dbman->change_field_notnull($table, $f_intervals_notnull);
        }

        // Numeric columns: DEFAULT in-schema is supported, no 3-step needed.
        $numeric_fields = [
            new xmldb_field('current_position', XMLDB_TYPE_NUMBER,  '10,3', null, XMLDB_NOTNULL, null, '0', 'watched_intervals'),
            new xmldb_field('has_completed',    XMLDB_TYPE_INTEGER, '1',    null, XMLDB_NOTNULL, null, '0', 'current_position'),
        ];
        foreach ($numeric_fields as $f) {
            if (!$dbman->field_exists($table, $f)) {
                $dbman->add_field($table, $f);
            }
        }

        upgrade_mod_savepoint(true, 2026051300, 'fastpix');
    }

    if ($oldversion < 2026051301) {
        // Phase D Slice A Step 3 — registers the mod_fastpix_record_view_progress
        // web service. Service registration is picked up from db/services.php by
        // Moodle's upgrade machinery; no schema change required here.
        upgrade_mod_savepoint(true, 2026051301, 'fastpix');
    }

    if ($oldversion < 2026051302) {
        // Phase D Slice A Step 4 — custom_completion class + grade_item_update /
        // update_grades callback bodies. No schema change; the savepoint is
        // here so Moodle picks up cm_info / customdata cache refreshes that
        // the new fastpix_get_coursemodule_info() callback now populates.
        upgrade_mod_savepoint(true, 2026051302, 'fastpix');
    }

    return true;
}
