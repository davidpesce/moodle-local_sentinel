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
 * Sensitive-name redaction tests.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\collectors;

/**
 * Verifies secret-bearing setting names are detected and redacted.
 *
 * @covers \local_sentinel\collectors\config_drift
 * @covers \local_sentinel\collectors\config_changes
 */
final class config_drift_test extends \advanced_testcase {
    /**
     * Secret-bearing names are detected; benign names are not.
     *
     * @dataProvider sensitive_name_provider
     * @param string $name Setting name from {config_log}.
     * @param bool $expected Whether it should be treated as sensitive.
     */
    public function test_name_is_sensitive(string $name, bool $expected): void {
        $this->assertSame($expected, config_drift::name_is_sensitive($name));
    }

    /**
     * Cases for {@see test_name_is_sensitive}.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function sensitive_name_provider(): array {
        return [
            // Regression: LDAP/CAS/enrol bind passwords match no other pattern.
            'ldap bind_pw' => ['bind_pw', true],
            'cas bind_pw uppercase' => ['BIND_PW', true],
            'password' => ['smtppass', true],
            'secret' => ['client_secret', true],
            'token' => ['pushtoken', true],
            // Non-secret names stay visible.
            'benign timezone' => ['timezone', false],
            'benign theme' => ['theme', false],
        ];
    }

    public function test_config_changes_redacts_bind_pw_value(): void {
        global $DB;
        $this->resetAfterTest();

        $DB->insert_record('config_log', (object) [
            'userid' => 2,
            'timemodified' => time(),
            'plugin' => 'auth_ldap',
            'name' => 'bind_pw',
            'oldvalue' => 'old-secret-pw',
            'value' => 'new-secret-pw',
        ]);

        $result = config_changes::collect();
        $entry = null;
        foreach ($result['entries'] as $e) {
            if ($e['name'] === 'bind_pw') {
                $entry = $e;
                break;
            }
        }

        $this->assertNotNull($entry, 'bind_pw change should be present in the tail');
        $this->assertSame(config_changes::REDACTED, $entry['newvalue']);
        $this->assertSame(config_changes::REDACTED, $entry['oldvalue']);
    }
}
