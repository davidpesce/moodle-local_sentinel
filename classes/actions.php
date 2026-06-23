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
 * Actionable-item derivation for the Overview page.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Derives the "Action needed" list from a snapshot.
 *
 * Answers "what should I do on this site today?" from point-in-time facts the
 * collectors already hold — each item carries a severity, a human message, and
 * a link to the page where the admin acts. Strictly local and strictly
 * render-on-request: no history, no notifications, no external data sources.
 */
class actions {
    /** @var int Warn when the TLS certificate expires within this many days. */
    public const CERT_WARN_DAYS = 21;

    /** @var int Warn when branch security support ends within this many days. */
    public const EOL_WARN_DAYS = 90;

    /** @var int Warn below this % of free disk on dataroot. */
    public const DISK_WARN_PCT = 10;

    /** @var int Escalate below this % of free disk on dataroot. */
    public const DISK_DANGER_PCT = 5;

    /**
     * Build the ordered action list for a snapshot.
     *
     * @param array $snapshot Full snapshot from the collector.
     * @return array[] Each: ['severity' => danger|error|warning|info,
     *                        'message' => string, 'url' => \moodle_url|null]
     */
    public static function from_snapshot(array $snapshot): array {
        $items = [];
        $add = function (string $severity, string $message, ?\moodle_url $url) use (&$items): void {
            $items[] = ['severity' => $severity, 'message' => $message, 'url' => $url];
        };

        $reports = $snapshot['reports'] ?? [];
        $health = $snapshot['health'] ?? [];
        $status = $snapshot['status'] ?? [];
        $env = $snapshot['environment'] ?? [];
        $auth = $snapshot['auth'] ?? [];
        $plugins = $snapshot['plugins'] ?? [];

        // ... danger tier: the site is (or is about to be) broken.

        $critical = (int) ($reports['system_status']['counts_by_status']['critical'] ?? 0);
        if ($critical > 0) {
            $add(
                'danger',
                get_string('overview_action_critical', 'local_sentinel', $critical),
                new \moodle_url('/report/status/index.php')
            );
        }

        $cron = $health['cron'] ?? [];
        $since = $cron['seconds_since_last_run'] ?? null;
        if (is_numeric($since)) {
            $expected = max(60, (int) ($cron['expected_frequency_seconds'] ?? 60));
            if ((int) $since > max(2 * HOURSECS, 10 * $expected)) {
                $add(
                    'danger',
                    get_string('overview_action_cron', 'local_sentinel', format_time((int) $since)),
                    new \moodle_url('/admin/tool/task/scheduledtasks.php')
                );
            }
        }

        $failedtasks = (int) ($health['tasks']['scheduled_failed_count'] ?? 0);
        if ($failedtasks > 0) {
            $add(
                'danger',
                get_string('overview_action_failed_tasks', 'local_sentinel', $failedtasks),
                new \moodle_url('/admin/tool/task/scheduledtasks.php')
            );
        }

        $notready = (int) ($health['cache_stores']['not_ready_count'] ?? 0);
        if ($notready > 0) {
            $add(
                'danger',
                get_string('overview_action_cache', 'local_sentinel', $notready),
                new \moodle_url('/cache/admin.php')
            );
        }

        $ssl = $env['ssl'] ?? [];
        $certdays = $ssl['days_remaining'] ?? null;
        if (!empty($ssl['checked']) && is_numeric($certdays)) {
            if ((int) $certdays <= 0) {
                $add('danger', get_string('overview_action_cert_expired', 'local_sentinel'), null);
            } else if ((int) $certdays <= self::CERT_WARN_DAYS) {
                $add('warning', get_string('overview_action_cert', 'local_sentinel', (int) $certdays), null);
            }
        }

        $disk = $health['disk']['dataroot'] ?? [];
        $free = $disk['free_bytes'] ?? null;
        $total = $disk['total_bytes'] ?? null;
        if (is_numeric($free) && is_numeric($total) && (float) $total > 0) {
            $pct = (float) $free / (float) $total * 100;
            if ($pct < self::DISK_DANGER_PCT || $pct < self::DISK_WARN_PCT) {
                $add(
                    $pct < self::DISK_DANGER_PCT ? 'danger' : 'warning',
                    get_string('overview_action_disk', 'local_sentinel', display_size((int) $free)),
                    new \moodle_url('/local/sentinel/overview.php', ['tab' => 'health'])
                );
            }
        }

        $eoldays = $status['branch_eol_days_remaining'] ?? null;
        if (is_numeric($eoldays) && (int) $eoldays < 0) {
            $add('danger', get_string(
                'overview_action_branch_eol_past',
                'local_sentinel',
                (string) ($status['release'] ?? '')
            ), new \moodle_url('/admin/index.php'));
        }

        // ... warning tier: act soon.

        $errors = (int) ($reports['performance']['counts_by_status']['error'] ?? 0)
            + (int) ($reports['security']['counts_by_status']['error'] ?? 0)
            + (int) ($reports['system_status']['counts_by_status']['error'] ?? 0);
        if ($errors > 0) {
            $add(
                'error',
                get_string('overview_action_errors', 'local_sentinel', $errors),
                new \moodle_url('/local/sentinel/overview.php', ['tab' => 'reports'])
            );
        }

        if (!empty($status['core_update']['update_available'])) {
            $add(
                'warning',
                get_string(
                    'overview_action_core_update',
                    'local_sentinel',
                    (string) ($status['core_update']['latest_on_branch']['release'] ?? '')
                ),
                new \moodle_url('/admin/index.php')
            );
        }

        if (is_numeric($eoldays) && (int) $eoldays >= 0 && (int) $eoldays <= self::EOL_WARN_DAYS) {
            $add('warning', get_string('overview_action_branch_eol', 'local_sentinel', (object) [
                'release' => (string) ($status['release'] ?? ''),
                'days' => (int) $eoldays,
            ]), new \moodle_url('/admin/index.php'));
        }

        $pluginupdates = count($plugins['updates_available'] ?? []);
        if ($pluginupdates > 0) {
            $add(
                'warning',
                get_string('overview_action_plugin_updates', 'local_sentinel', $pluginupdates),
                new \moodle_url('/admin/plugins.php', null, 'updatable')
            );
        }

        $overdue = (int) ($health['tasks']['scheduled_overdue_count'] ?? 0);
        if ($overdue > 0) {
            $add(
                'warning',
                get_string('overview_action_overdue_tasks', 'local_sentinel', $overdue),
                new \moodle_url('/admin/tool/task/scheduledtasks.php')
            );
        }

        $backup = $health['backup'] ?? [];
        $backuperrors = (int) ($backup['status_counts']['error'] ?? 0);
        if (!empty($backup['automated_state']) && $backuperrors > 0) {
            $add(
                'warning',
                get_string('overview_action_backups', 'local_sentinel', $backuperrors),
                new \moodle_url('/report/backups/index.php')
            );
        }

        $locked = (int) ($auth['failed_logins']['locked_accounts'] ?? 0);
        if ($locked > 0) {
            $add(
                'warning',
                get_string('overview_action_locked', 'local_sentinel', $locked),
                new \moodle_url('/admin/user.php')
            );
        }

        $expiring = (int) ($auth['tokens']['expiring_within_30_days'] ?? 0);
        if ($expiring > 0) {
            $add(
                'warning',
                get_string('overview_action_tokens_expiring', 'local_sentinel', $expiring),
                new \moodle_url('/admin/webservice/tokens.php')
            );
        }

        // ... info tier: hygiene worth a look, not urgent.

        $noip = (int) ($auth['tokens']['without_ip_restriction'] ?? 0);
        if ($noip > 0) {
            $add(
                'info',
                get_string('overview_action_tokens_noip', 'local_sentinel', $noip),
                new \moodle_url('/admin/webservice/tokens.php')
            );
        }

        if (!empty($health['flags']['debug'])) {
            $add(
                'info',
                get_string('overview_action_debug', 'local_sentinel'),
                new \moodle_url('/admin/settings.php', ['section' => 'debugging'])
            );
        }

        $pkg = $env['os']['package_updates'] ?? [];
        $security = $pkg['security'] ?? null;
        if (is_numeric($security) && (int) $security > 0) {
            $add('info', get_string('overview_action_os_updates', 'local_sentinel', (int) $security), null);
        }
        if (!empty($pkg['reboot_required'])) {
            $add('info', get_string('overview_action_os_reboot', 'local_sentinel'), null);
        }

        // Stable severity ordering: danger, error, warning, info.
        $rank = ['danger' => 0, 'error' => 1, 'warning' => 2, 'info' => 3];
        usort($items, fn($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);
        return $items;
    }
}
