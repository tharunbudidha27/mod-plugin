<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'mod_fastpix';
$plugin->version      = 2026050802;
$plugin->requires     = 2024100700;
$plugin->maturity     = MATURITY_ALPHA;
$plugin->release      = '0.1.0-dev';
$plugin->dependencies = [
    'local_fastpix' => 2026050801,
];
