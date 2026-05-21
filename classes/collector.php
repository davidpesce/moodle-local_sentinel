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
 * Snapshot orchestrator.
 *
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_fleetmonitor;

/**
 * Builds the snapshot payload by delegating to per-slice collectors.
 *
 * One source of truth: external functions and the push scheduled task both
 * call into this class so the JSON shape is identical regardless of transport.
 */
class collector {
    /** @var int Bump on breaking changes to the payload shape. Additive changes do not require a bump. */
    public const SCHEMA_VERSION = 1;

    /**
     * Build the full snapshot envelope.
     *
     * @return array Envelope with schema_version, generated_at, site, and per-slice sections.
     */
    public static function get_snapshot(): array {
        return self::envelope([
            'site' => self::get_site_identity(),
            'status' => collectors\status::collect(),
            'environment' => collectors\environment::collect(),
            'plugins' => collectors\plugins::collect(),
            'health' => collectors\health::collect(),
            'auth' => collectors\auth::collect(),
            'config_changes' => collectors\config_changes::collect(),
        ]);
    }

    /**
     * Build a single-slice envelope, used by the granular external functions.
     *
     * @param string $slice One of: status, environment, plugins, health, auth, config_changes.
     * @return array Envelope containing only the requested slice plus site identity.
     */
    public static function get_slice(string $slice): array {
        $data = [
            'site' => self::get_site_identity(),
            $slice => self::collect_slice($slice),
        ];
        return self::envelope($data);
    }

    /**
     * Dispatch to the named collector class.
     *
     * @param string $slice Slice name matching the collectors\ class name.
     * @return array
     */
    protected static function collect_slice(string $slice): array {
        $class = __NAMESPACE__ . '\\collectors\\' . $slice;
        return $class::collect();
    }

    /**
     * Wrap collected data with schema version and timestamp.
     *
     * @param array $data
     * @return array
     */
    protected static function envelope(array $data): array {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
        ], $data);
    }

    /**
     * Stable identifiers for this Moodle instance.
     *
     * Uses siteidentifier (not wwwroot) as the primary key, since wwwroot
     * changes on domain migrations.
     *
     * @return array
     */
    public static function get_site_identity(): array {
        global $CFG, $SITE;
        return [
            'wwwroot' => $CFG->wwwroot,
            'siteidentifier' => $CFG->siteidentifier,
            'sitename' => format_string($SITE->fullname),
            'shortname' => format_string($SITE->shortname),
        ];
    }
}
