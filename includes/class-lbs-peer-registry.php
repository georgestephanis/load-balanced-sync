<?php
/**
 * CRUD operations for plugin settings and the peer list.
 *
 * @package LoadBalancedSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores plugin settings, peer records, and admin notices.
 */
class LBS_Peer_Registry {

	// -----------------------------------------------------------------------
	// Settings
	// -----------------------------------------------------------------------

	/**
	 * Get plugin settings with defaults applied.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_settings(): array {
		return wp_parse_args(
			get_option( 'lbs_settings', array() ),
			array(
				'group_name'   => 'production',
				'own_real_url' => get_site_url(),
				'sync_enabled' => true,
			)
		);
	}

	/**
	 * Save plugin settings.
	 *
	 * @param array<string,mixed> $settings Settings payload.
	 */
	public static function save_settings( array $settings ): void {
		update_option( 'lbs_settings', $settings, false );
	}

	/**
	 * Get this site's configured real URL.
	 *
	 * @return string
	 */
	public static function get_own_real_url(): string {
		return rtrim( self::get_settings()['own_real_url'] ?? get_site_url(), '/' );
	}

	/**
	 * Get this site's configured group.
	 *
	 * @return string
	 */
	public static function get_own_group(): string {
		return self::get_settings()['group_name'] ?? 'production';
	}

	/**
	 * Determine whether update sync is enabled.
	 *
	 * @return bool
	 */
	public static function is_sync_enabled(): bool {
		return (bool) ( self::get_settings()['sync_enabled'] ?? true );
	}

	// -----------------------------------------------------------------------
	// Peers
	// -----------------------------------------------------------------------

	/**
	 * Get all peers.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_all_peers(): array {
		return get_option( 'lbs_peers', array() );
	}

	/**
	 * Get active peers only.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_active_peers(): array {
		return array_filter( self::get_all_peers(), fn( $p ) => ( $p['status'] ?? '' ) === 'active' );
	}

	/**
	 * Get a peer by UUID.
	 *
	 * @param string $uuid Peer UUID.
	 * @return array<string,mixed>|null
	 */
	public static function get_peer( string $uuid ): ?array {
		foreach ( self::get_all_peers() as $peer ) {
			if ( ( $peer['uuid'] ?? '' ) === $uuid ) {
				return $peer;
			}
		}
		return null;
	}

	/**
	 * Find a peer by the URL they announce as their real address.
	 *
	 * @param string $real_url Peer real URL.
	 * @return array<string,mixed>|null
	 */
	public static function get_peer_by_url( string $real_url ): ?array {
		$url = rtrim( $real_url, '/' );
		foreach ( self::get_all_peers() as $peer ) {
			if ( rtrim( $peer['real_url'] ?? '', '/' ) === $url ) {
				return $peer;
			}
		}
		return null;
	}

	/**
	 * Add or replace a peer record. Keyed on uuid.
	 *
	 * @param array<string,mixed> $peer Peer record.
	 */
	public static function save_peer( array $peer ): void {
		$peers = self::get_all_peers();
		$found = false;

		foreach ( $peers as &$existing ) {
			if ( ( $existing['uuid'] ?? '' ) === $peer['uuid'] ) {
				$existing = array_merge( $existing, $peer );
				$found    = true;
				break;
			}
		}
		unset( $existing );

		if ( ! $found ) {
			$peers[] = $peer;
		}

		update_option( 'lbs_peers', array_values( $peers ), false );
	}

	/**
	 * Delete a peer by UUID.
	 *
	 * @param string $uuid Peer UUID.
	 */
	public static function delete_peer( string $uuid ): void {
		$peers = array_filter(
			self::get_all_peers(),
			fn( $p ) => ( $p['uuid'] ?? '' ) !== $uuid
		);
		update_option( 'lbs_peers', array_values( $peers ), false );
	}

	/**
	 * Mark a peer healthy and seen now.
	 *
	 * @param string $uuid Peer UUID.
	 */
	public static function mark_peer_seen( string $uuid ): void {
		self::save_peer(
			array(
				'uuid'       => $uuid,
				'status'     => 'active',
				'last_seen'  => time(),
				'last_error' => '',
			)
		);
	}

	/**
	 * Mark a peer with an error state.
	 *
	 * @param string $uuid  Peer UUID.
	 * @param string $error Error message.
	 */
	public static function mark_peer_error( string $uuid, string $error ): void {
		self::save_peer(
			array(
				'uuid'       => $uuid,
				'status'     => 'error',
				'last_error' => $error,
			)
		);
	}

	// -----------------------------------------------------------------------
	// URL Validation
	// -----------------------------------------------------------------------

	/**
	 * Validates a peer URL: must be https://, must pass wp_http_validate_url().
	 *
	 * @param string $url URL to validate.
	 * @return string|WP_Error  Sanitized URL or error.
	 */
	public static function validate_peer_url( string $url ): string|WP_Error {
		$url = rtrim( sanitize_url( $url ), '/' );

		if ( ! str_starts_with( $url, 'https://' ) ) {
			return new WP_Error( 'lbs_url_not_https', 'Peer URL must use HTTPS.' );
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'lbs_url_invalid', 'Peer URL is not a valid URL.' );
		}

		return $url;
	}

	// -----------------------------------------------------------------------
	// Cross-group Notices
	// -----------------------------------------------------------------------

	/**
	 * Add an admin notice entry.
	 *
	 * @param array<string,mixed> $notice Notice payload.
	 */
	public static function add_notice( array $notice ): void {
		$notices   = get_option( 'lbs_notices', array() );
		$notices[] = $notice;
		update_option( 'lbs_notices', $notices, false );
	}

	/**
	 * Pop and clear accumulated admin notices.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function pop_notices(): array {
		$notices = get_option( 'lbs_notices', array() );
		update_option( 'lbs_notices', array(), false );
		return $notices;
	}
}
