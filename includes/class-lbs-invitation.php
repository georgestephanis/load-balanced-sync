<?php
/**
 * Invitation token management for the peer pairing protocol.
 *
 * @package LoadBalancedSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates, accepts, and purges invitation tokens.
 */
class LBS_Invitation {

	// -----------------------------------------------------------------------
	// Site A: Generate an invitation
	// -----------------------------------------------------------------------

	/**
	 * Create a new one-time invitation token.
	 *
	 * The raw token is returned once (to be displayed to the admin) and never
	 * stored. Only the hash is persisted in options.
	 *
	 * @return array  ['token' => string]
	 */
	public static function create_invitation(): array {
		$raw_token  = wp_generate_password( 48, false );
		$token_hash = wp_fast_hash( $raw_token );

		$invitations   = get_option( 'lbs_pending_invitations', array() );
		$invitations[] = array(
			'token_hash'         => $token_hash,
			'created_at'         => time(),
			'expires_at'         => time() + HOUR_IN_SECONDS,
			'initiator_real_url' => LBS_Peer_Registry::get_own_real_url(),
			'group_name'         => LBS_Peer_Registry::get_own_group(),
			'used'               => false,
		);

		update_option( 'lbs_pending_invitations', $invitations, false );
		LBS_Logger::info( 'Generated new invitation token.' );

		return array( 'token' => $raw_token );
	}

	// -----------------------------------------------------------------------
	// Site B: Accept an invitation
	// -----------------------------------------------------------------------

	/**
	 * Accept an invitation from Site A.
	 *
	 * Creates an Application Password on this site for Site A to use, then
	 * calls Site A's handshake endpoint to exchange credentials.
	 *
	 * @param string $raw_token     The token from Site A's invitation.
	 * @param string $site_a_url    Site A's real URL.
	 * @return array|WP_Error       Peer record on success.
	 */
	public static function accept_invitation( string $raw_token, string $site_a_url ): array|WP_Error {
		if ( LBS_Crypto::is_auth_key_placeholder() ) {
			return new WP_Error( 'lbs_auth_key_placeholder', 'AUTH_KEY is not configured. Cannot store credentials securely.' );
		}

		// Validate Site A's URL.
		$site_a_url = rtrim( sanitize_url( $site_a_url ), '/' );
		$url_check  = LBS_Peer_Registry::validate_peer_url( $site_a_url );
		if ( is_wp_error( $url_check ) ) {
			return $url_check;
		}

		// Make sure we're not already peered with this URL.
		$existing = LBS_Peer_Registry::get_peer_by_url( $site_a_url );
		if ( $existing && ( $existing['status'] ?? '' ) === 'active' ) {
			return new WP_Error( 'lbs_already_paired', 'Already paired with that site.' );
		}

		// Find an administrator user to create the app password for.
		$users = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		if ( empty( $users ) ) {
			return new WP_Error( 'lbs_no_admin', 'No administrator user found.' );
		}
		$admin_user = $users[0];

		// Create an Application Password on this site for Site A.
		$host          = wp_parse_url( $site_a_url, PHP_URL_HOST );
		$app_pass_name = 'LBS — ' . $host;

		$app_password_result = WP_Application_Passwords::create_new_application_password(
			$admin_user->ID,
			array( 'name' => $app_pass_name )
		);

		if ( is_wp_error( $app_password_result ) ) {
			return $app_password_result;
		}

		[ $our_new_password, $our_app_item ] = $app_password_result;

		// Call Site A's handshake endpoint.
		$http    = new LBS_HTTP_Client();
		$own_url = LBS_Peer_Registry::get_own_real_url();

		$response = $http->send_handshake(
			$site_a_url,
			array(
				'token'             => $raw_token,
				'initiating_url'    => $site_a_url,
				'peer_real_url'     => $own_url,
				'peer_group'        => LBS_Peer_Registry::get_own_group(),
				'peer_label'        => wp_parse_url( $own_url, PHP_URL_HOST ),
				'peer_app_username' => $admin_user->user_login,
				'peer_app_password' => $our_new_password,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Clean up the app password we created since the handshake failed.
			WP_Application_Passwords::delete_application_password( $admin_user->ID, $our_app_item['uuid'] );
			LBS_Logger::error( "Handshake with {$site_a_url} failed: " . $response->get_error_message() );
			return $response;
		}

		// Site A responded with its credentials for us to use.
		$site_a_username = sanitize_text_field( $response['app_username'] ?? '' );
		$site_a_password = $response['app_password'] ?? '';

		if ( empty( $site_a_username ) || empty( $site_a_password ) ) {
			WP_Application_Passwords::delete_application_password( $admin_user->ID, $our_app_item['uuid'] );
			return new WP_Error( 'lbs_handshake_incomplete', 'Site A did not return credentials.' );
		}

		// Encrypt Site A's password for storage.
		try {
			$site_a_encrypted = LBS_Crypto::encrypt( $site_a_password );
		} catch ( RuntimeException $e ) {
			WP_Application_Passwords::delete_application_password( $admin_user->ID, $our_app_item['uuid'] );
			return new WP_Error( 'lbs_encrypt_failed', $e->getMessage() );
		}

		$peer_record = array(
			'uuid'                   => wp_generate_uuid4(),
			'label'                  => $host,
			'real_url'               => $site_a_url,
			'group_name'             => '', // Will be filled by the first ping response.
			'app_username'           => $site_a_username,
			'app_password_uuid'      => $our_app_item['uuid'], // Our local app pass UUID (for revocation).
			'app_password_encrypted' => $site_a_encrypted,    // Site A's password we use to call them.
			'status'                 => 'active',
			'last_seen'              => time(),
			'last_error'             => '',
			'paired_at'              => time(),
		);

		LBS_Peer_Registry::save_peer( $peer_record );

		// Confirm mutual auth with a ping and capture Site A's group name.
		$ping_result = ( new LBS_HTTP_Client() )->send_ping( $peer_record );
		if ( ! is_wp_error( $ping_result ) ) {
			$peer_record['group_name'] = sanitize_text_field( $ping_result['group'] ?? '' );
			LBS_Peer_Registry::save_peer( $peer_record );
		}

		LBS_Logger::info( "Successfully paired with {$site_a_url}." );

		return $peer_record;
	}

	// -----------------------------------------------------------------------
	// Cron: purge expired invitations
	// -----------------------------------------------------------------------

	/**
	 * Remove expired and used invitation tokens. Called by WP-Cron.
	 */
	public static function purge_expired(): void {
		$invitations = get_option( 'lbs_pending_invitations', array() );
		$now         = time();

		$clean = array_filter(
			$invitations,
			function ( $inv ) use ( $now ) {
				if ( $inv['used'] ?? false ) {
					return false;
				}
				if ( ( $inv['expires_at'] ?? 0 ) < $now ) {
					return false;
				}
				return true;
			}
		);

		update_option( 'lbs_pending_invitations', array_values( $clean ), false );
	}
}
