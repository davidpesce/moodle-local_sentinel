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
 * Collector smoke tests.
 *
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_fleetmonitor;

/**
 * Verifies each collector returns the expected top-level keys.
 *
 * These are smoke tests: they don't pin specific values (those depend on the
 * host environment), they just ensure the shape stays stable.
 *
 * @covers \local_fleetmonitor\collector
 */
final class collector_test extends \advanced_testcase {
    public function test_snapshot_envelope(): void {
        $this->resetAfterTest();

        $snapshot = collector::get_snapshot();

        $this->assertSame(collector::SCHEMA_VERSION, $snapshot['schema_version']);
        $this->assertNotEmpty($snapshot['generated_at']);
        $this->assertArrayHasKey('site', $snapshot);
        $this->assertArrayHasKey('status', $snapshot);
        $this->assertArrayHasKey('environment', $snapshot);
        $this->assertArrayHasKey('plugins', $snapshot);
        $this->assertArrayHasKey('health', $snapshot);
        $this->assertArrayHasKey('config_changes', $snapshot);
    }

    public function test_site_identity_uses_siteidentifier(): void {
        global $CFG;
        $this->resetAfterTest();

        $identity = collector::get_site_identity();

        $this->assertSame($CFG->wwwroot, $identity['wwwroot']);
        $this->assertSame($CFG->siteidentifier, $identity['siteidentifier']);
        $this->assertArrayHasKey('sitename', $identity);
    }

    public function test_status_keys(): void {
        $this->resetAfterTest();

        $status = collectors\status::collect();

        $this->assertArrayHasKey('version', $status);
        $this->assertArrayHasKey('branch', $status);
        $this->assertArrayHasKey('release', $status);
        $this->assertArrayHasKey('maintenance_enabled', $status);
        $this->assertIsBool($status['maintenance_enabled']);
    }

    public function test_environment_keys(): void {
        $this->resetAfterTest();

        $env = collectors\environment::collect();

        $this->assertArrayHasKey('php', $env);
        $this->assertArrayHasKey('os', $env);
        $this->assertArrayHasKey('webserver', $env);
        $this->assertArrayHasKey('database', $env);
        $this->assertArrayHasKey('opcache', $env);
        $this->assertArrayHasKey('extensions', $env);
        $this->assertSame(PHP_VERSION, $env['php']['version']);
        $this->assertSame(PHP_SAPI, $env['php']['sapi']);
        $this->assertIsArray($env['extensions']);
    }

    public function test_plugins_keys(): void {
        $this->resetAfterTest();

        $plugins = collectors\plugins::collect();

        $this->assertArrayHasKey('standard', $plugins);
        $this->assertArrayHasKey('third_party', $plugins);
        $this->assertArrayHasKey('updates_available', $plugins);
        $this->assertArrayHasKey('theme', $plugins);
        $this->assertNotEmpty($plugins['standard']);
    }

    public function test_health_keys(): void {
        $this->resetAfterTest();

        $health = collectors\health::collect();

        $this->assertArrayHasKey('cron', $health);
        $this->assertArrayHasKey('tasks', $health);
        $this->assertArrayHasKey('sessions', $health);
        $this->assertArrayHasKey('disk', $health);
        $this->assertArrayHasKey('mail', $health);
        $this->assertArrayHasKey('admins', $health);
        $this->assertArrayHasKey('flags', $health);
    }

    public function test_config_changes_respects_limit(): void {
        $this->resetAfterTest();

        $result = collectors\config_changes::collect(3);

        $this->assertSame(3, $result['limit']);
        $this->assertLessThanOrEqual(3, $result['count']);
        $this->assertCount($result['count'], $result['entries']);
    }

    public function test_get_slice_returns_only_requested_section(): void {
        $this->resetAfterTest();

        $snapshot = collector::get_slice('status');

        $this->assertArrayHasKey('site', $snapshot);
        $this->assertArrayHasKey('status', $snapshot);
        $this->assertArrayNotHasKey('environment', $snapshot);
        $this->assertArrayNotHasKey('plugins', $snapshot);
    }
}
