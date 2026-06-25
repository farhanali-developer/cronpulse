<?php
/**
 * CP_Cron_Tracker
 *
 * Wraps every registered cron hook to measure execution time and
 * record pass/fail status in the persistent log.
 */
defined( 'ABSPATH' ) || exit;

class CP_Cron_Tracker {

	/**
	 * Hooks registered for tracking in the current request.
	 *
	 * @var array<string, bool>
	 */
	private static array $tracked = [];

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_trackers' ], 1 );
	}

	/**
	 * Iterate all scheduled events and wrap their hooks.
	 */
	public static function register_trackers(): void {
		$crons = _get_cron_array();
		if ( empty( $crons ) || ! is_array( $crons ) ) {
			return;
		}

		foreach ( $crons as $timestamp => $cron_hooks ) {
			foreach ( $cron_hooks as $hook => $cron_args ) {
				if ( isset( self::$tracked[ $hook ] ) ) {
					continue;
				}
				self::$tracked[ $hook ] = true;

				// Add a high-priority action that fires BEFORE the real handler.
				add_action( $hook, static function () use ( $hook ) {
					self::before( $hook );
				}, -9999 );

				// Add a low-priority action that fires AFTER the real handler.
				add_action( $hook, static function () use ( $hook ) {
					self::after( $hook );
				}, 9999 );
			}
		}
	}

	/**
	 * Called immediately before the cron hook fires.
	 */
	public static function before( string $hook ): void {
		set_transient( 'cp_start_' . md5( $hook ), microtime( true ), 300 );
	}

	/**
	 * Called immediately after the cron hook fires.
	 */
	public static function after( string $hook ): void {
		$key   = 'cp_start_' . md5( $hook );
		$start = get_transient( $key );
		delete_transient( $key );

		$duration = $start ? round( ( microtime( true ) - (float) $start ) * 1000 ) : null; // ms

		self::log_execution( $hook, 'success', $duration );
	}

	/**
	 * Log a cron execution entry.
	 *
	 * @param string   $hook
	 * @param string   $status   'success' | 'error'
	 * @param int|null $duration Milliseconds
	 */
	public static function log_execution( string $hook, string $status, ?int $duration ): void {
		$log = get_option( CP_OPTION_LOG, [] );

		if ( ! is_array( $log ) ) {
			$log = [];
		}

		array_unshift( $log, [
			'hook'      => $hook,
			'status'    => $status,
			'duration'  => $duration,
			'timestamp' => time(),
		] );

		if ( count( $log ) > CP_LOG_LIMIT ) {
			$log = array_slice( $log, 0, CP_LOG_LIMIT );
		}

		update_option( CP_OPTION_LOG, $log, false );
	}

	/**
	 * Retrieve the last execution record for a given hook.
	 *
	 * @param string $hook
	 * @return array|null
	 */
	public static function get_last_run( string $hook ): ?array {
		$log = get_option( CP_OPTION_LOG, [] );

		if ( ! is_array( $log ) ) {
			return null;
		}

		foreach ( $log as $entry ) {
			if ( isset( $entry['hook'] ) && $entry['hook'] === $hook ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Return all log entries (newest first).
	 *
	 * @return array
	 */
	public static function get_log(): array {
		$log = get_option( CP_OPTION_LOG, [] );
		return is_array( $log ) ? $log : [];
	}

	/**
	 * Clear the entire log.
	 */
	public static function clear_log(): void {
		update_option( CP_OPTION_LOG, [], false );
	}
}
