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
 * Language strings for local_sentinel.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Sentinel';
$string['privacy:metadata'] = 'The Sentinel plugin does not store any personal data. '
    . 'It exposes site-level operational metrics through web services and an optional outbound push.';
$string['pushenabled'] = 'Enable push';
$string['pushenabled_desc'] = 'When enabled, the push_snapshot scheduled task POSTs a full snapshot '
    . 'to the configured endpoint on each run.';
$string['pushendpoint'] = 'Push endpoint URL';
$string['pushendpoint_desc'] = 'Full URL of the central collector to POST snapshots to. '
    . 'Used by the push_snapshot scheduled task.';
$string['pushsecret'] = 'Push shared secret';
$string['pushsecret_desc'] = 'Sent as the X-Sentinel-Secret header on push requests. '
    . 'The central collector must verify this value.';
$string['sentinel:view'] = 'View Sentinel snapshot data';
$string['servicemissing'] = 'The Sentinel external service was not found. '
    . 'Visit Site administration → Notifications to finish plugin installation, then retry.';
$string['servicename'] = 'Sentinel';
$string['settingsheading_push'] = 'Outbound push configuration';
$string['settingsheading_push_desc'] = 'Configure the plugin to POST snapshots to a central collector. '
    . 'Use this for new-client evaluation or for instances behind firewalls that cannot be polled inbound. '
    . 'Pull access through Moodle web services works independently of these settings.';
$string['setup_back'] = '← Back to setup';
$string['setup_copy'] = 'Copy token';
$string['setup_dashboard_help'] = 'On the Sentinel Dashboard, register this site:';
$string['setup_dashboard_label'] = 'Connect this site to the dashboard';
$string['setup_dashboard_step1'] = 'Browse to the dashboard\'s Instances → Add instance form.';
$string['setup_dashboard_step2'] = 'Paste the token above into the "WS token" field, '
    . 'put this site\'s wwwroot in the "wwwroot" field, and leave siteidentifier blank.';
$string['setup_dashboard_step3'] = 'Click "Test connection" to confirm and "Save" to finish.';
$string['setup_endpoint_label'] = 'Web service endpoint';
$string['setup_existing_notice'] = 'A token already exists for this site. '
    . 'Re-running the setup is safe and will reuse the existing token unless you tick the regenerate option below.';
$string['setup_heading'] = 'Sentinel: connect to dashboard';
$string['setup_identity_heading'] = 'Service user identity';
$string['setup_intro'] = 'This page bootstraps the web service access the Sentinel Dashboard needs to read snapshots '
    . 'from this Moodle. It is the GUI equivalent of running cli/setup.php — defaults match the CLI script.';
$string['setup_log_label'] = 'Setup steps';
$string['setup_regen_heading'] = 'Token regeneration';
$string['setup_regenerate'] = 'Generate a fresh token';
$string['setup_regenerate_desc'] = 'Delete the existing permanent token and create a new one. '
    . 'The dashboard registration for this site will need to be updated with the new value.';
$string['setup_rolename'] = 'Role display name';
$string['setup_roleshortname'] = 'Role shortname';
$string['setup_run'] = 'Run setup';
$string['setup_success'] = 'Setup complete. The token below is the value the dashboard needs.';
$string['setup_token_label'] = 'Permanent token';
$string['setup_username'] = 'Webservice username';
$string['setup_username_help'] = 'The Moodle user that will own the permanent web service token. '
    . 'A new user is created if one with this username does not already exist. '
    . 'Defaults to "sentinel".';
$string['task_push_snapshot'] = 'Push Sentinel snapshot to central collector';
$string['task_refresh_updates'] = 'Refresh available-updates cache (no admin email)';
