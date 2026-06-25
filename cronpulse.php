<?php
/**
 * Plugin Name: Cron Pulse
 * Plugin URI:  https://wordpress.org/plugins/cronpulse/
 * Description: A visual dashboard to monitor, debug, and manually trigger WordPress cron jobs. See schedules, last run times, execution duration, and pass/fail status at a glance.
 * Version:     1.1.0
 * Author:      Farhan Ali
 * Author URI:  https://farhanali.me
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cronpulse
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'CP_VERSION',        '1.1.0' );
define( 'CP_PLUGIN_DIR',     plugin_dir_path( __FILE__ ) );
define( 'CP_PLUGIN_URL',     plugin_dir_url( __FILE__ ) );
define( 'CP_OPTION_LOG',     'cp_execution_log' );
define( 'CP_LOG_LIMIT',      200 ); // default log retention; overridable via the Settings tab
define( 'CP_OPTION_ALERTS',  'cp_alert_settings' ); // also holds general settings, e.g. log retention
define( 'CP_OPTION_STREAKS', 'cp_alert_streaks' );

require_once CP_PLUGIN_DIR . 'includes/class-cron-tracker.php';
require_once CP_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once CP_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once CP_PLUGIN_DIR . 'includes/class-alerts.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once CP_PLUGIN_DIR . 'includes/class-cli.php';
}

/**
 * Bootstrap on plugins_loaded so all cron hooks are registered.
 */
add_action( 'plugins_loaded', function () {
	CP_Cron_Tracker::init();
	CP_Admin_Page::init();
	CP_Ajax_Handler::init();
	CP_Alerts::init();
} );

/**
 * Activation: create the log option.
 */
register_activation_hook( __FILE__, function () {
	if ( false === get_option( CP_OPTION_LOG ) ) {
		add_option( CP_OPTION_LOG, array(), '', false );
	}
} );

/**
 * Deactivation: nothing destructive — keep logs.
 */
register_deactivation_hook( __FILE__, '__return_true' );

/**
 * Uninstall: clean up stored data.
 */
register_uninstall_hook( __FILE__, 'cp_uninstall' );
function cp_uninstall() {
	delete_option( CP_OPTION_LOG );
	delete_option( CP_OPTION_ALERTS );
	delete_option( CP_OPTION_STREAKS );
}
