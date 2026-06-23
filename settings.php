<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Settings for local_sentinel.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Overview — live admin digest of this Moodle's health. Registered under
    // Site administration → Reports, where admins look for site status (any
    // plugin may add pages to the reports branch; no report_* plugin needed).
    $ADMIN->add('reports', new admin_externalpage(
        'local_sentinel_overview',
        get_string('pluginname', 'local_sentinel'),
        new moodle_url('/local/sentinel/overview.php')
    ));

    // Parent category so the config sub-pages nest under one "Sentinel"
    // heading (same pattern as Logstore xAPI / other plugin categories
    // under Local plugins).
    $ADMIN->add('localplugins', new admin_category(
        'local_sentinel_category',
        get_string('pluginname', 'local_sentinel')
    ));

    // Sub-page 1: Settings — alert recipients + connection-status summary.
    $ADMIN->add('local_sentinel_category', new admin_externalpage(
        'local_sentinel_alerts',
        get_string('alerts_label', 'local_sentinel'),
        new moodle_url('/local/sentinel/alerts.php')
    ));

    // Sub-page 2: Connect to dashboard — the single place to connect this site
    // (managed Service code-paste, or self-hosted manual mint-token / push
    // config). The old hidden setup + settings pages were folded into it; their
    // config keys are now managed by custom forms on connect.php / alerts.php
    // and read with sane fallbacks via get_config (no admin_setting defaults
    // needed). Core file integrity (integrityenabled) lives on the Settings
    // page (alerts.php) as a data/feature preference.
    $ADMIN->add('local_sentinel_category', new admin_externalpage(
        'local_sentinel_connect',
        get_string('connect_label', 'local_sentinel'),
        new moodle_url('/local/sentinel/connect.php')
    ));
}
