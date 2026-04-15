<?php
/**
 * REST controller for receiving update commands from peers.
 *
 * POST /wp-json/lbs/v1/update
 *
 * Authenticated via WordPress Application Passwords + HMAC nonce.
 * Schedules a WP-Cron event to run the actual upgrade asynchronously.
 */

defined( 'ABSPATH' ) || exit;

class LBS_REST_Update_Controller extends WP_REST_Controller {

	protected $namespace = 'lbs/v1';
	protected $rest_base = 'update';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_permissions_check' ),
					'show_in_index'       => false,
					'args'                => $this->get_item_args(),
				),
			)
		);
	}

	private function get_item_args(): array {
		return array(
			'type'           => array( 'type' => 'string', 'required' => true, 'enum' => array( 'plugin', 'theme', 'core' ) ),
			'identifier'     => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			'new_version'    => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			'notify_only'    => array( 'type' => 'boolean', 'required' => false, 'default' => false ),
			'initiator_uuid' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			'nonce'          => array( 'type' => 'string', 'required' => true ),
			'request_ts'     => array( 'type' => 'integer', 'required' => true ),
		);
	}

	public function update_permissions_check( $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Authentication required.', 'load-balanced-sync' ), array( 'status' => 401 ) );
		}

		// Require update capabilities.
		$type = $request->get_param( 'type' );
		$cap  = match ( $type ) {
			'plugin' => 'update_plugins',
			'theme'  => 'update_themes',
			'core'   => 'update_core',
			default  => 'manage_options',
		};
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Insufficient permissions.', 'load-balanced-sync' ), array( 'status' => 403 ) );
		}

		return $this->verify_request_hmac( $request );
	}

	private function verify_request_hmac( $request ): bool|WP_Error {
		$request_ts = (int) $request->get_param( 'request_ts' );

		// Replay protection: 5-minute window.
		if ( abs( time() - $request_ts ) > 300 ) {
			return new WP_Error( 'lbs_timestamp_invalid', __( 'Request timestamp is outside the allowed window.', 'load-balanced-sync' ), array( 'status' => 400 ) );
		}

		$initiator_uuid = $request->get_param( 'initiator_uuid' );
		$peer           = LBS_Peer_Registry::get_peer( $initiator_uuid );
		if ( ! $peer ) {
			return new WP_Error( 'lbs_unknown_initiator', __( 'Unknown initiator UUID.', 'load-balanced-sync' ), array( 'status' => 403 ) );
		}

		// Decrypt the peer's app password to use as HMAC key.
		$app_password = LBS_Crypto::decrypt( $peer['app_password_encrypted'] ?? '' );
		if ( is_wp_error( $app_password ) ) {
			return new WP_Error( 'lbs_decrypt_failed', __( 'Could not decrypt peer credentials.', 'load-balanced-sync' ), array( 'status' => 500 ) );
		}

		$payload = array(
			'type'           => $request->get_param( 'type' ),
			'identifier'     => $request->get_param( 'identifier' ) ?? '',
			'new_version'    => $request->get_param( 'new_version' ) ?? '',
			'initiator_uuid' => $initiator_uuid,
			'request_ts'     => $request_ts,
		);

		$nonce = $request->get_param( 'nonce' );
		if ( ! LBS_Crypto::verify_update_nonce( $nonce, $payload, $app_password ) ) {
			LBS_Logger::warning( "HMAC verification failed for update request from peer {$initiator_uuid}." );
			return new WP_Error( 'lbs_nonce_invalid', __( 'HMAC nonce verification failed.', 'load-balanced-sync' ), array( 'status' => 403 ) );
		}

		// Replay protection: check nonce hasn't been seen recently.
		$seen = get_transient( 'lbs_seen_nonces' ) ?: array();
		$nonce_hash = md5( $nonce );
		if ( in_array( $nonce_hash, $seen, true ) ) {
			return new WP_Error( 'lbs_replay', __( 'Duplicate nonce detected.', 'load-balanced-sync' ), array( 'status' => 400 ) );
		}

		// Record nonce.
		$seen[] = $nonce_hash;
		if ( count( $seen ) > 100 ) {
			$seen = array_slice( $seen, -100 );
		}
		set_transient( 'lbs_seen_nonces', $seen, 10 * MINUTE_IN_SECONDS );

		return true;
	}

	/** @return WP_REST_Response|WP_Error */
	public function update_item( $request ) {
		$type           = $request->get_param( 'type' );
		$identifier     = $request->get_param( 'identifier' ) ?? '';
		$new_version    = $request->get_param( 'new_version' ) ?? '';
		$notify_only    = (bool) $request->get_param( 'notify_only' );
		$initiator_uuid = $request->get_param( 'initiator_uuid' );

		$peer = LBS_Peer_Registry::get_peer( $initiator_uuid );

		if ( $notify_only ) {
			// Cross-group: just store a notice for the admin.
			LBS_Peer_Registry::add_notice( array(
				'type'        => $type,
				'identifier'  => $identifier,
				'new_version' => $new_version,
				'source_url'  => $peer['real_url'] ?? '',
				'source_group'=> $peer['group_name'] ?? '',
				'received_at' => time(),
			) );
			LBS_Logger::info( "Received cross-group update notice: {$type} {$identifier} → {$new_version} from {$peer['real_url']}." );

			return new WP_REST_Response( array( 'status' => 'noticed' ), 200 );
		}

		// Guard against loops: if already processing an update, acknowledge and skip.
		if ( get_transient( 'lbs_update_in_flight' ) ) {
			return new WP_REST_Response( array( 'status' => 'already_in_progress' ), 200 );
		}

		// Schedule the upgrade asynchronously (avoids HTTP timeout for large packages).
		$job_id = wp_generate_uuid4();
		wp_schedule_single_event( time() + 1, 'lbs_execute_update', array( array(
			'type'        => $type,
			'identifier'  => $identifier,
			'new_version' => $new_version,
			'job_id'      => $job_id,
		) ) );

		LBS_Logger::info( "Accepted update command: {$type} {$identifier} → {$new_version}. Job: {$job_id}." );

		return new WP_REST_Response( array(
			'status' => 'accepted',
			'job_id' => $job_id,
		), 202 );
	}
}
