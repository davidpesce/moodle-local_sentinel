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
 * Thin caching wrapper around the snapshot collector.
 *
 * Used by the Overview admin page only; WS endpoints continue to call
 * collector::get_snapshot() directly so external consumers always get
 * fresh data.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * MUC-backed snapshot cache for the Overview admin page.
 */
class cache_helper {
    /** Cache key — single shared entry for the whole site. */
    private const KEY = 'current';

    /**
     * Return the cached snapshot, populating the cache on a miss.
     *
     * Collector exceptions propagate — failures are NOT cached.
     *
     * @return array
     */
    public static function get_snapshot(): array {
        $cache = \cache::make('local_sentinel', 'snapshot');
        $snapshot = $cache->get(self::KEY);
        if ($snapshot === false) {
            $snapshot = collector::get_snapshot();
            $cache->set(self::KEY, $snapshot);
        }
        return $snapshot;
    }

    /**
     * Drop the cached snapshot so the next get_snapshot() re-runs collectors.
     */
    public static function purge(): void {
        \cache::make('local_sentinel', 'snapshot')->purge();
    }
}
