<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'mod_fastpix';
$plugin->version      = 2026052100;
$plugin->requires     = 2024100100;
$plugin->maturity     = MATURITY_STABLE;
$plugin->release      = '1.0.3';
$plugin->dependencies = [
    // local_fastpix v1.0.0 production release (>= 2026052100) consolidates
    // the verified-against-FastPix DRM playback chain: both playbacktoken
    // and drmtoken use aud="drm:<playback_id>" for DRM assets, matching
    // FastPix's reference player. End-to-end manifest fetch verified at
    // 200 for public / private / drm policies.
    'local_fastpix' => 2026052100,
];
