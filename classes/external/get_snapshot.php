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
 * External function: get_snapshot.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\external;

use core_external\external_single_structure;
use local_sentinel\collector;

/**
 * Returns the full monitoring snapshot.
 */
class get_snapshot extends base {
    /**
     * Return the full monitoring snapshot.
     *
     * @return array
     */
    public static function execute(): array {
        self::authorise();
        return collector::get_snapshot_for_egress();
    }

    /**
     * Declare the bundled-snapshot return shape.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::envelope_with_slices([
            'status',
            'environment',
            'plugins',
            'health',
            'auth',
            'reports',
            'config_changes',
            'config_drift',
            'integrity',
        ]);
    }
}
