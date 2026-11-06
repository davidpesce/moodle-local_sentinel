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
 * Scheduled task definitions for local_sentinel.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    // Every 15 minutes at a per-site minute offset (see
    // push_snapshot::default_minute()), so a fleet sharing a database server
    // does not collect in the same second. Enabled, but execute() self-gates on
    // the pushenabled setting + endpoint + secret, so it no-ops until a dashboard
    // is connected. It must ship enabled: core resets non-customised tasks to
    // these defaults on every upgrade, and a disabled default there switched
    // push off again on sites that registration had enabled.
    [
        'classname' => 'local_sentinel\\task\\push_snapshot',
        'blocking' => 0,
        'minute' => \local_sentinel\task\push_snapshot::default_minute(),
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    // Daily, random time per site: refresh the report checks too expensive to
    // run on every snapshot (core DB schema and environment checks). Self-gates
    // until a snapshot has been collected.
    [
        'classname' => 'local_sentinel\\task\\refresh_checks',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => 'R',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    [
        'classname' => 'local_sentinel\\task\\refresh_updates',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => 'R',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    // Weekly core integrity scan. Enabled, but execute() self-gates on the
    // integrityenabled setting + manifest presence, so it no-ops until the
    // feature is provisioned (same pattern as push_snapshot's setting gate).
    [
        'classname' => 'local_sentinel\\task\\integrity_scan',
        'blocking' => 0,
        'minute' => 'R',
        'hour' => 'R',
        'day' => '*',
        'month' => '*',
        'dayofweek' => 'R',
        'disabled' => 0,
    ],
];
