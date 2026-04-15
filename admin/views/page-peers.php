<?php
/**
 * Peers tab view.
 */

defined( 'ABSPATH' ) || exit;

$peers = LBS_Peer_Registry::get_all_peers();

if ( ! empty( $_GET['removed'] ) ) {
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Peer removed.', 'load-balanced-sync' ) . '</p></div>';
}

if ( ! empty( $_GET['invite_ok'] ) ) {
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Pairing successful! New peer is now active.', 'load-balanced-sync' ) . '</p></div>';
}

if ( ! empty( $_GET['invite_error'] ) ) {
	$error_msg = get_transient( 'lbs_invite_error' );
	$error_msg = $error_msg ?: __( 'Pairing failed. Check the URL and token and try again.', 'load-balanced-sync' );
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error_msg ) . '</p></div>';
}

if ( ! empty( $_GET['pinged'] ) ) {
	$uuid   = sanitize_text_field( $_GET['pinged'] );
	$peer   = LBS_Peer_Registry::get_peer( $uuid );
	$status = $peer['status'] ?? 'unknown';
	if ( 'active' === $status ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ping successful.', 'load-balanced-sync' ) . '</p></div>';
	} else {
		$err = esc_html( $peer['last_error'] ?? __( 'Unknown error', 'load-balanced-sync' ) );
		echo '<div class="notice notice-error is-dismissible"><p>' . sprintf( esc_html__( 'Ping failed: %s', 'load-balanced-sync' ), $err ) . '</p></div>';
	}
}
?>

<p>
	<?php
	printf(
		/* translators: %s: link to pairing tab */
		esc_html__( 'Manage peer sites below. To add a new peer, go to the %s tab.', 'load-balanced-sync' ),
		'<a href="' . esc_url( add_query_arg( array( 'page' => 'load-balanced-sync', 'tab' => 'invite' ), admin_url( 'options-general.php' ) ) ) . '">' . esc_html__( 'Pairing', 'load-balanced-sync' ) . '</a>'
	);
	?>
</p>

<?php if ( empty( $peers ) ) : ?>
	<p><em><?php esc_html_e( 'No peers configured yet.', 'load-balanced-sync' ); ?></em></p>
<?php else : ?>
<table class="wp-list-table widefat fixed striped lbs-peers-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Label', 'load-balanced-sync' ); ?></th>
			<th><?php esc_html_e( 'Group', 'load-balanced-sync' ); ?></th>
			<th><?php esc_html_e( 'Real URL', 'load-balanced-sync' ); ?></th>
			<th><?php esc_html_e( 'Status', 'load-balanced-sync' ); ?></th>
			<th><?php esc_html_e( 'Last Seen', 'load-balanced-sync' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'load-balanced-sync' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $peers as $peer ) :
		$uuid       = esc_attr( $peer['uuid'] ?? '' );
		$label      = esc_html( $peer['label'] ?? $peer['real_url'] ?? '—' );
		$group      = esc_html( $peer['group_name'] ?? '—' );
		$real_url   = esc_html( $peer['real_url'] ?? '—' );
		$status     = $peer['status'] ?? 'pending';
		$last_seen  = $peer['last_seen'] ?? 0;
		$last_error = esc_html( $peer['last_error'] ?? '' );

		$status_label = match ( $status ) {
			'active'  => __( 'Active', 'load-balanced-sync' ),
			'pending' => __( 'Pending', 'load-balanced-sync' ),
			'error'   => __( 'Error', 'load-balanced-sync' ),
			default   => $status,
		};
		$status_class = 'lbs-status-' . esc_attr( $status );
	?>
		<tr>
			<td><?php echo $label; ?></td>
			<td><?php echo $group; ?></td>
			<td><code><?php echo $real_url; ?></code></td>
			<td>
				<span class="lbs-status-dot <?php echo $status_class; ?>" title="<?php echo $last_error; ?>"></span>
				<?php echo esc_html( $status_label ); ?>
				<?php if ( $last_error ) : ?>
					<span class="lbs-error-hint" title="<?php echo $last_error; ?>">(?)</span>
				<?php endif; ?>
			</td>
			<td>
				<?php echo $last_seen ? esc_html( human_time_diff( $last_seen ) . ' ago' ) : '—'; ?>
			</td>
			<td>
				<!-- Ping -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="lbs_ping_peer">
					<input type="hidden" name="peer_uuid" value="<?php echo $uuid; ?>">
					<?php wp_nonce_field( 'lbs_ping_peer' ); ?>
					<button type="submit" class="button button-small"><?php esc_html_e( 'Ping', 'load-balanced-sync' ); ?></button>
				</form>
				<!-- Remove -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
					onsubmit="return confirm('<?php echo esc_js( __( 'Remove this peer? This will not revoke application passwords.', 'load-balanced-sync' ) ); ?>')">
					<input type="hidden" name="action" value="lbs_remove_peer">
					<input type="hidden" name="peer_uuid" value="<?php echo $uuid; ?>">
					<?php wp_nonce_field( 'lbs_remove_peer' ); ?>
					<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Remove', 'load-balanced-sync' ); ?></button>
				</form>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>
