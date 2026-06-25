<?php
/**
 * CP_REST_Controller
 *
 * Read-only JSON status endpoint, for agencies polling cron health across
 * multiple client sites without SSH/WP-CLI access. Authenticates the same
 * way any other WP REST route does — cookie+nonce for logged-in browser
 * requests, or an Application Password for external/remote consumers.
 */
defined( 'ABSPATH' ) || exit;

class CP_REST_Controller {

	const NAMESPACE_ = 'cronpulse/v1';

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE_, '/status', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_status' ],
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [
				'status' => [
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );
	}

	public static function get_status( WP_REST_Request $request ): WP_REST_Response {
		$jobs = CP_Admin_Page::get_jobs();

		$filter = $request->get_param( 'status' );
		if ( $filter ) {
			$jobs = array_values( array_filter( $jobs, static function ( $job ) use ( $filter ) {
				return $job['status'] === $filter;
			} ) );
		}

		$summary = [
			'total'   => count( $jobs ),
			'overdue' => 0,
			'failing' => 0,
			'healthy' => 0,
			'pending' => 0,
		];
		foreach ( $jobs as $job ) {
			if ( isset( $summary[ $job['status'] ] ) ) {
				$summary[ $job['status'] ]++;
			}
		}

		// Deliberately excludes args/sig — internal implementation details
		// that may carry sensitive data depending on the hook.
		$data = array_map( static function ( $job ) {
			return [
				'hook'     => $job['hook'],
				'status'   => $job['status'],
				'schedule' => $job['schedule'],
				'next_run' => gmdate( 'c', $job['next_run'] ),
			];
		}, $jobs );

		return new WP_REST_Response( [
			'site'    => home_url(),
			'summary' => $summary,
			'jobs'    => $data,
		], 200 );
	}
}
