<?php
/**
 * CronPulse_Alerts
 *
 * Tracks consecutive failures and overdue duration per hook, and sends an
 * email/webhook notification once a configured threshold is crossed.
 * Evaluated as a side effect of normal site traffic (init) and of cron
 * runs completing (log_execution), so it works even if no one opens the
 * dashboard and even if WP-Cron itself is disabled.
 */
defined( 'ABSPATH' ) || exit;

class CronPulse_Alerts {

	public static function init(): void {
		add_action( 'admin_init', [ __CLASS__, 'maybe_save_settings' ] );
		add_action( 'phpmailer_init', [ __CLASS__, 'configure_smtp' ] );
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
			'log_retention'     => CRONPULSE_LOG_LIMIT,
			'per_job'           => [],
			'smtp_enabled'      => false,
			'smtp_host'         => '',
			'smtp_port'         => 587,
			'smtp_encryption'   => 'tls', // 'none' | 'ssl' | 'tls'
			'smtp_username'     => '',
			'smtp_password'     => '',
			'smtp_from_email'   => '',
			'smtp_from_name'    => '',
		];

		$settings = get_option( CRONPULSE_OPTION_ALERTS, [] );

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
		if ( empty( $_POST['cronpulse_alerts_submit'] ) ) {
			return;
		}

		check_admin_referer( 'cronpulse_save_alerts', 'cronpulse_alerts_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'cronpulse' ) );
		}

		$email     = sanitize_email( wp_unslash( $_POST['cronpulse_alert_email'] ?? '' ) );
		$from_email = sanitize_email( wp_unslash( $_POST['cronpulse_smtp_from_email'] ?? '' ) );

		// Password field is left blank in the form on every page load (see
		// render_settings_tab()) so it never round-trips through HTML source.
		// An empty submission means "unchanged", not "clear it".
		$existing_password = self::get_settings()['smtp_password'];
		$submitted_password = wp_unslash( $_POST['cronpulse_smtp_password'] ?? '' );
		$password            = '' === $submitted_password ? $existing_password : sanitize_text_field( $submitted_password );

		$encryption = sanitize_key( wp_unslash( $_POST['cronpulse_smtp_encryption'] ?? 'tls' ) );
		if ( ! in_array( $encryption, [ 'none', 'ssl', 'tls' ], true ) ) {
			$encryption = 'tls';
		}

		update_option( CRONPULSE_OPTION_ALERTS, [
			'enabled'           => ! empty( $_POST['cronpulse_alert_enabled'] ),
			'failure_threshold' => max( 1, absint( $_POST['cronpulse_failure_threshold'] ?? 3 ) ),
			'overdue_minutes'   => max( 1, absint( $_POST['cronpulse_overdue_minutes'] ?? 30 ) ),
			'email'             => is_email( $email ) ? $email : '',
			'webhook'           => esc_url_raw( wp_unslash( $_POST['cronpulse_alert_webhook'] ?? '' ) ),
			'log_retention'     => min( 5000, max( 10, absint( $_POST['cronpulse_log_retention'] ?? CRONPULSE_LOG_LIMIT ) ) ),
			'per_job'           => self::parse_overrides_from_post(),
			'smtp_enabled'      => ! empty( $_POST['cronpulse_smtp_enabled'] ),
			'smtp_host'         => sanitize_text_field( wp_unslash( $_POST['cronpulse_smtp_host'] ?? '' ) ),
			'smtp_port'         => max( 1, min( 65535, absint( $_POST['cronpulse_smtp_port'] ?? 587 ) ) ),
			'smtp_encryption'   => $encryption,
			'smtp_username'     => sanitize_text_field( wp_unslash( $_POST['cronpulse_smtp_username'] ?? '' ) ),
			'smtp_password'     => $password,
			'smtp_from_email'   => is_email( $from_email ) ? $from_email : '',
			'smtp_from_name'    => sanitize_text_field( wp_unslash( $_POST['cronpulse_smtp_from_name'] ?? '' ) ),
		], false );

		$redirect = add_query_arg( 'updated', '1', admin_url( 'tools.php?page=cronpulse' ) ) . '#cp-alerts';
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Rebuild the per-job override map from the settings form's parallel
	 * arrays (existing rows) plus the single "add new" row, if filled in.
	 *
	 * Nonce verification happens in maybe_save_settings() before this is ever
	 * called — phpcs can't see across the function boundary, hence the
	 * disable block below rather than a duplicate check here.
	 */
	private static function parse_overrides_from_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$per_job = [];

