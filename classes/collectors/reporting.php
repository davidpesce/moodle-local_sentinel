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
 * Report-recipient forwarding collector.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\collectors;

/**
 * Forwards the site's report-recipient list to the central dashboard.
 *
 * The plugin sends no reports itself; this slice just tells the dashboard who
 * the local admin wants enhanced reports delivered to for this site. As a
 * regular slice it is egress-toggleable on the Settings page — excluding it
 * means the dashboard falls back to its own vendor-set recipient list.
 */
class reporting {
    /**
     * Collect the recipient list.
     *
     * @return array
     */
    public static function collect(): array {
        $recipients = \local_sentinel\recipients::all();
        return [
            'recipients' => $recipients,
            'count' => count($recipients),
        ];
    }
}
