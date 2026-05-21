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
 * CLI: force-refresh Moodle's available-updates cache.
 *
 * Equivalent to clicking "Check for available updates" at Site administration
 * → Notifications. Issues an outbound HTTP request to moodle.org/updates and
 * stores the result. Subsequent get_plugins / get_snapshot calls will reflect
 * the new data.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(['help' => false], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln(
        "Force-refresh Moodle's available-updates cache.\n\n" .
        "Options:\n" .
        "  -h, --help     Show this help.\n"
    );
    exit(0);
}

$checker = \core\update\checker::instance();
if (!$checker->enabled()) {
    cli_error('Update checker is disabled in this Moodle. Enable it in Site administration → '
        . 'Server → Update notifications, then re-run this command.');
}

$before = (int) $checker->get_last_timefetched();
cli_writeln('Last fetch: ' . ($before > 0 ? date('c', $before) : 'never'));
cli_writeln('Fetching from moodle.org/updates ...');
$checker->fetch();
$after = (int) $checker->get_last_timefetched();
cli_writeln('New fetch:  ' . date('c', $after));
exit(0);
