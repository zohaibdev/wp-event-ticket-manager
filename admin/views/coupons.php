<?php
/**
 * Coupons screen: list and add/edit form.
 *
 * @package EventTicketManager
 *
 * @var string      $action Current action.
 * @var object|null $coupon Coupon being edited, if any.
 */

defined( 'ABSPATH' ) || exit;

$etm_user_id = get_current_user_id();
$etm_errors  = get_transient( 'etm_coupon_errors_' . $etm_user_id );

delete_transient( 'etm_coupon_errors_' . $etm_user_id );

$etm_errors = is_array( $etm_errors ) ? $etm_errors : array();

$etm_datetime = static function ( $value ): string {
	if ( empty( $value ) || '0000-00-00 00:00:00' === $value ) {
		return '';
	}

	$timestamp = strtotime( (string) $value );

	return $timestamp ? gmdate( 'Y-m-d\TH:i', $timestamp ) : '';
};
?>
<div class="wrap etm-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Coupons', 'event-ticket-manager' ); ?></h1>

	<?php if ( 'list' === $action ) : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-coupons&action=new' ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add New', 'event-ticket-manager' ); ?>
		</a>
	<?php else : ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-coupons' ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Back to coupons', 'event-ticket-manager' ); ?>
		</a>
	<?php endif; ?>

	<hr class="wp-header-end" />

	<?php if ( 'list' === $action ) : ?>
		<?php $etm_coupons = ETM_Coupons::all(); ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'event-ticket-manager' ); ?></th>
					<th><?php esc_html_e( 'Discount', 'event-ticket-manager' ); ?></th>
					<th><?php esc_html_e( 'Events', 'event-ticket-manager' ); ?></th>
					<th><?php esc_html_e( 'Used', 'event-ticket-manager' ); ?></th>
					<th><?php esc_html_e( 'Active', 'event-ticket-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'event-ticket-manager' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $etm_coupons ) ) : ?>
					<tr>
						<td colspan="7"><?php esc_html_e( 'No coupons yet.', 'event-ticket-manager' ); ?></td>
					</tr>
				<?php endif; ?>

				<?php foreach ( $etm_coupons as $etm_coupon ) : ?>
					<?php
					$etm_edit_url = admin_url( 'admin.php?page=etm-coupons&action=edit&coupon=' . (int) $etm_coupon->id );
					$etm_used     = ETM_Coupons::usage_count( (string) $etm_coupon->code );
					$etm_limit    = (int) $etm_coupon->usage_limit;
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( $etm_edit_url ); ?>"><?php echo esc_html( $etm_coupon->code ); ?></a></strong>
						</td>
						<td><?php echo esc_html( ETM_Coupons::describe( $etm_coupon ) ); ?></td>
						<td>
							<?php
							if ( ETM_Coupons::SCOPE_ALL === $etm_coupon->event_scope ) {
								esc_html_e( 'All events', 'event-ticket-manager' );
							} else {
								printf(
									/* translators: %d: number of events. */
									esc_html__( '%d selected', 'event-ticket-manager' ),
									count( ETM_Coupons::event_ids( $etm_coupon ) )
								);
							}
							?>
						</td>
						<td>
							<?php
							echo esc_html(
								$etm_limit > 0
									? $etm_used . ' / ' . $etm_limit
									: (string) $etm_used
							);
							?>
						</td>
						<td>
							<?php
							$etm_window = array();

							if ( ! empty( $etm_coupon->start_date ) ) {
								$etm_window[] = etm_format_datetime( $etm_coupon->start_date, false );
							}

							if ( ! empty( $etm_coupon->end_date ) ) {
								$etm_window[] = etm_format_datetime( $etm_coupon->end_date, false );
							}

							echo esc_html( empty( $etm_window ) ? __( 'Always', 'event-ticket-manager' ) : implode( ' - ', $etm_window ) );
							?>
						</td>
						<td>
							<?php
							echo etm_status_badge( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
								empty( $etm_coupon->enabled ) ? 'closed' : 'published',
								empty( $etm_coupon->enabled ) ? __( 'Disabled', 'event-ticket-manager' ) : __( 'Enabled', 'event-ticket-manager' )
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
												'action'     => 'etm_coupon_action',
												'etm_action' => 'delete',
												'coupon'     => (int) $etm_coupon->id,
											),
											admin_url( 'admin-post.php' )
										),
										'etm_coupon_action_delete_' . (int) $etm_coupon->id
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
			<?php wp_nonce_field( 'etm_save_coupon' ); ?>
			<input type="hidden" name="action" value="etm_save_coupon" />
			<input type="hidden" name="coupon_id" value="<?php echo esc_attr( (string) ( $coupon->id ?? 0 ) ); ?>" />

			<div class="etm-card">
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="etm-coupon-code"><?php esc_html_e( 'Coupon Code', 'event-ticket-manager' ); ?></label></th>
							<td>
								<input
									type="text"
									id="etm-coupon-code"
									name="code"
									class="regular-text"
									value="<?php echo esc_attr( (string) ( $coupon->code ?? '' ) ); ?>"
									required
								/>
								<p class="description"><?php esc_html_e( 'Letters, numbers, dashes and underscores. Customers may type it in any case.', 'event-ticket-manager' ); ?></p>
								<?php if ( isset( $etm_errors['code'] ) ) : ?>
									<p class="etm-error"><?php echo esc_html( $etm_errors['code'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="etm-coupon-type"><?php esc_html_e( 'Discount Type', 'event-ticket-manager' ); ?></label></th>
							<td>
								<select id="etm-coupon-type" name="discount_type">
									<?php foreach ( ETM_Coupons::types() as $etm_key => $etm_label ) : ?>
										<option value="<?php echo esc_attr( $etm_key ); ?>" <?php selected( (string) ( $coupon->discount_type ?? '' ), $etm_key ); ?>>
											<?php echo esc_html( $etm_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="etm-coupon-value"><?php esc_html_e( 'Discount Value', 'event-ticket-manager' ); ?></label></th>
							<td>
								<input
									type="number"
									id="etm-coupon-value"
									name="discount_value"
									min="0"
									step="0.01"
									value="<?php echo esc_attr( (string) ( $coupon->discount_value ?? '' ) ); ?>"
									required
								/>
								<p class="description"><?php esc_html_e( 'A percentage discount is taken off the ticket subtotal plus roommate add-on, before fees.', 'event-ticket-manager' ); ?></p>
								<?php if ( isset( $etm_errors['discount_value'] ) ) : ?>
									<p class="etm-error"><?php echo esc_html( $etm_errors['discount_value'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="etm-coupon-usage"><?php esc_html_e( 'Usage Limit', 'event-ticket-manager' ); ?></label></th>
							<td>
								<input type="number" id="etm-coupon-usage" name="usage_limit" min="0" step="1" value="<?php echo esc_attr( (string) ( $coupon->usage_limit ?? 0 ) ); ?>" />
								<p class="description"><?php esc_html_e( '0 means unlimited.', 'event-ticket-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="etm-coupon-per-customer"><?php esc_html_e( 'Per Customer Limit', 'event-ticket-manager' ); ?></label></th>
							<td>
								<input type="number" id="etm-coupon-per-customer" name="per_customer_limit" min="0" step="1" value="<?php echo esc_attr( (string) ( $coupon->per_customer_limit ?? 0 ) ); ?>" />
								<p class="description"><?php esc_html_e( '0 means unlimited. Counted by customer email address.', 'event-ticket-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="etm-coupon-start"><?php esc_html_e( 'Start Date', 'event-ticket-manager' ); ?></label></th>
							<td>
								<input type="datetime-local" id="etm-coupon-start" name="start_date" value="<?php echo esc_attr( $etm_datetime( $coupon->start_date ?? '' ) ); ?>" />
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="etm-coupon-end"><?php esc_html_e( 'End Date', 'event-ticket-manager' ); ?></label></th>
							<td>
								<input type="datetime-local" id="etm-coupon-end" name="end_date" value="<?php echo esc_attr( $etm_datetime( $coupon->end_date ?? '' ) ); ?>" />
								<?php if ( isset( $etm_errors['end_date'] ) ) : ?>
									<p class="etm-error"><?php echo esc_html( $etm_errors['end_date'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Events', 'event-ticket-manager' ); ?></th>
							<td>
								<?php $etm_scope = (string) ( $coupon->event_scope ?? ETM_Coupons::SCOPE_ALL ); ?>
								<label>
									<input type="radio" name="event_scope" value="<?php echo esc_attr( ETM_Coupons::SCOPE_ALL ); ?>" <?php checked( ETM_Coupons::SCOPE_ALL, $etm_scope ); ?> data-etm-toggle-hide="#etm-coupon-events" />
									<?php esc_html_e( 'All events', 'event-ticket-manager' ); ?>
								</label>
								<br />
								<label>
									<input type="radio" name="event_scope" value="<?php echo esc_attr( ETM_Coupons::SCOPE_SPECIFIC ); ?>" <?php checked( ETM_Coupons::SCOPE_SPECIFIC, $etm_scope ); ?> data-etm-toggle-show="#etm-coupon-events" />
									<?php esc_html_e( 'Specific events', 'event-ticket-manager' ); ?>
								</label>

								<?php $etm_selected = $coupon ? ETM_Coupons::event_ids( $coupon ) : array(); ?>
								<div id="etm-coupon-events" class="etm-checkbox-list" <?php echo ETM_Coupons::SCOPE_SPECIFIC === $etm_scope ? '' : 'style="display:none;"'; ?>>
									<?php foreach ( ETM_Events::get_all_for_select() as $etm_event ) : ?>
										<label>
											<input
												type="checkbox"
												name="event_ids[]"
												value="<?php echo esc_attr( (string) $etm_event->id ); ?>"
												<?php checked( in_array( (int) $etm_event->id, $etm_selected, true ) ); ?>
											/>
											<?php echo esc_html( $etm_event->title ); ?>
										</label>
									<?php endforeach; ?>
								</div>

								<?php if ( isset( $etm_errors['event_ids'] ) ) : ?>
									<p class="etm-error"><?php echo esc_html( $etm_errors['event_ids'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Enabled', 'event-ticket-manager' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enabled" value="1" <?php checked( 1, (int) ( $coupon->enabled ?? 1 ) ); ?> />
									<?php esc_html_e( 'Customers can use this coupon', 'event-ticket-manager' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( $coupon ? __( 'Save Coupon', 'event-ticket-manager' ) : __( 'Create Coupon', 'event-ticket-manager' ) ); ?>
			</div>
		</form>
	<?php endif; ?>
</div>
