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
 * Action-derivation tests for the Overview page.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

/**
 * Tests for {@see actions}.
 *
 * @covers \local_sentinel\actions
 */
final class actions_test extends \advanced_testcase {
    /**
     * A snapshot with nothing wrong — every rule's healthy baseline.
     *
     * @return array
     */
    protected function healthy_snapshot(): array {
        return [
            'status' => [
                'release' => '4.5.11+',
                'branch_eol_days_remaining' => 500,
                'core_update' => ['update_available' => false],
            ],
            'environment' => [
                'ssl' => ['checked' => true, 'days_remaining' => 60],
                'os' => ['package_updates' => ['security' => 0, 'reboot_required' => false]],
            ],
            'plugins' => ['updates_available' => []],
            'health' => [
                'cron' => ['seconds_since_last_run' => 120, 'expected_frequency_seconds' => 60],
                'tasks' => ['scheduled_failed_count' => 0, 'scheduled_overdue_count' => 0],
                'cache_stores' => ['not_ready_count' => 0],
                'disk' => ['dataroot' => ['free_bytes' => 50 * 1024 ** 3, 'total_bytes' => 100 * 1024 ** 3]],
                'backup' => ['automated_state' => 1, 'status_counts' => ['error' => 0]],
                'flags' => ['debug' => 0],
            ],
            'auth' => [
                'tokens' => ['without_ip_restriction' => 0, 'expiring_within_30_days' => 0],
                'failed_logins' => ['locked_accounts' => 0],
            ],
            'reports' => [
                'performance' => ['counts_by_status' => ['error' => 0]],
                'security' => ['counts_by_status' => ['error' => 0]],
                'system_status' => ['counts_by_status' => ['critical' => 0, 'error' => 0]],
            ],
        ];
    }

    /**
     * Collect just the message strings for easy assertion.
     *
     * @param array $items
     * @return string[]
     */
    protected function messages(array $items): array {
        return array_map(fn($i) => $i['message'], $items);
    }

    public function test_healthy_snapshot_yields_no_actions(): void {
        $this->assertSame([], actions::from_snapshot($this->healthy_snapshot()));
    }

    public function test_empty_snapshot_yields_no_actions(): void {
        // Missing slices (egress / old plugin) must not fabricate actions.
        $this->assertSame([], actions::from_snapshot([]));
    }

    public function test_critical_checks_are_danger_and_first(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot['reports']['system_status']['counts_by_status']['critical'] = 2;
        $snapshot['plugins']['updates_available'] = [['component' => 'mod_x']];

        $items = actions::from_snapshot($snapshot);

        $this->assertSame('danger', $items[0]['severity']);
        $this->assertStringContainsString('2 critical', $items[0]['message']);
        // Warning (plugin updates) sorts after danger.
        $this->assertSame('warning', end($items)['severity']);
    }

    public function test_cron_stall_threshold(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot['health']['cron']['seconds_since_last_run'] = HOURSECS; // Under 2h: fine.
        $this->assertSame([], actions::from_snapshot($snapshot));

        $snapshot['health']['cron']['seconds_since_last_run'] = 3 * HOURSECS;
        $items = actions::from_snapshot($snapshot);
        $this->assertCount(1, $items);
        $this->assertSame('danger', $items[0]['severity']);
        $this->assertStringContainsString('Cron', $items[0]['message']);
    }

    public function test_cert_expiry_tiers(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot['environment']['ssl']['days_remaining'] = 10;
        $items = actions::from_snapshot($snapshot);
        $this->assertSame('warning', $items[0]['severity']);
        $this->assertStringContainsString('10 day', $items[0]['message']);

        $snapshot['environment']['ssl']['days_remaining'] = -1;
        $items = actions::from_snapshot($snapshot);
        $this->assertSame('danger', $items[0]['severity']);
        $this->assertStringContainsString('expired', $items[0]['message']);
    }

    public function test_unchecked_cert_is_ignored(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot['environment']['ssl'] = ['checked' => false, 'reason' => 'not https'];
        $this->assertSame([], actions::from_snapshot($snapshot));
    }

    public function test_disk_tiers(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot['health']['disk']['dataroot'] = ['free_bytes' => 8 * 1024 ** 3, 'total_bytes' => 100 * 1024 ** 3];
        $items = actions::from_snapshot($snapshot);
        $this->assertSame('warning', $items[0]['severity']);

        $snapshot['health']['disk']['dataroot']['free_bytes'] = 2 * 1024 ** 3;
        $items = actions::from_snapshot($snapshot);
        $this->assertSame('danger', $items[0]['severity']);
    }

    public function test_branch_eol_tiers(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot['status']['branch_eol_days_remaining'] = 60;
        $items = actions::from_snapshot($snapshot);
        $this->assertSame('warning', $items[0]['severity']);
        $this->assertStringContainsString('60 day', $items[0]['message']);

        $snapshot['status']['branch_eol_days_remaining'] = -10;
        $items = actions::from_snapshot($snapshot);
        $this->assertSame('danger', $items[0]['severity']);
    }

    public function test_backup_errors_require_automation_on(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot['health']['backup'] = ['automated_state' => 0, 'status_counts' => ['error' => 5]];
        $this->assertSame([], actions::from_snapshot($snapshot));

        $snapshot['health']['backup']['automated_state'] = 1;
        $items = actions::from_snapshot($snapshot);
        $this->assertStringContainsString('5 course backup', $items[0]['message']);
    }

    public function test_info_tier_items(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot['auth']['tokens']['without_ip_restriction'] = 3;
        $snapshot['health']['flags']['debug'] = 32767;
        $snapshot['environment']['os']['package_updates'] = ['security' => 12, 'reboot_required' => true];

        $items = actions::from_snapshot($snapshot);

        $this->assertCount(4, $items);
        foreach ($items as $item) {
            $this->assertSame('info', $item['severity']);
        }
        $messages = implode(' | ', $this->messages($items));
        $this->assertStringContainsString('IP restriction', $messages);
        $this->assertStringContainsString('debugging', $messages);
        $this->assertStringContainsString('12 OS security update', $messages);
        $this->assertStringContainsString('reboot', $messages);
    }

    public function test_severity_ordering_danger_warning_info(): void {
        $snapshot = $this->healthy_snapshot();
        $snapshot['health']['flags']['debug'] = 1;                                    // Info.
        $snapshot['status']['core_update'] = [
            'update_available' => true,
            'latest_on_branch' => ['release' => '4.5.12+'],
        ];                                                                            // Warning.
        $snapshot['health']['cache_stores']['not_ready_count'] = 1;                   // Danger.

        $severities = array_map(fn($i) => $i['severity'], actions::from_snapshot($snapshot));
        $this->assertSame(['danger', 'warning', 'info'], $severities);
    }
}
