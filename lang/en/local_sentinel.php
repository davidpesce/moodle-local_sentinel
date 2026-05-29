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

$string['alertemails'] = 'Alert recipients';
$string['alertemails_desc'] = 'Email addresses to notify when this site reports issues. One address per line. '
    . 'Storage only — actual alert delivery is not yet wired up.';
$string['alertemails_invalid'] = 'Not a valid email address: {$a}';
$string['alertemails_save'] = 'Save recipients';
$string['alertemails_saved'] = 'Alert recipients updated.';
$string['alertemails_section'] = 'Alert recipients';
$string['alerts_heading'] = 'Sentinel: Settings';
$string['alerts_label'] = 'Settings';
$string['connect_configured'] = 'Configured';
$string['connect_heading'] = 'Sentinel: Connect to dashboard';
$string['connect_intro'] = 'Sentinel collects operational metrics about this Moodle '
    . '(release, plugins, scheduled tasks, errors, active users, and more) and makes them available '
    . 'to a central dashboard. Two mechanisms move the data between this site and the dashboard. '
    . 'Choose the one that fits your network setup — or use both.';
$string['connect_label'] = 'Connect to dashboard';
$string['connect_not_configured'] = 'Not configured';
$string['connect_note_both'] = 'Either or both mechanisms can be enabled. The dashboard de-duplicates '
    . 'incoming snapshots by siteidentifier.';
$string['connect_note_pull'] = 'Retrieval uses standard Moodle web service tokens. View tokens at '
    . 'Site administration → Server → Web services → Manage tokens.';
$string['connect_note_push'] = 'Sending uses a scheduled task that runs every 15 minutes by default. '
    . 'View and adjust at Site administration → Server → Scheduled tasks.';
$string['connect_notes_heading'] = 'Notes';
$string['connect_pull_cta'] = 'Configure retrieval →';
$string['connect_pull_desc'] = 'The dashboard polls this Moodle\'s web service endpoints on a schedule '
    . 'and fetches a snapshot each time.';
$string['connect_pull_requires'] = 'Requires: nothing from the dashboard. This site generates a token '
    . 'and the dashboard is configured with it.';
$string['connect_pull_title'] = 'Allow remote dashboard to retrieve data';
$string['connect_pull_when'] = 'Use when the dashboard can reach this site\'s URL inbound — the simpler '
    . 'default for most production setups.';
$string['connect_send_cta'] = 'Configure sending →';
$string['connect_send_desc'] = 'This Moodle posts a full snapshot to a configured dashboard URL on a '
    . 'schedule.';
$string['connect_send_requires'] = 'Requires: dashboard URL + shared secret. Both are issued by '
    . 'whoever runs the dashboard.';
$string['connect_send_title'] = 'Send data to remote dashboard';
$string['connect_send_when'] = 'Use when the dashboard cannot reach this site\'s URL — for example '
    . 'instances behind a firewall, on a private network, or being evaluated before network access '
    . 'has been opened up.';
$string['egress_field_db_host'] = 'Database hostname';
$string['egress_field_failed_logins'] = 'Top failed-login accounts';
$string['egress_field_os_hostname'] = 'Server hostname';
$string['egress_field_tokens_entries'] = 'Per-token detail rows';
$string['egress_fields_heading'] = 'Sensitive sub-fields';
$string['egress_fields_intro'] = 'Even when the parent slice is enabled, these specific fields can be redacted. '
    . 'Unchecked items are removed from the response sent to the dashboard.';
$string['egress_heading'] = 'Data shared with dashboard';
$string['egress_intro'] = 'Choose which parts of the snapshot are sent to the central dashboard. '
    . 'The Overview page above continues to show full data regardless of what you choose here. '
    . 'These settings affect both the pull web service endpoints and the push scheduled task.';
