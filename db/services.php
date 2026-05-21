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
 * Web service function and service definitions for local_fleetmonitor.
 *
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_fleetmonitor_get_status' => [
        'classname' => 'local_fleetmonitor\\external\\get_status',
        'description' => 'Cheap liveness probe: version, branch, maintenance flag.',
        'type' => 'read',
        'capabilities' => 'local/fleetmonitor:view',
    ],
    'local_fleetmonitor_get_snapshot' => [
        'classname' => 'local_fleetmonitor\\external\\get_snapshot',
        'description' => 'Full monitoring snapshot for this Moodle instance.',
        'type' => 'read',
        'capabilities' => 'local/fleetmonitor:view',
    ],
    'local_fleetmonitor_get_environment' => [
        'classname' => 'local_fleetmonitor\\external\\get_environment',
        'description' => 'PHP, OS, DB, web server and extension details.',
        'type' => 'read',
        'capabilities' => 'local/fleetmonitor:view',
    ],
    'local_fleetmonitor_get_plugins' => [
        'classname' => 'local_fleetmonitor\\external\\get_plugins',
        'description' => 'Installed plugins, versions, available updates.',
        'type' => 'read',
        'capabilities' => 'local/fleetmonitor:view',
    ],
    'local_fleetmonitor_get_health' => [
        'classname' => 'local_fleetmonitor\\external\\get_health',
        'description' => 'Cron, scheduled tasks, sessions, disk, backups, mail.',
        'type' => 'read',
        'capabilities' => 'local/fleetmonitor:view',
    ],
    'local_fleetmonitor_get_auth' => [
        'classname' => 'local_fleetmonitor\\external\\get_auth',
        'description' => 'Enabled auth methods and user counts per method.',
        'type' => 'read',
        'capabilities' => 'local/fleetmonitor:view',
    ],
    'local_fleetmonitor_get_reports' => [
        'classname' => 'local_fleetmonitor\\external\\get_reports',
        'description' => 'Performance / Security / System status checks + MFA report.',
        'type' => 'read',
        'capabilities' => 'local/fleetmonitor:view',
    ],
    'local_fleetmonitor_get_config_changes' => [
        'classname' => 'local_fleetmonitor\\external\\get_config_changes',
        'description' => 'Recent entries from mdl_config_log.',
        'type' => 'read',
        'capabilities' => 'local/fleetmonitor:view',
    ],
    'local_fleetmonitor_get_config_drift' => [
        'classname' => 'local_fleetmonitor\\external\\get_config_drift',
        'description' => 'Settings whose current value differs from default (secrets excluded).',
        'type' => 'read',
        'capabilities' => 'local/fleetmonitor:view',
    ],
];

$services = [
    'Fleet Monitor' => [
        'functions' => [
            'local_fleetmonitor_get_status',
            'local_fleetmonitor_get_snapshot',
            'local_fleetmonitor_get_environment',
            'local_fleetmonitor_get_plugins',
            'local_fleetmonitor_get_health',
            'local_fleetmonitor_get_auth',
            'local_fleetmonitor_get_reports',
            'local_fleetmonitor_get_config_changes',
            'local_fleetmonitor_get_config_drift',
        ],
        'restrictedusers' => 1,
        'enabled' => 1,
        'shortname' => 'local_fleetmonitor',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
