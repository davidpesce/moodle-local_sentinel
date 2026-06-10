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
 * Environment collector unit tests.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

use local_sentinel\collectors\environment;

/**
 * Tests for the OS package-update parsing and slice shape.
 *
 * @covers \local_sentinel\collectors\environment
 */
final class environment_test extends \advanced_testcase {
    public function test_parse_updates_available_typical_ubuntu(): void {
        $text = "97 updates can be applied immediately.\n" .
            "60 of these updates are standard security updates.\n" .
            "To see these additional updates run: apt list --upgradable\n";
        $counts = environment::parse_updates_available($text);
        $this->assertSame(97, $counts['available']);
        $this->assertSame(60, $counts['security']);
    }

    public function test_parse_updates_available_no_security_line(): void {
        $counts = environment::parse_updates_available("3 updates can be applied immediately.\n");
        $this->assertSame(3, $counts['available']);
        $this->assertNull($counts['security']);
    }

    public function test_parse_updates_available_zero_updates(): void {
        $counts = environment::parse_updates_available(
            "0 updates can be applied immediately.\n"
        );
        $this->assertSame(0, $counts['available']);
        $this->assertNull($counts['security']);
    }

    public function test_parse_updates_available_empty_or_noise(): void {
        $counts = environment::parse_updates_available("\nno numerals here\n");
        $this->assertNull($counts['available']);
        $this->assertNull($counts['security']);
    }

    public function test_os_slice_carries_package_updates(): void {
        $env = environment::collect();
        $this->assertArrayHasKey('package_updates', $env['os']);
        $updates = $env['os']['package_updates'];
        $this->assertArrayHasKey('checked', $updates);
        $this->assertArrayHasKey('available', $updates);
        $this->assertArrayHasKey('security', $updates);
        $this->assertArrayHasKey('reboot_required', $updates);
        $this->assertArrayHasKey('source', $updates);
        $this->assertIsBool($updates['checked']);
        $this->assertIsBool($updates['reboot_required']);
    }
}
