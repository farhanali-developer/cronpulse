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
				'running'      => __( 'Running…', 'cronpulse' ),
				'success'      => __( 'Triggered successfully', 'cronpulse' ),
				'error'        => __( 'Error triggering job', 'cronpulse' ),
				'runNow'       => __( 'Run Now', 'cronpulse' ),
				'justNow'      => __( 'Just now', 'cronpulse' ),
				'confirmClear' => __( 'Clear the entire execution log? This cannot be undone.', 'cronpulse' ),
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
		$healthy = 0;
		$never   = 0;
		foreach ( $jobs as $job ) {
			if ( $job['status'] === 'overdue' ) {
				$overdue++;
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
			</nav>

			<!-- Jobs tab -->
			<div id="cp-jobs" class="cp-tab-panel">
				<?php if ( empty( $jobs ) ) : ?>
					<p class="cp-empty"><?php esc_html_e( 'No scheduled cron jobs found.', 'cronpulse' ); ?></p>
				<?php else : ?>
				<div class="cp-search-bar">
					<input type="text" id="cp-search" placeholder="<?php esc_attr_e( 'Filter by hook name…', 'cronpulse' ); ?>" />
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
					<tr class="cp-row cp-status-<?php echo esc_attr( $job['status'] ); ?>" data-hook="<?php echo esc_attr( $job['hook'] ); ?>">
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
							/* translators: %d = number of log entries stored */
							esc_html__( '%d entries (newest first, max 200)', 'cronpulse' ),
							count( $log )
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
					?>
					<tr class="cp-row cp-status-<?php echo esc_attr( $entry_status ); ?>">
						<td>
							<span class="cp-dot cp-dot-<?php echo esc_attr( $entry_status ); ?>"></span>
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

		</div><!-- .cp-wrap -->
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a flat list of all scheduled cron jobs with status.
	 */
	private static function get_jobs(): array {
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
				foreach ( $events as $event ) {
					$last_run = CP_Cron_Tracker::get_last_run( $hook );

					if ( (int) $timestamp < $now ) {
						$status = 'overdue';
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
						'status'   => $status,
					];
				}
			}
		}

		usort( $jobs, static function ( $a, $b ) {
			$order = [ 'overdue' => 0, 'pending' => 1, 'healthy' => 2 ];
			$oa    = $order[ $a['status'] ] ?? 3;
			$ob    = $order[ $b['status'] ] ?? 3;
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
