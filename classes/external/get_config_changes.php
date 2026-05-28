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
 * External function: get_config_changes.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\external;

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_sentinel\collector;
use local_sentinel\collectors\config_changes;

/**
 * Returns just the config_changes slice. Accepts an optional row limit.
 */
class get_config_changes extends base {
    /**
     * Accept an optional row limit (1-500, default 50).
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'limit' => new external_value(
                PARAM_INT,
                'Maximum entries to return (1-500).',
                VALUE_DEFAULT,
                config_changes::DEFAULT_LIMIT
            ),
        ]);
    }

    /**
     * Return the config_changes slice.
     *
     * @param int $limit
     * @return array
     */
    public static function execute(int $limit = config_changes::DEFAULT_LIMIT): array {
        self::authorise();
        [
            'limit' => $limit,
        ] = self::validate_parameters(self::execute_parameters(), ['limit' => $limit]);

        $snapshot = collector::get_slice_for_egress('config_changes');
        $snapshot['config_changes'] = config_changes::collect($limit);
        return $snapshot;
    }

    /**
     * Declare the return shape.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::envelope_with_slices(['config_changes']);
    }
}
