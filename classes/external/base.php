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
 * Shared base for Sentinel external functions.
 *
 * @package    local_sentinel
 * @copyright  2026 David Pesce - Exputo Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Base class plus structure-definition helpers shared by every external function.
 *
 * Each concrete external function calls envelope_with_slices() in
 * execute_returns() to declare its return type. The same registry maps slice
 * names to structure builders so the wire contract lives in one place.
 */
abstract class base extends external_api {
    /**
     * Default: no parameters. Subclasses that need params override this.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Validates the system context and requires the Sentinel capability.
     */
    protected static function authorise(): void {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/sentinel:view', $context);
    }

    /**
     * Build the envelope structure declaration for a set of slice names.
     *
     * @param string[] $slicekeys
     * @return external_single_structure
     */
    protected static function envelope_with_slices(array $slicekeys): external_single_structure {
        $structure = [
            'schema_version' => new external_value(PARAM_INT, 'Snapshot schema version.'),
            'generated_at' => new external_value(PARAM_TEXT, 'ISO 8601 UTC timestamp.'),
            'plugin' => new external_single_structure([
                'component' => new external_value(PARAM_RAW, 'Plugin frankenstyle.'),
                'version' => self::nullable_int('local_sentinel version int.'),
                'release' => self::nullable_text('local_sentinel release string.'),
            ], 'local_sentinel self-identification.'),
            'site' => self::site_structure(),
        ];
        $builders = [
            'status' => 'status_structure',
            'environment' => 'environment_structure',
            'plugins' => 'plugins_structure',
            'health' => 'health_structure',
            'auth' => 'auth_structure',
            'reports' => 'reports_structure',
            'config_changes' => 'config_changes_structure',
            'config_drift' => 'config_drift_structure',
        ];
        foreach ($slicekeys as $key) {
            $method = $builders[$key];
            // Slices are VALUE_OPTIONAL: admins may exclude any slice from
            // egress via the Settings page. Older clients that hard-required
            // a slice get null back; clients using ->get('key') style reads
            // simply see the absent key. Defensive on the dashboard side.
            $structure[$key] = new external_single_structure(
                self::$method()->keys,
                self::$method()->desc,
                VALUE_OPTIONAL
            );
        }
        return new external_single_structure($structure);
    }

    /**
     * Nullable text value (PARAM_RAW + NULL_ALLOWED).
     *
     * @param string $desc
     * @return external_value
     */
    protected static function nullable_text(string $desc): external_value {
        return new external_value(PARAM_RAW, $desc, VALUE_REQUIRED, null, NULL_ALLOWED);
    }

    /**
     * Nullable integer value (PARAM_INT + NULL_ALLOWED).
     *
     * @param string $desc
     * @return external_value
     */
    protected static function nullable_int(string $desc): external_value {
        return new external_value(PARAM_INT, $desc, VALUE_REQUIRED, null, NULL_ALLOWED);
    }

    /**
     * Optional + nullable integer value (for fields that may be missing AND null).
     *
     * @param string $desc
     * @return external_value
     */
    protected static function nullable_int_optional(string $desc): external_value {
        return new external_value(PARAM_INT, $desc, VALUE_OPTIONAL, null, NULL_ALLOWED);
    }

    // Slice structure builders below. Each must stay aligned with the
    // matching collector class output in \local_sentinel\collectors\.

    /**
     * Stable site identifiers.
     *
     * @return external_single_structure
     */
    protected static function site_structure(): external_single_structure {
        return new external_single_structure([
            'wwwroot' => new external_value(PARAM_URL, 'Configured wwwroot.'),
            'siteidentifier' => new external_value(PARAM_TEXT, 'Stable site identifier.'),
            'sitename' => new external_value(PARAM_RAW, 'Site full name.'),
            'shortname' => new external_value(PARAM_RAW, 'Site short name.'),
        ], 'Site identity (stable across domain migrations).');
    }

