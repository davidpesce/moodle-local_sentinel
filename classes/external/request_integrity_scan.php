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
 * External function: request_integrity_scan.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\external;

use core_external\external_single_structure;
use core_external\external_value;
use local_sentinel\manifest_store;
use local_sentinel\task\integrity_scan_adhoc;

/**
 * Queues an on-demand integrity scan (the dashboard's "Run audit now").
 *
 * The scan itself runs as an adhoc task on the site's cron — hashing the
 * full tree takes tens of seconds and must never run inside a WS request.
 * Results arrive with the next snapshot after cron has processed the task.
 */
class request_integrity_scan extends base {
    /**
     * Queue the adhoc scan task if the feature is usable.
     *
     * @return array queued / reason.
     */
    public static function execute(): array {
        self::authorise_manage();

        if (!(bool) get_config('local_sentinel', 'integrityenabled')) {
            return [
                'queued' => false,
                'reason' => 'integrity scanning is disabled in the plugin settings',
            ];
        }
        if (manifest_store::load_meta() === null) {
            return [
                'queued' => false,
                'reason' => 'no manifest stored — call local_sentinel_set_manifest first',
            ];
        }
        // Queue with checkforexisting=true so repeated clicks coalesce into one scan.
        \core\task\manager::queue_adhoc_task(new integrity_scan_adhoc(), true);
        return [
            'queued' => true,
            'reason' => '',
        ];
    }

    /**
     * Declare the return shape.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'queued' => new external_value(PARAM_BOOL, 'Whether a scan was queued (or already pending).'),
            'reason' => new external_value(PARAM_RAW, 'Why the scan was not queued (empty on success).'),
        ]);
    }
}
