<?php
/**
 * CP_Alerts
 *
 * Tracks consecutive failures and overdue duration per hook, and sends an
 * email/webhook notification once a configured threshold is crossed.
 * Evaluated as a side effect of normal site traffic (init) and of cron
 * runs completing (log_execution), so it works even if no one opens the
 * dashboard and even if WP-Cron itself is disabled.
 */
defined( 'ABSPATH' ) || exit;

class CP_Alerts {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'maybe_save_settings' ] );
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	public static function get_settings(): array {
		$defaults = [
			'enabled'           => false,
			'failure_threshold' => 3,
			'overdue_minutes'   => 30,
			'email'             => '',
			'webhook'           => '',
		];

		$settings = get_option( CP_OPTION_ALERTS, [] );

		return wp_parse_args( is_array( $settings ) ? $settings : [], $defaults );
	}

	public static function maybe_save_settings(): void {
		if ( empty( $_POST['cp_alerts_submit'] ) ) {
			return;
		}

		check_admin_referer( 'cp_save_alerts', 'cp_alerts_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'cronpulse' ) );
		}

		$email = sanitize_email( wp_unslash( $_POST['cp_alert_email'] ?? '' ) );

		update_option( CP_OPTION_ALERTS, [
			'enabled'           => ! empty( $_POST['cp_alert_enabled'] ),
			'failure_threshold' => max( 1, absint( $_POST['cp_failure_threshold'] ?? 3 ) ),
			'overdue_minutes'   => max( 1, absint( $_POST['cp_overdue_minutes'] ?? 30 ) ),
			'email'             => is_email( $email ) ? $email : '',
			'webhook'           => esc_url_raw( wp_unslash( $_POST['cp_alert_webhook'] ?? '' ) ),
		], false );

		$redirect = add_query_arg( 'updated', '1', admin_url( 'tools.php?page=cronpulse' ) ) . '#cp-alerts';
		wp_safe_redirect( $redirect );
		exit;
	}

	// -------------------------------------------------------------------------
	// Evaluation
	// -------------------------------------------------------------------------

	/**
	 * Called on every page load for every scheduled hook. Tracks how long a
	 * hook has been continuously overdue (wall-clock minutes, not a "check"
	 * counter — those would scale with site traffic rather than real time).
	 */
	public static function evaluate_overdue( string $hook, int $next_run ): void {
		$settings = self::get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}

		$streaks = get_option( CP_OPTION_STREAKS, [] );
		$entry   = $streaks[ $hook ] ?? self::default_streak();

		if ( $next_run < time() ) {
			if ( null === $entry['overdue_since'] ) {
				$entry['overdue_since'] = time();
			}

			$minutes_overdue = ( time() - $entry['overdue_since'] ) / 60;

			if ( $minutes_overdue >= $settings['overdue_minutes'] && ! $entry['overdue_alerted'] ) {
				self::notify( $hook, 'overdue', [ 'minutes' => (int) $minutes_overdue ] );
				$entry['overdue_alerted'] = true;
			}
		} else {
			$entry['overdue_since']   = null;
			$entry['overdue_alerted'] = false;
		}

		$streaks[ $hook ] = $entry;
		update_option( CP_OPTION_STREAKS, $streaks, false );
	}

	/**
	 * Called every time a run is logged. Tracks real consecutive failures —
	 * unlike overdue, every call here corresponds to an actual execution.
	 */
	public static function evaluate_failure( string $hook, string $status ): void {
		$settings = self::get_settings();
		if ( ! $settings['enabled'] ) {
			return;
		}

		$streaks = get_option( CP_OPTION_STREAKS, [] );
		$entry   = $streaks[ $hook ] ?? self::default_streak();

		$is_failure = in_array( $status, [ 'fatal', 'incomplete', 'stuck' ], true );

		if ( $is_failure ) {
			$entry['failure_streak']++;

			if ( $entry['failure_streak'] >= $settings['failure_threshold'] && ! $entry['failure_alerted'] ) {
				self::notify( $hook, 'failure', [ 'count' => $entry['failure_streak'], 'status' => $status ] );
				$entry['failure_alerted'] = true;
			}
		} else {
			$entry['failure_streak']  = 0;
			$entry['failure_alerted'] = false;
		}

		$streaks[ $hook ] = $entry;
		update_option( CP_OPTION_STREAKS, $streaks, false );
	}

	private static function default_streak(): array {
		return [
			'overdue_since'   => null,
			'overdue_alerted' => false,
			'failure_streak'  => 0,
			'failure_alerted' => false,
		];
	}

	// -------------------------------------------------------------------------
	// Notification
	// -------------------------------------------------------------------------

	private static function notify( string $hook, string $type, array $context ): void {
		$settings = self::get_settings();
		$site     = wp_parse_url( home_url(), PHP_URL_HOST );
		$dashboard = admin_url( 'tools.php?page=cronpulse' );

		if ( 'overdue' === $type ) {
			$subject = sprintf( '[Cron Pulse] %s is overdue on %s', $hook, $site );
			$body    = sprintf(
				"The cron hook \"%s\" has been overdue for more than %d minutes.\n\nSite: %s\nDashboard: %s",
				$hook,
				$context['minutes'],
				$site,
				$dashboard
			);
		} else {
			$subject = sprintf( '[Cron Pulse] %s is failing on %s', $hook, $site );
			$body    = sprintf(
				"The cron hook \"%s\" has failed %d times in a row (last status: %s).\n\nSite: %s\nDashboard: %s",
				$hook,
				$context['count'],
				$context['status'],
				$site,
				$dashboard
			);
		}

		$email = $settings['email'] ?: get_option( 'admin_email' );
		if ( $email ) {
			wp_mail( $email, $subject, $body );
		}

		if ( ! empty( $settings['webhook'] ) ) {
			wp_remote_post( $settings['webhook'], [
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => [ 'Content-Type' => 'application/json' ],
				'body'     => wp_json_encode( [
					'plugin'  => 'cronpulse',
					'hook'    => $hook,
					'type'    => $type,
					'site'    => home_url(),
					'message' => $body,
				] ),
			] );
		}
	}

	// -------------------------------------------------------------------------
	// Settings UI
	// -------------------------------------------------------------------------

	public static function render_settings_tab(): void {
		$settings = self::get_settings();
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'cp_save_alerts', 'cp_alerts_nonce' ); ?>
			<input type="hidden" name="cp_alerts_submit" value="1" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="cp-alert-enabled"><?php esc_html_e( 'Enable alerts', 'cronpulse' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" id="cp-alert-enabled" name="cp_alert_enabled" value="1" <?php checked( $settings['enabled'] ); ?> />
							<?php esc_html_e( 'Notify me when a job is failing or overdue', 'cronpulse' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cp-failure-threshold"><?php esc_html_e( 'Failure threshold', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="number" min="1" id="cp-failure-threshold" name="cp_failure_threshold" value="<?php echo esc_attr( $settings['failure_threshold'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Alert after this many consecutive failed runs for a job.', 'cronpulse' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cp-overdue-minutes"><?php esc_html_e( 'Overdue threshold (minutes)', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="number" min="1" id="cp-overdue-minutes" name="cp_overdue_minutes" value="<?php echo esc_attr( $settings['overdue_minutes'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Alert when a job has been overdue for longer than this.', 'cronpulse' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cp-alert-email"><?php esc_html_e( 'Notification email', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="email" id="cp-alert-email" name="cp_alert_email" value="<?php echo esc_attr( $settings['email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'cronpulse' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cp-alert-webhook"><?php esc_html_e( 'Webhook URL', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="url" id="cp-alert-webhook" name="cp_alert_webhook" value="<?php echo esc_attr( $settings['webhook'] ); ?>" class="regular-text" placeholder="https://" />
						<p class="description"><?php esc_html_e( 'Optional. Receives a JSON POST for every alert (Slack, Discord, or your own endpoint).', 'cronpulse' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Alert Settings', 'cronpulse' ) ); ?>
		</form>
		<?php
	}
}
