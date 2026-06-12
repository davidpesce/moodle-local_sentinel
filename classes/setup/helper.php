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
 * Sentinel WS access bootstrap, shared by the CLI and the admin web page.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\setup;

use context_system;

/**
 * Idempotent setup of everything Sentinel needs to be reachable as a WS endpoint.
 *
 * Steps performed by {@see run()}:
 *   1. Enable web services + REST protocol.
 *   2. Find the pre-registered Sentinel external service.
 *   3. Create (or reuse) the Sentinel role + ensure required capabilities.
 *   4. Create (or reuse) the webservice user.
 *   5. Assign the role at system context.
 *   6. Add the user to the service.
 *   7. Find or generate a permanent token.
 *
 * Both the CLI (`cli/setup.php`) and the admin web page (`setup.php`) call
 * this so behaviour is identical regardless of trigger.
 */
class helper {
    /**
     * Run the full setup.
     *
     * @param array $options Keys: 'username', 'rolename', 'roleshortname'.
     *                       Each is optional; defaults are 'sentinel' /
     *                       'Sentinel' / 'sentinel'.
     * @param bool  $regenerate If true, deletes any existing permanent token
     *                          for this user+service and creates a fresh one.
     * @return result
     */
    public static function run(array $options = [], bool $regenerate = false): result {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/accesslib.php');

        $opts = array_merge([
            'username' => 'sentinel',
            'rolename' => 'Sentinel',
            'roleshortname' => 'sentinel',
        ], $options);

        $result = new result();

        // 1. Enable web services site-wide.
        if (empty($CFG->enablewebservices)) {
            set_config('enablewebservices', 1);
            $result->enabledwebservicesnow = true;
            $result->steps[] = 'Enabled $CFG->enablewebservices.';
        } else {
            $result->steps[] = 'Web services already enabled.';
        }

        // 2. Enable REST protocol.
        $protocols = isset($CFG->webserviceprotocols) ? explode(',', $CFG->webserviceprotocols) : [];
        $protocols = array_filter(array_map('trim', $protocols));
        if (!in_array('rest', $protocols, true)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', $protocols));
            $result->enabledrestnow = true;
            $result->steps[] = 'Enabled REST protocol.';
        } else {
            $result->steps[] = 'REST protocol already enabled.';
        }

        // 3. Resolve the Sentinel external service registered in db/services.php.
        $service = $DB->get_record('external_services', ['shortname' => 'local_sentinel']);
        if (!$service) {
            throw new \moodle_exception('servicemissing', 'local_sentinel');
        }
        $result->steps[] = "Service '$service->shortname' present.";

        // 4. Create or find role.
        $role = $DB->get_record('role', ['shortname' => $opts['roleshortname']]);
        if (!$role) {
            $roleid = create_role(
                $opts['rolename'],
                $opts['roleshortname'],
                'Sentinel webservice access role.',
                ''
            );
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
            $result->createdrole = true;
            $result->steps[] = "Created role '{$opts['roleshortname']}'.";
        } else {
            $roleid = (int) $role->id;
            $result->steps[] = "Reusing existing role '{$opts['roleshortname']}'.";
        }
        $result->roleid = $roleid;

        $systemcontext = context_system::instance();
        $requiredcaps = ['webservice/rest:use', 'local/sentinel:view', 'local/sentinel:manage'];
        foreach ($requiredcaps as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $systemcontext->id, true);
        }
        $result->steps[] = 'Role has: ' . implode(', ', $requiredcaps);

        // 5. Create or find webservice user.
        $user = $DB->get_record('user', ['username' => $opts['username'], 'deleted' => 0]);
        if (!$user) {
            $new = (object) [
                'username' => $opts['username'],
                'firstname' => 'Sentinel',
                'lastname' => 'Service',
                'email' => $opts['username'] . '@example.invalid',
                'auth' => 'webservice',
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'password' => 'not used',
                'policyagreed' => 1,
            ];
            $new->id = user_create_user($new, false, false);
            $user = $new;
            $result->createduser = true;
            $result->steps[] = "Created user '{$user->username}'.";
        } else {
            $result->steps[] = "Reusing existing user '{$user->username}'.";
        }
        $result->userid = (int) $user->id;

