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
 * Report-recipient parsing and access.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Parses and exposes the site's report-recipient list.
 *
 * The recipient list is admin-entered free text (stored in the alertemails
 * config) naming who the central Sentinel dashboard should send enhanced
 * reports to for this site. The plugin itself sends nothing — it only forwards
 * this list in the snapshot. Lives in its own autoloadable class (rather than
 * the alerts.php web page) so the snapshot collector can read it under cron/WS.
 */
class recipients {
    /**
     * Parse a textarea of recipients into a clean, deduped list.
     *
     * Splits on whitespace, commas, and semicolons; preserves entry order;
     * validates each address. Returns [list, null] on success, [list_so_far,
     * first_invalid] on validation failure.
     *
     * @param string $raw
     * @return array{0: string[], 1: string|null}
     */
    public static function parse(string $raw): array {
        $valid = [];
        $seen = [];
        foreach (preg_split('/[\s,;]+/', $raw) as $token) {
            $token = trim($token);
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            if (!validate_email($token)) {
                return [$valid, $token];
            }
            $seen[$token] = true;
            $valid[] = $token;
        }
        return [$valid, null];
    }

    /**
     * Read the stored recipient config and return the valid addresses only.
     *
     * The stored value is pre-validated on save, so any invalid trailing token
     * is simply dropped here rather than reported.
     *
     * @return string[]
     */
    public static function all(): array {
        [$valid] = self::parse((string) get_config('local_sentinel', 'alertemails'));
        return $valid;
    }
}
