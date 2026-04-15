<?php
/**
 * REST controller for the pairing handshake.
 *
 * POST /wp-json/lbs/v1/handshake/accept
 *
 * This endpoint is unauthenticated — it is validated via a one-time
 * invitation token, not application passwords.
 */

defined( 'ABSPATH' ) || exit;

class LBS_REST_Handshake_Controller extends WP_REST_Controller {

	protected $namespace = 'lbs/v1';
	protected $rest_base = 'handshake';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/accept',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'accept_item' ),
					'permission_callback' => array( $this, 'accept_permissions_check' ),
					'show_in_index'       => false,
					'args'                => array(
						'token'             => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'initiating_url'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_url',
						),
						'peer_real_url'     => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_url',
						),
						'peer_group'        => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'peer_label'        => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'peer_app_username' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'peer_app_password' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}

	public function accept_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		// Rate limit: max 5 handshake attempts per IP per 10 minutes.
		$ip   = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
		$key  = 'lbs_handshake_rate_' . md5( $ip );
		$hits = (int) get_transient( $key );

		if ( $hits >= 5 ) {
			return new WP_Error(
				'lbs_rate_limited',
				__( 'Too many handshake attempts. Try again later.', 'load-balanced-sync' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );

		return true; // Token is the sole auth mechanism.
	}

	public function accept_item( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( LBS_Crypto::is_auth_key_placeholder() ) {
			return new WP_Error(
				'lbs_auth_key_placeholder',
				__( 'AUTH_KEY is not configured. Cannot store credentials securely.', 'load-balanced-sync' ),
				array( 'status' => 500 )
			);
		}

		$token      = $request->get_param( 'token' );
		$initiating = rtrim( $request->get_param( 'initiating_url' ), '/' );
		$peer_url   = rtrim( $request->get_param( 'peer_real_url' ), '/' );
		$peer_group = $request->get_param( 'peer_group' );
		$peer_label = $request->get_param( 'peer_label' ) ?: parse_url( $peer_url, PHP_URL_HOST );
		$peer_user  = $request->get_param( 'peer_app_username' );
		$peer_pass  = $request->get_param( 'peer_app_password' );

		// Validate that this request is actually for us.
		$own_url = LBS_Peer_Registry::get_own_real_url();
		if ( rtrim( $initiating, '/' ) !== $own_url ) {
			return new WP_Error(
				'lbs_url_mismatch',
				__( 'Initiating URL does not match this site\'s Real URL. Update Settings → LB Sync → This Site\'s Real URL.', 'load-balanced-sync' ),
				array( 'status' => 400 )
			);
		}

		// Find and consume the invitation token.
		$invitations = get_option( 'lbs_pending_invitations', array() );
		$found_index = null;

		foreach ( $invitations as $i => $inv ) {
			if ( ( $inv['used'] ?? false ) ) {
				continue;
			}
			if ( ( $inv['expires_at'] ?? 0 ) < time() ) {
				continue;
			}
			if ( wp_verify_fast_hash( $token, $inv['token_hash'] ) ) {
				$found_index = $i;
				break;
			}
		}

		if ( null === $found_index ) {
			LBS_Logger::warning( "Handshake attempt with invalid/expired token from {$peer_url}." );
			return new WP_Error(
				'lbs_token_invalid',
				__( 'Invitation token is invalid or has expired.', 'load-balanced-sync' ),
				array( 'status' => 403 )
			);
		}

		// Mark token as used IMMEDIATELY before doing anything else.
		$invitations[ $found_index ]['used'] = true;
		update_option( 'lbs_pending_invitations', $invitations, false );

		// Create an Application Password for the peer on this site.
		$admin_user = self::get_admin_user();
		if ( ! $admin_user ) {
			return new WP_Error(
				'lbs_no_admin',
				__( 'Could not find an administrator user.', 'load-balanced-sync' ),
				array( 'status' => 500 )
			);
		}

		$app_password_data = WP_Application_Passwords::create_new_application_password(
			$admin_user->ID,
			array( 'name' => 'LBS — ' . $peer_url )
		);

		if ( is_wp_error( $app_password_data ) ) {
			return $app_password_data;
		}

		[ $new_password, $app_password_item ] = $app_password_data;

		// Encrypt and save both credentials.
		try {
			$our_encrypted  = LBS_Crypto::encrypt( $new_password );
			$peer_encrypted = LBS_Crypto::encrypt( $peer_pass );
		} catch ( RuntimeException $e ) {
			// Revoke the app password we just created since we can't store it.
			WP_Application_Passwords::delete_application_password( $admin_user->ID, $app_password_item['uuid'] );
			return new WP_Error( 'lbs_encrypt_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		$peer_record = array(
			'uuid'                   => wp_generate_uuid4(),
			'label'                  => $peer_label,
			'real_url'               => $peer_url,
			'group_name'             => $peer_group,
			'app_username'           => $peer_user,
			'app_password_uuid'      => $app_password_item['uuid'],  // UUID of our app password for them
			'app_password_encrypted' => $peer_encrypted,             // Their password we use to call them
			'status'                 => 'active',
			'last_seen'              => time(),
			'last_error'             => '',
			'paired_at'              => time(),
		);

		LBS_Peer_Registry::save_peer( $peer_record );
		LBS_Logger::info( "Paired with new peer: {$peer_url} (group: {$peer_group})." );

		return new WP_REST_Response(
			array(
				'status'       => 'paired',
				'app_username' => $admin_user->user_login,
				'app_password' => $new_password,  // Plaintext, sent once over HTTPS.
			),
			200
		);
	}

	/**
	 * Returns the first administrator-role user.
	 */
	private static function get_admin_user(): WP_User|false {
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		return $users[0] ?? false;
	}
}
