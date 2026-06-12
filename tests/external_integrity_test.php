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
 * Integrity external function tests.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

use core_external\external_api;

/**
 * Tests for set_manifest and request_integrity_scan.
 *
 * @covers \local_sentinel\external\set_manifest
 * @covers \local_sentinel\external\request_integrity_scan
 */
final class external_integrity_test extends \advanced_testcase {
    /** Sample manifest text used across tests. */
    private const TEXT = "e69de29bb2d1d6434b8b29ae775ad8c2e48c5391\tlib/empty.php\n"
        . "ce013625030ba8dba906f756967f9e9ca394464a\tlib/hello.php\n";

    /**
     * Wire-encode manifest text the way the dashboard does.
     *
     * @param string $text Raw manifest text.
     * @return array [base64(gzip(text)), sha256(text)].
     */
    private static function encode(string $text): array {
        return [base64_encode(gzencode($text)), hash('sha256', $text)];
    }

    /**
     * Happy path: valid payload is stored and state recorded.
     */
    public function test_set_manifest_stores(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        manifest_store::reset();

        [$payload, $digest] = self::encode(self::TEXT);
        $result = external\set_manifest::execute('2024100711.04', $digest, $payload);
        $cleaned = external_api::clean_returnvalue(external\set_manifest::execute_returns(), $result);

        $this->assertSame('stored', $cleaned['status']);
        $this->assertSame('2024100711.04', $cleaned['version']);
        $this->assertSame(2, $cleaned['lines']);

        $this->assertSame('2024100711.04', manifest_store::load_meta()['version']);
        $this->assertSame('2024100711.04', integrity_state::get()['manifest_version']);
        $this->assertCount(2, manifest_store::load_manifest_map());
    }

    /**
     * Corrupted base64 is rejected.
     */
    public function test_set_manifest_rejects_bad_base64(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\invalid_parameter_exception::class);
        external\set_manifest::execute('2024100711.04', hash('sha256', self::TEXT), '!!!not-base64!!!');
    }

    /**
     * A digest that does not match the inflated text is rejected.
     */
    public function test_set_manifest_rejects_digest_mismatch(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$payload] = self::encode(self::TEXT);
        $this->expectException(\invalid_parameter_exception::class);
        external\set_manifest::execute('2024100711.04', str_repeat('0', 64), $payload);
    }

    /**
     * Payloads that inflate to something that is not a manifest are rejected.
     */
    public function test_set_manifest_rejects_non_manifest_text(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $text = "<?php this is not a manifest\n";
        [$payload, $digest] = self::encode($text);
        $this->expectException(\invalid_parameter_exception::class);
        external\set_manifest::execute('2024100711.04', $digest, $payload);
    }

    /**
     * A version param that is not a Moodle version string is rejected.
     */
    public function test_set_manifest_rejects_bad_version(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$payload, $digest] = self::encode(self::TEXT);
        $this->expectException(\invalid_parameter_exception::class);
        external\set_manifest::execute('main', $digest, $payload);
    }

    /**
     * The write functions require local/sentinel:manage — a :view-only user
     * (or any plain user) is refused.
     */
    public function test_write_functions_require_manage_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        [$payload, $digest] = self::encode(self::TEXT);
        $this->expectException(\required_capability_exception::class);
        external\set_manifest::execute('2024100711.04', $digest, $payload);
    }

    /**
     * request_integrity_scan refuses while the feature is unusable, then
     * queues exactly one adhoc task once provisioned (dedupe on re-request).
     */
    public function test_request_integrity_scan(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        manifest_store::reset();

        // Disabled setting → refused.
        set_config('integrityenabled', 0, 'local_sentinel');
        $result = external\request_integrity_scan::execute();
        $this->assertFalse($result['queued']);
        $this->assertStringContainsString('disabled', $result['reason']);

        // Enabled but no manifest → refused.
        set_config('integrityenabled', 1, 'local_sentinel');
        $result = external\request_integrity_scan::execute();
        $this->assertFalse($result['queued']);
        $this->assertStringContainsString('manifest', $result['reason']);

        // Provisioned → queued, and a second request coalesces.
        [$payload, $digest] = self::encode(self::TEXT);
        external\set_manifest::execute('2024100711.04', $digest, $payload);
        $result = external\request_integrity_scan::execute();
        $this->assertTrue($result['queued']);
        $result = external\request_integrity_scan::execute();
        $this->assertTrue($result['queued']);

        $queued = \core\task\manager::get_adhoc_tasks(task\integrity_scan_adhoc::class);
        $this->assertCount(1, $queued);
    }
}
