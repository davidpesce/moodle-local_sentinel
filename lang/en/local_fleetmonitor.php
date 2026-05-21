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
 * Language strings for local_fleetmonitor.
 *
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['fleetmonitor:view'] = 'View Fleet Monitor snapshot data';
$string['pluginname'] = 'Fleet Monitor';
$string['privacy:metadata'] = 'The Fleet Monitor plugin does not store any personal data. '
    . 'It exposes site-level operational metrics through web services and an optional outbound push.';
$string['pushenabled'] = 'Enable push';
$string['pushenabled_desc'] = 'When enabled, the push_snapshot scheduled task POSTs a full snapshot '
    . 'to the configured endpoint on each run.';
$string['pushendpoint'] = 'Push endpoint URL';
$string['pushendpoint_desc'] = 'Full URL of the central collector to POST snapshots to. '
    . 'Used by the push_snapshot scheduled task.';
$string['pushsecret'] = 'Push shared secret';
$string['pushsecret_desc'] = 'Sent as the X-Fleetmonitor-Secret header on push requests. '
    . 'The central collector must verify this value.';
$string['servicename'] = 'Fleet Monitor';
$string['settingsheading_push'] = 'Outbound push configuration';
$string['settingsheading_push_desc'] = 'Configure the plugin to POST snapshots to a central collector. '
    . 'Use this for new-client evaluation or for instances behind firewalls that cannot be polled inbound. '
    . 'Pull access through Moodle web services works independently of these settings.';
$string['task_push_snapshot'] = 'Push fleet monitor snapshot to central collector';
$string['task_refresh_updates'] = 'Refresh available-updates cache (no admin email)';
