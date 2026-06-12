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
 * Upgrade steps for local_sentinel.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the local_sentinel plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_sentinel_upgrade($oldversion): bool {
    global $DB;

    if ($oldversion < 2026061200) {
        // 2.21.0 introduces local/sentinel:manage for the write WS functions
        // (set_manifest / request_integrity_scan). Grant it to every role
        // that already holds local/sentinel:view at system context — in
        // practice the provisioned 'sentinel' service role — so existing
        // dashboard tokens can use the new functions without re-running setup.
        // Capabilities from db/access.php are normally installed AFTER
        // upgrade.php runs; install them now so the grant below can validate.
        update_capabilities('local_sentinel');

        $systemcontext = context_system::instance();
        $roleids = $DB->get_fieldset_select(
            'role_capabilities',
            'DISTINCT roleid',
            'capability = ? AND permission = ? AND contextid = ?',
            ['local/sentinel:view', CAP_ALLOW, $systemcontext->id]
        );
        foreach ($roleids as $roleid) {
            assign_capability('local/sentinel:manage', CAP_ALLOW, (int) $roleid, $systemcontext->id, true);
        }

        upgrade_plugin_savepoint(true, 2026061200, 'local', 'sentinel');
    }

    return true;
}
