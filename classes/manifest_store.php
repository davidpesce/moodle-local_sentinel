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
 * Dataroot persistence for the core-integrity manifest and scan results.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Stores the dashboard-provided reference manifest and the latest scan result.
 *
 * Both artefacts are too large for config_plugins (the manifest is ~3 MB,
 * one line per core file), so they live as files under
 * $CFG->dataroot/local_sentinel/. Compact summary state lives in plugin
 * config via {@see integrity_state} so the snapshot collector never has to
 * touch these files on the hot path except for the (small) scan result.
 */
class manifest_store {
    /** Manifest line shape: 40-hex git blob sha1, tab, path. */
    public const LINE_REGEX = '/^[0-9a-f]{40}\t.+/';

    /**
     * Directory holding the integrity artefacts, created on demand.
     *
     * @return string
     */
    public static function dir(): string {
        global $CFG;
        $dir = $CFG->dataroot . '/local_sentinel';
        make_writable_directory($dir);
        return $dir;
    }

    /**
     * Persist a manifest received from the dashboard.
     *
     * @param string $version Literal Moodle $version decimal string the manifest is for.
     * @param string $digest  SHA-256 hex of the manifest text.
     * @param string $text    Raw manifest text ("<sha1>\t<path>\n" lines).
     * @return int Number of manifest lines stored.
     */
    public static function save_manifest(string $version, string $digest, string $text): int {
        $dir = self::dir();
        file_put_contents($dir . '/manifest.txt', $text);
        $lines = substr_count($text, "\n");
        $meta = [
            'version' => $version,
            'digest' => $digest,
            'received_at' => time(),
            'line_count' => $lines,
        ];
        file_put_contents($dir . '/manifest_meta.json', json_encode($meta));
        return $lines;
    }

    /**
     * Metadata about the stored manifest, or null when none exists.
     *
     * @return array|null Keys: version, digest, received_at, line_count.
     */
    public static function load_meta(): ?array {
        $path = self::dir() . '/manifest_meta.json';
        if (!is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['version'])) {
            return null;
        }
        return $decoded;
    }

    /**
     * Parse the stored manifest into a path => sha1 map.
     *
     * @return array|null Null when no manifest is stored or it is unreadable.
     */
    public static function load_manifest_map(): ?array {
        $path = self::dir() . '/manifest.txt';
        if (!is_readable($path)) {
            return null;
        }
        $map = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $tab = strpos($line, "\t");
            if ($tab !== 40) {
                continue; // Malformed line; ignore defensively.
            }
            $map[substr($line, 41)] = substr($line, 0, 40);
        }
        fclose($handle);
        return $map;
    }

    /**
     * Persist the most recent scan result (capped deviation lists included).
     *
     * @param array $result Output of {@see integrity_scanner::scan()}.
     */
    public static function save_scan_result(array $result): void {
        file_put_contents(self::dir() . '/scan_result.json', json_encode($result));
        // The snapshot collector reads this file; drop the snapshot cache so
        // the next overview render / WS pull reflects the new scan.
        cache_helper::purge();
    }

    /**
     * Load the most recent scan result, or null when no scan has completed.
     *
     * @return array|null
     */
    public static function load_scan_result(): ?array {
        $path = self::dir() . '/scan_result.json';
        if (!is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Remove all stored artefacts (tests and future Reset support).
     */
    public static function reset(): void {
        foreach (['manifest.txt', 'manifest_meta.json', 'scan_result.json'] as $file) {
            $path = self::dir() . '/' . $file;
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
}
