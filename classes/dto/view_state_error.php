<?php
namespace mod_fastpix\dto;

defined('MOODLE_INTERNAL') || die();

/**
 * User-facing error state. Reason key is one of:
 *   - videounavailable     (asset missing or soft-deleted; ADR-010)
 *   - drm_unsupported      (drm_required but client cannot play DRM)
 *   - capability_lost      (capability revoked mid-session)
 *
 * Only the reason key crosses the template boundary — never asset IDs,
 * statuses, or other internals (rule S9).
 */
class view_state_error {

    public function __construct(
        public readonly string $reason_key,
        public readonly string $activity_name,
    ) {}
}
