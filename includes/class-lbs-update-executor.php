<?php
/**
 * Executes WordPress updates programmatically in a WP-Cron context.
 *
 * Uses the same internal upgrader classes that WordPress's auto-updater uses,
 * with Automatic_Upgrader_Skin to suppress all output.
 */

defined( 'ABSPATH' ) || exit;

class LBS_Update_Executor {

	/**
	 * Execute a pending update. Called by the lbs_execute_update cron event.
	 *
	 * @param array $args  Keys: type, identifier, new_version, job_id.
	 */
	public static function execute( array $args ): void {
		$type        = $args['type'] ?? '';
		$identifier  = $args['identifier'] ?? '';
		$new_version = $args['new_version'] ?? '';
		$job_id      = $args['job_id'] ?? 'unknown';

		LBS_Logger::info( "Starting update job {$job_id}: {$type} {$identifier} → {$new_version}." );

		// Set the in-flight guard FIRST so our own upgrader_process_complete hook
		// does not re-broadcast this update to peers (infinite loop prevention).
		set_transient( 'lbs_update_in_flight', true, 120 );

		// Allow direct filesystem access without FTP credentials.
		// This mirrors how WP_Automatic_Updater works.
		if ( ! defined( 'FS_METHOD' ) ) {
			define( 'FS_METHOD', 'direct' );
		}

		// Load required files.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		// Automatic_Upgrader_Skin silences all output — same skin used by
		// WordPress core's auto-updater (WP_Automatic_Updater).
		$skin = new Automatic_Upgrader_Skin();

		try {
			switch ( $type ) {
				case 'plugin':
					self::upgrade_plugin( $identifier, $new_version, $skin );
					break;

				case 'theme':
					self::upgrade_theme( $identifier, $new_version, $skin );
					break;

				case 'core':
					self::upgrade_core( $new_version, $skin );
					break;

				default:
					LBS_Logger::error( "Unknown update type '{$type}' for job {$job_id}." );
					break;
			}
		} catch ( Throwable $e ) {
			LBS_Logger::error( "Exception during update job {$job_id}: " . $e->getMessage() );
		}

		delete_transient( 'lbs_update_in_flight' );
		LBS_Logger::info( "Finished update job {$job_id}." );
	}

	// -----------------------------------------------------------------------
	// Type-specific upgraders
	// -----------------------------------------------------------------------

	private static function upgrade_plugin( string $plugin_file, string $new_version, Automatic_Upgrader_Skin $skin ): void {
		// Refresh the update_plugins transient so the upgrader can find the package.
		wp_update_plugins();

		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			LBS_Logger::error( "Plugin upgrade failed ({$plugin_file}): " . $result->get_error_message() );
		} elseif ( false === $result ) {
			LBS_Logger::warning( "Plugin upgrade returned false — may already be up to date ({$plugin_file})." );
		} else {
			LBS_Logger::info( "Plugin upgraded successfully: {$plugin_file}." );
		}
	}

	private static function upgrade_theme( string $theme_slug, string $new_version, Automatic_Upgrader_Skin $skin ): void {
		wp_update_themes();

		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $theme_slug );

		if ( is_wp_error( $result ) ) {
			LBS_Logger::error( "Theme upgrade failed ({$theme_slug}): " . $result->get_error_message() );
		} elseif ( false === $result ) {
			LBS_Logger::warning( "Theme upgrade returned false — may already be up to date ({$theme_slug})." );
		} else {
			LBS_Logger::info( "Theme upgraded successfully: {$theme_slug}." );
		}
	}

	private static function upgrade_core( string $new_version, Automatic_Upgrader_Skin $skin ): void {
		require_once ABSPATH . 'wp-admin/includes/update-core.php';
		$requested_version = trim( $new_version );

		// Refresh the update_core transient.
		wp_version_check( array(), true );

		$updates = get_site_transient( 'update_core' );
		if ( empty( $updates->updates ) ) {
			LBS_Logger::warning( 'Core upgrade requested but no update found in transient.' );
			return;
		}

		// Find the update matching the requested version.
		$target = null;
		foreach ( $updates->updates as $update ) {
			if ( isset( $update->version ) && $update->version === $requested_version ) {
				$target = $update;
				break;
			}
		}

		if ( ! $target && '' === $requested_version ) {
			// Fall back to the preferred update if exact version not found.
			$target = get_preferred_from_update_core();
		}

		if ( ! $target || empty( $target->version ) ) {
			LBS_Logger::warning( "Core upgrade requested to {$new_version} but no matching update object found." );
			return;
		}

		$upgrader = new Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $target, array( 'attempt_rollback' => true ) );

		if ( is_wp_error( $result ) ) {
			LBS_Logger::error( 'Core upgrade failed: ' . $result->get_error_message() );
		} else {
			LBS_Logger::info( "Core upgraded to {$target->version} successfully." );
		}
	}
}
