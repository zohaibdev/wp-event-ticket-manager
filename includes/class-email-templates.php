<?php
/**
 * Editable email subjects and bodies.
 *
 * Each template ships with default copy an admin can edit. Clearing a body
 * entirely is a deliberate choice that falls back to the plugin's built-in
 * HTML layout for that email.
 *
 * The seeded copy leans on block variables that suppress themselves when they
 * have nothing to say, so one template covers bookings with and without a
 * roommate, a coupon or fees.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage, defaults and variable substitution for email templates.
 */
class ETM_Email_Templates {

	public const OPTION = 'etm_email_templates';

	public const CUSTOMER_TICKET = 'customer_ticket_confirmation';
	public const ADMIN_TICKET    = 'admin_ticket_notification';
	public const CUSTOMER_RSVP   = 'customer_rsvp_confirmation';
	public const ADMIN_RSVP      = 'admin_rsvp_notification';

	/**
	 * Template keys with labels.
	 *
	 * @return array<string, string>
	 */
	public static function templates(): array {
		return array(
			self::CUSTOMER_TICKET => __( 'Customer Ticket Confirmation', 'event-ticket-manager' ),
			self::ADMIN_TICKET    => __( 'Admin Ticket Notification', 'event-ticket-manager' ),
			self::CUSTOMER_RSVP   => __( 'Customer RSVP Confirmation', 'event-ticket-manager' ),
			self::ADMIN_RSVP      => __( 'Admin RSVP Notification', 'event-ticket-manager' ),
		);
	}

	/**
	 * The variables an admin may use, with a short description each.
	 *
	 * @return array<string, string>
	 */
	public static function variables(): array {
		return array(
			'{{customer_name}}'     => __( 'Customer name', 'event-ticket-manager' ),
			'{{customer_email}}'    => __( 'Customer email', 'event-ticket-manager' ),
			'{{event_name}}'        => __( 'Event name', 'event-ticket-manager' ),
			'{{event_date}}'        => __( 'Event date', 'event-ticket-manager' ),
			'{{event_time}}'        => __( 'Event time', 'event-ticket-manager' ),
			'{{event_type}}'        => __( 'Ticket or RSVP', 'event-ticket-manager' ),
			'{{booking_number}}'    => __( 'Booking number', 'event-ticket-manager' ),
			'{{ticket_summary}}'    => __( 'List of ticket types and quantities', 'event-ticket-manager' ),
			'{{attendee_list}}'     => __( 'List of attendees', 'event-ticket-manager' ),
			'{{roommate_details}}'  => __( 'Every roommate name and email, if any', 'event-ticket-manager' ),
			'{{roommate_block}}'    => __( 'A complete roommate section, or nothing when there is no roommate', 'event-ticket-manager' ),
			'{{payment_summary}}'   => __( 'A table of subtotal, fees, discount, total and payment status', 'event-ticket-manager' ),
			'{{subtotal}}'          => __( 'Ticket subtotal plus roommate add-on', 'event-ticket-manager' ),
			'{{fees}}'              => __( 'Total fees', 'event-ticket-manager' ),
			'{{coupon_code}}'       => __( 'Coupon code used, if any', 'event-ticket-manager' ),
			'{{discount}}'          => __( 'Discount amount, if any', 'event-ticket-manager' ),
			'{{total}}'             => __( 'Final total', 'event-ticket-manager' ),
			'{{payment_status}}'    => __( 'Payment status', 'event-ticket-manager' ),
			'{{stripe_payment_id}}' => __( 'Stripe payment reference, paid orders only', 'event-ticket-manager' ),
			'{{site_name}}'         => __( 'Site name', 'event-ticket-manager' ),
			'{{site_url}}'          => __( 'Site address', 'event-ticket-manager' ),
		);
	}

	/**
	 * Default subject and body for every template.
	 *
	 * Ticket subjects fall back to the values already configured under the Email
	 * tab, so upgrading never silently changes an email that was customised.
	 *
	 * @return array<string, array{subject: string, body: string}>
	 */
	public static function defaults(): array {
		return array(
			self::CUSTOMER_TICKET => array(
				'subject' => (string) ETM_Settings::email( 'customer_subject', __( 'Your booking is confirmed - {{booking_number}}', 'event-ticket-manager' ) ),
				'body'    => self::default_body( self::CUSTOMER_TICKET ),
			),
			self::ADMIN_TICKET    => array(
				'subject' => (string) ETM_Settings::email( 'admin_subject', __( 'New booking {{booking_number}} for {{event_name}}', 'event-ticket-manager' ) ),
				'body'    => self::default_body( self::ADMIN_TICKET ),
			),
			self::CUSTOMER_RSVP   => array(
				'subject' => __( 'Your RSVP for {{event_name}} is confirmed', 'event-ticket-manager' ),
				'body'    => self::default_body( self::CUSTOMER_RSVP ),
			),
			self::ADMIN_RSVP      => array(
				'subject' => __( 'New RSVP {{booking_number}} for {{event_name}}', 'event-ticket-manager' ),
				'body'    => self::default_body( self::ADMIN_RSVP ),
			),
		);
	}

