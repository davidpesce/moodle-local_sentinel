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
 * Adhoc task: on-demand core file integrity scan.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\task;

use core\task\adhoc_task;
use local_sentinel\integrity_scanner;

/**
 * One-shot scan queued by the request_integrity_scan external function
 * (the dashboard's "Run audit now" button). Runs on the next cron tick.
 */
class integrity_scan_adhoc extends adhoc_task {
    /**
     * Get name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_integrity_scan_adhoc', 'local_sentinel');
    }

    /**
     * Run the scan via the shared orchestrator.
     */
    public function execute(): void {
        integrity_scanner::run();
    }
}
