<?php
/**
 * REST controller for peer health checks.
 *
 * POST /wp-json/lbs/v1/ping
 *
 * Authenticated via WordPress Application Passwords.
 *
 * @package LoadBalancedSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes a ping endpoint for peer liveness checks.
 */
class LBS_REST_Ping_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'lbs/v1';

	/**
	 * REST route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'ping';

	/**
	 * Register ping route.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'ping_item' ),
					'permission_callback' => array( $this, 'ping_permissions_check' ),
					'show_in_index'       => false,
					'args'                => array(
						'sender_uuid' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Check permissions for ping endpoint.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function ping_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication required.', 'load-balanced-sync' ),
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'load-balanced-sync' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Return local health and version details.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function ping_item( WP_REST_Request $request ): WP_REST_Response {
		$sender_uuid = $request->get_param( 'sender_uuid' );

		// If the sender identifies itself, mark it as seen.
		if ( $sender_uuid ) {
			$peer = LBS_Peer_Registry::get_peer( $sender_uuid );
			if ( $peer ) {
				LBS_Peer_Registry::mark_peer_seen( $sender_uuid );
			}
		}

		return new WP_REST_Response(
			array(
				'status'     => 'ok',
				'site_url'   => LBS_Peer_Registry::get_own_real_url(),
				'group'      => LBS_Peer_Registry::get_own_group(),
				'ts'         => time(),
				'wp_version' => get_bloginfo( 'version' ),
			),
			200
		);
	}
}
