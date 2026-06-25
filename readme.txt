=== Cron Pulse ===
Contributors:      farhanalidev
Tags:              cron, cron jobs, wp-cron, developer tools, debugging
Requires at least: 5.8
Tested up to:      7.0
Stable tag:        1.0.0
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
* **Execution Log** — persistent log of the last 200 runs with duration and pass/fail status
* **Hook filter** — search/filter jobs by hook name
* **DISABLE_WP_CRON warning** — alerts you when automatic cron execution is disabled
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

In the WordPress options table under the key `cp_execution_log`. It is capped at 200 entries and cleared on uninstall.

= Is it compatible with Action Scheduler or WooCommerce? =

Cron Pulse tracks jobs registered through the standard WordPress `wp_schedule_event()` / `_get_cron_array()` API. Action Scheduler uses its own queue system and is not covered.

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release.
