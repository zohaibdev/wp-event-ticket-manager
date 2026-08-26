<?php
/**
 * Fees screen: list and add/edit form.
 *
 * @package EventTicketManager
 *
 * @var string      $action Current action.
 * @var object|null $fee    Fee being edited, if any.
 */

defined( 'ABSPATH' ) || exit;

$etm_user_id = get_current_user_id();
$etm_errors  = get_transient( 'etm_fee_errors_' . $etm_user_id );

delete_transient( 'etm_fee_errors_' . $etm_user_id );

$etm_errors = is_array( $etm_errors ) ? $etm_errors : array();
?>
<div class="wrap etm-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Fees', 'event-ticket-manager' ); ?></h1>

	<?php if ( 'list' === $action ) : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-fees&action=new' ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add New', 'event-ticket-manager' ); ?>
		</a>
	<?php else : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-fees' ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Back to fees', 'event-ticket-manager' ); ?>
		</a>
	<?php endif; ?>

	<hr class="wp-header-end" />

	<?php if ( 'list' === $action ) : ?>
		<p class="description etm-intro">
			<?php esc_html_e( 'Fees are added to an order after the ticket subtotal and roommate add-on. A percentage fee is calculated from that subtotal, before any coupon.', 'event-ticket-manager' ); ?>
		</p>

		<?php $etm_fees = ETM_Fees::all(); ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Fee', 'event-ticket-manager' ); ?></th>
					<th><?php esc_html_e( 'Type', 'event-ticket-manager' ); ?></th>
					<th><?php esc_html_e( 'Value', 'event-ticket-manager' ); ?></th>
					<th><?php esc_html_e( 'Applies to', 'event-ticket-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'event-ticket-manager' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $etm_fees ) ) : ?>
					<tr>
						<td colspan="6"><?php esc_html_e( 'No fees yet.', 'event-ticket-manager' ); ?></td>
					</tr>
				<?php endif; ?>

				<?php foreach ( $etm_fees as $etm_fee ) : ?>
					<?php $etm_edit_url = admin_url( 'admin.php?page=etm-fees&action=edit&fee=' . (int) $etm_fee->id ); ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( $etm_edit_url ); ?>"><?php echo esc_html( $etm_fee->name ); ?></a></strong></td>
						<td><?php echo esc_html( ETM_Fees::types()[ $etm_fee->fee_type ] ?? $etm_fee->fee_type ); ?></td>
						<td>
							<?php
							echo esc_html(
								ETM_Fees::TYPE_PERCENTAGE === $etm_fee->fee_type
									? rtrim( rtrim( number_format( (float) $etm_fee->fee_value, 2, '.', '' ), '0' ), '.' ) . '%'
									: etm_format_price( (float) $etm_fee->fee_value )
							);
							?>
						</td>
						<td>
							<?php
							if ( ETM_Fees::SCOPE_ALL === $etm_fee->scope ) {
								esc_html_e( 'All events', 'event-ticket-manager' );
							} else {
								$etm_event = ETM_Events::get( (int) $etm_fee->event_id );

								echo esc_html( $etm_event ? $etm_event->title : __( '(deleted event)', 'event-ticket-manager' ) );
							}
							?>
						</td>
						<td>
							<?php
							echo etm_status_badge( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
								empty( $etm_fee->enabled ) ? 'closed' : 'published',
								empty( $etm_fee->enabled ) ? __( 'Disabled', 'event-ticket-manager' ) : __( 'Enabled', 'event-ticket-manager' )
							);
							?>
						</td>
						<td>
							<a href="<?php echo esc_url( $etm_edit_url ); ?>"><?php esc_html_e( 'Edit', 'event-ticket-manager' ); ?></a>
							|
							<a
								class="etm-delete submitdelete"
								href="<?php
								echo esc_url(
									wp_nonce_url(
										add_query_arg(
											array(
												'action'     => 'etm_fee_action',
												'etm_action' => 'delete',
												'fee'        => (int) $etm_fee->id,
											),
											admin_url( 'admin-post.php' )
										),
										'etm_fee_action_delete_' . (int) $etm_fee->id
									)
								);
								?>"
							><?php esc_html_e( 'Delete', 'event-ticket-manager' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="etm-form">
			<?php wp_nonce_field( 'etm_save_fee' ); ?>
			<input type="hidden" name="action" value="etm_save_fee" />
			<input type="hidden" name="fee_id" value="<?php echo esc_attr( (string) ( $fee->id ?? 0 ) ); ?>" />

			<div class="etm-card">
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="etm-fee-name"><?php esc_html_e( 'Fee Name', 'event-ticket-manager' ); ?></label></th>
							<td>
								<input type="text" id="etm-fee-name" name="name" class="regular-text" value="<?php echo esc_attr( (string) ( $fee->name ?? '' ) ); ?>" required />
								<p class="description"><?php esc_html_e( 'Shown to the customer at checkout, for example Booking Fee.', 'event-ticket-manager' ); ?></p>
								<?php if ( isset( $etm_errors['name'] ) ) : ?>
									<p class="etm-error"><?php echo esc_html( $etm_errors['name'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="etm-fee-type"><?php esc_html_e( 'Fee Type', 'event-ticket-manager' ); ?></label></th>
							<td>
								<select id="etm-fee-type" name="fee_type">
									<?php foreach ( ETM_Fees::types() as $etm_key => $etm_label ) : ?>
										<option value="<?php echo esc_attr( $etm_key ); ?>" <?php selected( (string) ( $fee->fee_type ?? '' ), $etm_key ); ?>>
											<?php echo esc_html( $etm_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="etm-fee-value"><?php esc_html_e( 'Fee Value', 'event-ticket-manager' ); ?></label></th>
							<td>
								<input type="number" id="etm-fee-value" name="fee_value" min="0" step="0.01" value="<?php echo esc_attr( (string) ( $fee->fee_value ?? '' ) ); ?>" required />
								<?php if ( isset( $etm_errors['fee_value'] ) ) : ?>
									<p class="etm-error"><?php echo esc_html( $etm_errors['fee_value'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Applies to', 'event-ticket-manager' ); ?></th>
							<td>
								<?php $etm_scope = (string) ( $fee->scope ?? ETM_Fees::SCOPE_ALL ); ?>
								<label>
									<input type="radio" name="scope" value="<?php echo esc_attr( ETM_Fees::SCOPE_ALL ); ?>" <?php checked( ETM_Fees::SCOPE_ALL, $etm_scope ); ?> data-etm-toggle-hide="#etm-fee-event" />
									<?php esc_html_e( 'All events', 'event-ticket-manager' ); ?>
								</label>
								<br />
								<label>
									<input type="radio" name="scope" value="<?php echo esc_attr( ETM_Fees::SCOPE_EVENT ); ?>" <?php checked( ETM_Fees::SCOPE_EVENT, $etm_scope ); ?> data-etm-toggle-show="#etm-fee-event" />
									<?php esc_html_e( 'One event', 'event-ticket-manager' ); ?>
								</label>

								<p id="etm-fee-event" <?php echo ETM_Fees::SCOPE_EVENT === $etm_scope ? '' : 'style="display:none;"'; ?>>
									<select name="event_id">
										<option value="0"><?php esc_html_e( 'Choose an event', 'event-ticket-manager' ); ?></option>
										<?php foreach ( ETM_Events::get_all_for_select() as $etm_event ) : ?>
											<option value="<?php echo esc_attr( (string) $etm_event->id ); ?>" <?php selected( (int) ( $fee->event_id ?? 0 ), (int) $etm_event->id ); ?>>
												<?php echo esc_html( $etm_event->title ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</p>

								<?php if ( isset( $etm_errors['event_id'] ) ) : ?>
									<p class="etm-error"><?php echo esc_html( $etm_errors['event_id'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Enabled', 'event-ticket-manager' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enabled" value="1" <?php checked( 1, (int) ( $fee->enabled ?? 1 ) ); ?> />
									<?php esc_html_e( 'Apply this fee to new orders', 'event-ticket-manager' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( $fee ? __( 'Save Fee', 'event-ticket-manager' ) : __( 'Create Fee', 'event-ticket-manager' ) ); ?>
			</div>
		</form>
	<?php endif; ?>
</div>
