<?php
/**
 * Single booking detail screen.
 *
 * @package EventTicketManager
 *
 * @var object      $booking       Booking row.
 * @var object|null $event         Event row.
 * @var object[]    $attendees     Attendee rows.
 * @var array       $custom_values Custom field values by scope.
 * @var array       $meta          All booking meta.
 */

defined( 'ABSPATH' ) || exit;

$etm_currency = (string) $booking->currency;

/**
 * Build a nonced booking action URL.
 *
 * @param string               $action Action key.
 * @param int                  $id     Booking ID.
 * @param array<string, mixed> $extra  Extra query args.
 * @return string
 */
$etm_action_url = static function ( string $action, int $id, array $extra = array() ): string {
	return wp_nonce_url(
		add_query_arg(
			array_merge(
				array(
					'action'     => 'etm_booking_action',
					'etm_action' => $action,
					'booking'    => $id,
				),
				$extra
			),
			admin_url( 'admin-post.php' )
		),
		'etm_booking_action_' . $action . '_' . $id
	);
};
?>
<div class="wrap etm-wrap">
	<h1 class="wp-heading-inline">
		<?php
		printf(
			/* translators: %s: booking number. */
			esc_html__( 'Booking %s', 'event-ticket-manager' ),
			esc_html( '' !== (string) $booking->booking_number ? (string) $booking->booking_number : '#' . (int) $booking->id )
		);
		?>
	</h1>

	<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-bookings' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Back to bookings', 'event-ticket-manager' ); ?>
	</a>

	<hr class="wp-header-end" />

	<?php if ( ! empty( $meta['capacity_warning'] ) ) : ?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Capacity warning:', 'event-ticket-manager' ); ?></strong>
				<?php echo esc_html( (string) $meta['capacity_warning'] ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $meta['amount_mismatch'] ) ) : ?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Amount mismatch:', 'event-ticket-manager' ); ?></strong>
				<?php echo esc_html( (string) $meta['amount_mismatch'] ); ?>
			</p>
		</div>
	<?php endif; ?>

	<div class="etm-columns">
		<div class="etm-columns__main">
			<div class="etm-card">
				<h2 class="etm-card__title"><?php esc_html_e( 'Booking Information', 'event-ticket-manager' ); ?></h2>

				<table class="etm-detail-table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Booking number', 'event-ticket-manager' ); ?></th>
							<td><?php echo esc_html( (string) $booking->booking_number ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Event', 'event-ticket-manager' ); ?></th>
							<td>
								<?php if ( $event ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=etm-events&action=edit&event=' . (int) $event->id ) ); ?>">
										<?php echo esc_html( $event->title ); ?>
									</a>
									<div class="etm-muted"><?php echo esc_html( etm_event_date_range( $event ) ); ?></div>
								<?php else : ?>
									<span class="etm-muted"><?php esc_html_e( '(deleted event)', 'event-ticket-manager' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Event type', 'event-ticket-manager' ); ?></th>
							<td>
								<?php
								$etm_types = ETM_Events::types();

								echo esc_html( $etm_types[ $booking->event_type ] ?? $booking->event_type );
								?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Booking date', 'event-ticket-manager' ); ?></th>
							<td><?php echo esc_html( etm_format_datetime( $booking->created_at ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Status', 'event-ticket-manager' ); ?></th>
							<td>
								<?php
								echo etm_status_badge( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
									(string) $booking->payment_status,
									etm_booking_status_label( (string) $booking->payment_status )
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="etm-card">
				<h2 class="etm-card__title"><?php esc_html_e( 'Customer Information', 'event-ticket-manager' ); ?></h2>

				<table class="etm-detail-table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Name', 'event-ticket-manager' ); ?></th>
							<td><?php echo esc_html( (string) $booking->customer_name ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Email', 'event-ticket-manager' ); ?></th>
							<td>
								<a href="mailto:<?php echo esc_attr( (string) $booking->customer_email ); ?>">
									<?php echo esc_html( (string) $booking->customer_email ); ?>
								</a>
							</td>
						</tr>
						<?php if ( ! empty( $custom_values['customer'] ) ) : ?>
							<?php foreach ( (array) $custom_values['customer'] as $etm_key => $etm_val ) : ?>
								<tr>
									<th><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $etm_key ) ) ); ?></th>
									<td><?php echo esc_html( is_scalar( $etm_val ) ? (string) $etm_val : wp_json_encode( $etm_val ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="etm-card">
				<h2 class="etm-card__title"><?php esc_html_e( 'Tickets', 'event-ticket-manager' ); ?></h2>

				<?php $etm_ticket_lines = ETM_Bookings::get_ticket_lines( (int) $booking->id ); ?>

				<?php if ( empty( $etm_ticket_lines ) ) : ?>
					<p class="etm-muted"><?php esc_html_e( 'No ticket breakdown recorded for this booking.', 'event-ticket-manager' ); ?></p>
				<?php else : ?>
					<table class="widefat striped etm-booking-tickets">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Ticket Type', 'event-ticket-manager' ); ?></th>
								<th><?php esc_html_e( 'Quantity', 'event-ticket-manager' ); ?></th>
								<th><?php esc_html_e( 'Unit Price', 'event-ticket-manager' ); ?></th>
								<th><?php esc_html_e( 'Subtotal', 'event-ticket-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $etm_ticket_lines as $etm_line ) : ?>
								<tr>
									<td><?php echo esc_html( $etm_line['title'] ); ?></td>
									<td><?php echo esc_html( (string) (int) $etm_line['quantity'] ); ?></td>
									<td><?php echo esc_html( etm_format_price( (float) $etm_line['unit_price'], $etm_currency ) ); ?></td>
									<td><?php echo esc_html( etm_format_price( (float) $etm_line['subtotal'], $etm_currency ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="etm-card">
				<h2 class="etm-card__title"><?php esc_html_e( 'Attendees', 'event-ticket-manager' ); ?></h2>

				<?php if ( empty( $attendees ) ) : ?>
					<p class="etm-muted"><?php esc_html_e( 'No attendee records for this booking.', 'event-ticket-manager' ); ?></p>
				<?php else : ?>
					<table class="widefat striped etm-detail-table">
						<thead>
							<tr>
								<th>#</th>
								<th><?php esc_html_e( 'Name', 'event-ticket-manager' ); ?></th>
								<th><?php esc_html_e( 'Email', 'event-ticket-manager' ); ?></th>
								<th><?php esc_html_e( 'Ticket Type', 'event-ticket-manager' ); ?></th>
								<th><?php esc_html_e( 'Additional', 'event-ticket-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $attendees as $etm_attendee ) : ?>
								<?php $etm_scope = 'attendee_' . (int) $etm_attendee->attendee_number; ?>
								<tr>
									<td><?php echo esc_html( (string) (int) $etm_attendee->attendee_number ); ?></td>
									<td><?php echo esc_html( $etm_attendee->name ); ?></td>
									<td><?php echo esc_html( $etm_attendee->email ); ?></td>
									<td>
										<?php if ( ! empty( $etm_attendee->ticket_title_snapshot ) ) : ?>
											<?php echo esc_html( $etm_attendee->ticket_title_snapshot ); ?>
										<?php else : ?>
											<span class="etm-muted">&mdash;</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $custom_values[ $etm_scope ] ) ) : ?>
											<?php foreach ( (array) $custom_values[ $etm_scope ] as $etm_key => $etm_val ) : ?>
												<div>
													<span class="etm-muted"><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $etm_key ) ) ); ?>:</span>
													<?php echo esc_html( is_scalar( $etm_val ) ? (string) $etm_val : wp_json_encode( $etm_val ) ); ?>
												</div>
											<?php endforeach; ?>
										<?php else : ?>
											<span class="etm-muted">&mdash;</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php
			$etm_roommates = ETM_Bookings::get_roommates( $booking );

			// Only a booking sold the old add-on has a roommate price of its own;
			// a Sharing a Room ticket is priced in the ticket lines.
			$etm_roommate_charge = (float) $booking->roommate_price;
			?>
			<?php if ( ! empty( $etm_roommates ) ) : ?>
				<div class="etm-card">
					<h2 class="etm-card__title"><?php esc_html_e( 'Roommate Information', 'event-ticket-manager' ); ?></h2>

					<table class="etm-detail-table">
						<tbody>
							<?php foreach ( $etm_roommates as $etm_roommate_index => $etm_roommate ) : ?>
								<?php
								// Roommate 1 kept the original scope, so a booking
								// taken before quantities still shows its extras.
								$etm_scope = 0 === (int) $etm_roommate_index ? 'roommate' : 'roommate_' . ( (int) $etm_roommate_index + 1 );
								?>
								<tr>
									<th>
										<?php
										if ( count( $etm_roommates ) > 1 ) {
											printf(
												/* translators: %d: roommate number. */
												esc_html__( 'Roommate %d', 'event-ticket-manager' ),
												(int) $etm_roommate_index + 1
											);
										} else {
											esc_html_e( 'Roommate', 'event-ticket-manager' );
										}
										?>
									</th>
									<td>
										<?php echo esc_html( $etm_roommate['name'] ); ?>
										<span class="etm-muted">(<?php echo esc_html( $etm_roommate['email'] ); ?>)</span>
									</td>
								</tr>
								<?php if ( ! empty( $custom_values[ $etm_scope ] ) ) : ?>
									<?php foreach ( (array) $custom_values[ $etm_scope ] as $etm_key => $etm_val ) : ?>
										<tr>
											<th><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $etm_key ) ) ); ?></th>
											<td><?php echo esc_html( is_scalar( $etm_val ) ? (string) $etm_val : wp_json_encode( $etm_val ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							<?php endforeach; ?>
							<?php if ( $etm_roommate_charge > 0 ) : ?>
								<tr>
									<th><?php esc_html_e( 'Quantity', 'event-ticket-manager' ); ?></th>
									<td><?php echo esc_html( (string) ETM_Bookings::roommate_quantity( $booking ) ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Price per roommate', 'event-ticket-manager' ); ?></th>
									<td><?php echo esc_html( etm_format_price( ETM_Bookings::roommate_unit_price( $booking ), $etm_currency ) ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Add-on total', 'event-ticket-manager' ); ?></th>
									<td><?php echo esc_html( etm_format_price( $etm_roommate_charge, $etm_currency ) ); ?></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<div class="etm-columns__side">
			<div class="etm-card">
				<h2 class="etm-card__title"><?php esc_html_e( 'Payment Information', 'event-ticket-manager' ); ?></h2>

				<table class="etm-detail-table">
					<tbody>
						<?php
						// One line per ticket type. The booking's own ticket_price
						// column only holds a meaningful unit price for orders with
						// a single type, so it is never shown directly.
						$etm_payment_lines = isset( $etm_ticket_lines )
							? $etm_ticket_lines
							: ETM_Bookings::get_ticket_lines( (int) $booking->id );
						?>
						<?php foreach ( $etm_payment_lines as $etm_pay_line ) : ?>
							<tr>
								<th>
									<?php echo esc_html( $etm_pay_line['title'] ); ?>
									&times; <?php echo esc_html( (string) (int) $etm_pay_line['quantity'] ); ?>
									<div class="etm-muted">
										<?php
										printf(
											/* translators: 1: unit price, 2: the variation's price label, e.g. "per person". */
											esc_html__( '%1$s %2$s', 'event-ticket-manager' ),
											esc_html( etm_format_price( (float) $etm_pay_line['unit_price'], $etm_currency ) ),
											esc_html( (string) ( $etm_pay_line['price_label'] ?? '' ) )
										);
										?>
									</div>
								</th>
								<td><?php echo esc_html( etm_format_price( (float) $etm_pay_line['subtotal'], $etm_currency ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th><?php esc_html_e( 'Total tickets', 'event-ticket-manager' ); ?></th>
							<td><?php echo esc_html( (string) (int) $booking->ticket_quantity ); ?></td>
						</tr>
						<?php $etm_fee_lines = ETM_Bookings::get_meta( (int) $booking->id, 'fee_lines', array() ); ?>
						<?php foreach ( (array) $etm_fee_lines as $etm_fee ) : ?>
							<tr>
								<th><?php echo esc_html( (string) $etm_fee['name'] ); ?></th>
								<td><?php echo esc_html( etm_format_price( (float) $etm_fee['amount'], $etm_currency ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( (float) $booking->fees_total > 0 && empty( $etm_fee_lines ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'Fees', 'event-ticket-manager' ); ?></th>
								<td><?php echo esc_html( etm_format_price( (float) $booking->fees_total, $etm_currency ) ); ?></td>
							</tr>
						<?php endif; ?>
						<?php if ( ! empty( $booking->coupon_code ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'Coupon', 'event-ticket-manager' ); ?></th>
								<td>
									<code><?php echo esc_html( (string) $booking->coupon_code ); ?></code>
									&minus;<?php echo esc_html( etm_format_price( (float) $booking->discount_total, $etm_currency ) ); ?>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th><?php esc_html_e( 'Ticket subtotal', 'event-ticket-manager' ); ?></th>
							<td><?php echo esc_html( etm_format_price( (float) $booking->ticket_subtotal, $etm_currency ) ); ?></td>
						</tr>
						<?php if ( (float) $booking->roommate_price > 0 ) : ?>
							<tr>
								<th>
									<?php esc_html_e( 'Roommate', 'event-ticket-manager' ); ?>
									&times; <?php echo esc_html( (string) ETM_Bookings::roommate_quantity( $booking ) ); ?>
								</th>
								<td><?php echo esc_html( etm_format_price( (float) $booking->roommate_price, $etm_currency ) ); ?></td>
							</tr>
						<?php endif; ?>
						<tr>
							<th><?php esc_html_e( 'Total', 'event-ticket-manager' ); ?></th>
							<td><strong><?php echo esc_html( etm_format_price( (float) $booking->total, $etm_currency ) ); ?></strong></td>
						</tr>
						<?php if ( ! empty( $booking->stripe_payment_intent_id ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'Transaction ID', 'event-ticket-manager' ); ?></th>
								<td><code class="etm-code"><?php echo esc_html( (string) $booking->stripe_payment_intent_id ); ?></code></td>
							</tr>
						<?php endif; ?>
						<?php if ( ! empty( $booking->stripe_checkout_session_id ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'Checkout session', 'event-ticket-manager' ); ?></th>
								<td><code class="etm-code"><?php echo esc_html( (string) $booking->stripe_checkout_session_id ); ?></code></td>
							</tr>
						<?php endif; ?>
						<?php if ( ! empty( $meta['refunded_amount'] ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'Refunded', 'event-ticket-manager' ); ?></th>
								<td><?php echo esc_html( etm_format_price( (float) $meta['refunded_amount'], $etm_currency ) ); ?></td>
							</tr>
						<?php endif; ?>
						<?php if ( ! empty( $meta['stripe_mode'] ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'Mode', 'event-ticket-manager' ); ?></th>
								<td><?php echo esc_html( (string) $meta['stripe_mode'] ); ?></td>
							</tr>
						<?php endif; ?>
						<?php if ( ! empty( $meta['failure_reason'] ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'Failure reason', 'event-ticket-manager' ); ?></th>
								<td><?php echo esc_html( (string) $meta['failure_reason'] ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="etm-card">
				<h2 class="etm-card__title"><?php esc_html_e( 'Actions', 'event-ticket-manager' ); ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="etm-status-form">
					<input type="hidden" name="action" value="etm_booking_action" />
					<input type="hidden" name="etm_action" value="set_status" />
					<input type="hidden" name="booking" value="<?php echo esc_attr( (string) (int) $booking->id ); ?>" />
					<?php wp_nonce_field( 'etm_booking_action_set_status_' . (int) $booking->id ); ?>

					<label for="etm-booking-status"><?php esc_html_e( 'Change status', 'event-ticket-manager' ); ?></label>
					<select id="etm-booking-status" name="status">
						<?php foreach ( ETM_Bookings::statuses() as $etm_key => $etm_label ) : ?>
							<option value="<?php echo esc_attr( $etm_key ); ?>" <?php selected( (string) $booking->payment_status, $etm_key ); ?>>
								<?php echo esc_html( $etm_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<button type="submit" class="button"><?php esc_html_e( 'Update', 'event-ticket-manager' ); ?></button>
				</form>

				<p>
					<a class="button" href="<?php echo esc_url( $etm_action_url( 'resend_customer_email', (int) $booking->id ) ); ?>">
						<?php esc_html_e( 'Resend confirmation email', 'event-ticket-manager' ); ?>
					</a>
				</p>

				<p>
					<a
						class="button button-link-delete etm-delete-booking"
						href="<?php echo esc_url( $etm_action_url( 'delete', (int) $booking->id ) ); ?>"
					>
						<?php esc_html_e( 'Delete booking', 'event-ticket-manager' ); ?>
					</a>
				</p>
			</div>
		</div>
	</div>
</div>
