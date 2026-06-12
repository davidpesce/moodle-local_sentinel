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
 * Integrity-scan self-monitoring state tracker.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Plain-data accessor for the core-integrity pipeline state.
 *
 * Stored as a JSON blob in `local_sentinel/integrity_state` config (same
 * pattern as {@see push_state}) so a fresh install starts at zero/null
 * without needing an install.xml table. Holds only the compact summary —
 * the manifest itself and the full deviation lists live in dataroot files
 * via {@see manifest_store}.
 */
class integrity_state {
    /** Config key under the local_sentinel plugin scope. */
    private const KEY = 'integrity_state';

    /** Scan status: never run. */
    public const STATUS_NEVER = 'never';

    /** Scan status: most recent scan completed. */
    public const STATUS_OK = 'ok';

    /** Scan status: most recent scan failed. */
    public const STATUS_ERROR = 'error';

    /**
     * Default shape for a site that never received a manifest or ran a scan.
     *
     * @return array
     */
    protected static function defaults(): array {
        return [
            'manifest_version' => '',
            'manifest_digest' => '',
            'manifest_received_at' => 0,
            'last_scan_status' => self::STATUS_NEVER,
            'last_scan_at' => 0,
            'last_scan_duration' => 0,
            'last_scan_manifest_version' => '',
            'files_scanned' => 0,
            'modified_count' => 0,
            'missing_count' => 0,
            'unexpected_count' => 0,
            'last_error' => '',
        ];
    }

    /**
     * Return the current integrity state, falling back to the empty shape.
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
        // render and the next pull-WS response carry the latest scan outcome.
        cache_helper::purge();
    }

    /**
     * Record receipt of a new reference manifest from the dashboard.
     *
     * @param string $version Literal Moodle $version decimal string.
     * @param string $digest  SHA-256 hex of the manifest text.
     */
    public static function record_manifest(string $version, string $digest): void {
        $state = self::get();
        $state['manifest_version'] = $version;
        $state['manifest_digest'] = $digest;
        $state['manifest_received_at'] = time();
        self::put($state);
    }

    /**
     * Record a completed scan from its result array.
     *
     * @param array $result Output of {@see integrity_scanner::scan()} with status 'ok'.
     */
    public static function record_scan_ok(array $result): void {
        $state = self::get();
        $state['last_scan_status'] = self::STATUS_OK;
        $state['last_scan_at'] = (int) ($result['scanned_at'] ?? time());
        $state['last_scan_duration'] = (int) ($result['duration_seconds'] ?? 0);
        $state['last_scan_manifest_version'] = (string) ($result['manifest_version'] ?? '');
        $state['files_scanned'] = (int) ($result['files_scanned'] ?? 0);
        $state['modified_count'] = (int) ($result['modified_count'] ?? 0);
        $state['missing_count'] = (int) ($result['missing_count'] ?? 0);
        $state['unexpected_count'] = (int) ($result['unexpected_count'] ?? 0);
        $state['last_error'] = '';
        self::put($state);
    }

    /**
     * Record a failed scan.
     *
     * @param string $error Short human-readable error message.
     */
    public static function record_scan_error(string $error): void {
        $state = self::get();
        $state['last_scan_status'] = self::STATUS_ERROR;
        $state['last_scan_at'] = time();
        $trimmed = trim($error);
        if (strlen($trimmed) > 500) {
            $trimmed = substr($trimmed, 0, 497) . '...';
        }
        $state['last_error'] = $trimmed;
        self::put($state);
    }

    /**
     * Clear all integrity state (used by tests and the future Reset button).
     */
    public static function reset(): void {
        self::put(self::defaults());
    }
}
