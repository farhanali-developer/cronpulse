<?php
/**
 * CP_Admin_Page
 *
 * Registers the wp-admin menu page and renders the cron dashboard.
 */
defined( 'ABSPATH' ) || exit;

class CP_Admin_Page {

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
			'cp-admin',
			CP_PLUGIN_URL . 'assets/admin.css',
			[],
			CP_VERSION
		);
		wp_enqueue_script(
			'cp-admin',
			CP_PLUGIN_URL . 'assets/admin.js',
			[ 'jquery' ],
			CP_VERSION,
			true
		);
		wp_localize_script( 'cp-admin', 'cpData', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cp_nonce' ),
			'i18n'    => [
				'running'           => __( 'Running…', 'cronpulse' ),
				'success'           => __( 'Triggered successfully', 'cronpulse' ),
				'error'             => __( 'Error triggering job', 'cronpulse' ),
				'runNow'            => __( 'Run Now', 'cronpulse' ),
				'justNow'           => __( 'Just now', 'cronpulse' ),
				'confirmClear'      => __( 'Clear the entire execution log? This cannot be undone.', 'cronpulse' ),
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

		$jobs      = self::get_jobs();
		$log       = CP_Cron_Tracker::get_log();
		$schedules = wp_get_schedules();

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

			<?php if ( isset( $_GET['updated'] ) ) : ?>
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
							<th><?php esc_html_e( 'Next Run', 'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Last Run', 'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Duration', 'cronpulse' ); ?></th>
							<th><?php esc_html_e( 'Actions',  'cronpulse' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $jobs as $job ) :
						$schedule_label = isset( $schedules[ $job['schedule'] ] )
							? $schedules[ $job['schedule'] ]['display']
							: ( $job['schedule'] ?: '—' );
						$last_run = CP_Cron_Tracker::get_last_run( $job['hook'] );
						$duration = isset( $last_run['duration'] ) ? absint( $last_run['duration'] ) . ' ms' : '—';
					?>
					<tr class="cp-row cp-status-<?php echo esc_attr( $job['status'] ); ?>" data-hook="<?php echo esc_attr( $job['hook'] ); ?>" data-status="<?php echo esc_attr( $job['status'] ); ?>">
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
						<td><?php echo esc_html( $duration ); ?></td>
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
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
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
						<?php printf(
							/* translators: 1: number of log entries stored, 2: configured retention limit */
							esc_html__( '%1$d entries (newest first, max %2$d)', 'cronpulse' ),
							count( $log ),
							CP_Alerts::get_settings()['log_retention']
						); ?>
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

			<!-- Alerts tab -->
			<div id="cp-alerts" class="cp-tab-panel" style="display:none;">
				<?php CP_Alerts::render_settings_tab(); ?>
			</div>

		</div><!-- .cp-wrap -->
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a flat list of all scheduled cron jobs with status.
	 * Public so CP_CLI_Command can reuse the same status logic.
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
					$last_run = CP_Cron_Tracker::get_last_run( $hook );

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