	/**
	 * Starter copy for one template.
	 *
	 * Every block variable used here suppresses itself when it has nothing to
	 * say, so a booking with no roommate or no coupon simply omits that line.
	 *
	 * @param string $key Template key.
	 * @return string
	 */
	public static function default_body( string $key ): string {
		$bodies = array(
			self::CUSTOMER_TICKET => __(
				"<h2>Thank you for your booking!</h2>\n\n" .
				"<p>Hi {{customer_name}}, your payment was received and your tickets for <strong>{{event_name}}</strong> are confirmed.</p>\n\n" .
				"<p><strong>Booking number:</strong> {{booking_number}}<br />\n" .
				"<strong>Date:</strong> {{event_date}}<br />\n" .
				"<strong>Time:</strong> {{event_time}}</p>\n\n" .
				"<h3>Your tickets</h3>\n{{ticket_summary}}\n\n" .
				"<h3>Attendees</h3>\n{{attendee_list}}\n\n" .
				"{{roommate_block}}\n" .
				"<h3>Payment</h3>\n{{payment_summary}}\n\n" .
				"<p>We look forward to seeing you. Please keep this email for your records.</p>\n\n" .
				"<p>{{site_name}}</p>",
				'event-ticket-manager'
			),
			self::ADMIN_TICKET    => __(
				"<h2>New paid booking</h2>\n\n" .
				"<p><strong>{{booking_number}}</strong> for <strong>{{event_name}}</strong> on {{event_date}}.</p>\n\n" .
				"<p><strong>Customer:</strong> {{customer_name}} ({{customer_email}})</p>\n\n" .
				"<h3>Tickets</h3>\n{{ticket_summary}}\n\n" .
				"<h3>Attendees</h3>\n{{attendee_list}}\n\n" .
				"{{roommate_block}}\n" .
				"<h3>Payment</h3>\n{{payment_summary}}\n\n" .
				"<p>{{site_name}} - {{site_url}}</p>",
				'event-ticket-manager'
			),
			self::CUSTOMER_RSVP   => __(
				"<h2>Your RSVP is confirmed</h2>\n\n" .
				"<p>Hi {{customer_name}}, thank you for registering for <strong>{{event_name}}</strong>. Your place is reserved and there is nothing to pay.</p>\n\n" .
				"<p><strong>Reference:</strong> {{booking_number}}<br />\n" .
				"<strong>Date:</strong> {{event_date}}<br />\n" .
				"<strong>Time:</strong> {{event_time}}</p>\n\n" .
				"<h3>Who is coming</h3>\n{{attendee_list}}\n\n" .
				"{{roommate_block}}\n" .
				"<p>If your plans change, please let us know so we can offer your place to someone else.</p>\n\n" .
				"<p>{{site_name}}</p>",
				'event-ticket-manager'
			),
			self::ADMIN_RSVP      => __(
				"<h2>New RSVP</h2>\n\n" .
				"<p><strong>{{booking_number}}</strong> for <strong>{{event_name}}</strong> on {{event_date}}.</p>\n\n" .
				"<p><strong>Registered by:</strong> {{customer_name}} ({{customer_email}})</p>\n\n" .
				"<h3>Attendees</h3>\n{{attendee_list}}\n\n" .
				"{{roommate_block}}\n" .
				"<p>{{site_name}} - {{site_url}}</p>",
				'event-ticket-manager'
			),
		);

		return $bodies[ $key ] ?? '';
	}

	/**
	 * One template, merged over its defaults.
	 *
	 * @param string $key Template key.
	 * @return array{subject: string, body: string}
	 */
	public static function get( string $key ): array {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = self::defaults();

		if ( ! isset( $defaults[ $key ] ) ) {
			return array( 'subject' => '', 'body' => '' );
		}

		$template = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();

		return array(
			'subject' => isset( $template['subject'] ) && '' !== trim( (string) $template['subject'] )
				? (string) $template['subject']
				: $defaults[ $key ]['subject'],
			// An admin who clears the body means it: that falls through to the
			// built-in layout. Only an unsaved template gets the seeded default.
			'body'    => array_key_exists( 'body', $template )
				? (string) $template['body']
				: $defaults[ $key ]['body'],
		);
	}

