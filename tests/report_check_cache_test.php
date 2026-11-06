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
            $this->assertArrayHasKey($ref, $cache);
            $this->assertGreaterThan(time() - 60, $cache[$ref]['checkedat']);
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
}
