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
 * Health collector: cron, tasks, disk, sessions, mail, foot-gun flags.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\collectors;

/**
 * Operational health signals — the things most likely to wake an operator at night.
 */
class health {
    /**
     * Collect.
     *
     * @return array
     */
    public static function collect(): array {
        return [
            'cron' => self::collect_cron(),
            'tasks' => self::collect_tasks(),
            'sessions' => self::collect_sessions(),
            'active_users' => self::collect_active_users(),
            'disk' => self::collect_disk(),
            'mail' => self::collect_mail(),
            'admins' => self::collect_admins(),
            'backup' => self::collect_backup(),
            'upgrade_log' => self::collect_upgrade_log(),
            'flags' => self::collect_footgun_flags(),
            'cache_stores' => self::collect_cache_stores(),
            'push_state' => \local_sentinel\push_state::get(),
        ];
    }

    /**
     * Reachability summary for every configured MUC cache store.
     *
     * The dashboard's main consumer — it can flag a site running degraded
     * because (say) Memcached is down even when no admin can log in to
     * notice. Stores are probed via Moodle's own administration_helper, which
     * calls each store's is_ready() — same signal the /cache/admin.php page
     * shows.
     *
     * Caveat: probing a remote store that's TCP-unreachable can sit on a
     * connection timeout. We wrap the whole walk in a try/catch and add a
     * per-store catch so one bad store doesn't poison the rest.
     *
     * @return array
     */
    protected static function collect_cache_stores(): array {
        if (!class_exists('\core_cache\administration_helper')) {
            return ['available' => false, 'reason' => 'core_cache helpers not available'];
        }

        try {
            // The administration_helper instantiates each store and calls
            // is_ready() — same signal the /cache/admin.php page shows.
            // Suppress any stray output the store classes might emit during
            // construction (some legacy stores echo warnings).
            ob_start();
            $summaries = \core_cache\administration_helper::get_store_instance_summaries();
            ob_end_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            return ['available' => false, 'reason' => 'enumeration failed: ' . $e->getMessage()];
        }

        $stores = [];
        $notreadycount = 0;
        foreach ($summaries as $name => $detail) {
            $isready = !empty($detail['isready']);
            if (!$isready) {
                $notreadycount++;
            }
            $stores[] = [
                'name' => (string) $name,
                'plugin' => (string) ($detail['plugin'] ?? ''),
                'is_default' => !empty($detail['default']),
                'is_ready' => $isready,
                'requirements_met' => !empty($detail['requirementsmet']),
                'mappings' => (int) ($detail['mappings'] ?? 0),
                'warnings' => array_values(array_map('strval', $detail['warnings'] ?? [])),
                'supports_application_mode' => !empty($detail['modes'][1] ?? false),
                'supports_session_mode' => !empty($detail['modes'][2] ?? false),
                'supports_request_mode' => !empty($detail['modes'][4] ?? false),
            ];
        }

        return [
            'available' => true,
            'total_count' => count($stores),
            'not_ready_count' => $notreadycount,
            'stores' => $stores,
        ];
    }

    /**
     * Distinct active users in the last day / week / month, by lastaccess.
     *
     * @return array
     */
    protected static function collect_active_users(): array {
        global $DB;

        $now = time();
        return [
            'dau' => (int) $DB->count_records_select(
                'user',
                'deleted = 0 AND lastaccess > :since',
                ['since' => $now - DAYSECS]
            ),
            'wau' => (int) $DB->count_records_select(
                'user',
                'deleted = 0 AND lastaccess > :since',
                ['since' => $now - WEEKSECS]
            ),
            'mau' => (int) $DB->count_records_select(
                'user',
                'deleted = 0 AND lastaccess > :since',
                ['since' => $now - (30 * DAYSECS)]
            ),
        ];
    }