		$hooks    = isset( $_POST['cronpulse_override_hook'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['cronpulse_override_hook'] ) ) : [];
		$failures = isset( $_POST['cronpulse_override_failure'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['cronpulse_override_failure'] ) ) : [];
		$overdues = isset( $_POST['cronpulse_override_overdue'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['cronpulse_override_overdue'] ) ) : [];
		$removed  = isset( $_POST['cronpulse_override_remove'] ) ? array_flip( array_map( 'absint', wp_unslash( (array) $_POST['cronpulse_override_remove'] ) ) ) : [];

		foreach ( $hooks as $i => $hook ) {
			if ( isset( $removed[ $i ] ) || empty( $hook ) ) {
				continue;
			}

			$per_job[ $hook ] = [
				'failure_threshold' => max( 1, $failures[ $i ] ?? 3 ),
				'overdue_minutes'   => max( 1, $overdues[ $i ] ?? 30 ),
			];
		}

		$new_hook = isset( $_POST['cronpulse_new_override_hook'] ) ? sanitize_key( wp_unslash( $_POST['cronpulse_new_override_hook'] ) ) : '';
		if ( ! empty( $new_hook ) ) {
			$per_job[ $new_hook ] = [
				'failure_threshold' => max( 1, absint( $_POST['cronpulse_new_override_failure'] ?? 3 ) ),
				'overdue_minutes'   => max( 1, absint( $_POST['cronpulse_new_override_overdue'] ?? 30 ) ),
			];
		}

