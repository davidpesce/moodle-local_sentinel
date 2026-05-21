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
 * Auth methods collector.
 *
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_fleetmonitor\collectors;

/**
 * Enabled authentication plugins and user counts per method.
 */
class auth {
    /**
     * Collect.
     *
     * @return array
     */
    public static function collect(): array {
        global $CFG, $DB;

        $enabled = array_values(array_filter(array_map('trim', explode(',', (string) $CFG->auth))));
        $methods = [];
        foreach ($enabled as $method) {
            $methods[] = [
                'plugin' => $method,
                'total_users' => (int) $DB->count_records('user', [
                    'auth' => $method,
                    'deleted' => 0,
                ]),
                'active_users' => (int) $DB->count_records('user', [
                    'auth' => $method,
                    'deleted' => 0,
                    'suspended' => 0,
                ]),
            ];
        }

        $allmethods = (int) $DB->count_records_select('user', 'deleted = 0', null, 'COUNT(DISTINCT auth)');

        return [
            'enabled' => $enabled,
            'methods' => $methods,
            'distinct_methods_in_use' => $allmethods,
        ];
    }
}
