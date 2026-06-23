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
 * Admin web page: Sentinel — Settings.
 *
 * Operator-facing settings that aren't part of the push/pull connection flow:
 * for now, the alert recipients list and the at-a-glance connection-status
 * indicator. Operators land here to change who gets notified and to confirm
 * which transports the dashboard is using to reach this site.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_sentinel_alerts');

// Handle the alert-recipients save before any output (so we can redirect).
$alertsaved = false;
$alertinvalid = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('alertemails_submit', 0, PARAM_INT)) {
    require_sesskey();
    $raw = optional_param('alertemails', '', PARAM_RAW_TRIMMED);
    [$valid, $invalid] = \local_sentinel\recipients::parse($raw);
    if ($invalid !== null) {
        $alertinvalid = $invalid;
    } else {
        set_config('alertemails', implode("\n", $valid), 'local_sentinel');
        $alertsaved = true;
    }
}

// Handle the core-file-integrity toggle save before any output.
$integritysaved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('integrity_submit', 0, PARAM_INT)) {
    require_sesskey();
    set_config('integrityenabled', optional_param('integrityenabled', 0, PARAM_INT) ? 1 : 0, 'local_sentinel');
    $integritysaved = true;
}

// Handle the egress-policy save before any output.
$egresssaved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('egress_submit', 0, PARAM_INT)) {
    require_sesskey();
    $keptslices = optional_param_array('egress_slices', [], PARAM_ALPHAEXT);
    $keptfields = optional_param_array('egress_fields', [], PARAM_RAW);
    // Store the INVERSE — settings to exclude — so an empty stored list
    // means "send everything" (graceful default for fresh installs).
    $excludedslices = array_values(array_diff(\local_sentinel\collector::ALL_SLICES, $keptslices));
    $excludedfields = array_values(array_diff(\local_sentinel\collector::REDACTABLE_FIELDS, $keptfields));
    set_config('egress_excluded_slices', json_encode($excludedslices), 'local_sentinel');
    set_config('egress_excluded_fields', json_encode($excludedfields), 'local_sentinel');
    \local_sentinel\cache_helper::purge();
    redirect(new moodle_url('/local/sentinel/alerts.php', ['egresssaved' => 1]));
}
$egresssaved = (bool) optional_param('egresssaved', 0, PARAM_INT);
$showpreview = (bool) optional_param('preview', 0, PARAM_INT);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('alerts_heading', 'local_sentinel'));
echo $PAGE->get_renderer('local_sentinel')->sentinel_subnav('alerts');

// Section: Alert recipients.
echo html_writer::tag(
    'h4',
    s(get_string('alertemails_section', 'local_sentinel')),
    ['class' => 'h6 text-uppercase text-muted mt-4']
);

if ($alertsaved) {
    echo $OUTPUT->notification(
        get_string('alertemails_saved', 'local_sentinel'),
        \core\output\notification::NOTIFY_SUCCESS
    );
} else if ($alertinvalid !== '') {
    echo $OUTPUT->notification(
        get_string('alertemails_invalid', 'local_sentinel', s($alertinvalid)),
        \core\output\notification::NOTIFY_ERROR
    );
}

$currentvalue = (string) get_config('local_sentinel', 'alertemails');

echo html_writer::start_div('card');
echo html_writer::start_div('card-body');
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/sentinel/alerts.php'))->out(false),
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'alertemails_submit', 'value' => 1]);
echo html_writer::tag(
    'p',
    s(get_string('alertemails_desc', 'local_sentinel')),
    ['class' => 'small text-muted mb-2']
);
echo html_writer::tag('textarea', s($currentvalue), [
    'name' => 'alertemails',
    'class' => 'form-control',
    'rows' => 4,
    'placeholder' => "alice@example.com\nbob@example.com",
]);
echo html_writer::tag('button', s(get_string('alertemails_save', 'local_sentinel')), [
    'type' => 'submit',
    'class' => 'btn btn-primary btn-sm mt-2',
]);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

// Section: Connection status (read-only summary; configure on Connect page).
$pushconfigured = (
    !empty(get_config('local_sentinel', 'pushenabled')) &&
    !empty(get_config('local_sentinel', 'pushendpoint')) &&
    !empty(get_config('local_sentinel', 'pushsecret'))
);
$pullconfigured = \local_sentinel\setup\helper::existing_token() !== null;

echo html_writer::tag(
    'h4',
    s(get_string('overview_connection_heading', 'local_sentinel')),
    ['class' => 'h6 text-uppercase text-muted mt-4']
);

