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
 * Integrity state tracker tests.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Tests for the integrity pipeline state blob.
 *
 * @covers \local_sentinel\integrity_state
 */
final class integrity_state_test extends \advanced_testcase {
    /**
     * A fresh site reports the never-scanned shape.
     */
    public function test_defaults(): void {
        $this->resetAfterTest();
        $state = integrity_state::get();

        $this->assertSame('', $state['manifest_version']);
        $this->assertSame(integrity_state::STATUS_NEVER, $state['last_scan_status']);
        $this->assertSame(0, $state['last_scan_at']);
        $this->assertSame(0, $state['modified_count']);
    }

    /**
     * Manifest receipt is stamped without touching scan state.
     */
    public function test_record_manifest(): void {
        $this->resetAfterTest();
        integrity_state::record_manifest('2024100711.04', str_repeat('ab', 32));

        $state = integrity_state::get();
        $this->assertSame('2024100711.04', $state['manifest_version']);
        $this->assertSame(str_repeat('ab', 32), $state['manifest_digest']);
        $this->assertGreaterThan(0, $state['manifest_received_at']);
        $this->assertSame(integrity_state::STATUS_NEVER, $state['last_scan_status']);
    }

    /**
     * A completed scan stores its summary and clears any prior error.
     */
    public function test_record_scan_ok(): void {
        $this->resetAfterTest();
        integrity_state::record_scan_error('previous failure');
        integrity_state::record_scan_ok([
            'scanned_at' => 1750000000,
            'duration_seconds' => 42,
            'manifest_version' => '2024100711.04',
            'files_scanned' => 29077,
            'modified_count' => 2,
            'missing_count' => 1,
            'unexpected_count' => 3,
        ]);

        $state = integrity_state::get();
        $this->assertSame(integrity_state::STATUS_OK, $state['last_scan_status']);
        $this->assertSame(1750000000, $state['last_scan_at']);
        $this->assertSame(42, $state['last_scan_duration']);
        $this->assertSame(29077, $state['files_scanned']);
        $this->assertSame(2, $state['modified_count']);
        $this->assertSame(1, $state['missing_count']);
        $this->assertSame(3, $state['unexpected_count']);
        $this->assertSame('', $state['last_error']);
    }

    /**
     * Failures store a trimmed error message.
     */
    public function test_record_scan_error_trims(): void {
        $this->resetAfterTest();
        integrity_state::record_scan_error(str_repeat('x', 600));

        $state = integrity_state::get();
        $this->assertSame(integrity_state::STATUS_ERROR, $state['last_scan_status']);
        $this->assertSame(500, strlen($state['last_error']));
        $this->assertStringEndsWith('...', $state['last_error']);
    }

    /**
     * Reset returns the blob to defaults.
     */
    public function test_reset(): void {
        $this->resetAfterTest();
        integrity_state::record_manifest('2024100711.04', str_repeat('ab', 32));
        integrity_state::reset();

        $this->assertSame('', integrity_state::get()['manifest_version']);
    }
}
