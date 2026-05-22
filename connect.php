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
echo html_writer::link(
    $sendurl,
    s(get_string('connect_send_cta', 'local_sentinel')),
    ['class' => 'btn btn-primary mt-auto align-self-start']
);
echo html_writer::end_div(); // card-body.
echo html_writer::end_div(); // card.
echo html_writer::end_div(); // col.

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

echo html_writer::end_div(); // row.

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
