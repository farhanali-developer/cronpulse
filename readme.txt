=== Cron Pulse ===
Contributors:      farhanalidev
Tags:              cron, cron jobs, wp-cron, developer tools, debugging
Requires at least: 5.8
Tested up to:      7.0
Stable tag:        1.1.0
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A visual dashboard to monitor, debug, and manually trigger WordPress cron jobs.

== Description ==

WordPress developers fly blind with WP-Cron. The core tools give you no visibility into whether scheduled jobs are actually running, how long they take, or when they last fired.

**Cron Pulse** adds a clean dashboard under **Tools → Cron Pulse** that shows everything you need at a glance:

= Features =

* **Scheduled Jobs table** — hook name, recurrence schedule, next run time, last run time, execution duration
* **Status indicators** — Healthy / Overdue / Pending color coding so problems jump out immediately
* **Overdue detection** — instantly see jobs that should have fired but haven't
* **Run Now** — manually trigger any cron hook with one click (great for testing)
* **Unschedule** — delete a stuck or duplicate scheduled event straight from the dashboard
* **Execution Log** — persistent log of run history with duration and pass/fail status; retention is configurable
* **Hook and status filters** — search by hook name or narrow the table to just Overdue/Failing/Healthy/Never Run
* **DISABLE_WP_CRON warning** — alerts you when automatic cron execution is disabled
* **Email and webhook alerts** — get notified after N consecutive failed runs or when a job has been overdue too long
* **WP-CLI support** — `wp cronpulse status` for scripting health checks across sites
* Zero external dependencies — pure PHP and vanilla jQuery

= Who is this for? =

* **WordPress developers** debugging cron-based features
* **Agencies** maintaining multiple client sites
* **Enterprise teams** needing visibility into scheduled background tasks
* Anyone tired of checking wp-cron manually or reading cryptic log files

= Privacy =

This plugin stores cron execution data (hook name, timestamp, duration) in the WordPress options table. No data is sent externally. All data is deleted on plugin uninstall.

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

In the WordPress options table under the key `cp_execution_log`. The entry cap defaults to 200 and is configurable from the Settings tab (10–5000). It's cleared on uninstall.

= Is it compatible with Action Scheduler or WooCommerce? =

Cron Pulse tracks jobs registered through the standard WordPress `wp_schedule_event()` / `_get_cron_array()` API. Action Scheduler uses its own queue system and is not covered.

== Changelog ==

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

= 1.1.0 =
No action needed. New Settings tab lets you configure alerts and log retention.

= 1.0.0 =
Initial release.
