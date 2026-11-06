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
 * Tests for the push_snapshot schedule (per-site offset, survives upgrades).
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

use core\task\manager;
use local_sentinel\task\push_snapshot;

/**
 * Push schedule tests.
 *
 * @covers \local_sentinel\task\push_snapshot
 */
final class push_schedule_test extends \advanced_testcase {
    public function test_default_minute_is_every_15_minutes_at_a_stable_offset(): void {
        $minute = push_snapshot::default_minute('abc123example.com');

        $minutes = array_map('intval', explode(',', $minute));
        $this->assertCount(4, $minutes);
        $this->assertLessThan(15, $minutes[0]);
        $this->assertSame([0, 15, 30, 45], array_map(fn($m) => $m - $minutes[0], $minutes));
        // Deterministic for a given site.
        $this->assertSame($minute, push_snapshot::default_minute('abc123example.com'));
    }

    public function test_default_minute_spreads_a_fleet(): void {
        $offsets = [];
        for ($i = 0; $i < 300; $i++) {
            $offsets[] = (int) explode(',', push_snapshot::default_minute(random_string(32) . "site$i.example"))[0];
        }
        // Every one of the 15 slots is used, none by more than a fifth of the fleet.
        $counts = array_count_values($offsets);
        $this->assertCount(15, $counts);
        $this->assertLessThan(60, max($counts));
    }

    public function test_default_minute_falls_back_without_siteidentifier(): void {
        $this->assertSame('*/15', push_snapshot::default_minute(''));
    }

    public function test_installed_task_uses_the_site_offset_and_ships_enabled(): void {
        $this->resetAfterTest();
        manager::reset_scheduled_tasks_for_component('local_sentinel');

        $task = manager::get_scheduled_task(push_snapshot::class);
        $this->assertSame(push_snapshot::default_minute(), $task->get_minute());
        $this->assertFalse($task->get_disabled());
    }

    public function test_upgrade_does_not_switch_registered_push_off(): void {
        $this->resetAfterTest();

        register::enable_push_pipeline();
        // Core resets every non-customised task to its db/tasks.php default on
        // each plugin upgrade. That must not disable the push again.
        manager::reset_scheduled_tasks_for_component('local_sentinel');

        $task = manager::get_scheduled_task(push_snapshot::class);
        $this->assertFalse($task->get_disabled());
        $this->assertFalse((bool) $task->is_customised());
    }
}
