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
 * Uninstall hook for local_sentinel.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove what the plugin left outside its own config and tables.
 *
 * Core already drops the plugin's config, its external service (with tokens
 * and service-user rows), scheduled tasks, caches and capabilities. This
 * covers the rest: the core-integrity artefacts under
 * $CFG->dataroot/local_sentinel/ and the service role + user the setup
 * helper provisioned — the latter only while they still carry the helper's
 * ownership markers (see {@see \local_sentinel\setup\helper::teardown()}).
 *
 * @return bool
 */
function xmldb_local_sentinel_uninstall(): bool {
    \local_sentinel\manifest_store::remove_all();
    \local_sentinel\setup\helper::teardown();
    return true;
}
