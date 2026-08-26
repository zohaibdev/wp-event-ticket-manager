<?php
/**
 * Admin new booking notification email.
 *
 * Override by copying to {theme}/event-ticket-manager/emails/admin-notification.php.
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
 * @var string      $admin_link    Link to the booking detail screen.
 * @var string      $warning       Capacity warning, if any.
 */

defined( 'ABSPATH' ) || exit;

$etm_cell  = 'padding:9px 0;font-size:14px;color:#1f2933;border-bottom:1px solid #e4e7eb;';
$etm_label = 'padding:9px 0;font-size:14px;color:#616e7c;border-bottom:1px solid #e4e7eb;';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php esc_html_e( 'New booking', 'event-ticket-manager' ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;padding:24px 12px;">
		<tr>
			<td align="center">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;border:1px solid #e4e7eb;">
					<tr>
						<td style="padding:28px 32px 4px;">
							<h1 style="margin:0 0 6px;font-size:20px;color:#111827;">
								<?php esc_html_e( 'New paid booking', 'event-ticket-manager' ); ?>
							</h1>
							<p style="margin:0;font-size:14px;color:#4b5563;">
								<?php echo esc_html( (string) $booking->booking_number ); ?>
							</p>
						</td>
					</tr>

					<?php if ( ! empty( $warning ) ) : ?>
						<tr>
							<td style="padding:12px 32px 0;">
								<p style="margin:0;padding:12px 14px;background:#fff4e5;border:1px solid #f7c48b;border-radius:8px;font-size:13px;color:#8a4b08;">
									<strong><?php esc_html_e( 'Capacity warning:', 'event-ticket-manager' ); ?></strong>
									<?php echo esc_html( $warning ); ?>
								</p>
							</td>
						</tr>
					<?php endif; ?>

					<tr>
						<td style="padding:16px 32px 0;">
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
								<tr>
									<td style="<?php echo esc_attr( $etm_label ); ?>"><?php esc_html_e( 'Event', 'event-ticket-manager' ); ?></td>
									<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>"><?php echo esc_html( $event ? $event->title : '-' ); ?></td>
								</tr>
								<tr>
									<td style="<?php echo esc_attr( $etm_label ); ?>"><?php esc_html_e( 'Customer', 'event-ticket-manager' ); ?></td>
									<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>">
										<?php echo esc_html( (string) $booking->customer_name ); ?><br />
										<span style="color:#616e7c;font-size:13px;"><?php echo esc_html( (string) $booking->customer_email ); ?></span>
									</td>
								</tr>
								<?php foreach ( (array) $tickets as $etm_line ) : ?>
									<tr>
										<td style="<?php echo esc_attr( $etm_label ); ?>">
											<?php echo esc_html( $etm_line['title'] ); ?>
											&times; <?php echo esc_html( (string) (int) $etm_line['quantity'] ); ?>
										</td>
										<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>">
											<?php echo esc_html( etm_format_price( (float) $etm_line['subtotal'], $currency ) ); ?>
										</td>
									</tr>
								<?php endforeach; ?>
								<tr>
									<td style="<?php echo esc_attr( $etm_label ); ?>"><?php esc_html_e( 'Ticket quantity', 'event-ticket-manager' ); ?></td>
									<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>"><?php echo esc_html( (string) (int) $booking->ticket_quantity ); ?></td>
								</tr>
								<?php $etm_roommates = ETM_Bookings::get_roommates( $booking ); ?>
								<?php if ( empty( $etm_roommates ) ) : ?>
									<tr>
										<td style="<?php echo esc_attr( $etm_label ); ?>"><?php esc_html_e( 'Roommate', 'event-ticket-manager' ); ?></td>
										<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>"><?php esc_html_e( 'No', 'event-ticket-manager' ); ?></td>
									</tr>
								<?php else : ?>
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
												<span style="color:#616e7c;font-size:13px;">(<?php echo esc_html( $etm_roommate['email'] ); ?>)</span>
											</td>
										</tr>
									<?php endforeach; ?>
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
									<td style="<?php echo esc_attr( $etm_label ); ?>"><?php esc_html_e( 'Total', 'event-ticket-manager' ); ?></td>
									<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>"><strong><?php echo esc_html( etm_format_price( (float) $booking->total, $currency ) ); ?></strong></td>
								</tr>
								<tr>
									<td style="<?php echo esc_attr( $etm_label ); ?>"><?php esc_html_e( 'Stripe payment ID', 'event-ticket-manager' ); ?></td>
									<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>">
										<span style="font-family:monospace;font-size:12px;"><?php echo esc_html( (string) $booking->stripe_payment_intent_id ); ?></span>
									</td>
								</tr>
							</table>
						</td>
					</tr>

					<?php if ( ! empty( $attendees ) ) : ?>
						<tr>
							<td style="padding:20px 32px 0;">
								<h2 style="margin:0 0 4px;font-size:15px;color:#111827;"><?php esc_html_e( 'Attendees', 'event-ticket-manager' ); ?></h2>
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
									<?php foreach ( $attendees as $attendee ) : ?>
										<tr>
											<td style="<?php echo esc_attr( $etm_label ); ?>">#<?php echo esc_html( (string) (int) $attendee->attendee_number ); ?></td>
											<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>">
												<?php echo esc_html( $attendee->name ); ?>
												<span style="color:#616e7c;font-size:13px;">(<?php echo esc_html( $attendee->email ); ?>)</span>
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

					<?php if ( ! empty( $custom_fields ) ) : ?>
						<tr>
							<td style="padding:20px 32px 0;">
								<h2 style="margin:0 0 4px;font-size:15px;color:#111827;"><?php esc_html_e( 'Custom fields', 'event-ticket-manager' ); ?></h2>
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
									<?php foreach ( $custom_fields as $scope => $values ) : ?>
										<?php foreach ( (array) $values as $key => $value ) : ?>
											<tr>
												<td style="<?php echo esc_attr( $etm_label ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $scope . ' - ' . $key ) ) ); ?></td>
												<td align="right" style="<?php echo esc_attr( $etm_cell ); ?>"><?php echo esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ); ?></td>
											</tr>
										<?php endforeach; ?>
									<?php endforeach; ?>
								</table>
							</td>
						</tr>
					<?php endif; ?>

					<tr>
						<td style="padding:24px 32px 32px;">
							<a
								href="<?php echo esc_url( $admin_link ); ?>"
								style="display:inline-block;padding:11px 18px;background:#111827;color:#ffffff;text-decoration:none;border-radius:8px;font-size:14px;font-weight:600;"
							>
								<?php esc_html_e( 'View booking', 'event-ticket-manager' ); ?>
							</a>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
