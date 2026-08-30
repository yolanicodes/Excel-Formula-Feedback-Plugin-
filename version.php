<?php
// ============================================================
// version.php
// This file tells Moodle what this plugin is and what version
// of Moodle it needs to run. You rarely need to change this
// except when you update your plugin (bump the version number).
// ============================================================

defined('MOODLE_INTERNAL') || die(); // Security: stops this file being opened directly in a browser
$plugin = new stdClass(); // Ensure $plugin is defined to avoid "Undefined variable" notices

$plugin->component = 'qtype_excelformula';  // Internal name: must match your folder name
$plugin->version = 2026083000;  // Was 2023121100             // Today's date + 2 digits: YYYYMMDDNN
$plugin->requires  = 2022041900;  // Moodle 4.0
$plugin->maturity  = MATURITY_ALPHA;         // ALPHA = still in development. Change to MATURITY_STABLE when ready.
$plugin->release   = '4.0.12 (Build: 20231211)';  // Human-readable version label
