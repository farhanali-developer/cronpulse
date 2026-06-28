<?php
/**
 * CronPulse_Admin_Page
 *
 * Registers the wp-admin menu page and renders the cron dashboard.
 */
defined( 'ABSPATH' ) || exit;

class CronPulse_Admin_Page {

	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	public static function register_menu(): void {
		add_management_page(
			__( 'Cron Pulse', 'cronpulse' ),
			__( 'Cron Pulse', 'cronpulse' ),
			'manage_options',
			'cronpulse',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'tools_page_cronpulse' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'cronpulse-admin',
			CRONPULSE_PLUGIN_URL . 'assets/admin.css',
			[],
			CRONPULSE_VERSION
		);
		wp_enqueue_script(
			'cronpulse-admin',
			CRONPULSE_PLUGIN_URL . 'assets/admin.js',
			[ 'jquery' ],
			CRONPULSE_VERSION,
			true
		);
		wp_localize_script( 'cronpulse-admin', 'cronpulseData', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cronpulse_nonce' ),
			'i18n'    => [
				'running'           => __( 'Running…', 'cronpulse' ),
				'success'           => __( 'Triggered successfully', 'cronpulse' ),
				'error'             => __( 'Error triggering job', 'cronpulse' ),
				'runNow'            => __( 'Run Now', 'cronpulse' ),
				'justNow'           => __( 'Just now', 'cronpulse' ),
				'confirmClear'      => __( 'Clear the entire execution log? This cannot be undone.', 'cronpulse' ),
				/* translators: %s = cron hook name */
				'confirmUnschedule' => __( 'Unschedule "%s"? If something else re-schedules it, it may come back.', 'cronpulse' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'cronpulse' ) );
		}

		$jobs           = self::get_jobs();
		$log            = CronPulse_Cron_Tracker::get_log();
		$email_log      = CronPulse_Alerts::get_email_log();
		$schedules      = wp_get_schedules();
		$alerts_enabled = CronPulse_Alerts::get_settings()['enabled'];

		// Summary counts
		$total   = count( $jobs );
		$overdue = 0;
		$failing = 0;
		$healthy = 0;
		$never   = 0;
		foreach ( $jobs as $job ) {
			if ( $job['status'] === 'overdue' ) {
				$overdue++;
			} elseif ( $job['status'] === 'failing' ) {
				$failing++;
			} elseif ( $job['status'] === 'healthy' ) {
				$healthy++;
			} else {
				$never++;
			}
		}
		?>
		<div class="wrap cp-wrap">
			<h1 class="cp-title">
				<span class="dashicons dashicons-clock"></span>
				<?php esc_html_e( 'Cron Pulse', 'cronpulse' ); ?>
			</h1>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag that toggles a static notice; nothing is processed or saved here. The actual save in CronPulse_Alerts::maybe_save_settings() is nonce-verified.
			if ( isset( $_GET['updated'] ) ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'cronpulse' ); ?></p></div>
			<?php endif; ?>

			<!-- Summary bar -->
			<div class="cp-summary-bar">
				<div class="cp-summary-card cp-total">
					<span class="cp-num"><?php echo esc_html( $total ); ?></span>
					<span class="cp-label"><?php esc_html_e( 'Total Jobs', 'cronpulse' ); ?></span>
				</div>
				<div class="cp-summary-card cp-healthy">
					<span class="cp-num"><?php echo esc_html( $healthy ); ?></span>
					<span class="cp-label"><?php esc_html_e( 'Healthy', 'cronpulse' ); ?></span>
				</div>
				<div class="cp-summary-card cp-overdue">
					<span class="cp-num"><?php echo esc_html( $overdue ); ?></span>
					<span class="cp-label"><?php esc_html_e( 'Overdue', 'cronpulse' ); ?></span>
				</div>
				<div class="cp-summary-card cp-failing">
					<span class="cp-num"><?php echo esc_html( $failing ); ?></span>
					<span class="cp-label"><?php esc_html_e( 'Failing', 'cronpulse' ); ?></span>
				</div>
				<div class="cp-summary-card cp-never">
					<span class="cp-num"><?php echo esc_html( $never ); ?></span>
					<span class="cp-label"><?php esc_html_e( 'Never Run', 'cronpulse' ); ?></span>
				</div>
				<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<div class="cp-notice-inline cp-warn">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'DISABLE_WP_CRON is set — crons will not fire automatically.', 'cronpulse' ); ?>
				</div>
				<?php endif; ?>
			</div>

