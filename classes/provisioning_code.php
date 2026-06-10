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
 * Provisioning-code parsing for one-paste dashboard connection.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Decodes a provisioning code into the dashboard URL + enrollment key pair.
 *
 * A provisioning code is a single opaque string a dashboard operator hands to
 * the site admin, replacing the separate "type the dashboard URL, then the
 * enrollment key" steps with one paste. Format:
 *
 *     SNTL1.<base64url(JSON {"k": "<enrollment key>", "u": "<https base URL>"})>
 *
 * The code is vendor-neutral — any Sentinel dashboard can issue one; nothing
 * is hardcoded here. Parsing is strict and offline (no network); registration
 * itself still happens via {@see register::run()} and remains opt-in.
 */
class provisioning_code {
    /** @var string Format/version prefix. Bump (SNTL2.) on breaking changes. */
    public const PREFIX = 'SNTL1.';

    /** @var int Sanity cap well above any real URL+key pair. */
    public const MAX_LENGTH = 2048;

    /**
     * Parse a provisioning code.
     *
     * @param string $code The pasted code.
     * @return array|null ['url' => https base URL (no trailing slash),
     *                     'key' => enrollment key] or null when invalid.
     */
    public static function parse(string $code): ?array {
        $code = trim($code);
        if ($code === '' || strlen($code) > self::MAX_LENGTH) {
            return null;
        }
        if (strpos($code, self::PREFIX) !== 0) {
            return null;
        }

        // Convert base64url (unpadded) to standard base64 with padding restored.
        $b64 = strtr(substr($code, strlen(self::PREFIX)), '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $json = base64_decode($b64, true);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $url = isset($data['u']) && is_string($data['u']) ? trim($data['u']) : '';
        $key = isset($data['k']) && is_string($data['k']) ? trim($data['k']) : '';
        if ($url === '' || $key === '') {
            return null;
        }

        // Same HTTPS-only stance as register::run(): identity + a secret never
        // travel over plaintext, so refuse codes pointing at http endpoints.
        if (
            strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
                || (string) parse_url($url, PHP_URL_HOST) === ''
        ) {
            return null;
        }

        return ['url' => rtrim($url, '/'), 'key' => $key];
    }
}
