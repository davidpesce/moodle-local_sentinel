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
 * CLI: bootstrap Sentinel web service access.
 *
 * Thin wrapper around \local_sentinel\setup\helper::run() so this and the
 * admin web page (Site admin → Plugins → Local plugins → Sentinel setup)
 * stay in lockstep.
 *
 * Idempotent — safe to re-run. Reuses existing role/user/token unless
 * --regenerate is passed.
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
        'username' => 'sentinel',
        'rolename' => 'Sentinel',
        'roleshortname' => 'sentinel',
        'regenerate' => false,
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
        "Bootstrap Sentinel web service access.\n\n" .
        "Options:\n" .
        "  -h, --help               Show this help.\n" .
        "  --username=<name>        Webservice username (default: sentinel).\n" .
        "  --rolename=<name>        Role display name (default: 'Sentinel').\n" .
        "  --roleshortname=<name>   Role short name (default: sentinel).\n" .
        "  --regenerate             Delete the existing token and create a fresh one.\n"
    );
    exit(0);
}

cli_heading('Sentinel setup');

try {
    $result = \local_sentinel\setup\helper::run(
        [
            'username' => $options['username'],
            'rolename' => $options['rolename'],
            'roleshortname' => $options['roleshortname'],
        ],
        (bool) $options['regenerate']
    );
} catch (\Throwable $e) {
    cli_error('Setup failed: ' . $e->getMessage());
}

foreach ($result->steps as $step) {
    cli_writeln('  ' . $step);
}

cli_heading('Done');
cli_writeln('Token:      ' . $result->token);
cli_writeln('Endpoint:   ' . $result->endpoint);
cli_writeln('');
cli_writeln('Quick test:');
cli_writeln(
    "  curl '" . $result->endpoint .
    "?wstoken={$result->token}&wsfunction=local_sentinel_get_status&moodlewsrestformat=json'"
);

exit(0);
