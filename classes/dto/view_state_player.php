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
    ) {}
}
