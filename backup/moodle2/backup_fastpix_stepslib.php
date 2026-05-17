<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Backup steps for mod_fastpix (rule M9).
 *
 * Captures the activity row from mdl_fastpix plus per-user attempt rows
 * from mdl_fastpix_attempt when "include user data" is selected.
 *
 * Does NOT capture asset bytes — FastPix owns them. The activity carries
 * `fastpix_asset_id` (FK to local_fastpix's asset table); on restore the
 * importer either reuses the row (same FastPix account) or shows
 * "Video unavailable" per ADR-010 (different account, no asset on the
 * target FastPix tenant). Session tokens are NOT serialised: each restore
 * mints fresh tokens on first view.
 */
class backup_fastpix_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {

        $userinfo = $this->get_setting_value('userinfo');

        $fastpix = new backup_nested_element('fastpix', ['id'], [
            'name',
            'intro',
            'introformat',
            'fastpix_asset_id',
            'upload_session_id',
            'completion_watch_percent',
            'no_skip_required',
            'default_show_captions',
            'grademax',
            'timecreated',
            'timemodified',
        ]);

        $attempts = new backup_nested_element('attempts');
        // session_token is intentionally omitted — fresh tokens are minted on
        // first view of the restored activity. Persisting them would leak
        // auth material into the backup file (S6).
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid',
            'asset_id',
            'session_start_ts',
            'last_callback_ts',
            'seek_count',
            'watched_intervals',
            'current_position',
            'has_completed',
            'fraud_count',
            'last_fraud_reason',
            'completion_state',
            'milestone_25_at',
            'milestone_50_at',
            'milestone_75_at',
            'milestone_100_at',
        ]);

        $fastpix->add_child($attempts);
        $attempts->add_child($attempt);

        $fastpix->set_source_table('fastpix', ['id' => backup::VAR_ACTIVITYID]);
        if ($userinfo) {
            $attempt->set_source_table('fastpix_attempt', ['activity_id' => backup::VAR_PARENTID]);
        }

        $attempt->annotate_ids('user', 'userid');

        $fastpix->annotate_files('mod_fastpix', 'intro', null);

        return $this->prepare_activity_structure($fastpix);
    }
}
