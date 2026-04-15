<?php
/**
 * Admin notices for cross-group update events.
 *
 * @package LoadBalancedSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders plugin-specific admin notices.
 */
class LBS_Admin_Notices {

	/**
	 * Register admin notice hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_cross_group_notices' ) );

		// Auth key warning.
		add_action( 'admin_notices', array( $this, 'render_auth_key_warning' ) );
	}

	/**
	 * Render notices for cross-group update events.
	 */
	public function render_cross_group_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notices = LBS_Peer_Registry::pop_notices();
		if ( empty( $notices ) ) {
			return;
		}

		foreach ( $notices as $notice ) {
			$type       = esc_html( $notice['type'] ?? 'item' );
			$identifier = esc_html( $notice['identifier'] ?? '' );
			$version    = esc_html( $notice['new_version'] ?? '' );
			$source     = esc_html( $notice['source_url'] ?? '' );
			$group      = esc_html( $notice['source_group'] ?? '' );

			$message = sprintf(
				/* translators: 1: type (plugin/theme/core), 2: identifier, 3: version, 4: source site URL, 5: source group name */
				__( '<strong>LB Sync:</strong> %1$s <code>%2$s</code> was updated to <strong>%3$s</strong> on <code>%4$s</code> (group: %5$s). This site was <strong>not</strong> automatically updated because it is in a different group.', 'load-balanced-sync' ),
				$type,
				$identifier,
				$version,
				$source,
				$group
			);

			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				wp_kses(
					$message,
					array(
						'strong' => array(),
						'code'   => array(),
					)
				)
			);
		}
	}

	/**
	 * Warn when AUTH_KEY is left at the default placeholder value.
	 */
	public function render_auth_key_warning(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! LBS_Crypto::is_auth_key_placeholder() ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: link to wp-config.php docs */
			__( '<strong>LB Sync:</strong> Your <code>AUTH_KEY</code> in <code>wp-config.php</code> is still the default placeholder. Peer credentials cannot be securely stored until you set unique secret keys. <a href="%s" target="_blank" rel="noopener">Generate new keys</a>.', 'load-balanced-sync' ),
			'https://api.wordpress.org/secret-key/1.1/salt/'
		);

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			wp_kses(
				$message,
				array(
					'strong' => array(),
					'code'   => array(),
					'a'      => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			)
		);
	}
}
