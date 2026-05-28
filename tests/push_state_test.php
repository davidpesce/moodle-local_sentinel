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
 * Tests for the push-state self-monitoring helper.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Tests for the push-state self-monitoring helper.
 *
 * @covers \local_sentinel\push_state
 */
final class push_state_test extends \advanced_testcase {
    public function test_get_returns_empty_shape_when_never_pushed(): void {
        $this->resetAfterTest();

        $state = push_state::get();

        $this->assertSame(0, $state['last_attempt_at']);
        $this->assertSame(0, $state['last_success_at']);
        $this->assertSame(push_state::STATUS_NEVER, $state['last_status']);
        $this->assertSame(0, $state['consecutive_failures']);
        $this->assertSame(0, $state['total_attempts']);
        $this->assertSame(0, $state['total_successes']);
    }

    public function test_record_attempt_increments_total(): void {
        $this->resetAfterTest();

        push_state::record_attempt();
        push_state::record_attempt();

        $state = push_state::get();
        $this->assertSame(2, $state['total_attempts']);
        $this->assertGreaterThan(0, $state['last_attempt_at']);
    }

    public function test_record_failure_increments_consecutive(): void {
        $this->resetAfterTest();

        push_state::record_attempt();
        push_state::record_failure('boom', 502);
        push_state::record_attempt();
        push_state::record_failure('boom again', 502);
        push_state::record_attempt();
        push_state::record_failure('boom once more', 503);

        $state = push_state::get();
        $this->assertSame(3, $state['consecutive_failures']);
        $this->assertSame(push_state::STATUS_FAILED, $state['last_status']);
        $this->assertSame(503, $state['last_http_status']);
        $this->assertSame('boom once more', $state['last_error']);
        $this->assertSame(0, $state['total_successes']);
    }

    public function test_record_success_clears_consecutive_failures(): void {
        $this->resetAfterTest();

        push_state::record_attempt();
        push_state::record_failure('boom', 502);
        push_state::record_attempt();
        push_state::record_failure('boom again', 502);
        push_state::record_attempt();
        push_state::record_success(200);

        $state = push_state::get();
        $this->assertSame(0, $state['consecutive_failures']);
        $this->assertSame(push_state::STATUS_SUCCESS, $state['last_status']);
        $this->assertSame(200, $state['last_http_status']);
        $this->assertSame('', $state['last_error']);
        $this->assertSame(1, $state['total_successes']);
        $this->assertSame(3, $state['total_attempts']);
        $this->assertGreaterThan(0, $state['last_success_at']);
    }

    public function test_long_error_message_is_truncated(): void {
        $this->resetAfterTest();

        $msg = str_repeat('A', 600);
        push_state::record_failure($msg, 500);

        $state = push_state::get();
        $this->assertLessThanOrEqual(500, strlen($state['last_error']));
        $this->assertStringEndsWith('...', $state['last_error']);
    }
}
