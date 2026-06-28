<?php
/**
 * CronPulse_Admin_Bar
 *
 * Adds a small warning badge to the WP toolbar when any job is overdue
 * or failing, so problems are visible from any page without remembering
 * to open the dashboard.
 */
defined( 'ABSPATH' ) || exit;

class CronPulse_Admin_Bar {

	public static function init(): void {
		add_action( 'admin_bar_menu',       [ __CLASS__, 'add_node' ], 999 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_style' ] );
		add_action( 'wp_enqueue_scripts',    [ __CLASS__, 'enqueue_style' ] );
	}

	public static function add_node( $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$jobs  = CronPulse_Admin_Page::get_jobs();
		$count = count( array_filter( $jobs, static function ( $job ) {
			return in_array( $job['status'], [ 'overdue', 'failing' ], true );
		} ) );

		if ( $count < 1 ) {
			return;
		}

		$wp_admin_bar->add_node( [
			'id'    => 'cronpulse-status',
			'title' => sprintf(
				/* translators: %d = number of overdue or failing cron jobs */
				'⚠ ' . _n( '%d failing', '%d failing', $count, 'cronpulse' ),
				$count
			),
			'href'  => admin_url( 'tools.php?page=cronpulse' ),
			'meta'  => [ 'title' => __( 'Cron Pulse: jobs need attention', 'cronpulse' ) ],
		] );
	}

	/**
	 * The badge needs to stand out wherever the toolbar appears (front-end
	 * included), so it's a tiny inline style on the always-present
	 * 'admin-bar' handle rather than a dedicated enqueued stylesheet.
	 */
	public static function enqueue_style(): void {
		if ( ! is_admin_bar_showing() ) {
			return;
		}

		wp_add_inline_style(
			'admin-bar',
			'#wp-admin-bar-cronpulse-status .ab-item { color: #ffabaf !important; font-weight: 600; }
			#wp-admin-bar-cronpulse-status:hover .ab-item { color: #fff !important; }'
		);
	}
}
