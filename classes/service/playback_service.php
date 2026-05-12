<?php
namespace mod_fastpix\service;

use local_fastpix\service\asset_service;
use local_fastpix\service\playback_service as lf_playback_service;
use local_fastpix\exception\asset_not_found;
use local_fastpix\exception\asset_not_ready;
use mod_fastpix\dto\view_state_error;
use mod_fastpix\dto\view_state_player;
use mod_fastpix\dto\view_state_processing;

defined('MOODLE_INTERNAL') || die();

/**
 * mod_fastpix's wrapper around local_fastpix's playback + asset services.
 * Owns the activity-level reconciliation: upload_session_id → asset row,
 * with idempotent backfill of fastpix_asset_id, and the get-or-create
 * attempt logic that anchors session_token storage (D1).
 */
class playback_service {

    /**
     * ESM build of the FastPix Web Player. Loaded via native `import()` from
     * view.php — bypasses Moodle's RequireJS entirely. The module side-effects
     * `customElements.define('fastpix-player', ...)` on first import; subsequent
     * imports are no-ops thanks to the `customElements.get(...) ||` guard at
     * the bottom of the player IIFE.
     *
     * Why ESM, not the IIFE bundle: the player's own hls.js auto-loader uses
     * a plain `<script>` append, which under Moodle's RequireJS triggers the
     * UMD `define.amd` branch and never sets `window.Hls`. Pre-loading hls.js
     * as ESM (HLS_LIB_URL below) and stashing it on `window.Hls` short-circuits
     * the player's loader. ESM runs outside the AMD context.
     */
    const PLAYER_LIB_URL = 'https://cdn.jsdelivr.net/npm/@fastpix/fp-player@1.0.17/dist/player.esm.js';

    /**
     * HLS.js as ESM. jsdelivr's `+esm` adapter wraps the UMD package as a
     * native ES module; the default export is the `Hls` class. Native
     * `import()` bypasses RequireJS, so the UMD-vs-AMD conflict that plagues
     * the player's built-in hls auto-loader cannot fire here.
     */
    const HLS_LIB_URL = 'https://cdn.jsdelivr.net/npm/hls.js@1.6.16/+esm';

    /** @var self|null */
    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Does this activity have any REAL watch attempts (watched_seconds > 0)?
     * "Real" excludes teacher previews which leave behind zero-second rows
     * (see get_or_create_attempt — teachers now get an in-memory stub, but
     * legacy rows from before that fix may still exist).
     *
     * Centralised here so mod_form::validation can call it without doing
     * its own $DB read (A6 — services own business logic).
     */
    public function has_attempts_for(int $activity_id): bool {
        global $DB;
        return $DB->record_exists_select(
            'fastpix_attempt',
            'activity_id = :aid AND watched_seconds > 0',
            ['aid' => $activity_id]
        );
    }

    /**
     * Reduce the activity row to one of three view-state DTOs. Caller has
     * already performed require_login + require_capability (rule S3).
     *
     * Returns view_state_player when the asset is ready and a JWT minted;
     * view_state_processing when the asset is in flight; view_state_error
     * with reason 'videounavailable' otherwise (ADR-010).
     */
    public function resolve_for_view(\stdClass $activity, int $userid, \cm_info $cm): object {
        global $DB;

        $asset = null;

        if (!empty($activity->fastpix_asset_id)) {
            $asset = asset_service::get_by_id((int)$activity->fastpix_asset_id);
        } else if (!empty($activity->upload_session_id)) {
            $asset = asset_service::get_by_upload_session_id((int)$activity->upload_session_id);
            if ($asset !== null) {
                // Idempotent backfill: only update when the column is still NULL.
                $DB->set_field(
                    'fastpix',
                    'fastpix_asset_id',
                    $asset->id,
                    ['id' => $activity->id, 'fastpix_asset_id' => null]
                );
                $activity->fastpix_asset_id = $asset->id;
            }
        }

        if ($asset === null) {
            // No fastpix_asset_id and no resolvable upload_session → still processing.
            // No upload_session at all → asset truly unavailable.
            if (!empty($activity->upload_session_id)) {
                return new view_state_processing(
                    activity_id:       (int)$activity->id,
                    cm_id:             (int)$cm->id,
                    upload_session_id: (int)$activity->upload_session_id,
                    activity_name:     (string)$activity->name,
                );
            }
            return new view_state_error('videounavailable', (string)$activity->name);
        }

        if (!empty($asset->deleted_at)) {
            return new view_state_error('videounavailable', (string)$activity->name);
        }

        if ($asset->status !== 'ready') {
            return new view_state_processing(
                activity_id:       (int)$activity->id,
                cm_id:             (int)$cm->id,
                upload_session_id: !empty($activity->upload_session_id) ? (int)$activity->upload_session_id : null,
                activity_name:     (string)$activity->name,
            );
        }

        $attempt = $this->get_or_create_attempt($activity, $userid, $asset);

        try {
            $payload = lf_playback_service::resolve((string)$asset->fastpix_id, $userid);
        } catch (asset_not_found $e) {
            return new view_state_error('videounavailable', (string)$activity->name);
        } catch (asset_not_ready $e) {
            return new view_state_processing(
                activity_id:       (int)$activity->id,
                cm_id:             (int)$cm->id,
                upload_session_id: !empty($activity->upload_session_id) ? (int)$activity->upload_session_id : null,
                activity_name:     (string)$activity->name,
            );
        }

        return new view_state_player(
            playback_id:           $payload->playback_id,
            playback_token:        $payload->playback_token,
            expires_at_ts:         $payload->expires_at_ts,
            drm_required:          $payload->drm_required,
            accent_color:          $payload->accent_color,
            default_show_captions: $payload->default_show_captions,
            activity_name:         (string)$activity->name,
            activity_id:           (int)$activity->id,
            cm_id:                 (int)$cm->id,
            asset_id:              (int)$asset->id,
            session_token:         (string)$attempt->session_token,
            no_skip_required:      !empty($asset->no_skip_required),
        );
    }

