<?php
/**
 * Outbound HTTP client for communicating with peer sites.
 *
 * Uses wp_remote_post() with Application Password Basic Auth.
 * Temporarily enables requests to private/LAN IPs by hooking
 * http_request_host_is_external for the duration of each call.
 *
 * @package LoadBalancedSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles authenticated outbound REST requests to peer sites.
 */
class LBS_HTTP_Client {

	private const TIMEOUT = 15; // seconds.

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Send a handshake acceptance request to a peer.
	 *
	 * This request is unauthenticated — the token provides authority.
	 *
	 * @param string $site_a_url     The peer's REST API base URL.
	 * @param array  $body           Request body fields.
	 * @return array|WP_Error        Decoded response body or error.
	 */
	public function send_handshake( string $site_a_url, array $body ): array|WP_Error {
		$url = trailingslashit( $site_a_url ) . 'wp-json/lbs/v1/handshake/accept';

		return $this->post( $url, $body, array() );
	}

	/**
	 * Ping a peer to confirm connectivity and update last_seen.
	 *
	 * @param array $peer  Peer record from LBS_Peer_Registry.
	 * @return array|WP_Error
	 */
	public function send_ping( array $peer ): array|WP_Error {
		$url = trailingslashit( $peer['real_url'] ) . 'wp-json/lbs/v1/ping';

		$body = array(
			'sender_uuid' => $this->get_own_uuid(),
		);

		return $this->post( $url, $body, $this->auth_headers( $peer ) );
	}

	/**
	 * Send an update command (or cross-group notification) to a peer.
	 *
	 * @param array $peer     Peer record.
	 * @param array $payload  Update payload.
	 * @return array|WP_Error
	 */
	public function send_update_command( array $peer, array $payload ): array|WP_Error {
		$app_password = LBS_Crypto::decrypt( $peer['app_password_encrypted'] ?? '' );
		if ( is_wp_error( $app_password ) ) {
			return $app_password;
		}

		$request_ts = time();
		$nonce      = LBS_Crypto::build_update_nonce( array_merge( $payload, array( 'request_ts' => $request_ts ) ), $app_password );

		$body = array_merge(
			$payload,
			array(
				'request_ts' => $request_ts,
				'nonce'      => $nonce,
			)
		);

		$url = trailingslashit( $peer['real_url'] ) . 'wp-json/lbs/v1/update';

		return $this->post( $url, $body, $this->auth_headers( $peer ) );
	}

	/**
	 * Query a peer's installed version of a component.
	 *
	 * @param array  $peer        Peer record.
	 * @param string $type        'plugin', 'theme', or 'core'.
	 * @param string $identifier  Plugin basename or theme slug.
	 * @return array|WP_Error
	 */
	public function get_peer_status( array $peer, string $type, string $identifier = '' ): array|WP_Error {
		$url = add_query_arg(
			array(
				'type'       => $type,
				'identifier' => $identifier,
			),
			trailingslashit( $peer['real_url'] ) . 'wp-json/lbs/v1/status'
		);

		return $this->get( $url, $this->auth_headers( $peer ) );
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	/**
	 * Returns HTTP Basic Auth headers for a peer, using its stored app password.
	 *
	 * @param array $peer Peer record.
	 * @return array<string,string>
	 */
	private function auth_headers( array $peer ): array {
		$app_password = LBS_Crypto::decrypt( $peer['app_password_encrypted'] ?? '' );
		if ( is_wp_error( $app_password ) ) {
			return array();
		}

		$app_username = $peer['app_username'] ?? '';
		if ( ! is_string( $app_username ) || false !== strpos( $app_username, ':' ) ) {
			return array();
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by HTTP Basic Auth format.
		$credentials = base64_encode( $app_username . ':' . $app_password );
		return array( 'Authorization' => 'Basic ' . $credentials );
	}

	/**
	 * Returns the UUID that this site has in the peer's peer list.
	 *
	 * We discover this by looking at the peer record, which stores the UUID
	 * the peer assigned to us during handshake — but we don't have that.
	 * Instead, we send our own settings to help the peer identify us.
	 *
	 * @return string
	 */
	private function get_own_uuid(): string {
		// Our UUID in the peer's registry is not stored locally.
		// We identify ourselves by our real URL instead, via the ping body.
		return '';
	}

	/**
	 * Perform a POST request to a peer URL.
	 *
	 * @param string               $url           Target URL.
	 * @param array<string,mixed>  $body          Request payload.
	 * @param array<string,string> $extra_headers Extra request headers.
	 * @return array|WP_Error  Decoded JSON body on success.
	 */
	private function post( string $url, array $body, array $extra_headers ): array|WP_Error {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$this->allow_host( $host );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'headers'     => array_merge(
					array( 'Content-Type' => 'application/json' ),
					$extra_headers
				),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);

		$this->disallow_host();

		return $this->parse_response( $response );
	}

	/**
	 * Perform a GET request to a peer URL.
	 *
	 * @param string               $url           Target URL.
	 * @param array<string,string> $extra_headers Extra request headers.
	 * @return array|WP_Error
	 */
	private function get( string $url, array $extra_headers ): array|WP_Error {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$this->allow_host( $host );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $extra_headers,
			)
		);

		$this->disallow_host();

		return $this->parse_response( $response );
	}

	/**
	 * Parse an HTTP response into a data array or WP_Error.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return array|WP_Error
	 */
	private function parse_response( array|WP_Error $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			$message = is_array( $data ) ? ( $data['message'] ?? $body ) : $body;
			return new WP_Error( 'lbs_http_error_' . $code, $message, array( 'status' => $code ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'lbs_invalid_response', 'Non-JSON response from peer.', array( 'body' => $body ) );
		}

		return $data;
	}

	// -----------------------------------------------------------------------
	// Private IP / LAN host allowlisting
	//
	// wp_remote_post() calls wp_http_validate_url() by default, which rejects
	// private IP addresses to prevent SSRF. We temporarily hook the WordPress
	// filter to allow outbound requests to a specific known-good peer host.
	// -----------------------------------------------------------------------

	/**
	 * Temporarily allowed host while sending a peer request.
	 *
	 * @var string
	 */
	private string $allowed_host = '';

	/**
	 * Temporarily allow a specific host for outbound requests.
	 *
	 * @param string $host Hostname to allow.
	 */
	private function allow_host( string $host ): void {
		$this->allowed_host = $host;
		add_filter( 'http_request_host_is_external', array( $this, 'filter_allow_host' ), 10, 2 );
	}

	/**
	 * Remove the temporary host allowlist filter.
	 */
	private function disallow_host(): void {
		remove_filter( 'http_request_host_is_external', array( $this, 'filter_allow_host' ), 10 );
		$this->allowed_host = '';
	}

	/**
	 * Filter callback for host externality checks.
	 *
	 * @param bool   $allow Whether host is currently allowed.
	 * @param string $host  Host being validated.
	 * @return bool
	 *
	 * @internal  Used only as a temporary filter callback.
	 */
	public function filter_allow_host( bool $allow, string $host ): bool {
		if ( $host === $this->allowed_host ) {
			return true;
		}
		return $allow;
	}
}
