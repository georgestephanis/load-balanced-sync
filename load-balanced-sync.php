<?php
/**
 * Plugin Name: Load Balanced Sync
 * Plugin URI:  https://github.com/georgestephanis/load-balanced-sync
 * Description: Synchronizes plugin, theme, and core updates across multiple WordPress instances in a load-balanced or distributed setup.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      George Stephanis
 * License:     GPL-2.0-or-later
 * Text Domain: load-balanced-sync
 *
 * @package LoadBalancedSync
 */

defined( 'ABSPATH' ) || exit;

define( 'LBS_VERSION', '1.0.0' );
define( 'LBS_PLUGIN_FILE', __FILE__ );
define( 'LBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------

register_activation_hook( __FILE__, 'lbs_activate' );

/**
 * Activate plugin defaults and scheduled tasks.
 */
function lbs_activate(): void {
	// Seed default settings if not already present.
	if ( ! get_option( 'lbs_settings' ) ) {
		update_option(
			'lbs_settings',
			array(
				'group_name'   => 'production',
				'own_real_url' => get_site_url(),
				'sync_enabled' => true,
			),
			false
		);
	}

	if ( false === get_option( 'lbs_peers' ) ) {
		update_option( 'lbs_peers', array(), false );
	}

	if ( false === get_option( 'lbs_pending_invitations' ) ) {
		update_option( 'lbs_pending_invitations', array(), false );
	}

	if ( false === get_option( 'lbs_notices' ) ) {
		update_option( 'lbs_notices', array(), false );
	}

	if ( false === get_option( 'lbs_log' ) ) {
		update_option( 'lbs_log', array(), false );
	}

	// Schedule the invitation purge cron.
	if ( ! wp_next_scheduled( 'lbs_purge_expired_invitations' ) ) {
		wp_schedule_event( time(), 'hourly', 'lbs_purge_expired_invitations' );
	}
}

register_deactivation_hook( __FILE__, 'lbs_deactivate' );

/**
 * Unschedule plugin cron hooks.
 */
function lbs_deactivate(): void {
	wp_clear_scheduled_hook( 'lbs_purge_expired_invitations' );
	wp_clear_scheduled_hook( 'lbs_fan_out_update' );
	wp_clear_scheduled_hook( 'lbs_execute_update' );
}

// ---------------------------------------------------------------------------
// Autoload includes
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'lbs_init', 1 );

/**
 * Initialize plugin services and hooks.
 */
function lbs_init(): void {
	// Core includes.
	require_once LBS_PLUGIN_DIR . 'includes/class-lbs-crypto.php';
	require_once LBS_PLUGIN_DIR . 'includes/class-lbs-logger.php';
	require_once LBS_PLUGIN_DIR . 'includes/class-lbs-peer-registry.php';
	require_once LBS_PLUGIN_DIR . 'includes/class-lbs-http-client.php';
	require_once LBS_PLUGIN_DIR . 'includes/class-lbs-invitation.php';
	require_once LBS_PLUGIN_DIR . 'includes/class-lbs-update-dispatcher.php';
	require_once LBS_PLUGIN_DIR . 'includes/class-lbs-update-executor.php';

	// REST API controllers.
	require_once LBS_PLUGIN_DIR . 'rest-api/class-lbs-rest-handshake-controller.php';
	require_once LBS_PLUGIN_DIR . 'rest-api/class-lbs-rest-ping-controller.php';
	require_once LBS_PLUGIN_DIR . 'rest-api/class-lbs-rest-update-controller.php';
	require_once LBS_PLUGIN_DIR . 'rest-api/class-lbs-rest-status-controller.php';

	// Admin.
	if ( is_admin() ) {
		require_once LBS_PLUGIN_DIR . 'admin/class-lbs-admin.php';
		require_once LBS_PLUGIN_DIR . 'admin/class-lbs-admin-notices.php';
		( new LBS_Admin() )->register();
		( new LBS_Admin_Notices() )->register();
	}

	// REST API.
	add_action( 'rest_api_init', 'lbs_register_rest_routes' );

	// Update dispatcher.
	( new LBS_Update_Dispatcher() )->register();

	// Cron event handlers.
	add_action( 'lbs_purge_expired_invitations', array( 'LBS_Invitation', 'purge_expired' ) );
	add_action( 'lbs_fan_out_update', array( 'LBS_Update_Dispatcher', 'fan_out' ) );
	add_action( 'lbs_execute_update', array( 'LBS_Update_Executor', 'execute' ) );
}

/**
 * Register plugin REST API routes.
 */
function lbs_register_rest_routes(): void {
	( new LBS_REST_Handshake_Controller() )->register_routes();
	( new LBS_REST_Ping_Controller() )->register_routes();
	( new LBS_REST_Update_Controller() )->register_routes();
	( new LBS_REST_Status_Controller() )->register_routes();
}
