<?php
/**
 * Admin page registration and form handling for Load Balanced Sync.
 *
 * @package LoadBalancedSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers admin UI and handles admin-post actions.
 */
class LBS_Admin {

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_notices', array( $this, 'render_missing_build_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_post_lbs_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_lbs_generate_invite', array( $this, 'handle_generate_invite' ) );
		add_action( 'admin_post_lbs_accept_invite', array( $this, 'handle_accept_invite' ) );
		add_action( 'admin_post_lbs_ping_peer', array( $this, 'handle_ping_peer' ) );
		add_action( 'admin_post_lbs_remove_peer', array( $this, 'handle_remove_peer' ) );
		add_action( 'admin_post_lbs_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_lbs_download_log', array( $this, 'handle_download_log' ) );
	}

	/**
	 * Register plugin settings page.
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'Load Balanced Sync', 'load-balanced-sync' ),
			__( 'LB Sync', 'load-balanced-sync' ),
			'manage_options',
			'load-balanced-sync',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Warn when compiled admin assets have not been built yet.
	 */
	public function render_missing_build_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_load-balanced-sync' !== $screen->id ) {
			return;
		}

		if ( file_exists( LBS_PLUGIN_DIR . 'build/index.asset.php' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Load Balanced Sync admin assets have not been built yet. Run npm install and npm run build in the plugin directory to enable the enhanced admin UI.', 'load-balanced-sync' )
		);
	}

	/**
	 * Enqueue admin scripts/styles on plugin page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_load-balanced-sync' !== $hook ) {
			return;
		}

		$tab_param = filter_input( INPUT_GET, 'tab', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$tab       = $tab_param ? sanitize_key( wp_unslash( $tab_param ) ) : 'settings';

		$build_asset_path = LBS_PLUGIN_DIR . 'build/index.asset.php';
		$build_script_url = LBS_PLUGIN_URL . 'build/index.js';
		$build_style_path = LBS_PLUGIN_DIR . 'build/style-index.css';

		if ( ! file_exists( $build_asset_path ) ) {
			return;
		}

		$asset = require $build_asset_path;
		$deps  = is_array( $asset['dependencies'] ?? null ) ? $asset['dependencies'] : array();
		$ver   = is_string( $asset['version'] ?? null ) ? $asset['version'] : LBS_VERSION;

		$required_deps = array( 'wp-element', 'wp-i18n', 'wp-components' );
		if ( wp_script_is( 'wp-dataviews', 'registered' ) ) {
			$required_deps[] = 'wp-dataviews';
		}
		$deps = array_values( array_unique( array_merge( $deps, $required_deps ) ) );

		wp_enqueue_script( 'lbs-admin', $build_script_url, $deps, $ver, true );

		if ( file_exists( $build_style_path ) ) {
			$style_deps = array( 'wp-components' );
			if ( wp_style_is( 'wp-dataviews', 'registered' ) ) {
				$style_deps[] = 'wp-dataviews';
			}

			wp_enqueue_style( 'lbs-admin', LBS_PLUGIN_URL . 'build/style-index.css', $style_deps, $ver );
			wp_style_add_data( 'lbs-admin', 'rtl', 'replace' );
		}

		if ( 'log' === $tab ) {
			wp_add_inline_script(
				'lbs-admin',
				'window.lbsLogViewerConfig = ' . wp_json_encode( $this->get_log_viewer_config() ) . ';',
				'before'
			);
		}
	}

	/**
	 * Build client config for the log DataViews renderer.
	 *
	 * @return array<string,mixed>
	 */
	private function get_log_viewer_config(): array {
		$selected_file_param = filter_input( INPUT_GET, 'log_file', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$selected_file       = $selected_file_param ? sanitize_file_name( wp_unslash( $selected_file_param ) ) : '';

		if ( '' !== $selected_file ) {
			$entries = LBS_Logger::get_entries_by_filename( $selected_file, 1000 );
		} else {
			$entries = LBS_Logger::get( 1000 );
		}

		$normalized = array();
		foreach ( $entries as $index => $entry ) {
			$normalized[] = array(
				'id'      => (int) $index + 1,
				'ts'      => absint( $entry['ts'] ?? 0 ),
				'level'   => sanitize_key( $entry['level'] ?? 'info' ),
				'message' => (string) ( $entry['message'] ?? '' ),
			);
		}

		return array(
			'selectedFile' => $selected_file,
			'entries'      => $normalized,
			'files'        => LBS_Logger::get_log_files(),
			'restBase'     => esc_url_raw( rest_url( 'lbs/v1/log' ) ),
			'restNonce'    => wp_create_nonce( 'wp_rest' ),
		);
	}

	/**
	 * Register admin log viewer REST endpoints.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'lbs/v1',
			'/log/entries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_get_log_entries' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'file'     => array(
						'type'     => 'string',
						'required' => false,
					),
					'per_page' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 500,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Return normalized log entries for DataViews rendering.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function rest_get_log_entries( WP_REST_Request $request ): WP_REST_Response {
		$file     = sanitize_file_name( (string) $request->get_param( 'file' ) );
		$per_page = max( 1, min( 2000, (int) $request->get_param( 'per_page' ) ) );

		if ( '' !== $file ) {
			$entries = LBS_Logger::get_entries_by_filename( $file, $per_page );
		} else {
			$entries = LBS_Logger::get( $per_page );
		}

		$items = array();
		foreach ( $entries as $index => $entry ) {
			$items[] = array(
				'id'      => (int) $index + 1,
				'ts'      => absint( $entry['ts'] ?? 0 ),
				'level'   => sanitize_key( $entry['level'] ?? 'info' ),
				'message' => (string) ( $entry['message'] ?? '' ),
			);
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
			),
			200
		);
	}

	/**
	 * Render plugin admin page content.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'load-balanced-sync' ) );
		}

		$tab_param = filter_input( INPUT_GET, 'tab', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$tab       = $tab_param ? sanitize_key( wp_unslash( $tab_param ) ) : 'settings';
		$tabs      = array(
			'settings' => __( 'Settings', 'load-balanced-sync' ),
			'peers'    => __( 'Peers', 'load-balanced-sync' ),
			'invite'   => __( 'Pairing', 'load-balanced-sync' ),
			'log'      => __( 'Log', 'load-balanced-sync' ),
		);

		echo '<div class="wrap lbs-wrap">';
		echo '<h1>' . esc_html__( 'Load Balanced Sync', 'load-balanced-sync' ) . '</h1>';

		// Tab nav.
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$url    = add_query_arg(
				array(
					'page' => 'load-balanced-sync',
					'tab'  => $key,
				),
				admin_url( 'options-general.php' )
			);
			$active = ( $tab === $key ) ? ' nav-tab-active' : '';
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( $url ), esc_attr( $active ), esc_html( $label ) );
		}
		echo '</nav>';

		echo '<div class="lbs-tab-content">';

		$view_file = LBS_PLUGIN_DIR . 'includes/admin/views/page-' . $tab . '.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			echo '<p>' . esc_html__( 'Unknown tab.', 'load-balanced-sync' ) . '</p>';
		}

		echo '</div></div>';
	}

	/**
	 * Form handlers.
	 */

	/**
	 * Handle settings save request.
	 */
	public function handle_save_settings(): void {
		check_admin_referer( 'lbs_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'load-balanced-sync' ) );
		}

		$raw_url = sanitize_url( wp_unslash( $_POST['own_real_url'] ?? '' ) );
		$url_ok  = LBS_Peer_Registry::validate_peer_url( $raw_url );

		$settings = array(
			'group_name'   => sanitize_text_field( wp_unslash( $_POST['group_name'] ?? 'production' ) ),
			'own_real_url' => is_wp_error( $url_ok ) ? LBS_Peer_Registry::get_own_real_url() : $url_ok,
			'sync_enabled' => ! empty( $_POST['sync_enabled'] ),
		);

		LBS_Peer_Registry::save_settings( $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'load-balanced-sync',
					'tab'     => 'settings',
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle invitation token generation.
	 */
	public function handle_generate_invite(): void {
		check_admin_referer( 'lbs_generate_invite' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'load-balanced-sync' ) );
		}

		$result = LBS_Invitation::create_invitation();

		// Pass the token back via a signed transient so it only shows once.
		$transient_key = 'lbs_show_token_' . wp_generate_password( 8, false );
		set_transient( $transient_key, $result['token'], 120 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'load-balanced-sync',
					'tab'   => 'invite',
					'token' => $transient_key,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle invitation acceptance submission.
	 */
	public function handle_accept_invite(): void {
		check_admin_referer( 'lbs_accept_invite' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'load-balanced-sync' ) );
		}

		$site_a_url = sanitize_url( wp_unslash( $_POST['initiator_url'] ?? '' ) );
		$token      = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );

		$result = LBS_Invitation::accept_invitation( $token, $site_a_url );

		$args = array(
			'page' => 'load-balanced-sync',
			'tab'  => 'peers',
		);

		if ( is_wp_error( $result ) ) {
			set_transient( 'lbs_invite_error', $result->get_error_message(), 60 );
			$args['invite_error'] = '1';
		} else {
			$args['invite_ok'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Handle manual ping request for a peer.
	 */
	public function handle_ping_peer(): void {
		check_admin_referer( 'lbs_ping_peer' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'load-balanced-sync' ) );
		}

		$uuid = sanitize_text_field( wp_unslash( $_POST['peer_uuid'] ?? '' ) );
		$peer = LBS_Peer_Registry::get_peer( $uuid );

		if ( $peer ) {
			$http   = new LBS_HTTP_Client();
			$result = $http->send_ping( $peer );
			if ( is_wp_error( $result ) ) {
				LBS_Peer_Registry::mark_peer_error( $uuid, $result->get_error_message() );
			} else {
				LBS_Peer_Registry::mark_peer_seen( $uuid );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'load-balanced-sync',
					'tab'    => 'peers',
					'pinged' => $uuid,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle removing a peer from local registry.
	 */
	public function handle_remove_peer(): void {
		check_admin_referer( 'lbs_remove_peer' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'load-balanced-sync' ) );
		}

		$uuid = sanitize_text_field( wp_unslash( $_POST['peer_uuid'] ?? '' ) );
		LBS_Peer_Registry::delete_peer( $uuid );
		LBS_Logger::info( "Removed peer {$uuid}." );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'load-balanced-sync',
					'tab'     => 'peers',
					'removed' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle clearing the plugin log.
	 */
	public function handle_clear_log(): void {
		check_admin_referer( 'lbs_clear_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'load-balanced-sync' ) );
		}

		LBS_Logger::clear();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'load-balanced-sync',
					'tab'  => 'log',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Handle downloading a selected JSONL log file.
	 */
	public function handle_download_log(): void {
		check_admin_referer( 'lbs_download_log' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'load-balanced-sync' ) );
		}

		$file = sanitize_file_name( wp_unslash( $_GET['file'] ?? '' ) );
		$path = LBS_Logger::get_log_file_path_by_name( $file );

		if ( ! $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Log file not found.', 'load-balanced-sync' ) );
		}

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( wp_basename( $path ) ) . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: public' );

		$filesize = filesize( $path );
		if ( false !== $filesize ) {
			header( 'Content-Length: ' . (string) $filesize );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Streaming local admin-requested log file download.
		$contents = file_get_contents( $path );
		if ( false !== $contents ) {
			echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw file download output.
		}

		exit;
	}
}
