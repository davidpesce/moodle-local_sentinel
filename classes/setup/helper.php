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

        // 5a. The service user's auth plugin must be enabled. Moodle 5.3+
        // (core_auth\validate_user) rejects WS tokens whose user authenticates
        // through a disabled auth plugin, reported as errorcode
        // wsaccessusernologin. 'webservice' is disabled on a stock install, and
        // earlier Moodle versions never checked, so setup didn't enable it.
        if (self::ensure_auth_enabled($user)) {
            $result->enabledauthnow = true;
            $result->steps[] = "Enabled the '{$user->auth}' authentication plugin.";
        } else {
            $result->steps[] = "Authentication plugin '{$user->auth}' already enabled.";
        }

        // 5b. Required custom profile fields would otherwise make the service
        // account "not fully set up", which Moodle rejects WS auth for
        // (errorcode usernotfullysetup). The account never uses the site UI, so
        // fill each blocking field with a value valid for its type. The same
        // repair runs at request time (external\base) for fields added later.
        $repair = self::satisfy_required_profile_fields((int) $user->id);
        if ($repair['filled']) {
            $result->steps[] = 'Filled required profile field(s) for the service user: '
                . implode(', ', $repair['filled']) . '.';
        }
        if ($repair['unfillable']) {
            $result->steps[] = 'WARNING: the service user is still not fully set up. Set a value by hand on '
                . "the '{$user->username}' user for: " . implode(', ', $repair['unfillable']) . '.';
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
     * Enable the auth plugin the given user authenticates through, if it isn't.
     *
     * Moodle 5.3+ refuses WS tokens for users whose auth plugin is disabled
     * (errorcode wsaccessusernologin). 'nologin' is never enabled; 'manual'
     * is always on, so both are left alone.
     *
     * @param \stdClass $user Needs the 'auth' field.
     * @return bool True if the plugin was enabled by this call.
     */
    public static function ensure_auth_enabled(\stdClass $user): bool {
        $auth = (string) $user->auth;
        if ($auth === '' || $auth === 'nologin' || is_enabled_auth($auth) || !exists_auth_plugin($auth)) {
            return false;
        }
        \core\plugininfo\auth::enable_plugin($auth, 1);
        return true;
    }

    /**
     * True if a user created by {@see run()} exists under the given username.
     *
     * @param string $username
     * @return \stdClass|null The user record, or null if absent or not ours.
     */
    public static function provisioned_user(string $username = 'sentinel'): ?\stdClass {
        global $DB;
        $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
        return ($user && self::owns_user($user)) ? $user : null;
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

    /** Value stored in required free-text profile fields for the service account. */
    protected const PROFILE_PLACEHOLDER = 'n/a';

    /** Core free-text field types, where any non-empty value is valid. */
    protected const TEXT_FIELD_CLASSES = ['profile_field_text', 'profile_field_textarea', 'profile_field_social'];

    /**
     * True if the user record is a service account created by {@see run()}.
     *
     * @param \stdClass $user Needs 'username', 'auth' and 'email'.
     * @return bool
     */
    public static function is_service_account(\stdClass $user): bool {
        return isset($user->username, $user->auth, $user->email) && self::owns_user($user);
    }

    /**
     * Shortnames of the custom profile fields that make the user "not fully set
     * up" — the condition in core's `profile_has_required_custom_fields_set()`.
     *
     * @param int $userid
     * @return string[]
     */
    public static function blocking_profile_fields(int $userid): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $blocking = [];
        foreach (profile_get_user_fields_with_data($userid) as $field) {
            if (self::is_blocking($field)) {
                $blocking[] = $field->field->shortname;
            }
        }
        return $blocking;
    }

    /**
     * Fill every custom profile field that blocks WS access for the service
     * account with a value valid for the field's type, so
     * `user_not_fully_set_up()` passes and WS auth isn't rejected with
     * `usernotfullysetup`.
     *
     * A field only blocks when it is required, unlocked, visible and empty —
     * and an unset field already shows its default, so a blocking field has no
     * usable default. Values by type:
     *  - dropdown (core `menu`, or any type using its public `$options`
     *    convention): the first real option;
     *  - checkbox: the field's default (a saved row is what counts);
     *  - date/time: now;
     *  - core free-text types: {@see PROFILE_PLACEHOLDER};
     *  - anything else: left alone and reported as unfillable — no guessing at
     *    what a third-party type accepts.
     *
     * Also repairs the placeholder an earlier release wrote into dropdowns,
     * which passed Moodle's emptiness check but isn't one of the options.
     *
     * @param int $userid
     * @return array{filled: string[], unfillable: string[]} Field shortnames.
     */
    public static function satisfy_required_profile_fields(int $userid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $result = ['filled' => [], 'unfillable' => []];
        foreach (profile_get_user_fields_with_data($userid) as $field) {
            $blocking = self::is_blocking($field);
            if (!$blocking && !self::has_invalid_placeholder($field)) {
                continue;
            }
            $value = self::fill_value($field);
            if ($value === null) {
                if ($blocking) {
                    $result['unfillable'][] = $field->field->shortname;
                }
                continue;
            }
            $record = [
                'data' => $value,
                'dataformat' => get_class($field) === 'profile_field_textarea' ? FORMAT_HTML : FORMAT_MOODLE,
            ];
            $keys = ['userid' => $userid, 'fieldid' => (int) $field->fieldid];
            if ($id = $DB->get_field('user_info_data', 'id', $keys)) {
                $DB->update_record('user_info_data', (object) (['id' => $id] + $record));
            } else {
                $DB->insert_record('user_info_data', (object) ($keys + $record));
            }
            $result['filled'][] = $field->field->shortname;
        }
        return $result;
    }

    /**
     * Whether the field makes its user "not fully set up" (mirrors core).
     *
     * @param \profile_field_base $field
     * @return bool
     */
    protected static function is_blocking(\profile_field_base $field): bool {
        return $field->is_required() && !$field->is_locked() && $field->is_empty()
            && !empty($field->get_field_config_for_external()['visible']);
    }

    /**
     * A type-valid value for the field, or null if its type is not one we can
     * fill safely.
     *
     * @param \profile_field_base $field
     * @return string|null
     */
    protected static function fill_value(\profile_field_base $field): ?string {
        $options = self::dropdown_options($field);
        if ($options !== null) {
            return $options ? $options[0] : null;
        }
        if ($field instanceof \profile_field_checkbox) {
            return (string) (int) $field->field->defaultdata;
        }
        if ($field instanceof \profile_field_datetime) {
            return (string) time();
        }
        if (in_array(get_class($field), self::TEXT_FIELD_CLASSES, true)) {
            return self::PROFILE_PLACEHOLDER;
        }
        return null;
    }

    /**
     * The stored values a dropdown-style field accepts, or null if the field is
     * not a dropdown. Core's menu type — and custom select types that copy it —
     * expose a public `$options` array keyed by the stored value, with '' as the
     * "Choose..." entry on required fields.
     *
     * @param \profile_field_base $field
     * @return string[]|null
     */
    protected static function dropdown_options(\profile_field_base $field): ?array {
        if (!($field instanceof \profile_field_menu) && !(property_exists($field, 'options') && is_array($field->options))) {
            return null;
        }
        $values = array_map('strval', array_keys((array) $field->options));
        return array_values(array_filter($values, static fn(string $v): bool => $v !== ''));
    }

    /**
     * True if the field holds the free-text placeholder an earlier release wrote
     * into every field type, on a dropdown where it isn't a valid option.
     *
     * @param \profile_field_base $field
     * @return bool
     */
    protected static function has_invalid_placeholder(\profile_field_base $field): bool {
        $options = self::dropdown_options($field);
        return $options !== null && (string) $field->data === self::PROFILE_PLACEHOLDER
            && !in_array(self::PROFILE_PLACEHOLDER, $options, true);
    }
}
