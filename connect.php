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
 * Admin web page: Sentinel — Connect to remote dashboard.
 *
 * Consolidated connection page: explains the two mechanisms — send (push)
 * and allow retrieval (pull) — side-by-side, with a live "configured /
 * not configured" indicator on each, and a "Configure →" button per card
 * linking to the underlying settings page / setup wizard (both hidden
 * from the nav so this page is the single entry point).
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_sentinel_connect');

// Test-push handler runs before any output so we can redirect cleanly.
$testpushresult = null;
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
            new moodle_url('/local/sentinel/connect.php'),
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
        new moodle_url('/local/sentinel/connect.php'),
        $provisionmessage,
        null,
        $provisionok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
    );
}

// Self-registration handler — runs before output so we can redirect cleanly.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('do_register', 0, PARAM_INT)) {
    require_sesskey();
    require_capability('moodle/site:config', context_system::instance());
    [$registerok, $registermessage] = \local_sentinel\register::run();
    redirect(
        new moodle_url('/local/sentinel/connect.php'),
        $registermessage,
        null,
        $registerok ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
    );
}

// Status checks.
$pushconfigured = (
    !empty(get_config('local_sentinel', 'pushenabled')) &&
    !empty(get_config('local_sentinel', 'pushendpoint')) &&
    !empty(get_config('local_sentinel', 'pushsecret'))
);
$pullconfigured = \local_sentinel\setup\helper::existing_token() !== null;

$sendurl = new moodle_url('/admin/settings.php', ['section' => 'local_sentinel']);
$pullurl = new moodle_url('/local/sentinel/setup.php');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('connect_heading', 'local_sentinel'));
echo $PAGE->get_renderer('local_sentinel')->sentinel_subnav('connect');
echo $OUTPUT->box(get_string('connect_intro', 'local_sentinel'));

echo html_writer::start_div('row g-3 mb-3');

