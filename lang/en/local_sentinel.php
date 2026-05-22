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
    . 'It exposes site-level operational metrics through web services and an optional outbound send.';
$string['pushenabled'] = 'Enable sending';
$string['pushenabled_desc'] = 'When enabled, this Moodle sends a full snapshot to the configured '
    . 'dashboard endpoint on a schedule (every 15 minutes by default).';
$string['pushendpoint'] = 'Dashboard ingest URL';
$string['pushendpoint_desc'] = 'Full URL of the dashboard\'s ingest endpoint. '
    . 'Snapshots are POSTed here by the scheduled task.';
$string['pushsecret'] = 'Shared secret';
$string['pushsecret_desc'] = 'Sent as the X-Sentinel-Secret header on each request. '
    . 'The dashboard must verify this value to accept the snapshot.';
$string['sentinel:view'] = 'View Sentinel snapshot data';
$string['servicemissing'] = 'The Sentinel external service was not found. '
    . 'Visit Site administration → Notifications to finish plugin installation, then retry.';
$string['servicename'] = 'Sentinel';
$string['settings_label'] = 'Send data to remote dashboard';
$string['settingsheading_push'] = 'Outbound configuration';
$string['settingsheading_push_desc'] = 'When enabled, this Moodle pushes a full snapshot to the dashboard '
    . 'on a schedule. Use this when the dashboard cannot reach this site\'s URL directly — for example '
    . 'instances behind a firewall, or new-client evaluation before network access is set up. '
    . 'The other mechanism — "Allow remote dashboard to retrieve data" — is independent; either or both '
    . 'can be enabled.';
$string['setup_back'] = '← Back to setup';
$string['setup_copy'] = 'Copy token';
$string['setup_dashboard_help'] = 'On the Sentinel Dashboard, register this site:';
$string['setup_dashboard_label'] = 'Register this site on the dashboard';
$string['setup_dashboard_step1'] = 'Browse to the dashboard\'s Instances → Add instance form.';
$string['setup_dashboard_step2'] = 'Paste the token above into the "WS token" field, '
    . 'put this site\'s wwwroot in the "wwwroot" field, and leave siteidentifier blank.';
$string['setup_dashboard_step3'] = 'Click "Test connection" to confirm and "Save" to finish.';
$string['setup_endpoint_label'] = 'Web service endpoint';
$string['setup_existing_notice'] = 'A token already exists for this site. '
    . 'Re-running the setup is safe and will reuse the existing token unless you tick the regenerate option below.';
$string['setup_heading'] = 'Allow remote dashboard to retrieve data';
$string['setup_identity_heading'] = 'Service user identity';
$string['setup_intro'] = 'Generates a web service token that the central dashboard will use to pull snapshots '
    . 'from this Moodle on a schedule. Use this when the dashboard can reach this site\'s URL inbound. '
    . 'The other mechanism — "Send data to remote dashboard" — is independent; either or both can be used. '
    . 'This page is the GUI equivalent of running cli/setup.php; defaults match the CLI script.';
$string['setup_label'] = 'Allow remote dashboard to retrieve data';
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
$string['task_push_snapshot'] = 'Send Sentinel snapshot to remote dashboard';
$string['task_refresh_updates'] = 'Refresh available-updates cache (no admin email)';
