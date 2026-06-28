# Cron Pulse

A visual dashboard to monitor, debug, and manually trigger WordPress cron jobs.

WordPress developers fly blind with WP-Cron — the core tools give you no visibility into whether scheduled jobs are actually running, how long they take, or when they last fired. Cron Pulse adds a clean dashboard under **Tools → Cron Pulse** that fixes that.

## Features

- **Scheduled Jobs table** — hook name, recurrence schedule, next run time, last run time, execution duration
- **Status indicators** — Healthy / Overdue / Failing / Pending color coding so problems jump out immediately
- **Overdue detection** — instantly see jobs that should have fired but haven't
- **Stuck-job detection** — catches runs that started but never finished (process killed outright), not just fatals
- **Admin bar badge** — a small warning indicator on every page when something needs attention
- **Run Now** — manually trigger any cron hook with one click (great for testing)
- **Unschedule** — delete a stuck or duplicate scheduled event straight from the dashboard
- **Sortable columns and pagination** — click Next Run or Duration to sort; 25 jobs per page on large sites
- **Duration sparkline** — tiny trend line per hook so a creeping-up execution time is visible before it becomes a timeout
- **Execution Log** — persistent log of run history with duration and pass/fail status; retention is configurable
- **Hook and status filters** — search by hook name or narrow the table to Overdue/Failing/Healthy/Never Run
- **DISABLE_WP_CRON warning** — alerts you when automatic cron execution is disabled
- **Email and webhook alerts** — notify after N consecutive failed runs or extended overdue time, with optional per-job thresholds and one-click Snooze
- **Built-in SMTP settings** — route alert emails through your own mail provider instead of the server's default mail(), no separate SMTP plugin
- **Email Log** — see every alert/test email sent, with delivery status and the underlying error if one failed
- **Send Test Email / Send Test Webhook** — confirm notification settings work before you actually need them
- **WP-CLI support** — `wp cronpulse status` for scripting health checks across sites
- **REST API** — `GET /wp-json/cronpulse/v1/status` for remote dashboards
- Zero external dependencies — pure PHP and vanilla jQuery

## Requirements

- WordPress 5.8+
- PHP 7.4+

## Installation

### From WordPress.org

Search for "Cron Pulse" in **Plugins → Add New**, install, and activate.

### Manual / from source

```bash
git clone https://github.com/farhanali-developer/cronpulse.git
```

Copy (or symlink) the `cronpulse` folder into `wp-content/plugins/`, then activate it from **Plugins → Installed Plugins**.

## Usage

Navigate to **Tools → Cron Pulse** to see all scheduled cron jobs, their status, and execution history. Use **Run Now** to manually fire any hook for testing, or **Delete** to unschedule a stuck/duplicate event.

### Alerts

The **Settings** tab lets you enable email and/or webhook notifications:

- **Failure threshold** — alert after N consecutive failed runs for a job
- **Overdue threshold** — alert once a job has been overdue for longer than N minutes
- **Per-job overrides** — set tighter or looser thresholds for specific hooks (e.g. a payment-processing hook vs. a daily cleanup job)
- **Webhook** — receives a JSON POST for every alert. Includes both `text` and `content` keys alongside the structured fields, so the same payload works directly as a Slack or Discord webhook with no relay needed. Step-by-step setup for both, plus the full payload shape for a custom endpoint, is in a help box right on the Settings tab
- **SMTP** — host, port, encryption, username/password, and From address/name, applied via WordPress's `phpmailer_init` hook — no third-party SMTP plugin involved
- **Snooze** — acknowledge a current incident from the dashboard without disabling alerts globally; resumes normally once that hook recovers and fails again

After saving, use **Send Test Email** / **Send Test Webhook** to confirm delivery actually works, and check the **Email Log** tab for a record of every email sent (recipient, subject, status, and the underlying error on failure).

If the error message alone isn't enough, the same tab has an **Email Debug Log** — the actual SMTP conversation (connection, TLS, AUTH, server responses) for each attempt, written to `wp-content/uploads/cronpulse-logs/email-debug.log` (blocked from direct web access; login credentials are redacted before anything is written regardless).

### WP-CLI

```bash
wp cronpulse status
wp cronpulse status --status=overdue
wp cronpulse status --format=json
```

Exits with status code `1` if any job is overdue or failing — useful for scripting health checks across multiple sites.

### REST API

```
GET /wp-json/cronpulse/v1/status
GET /wp-json/cronpulse/v1/status?status=overdue
```

Requires `manage_options`. Authenticate with a logged-in session (cookie + nonce) or an [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) for external tools — no SSH or WP-CLI access needed.

## FAQ

**Why does a job show as "Overdue"?**
The scheduled run time has passed but the job hasn't fired yet. This can happen when `DISABLE_WP_CRON` is set in `wp-config.php`, or when the site has low traffic and the WP-Cron system-tick hasn't triggered.

**Does "Run Now" advance the next scheduled run time?**
No. It fires the hook's callback functions directly without modifying the cron schedule.

**Will this slow down my site?**
No. Tracker hooks only fire during cron execution (not regular page loads), and overhead is limited to a transient read/write per cron event. The admin bar badge and overdue detection piggyback on the same per-page-load check the plugin already does, not an additional query.

**Is it compatible with Action Scheduler or WooCommerce?**
Cron Pulse tracks jobs registered through the standard WordPress `wp_schedule_event()` / `_get_cron_array()` API. Action Scheduler uses its own queue system and is not covered.

**What does Snooze do, exactly?**
It marks the current failing/overdue incident as acknowledged so no further alert fires for it. Alerts remain enabled globally — the moment that hook recovers and later fails (or becomes overdue) again, alerting resumes for the new incident.

**Why would I need the SMTP settings?**
Many hosts don't have PHP's `mail()` configured at all, or send through it in a way that gets flagged as spam (no SPF/DKIM alignment, generic From address). SMTP routes through your own authenticated mail provider instead — Gmail, your host's mail server, or a transactional email provider's SMTP endpoint.

## License

GPL-2.0-or-later — see [license URI](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

[Farhan Ali](https://farhanali.me)
