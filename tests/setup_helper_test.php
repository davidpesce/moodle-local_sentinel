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

        $filled = setup\helper::satisfy_required_profile_fields((int) $user->id)['filled'];

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

        $this->assertSame(
            ['filled' => [], 'unfillable' => []],
            setup\helper::satisfy_required_profile_fields((int) $user->id)
        );
    }

    /**
     * Insert a custom profile field.
     *
     * @param string $shortname
     * @param string $datatype
     * @param array $extra Overrides (param1, defaultdata, required, ...).
     * @return int Field id.
     */
    private function add_profile_field(string $shortname, string $datatype, array $extra = []): int {
        global $DB;
        static $catid = null;
        if (!$catid || !$DB->record_exists('user_info_category', ['id' => $catid])) {
            $catid = $DB->insert_record('user_info_category', (object) ['name' => 'Other', 'sortorder' => 1]);
        }
        return (int) $DB->insert_record('user_info_field', (object) ($extra + [
            'shortname' => $shortname, 'name' => $shortname, 'categoryid' => $catid,
            'datatype' => $datatype, 'description' => '', 'descriptionformat' => FORMAT_HTML,
            'required' => 1, 'locked' => 0, 'visible' => 2, 'forceunique' => 0, 'signup' => 1,
            'defaultdata' => '', 'defaultdataformat' => FORMAT_MOODLE, 'param1' => null,
            'sortorder' => 1,
        ]));
    }

    /**
     * A required dropdown gets its first real option, not the free-text placeholder;
     * a required date gets a timestamp.
     */
    public function test_fill_uses_type_valid_values(): void {
        global $DB;
        $this->resetAfterTest();
        $menuid = $this->add_profile_field('orgtype', 'menu', ['param1' => "Hospital\nClinic\nOther"]);
        $dateid = $this->add_profile_field('started', 'datetime', ['param1' => 2000, 'param2' => 2050, 'param3' => 0]);
        $user = $this->getDataGenerator()->create_user();

        $result = setup\helper::satisfy_required_profile_fields((int) $user->id);

        $this->assertEqualsCanonicalizing(['orgtype', 'started'], $result['filled']);
        $this->assertSame([], $result['unfillable']);
        $keys = ['userid' => $user->id];
        $this->assertSame('Hospital', $DB->get_field('user_info_data', 'data', $keys + ['fieldid' => $menuid]));
        $this->assertGreaterThan(0, (int) $DB->get_field('user_info_data', 'data', $keys + ['fieldid' => $dateid]));
        $this->assertFalse(user_not_fully_set_up(\core_user::get_user($user->id)));
    }

    /**
     * The 'n/a' an earlier release wrote into a dropdown is replaced by a real option.
     */
    public function test_fill_repairs_placeholder_in_dropdown(): void {
        global $DB;
        $this->resetAfterTest();
        $menuid = $this->add_profile_field('orgtype', 'menu', ['param1' => "Hospital\nClinic"]);
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_info_data', (object) [
            'userid' => $user->id, 'fieldid' => $menuid, 'data' => 'n/a', 'dataformat' => FORMAT_MOODLE,
        ]);

        $result = setup\helper::satisfy_required_profile_fields((int) $user->id);

        $this->assertSame(['orgtype'], $result['filled']);
        $this->assertSame('Hospital', $DB->get_field('user_info_data', 'data', ['userid' => $user->id, 'fieldid' => $menuid]));
    }

    /**
     * Field-type dispatch: a custom type using core's public $options convention is
     * a dropdown; an unknown type gets no value (reported, not guessed).
     */
    public function test_fill_value_for_custom_types(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $method = new \ReflectionMethod(setup\helper::class, 'fill_value');
        $method->setAccessible(true);

        $dropdown = new class extends \profile_field_base {
            /** @var array Options keyed by stored value. */
            public $options = ['' => 'Choose...', 'Albany' => 'Albany', 'Bronx' => 'Bronx'];
        };
        $unknown = new class extends \profile_field_base {
        };

        $this->assertSame('Albany', $method->invoke(null, $dropdown));
        $this->assertNull($method->invoke(null, $unknown));
    }

    /**
     * The WS layer repairs Sentinel's own service account before validate_context(),
     * so a required field added after setup doesn't lock the dashboard out.
     */
    public function test_request_time_repair_unblocks_service_account(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = setup\helper::run();
        $this->add_profile_field('orgtype', 'menu', ['param1' => "Hospital\nClinic"]);
        $this->setUser($result->userid);
        $this->assertTrue(user_not_fully_set_up($USER, true));

        $method = new \ReflectionMethod(external\base::class, 'repair_service_account');
        $method->setAccessible(true);
        $method->invoke(null);

        $this->assertFalse(user_not_fully_set_up(\core_user::get_user($result->userid), true));
    }

    /**
     * Request-time repair never touches an account setup didn't create.
     */
    public function test_request_time_repair_ignores_other_users(): void {
        $this->resetAfterTest();
        $this->add_profile_field('orgtype', 'menu', ['param1' => "Hospital\nClinic"]);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $method = new \ReflectionMethod(external\base::class, 'repair_service_account');
        $method->setAccessible(true);
        $method->invoke(null);

        $this->assertSame(['orgtype'], setup\helper::blocking_profile_fields((int) $user->id));
    }

    /**
     * The health slice reports the service account and any blocking field.
     */
    public function test_health_reports_service_account(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        setup\helper::run();

        $method = new \ReflectionMethod(collectors\health::class, 'collect_service_account');
        $method->setAccessible(true);
        $this->assertSame(
            ['found' => true, 'username' => 'sentinel', 'fully_set_up' => true, 'blocking_fields' => []],
            $method->invoke(null)
        );

        $this->add_profile_field('orgname', 'text', ['param1' => 30, 'param2' => 2048]);
        $state = $method->invoke(null);
        $this->assertFalse($state['fully_set_up']);
        $this->assertSame(['orgname'], $state['blocking_fields']);
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
     * Setup enables the 'webservice' auth plugin the service account uses —
     * Moodle 5.3+ refuses WS tokens for users of a disabled auth plugin
     * (wsaccessusernologin). Re-running leaves it alone.
     */
    public function test_run_enables_webservice_auth(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('auth', 'email');
        $this->assertFalse(is_enabled_auth('webservice'));

        $result = setup\helper::run();

        $this->assertTrue(is_enabled_auth('webservice'));
        $this->assertTrue(is_enabled_auth('email'));
        $this->assertTrue($result->enabledauthnow);
        $this->assertContains("Enabled the 'webservice' authentication plugin.", $result->steps);

        $again = setup\helper::run();
        $this->assertFalse($again->enabledauthnow);
        $this->assertContains("Authentication plugin 'webservice' already enabled.", $again->steps);
    }

    /**
     * ensure_auth_enabled() never enables 'nologin' and is a no-op for 'manual'.
     */
    public function test_ensure_auth_enabled_skips_nologin_and_manual(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('auth', '');

        $this->assertFalse(setup\helper::ensure_auth_enabled((object) ['auth' => 'nologin']));
        $this->assertFalse(setup\helper::ensure_auth_enabled((object) ['auth' => 'manual']));
        $this->assertSame('', (string) $CFG->auth);
    }

    /**
     * provisioned_user() only returns the account setup created.
     */
    public function test_provisioned_user(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertNull(setup\helper::provisioned_user());
        $this->getDataGenerator()->create_user(['username' => 'sentinel']);
        $this->assertNull(setup\helper::provisioned_user());
    }

    /**
     * provisioned_user() finds the account setup created.
     */
    public function test_provisioned_user_finds_setup_account(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = setup\helper::run();
        $this->assertSame($result->userid, (int) setup\helper::provisioned_user()->id);
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
