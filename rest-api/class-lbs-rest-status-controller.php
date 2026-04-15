<?php
/**
 * REST controller for querying installed component versions.
 *
 * GET /wp-json/lbs/v1/status?type=plugin&identifier=woocommerce/woocommerce.php
 *
 * Authenticated via WordPress Application Passwords.
 */

defined( 'ABSPATH' ) || exit;

class LBS_REST_Status_Controller extends WP_REST_Controller {

	protected $namespace = 'lbs/v1';
	protected $rest_base = 'status';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'status_permissions_check' ),
					'show_in_index'       => false,
					'args'                => array(
						'type'       => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'plugin', 'theme', 'core' ),
						),
						'identifier' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	public function status_permissions_check( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Authentication required.', 'load-balanced-sync' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function get_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type       = $request->get_param( 'type' );
		$identifier = $request->get_param( 'identifier' );

		switch ( $type ) {
			case 'plugin':
				return $this->get_plugin_status( $identifier );

			case 'theme':
				return $this->get_theme_status( $identifier );

			case 'core':
				return $this->get_core_status();

			default:
				return new WP_Error( 'lbs_invalid_type', __( 'Invalid type.', 'load-balanced-sync' ), array( 'status' => 400 ) );
		}
	}

	private function get_plugin_status( string $plugin_file ): WP_REST_Response|WP_Error {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $plugin_path ) ) {
			return new WP_Error( 'lbs_plugin_not_found', __( 'Plugin not found.', 'load-balanced-sync' ), array( 'status' => 404 ) );
		}

		$data = get_plugin_data( $plugin_path, false, false );

		// Check if update is available.
		$updates          = get_site_transient( 'update_plugins' );
		$update_available = isset( $updates->response[ $plugin_file ] );

		return new WP_REST_Response(
			array(
				'type'             => 'plugin',
				'identifier'       => $plugin_file,
				'version'          => $data['Version'] ?? '0',
				'update_available' => $update_available,
			),
			200
		);
	}

	private function get_theme_status( string $theme_slug ): WP_REST_Response|WP_Error {
		$theme = wp_get_theme( $theme_slug );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'lbs_theme_not_found', __( 'Theme not found.', 'load-balanced-sync' ), array( 'status' => 404 ) );
		}

		$updates          = get_site_transient( 'update_themes' );
		$update_available = isset( $updates->response[ $theme_slug ] );

		return new WP_REST_Response(
			array(
				'type'             => 'theme',
				'identifier'       => $theme_slug,
				'version'          => $theme->get( 'Version' ) ?? '0',
				'update_available' => $update_available,
			),
			200
		);
	}

	private function get_core_status(): WP_REST_Response {
		$updates          = get_site_transient( 'update_core' );
		$update_available = ! empty( $updates->updates );

		return new WP_REST_Response(
			array(
				'type'             => 'core',
				'identifier'       => '',
				'version'          => get_bloginfo( 'version' ),
				'update_available' => $update_available,
			),
			200
		);
	}
}
