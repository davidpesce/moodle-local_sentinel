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
 * Privacy provider for local_sentinel.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;

/**
 * Privacy provider for local_sentinel.
 *
 * The plugin does not store any personal data in its own tables (it has no
 * install.xml). It does, however, read a small set of personal data from
 * Moodle's existing tables (admin usernames, last-login timestamps, who made
 * recent admin-setting changes, web-service token owners, top failed-login
 * accounts) and send it to an external Sentinel dashboard via either a
 * scheduled push or a web-service pull. That external transmission is
 * declared below via add_external_location_link().
 *
 * Site admins can opt out of two of the more sensitive field groups
 * (auth.failed_logins.top_accounts and auth.tokens.entries) per-site on the
 * Sentinel Settings admin page; everything else flows unconditionally when
 * the dashboard requests a snapshot or the push task runs.
 *
 * No core_userlist_provider / request\plugin\provider implementations are
 * needed — the plugin holds no local user data to export or delete.
 */
class provider implements metadata_provider {
    /**
     * Declare the personal data this plugin transmits to the central
     * Sentinel dashboard.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link(
            'sentinel_dashboard',
            [
                'siteidentifier'             => 'privacy:metadata:sentinel_dashboard:siteidentifier',
                'admin_username'             => 'privacy:metadata:sentinel_dashboard:admin_username',
                'admin_lastlogin'            => 'privacy:metadata:sentinel_dashboard:admin_lastlogin',
                'admin_lastaccess'           => 'privacy:metadata:sentinel_dashboard:admin_lastaccess',
                'config_change_userid'       => 'privacy:metadata:sentinel_dashboard:config_change_userid',
                'config_change_username'     => 'privacy:metadata:sentinel_dashboard:config_change_username',
                'failed_login_userid'        => 'privacy:metadata:sentinel_dashboard:failed_login_userid',
                'failed_login_username'      => 'privacy:metadata:sentinel_dashboard:failed_login_username',
                'failed_login_count'         => 'privacy:metadata:sentinel_dashboard:failed_login_count',
                'failed_login_lastfailure'   => 'privacy:metadata:sentinel_dashboard:failed_login_lastfailure',
                'failed_login_lastlogin'     => 'privacy:metadata:sentinel_dashboard:failed_login_lastlogin',
                'token_owner_username'       => 'privacy:metadata:sentinel_dashboard:token_owner_username',
                'token_lastaccess'           => 'privacy:metadata:sentinel_dashboard:token_lastaccess',
                'token_created'              => 'privacy:metadata:sentinel_dashboard:token_created',
            ],
            'privacy:metadata:sentinel_dashboard'
        );
        return $collection;
    }
}
