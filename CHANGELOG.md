# Changelog

All notable changes to `local_sentinel` are documented here.

The plugin uses two version dimensions consumers should be aware of:

- **Plugin release** (e.g. `0.9.0`) — the semantic version of this plugin
  itself. Bumps on every meaningful change.
- **Snapshot `schema_version`** — the wire-format version of the JSON
  envelope returned by the web service functions. Bumps only on *breaking*
  shape changes; additive fields do not require a bump.

A central dashboard should branch its parser on `schema_version`, not on
plugin release.

## [2.21.6] — schema_version 3 — 2026-06-23

UI consistency.

- **The critical "Review →" action now opens the in-plugin Reports tab** (and
  scrolls to it) like the CRITICAL/ERRORS cards and the errors action, instead of
  the native `/report/status/` page. The Reports tab shows performance / security
  / system_status side-by-side, matching the cards' rationale of not dropping the
  operator onto a single contributing native page.

## [2.21.5] — schema_version 3 — 2026-06-23

UI polish on the 2.21.4 Overview colours.

- **Tab-switching action/card links now scroll to the tab content.** The tab
  area has an `id="sentinel-tab"` anchor and the "Review →" links (errors → Reports,
  low disk → Health) plus the CRITICAL/ERRORS cards target it, so the page jumps
  to the tab instead of landing at the top.
- **Error and warning are now visually distinct** — warning shifted to a clear
  yellow (#ffc107) and the error/warning row + card tints pulled apart (peach vs
  pale yellow).
- **Severity badges use a consistent dark text** on all four tiers (white is
  unreadable on the yellow/cyan; dark text clears WCAG AA contrast on all of them).

## [2.21.4] — schema_version 3 — 2026-06-23

UI only; no envelope shape change.

