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
 * CLI: bootstrap Fleet Monitor web service access.
 *
 * Enables web services + REST, ensures the Fleet Monitor role and webservice
 * user exist, assigns the user to the service, and prints a token.
 *
 * Idempotent — safe to re-run. Will reuse existing role/user/token if found.
 *
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/accesslib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
        'username' => 'fleetmonitor',
        'rolename' => 'Fleet Monitor',
        'roleshortname' => 'fleetmonitor',
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln(
        "Bootstrap Fleet Monitor web service access.\n\n" .
        "Options:\n" .
        "  -h, --help               Show this help.\n" .
        "  --username=<name>        Webservice username (default: fleetmonitor).\n" .
        "  --rolename=<name>        Role display name (default: 'Fleet Monitor').\n" .
        "  --roleshortname=<name>   Role short name (default: fleetmonitor).\n"
    );
    exit(0);
}

global $DB;

cli_heading('Fleet Monitor setup');

// 1. Enable web services.
if (empty($CFG->enablewebservices)) {
    set_config('enablewebservices', 1);
    cli_writeln('  Enabled $CFG->enablewebservices.');
} else {
    cli_writeln('  Web services already enabled.');
}

// 2. Enable REST protocol.
$protocols = isset($CFG->webserviceprotocols) ? explode(',', $CFG->webserviceprotocols) : [];
$protocols = array_filter(array_map('trim', $protocols));
if (!in_array('rest', $protocols, true)) {
    $protocols[] = 'rest';
    set_config('webserviceprotocols', implode(',', $protocols));
    cli_writeln('  Enabled REST protocol.');
} else {
    cli_writeln('  REST protocol already enabled.');
}

// 3. Find the Fleet Monitor service (auto-created from db/services.php on install).
$service = $DB->get_record('external_services', ['shortname' => 'local_fleetmonitor']);
if (!$service) {
    cli_error(
        "Fleet Monitor service not found. Run Site administration → Notifications first " .
        "to finish plugin installation."
    );
}
cli_writeln("  Service '$service->shortname' present (id=$service->id).");

// 4. Create or find the role, ensure required capabilities.
$role = $DB->get_record('role', ['shortname' => $options['roleshortname']]);
if (!$role) {
    $roleid = create_role(
        $options['rolename'],
        $options['roleshortname'],
        'Fleet Monitor webservice access role.',
        ''
    );
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
    cli_writeln("  Created role '{$options['roleshortname']}' (id=$roleid).");
} else {
    $roleid = (int) $role->id;
    cli_writeln("  Reusing existing role '{$options['roleshortname']}' (id=$roleid).");
}

$systemcontext = context_system::instance();
$requiredcaps = ['webservice/rest:use', 'local/fleetmonitor:view'];
foreach ($requiredcaps as $cap) {
    assign_capability($cap, CAP_ALLOW, $roleid, $systemcontext->id, true);
}
cli_writeln('  Ensured role has: ' . implode(', ', $requiredcaps) . '.');

// 5. Create or find the webservice user.
$user = $DB->get_record('user', ['username' => $options['username'], 'deleted' => 0]);
if (!$user) {
    $new = (object) [
        'username' => $options['username'],
        'firstname' => 'Fleet',
        'lastname' => 'Monitor',
        'email' => $options['username'] . '@example.invalid',
        'auth' => 'webservice',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'password' => 'not used',
        'policyagreed' => 1,
    ];
    $new->id = user_create_user($new, false, false);
    $user = $new;
    cli_writeln("  Created user '{$user->username}' (id=$user->id).");
} else {
    cli_writeln("  Reusing existing user '{$user->username}' (id=$user->id).");
}

// 6. Assign role to user at system context.
$existing = $DB->record_exists('role_assignments', [
    'roleid' => $roleid,
    'userid' => $user->id,
    'contextid' => $systemcontext->id,
]);
if (!$existing) {
    role_assign($roleid, $user->id, $systemcontext->id);
    cli_writeln('  Assigned role at system context.');
} else {
    cli_writeln('  Role already assigned at system context.');
}

// 7. Add user to the service (mdl_external_services_users join).
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
    cli_writeln('  Added user to service.');
} else {
    cli_writeln('  User already on service.');
}

// 8. Find or generate token.
$token = $DB->get_record('external_tokens', [
    'externalserviceid' => $service->id,
    'userid' => $user->id,
    'tokentype' => EXTERNAL_TOKEN_PERMANENT,
]);
if (!$token) {
    $tokenstring = \core_external\util::generate_token(
        EXTERNAL_TOKEN_PERMANENT,
        $service,
        $user->id,
        $systemcontext,
        0,
        '',
        'Fleet Monitor token'
    );
    cli_writeln('  Generated new permanent token.');
} else {
    $tokenstring = $token->token;
    cli_writeln('  Reusing existing permanent token.');
}

cli_heading('Done');
cli_writeln('Token:      ' . $tokenstring);
cli_writeln('Endpoint:   ' . $CFG->wwwroot . '/webservice/rest/server.php');
cli_writeln('');
cli_writeln('Quick test:');
cli_writeln(
    "  curl '" . $CFG->wwwroot .
    "/webservice/rest/server.php?wstoken={$tokenstring}" .
    "&wsfunction=local_fleetmonitor_get_status&moodlewsrestformat=json'"
);

exit(0);
