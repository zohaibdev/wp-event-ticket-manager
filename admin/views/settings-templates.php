<?php
/**
 * Email templates tab.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="etm-settings-form">
	<?php wp_nonce_field( 'etm_save_templates' ); ?>
	<input type="hidden" name="action" value="etm_save_templates" />

	<p class="description etm-intro">
		<?php esc_html_e( 'Each email starts from the default copy below. Edit it freely, or clear a body completely to fall back to the plugin\'s built-in layout instead. Reset restores the default copy.', 'event-ticket-manager' ); ?>
	</p>

	<div class="etm-columns">
		<div class="etm-columns__main">
			<?php foreach ( ETM_Email_Templates::templates() as $etm_key => $etm_label ) : ?>
				<?php $etm_template = ETM_Email_Templates::get( $etm_key ); ?>

				<div class="etm-card">
					<h2 class="etm-card__title"><?php echo esc_html( $etm_label ); ?></h2>

					<div class="etm-admin-field">
						<label for="etm-subject-<?php echo esc_attr( $etm_key ); ?>">
							<?php esc_html_e( 'Email Subject', 'event-ticket-manager' ); ?>
						</label>
						<input
							type="text"
							id="etm-subject-<?php echo esc_attr( $etm_key ); ?>"
							name="templates[<?php echo esc_attr( $etm_key ); ?>][subject]"
							class="large-text"
							value="<?php echo esc_attr( $etm_template['subject'] ); ?>"
						/>
					</div>

					<div class="etm-admin-field">
						<label for="etm-body-<?php echo esc_attr( $etm_key ); ?>">
							<?php esc_html_e( 'Email Body', 'event-ticket-manager' ); ?>
						</label>
						<?php
						wp_editor(
							$etm_template['body'],
							'etm-body-' . str_replace( '_', '-', $etm_key ),
							array(
								'textarea_name' => 'templates[' . $etm_key . '][body]',
								'textarea_rows' => 8,
								'media_buttons' => false,
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'Clear this completely to use the plugin\'s built-in layout for this email instead.', 'event-ticket-manager' ); ?>
						</p>
					</div>

					<p>
						<a
							class="button"
							href="<?php
							echo esc_url(
								wp_nonce_url(
									add_query_arg(
										array(
											'action'     => 'etm_reset_template',
											'etm_action' => 'reset',
											'template'   => $etm_key,
										),
										admin_url( 'admin-post.php' )
									),
									'etm_reset_template_' . $etm_key
								)
							);
							?>"
						>
							<?php esc_html_e( 'Reset to default', 'event-ticket-manager' ); ?>
						</a>
					</p>
				</div>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save Templates', 'event-ticket-manager' ) ); ?>
		</div>

		<div class="etm-columns__side">
			<div class="etm-card etm-card--info">
				<h2 class="etm-card__title"><?php esc_html_e( 'Available variables', 'event-ticket-manager' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Use these in a subject or body. Anything unrecognised is left as written.', 'event-ticket-manager' ); ?>
				</p>

				<table class="etm-detail-table">
					<tbody>
						<?php foreach ( ETM_Email_Templates::variables() as $etm_var => $etm_desc ) : ?>
							<tr>
								<th><code class="etm-shortcode" data-etm-copy><?php echo esc_html( $etm_var ); ?></code></th>
								<td><?php echo esc_html( $etm_desc ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description">
					<?php esc_html_e( 'Payment variables stay empty for RSVP and free orders.', 'event-ticket-manager' ); ?>
				</p>
			</div>
		</div>
	</div>
</form>
