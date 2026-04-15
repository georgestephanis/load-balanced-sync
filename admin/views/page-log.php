<?php
/**
 * Log tab view.
 */

defined( 'ABSPATH' ) || exit;

$entries = LBS_Logger::get( 50 );

$level_labels = array(
	'info'    => __( 'Info', 'load-balanced-sync' ),
	'warning' => __( 'Warning', 'load-balanced-sync' ),
	'error'   => __( 'Error', 'load-balanced-sync' ),
);
?>

<p>
	<?php esc_html_e( 'The 50 most recent log entries (newest first).', 'load-balanced-sync' ); ?>
</p>

<?php if ( empty( $entries ) ) : ?>
	<p><em><?php esc_html_e( 'No log entries yet.', 'load-balanced-sync' ); ?></em></p>
<?php else : ?>
<table class="wp-list-table widefat fixed striped lbs-log-table">
	<thead>
		<tr>
			<th style="width:180px"><?php esc_html_e( 'Time', 'load-balanced-sync' ); ?></th>
			<th style="width:80px"><?php esc_html_e( 'Level', 'load-balanced-sync' ); ?></th>
			<th><?php esc_html_e( 'Message', 'load-balanced-sync' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $entries as $entry ) :
		$ts      = absint( $entry['ts'] ?? 0 );
		$level   = sanitize_key( $entry['level'] ?? 'info' );
		$message = esc_html( $entry['message'] ?? '' );
		$date    = $ts ? wp_date( 'Y-m-d H:i:s', $ts ) : '—';
	?>
		<tr class="lbs-log-<?php echo esc_attr( $level ); ?>">
			<td><code><?php echo esc_html( $date ); ?></code></td>
			<td><?php echo esc_html( $level_labels[ $level ] ?? $level ); ?></td>
			<td><?php echo $message; ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
	<input type="hidden" name="action" value="lbs_clear_log">
	<?php wp_nonce_field( 'lbs_clear_log' ); ?>
	<button type="submit" class="button button-secondary"
		onclick="return confirm('<?php echo esc_js( __( 'Clear all log entries?', 'load-balanced-sync' ) ); ?>')">
		<?php esc_html_e( 'Clear Log', 'load-balanced-sync' ); ?>
	</button>
</form>
