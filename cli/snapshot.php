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
 * CLI: dump the current snapshot to stdout as JSON.
 *
 * Useful for debugging the collector pipeline without going through the
 * web service layer. Always returns the full snapshot.
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
        'pretty' => false,
    ],
    [
        'h' => 'help',
        'p' => 'pretty',
    ]
);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help']) {
    cli_writeln(
        "Dump local_sentinel snapshot as JSON.\n\n" .
        "Options:\n" .
        "  -h, --help     Show this help.\n" .
        "  -p, --pretty   Pretty-print JSON (default: compact).\n"
    );
    exit(0);
}

$snapshot = \local_sentinel\collector::get_snapshot();
$flags = JSON_UNESCAPED_SLASHES;
if (!empty($options['pretty'])) {
    $flags |= JSON_PRETTY_PRINT;
}
echo json_encode($snapshot, $flags) . PHP_EOL;
exit(0);
