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
 * Tests for the Sentinel setup helper.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Tests that setup provisions the service account so WS auth succeeds.
 *
 * @covers \local_sentinel\setup\helper
 */
final class setup_helper_test extends \advanced_testcase {
    /**
     * A required custom profile field must not leave the WS service account
     * "not fully set up" — that blocks pulls with errorcode usernotfullysetup.
     */
    public function test_satisfies_required_profile_fields(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $catid = $DB->insert_record('user_info_category', (object) ['name' => 'Other', 'sortorder' => 1]);
        // A required text field (base is_empty = empty data) AND a required
        // checkbox (overrides is_empty to key off the presence of a data row) —
        // the two distinct emptiness models among core field types.
        $DB->insert_record('user_info_field', (object) [
            'shortname' => 'mustfill', 'name' => 'Must fill', 'categoryid' => $catid,
            'datatype' => 'text', 'description' => '', 'descriptionformat' => FORMAT_HTML,
            'required' => 1, 'locked' => 0, 'visible' => 2, 'forceunique' => 0, 'signup' => 0,
            'defaultdata' => '', 'defaultdataformat' => FORMAT_MOODLE,
            'param1' => 30, 'param2' => 2048, 'sortorder' => 1,
        ]);
        $DB->insert_record('user_info_field', (object) [
            'shortname' => 'mustcheck', 'name' => 'Must check', 'categoryid' => $catid,
            'datatype' => 'checkbox', 'description' => '', 'descriptionformat' => FORMAT_HTML,
            'required' => 1, 'locked' => 0, 'visible' => 2, 'forceunique' => 0, 'signup' => 0,
            'defaultdata' => '0', 'defaultdataformat' => FORMAT_MOODLE, 'sortorder' => 2,
        ]);

        $user = $this->getDataGenerator()->create_user();
        $this->assertTrue(user_not_fully_set_up($user));  // Required fields empty.

        $method = new \ReflectionMethod(setup\helper::class, 'satisfy_required_profile_fields');
        $method->setAccessible(true);
        $filled = $method->invoke(null, (int) $user->id);

        $this->assertContains('mustfill', $filled);
        $this->assertContains('mustcheck', $filled);
        $this->assertCount(2, $filled);
        // Reload and confirm the account now passes the strict check (both the
        // value-based text field and the row-presence-based checkbox).
        $fresh = \core_user::get_user($user->id);
        $this->assertFalse(user_not_fully_set_up($fresh));
    }

    /**
     * No required fields → nothing to fill, no stray profile data written.
     */
    public function test_noop_without_required_fields(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $method = new \ReflectionMethod(setup\helper::class, 'satisfy_required_profile_fields');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke(null, (int) $user->id));
    }
}
