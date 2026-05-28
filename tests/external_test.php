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
 * External function smoke tests.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

use core_external\external_api;

/**
 * Exercises each external function end-to-end and validates the response
 * against its declared structure via clean_returnvalue().
 *
 * clean_returnvalue() is what the WS framework calls in production. Running
 * it in the test layer catches collector output that drifts from the
 * declared shape before it can ship.
 *
 * @covers \local_sentinel\external\get_status
 * @covers \local_sentinel\external\get_snapshot
 * @covers \local_sentinel\external\get_environment
 * @covers \local_sentinel\external\get_plugins
 * @covers \local_sentinel\external\get_health
 * @covers \local_sentinel\external\get_auth
 * @covers \local_sentinel\external\get_reports
 * @covers \local_sentinel\external\get_config_changes
 * @covers \local_sentinel\external\get_config_drift
 */
final class external_test extends \advanced_testcase {
    /**
     * Provider yielding each external function class for parametrised tests.
     *
     * @return array<string, array{0: class-string}>
     */
    public static function endpoint_provider(): array {
        return [
            'get_status' => [external\get_status::class],
            'get_snapshot' => [external\get_snapshot::class],
            'get_environment' => [external\get_environment::class],
            'get_plugins' => [external\get_plugins::class],
            'get_health' => [external\get_health::class],
            'get_auth' => [external\get_auth::class],
            'get_reports' => [external\get_reports::class],
            'get_config_changes' => [external\get_config_changes::class],
            'get_config_drift' => [external\get_config_drift::class],
        ];
    }

    /**
     * Every external function's actual output must validate against its declared structure.
     *
     * @dataProvider endpoint_provider
     * @param class-string $class External function class under test.
     */
    public function test_response_validates_against_declared_structure(string $class): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = $class::execute();
        $cleaned = external_api::clean_returnvalue($class::execute_returns(), $result);

        $this->assertIsArray($cleaned);
        $this->assertSame(collector::SCHEMA_VERSION, $cleaned['schema_version']);
        $this->assertNotEmpty($cleaned['generated_at']);
        $this->assertArrayHasKey('site', $cleaned);
    }

    public function test_get_snapshot_has_all_slices(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = external\get_snapshot::execute();
        $cleaned = external_api::clean_returnvalue(external\get_snapshot::execute_returns(), $result);

        $expected = [
            'status', 'environment', 'plugins', 'health',
            'auth', 'reports', 'config_changes', 'config_drift',
        ];
        foreach ($expected as $slice) {
            $this->assertArrayHasKey($slice, $cleaned, "Snapshot missing slice: $slice");
        }
    }

    public function test_authorisation_required(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        external\get_status::execute();
    }
}
