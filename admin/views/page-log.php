<?php
/**
 * Log tab view.
 *
 * @package LoadBalancedSync
 */

defined( 'ABSPATH' ) || exit;

$entries = LBS_Logger::get( 50 );
$meta    = LBS_Logger::get_storage_meta();
$files   = LBS_Logger::get_log_files();

$selected_file_param = filter_input( INPUT_GET, 'log_file', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
$selected_file       = $selected_file_param ? sanitize_file_name( wp_unslash( $selected_file_param ) ) : '';
$selected_entries    = '' !== $selected_file ? LBS_Logger::get_entries_by_filename( $selected_file, 1000 ) : array();
$viewer_entries      = '' !== $selected_file ? $selected_entries : $entries;

$level_labels = array(
	'info'    => __( 'Info', 'load-balanced-sync' ),
	'warning' => __( 'Warning', 'load-balanced-sync' ),
	'error'   => __( 'Error', 'load-balanced-sync' ),
);
?>

<p>
	<?php esc_html_e( 'The 50 most recent log entries (newest first).', 'load-balanced-sync' ); ?>
</p>

<p class="description">
	<?php
	printf(
		/* translators: 1: log directory path, 2: server UUID, 3: file naming pattern, 4: current weekly log filename, 5: file count */
		esc_html__( 'Logs are stored in %1$s using server UUID %2$s with pattern %3$s (current: %4$s). Found %5$s file(s).', 'load-balanced-sync' ),
		'<code>' . esc_html( $meta['directory'] ?? '' ) . '</code>',
		'<code>' . esc_html( $meta['uuid'] ?? '' ) . '</code>',
		'<code>' . esc_html( $meta['file_pattern'] ?? '' ) . '</code>',
		'<code>' . esc_html( $meta['current_file'] ?? '' ) . '</code>',
		'<code>' . esc_html( $meta['file_count'] ?? '0' ) . '</code>'
	);
	?>
</p>

<?php if ( ! empty( $files ) ) : ?>
	<h2><?php esc_html_e( 'Log Files', 'load-balanced-sync' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Filename', 'load-balanced-sync' ); ?></th>
				<th style="width:150px"><?php esc_html_e( 'Modified', 'load-balanced-sync' ); ?></th>
				<th style="width:120px"><?php esc_html_e( 'Size', 'load-balanced-sync' ); ?></th>
				<th style="width:220px"><?php esc_html_e( 'Actions', 'load-balanced-sync' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $files as $file ) : ?>
			<?php
			$filename = sanitize_file_name( $file['filename'] ?? '' );
			$mtime    = absint( $file['mtime'] ?? 0 );
			$size     = absint( $file['size'] ?? 0 );

			$download_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'lbs_download_log',
						'file'   => $filename,
					),
					admin_url( 'admin-post.php' )
				),
				'lbs_download_log'
			);

			$view_url = add_query_arg(
				array(
					'page'     => 'load-balanced-sync',
					'tab'      => 'log',
					'log_file' => $filename,
				),
				admin_url( 'options-general.php' )
			);
			?>
			<tr>
				<td><code><?php echo esc_html( $filename ); ?></code></td>
				<td><?php echo esc_html( $mtime ? wp_date( 'Y-m-d H:i:s', $mtime ) : '—' ); ?></td>
				<td><?php echo esc_html( size_format( $size, 2 ) ); ?></td>
				<td>
					<a class="button button-small" href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View', 'load-balanced-sync' ); ?></a>
					<a class="button button-small" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download', 'load-balanced-sync' ); ?></a>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php if ( '' !== $selected_file ) : ?>
	<h2>
		<?php
		printf(
			/* translators: %s: selected log filename. */
			esc_html__( 'Viewing File: %s', 'load-balanced-sync' ),
			'<code>' . esc_html( $selected_file ) . '</code>'
		);
		?>
	</h2>
<?php endif; ?>

<h2><?php esc_html_e( 'Log Viewer', 'load-balanced-sync' ); ?></h2>
<div id="lbs-log-viewer-root"></div>


<div id="lbs-log-viewer-fallback">
	<?php if ( empty( $viewer_entries ) ) : ?>
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
		<?php
		foreach ( $viewer_entries as $entry ) :
			$ts      = absint( $entry['ts'] ?? 0 );
			$level   = sanitize_key( $entry['level'] ?? 'info' );
			$message = $entry['message'] ?? '';
			$date    = $ts ? wp_date( 'Y-m-d H:i:s', $ts ) : '—';
			?>
			<tr class="lbs-log-<?php echo esc_attr( $level ); ?>">
				<td><code><?php echo esc_html( $date ); ?></code></td>
				<td><?php echo esc_html( $level_labels[ $level ] ?? $level ); ?></td>
				<td><?php echo esc_html( $message ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em">
	<input type="hidden" name="action" value="lbs_clear_log">
	<?php wp_nonce_field( 'lbs_clear_log' ); ?>
	<button type="submit" class="button button-secondary"
		onclick="return confirm('<?php echo esc_js( __( 'Clear all log entries?', 'load-balanced-sync' ) ); ?>')">
		<?php esc_html_e( 'Clear Log', 'load-balanced-sync' ); ?>
	</button>
</form>
