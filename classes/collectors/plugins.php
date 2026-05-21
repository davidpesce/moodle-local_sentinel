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
 * Plugin inventory collector.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\collectors;

use core_plugin_manager;

/**
 * Inventories installed plugins, separating standard core plugins from extensions.
 */
class plugins {
    /**
     * Collect.
     *
     * @return array
     */
    public static function collect(): array {
        $pluginman = core_plugin_manager::instance();
        $updates = self::collect_updates($pluginman);

        // Index updates by component for O(1) lookup when annotating each entry.
        $updatesindex = [];
        foreach ($updates as $update) {
            $updatesindex[$update['component']] = $update;
        }

        $standard = [];
        $thirdparty = [];

        foreach ($pluginman->get_plugins() as $type => $plugintypes) {
            foreach ($plugintypes as $plugin) {
                $status = $plugin->get_status();
                $upstream = $updatesindex[$plugin->component] ?? null;
                $entry = [
                    'type' => $type,
                    'component' => $plugin->component,
                    'name' => $plugin->displayname,
                    'version_disk' => isset($plugin->versiondisk) ? (int) $plugin->versiondisk : null,
                    'version_db' => isset($plugin->versiondb) ? (int) $plugin->versiondb : null,
                    'release' => $plugin->release ?? null,
                    'source' => $plugin->source ?? null,
                    'status' => $status,
                    'missing_from_disk' => $status === core_plugin_manager::PLUGIN_STATUS_MISSING,
                    'update_available' => $upstream !== null,
                    'version_latest' => $upstream['version'] ?? null,
                    'enabled' => $pluginman->get_plugin_info($plugin->component)->is_enabled(),
                ];
                if (($plugin->source ?? '') === core_plugin_manager::PLUGIN_SOURCE_STANDARD) {
                    $standard[] = $entry;
                } else {
                    $thirdparty[] = $entry;
                }
            }
        }

        return [
            'standard' => $standard,
            'third_party' => $thirdparty,
            'updates_available' => $updates,
            'update_check' => self::collect_update_check(),
            'theme' => self::collect_theme(),
        ];
    }

    /**
     * Freshness metadata about Moodle's update checker.
     *
     * `updates_available` reflects the last cached fetch from moodle.org/updates;
     * it does not trigger a new fetch. Use these fields to judge how stale that
     * data is. Run cli/refresh_updates.php to force a refresh on demand.
     *
     * @return array
     */
    protected static function collect_update_check(): array {
        $checker = \core\update\checker::instance();
        $lastfetched = (int) $checker->get_last_timefetched();
        $now = time();
        return [
            'enabled' => (bool) $checker->enabled(),
            'last_fetched' => $lastfetched > 0 ? $lastfetched : null,
            'age_seconds' => $lastfetched > 0 ? $now - $lastfetched : null,
        ];
    }

    /**
     * Available updates known to core_plugin_manager (driven by Moodle's plugin update check).
     *
     * @param core_plugin_manager $pluginman
     * @return array
     */
    protected static function collect_updates(core_plugin_manager $pluginman): array {
        $updates = $pluginman->available_updates();
        if (empty($updates)) {
            return [];
        }
        $out = [];
        foreach ($updates as $component => $remoteinfo) {
            // available_updates() returns one \core\update\remote_info per component;
            // the actual version metadata lives in $remoteinfo->version (a stdClass).
            $v = $remoteinfo->version ?? null;
            $out[] = [
                'component' => $component,
                'version' => $v->version ?? null,
                'release' => $v->release ?? null,
                'maturity' => $v->maturity ?? null,
                'download' => $v->downloadurl ?? null,
                'source_url' => $remoteinfo->source ?? null,
            ];
        }
        return $out;
    }

    /**
     * Collect theme.
     *
     * @return array
     */
    protected static function collect_theme(): array {
        global $CFG;

        $name = $CFG->theme;
        $pluginman = core_plugin_manager::instance();
        $info = $pluginman->get_plugin_info('theme_' . $name);
        return [
            'name' => $name,
            'version' => $info ? (int) $info->versiondisk : null,
            'release' => $info->release ?? null,
            'source' => $info->source ?? null,
        ];
    }
}
