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

    // Sub-page 2: Connect to dashboard — single entry point for the
    // connection-explanation cards. Links into the two hidden config
    // routes below.
    $ADMIN->add('local_sentinel_category', new admin_externalpage(
        'local_sentinel_connect',
        get_string('connect_label', 'local_sentinel'),
        new moodle_url('/local/sentinel/connect.php')
    ));

    // Hidden in the nav, but still routable URLs that the Connect page
    // links into. Constructor's 4th-arg ($req_capability) keeps the
    // default 'moodle/site:config'; 5th-arg ($hidden) hides from the
    // tree. admin_settingpage takes ($name, $visiblename, $req_capability,
    // $hidden); admin_externalpage takes ($name, $visiblename, $url,
    // $req_capability, $hidden, $context).
    $settings = new admin_settingpage(
        'local_sentinel',
        get_string('settings_label', 'local_sentinel'),
        'moodle/site:config',
        true
    );
    $ADMIN->add('local_sentinel_category', $settings);

    $ADMIN->add('local_sentinel_category', new admin_externalpage(
        'local_sentinel_setup',
        get_string('setup_label', 'local_sentinel'),
        new moodle_url('/local/sentinel/setup.php'),
        'moodle/site:config',
        true
    ));

    $settings->add(new admin_setting_heading(
        'local_sentinel/settingsheading_push',
        get_string('settingsheading_push', 'local_sentinel'),
        get_string('settingsheading_push_desc', 'local_sentinel')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_sentinel/pushenabled',
        get_string('pushenabled', 'local_sentinel'),
        get_string('pushenabled_desc', 'local_sentinel'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_sentinel/pushendpoint',
        get_string('pushendpoint', 'local_sentinel'),
        get_string('pushendpoint_desc', 'local_sentinel'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_sentinel/pushsecret',
        get_string('pushsecret', 'local_sentinel'),
        get_string('pushsecret_desc', 'local_sentinel'),
        ''
    ));

    $settings->add(new admin_setting_heading(
        'local_sentinel/settingsheading_registration',
        get_string('settingsheading_registration', 'local_sentinel'),
        get_string('settingsheading_registration_desc', 'local_sentinel')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_sentinel/registrationenabled',
        get_string('registrationenabled', 'local_sentinel'),
        get_string('registrationenabled_desc', 'local_sentinel'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_sentinel/dashboardbaseurl',
        get_string('dashboardbaseurl', 'local_sentinel'),
        get_string('dashboardbaseurl_desc', 'local_sentinel'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_sentinel/enrollmentkey',
        get_string('enrollmentkey', 'local_sentinel'),
        get_string('enrollmentkey_desc', 'local_sentinel'),
        ''
    ));
}
