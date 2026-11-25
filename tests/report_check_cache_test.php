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
 * Tests for caching the expensive report checks (core DB schema check).
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

use local_sentinel\collectors\reports;

/**
 * Report check cache tests.
 *
 * @covers \local_sentinel\collectors\reports
 * @covers \local_sentinel\task\refresh_checks
 */
final class report_check_cache_test extends \advanced_testcase {
    /**
     * Find the dbschema entry in a reports slice.
     *
     * @param array $slice
     * @return array
     */
    private function dbschema_entry(array $slice): array {
        foreach ($slice['performance']['checks'] as $entry) {
            if ($entry['ref'] === 'core_dbschema') {
                return $entry;
            }
        }
        $this->fail('core_dbschema missing from the performance checks');
    }

    /**
     * Overwrite the cached dbschema result with a recognisable marker.
     *
     * @param string|null $hash Version hash to store; defaults to the current one.
     * @param int|null $checkedat Defaults to now.
     */
    private function plant_marker(?string $hash = null, ?int $checkedat = null): void {
        global $CFG;
        set_config(reports::CACHE_CONFIG_KEY, json_encode(['core_dbschema' => [
            'status' => 'critical',
            'summary' => 'cached-marker',
            'checkedat' => $checkedat ?? time(),
            'hash' => $hash ?? (string) $CFG->allversionshash,
        ]]), 'local_sentinel');
    }

    public function test_dbschema_runs_once_then_serves_from_cache(): void {
        global $DB;
        $this->resetAfterTest();
        reports::purge_cache();

        $before = $DB->perf_get_queries();
        $first = reports::collect();
        $coldqueries = $DB->perf_get_queries() - $before;

        $cache = json_decode(get_config('local_sentinel', reports::CACHE_CONFIG_KEY), true);
        foreach (reports::CACHED_CHECKS as $ref) {
            // Tests run under CLI; SAPI-sensitive checks are cached per SAPI.
            $key = in_array($ref, reports::SAPI_SENSITIVE_CHECKS, true) ? $ref . '@cli' : $ref;
            $this->assertArrayHasKey($key, $cache);
            $this->assertGreaterThan(time() - 60, $cache[$key]['checkedat']);
        }

        $before = $DB->perf_get_queries();
        $second = reports::collect();
        $warmqueries = $DB->perf_get_queries() - $before;

        // The schema check (and, on MySQL, the environment check) reads every
        // table's catalog; served from the cache, the whole reports slice is a
        // handful of queries on any database.
        $this->assertGreaterThan(200, $coldqueries - $warmqueries);
        $this->assertLessThan(50, $warmqueries);
        // Identical payload either way (same shape, same values).
        $this->assertSame($first['performance'], $second['performance']);
    }

    public function test_cached_result_is_reported_with_counts(): void {
        $this->resetAfterTest();
        $this->plant_marker();

        $performance = reports::collect()['performance'];

        $entry = $this->dbschema_entry(['performance' => $performance]);
        $this->assertSame('critical', $entry['status']);
        $this->assertSame('cached-marker', $entry['summary']);
        $this->assertSame('core', $entry['component']);
        $this->assertNotEmpty($entry['name']);
        $this->assertGreaterThanOrEqual(1, $performance['counts_by_status']['critical']);
        $this->assertSame($performance['total'], array_sum($performance['counts_by_status']));
    }

    public function test_upgrade_invalidates_cache(): void {
        $this->resetAfterTest();
        $this->plant_marker('hash-before-upgrade');

        $entry = $this->dbschema_entry(reports::collect());

        $this->assertNotSame('cached-marker', $entry['summary']);
        $cache = json_decode(get_config('local_sentinel', reports::CACHE_CONFIG_KEY), true);
        $this->assertNotSame('hash-before-upgrade', $cache['core_dbschema']['hash']);
    }

    public function test_expired_cache_is_recomputed_inline(): void {
        $this->resetAfterTest();
        $this->plant_marker(null, time() - reports::CACHE_MAX_AGE - 1);

        $entry = $this->dbschema_entry(reports::collect());

        $this->assertNotSame('cached-marker', $entry['summary']);
    }

    public function test_refresh_task_skips_until_a_snapshot_populated_the_cache(): void {
        $this->resetAfterTest();
        reports::purge_cache();

        $this->assertSame([], reports::refresh_cached_checks());
        $this->assertFalse(get_config('local_sentinel', reports::CACHE_CONFIG_KEY));
    }

    public function test_refresh_task_forces_recompute(): void {
        $this->resetAfterTest();
        $this->plant_marker();

        ob_start();
        (new task\refresh_checks())->execute();
        $output = ob_get_clean();

        $this->assertStringContainsString('core_dbschema', $output);
        $entry = $this->dbschema_entry(reports::collect());
        $this->assertNotSame('cached-marker', $entry['summary']);
    }

    /**
     * Find the core_environment entry in a reports slice.
     *
     * @param array $slice
     * @return array
     */
    private function environment_entry(array $slice): array {
        foreach ($slice['system_status']['checks'] as $entry) {
            if ($entry['ref'] === 'core_environment') {
                return $entry;
            }
        }
        $this->fail('core_environment missing from the status checks');
    }

    /**
     * A reports collector that believes it runs under a web server SAPI.
     *
     * @return reports
     */
    private function web_reports(): reports {
        return new class extends reports {
            /**
             * Pretend to be a web request.
             *
             * @return string
             */
            protected static function sapi_class(): string {
                return 'web';
            }
        };
    }

    public function test_environment_check_is_cached_per_sapi(): void {
        global $CFG;
        $this->resetAfterTest();
        reports::purge_cache();

        // Cron (CLI) computes and caches its own reading.
        reports::collect();
        $cache = json_decode(get_config('local_sentinel', reports::CACHE_CONFIG_KEY), true);
        $this->assertArrayHasKey('core_environment@cli', $cache);
        $this->assertArrayNotHasKey('core_environment@web', $cache);
        $this->assertArrayHasKey('core_dbschema', $cache);

        // Mark the CLI reading so we can tell whether a web request is served it.
        $cache['core_environment@cli']['summary'] = 'cli-marker';
        set_config(reports::CACHE_CONFIG_KEY, json_encode($cache), 'local_sentinel');

        $web = $this->web_reports();
        $entry = $this->environment_entry($web::collect());
        $this->assertNotSame('cli-marker', $entry['summary']);

        $cache = json_decode(get_config('local_sentinel', reports::CACHE_CONFIG_KEY), true);
        $this->assertArrayHasKey('core_environment@web', $cache);
        $this->assertSame('cli-marker', $cache['core_environment@cli']['summary']);
        $this->assertSame((string) $CFG->allversionshash, $cache['core_environment@web']['hash']);

        // CLI is still served its own cached reading.
        $this->assertSame('cli-marker', $this->environment_entry(reports::collect())['summary']);
    }

    public function test_legacy_shared_environment_entry_is_ignored_and_dropped(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config(reports::CACHE_CONFIG_KEY, json_encode(['core_environment' => [
            'status' => 'error',
            'summary' => 'legacy-marker',
            'checkedat' => time(),
            'hash' => (string) $CFG->allversionshash,
        ]]), 'local_sentinel');

        $web = $this->web_reports();
        $entry = $this->environment_entry($web::collect());

        $this->assertNotSame('legacy-marker', $entry['summary']);
        $cache = json_decode(get_config('local_sentinel', reports::CACHE_CONFIG_KEY), true);
        $this->assertArrayNotHasKey('core_environment', $cache);
    }
}
