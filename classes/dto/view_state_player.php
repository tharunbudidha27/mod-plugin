<?php
namespace mod_fastpix\dto;

defined('MOODLE_INTERNAL') || die();

/**
 * Player render state. Carries everything the view template + AMD modules
 * need to mount the player and the watch tracker (Phase D consumes the
 * data-* attributes set from these fields).
 */
class view_state_player {

    public function __construct(
        public readonly string  $playback_id,
        public readonly string  $playback_token,
        public readonly int     $expires_at_ts,
        public readonly bool    $drm_required,
        public readonly ?string $accent_color,
        public readonly bool    $default_show_captions,
        public readonly string  $activity_name,
        public readonly int     $activity_id,
        public readonly int     $cm_id,
        public readonly int     $asset_id,
        public readonly string  $session_token,
        public readonly bool    $no_skip_required,
        // Phase D Slice A Step 1 — coverage-based watch tracking.
        // initial_coverage_percent is computed server-side from
        // watched_intervals + asset.duration so the progress strip
        // renders with the correct fill on first paint (no FOUC).
        public readonly int     $initial_coverage_percent,
        public readonly int     $completion_watch_percent,
        public readonly float   $current_position,
        public readonly int     $asset_duration_seconds,
        // Phase D Slice A Step 2 — tracker JS hydration.
        // initial_intervals_json is the raw JSON literal from
        // fastpix_attempt.watched_intervals; the mustache template emits
        // it via {{{ ... }}} (no escaping) because it is server-generated.
        public readonly string  $initial_intervals_json,
        public readonly bool    $has_completed,
    ) {}
}
