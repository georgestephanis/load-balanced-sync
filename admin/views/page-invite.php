<?php
/**
 * Pairing / invitation tab view.
 */

defined( 'ABSPATH' ) || exit;

$own_url   = LBS_Peer_Registry::get_own_real_url();
$own_group = LBS_Peer_Registry::get_own_group();

// If we just generated a token, retrieve and display it once.
$show_token = '';
if ( ! empty( $_GET['token'] ) ) {
	$transient_key = sanitize_text_field( wp_unslash( $_GET['token'] ) );
	$show_token    = get_transient( $transient_key );
	if ( $show_token ) {
		delete_transient( $transient_key );
	}
}
?>

<div class="lbs-invite-section">
	<h2><?php esc_html_e( 'Step 1 — Generate an Invitation (on this site)', 'load-balanced-sync' ); ?></h2>
	<p>
		<?php esc_html_e( 'Generate a one-time token on this site, then go to the peer site and enter this site\'s Real URL plus the token in the "Accept Invitation" form below.', 'load-balanced-sync' ); ?>
	</p>
	<p>
		<?php
		printf(
			/* translators: %s: this site's real URL */
			esc_html__( 'This site\'s Real URL: %s', 'load-balanced-sync' ),
			'<code>' . esc_html( $own_url ) . '</code>'
		);
		?>
		<br>
		<?php
		printf(
			/* translators: %s: this site's group name */
			esc_html__( 'Group: %s', 'load-balanced-sync' ),
			'<code>' . esc_html( $own_group ) . '</code>'
		);
		?>
	</p>

	<?php if ( $show_token ) : ?>
		<div class="notice notice-success">
			<p><strong><?php esc_html_e( 'Invitation token generated! Copy it now — it will not be shown again.', 'load-balanced-sync' ); ?></strong></p>
			<p>
				<input type="text" id="lbs-invite-token" value="<?php echo esc_attr( $show_token ); ?>"
					class="regular-text" readonly>
				<button type="button" class="button" data-lbs-copy="lbs-invite-token">
					<?php esc_html_e( 'Copy', 'load-balanced-sync' ); ?>
				</button>
			</p>
			<p class="description">
				<?php esc_html_e( 'Token expires in 1 hour. Share it with the admin of the peer site via a secure channel.', 'load-balanced-sync' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="lbs_generate_invite">
		<?php wp_nonce_field( 'lbs_generate_invite' ); ?>
		<?php submit_button( __( 'Generate Invitation Token', 'load-balanced-sync' ), 'secondary', 'submit', false ); ?>
	</form>
</div>

<hr>

<div class="lbs-invite-section">
	<h2><?php esc_html_e( 'Step 2 — Accept an Invitation (from another site)', 'load-balanced-sync' ); ?></h2>
	<p>
		<?php esc_html_e( 'Enter the Real URL of the site that generated the invitation and the token it gave you. This will create a mutual peer relationship between the two sites.', 'load-balanced-sync' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="lbs_accept_invite">
		<?php wp_nonce_field( 'lbs_accept_invite' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="lbs_initiator_url"><?php esc_html_e( 'Initiating Site Real URL', 'load-balanced-sync' ); ?></label>
				</th>
				<td>
					<input type="url" id="lbs_initiator_url" name="initiator_url"
						class="regular-text" placeholder="https://10.0.0.1" required>
					<p class="description">
						<?php esc_html_e( 'The direct URL of the site that generated the token (must start with https://).', 'load-balanced-sync' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="lbs_token"><?php esc_html_e( 'Invitation Token', 'load-balanced-sync' ); ?></label>
				</th>
				<td>
					<input type="text" id="lbs_token" name="token"
						class="regular-text" required autocomplete="off">
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Accept Invitation & Pair Sites', 'load-balanced-sync' ), 'primary', 'submit', false ); ?>
	</form>
</div>

<hr>

<div class="lbs-invite-section">
	<h2><?php esc_html_e( 'How it works', 'load-balanced-sync' ); ?></h2>
	<ol>
		<li><?php esc_html_e( 'Admin on Site A generates a token (Step 1 above).', 'load-balanced-sync' ); ?></li>
		<li><?php esc_html_e( 'Admin copies Site A\'s Real URL and the token to the admin of Site B out-of-band (e.g. secure chat).', 'load-balanced-sync' ); ?></li>
		<li><?php esc_html_e( 'Admin on Site B pastes them into Step 2 above.', 'load-balanced-sync' ); ?></li>
		<li><?php esc_html_e( 'Both sites exchange WordPress Application Passwords automatically and become peers.', 'load-balanced-sync' ); ?></li>
	</ol>
</div>
