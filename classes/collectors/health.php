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
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_fleetmonitor\collectors;

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
            'disk' => self::collect_disk(),
            'mail' => self::collect_mail(),
            'admins' => self::collect_admins(),
            'backup' => self::collect_backup(),
            'upgrade_log' => self::collect_upgrade_log(),
            'flags' => self::collect_footgun_flags(),
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
        $lastcron = (int) get_config('core', 'lastcron');
        $now = time();
        return [
            'last_run' => $lastcron,
            'seconds_since_last_run' => $lastcron > 0 ? $now - $lastcron : null,
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

        $failed = $DB->get_records_select(
            'task_scheduled',
            'faildelay > 0',
            null,
            'classname ASC',
            'id, classname, lastruntime, faildelay, disabled'
        );
        $failedlist = [];
        foreach ($failed as $row) {
            $failedlist[] = [
                'classname' => $row->classname,
                'last_run' => (int) $row->lastruntime,
                'faildelay' => (int) $row->faildelay,
                'disabled' => (bool) $row->disabled,
            ];
        }

        $adhoctotal = (int) $DB->count_records('task_adhoc');
        $oldest = $DB->get_field_sql(
            'SELECT MIN(nextruntime) FROM {task_adhoc} WHERE nextruntime > 0'
        );

        return [
            'scheduled_failed_count' => count($failedlist),
            'scheduled_failed' => $failedlist,
            'adhoc_queue_depth' => $adhoctotal,
            'adhoc_oldest_nextruntime' => $oldest ? (int) $oldest : null,
        ];
    }

    /**
     * Collect sessions.
     *
     * @return array
     */
    protected static function collect_sessions(): array {
        global $DB;

        $now = time();
        return [
            'active_last_5_min' => (int) $DB->count_records_select(
                'sessions',
                'timemodified > :since',
                ['since' => $now - 300]
            ),
            'active_last_hour' => (int) $DB->count_records_select(
                'sessions',
                'timemodified > :since',
                ['since' => $now - 3600]
            ),
            'total_rows' => (int) $DB->count_records('sessions'),
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