    /**
     * Tail of mdl_upgrade_log: recent install/upgrade activity with type-named severity.
     *
     * @return array
     */
    protected static function collect_upgrade_log(): array {
        global $CFG, $DB;

        // Constants live in upgradelib.php which is not autoloaded in every context.
        require_once($CFG->libdir . '/upgradelib.php');

        $typelabels = [
            UPGRADE_LOG_NORMAL => 'normal',
            UPGRADE_LOG_NOTICE => 'notice',
            UPGRADE_LOG_ERROR => 'error',
        ];

        $rows = $DB->get_records_sql(
            'SELECT id, type, plugin, version, targetversion, info, timemodified, userid
               FROM {upgrade_log}
              ORDER BY timemodified DESC, id DESC',
            null,
            0,
            25
        );

        $entries = [];
        foreach ($rows as $row) {
            $type = (int) $row->type;
            $entries[] = [
                'id' => (int) $row->id,
                'time' => (int) $row->timemodified,
                'type' => $type,
                'type_label' => $typelabels[$type] ?? 'unknown',
                'plugin' => $row->plugin,
                'version' => $row->version,
                'targetversion' => $row->targetversion,
                'info' => $row->info,
                'userid' => (int) $row->userid,
            ];
        }

        $errorcount = (int) $DB->count_records('upgrade_log', ['type' => UPGRADE_LOG_ERROR]);

        return [
            'recent' => $entries,
            'total_errors' => $errorcount,
        ];
    }

    /**
     * Automated backup state and per-status counts from mdl_backup_courses.
     *
     * Reads the table directly rather than going through backup_cron_automated_helper
     * because the helper's public API surface has shifted across Moodle versions and
     * is not stable.
     *
     * Status codes (from backup_cron_automated_helper):
     *   0 = ERROR, 1 = OK, 2 = UNFINISHED, 3 = SKIPPED,
     *   4 = WARNING, 5 = NOTYETRUN, 6 = QUEUED
     *
     * @return array
     */
    protected static function collect_backup(): array {
        global $DB;

        $automatedstate = (int) get_config('backup', 'backup_auto_active');

        $statuslabels = [
            0 => 'error',
            1 => 'ok',
            2 => 'unfinished',
            3 => 'skipped',
            4 => 'warning',
            5 => 'notyetrun',
            6 => 'queued',
        ];

        $rows = $DB->get_records_sql(
            'SELECT laststatus, COUNT(*) AS c FROM {backup_courses} GROUP BY laststatus'
        );
        $statuscounts = array_fill_keys(array_values($statuslabels), 0);
        foreach ($rows as $row) {
            $label = $statuslabels[(int) $row->laststatus] ?? 'unknown';
            $statuscounts[$label] = (int) $row->c;
        }

        $lastsuccess = (int) $DB->get_field_sql(
            'SELECT MAX(laststarttime) FROM {backup_courses} WHERE laststatus = ?',
            [1]
        );

        return [
            'automated_state' => $automatedstate,
            'status_counts' => $statuscounts,
            'last_success' => $lastsuccess > 0 ? $lastsuccess : null,
            'total_courses_tracked' => array_sum($statuscounts),
        ];
    }

    /**
     * Collect cron.
     *
     * @return array
     */
    protected static function collect_cron(): array {
        global $CFG;
        // The tool_task/lastcronstart config is what modern Moodle (4.x+)
        // updates on every cron tick — same field /admin/tool/task/check/
        // cronrunning reads. The legacy core/lastcron we used previously
        // is no longer written by Moodle's task runner, so it stayed at 0
        // forever and made healthy sites look broken.
        $lastcron = (int) get_config('tool_task', 'lastcronstart');
        $lastinterval = (int) get_config('tool_task', 'lastcroninterval');
        $expected = (int) ($CFG->expectedcronfrequency ?? MINSECS);
        $now = time();
        return [
            'last_run' => $lastcron,
            'seconds_since_last_run' => $lastcron > 0 ? $now - $lastcron : null,
            'last_interval_seconds' => $lastinterval > 0 ? $lastinterval : null,
            'expected_frequency_seconds' => $expected,
            'now' => $now,
        ];
    }

