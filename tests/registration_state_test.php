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
 * Tests for the self-registration state helper.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Tests for the self-registration state helper.
 *
 * @covers \local_sentinel\registration_state
 */
final class registration_state_test extends \advanced_testcase {
    public function test_get_returns_never_shape_by_default(): void {
        $this->resetAfterTest();

        $state = registration_state::get();

        $this->assertSame(0, $state['last_attempt_at']);
        $this->assertSame(registration_state::STATUS_NEVER, $state['last_status']);
        $this->assertSame(0, $state['last_http_status']);
        $this->assertSame('', $state['last_error']);
        $this->assertSame('', $state['registered_siteidentifier']);
    }

    public function test_record_attempt_stamps_time(): void {
        $this->resetAfterTest();

        registration_state::record_attempt();

        $this->assertGreaterThan(0, registration_state::get()['last_attempt_at']);
    }

    public function test_record_result_activated_stamps_identifier(): void {
        $this->resetAfterTest();

        registration_state::record_result(registration_state::STATUS_ACTIVATED, 200, 'site-xyz');

        $state = registration_state::get();
        $this->assertSame(registration_state::STATUS_ACTIVATED, $state['last_status']);
        $this->assertSame(200, $state['last_http_status']);
        $this->assertSame('site-xyz', $state['registered_siteidentifier']);
        $this->assertGreaterThan(0, $state['registered_at']);
    }

    public function test_record_result_pending_does_not_set_registered(): void {
        $this->resetAfterTest();

        registration_state::record_result(registration_state::STATUS_PENDING, 200);

        $state = registration_state::get();
        $this->assertSame(registration_state::STATUS_PENDING, $state['last_status']);
        $this->assertSame('', $state['registered_siteidentifier']);
        $this->assertSame(0, $state['registered_at']);
    }

    public function test_record_failure_captures_and_trims_error(): void {
        $this->resetAfterTest();

        registration_state::record_failure(str_repeat('x', 800), 503);

        $state = registration_state::get();
        $this->assertSame(registration_state::STATUS_FAILED, $state['last_status']);
        $this->assertSame(503, $state['last_http_status']);
        $this->assertSame(500, strlen($state['last_error']));
        $this->assertStringEndsWith('...', $state['last_error']);
    }

    public function test_reset_returns_to_never(): void {
        $this->resetAfterTest();

        registration_state::record_result(registration_state::STATUS_ACTIVATED, 200, 'x');
        registration_state::reset();

        $this->assertSame(registration_state::STATUS_NEVER, registration_state::get()['last_status']);
    }
}
