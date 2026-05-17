<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component    = 'mod_fastpix';
$plugin->version      = 2026051503;
$plugin->requires     = 2024100100;
$plugin->maturity     = MATURITY_STABLE;
$plugin->release      = '1.0.0';
$plugin->dependencies = [
    'local_fastpix' => 2026051201,
];
