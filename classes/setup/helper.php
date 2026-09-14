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
 * this so behaviour is identical regardless of trigger. {@see teardown()}
 * reverses steps 3–5 for db/uninstall.php.
 */
class helper {
    /** Description stamped on the role we create — also the marker that a role is ours. */
    private const ROLE_DESCRIPTION = 'Sentinel webservice access role.';

    /** Email domain stamped on the service user we create — also the marker that a user is ours. */
    private const USER_EMAIL_DOMAIN = 'example.invalid';

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
                self::ROLE_DESCRIPTION,
                ''
            );
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
            $result->createdrole = true;
            $result->steps[] = "Created role '{$opts['roleshortname']}'.";
        } else if (trim((string) $role->description) === self::ROLE_DESCRIPTION) {
            $roleid = (int) $role->id;
            $result->steps[] = "Reusing existing role '{$opts['roleshortname']}'.";
        } else {
            // A role with this shortname exists but wasn't created by us. Refuse to
            // repurpose it — the cap assignment below grants local/sentinel:manage
            // (RISK_CONFIG|RISK_DATALOSS) + WS access, which would silently
            // escalate every existing holder of that unrelated role.
            throw new \moodle_exception('roleconflict', 'local_sentinel', '', $opts['roleshortname']);
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
                'email' => $opts['username'] . '@' . self::USER_EMAIL_DOMAIN,
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

        // 7. Add user to the service — through the core API so the row shape
        // (and any bookkeeping core adds around service membership) stays
        // core's concern rather than a direct write to a table we don't own.
        $inservice = $DB->record_exists('external_services_users', [
            'externalserviceid' => $service->id,
            'userid' => $user->id,
        ]);
        if (!$inservice) {
            require_once($CFG->dirroot . '/webservice/lib.php');
            $webservicemanager = new \webservice();
            $webservicemanager->add_ws_authorised_user((object) [
                'externalserviceid' => $service->id,
                'userid' => $user->id,
                'iprestriction' => '',
                'validuntil' => 0,
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

    /**
     * Undo what {@see run()} provisioned — called from db/uninstall.php.
     *
     * Only objects still carrying our ownership markers are removed: the role
     * whose description is {@see ROLE_DESCRIPTION}, and the user created with
     * the 'webservice' auth method and a <username> address at example.invalid.
     * A same-named role or user an admin created or repurposed is left alone.
     *
     * Not touched here because core handles them when db/services.php is
     * uninstalled: the external service, its tokens and service-user rows.
     * Deliberately not reverted: $CFG->enablewebservices and the REST protocol —
     * other consumers may depend on them.
     *
     * @param array $options Same keys as {@see run()}: 'username', 'roleshortname'.
     * @return string[] Human-readable log of what was removed or left in place.
     */
    public static function teardown(array $options = []): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/accesslib.php');

        $opts = array_merge([
            'username' => 'sentinel',
            'roleshortname' => 'sentinel',
        ], $options);
        $steps = [];

        $role = $DB->get_record('role', ['shortname' => $opts['roleshortname']]);
        if ($role && trim((string) $role->description) === self::ROLE_DESCRIPTION) {
            delete_role((int) $role->id);
            $steps[] = "Deleted role '{$opts['roleshortname']}'.";
        } else if ($role) {
            $steps[] = "Left role '{$opts['roleshortname']}' in place (not created by Sentinel).";
        }

        $user = $DB->get_record('user', ['username' => $opts['username'], 'deleted' => 0]);
        if ($user && self::owns_user($user)) {
            delete_user($user);
            $steps[] = "Deleted user '{$opts['username']}'.";
        } else if ($user) {
            $steps[] = "Left user '{$opts['username']}' in place (not created by Sentinel).";
        }

        return $steps;
    }

    /**
     * True if the user record carries the markers {@see run()} stamps on the
     * service account it creates (webservice auth + a <username> address at example.invalid).
     *
     * @param \stdClass $user
     * @return bool
     */
    protected static function owns_user(\stdClass $user): bool {
        $expectedemail = strtolower($user->username . '@' . self::USER_EMAIL_DOMAIN);
        return $user->auth === 'webservice' && strtolower((string) $user->email) === $expectedemail;
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
