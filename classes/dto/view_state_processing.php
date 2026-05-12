<?php
namespace mod_fastpix\dto;

defined('MOODLE_INTERNAL') || die();

/**
 * Asset is not yet ready (uploading or transcoding). The processing template
 * polls local_fastpix_get_upload_status; on status='ready' the page reloads
 * and resolve_for_view returns view_state_player.
 */
class view_state_processing {

    public function __construct(
        public readonly int    $activity_id,
        public readonly int    $cm_id,
        public readonly ?int   $upload_session_id,
        public readonly string $activity_name,
    ) {}
}
