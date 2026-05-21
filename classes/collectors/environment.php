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
 * @package    local_fleetmonitor
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_fleetmonitor\collectors;

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
        return [
            'sysname' => php_uname('s'),
            'release' => php_uname('r'),
            'version' => php_uname('v'),
            'machine' => php_uname('m'),
            'hostname' => php_uname('n'),
        ];
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
        ];
    }

    /**
     * Collect opcache.
     *
     * @return array
     */
    protected static function collect_opcache(): array {
        if (!function_exists('opcache_get_status')) {
            return ['enabled' => false, 'reason' => 'opcache_get_status() not available'];
        }
        $status = @opcache_get_status(false);
        if ($status === false) {
            return ['enabled' => false, 'reason' => 'OPcache disabled'];
        }
        $memory = $status['memory_usage'] ?? [];
        $stats = $status['opcache_statistics'] ?? [];
        return [
            'enabled' => !empty($status['opcache_enabled']),
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
