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
 * Renderer for the Sentinel Overview tabs.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\output;

use core\check\result;
use html_writer;
use moodle_url;
use plugin_renderer_base;

/**
 * Per-tab renderers for the Overview admin page.
 *
 * Each method takes the full snapshot array and returns an HTML string.
 * Methods read only the keys they need so a partial snapshot still renders
 * something reasonable.
 */
class renderer extends plugin_renderer_base {
    /**
     * Render the Health tab (current digest).
     *
     * @param array $snapshot
     * @return string
     */
    public function render_health_tab(array $snapshot): string {
        $health = $snapshot['health'] ?? [];
        $ssl = $snapshot['environment']['ssl'] ?? [];
        $out = $this->refresh_button('health');
        $rows = '';

        // Cron.
        $cronlast = (int) ($health['cron']['last_run'] ?? 0);
        $cronlag = (int) ($health['cron']['seconds_since_last_run'] ?? 0);
        $cronvalue = $cronlast === 0
            ? html_writer::tag('span', s(get_string('overview_cron_never', 'local_sentinel')), ['class' => 'text-danger'])
            : (userdate($cronlast, '%Y-%m-%d %H:%M:%S') . ' '
                . html_writer::tag('span', "({$cronlag}s ago)", ['class' => 'text-muted small']));
        $rows .= $this->kv_row(get_string('overview_cron_last_run', 'local_sentinel'), $cronvalue);

        // Overdue scheduled tasks — show the actual list inline so the
        // operator can see WHICH tasks are late. Moodle's
        // /admin/tool/task/scheduledtasks.php has no overdue filter, so
        // sending the user there blind was unhelpful. Each task name links
        // to its edit page on that screen.
        $overdue = (int) ($health['tasks']['scheduled_overdue_count'] ?? 0);
        $overduerows = $health['tasks']['scheduled_overdue'] ?? [];
        if ($overdue > 0) {
            $overduevalue = html_writer::tag('span', $overdue, ['class' => 'text-danger fw-bold'])
                . $this->overdue_tasks_detail($overduerows);
        } else {
            $overduevalue = html_writer::tag('span', '0', ['class' => 'text-success']);
        }
        $rows .= $this->kv_row(get_string('overview_overdue_tasks', 'local_sentinel'), $overduevalue);

        // Active users.
        $au = $health['active_users'] ?? [];
        $rows .= $this->kv_row(
            get_string('overview_active_users', 'local_sentinel'),
            sprintf(
                'DAU %d · WAU %d · MAU %d',
                (int) ($au['dau'] ?? 0),
                (int) ($au['wau'] ?? 0),
                (int) ($au['mau'] ?? 0)
            )
        );

        // Disk free.
        $dataroot = $health['disk']['dataroot'] ?? [];
        $diskfree = $dataroot['free_bytes'] ?? null;
        $disktotal = $dataroot['total_bytes'] ?? null;
        if ($diskfree !== null && $disktotal !== null && $disktotal > 0) {
            $pct = round(($diskfree / $disktotal) * 100, 1);
            $diskvalue = display_size($diskfree) . ' / ' . display_size($disktotal) . " ({$pct}% free)";
        } else {
            $diskvalue = '—';
        }
        $rows .= $this->kv_row(get_string('overview_disk_free', 'local_sentinel'), $diskvalue);

        // SSL.
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
            $rows .= $this->kv_row(get_string('overview_ssl_days_remaining', 'local_sentinel'), $sslvalue);
        }