- **Overview page now colour-prioritises severity.** The Action needed list shows
  filled, uppercase severity badges with a coloured left accent and faint row
  tint; the headline metric cards colour the big number (and border/tint) by
  severity — CRITICAL red, ERRORS orange, updates amber, healthy green. Colours
  are defined in the plugin's own inline CSS rather than Bootstrap utility classes
  (`text-bg-*`/`display-6`/`fw-bold` are Bootstrap 5 only and render as no-ops on
  Moodle 4.x's Bootstrap 4), so they look the same across Moodle 4.5–5.2.
- **New `error` action severity tier** (danger > error > warning > info): the
  "checks reporting errors" action now stands out from generic warnings, matching
  the separate ERRORS metric card.

## [2.21.3] — schema_version 3 — 2026-06-23

Bug fix; no envelope shape change.

- **Add the missing `egress_slice_label_reporting` language string.** The egress
  settings page iterates `collector::ALL_SLICES` (which includes `reporting`)
  and renders each checkbox label via `get_string('egress_slice_label_' .
  $slice)`. The `reporting` slice had no label, so its checkbox rendered as the
  raw `[[egress_slice_label_reporting]]` placeholder. Pre-existing since the
  `reporting` slice was added.

## [2.21.2] — schema_version 3 — 2026-06-22

Honest OPcache reporting under CLI (additive envelope change; no schema bump).

- **OPcache is no longer reported as "disabled" when collected under CLI.**
  OPcache is per-SAPI: the `push_snapshot` scheduled task runs under cron/CLI,
  which cannot see the web workers' (php-fpm/apache) OPcache, so
  `opcache_get_status()` returns false there. The collector now reports
  `enabled: false, measurable: false` with a clear reason in that context
  instead of falsely asserting OPcache is off. A web-context read (a WS pull,
  which executes under the site's web SAPI) carries the real reading.
- **New additive `environment.opcache.measurable` field** (`VALUE_OPTIONAL`) —
  false under CLI/cron, true for a web-collected snapshot. No `schema_version`
  bump (additive). The dashboard reads the authoritative value from the most
  recent pull regardless of this flag.
- Overview page: shows "not measurable in this context" rather than "disabled"
  for an unmeasurable reading (only relevant if viewed outside a web SAPI).

## [2.21.1] — schema_version 3 — 2026-06-13

Bug fix; no envelope shape change.

- **`integrity.core_version_full` now reads the literal `$version` string
  from the on-disk version.php** instead of `$CFG->version`. Moodle stores
  `$version` as a float and PHP's float→string drops trailing zeros, so any
  tagged `.00` build (e.g. 4.5.12 = `2024100712.00`) reported `2024100712` —
  which failed the dashboard's manifest lookup and stalled delivery at
  "Awaiting manifest". The disk file also describes the tree actually being
  scanned, even when a DB upgrade is pending. (Dashboard ≥0.14.4 also
  normalises the reported value, so either side's fix unblocks delivery.)

## [2.21.0] — schema_version 3 — 2026-06-12

Core file integrity scanning (additive envelope change; no schema bump).

- **New `integrity` slice**: detects modified, missing, and unexpected files
  in the Moodle code tree by comparing on-disk git-blob hashes against a
  pristine manifest for the site's **exact build** (weekly `+` builds
  included, keyed by the literal `$version` decimal string). Manifests are
  derived from the public
  [moodle-core-manifests](https://github.com/davidpesce/moodle-core-manifests)
  dataset, but the plugin **never fetches anything itself** — a connected
  dashboard pushes the matching manifest down over WS. Only deviations leave
  the site: unexpected files are reported by **path only, never hashed**, so
  a stray secret-bearing file cannot be fingerprinted off-site. The slice is
  egress-filterable like any other.
- **New WS functions**: `local_sentinel_set_manifest` and
  `local_sentinel_request_integrity_scan` (both `type: write`, gated by the
  new `local/sentinel:manage` capability) and `local_sentinel_get_integrity`
  (read). `db/upgrade.php` grants `:manage` to roles already holding `:view`
  at system context, so existing dashboard tokens keep working without
  re-running setup. The manifest POST is ~1.3 MB — the site's web server
  must accept request bodies of a few MB (nginx: `client_max_body_size 4m;`).
- **New scheduled task** `integrity_scan` (weekly, randomized; self-gating on
  the new `integrityenabled` setting + manifest presence, both off/absent by
  default) plus an adhoc variant powering the dashboard's "Run audit now".
- **Overview → Integrity tab** shows the latest verdict and deviation lists
  locally.
- **Overview moved to Site administration → Reports → Sentinel** (was
  Plugins → Local plugins → Sentinel → Overview), where admins look for
  site status. The config pages (Alerts, Connect, Settings) stay under
  Local plugins → Sentinel. Same URL and page key; bookmarks keep working.

## [2.20.1] — schema_version 3 — 2026-06-10

Bug fix; no envelope shape change.

- Plugin-update links (Action panel + metric card) now jump to the
  **Available updates** section of /admin/plugins.php (`#updatable`); they
  previously anchored to the additional-plugins list (`#additional`).

## [2.20.0] — schema_version 3 — 2026-06-10

UI only; no envelope shape change (`schema_version` unchanged).

- **Overview now leads with "Action needed"**: a severity-ordered list of
  concrete actions derived from the snapshot (critical checks, stalled cron,
  failing/overdue tasks, unready cache stores, expiring/expired TLS cert, low
  disk, branch security-EOL countdown, core/plugin updates, backup errors,
  locked accounts, expiring tokens, token hygiene, debugging enabled, pending
  OS security updates) — each linking to the page where it gets fixed. Green
  all-clear state when nothing matches. (`\local_sentinel\actions`, fully
  unit-tested.)
- **Progressive disclosure**: the Reports tab shows non-OK checks expanded
  with passing checks collapsed behind an expander; the Plugins tab leads
  with missing/updatable plugins and collapses the up-to-date bulk. Nothing
  removed — attention is earned, not demanded.
- **Quiet healthy state**: zero-value metric cards render muted; only
  problems get color.

## [2.19.0] — schema_version 3 — 2026-06-10

No envelope shape change (`schema_version` unchanged).

- **One-paste provisioning codes.** The Connect page now accepts a single
  provisioning code (`SNTL1.<base64url(JSON)>` carrying the dashboard base URL
  + enrollment key) that fills in the connection settings, registers the site,
  and enables the push pipeline in one step — replacing the type-the-URL,
  type-the-key, click-register sequence. Vendor-neutral: any Sentinel
  dashboard can issue codes; nothing is hardcoded. HTTPS-only, strict offline
  parsing (`\local_sentinel\provisioning_code`), registration remains
  explicit and opt-in.

## [2.18.0] — schema_version 3 — 2026-06-10

No envelope shape change (`schema_version` unchanged).

- **Fix: registration now enables the push pipeline.** Previously a successful
  registration set `pushenabled` only on immediate (allowlisted) activation,
  and the `push_snapshot` scheduled task — which ships disabled — was never
  enabled at all, so a "connected" site silently never pushed. Registration
  (both *activated* and *pending* outcomes) now enables the setting **and**
  the scheduled task; a pending site starts sending automatically the moment
  the operator approves. An admin's explicit task customisation is respected
  (a hand-configured task's disabled state is left alone).
- **Moodle 5.2 support**: `$plugin->supported` extended to `[405, 502]`,
  branch 502 added to the security-EOL map (2027-10-04), CI matrix extended
  to `MOODLE_502_STABLE` (PHP 8.3/8.4).

## [2.17.0] — schema_version 3 — 2026-06-10

Additive; no envelope shape change (`schema_version` unchanged).

- **OS package-update signal**: the `environment.os` block now carries a
  **`package_updates`** object — `checked`, `available`, `security` (pending
  update counts, null = unknown), `reboot_required`, and `source`. Read
  root-free from update-notifier's `/var/lib/update-notifier/updates-available`
  and `/var/run/reboot-required` on Debian/Ubuntu; on other hosts the counts
  are null. Raw facts only — the dashboard decides what warrants attention.
  `VALUE_OPTIONAL` so existing consumers are unaffected.
- **Maturity raised to STABLE** ahead of a Moodle plugins-directory listing.
  The collectors, setup, push pipeline, egress filter, and self-registration
  have been stable across the 2.14–2.16 line.
- The local Overview page now shows a **dismissible pointer to connecting a
  Sentinel dashboard** (alerting, uptime history, scheduled reports) on sites
  that have neither push configured nor a registration activated. It
  disappears once connected, or permanently per user via Dismiss.

## [2.16.0] — schema_version 3 — 2026-06-09

Additive; no envelope shape change (`schema_version` unchanged).

- The cheap `get_status` liveness probe now carries an **`active`** block
  (`status.active.last_5_min` / `last_hour`) — the same lastaccess-based
  logged-in-user counts as the `health` slice, factored into a shared
  `health::active_user_counts()` helper. This lets a dashboard refresh
  "who's active right now" on the 5-minute liveness cadence **without** a full
  snapshot pull. The counts are indexed `lastaccess` range scans, so the probe
  stays cheap. The field is `VALUE_OPTIONAL` so the egress filter can still
  withhold it.

## [2.15.3] — schema_version 3 — 2026-06-09

Bug fix; no envelope shape change.

- Setup now satisfies **required custom profile fields** for the `sentinel`
  web-service user. On a site with a required custom user profile field, the
  auto-created service account counted as "not fully set up", so the dashboard's
  pull was rejected with `usernotfullysetup`. Setup (and registration, which runs
  setup) now fills any required/visible/unlocked-but-empty field with a
  placeholder — the account never uses the site UI, so the value is immaterial.
  Idempotent and self-healing: re-running setup / re-registering fixes an
  already-created account.
- Maturity raised `ALPHA` → `BETA` (in production across the fleet).

## [2.15.2] — schema_version 3 — 2026-06-09

Bug fix; no envelope shape change.

- `health.sessions.active_last_5_min` / `active_last_hour` are now measured by
  `mdl_user.lastaccess`, **not** the `mdl_sessions` table. The session table is
  only populated under the *database* session handler; busy sites commonly store
  sessions in Redis/Memcached, leaving `mdl_sessions` empty — so the previous
  query read **0 active users even with dozens online**. `lastaccess` is updated
  on activity regardless of the session backend and is the same source as the
  day/week/month figures (so they stay consistent). Still excludes deleted users
  and the Guest account; real users of any role count. (Supersedes the 2.15.1
  session-table approach, which fixed over-counting but broke on external session
  stores.)

## [2.15.1] — schema_version 3 — 2026-06-09

Bug fix; no envelope shape change.

- `health.sessions.active_last_5_min` / `active_last_hour` now count **distinct
  logged-in users**, not raw `mdl_sessions` rows. Moodle creates a session for
  every visitor — including anonymous/not-logged-in ones (`userid 0`, e.g.
  crawlers and logged-out browsing) — and a single user can hold several
  (multiple devices/tabs), so the old count was badly inflated (e.g. reporting 8
  when one user was online). Now filters `userid <> 0` and `COUNT(DISTINCT
  userid)`. `total_rows` still reports the raw session-table size for context.

## [2.15.0] — schema_version 3 — 2026-06-09

Self-registration now provisions **pull** as well as push. The register payload
carries a freshly-minted (or reused) web-service `ws_token` alongside the push
secret, so the dashboard can fetch on demand — e.g. a fresh "who's active right
now" read before a maintenance window — instead of waiting for the next 15-minute
push.

- `register::run()` calls the idempotent setup helper to ensure a WS token
  (enabling web services + REST and the Sentinel user/role as a side effect of
  registering) and includes it in the body; `build_payload()` gains a `ws_token`
  field. Best-effort: if minting fails, registration proceeds **push-only**
  (empty token).
- Privacy: the registration metadata now declares the transmitted `ws_token`
  (a machine credential, not user data). HTTPS-only is still enforced, so the
  token never crosses plaintext.
- No envelope shape change (`schema_version` unchanged) — this is a
  registration-protocol addition.

## [2.14.2] — schema_version 3 — 2026-06-08

Bug fix; no envelope shape change.

- `environment.os.distro_version` now reports the precise installed version,
  including the point release. Previously it carried only `VERSION_ID`, which on
  Ubuntu omits the patch (e.g. `24.04` rather than `24.04.4` — the point release
  lives in `VERSION`/`PRETTY_NAME`, not `VERSION_ID`). The dashboard compared
  that bare cycle against endoflife's latest patch and falsely flagged an
  available OS update on every up-to-date Ubuntu host. The collector now prefers
  the `VERSION` token when it extends `VERSION_ID`, and falls back to
  `VERSION_ID` for distros that already carry the patch there (Debian `12`,
  RHEL `9.4`). The dashboard derives the endoflife cycle (major.minor) from this
  precise value.

## [2.14.1] — schema_version 3 — 2026-06-01

Additive; no envelope shape change.

- New `cli/register.php` — headless equivalent of the Connect-page Register
  action, so fleet automation (Ansible) can onboard many sites without a UI
  session. Same off-by-default gate + HTTPS-only guard as the UI path; exits 0
  when the dashboard accepts (activated/pending), 1 otherwise.

## [2.14.0] — schema_version 3 — 2026-06-01

Additive; no envelope shape change (`schema_version` unchanged).

Adds **self-registration**: a site can register itself with a Sentinel
dashboard instead of the operator hand-creating the instance.

- New settings (all off/empty by default): `registrationenabled`,
  `dashboardbaseurl` (HTTPS only), `enrollmentkey`.
- New `\local_sentinel\register::run()` — generates its own push secret,
  submits site identity + the enrollment key to the dashboard's
  `/api/register/`, and records the outcome in
  `\local_sentinel\registration_state` (mirrors `push_state`). Triggered
  explicitly from the **Connect to dashboard** page (sesskey + capability);
  never automatic, never a hardcoded URL.
- Privacy: declares the registration transmission via the metadata provider.
  The payload carries site identity + a generated machine credential only —
  no user personal data.

## [2.13.0] — schema_version 3 — 2026-05-30

Additive. The envelope now carries an **`egress`** block
(`{ excluded_slices, excluded_fields }`) declaring exactly what the site's
egress filter withholds, so a dashboard can show "withheld by the site" instead
of treating the gap as missing/broken data.

## [2.12.0] — schema_version 3 — 2026-05-30

Additive. `environment.os` now reports the Linux distribution (`distro`,
`distro_version`, `distro_name`) parsed from `/etc/os-release` — previously only
the kernel (from `php_uname()`) was available. Empty on non-Linux.

## [2.11.0] — schema_version 3 — 2026-05-29

Additive. Forwards the site's alert-recipient list in the snapshot
(`reporting.recipients`, from the `alertemails` setting) so the dashboard knows
who to send reports to. Reporting itself stays a dashboard concern.

## [2.10.0] — schema_version 3 — 2026-05-28

Privacy API now declares external transmission to a remote dashboard; actor
`firstname`/`lastname` are stripped from outbound data.

## [2.9.0] — [2.9.1] — schema_version 3 — 2026-05-28

Push pipeline **self-monitoring**: the plugin records its own push attempts /
successes / failures (`push_state`) and surfaces them in the `health` slice.
2.9.1 stops counting tasks whose plugin or row is disabled as overdue/failed.

## [2.8.0] — [2.8.1] — schema_version 3 — 2026-05-28

Adds the **admin-controlled data egress filter** (`egress_excluded_slices` /
`egress_excluded_fields`) — slice- and field-level toggles to withhold data from
the snapshot. 2.8.1 adds MUC cache-store reachability to the `health` slice.

## [2.1.0] – [2.7.0] — schema_version 3 — 2026-05-22..28

In-plugin **Overview** admin digest and consolidated **Connect** UI, a
**Settings/alerts** admin page, modern Moodle cron last-run field, inline
overdue-task reporting, and assorted health/collector hardening. See the git
history for per-commit detail (these versions were not individually recorded
here at the time).

## [2.0.0] — schema_version 3 — 2026-05-22

**Plugin renamed: `local_fleetmonitor` → `local_sentinel`.**

The plugin's identity changes throughout — component name, namespace,
capability, WS function prefix, push-secret header, lang strings, and
operator-facing display name "Sentinel". Existing installs of
`local_fleetmonitor` must be uninstalled before installing this version
(Moodle treats the renamed plugin as a brand-new component).

Snapshot `schema_version` is unchanged at 3; the wire shape is the same.
Consumers must update:

| Was | Now |
|---|---|
| WS prefix `local_fleetmonitor_*` | `local_sentinel_*` |
| Header `X-Fleetmonitor-Secret` | `X-Sentinel-Secret` |
| Capability `local/fleetmonitor:view` | `local/sentinel:view` |
| `payload.plugin.component` value `local_fleetmonitor` | `local_sentinel` |

## [1.1.0] — schema_version 3 — 2026-05-21

Additive. Exposes Moodle core update availability — previously the
snapshot only showed per-plugin updates and not whether Moodle itself
had a newer release.

- **Added** `status.core_update`:
  - `update_available: bool` — true if a newer Moodle release exists
    on the current branch (e.g. 4.5.10 → 4.5.11).
  - `latest_on_branch: {branch, version, release, maturity, download}`
    or `null` — same-branch latest, what an operator would actually
    apply as a routine patch.
  - `newer_branches: [...]` — one entry per newer branch (e.g. 5.0,
    5.1, 5.2) with its latest stable release. For planning major
    upgrades, not routine patching.

  Data comes from `\core\update\checker::get_update_info('core')`.
  Refresh is handled by the existing `refresh_updates` scheduled
  task; the collector reads cache only.

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
  `local_sentinel` versions.
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

- **Added** `local_sentinel\task\refresh_updates` scheduled task —
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
