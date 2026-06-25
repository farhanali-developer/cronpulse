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

	/**
	 * Hooks currently executing in this request — removed once after() runs.
	 * Anything still here at shutdown started but never finished.
	 *
	 * @var array<string, bool>
	 */
	private static array $in_flight = [];

	/**
	 * Whether the shutdown handler has already been registered this request.
	 */
	private static bool $shutdown_registered = false;

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
		self::$in_flight[ $hook ] = true;

		// Registered once per request; catches fatals/timeouts that never reach after().
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( [ __CLASS__, 'handle_shutdown' ] );
		}
	}

	/**
	 * Called immediately after the cron hook fires.
	 */
	public static function after( string $hook ): void {
		unset( self::$in_flight[ $hook ] );

		$key   = 'cp_start_' . md5( $hook );
		$start = get_transient( $key );
		delete_transient( $key );

		$duration = $start ? round( ( microtime( true ) - (float) $start ) * 1000 ) : null; // ms

		self::log_execution( $hook, 'success', $duration );
	}

	/**
	 * Runs at PHP shutdown. Any hook still marked in-flight here started but
	 * never reached after() — it fataled, timed out, or the process was killed.
	 * Without this, that run would just vanish instead of being logged.
	 */
	public static function handle_shutdown(): void {
		if ( empty( self::$in_flight ) ) {
			return;
		}

		$error    = error_get_last();
		$fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];
		$is_fatal = $error && in_array( $error['type'], $fatal_types, true );

		foreach ( array_keys( self::$in_flight ) as $hook ) {
			$key   = 'cp_start_' . md5( $hook );
			$start = get_transient( $key );
			delete_transient( $key );

			$duration = $start ? round( ( microtime( true ) - (float) $start ) * 1000 ) : null;

			if ( $is_fatal ) {
				$message = sprintf( '%s in %s on line %d', $error['message'], $error['file'], $error['line'] );
				self::log_execution( $hook, 'fatal', $duration, $message );
			} else {
				self::log_execution( $hook, 'incomplete', $duration );
			}
		}
	}

	/**
	 * Log a cron execution entry.
	 *
	 * @param string      $hook
	 * @param string      $status   'success' | 'fatal' | 'incomplete'
	 * @param int|null    $duration Milliseconds
	 * @param string|null $message  Error detail, only set for 'fatal'
	 */
	public static function log_execution( string $hook, string $status, ?int $duration, ?string $message = null ): void {
		$log = get_option( CP_OPTION_LOG, [] );

		if ( ! is_array( $log ) ) {
			$log = [];
		}

		array_unshift( $log, [
			'hook'      => $hook,
			'status'    => $status,
			'duration'  => $duration,
			'message'   => $message,
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
