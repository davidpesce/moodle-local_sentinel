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
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Verifies each collector returns the expected top-level keys.
 *
 * These are smoke tests: they don't pin specific values (those depend on the
 * host environment), they just ensure the shape stays stable.
 *
 * @covers \local_sentinel\collector
 */
final class collector_test extends \advanced_testcase {
    public function test_snapshot_envelope(): void {
        $this->resetAfterTest();

        $snapshot = collector::get_snapshot();

        $this->assertSame(collector::SCHEMA_VERSION, $snapshot['schema_version']);
        $this->assertNotEmpty($snapshot['generated_at']);
        $this->assertArrayHasKey('egress', $snapshot);
        $this->assertArrayHasKey('excluded_slices', $snapshot['egress']);
        $this->assertArrayHasKey('excluded_fields', $snapshot['egress']);
        $this->assertArrayHasKey('site', $snapshot);
        $this->assertArrayHasKey('status', $snapshot);
        $this->assertArrayHasKey('environment', $snapshot);
        $this->assertArrayHasKey('plugins', $snapshot);
        $this->assertArrayHasKey('health', $snapshot);
        $this->assertArrayHasKey('auth', $snapshot);
        $this->assertArrayHasKey('reports', $snapshot);
        $this->assertArrayHasKey('config_changes', $snapshot);
        $this->assertArrayHasKey('config_drift', $snapshot);
        $this->assertArrayHasKey('reporting', $snapshot);
        $this->assertArrayHasKey('recipients', $snapshot['reporting']);
        $this->assertArrayHasKey('count', $snapshot['reporting']);
    }

    public function test_reporting_slice_forwards_configured_recipients(): void {
        $this->resetAfterTest();
        set_config('alertemails', "alice@example.com\nbob@example.com", 'local_sentinel');

        $reporting = collectors\reporting::collect();

        $this->assertSame(['alice@example.com', 'bob@example.com'], $reporting['recipients']);
        $this->assertSame(2, $reporting['count']);
    }

    public function test_reports_keys(): void {
        $this->resetAfterTest();

        $reports = collectors\reports::collect();

        $this->assertArrayHasKey('performance', $reports);
        $this->assertArrayHasKey('security', $reports);
        $this->assertArrayHasKey('system_status', $reports);
        $this->assertArrayHasKey('mfa', $reports);
        foreach (['performance', 'security', 'system_status'] as $section) {
            $this->assertArrayHasKey('total', $reports[$section]);
            $this->assertArrayHasKey('counts_by_status', $reports[$section]);
            $this->assertArrayHasKey('checks', $reports[$section]);
            foreach (['ok', 'warning', 'error', 'critical'] as $status) {
                $this->assertArrayHasKey($status, $reports[$section]['counts_by_status']);
            }
        }
    }

    public function test_config_drift_redacts_secrets(): void {
        $this->resetAfterTest();

        $drift = collectors\config_drift::collect();

        $this->assertArrayHasKey('count', $drift);
        $this->assertArrayHasKey('entries', $drift);
        $this->assertArrayHasKey('skipped', $drift);
        foreach ($drift['entries'] as $entry) {
            $name = strtolower($entry['name']);
            $this->assertStringNotContainsString('password', $name);
            $this->assertStringNotContainsString('secret', $name);
            $this->assertStringNotContainsString('token', $name);
        }
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
        $this->assertArrayHasKey('branch_eol_date', $status);
        $this->assertArrayHasKey('branch_eol_days_remaining', $status);
        $this->assertArrayHasKey('build_age_days', $status);
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
        // OS distro fields (parsed from /etc/os-release; empty on non-Linux).
        $this->assertArrayHasKey('distro', $env['os']);
        $this->assertArrayHasKey('distro_version', $env['os']);
        $this->assertArrayHasKey('distro_name', $env['os']);
        $this->assertIsArray($env['extensions']);
        $this->assertArrayHasKey('size_bytes', $env['database']);
        $this->assertArrayHasKey('largest_tables', $env['database']);
        $this->assertIsArray($env['database']['largest_tables']);
    }

    public function test_plugins_keys(): void {
        $this->resetAfterTest();

        $plugins = collectors\plugins::collect();

        $this->assertArrayHasKey('standard', $plugins);
        $this->assertArrayHasKey('third_party', $plugins);
        $this->assertArrayHasKey('updates_available', $plugins);
        $this->assertArrayHasKey('theme', $plugins);
        $this->assertNotEmpty($plugins['standard']);

        $first = $plugins['standard'][0];
        $this->assertArrayHasKey('status', $first);
        $this->assertArrayHasKey('missing_from_disk', $first);
        $this->assertArrayHasKey('version_disk', $first);
        $this->assertArrayHasKey('version_db', $first);
        $this->assertIsBool($first['missing_from_disk']);
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
        $this->assertArrayHasKey('backup', $health);
        $this->assertArrayHasKey('flags', $health);
        $this->assertArrayHasKey('status_counts', $health['backup']);
        $this->assertArrayHasKey('last_success', $health['backup']);
        $this->assertArrayHasKey('automated_state', $health['backup']);
    }

    public function test_auth_keys(): void {
        $this->resetAfterTest();

        $auth = collectors\auth::collect();

        $this->assertArrayHasKey('enabled', $auth);
        $this->assertArrayHasKey('methods', $auth);
        $this->assertIsArray($auth['enabled']);
        $this->assertIsArray($auth['methods']);
        foreach ($auth['methods'] as $entry) {
            $this->assertArrayHasKey('plugin', $entry);
            $this->assertArrayHasKey('total_users', $entry);
            $this->assertArrayHasKey('active_users', $entry);
        }
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

    public function test_get_snapshot_for_egress_omits_excluded_slices(): void {
        $this->resetAfterTest();
        set_config('egress_excluded_slices', json_encode(['health', 'auth']), 'local_sentinel');

        $snapshot = collector::get_snapshot_for_egress();

        $this->assertArrayHasKey('site', $snapshot);
        $this->assertArrayHasKey('status', $snapshot);
        $this->assertArrayHasKey('environment', $snapshot);
        $this->assertArrayNotHasKey('health', $snapshot);
        $this->assertArrayNotHasKey('auth', $snapshot);
    }

    public function test_get_snapshot_for_egress_omits_excluded_reporting_slice(): void {
        $this->resetAfterTest();
        set_config('alertemails', 'alice@example.com', 'local_sentinel');
        set_config('egress_excluded_slices', json_encode(['reporting']), 'local_sentinel');

        $snapshot = collector::get_snapshot_for_egress();

        $this->assertArrayNotHasKey('reporting', $snapshot);
    }

    public function test_get_snapshot_for_egress_omits_excluded_fields(): void {
        $this->resetAfterTest();
        set_config(
            'egress_excluded_fields',
            json_encode(['environment.database.host', 'environment.os.hostname']),
            'local_sentinel'
        );

        $snapshot = collector::get_snapshot_for_egress();

        $this->assertArrayHasKey('database', $snapshot['environment']);
        $this->assertArrayNotHasKey('host', $snapshot['environment']['database']);
        $this->assertArrayHasKey('type', $snapshot['environment']['database']);
        $this->assertArrayNotHasKey('hostname', $snapshot['environment']['os']);
    }

    public function test_get_slice_for_egress_returns_empty_envelope_when_excluded(): void {
        $this->resetAfterTest();
        set_config('egress_excluded_slices', json_encode(['health']), 'local_sentinel');

        $snapshot = collector::get_slice_for_egress('health');

        $this->assertArrayHasKey('site', $snapshot);
        $this->assertArrayNotHasKey('health', $snapshot);
    }
}
