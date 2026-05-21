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
    // Parent category so the two sub-pages nest under one "Sentinel" heading
    // (same pattern as Logstore xAPI / other plugin categories under Local plugins).
    $ADMIN->add('localplugins', new admin_category(
        'local_sentinel_category',
        get_string('pluginname', 'local_sentinel')
    ));

    // Sub-page 1: push / runtime settings.
    $settings = new admin_settingpage('local_sentinel', get_string('settings_label', 'local_sentinel'));
    $ADMIN->add('local_sentinel_category', $settings);

    // Sub-page 2: web-UI replacement for cli/setup.php.
    $ADMIN->add('local_sentinel_category', new admin_externalpage(
        'local_sentinel_setup',
        get_string('setup_label', 'local_sentinel'),
        new moodle_url('/local/sentinel/setup.php')
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
}