		return $per_job;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Routes wp_mail() through user-supplied SMTP credentials instead of the
	 * server's default PHP mail()/sendmail — no third-party plugin involved,
	 * just configuring the PHPMailer instance WordPress already bundles.
	 *
	 * @param PHPMailer $phpmailer Passed by reference by the phpmailer_init action.
	 */
	public static function configure_smtp( $phpmailer ): void {
		$settings = self::get_settings();

		if ( empty( $settings['smtp_enabled'] ) || empty( $settings['smtp_host'] ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host     = $settings['smtp_host'];
		$phpmailer->Port     = $settings['smtp_port'];
		$phpmailer->SMTPAuth = ! empty( $settings['smtp_username'] );
		$phpmailer->Username = $settings['smtp_username'];
		$phpmailer->Password = $settings['smtp_password'];

		if ( 'none' === $settings['smtp_encryption'] ) {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPSecure = $settings['smtp_encryption']; // 'ssl' or 'tls'
		}

		if ( ! empty( $settings['smtp_from_email'] ) ) {
			$phpmailer->setFrom( $settings['smtp_from_email'], $settings['smtp_from_name'] ?: get_bloginfo( 'name' ) );
		}
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

		$streaks = get_option( CRONPULSE_OPTION_STREAKS, [] );
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
		update_option( CRONPULSE_OPTION_STREAKS, $streaks, false );
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

		$streaks = get_option( CRONPULSE_OPTION_STREAKS, [] );
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
		update_option( CRONPULSE_OPTION_STREAKS, $streaks, false );
	}

	/**
	 * Acknowledge the current incident for a hook without disabling alerts
	 * globally — reuses the existing *_alerted guards, so no new notification
	 * fires until this streak clears and a fresh one starts.
	 */
	public static function snooze( string $hook ): void {
		$streaks = get_option( CRONPULSE_OPTION_STREAKS, [] );
		$entry   = $streaks[ $hook ] ?? self::default_streak();

		$entry['failure_alerted'] = true;
		$entry['overdue_alerted'] = true;

		$streaks[ $hook ] = $entry;
		update_option( CRONPULSE_OPTION_STREAKS, $streaks, false );
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
			self::send_and_log( $email, $subject, $body, $type );
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

	/**
	 * Number of email log entries to keep. Not user-configurable — email
	 * volume is naturally much lower than cron-execution volume, so a fixed
	 * cap is enough; no need to duplicate the log_retention setting for it.
	 */
	private const EMAIL_LOG_LIMIT = 50;

	/**
	 * Send via wp_mail() and record the outcome (including the real PHPMailer
	 * error on failure, captured via wp_mail_failed) in the email log.
	 */
	public static function send_and_log( string $to, string $subject, string $body, string $type = 'alert' ): bool {
		$error   = null;
		$capture = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error;
		};

		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( $to, $subject, $body );
		remove_action( 'wp_mail_failed', $capture );

		self::log_email( $to, $subject, $type, $sent ? 'sent' : 'failed', $error ? $error->get_error_message() : null );

		return $sent;
	}

	private static function log_email( string $to, string $subject, string $type, string $status, ?string $error = null ): void {
		$log = get_option( CRONPULSE_OPTION_EMAIL_LOG, [] );

		if ( ! is_array( $log ) ) {
			$log = [];
		}

		array_unshift( $log, [
			'to'        => $to,
			'subject'   => $subject,
			'type'      => $type,
			'status'    => $status,
			'error'     => $error,
			'timestamp' => time(),
		] );

		if ( count( $log ) > self::EMAIL_LOG_LIMIT ) {
			$log = array_slice( $log, 0, self::EMAIL_LOG_LIMIT );
		}

		update_option( CRONPULSE_OPTION_EMAIL_LOG, $log, false );
	}

	/**
	 * Return all email log entries (newest first).
	 */
	public static function get_email_log(): array {
		$log = get_option( CRONPULSE_OPTION_EMAIL_LOG, [] );
		return is_array( $log ) ? $log : [];
	}

	public static function clear_email_log(): void {
		update_option( CRONPULSE_OPTION_EMAIL_LOG, [], false );
	}

	// -------------------------------------------------------------------------
	// Settings UI
	// -------------------------------------------------------------------------

	private static function render_overrides_table( array $per_job ): void {
		$all_hooks = array_values( array_unique( array_map(
			static function ( $job ) {
				return $job['hook'];
			},
			CronPulse_Admin_Page::get_jobs()
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
						<input type="hidden" name="cronpulse_override_hook[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $hook ); ?>" />
					</td>
					<td><input type="number" min="1" name="cronpulse_override_failure[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $override['failure_threshold'] ); ?>" class="small-text" /></td>
					<td><input type="number" min="1" name="cronpulse_override_overdue[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $override['overdue_minutes'] ); ?>" class="small-text" /></td>
					<td><input type="checkbox" name="cronpulse_override_remove[]" value="<?php echo esc_attr( $i ); ?>" /></td>
				</tr>
			<?php $i++; endforeach; ?>
				<tr>
					<td>
						<select name="cronpulse_new_override_hook">
							<option value=""><?php esc_html_e( '— Add a hook —', 'cronpulse' ); ?></option>
							<?php foreach ( $available as $hook ) : ?>
								<option value="<?php echo esc_attr( $hook ); ?>"><?php echo esc_html( $hook ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><input type="number" min="1" name="cronpulse_new_override_failure" value="3" class="small-text" /></td>
					<td><input type="number" min="1" name="cronpulse_new_override_overdue" value="30" class="small-text" /></td>
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
			<?php wp_nonce_field( 'cronpulse_save_alerts', 'cronpulse_alerts_nonce' ); ?>
			<input type="hidden" name="cronpulse_alerts_submit" value="1" />

			<h2><?php esc_html_e( 'Alerts', 'cronpulse' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="cronpulse-alert-enabled"><?php esc_html_e( 'Enable alerts', 'cronpulse' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" id="cronpulse-alert-enabled" name="cronpulse_alert_enabled" value="1" <?php checked( $settings['enabled'] ); ?> />
							<?php esc_html_e( 'Notify me when a job is failing or overdue', 'cronpulse' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cronpulse-failure-threshold"><?php esc_html_e( 'Failure threshold', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="number" min="1" id="cronpulse-failure-threshold" name="cronpulse_failure_threshold" value="<?php echo esc_attr( $settings['failure_threshold'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Alert after this many consecutive failed runs for a job.', 'cronpulse' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cronpulse-overdue-minutes"><?php esc_html_e( 'Overdue threshold (minutes)', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="number" min="1" id="cronpulse-overdue-minutes" name="cronpulse_overdue_minutes" value="<?php echo esc_attr( $settings['overdue_minutes'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Alert when a job has been overdue for longer than this.', 'cronpulse' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cronpulse-alert-email"><?php esc_html_e( 'Notification email', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="email" id="cronpulse-alert-email" name="cronpulse_alert_email" value="<?php echo esc_attr( $settings['email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'cronpulse' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cronpulse-alert-webhook"><?php esc_html_e( 'Webhook URL', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="url" id="cronpulse-alert-webhook" name="cronpulse_alert_webhook" value="<?php echo esc_attr( $settings['webhook'] ); ?>" class="regular-text" placeholder="https://" />
						<button type="button" class="button cp-test-webhook"><?php esc_html_e( 'Send Test Webhook', 'cronpulse' ); ?></button>
						<p class="description"><?php esc_html_e( 'Optional. Receives a JSON POST for every alert (Slack, Discord, or your own endpoint). Save settings first, then use the button to test the saved URL.', 'cronpulse' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Email Delivery (SMTP)', 'cronpulse' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Without this, alert emails go through the server\'s default mail() function, which many hosts either block or send unreliably. Configuring SMTP here routes through your own mail provider — no separate SMTP plugin needed.', 'cronpulse' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="cronpulse-smtp-enabled"><?php esc_html_e( 'Use SMTP', 'cronpulse' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" id="cronpulse-smtp-enabled" name="cronpulse_smtp_enabled" value="1" <?php checked( $settings['smtp_enabled'] ); ?> />
							<?php esc_html_e( 'Send Cron Pulse emails through the SMTP server below instead of the default mail() function', 'cronpulse' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cronpulse-smtp-host"><?php esc_html_e( 'SMTP Host', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="text" id="cronpulse-smtp-host" name="cronpulse_smtp_host" value="<?php echo esc_attr( $settings['smtp_host'] ); ?>" class="regular-text" placeholder="smtp.example.com" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cronpulse-smtp-port"><?php esc_html_e( 'SMTP Port', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="number" min="1" max="65535" id="cronpulse-smtp-port" name="cronpulse_smtp_port" value="<?php echo esc_attr( $settings['smtp_port'] ); ?>" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cronpulse-smtp-encryption"><?php esc_html_e( 'Encryption', 'cronpulse' ); ?></label>
					</th>
					<td>
						<select id="cronpulse-smtp-encryption" name="cronpulse_smtp_encryption">
							<option value="tls" <?php selected( $settings['smtp_encryption'], 'tls' ); ?>>TLS</option>
							<option value="ssl" <?php selected( $settings['smtp_encryption'], 'ssl' ); ?>>SSL</option>
							<option value="none" <?php selected( $settings['smtp_encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'cronpulse' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'TLS on port 587 is the most common modern setup.', 'cronpulse' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cronpulse-smtp-username"><?php esc_html_e( 'SMTP Username', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="text" id="cronpulse-smtp-username" name="cronpulse_smtp_username" value="<?php echo esc_attr( $settings['smtp_username'] ); ?>" class="regular-text" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cronpulse-smtp-password"><?php esc_html_e( 'SMTP Password', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="password" id="cronpulse-smtp-password" name="cronpulse_smtp_password" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo $settings['smtp_password'] ? esc_attr__( '•••••••• (leave blank to keep)', 'cronpulse' ) : ''; ?>" />
						<p class="description"><?php esc_html_e( 'Left blank on every page load for security. Leave it blank when saving to keep the existing password.', 'cronpulse' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cronpulse-smtp-from-email"><?php esc_html_e( 'From Email', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="email" id="cronpulse-smtp-from-email" name="cronpulse_smtp_from_email" value="<?php echo esc_attr( $settings['smtp_from_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Optional. Many SMTP providers require this to match an address they\'ve verified.', 'cronpulse' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="cronpulse-smtp-from-name"><?php esc_html_e( 'From Name', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="text" id="cronpulse-smtp-from-name" name="cronpulse_smtp_from_name" value="<?php echo esc_attr( $settings['smtp_from_name'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Test', 'cronpulse' ); ?></th>
					<td>
						<button type="button" class="button cp-test-email"><?php esc_html_e( 'Send Test Email', 'cronpulse' ); ?></button>
						<p class="description"><?php esc_html_e( 'Save settings first, then use this to confirm delivery actually works. Sent to the notification email above (or the site admin email).', 'cronpulse' ); ?></p>
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
						<label for="cronpulse-log-retention"><?php esc_html_e( 'Log retention', 'cronpulse' ); ?></label>
					</th>
					<td>
						<input type="number" min="10" max="5000" id="cronpulse-log-retention" name="cronpulse_log_retention" value="<?php echo esc_attr( $settings['log_retention'] ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Number of execution log entries to keep (10–5000).', 'cronpulse' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'cronpulse' ) ); ?>
		</form>
		<?php
	}
}
