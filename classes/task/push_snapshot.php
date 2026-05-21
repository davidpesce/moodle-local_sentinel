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
 * Scheduled task that POSTs a snapshot to the central collector.
 *
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_fleetmonitor\task;

use core\task\scheduled_task;
use local_fleetmonitor\collector;

/**
 * Push the current snapshot to the configured central collector.
 *
 * Disabled by default — flip on in plugin settings after configuring
 * the endpoint URL and shared secret. Intended for new-client evaluation
 * and instances that cannot be polled inbound.
 */
class push_snapshot extends scheduled_task {
    /**
     * Get name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_push_snapshot', 'local_fleetmonitor');
    }

    /**
     * Build the snapshot, POST it to the configured endpoint, log the outcome.
     */
    public function execute(): void {
        $endpoint = trim((string) get_config('local_fleetmonitor', 'pushendpoint'));
        $secret = (string) get_config('local_fleetmonitor', 'pushsecret');
        $enabled = (bool) get_config('local_fleetmonitor', 'pushenabled');

        if (!$enabled) {
            mtrace('local_fleetmonitor: push disabled in settings, skipping.');
            return;
        }
        if ($endpoint === '') {
            mtrace('local_fleetmonitor: pushendpoint not configured, skipping.');
            return;
        }
        if ($secret === '') {
            mtrace('local_fleetmonitor: pushsecret not configured, refusing to push.');
            return;
        }

        $snapshot = collector::get_snapshot();
        $body = json_encode($snapshot);

        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-Fleetmonitor-Secret: ' . $secret,
            'X-Fleetmonitor-Site: ' . $snapshot['site']['siteidentifier'],
        ]);
        $response = $curl->post($endpoint, $body);
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);

        if ($curl->get_errno() !== 0) {
            mtrace('local_fleetmonitor: push failed: ' . $curl->error);
            return;
        }
        if ($httpcode < 200 || $httpcode >= 300) {
            mtrace("local_fleetmonitor: push got HTTP $httpcode from $endpoint");
            mtrace('local_fleetmonitor: response body: ' . substr((string) $response, 0, 500));
            return;
        }
        mtrace("local_fleetmonitor: pushed snapshot to $endpoint (HTTP $httpcode, " . strlen($body) . ' bytes).');
    }
}
