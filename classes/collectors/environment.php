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
 * Environment collector: PHP, OS, DB, web server, extensions.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\collectors;

/**
 * Environment details for the underlying host and stack.
 */
class environment {
    /**
     * Collect.
     *
     * @return array
     */
    public static function collect(): array {
        return [
            'php' => self::collect_php(),
            'os' => self::collect_os(),
            'webserver' => self::collect_webserver(),
            'database' => self::collect_database(),
            'opcache' => self::collect_opcache(),
            'extensions' => self::collect_extensions(),
            'ssl' => self::collect_ssl(),
        ];
    }

    /**
     * Collect php.
     *
     * @return array
     */
    protected static function collect_php(): array {
        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'timezone' => date_default_timezone_get(),
        ];
    }

    /**
     * Collect os.
     *
     * @return array
     */
    protected static function collect_os(): array {
        $osrelease = self::os_release();
        return [
            'sysname' => php_uname('s'),
            'release' => php_uname('r'),
            'version' => php_uname('v'),
            'machine' => php_uname('m'),
            'hostname' => php_uname('n'),
            // Linux distribution from /etc/os-release (php_uname only gives the
            // kernel). Lets the dashboard check distro EOL (e.g. Ubuntu 22.04).
            'distro' => $osrelease['ID'] ?? '',
            'distro_version' => self::distro_version($osrelease),
            'distro_name' => $osrelease['PRETTY_NAME'] ?? '',
            'package_updates' => self::collect_package_updates(),
        ];
    }

    /**
     * Pending OS package updates + reboot-required flag (Debian/Ubuntu, best-effort).
     *
     * Raw facts only — the dashboard interprets. Sources readable without root:
     *  - /var/lib/update-notifier/updates-available — text maintained by
     *    update-notifier-common, e.g. "97 updates can be applied immediately." /
     *    "60 of these updates are standard security updates."
     *  - /var/run/reboot-required — existence flags a pending reboot.
     *
     * On hosts without update-notifier (non-Debian, containers, RHEL) the counts
     * are null and `checked` is false; reboot_required is still reported when
     * the flag file is detectable.
     *
     * @return array
     */
    protected static function collect_package_updates(): array {
        $path = '/var/lib/update-notifier/updates-available';
        $counts = ['available' => null, 'security' => null];
        $checked = false;
        if (is_readable($path)) {
            $text = (string) @file_get_contents($path);
            if ($text !== '') {
                $counts = self::parse_updates_available($text);
                $checked = true;
            }
        }
        return [
            'checked' => $checked,
            'available' => $counts['available'],
            'security' => $counts['security'],
            'reboot_required' => file_exists('/var/run/reboot-required'),
            'source' => $checked ? 'update-notifier' : '',
        ];
    }

    /**
     * Parse update-notifier's updates-available text into counts.
     *
     * Locale caveat: the file is written in the system locale. The security
     * line is matched on the word "security" (English); on other locales the
     * security count stays null while the total may still parse. Best-effort
     * by design — null means "unknown", never 0.
     *
     * @param string $text contents of updates-available
     * @return array{available: int|null, security: int|null}
     */
    public static function parse_updates_available(string $text): array {
        $available = null;
        $security = null;
        foreach (preg_split('/\r?\n/', $text) as $line) {
            if (!preg_match('/\d+/', $line, $m)) {
                continue;
            }
            $count = (int) $m[0];
            if ($security === null && stripos($line, 'security') !== false) {
                $security = $count;
            } else if ($available === null) {
                $available = $count;
            }
        }
        return ['available' => $available, 'security' => $security];
    }

    /**
     * Most precise installed distro version from /etc/os-release.
     *
     * `VERSION_ID` carries only the cycle on some distros — notably Ubuntu, where
     * `VERSION_ID="24.04"` omits the point release that `VERSION` includes
     * (`VERSION="24.04.4 LTS (Noble Numbat)"`). Reporting only `VERSION_ID` makes
     * the dashboard compare the cycle ("24.04") against endoflife's latest patch
     * ("24.04.4") and wrongly flag an available update. Prefer the more precise
     * `VERSION` token when it extends `VERSION_ID`; otherwise fall back to
     * `VERSION_ID` (e.g. Debian "12", RHEL "9.4", which already carry the patch).
     *
     * @param array $osrelease parsed /etc/os-release map (key => value)
     * @return string
     */
    protected static function distro_version(array $osrelease): string {
        $versionid = $osrelease['VERSION_ID'] ?? '';
        $version = $osrelease['VERSION'] ?? '';
        $first = $version === '' ? '' : explode(' ', $version, 2)[0];
        if ($versionid !== '' && strpos($first, $versionid . '.') === 0) {
            return $first;
        }
        return $versionid;
    }

    /**
     * Parse /etc/os-release into a key => value map (empty on non-Linux/missing).
     *
     * The file is shell-style: KEY=value or KEY="value with spaces".
     *
     * @return array<string, string>
     */
    protected static function os_release(): array {
        $path = '/etc/os-release';
        if (!is_readable($path)) {
            return [];
        }
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (strpos($line, '=') === false || $line[0] === '#') {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\"'");
        }
        return $values;
    }

    /**
     * Collect webserver.
     *
     * @return array
     */
    protected static function collect_webserver(): array {
        $software = isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
        return [
            'software' => $software,
            'sapi_is_fpm' => PHP_SAPI === 'fpm-fcgi',
            'sapi_is_apache' => str_contains(PHP_SAPI, 'apache'),
            'sapi_is_cli' => PHP_SAPI === 'cli',
        ];
    }

    /**
     * Collect database.
     *
     * @return array
     */
    protected static function collect_database(): array {
        global $CFG, $DB;

        $info = $DB->get_server_info();
        return [
            'type' => $CFG->dbtype,
            'version' => $info['version'] ?? '',
            'description' => $info['description'] ?? '',
            'host' => $CFG->dbhost,
            'name' => $CFG->dbname,
            'prefix' => $CFG->prefix,
            'size_bytes' => self::db_size_bytes(),
            'largest_tables' => self::db_largest_tables(10),
        ];
    }

    /**
     * Total database size in bytes, dispatching on dbtype. Null if unsupported.
     *
     * @return int|null
     */
    protected static function db_size_bytes(): ?int {
        global $CFG, $DB;

        try {
            if ($CFG->dbtype === 'pgsql') {
                $val = $DB->get_field_sql('SELECT pg_database_size(current_database())');
            } else if (in_array($CFG->dbtype, ['mysqli', 'mariadb', 'auroramysql'], true)) {
                $val = $DB->get_field_sql(
                    'SELECT SUM(data_length + index_length) FROM information_schema.tables '
                    . 'WHERE table_schema = DATABASE()'
                );
            } else {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return $val !== false && $val !== null ? (int) $val : null;
    }

    /**
     * Top N largest Moodle tables by total size (data + indexes).
     *
     * Filtered to tables matching the configured prefix so legacy / unrelated
     * tables in the same schema don't pollute the list.
     *
     * @param int $limit
     * @return array
     */
    protected static function db_largest_tables(int $limit): array {
        global $CFG, $DB;

        $prefix = $CFG->prefix;
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $prefix) . '%';

        try {
            if ($CFG->dbtype === 'pgsql') {
                $sql = 'SELECT c.relname AS name, pg_total_relation_size(c.oid) AS size_bytes
                          FROM pg_class c
                          JOIN pg_namespace n ON n.oid = c.relnamespace
                         WHERE c.relkind = \'r\'
                           AND n.nspname = current_schema()
                           AND c.relname LIKE :like ESCAPE \'\\\'
                      ORDER BY pg_total_relation_size(c.oid) DESC';
                $rows = $DB->get_records_sql($sql, ['like' => $like], 0, $limit);
            } else if (in_array($CFG->dbtype, ['mysqli', 'mariadb', 'auroramysql'], true)) {
                $sql = 'SELECT table_name AS name, (data_length + index_length) AS size_bytes
                          FROM information_schema.tables
                         WHERE table_schema = DATABASE()
                           AND table_name LIKE :like ESCAPE \'\\\'
                      ORDER BY (data_length + index_length) DESC';
                $rows = $DB->get_records_sql($sql, ['like' => $like], 0, $limit);
            } else {
                return [];
            }
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'name' => $row->name,
                'size_bytes' => (int) $row->size_bytes,
            ];
        }
        return $out;
    }

    /**
     * Collect opcache.
     *
     * @return array
     */
    protected static function collect_opcache(): array {
        // OPcache is per-SAPI: each process pool (php-fpm/apache web workers, CLI)
        // has its own cache, and there is no API to read one SAPI's OPcache from
        // another. Under CLI (cron — e.g. the push_snapshot task) we cannot see the
        // web workers' OPcache, so report "not measurable" rather than asserting it
        // is disabled. A web-context read (a WS pull) carries the real reading.
        if (PHP_SAPI === 'cli') {
            return [
                'enabled' => false,
                'measurable' => false,
                'reason' => 'Not measurable from CLI/cron — OPcache is per-SAPI; read on a web request.',
            ];
        }
        if (!function_exists('opcache_get_status')) {
            return ['enabled' => false, 'measurable' => true, 'reason' => 'opcache_get_status() not available'];
        }
        $status = @opcache_get_status(false);
        if ($status === false) {
            return ['enabled' => false, 'measurable' => true, 'reason' => 'OPcache disabled'];
        }
        $memory = $status['memory_usage'] ?? [];
        $stats = $status['opcache_statistics'] ?? [];
        return [
            'enabled' => !empty($status['opcache_enabled']),
            'measurable' => true,
            'used_memory' => (int) ($memory['used_memory'] ?? 0),
            'free_memory' => (int) ($memory['free_memory'] ?? 0),
            'wasted_memory' => (int) ($memory['wasted_memory'] ?? 0),
            'num_cached_scripts' => (int) ($stats['num_cached_scripts'] ?? 0),
            'hits' => (int) ($stats['hits'] ?? 0),
            'misses' => (int) ($stats['misses'] ?? 0),
            'hit_rate' => (float) ($stats['opcache_hit_rate'] ?? 0),
        ];
    }

    /**
     * Collect extensions.
     *
     * @return array
     */
    protected static function collect_extensions(): array {
        $loaded = get_loaded_extensions();
        sort($loaded, SORT_STRING | SORT_FLAG_CASE);
        return $loaded;
    }

    /**
     * Best-effort SSL certificate inspection.
     *
     * Reads the cert presented by the public wwwroot if it's HTTPS. Fails
     * quietly on lookup errors — these are common on dev hosts and shouldn't
     * crash the collector.
     *
     * @return array
     */
    protected static function collect_ssl(): array {
        global $CFG;

        $parts = parse_url($CFG->wwwroot);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return ['checked' => false, 'reason' => 'wwwroot is not https'];
        }
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return ['checked' => false, 'reason' => 'no host in wwwroot'];
        }
        $port = (int) ($parts['port'] ?? 443);

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            'ssl://' . $host . ':' . $port,
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($client === false) {
            return ['checked' => false, 'reason' => "connect failed: $errstr"];
        }
        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert === null) {
            return ['checked' => false, 'reason' => 'no peer certificate captured'];
        }
        $parsed = openssl_x509_parse($cert);
        if ($parsed === false) {
            return ['checked' => false, 'reason' => 'openssl_x509_parse failed'];
        }
        $validto = (int) ($parsed['validTo_time_t'] ?? 0);
        $daysleft = $validto > 0 ? (int) floor(($validto - time()) / 86400) : null;
        return [
            'checked' => true,
            'host' => $host,
            'issuer' => $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? ''),
            'subject_cn' => $parsed['subject']['CN'] ?? '',
            'valid_from' => (int) ($parsed['validFrom_time_t'] ?? 0),
            'valid_to' => $validto,
            'days_remaining' => $daysleft,
        ];
    }
}
