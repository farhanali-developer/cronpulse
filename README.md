# Cron Pulse

A visual dashboard to monitor, debug, and manually trigger WordPress cron jobs.

WordPress developers fly blind with WP-Cron — the core tools give you no visibility into whether scheduled jobs are actually running, how long they take, or when they last fired. Cron Pulse adds a clean dashboard under **Tools → Cron Pulse** that fixes that.

## Features

- **Scheduled Jobs table** — hook name, recurrence schedule, next run time, last run time, execution duration
- **Status indicators** — Healthy / Overdue / Pending color coding so problems jump out immediately
- **Overdue detection** — instantly see jobs that should have fired but haven't
- **Run Now** — manually trigger any cron hook with one click (great for testing)
- **Execution Log** — persistent log of the last 200 runs with duration and pass/fail status
- **Hook filter** — search/filter jobs by hook name
- **DISABLE_WP_CRON warning** — alerts you when automatic cron execution is disabled
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

Navigate to **Tools → Cron Pulse** to see all scheduled cron jobs, their status, and execution history. Use **Run Now** to manually fire any hook for testing.

## FAQ

**Why does a job show as "Overdue"?**
The scheduled run time has passed but the job hasn't fired yet. This can happen when `DISABLE_WP_CRON` is set in `wp-config.php`, or when the site has low traffic and the WP-Cron system-tick hasn't triggered.

**Does "Run Now" advance the next scheduled run time?**
No. It fires the hook's callback functions directly without modifying the cron schedule.

**Will this slow down my site?**
No. Tracker hooks only fire during cron execution (not regular page loads), and overhead is limited to a transient read/write per cron event.

**Is it compatible with Action Scheduler or WooCommerce?**
Cron Pulse tracks jobs registered through the standard WordPress `wp_schedule_event()` / `_get_cron_array()` API. Action Scheduler uses its own queue system and is not covered.

## License

GPL-2.0-or-later — see [license URI](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

[Farhan Ali](https://farhanali.me)
