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
 * Shared base for fleet monitor external functions.
 *
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_fleetmonitor\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides the standard envelope return shape and a permission check helper.
 *
 * Each concrete subclass implements execute() and calls authorise() first,
 * then returns the result of envelope().
 */
abstract class base extends external_api {

    /**
     * Default: no parameters. Subclasses that need params override this.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * All snapshot endpoints return the same envelope shape.
     *
     * The actual data lives in the JSON-encoded `payload` field so the snapshot
     * shape can evolve without breaking the web service contract. Bump
     * collector::SCHEMA_VERSION when making breaking shape changes.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'schema_version' => new external_value(PARAM_INT, 'Snapshot schema version.'),
            'generated_at' => new external_value(PARAM_TEXT, 'ISO 8601 UTC timestamp.'),
            'payload' => new external_value(PARAM_RAW, 'JSON-encoded snapshot data.'),
        ]);
    }

    /**
     * Validates the system context and requires the fleet monitor capability.
     */
    protected static function authorise(): void {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/fleetmonitor:view', $context);
    }

    /**
     * Wrap snapshot data into the WS return envelope.
     *
     * @param array $snapshot
     * @return array
     */
    protected static function envelope(array $snapshot): array {
        return [
            'schema_version' => (int) $snapshot['schema_version'],
            'generated_at' => (string) $snapshot['generated_at'],
            'payload' => json_encode($snapshot),
        ];
    }
}
