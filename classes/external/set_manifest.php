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
 * External function: set_manifest.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\external;

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;
use local_sentinel\integrity_state;
use local_sentinel\manifest_store;

/**
 * Receives a pristine-tree manifest from the dashboard and stores it.
 *
 * The plugin never fetches manifests itself — the dashboard pushes the one
 * matching this site's exact build (resolved by core_version_full from the
 * integrity slice). The manifest travels as base64-wrapped gzip in an
 * ordinary text parameter; at ~1.3 MB the request needs the site's web
 * server to accept a body of a few MB (nginx client_max_body_size).
 */
class set_manifest extends base {
    /** Refuse to inflate beyond this many bytes (defence against gzip bombs). */
    private const MAX_INFLATED_BYTES = 32 * 1024 * 1024;

    /**
     * Parameter declaration.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'version' => new external_value(
                PARAM_RAW_TRIMMED,
                'Literal Moodle $version decimal string the manifest was generated for (e.g. 2024100711.04).'
            ),
            'digest' => new external_value(PARAM_ALPHANUM, 'SHA-256 hex of the UNCOMPRESSED manifest text.'),
            'manifest' => new external_value(PARAM_RAW, 'Base64-encoded gzip of the manifest text.'),
        ]);
    }

    /**
     * Validate and store the manifest.
     *
     * @param string $version  Version string the manifest targets.
     * @param string $digest   SHA-256 of the manifest text.
     * @param string $manifest Base64(gzip(text)).
     * @return array status / version / lines.
     */
    public static function execute(string $version, string $digest, string $manifest): array {
        self::authorise_manage();
        [
            'version' => $version,
            'digest' => $digest,
            'manifest' => $manifest,
        ] = self::validate_parameters(self::execute_parameters(), [
            'version' => $version,
            'digest' => $digest,
            'manifest' => $manifest,
        ]);

        if (!preg_match('/^\d{8,12}(\.\d{1,4})?$/', $version)) {
            throw new invalid_parameter_exception('version must be a Moodle $version decimal string');
        }
        $binary = base64_decode($manifest, true);
        if ($binary === false) {
            throw new invalid_parameter_exception('manifest is not valid base64');
        }
        $text = @gzdecode($binary, self::MAX_INFLATED_BYTES);
        if ($text === false || $text === '') {
            throw new invalid_parameter_exception('manifest is not valid gzip (or exceeds the inflate cap)');
        }
        if (!hash_equals(hash('sha256', $text), strtolower($digest))) {
            throw new invalid_parameter_exception('manifest digest mismatch — transfer corrupted?');
        }
        if (!preg_match(manifest_store::LINE_REGEX, strtok($text, "\n"))) {
            throw new invalid_parameter_exception('manifest first line is not "<sha1>\t<path>"');
        }

        $lines = manifest_store::save_manifest($version, strtolower($digest), $text);
        integrity_state::record_manifest($version, strtolower($digest));

        return [
            'status' => 'stored',
            'version' => $version,
            'lines' => $lines,
        ];
    }

    /**
     * Declare the return shape.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, "Always 'stored' on success."),
            'version' => new external_value(PARAM_RAW, 'Version string the stored manifest targets.'),
            'lines' => new external_value(PARAM_INT, 'Number of manifest entries stored.'),
        ]);
    }
}
