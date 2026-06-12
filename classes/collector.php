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
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Builds the snapshot payload by delegating to per-slice collectors.
 *
 * One source of truth: external functions and the push scheduled task both
 * call into this class so the JSON shape is identical regardless of transport.
 */
class collector {
    /** @var int Bump on breaking changes to the payload shape. Additive changes do not require a bump. */
    public const SCHEMA_VERSION = 3;

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
            'reports' => collectors\reports::collect(),
            'config_changes' => collectors\config_changes::collect(),
            'config_drift' => collectors\config_drift::collect(),
            'reporting' => collectors\reporting::collect(),
            'integrity' => collectors\integrity::collect(),
        ]);
    }

    /**
     * Build a single-slice envelope, used by the granular external functions.
     *
     * @param string $slice One of: status, environment, plugins, health, auth, reports,
     *                      config_changes, config_drift, reporting.
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
            'plugin' => self::get_plugin_identity(),
            // Declare what this site withholds so the dashboard can show
            // "excluded by the site" instead of treating it as missing data.
            'egress' => [
                'excluded_slices' => array_values(self::excluded_slices()),
                'excluded_fields' => array_values(self::excluded_fields()),
            ],
        ], $data);
    }

    /**
     * Self-identification for the local_sentinel plugin itself.
     *
     * Lets a central dashboard detect instances running outdated plugin
     * versions and parse older snapshots correctly when SCHEMA_VERSION
     * has bumped.
     *
     * @return array
     */
    public static function get_plugin_identity(): array {
        $info = \core_plugin_manager::instance()->get_plugin_info('local_sentinel');
        return [
            'component' => 'local_sentinel',
            'version' => $info ? (int) $info->versiondisk : null,
            'release' => $info && isset($info->release) ? (string) $info->release : null,
        ];
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

    /** All top-level slice names the snapshot contains. */
    public const ALL_SLICES = [
        'status', 'environment', 'plugins', 'health',
        'auth', 'reports', 'config_changes', 'config_drift', 'reporting',
        'integrity',
    ];

    /** Sub-field dotted paths that admins can opt out of independently. */
    public const REDACTABLE_FIELDS = [
        'auth.failed_logins.top_accounts',
        'auth.tokens.entries',
        'environment.database.host',
        'environment.os.hostname',
    ];

    /**
     * Build the egress-filtered snapshot for WS / push consumers.
     *
     * @return array
     */
    public static function get_snapshot_for_egress(): array {
        return self::apply_egress_filter(self::get_snapshot());
    }

    /**
     * Build the egress-filtered envelope for a single slice (WS slice endpoint).
     *
     * If the requested slice is excluded by the admin, an envelope is still
     * returned (with site identity + plugin self-identification) but the
     * slice key is absent — the WS schema marks it as VALUE_OPTIONAL.
     *
     * @param string $slice
     * @return array
     */
    public static function get_slice_for_egress(string $slice): array {
        if (in_array($slice, self::excluded_slices(), true)) {
            return self::envelope(['site' => self::get_site_identity()]);
        }
        return self::apply_egress_filter(self::get_slice($slice));
    }

    /**
     * Read the admin-configured list of excluded slice names.
     *
     * @return string[]
     */
    public static function excluded_slices(): array {
        return self::decode_list((string) get_config('local_sentinel', 'egress_excluded_slices'));
    }

    /**
     * Read the admin-configured list of excluded dotted field paths.
     *
     * @return string[]
     */
    public static function excluded_fields(): array {
        return self::decode_list((string) get_config('local_sentinel', 'egress_excluded_fields'));
    }

    /**
     * Decode a JSON list of strings as stored in plugin config.
     *
     * @param string $raw
     * @return string[]
     */
    protected static function decode_list(string $raw): array {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * Remove excluded slices and excluded sub-field paths from a snapshot envelope.
     *
     * Robust to missing keys at any level — operates in-place on a copy.
     *
     * Public so granular WS endpoints that rebuild a slice (e.g. with a custom
     * row limit) can re-apply the same filtering instead of returning raw data.
     *
     * @param array $envelope
     * @return array
     */
    public static function apply_egress_filter(array $envelope): array {
        foreach (self::excluded_slices() as $slice) {
            unset($envelope[$slice]);
        }
        foreach (self::excluded_fields() as $path) {
            self::unset_path($envelope, explode('.', $path));
        }
        return $envelope;
    }

    /**
     * Unset a deep array key by dotted-path segments.
     *
     * @param array    $array
     * @param string[] $segments
     */
    protected static function unset_path(array &$array, array $segments): void {
        if (empty($segments)) {
            return;
        }
        $head = array_shift($segments);
        if (!array_key_exists($head, $array)) {
            return;
        }
        if (empty($segments)) {
            unset($array[$head]);
            return;
        }
        if (is_array($array[$head])) {
            self::unset_path($array[$head], $segments);
        }
    }
}