    /**
     * Collect tasks.
     *
     * @return array
     */
    protected static function collect_tasks(): array {
        global $DB;

        /*
         * Failed: faildelay > 0 means the task tripped its retry timer.
         * Filtered to match Moodle's own cron loop, same as the overdue
         * list below: skip rows where the task is disabled at the row
         * level, where can_run() returns false (plugin is disabled), or
         * where the class no longer exists (uninstalled plugin left
         * orphan rows). The result is the set of failed tasks Moodle
         * would actually retry on the next cron tick.
         */
        $failed = $DB->get_records_select(
            'task_scheduled',
            'faildelay > 0 AND disabled = 0',
            null,
            'classname ASC'
        );
        $failedlist = [];
        foreach ($failed as $row) {
            try {
                $task = \core\task\manager::scheduled_task_from_record($row);
            } catch (\Throwable $e) {
                continue;
            }
            if (!$task || !$task->can_run()) {
                continue;
            }
            $failedlist[] = [
                'classname' => $row->classname,
                'last_run' => (int) $row->lastruntime,
                'faildelay' => (int) $row->faildelay,
                'disabled' => (bool) $row->disabled,
            ];
        }

        /*
         * Overdue: scheduled to have run >1h ago, enabled, not in active
         * retry (those are already in scheduled_failed). Catches "zombie"
         * tasks where cron is alive but the task itself isn't getting picked
         * up for some reason — silent failures that don't trip faildelay.
         *
         * Filtered to match Moodle's own cron loop: each candidate is
         * hydrated into a scheduled_task instance and dropped unless
         * can_run() is true. This drops tasks owned by a disabled plugin
         * (e.g. auth_oauth2 tasks while auth_oauth2 is disabled) — Moodle
         * wouldn't run them and we shouldn't flag them as overdue.
         */
        $overduethreshold = time() - HOURSECS;
        $overduerows = $DB->get_records_select(
            'task_scheduled',
            'disabled = 0 AND faildelay = 0 AND nextruntime > 0 AND nextruntime < :threshold',
            ['threshold' => $overduethreshold],
            'nextruntime ASC'
        );
        $overduelist = [];
        $now = time();
        foreach ($overduerows as $row) {
            try {
                $task = \core\task\manager::scheduled_task_from_record($row);
            } catch (\Throwable $e) {
                // Class no longer exists (e.g. uninstalled plugin) — skip.
                continue;
            }
            if (!$task || !$task->can_run()) {
                continue;
            }
            $overduelist[] = [
                'classname' => $row->classname,
                'last_run' => (int) $row->lastruntime,
                'next_run' => (int) $row->nextruntime,
                'seconds_late' => $now - (int) $row->nextruntime,
            ];
        }

        $adhoctotal = (int) $DB->count_records('task_adhoc');
        $oldest = $DB->get_field_sql(
            'SELECT MIN(nextruntime) FROM {task_adhoc} WHERE nextruntime > 0'
        );

        return [
            'scheduled_failed_count' => count($failedlist),
            'scheduled_failed' => $failedlist,
            'scheduled_overdue_count' => count($overduelist),
            'scheduled_overdue' => $overduelist,
            'adhoc_queue_depth' => $adhoctotal,
            'adhoc_oldest_nextruntime' => $oldest ? (int) $oldest : null,
        ];
    }

    /**
     * Collect sessions.
     *
     * `active_*` count **real logged-in users** active in the window, measured by
     * `mdl_user.lastaccess` — **not** the `mdl_sessions` table. Sessions only live
     * in the DB under the *database* session handler; busy sites commonly store
     * them in Redis/Memcached, leaving `mdl_sessions` empty (so a session-row
     * count read 0 despite many users being online). `lastaccess` is updated on
     * activity regardless of the session backend, and is the same source as the
     * day/week/month figures, so the numbers stay consistent. Excludes deleted
     * users and the shared **Guest** account (`$CFG->siteguest`) — real users of
     * any role (students/teachers/admins) count. `session_rows` keeps the raw
     * `mdl_sessions` size for context (0 under an external session store).
     *
     * @return array
     */
    protected static function collect_sessions(): array {
        global $DB;

        $counts = self::active_user_counts();
        return [
            'active_last_5_min' => $counts['last_5_min'],
            'active_last_hour' => $counts['last_hour'],
            'total_rows' => (int) $DB->count_records('sessions'),
        ];
    }

    /**
     * Distinct real logged-in users active in the last 5 minutes / hour, by
     * `mdl_user.lastaccess` (indexed — a cheap range count, safe for the
     * high-frequency liveness probe). Excludes deleted users and the Guest
     * account; real users of any role count. Shared by the `health` slice and
     * the cheap `status`/get_status slice so both report identical figures.
     *
     * @return array{last_5_min: int, last_hour: int}
     */
    public static function active_user_counts(): array {
        global $DB, $CFG;

        $now = time();
        $select = 'deleted = 0 AND id <> :guestid AND lastaccess > :since';
        $params = ['guestid' => (int) $CFG->siteguest];
        return [
            'last_5_min' => (int) $DB->count_records_select('user', $select, $params + ['since' => $now - 300]),
            'last_hour' => (int) $DB->count_records_select('user', $select, $params + ['since' => $now - 3600]),
        ];
    }

