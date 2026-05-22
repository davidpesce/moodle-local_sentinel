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

$string['overview_configured'] = 'Configured';
$string['overview_heading'] = 'Sentinel: overview';
$string['overview_intro'] = 'Sentinel collects operational metrics about this Moodle '
    . '(release, plugins, scheduled tasks, errors, active users, and more) and makes them available '
    . 'to a central dashboard. Two mechanisms move the data between this site and the dashboard. '
    . 'Choose the one that fits your network setup — or use both.';
$string['overview_label'] = 'Overview';
$string['overview_not_configured'] = 'Not configured';
$string['overview_note_both'] = 'Either or both mechanisms can be enabled. The dashboard de-duplicates '
    . 'incoming snapshots by siteidentifier.';
$string['overview_note_pull'] = 'Retrieval uses standard Moodle web service tokens. View tokens at '
    . 'Site administration → Server → Web services → Manage tokens.';
$string['overview_note_push'] = 'Sending uses a scheduled task that runs every 15 minutes by default. '
    . 'View and adjust at Site administration → Server → Scheduled tasks.';
$string['overview_notes_heading'] = 'Notes';
$string['overview_pull_cta'] = 'Configure retrieval →';
$string['overview_pull_desc'] = 'The dashboard polls this Moodle\'s web service endpoints on a schedule '
    . 'and fetches a snapshot each time. Nothing leaves this site outbound on a timer; the request '
    . 'originates from the dashboard.';
$string['overview_pull_requires'] = 'Requires: nothing from the dashboard. This site generates a token '
    . 'and the dashboard is configured with it.';
$string['overview_pull_title'] = 'Allow remote dashboard to retrieve data';
$string['overview_pull_when'] = 'Use when the dashboard can reach this site\'s URL inbound — the simpler '
    . 'default for most production setups.';
$string['overview_send_cta'] = 'Configure sending →';
$string['overview_send_desc'] = 'This Moodle posts a full snapshot to a configured dashboard URL on a '
    . 'schedule. The request originates from this site outbound; configure an https:// URL in production.';
$string['overview_send_requires'] = 'Requires: dashboard URL + shared secret. Both are issued by '
    . 'whoever runs the dashboard.';
$string['overview_send_title'] = 'Send data to remote dashboard';
$string['overview_send_when'] = 'Use when the dashboard cannot reach this site\'s URL — for example '
    . 'instances behind a firewall, on a private network, or being evaluated before network access '
    . 'has been opened up.';
$string['pluginname'] = 'Sentinel';
$string['privacy:metadata'] = 'The Sentinel plugin does not store any personal data. '
    . 'It exposes site-level operational metrics through web services and an optional outbound send.';
$string['pushenabled'] = 'Enable sending';
$string['pushenabled_desc'] = 'When enabled, this Moodle sends a full snapshot to the configured '
    . 'dashboard endpoint on a schedule (every 15 minutes by default).';
$string['pushendpoint'] = 'Dashboard ingest URL';
$string['pushendpoint_desc'] = 'Full URL of the dashboard\'s ingest endpoint. '
    . 'Snapshots are POSTed here by the scheduled task. '
    . 'Use https:// in production — the shared secret and the full snapshot payload should not '
    . 'travel over plaintext HTTP across an untrusted network.';
$string['pushsecret'] = 'Shared secret';
$string['pushsecret_desc'] = 'Sent as the X-Sentinel-Secret header on each request. '
    . 'The dashboard must verify this value to accept the snapshot.';
$string['sentinel:view'] = 'View Sentinel snapshot data';
$string['servicemissing'] = 'The Sentinel external service was not found. '
    . 'Visit Site administration → Notifications to finish plugin installation, then retry.';
$string['servicename'] = 'Sentinel';
$string['settings_label'] = 'Send data to remote dashboard';
$string['settingsheading_push'] = 'Outbound configuration';
$string['settingsheading_push_desc'] = 'Configures the scheduled task that posts snapshots to the '
    . 'remote dashboard. See the Overview page for when to use this and how it differs from the '
    . 'retrieval mechanism.';
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
$string['setup_intro'] = 'Generates a web service token that the central dashboard uses to pull snapshots '
    . 'from this Moodle. See the Overview page for when to use this. '
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
