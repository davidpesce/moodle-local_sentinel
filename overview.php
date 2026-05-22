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
 * Admin web page: Sentinel — Overview.
 *
 * Live admin digest: runs the plugin's own collectors on demand and renders
 * the same headline metrics the central dashboard's instance-detail page
 * shows for this Moodle, plus a few support rows and a compact connection-
 * status footer linking to the Connect page.
 *
 * Generated each request — no caching. If load time becomes a problem, a
 * short MUC cache around collector::get_snapshot() is the right knob.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_sentinel_overview');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('overview_heading', 'local_sentinel'));

// Collect everything in one call. Catch surface errors so a broken collector
// never breaks the page — operators get a notification instead.
try {
    $snapshot = \local_sentinel\collector::get_snapshot();
} catch (\Throwable $e) {
    echo $OUTPUT->notification(
        get_string('overview_snapshot_error', 'local_sentinel', $e->getMessage()),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    return;
}

// ---------------------------------------------------------------------------
// Headline metrics.
// ---------------------------------------------------------------------------

$reports = $snapshot['reports'] ?? [];
$plugins = $snapshot['plugins'] ?? [];
$status = $snapshot['status'] ?? [];
$health = $snapshot['health'] ?? [];

$critical = (int) (($reports['system_status']['counts_by_status']['critical'] ?? 0));
$errors = (
    (int) (($reports['performance']['counts_by_status']['error'] ?? 0))
    + (int) (($reports['security']['counts_by_status']['error'] ?? 0))
    + (int) (($reports['system_status']['counts_by_status']['error'] ?? 0))
);
$pluginupdates = count($plugins['updates_available'] ?? []);

// Patch-diff between current release and latest_on_branch — mirrors the
// dashboard extractor's logic (`fleet/extractors.py:_parse_patch`).
$currentpatch = local_sentinel_overview_release_patch($status['release'] ?? '');
$latestpatch = local_sentinel_overview_release_patch(
    $status['core_update']['latest_on_branch']['release'] ?? ''
);
$coreupdate = ($currentpatch !== null && $latestpatch !== null)
    ? max(0, $latestpatch - $currentpatch)
    : 0;

// Context strip.
$contextdata = (object) [
    'release' => $status['release'] ?? '',
    'generated' => userdate(strtotime($snapshot['generated_at'] ?? 'now'), '%Y-%m-%d %H:%M:%S'),
];
echo html_writer::tag(
    'div',
    s(get_string('overview_context_strip', 'local_sentinel', $contextdata)),
    ['class' => 'text-muted mb-3 small']
);

// Four cards.
echo html_writer::start_div('row g-3 mb-4');
echo local_sentinel_overview_metric_card(
    get_string('overview_metric_critical', 'local_sentinel'),
    $critical,
    get_string('overview_metric_critical_subtext', 'local_sentinel'),
    $critical > 0 ? 'border-danger' : 'border-success'
);
echo local_sentinel_overview_metric_card(
    get_string('overview_metric_errors', 'local_sentinel'),
    $errors,
    get_string('overview_metric_errors_subtext', 'local_sentinel'),
    $errors > 0 ? 'border-danger' : 'border-success'
);
echo local_sentinel_overview_metric_card(
    get_string('overview_metric_plugin_updates', 'local_sentinel'),
    $pluginupdates,
    get_string('overview_metric_plugin_updates_subtext', 'local_sentinel'),
    $pluginupdates > 0 ? 'border-warning' : 'border-success'
);
echo local_sentinel_overview_metric_card(
    get_string('overview_metric_core_update', 'local_sentinel'),
    $coreupdate,
    get_string('overview_metric_core_update_subtext', 'local_sentinel'),
    $coreupdate > 0 ? 'border-warning' : 'border-success'
);
echo html_writer::end_div();

// ---------------------------------------------------------------------------
// Supporting rows.
// ---------------------------------------------------------------------------

echo html_writer::tag(
    'h4',
    s(get_string('overview_section_health', 'local_sentinel')),
    ['class' => 'h6 text-uppercase text-muted mt-4']
);

echo html_writer::start_tag('table', ['class' => 'table table-sm']);
echo html_writer::start_tag('tbody');

// Cron last run.
$cronlast = (int) ($health['cron']['last_run'] ?? 0);
$cronlag = (int) ($health['cron']['seconds_since_last_run'] ?? 0);
$cronvalue = $cronlast === 0
    ? html_writer::tag('span', s(get_string('overview_cron_never', 'local_sentinel')), ['class' => 'text-danger'])
    : (userdate($cronlast, '%Y-%m-%d %H:%M:%S') . ' '
        . html_writer::tag('span', "({$cronlag}s ago)", ['class' => 'text-muted small']));
echo local_sentinel_overview_kv_row(get_string('overview_cron_last_run', 'local_sentinel'), $cronvalue);

// Overdue tasks.
$overdue = (int) ($health['tasks']['scheduled_overdue_count'] ?? 0);
$overduevalue = $overdue > 0
    ? html_writer::tag('span', $overdue, ['class' => 'text-danger fw-bold'])
        . ' ' . html_writer::link(
            new moodle_url('/admin/tool/task/scheduledtasks.php'),
            get_string('view'),
            ['class' => 'small ms-2']
        )
    : html_writer::tag('span', '0', ['class' => 'text-success']);
echo local_sentinel_overview_kv_row(get_string('overview_overdue_tasks', 'local_sentinel'), $overduevalue);

// Plugins missing from disk.
$missing = 0;
foreach (($plugins['standard'] ?? []) as $p) {
    if (!empty($p['missing_from_disk'])) {
        $missing++;
    }
}
foreach (($plugins['third_party'] ?? []) as $p) {
    if (!empty($p['missing_from_disk'])) {
        $missing++;
    }
}
$missingvalue = $missing > 0
    ? html_writer::tag('span', $missing, ['class' => 'text-warning fw-bold'])
    : html_writer::tag('span', '0', ['class' => 'text-success']);
echo local_sentinel_overview_kv_row(get_string('overview_plugins_missing', 'local_sentinel'), $missingvalue);

// Active users.
$au = $health['active_users'] ?? [];
$dau = (int) ($au['dau'] ?? 0);
$wau = (int) ($au['wau'] ?? 0);
$mau = (int) ($au['mau'] ?? 0);
echo local_sentinel_overview_kv_row(
    get_string('overview_active_users', 'local_sentinel'),
    "DAU {$dau} · WAU {$wau} · MAU {$mau}"
);

// Disk free on moodledata.
$dataroot = $health['disk']['dataroot'] ?? [];
$diskfree = $dataroot['free_bytes'] ?? null;
$disktotal = $dataroot['total_bytes'] ?? null;
if ($diskfree !== null && $disktotal !== null && $disktotal > 0) {
    $pct = round(($diskfree / $disktotal) * 100, 1);
    $diskvalue = display_size($diskfree) . ' / ' . display_size($disktotal) . " ({$pct}% free)";
} else {
    $diskvalue = '—';
}
echo local_sentinel_overview_kv_row(get_string('overview_disk_free', 'local_sentinel'), $diskvalue);

// SSL cert days remaining — only when checked succeeded.
$ssl = $snapshot['environment']['ssl'] ?? [];
if (!empty($ssl['checked'])) {
    $days = (int) ($ssl['days_remaining'] ?? 0);
    if ($days < 14) {
        $sslclass = 'text-danger fw-bold';
    } else if ($days < 60) {
        $sslclass = 'text-warning';
    } else {
        $sslclass = 'text-success';
    }
    $sslvalue = html_writer::tag('span', "{$days} days", ['class' => $sslclass]);
    echo local_sentinel_overview_kv_row(get_string('overview_ssl_days_remaining', 'local_sentinel'), $sslvalue);
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

// ---------------------------------------------------------------------------
// Connection status footer.
// ---------------------------------------------------------------------------

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

echo $OUTPUT->footer();


/**
 * Render one metric card in the headline row.
 *
 * @param string $label
 * @param int $value
 * @param string $subtext
 * @param string $borderclass Bootstrap border-* utility, e.g. 'border-success'.
 * @return string
 */
function local_sentinel_overview_metric_card(string $label, int $value, string $subtext, string $borderclass): string {
    $body = html_writer::tag(
        'div',
        s($label),
        ['class' => 'text-uppercase small-meta text-muted small mb-1']
    );
    $body .= html_writer::tag('div', (string) $value, ['class' => 'display-6 fw-bold lh-1 mb-1']);
    $body .= html_writer::tag('div', s($subtext), ['class' => 'small text-muted']);

    return html_writer::div(
        html_writer::div(
            html_writer::div($body, 'card-body'),
            "card border-2 {$borderclass} h-100"
        ),
        'col-md-3'
    );
}

/**
 * Render one row of the supporting kv table.
 *
 * @param string $label
 * @param string $value (HTML already-escaped where it needs to be)
 * @return string
 */
function local_sentinel_overview_kv_row(string $label, string $value): string {
    return html_writer::tag(
        'tr',
        html_writer::tag('th', s($label), ['scope' => 'row', 'style' => 'width:30%'])
        . html_writer::tag('td', $value)
    );
}

/**
 * Extract the patch number from a Moodle release string.
 *
 * '4.5.10+ (Build: 20260306)' → 10
 * '5.3dev (Build: ...)'       → null
 *
 * @param string $release
 * @return int|null
 */
function local_sentinel_overview_release_patch(string $release): ?int {
    if ($release === '') {
        return null;
    }
    if (preg_match('/^\d+\.\d+\.(\d+)/', trim($release), $m)) {
        return (int) $m[1];
    }
    return null;
}
