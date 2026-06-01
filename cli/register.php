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
 * CLI: register this site with the configured Sentinel dashboard.
 *
 * Headless equivalent of the "Register with dashboard" button on the Connect
 * page — the entry point fleet automation (e.g. Ansible) uses to onboard many
 * sites at once after the dashboard base URL + enrollment key are configured.
 * Honours the same off-by-default gate and HTTPS-only guard as the UI action.
 *
 * Exit status: 0 if the dashboard accepted the registration (activated or
 * pending approval), 1 otherwise (disabled, misconfigured, rejected, or a
 * transport error).
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help']) {
    cli_writeln(
        "Register this site with the configured Sentinel dashboard.\n\n" .
        "Reads the local_sentinel registration settings (registrationenabled,\n" .
        "dashboardbaseurl, enrollmentkey) and submits a registration request.\n" .
        "Exit status is 0 when accepted (activated or pending), 1 otherwise.\n\n" .
        "Options:\n" .
        "  -h, --help   Show this help.\n"
    );
    exit(0);
}

[$ok, $message] = \local_sentinel\register::run();
cli_writeln($message);
exit($ok ? 0 : 1);