        // 5b. Required custom profile fields would otherwise make the service
        // account "not fully set up", which Moodle rejects WS auth for
        // (errorcode usernotfullysetup) — seen on sites with a required custom
        // user profile field. The account never uses the site UI, so fill any
        // required/visible/unlocked-but-empty field with a placeholder.
        $filled = self::satisfy_required_profile_fields($user->id);
        if ($filled) {
            $result->steps[] = 'Filled required profile field(s) for the service user: '
                . implode(', ', $filled) . '.';
        }
        // Safety net: surface the rare case a (third-party) field type's
        // is_empty() validates the value rather than just checking presence, so
        // the placeholder didn't satisfy it — better a visible warning than a
        // silent usernotfullysetup at pull time.
        require_once($CFG->dirroot . '/lib/moodlelib.php');
        if (user_not_fully_set_up(\core_user::get_user($user->id))) {
            $result->steps[] = 'WARNING: the service user is still not fully set up — a required '
                . 'profile field likely needs a manual value (its field type rejects the placeholder).';
        }

        // 6. Role assignment at system context.
        $assigned = $DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'userid' => $user->id,
            'contextid' => $systemcontext->id,
        ]);
        if (!$assigned) {
            role_assign($roleid, $user->id, $systemcontext->id);
            $result->steps[] = 'Assigned role at system context.';
        } else {
            $result->steps[] = 'Role already assigned at system context.';
        }

        // 7. Add user to the service.
        $inservice = $DB->record_exists('external_services_users', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
        ]);
        if (!$inservice) {
            $DB->insert_record('external_services_users', (object) [
                'externalserviceid' => $service->id,
                'userid' => $user->id,
                'iprestriction' => '',
                'validuntil' => 0,
                'timecreated' => time(),
            ]);
            $result->steps[] = 'Added user to the service.';
        } else {
            $result->steps[] = 'User already on the service.';
        }

        // 8. Token (regenerate or reuse).
        $existingtoken = $DB->get_record('external_tokens', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ]);

        if ($regenerate && $existingtoken) {
            $DB->delete_records('external_tokens', ['id' => $existingtoken->id]);
            $existingtoken = null;
            $result->steps[] = 'Deleted previous token for regeneration.';
        }

        if (!$existingtoken) {
            $tokenstring = \core_external\util::generate_token(
                EXTERNAL_TOKEN_PERMANENT,
                $service,
                $user->id,
                $systemcontext,
                0,
                '',
                'Sentinel token'
            );
            $result->createdtoken = true;
            $result->steps[] = 'Generated new permanent token.';
        } else {
            $tokenstring = $existingtoken->token;
            $result->steps[] = 'Reusing existing permanent token.';
        }

        $result->token = $tokenstring;
        $result->endpoint = $CFG->wwwroot . '/webservice/rest/server.php';

        return $result;
    }

    /**
     * Return the existing token for the given user+service, or null if none.
     *
     * Used by the admin page to detect whether setup has already been run.
     *
     * @param string $username
     * @return string|null
     */
    public static function existing_token(string $username = 'sentinel'): ?string {
        global $DB;

        $service = $DB->get_record('external_services', ['shortname' => 'local_sentinel']);
        if (!$service) {
            return null;
        }
        $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
        if (!$user) {
            return null;
        }
        $token = $DB->get_record('external_tokens', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ]);
        return $token ? $token->token : null;
    }

    /** Placeholder stored in required profile fields for the service account. */
    protected const PROFILE_PLACEHOLDER = 'n/a';

    /**
     * Give the service account a placeholder for any required custom profile
     * field it has left empty, so `user_not_fully_set_up()` passes and WS auth
     * isn't rejected with `usernotfullysetup`. Mirrors the condition in
     * `profile_has_required_custom_fields_set()` (required, not locked, empty).
     * The value is immaterial — the account never uses the site UI.
     *
     * @param int $userid
     * @return string[] shortnames of the fields filled (empty if none needed)
     */
    protected static function satisfy_required_profile_fields(int $userid): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $filled = [];
        foreach (profile_get_user_fields_with_data($userid) as $field) {
            if (!$field->is_required() || $field->is_locked() || !$field->is_empty()) {
                continue;
            }
            $fieldid = (int) $field->fieldid;
            $existing = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $fieldid]);
            if ($existing) {
                $existing->data = self::PROFILE_PLACEHOLDER;
                $existing->dataformat = FORMAT_MOODLE;
                $DB->update_record('user_info_data', $existing);
            } else {
                $DB->insert_record('user_info_data', (object) [
                    'userid' => $userid,
                    'fieldid' => $fieldid,
                    'data' => self::PROFILE_PLACEHOLDER,
                    'dataformat' => FORMAT_MOODLE,
                ]);
            }
            $filled[] = $field->field->shortname;
        }
        return $filled;
    }
}
