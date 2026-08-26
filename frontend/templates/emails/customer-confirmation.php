<?php
/**
 * Customer booking confirmation email.
 *
 * Override by copying to {theme}/event-ticket-manager/emails/customer-confirmation.php.
 *
 * @package EventTicketManager
 *
 * @var object      $booking       Booking row.
 * @var object|null $event         Event row.
 * @var object[]    $attendees     Attendee rows.
 * @var array       $tickets       Ticket-type breakdown.
 * @var array       $fee_lines     Fee lines applied to the order.
 * @var array       $custom_fields Custom field values by scope.
 * @var string      $currency      Currency code.
 * @var string      $heading       Email heading.
 * @var string      $intro         Intro paragraph.
 */

defined( 'ABSPATH' ) || exit;

$etm_cell  = 'padding:10px 0;font-size:14px;color:#1f2933;border-bottom:1px solid #e4e7eb;';
$etm_label = 'padding:10px 0;font-size:14px;color:#616e7c;border-bottom:1px solid #e4e7eb;';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $heading ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;padding:24px 12px;">
		<tr>
			<td align="center">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e4e7eb;">
					<tr>
						<td style="padding:28px 32px 8px;">
							<h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;color:#111827;">
								<?php echo esc_html( $heading ); ?>
							</h1>
							<p style="margin:0;font-size:15px;line-height:1.6;color:#4b5563;">
								<?php echo wp_kses_post( $intro ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<td style="padding:16px 32px;">
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:8px;padding:16px;">
								<tr>
									<td style="padding:12px 16px;font-size:13px;color:#616e7c;text-transform:uppercase;letter-spacing:.04em;">
										<?php esc_html_e( 'Booking number', 'event-ticket-manager' ); ?>
									</td>
								</tr>
								<tr>
									<td style="padding:0 16px 14px;font-size:20px;font-weight:700;color:#111827;letter-spacing:.02em;">
										<?php echo esc_html( (string) $booking->booking_number ); ?>
									</td>
								</tr>
							</table>
						</td>
					</tr>

					<tr>
						<td style="padding:8px 32px 0;">
							<h2 style="margin:0 0 4px;font-size:16px;color:#111827;"><?php esc_html_e( 'Event details', 'event-ticket-manager' ); ?></h2>
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
								<?php if ( $event ) : ?>
									<tr>
										<td style="<?php echo esc_attr( $etm_label ); ?>"><?php esc_html_e( 'Event', 'event-ticket-manager' ); ?></td>
										<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>"><?php echo esc_html( $event->title ); ?></td>
									</tr>
									<tr>
										<td style="<?php echo esc_attr( $etm_label ); ?>"><?php esc_html_e( 'Date', 'event-ticket-manager' ); ?></td>
										<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>"><?php echo esc_html( etm_format_datetime( $event->start_datetime, false ) ); ?></td>
									</tr>
									<tr>
										<td style="<?php echo esc_attr( $etm_label ); ?>"><?php esc_html_e( 'Time', 'event-ticket-manager' ); ?></td>
										<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>"><?php echo esc_html( etm_event_date_range( $event ) ); ?></td>
									</tr>
								<?php endif; ?>
								<tr>
									<td style="<?php echo esc_attr( $etm_label ); ?>"><?php esc_html_e( 'Name', 'event-ticket-manager' ); ?></td>
									<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>"><?php echo esc_html( (string) $booking->customer_name ); ?></td>
								</tr>
							</table>
						</td>
					</tr>

					<?php if ( ! empty( $attendees ) ) : ?>
						<tr>
							<td style="padding:20px 32px 0;">
								<h2 style="margin:0 0 4px;font-size:16px;color:#111827;"><?php esc_html_e( 'Attendees', 'event-ticket-manager' ); ?></h2>
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
									<?php foreach ( $attendees as $attendee ) : ?>
										<tr>
											<td style="<?php echo esc_attr( $etm_label ); ?>">
												<?php
												printf(
													/* translators: %d: attendee number. */
													esc_html__( 'Attendee %d', 'event-ticket-manager' ),
													(int) $attendee->attendee_number
												);
												?>
											</td>
												<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>">
												<?php echo esc_html( $attendee->name ); ?><br />
												<span style="color:#616e7c;font-size:13px;"><?php echo esc_html( $attendee->email ); ?></span>
												<?php if ( ! empty( $attendee->ticket_title_snapshot ) ) : ?>
													<br /><span style="color:#616e7c;font-size:12px;"><?php echo esc_html( $attendee->ticket_title_snapshot ); ?></span>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</table>
							</td>
						</tr>
					<?php endif; ?>

					<?php $etm_roommates = ETM_Bookings::get_roommates( $booking ); ?>
					<?php if ( ! empty( $etm_roommates ) ) : ?>
						<tr>
							<td style="padding:20px 32px 0;">
								<h2 style="margin:0 0 4px;font-size:16px;color:#111827;">
									<?php echo esc_html( _n( 'Roommate', 'Roommates', count( $etm_roommates ), 'event-ticket-manager' ) ); ?>
								</h2>
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
									<?php foreach ( $etm_roommates as $etm_roommate_index => $etm_roommate ) : ?>
										<tr>
											<td style="<?php echo esc_attr( $etm_label ); ?>">
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
											</td>
											<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>">
												<?php echo esc_html( $etm_roommate['name'] ); ?>
												<span style="color:#8492a6;font-size:12px;">(<?php echo esc_html( $etm_roommate['email'] ); ?>)</span>
											</td>
										</tr>
									<?php endforeach; ?>
								</table>
							</td>
						</tr>
					<?php endif; ?>

					<tr>
						<td style="padding:20px 32px 8px;">
							<h2 style="margin:0 0 4px;font-size:16px;color:#111827;"><?php esc_html_e( 'Payment summary', 'event-ticket-manager' ); ?></h2>
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
								<?php foreach ( (array) $tickets as $etm_line ) : ?>
									<tr>
										<td style="<?php echo esc_attr( $etm_label ); ?>">
											<?php echo esc_html( $etm_line['title'] ); ?>
											&times; <?php echo esc_html( (string) (int) $etm_line['quantity'] ); ?>
											<span style="color:#8492a6;font-size:12px;">
												(<?php echo esc_html( etm_format_price( (float) $etm_line['unit_price'], $currency ) ); ?>
												<?php echo esc_html( (string) ( $etm_line['price_label'] ?? '' ) ); ?>)
											</span>
										</td>
										<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>">
											<?php echo esc_html( etm_format_price( (float) $etm_line['subtotal'], $currency ) ); ?>
										</td>
									</tr>
								<?php endforeach; ?>
								<tr>
									<td style="<?php echo esc_attr( $etm_label ); ?>"><?php esc_html_e( 'Ticket subtotal', 'event-ticket-manager' ); ?></td>
									<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>"><?php echo esc_html( etm_format_price( (float) $booking->ticket_subtotal, $currency ) ); ?></td>
								</tr>
								<?php $etm_roommate_qty = ETM_Bookings::roommate_quantity( $booking ); ?>
								<?php if ( (float) $booking->roommate_price > 0 ) : ?>
									<tr>
										<td style="<?php echo esc_attr( $etm_label ); ?>">
											<?php esc_html_e( 'Roommate add-on', 'event-ticket-manager' ); ?>
											&times; <?php echo esc_html( (string) $etm_roommate_qty ); ?>
											<span style="color:#8492a6;font-size:12px;">
												(<?php echo esc_html( etm_format_price( ETM_Bookings::roommate_unit_price( $booking ), $currency ) ); ?>)
											</span>
										</td>
										<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>"><?php echo esc_html( etm_format_price( (float) $booking->roommate_price, $currency ) ); ?></td>
									</tr>
								<?php endif; ?>
								<?php foreach ( (array) $fee_lines as $etm_fee ) : ?>
									<tr>
										<td style="<?php echo esc_attr( $etm_label ); ?>"><?php echo esc_html( (string) $etm_fee['name'] ); ?></td>
										<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>">
											<?php echo esc_html( etm_format_price( (float) $etm_fee['amount'], $currency ) ); ?>
										</td>
									</tr>
								<?php endforeach; ?>
								<?php if ( (float) $booking->discount_total > 0 ) : ?>
									<tr>
										<td style="<?php echo esc_attr( $etm_label ); ?>">
											<?php
											printf(
												/* translators: %s: coupon code. */
												esc_html__( 'Coupon %s', 'event-ticket-manager' ),
												esc_html( (string) $booking->coupon_code )
											);
											?>
										</td>
										<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>">
											-<?php echo esc_html( etm_format_price( (float) $booking->discount_total, $currency ) ); ?>
										</td>
									</tr>
								<?php endif; ?>
								<tr>
									<td style="<?php echo esc_attr( $etm_label ); ?>"><?php esc_html_e( 'Payment status', 'event-ticket-manager' ); ?></td>
									<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>">
										<?php echo esc_html( etm_booking_status_label( (string) $booking->payment_status ) ); ?>
									</td>
								</tr>
								<tr>
									<td style="padding:12px 0;font-size:16px;font-weight:700;color:#111827;"><?php esc_html_e( 'Total paid', 'event-ticket-manager' ); ?></td>
									<td align="right" style="padding:12px 0;font-size:16px;font-weight:700;color:#111827;">
										<?php echo esc_html( etm_format_price( (float) $booking->total, $currency ) ); ?>
									</td>
								</tr>
							</table>
						</td>
					</tr>

					<?php if ( ! empty( $custom_fields ) ) : ?>
						<tr>
							<td style="padding:8px 32px 0;">
								<h2 style="margin:0 0 4px;font-size:16px;color:#111827;"><?php esc_html_e( 'Additional information', 'event-ticket-manager' ); ?></h2>
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
									<?php foreach ( $custom_fields as $scope => $values ) : ?>
										<?php foreach ( (array) $values as $key => $value ) : ?>
											<tr>
												<td style="<?php echo esc_attr( $etm_label ); ?>">
													<?php echo esc_html( ucwords( str_replace( '_', ' ', $scope . ' - ' . $key ) ) ); ?>
												</td>
												<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>">
													<?php echo esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ); ?>
												</td>
											</tr>
										<?php endforeach; ?>
									<?php endforeach; ?>
								</table>
							</td>
						</tr>
					<?php endif; ?>

					<tr>
						<td style="padding:24px 32px 32px;">
							<p style="margin:0;font-size:13px;line-height:1.6;color:#8492a6;">
								<?php
								printf(
									/* translators: %s: site name. */
									esc_html__( 'This confirmation was sent by %s. Please keep it for your records.', 'event-ticket-manager' ),
									esc_html( get_bloginfo( 'name' ) )
								);
								?>
							</p>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
