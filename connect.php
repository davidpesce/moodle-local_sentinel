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
 * Admin web page: Sentinel — Connect to dashboard.
 *
 * The single place to connect this site to a Sentinel dashboard, organised by
 * who hosts it: the managed Sentinel Monitoring Service (paste a provisioning
 * code) or your own self-hosted dashboard (paste a code, or set it up manually
 * by minting a pull token and/or configuring push). Everything that used to
 * live on the hidden setup + settings pages is embedded here.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_sentinel_connect');

/** @var string Where to send operators who don't yet have a provisioning code. */
const LOCAL_SENTINEL_SIGNUP_URL = 'https://mdlsentinel.com';

$connecturl = new moodle_url('/local/sentinel/connect.php');

// Test-push handler runs before any output so we can redirect cleanly.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('test_push', 0, PARAM_INT)) {
    require_sesskey();
    try {
        (new \local_sentinel\task\push_snapshot())->execute();
        $testpushresult = 'ok';
    } catch (\Throwable $e) {
        $testpushresult = 'thrown:' . $e->getMessage();
    }
    redirect(new moodle_url('/local/sentinel/connect.php', ['testresult' => $testpushresult]));
}
$testpushresult = optional_param('testresult', '', PARAM_RAW);

// Provisioning-code handler — one paste configures the connection settings
// and registers in a single step. Runs before output so we can redirect.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('do_provision', 0, PARAM_INT)) {
    require_sesskey();
    require_capability('moodle/site:config', context_system::instance());
    $code = optional_param('provisioning_code', '', PARAM_RAW_TRIMMED);
    $parsed = \local_sentinel\provisioning_code::parse($code);
    if ($parsed === null) {
        redirect(
            $connecturl,
            get_string('registration_code_invalid', 'local_sentinel'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    set_config('dashboardbaseurl', $parsed['url'], 'local_sentinel');
    set_config('enrollmentkey', $parsed['key'], 'local_sentinel');
    set_config('registrationenabled', 1, 'local_sentinel');
    [$provisionok, $provisionmessage] = \local_sentinel\register::run();
    redirect(
        $connecturl,
        $provisionmessage,
        null,
        $provisionok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
    );
}

// Re-send registration handler (retry an existing, configured registration).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('do_register', 0, PARAM_INT)) {
    require_sesskey();
    require_capability('moodle/site:config', context_system::instance());
    [$registerok, $registermessage] = \local_sentinel\register::run();
    redirect(
        $connecturl,
        $registermessage,
        null,
        $registerok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
    );
}

// Mint-token handler (pull) — embeds what the old setup.php page did.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('do_setup', 0, PARAM_INT)) {
    require_sesskey();
    require_capability('moodle/site:config', context_system::instance());
    $regenerate = (bool) optional_param('regenerate', 0, PARAM_INT);
    \local_sentinel\setup\helper::run([], $regenerate);
    redirect(
        new moodle_url('/local/sentinel/connect.php', ['setupdone' => 1]),
        get_string('setup_success', 'local_sentinel'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Save-push handler — embeds the old push settings (endpoint/secret/enable).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('do_push', 0, PARAM_INT)) {
    require_sesskey();
    require_capability('moodle/site:config', context_system::instance());
    $endpoint = optional_param('pushendpoint', '', PARAM_RAW_TRIMMED);
    $secret = optional_param('pushsecret', '', PARAM_RAW_TRIMMED);
    $enable = (bool) optional_param('pushenabled', 0, PARAM_INT);
    if ($endpoint !== '' && stripos($endpoint, 'https://') !== 0) {
        redirect(
            $connecturl,
            get_string('connect_push_https', 'local_sentinel'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    set_config('pushendpoint', clean_param($endpoint, PARAM_URL), 'local_sentinel');
    set_config('pushsecret', $secret, 'local_sentinel');
    if ($enable) {
        // Flips pushenabled on and enables the scheduled task (respects admin
        // customisation), the same path the registration flow uses.
        \local_sentinel\register::enable_push_pipeline();
    } else {
        set_config('pushenabled', 0, 'local_sentinel');
    }
    redirect(
        $connecturl,
        get_string('connect_push_saved', 'local_sentinel'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Current connection state.
$pushconfigured = (
    !empty(get_config('local_sentinel', 'pushenabled')) &&
    !empty(get_config('local_sentinel', 'pushendpoint')) &&
    !empty(get_config('local_sentinel', 'pushsecret'))
);
$token = \local_sentinel\setup\helper::existing_token();
$pullconfigured = $token !== null;
$regstate = \local_sentinel\registration_state::get();
$regconfigured = (
    !empty(get_config('local_sentinel', 'registrationenabled')) &&
    trim((string) get_config('local_sentinel', 'dashboardbaseurl')) !== '' &&
    (string) get_config('local_sentinel', 'enrollmentkey') !== ''
);
$endpoint = (new moodle_url('/webservice/rest/server.php'))->out(false);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('connect_heading', 'local_sentinel'));
echo $PAGE->get_renderer('local_sentinel')->sentinel_subnav('connect');
echo $OUTPUT->box(get_string('connect_intro', 'local_sentinel'));

// Path 1 (primary): the managed Sentinel Monitoring Service.
echo html_writer::start_div('card border-primary mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h4', s(get_string('connect_service_title', 'local_sentinel')), ['class' => 'h5']);
echo html_writer::tag('p', s(get_string('connect_service_intro', 'local_sentinel')));

echo local_sentinel_connect_code_form($connecturl);

echo html_writer::tag(
    'p',
    html_writer::link(
        LOCAL_SENTINEL_SIGNUP_URL,
        s(get_string('connect_service_signup', 'local_sentinel')) . ' →',
        ['target' => '_blank', 'rel' => 'noopener']
    ),
    ['class' => 'mb-0']
);

// Registration status (shown once a registration has been attempted).
if ($regstate['last_status'] !== \local_sentinel\registration_state::STATUS_NEVER) {
    if (
        $regstate['last_status'] === \local_sentinel\registration_state::STATUS_FAILED
            && $regstate['last_error'] !== ''
    ) {
        $regstatustext = get_string('registration_failed', 'local_sentinel', $regstate['last_error']);
    } else {
        $regstatustext = get_string('registration_' . $regstate['last_status'], 'local_sentinel');
    }
    $regstatusclass = 'text-muted';
    if ($regstate['last_status'] === \local_sentinel\registration_state::STATUS_ACTIVATED) {
        $regstatusclass = 'text-success';
    } else if ($regstate['last_status'] === \local_sentinel\registration_state::STATUS_PENDING) {
        $regstatusclass = 'text-info';
    } else if (in_array($regstate['last_status'], ['rejected', 'failed'], true)) {
        $regstatusclass = 'text-danger';
    }
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', s(get_string('registration_status_label', 'local_sentinel')) . ': ')
            . html_writer::tag('span', s($regstatustext), ['class' => $regstatusclass]),
        ['class' => 'mt-3 mb-0']
    );
    // Offer a retry when configured but not yet activated.
    if ($regconfigured && $regstate['last_status'] !== \local_sentinel\registration_state::STATUS_ACTIVATED) {
        echo html_writer::start_tag('form', [
            'method' => 'post', 'action' => $connecturl->out(false), 'class' => 'mt-2 mb-0',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do_register', 'value' => 1]);
        echo html_writer::tag('button', s(get_string('registration_register_button', 'local_sentinel')), [
            'type' => 'submit', 'class' => 'btn btn-outline-secondary btn-sm',
        ]);
        echo html_writer::end_tag('form');
    }
}
echo html_writer::end_div(); // End card-body.
echo html_writer::end_div(); // End card.

// Sending status: the push heartbeat (managed or self-hosted) when configured.
if ($pushconfigured) {
    if ($testpushresult === 'ok') {
        echo $OUTPUT->notification(
            get_string('pushstate_test_success', 'local_sentinel'),
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if (strpos((string) $testpushresult, 'thrown:') === 0) {
        echo $OUTPUT->notification(
            get_string('pushstate_test_failed', 'local_sentinel', substr($testpushresult, 7)),
            \core\output\notification::NOTIFY_ERROR
        );
    }
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo local_sentinel_connect_push_state_panel(\local_sentinel\push_state::get());
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Path 2 (secondary): your own self-hosted dashboard — manual setup.
echo html_writer::start_tag('details', ['class' => 'card mb-3']);
echo html_writer::tag(
    'summary',
    s(get_string('connect_selfhosted_title', 'local_sentinel')),
    ['class' => 'card-header h6 mb-0']
);
echo html_writer::start_div('card-body');
echo html_writer::tag('p', s(get_string('connect_selfhosted_intro', 'local_sentinel')), ['class' => 'text-muted']);

// Pull: mint a web service token.
echo html_writer::tag(
    'h5',
    s(get_string('connect_pull_title', 'local_sentinel')) . ' '
        . html_writer::tag(
            'span',
            '● ' . s(get_string($pullconfigured ? 'connect_configured' : 'connect_not_configured', 'local_sentinel')),
            ['class' => 'small ' . ($pullconfigured ? 'text-success' : 'text-muted')]
        ),
    ['class' => 'h6 text-uppercase text-muted mt-2']
);
echo html_writer::tag('p', s(get_string('connect_pull_desc', 'local_sentinel')));

if ($pullconfigured) {
    $tokenid = html_writer::random_id('sentinel-token-');
    echo html_writer::tag('label', s(get_string('setup_token_label', 'local_sentinel')), [
        'for' => $tokenid, 'class' => 'form-label small text-muted mb-1',
    ]);
    echo html_writer::tag('input', '', [
        'id' => $tokenid, 'type' => 'text', 'readonly' => 'readonly',
        'class' => 'form-control font-monospace', 'value' => $token,
    ]);
    echo html_writer::tag('button', s(get_string('setup_copy', 'local_sentinel')), [
        'type' => 'button', 'class' => 'btn btn-secondary btn-sm mt-2', 'data-copy-target' => $tokenid,
    ]);
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', s(get_string('setup_endpoint_label', 'local_sentinel')) . ': ')
            . html_writer::tag('code', $endpoint),
        ['class' => 'mt-2 mb-2']
    );
    echo html_writer::tag('p', s(get_string('setup_dashboard_help', 'local_sentinel')), ['class' => 'small text-muted mb-1']);
    echo local_sentinel_connect_mint_button($connecturl, true);
    $PAGE->requires->js_amd_inline("
        document.querySelectorAll('[data-copy-target]').forEach(btn => {
            btn.addEventListener('click', () => {
                const target = document.getElementById(btn.dataset.copyTarget);
                if (!target) return;
                target.select();
                navigator.clipboard.writeText(target.value).then(() => {
                    const orig = btn.textContent;
                    btn.textContent = 'Copied!';
                    setTimeout(() => { btn.textContent = orig; }, 1500);
                });
            });
        });
    ");
} else {
    echo local_sentinel_connect_mint_button($connecturl, false);
}

// Push: configure outbound sending.
echo html_writer::tag(
    'h5',
    s(get_string('connect_send_title', 'local_sentinel')) . ' '
        . html_writer::tag(
            'span',
            '● ' . s(get_string($pushconfigured ? 'connect_configured' : 'connect_not_configured', 'local_sentinel')),
            ['class' => 'small ' . ($pushconfigured ? 'text-success' : 'text-muted')]
        ),
    ['class' => 'h6 text-uppercase text-muted mt-4']
);
echo html_writer::tag('p', s(get_string('connect_send_desc', 'local_sentinel')));

echo html_writer::start_tag('form', [
    'method' => 'post', 'action' => $connecturl->out(false), 'style' => 'max-width: 640px;',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do_push', 'value' => 1]);

echo html_writer::start_div('mb-2');
echo html_writer::tag('label', s(get_string('pushendpoint', 'local_sentinel')), [
    'for' => 'sentinel-pushendpoint', 'class' => 'form-label small text-muted mb-1',
]);
echo html_writer::empty_tag('input', [
    'type' => 'url', 'name' => 'pushendpoint', 'id' => 'sentinel-pushendpoint', 'class' => 'form-control',
    'value' => (string) get_config('local_sentinel', 'pushendpoint'), 'placeholder' => 'https://dash.example.com/ingest/snapshot/',
]);
echo html_writer::end_div();

echo html_writer::start_div('mb-2');
echo html_writer::tag('label', s(get_string('pushsecret', 'local_sentinel')), [
    'for' => 'sentinel-pushsecret', 'class' => 'form-label small text-muted mb-1',
]);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'pushsecret', 'id' => 'sentinel-pushsecret', 'class' => 'form-control font-monospace',
    'value' => (string) get_config('local_sentinel', 'pushsecret'), 'autocomplete' => 'off',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-check mb-3');
echo html_writer::empty_tag('input', array_merge(
    ['type' => 'checkbox', 'class' => 'form-check-input', 'name' => 'pushenabled', 'value' => 1, 'id' => 'sentinel-pushenabled'],
    get_config('local_sentinel', 'pushenabled') ? ['checked' => 'checked'] : []
));
echo html_writer::tag('label', s(get_string('pushenabled', 'local_sentinel')), [
    'class' => 'form-check-label', 'for' => 'sentinel-pushenabled',
]);
echo html_writer::end_div();

echo html_writer::tag('button', s(get_string('connect_push_save', 'local_sentinel')), [
    'type' => 'submit', 'class' => 'btn btn-primary btn-sm',
]);
echo html_writer::end_tag('form');

echo html_writer::end_div(); // End card-body.
echo html_writer::end_tag('details');

echo $OUTPUT->footer();


/**
 * Render the provisioning-code paste form (one paste configures + registers).
 *
 * @param moodle_url $action Form action URL.
 * @return string
 */
function local_sentinel_connect_code_form(moodle_url $action): string {
    $out = html_writer::start_tag('form', ['method' => 'post', 'action' => $action->out(false), 'class' => 'mb-2']);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do_provision', 'value' => 1]);
    $out .= html_writer::start_div('d-flex gap-2 align-items-start', ['style' => 'max-width: 640px;']);
    $out .= html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'provisioning_code', 'class' => 'form-control',
        'placeholder' => s(get_string('registration_code_label', 'local_sentinel')),
        'autocomplete' => 'off', 'spellcheck' => 'false',
        'aria-label' => s(get_string('registration_code_label', 'local_sentinel')),
    ]);
    $out .= html_writer::tag('button', s(get_string('registration_code_button', 'local_sentinel')), [
        'type' => 'submit', 'class' => 'btn btn-primary text-nowrap',
    ]);
    $out .= html_writer::end_div();
    $out .= html_writer::end_tag('form');
    return $out;
}

/**
 * Render the mint-token button (or regenerate, when a token already exists).
 *
 * @param moodle_url $action Form action URL.
 * @param bool $regenerate Whether a token already exists (label becomes "Regenerate").
 * @return string
 */
function local_sentinel_connect_mint_button(moodle_url $action, bool $regenerate): string {
    $label = $regenerate
        ? get_string('connect_mint_regen', 'local_sentinel')
        : get_string('connect_mint_button', 'local_sentinel');
    $out = html_writer::start_tag('form', ['method' => 'post', 'action' => $action->out(false), 'class' => 'mb-0']);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do_setup', 'value' => 1]);
    if ($regenerate) {
        $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'regenerate', 'value' => 1]);
    }
    $out .= html_writer::tag('button', s($label), [
        'type' => 'submit',
        'class' => 'btn btn-' . ($regenerate ? 'outline-secondary' : 'primary') . ' btn-sm',
    ]);
    $out .= html_writer::end_tag('form');
    return $out;
}

/**
 * Render the push-state diagnostic panel (timestamps, failure count, last error).
 *
 * Only called when push is configured. Returns an HTML string suitable for
 * embedding on the Connect page.
 *
 * @param array $state Push state from \local_sentinel\push_state::get().
 * @return string
 */
function local_sentinel_connect_push_state_panel(array $state): string {
    $never = (int) $state['last_attempt_at'] === 0;
    $consecutive = (int) $state['consecutive_failures'];
    $statusclass = 'text-muted';
    if (!$never) {
        if ($state['last_status'] === 'success' && $consecutive === 0) {
            $statusclass = 'text-success';
        } else if ($consecutive > 0) {
            $statusclass = 'text-danger';
        }
    }

    $rows = '';
    $neverstr = get_string('pushstate_never', 'local_sentinel');
    $fmt = fn(int $ts) => $ts > 0
        ? userdate($ts, '%Y-%m-%d %H:%M:%S') . ' (' . format_time(time() - $ts) . ' ago)'
        : $neverstr;

    $rows .= html_writer::tag(
        'tr',
        html_writer::tag('th', s(get_string('pushstate_last_attempt', 'local_sentinel')), ['scope' => 'row'])
        . html_writer::tag('td', $fmt((int) $state['last_attempt_at']))
    );
    $rows .= html_writer::tag(
        'tr',
        html_writer::tag('th', s(get_string('pushstate_last_success', 'local_sentinel')), ['scope' => 'row'])
        . html_writer::tag('td', $fmt((int) $state['last_success_at']))
    );
    $consvalue = $consecutive > 0
        ? html_writer::tag('span', $consecutive, ['class' => 'text-danger fw-bold'])
        : html_writer::tag('span', '0', ['class' => 'text-success']);
    $rows .= html_writer::tag(
        'tr',
        html_writer::tag('th', s(get_string('pushstate_consecutive_failures', 'local_sentinel')), ['scope' => 'row'])
        . html_writer::tag('td', $consvalue)
    );
    if (!empty($state['last_error'])) {
        $rows .= html_writer::tag(
            'tr',
            html_writer::tag('th', s(get_string('pushstate_last_error', 'local_sentinel')), ['scope' => 'row'])
            . html_writer::tag('td', html_writer::tag('code', s((string) $state['last_error'])))
        );
    }

    $out = html_writer::tag(
        'h5',
        s(get_string('pushstate_heading', 'local_sentinel')) . ' '
            . html_writer::tag('span', '●', ['class' => 'ms-1 ' . $statusclass]),
        ['class' => 'h6 text-uppercase text-muted mb-2']
    );
    $out .= html_writer::tag(
        'table',
        html_writer::tag('tbody', $rows),
        ['class' => 'table table-sm mb-2']
    );

    // Test-push button — POSTs back to this page with sesskey.
    $out .= html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/local/sentinel/connect.php'))->out(false),
        'class' => 'mb-0',
    ]);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'test_push', 'value' => 1]);
    $out .= html_writer::tag('button', s(get_string('pushstate_test_button', 'local_sentinel')), [
        'type' => 'submit', 'class' => 'btn btn-outline-secondary btn-sm',
    ]);
    $out .= html_writer::end_tag('form');

    return $out;
}
