<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 *
 * Removes all lbs_* options. Does NOT revoke application passwords
 * created on peer sites — the admin must do that manually.
 *
 * @package LoadBalancedSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$option_names = array(
	'lbs_settings',
	'lbs_peers',
	'lbs_pending_invitations',
	'lbs_notices',
	'lbs_log',
);

foreach ( $option_names as $name ) {
	delete_option( $name );
}

// Remove any scheduled cron events.
wp_clear_scheduled_hook( 'lbs_purge_expired_invitations' );
wp_clear_scheduled_hook( 'lbs_fan_out_update' );
wp_clear_scheduled_hook( 'lbs_execute_update' );

// Remove transients.
delete_transient( 'lbs_update_in_flight' );
delete_transient( 'lbs_seen_nonces' );
