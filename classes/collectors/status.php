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
 * Status collector: cheap liveness data.
 *
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_fleetmonitor\collectors;

/**
 * Returns the minimum data needed to answer "is this site up and which Moodle is it?".
 *
 * Kept deliberately cheap — no DB scans, no expensive Moodle API calls — so it can be
 * polled at high frequency by a fleet dashboard.
 */
class status {
    /**
     * Branch number => security-support end date (ISO 8601).
     *
     * Source: https://moodledev.io/general/releases — security end dates for each
     * supported branch. Bump this map when a new Moodle major release ships.
     */
    private const BRANCH_EOL = [
        401 => '2025-12-08',
        402 => '2023-12-11',
        403 => '2025-04-14',
        404 => '2025-10-13',
        405 => '2027-12-13',
        500 => '2026-10-12',
        501 => '2027-04-12',
    ];

    /**
     * Collect.
     *
     * @return array
     */
    public static function collect(): array {
        global $CFG;

        return [
            'version' => (int) $CFG->version,
            'branch' => (int) $CFG->branch,
            'release' => $CFG->release,
            'maintenance_enabled' => !empty($CFG->maintenance_enabled),
            'maintenance_message' => isset($CFG->maintenance_message) ? (string) $CFG->maintenance_message : '',
            'branch_eol_date' => self::branch_eol_date((int) $CFG->branch),
            'branch_eol_days_remaining' => self::branch_eol_days_remaining((int) $CFG->branch),
            'build_age_days' => self::build_age_days((int) $CFG->version),
        ];
    }

    /**
     * EOL date string for a branch, or null if unknown.
     *
     * @param int $branch
     * @return string|null
     */
    protected static function branch_eol_date(int $branch): ?string {
        return self::BRANCH_EOL[$branch] ?? null;
    }

    /**
     * Days remaining until security support ends. Negative if already past EOL.
     *
     * @param int $branch
     * @return int|null
     */
    protected static function branch_eol_days_remaining(int $branch): ?int {
        $date = self::branch_eol_date($branch);
        if ($date === null) {
            return null;
        }
        $eol = strtotime($date . ' 23:59:59 UTC');
        if ($eol === false) {
            return null;
        }
        return (int) floor(($eol - time()) / 86400);
    }

    /**
     * Days since the build encoded in $CFG->version (YYYYMMDDXX).
     *
     * @param int $version
     * @return int|null
     */
    protected static function build_age_days(int $version): ?int {
        $str = (string) $version;
        if (strlen($str) < 8) {
            return null;
        }
        $year = (int) substr($str, 0, 4);
        $month = (int) substr($str, 4, 2);
        $day = (int) substr($str, 6, 2);
        $build = gmmktime(0, 0, 0, $month, $day, $year);
        if ($build === false) {
            return null;
        }
        return (int) floor((time() - $build) / 86400);
    }
}
