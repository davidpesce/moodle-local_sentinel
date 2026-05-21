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
 * Admin web page: bootstrap Sentinel web service access.
 *
 * Same behaviour as cli/setup.php but with a Moodle admin form. Renders a
 * configurable form, runs the setup on submit, then shows the generated
 * token alongside copy-ready dashboard registration instructions.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_sentinel_setup');

$existingtoken = \local_sentinel\setup\helper::existing_token();

$form = new \local_sentinel\form\setup_form(null, [
    'has_existing_token' => $existingtoken !== null,
]);

$result = null;
if ($data = $form->get_data()) {
    require_sesskey();
    $result = \local_sentinel\setup\helper::run(
        [
            'username' => $data->username,
            'rolename' => $data->rolename,
            'roleshortname' => $data->roleshortname,
        ],
        !empty($data->regenerate)
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('setup_heading', 'local_sentinel'));

if ($result === null) {
    echo $OUTPUT->box(get_string('setup_intro', 'local_sentinel'));

    if ($existingtoken !== null) {
        echo $OUTPUT->notification(
            get_string('setup_existing_notice', 'local_sentinel'),
            \core\output\notification::NOTIFY_INFO
        );
    }

    $form->display();
} else {
    echo $OUTPUT->notification(
        get_string('setup_success', 'local_sentinel'),
        \core\output\notification::NOTIFY_SUCCESS
    );

    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h4', get_string('setup_token_label', 'local_sentinel'), ['class' => 'h5']);

    $tokenid = html_writer::random_id('sentinel-token-');
    echo html_writer::tag('input', '', [
        'id' => $tokenid,
        'type' => 'text',
        'readonly' => 'readonly',
        'class' => 'form-control form-control-lg font-monospace',
        'value' => $result->token,
    ]);
    echo html_writer::tag('button', get_string('setup_copy', 'local_sentinel'), [
        'type' => 'button',
        'class' => 'btn btn-secondary btn-sm mt-2',
        'data-copy-target' => $tokenid,
    ]);

    echo html_writer::tag('h5', get_string('setup_endpoint_label', 'local_sentinel'), ['class' => 'mt-3 h6']);
    echo html_writer::tag('p', html_writer::tag('code', $result->endpoint));

    echo html_writer::tag('h5', get_string('setup_dashboard_label', 'local_sentinel'), ['class' => 'mt-3 h6']);
    echo html_writer::tag('p', get_string('setup_dashboard_help', 'local_sentinel'));
    echo html_writer::start_tag('ol');
    echo html_writer::tag('li', get_string('setup_dashboard_step1', 'local_sentinel'));
    echo html_writer::tag('li', get_string('setup_dashboard_step2', 'local_sentinel'));
    echo html_writer::tag('li', get_string('setup_dashboard_step3', 'local_sentinel'));
    echo html_writer::end_tag('ol');

    echo html_writer::end_div();
    echo html_writer::end_div();

    echo $OUTPUT->collapsible_region_start('', 'setup-log', get_string('setup_log_label', 'local_sentinel'), '', true);
    echo html_writer::start_tag('ul', ['class' => 'mb-0']);
    foreach ($result->steps as $step) {
        echo html_writer::tag('li', s($step));
    }
    echo html_writer::end_tag('ul');
    echo $OUTPUT->collapsible_region_end();

    echo html_writer::link(
        new moodle_url('/local/sentinel/setup.php'),
        get_string('setup_back', 'local_sentinel'),
        ['class' => 'btn btn-link mt-3']
    );

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
}

echo $OUTPUT->footer();
