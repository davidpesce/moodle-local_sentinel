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
 * Integrity scanner tests.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Tests for the core file integrity scanner and manifest store.
 *
 * @covers \local_sentinel\integrity_scanner
 * @covers \local_sentinel\manifest_store
 */
final class integrity_scanner_test extends \advanced_testcase {
    /**
     * git_blob_sha1 must match `git hash-object` for known vectors.
     */
    public function test_git_blob_sha1_known_vectors(): void {
        $this->resetAfterTest();
        global $CFG;
        $dir = make_temp_directory('local_sentinel_test');

        // The empty blob is git's most famous constant.
        $empty = $dir . '/empty.txt';
        file_put_contents($empty, '');
        $this->assertSame(
            'e69de29bb2d1d6434b8b29ae775ad8c2e48c5391',
            integrity_scanner::git_blob_sha1($empty)
        );

        // Matches what git hash-object reports for a file containing "hello\n".
        $hello = $dir . '/hello.txt';
        file_put_contents($hello, "hello\n");
        $this->assertSame(
            'ce013625030ba8dba906f756967f9e9ca394464a',
            integrity_scanner::git_blob_sha1($hello)
        );

        $this->assertNull(integrity_scanner::git_blob_sha1($dir . '/does-not-exist.txt'));
    }

    /**
     * core_version_full must report the literal version.php string, decimals
     * intact — $CFG->version goes through PHP float→string, which drops
     * trailing zeros on .00 builds and breaks the manifest lookup.
     */
    public function test_core_version_full_reads_disk_literal(): void {
        $this->resetAfterTest();
        $version = integrity_scanner::core_version_full();

        // Literal version.php shape: 10-digit date+increment, dot, TWO decimals.
        $this->assertMatchesRegularExpression('/^\d{10}\.\d{2}$/', $version);

        // And it matches the raw text of the version.php actually on disk.
        $base = rtrim(integrity_scanner::base_dir(), '/');
        $file = is_readable($base . '/version.php') ? $base . '/version.php' : $base . '/public/version.php';
        preg_match('/^\s*\$version\s*=\s*([0-9.]+)\s*;/m', file_get_contents($file), $m);
        $this->assertSame($m[1], $version);
    }

    /**
     * The exclusion list must cover VCS metadata, config.php and this plugin itself.
     */
    public function test_excluded_prefixes(): void {
        $this->resetAfterTest();
        $prefixes = integrity_scanner::excluded_prefixes();

        $this->assertContains('.git', $prefixes);
        $this->assertContains('config.php', $prefixes);
        $this->assertContains('vendor', $prefixes);
        // The local_sentinel plugin is non-standard, so its own directory must
        // be excluded (its base-relative path differs between 4.x and 5.1+).
        $found = false;
        foreach ($prefixes as $prefix) {
            if (str_ends_with($prefix, 'local/sentinel')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'local/sentinel must be excluded as a non-standard plugin');
    }

    /**
     * Manifest store round-trip: save, meta, map parsing, malformed lines skipped.
     */
    public function test_manifest_store_roundtrip(): void {
        $this->resetAfterTest();
        manifest_store::reset();

        $text = "e69de29bb2d1d6434b8b29ae775ad8c2e48c5391\tlib/empty.php\n"
            . "ce013625030ba8dba906f756967f9e9ca394464a\tlib/hello.php\n"
            . "not-a-valid-line\n";
        $lines = manifest_store::save_manifest('2024100711.04', hash('sha256', $text), $text);
        $this->assertSame(3, $lines);

        $meta = manifest_store::load_meta();
        $this->assertSame('2024100711.04', $meta['version']);

        $map = manifest_store::load_manifest_map();
        $this->assertCount(2, $map);
        $this->assertSame('e69de29bb2d1d6434b8b29ae775ad8c2e48c5391', $map['lib/empty.php']);

        manifest_store::reset();
        $this->assertNull(manifest_store::load_meta());
        $this->assertNull(manifest_store::load_manifest_map());
    }

    /**
     * Full scan against a tiny manifest exercises all three deviation kinds
     * plus the list caps, walking the real dirroot.
     */
    public function test_scan_classifies_deviations(): void {
        $this->resetAfterTest();
        manifest_store::reset();

        $base = rtrim(integrity_scanner::base_dir(), '/');
        // A real file whose pristine hash we can compute on the spot: the
        // manifest entry matches disk, so it must NOT be reported.
        $realrel = 'version.php';
        if (!is_readable($base . '/' . $realrel)) {
            $realrel = 'public/version.php';
        }
        $realhash = integrity_scanner::git_blob_sha1($base . '/' . $realrel);
        $this->assertNotNull($realhash);

        // A second real file with a deliberately wrong hash → modified.
        $modrel = is_readable($base . '/index.php') ? 'index.php' : 'public/index.php';
        $text = "{$realhash}\t{$realrel}\n"
            . str_repeat('0', 40) . "\t{$modrel}\n"
            . str_repeat('1', 40) . "\tno/such/file.php\n";
        manifest_store::save_manifest(integrity_scanner::core_version_full(), hash('sha256', $text), $text);

        $result = integrity_scanner::scan();

        $this->assertSame('ok', $result['status']);
        $this->assertFalse($result['manifest_version_mismatch']);
        $this->assertGreaterThan(0, $result['files_scanned']);

        // The version.php entry matched its manifest hash → not modified.
        $modifiedpaths = array_column($result['modified'], 'path');
        $this->assertNotContains($realrel, $modifiedpaths);
        $this->assertContains($modrel, $modifiedpaths);
        $this->assertSame(1, $result['modified_count']);

        $this->assertSame(['no/such/file.php'], $result['missing']);
        $this->assertSame(1, $result['missing_count']);

        // Everything else on disk is unexpected vs this tiny manifest, which
        // exercises the cap + overflow accounting.
        $this->assertGreaterThan(integrity_scanner::MAX_DEVIATIONS, $result['unexpected_count']);
        $this->assertCount(integrity_scanner::MAX_DEVIATIONS, $result['unexpected']);
        $this->assertSame(
            $result['unexpected_count'] - integrity_scanner::MAX_DEVIATIONS,
            $result['unexpected_overflow']
        );
    }

    /**
     * A scan against a manifest for a different build flags the mismatch.
     */
    public function test_scan_flags_manifest_version_mismatch(): void {
        $this->resetAfterTest();
        manifest_store::reset();

        $text = str_repeat('a', 40) . "\tno/file.php\n";
        manifest_store::save_manifest('1999010100.00', hash('sha256', $text), $text);

        $result = integrity_scanner::scan();
        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['manifest_version_mismatch']);
    }

    /**
     * run() must no-op (and say why) when the feature is off or unprovisioned.
     */
    public function test_run_self_gates(): void {
        $this->resetAfterTest();
        $this->expectOutputRegex('/skipping/');
        manifest_store::reset();
        integrity_state::reset();

        set_config('integrityenabled', 0, 'local_sentinel');
        integrity_scanner::run();
        $this->assertSame(integrity_state::STATUS_NEVER, integrity_state::get()['last_scan_status']);

        set_config('integrityenabled', 1, 'local_sentinel');
        integrity_scanner::run(); // No manifest stored → still never.
        $this->assertSame(integrity_state::STATUS_NEVER, integrity_state::get()['last_scan_status']);
    }
}