    /**
     * Collect disk.
     *
     * @return array
     */
    protected static function collect_disk(): array {
        global $CFG;

        return [
            'dataroot' => [
                'path' => $CFG->dataroot,
                'free_bytes' => self::safe_disk_free($CFG->dataroot),
                'total_bytes' => self::safe_disk_total($CFG->dataroot),
            ],
            'dirroot' => [
                'path' => $CFG->dirroot,
                'free_bytes' => self::safe_disk_free($CFG->dirroot),
                'total_bytes' => self::safe_disk_total($CFG->dirroot),
            ],
        ];
    }

    /**
     * Safe disk free.
     *
     * @param string $path
     * @return int|null
     */
    protected static function safe_disk_free(string $path): ?int {
        $bytes = @disk_free_space($path);
        return $bytes === false ? null : (int) $bytes;
    }

    /**
     * Safe disk total.
     *
     * @param string $path
     * @return int|null
     */
    protected static function safe_disk_total(string $path): ?int {
        $bytes = @disk_total_space($path);
        return $bytes === false ? null : (int) $bytes;
    }

    /**
     * Collect mail.
     *
     * @return array
     */
    protected static function collect_mail(): array {
        global $CFG;

        return [
            'smtphosts' => isset($CFG->smtphosts) ? (string) $CFG->smtphosts : '',
            'smtpsecure' => isset($CFG->smtpsecure) ? (string) $CFG->smtpsecure : '',
            'noreplyaddress' => isset($CFG->noreplyaddress) ? (string) $CFG->noreplyaddress : '',
            'supportemail' => isset($CFG->supportemail) ? (string) $CFG->supportemail : '',
        ];
    }

    /**
     * Collect admins.
     *
     * @return array
     */
    protected static function collect_admins(): array {
        global $DB;

        $adminids = explode(',', (string) get_config('core', 'siteadmins'));
        $adminids = array_filter(array_map('intval', $adminids));
        if (empty($adminids)) {
            return ['count' => 0, 'admins' => []];
        }
        [$insql, $params] = $DB->get_in_or_equal($adminids, SQL_PARAMS_NAMED);
        $rows = $DB->get_records_select(
            'user',
            "id $insql AND deleted = 0",
            $params,
            'lastaccess DESC',
            'id, username, lastaccess, lastlogin, suspended'
        );
        $admins = [];
        foreach ($rows as $row) {
            $admins[] = [
                'id' => (int) $row->id,
                'username' => $row->username,
                'last_access' => (int) $row->lastaccess,
                'last_login' => (int) $row->lastlogin,
                'suspended' => (bool) $row->suspended,
            ];
        }
        return [
            'count' => count($admins),
            'admins' => $admins,
            'last_changed' => self::collect_admins_last_changed(),
        ];
    }

    /**
     * When did the siteadmins configuration value last change?
     *
     * Reads from mdl_config_log; the row records both the previous and new
     * comma-separated user-ID lists, so a dashboard can compute the diff.
     *
     * @return array
     */
    protected static function collect_admins_last_changed(): array {
        global $DB;

        $row = $DB->get_record_sql(
            "SELECT timemodified, userid, oldvalue, value
               FROM {config_log}
              WHERE name = 'siteadmins' AND plugin IS NULL
              ORDER BY timemodified DESC, id DESC",
            null,
            IGNORE_MULTIPLE
        );
        if (!$row) {
            return [
                'time' => null,
                'userid' => null,
                'oldvalue' => null,
                'newvalue' => null,
            ];
        }
        return [
            'time' => (int) $row->timemodified,
            'userid' => (int) $row->userid,
            'oldvalue' => $row->oldvalue,
            'newvalue' => $row->value,
        ];
    }

    /**
     * Common production foot-guns. True values are suspicious on a live site.
     *
     * @return array
     */
    protected static function collect_footgun_flags(): array {
        global $CFG;

        return [
            'debug' => isset($CFG->debug) ? (int) $CFG->debug : null,
            'debugdisplay' => !empty($CFG->debugdisplay),
            'themedesignermode' => !empty($CFG->themedesignermode),
            'cachejs_disabled' => isset($CFG->cachejs) ? !$CFG->cachejs : false,
            'perfdebug' => isset($CFG->perfdebug) ? (int) $CFG->perfdebug : null,
        ];
    }
}