        return $out . $this->kv_table($rows);
    }

    /**
     * Render the Environment tab.
     *
     * @param array $snapshot
     * @return string
     */
    public function render_environment_tab(array $snapshot): string {
        $env = $snapshot['environment'] ?? [];
        $out = $this->refresh_button('environment');
        $out .= $this->native_link(
            get_string('overview_env_native', 'local_sentinel'),
            new moodle_url('/admin/environment.php')
        );

        // PHP.
        $php = $env['php'] ?? [];
        $rows = $this->kv_row('Version', s((string) ($php['version'] ?? '')))
            . $this->kv_row('SAPI', s((string) ($php['sapi'] ?? '')))
            . $this->kv_row('memory_limit', s((string) ($php['memory_limit'] ?? '')))
            . $this->kv_row('max_execution_time', s((string) ($php['max_execution_time'] ?? '')))
            . $this->kv_row('upload_max_filesize', s((string) ($php['upload_max_filesize'] ?? '')))
            . $this->kv_row('post_max_size', s((string) ($php['post_max_size'] ?? '')))
            . $this->kv_row('Timezone', s((string) ($php['timezone'] ?? '')));
        $out .= $this->section_heading(get_string('overview_env_php', 'local_sentinel'));
        $out .= $this->kv_table($rows);

        // OS.
        $os = $env['os'] ?? [];
        $rows = $this->kv_row('System', s(($os['sysname'] ?? '') . ' ' . ($os['release'] ?? '')))
            . $this->kv_row('Machine', s((string) ($os['machine'] ?? '')))
            . $this->kv_row('Hostname', s((string) ($os['hostname'] ?? '')));
        $out .= $this->section_heading(get_string('overview_env_os', 'local_sentinel'));
        $out .= $this->kv_table($rows);

        // Web server.
        $web = $env['webserver'] ?? [];
        $sapilabel = !empty($web['sapi_is_fpm']) ? 'FPM' : (!empty($web['sapi_is_apache']) ? 'Apache' : 'Other');
        $rows = $this->kv_row('Software', s((string) ($web['software'] ?? '')))
            . $this->kv_row('SAPI', s($sapilabel));
        $out .= $this->section_heading(get_string('overview_env_web', 'local_sentinel'));
        $out .= $this->kv_table($rows);

        // Database.
        $db = $env['database'] ?? [];
        $size = isset($db['size_bytes']) && $db['size_bytes'] !== null ? display_size((int) $db['size_bytes']) : '—';
        $rows = $this->kv_row('Type', s((string) ($db['type'] ?? '')))
            . $this->kv_row('Version', s((string) ($db['version'] ?? '')))
            . $this->kv_row('Host', s((string) ($db['host'] ?? '')))
            . $this->kv_row('Name', html_writer::tag('code', s((string) ($db['name'] ?? ''))))
            . $this->kv_row('Prefix', html_writer::tag('code', s((string) ($db['prefix'] ?? ''))))
            . $this->kv_row('Total size', s($size));
        $out .= $this->section_heading(get_string('overview_env_db', 'local_sentinel'));
        $out .= $this->kv_table($rows);

        // OPcache.
        $oc = $env['opcache'] ?? [];
        if (!empty($oc['enabled'])) {
            $hitrate = isset($oc['hit_rate']) ? round((float) $oc['hit_rate'], 2) . '%' : '—';
            $rows = $this->kv_row('Status', html_writer::tag('span', 'enabled', ['class' => 'text-success']))
                . $this->kv_row('Cached scripts', s((string) ($oc['num_cached_scripts'] ?? 0)))
                . $this->kv_row('Memory used', display_size((int) ($oc['used_memory'] ?? 0))
                    . ' (free: ' . display_size((int) ($oc['free_memory'] ?? 0)) . ', wasted: '
                    . display_size((int) ($oc['wasted_memory'] ?? 0)) . ')')
                . $this->kv_row('Hit rate', s($hitrate));
        } else if (isset($oc['measurable']) && !$oc['measurable']) {
            // Collected under CLI/cron, where OPcache is per-SAPI and not visible.
            $rows = $this->kv_row('Status', html_writer::tag(
                'span',
                'not measurable in this context (' . s((string) ($oc['reason'] ?? '')) . ')',
                ['class' => 'text-muted']
            ));
        } else {
            $rows = $this->kv_row('Status', html_writer::tag(
                'span',
                'disabled (' . s((string) ($oc['reason'] ?? '')) . ')',
                ['class' => 'text-muted']
            ));
        }
        $out .= $this->section_heading(get_string('overview_env_opcache', 'local_sentinel'));
        $out .= $this->kv_table($rows);

        // SSL.
        $ssl = $env['ssl'] ?? [];
        if (!empty($ssl['checked'])) {
            $rows = $this->kv_row('Host', s((string) ($ssl['host'] ?? '')))
                . $this->kv_row('Issuer', s((string) ($ssl['issuer'] ?? '')))
                . $this->kv_row('Subject CN', s((string) ($ssl['subject_cn'] ?? '')))
                . $this->kv_row('Valid until', userdate((int) ($ssl['valid_to'] ?? 0), '%Y-%m-%d'))
                . $this->kv_row('Days remaining', s((string) ($ssl['days_remaining'] ?? '—')));
        } else {
            $rows = $this->kv_row('Status', html_writer::tag(
                'span',
                'not checked (' . s((string) ($ssl['reason'] ?? '')) . ')',
                ['class' => 'text-muted']
            ));
        }
        $out .= $this->section_heading(get_string('overview_env_ssl', 'local_sentinel'));
        $out .= $this->kv_table($rows);

        return $out;
    }

    /**
     * Render the Plugins tab.
     *
     * @param array $snapshot
     * @return string
     */
    public function render_plugins_tab(array $snapshot): string {
        $p = $snapshot['plugins'] ?? [];
        $all = $p['third_party'] ?? [];
        usort($all, fn($a, $b) => strcmp($a['component'] ?? '', $b['component'] ?? ''));

        $prefix = $this->refresh_button('plugins');

        $checktext = !empty($p['update_check']['last_fetched'])
            ? userdate((int) $p['update_check']['last_fetched'], '%Y-%m-%d %H:%M:%S')
                . ' (' . ((int) ($p['update_check']['age_seconds'] ?? 0)) . 's ago)'
            : 'never';
        $out = $prefix . $this->native_link(
            get_string('overview_plugins_manage', 'local_sentinel'),
            new moodle_url('/admin/plugins.php')
        );
        $out .= html_writer::tag(
            'p',
            get_string('overview_plugins_intro', 'local_sentinel', (object) [
                'count' => count($all),
                'fetched' => s($checktext),
            ]),
            ['class' => 'small text-muted mb-2']
        );

        if (empty($all)) {
            $out .= html_writer::tag(
                'p',
                s(get_string('overview_plugins_none', 'local_sentinel')),
                ['class' => 'text-muted']
            );
            return $out;
        }

        $table = new \html_table();
        $table->attributes['class'] = 'admintable generaltable';
        $table->head = [
            get_string('overview_plugin_component', 'local_sentinel'),
            get_string('overview_plugin_version', 'local_sentinel'),
            get_string('overview_plugin_update', 'local_sentinel'),
        ];

        // Exceptions first (missing from disk / update available), the
        // up-to-date bulk collapsed behind an expander — nothing is removed,
        // attention is just earned.
        $headrow = $table->head;
        $attention = [];
        $fine = [];
        foreach ($all as $row) {
            $version = (string) ($row['release'] ?? '') . ' (' . ((int) ($row['version_disk'] ?? 0)) . ')';
            if (!empty($row['missing_from_disk'])) {
                $update = html_writer::tag('span', 'missing on disk', ['class' => 'text-danger']);
            } else if (!empty($row['update_available'])) {
                $latest = (int) ($row['version_latest'] ?? 0);
                $update = html_writer::tag('span', 'update available → ' . $latest, ['class' => 'text-warning']);
            } else {
                $update = html_writer::tag('span', '✓', ['class' => 'text-success']);
            }
            $name = html_writer::tag('code', s((string) ($row['component'] ?? '')))
                . html_writer::tag('div', s((string) ($row['name'] ?? '')), ['class' => 'small text-muted']);
            $cells = [$name, s($version), $update];
            if (!empty($row['missing_from_disk']) || !empty($row['update_available'])) {
                $attention[] = $cells;
            } else {
                $fine[] = $cells;
            }
        }

        if (!empty($attention)) {
            $table->data = $attention;
            $out .= html_writer::table($table);
        }
        if (!empty($fine)) {
            $finetable = new \html_table();
            $finetable->attributes['class'] = 'admintable generaltable';
            $finetable->head = $headrow;
            $finetable->data = $fine;
            $out .= $this->collapsed_section(
                get_string('overview_collapsed_plugins', 'local_sentinel', count($fine)),
                html_writer::table($finetable)
            );
        }
        return $out;
    }

    /**
     * Render the Authentication tab.
     *
     * @param array $snapshot
     * @return string
     */
    public function render_auth_tab(array $snapshot): string {
        $auth = $snapshot['auth'] ?? [];
        $out = $this->refresh_button('auth');
        $out .= $this->section_heading(
            get_string('overview_auth_methods', 'local_sentinel')
                . ' ' . $this->native_inline_link(
                    get_string('overview_view_native', 'local_sentinel'),
                    new moodle_url('/admin/settings.php', ['section' => 'manageauths'])
                ),
            false
        );

        $table = new \html_table();
        $table->attributes['class'] = 'admintable generaltable';
        $table->head = [
            get_string('overview_auth_method', 'local_sentinel'),
            get_string('overview_auth_users_total', 'local_sentinel'),
            get_string('overview_auth_users_active', 'local_sentinel'),
        ];
        foreach (($auth['methods'] ?? []) as $m) {
            $table->data[] = [
                html_writer::tag('code', s((string) ($m['plugin'] ?? ''))),
                (int) ($m['total_users'] ?? 0),
                (int) ($m['active_users'] ?? 0),
            ];
        }
        $out .= html_writer::table($table);

        // Token summary.
        $tokens = $auth['tokens'] ?? [];
        $out .= $this->section_heading(
            get_string('overview_auth_tokens', 'local_sentinel')
                . ' ' . $this->native_inline_link(
                    get_string('overview_view_native', 'local_sentinel'),
                    new moodle_url('/admin/webservice/tokens.php')
                ),
            false
        );
        $rows = $this->kv_row('Total tokens', (int) ($tokens['total_count'] ?? 0));
        $without = (int) ($tokens['without_ip_restriction'] ?? 0);
        $rows .= $this->kv_row(
            'Without IP restriction',
            $without > 0
                ? html_writer::tag('span', $without, ['class' => 'text-warning fw-bold'])
            : html_writer::tag('span', '0', ['class' => 'text-success'])
        );
        $rows .= $this->kv_row('Never used', (int) ($tokens['never_used'] ?? 0));
        $rows .= $this->kv_row('Active in last 7 days', (int) ($tokens['active_last_7_days'] ?? 0));
        $rows .= $this->kv_row('Stale (>90 days)', (int) ($tokens['stale_over_90_days'] ?? 0));
        $rows .= $this->kv_row(
            'Expiring within 30 days',
            ((int) ($tokens['expiring_within_30_days'] ?? 0)) > 0
                ? html_writer::tag(
                    'span',
                    (int) $tokens['expiring_within_30_days'],
                    ['class' => 'text-warning fw-bold']
                )
            : html_writer::tag('span', '0', ['class' => 'text-success'])
        );
        $out .= $this->kv_table($rows);

        // Failed logins.
        $fail = $auth['failed_logins'] ?? [];
        $out .= $this->section_heading(get_string('overview_auth_failed', 'local_sentinel'));
        $rows = $this->kv_row('Total failed (across all accounts)', (int) ($fail['total_failed_count'] ?? 0));
        $rows .= $this->kv_row('Accounts with failures', (int) ($fail['accounts_with_failures'] ?? 0));
        $rows .= $this->kv_row(
            'Currently locked accounts',
            ((int) ($fail['locked_accounts'] ?? 0)) > 0
                ? html_writer::tag('span', (int) $fail['locked_accounts'], ['class' => 'text-danger fw-bold'])
            : html_writer::tag('span', '0', ['class' => 'text-success'])
        );
        $out .= $this->kv_table($rows);

        return $out;
    }

    /**
     * Render the Reports tab (performance / security / system_status).
     *
     * @param array $snapshot
     * @return string
     */
    public function render_reports_tab(array $snapshot): string {
        $r = $snapshot['reports'] ?? [];
        $out = $this->refresh_button('reports');
        $sections = [
            'performance' => [
                'heading' => get_string('overview_reports_performance', 'local_sentinel'),
                'link' => '/report/performance/index.php',
            ],
            'security' => [
                'heading' => get_string('overview_reports_security', 'local_sentinel'),
                'link' => '/report/security/index.php',
            ],
            'system_status' => [
                'heading' => get_string('overview_reports_system_status', 'local_sentinel'),
                'link' => '/report/status/index.php',
            ],
        ];
        foreach ($sections as $key => $meta) {
            $checks = $r[$key]['checks'] ?? [];
            $heading = $meta['heading']
                . ' ' . html_writer::link(
                    new moodle_url($meta['link']),
                    '(' . get_string('overview_view_native', 'local_sentinel') . ')',
                    ['class' => 'small ms-2']
                );
            $out .= $this->section_heading($heading, false);

            if (empty($checks)) {
                $out .= html_writer::tag(
                    'p',
                    s(get_string('overview_no_checks', 'local_sentinel')),
                    ['class' => 'text-muted small']
                );
                continue;
            }
            // Mirror the native /report/status/ table: status badge first
            // (right-aligned), then check name, then summary. Use admintable
            // CSS classes so theming matches site-wide check reports.
            // Non-OK checks render expanded; the passing bulk is collapsed
            // behind an expander so exceptions get the attention.
            $attention = [];
            $fine = [];
            foreach ($checks as $c) {
                $status = (string) ($c['status'] ?? '');
                $summary = (string) ($c['summary'] ?? '');
                $badge = $this->check_result(new result($status, $summary));
                $cells = [
                    $badge,
                    s((string) ($c['name'] ?? '')),
                    s($summary),
                ];
                if (in_array($status, ['warning', 'error', 'critical'], true)) {
                    $attention[] = $cells;
                } else {
                    $fine[] = $cells;
                }
            }
            if (!empty($attention)) {
                $out .= html_writer::table($this->checks_table($attention));
            }
            if (!empty($fine)) {
                $out .= $this->collapsed_section(
                    get_string('overview_collapsed_checks', 'local_sentinel', count($fine)),
                    html_writer::table($this->checks_table($fine))
                );
            }
        }
        return $out;
    }

    /**
     * Render the Integrity tab.
     *
     * Verdict + deviation tables from the most recent core-file scan. The
     * comparison needs a reference manifest, which a connected dashboard
     * provisions — unconnected sites see an explainer instead.
     *
     * @param array $snapshot
     * @return string
     */
    public function render_integrity_tab(array $snapshot): string {
        $integrity = $snapshot['integrity'] ?? [];
        $out = $this->refresh_button('integrity');

        if (empty($integrity['enabled'])) {
            return $out . html_writer::div(
                s(get_string('integrity_disabled_note', 'local_sentinel')),
                'alert alert-secondary'
            );
        }
        if (empty($integrity['manifest'])) {
            return $out . html_writer::div(
                s(get_string('integrity_no_manifest_note', 'local_sentinel')),
                'alert alert-info'
            );
        }

        $scan = $integrity['last_scan'] ?? [];
        $status = (string) ($scan['status'] ?? 'never');
        if ($status === 'never') {
            return $out . html_writer::div(
                s(get_string('integrity_no_scan_note', 'local_sentinel')),
                'alert alert-info'
            );
        }
        if ($status === 'error') {
            $out .= html_writer::div(
                s(get_string('integrity_scan_error', 'local_sentinel', (string) ($scan['error'] ?? ''))),
                'alert alert-danger'
            );
        }

        $modified = (int) ($integrity['modified_count'] ?? 0);
        $missing = (int) ($integrity['missing_count'] ?? 0);
        $unexpected = (int) ($integrity['unexpected_count'] ?? 0);
        if ($status === 'ok') {
            if ($modified + $missing + $unexpected === 0) {
                $out .= html_writer::div(
                    s(get_string('integrity_clean', 'local_sentinel')),
                    'alert alert-success'
                );
            } else {
                $out .= html_writer::div(
                    s(get_string('integrity_deviations_found', 'local_sentinel', (object) [
                        'modified' => $modified,
                        'missing' => $missing,
                        'unexpected' => $unexpected,
                    ])),
                    'alert ' . ($modified + $missing > 0 ? 'alert-danger' : 'alert-warning')
                );
            }
        }

        // Scan metadata.
        $rows = $this->kv_row(
            get_string('integrity_last_scan', 'local_sentinel'),
            empty($scan['scanned_at'])
                ? s(get_string('integrity_never', 'local_sentinel'))
                : userdate((int) $scan['scanned_at'], '%Y-%m-%d %H:%M:%S')
                    . ' ' . html_writer::tag(
                        'span',
                        '(' . (int) ($scan['files_scanned'] ?? 0) . ' files, '
                        . (int) ($scan['duration_seconds'] ?? 0) . 's)',
                        ['class' => 'text-muted small']
                    )
        );
        $manifestlabel = s((string) ($integrity['manifest']['version'] ?? ''));
        if (!empty($scan['manifest_version_mismatch'])) {
            $manifestlabel .= ' ' . html_writer::tag(
                'span',
                s(get_string('integrity_manifest_stale', 'local_sentinel', (string) ($integrity['core_version_full'] ?? ''))),
                ['class' => 'badge text-bg-warning']
            );
        }
        $rows .= $this->kv_row(get_string('integrity_manifest', 'local_sentinel'), $manifestlabel);
        $out .= $this->kv_table($rows);

        // Deviation lists, each behind an expander.
        $out .= $this->integrity_path_section(
            get_string('integrity_modified_heading', 'local_sentinel', $modified),
            array_map(
                fn($entry) => s((string) ($entry['path'] ?? '')) . ' '
                    . html_writer::tag('code', s(substr((string) ($entry['actual_sha1'] ?? ''), 0, 12)), [
                        'class' => 'small text-muted',
                    ]),
                $integrity['modified'] ?? []
            ),
            (int) ($integrity['modified_overflow'] ?? 0)
        );
        $out .= $this->integrity_path_section(
            get_string('integrity_missing_heading', 'local_sentinel', $missing),
            array_map(fn($path) => s((string) $path), $integrity['missing'] ?? []),
            (int) ($integrity['missing_overflow'] ?? 0)
        );
        $out .= $this->integrity_path_section(
            get_string('integrity_unexpected_heading', 'local_sentinel', $unexpected),
            array_map(fn($path) => s((string) $path), $integrity['unexpected'] ?? []),
            (int) ($integrity['unexpected_overflow'] ?? 0)
        );

        return $out;
    }

    /**
     * One collapsed deviation list for the Integrity tab.
     *
     * @param string   $heading  Summary line (includes the count).
     * @param string[] $items    Pre-escaped HTML lines.
     * @param int      $overflow Entries beyond the reported cap.
     * @return string Empty string when there is nothing to show.
     */
    protected function integrity_path_section(string $heading, array $items, int $overflow): string {
        if (empty($items)) {
            return '';
        }
        $list = html_writer::alist($items, ['class' => 'small mb-1']);
        if ($overflow > 0) {
            $list .= html_writer::tag(
                'p',
                s(get_string('integrity_overflow_note', 'local_sentinel', $overflow)),
                ['class' => 'text-muted small']
            );
        }
        return $this->collapsed_section($heading, $list);
    }

    /**
     * Build the shared checks table shell (status / check / summary columns).
     *
     * @param array $data Table rows.
     * @return \html_table
     */
    protected function checks_table(array $data): \html_table {
        $table = new \html_table();
        $table->attributes['class'] = 'admintable generaltable';
        $table->head = [
            get_string('status'),
            get_string('check'),
            get_string('summary'),
        ];
        $table->colclasses = [
            'rightalign status',
            'leftalign check',
            'leftalign summary',
        ];
        $table->data = $data;
        return $table;
    }

    /**
     * Wrap content in a collapsed <details> expander with a summary line.
     *
     * The progressive-disclosure primitive for the Overview tabs: bulk data
     * stays one click away rather than dominating the page.
     *
     * @param string $summary Plain-text summary line.
     * @param string $content Pre-rendered HTML body.
     * @return string
     */
    protected function collapsed_section(string $summary, string $content): string {
        return html_writer::tag(
            'details',
            html_writer::tag('summary', s($summary), ['class' => 'text-muted small mb-2'])
            . $content,
            ['class' => 'mb-3']
        );
    }

    /**
     * Render the Config drift tab.
     *
     * @param array $snapshot
     * @return string
     */
    public function render_config_drift_tab(array $snapshot): string {
        $drift = $snapshot['config_drift'] ?? [];
        $entries = $drift['entries'] ?? [];
        $count = (int) ($drift['count'] ?? count($entries));

        $out = $this->refresh_button('configdrift');
        $out .= $this->native_link(
            get_string('overview_drift_native', 'local_sentinel'),
            new moodle_url('/admin/search.php')
        );

        if ($count === 0) {
            return $out . html_writer::tag(
                'p',
                s(get_string('overview_no_drift', 'local_sentinel')),
                ['class' => 'text-muted']
            );
        }

        // Bucket entries: user-ignored (explicit hide), auto-ignored (matches
        // the default-rule), visible (everything else).
        $userignored = self::get_ignored_list();
        $visible = [];
        $autoignored = [];
        $manualignored = [];
        foreach ($entries as $row) {
            $fullname = (string) ($row['fullname'] ?? '');
            if ($fullname !== '' && in_array($fullname, $userignored, true)) {
                $manualignored[] = $row;
            } else if (self::drift_matches_auto_rule($row)) {
                $autoignored[] = $row;
            } else {
                $visible[] = $row;
            }
        }

        $skipped = $drift['skipped'] ?? [];
        $totalignored = count($autoignored) + count($manualignored);
        $out .= html_writer::tag(
            'p',
            count($visible) . ' visible drift entries. '
                . $totalignored . ' ignored ('
                . count($autoignored) . ' auto, ' . count($manualignored) . ' manual). '
                . 'Skipped: ' . (int) ($skipped['sensitive'] ?? 0) . ' sensitive, '
                . (int) ($skipped['no_default'] ?? 0) . ' with no declared default.',
            ['class' => 'small text-muted']
        );

        // Scoped CSS so long unbroken setting values (e.g. serialised arrays)
        // wrap inside their cells instead of forcing the whole table to scroll.
        // table-layout: fixed shares width predictably between the four
        // columns once word-break is enabled.
        $out .= '<style>
            table.sentinel-drift { table-layout: fixed; width: 100%; }
            table.sentinel-drift th, table.sentinel-drift td {
                vertical-align: top; word-break: break-word; overflow-wrap: anywhere;
            }
            table.sentinel-drift code { white-space: normal; word-break: break-all; }
            table.sentinel-drift th.col-setting, table.sentinel-drift td.col-setting { width: 38%; }
            table.sentinel-drift th.col-value, table.sentinel-drift td.col-value { width: 27%; }
            table.sentinel-drift th.col-action, table.sentinel-drift td.col-action {
                width: 8%; text-align: right;
            }
            table.sentinel-drift form { display: inline; margin: 0; }
        </style>';

        $out .= $this->drift_table($visible, 'ignore');

        if ($totalignored > 0) {
            $out .= $this->drift_ignored_section($manualignored, $autoignored);
        }

        return $out;
    }

    /**
     * Render the main / ignored drift table.
     *
     * @param array  $rows
     * @param string $action 'ignore' (rows in visible table) or 'unignore' (rows in
     *                       user-ignored section). Empty string suppresses the
     *                       action button (used for auto-ignored rows).
     * @return string
     */
    protected function drift_table(array $rows, string $action): string {
        if (empty($rows)) {
            return '';
        }

        $head = [
            get_string('overview_drift_setting', 'local_sentinel'),
            get_string('overview_drift_default', 'local_sentinel'),
            get_string('overview_drift_current', 'local_sentinel'),
        ];
        $colclasses = ['col-setting', 'col-value', 'col-value'];
        if ($action !== '') {
            $head[] = '';
            $colclasses[] = 'col-action';
        }

        $table = new \html_table();
        $table->attributes['class'] = 'admintable generaltable sentinel-drift';
        $table->head = $head;
        $table->colclasses = $colclasses;
        foreach ($rows as $row) {
            $cells = [
                $this->drift_setting_label($row),
                $this->drift_value((string) ($row['default'] ?? '')),
                $this->drift_value((string) ($row['current'] ?? '')),
            ];
            if ($action !== '') {
                $cells[] = $this->drift_action_button((string) ($row['fullname'] ?? ''), $action);
            }
            $table->data[] = $cells;
        }
        return html_writer::table($table);
    }

    /**
     * Render the collapsible "Ignored settings" section.
     *
     * @param array $manual User-ignored rows.
     * @param array $auto   Auto-ignored rows (default rule).
     * @return string
     */
    protected function drift_ignored_section(array $manual, array $auto): string {
        $total = count($manual) + count($auto);
        $summary = html_writer::tag(
            'summary',
            s(get_string('overview_drift_ignored_heading', 'local_sentinel', $total)),
            ['class' => 'h6 text-uppercase text-muted mt-4 mb-2']
        );

        $body = '';
        if (!empty($manual)) {
            $body .= html_writer::tag(
                'p',
                s(get_string('overview_drift_ignored_manual_sub', 'local_sentinel', count($manual))),
                ['class' => 'small fw-bold mt-2']
            );
            $body .= $this->drift_table($manual, 'unignore');
        }
        if (!empty($auto)) {
            $body .= html_writer::tag(
                'p',
                s(get_string('overview_drift_ignored_auto_sub', 'local_sentinel', count($auto)))
                    . ' — ' . s(get_string('overview_drift_ignored_auto_explainer', 'local_sentinel')),
                ['class' => 'small text-muted mt-2']
            );
            $body .= $this->drift_table($auto, '');
        }

        return html_writer::tag('details', $summary . $body);
    }

    /**
     * Render the per-row Ignore / Show button as a small inline form.
     *
     * @param string $fullname Setting fullname (plugin/name).
     * @param string $action   'ignore' or 'unignore'.
     * @return string
     */
    protected function drift_action_button(string $fullname, string $action): string {
        $label = $action === 'ignore'
            ? get_string('overview_drift_ignore', 'local_sentinel')
            : get_string('overview_drift_show', 'local_sentinel');
        $btnclass = $action === 'ignore' ? 'btn btn-link btn-sm p-0' : 'btn btn-link btn-sm p-0';

        $form = html_writer::start_tag('form', [
            'method' => 'post',
            'action' => (new moodle_url('/local/sentinel/overview.php', ['tab' => 'configdrift']))->out(false),
        ]);
        $form .= html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey(),
        ]);
        $form .= html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'drift_ignore_action', 'value' => $action,
        ]);
        $form .= html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'drift_ignore_fullname', 'value' => $fullname,
        ]);
        $form .= html_writer::tag('button', s($label), [
            'type' => 'submit', 'class' => $btnclass,
        ]);
        $form .= html_writer::end_tag('form');
        return $form;
    }

    /**
     * The auto-ignore rule: settings whose declared default is empty/null and
     * whose current value stringifies to '0' aren't real drift — they're
     * typically widget defaults (save buttons) or checkbox-off-vs-not-set
     * cases where the comparator can't tell `false` apart from `''`.
     *
     * @param array $row One config_drift entry.
     * @return bool
     */
    public static function drift_matches_auto_rule(array $row): bool {
        $default = (string) ($row['default'] ?? '');
        $current = (string) ($row['current'] ?? '');
        return $default === '' && $current === '0';
    }

    /**
     * Load the user-stored ignore list (array of fullnames).
     *
     * @return string[]
     */
    public static function get_ignored_list(): array {
        $raw = get_config('local_sentinel', 'drift_ignored');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /**
     * Persist a new ignore list.
     *
     * @param string[] $list
     */
    public static function set_ignored_list(array $list): void {
        $list = array_values(array_unique(array_filter($list, 'is_string')));
        set_config('drift_ignored', json_encode($list), 'local_sentinel');
    }

    /**
     * Render the setting-name cell. Visible name (the human label Moodle shows
     * in the admin UI) is the primary line, linked to the setting's section
     * page with the row anchor when we know the section. The technical
     * plugin/name identifier appears as small muted text below so admins can
     * still copy/paste it. Settings with no visible name (some
     * widget-only settings like savebutton) show the fullname as primary.
     *
     * @param array $row One config_drift entry.
     * @return string
     */
    protected function drift_setting_label(array $row): string {
        $fullname = (string) ($row['fullname'] ?? '');
        $visible = trim((string) ($row['visible_name'] ?? ''));
        $section = (string) ($row['section'] ?? '');
        $name = (string) ($row['name'] ?? '');

        $primary = $visible !== '' ? $visible : $fullname;

        if ($section !== '' && $name !== '') {
            $url = new moodle_url('/admin/settings.php', ['section' => $section], 'admin-' . $name);
            $primaryhtml = html_writer::link($url, s($primary));
        } else {
            $primaryhtml = s($primary);
        }

        $secondaryhtml = '';
        if ($visible !== '' && $visible !== $fullname) {
            $secondaryhtml = html_writer::tag('div', s($fullname), ['class' => 'small text-muted']);
        }
        return $primaryhtml . $secondaryhtml;
    }

    /**
     * Render a config-drift cell value.
     *
     * Many Moodle settings declare their default as a literal empty string —
     * roughly two-thirds of typical drift rows. Rendering empty cells as just
     * blank space made the Default column look empty/absent; we instead show
     * `""` in code style so the user reads "the value IS the empty string"
     * rather than "the value is missing from the page". Raw fidelity is
     * preserved (no `'0'` → `''` normalization).
     *
     * @param string $value
     * @return string
     */
    protected function drift_value(string $value): string {
        if ($value === '') {
            return html_writer::tag('code', '""', ['class' => 'text-muted']);
        }
        return html_writer::tag('code', s($value));
    }

    /**
     * Render a small "Refresh" link with the Moodle native reload icon.
     *
     * Posts to overview.php?tab=<tab>&refresh=1 (sesskey guarded) which purges
     * the snapshot cache and redirects back to the same tab. Used at the top
     * of every tab body so operators can force a re-collect after making
     * admin changes without waiting for the TTL.
     *
     * @param string $tab Current tab key — preserved across the refresh roundtrip.
     * @return string
     */
    public function refresh_button(string $tab): string {
        $url = new moodle_url('/local/sentinel/overview.php', [
            'tab' => $tab,
            'refresh' => 1,
            'sesskey' => sesskey(),
        ]);
        $icon = $this->output->pix_icon('i/reload', '', 'moodle', ['class' => 'me-1']);
        return html_writer::tag(
            'div',
            html_writer::link(
                $url,
                $icon . s(get_string('overview_refresh', 'local_sentinel')),
                ['class' => 'small']
            ),
            ['class' => 'mb-2 text-end']
        );
    }

    /**
     * Cross-page navigation strip for the three Sentinel admin pages.
     *
     * Rendered as Bootstrap 5 nav-pills under the heading on each page so
     * operators can jump between Overview / Settings / Connect to dashboard
     * without backing up the admin breadcrumb. The active page is shown as
     * a non-link selected pill.
     *
     * @param string $active One of 'overview', 'alerts', 'connect'.
     * @return string
     */
    public function sentinel_subnav(string $active): string {
        $items = [
            'overview' => [
                new moodle_url('/local/sentinel/overview.php'),
                get_string('overview_label', 'local_sentinel'),
            ],
            'alerts' => [
                new moodle_url('/local/sentinel/alerts.php'),
                get_string('alerts_label', 'local_sentinel'),
            ],
            'connect' => [
                new moodle_url('/local/sentinel/connect.php'),
                get_string('connect_label', 'local_sentinel'),
            ],
        ];

        $lis = '';
        foreach ($items as $key => [$url, $label]) {
            if ($key === $active) {
                $inner = html_writer::tag(
                    'span',
                    s($label),
                    ['class' => 'nav-link active', 'aria-current' => 'page']
                );
            } else {
                $inner = html_writer::link($url, s($label), ['class' => 'nav-link']);
            }
            $lis .= html_writer::tag('li', $inner, ['class' => 'nav-item']);
        }
        return html_writer::tag('ul', $lis, ['class' => 'nav nav-pills mb-3']);
    }

    /**
     * Render a small "go to native page" link, used at the top of each tab.
     *
     * @param string     $label
     * @param moodle_url $url
     * @return string
     */
    protected function native_link(string $label, moodle_url $url): string {
        return html_writer::tag(
            'div',
            html_writer::link($url, s($label) . ' →', ['class' => 'small']),
            ['class' => 'mb-2']
        );
    }

    /**
     * Inline variant — meant to sit on the same line as a section heading.
     *
     * @param string     $label
     * @param moodle_url $url
     * @return string
     */
    protected function native_inline_link(string $label, moodle_url $url): string {
        return html_writer::link($url, '(' . s($label) . ')', ['class' => 'small ms-2 fw-normal']);
    }

    /**
     * Section heading used between tab sub-blocks.
     *
     * @param string $text May contain HTML when $escape is false.
     * @param bool   $escape Escape the text. False allows embedded links.
     * @return string
     */
    protected function section_heading(string $text, bool $escape = true): string {
        return html_writer::tag(
            'h4',
            $escape ? s($text) : $text,
            ['class' => 'h6 text-uppercase text-muted mt-4']
        );
    }

    /**
     * One row of a key/value table.
     *
     * @param string     $label
     * @param string|int $value Already-escaped HTML or a scalar.
     * @return string
     */
    /**
     * Render the per-row overdue-task detail block under the count.
     *
     * Each row shows the task classname (linked to its edit page on
     * /admin/tool/task/scheduledtasks.php), the scheduled next-run time
     * the cron runner missed, and how late it is. Wrapped in a `<details>`
     * so the row stays compact when collapsed and the operator opts in
     * to seeing the list.
     *
     * @param array $tasks Per the collector: each entry has classname,
     *                     last_run, next_run, seconds_late.
     * @return string
     */
    protected function overdue_tasks_detail(array $tasks): string {
        if (empty($tasks)) {
            return '';
        }
        $rows = '';
        foreach (array_slice($tasks, 0, 25) as $task) {
            $classname = (string) ($task['classname'] ?? '');
            $editurl = new moodle_url(
                '/admin/tool/task/scheduledtasks.php',
                ['action' => 'edit', 'task' => $classname]
            );
            $lastrun = (int) ($task['last_run'] ?? 0);
            $nextrun = (int) ($task['next_run'] ?? 0);
            $lateby = (int) ($task['seconds_late'] ?? 0);
            $rows .= html_writer::tag(
                'tr',
                html_writer::tag('td', html_writer::link(
                    $editurl,
                    s($classname),
                    ['class' => 'small']
                ))
                . html_writer::tag(
                    'td',
                    $lastrun > 0
                    ? userdate($lastrun, '%Y-%m-%d %H:%M') : '<span class="text-muted">never</span>',
                    ['class' => 'small text-muted']
                )
                . html_writer::tag(
                    'td',
                    $nextrun > 0
                    ? userdate($nextrun, '%Y-%m-%d %H:%M') : '—',
                    ['class' => 'small text-muted']
                )
                . html_writer::tag(
                    'td',
                    format_time($lateby) . ' late',
                    ['class' => 'small text-danger']
                )
            );
        }
        $extra = count($tasks) > 25
            ? html_writer::tag(
                'div',
                '… and ' . (count($tasks) - 25) . ' more.',
                ['class' => 'small-meta text-muted']
            )
            : '';
        $table = html_writer::tag(
            'table',
            html_writer::tag(
                'thead',
                html_writer::tag(
                    'tr',
                    html_writer::tag('th', 'Task', ['class' => 'small'])
                    . html_writer::tag('th', 'Last run', ['class' => 'small'])
                    . html_writer::tag('th', 'Scheduled for', ['class' => 'small'])
                    . html_writer::tag('th', 'Late by', ['class' => 'small'])
                )
            )
            . html_writer::tag('tbody', $rows),
            ['class' => 'table table-sm mt-2 mb-0']
        );
        $summary = html_writer::tag(
            'summary',
            'Show overdue tasks',
            ['class' => 'small ms-2 text-muted', 'style' => 'cursor: pointer']
        );
        return html_writer::tag(
            'details',
            $summary . $table . $extra,
            ['class' => 'd-inline-block']
        );
    }

    /**
     * Render one row of a key/value table.
     *
     * @param string     $label Plain text; will be htmlspecialchars-escaped.
     * @param string|int $value Already-escaped HTML or a scalar.
     * @return string
     */
    protected function kv_row(string $label, $value): string {
        return html_writer::tag(
            'tr',
            html_writer::tag('th', s($label), ['scope' => 'row', 'style' => 'width:30%'])
            . html_writer::tag('td', (string) $value)
        );
    }

    /**
     * Wrap a series of kv rows in a Bootstrap table.
     *
     * @param string $rows Concatenated <tr> HTML.
     * @return string
     */
    protected function kv_table(string $rows): string {
        return html_writer::tag(
            'table',
            html_writer::tag('tbody', $rows),
            ['class' => 'table table-sm']
        );
    }
}