echo html_writer::start_div('card');
echo html_writer::start_div('card-body py-2');
echo html_writer::start_div('d-flex justify-content-between align-items-center');
echo html_writer::start_div();
echo html_writer::tag(
    'div',
    s(get_string('overview_send_status', 'local_sentinel')) . ': '
    . html_writer::tag(
        'span',
        '● ' . s(get_string($pushconfigured ? 'connect_configured' : 'connect_not_configured', 'local_sentinel')),
        ['class' => 'small ' . ($pushconfigured ? 'text-success' : 'text-muted')]
    )
);
echo html_writer::tag(
    'div',
    s(get_string('overview_pull_status', 'local_sentinel')) . ': '
    . html_writer::tag(
        'span',
        '● ' . s(get_string($pullconfigured ? 'connect_configured' : 'connect_not_configured', 'local_sentinel')),
        ['class' => 'small ' . ($pullconfigured ? 'text-success' : 'text-muted')]
    )
);
echo html_writer::end_div();
echo html_writer::link(
    new moodle_url('/local/sentinel/connect.php'),
    s(get_string('overview_manage_connection', 'local_sentinel')),
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// Section: Data shared with dashboard.
echo html_writer::tag(
    'h4',
    s(get_string('egress_heading', 'local_sentinel')),
    ['class' => 'h6 text-uppercase text-muted mt-4']
);

if ($egresssaved) {
    echo $OUTPUT->notification(
        get_string('egress_saved', 'local_sentinel'),
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$excludedslices = \local_sentinel\collector::excluded_slices();
$excludedfields = \local_sentinel\collector::excluded_fields();

echo html_writer::start_div('card');
echo html_writer::start_div('card-body');
echo html_writer::tag(
    'p',
    s(get_string('egress_intro', 'local_sentinel')),
    ['class' => 'small text-muted mb-3']
);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/sentinel/alerts.php'))->out(false),
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'egress_submit', 'value' => 1]);

echo html_writer::tag(
    'h5',
    s(get_string('egress_slices_heading', 'local_sentinel')),
    ['class' => 'h6 mb-2']
);
foreach (\local_sentinel\collector::ALL_SLICES as $slice) {
    $checked = !in_array($slice, $excludedslices, true);
    echo html_writer::start_div('form-check');
    echo html_writer::empty_tag('input', array_merge(
        ['type' => 'checkbox', 'class' => 'form-check-input',
         'name' => 'egress_slices[]', 'value' => $slice, 'id' => "egress_slice_$slice"],
        $checked ? ['checked' => 'checked'] : []
    ));
    echo html_writer::tag(
        'label',
        s(get_string('egress_slice_label_' . $slice, 'local_sentinel')),
        ['class' => 'form-check-label', 'for' => "egress_slice_$slice"]
    );
    echo html_writer::end_div();
}

echo html_writer::tag(
    'h5',
    s(get_string('egress_fields_heading', 'local_sentinel')),
    ['class' => 'h6 mt-3 mb-2']
);
echo html_writer::tag(
    'p',
    s(get_string('egress_fields_intro', 'local_sentinel')),
    ['class' => 'small text-muted']
);
$fieldlabels = [
    'auth.failed_logins.top_accounts' => 'egress_field_failed_logins',
    'auth.tokens.entries' => 'egress_field_tokens_entries',
    'environment.database.host' => 'egress_field_db_host',
    'environment.os.hostname' => 'egress_field_os_hostname',
];
foreach (\local_sentinel\collector::REDACTABLE_FIELDS as $path) {
    $checked = !in_array($path, $excludedfields, true);
    $id = 'egress_field_' . md5($path);
    echo html_writer::start_div('form-check');
    echo html_writer::empty_tag('input', array_merge(
        ['type' => 'checkbox', 'class' => 'form-check-input',
         'name' => 'egress_fields[]', 'value' => $path, 'id' => $id],
        $checked ? ['checked' => 'checked'] : []
    ));
    echo html_writer::tag(
        'label',
        s(get_string($fieldlabels[$path], 'local_sentinel'))
            . ' ' . html_writer::tag('code', $path, ['class' => 'small text-muted ms-1']),
        ['class' => 'form-check-label', 'for' => $id]
    );
    echo html_writer::end_div();
}

echo html_writer::start_div('mt-3 d-flex gap-2 align-items-center');
echo html_writer::tag('button', s(get_string('egress_save', 'local_sentinel')), [
    'type' => 'submit', 'class' => 'btn btn-primary btn-sm',
]);
echo html_writer::link(
    new moodle_url('/local/sentinel/alerts.php', ['preview' => 1]),
    s(get_string('egress_preview_link', 'local_sentinel')) . ' →',
    ['class' => 'small ms-2']
);
echo html_writer::end_div();

echo html_writer::end_tag('form');

if ($showpreview) {
    $preview = \local_sentinel\collector::get_snapshot_for_egress();
    echo html_writer::tag(
        'h5',
        s(get_string('egress_preview_heading', 'local_sentinel')),
        ['class' => 'h6 mt-4']
    );
    echo html_writer::tag(
        'pre',
        s(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
        ['style' => 'max-height: 60vh; overflow: auto; background: #f6f8fa; padding: 12px; '
        . 'border-radius: 4px; font-size: 0.85em;']
    );
}

echo html_writer::end_div();
echo html_writer::end_div();

// Section: Core file integrity (a data/feature preference, not a connection step).
echo html_writer::tag(
    'h4',
    s(get_string('settingsheading_integrity', 'local_sentinel')),
    ['class' => 'h6 text-uppercase text-muted mt-4']
);

if ($integritysaved) {
    echo $OUTPUT->notification(
        get_string('integrity_saved', 'local_sentinel'),
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo html_writer::start_div('card');
echo html_writer::start_div('card-body');
echo html_writer::tag(
    'p',
    s(get_string('integrityenabled_desc', 'local_sentinel')),
    ['class' => 'small text-muted mb-3']
);
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/sentinel/alerts.php'))->out(false),
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'integrity_submit', 'value' => 1]);
echo html_writer::start_div('form-check mb-3');
echo html_writer::empty_tag('input', array_merge(
    ['type' => 'checkbox', 'class' => 'form-check-input', 'name' => 'integrityenabled',
     'value' => 1, 'id' => 'sentinel-integrityenabled'],
    get_config('local_sentinel', 'integrityenabled') ? ['checked' => 'checked'] : []
));
echo html_writer::tag('label', s(get_string('integrityenabled', 'local_sentinel')), [
    'class' => 'form-check-label', 'for' => 'sentinel-integrityenabled',
]);
echo html_writer::end_div();
echo html_writer::tag('button', s(get_string('egress_save', 'local_sentinel')), [
    'type' => 'submit', 'class' => 'btn btn-primary btn-sm',
]);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->footer();
