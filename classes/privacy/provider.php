<?php
/**
 * Phase C interim privacy provider.
 *
 * Implements null_provider to satisfy Moodle's privacy audit minimum bar
 * while Phase D / Phase E land the real per-user data declarations for
 * mdl_fastpix_attempt. Once that's wired, this class swaps to the full
 * metadata + request/delete providers (S10).
 *
 * @package mod_fastpix
 */

namespace mod_fastpix\privacy;

use core_privacy\local\metadata\null_provider;

defined('MOODLE_INTERNAL') || die();

class provider implements null_provider {

    /**
     * Justification string key — used by Moodle's privacy audit UI to
     * explain why this plugin is currently a null provider.
     */
    public static function get_reason(): string {
        return 'privacy:metadata:null_phase_e';
    }
}