    /**
     * Status slice: version, branch, maintenance, EOL data.
     *
     * @return external_single_structure
     */
    protected static function status_structure(): external_single_structure {
        return new external_single_structure([
            'version' => new external_value(PARAM_INT, 'Moodle version integer.'),
            'branch' => new external_value(PARAM_INT, 'Moodle branch (e.g. 405).'),
            'release' => new external_value(PARAM_RAW, 'Release string.'),
            'maintenance_enabled' => new external_value(PARAM_BOOL, 'Maintenance mode flag.'),
            'maintenance_message' => new external_value(PARAM_RAW, 'Maintenance banner text.'),
            'branch_eol_date' => self::nullable_text('Branch security EOL date (ISO 8601).'),
            'branch_eol_days_remaining' => self::nullable_int('Days until branch security EOL.'),
            'build_age_days' => self::nullable_int('Days since this build date.'),
            'core_update' => new external_single_structure([
                'update_available' => new external_value(
                    PARAM_BOOL,
                    'True if a newer Moodle release exists on the current branch.'
                ),
                'latest_on_branch' => new external_single_structure([
                    'branch' => new external_value(PARAM_INT, 'Branch number.'),
                    'version' => new external_value(PARAM_FLOAT, 'Available version (float, preserves sub-build).'),
                    'release' => new external_value(PARAM_RAW, 'Release string.'),
                    'maturity' => self::nullable_int('Release maturity (MATURITY_* constant).'),
                    'download' => self::nullable_text('Download URL.'),
                ], 'Newest release on the current branch; null if up to date.', VALUE_REQUIRED, null, NULL_ALLOWED),
                'newer_branches' => new external_multiple_structure(
                    new external_single_structure([
                        'branch' => new external_value(PARAM_INT, 'Branch number.'),
                        'version' => new external_value(PARAM_FLOAT, 'Latest version on that branch.'),
                        'release' => new external_value(PARAM_RAW, 'Release string.'),
                        'maturity' => self::nullable_int('Release maturity.'),
                        'download' => self::nullable_text('Download URL.'),
                    ]),
                    'Latest release per newer branch (one entry per branch).'
                ),
            ]),
        ]);
    }

