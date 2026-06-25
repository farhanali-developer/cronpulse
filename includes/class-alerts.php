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
			'log_retention'     => CP_LOG_LIMIT,
			'per_job'           => [],
		];

		$settings = get_option( CP_OPTION_ALERTS, [] );

		return wp_parse_args( is_array( $settings ) ? $settings : [], $defaults );
	}

	/**
	 * Resolve the effective thresholds for a hook: its own override, if one
	 * is configured, otherwise the global defaults.
	 */
	public static function get_thresholds_for( string $hook ): array {
		$settings = self::get_settings();
		$override = $settings['per_job'][ $hook ] ?? [];

		return [
			'failure_threshold' => $override['failure_threshold'] ?? $settings['failure_threshold'],
			'overdue_minutes'   => $override['overdue_minutes'] ?? $settings['overdue_minutes'],
		];
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
			'log_retention'     => min( 5000, max( 10, absint( $_POST['cp_log_retention'] ?? CP_LOG_LIMIT ) ) ),
			'per_job'           => self::parse_overrides_from_post(),
		], false );

		$redirect = add_query_arg( 'updated', '1', admin_url( 'tools.php?page=cronpulse' ) ) . '#cp-alerts';
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Rebuild the per-job override map from the settings form's parallel
	 * arrays (existing rows) plus the single "add new" row, if filled in.
	 */
	private static function parse_overrides_from_post(): array {
		$per_job = [];

		$hooks      = (array) ( $_POST['cp_override_hook'] ?? [] );
		$failures   = (array) ( $_POST['cp_override_failure'] ?? [] );
		$overdues   = (array) ( $_POST['cp_override_overdue'] ?? [] );
		$removed    = array_flip( (array) ( $_POST['cp_override_remove'] ?? [] ) );

		foreach ( $hooks as $i => $raw_hook ) {
			if ( isset( $removed[ $i ] ) ) {
				continue;
			}

			$hook = sanitize_key( wp_unslash( $raw_hook ) );
			if ( empty( $hook ) ) {
				continue;
			}

			$per_job[ $hook ] = [
				'failure_threshold' => max( 1, absint( $failures[ $i ] ?? 3 ) ),
				'overdue_minutes'   => max( 1, absint( $overdues[ $i ] ?? 30 ) ),
			];
		}

		$new_hook = sanitize_key( wp_unslash( $_POST['cp_new_override_hook'] ?? '' ) );
		if ( ! empty( $new_hook ) ) {
			$per_job[ $new_hook ] = [
				'failure_threshold' => max( 1, absint( $_POST['cp_new_override_failure'] ?? 3 ) ),
				'overdue_minutes'   => max( 1, absint( $_POST['cp_new_override_overdue'] ?? 30 ) ),
			];
		}

		return $per_job;
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

		$threshold = self::get_thresholds_for( $hook )['overdue_minutes'];

		$streaks = get_option( CP_OPTION_STREAKS, [] );
		$entry   = $streaks[ $hook ] ?? self::default_streak();

		if ( $next_run < time() ) {
			if ( null === $entry['overdue_since'] ) {
				$entry['overdue_since'] = time();
			}

			$minutes_overdue = ( time() - $entry['overdue_since'] ) / 60;

			if ( $minutes_overdue >= $threshold && ! $entry['overdue_alerted'] ) {
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

		$threshold = self::get_thresholds_for( $hook )['failure_threshold'];

		$streaks = get_option( CP_OPTION_STREAKS, [] );
		$entry   = $streaks[ $hook ] ?? self::default_streak();

		$is_failure = in_array( $status, [ 'fatal', 'incomplete', 'stuck' ], true );

		if ( $is_failure ) {
			$entry['failure_streak']++;

			if ( $entry['failure_streak'] >= $threshold && ! $entry['failure_alerted'] ) {
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

	/**
	 * Acknowledge the current incident for a hook without disabling alerts
	 * globally — reuses the existing *_alerted guards, so no new notification
	 * fires until this streak clears and a fresh one starts.
	 */
	public static function snooze( string $hook ): void {
		$streaks = get_option( CP_OPTION_STREAKS, [] );
		$entry   = $streaks[ $hook ] ?? self::default_streak();

		$entry['failure_alerted'] = true;
		$entry['overdue_alerted'] = true;

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

	private static function render_overrides_table( array $per_job ): void {
		$all_hooks = array_values( array_unique( array_map(
			static function ( $job ) {
				return $job['hook'];
			},
			CP_Admin_Page::get_jobs()
		) ) );
		$available = array_values( array_diff( $all_hooks, array_keys( $per_job ) ) );
		?>
		<table class="wp-list-table widefat striped" style="max-width:700px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Hook', 'cronpulse' ); ?></th>
					<th><?php esc_html_e( 'Failure threshold', 'cronpulse' ); ?></th>
					<th><?php esc_html_e( 'Overdue minutes', 'cronpulse' ); ?></th>
					<th><?php esc_html_e( 'Remove', 'cronpulse' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $per_job ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No overrides configured — all jobs use the global thresholds above.', 'cronpulse' ); ?></td></tr>
			<?php endif; ?>
			<?php $i = 0; foreach ( $per_job as $hook => $override ) : ?>
				<tr>
					<td>
						<code><?php echo esc_html( $hook ); ?></code>
						<input type="hidden" name="cp_override_hook[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $hook ); ?>" />
					</td>
					<td><input type="number" min="1" name="cp_override_failure[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $override['failure_threshold'] ); ?>" class="small-text" /></td>
					<td><input type="number" min="1" name="cp_override_overdue[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $override['overdue_minutes'] ); ?>" class="small-text" /></td>
					<td><input type="checkbox" name="cp_override_remove[]" value="<?php echo esc_attr( $i ); ?>" /></td>
				</tr>
			<?php $i++; endforeach; ?>
				<tr>
					<td>
						<select name="cp_new_override_hook">
							<option value=""><?php esc_html_e( '— Add a hook —', 'cronpulse' ); ?></option>
							<?php foreach ( $available as $hook ) : ?>
								<option value="<?php echo esc_attr( $hook ); ?>"><?php echo esc_html( $hook ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><input type="number" min="1" name="cp_new_override_failure" value="3" class="small-text" /></td>
					<td><input type="number" min="1" name="cp_new_override_overdue" value="30" class="small-text" /></td>
					<td>—</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public static function render_settings_tab(): void {
		$settings = self::get_settings();
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'cp_save_alerts', 'cp_alerts_nonce' ); ?>
			<input type="hidden" name="cp_alerts_submit" value="1" />

			<h2><?php esc_html_e( 'Alerts', 'cronpulse' ); ?></h2>
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

			<h2><?php esc_html_e( 'Per-Job Overrides', 'cronpulse' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Override the failure/overdue thresholds above for specific hooks — e.g. a tighter threshold for a payment hook than for a daily cleanup job.', 'cronpulse' ); ?></p>
			<?php self::render_overrides_table( $settings['per_job'] ); ?>

			<h2><?php esc_html_e( 'General', 'cronpulse' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="cp-log-retention"><?php esc_html_e( 'Log retention', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="number" min="10" max="5000" id="cp-log-retention" name="cp_log_retention" value="<?php echo esc_attr( $settings['log_retention'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Number of execution log entries to keep (10–5000).', 'cronpulse' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'cronpulse' ) ); ?>
		</form>
		<?php
	}
}
