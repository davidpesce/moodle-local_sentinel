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
 * Recipient parsing tests.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Tests for the report-recipient parser.
 *
 * @covers \local_sentinel\recipients
 */
final class recipients_test extends \advanced_testcase {
    public function test_parse_valid_list(): void {
        [$valid, $invalid] = recipients::parse("alice@example.com\nbob@example.com");

        $this->assertSame(['alice@example.com', 'bob@example.com'], $valid);
        $this->assertNull($invalid);
    }

    public function test_parse_splits_on_commas_semicolons_and_whitespace(): void {
        [$valid, $invalid] = recipients::parse("a@example.com, b@example.com; c@example.com\t d@example.com");

        $this->assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'],
            $valid
        );
        $this->assertNull($invalid);
    }

    public function test_parse_dedupes_preserving_order(): void {
        [$valid, $invalid] = recipients::parse("a@example.com\nb@example.com\na@example.com");

        $this->assertSame(['a@example.com', 'b@example.com'], $valid);
        $this->assertNull($invalid);
    }

    public function test_parse_returns_first_invalid(): void {
        [$valid, $invalid] = recipients::parse("good@example.com\nnot-an-email\nlater@example.com");

        $this->assertSame(['good@example.com'], $valid);
        $this->assertSame('not-an-email', $invalid);
    }

    public function test_parse_empty_string(): void {
        [$valid, $invalid] = recipients::parse('');

        $this->assertSame([], $valid);
        $this->assertNull($invalid);
    }

    public function test_all_reads_config_and_drops_invalid_tail(): void {
        $this->resetAfterTest();
        set_config('alertemails', "alice@example.com\nbob@example.com", 'local_sentinel');

        $this->assertSame(['alice@example.com', 'bob@example.com'], recipients::all());
    }

    public function test_all_empty_when_unset(): void {
        $this->resetAfterTest();

        $this->assertSame([], recipients::all());
    }
}
