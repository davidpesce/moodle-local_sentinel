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

// Tab dispatch.
$allowedtabs = ['health', 'environment', 'plugins', 'auth', 'reports', 'configdrift'];
$tab = optional_param('tab', 'health', PARAM_ALPHA);
if (!in_array($tab, $allowedtabs, true)) {
    $tab = 'health';
}

// Handle an explicit cache refresh before any output. Sesskey guarded so a
// crawler can't trigger collector runs.
if (optional_param('refresh', 0, PARAM_INT)) {
    require_sesskey();
    \local_sentinel\cache_helper::purge();
    redirect(new moodle_url('/local/sentinel/overview.php', ['tab' => $tab]));
}

// Handle the per-row "Ignore" / "Show" toggles on the Config drift tab before
// any output, so we can redirect-back instead of re-submitting on refresh.
$driftaction = optional_param('drift_ignore_action', '', PARAM_ALPHA);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($driftaction, ['ignore', 'unignore'], true)) {
    require_sesskey();
    $fullname = trim(optional_param('drift_ignore_fullname', '', PARAM_RAW));
    if ($fullname !== '') {
        $list = \local_sentinel\output\renderer::get_ignored_list();
        if ($driftaction === 'ignore' && !in_array($fullname, $list, true)) {
            $list[] = $fullname;
        } else if ($driftaction === 'unignore') {
            $list = array_values(array_filter($list, fn($x) => $x !== $fullname));
        }
        \local_sentinel\output\renderer::set_ignored_list($list);
    }
    redirect(new moodle_url('/local/sentinel/overview.php', ['tab' => 'configdrift']));
}

// Per-user dismissal of the "connect a dashboard" pointer below.
if (optional_param('dismissdashboardnote', 0, PARAM_INT)) {
    require_sesskey();
    set_user_preference('local_sentinel_dashboard_note_dismissed', 1);
    redirect(new moodle_url('/local/sentinel/overview.php', ['tab' => $tab]));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('overview_heading', 'local_sentinel'));
echo $PAGE->get_renderer('local_sentinel')->sentinel_subnav('overview');

// Gentle pointer to central monitoring for sites not yet connected (neither
// push configured nor a registration activated). Dismissible per user, and
// disappears on its own once the site connects.
$overviewpushenabled = (bool) get_config('local_sentinel', 'pushenabled');
$overviewregstatus = \local_sentinel\registration_state::get()['status'] ?? 'never';
if (
    !$overviewpushenabled && $overviewregstatus !== 'activated'
        && !get_user_preferences('local_sentinel_dashboard_note_dismissed', false)
) {
    $dismissurl = new moodle_url(
        '/local/sentinel/overview.php',
        ['tab' => $tab, 'dismissdashboardnote' => 1, 'sesskey' => sesskey()]
    );
    $connecturl = new moodle_url('/local/sentinel/connect.php');
    echo $OUTPUT->notification(
        get_string('overview_dashboard_note', 'local_sentinel', [
            'connecturl' => $connecturl->out(false),
            'dismissurl' => $dismissurl->out(false),
        ]),
        \core\output\notification::NOTIFY_INFO,
        false
    );
}

