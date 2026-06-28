=== Cron Pulse ===
Contributors:      farhanalidev
Tags:              cron, cron jobs, wp-cron, developer tools, debugging
Requires at least: 5.8
Tested up to:      7.0
Stable tag:        1.4.0
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A visual dashboard to monitor, debug, and manually trigger WordPress cron jobs.

== Description ==

WordPress developers fly blind with WP-Cron. The core tools give you no visibility into whether scheduled jobs are actually running, how long they take, or when they last fired.

**Cron Pulse** adds a clean dashboard under **Tools → Cron Pulse** that shows everything you need at a glance:

= Features =

* **Scheduled Jobs table** — hook name, recurrence schedule, next run time, last run time, execution duration
* **Status indicators** — Healthy / Overdue / Failing / Pending color coding so problems jump out immediately
* **Overdue detection** — instantly see jobs that should have fired but haven't
* **Admin bar badge** — a small warning indicator on every wp-admin (and front-end) page when something needs attention, so you don't have to remember to check the dashboard
* **Run Now** — manually trigger any cron hook with one click (great for testing)
* **Unschedule** — delete a stuck or duplicate scheduled event straight from the dashboard
* **Sortable columns and pagination** — click Next Run or Duration to sort; 25 jobs per page on sites with large schedules
* **Duration sparkline** — tiny trend line per hook so a creeping-up execution time is visible before it becomes a timeout
* **Execution Log** — persistent log of run history with duration and pass/fail status; retention is configurable
* **Hook and status filters** — search by hook name or narrow the table to just Overdue/Failing/Healthy/Never Run
* **DISABLE_WP_CRON warning** — alerts you when automatic cron execution is disabled
* **Email and webhook alerts** — get notified after N consecutive failed runs or when a job has been overdue too long, with optional per-job thresholds and a one-click snooze for incidents you already know about
* **Built-in SMTP settings** — route alert emails through your own mail provider instead of the server's default mail() function, no separate SMTP plugin required
* **Email Log** — see every alert/test email Cron Pulse has sent, with delivery status and the underlying error if one failed
* **Send Test Email / Send Test Webhook** — confirm your notification settings actually work before you need them
* **WP-CLI support** — `wp cronpulse status` for scripting health checks across sites
* **REST API** — `GET /wp-json/cronpulse/v1/status` for remote dashboards, authenticated like any other WP REST route
* Zero external dependencies — pure PHP and vanilla jQuery

= Who is this for? =

* **WordPress developers** debugging cron-based features
* **Agencies** maintaining multiple client sites
* **Enterprise teams** needing visibility into scheduled background tasks
* Anyone tired of checking wp-cron manually or reading cryptic log files

= Privacy =

This plugin stores cron execution data (hook name, timestamp, duration) in the WordPress options table. No data is sent externally unless you explicitly configure a webhook URL under alert settings, in which case alert payloads are POSTed to that URL. The REST API endpoint is read-only and pull-based — nothing is sent anywhere on its own. If you enable SMTP, your SMTP credentials are stored in the WordPress options table, same as any other plugin setting — no third-party service receives them except the SMTP server you configure. The email log stores recipient addresses and subjects for emails Cron Pulse has sent. All data is deleted on plugin uninstall.

== Installation ==

