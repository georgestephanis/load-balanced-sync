<?php
/**
 * Settings tab view.
 *
 * @var array $settings  Current LBS settings (injected by render_page context).
 */

defined( 'ABSPATH' ) || exit;

$settings = LBS_Peer_Registry::get_settings();

if ( ! empty( $_GET['updated'] ) ) {
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'load-balanced-sync' ) . '</p></div>';
}

// Filesystem access indicator.
require_once ABSPATH . 'wp-admin/includes/file.php';
$fs_method = get_filesystem_method();
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="lbs_save_settings">
	<?php wp_nonce_field( 'lbs_save_settings' ); ?>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="lbs_group_name"><?php esc_html_e( 'Group Name', 'load-balanced-sync' ); ?></label>
			</th>
			<td>
				<input type="text" id="lbs_group_name" name="group_name"
					value="<?php echo esc_attr( $settings['group_name'] ); ?>"
					class="regular-text" required>
				<p class="description">
					<?php esc_html_e( 'Sites in the same group automatically sync updates. Sites in different groups are only notified.', 'load-balanced-sync' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="lbs_own_real_url"><?php esc_html_e( 'This Site\'s Real URL', 'load-balanced-sync' ); ?></label>
			</th>
			<td>
				<input type="url" id="lbs_own_real_url" name="own_real_url"
					value="<?php echo esc_attr( $settings['own_real_url'] ); ?>"
					class="regular-text" required>
				<p class="description">
					<?php esc_html_e( 'The direct HTTPS address to reach this server, bypassing any load balancer. Peer sites will call this address. Must start with https://.', 'load-balanced-sync' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Sync Enabled', 'load-balanced-sync' ); ?>
			</th>
			<td>
				<label>
					<input type="checkbox" name="sync_enabled" value="1"
						<?php checked( $settings['sync_enabled'] ); ?>>
					<?php esc_html_e( 'Propagate updates to peers in the same group', 'load-balanced-sync' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Filesystem Access', 'load-balanced-sync' ); ?>
			</th>
			<td>
				<?php if ( 'direct' === $fs_method ) : ?>
					<span class="lbs-badge lbs-badge-ok"><?php esc_html_e( 'Direct (OK)', 'load-balanced-sync' ); ?></span>
					<p class="description"><?php esc_html_e( 'Background updates will work without FTP credentials.', 'load-balanced-sync' ); ?></p>
				<?php else : ?>
					<span class="lbs-badge lbs-badge-warn"><?php echo esc_html( $fs_method ); ?></span>
					<p class="description">
						<?php esc_html_e( 'This server requires FTP/SSH for file writes. Add FS_METHOD, FTP_HOST, FTP_USER, and FTP_PASS constants to wp-config.php for background updates to work.', 'load-balanced-sync' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<?php submit_button( __( 'Save Settings', 'load-balanced-sync' ) ); ?>
</form>
