<?php
/**
 * CP_CLI_Command
 *
 * WP-CLI command for scripting cron health checks across sites
 * (e.g. agencies running this in a loop over multiple client installs).
 */
defined( 'ABSPATH' ) || exit;

class CP_CLI_Command {

	/**
	 * Show the status of all scheduled WP-Cron jobs tracked by Cron Pulse.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only show jobs with this status.
	 * ---
	 * options:
	 *   - overdue
	 *   - failing
	 *   - healthy
	 *   - pending
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cronpulse status
	 *     wp cronpulse status --status=overdue
	 *     wp cronpulse status --format=json
	 *
	 * @when after_wp_load
	 */
	public function status( array $args, array $assoc_args ): void {
		$jobs = CP_Admin_Page::get_jobs();

		$filter = $assoc_args['status'] ?? '';
		if ( $filter ) {
			$jobs = array_values( array_filter( $jobs, static function ( $job ) use ( $filter ) {
				return $job['status'] === $filter;
			} ) );
		}

		if ( empty( $jobs ) ) {
			WP_CLI::success( 'No matching cron jobs found.' );
			return;
		}

		$rows = array_map( static function ( $job ) {
			return [
				'status'   => $job['status'],
				'hook'     => $job['hook'],
				'schedule' => $job['schedule'] ?: '—',
				'next_run' => gmdate( 'Y-m-d H:i:s', $job['next_run'] ) . ' GMT',
			];
		}, $jobs );

		$formatter = new \WP_CLI\Formatter( $assoc_args, [ 'status', 'hook', 'schedule', 'next_run' ] );
		$formatter->display_items( $rows );

		$unhealthy = count( array_filter( $jobs, static function ( $job ) {
			return in_array( $job['status'], [ 'overdue', 'failing' ], true );
		} ) );

		if ( $unhealthy > 0 ) {
			WP_CLI::warning( sprintf( '%d job(s) overdue or failing.', $unhealthy ) );
			WP_CLI::halt( 1 );
		}
	}
}

WP_CLI::add_command( 'cronpulse', 'CP_CLI_Command' );
