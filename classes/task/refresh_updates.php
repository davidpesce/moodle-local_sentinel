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
 * Scheduled task: refresh Moodle's available-updates cache.
 *
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_fleetmonitor\task;

use core\task\scheduled_task;

/**
 * Refresh Moodle's available-updates cache without emailing admins.
 *
 * Moodle's built-in auto-check task fetches AND notifies. Many operators
 * disable it to silence the email noise, which also stops the fetch and
 * leaves `core_plugin_manager::available_updates()` permanently stale.
 *
 * This task calls `\core\update\checker::fetch()` directly — same fetch,
 * no notifications. Independent of the `updateautocheck` setting.
 *
 * Respects the top-level kill switch (`disableupdatenotifications`) for
 * sites that have explicitly chosen not to phone home to moodle.org.
 */
class refresh_updates extends scheduled_task {
    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_refresh_updates', 'local_fleetmonitor');
    }

    /**
     * Fetch fresh data from moodle.org/updates if the kill switch is off.
     */
    public function execute(): void {
        $checker = \core\update\checker::instance();
        if (!$checker->enabled()) {
            mtrace('local_fleetmonitor: update notifications globally disabled '
                . '($CFG->disableupdatenotifications), skipping fetch.');
            return;
        }
        $before = (int) $checker->get_last_timefetched();
        try {
            $checker->fetch();
        } catch (\Throwable $e) {
            mtrace('local_fleetmonitor: update fetch failed: ' . $e->getMessage());
            return;
        }
        $after = (int) $checker->get_last_timefetched();
        mtrace('local_fleetmonitor: update cache refreshed (was: '
            . ($before > 0 ? date('c', $before) : 'never')
            . ', now: ' . date('c', $after) . ').');
    }
}