    /**
     * Look up the (userid, activity_id) attempt row. If the existing row's
     * session is within TTL, reuse it. Otherwise mint a new session_token
     * and reset session_start_ts. Phase D will mutate watched_seconds and
     * fraud_count on this same row.
     */
    public function get_or_create_attempt(\stdClass $activity, int $userid, \stdClass $asset): \stdClass {
        global $DB;

        $tokens = session_token_service::instance();
        $now = time();

        // Teacher previews are NOT tracked. Without this guard, every time a
        // teacher/admin opens their own activity, a fastpix_attempt row gets
        // created and the asset-swap guard in mod_form::validation (D5) then
        // refuses to let them swap the video. Stub attempt (id=0) lets
        // view.php still render the player; no DB row is written.
        //
        // Phase D contract: record_view_progress MUST treat attempt.id=0 as
        // "preview mode" and short-circuit with a soft-success (no row write,
        // no fraud check). The AMD watch_tracker should no-op when its
        // session_token matches a stub.
        $cm = get_coursemodule_from_instance('fastpix', (int)$activity->id, 0, false, MUST_EXIST);
        $context = \context_module::instance((int)$cm->id);
        if (has_capability('mod/fastpix:addinstance', $context, $userid, false)) {
            return (object)[
                'id'                => 0,
                'userid'            => $userid,
                'activity_id'       => (int)$activity->id,
                'asset_id'          => (int)$asset->id,
                'session_start_ts'  => $now,
                'session_token'     => $tokens->issue($userid, (int)$activity->id, $now),
                'last_callback_ts'  => null,
                'watched_seconds'   => 0,
                'seek_count'        => 0,
                'fraud_count'       => 0,
                'last_fraud_reason' => null,
                'completion_state'  => 'in_progress',
            ];
        }

        $row = $DB->get_record(
            'fastpix_attempt',
            ['userid' => $userid, 'activity_id' => (int)$activity->id]
        );

        if ($row && $tokens->is_within_ttl((int)$row->session_start_ts)) {
            return $row;
        }

        if ($row) {
            $row->asset_id = (int)$asset->id;
            $row->session_start_ts = $now;
            $row->session_token = $tokens->issue($userid, (int)$activity->id, $now);
            $row->last_callback_ts = null;
            $DB->update_record('fastpix_attempt', $row);
            return $row;
        }

        $new = (object)[
            'userid'           => $userid,
            'activity_id'      => (int)$activity->id,
            'asset_id'         => (int)$asset->id,
            'session_start_ts' => $now,
            'session_token'    => $tokens->issue($userid, (int)$activity->id, $now),
            'watched_seconds'  => 0,
            'seek_count'       => 0,
            'fraud_count'      => 0,
            'completion_state' => 'in_progress',
        ];
        $new->id = $DB->insert_record('fastpix_attempt', $new);
        return $new;
    }
}
