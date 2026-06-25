<?php
/**
 * CP_Ajax_Handler
 *
 * Handles AJAX requests for:
 *  - cp_run_now     — manually trigger a cron hook
 *  - cp_clear_log   — wipe the execution log
 *  - cp_unschedule  — remove a scheduled event from WP-Cron
 */
defined( 'ABSPATH' ) || exit;

class CP_Ajax_Handler {

	public static function init(): void {
		add_action( 'wp_ajax_cp_run_now',    [ __CLASS__, 'handle_run_now' ] );
		add_action( 'wp_ajax_cp_clear_log',  [ __CLASS__, 'handle_clear_log' ] );
		add_action( 'wp_ajax_cp_unschedule', [ __CLASS__, 'handle_unschedule' ] );
	}

	// -------------------------------------------------------------------------

	public static function handle_run_now(): void {
		check_ajax_referer( 'cp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'cronpulse' ) ], 403 );
			return;
		}

		$hook = sanitize_key( wp_unslash( $_POST['hook'] ?? '' ) );
		if ( empty( $hook ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid hook name.', 'cronpulse' ) ] );
			return;
		}

		// Whitelist: only allow hooks that are currently registered in WP-Cron.
		if ( ! in_array( $hook, self::get_registered_cron_hooks(), true ) ) {
			wp_send_json_error( [ 'message' => __( 'Hook is not a registered cron job.', 'cronpulse' ) ] );
			return;
		}

		$raw_args = sanitize_text_field( wp_unslash( $_POST['args'] ?? '' ) );
		$args     = [];
		if ( ! empty( $raw_args ) ) {
			$decoded = json_decode( $raw_args, true );
			if ( is_array( $decoded ) ) {
				$args = self::sanitize_args( $decoded );
			}
		}

		// Fire the hook and measure execution time.
		$start    = microtime( true );
		do_action_ref_array( $hook, $args );
		$duration = (int) round( ( microtime( true ) - $start ) * 1000 );

		CP_Cron_Tracker::log_execution( $hook, 'success', $duration );

		wp_send_json_success( [
			'message'  => sprintf(
				/* translators: 1: cron hook name, 2: execution time in milliseconds */
				__( 'Hook "%1$s" fired in %2$d ms.', 'cronpulse' ),
				esc_html( $hook ),
				$duration
			),
			'duration' => $duration,
		] );
	}

	// -------------------------------------------------------------------------

	public static function handle_unschedule(): void {
		check_ajax_referer( 'cp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'cronpulse' ) ], 403 );
			return;
		}

		$hook      = sanitize_key( wp_unslash( $_POST['hook'] ?? '' ) );
		$timestamp = absint( $_POST['timestamp'] ?? 0 );
		$sig       = sanitize_text_field( wp_unslash( $_POST['sig'] ?? '' ) );

		if ( empty( $hook ) || empty( $timestamp ) || empty( $sig ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid job.', 'cronpulse' ) ] );
			return;
		}

		// Look up the event's own stored args rather than trusting anything
		// reconstructed client-side — wp_unschedule_event() matches on the
		// exact args array, and a JSON round-trip can change scalar types.
		$crons = _get_cron_array();
		if ( empty( $crons[ $timestamp ][ $hook ][ $sig ] ) ) {
			wp_send_json_error( [ 'message' => __( 'That job is no longer scheduled.', 'cronpulse' ) ] );
			return;
		}

		$args   = $crons[ $timestamp ][ $hook ][ $sig ]['args'] ?? [];
		$result = wp_unschedule_event( $timestamp, $hook, $args );

		if ( false === $result || is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not unschedule the job.', 'cronpulse' ) ] );
			return;
		}

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: %s = cron hook name */
				__( 'Unscheduled "%s".', 'cronpulse' ),
				esc_html( $hook )
			),
		] );
	}

	// -------------------------------------------------------------------------

	public static function handle_clear_log(): void {
		check_ajax_referer( 'cp_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'cronpulse' ) ], 403 );
			return;
		}

		CP_Cron_Tracker::clear_log();
		wp_send_json_success( [ 'message' => __( 'Log cleared.', 'cronpulse' ) ] );
	}

	// -------------------------------------------------------------------------

	/**
	 * Return a flat unique list of all hook names in the WP-Cron queue.
	 *
	 * @return string[]
	 */
	private static function get_registered_cron_hooks(): array {
		$crons = _get_cron_array();
		if ( empty( $crons ) || ! is_array( $crons ) ) {
			return [];
		}

		$hooks = [];
		foreach ( $crons as $events ) {
			if ( is_array( $events ) ) {
				foreach ( array_keys( $events ) as $hook ) {
					$hooks[] = $hook;
				}
			}
		}

		return array_unique( $hooks );
	}

	/**
	 * Recursively sanitize an args array received from the client.
	 * Scalar values are cast to string and sanitized; nested arrays are
	 * processed recursively. Non-scalar, non-array values are dropped.
	 *
	 * @param array $args
	 * @return array
	 */
	private static function sanitize_args( array $args ): array {
		$clean = [];
		foreach ( $args as $key => $value ) {
			$clean_key = sanitize_key( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $clean_key ] = self::sanitize_args( $value );
			} elseif ( is_scalar( $value ) ) {
				$clean[ $clean_key ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}
}
