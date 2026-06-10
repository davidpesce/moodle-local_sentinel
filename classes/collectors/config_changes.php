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
 * Recent config changes collector.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\collectors;

/**
 * Tails mdl_config_log, matching the data exposed by Reports → Config Changes.
 */
class config_changes {
    /** @var int Default number of rows to return. */
    public const DEFAULT_LIMIT = 50;

    /** @var string Placeholder shipped in place of a secret setting's value. */
    public const REDACTED = '__sentinel_redacted__';

    /**
     * Collect.
     *
     * @param int|null $limit Override the default row count.
     * @return array
     */
    public static function collect(?int $limit = null): array {
        global $DB;

        $limit = max(1, min(500, $limit ?? self::DEFAULT_LIMIT));

        // Pull username for accountability but skip firstname/lastname —
        // those add no operational value to a config-change audit, are
        // personal data we'd rather not ship, and make the privacy story
        // simpler.
        $sql = "SELECT cl.id, cl.timemodified, cl.userid, cl.plugin, cl.name,
                       cl.oldvalue, cl.value,
                       u.username
                  FROM {config_log} cl
             LEFT JOIN {user} u ON u.id = cl.userid
              ORDER BY cl.timemodified DESC, cl.id DESC";
        $rows = $DB->get_records_sql($sql, [], 0, $limit);

        $entries = [];
        foreach ($rows as $row) {
            // Moodle records the literal value in {config_log}, so a change to
            // an SMTP password, OAuth secret, API key, cronremotepassword, etc.
            // would otherwise ship off-site verbatim. Redact the value (keeping
            // the name for audit value) when the setting name looks secret-
            // bearing — same best-effort detection config_drift uses.
            $sensitive = config_drift::name_is_sensitive((string) $row->name);
            $entries[] = [
                'id' => (int) $row->id,
                'time' => (int) $row->timemodified,
                'userid' => (int) $row->userid,
                'username' => $row->username,
                'plugin' => $row->plugin,
                'name' => $row->name,
                'oldvalue' => $sensitive ? self::REDACTED : $row->oldvalue,
                'newvalue' => $sensitive ? self::REDACTED : $row->value,
            ];
        }
        return [
            'limit' => $limit,
            'count' => count($entries),
            'entries' => $entries,
        ];
    }
}