$string['egress_preview_heading'] = 'Egress preview';
$string['egress_preview_link'] = 'Preview what the dashboard sees';
$string['egress_save'] = 'Save data-sharing settings';
$string['egress_saved'] = 'Data-sharing preferences saved.';
$string['egress_slice_label_auth'] = 'Authentication — methods, tokens, failed logins';
$string['egress_slice_label_config_changes'] = 'Config changes — recent admin-setting edits';
$string['egress_slice_label_config_drift'] = 'Config drift — settings that differ from default';
$string['egress_slice_label_environment'] = 'Environment — PHP, OS, DB, OPcache, extensions, SSL';
$string['egress_slice_label_health'] = 'Health — cron, tasks, users, disk, backup';
$string['egress_slice_label_plugins'] = 'Plugins — installed plugins, available updates';
$string['egress_slice_label_reports'] = 'Reports — performance, security, system status';
$string['egress_slice_label_status'] = 'Status — release, branch, EOL, core update';
$string['egress_slices_heading'] = 'Snapshot slices';
$string['overview_active_users'] = 'Active users';
$string['overview_auth_failed'] = 'Failed logins';
$string['overview_auth_method'] = 'Method';
$string['overview_auth_methods'] = 'Enabled authentication methods';
$string['overview_auth_tokens'] = 'Web service tokens';
$string['overview_auth_users_active'] = 'Active users';
$string['overview_auth_users_total'] = 'Total users';
$string['overview_connection_heading'] = 'Connection to remote dashboard';
$string['overview_context_strip'] = '{$a->release} · snapshot generated {$a->generated}';
$string['overview_cron_last_run'] = 'Cron last run';
$string['overview_cron_never'] = 'never';
$string['overview_disk_free'] = 'Disk free (moodledata)';
$string['overview_drift_current'] = 'Current';
$string['overview_drift_default'] = 'Default';
$string['overview_drift_ignore'] = 'Ignore';
$string['overview_drift_ignored_auto_explainer'] = 'Default is empty/null and current is "0" — typically widgets and one-shot flags with no meaningful drift.';
$string['overview_drift_ignored_auto_sub'] = 'Auto-ignored ({$a})';
$string['overview_drift_ignored_heading'] = 'Ignored settings ({$a}) — click to expand';
$string['overview_drift_ignored_manual_sub'] = 'Manually ignored ({$a})';
$string['overview_drift_native'] = 'Browse all site config';
$string['overview_drift_setting'] = 'Setting';
$string['overview_drift_show'] = 'Show';
$string['overview_env_db'] = 'Database';
$string['overview_env_native'] = 'View Moodle environment check';
$string['overview_env_opcache'] = 'OPcache';
$string['overview_env_os'] = 'Operating system';
$string['overview_env_php'] = 'PHP';
$string['overview_env_ssl'] = 'SSL certificate';
$string['overview_env_web'] = 'Web server';
$string['overview_heading'] = 'Sentinel: Overview';
$string['overview_label'] = 'Overview';
$string['overview_manage_connection'] = 'Manage connection →';
$string['overview_metric_core_update'] = 'Core update';
$string['overview_metric_core_update_subtext'] = 'releases behind latest';
$string['overview_metric_critical'] = 'Critical';
$string['overview_metric_critical_subtext'] = 'system_status checks';
$string['overview_metric_errors'] = 'Errors';
$string['overview_metric_errors_subtext'] = 'across performance / security / system_status';
$string['overview_metric_plugin_updates'] = 'Plugin updates';
$string['overview_metric_plugin_updates_subtext'] = 'plugins with a newer version upstream';
$string['overview_no_checks'] = 'No checks reported.';
$string['overview_no_drift'] = 'No settings differ from default — config is at out-of-the-box values.';
$string['overview_overdue_tasks'] = 'Overdue scheduled tasks';
$string['overview_plugin_component'] = 'Component';
$string['overview_plugin_update'] = 'Update';
$string['overview_plugin_version'] = 'Version';
$string['overview_plugins_intro'] = '{$a->count} third-party plugin(s) installed. Update check last fetched: {$a->fetched}.';
$string['overview_plugins_manage'] = 'Manage plugins';
$string['overview_plugins_missing'] = 'Plugins missing from disk';
$string['overview_plugins_none'] = 'No third-party plugins installed.';
$string['overview_pull_status'] = 'Allow dashboard to pull';
$string['overview_refresh'] = 'Refresh';
$string['overview_reports_performance'] = 'Performance';
$string['overview_reports_security'] = 'Security';
$string['overview_reports_system_status'] = 'System status';
$string['overview_section_health'] = 'Health';
$string['overview_send_status'] = 'Send to dashboard';
$string['overview_snapshot_error'] = 'Snapshot could not be generated: {$a}';
$string['overview_ssl_days_remaining'] = 'SSL certificate days remaining';
$string['overview_tab_auth'] = 'Authentication';
$string['overview_tab_configdrift'] = 'Config drift';
$string['overview_tab_environment'] = 'Environment';
$string['overview_tab_health'] = 'Health';
$string['overview_tab_plugins'] = 'Plugins';
$string['overview_tab_reports'] = 'Reports';
$string['overview_view_native'] = 'view native page';
$string['pluginname'] = 'Sentinel';
$string['privacy:metadata'] = 'The Sentinel plugin stores no personal data in its own tables. '
    . 'It does, however, read a small set of personal data from existing Moodle tables and transmit '
    . 'it to a central Sentinel dashboard either via a scheduled push or in response to a pull web-service call. '
    . 'See below for the fields involved.';