// Collect everything in one call (via the UI-side cache). Catch surface
// errors so a broken collector never breaks the page — operators get a
// notification instead.
try {
    $snapshot = \local_sentinel\cache_helper::get_snapshot();
} catch (\Throwable $e) {
    echo $OUTPUT->notification(
        get_string('overview_snapshot_error', 'local_sentinel', $e->getMessage()),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    return;
}

// Section: Headline metrics.
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

// Hover styles for the clickable metric cards + quiet styling for healthy
// (zero-value) ones. Inlined so the page is self-contained (no project-wide
// stylesheet in this plugin).
echo '<style>
    a.sentinel-metric-card { color: inherit; text-decoration: none; display: block; height: 100%;
        transition: transform 0.1s, box-shadow 0.1s; }
    a.sentinel-metric-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    a.sentinel-metric-card .metric-link-hint { opacity: 0.4; }
    a.sentinel-metric-card:hover .metric-link-hint { opacity: 0.9; }
    a.sentinel-metric-card .card.sentinel-quiet { opacity: 0.65; }
    a.sentinel-metric-card:hover .card.sentinel-quiet { opacity: 1; }
</style>';

// Section: Action needed. The page leads with what to DO — a severity-ordered
// list of concrete actions derived from the snapshot, each linking to the
// page where it gets fixed. Point-in-time by design; continuous watching is
// the dashboard's job.
echo local_sentinel_overview_action_panel(\local_sentinel\actions::from_snapshot($snapshot));

// Four cards. Each is a link to the native Moodle page where the operator
// can investigate the underlying data.
echo html_writer::start_div('row g-3 mb-4');
// Both Critical and Errors aggregate across multiple reports (critical = status,
// errors = perf + security + status). Linking to the in-plugin Reports tab —
// which shows all three side-by-side — avoids dropping the user onto only one
// of the contributing native pages.
$reportstaburl = new moodle_url('/local/sentinel/overview.php', ['tab' => 'reports']);
echo local_sentinel_overview_metric_card(
    get_string('overview_metric_critical', 'local_sentinel'),
    $critical,
    get_string('overview_metric_critical_subtext', 'local_sentinel'),
    $critical > 0 ? 'border-danger' : 'sentinel-quiet',
    $reportstaburl
);
echo local_sentinel_overview_metric_card(
    get_string('overview_metric_errors', 'local_sentinel'),
    $errors,
    get_string('overview_metric_errors_subtext', 'local_sentinel'),
    $errors > 0 ? 'border-danger' : 'sentinel-quiet',
    $reportstaburl
);
echo local_sentinel_overview_metric_card(
    get_string('overview_metric_plugin_updates', 'local_sentinel'),
    $pluginupdates,
    get_string('overview_metric_plugin_updates_subtext', 'local_sentinel'),
    $pluginupdates > 0 ? 'border-warning' : 'sentinel-quiet',
    new moodle_url('/admin/plugins.php', null, 'updatable')
);
echo local_sentinel_overview_metric_card(
    get_string('overview_metric_core_update', 'local_sentinel'),
    $coreupdate,
    get_string('overview_metric_core_update_subtext', 'local_sentinel'),
    $coreupdate > 0 ? 'border-warning' : 'sentinel-quiet',
    new moodle_url('/admin/index.php')
);
echo html_writer::end_div();

// Section: Tab strip + active tab body.
$tabs = [];
foreach ($allowedtabs as $name) {
    $tabs[] = new tabobject(
        $name,
        new moodle_url('/local/sentinel/overview.php', ['tab' => $name]),
        get_string('overview_tab_' . $name, 'local_sentinel')
    );
}
echo $OUTPUT->tabtree($tabs, $tab);

$renderer = $PAGE->get_renderer('local_sentinel');
$method = 'render_' . ($tab === 'configdrift' ? 'config_drift' : $tab) . '_tab';
echo $renderer->$method($snapshot);

echo $OUTPUT->footer();


/**
 * Render one metric card in the headline row.
 *
 * The card is wrapped in an <a> so operators can click through to where the
 * underlying data can be investigated (system status report, plugins overview,
 * notifications page, etc.). The hover state is styled inline at the top of
 * the page render.
 *
 * @param string     $label
 * @param int        $value
 * @param string     $subtext
 * @param string     $borderclass Bootstrap border-* utility, e.g. 'border-success'.
 * @param moodle_url $href Destination the card links to.
 * @return string
 */
function local_sentinel_overview_metric_card(
    string $label,
    int $value,
    string $subtext,
    string $borderclass,
    moodle_url $href
): string {
    $body = html_writer::start_div('d-flex justify-content-between align-items-start mb-1');
    $body .= html_writer::tag('div', s($label), ['class' => 'text-uppercase text-muted small']);
    $body .= html_writer::tag('span', '›', ['class' => 'metric-link-hint h5 mb-0']);
    $body .= html_writer::end_div();
    $body .= html_writer::tag('div', (string) $value, ['class' => 'display-6 fw-bold lh-1 mb-1']);
    $body .= html_writer::tag('div', s($subtext), ['class' => 'small text-muted']);

    $card = html_writer::div(
        html_writer::div($body, 'card-body'),
        "card border-2 {$borderclass} h-100"
    );

    return html_writer::div(
        html_writer::link(
            $href,
            $card,
            ['class' => 'sentinel-metric-card']
        ),
        'col-md-3'
    );
}

/**
 * Render the "Action needed" panel from the derived action list.
 *
 * Severity-ordered rows, each with a badge, the message, and a link to the
 * page where the admin acts. Empty list renders the green all-clear state.
 *
 * @param array $actions Items from \local_sentinel\actions::from_snapshot().
 * @return string
 */
function local_sentinel_overview_action_panel(array $actions): string {
    $heading = html_writer::tag(
        'h4',
        s(get_string('overview_action_heading', 'local_sentinel'))
            . ' ' . html_writer::tag('span', (string) count($actions), [
                'class' => 'badge ms-1 ' . (count($actions) ? 'text-bg-secondary' : 'text-bg-success'),
            ]),
        ['class' => 'h5 mb-2']
    );

    if (empty($actions)) {
        $body = html_writer::div(
            html_writer::tag('span', '✓ ', ['class' => 'text-success fw-bold'])
            . html_writer::tag('strong', s(get_string('overview_action_none', 'local_sentinel')))
            . ' ' . html_writer::tag(
                'span',
                s(get_string('overview_action_none_detail', 'local_sentinel')),
                ['class' => 'text-muted small']
            ),
            'alert alert-success mb-0'
        );
        return html_writer::div($heading . $body, 'mb-4');
    }

    $badges = [
        'danger' => 'text-bg-danger',
        'warning' => 'text-bg-warning',
        'info' => 'text-bg-info',
    ];
    $rows = '';
    foreach ($actions as $action) {
        $left = html_writer::tag('span', s($action['severity']), [
            'class' => 'badge me-2 ' . ($badges[$action['severity']] ?? 'text-bg-secondary'),
        ]) . s($action['message']);
        $right = $action['url'] instanceof moodle_url
            ? html_writer::link($action['url'], s(get_string('overview_action_go', 'local_sentinel')) . ' →', [
                'class' => 'btn btn-sm btn-outline-secondary text-nowrap',
            ])
            : '';
        $rows .= html_writer::div(
            html_writer::div($left, 'me-3') . $right,
            'list-group-item d-flex justify-content-between align-items-center'
        );
    }
    return html_writer::div(
        $heading . html_writer::div($rows, 'list-group'),
        'mb-4'
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
