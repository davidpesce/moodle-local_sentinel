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
 * Provisioning-code parser tests.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Tests for {@see provisioning_code}.
 *
 * @covers \local_sentinel\provisioning_code
 */
final class provisioning_code_test extends \basic_testcase {
    /**
     * Canonical vector — must stay in sync with the dashboard's builder test
     * (sentinel-dashboard tests/test_provisioning.py uses the same string).
     */
    public function test_parses_canonical_code(): void {
        $code = 'SNTL1.eyJrIjoiZW5yb2xsLXNlY3JldCIsInUiOiJodHRwczovL2Rhc2guZXhhbXBsZS5jb20ifQ';
        $parsed = provisioning_code::parse($code);
        $this->assertNotNull($parsed);
        $this->assertSame('https://dash.example.com', $parsed['url']);
        $this->assertSame('enroll-secret', $parsed['key']);
    }

    public function test_tolerates_surrounding_whitespace(): void {
        $code = "  SNTL1.eyJrIjoiZW5yb2xsLXNlY3JldCIsInUiOiJodHRwczovL2Rhc2guZXhhbXBsZS5jb20ifQ \n";
        $this->assertNotNull(provisioning_code::parse($code));
    }

    public function test_strips_trailing_slash_from_url(): void {
        $payload = base64_encode(json_encode(['k' => 'k1', 'u' => 'https://dash.example.com/']));
        $parsed = provisioning_code::parse('SNTL1.' . rtrim(strtr($payload, '+/', '-_'), '='));
        $this->assertSame('https://dash.example.com', $parsed['url']);
    }

    public function test_rejects_http_url(): void {
        $code = 'SNTL1.eyJrIjoiZW5yb2xsLXNlY3JldCIsInUiOiJodHRwOi8vZGFzaC5leGFtcGxlLmNvbSJ9';
        $this->assertNull(provisioning_code::parse($code));
    }

    public function test_rejects_wrong_prefix(): void {
        $this->assertNull(provisioning_code::parse('SNTL2.eyJrIjoiYSIsInUiOiJodHRwczovL3gifQ'));
        $this->assertNull(provisioning_code::parse('eyJrIjoiYSIsInUiOiJodHRwczovL3gifQ'));
    }

    public function test_rejects_garbage(): void {
        $this->assertNull(provisioning_code::parse(''));
        $this->assertNull(provisioning_code::parse('SNTL1.'));
        $this->assertNull(provisioning_code::parse('SNTL1.!!!not-base64!!!'));
        $this->assertNull(provisioning_code::parse('SNTL1.' . rtrim(strtr(base64_encode('not json'), '+/', '-_'), '=')));
    }

    public function test_rejects_missing_fields(): void {
        $onlyurl = base64_encode(json_encode(['u' => 'https://dash.example.com']));
        $this->assertNull(provisioning_code::parse('SNTL1.' . rtrim(strtr($onlyurl, '+/', '-_'), '=')));
        $onlykey = base64_encode(json_encode(['k' => 'secret']));
        $this->assertNull(provisioning_code::parse('SNTL1.' . rtrim(strtr($onlykey, '+/', '-_'), '=')));
    }

    public function test_rejects_oversized_input(): void {
        $this->assertNull(provisioning_code::parse('SNTL1.' . str_repeat('A', 4096)));
    }
}
