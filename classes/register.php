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
     * Deliberately carries only site identity + generated machine credentials
     * (a push secret and a web-service token) — no user personal data. Kept as a
     * separate method so the no-PII guarantee is unit-testable without mocking
     * the HTTP layer.
     *
     * @param string $secret The push secret to register.
     * @param string $wstoken The web-service token to register (may be empty if
     *                        minting failed — the site stays push-only).
     * @return array
     */
    public static function build_payload(string $secret, string $wstoken = ''): array {
        return [
            'site' => collector::get_site_identity(),
            'plugin' => collector::get_plugin_identity(),
            'push_secret' => $secret,
            'ws_token' => $wstoken,
        ];
    }

    /**
     * Ensure a web-service token exists for the dashboard to pull with, and
     * return it. Idempotent via the setup helper (enables web services + REST,
     * ensures the Sentinel user/role, mints or reuses the token). Returns '' if
     * provisioning fails, so registration degrades to push-only rather than
     * aborting.
     *
     * @return string
     */
    protected static function ensure_ws_token(): string {
        try {
            return \local_sentinel\setup\helper::run()->token;
        } catch (\Throwable $e) {
            debugging('local_sentinel: WS token provisioning failed during registration: '
                . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }

    /**
     * Make the push pipeline actually run: enable the pushenabled setting AND
     * the push_snapshot scheduled task (which ships disabled by default).
     *
     * Without the task half, a "connected" site silently never pushes. Called
     * on every successful registration submission — including PENDING, so that
     * once the operator approves on the dashboard the very next task run goes
     * through with no further action on this site. (While pending, pushes get
     * a distinct 409 the dashboard sends for unapproved sites; that state is
     * visible on the Connect page.)
     *
     * An admin's explicit task customisation is respected: if the task record
     * was hand-configured (customised flag), its disabled state is left alone.
     */
    public static function enable_push_pipeline(): void {
        set_config('pushenabled', 1, 'local_sentinel');
        try {
            $task = \core\task\manager::get_scheduled_task(\local_sentinel\task\push_snapshot::class);
            if ($task && $task->get_disabled() && !$task->is_customised()) {
                $task->set_disabled(false);
                \core\task\manager::configure_scheduled_task($task);
            }
        } catch (\Throwable $e) {
            debugging('local_sentinel: could not enable the push_snapshot task: '
                . $e->getMessage(), DEBUG_DEVELOPER);
        }
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

        // Provision a pull token too, so the dashboard can fetch on demand (e.g.
        // a fresh "who's active right now" read before a maintenance window).
        // Best-effort: registration proceeds push-only if minting fails.
        $wstoken = self::ensure_ws_token();

        $payload = self::build_payload($secret, $wstoken);
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
            // Start the pipeline for BOTH outcomes: an activated site sends
            // immediately; a pending site starts sending the moment the
            // operator approves, with no further action needed here.
            self::enable_push_pipeline();
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
