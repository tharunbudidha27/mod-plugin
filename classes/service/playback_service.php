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
     * Does this activity have any REAL watch attempts?
     * "Real" excludes teacher previews which leave behind empty-interval rows
     * (see get_or_create_attempt — teachers now get an in-memory stub, but
     * legacy rows from before that fix may still exist).
     *
     * Phase D Slice A: post-schema-migration this checks watched_intervals
     * (was watched_seconds > 0 pre-migration). Preview rows initialise as
     * the empty-string default; any real progress callback writes a JSON
     * array, so the "<> ''" predicate is equivalent.
     *
     * Centralised here so mod_form::validation can call it without doing
     * its own $DB read (A6 — services own business logic).
     */
    public function has_attempts_for(int $activity_id): bool {
        global $DB;
        return $DB->record_exists_select(
            'fastpix_attempt',
            "activity_id = :aid AND watched_intervals <> ''",
            ['aid' => $activity_id]
        );
    }

    /**
     * Compute coverage percent from a serialised watched-intervals JSON blob.
     * Extracted from resolve_for_view() so PHPUnit can exercise the math
     * without standing up the full local_fastpix resolve path.
     *
     * @param string|null $intervals_json e.g. '[[0,30],[40,50]]' or '' or null
     * @param int $duration_seconds Asset duration; <= 0 collapses to 0%.
     */
    public static function compute_initial_coverage_percent(?string $intervals_json, int $duration_seconds): int {
        if ($duration_seconds <= 0) {
            return 0;
        }
        $intervals = json_decode($intervals_json ?: '[]', true) ?: [];
        $watched = 0.0;
        foreach ($intervals as $interval) {
            if (is_array($interval) && isset($interval[0], $interval[1])) {
                $watched += max(0.0, (float)$interval[1] - (float)$interval[0]);
            }
        }
        return (int) min(100, round(($watched / $duration_seconds) * 100));
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

        // Defensive: an asset row can exist with status='ready' but
        // playback_id still null if the media.ready webhook split (asset
        // created event arrived but ready event was lost / delayed).
        // local_fastpix's resolve does not always throw asset_not_ready in
        // that case; treat empty playback_id as still-processing.
        if (empty($payload->playback_id)) {
            return new view_state_processing(
                activity_id:       (int)$activity->id,
                cm_id:             (int)$cm->id,
                upload_session_id: !empty($activity->upload_session_id) ? (int)$activity->upload_session_id : null,
                activity_name:     (string)$activity->name,
            );
        }

        // Phase D Slice A: compute the visible progress strip's first-paint
        // fill server-side so the bar shows correct % before tracker JS runs.
        $duration = (int)($asset->duration ?? 0);
        $initial_coverage = self::compute_initial_coverage_percent(
            $attempt->watched_intervals ?? '',
            $duration
        );

        return new view_state_player(
            playback_id:               $payload->playback_id,
            playback_token:            $payload->playback_token,
            expires_at_ts:             $payload->expires_at_ts,
            drm_required:              $payload->drm_required,
            accent_color:              $payload->accent_color,
            // Teacher's per-activity checkbox (mdl_fastpix.default_show_captions)
            // is the source of truth — it overrides the tenant default coming
            // back on the playback_payload. Falsy activity column → fall back
            // to the tenant-level default so global "always on" still works.
            default_show_captions:     !empty($activity->default_show_captions)
                                          ? true
                                          : (bool) $payload->default_show_captions,
            activity_name:             (string)$activity->name,
            activity_id:               (int)$activity->id,
            cm_id:                     (int)$cm->id,
            asset_id:                  (int)$asset->id,
            session_token:             (string)$attempt->session_token,
            no_skip_required:          !empty($asset->no_skip_required),
            initial_coverage_percent:  $initial_coverage,
            completion_watch_percent:  (int)($activity->completion_watch_percent ?? 90),
            current_position:          (float)($attempt->current_position ?? 0.0),
            asset_duration_seconds:    $duration,
            initial_intervals_json:    !empty($attempt->watched_intervals) ? (string)$attempt->watched_intervals : '[]',
            has_completed:             !empty($attempt->has_completed),
        );
    }

    /**
     * Look up the (userid, activity_id) attempt row. If the existing row's
     * session is within TTL, reuse it. Otherwise mint a new session_token
     * and reset session_start_ts. Phase D mutates watched_intervals,
     * current_position, has_completed, seek_count, and fraud_count on this
     * same row; session reset preserves progress (only session_* is rotated).
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
                'watched_intervals' => '',
                'current_position'  => 0.0,
                'has_completed'     => 0,
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
            'userid'            => $userid,
            'activity_id'       => (int)$activity->id,
            'asset_id'          => (int)$asset->id,
            'session_start_ts'  => $now,
            'session_token'     => $tokens->issue($userid, (int)$activity->id, $now),
            'watched_intervals' => '',
            'current_position'  => 0,
            'has_completed'     => 0,
            'seek_count'        => 0,
            'fraud_count'       => 0,
            'completion_state'  => 'in_progress',
        ];
        $new->id = $DB->insert_record('fastpix_attempt', $new);
        return $new;
    }
}
