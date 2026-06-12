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
 * Scheduled task: weekly core file integrity scan.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\task;

use core\task\scheduled_task;
use local_sentinel\integrity_scanner;

/**
 * Weekly scan of the code tree against the dashboard-provided manifest.
 *
 * Enabled by default in db/tasks.php but self-gating: it no-ops unless the
 * integrityenabled setting is on AND a manifest has been provisioned, so
 * flipping the feature on never requires task administration.
 */
class integrity_scan extends scheduled_task {
    /**
     * Get name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_integrity_scan', 'local_sentinel');
    }

    /**
     * Run the scan via the shared orchestrator.
     */
    public function execute(): void {
        integrity_scanner::run();
    }
}
