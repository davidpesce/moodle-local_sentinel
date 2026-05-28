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
 * Reports collector: performance, security, system status, MFA.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\collectors;

use core\check\manager;
use core\check\result;

/**
 * Built-in Moodle reports, exposed in the snapshot.
 *
 * Performance / security / system_status all use the modern
 * \core\check\manager API, which is the same data behind the admin UI's
 * Performance overview, Security checks, and System status pages.
 *
 * MFA aggregates the tool_mfa table directly: count of users per active
 * factor, total users with any factor, and locked-out user count.
 */
class reports {
    /**
     * Collect.
     *
     * @return array
     */
    public static function collect(): array {
        return [
            'performance' => self::collect_checks('performance'),
            'security' => self::collect_checks('security'),
            'system_status' => self::collect_checks('status'),
            'mfa' => self::collect_mfa(),
        ];
    }

    /**
     * Run a category of \core\check\manager checks and capture status + summary.
     *
     * @param string $type One of: performance, security, status.
     * @return array
     */
    protected static function collect_checks(string $type): array {
        // Some core checks (e.g. core\check\environment\upgradecheck) emit
        // stray output (a newline) when get_result() runs, which trips
        // PHPUnit's strict output-buffering check and would leak through a WS
        // response. Wrap the whole walk in a buffer and discard it.
        ob_start();
        try {
            $checks = match ($type) {
                'performance' => manager::get_performance_checks(),
                'security' => manager::get_security_checks(),
                'status' => manager::get_status_checks(),
                default => [],
            };

            // All possible status keys, so the output shape is stable
            // regardless of which statuses happen to occur on this site.
            $countsbystatus = array_fill_keys([
                result::NA,
                result::OK,
                result::INFO,
                result::UNKNOWN,
                result::WARNING,
                result::ERROR,
                result::CRITICAL,
            ], 0);

            $entries = [];
            foreach ($checks as $check) {
                try {
                    $checkresult = $check->get_result();
                } catch (\Throwable $e) {
                    $entries[] = [
                        'ref' => $check->get_ref(),
                        'component' => $check->get_component(),
                        'name' => $check->get_name(),
                        'status' => result::UNKNOWN,
                        'summary' => 'Check threw: ' . $e->getMessage(),
                    ];
                    $countsbystatus[result::UNKNOWN]++;
                    continue;
                }
                $status = $checkresult->get_status();
                $countsbystatus[$status] = ($countsbystatus[$status] ?? 0) + 1;
                $entries[] = [
                    'ref' => $check->get_ref(),
                    'component' => $check->get_component(),
                    'name' => $check->get_name(),
                    'status' => $status,
                    'summary' => trim(html_to_text($checkresult->get_summary(), 0, false)),
                ];
            }
        } finally {
            ob_end_clean();
        }

        return [
            'total' => count($entries),
            'counts_by_status' => $countsbystatus,
            'checks' => $entries,
        ];
    }

    /**
     * MFA factor enrollment counts from mdl_tool_mfa.
     *
     * tool_mfa ships with Moodle 4.x but isn't necessarily enabled. Reports
     * installed = false when the plugin records don't exist.
     *
     * @return array
     */
    protected static function collect_mfa(): array {
        global $DB;

        $pluginman = \core_plugin_manager::instance();
        $info = $pluginman->get_plugin_info('tool_mfa');
        if (!$info) {
            return ['installed' => false];
        }

        // The plugin may be present in the codebase but not yet installed
        // (e.g. fresh 4.5 box that has never run upgrade); guard the table read.
        $dbmanager = $DB->get_manager();
        if (!$dbmanager->table_exists('tool_mfa')) {
            return ['installed' => false];
        }

        $enabled = (bool) get_config('tool_mfa', 'enabled');

        $byfactor = $DB->get_records_sql(
            'SELECT factor, COUNT(DISTINCT userid) AS users
               FROM {tool_mfa}
              WHERE revoked = 0
              GROUP BY factor
              ORDER BY factor'
        );
        $factors = [];
        foreach ($byfactor as $row) {
            $factors[] = [
                'factor' => $row->factor,
                'active_users' => (int) $row->users,
            ];
        }

        $userswithfactor = (int) $DB->get_field_sql(
            'SELECT COUNT(DISTINCT userid) FROM {tool_mfa} WHERE revoked = 0'
        );

        $locklevel = (int) get_config('tool_mfa', 'lockout');
        $locked = 0;
        if ($locklevel > 0) {
            $locked = (int) $DB->get_field_sql(
                'SELECT COUNT(DISTINCT userid) FROM {tool_mfa} WHERE revoked = 0 AND lockcounter >= ?',
                [$locklevel]
            );
        }

        return [
            'installed' => true,
            'enabled' => $enabled,
            'users_with_factor' => $userswithfactor,
            'locked_users' => $locked,
            'by_factor' => $factors,
        ];
    }
}
