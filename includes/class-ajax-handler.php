<?php
/**
 * CP_Ajax_Handler
 *
 * Handles AJAX requests for:
 *  - cp_run_now   — manually trigger a cron hook
 *  - cp_clear_log — wipe the execution log
 */
defined( 'ABSPATH' ) || exit;

class CP_Ajax_Handler {

	public static function init(): void {
		add_action( 'wp_ajax_cp_run_now',   [ __CLASS__, 'handle_run_now' ] );
		add_action( 'wp_ajax_cp_clear_log', [ __CLASS__, 'handle_clear_log' ] );
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
