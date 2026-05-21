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

defined('MOODLE_INTERNAL') || die();

/**
 * Returns the minimum data needed to answer "is this site up and which Moodle is it?".
 *
 * Kept deliberately cheap — no DB scans, no expensive Moodle API calls — so it can be
 * polled at high frequency by a fleet dashboard.
 */
class status {

    /**
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
        ];
    }
}
