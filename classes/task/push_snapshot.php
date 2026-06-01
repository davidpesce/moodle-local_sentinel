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
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\task;

use core\task\scheduled_task;
use local_sentinel\collector;
use local_sentinel\push_state;

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
        return get_string('task_push_snapshot', 'local_sentinel');
    }

    /**
     * Build the snapshot, POST it to the configured endpoint, log the outcome.
     */
    public function execute(): void {
        $endpoint = trim((string) get_config('local_sentinel', 'pushendpoint'));
        $secret = (string) get_config('local_sentinel', 'pushsecret');
        $enabled = (bool) get_config('local_sentinel', 'pushenabled');

        if (!$enabled) {
            mtrace('local_sentinel: push disabled in settings, skipping.');
            return;
        }
        if ($endpoint === '') {
            mtrace('local_sentinel: pushendpoint not configured, skipping.');
            return;
        }
        if ($secret === '') {
            mtrace('local_sentinel: pushsecret not configured, refusing to push.');
            return;
        }

        // Only after the gating checks pass do we count this as an attempt —
        // we don't want skipped-when-disabled runs to inflate the counter.
        push_state::record_attempt();

        $snapshot = collector::get_snapshot_for_egress();
        $body = json_encode($snapshot);

        // The Moodle curl wrapper is defined in filelib.php; ensure it's loaded
        // (a bare CLI bootstrap doesn't autoload it).
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-Sentinel-Secret: ' . $secret,
            'X-Fleetmonitor-Site: ' . $snapshot['site']['siteidentifier'],
        ]);
        $response = $curl->post($endpoint, $body);
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);

        if ($curl->get_errno() !== 0) {
            push_state::record_failure('curl: ' . $curl->error, 0);
            mtrace('local_sentinel: push failed: ' . $curl->error);
            return;
        }
        if ($httpcode < 200 || $httpcode >= 300) {
            $snippet = substr((string) $response, 0, 200);
            push_state::record_failure("HTTP $httpcode — $snippet", $httpcode);
            mtrace("local_sentinel: push got HTTP $httpcode from $endpoint");
            mtrace('local_sentinel: response body: ' . substr((string) $response, 0, 500));
            return;
        }
        push_state::record_success($httpcode);
        mtrace("local_sentinel: pushed snapshot to $endpoint (HTTP $httpcode, " . strlen($body) . ' bytes).');
    }
}