1. Upload the `cronpulse` folder to `/wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. Navigate to **Tools → Cron Pulse**

== Screenshots ==

1. Main dashboard showing scheduled jobs with status indicators
2. Execution log tab with run history

== Frequently Asked Questions ==

= Why does a job show as "Overdue"? =

It means the scheduled run time has passed but the job hasn't fired yet. This can happen when `DISABLE_WP_CRON` is set in `wp-config.php`, or when your site has low traffic and the WP-Cron system-tick hasn't triggered.

= Does "Run Now" advance the next scheduled run time? =

No. It fires the hook's callback functions directly without modifying the cron schedule. Use it for testing or manual one-off execution.

= Will this slow down my site? =

No. The tracker hooks fire only during cron execution (not on regular page loads) and the overhead is limited to a transient read/write per cron event.

= Where is the log stored? =

In the WordPress options table under the key `cronpulse_execution_log`. The entry cap defaults to 200 and is configurable from the Settings tab (10–5000). It's cleared on uninstall.

= Is it compatible with Action Scheduler or WooCommerce? =

Cron Pulse tracks jobs registered through the standard WordPress `wp_schedule_event()` / `_get_cron_array()` API. Action Scheduler uses its own queue system and is not covered.

= How do I authenticate against the REST endpoint? =

The same way as any other WordPress REST route: a logged-in browser session (cookie + nonce), or an Application Password for external tools — Users → Profile → Application Passwords. The requesting user needs the `manage_options` capability.

= What does "Snooze" do? =

It acknowledges the current incident for that hook so no further alert is sent for it, without turning off alerts globally. The moment the job recovers and later fails (or becomes overdue) again, alerting resumes normally for that new incident.

= Why does the admin bar badge sometimes not appear right away? =

It's evaluated on every page load along with everything else the plugin tracks, so it only updates when you load a page — there's no background process polling for it.

= Why would I need the SMTP settings? =

Many hosts either don't have PHP's mail() function configured at all, or send through it in a way that gets flagged as spam (no SPF/DKIM alignment, generic "From" address). Configuring SMTP with your own mail provider's credentials routes through a real authenticated mail server instead, without needing a separate SMTP plugin. Use "Send Test Email" after saving to confirm it's actually working.

= Where is the email log stored, and what's in it? =

In the WordPress options table under the key `cronpulse_email_log`, capped at the last 50 entries. Each entry has the recipient, subject, type (alert/test), delivery status, and the underlying error message if it failed. Cleared on uninstall, or anytime via the Clear Email Log button.

== Changelog ==

= 1.4.0 =
* Added built-in SMTP settings — route alert emails through your own mail provider, no separate SMTP plugin needed
* Added Email Log tab — see every alert/test email sent, with delivery status and error detail on failure
* Added Send Test Email and Send Test Webhook buttons to confirm notification settings work before relying on them

= 1.3.1 =
* Fixed: missing translators comment on a translatable string with a placeholder
* Fixed: removed `.gitignore` from the plugin package — hidden files aren't permitted in the WordPress.org repository
* Fixed: escaped output in the execution log count display
* Fixed: nonce-verification and input-sanitization warnings in the per-job alert overrides save handler
* Corrected "Tested up to" to the current WordPress version

= 1.3.0 =
* Fixed: clicking Run Now wrote two log entries for the same run (the wrapper hooked to every cron action fired alongside the explicit log call)
* Fixed: cron tracking now only attaches during a genuine WP-Cron run (wp_doing_cron()), so it no longer records unrelated direct invocations of a same-named hook
* Fixed: per-hook run duration no longer relies solely on a shared transient, so overlapping runs of the same hook can't clobber each other's timing
* Renamed all internal PHP constants, classes, options, transients, and AJAX actions to a longer, more distinctive prefix (`cronpulse_` / `CronPulse_`) to avoid collisions with other plugins

= 1.2.0 =
* Added admin bar badge showing a count of overdue/failing jobs on any page
* Added REST API endpoint: `GET /wp-json/cronpulse/v1/status`
* Added per-job alert threshold overrides (Settings tab)
* Added sortable Next Run / Duration columns
* Added per-hook duration sparkline
* Added pagination to the Scheduled Jobs table (25 per page)
* Added one-click Snooze to acknowledge a failing/overdue job without disabling alerts globally

= 1.1.0 =
* Added stuck-job detection (process killed without completing, not just fatals)
* Added email/webhook alerts after N consecutive failures or extended overdue time
* Added unschedule/delete for a single scheduled event
* Added WP-CLI command: `wp cronpulse status`
* Added status filter on the Scheduled Jobs table
* Added configurable execution log retention (Settings tab)

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.4.0 =
No action needed. SMTP is off by default — alert emails keep using the server's default mail() function until you enable and save SMTP settings yourself.

= 1.3.1 =
No action needed. Code-quality and compliance fixes only.

= 1.3.0 =
Internal option/constant names changed for WordPress.org compliance. If you installed an earlier version, your execution log and alert settings will reset on upgrade (the plugin starts fresh under the new names).

= 1.2.0 =
No action needed. New per-job alert overrides and REST endpoint are opt-in.

= 1.1.0 =
No action needed. New Settings tab lets you configure alerts and log retention.

= 1.0.0 =
Initial release.
