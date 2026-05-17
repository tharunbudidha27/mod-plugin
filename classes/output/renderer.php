<?php
namespace mod_fastpix\output;

use mod_fastpix\dto\view_state_error;
use mod_fastpix\dto\view_state_player;
use mod_fastpix\dto\view_state_processing;

defined('MOODLE_INTERNAL') || die();

/**
 * Dispatches a view_state DTO to the matching mustache template.
 */
class renderer extends \plugin_renderer_base {

    public function render_state(object $state): string {
        if ($state instanceof view_state_player) {
            return $this->render_player($state);
        }
        if ($state instanceof view_state_processing) {
            return $this->render_processing($state);
        }
        if ($state instanceof view_state_error) {
            return $this->render_error($state);
        }
        throw new \coding_exception('Unknown view state: ' . get_class($state));
    }

    private function render_player(view_state_player $s): string {
        return $this->render_from_template('mod_fastpix/view', [
            'playback_id'              => $s->playback_id,
            'playback_token'           => $s->playback_token,
            'expires_at_ts'            => $s->expires_at_ts,
            'drm_required'             => $s->drm_required,
            'accent_color'             => $s->accent_color,
            'default_show_captions'    => $s->default_show_captions,
            'activity_name'            => $s->activity_name,
            'activity_id'              => $s->activity_id,
            'cm_id'                    => $s->cm_id,
            'asset_id'                 => $s->asset_id,
            'session_token'            => $s->session_token,
            'no_skip_required'         => $s->no_skip_required,
            // Phase D Slice A Step 1 — progress strip + resume + tracker prereqs.
            'initial_coverage_percent' => $s->initial_coverage_percent,
            'completion_watch_percent' => $s->completion_watch_percent,
            'current_position'         => $s->current_position,
            'asset_duration_seconds'   => $s->asset_duration_seconds,
            // Phase D Slice A Step 2 — tracker JS hydration.
            'initial_intervals_json'   => $s->initial_intervals_json,
            'has_completed'            => $s->has_completed,
        ]);
    }

    private function render_processing(view_state_processing $s): string {
        return $this->render_from_template('mod_fastpix/processing', [
            'activity_id'       => $s->activity_id,
            'cm_id'             => $s->cm_id,
            'upload_session_id' => $s->upload_session_id,
            'activity_name'     => $s->activity_name,
        ]);
    }

    private function render_error(view_state_error $s): string {
        return $this->render_from_template('mod_fastpix/error', [
            'reason_key'    => $s->reason_key,
            'activity_name' => $s->activity_name,
            'is_videounavailable' => $s->reason_key === 'videounavailable',
            'is_drm_unsupported'  => $s->reason_key === 'drm_unsupported',
            'is_capability_lost'  => $s->reason_key === 'capability_lost',
        ]);
    }
}
