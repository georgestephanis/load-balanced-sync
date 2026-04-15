<?php
/**
 * Listens for WordPress upgrade events and fans updates out to peers.
 */

defined( 'ABSPATH' ) || exit;

class LBS_Update_Dispatcher {

	public function register(): void {
		// Priority 20 — after WordPress's own cleanup hooks at priority 9.
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrade_complete' ), 20, 2 );
	}

	/**
	 * Called by WordPress after any upgrade process completes.
	 *
	 * @param WP_Upgrader $upgrader    The upgrader instance.
	 * @param array       $hook_extra Data describing what was upgraded.
	 */
	public function on_upgrade_complete( WP_Upgrader $upgrader, array $hook_extra ): void {
		// Guard: if we are processing a peer-triggered update, do nothing —
		// this prevents echo loops where each site triggers the others.
		if ( get_transient( 'lbs_update_in_flight' ) ) {
			return;
		}

		if ( ! LBS_Peer_Registry::is_sync_enabled() ) {
			return;
		}

		// Only care about updates, not fresh installs.
		if ( ( $hook_extra['action'] ?? '' ) !== 'update' ) {
			return;
		}

		$type = $hook_extra['type'] ?? '';
		if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
			return;
		}

		$peers = LBS_Peer_Registry::get_active_peers();
		if ( empty( $peers ) ) {
			return;
		}

		$own_group = LBS_Peer_Registry::get_own_group();

