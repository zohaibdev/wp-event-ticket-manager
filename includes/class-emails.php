<?php
/**
 * Transactional email for bookings.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Customer confirmation and admin notification email.
 */
class ETM_Emails {

	/**
	 * Sender details applied while a plugin email is being sent.
	 *
	 * @var array{name: string, email: string}|null
	 */
	private ?array $sender = null;

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'etm_booking_paid', array( $this, 'on_booking_paid' ), 10, 2 );
	}

	/**
	 * Send both notifications when a booking is confirmed.
	 *
	 * @param int         $booking_id Booking ID.
	 * @param object|null $booking    Booking row.
	 */
	public function on_booking_paid( int $booking_id, ?object $booking = null ): void {
		$booking = $booking ?: ETM_Bookings::get( $booking_id );

		if ( ! $booking ) {
			return;
		}

		if ( ETM_Settings::email( 'enable_customer_email', 1 ) ) {
			$this->send_customer_confirmation( $booking );
		}

		if ( ETM_Settings::email( 'enable_admin_email', 1 ) ) {
			$this->send_admin_notification( $booking );
		}
	}

	/**
	 * Whether a booking is a registration rather than a purchase.
	 *
	 * @param object $booking Booking row.
	 * @return bool
	 */
	private function is_rsvp( object $booking ): bool {
		return ETM_Events::TYPE_RSVP === $booking->event_type;
	}

	/**
	 * Where an admin notification should go.
	 *
	 * The event's own override wins for any booking on that event, ticket or
	 * RSVP. Failing that, an RSVP booking prefers the global RSVP addresses,
	 * and everything else falls through to the ticket admin addresses.
	 *
	 * @param object $booking Booking row.
	 * @return string[]
	 */
	public function admin_recipients( object $booking ): array {
		$per_event = ETM_Settings::parse_email_list(
			(string) ETM_Events::get_meta( (int) $booking->event_id, 'rsvp_notification_email', '' )
		);

		if ( ! empty( $per_event ) ) {
			return $per_event;
		}

		if ( $this->is_rsvp( $booking ) ) {
			$global_rsvp = ETM_Settings::rsvp_notification_emails();

			if ( ! empty( $global_rsvp ) ) {
				return $global_rsvp;
			}
		}

		return ETM_Settings::admin_notification_emails();
	}

	/**
	 * Assemble the data every template needs.
	 *
	 * @param object $booking Booking row.
	 * @return array<string, mixed>
	 */
	public function get_template_data( object $booking ): array {
		$event = ETM_Events::get( (int) $booking->event_id );

		$fee_lines = ETM_Bookings::get_meta( (int) $booking->id, 'fee_lines', array() );

		return array(
			'booking'       => $booking,
			'event'         => $event,
			'attendees'     => ETM_Attendees::get_for_booking( (int) $booking->id ),
			'tickets'       => ETM_Bookings::get_ticket_lines( (int) $booking->id ),
			'fee_lines'     => is_array( $fee_lines ) ? $fee_lines : array(),
			'custom_fields' => ETM_Bookings::get_custom_field_values( (int) $booking->id ),
			'currency'      => (string) $booking->currency,
		);
	}

	/**
	 * Placeholder map for subjects and intro copy.
	 *
	 * @param object $booking Booking row.
	 * @return array<string, string>
	 */
	public function get_placeholders( object $booking ): array {
		$event = ETM_Events::get( (int) $booking->event_id );

		return array(
			'{booking_number}'  => (string) $booking->booking_number,
			'{customer_name}'   => (string) $booking->customer_name,
			'{customer_email}'  => (string) $booking->customer_email,
			'{event_title}'     => $event ? (string) $event->title : '',
			'{event_date}'      => $event ? etm_format_datetime( $event->start_datetime ) : '',
			'{ticket_quantity}' => (string) (int) $booking->ticket_quantity,
			'{total}'           => etm_format_price( (float) $booking->total, (string) $booking->currency ),
			'{site_name}'       => get_bloginfo( 'name' ),
		);
	}

	/**
	 * Replace placeholders in a string.
	 *
	 * @param string $text    Raw text.
	 * @param object $booking Booking row.
	 * @return string
	 */
	public function parse_placeholders( string $text, object $booking ): string {
		$map = $this->get_placeholders( $booking );

		return strtr( $text, $map );
	}

	/**
	 * Send the customer confirmation.
	 *
	 * @param object $booking Booking row.
	 * @return bool
	 */
	public function send_customer_confirmation( object $booking ): bool {
		if ( ! is_email( $booking->customer_email ) ) {
			return false;
		}

		$key      = $this->is_rsvp( $booking ) ? ETM_Email_Templates::CUSTOMER_RSVP : ETM_Email_Templates::CUSTOMER_TICKET;
		$template = ETM_Email_Templates::get( $key );

		$subject = ETM_Email_Templates::render( $template['subject'], $booking );
		$subject = $this->parse_placeholders( $subject, $booking );

		if ( '' !== trim( $template['body'] ) ) {
			// The admin wrote their own copy for this email.
			$body = ETM_Email_Templates::wrap(
				ETM_Email_Templates::render( $template['body'], $booking ),
				$subject
			);
		} else {
			$data = $this->get_template_data( $booking );

			$data['heading'] = $this->parse_placeholders( (string) ETM_Settings::email( 'customer_heading', '' ), $booking );
			$data['intro']   = $this->parse_placeholders( (string) ETM_Settings::email( 'customer_intro', '' ), $booking );

			$body = $this->render_template( 'emails/customer-confirmation.php', $data );
		}

		/**
		 * Filter the customer confirmation email before sending.
		 *
		 * @param array  $email   Subject, body and recipient.
		 * @param object $booking Booking row.
		 */
		$email = apply_filters(
			'etm_customer_email',
			array(
				'to'      => (string) $booking->customer_email,
				'subject' => $subject,
				'body'    => $body,
			),
			$booking
		);

		return $this->send( $email['to'], $email['subject'], $email['body'] );
	}

	/**
	 * Send the admin notification.
	 *
	 * @param object $booking Booking row.
	 * @return bool
	 */
	public function send_admin_notification( object $booking ): bool {
		$recipients = $this->admin_recipients( $booking );

		if ( empty( $recipients ) ) {
			return false;
		}

		$key      = $this->is_rsvp( $booking ) ? ETM_Email_Templates::ADMIN_RSVP : ETM_Email_Templates::ADMIN_TICKET;
		$template = ETM_Email_Templates::get( $key );

		$subject = ETM_Email_Templates::render( $template['subject'], $booking );
		$subject = $this->parse_placeholders( $subject, $booking );

		if ( '' !== trim( $template['body'] ) ) {
			$body = ETM_Email_Templates::wrap(
				ETM_Email_Templates::render( $template['body'], $booking ),
				$subject
			);
		} else {
			$data = $this->get_template_data( $booking );

			$data['admin_link'] = admin_url( 'admin.php?page=etm-bookings&action=view&booking=' . (int) $booking->id );
			$data['warning']    = (string) ETM_Bookings::get_meta( (int) $booking->id, 'capacity_warning', '' );

			$body = $this->render_template( 'emails/admin-notification.php', $data );
		}

		/**
		 * Filter the admin notification email before sending.
		 *
		 * @param array  $email   Subject, body and recipients. 'to' is a list of
		 *                        addresses; each one is sent its own copy so no
		 *                        recipient sees another's address.
		 * @param object $booking Booking row.
		 */
		$email = apply_filters(
			'etm_admin_email',
			array(
				'to'      => $recipients,
				'subject' => $subject,
				'body'    => $body,
			),
			$booking
		);

		$sent = false;
		$seen = array();

		foreach ( (array) $email['to'] as $to ) {
			$to  = is_string( $to ) ? trim( $to ) : '';
			$key = strtolower( $to );

			if ( '' === $to || ! is_email( $to ) || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			if ( $this->send( $to, $email['subject'], $email['body'] ) ) {
				$sent = true;
			}
		}

		return $sent;
	}

	/**
	 * Render an email template to a string.
	 *
	 * Themes can override templates in {theme}/event-ticket-manager/.
	 *
	 * @param string               $template Relative template path.
	 * @param array<string, mixed> $data     Variables exposed to the template.
	 * @return string
	 */
	public function render_template( string $template, array $data ): string {
		$path = etm_locate_template( $template );

		if ( ! $path ) {
			return '';
		}

		ob_start();

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template scope.
		extract( $data, EXTR_SKIP );

		include $path;

		return (string) ob_get_clean();
	}

	/**
	 * Send an HTML email using the configured sender.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    HTML body.
	 * @return bool
	 */
	public function send( string $to, string $subject, string $body ): bool {
		if ( '' === trim( $body ) || ! is_email( $to ) ) {
			return false;
		}

		$this->sender = array(
			'name'  => (string) ETM_Settings::email( 'from_name', get_bloginfo( 'name' ) ),
			'email' => (string) ETM_Settings::email( 'from_email', get_option( 'admin_email' ) ),
		);

		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), 99 );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), 99 );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = wp_mail( $to, wp_specialchars_decode( $subject, ENT_QUOTES ), $body, $headers );

		remove_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), 99 );
		remove_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), 99 );

		$this->sender = null;

		if ( ! $sent ) {
			ETM_Stripe::log( 'Failed to send email to ' . $to );
		}

		return $sent;
	}

	/**
	 * Sender address filter.
	 *
	 * @param string $from Current value.
	 * @return string
	 */
	public function filter_from_email( $from ) {
		$email = $this->sender['email'] ?? '';

		return is_email( $email ) ? $email : $from;
	}

	/**
	 * Sender name filter.
	 *
	 * @param string $name Current value.
	 * @return string
	 */
	public function filter_from_name( $name ) {
		$sender_name = $this->sender['name'] ?? '';

		return '' !== $sender_name ? $sender_name : $name;
	}

	/**
	 * Send a test copy of the customer email to an address.
	 *
	 * @param string $to Recipient.
	 * @return bool
	 */
	public function send_test( string $to ): bool {
		$booking = (object) array(
			'id'               => 0,
			'booking_number'   => 'EVT-' . gmdate( 'Y' ) . '-000000',
			'event_id'         => 0,
			'customer_name'    => __( 'Test Customer', 'event-ticket-manager' ),
			'customer_email'   => $to,
			'ticket_quantity'  => 2,
			'ticket_price'     => 100.00,
			'ticket_subtotal'  => 200.00,
			'roommate_enabled' => 1,
			'roommate_quantity' => 1,
			'roommate_name'    => __( 'Test Roommate', 'event-ticket-manager' ),
			'roommate_email'   => $to,
			'roommate_price'   => 0.00,
			'fees_total'       => 0.00,
			'coupon_code'      => '',
			'discount_total'   => 0.00,
			'total'            => 200.00,
			'currency'         => ETM_Settings::currency(),
			'event_type'       => ETM_Events::TYPE_TICKET,
			'payment_status'   => ETM_Bookings::STATUS_PAID,
			'created_at'       => current_time( 'mysql' ),
		);

		$data = array(
			'booking'       => $booking,
			'event'         => null,
			'attendees'     => array(),
			'tickets'       => array(
				array(
					'ticket_type_id' => 0,
					'title'          => __( 'General Admission', 'event-ticket-manager' ),
					'unit_price'     => 100.00,
					'quantity'       => 2,
					'subtotal'       => 200.00,
				),
			),
			'fee_lines'     => array(),
			'custom_fields' => array(),
			'currency'      => (string) $booking->currency,
			'heading'       => $this->parse_placeholders( (string) ETM_Settings::email( 'customer_heading', '' ), $booking ),
			'intro'         => $this->parse_placeholders( (string) ETM_Settings::email( 'customer_intro', '' ), $booking ),
		);

		$subject = $this->parse_placeholders( (string) ETM_Settings::email( 'customer_subject', '' ), $booking );

		return $this->send( $to, '[' . __( 'Test', 'event-ticket-manager' ) . '] ' . $subject, $this->render_template( 'emails/customer-confirmation.php', $data ) );
	}
}
