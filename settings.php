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
 * Settings for local_fleetmonitor.
 *
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_fleetmonitor', get_string('pluginname', 'local_fleetmonitor'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading(
        'local_fleetmonitor/settingsheading_push',
        get_string('settingsheading_push', 'local_fleetmonitor'),
        get_string('settingsheading_push_desc', 'local_fleetmonitor')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_fleetmonitor/pushenabled',
        get_string('pushenabled', 'local_fleetmonitor'),
        get_string('pushenabled_desc', 'local_fleetmonitor'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_fleetmonitor/pushendpoint',
        get_string('pushendpoint', 'local_fleetmonitor'),
        get_string('pushendpoint_desc', 'local_fleetmonitor'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_fleetmonitor/pushsecret',
        get_string('pushsecret', 'local_fleetmonitor'),
        get_string('pushsecret_desc', 'local_fleetmonitor'),
        ''
    ));
}