// Card 1: Send data (push).
echo html_writer::start_div('col-md-6');
echo html_writer::start_div('card h-100');
echo html_writer::start_div('card-body d-flex flex-column');
echo html_writer::start_div('d-flex justify-content-between align-items-start mb-2');
echo html_writer::tag('h4', s(get_string('connect_send_title', 'local_sentinel')), ['class' => 'h5 mb-0']);
echo html_writer::tag(
    'span',
    '● ' . s(get_string($pushconfigured ? 'connect_configured' : 'connect_not_configured', 'local_sentinel')),
    ['class' => 'small ' . ($pushconfigured ? 'text-success' : 'text-muted')]
);
echo html_writer::end_div();
echo html_writer::tag('p', s(get_string('connect_send_desc', 'local_sentinel')));
echo html_writer::tag(
    'p',
    html_writer::tag('strong', 'When to use: ') . s(get_string('connect_send_when', 'local_sentinel')),
    ['class' => 'mb-2']
);
echo html_writer::tag(
    'p',
    html_writer::tag('strong', 'Requires: ') . s(get_string('connect_send_requires', 'local_sentinel')),
    ['class' => 'mb-3']
);
// Push pipeline self-monitoring panel — only shown when push is configured.
if ($pushconfigured) {
    $pushstate = \local_sentinel\push_state::get();
    if ($testpushresult === 'ok') {
        // The test-push handler already updated push_state via the task; just
        // surface a success notification next to the panel.
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
    echo local_sentinel_connect_push_state_panel($pushstate);
}

echo html_writer::link(
    $sendurl,
    s(get_string('connect_send_cta', 'local_sentinel')),
    ['class' => 'btn btn-primary mt-auto align-self-start']
);
echo html_writer::end_div(); // End card-body.
echo html_writer::end_div(); // End card.
echo html_writer::end_div(); // End col.

// Card 2: Allow retrieval (pull).
echo html_writer::start_div('col-md-6');
echo html_writer::start_div('card h-100');
echo html_writer::start_div('card-body d-flex flex-column');
echo html_writer::start_div('d-flex justify-content-between align-items-start mb-2');
echo html_writer::tag('h4', s(get_string('connect_pull_title', 'local_sentinel')), ['class' => 'h5 mb-0']);
echo html_writer::tag(
    'span',
    '● ' . s(get_string($pullconfigured ? 'connect_configured' : 'connect_not_configured', 'local_sentinel')),
    ['class' => 'small ' . ($pullconfigured ? 'text-success' : 'text-muted')]
);
echo html_writer::end_div();
echo html_writer::tag('p', s(get_string('connect_pull_desc', 'local_sentinel')));
echo html_writer::tag(
    'p',
    html_writer::tag('strong', 'When to use: ') . s(get_string('connect_pull_when', 'local_sentinel')),
    ['class' => 'mb-2']
);
echo html_writer::tag(
    'p',
    html_writer::tag('strong', 'Requires: ') . s(get_string('connect_pull_requires', 'local_sentinel')),
    ['class' => 'mb-3']
);
echo html_writer::link(
    $pullurl,
    s(get_string('connect_pull_cta', 'local_sentinel')),
    ['class' => 'btn btn-primary mt-auto align-self-start']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // End row.

// Self-registration card (full width) — the automated alternative to manually
// configuring push and hand-creating the instance on the dashboard.
$regstate = \local_sentinel\registration_state::get();
$regconfigured = (
    !empty(get_config('local_sentinel', 'registrationenabled')) &&
    trim((string) get_config('local_sentinel', 'dashboardbaseurl')) !== '' &&
    (string) get_config('local_sentinel', 'enrollmentkey') !== ''
);

echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h4', s(get_string('registration_heading', 'local_sentinel')), ['class' => 'h5']);
echo html_writer::tag('p', s(get_string('registration_intro', 'local_sentinel')));

// One-paste provisioning code: configures dashboard URL + enrollment key and
// registers in a single step. Shown first — it's the fast path.
echo html_writer::tag('p', s(get_string('registration_code_desc', 'local_sentinel')), ['class' => 'mb-2']);
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/sentinel/connect.php'))->out(false),
    'class' => 'mb-3',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do_provision', 'value' => 1]);
echo html_writer::start_div('d-flex gap-2 align-items-start', ['style' => 'max-width: 640px;']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'provisioning_code',
    'class' => 'form-control',
    'placeholder' => s(get_string('registration_code_label', 'local_sentinel')),
    'autocomplete' => 'off',
    'spellcheck' => 'false',
    'aria-label' => s(get_string('registration_code_label', 'local_sentinel')),
]);
echo html_writer::tag('button', s(get_string('registration_code_button', 'local_sentinel')), [
    'type' => 'submit', 'class' => 'btn btn-primary text-nowrap',
]);
echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::tag('hr', '');

if ($regstate['last_status'] === \local_sentinel\registration_state::STATUS_FAILED && $regstate['last_error'] !== '') {
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
        . html_writer::tag('span', s($regstatustext), ['class' => $regstatusclass])
);

if ($regconfigured) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/local/sentinel/connect.php'))->out(false),
        'class' => 'mb-0',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'do_register', 'value' => 1]);
    echo html_writer::tag('button', s(get_string('registration_register_button', 'local_sentinel')), [
        'type' => 'submit', 'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
} else {
    echo html_writer::tag(
        'p',
        html_writer::link($sendurl, s(get_string('connect_send_cta', 'local_sentinel')), [
            'class' => 'btn btn-outline-secondary',
        ]),
        ['class' => 'mb-0']
    );
}
echo html_writer::end_div(); // End card-body.
echo html_writer::end_div(); // End card.

// Notes.
echo html_writer::tag(
    'h4',
    s(get_string('connect_notes_heading', 'local_sentinel')),
    ['class' => 'h6 text-uppercase text-muted mt-4']
);
echo html_writer::start_tag('ul');
echo html_writer::tag('li', s(get_string('connect_note_both', 'local_sentinel')));
echo html_writer::tag('li', s(get_string('connect_note_pull', 'local_sentinel')));
echo html_writer::tag('li', s(get_string('connect_note_push', 'local_sentinel')));
echo html_writer::end_tag('ul');

echo $OUTPUT->footer();


/**
 * Render the push-state diagnostic panel (timestamps, failure count, last error).
 *
 * Only called when push is configured. Returns an HTML string suitable for
 * embedding inside the Send card on the Connect page.
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
        ['class' => 'h6 text-uppercase text-muted mt-3 mb-2']
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
        'class' => 'mb-3',
    ]);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'test_push', 'value' => 1]);
    $out .= html_writer::tag('button', s(get_string('pushstate_test_button', 'local_sentinel')), [
        'type' => 'submit', 'class' => 'btn btn-outline-secondary btn-sm',
    ]);
    $out .= html_writer::end_tag('form');

    return $out;
}
