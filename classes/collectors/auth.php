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
 * Auth methods collector.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\collectors;

/**
 * Enabled authentication plugins and user counts per method.
 */
class auth {
    /**
     * Collect.
     *
     * @return array
     */
    public static function collect(): array {
        global $CFG, $DB;

        $enabled = array_values(array_filter(array_map('trim', explode(',', (string) $CFG->auth))));
        $methods = [];
        foreach ($enabled as $method) {
            $methods[] = [
                'plugin' => $method,
                'total_users' => (int) $DB->count_records('user', [
                    'auth' => $method,
                    'deleted' => 0,
                ]),
                'active_users' => (int) $DB->count_records('user', [
                    'auth' => $method,
                    'deleted' => 0,
                    'suspended' => 0,
                ]),
            ];
        }

        $allmethods = (int) $DB->count_records_select('user', 'deleted = 0', null, 'COUNT(DISTINCT auth)');

        return [
            'enabled' => $enabled,
            'methods' => $methods,
            'distinct_methods_in_use' => $allmethods,
            'failed_logins' => self::collect_failed_logins(),
            'tokens' => self::collect_tokens(),
        ];
    }

    /**
     * Inventory of web service tokens.
     *
     * Metadata only — the actual token strings are credentials and never
     * leave the originating Moodle. Used to spot security drift: tokens
     * with no IP restriction, tokens that have never been used, tokens
     * approaching their expiry.
     *
     * @return array
     */
    protected static function collect_tokens(): array {
        global $DB;

        $sql = "SELECT t.id, t.tokentype, t.userid, t.externalserviceid,
                       t.iprestriction, t.validuntil, t.timecreated, t.lastaccess,
                       u.username, u.deleted AS userdeleted,
                       s.shortname AS serviceshortname, s.name AS servicename
                  FROM {external_tokens} t
             LEFT JOIN {user} u ON u.id = t.userid
             LEFT JOIN {external_services} s ON s.id = t.externalserviceid
              ORDER BY t.id ASC";
        $rows = $DB->get_records_sql($sql);

        $entries = [];
        $now = time();
        $expiringthreshold = $now + (30 * DAYSECS);
        $stalethreshold = $now - (90 * DAYSECS);
        $recentthreshold = $now - (7 * DAYSECS);

        $without_ip = 0;
        $never_used = 0;
        $active_recent = 0;
        $stale = 0;
        $expiring_soon = 0;

        foreach ($rows as $row) {
            $iprestriction = trim((string) ($row->iprestriction ?? ''));
            $hasip = $iprestriction !== '';
            $lastaccess = (int) $row->lastaccess;
            $validuntil = (int) $row->validuntil;

            if (!$hasip) {
                $without_ip++;
            }
            if ($lastaccess === 0) {
                $never_used++;
            } else if ($lastaccess > $recentthreshold) {
                $active_recent++;
            } else if ($lastaccess < $stalethreshold) {
                $stale++;
            }
            if ($validuntil > 0 && $validuntil < $expiringthreshold) {
                $expiring_soon++;
            }

            $entries[] = [
                'id' => (int) $row->id,
                'type' => self::token_type_label((int) $row->tokentype),
                'user' => $row->username ?? '(deleted)',
                'user_deleted' => (bool) ($row->userdeleted ?? false),
                'service_shortname' => $row->serviceshortname,
                'service_name' => $row->servicename,
                'has_ip_restriction' => $hasip,
                'ip_restriction' => $iprestriction,
                'created' => (int) $row->timecreated,
                'last_access' => $lastaccess,
                'valid_until' => $validuntil,
            ];
        }

        return [
            'total_count' => count($entries),
            'without_ip_restriction' => $without_ip,
            'never_used' => $never_used,
            'active_last_7_days' => $active_recent,
            'stale_over_90_days' => $stale,
            'expiring_within_30_days' => $expiring_soon,
            'entries' => $entries,
        ];
    }

    /**
     * Map an EXTERNAL_TOKEN_* constant to a short label.
     *
     * @param int $type
     * @return string
     */
    protected static function token_type_label(int $type): string {
        if (defined('EXTERNAL_TOKEN_PERMANENT') && $type === EXTERNAL_TOKEN_PERMANENT) {
            return 'permanent';
        }
        if (defined('EXTERNAL_TOKEN_EMBEDDED') && $type === EXTERNAL_TOKEN_EMBEDDED) {
            return 'embedded';
        }
        return (string) $type;
    }

    /**
     * Cumulative failed-login counter from mdl_user.failedlogincount.
     *
     * The counter resets on successful login, so a high value indicates an
     * account is actively under attack or being locked out by a typo. Top N
     * accounts surfaced so the fleet dashboard can highlight specific targets.
     *
     * @return array
     */
    protected static function collect_failed_logins(): array {
        global $DB;

        // Moodle stores per-user failure counters in mdl_user_preferences.
        // login_failed_count_since_success is the active-attack signal: it
        // increments on every failed login and resets only on a real
        // successful login (not on the automatic lockout-window expiry that
        // resets login_failed_count). login_failed_last is the timestamp of
        // the most recent failure; login_lockout is set while the account
        // is currently locked.
        $countbyuser = [];
        $rows = $DB->get_records('user_preferences', ['name' => 'login_failed_count_since_success']);
        foreach ($rows as $row) {
            $count = (int) $row->value;
            if ($count > 0) {
                $countbyuser[(int) $row->userid] = $count;
            }
        }

        $lockeduserids = array_map('intval', array_keys(
            $DB->get_records_menu('user_preferences', ['name' => 'login_lockout'], '', 'userid, name')
        ));

        $totalfailed = array_sum($countbyuser);
        $accountswithfailures = count($countbyuser);

        arsort($countbyuser, SORT_NUMERIC);
        $topids = array_slice(array_keys($countbyuser), 0, 10);
        $topaccounts = [];
        if (!empty($topids)) {
            [$insql, $params] = $DB->get_in_or_equal($topids, SQL_PARAMS_NAMED);
            $users = $DB->get_records_select(
                'user',
                "id $insql AND deleted = 0",
                $params,
                '',
                'id, username, lastlogin, suspended'
            );
            $lastfailures = $DB->get_records_select_menu(
                'user_preferences',
                "name = 'login_failed_last' AND userid $insql",
                $params,
                '',
                'userid, value'
            );
            foreach ($topids as $uid) {
                if (!isset($users[$uid])) {
                    continue;
                }
                $u = $users[$uid];
                $topaccounts[] = [
                    'id' => (int) $u->id,
                    'username' => $u->username,
                    'failed_count' => $countbyuser[$uid],
                    'last_failure' => isset($lastfailures[$uid]) ? (int) $lastfailures[$uid] : null,
                    'last_login' => (int) $u->lastlogin,
                    'locked' => in_array($uid, $lockeduserids, true),
                    'suspended' => (bool) $u->suspended,
                ];
            }
        }

        return [
            'total_failed_count' => $totalfailed,
            'accounts_with_failures' => $accountswithfailures,
            'locked_accounts' => count($lockeduserids),
            'top_accounts' => $topaccounts,
        ];
    }
}
