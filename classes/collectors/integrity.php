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
 * Integrity slice collector.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\collectors;

use local_sentinel\integrity_scanner;
use local_sentinel\integrity_state;
use local_sentinel\manifest_store;

/**
 * Core file integrity slice: latest scan outcome + deviations.
 *
 * Cheap by design — reads plugin config and one small JSON file; never
 * hashes anything. The scan itself runs in the integrity_scan task. A
 * clean connected site reports zero counts and empty lists, so the slice
 * adds only a few hundred bytes to every snapshot.
 *
 * `core_version_full` is the literal $version decimal string (e.g.
 * "2024100711.04") — weekly builds bump only the decimals, so the integer
 * in the status slice cannot select the right manifest.
 */
class integrity {
    /**
     * Collect the integrity slice.
     *
     * @return array
     */
    public static function collect(): array {
        global $CFG;
        $state = integrity_state::get();
        $result = manifest_store::load_scan_result() ?? [];

        $manifest = null;
        if ($state['manifest_version'] !== '') {
            $manifest = [
                'version' => (string) $state['manifest_version'],
                'digest' => (string) $state['manifest_digest'],
                'received_at' => (int) $state['manifest_received_at'],
            ];
        }

        return [
            'enabled' => (bool) get_config('local_sentinel', 'integrityenabled'),
            'core_version_full' => (string) $CFG->version,
            'manifest' => $manifest,
            'last_scan' => [
                'status' => (string) $state['last_scan_status'],
                'scanned_at' => (int) $state['last_scan_at'],
                'duration_seconds' => (int) $state['last_scan_duration'],
                'manifest_version' => (string) $state['last_scan_manifest_version'],
                'manifest_version_mismatch' => (bool) ($result['manifest_version_mismatch'] ?? false),
                'files_scanned' => (int) $state['files_scanned'],
                'error' => (string) $state['last_error'],
            ],
            'modified_count' => (int) $state['modified_count'],
            'missing_count' => (int) $state['missing_count'],
            'unexpected_count' => (int) $state['unexpected_count'],
            'modified' => array_slice($result['modified'] ?? [], 0, integrity_scanner::MAX_DEVIATIONS),
            'missing' => array_slice($result['missing'] ?? [], 0, integrity_scanner::MAX_DEVIATIONS),
            'unexpected' => array_slice($result['unexpected'] ?? [], 0, integrity_scanner::MAX_DEVIATIONS),
            'modified_overflow' => (int) ($result['modified_overflow'] ?? 0),
            'missing_overflow' => (int) ($result['missing_overflow'] ?? 0),
            'unexpected_overflow' => (int) ($result['unexpected_overflow'] ?? 0),
            'scan_errors' => array_values(array_map('strval', $result['errors'] ?? [])),
        ];
    }
}
