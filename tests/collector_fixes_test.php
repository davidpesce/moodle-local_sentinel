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
 * Regression tests for collector correctness fixes.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

use local_sentinel\collectors\auth;
use local_sentinel\collectors\health;

/**
 * Regression tests for collector correctness fixes.
 *
 * @covers \local_sentinel\collectors\auth
 * @covers \local_sentinel\collectors\health
 */
final class collector_fixes_test extends \advanced_testcase {
    public function test_expiring_tokens_excludes_already_expired(): void {
        global $DB;
        $this->resetAfterTest();

        $now = time();
        // One token expiring in 10 days (counts), one that expired 5 days ago
        // (must NOT count as "expiring within 30 days").
        $this->insert_token($DB, $now + (10 * DAYSECS));
        $this->insert_token($DB, $now - (5 * DAYSECS));

        $tokens = auth::collect()['tokens'];
        $this->assertSame(1, $tokens['expiring_within_30_days']);
        $this->assertSame(2, $tokens['total_count']);
    }

    public function test_admin_change_logged_with_plugin_core_is_detected(): void {
        global $DB;
        $this->resetAfterTest();

        // Moodle logs add/remove-admin with plugin = 'core' (only "set main admin"
        // is NULL). The collector must pick this up, not report a stale time.
        $when = time() - 120;
        $DB->insert_record('config_log', (object) [
            'userid' => 2,
            'timemodified' => $when,
            'plugin' => 'core',
            'name' => 'siteadmins',
            'oldvalue' => '2',
            'value' => '2,3',
        ]);

        // Call the specific method directly (the full health::collect() runs many
        // sub-collectors that emit env-dependent notices, which would flag the
        // test "risky" for unrelated output).
        $method = new \ReflectionMethod(health::class, 'collect_admins_last_changed');
        $method->setAccessible(true);
        $lastchanged = $method->invoke(null);
        $this->assertSame($when, $lastchanged['time']);
    }

    /**
     * Insert a minimal external_tokens row with a given expiry.
     *
     * @param \moodle_database $db
     * @param int $validuntil
     */
    private function insert_token(\moodle_database $db, int $validuntil): void {
        static $seq = 0;
        $seq++;
        $db->insert_record('external_tokens', (object) [
            'token' => sha1('tok' . $seq . microtime(true)),
            'tokentype' => 0,
            'userid' => 2,
            'externalserviceid' => 1,
            'contextid' => \context_system::instance()->id,
            'creatorid' => 2,
            'iprestriction' => null,
            'validuntil' => $validuntil,
            'timecreated' => time(),
            'lastaccess' => time(),
        ]);
    }
}