		switch ( $type ) {
			case 'plugin':
				$this->dispatch_plugins( $hook_extra, $peers, $own_group );
				break;

			case 'theme':
				$this->dispatch_themes( $hook_extra, $peers, $own_group );
				break;

			case 'core':
				$this->dispatch_core( $peers, $own_group );
				break;
		}
	}

	// -----------------------------------------------------------------------
	// Dispatch helpers
	// -----------------------------------------------------------------------

	private function dispatch_plugins( array $hook_extra, array $peers, string $own_group ): void {
		// Bulk upgrade provides $hook_extra['plugins']; single upgrade uses $hook_extra['plugin'].
		$plugin_files = ! empty( $hook_extra['plugins'] )
			? (array) $hook_extra['plugins']
			: ( ! empty( $hook_extra['plugin'] ) ? array( $hook_extra['plugin'] ) : array() );

		if ( empty( $plugin_files ) ) {
			return;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( $plugin_files as $plugin_file ) {
			$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( ! file_exists( $plugin_path ) ) {
				continue;
			}
			$data    = get_plugin_data( $plugin_path, false, false );
			$version = $data['Version'] ?? '0';

			$this->schedule_fan_out(
				array(
					'type'        => 'plugin',
					'identifier'  => $plugin_file,
					'new_version' => $version,
				),
				$peers,
				$own_group
			);
		}
	}

	private function dispatch_themes( array $hook_extra, array $peers, string $own_group ): void {
		$theme_slugs = ! empty( $hook_extra['themes'] )
			? (array) $hook_extra['themes']
			: ( ! empty( $hook_extra['theme'] ) ? array( $hook_extra['theme'] ) : array() );

		if ( empty( $theme_slugs ) ) {
			return;
		}

		foreach ( $theme_slugs as $slug ) {
			$theme   = wp_get_theme( $slug );
			$version = $theme->exists() ? $theme->get( 'Version' ) : '0';

			$this->schedule_fan_out(
				array(
					'type'        => 'theme',
					'identifier'  => $slug,
					'new_version' => $version,
				),
				$peers,
				$own_group
			);
		}
	}

	private function dispatch_core( array $peers, string $own_group ): void {
		$this->schedule_fan_out(
			array(
				'type'        => 'core',
				'identifier'  => '',
				'new_version' => get_bloginfo( 'version' ),
			),
			$peers,
			$own_group
		);
	}

	/**
	 * Schedule the fan-out cron event.
	 *
	 * Adds a `notify_only` flag to the payload for cross-group peers so
	 * the cron job knows to notify rather than trigger an upgrade.
	 *
	 * @param array  $base_payload  type, identifier, new_version.
	 * @param array  $peers         Active peer list.
	 * @param string $own_group     This site's group.
	 */
	private function schedule_fan_out( array $base_payload, array $peers, string $own_group ): void {
		$same_group_uuids  = array();
		$cross_group_uuids = array();

		foreach ( $peers as $peer ) {
			$uuid = $peer['uuid'] ?? null;
			if ( ! $uuid ) {
				continue;
			}

			if ( ( $peer['group_name'] ?? '' ) === $own_group ) {
				$same_group_uuids[] = $uuid;
			} else {
				$cross_group_uuids[] = $uuid;
			}
		}

		if ( ! empty( $same_group_uuids ) ) {
			wp_schedule_single_event(
				time() + 1,
				'lbs_fan_out_update',
				array(
					array_merge(
						$base_payload,
						array(
							'notify_only' => false,
							'peers'       => $same_group_uuids,
						)
					),
				)
			);
		}

		if ( ! empty( $cross_group_uuids ) ) {
			wp_schedule_single_event(
				time() + 1,
				'lbs_fan_out_update',
				array(
					array_merge(
						$base_payload,
						array(
							'notify_only' => true,
							'peers'       => $cross_group_uuids,
						)
					),
				)
			);
		}
	}

	// -----------------------------------------------------------------------
	// Cron: fan-out
	// -----------------------------------------------------------------------

	/**
	 * Send the update command/notification to each peer. Called by WP-Cron.
	 *
	 * @param array $payload  Includes type, identifier, new_version, notify_only, peers (UUIDs).
	 */
	public static function fan_out( array $payload ): void {
		$http         = new LBS_HTTP_Client();
		$own_uuid     = ''; // We'll identify via our real URL in the payload.
		$own_uuid_key = 'initiator_uuid';

		// Find our own UUID as seen by our peers by using a placeholder;
		// the receiver will look us up by app-password-auth identity.
		// We use a self-generated UUID stored in options.
		$own_initiator_uuid = self::get_own_initiator_uuid();

		$send_payload = array(
			'type'           => $payload['type'],
			'identifier'     => $payload['identifier'] ?? '',
			'new_version'    => $payload['new_version'] ?? '',
			'notify_only'    => (bool) ( $payload['notify_only'] ?? false ),
			'initiator_uuid' => $own_initiator_uuid,
		);

		foreach ( (array) ( $payload['peers'] ?? array() ) as $peer_uuid ) {
			$peer = LBS_Peer_Registry::get_peer( $peer_uuid );
			if ( ! $peer ) {
				LBS_Logger::warning( "Fan-out: peer {$peer_uuid} not found, skipping." );
				continue;
			}

			$result = $http->send_update_command( $peer, $send_payload );

			if ( is_wp_error( $result ) ) {
				LBS_Logger::error( "Fan-out to {$peer['label']} ({$peer['real_url']}) failed: " . $result->get_error_message() );
				LBS_Peer_Registry::mark_peer_error( $peer_uuid, $result->get_error_message() );
			} else {
				$status = $result['status'] ?? 'unknown';
				LBS_Logger::info( "Fan-out to {$peer['label']}: {$status}." );
				LBS_Peer_Registry::mark_peer_seen( $peer_uuid );
			}
		}
	}

	/**
	 * Returns a stable UUID that identifies this site as an initiator.
	 *
	 * When the remote site receives our update command authenticated as our
	 * app-password user, it can look up the peer record by the authenticated
	 * user. The initiator_uuid here is a convenience; the HMAC is what
	 * actually binds the request to our identity.
	 */
	private static function get_own_initiator_uuid(): string {
		$uuid = get_option( 'lbs_own_initiator_uuid' );
		if ( ! $uuid ) {
			$uuid = wp_generate_uuid4();
			update_option( 'lbs_own_initiator_uuid', $uuid, false );
		}
		return $uuid;
	}
}
