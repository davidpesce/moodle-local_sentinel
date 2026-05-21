# local_fleetmonitor

Per-instance Moodle plugin that exposes operational health, environment details,
plugin inventory, and recent config changes through Moodle web services (pull)
and an optional outbound push.

Designed for fleets of Moodle sites managed by a single operator. The plugin is
the data source; the central dashboard is a separate concern.

## Requirements

- Moodle 4.5 LTS or later (supported through 5.1)
- Web services enabled with the REST protocol

## Install

Copy the plugin to `moodle/local/fleetmonitor/`, visit Site administration →
Notifications, and run the upgrade.

After install, run the bundled setup helper from the Moodle root:

```bash
php local/fleetmonitor/cli/setup.php --username=fleetmonitor
```

It enables web services + REST, creates a `local_fleetmonitor` role with the
required capabilities, creates a dedicated webservice user, and prints a token
ready for the central poller to use.

## Usage

### Pull

```
GET /webservice/rest/server.php
    ?wstoken=<token>
    &wsfunction=local_fleetmonitor_get_snapshot
    &moodlewsrestformat=json
```

Available functions:

| Function | Purpose |
|---|---|
| `local_fleetmonitor_get_status` | Cheap liveness — version + maintenance flag |
| `local_fleetmonitor_get_snapshot` | Full snapshot (all sections below) |
| `local_fleetmonitor_get_environment` | PHP, OS, DB, web server, extensions |
| `local_fleetmonitor_get_plugins` | Installed plugins + available updates |
| `local_fleetmonitor_get_health` | Cron, tasks, sessions, disk, backups, mail |
| `local_fleetmonitor_get_auth` | Enabled auth methods + user counts per method |
| `local_fleetmonitor_get_config_changes` | Recent `mdl_config_log` entries |

Each function returns:

```json
{
  "schema_version": 1,
  "generated_at": "2026-05-21T14:23:11+00:00",
  "payload": "<json-encoded snapshot data>"
}
```

### Push

For new-client evaluation or instances behind firewalls, the plugin can push
snapshots outbound on a schedule. Configure under Site administration → Plugins
→ Local plugins → Fleet Monitor:

- **Push endpoint URL** — where to POST
- **Push shared secret** — sent as `X-Fleetmonitor-Secret` header
- **Enable push** — flip the scheduled task on

The central collector must verify the secret header before accepting the body.

## Available-updates freshness

The `plugins.updates_available` and `plugins.update_check` fields reflect
Moodle's cached results from its last fetch of `moodle.org/updates`. The
plugin does **not** trigger a fresh fetch on every snapshot call — that would
be slow and hammer moodle.org at fleet scale.

Instead, the plugin ships its own scheduled task **`refresh_updates`**
(enabled by default, daily at a randomized time). It calls
`\core\update\checker::fetch()` directly — same network call as Moodle's
built-in auto-check, but **without** the admin email notifications.

This means you can leave Moodle's own "Check for updates and notify me"
task disabled to silence the email spam, and this plugin will still keep
the update cache fresh.

The task respects the top-level kill switch `$CFG->disableupdatenotifications`
for sites that have explicitly chosen not to phone home to moodle.org at all.

For on-demand refresh:
`php local/fleetmonitor/cli/refresh_updates.php` — equivalent to clicking
"Check for available updates" at Site administration → Notifications.

Use `plugins.update_check.age_seconds` to judge how stale the data is.

## Schema

The snapshot envelope is keyed by `siteidentifier` (stable across domain
migrations) and versioned via `schema_version`. Bump the version when adding
breaking fields; additive changes do not require a bump.

## Development

This plugin lives in the `_MoodleDEV` workspace. Use the workspace `Makefile`:

```bash
make phpcs PLUGIN=moodle-local_fleetmonitor
make phpunit SUITE=local_fleetmonitor_testsuite
```
