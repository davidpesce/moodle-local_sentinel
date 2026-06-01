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
 * Self-registration state tracker.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Plain-data accessor for the per-site self-registration state.
 *
 * Stored as a JSON blob in `local_sentinel/registration_state` config so a
 * fresh install starts at "never" without needing an install.xml table — the
 * same pattern as {@see push_state}. The Connect page reads get() to show the
 * site admin where their registration stands (pending approval, activated,
 * rejected, or failed).
 */
class registration_state {
    /** Config key under the local_sentinel plugin scope. */
    private const KEY = 'registration_state';

    /** Status: never attempted. */
    public const STATUS_NEVER = 'never';

    /** Status: registered, awaiting operator approval. */
    public const STATUS_PENDING = 'pending';

    /** Status: approved — the site is now an instance on the dashboard. */
    public const STATUS_ACTIVATED = 'activated';

    /** Status: the dashboard operator rejected this registration. */
    public const STATUS_REJECTED = 'rejected';

    /** Status: the last attempt failed (transport, bad key, or unexpected). */
    public const STATUS_FAILED = 'failed';

    /**
     * Default shape for a never-registered site.
     *
     * @return array
     */
    protected static function defaults(): array {
        return [
            'last_attempt_at' => 0,
            'last_status' => self::STATUS_NEVER,
            'last_http_status' => 0,
            'last_error' => '',
            'registered_at' => 0,
            'registered_siteidentifier' => '',
        ];
    }

    /**
     * Return the current registration state, falling back to the empty shape.
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
     * Persist a new state and drop the snapshot cache.
     *
     * @param array $state
     */
    protected static function put(array $state): void {
        set_config(self::KEY, json_encode($state), 'local_sentinel');
        cache_helper::purge();
    }

    /**
     * Stamp the attempt time (called just before the POST).
     */
    public static function record_attempt(): void {
        $state = self::get();
        $state['last_attempt_at'] = time();
        self::put($state);
    }

    /**
     * Record a recognised dashboard response (pending / activated / rejected).
     *
     * @param string $status         One of the STATUS_* constants.
     * @param int    $httpstatus      HTTP status returned by the dashboard.
     * @param string $siteidentifier  Stamped on activation for reference.
     */
    public static function record_result(string $status, int $httpstatus, string $siteidentifier = ''): void {
        $state = self::get();
        $state['last_status'] = $status;
        $state['last_http_status'] = $httpstatus;
        $state['last_error'] = '';
        if ($status === self::STATUS_ACTIVATED) {
            $state['registered_at'] = time();
            $state['registered_siteidentifier'] = $siteidentifier;
        }
        self::put($state);
    }

    /**
     * Record a failed attempt (transport error, bad key, queue full, etc.).
     *
     * @param string $error      Short human-readable error message.
     * @param int    $httpstatus HTTP status (0 if no response was received).
     */
    public static function record_failure(string $error, int $httpstatus = 0): void {
        $state = self::get();
        $state['last_status'] = self::STATUS_FAILED;
        $state['last_http_status'] = $httpstatus;
        $trimmed = trim($error);
        if (strlen($trimmed) > 500) {
            $trimmed = substr($trimmed, 0, 497) . '...';
        }
        $state['last_error'] = $trimmed;
        self::put($state);
    }

    /**
     * Clear all registration state (used by tests).
     */
    public static function reset(): void {
        self::put(self::defaults());
    }
}
