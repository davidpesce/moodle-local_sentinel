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
 * Privacy provider tests.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\external_location;

/**
 * Tests for the local_sentinel privacy metadata provider.
 *
 * The plugin stores no personal data locally; it only declares an external
 * transmission to the central Sentinel dashboard. These tests pin that
 * declaration and verify every declared field resolves to a lang string —
 * guarding against the easy regression where a collector field is added but
 * its privacy:metadata string is forgotten.
 *
 * @covers \local_sentinel\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * get_metadata() returns exactly one external-location item, summarised
     * by a resolvable lang string.
     */
    public function test_get_metadata_declares_external_locations(): void {
        $collection = provider::get_metadata(new collection('local_sentinel'));
        $items = $collection->get_collection();

        $this->assertCount(2, $items, 'Expected two external-location declarations.');

        $names = [];
        foreach ($items as $item) {
            $this->assertInstanceOf(external_location::class, $item);
            $names[] = $item->get_name();
            // The summary string must exist (get_string throws on a missing key).
            $this->assertNotEmpty(get_string($item->get_summary(), 'local_sentinel'));
        }
        $this->assertEqualsCanonicalizing(['sentinel_dashboard', 'sentinel_registration'], $names);
    }

    /**
     * Every transmitted field declared by the provider must resolve to a
     * non-empty lang string in local_sentinel.
     */
    public function test_every_declared_field_has_a_lang_string(): void {
        $collection = provider::get_metadata(new collection('local_sentinel'));

        foreach ($collection->get_collection() as $item) {
            $fields = $item->get_privacy_fields();
            $this->assertNotEmpty($fields, "Link '{$item->get_name()}' declared no fields.");
            foreach ($fields as $field => $stringkey) {
                /* get_string() raises a coding_exception if the key is missing,
                   which fails the test with a clear message naming the field. */
                $this->assertNotEmpty(
                    get_string($stringkey, 'local_sentinel'),
                    "Lang string '{$stringkey}' for field '{$field}' is empty."
                );
            }
        }
    }
}