			<!-- Tabs -->
			<nav class="cp-tabs">
				<a href="#cp-jobs" class="cp-tab cp-tab-active" data-tab="jobs">
					<?php esc_html_e( 'Scheduled Jobs', 'cronpulse' ); ?>
				</a>
				<a href="#cp-log" class="cp-tab" data-tab="log">
					<?php esc_html_e( 'Execution Log', 'cronpulse' ); ?>
					<?php if ( ! empty( $log ) ) : ?>
						<span class="cp-badge"><?php echo esc_html( count( $log ) ); ?></span>
					<?php endif; ?>
				</a>
				<a href="#cp-email-log" class="cp-tab" data-tab="email-log">
					<?php esc_html_e( 'Email Log', 'cronpulse' ); ?>
					<?php if ( ! empty( $email_log ) ) : ?>
						<span class="cp-badge"><?php echo esc_html( count( $email_log ) ); ?></span>
					<?php endif; ?>
				</a>
				<a href="#cp-alerts" class="cp-tab" data-tab="alerts">
					<?php esc_html_e( 'Settings', 'cronpulse' ); ?>
				</a>
			</nav>

			<!-- Jobs tab -->
			<div id="cp-jobs" class="cp-tab-panel">
				<?php if ( empty( $jobs ) ) : ?>
					<p class="cp-empty"><?php esc_html_e( 'No scheduled cron jobs found.', 'cronpulse' ); ?></p>
				<?php else : ?>
				<div class="cp-search-bar">
					<input type="text" id="cp-search" placeholder="<?php esc_attr_e( 'Filter by hook name…', 'cronpulse' ); ?>" />
					<select id="cp-status-filter">
						<option value=""><?php esc_html_e( 'All statuses', 'cronpulse' ); ?></option>
						<option value="overdue"><?php esc_html_e( 'Overdue', 'cronpulse' ); ?></option>
						<option value="failing"><?php esc_html_e( 'Failing', 'cronpulse' ); ?></option>
						<option value="healthy"><?php esc_html_e( 'Healthy', 'cronpulse' ); ?></option>
						<option value="pending"><?php esc_html_e( 'Never Run', 'cronpulse' ); ?></option>
					</select>
				</div>
				<table class="cp-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Status',   'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Hook',     'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Schedule', 'cronpulse' ); ?></th>
							<th class="cp-sortable" data-sort="next-run"><?php esc_html_e( 'Next Run', 'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Last Run', 'cronpulse' ); ?></th>
							<th class="cp-sortable" data-sort="duration"><?php esc_html_e( 'Duration', 'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Actions',  'cronpulse' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $jobs as $job ) :
						$schedule_label = isset( $schedules[ $job['schedule'] ] )
							? $schedules[ $job['schedule'] ]['display']
							: ( $job['schedule'] ?: '—' );
						$last_run    = CronPulse_Cron_Tracker::get_last_run( $job['hook'] );
						$duration_ms = isset( $last_run['duration'] ) ? (int) $last_run['duration'] : -1;
						$duration    = $duration_ms >= 0 ? $duration_ms . ' ms' : '—';
						$sparkline   = self::render_sparkline( CronPulse_Cron_Tracker::get_recent_durations( $job['hook'], 10 ) );
						$needs_alert_action = $alerts_enabled && in_array( $job['status'], [ 'overdue', 'failing' ], true );
					?>
					<tr
						class="cp-row cp-status-<?php echo esc_attr( $job['status'] ); ?>"
						data-hook="<?php echo esc_attr( $job['hook'] ); ?>"
						data-status="<?php echo esc_attr( $job['status'] ); ?>"
						data-next-run="<?php echo esc_attr( $job['next_run'] ); ?>"
						data-duration="<?php echo esc_attr( $duration_ms ); ?>"
					>
						<td>
							<span class="cp-dot cp-dot-<?php echo esc_attr( $job['status'] ); ?>" title="<?php echo esc_attr( ucfirst( $job['status'] ) ); ?>"></span>
							<span class="cp-status-text"><?php echo esc_html( ucfirst( $job['status'] ) ); ?></span>
						</td>
						<td class="cp-hook">
							<code><?php echo esc_html( $job['hook'] ); ?></code>
							<?php if ( ! empty( $job['args'] ) ) : ?>
								<span class="cp-args" title="<?php echo esc_attr( wp_json_encode( $job['args'] ) ); ?>">+args</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $schedule_label ); ?></td>
						<td class="cp-next-run" data-timestamp="<?php echo esc_attr( $job['next_run'] ); ?>">
							<?php echo esc_html( self::format_time( (int) $job['next_run'] ) ); ?>
						</td>
						<td>
							<?php if ( $last_run ) :
								echo esc_html( self::format_time( (int) $last_run['timestamp'] ) );
							else : ?>
								<span class="cp-muted"><?php esc_html_e( 'Never', 'cronpulse' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<span class="cp-duration-text"><?php echo esc_html( $duration ); ?></span>
							<?php echo $sparkline; // phpcs:ignore WordPress.Security.EscapeOutput -- built entirely from numeric duration data above ?>
						</td>
						<td>
							<button
								class="button button-small cp-run-now"
								data-hook="<?php echo esc_attr( $job['hook'] ); ?>"
								data-args="<?php echo esc_attr( wp_json_encode( $job['args'] ) ); ?>"
							><?php esc_html_e( 'Run Now', 'cronpulse' ); ?></button>
							<button
								class="button button-small button-link-delete cp-unschedule"
								data-hook="<?php echo esc_attr( $job['hook'] ); ?>"
								data-timestamp="<?php echo esc_attr( $job['next_run'] ); ?>"
								data-sig="<?php echo esc_attr( $job['sig'] ); ?>"
							><?php esc_html_e( 'Delete', 'cronpulse' ); ?></button>
							<?php if ( $needs_alert_action ) : ?>
								<button
									class="button button-small cp-snooze"
									data-hook="<?php echo esc_attr( $job['hook'] ); ?>"
								><?php esc_html_e( 'Snooze', 'cronpulse' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div id="cp-pagination" class="cp-pagination" style="display:none;">
					<button type="button" id="cp-prev-page" class="button button-small">‹ <?php esc_html_e( 'Previous', 'cronpulse' ); ?></button>
					<span id="cp-page-info"></span>
					<button type="button" id="cp-next-page" class="button button-small"><?php esc_html_e( 'Next', 'cronpulse' ); ?> ›</button>
				</div>
				<?php endif; ?>
			</div>

			<!-- Log tab -->
			<div id="cp-log" class="cp-tab-panel" style="display:none;">
				<?php if ( empty( $log ) ) : ?>
					<p class="cp-empty"><?php esc_html_e( 'No execution history yet. Run a cron job to start recording.', 'cronpulse' ); ?></p>
				<?php else : ?>
				<div class="cp-log-toolbar">
					<button class="button cp-clear-log"><?php esc_html_e( 'Clear Log', 'cronpulse' ); ?></button>
					<span class="cp-log-count">
						<?php
						echo esc_html( sprintf(
							/* translators: 1: number of log entries stored, 2: configured retention limit */
							__( '%1$d entries (newest first, max %2$d)', 'cronpulse' ),
							count( $log ),
							CronPulse_Alerts::get_settings()['log_retention']
						) );
						?>
					</span>
				</div>
				<table class="cp-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Status',   'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Hook',     'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Ran At',   'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'cronpulse' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $log as $entry ) :
						if ( ! is_array( $entry )
							|| empty( $entry['hook'] )
							|| empty( $entry['status'] )
							|| empty( $entry['timestamp'] )
						) {
							continue;
						}
						$entry_status    = sanitize_key( $entry['status'] );
						$entry_hook      = (string) $entry['hook'];
						$entry_timestamp = (int) $entry['timestamp'];
						$entry_duration  = isset( $entry['duration'] ) ? absint( $entry['duration'] ) . ' ms' : '—';
						$entry_message   = isset( $entry['message'] ) ? (string) $entry['message'] : '';
					?>
					<tr class="cp-row cp-status-<?php echo esc_attr( $entry_status ); ?>">
						<td>
							<span class="cp-dot cp-dot-<?php echo esc_attr( $entry_status ); ?>" title="<?php echo esc_attr( $entry_message ); ?>"></span>
							<span class="cp-status-text"><?php echo esc_html( ucfirst( $entry_status ) ); ?></span>
						</td>
						<td><code><?php echo esc_html( $entry_hook ); ?></code></td>
						<td><?php echo esc_html( self::format_time( $entry_timestamp ) ); ?></td>
						<td><?php echo esc_html( $entry_duration ); ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>

			<!-- Email Log tab -->
			<div id="cp-email-log" class="cp-tab-panel" style="display:none;">
				<div class="cp-email-log-section">
				<?php if ( empty( $email_log ) ) : ?>
					<p class="cp-empty"><?php esc_html_e( 'No emails sent yet. Alert emails (and test emails) will show up here.', 'cronpulse' ); ?></p>
				<?php else : ?>
				<div class="cp-log-toolbar">
					<button class="button cp-clear-email-log"><?php esc_html_e( 'Clear Email Log', 'cronpulse' ); ?></button>
					<span class="cp-log-count">
						<?php
						echo esc_html( sprintf(
							/* translators: %d = number of email log entries stored */
							__( '%d entries (newest first, max 50)', 'cronpulse' ),
							count( $email_log )
						) );
						?>
					</span>
				</div>
				<table class="cp-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Status',  'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'To',      'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Type',    'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Sent At', 'cronpulse' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $email_log as $entry ) :
						if ( ! is_array( $entry ) || empty( $entry['to'] ) || empty( $entry['timestamp'] ) ) {
							continue;
						}
						$entry_status  = isset( $entry['status'] ) ? sanitize_key( $entry['status'] ) : 'failed';
						$entry_to      = (string) $entry['to'];
						$entry_subject = isset( $entry['subject'] ) ? (string) $entry['subject'] : '';
						$entry_type    = isset( $entry['type'] ) ? (string) $entry['type'] : '';
						$entry_error   = isset( $entry['error'] ) ? (string) $entry['error'] : '';
						$dot_status    = 'sent' === $entry_status ? 'success' : 'error';
					?>
					<tr class="cp-row">
						<td>
							<span class="cp-dot cp-dot-<?php echo esc_attr( $dot_status ); ?>" title="<?php echo esc_attr( $entry_error ); ?>"></span>
							<span class="cp-status-text"><?php echo esc_html( ucfirst( $entry_status ) ); ?></span>
						</td>
						<td><?php echo esc_html( $entry_to ); ?></td>
						<td><?php echo esc_html( $entry_subject ); ?></td>
						<td><?php echo esc_html( $entry_type ); ?></td>
						<td><?php echo esc_html( self::format_time( (int) $entry['timestamp'] ) ); ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
				</div><!-- .cp-email-log-section -->

				<div class="cp-debug-log-section">
				<h2 class="cp-debug-log-heading"><?php esc_html_e( 'Email Debug Log', 'cronpulse' ); ?></h2>
				<?php
				$debug_log_contents = CronPulse_Debug_Log::get_contents();
				$debug_log_path     = CronPulse_Debug_Log::get_path();
				?>
				<p class="description">
					<?php
					echo esc_html(
						/* translators: %s = full server file path to the debug log */
						sprintf( __( 'The raw SMTP conversation for each send attempt — connection, TLS, auth, server responses. Credentials are never written here. File: %s', 'cronpulse' ), $debug_log_path )
					);
					?>
				</p>
				<?php if ( empty( $debug_log_contents ) ) : ?>
					<p class="cp-empty"><?php esc_html_e( 'No debug output yet. Use "Send Test Email" on the Settings tab to generate some.', 'cronpulse' ); ?></p>
				<?php else : ?>
					<div class="cp-log-toolbar">
						<button class="button cp-clear-debug-log"><?php esc_html_e( 'Clear Debug Log', 'cronpulse' ); ?></button>
					</div>
					<pre class="cp-debug-log"><?php echo esc_html( $debug_log_contents ); ?></pre>
				<?php endif; ?>
				</div><!-- .cp-debug-log-section -->
			</div>

			<!-- Alerts tab -->
			<div id="cp-alerts" class="cp-tab-panel" style="display:none;">
				<?php CronPulse_Alerts::render_settings_tab(); ?>
			</div>

		</div><!-- .cp-wrap -->
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a flat list of all scheduled cron jobs with status.
	 * Public so CronPulse_CLI_Command can reuse the same status logic.
	 */
	public static function get_jobs(): array {
		$crons = _get_cron_array();
		if ( empty( $crons ) || ! is_array( $crons ) ) {
			return [];
		}

		$jobs = [];
		$now  = time();

		foreach ( $crons as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				foreach ( $events as $sig => $event ) {
					$last_run = CronPulse_Cron_Tracker::get_last_run( $hook );

					if ( (int) $timestamp < $now ) {
						$status = 'overdue';
					} elseif ( $last_run && in_array( $last_run['status'], [ 'fatal', 'incomplete', 'stuck' ], true ) ) {
						$status = 'failing';
					} elseif ( $last_run ) {
						$status = 'healthy';
					} else {
						$status = 'pending';
					}

					$jobs[] = [
						'hook'     => $hook,
						'next_run' => (int) $timestamp,
						'schedule' => $event['schedule'] ?? '',
						'args'     => $event['args'] ?? [],
						'sig'      => (string) $sig,
						'status'   => $status,
					];
				}
			}
		}

		usort( $jobs, static function ( $a, $b ) {
			$order = [ 'overdue' => 0, 'failing' => 1, 'pending' => 2, 'healthy' => 3 ];
			$oa    = $order[ $a['status'] ] ?? 4;
			$ob    = $order[ $b['status'] ] ?? 4;
			if ( $oa !== $ob ) {
				return $oa - $ob;
			}
			return $a['next_run'] <=> $b['next_run'];
		} );

		return $jobs;
	}

	/**
	 * Build a tiny inline SVG sparkline from a hook's recent durations, so a
	 * creeping-up execution time is visible before it becomes a timeout.
	 * Returns an empty string when there's not enough data for a trend line.
	 *
	 * @param int[] $durations Milliseconds, oldest first.
	 */
	private static function render_sparkline( array $durations ): string {
		if ( count( $durations ) < 2 ) {
			return '';
		}

		$width  = 60;
		$height = 18;
		$max    = max( $durations );
		$min    = min( $durations );
		$range  = max( 1, $max - $min ); // avoid div-by-zero when every run took the same time
		$count  = count( $durations );
		$step   = $width / ( $count - 1 );

		$points = [];
		foreach ( $durations as $i => $d ) {
			$x        = round( $i * $step, 1 );
			$y        = round( $height - ( ( $d - $min ) / $range ) * $height, 1 );
			$points[] = $x . ',' . $y;
		}

		return sprintf(
			'<svg class="cp-sparkline" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" aria-hidden="true"><polyline points="%3$s" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>',
			$width,
			$height,
			esc_attr( implode( ' ', $points ) )
		);
	}

	/**
	 * Format a Unix timestamp as a human-readable relative string.
	 */
	public static function format_time( int $timestamp ): string {
		$diff = time() - $timestamp;

		if ( abs( $diff ) < 60 ) {
			return __( 'Just now', 'cronpulse' );
		}

		$future = $timestamp > time();
		$label  = human_time_diff( $timestamp );

		if ( $future ) {
			/* translators: %s = human-readable time difference, e.g. "5 minutes" */
			return sprintf( __( 'in %s', 'cronpulse' ), $label );
		}

		/* translators: %s = human-readable time difference, e.g. "5 minutes" */
		return sprintf( __( '%s ago', 'cronpulse' ), $label );
	}
}
