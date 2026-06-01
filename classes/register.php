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
 * Self-registration action: register this site with a Sentinel dashboard.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Builds and submits a self-registration request to the configured dashboard.
 *
 * Off by default — the admin sets the dashboard base URL + enrollment key in
 * settings, then triggers {@see run()} explicitly from the Connect page (never
 * automatically, and never to a hardcoded URL). The site generates its own push
 * secret, so no secret is hand-copied; the dashboard approves the request and
 * the existing push pipeline starts flowing.
 */
class register {
    /**
     * Generate a fresh push secret for this site.
     *
     * @return string
     */
    public static function generate_secret(): string {
        return random_string(40);
    }

    /**
     * Build the registration request body.
     *
     * Deliberately carries only site identity + the generated machine credential
     * — no user personal data. Kept as a separate method so the no-PII guarantee
     * is unit-testable without mocking the HTTP layer.
     *
     * @param string $secret The push secret to register.
     * @return array
     */
    public static function build_payload(string $secret): array {
        return [
            'site' => collector::get_site_identity(),
            'plugin' => collector::get_plugin_identity(),
            'push_secret' => $secret,
        ];
    }

    /**
     * Attempt to register this site with the configured dashboard.
     *
     * @return array [bool $ok, string $message, string $status] — $status is one
     *               of the registration_state STATUS_* constants.
     */
    public static function run(): array {
        $baseurl = trim((string) get_config('local_sentinel', 'dashboardbaseurl'));
        $enrollmentkey = (string) get_config('local_sentinel', 'enrollmentkey');
        $enabled = (bool) get_config('local_sentinel', 'registrationenabled');

        if (!$enabled) {
            return [false, get_string('registration_disabled', 'local_sentinel'), registration_state::STATUS_FAILED];
        }
        if ($baseurl === '' || $enrollmentkey === '') {
            return [false, get_string('registration_misconfigured', 'local_sentinel'), registration_state::STATUS_FAILED];
        }

        // HTTPS-only: never submit identity + a secret over plaintext.
        if (strtolower((string) parse_url($baseurl, PHP_URL_SCHEME)) !== 'https') {
            registration_state::record_failure(get_string('registration_https_required', 'local_sentinel'));
            return [false, get_string('registration_https_required', 'local_sentinel'), registration_state::STATUS_FAILED];
        }

        $base = rtrim($baseurl, '/');

        // Reuse an existing push secret if one is already set (so re-registering
        // an already-onboarded site doesn't rotate the secret the dashboard
        // holds); otherwise generate one. Persist secret + endpoint BEFORE the
        // POST so an approved site can push even if the response is lost.
        $secret = (string) get_config('local_sentinel', 'pushsecret');
        if ($secret === '') {
            $secret = self::generate_secret();
            set_config('pushsecret', $secret, 'local_sentinel');
        }
        set_config('pushendpoint', $base . '/ingest/snapshot/', 'local_sentinel');

        $payload = self::build_payload($secret);
        $identity = $payload['site'];

        registration_state::record_attempt();

        // The Moodle curl wrapper is defined in filelib.php, which a bare CLI
        // bootstrap doesn't load — require it before use.
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-Sentinel-Enrollment-Key: ' . $enrollmentkey,
        ]);
        $response = $curl->post($base . '/api/register/', json_encode($payload));
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);

        if ($curl->get_errno() !== 0) {
            registration_state::record_failure('curl: ' . $curl->error, 0);
            return [false, get_string('registration_failed', 'local_sentinel', $curl->error),
                registration_state::STATUS_FAILED];
        }

        $decoded = json_decode((string) $response, true);
        $status = (is_array($decoded) && isset($decoded['status'])) ? (string) $decoded['status'] : '';

        if ($httpcode === 200 && ($status === 'activated' || $status === 'pending')) {
            $mapped = $status === 'activated' ? registration_state::STATUS_ACTIVATED : registration_state::STATUS_PENDING;
            registration_state::record_result($mapped, $httpcode, $identity['siteidentifier']);
            if ($mapped === registration_state::STATUS_ACTIVATED) {
                // Approved immediately (allowlisted) — start sending now.
                set_config('pushenabled', 1, 'local_sentinel');
            }
            return [true, get_string('registration_' . $mapped, 'local_sentinel'), $mapped];
        }

        if ($httpcode === 200 && $status === 'rejected') {
            registration_state::record_result(registration_state::STATUS_REJECTED, $httpcode);
            return [false, get_string('registration_rejected', 'local_sentinel'), registration_state::STATUS_REJECTED];
        }

        // 401 unauthorized, 503 queue_full, or any unexpected response.
        $detail = $status !== '' ? $status : ('HTTP ' . $httpcode);
        registration_state::record_failure($detail, $httpcode);
        return [false, get_string('registration_failed', 'local_sentinel', $detail), registration_state::STATUS_FAILED];
    }
}