	/**
	 * Save every template from a submitted form.
	 *
	 * @param array<string, mixed> $raw Raw submission.
	 */
	public static function save( array $raw ): void {
		$clean = array();

		foreach ( array_keys( self::templates() ) as $key ) {
			$template = isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? $raw[ $key ] : array();

			$clean[ $key ] = array(
				'subject' => sanitize_text_field( (string) ( $template['subject'] ?? '' ) ),
				'body'    => wp_kses_post( (string) ( $template['body'] ?? '' ) ),
			);
		}

		update_option( self::OPTION, $clean );
	}

	/**
	 * Restore one template to its seeded default subject and body.
	 *
	 * @param string $key Template key.
	 */
	public static function reset( string $key ): void {
		if ( ! isset( self::templates()[ $key ] ) ) {
			return;
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		unset( $stored[ $key ] );

		update_option( self::OPTION, $stored );
	}

	/**
	 * Build the replacement map for one booking.
	 *
	 * Payment values are blank for RSVP and free orders rather than misleading.
	 *
	 * @param object $booking Booking row.
	 * @return array<string, string>
	 */
	public static function variable_values( object $booking ): array {
		$event     = ETM_Events::get( (int) $booking->event_id );
		$currency  = (string) $booking->currency;
		$lines     = ETM_Bookings::get_ticket_lines( (int) $booking->id );
		$attendees = ETM_Attendees::get_for_booking( (int) $booking->id );

		$is_rsvp = ETM_Events::TYPE_RSVP === $booking->event_type;

		$ticket_items = array();

		foreach ( $lines as $line ) {
			$ticket_items[] = sprintf(
				'%s &times; %d &mdash; %s',
				esc_html( (string) $line['title'] ),
				(int) $line['quantity'],
				esc_html( etm_format_price( (float) $line['subtotal'], $currency ) )
			);
		}

		$attendee_items = array();

		foreach ( $attendees as $attendee ) {
			$label = esc_html( trim( (string) $attendee->name ) ) . ' (' . esc_html( (string) $attendee->email ) . ')';

			if ( ! empty( $attendee->ticket_title_snapshot ) ) {
				$label .= ' &mdash; ' . esc_html( (string) $attendee->ticket_title_snapshot );
			}

			$attendee_items[] = $label;
		}

		// One line per roommate on the booking, however many were bought.
		$roommate_items = array();

		foreach ( ETM_Bookings::get_roommates( $booking ) as $person ) {
			$roommate_items[] = trim( $person['name'] . ' (' . $person['email'] . ')' );
		}

		$roommate = implode( ', ', $roommate_items );

		// A whole section that disappears when there is no roommate.
		$roommate_block = empty( $roommate_items )
			? ''
			: '<h3>' . esc_html(
				_n( 'Roommate', 'Roommates', count( $roommate_items ), 'event-ticket-manager' )
			) . '</h3>' . "\n" . self::html_list( $roommate_items );

		// The roommate price is only ever non-zero for a booking sold the add-on
		// the plugin used to have; a shared room is already in the ticket lines.
		$subtotal = round( (float) $booking->ticket_subtotal + (float) $booking->roommate_price, 2 );
		$is_paid  = ETM_Bookings::STATUS_PAID === $booking->payment_status;

		return array(
			'{{customer_name}}'     => (string) $booking->customer_name,
			'{{customer_email}}'    => (string) $booking->customer_email,
			'{{event_name}}'        => $event ? (string) $event->title : '',
			'{{event_date}}'        => $event ? etm_format_datetime( $event->start_datetime, false ) : '',
			'{{event_time}}'        => $event ? etm_event_time_range( $event ) : '',
			'{{event_type}}'        => ETM_Events::TYPE_RSVP === $booking->event_type
				? __( 'RSVP', 'event-ticket-manager' )
				: __( 'Ticket', 'event-ticket-manager' ),
			'{{booking_number}}'    => (string) $booking->booking_number,
			'{{ticket_summary}}'    => self::html_list( $ticket_items ),
			'{{attendee_list}}'     => self::html_list( $attendee_items ),
			'{{roommate_details}}'  => $roommate,
			'{{roommate_block}}'    => $roommate_block,
			'{{payment_summary}}'   => self::payment_summary( $booking, $subtotal, $is_rsvp ),
			'{{subtotal}}'          => etm_format_price( $subtotal, $currency ),
			'{{fees}}'              => etm_format_price( (float) $booking->fees_total, $currency ),
			'{{coupon_code}}'       => (string) $booking->coupon_code,
			'{{discount}}'          => (float) $booking->discount_total > 0
				? etm_format_price( (float) $booking->discount_total, $currency )
				: '',
			'{{total}}'             => etm_format_price( (float) $booking->total, $currency ),
			'{{payment_status}}'    => etm_booking_status_label( (string) $booking->payment_status ),
			// Never present for RSVP or free orders.
			'{{stripe_payment_id}}' => $is_paid ? (string) $booking->stripe_payment_intent_id : '',
			'{{site_name}}'         => (string) get_bloginfo( 'name' ),
			'{{site_url}}'          => (string) home_url( '/' ),
		);
	}

	/**
	 * Render items as an HTML list, or nothing when there are none.
	 *
	 * @param string[] $items Already-escaped list items.
	 * @return string
	 */
	private static function html_list( array $items ): string {
		if ( empty( $items ) ) {
			return '';
		}

		return '<ul><li>' . implode( '</li><li>', $items ) . '</li></ul>';
	}

	/**
	 * The money side of a booking as a small table.
	 *
	 * Rows that do not apply are left out, and an RSVP shows no money at all.
	 *
	 * @param object $booking  Booking row.
	 * @param float  $subtotal Ticket subtotal plus roommate add-on.
	 * @param bool   $is_rsvp  Whether the booking is a registration.
	 * @return string
	 */
	private static function payment_summary( object $booking, float $subtotal, bool $is_rsvp ): string {
		$currency = (string) $booking->currency;
		$status   = etm_booking_status_label( (string) $booking->payment_status );

		if ( $is_rsvp ) {
			return '<p>' . esc_html__( 'No payment was required for this registration.', 'event-ticket-manager' ) . '</p>';
		}

		$rows = array(
			array( __( 'Subtotal', 'event-ticket-manager' ), etm_format_price( $subtotal, $currency ) ),
		);

		if ( (float) $booking->fees_total > 0 ) {
			$rows[] = array( __( 'Fees', 'event-ticket-manager' ), etm_format_price( (float) $booking->fees_total, $currency ) );
		}

		if ( (float) $booking->discount_total > 0 ) {
			$rows[] = array(
				sprintf(
					/* translators: %s: coupon code. */
					__( 'Coupon %s', 'event-ticket-manager' ),
					(string) $booking->coupon_code
				),
				'-' . etm_format_price( (float) $booking->discount_total, $currency ),
			);
		}

		$rows[] = array( __( 'Total', 'event-ticket-manager' ), etm_format_price( (float) $booking->total, $currency ) );
		$rows[] = array( __( 'Payment status', 'event-ticket-manager' ), $status );

		if ( ETM_Bookings::STATUS_PAID === $booking->payment_status && ! empty( $booking->stripe_payment_intent_id ) ) {
			$rows[] = array( __( 'Payment reference', 'event-ticket-manager' ), (string) $booking->stripe_payment_intent_id );
		}

		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';

		foreach ( $rows as $row ) {
			$html .= '<tr>'
				. '<td style="padding:6px 0;color:#616e7c;">' . esc_html( $row[0] ) . '</td>'
				. '<td align="right" style="padding:6px 0;color:#1f2933;">' . esc_html( $row[1] ) . '</td>'
				. '</tr>';
		}

		return $html . '</table>';
	}

	/**
	 * Substitute variables into a string.
	 *
	 * Anything that is not a known variable is left exactly as written, so an
	 * unrecognised token can never break an email.
	 *
	 * @param string $text    Raw text.
	 * @param object $booking Booking row.
	 * @return string
	 */
	public static function render( string $text, object $booking ): string {
		if ( '' === trim( $text ) ) {
			return '';
		}

		return strtr( $text, self::variable_values( $booking ) );
	}

	/**
	 * Wrap a custom body in the plugin's branded email shell.
	 *
	 * @param string $body    Rendered body HTML.
	 * @param string $heading Heading shown at the top.
	 * @return string
	 */
	public static function wrap( string $body, string $heading = '' ): string {
		$primary = sanitize_hex_color( (string) ETM_Settings::branding( 'primary_color', '#4f46e5' ) );
		$primary = $primary ? $primary : '#4f46e5';

		ob_start();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>" />
			<meta name="viewport" content="width=device-width, initial-scale=1" />
			<title><?php echo esc_html( '' !== $heading ? $heading : get_bloginfo( 'name' ) ); ?></title>
		</head>
		<body style="margin:0;padding:0;background:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f7fa;padding:24px 12px;">
				<tr>
					<td align="center">
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;border:1px solid #e4e7eb;">
							<tr>
								<td style="height:4px;background:<?php echo esc_attr( $primary ); ?>;border-radius:12px 12px 0 0;"></td>
							</tr>
							<tr>
								<td style="padding:28px 32px;font-size:15px;line-height:1.6;color:#1f2933;">
									<?php echo wp_kses_post( wpautop( $body ) ); ?>
								</td>
							</tr>
						</table>
					</td>
				</tr>
			</table>
		</body>
		</html>
		<?php

		return (string) ob_get_clean();
	}
}
