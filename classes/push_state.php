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
 * Push self-monitoring state tracker.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Plain-data accessor for the per-site push pipeline state.
 *
 * Stored as a JSON blob in `local_sentinel/push_state` config so a fresh
 * install starts at zero/null without needing an install.xml table.
 *
 * The push task calls record_attempt / record_success / record_failure at
 * the appropriate points in its execute() loop; the snapshot collector
 * exposes get() under `health.push_state` so both the in-plugin UI and the
 * central dashboard can render the same data.
 */
class push_state {
    /** Config key under the local_sentinel plugin scope. */
    private const KEY = 'push_state';

    /** Status: never attempted. */
    public const STATUS_NEVER = 'never';

    /** Status: most recent attempt succeeded. */
    public const STATUS_SUCCESS = 'success';

    /** Status: most recent attempt failed. */
    public const STATUS_FAILED = 'failed';

    /**
     * Default shape for a never-pushed site.
     *
     * @return array
     */
    protected static function defaults(): array {
        return [
            'last_attempt_at' => 0,
            'last_success_at' => 0,
            'last_failure_at' => 0,
            'last_status' => self::STATUS_NEVER,
            'last_http_status' => 0,
            'last_error' => '',
            'consecutive_failures' => 0,
            'total_attempts' => 0,
            'total_successes' => 0,
        ];
    }

    /**
     * Return the current push state, falling back to the empty shape.
     *
     * @return array
     */
    public static function get(): array {
        $raw = get_config('local_sentinel', self::KEY);
        if ($raw === false || $raw === '') {
            return self::defaults();
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return self::defaults();
        }
        // Merge with defaults so older stored shapes don't break if we add fields later.
        return $decoded + self::defaults();
    }

    /**
     * Persist a new state.
     *
     * @param array $state
     */
    protected static function put(array $state): void {
        set_config(self::KEY, json_encode($state), 'local_sentinel');
        // Snapshot cache may hold an older state; drop it so the next overview
        // render and the next pull-WS response carry the latest push outcome.
        cache_helper::purge();
    }

    /**
     * Increment the lifetime attempt counter and stamp the attempt time.
     */
    public static function record_attempt(): void {
        $state = self::get();
        $state['total_attempts']++;
        $state['last_attempt_at'] = time();
        self::put($state);
    }

    /**
     * Mark the most recent attempt as a success.
     *
     * @param int $httpstatus HTTP status code returned by the dashboard.
     */
    public static function record_success(int $httpstatus): void {
        $state = self::get();
        $state['last_success_at'] = time();
        $state['last_status'] = self::STATUS_SUCCESS;
        $state['last_http_status'] = $httpstatus;
        $state['last_error'] = '';
        $state['consecutive_failures'] = 0;
        $state['total_successes']++;
        self::put($state);
    }

    /**
     * Mark the most recent attempt as a failure.
     *
     * @param string $error      Short human-readable error message.
     * @param int    $httpstatus HTTP status (0 if no response was received).
     */
    public static function record_failure(string $error, int $httpstatus = 0): void {
        $state = self::get();
        $state['last_failure_at'] = time();
        $state['last_status'] = self::STATUS_FAILED;
        $state['last_http_status'] = $httpstatus;
        // Trim noisy bodies — we only need the first line of the error.
        $trimmed = trim($error);
        if (strlen($trimmed) > 500) {
            $trimmed = substr($trimmed, 0, 497) . '...';
        }
        $state['last_error'] = $trimmed;
        $state['consecutive_failures']++;
        self::put($state);
    }

    /**
     * Clear all push state (used by tests and the future Reset button).
     */
    public static function reset(): void {
        self::put(self::defaults());
    }
}