$string['privacy:metadata:sentinel_dashboard'] = 'Operational snapshot transmitted to the central Sentinel dashboard. '
    . 'The dashboard\'s purpose is fleet-wide monitoring of Moodle health, security, and configuration. '
    . 'Site administrators can opt out of specific personal-data fields on the Sentinel Settings page (the egress filter).';
$string['privacy:metadata:sentinel_dashboard:admin_lastaccess'] = 'Timestamp of each site administrator\'s most recent activity on the site, used so the dashboard can show last-seen ages.';
$string['privacy:metadata:sentinel_dashboard:admin_lastlogin'] = 'Timestamp of each site administrator\'s most recent successful login.';
$string['privacy:metadata:sentinel_dashboard:admin_username'] = 'Username of each user listed in $CFG->siteadmins, so the dashboard can attribute admin activity per site.';
$string['privacy:metadata:sentinel_dashboard:config_change_userid'] = 'Internal user ID of the admin who made each recently-recorded admin-setting change (from mdl_config_log).';
$string['privacy:metadata:sentinel_dashboard:config_change_username'] = 'Username of the admin who made each recent admin-setting change, for accountability in the change log.';
$string['privacy:metadata:sentinel_dashboard:failed_login_count'] = 'Number of failed login attempts since the last successful login for each top affected account.';
$string['privacy:metadata:sentinel_dashboard:failed_login_lastfailure'] = 'Timestamp of the most recent failed login attempt for each top affected account.';
$string['privacy:metadata:sentinel_dashboard:failed_login_lastlogin'] = 'Timestamp of the most recent successful login for each top affected account.';
$string['privacy:metadata:sentinel_dashboard:failed_login_userid'] = 'Internal user ID of accounts with active failed-login counters (admin-opt-out via the egress filter).';
$string['privacy:metadata:sentinel_dashboard:failed_login_username'] = 'Username of accounts under active failed-login attack (admin-opt-out via the egress filter).';
$string['privacy:metadata:sentinel_dashboard:siteidentifier'] = 'Stable per-site identifier used as the primary key the dashboard correlates a site by.';
$string['privacy:metadata:sentinel_dashboard:token_created'] = 'Timestamp each web-service token was issued, for spotting tokens that have been around a long time.';
$string['privacy:metadata:sentinel_dashboard:token_lastaccess'] = 'Timestamp each web-service token was most recently used, for spotting stale or unused tokens.';
$string['privacy:metadata:sentinel_dashboard:token_owner_username'] = 'Username of each web-service token\'s owner (admin-opt-out via the egress filter).';
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
$string['pushstate_consecutive_failures'] = 'Consecutive failures';
$string['pushstate_heading'] = 'Push pipeline';
$string['pushstate_last_attempt'] = 'Last attempt';
$string['pushstate_last_error'] = 'Last error';
$string['pushstate_last_success'] = 'Last success';
$string['pushstate_never'] = 'never';
$string['pushstate_test_button'] = 'Test push now';
$string['pushstate_test_failed'] = 'Test push failed: {$a}';
$string['pushstate_test_success'] = 'Test push completed — see status below.';
$string['sentinel:view'] = 'View Sentinel snapshot data';
$string['servicemissing'] = 'The Sentinel external service was not found. '
    . 'Visit Site administration → Notifications to finish plugin installation, then retry.';
$string['servicename'] = 'Sentinel';
$string['settings_label'] = 'Send data to remote dashboard';
$string['settingsheading_push'] = 'Outbound configuration';
$string['settingsheading_push_desc'] = 'Configures the scheduled task that posts snapshots to the '
    . 'remote dashboard. See the Connect to dashboard page for when to use this and how it differs '
    . 'from the retrieval mechanism.';
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
    . 'from this Moodle. See the Connect to dashboard page for when to use this. '
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
