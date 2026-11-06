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
 * Daily refresh of the cached expensive report checks.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\task;

use core\task\scheduled_task;
use local_sentinel\collectors\reports;

/**
 * Recompute the report checks that are too expensive for every snapshot.
 *
 * See reports::CACHED_CHECKS. Runs once a day at a random time per site (the
 * 'R' hour/minute in db/tasks.php), so a fleet sharing one database server
 * never runs the schema and environment checks at the same moment. Snapshots
 * between runs report the cached result.
 */
class refresh_checks extends scheduled_task {
    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_refresh_checks', 'local_sentinel');
    }

    /**
     * Refresh the cached checks.
     */
    public function execute(): void {
        $refreshed = reports::refresh_cached_checks();
        if (!$refreshed) {
            mtrace('local_sentinel: no cached checks yet (no snapshot collected), skipping.');
            return;
        }
        mtrace('local_sentinel: refreshed cached checks: ' . implode(', ', $refreshed) . '.');
    }
}
