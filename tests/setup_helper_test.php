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

    /**
     * Setup authorises the service account on the Sentinel service (via the
     * core webservice API) and mints a permanent token; re-running is idempotent.
     */
    public function test_run_authorises_user_on_service(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = setup\helper::run();

        $service = $DB->get_record('external_services', ['shortname' => 'local_sentinel'], '*', MUST_EXIST);
        $membership = ['externalserviceid' => $service->id, 'userid' => $result->userid];
        $row = $DB->get_record('external_services_users', $membership, '*', MUST_EXIST);
        $this->assertSame('', $row->iprestriction);
        $this->assertSame(0, (int) $row->validuntil);
        $this->assertGreaterThan(0, (int) $row->timecreated);
        $this->assertNotSame('', $result->token);
        $this->assertContains('Added user to the service.', $result->steps);

        $again = setup\helper::run();
        $this->assertSame(1, $DB->count_records('external_services_users', $membership));
        $this->assertSame($result->token, $again->token);
        $this->assertContains('User already on the service.', $again->steps);
    }

    /**
     * Teardown removes the role and service user that setup created.
     */
    public function test_teardown_removes_provisioned_role_and_user(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = setup\helper::run();
        $steps = setup\helper::teardown();

        $this->assertFalse($DB->record_exists('role', ['id' => $result->roleid]));
        $this->assertSame(1, (int) $DB->get_field('user', 'deleted', ['id' => $result->userid]));
        $this->assertFalse($DB->record_exists('user', ['username' => 'sentinel', 'deleted' => 0]));
        $this->assertContains("Deleted role 'sentinel'.", $steps);
        $this->assertContains("Deleted user 'sentinel'.", $steps);
    }

    /**
     * A same-named role or user that setup did not create is left untouched.
     */
    public function test_teardown_leaves_foreign_role_and_user(): void {
        global $DB;
        $this->resetAfterTest();

        $roleid = create_role('Sentinel', 'sentinel', 'A role somebody else made.', '');
        $user = $this->getDataGenerator()->create_user(['username' => 'sentinel']);

        $steps = setup\helper::teardown();

        $this->assertTrue($DB->record_exists('role', ['id' => $roleid]));
        $this->assertSame(0, (int) $DB->get_field('user', 'deleted', ['id' => $user->id]));
        $this->assertContains("Left role 'sentinel' in place (not created by Sentinel).", $steps);
        $this->assertContains("Left user 'sentinel' in place (not created by Sentinel).", $steps);
    }

    /**
     * The uninstall hook removes the dataroot artefacts and the provisioned account.
     */
    public function test_uninstall_hook_cleans_dataroot_and_account(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/local/sentinel/db/uninstall.php');

        setup\helper::run();
        manifest_store::save_scan_result(['status' => 'clean']);
        $dir = manifest_store::path();
        $this->assertDirectoryExists($dir);
        $this->assertFileExists($dir . '/scan_result.json');

        $this->assertTrue(xmldb_local_sentinel_uninstall());

        $this->assertDirectoryDoesNotExist($dir);
        $this->assertFalse($DB->record_exists('user', ['username' => 'sentinel', 'deleted' => 0]));
        $this->assertFalse($DB->record_exists('role', ['shortname' => 'sentinel']));
    }
}
