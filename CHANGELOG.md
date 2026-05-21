# Changelog

All notable changes to `local_fleetmonitor` are documented here.

The plugin uses two version dimensions consumers should be aware of:

- **Plugin release** (e.g. `0.9.0`) — the semantic version of this plugin
  itself. Bumps on every meaningful change.
- **Snapshot `schema_version`** — the wire-format version of the JSON
  envelope returned by the web service functions. Bumps only on *breaking*
  shape changes; additive fields do not require a bump.

A central dashboard should branch its parser on `schema_version`, not on
plugin release.

## [1.0.0] — schema_version 3 — 2026-05-21

Closes the original spec. Adds the long-deferred reports collector.

- **Added** `reports` slice (new `get_reports` external function):
  - `performance` — runs `\core\check\manager::get_performance_checks()`,
    captures status / summary for each (cachejs, OPcache, designermode, etc.).
  - `security` — same via `get_security_checks()` (crawlers, password policy,
    web cron, embed, etc.).
  - `system_status` — same via `get_status_checks()` (the data behind the
    System status admin page).
  - `mfa` — direct read of `mdl_tool_mfa`: per-factor active user counts,
    total users with any factor enrolled, current locked user count.
  Each check section includes `counts_by_status` (na/ok/info/unknown/warning/
  error/critical) plus the full check list with plaintext summaries.

## [0.9.0] — schema_version 3 — 2026-05-21

Additive on the wire. Plugin self-identification embedded in every snapshot.

- **Added** `plugin` field on the envelope: `{component, version, release}` —
  lets a central dashboard detect instances running outdated
  `local_fleetmonitor` versions.
- **Added** `auth.failed_logins`: total/per-account login failures from
  `mdl_user_preferences.login_failed_count_since_success`, plus current
  lockout count and top 10 targeted accounts.
- **Added** `health.upgrade_log`: tail of `mdl_upgrade_log` with severity
  labels and lifetime error count.
- **Added** `health.admins.last_changed`: most recent `mdl_config_log` entry
  for the `siteadmins` config key so dashboards can diff admin churn.
- **Added** `tests/external_test.php` — runs each external function's output
  through `external_api::clean_returnvalue()` against its declared structure,
  catching collector/structure drift in CI.

## [0.7.1] — schema_version 3 — 2026-05-21

- **Added** `update_available: bool` and `version_latest: int|null` to each
  plugin entry, cross-referenced from `updates_available[]`. Resolves the
  confusing case where `status: "uptodate"` (install-consistency) coincided
  with an upstream update being available.

## [0.7.0] — schema_version 3 — 2026-05-21

**Breaking wire-format change.**

- **Removed** the `payload` JSON-encoded string field from every WS response.
- **Added** native nested JSON via `external_single_structure` declarations
  for every field. Browser rendering of the bundled snapshot dropped from
  >1 minute to instant.
- Brings the plugin in line with Moodle external_api best practices:
  `clean_returnvalue()` validation, multi-protocol serialization
  (REST / XML-RPC / SOAP), auto-generated WS docs, typed client bindings.

## [0.6.0] — schema_version 2 — 2026-05-21

**Breaking** rename in plugin entries.

- **Renamed** plugin entry `version` → `version_disk`.
- **Added** plugin entry `version_db`, `status`, `missing_from_disk` —
  surfaces "Missing from disk" plugins the way Moodle's admin UI does.

## [0.5.0] — schema_version 1 — 2026-05-21

- **Added** `config_drift` collector (and matching `get_config_drift` WS
  function) — settings whose current value differs from declared default,
  with sensitive-field redaction.

## [0.4.0] — schema_version 1 — 2026-05-21

- **Added** `local_fleetmonitor\task\refresh_updates` scheduled task —
  fetches the moodle.org updates cache without sending admin notification
  email. Default daily at a randomized time.
- **Added** `cli/refresh_updates.php` — on-demand cache refresh equivalent
  to "Check for available updates" at Site admin → Notifications.
- **Added** `plugins.update_check`: enabled flag, last_fetched timestamp,
  age_seconds for the available-updates cache.
- **Fixed** `updates_available[]` producing 8 duplicate entries per
  component due to misinterpreting `core_plugin_manager::available_updates()`
  return shape.

## [0.3.0] — schema_version 1 — 2026-05-21

- **Added** `status.branch_eol_date`, `branch_eol_days_remaining`, and
  `build_age_days`.
- **Added** `environment.database.size_bytes` and `largest_tables[]` (top 10).
- **Added** `health.backup`: automated_state, status_counts, last_success.
- **Added** `auth` slice (new external function `get_auth`): enabled methods,
  per-method user counts, drift detection (users on disabled auth methods).

## [0.2.0] — schema_version 1 — 2026-05-21

- **Initial** scaffold with 6 collectors (status, environment, plugins,
  health, config_changes) plus 7 external functions and the `push_snapshot`
  scheduled task.
