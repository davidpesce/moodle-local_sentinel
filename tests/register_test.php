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
 * Tests for the self-registration action.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Tests for the self-registration action.
 *
 * Covers the pre-flight guards and the payload shape. The networked happy path
 * (POST + dashboard response handling) is exercised by the dashboard test suite.
 *
 * @covers \local_sentinel\register
 */
final class register_test extends \advanced_testcase {
    public function test_generate_secret_is_long_and_distinct(): void {
        $a = register::generate_secret();
        $b = register::generate_secret();

        $this->assertGreaterThanOrEqual(40, strlen($a));
        $this->assertNotSame($a, $b);
    }

    public function test_build_payload_carries_no_user_data(): void {
        $this->resetAfterTest();

        // Warming the plugin manager (via get_plugin_identity) can emit
        // incidental framework output; absorb it so the assertions below stay
        // deterministic (the same condition flags the snapshot collector tests).
        ob_start();
        $payload = register::build_payload('sek', 'wstok');
        ob_end_clean();

        $this->assertSame(['site', 'plugin', 'push_secret', 'ws_token'], array_keys($payload));
        $this->assertSame('sek', $payload['push_secret']);
        $this->assertSame('wstok', $payload['ws_token']);
        $this->assertSame(
            ['wwwroot', 'siteidentifier', 'sitename', 'shortname'],
            array_keys($payload['site'])
        );
        // No user-identifying keys anywhere in the body (both credentials are
        // machine credentials, not user data).
        $flat = json_encode($payload);
        foreach (['username', 'firstname', 'lastname', 'email', 'userid'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $flat);
        }
    }

    public function test_build_payload_ws_token_defaults_empty(): void {
        $this->resetAfterTest();

        ob_start();
        $payload = register::build_payload('sek');
        ob_end_clean();

        // Push-only fallback: an omitted token registers as empty, not missing.
        $this->assertSame('', $payload['ws_token']);
    }

    public function test_run_refuses_when_disabled(): void {
        $this->resetAfterTest();
        set_config('registrationenabled', 0, 'local_sentinel');

        [$ok, , $status] = register::run();

        $this->assertFalse($ok);
        $this->assertSame(registration_state::STATUS_FAILED, $status);
        // A disabled attempt is a no-op — state is untouched.
        $this->assertSame(registration_state::STATUS_NEVER, registration_state::get()['last_status']);
    }

    public function test_run_refuses_when_misconfigured(): void {
        $this->resetAfterTest();
        set_config('registrationenabled', 1, 'local_sentinel');
        // No dashboardbaseurl / enrollmentkey.

        [$ok, , $status] = register::run();

        $this->assertFalse($ok);
        $this->assertSame(registration_state::STATUS_FAILED, $status);
        $this->assertSame(registration_state::STATUS_NEVER, registration_state::get()['last_status']);
    }

    public function test_run_refuses_non_https_and_does_not_persist_secret(): void {
        $this->resetAfterTest();
        set_config('registrationenabled', 1, 'local_sentinel');
        set_config('dashboardbaseurl', 'http://dash.example.com', 'local_sentinel');
        set_config('enrollmentkey', 'enroll-secret', 'local_sentinel');

        [$ok, , $status] = register::run();

        $this->assertFalse($ok);
        $this->assertSame(registration_state::STATUS_FAILED, $status);
        $this->assertSame(registration_state::STATUS_FAILED, registration_state::get()['last_status']);
        // The HTTPS guard runs before any secret/endpoint is written.
        $this->assertEmpty(get_config('local_sentinel', 'pushsecret'));
        $this->assertEmpty(get_config('local_sentinel', 'pushendpoint'));
    }

    public function test_enable_push_pipeline_enables_setting_and_task(): void {
        $this->resetAfterTest();

        // Fresh-install state: setting off, task shipped disabled, not customised.
        $task = \core\task\manager::get_scheduled_task(\local_sentinel\task\push_snapshot::class);
        $this->assertTrue($task->get_disabled());
        $this->assertEmpty(get_config('local_sentinel', 'pushenabled'));

        register::enable_push_pipeline();

        $this->assertEquals(1, get_config('local_sentinel', 'pushenabled'));
        $task = \core\task\manager::get_scheduled_task(\local_sentinel\task\push_snapshot::class);
        $this->assertFalse($task->get_disabled());
    }

    public function test_enable_push_pipeline_respects_admin_customisation(): void {
        $this->resetAfterTest();

        // Admin explicitly customised the task and kept it disabled.
        $task = \core\task\manager::get_scheduled_task(\local_sentinel\task\push_snapshot::class);
        $task->set_disabled(true);
        $task->set_customised(true);
        \core\task\manager::configure_scheduled_task($task);

        register::enable_push_pipeline();

        // Setting flips on, but the hand-configured task state is left alone.
        $this->assertEquals(1, get_config('local_sentinel', 'pushenabled'));
        $task = \core\task\manager::get_scheduled_task(\local_sentinel\task\push_snapshot::class);
        $this->assertTrue($task->get_disabled());
    }

    public function test_enable_push_pipeline_is_idempotent(): void {
        $this->resetAfterTest();

        register::enable_push_pipeline();
        register::enable_push_pipeline();

        $task = \core\task\manager::get_scheduled_task(\local_sentinel\task\push_snapshot::class);
        $this->assertFalse($task->get_disabled());
        $this->assertEquals(1, get_config('local_sentinel', 'pushenabled'));
    }
}