    /**
     * Environment slice: PHP, OS, web server, database, OPcache, extensions, SSL.
     *
     * @return external_single_structure
     */
    protected static function environment_structure(): external_single_structure {
        return new external_single_structure([
            'php' => new external_single_structure([
                'version' => new external_value(PARAM_RAW, 'PHP version.'),
                'sapi' => new external_value(PARAM_RAW, 'PHP SAPI.'),
                'memory_limit' => new external_value(PARAM_RAW, 'memory_limit ini value.'),
                'max_execution_time' => new external_value(PARAM_INT, 'max_execution_time ini value.'),
                'upload_max_filesize' => new external_value(PARAM_RAW, 'upload_max_filesize ini value.'),
                'post_max_size' => new external_value(PARAM_RAW, 'post_max_size ini value.'),
                'timezone' => new external_value(PARAM_RAW, 'Default timezone.'),
            ]),
            'os' => new external_single_structure([
                'sysname' => new external_value(PARAM_RAW, 'OS sysname.'),
                'release' => new external_value(PARAM_RAW, 'OS release.'),
                'version' => new external_value(PARAM_RAW, 'OS version.'),
                'machine' => new external_value(PARAM_RAW, 'CPU architecture.'),
                'hostname' => new external_value(PARAM_RAW, 'Hostname.', VALUE_OPTIONAL),
            ]),
            'webserver' => new external_single_structure([
                'software' => new external_value(PARAM_RAW, 'SERVER_SOFTWARE value (empty under CLI).'),
                'sapi_is_fpm' => new external_value(PARAM_BOOL, 'Running under PHP-FPM.'),
                'sapi_is_apache' => new external_value(PARAM_BOOL, 'Running under Apache module SAPI.'),
                'sapi_is_cli' => new external_value(PARAM_BOOL, 'Running under CLI SAPI.'),
            ]),
            'database' => new external_single_structure([
                'type' => new external_value(PARAM_RAW, 'DB driver type.'),
                'version' => new external_value(PARAM_RAW, 'DB server version.'),
                'description' => new external_value(PARAM_RAW, 'DB server description.'),
                'host' => new external_value(PARAM_RAW, 'DB host.', VALUE_OPTIONAL),
                'name' => new external_value(PARAM_RAW, 'DB name.'),
                'prefix' => new external_value(PARAM_RAW, 'Table prefix.'),
                'size_bytes' => self::nullable_int('Total DB size in bytes (null if unsupported).'),
                'largest_tables' => new external_multiple_structure(
                    new external_single_structure([
                        'name' => new external_value(PARAM_RAW, 'Table name.'),
                        'size_bytes' => new external_value(PARAM_INT, 'Table size in bytes.'),
                    ])
                ),
            ]),
            'opcache' => new external_single_structure([
                'enabled' => new external_value(PARAM_BOOL, 'OPcache enabled.'),
                'reason' => new external_value(PARAM_RAW, 'Reason when disabled.', VALUE_OPTIONAL),
                'used_memory' => new external_value(PARAM_INT, 'Used memory bytes.', VALUE_OPTIONAL),
                'free_memory' => new external_value(PARAM_INT, 'Free memory bytes.', VALUE_OPTIONAL),
                'wasted_memory' => new external_value(PARAM_INT, 'Wasted memory bytes.', VALUE_OPTIONAL),
                'num_cached_scripts' => new external_value(PARAM_INT, 'Cached script count.', VALUE_OPTIONAL),
                'hits' => new external_value(PARAM_INT, 'OPcache hits.', VALUE_OPTIONAL),
                'misses' => new external_value(PARAM_INT, 'OPcache misses.', VALUE_OPTIONAL),
                'hit_rate' => new external_value(PARAM_FLOAT, 'Hit rate percentage.', VALUE_OPTIONAL),
            ]),
            'extensions' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Loaded PHP extension name.')
            ),
            'ssl' => new external_single_structure([
                'checked' => new external_value(PARAM_BOOL, 'Whether the SSL probe ran successfully.'),
                'reason' => new external_value(PARAM_RAW, 'Reason when not checked.', VALUE_OPTIONAL),
                'host' => new external_value(PARAM_RAW, 'Probed hostname.', VALUE_OPTIONAL),
                'issuer' => new external_value(PARAM_RAW, 'Cert issuer.', VALUE_OPTIONAL),
                'subject_cn' => new external_value(PARAM_RAW, 'Cert subject CN.', VALUE_OPTIONAL),
                'valid_from' => new external_value(PARAM_INT, 'Cert validFrom timestamp.', VALUE_OPTIONAL),
                'valid_to' => new external_value(PARAM_INT, 'Cert validTo timestamp.', VALUE_OPTIONAL),
                'days_remaining' => self::nullable_int_optional('Days until cert expiry.'),
            ]),
        ]);
    }

    /**
     * Plugins slice: standard, third-party, updates, update_check, theme.
     *
     * @return external_single_structure
     */
    protected static function plugins_structure(): external_single_structure {
        $pluginentry = new external_single_structure([
            'type' => new external_value(PARAM_RAW, 'Plugin type (mod, local, tool, etc.).'),
            'component' => new external_value(PARAM_RAW, 'Frankenstyle component name.'),
            'name' => new external_value(PARAM_RAW, 'Display name.'),
            'version_disk' => self::nullable_int('Version declared in version.php on disk.'),
            'version_db' => self::nullable_int('Version recorded in mdl_config_plugins.'),
            'release' => self::nullable_text('Human release string.'),
            'source' => self::nullable_text('Plugin source (std / ext).'),
            'status' => new external_value(
                PARAM_RAW,
                'Install consistency: uptodate / missing / new / upgrade / downgrade. '
                . 'Refers to versiondisk vs versiondb, NOT freshness vs upstream.'
            ),
            'missing_from_disk' => new external_value(PARAM_BOOL, 'True when status is missing.'),
            'update_available' => new external_value(
                PARAM_BOOL,
                'True if moodle.org/updates has a newer version than version_disk. '
                . 'Independent of the install-consistency status field.'
            ),
            'version_latest' => self::nullable_int('Latest version available upstream (null if no update).'),
            'enabled' => new external_value(PARAM_BOOL, 'Whether the plugin is enabled.', VALUE_REQUIRED, null, NULL_ALLOWED),
        ]);

        return new external_single_structure([
            'standard' => new external_multiple_structure($pluginentry),
            'third_party' => new external_multiple_structure($pluginentry),
            'updates_available' => new external_multiple_structure(
                new external_single_structure([
                    'component' => new external_value(PARAM_RAW, 'Component name.'),
                    'version' => self::nullable_int('Available version int.'),
                    'release' => self::nullable_text('Available release string.'),
                    'maturity' => self::nullable_int('Available maturity (MATURITY_* constant).'),
                    'download' => self::nullable_text('Direct download URL.'),
                    'source_url' => self::nullable_text('Upstream source URL.'),
                ])
            ),
            'update_check' => new external_single_structure([
                'enabled' => new external_value(PARAM_BOOL, 'Update checker globally enabled.'),
                'last_fetched' => self::nullable_int('Unix timestamp of last fetch.'),
                'age_seconds' => self::nullable_int('Seconds since last fetch.'),
            ]),
            'theme' => new external_single_structure([
                'name' => new external_value(PARAM_RAW, 'Theme component name.'),
                'version' => self::nullable_int('Theme version int.'),
                'release' => self::nullable_text('Theme release.'),
                'source' => self::nullable_text('Theme source.'),
            ]),
        ]);
    }

    /**
     * Health slice: cron, tasks, sessions, disk, mail, admins, backup, flags.
     *
     * @return external_single_structure
     */
    protected static function health_structure(): external_single_structure {
        $diskpart = new external_single_structure([
            'path' => new external_value(PARAM_RAW, 'Filesystem path.'),
            'free_bytes' => self::nullable_int('Free bytes on this filesystem.'),
            'total_bytes' => self::nullable_int('Total bytes on this filesystem.'),
        ]);

        return new external_single_structure([
            'cron' => new external_single_structure([
                'last_run' => new external_value(PARAM_INT, 'Unix timestamp of last cron run (0 = never).'),
                'seconds_since_last_run' => self::nullable_int('Seconds since last cron run.'),
                'now' => new external_value(PARAM_INT, 'Current server time.'),
            ]),
            'tasks' => new external_single_structure([
                'scheduled_failed_count' => new external_value(PARAM_INT, 'Count of failing scheduled tasks.'),
                'scheduled_failed' => new external_multiple_structure(
                    new external_single_structure([
                        'classname' => new external_value(PARAM_RAW, 'Task class name.'),
                        'last_run' => new external_value(PARAM_INT, 'Last run timestamp.'),
                        'faildelay' => new external_value(PARAM_INT, 'Current retry delay.'),
                        'disabled' => new external_value(PARAM_BOOL, 'Task disabled flag.'),
                    ])
                ),
                'scheduled_overdue_count' => new external_value(
                    PARAM_INT,
                    'Enabled scheduled tasks whose nextruntime is >1h in the past with no active retry.'
                ),
                'scheduled_overdue' => new external_multiple_structure(
                    new external_single_structure([
                        'classname' => new external_value(PARAM_RAW, 'Task class name.'),
                        'last_run' => new external_value(PARAM_INT, 'Last successful run timestamp.'),
                        'next_run' => new external_value(PARAM_INT, 'Scheduled next run timestamp (in the past).'),
                        'seconds_late' => new external_value(PARAM_INT, 'Now minus next_run, in seconds.'),
                    ])
                ),
                'adhoc_queue_depth' => new external_value(PARAM_INT, 'Number of queued adhoc tasks.'),
                'adhoc_oldest_nextruntime' => self::nullable_int('Oldest nextruntime in adhoc queue.'),
            ]),
            'sessions' => new external_single_structure([
                'active_last_5_min' => new external_value(PARAM_INT, 'Sessions touched in last 5 min.'),
                'active_last_hour' => new external_value(PARAM_INT, 'Sessions touched in last hour.'),
                'total_rows' => new external_value(PARAM_INT, 'Total rows in mdl_sessions.'),
            ]),
            'active_users' => new external_single_structure([
                'dau' => new external_value(PARAM_INT, 'Distinct users with lastaccess in the last 24h.'),
                'wau' => new external_value(PARAM_INT, 'Distinct users with lastaccess in the last 7 days.'),
                'mau' => new external_value(PARAM_INT, 'Distinct users with lastaccess in the last 30 days.'),
            ]),
            'disk' => new external_single_structure([
                'dataroot' => $diskpart,
                'dirroot' => $diskpart,
            ]),
            'mail' => new external_single_structure([
                'smtphosts' => new external_value(PARAM_RAW, 'SMTP host list.'),
                'smtpsecure' => new external_value(PARAM_RAW, 'SMTP security setting.'),
                'noreplyaddress' => new external_value(PARAM_RAW, 'Noreply address.'),
                'supportemail' => new external_value(PARAM_RAW, 'Support email.'),
            ]),
            'admins' => new external_single_structure([
                'count' => new external_value(PARAM_INT, 'Number of site admins.'),
                'admins' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'User ID.'),
                        'username' => new external_value(PARAM_RAW, 'Username.'),
                        'last_access' => new external_value(PARAM_INT, 'Last access timestamp.'),
                        'last_login' => new external_value(PARAM_INT, 'Last login timestamp.'),
                        'suspended' => new external_value(PARAM_BOOL, 'Suspended flag.'),
                    ])
                ),
                'last_changed' => new external_single_structure([
                    'time' => self::nullable_int('When the siteadmins list last changed.'),
                    'userid' => self::nullable_int('Who made the change.'),
                    'oldvalue' => self::nullable_text('Previous siteadmins value (comma-separated user IDs).'),
                    'newvalue' => self::nullable_text('Current siteadmins value (comma-separated user IDs).'),
                ]),
            ]),
            'backup' => new external_single_structure([
                'automated_state' => new external_value(PARAM_INT, 'backup_auto_active setting.'),
                'status_counts' => new external_single_structure([
                    'error' => new external_value(PARAM_INT, 'Courses with last status = error.'),
                    'ok' => new external_value(PARAM_INT, 'Courses with last status = ok.'),
                    'unfinished' => new external_value(PARAM_INT, 'Courses with last status = unfinished.'),
                    'skipped' => new external_value(PARAM_INT, 'Courses with last status = skipped.'),
                    'warning' => new external_value(PARAM_INT, 'Courses with last status = warning.'),
                    'notyetrun' => new external_value(PARAM_INT, 'Courses with last status = notyetrun.'),
                    'queued' => new external_value(PARAM_INT, 'Courses with last status = queued.'),
                ]),
                'last_success' => self::nullable_int('Last successful automated backup timestamp.'),
                'total_courses_tracked' => new external_value(PARAM_INT, 'Total rows in mdl_backup_courses.'),
            ]),
            'upgrade_log' => new external_single_structure([
                'total_errors' => new external_value(PARAM_INT, 'Lifetime count of upgrade_log rows with type=error.'),
                'recent' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'upgrade_log row id.'),
                        'time' => new external_value(PARAM_INT, 'Modification timestamp.'),
                        'type' => new external_value(PARAM_INT, 'Severity: 0=normal, 1=notice, 2=error.'),
                        'type_label' => new external_value(PARAM_RAW, 'Severity label.'),
                        'plugin' => self::nullable_text('Plugin component.'),
                        'version' => self::nullable_text('Recorded version.'),
                        'targetversion' => self::nullable_text('Target version.'),
                        'info' => new external_value(PARAM_RAW, 'Log message.'),
                        'userid' => new external_value(PARAM_INT, 'User who triggered the upgrade step.'),
                    ])
                ),
            ]),
            'flags' => new external_single_structure([
                'debug' => self::nullable_int('$CFG->debug level.'),
                'debugdisplay' => new external_value(PARAM_BOOL, '$CFG->debugdisplay flag.'),
                'themedesignermode' => new external_value(PARAM_BOOL, 'Theme designer mode flag.'),
                'cachejs_disabled' => new external_value(PARAM_BOOL, 'JS caching disabled.'),
                'perfdebug' => self::nullable_int('$CFG->perfdebug level.'),
            ]),
            // MUC backend reachability. VALUE_OPTIONAL — older sites without the
            // collector don't include this key. Each store reports its is_ready()
            // signal plus warning strings — the same data /cache/admin.php shows.
            'cache_stores' => new external_single_structure([
                'available' => new external_value(PARAM_BOOL, 'True if the collector could enumerate stores.'),
                'reason' => new external_value(PARAM_RAW, 'Failure reason when available=false.', VALUE_OPTIONAL),
                'total_count' => new external_value(PARAM_INT, 'Number of configured stores.', VALUE_OPTIONAL),
                'not_ready_count' => new external_value(PARAM_INT, 'Stores reporting is_ready=false.', VALUE_OPTIONAL),
                'stores' => new external_multiple_structure(
                    new external_single_structure([
                        'name' => new external_value(PARAM_RAW, 'Store instance name.'),
                        'plugin' => new external_value(PARAM_RAW, 'cachestore_* plugin name.'),
                        'is_default' => new external_value(PARAM_BOOL, 'Default store for its mode.'),
                        'is_ready' => new external_value(PARAM_BOOL, 'Store is reachable / ready to use.'),
                        'requirements_met' => new external_value(PARAM_BOOL, 'PHP requirements satisfied.'),
                        'mappings' => new external_value(PARAM_INT, 'Definitions mapped to this store.'),
                        'warnings' => new external_multiple_structure(
                            new external_value(PARAM_RAW, 'Warning string.')
                        ),
                        'supports_application_mode' => new external_value(PARAM_BOOL, 'Usable for MODE_APPLICATION.'),
                        'supports_session_mode' => new external_value(PARAM_BOOL, 'Usable for MODE_SESSION.'),
                        'supports_request_mode' => new external_value(PARAM_BOOL, 'Usable for MODE_REQUEST.'),
                    ]),
                    'Per-store summary.',
                    VALUE_OPTIONAL
                ),
            ], 'MUC backend reachability summary (admin opt-out via egress filter).', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Auth slice: enabled methods + user counts per method.
     *
     * @return external_single_structure
     */
    protected static function auth_structure(): external_single_structure {
        return new external_single_structure([
            'enabled' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Enabled auth plugin name.')
            ),
            'methods' => new external_multiple_structure(
                new external_single_structure([
                    'plugin' => new external_value(PARAM_RAW, 'Auth plugin name.'),
                    'total_users' => new external_value(PARAM_INT, 'Total non-deleted users.'),
                    'active_users' => new external_value(PARAM_INT, 'Non-deleted, non-suspended users.'),
                ])
            ),
            'distinct_methods_in_use' => new external_value(PARAM_INT, 'Distinct auth values across all users.'),
            'failed_logins' => new external_single_structure([
                'total_failed_count' => new external_value(
                    PARAM_INT,
                    'Sum of login_failed_count_since_success across all users.'
                ),
                'accounts_with_failures' => new external_value(PARAM_INT, 'Distinct accounts with failures since last success.'),
                'locked_accounts' => new external_value(PARAM_INT, 'Accounts currently locked (login_lockout set).'),
                'top_accounts' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'User ID.'),
                        'username' => new external_value(PARAM_RAW, 'Username.'),
                        'failed_count' => new external_value(PARAM_INT, 'Failures since last successful login.'),
                        'last_failure' => self::nullable_int('Most recent failure timestamp.'),
                        'last_login' => new external_value(PARAM_INT, 'Last successful login timestamp.'),
                        'locked' => new external_value(PARAM_BOOL, 'True if the account is currently locked.'),
                        'suspended' => new external_value(PARAM_BOOL, 'Suspended flag.'),
                    ]),
                    'Per-user details for top failed-login accounts (admin opt-out).',
                    VALUE_OPTIONAL
                ),
            ]),
            'tokens' => new external_single_structure([
                'total_count' => new external_value(PARAM_INT, 'Total active web service tokens.'),
                'without_ip_restriction' => new external_value(
                    PARAM_INT,
                    'Tokens with no iprestriction set (broader attack surface).'
                ),
                'never_used' => new external_value(PARAM_INT, 'Tokens whose lastaccess is 0.'),
                'active_last_7_days' => new external_value(PARAM_INT, 'Tokens used in the last 7 days.'),
                'stale_over_90_days' => new external_value(PARAM_INT, 'Tokens with lastaccess older than 90 days.'),
                'expiring_within_30_days' => new external_value(
                    PARAM_INT,
                    'Tokens with non-zero validuntil that falls in the next 30 days.'
                ),
                'entries' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Token row ID (NOT the token string).'),
                        'type' => new external_value(PARAM_RAW, 'permanent / embedded.'),
                        'user' => new external_value(PARAM_RAW, 'Owning username (or (deleted) if user gone).'),
                        'user_deleted' => new external_value(PARAM_BOOL, 'Whether the owning user is deleted.'),
                        'service_shortname' => self::nullable_text('External service shortname.'),
                        'service_name' => self::nullable_text('External service display name.'),
                        'has_ip_restriction' => new external_value(PARAM_BOOL, 'Whether iprestriction is set.'),
                        'ip_restriction' => new external_value(PARAM_RAW, 'IP restriction value (empty when not set).'),
                        'created' => new external_value(PARAM_INT, 'Token creation timestamp.'),
                        'last_access' => new external_value(PARAM_INT, 'Token last-use timestamp (0 if never used).'),
                        'valid_until' => new external_value(PARAM_INT, 'Expiry timestamp (0 = never expires).'),
                    ]),
                    'Per-token detail rows (admin opt-out).',
                    VALUE_OPTIONAL
                ),
            ]),
        ]);
    }

    /**
     * Reports slice: performance / security / system_status checks + MFA stats.
     *
     * @return external_single_structure
     */
    protected static function reports_structure(): external_single_structure {
        $checkresult = new external_single_structure([
            'ref' => new external_value(PARAM_RAW, 'Unique check reference (component:id).'),
            'component' => new external_value(PARAM_RAW, 'Component the check belongs to.'),
            'name' => new external_value(PARAM_RAW, 'Display name.'),
            'status' => new external_value(PARAM_RAW, 'Result status: na/ok/info/unknown/warning/error/critical.'),
            'summary' => new external_value(PARAM_RAW, 'Plaintext summary of the result.'),
        ]);
        $checksection = new external_single_structure([
            'total' => new external_value(PARAM_INT, 'Total checks in this category.'),
            'counts_by_status' => new external_single_structure([
                'na' => new external_value(PARAM_INT, 'Not applicable.'),
                'ok' => new external_value(PARAM_INT, 'OK.'),
                'info' => new external_value(PARAM_INT, 'Informational.'),
                'unknown' => new external_value(PARAM_INT, 'Unknown / not yet run.'),
                'warning' => new external_value(PARAM_INT, 'Warning.'),
                'error' => new external_value(PARAM_INT, 'Error.'),
                'critical' => new external_value(PARAM_INT, 'Critical.'),
            ]),
            'checks' => new external_multiple_structure($checkresult),
        ]);

        return new external_single_structure([
            'performance' => $checksection,
            'security' => $checksection,
            'system_status' => $checksection,
            'mfa' => new external_single_structure([
                'installed' => new external_value(PARAM_BOOL, 'Whether tool_mfa is installed.'),
                'enabled' => new external_value(PARAM_BOOL, 'tool_mfa global enable flag.', VALUE_OPTIONAL),
                'users_with_factor' => new external_value(
                    PARAM_INT,
                    'Distinct users with at least one active MFA factor.',
                    VALUE_OPTIONAL
                ),
                'locked_users' => new external_value(PARAM_INT, 'Users currently locked out by MFA.', VALUE_OPTIONAL),
                'by_factor' => new external_multiple_structure(
                    new external_single_structure([
                        'factor' => new external_value(PARAM_RAW, 'Factor plugin name.'),
                        'active_users' => new external_value(PARAM_INT, 'Distinct users with this factor enrolled.'),
                    ]),
                    'Per-factor active user counts.',
                    VALUE_OPTIONAL
                ),
            ]),
        ]);
    }

    /**
     * Config changes slice: tail of mdl_config_log.
     *
     * @return external_single_structure
     */
    protected static function config_changes_structure(): external_single_structure {
        return new external_single_structure([
            'limit' => new external_value(PARAM_INT, 'Row limit applied.'),
            'count' => new external_value(PARAM_INT, 'Rows returned.'),
            'entries' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'config_log row id.'),
                    'time' => new external_value(PARAM_INT, 'Modification timestamp.'),
                    'userid' => new external_value(PARAM_INT, 'User ID who made the change.'),
                    'username' => self::nullable_text('Username (null for system user).'),
                    'fullname' => new external_value(PARAM_RAW, 'User full name.'),
                    'plugin' => self::nullable_text('Plugin component (null for core).'),
                    'name' => new external_value(PARAM_RAW, 'Setting name.'),
                    'oldvalue' => self::nullable_text('Previous value.'),
                    'newvalue' => self::nullable_text('New value.'),
                ])
            ),
        ]);
    }

    /**
     * Config drift slice: settings differing from defaults.
     *
     * @return external_single_structure
     */
    protected static function config_drift_structure(): external_single_structure {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Number of drift entries.'),
            'skipped' => new external_single_structure([
                'sensitive' => new external_value(PARAM_INT, 'Sensitive settings excluded.'),
                'no_default' => new external_value(PARAM_INT, 'Settings without a declared default.'),
            ]),
            'entries' => new external_multiple_structure(
                new external_single_structure([
                    'plugin' => new external_value(PARAM_RAW, 'Plugin component (empty for core).'),
                    'name' => new external_value(PARAM_RAW, 'Setting name.'),
                    'fullname' => new external_value(PARAM_RAW, 'Combined plugin/name identifier.'),
                    'visible_name' => new external_value(PARAM_RAW, 'Display label.'),
                    'section' => new external_value(PARAM_RAW, 'Parent admin_settingpage name for deep-linking.'),
                    'class' => new external_value(PARAM_RAW, 'admin_setting subclass.'),
                    'current' => new external_value(PARAM_RAW, 'Current stored value.'),
                    'default' => new external_value(PARAM_RAW, 'Declared default value.'),
                ])
            ),
        ]);
    }
}
