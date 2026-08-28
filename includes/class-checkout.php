<?php
/**
 * Checkout orchestration: validation, seat holding, PaymentIntent management
 * and post-payment confirmation.
 *
 * Two rules shape this class:
 *
 * 1. No booking row exists until a payment has been verified. Until then the
 *    order lives in ETM_Pending_Orders, which also holds the seats.
 * 2. Nothing about money comes from the browser. The request carries ticket
 *    type IDs and quantities; every title, price and total is read back from
 *    the event's ticket rows.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Frontend AJAX endpoints for the purchase flow.
 */
class ETM_Checkout {

	public const NONCE_ACTION = 'etm_frontend_nonce';

	/**
	 * Booking meta key holding the per-order access token.
	 */
	public const TOKEN_META = 'access_token';

	/**
	 * Verify the frontend nonce, responding with a proper JSON error instead of
	 * check_ajax_referer()'s default die(-1) on failure.
	 *
	 * A plain "-1" response is not valid JSON: the browser's fetch().json()
	 * throws, and every AJAX call in frontend.js collapses that into the same
	 * generic "Something went wrong" message, regardless of what actually
	 * failed. An expired or stale nonce - the common case on a cached page, or
	 * a tab left open across a session - is exactly the kind of failure this
	 * hides. Returning a specific, actionable error here instead means that
	 * failure mode is distinguishable from every other one.
	 */
	private function verify_nonce(): void {
		if ( check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			return;
		}

		wp_send_json_error(
			array(
				'message' => __( 'Your session has expired. Please refresh the page and try again.', 'event-ticket-manager' ),
				'expired' => true,
			),
			403
		);
	}

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'wp_ajax_etm_start_checkout', array( $this, 'ajax_start_checkout' ) );
		add_action( 'wp_ajax_nopriv_etm_start_checkout', array( $this, 'ajax_start_checkout' ) );

		add_action( 'wp_ajax_etm_confirm_payment', array( $this, 'ajax_confirm_payment' ) );
		add_action( 'wp_ajax_nopriv_etm_confirm_payment', array( $this, 'ajax_confirm_payment' ) );

		add_action( 'wp_ajax_etm_quote_order', array( $this, 'ajax_quote_order' ) );
		add_action( 'wp_ajax_nopriv_etm_quote_order', array( $this, 'ajax_quote_order' ) );

		add_action( 'wp_ajax_etm_event_availability', array( $this, 'ajax_event_availability' ) );
		add_action( 'wp_ajax_nopriv_etm_event_availability', array( $this, 'ajax_event_availability' ) );
	}

	/**
	 * Validate the order, hold the seats and hand back a PaymentIntent secret.
	 *
	 * Re-callable: passing the token back amends the existing hold and its
	 * PaymentIntent rather than creating duplicates.
	 */
	public function ajax_start_checkout(): void {
		$this->verify_nonce();

		if ( ! $this->check_rate_limit() ) {
			wp_send_json_error(
				array( 'message' => __( 'Too many attempts. Please wait a moment and try again.', 'event-ticket-manager' ) ),
				429
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$raw = wp_unslash( $_POST );

		$event_id = isset( $raw['event_id'] ) ? absint( $raw['event_id'] ) : 0;
		$event    = ETM_Events::get( $event_id );

		if ( ! $event || ! ETM_Events::is_purchasable( $event ) ) {
			wp_send_json_error( array( 'message' => __( 'This event is not available for booking.', 'event-ticket-manager' ) ), 400 );
		}

		if ( ! ETM_Stripe::is_configured() || '' === ETM_Stripe::get_publishable_key() ) {
			wp_send_json_error( array( 'message' => __( 'Payments are not available right now. Please contact the organiser.', 'event-ticket-manager' ) ), 503 );
		}

		$key_error = ETM_Stripe::key_mode_error();

		if ( '' !== $key_error ) {
			ETM_Stripe::log( 'Stripe key/mode mismatch: ' . $key_error );

			// Surfaced only to admins: a misconfiguration message is not useful
			// to a customer and would just look like an unexplained failure.
			wp_send_json_error(
				array(
					'message' => current_user_can( 'manage_options' )
						? $key_error
						: __( 'Payments are not available right now. Please contact the organiser.', 'event-ticket-manager' ),
				),
				503
			);
		}

		// An existing hold belonging to this visitor, when amending an order.
		$hold       = $this->resolve_hold( $raw, $event_id );
		$hold_token = $hold ? (string) $hold->token : '';

		// A hold that has already been paid for: send them to the summary.
		if ( ! $hold ) {
			$paid = $this->resolve_paid_booking( $raw );

			if ( $paid ) {
				wp_send_json_success(
					array(
						'already_paid' => true,
						'token'        => (string) $raw['token'],
					)
				);
			}
		}

		// The only things trusted from the request: which ticket IDs, how many.
		$selection_raw = isset( $raw['tickets'] ) && is_array( $raw['tickets'] ) ? $raw['tickets'] : array();
		$selection     = ETM_Event_Tickets::validate_selection( $event, $selection_raw, $hold_token );

		if ( ! empty( $selection['errors'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please check the tickets you selected.', 'event-ticket-manager' ),
					'errors'  => $selection['errors'],
				),
				422
			);
		}

		if ( $selection['quantity'] < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Please choose at least one ticket.', 'event-ticket-manager' ) ), 400 );
		}

		// Whether a roommate is asked for at all follows from the ticket rows: it
		// is true when the order contains a Sharing a Room variation, whatever
		// the browser sent about types or prices.
		$sharing_room = ETM_Event_Tickets::sharing_room_quantity( $selection['lines'] ) > 0;

		$slots      = ETM_Event_Tickets::attendee_slots( $selection['lines'] );
		$validation = $this->validate_submission( $raw, $slots, $sharing_room );

		if ( ! empty( $validation['errors'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please correct the highlighted fields.', 'event-ticket-manager' ),
					'errors'  => $validation['errors'],
				),
				422
			);
		}

		// The browser sends a code, never a discount. Everything else is priced
		// from the database.
		$customer_email = (string) ( $validation['customer']['customer_email'] ?? '' );
		$coupon         = $this->resolve_coupon( $raw, $event_id, $customer_email );

		if ( is_wp_error( $coupon ) ) {
			wp_send_json_error(
				array(
					'message' => $coupon->get_error_message(),
					'errors'  => array( 'coupon' => $coupon->get_error_message() ),
				),
				422
			);
		}

		$totals = self::calculate_totals( $event, $selection, $coupon );

		$payload = array(
			'quantity'          => $selection['quantity'],
			'lines'             => $selection['lines'],
			'sharing_room'      => $validation['sharing_room'],
			'totals'            => $totals,
			'event_type'        => ETM_Events::get_type( $event_id ),
			'customer'          => $validation['customer'],
			'customer_custom'   => $validation['customer_custom'],
			'attendees'         => $validation['attendees'],
			'ip_address'        => etm_get_client_ip(),
			'stripe_mode'       => ETM_Stripe::get_mode(),
		);

		// A shared room is an ordinary ticket as far as places are concerned.
		$seats = ETM_Capacity::seats_for_purchase( $event, $selection['quantity'], 0 );

		$token = ETM_Capacity::with_lock(
			$event_id,
			function ( $locked_event ) use ( $selection, $payload, $totals, $seats, $hold_token ) {
				$available = ETM_Capacity::check_availability(
					$locked_event,
					$selection['quantity'],
					0,
					null,
					$hold_token
				);

				if ( is_wp_error( $available ) ) {
					return $available;
				}

				if ( '' !== $hold_token ) {
					ETM_Pending_Orders::update( $hold_token, $seats, $payload, $totals['total'], $totals['currency'] );

					return $hold_token;
				}

				return ETM_Pending_Orders::create( (int) $locked_event->id, $seats, $payload, $totals['total'], $totals['currency'] );
			}
		);

		if ( is_wp_error( $token ) ) {
			wp_send_json_error( self::error_payload( $token, $token->get_error_message() ), 409 );
		}

		$token = (string) $token;

		// RSVP events never take payment, and neither does an order that comes
		// to zero once fees and any coupon have been applied. Both complete here
		// without Stripe ever being initialised.
		if ( ! self::requires_payment( $event_id, $totals ) ) {
			$booking = $this->complete_without_payment( $token, $event_id );

			if ( is_wp_error( $booking ) ) {
				wp_send_json_error( array( 'message' => $booking->get_error_message() ), 500 );
			}

			wp_send_json_success(
				array(
					'completed' => true,
					'token'     => $token,
					'order'     => self::get_order_summary( (int) $booking->id ),
				)
			);
		}

		$intent = $this->prepare_payment_intent( $token, $event, $totals, $validation, $selection );

		if ( is_wp_error( $intent ) ) {
			wp_send_json_error(
				self::error_payload( $intent, self::customer_facing_stripe_message( $intent ) ),
				502
			);
		}

		// Paid on an earlier attempt while the confirmation was still in flight.
		if ( 'succeeded' === (string) ( $intent['status'] ?? '' ) ) {
			$hold = ETM_Pending_Orders::get( $token );

			if ( $hold ) {
				ETM_Webhooks::confirm_paid_intent( $intent );
			}

			wp_send_json_success(
				array(
					'already_paid' => true,
					'token'        => $token,
				)
			);
		}

		wp_send_json_success(
			array(
				'token'            => $token,
				'client_secret'    => (string) $intent['client_secret'],
				'publishable_key'  => ETM_Stripe::get_publishable_key(),
				'amount'           => $totals['total'],
				'amount_formatted' => etm_format_price( $totals['total'], $totals['currency'] ),
				'currency'         => $totals['currency'],
				// The card form does not ask for these again; they are sent to
				// Stripe at confirmation from the values validated here.
				'billing'          => array(
					'name'  => (string) ( $validation['customer']['customer_name'] ?? '' ),
					'email' => (string) ( $validation['customer']['customer_email'] ?? '' ),
				),
			)
		);
	}

	/**
	 * Confirm a payment against Stripe, create the booking and return it.
	 *
	 * The browser reports that it attempted a payment; the truth comes from
	 * retrieving the PaymentIntent with the secret key. The webhook does the
	 * same job independently and both funnel through the same idempotent
	 * creation path.
	 */
	public function ajax_confirm_payment(): void {
		$this->verify_nonce();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$raw   = wp_unslash( $_POST );
		$token = isset( $raw['token'] ) ? ETM_Pending_Orders::sanitize_token( (string) $raw['token'] ) : '';

		if ( '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'event-ticket-manager' ) ), 404 );
		}

		// The booking already exists: this call, an earlier one or the webhook.
		$booking = ETM_Bookings::get_by_token( $token );

		if ( $booking ) {
			wp_send_json_success( self::get_order_summary( (int) $booking->id ) );
		}

		$hold = ETM_Pending_Orders::get( $token );

		if ( ! $hold ) {
			wp_send_json_error( array( 'message' => __( 'This checkout has expired. Please start again.', 'event-ticket-manager' ) ), 404 );
		}

		$intent_id = (string) $hold->stripe_payment_intent_id;

		if ( '' === $intent_id ) {
			wp_send_json_error( array( 'message' => __( 'No payment has been started for this order.', 'event-ticket-manager' ) ), 400 );
		}

		$intent = ETM_Stripe::retrieve_payment_intent( $intent_id );

		if ( is_wp_error( $intent ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'We could not confirm the payment yet. Please wait a moment.', 'event-ticket-manager' ),
					'retry'   => true,
				),
				503
			);
		}

		$status = (string) ( $intent['status'] ?? '' );

		if ( 'succeeded' === $status ) {
			$booking = ETM_Webhooks::confirm_paid_intent( $intent );

			if ( $booking instanceof stdClass || is_object( $booking ) ) {
				wp_send_json_success( self::get_order_summary( (int) $booking->id ) );
			}

			// The webhook may have won the race; read the booking back.
			$booking = ETM_Bookings::get_by_token( $token );

			if ( $booking ) {
				wp_send_json_success( self::get_order_summary( (int) $booking->id ) );
			}

			wp_send_json_error(
				array(
					'message' => __( 'Your payment went through and we are finishing your booking.', 'event-ticket-manager' ),
					'retry'   => true,
				),
				503
			);
		}

		if ( 'processing' === $status ) {
			wp_send_json_success(
				array(
					'pending' => true,
					'message' => __( 'Your payment is being processed. We will email you as soon as it clears.', 'event-ticket-manager' ),
				)
			);
		}

		if ( 'canceled' === $status ) {
			wp_send_json_error( array( 'message' => __( 'The payment was cancelled.', 'event-ticket-manager' ) ), 400 );
		}

		$message = (string) ( $intent['last_payment_error']['message'] ?? __( 'The payment was not completed. Please try again.', 'event-ticket-manager' ) );

		wp_send_json_error( array( 'message' => $message ), 402 );
	}

	/**
	 * Price a selection without creating anything, for the review step.
	 *
	 * Used to show the fee and coupon breakdown, and to apply or remove a coupon.
	 * Nothing here is trusted from the browser except the ticket IDs, quantities
	 * and the coupon code itself.
	 */
	public function ajax_quote_order(): void {
		$this->verify_nonce();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$raw      = wp_unslash( $_POST );
		$event_id = isset( $raw['event_id'] ) ? absint( $raw['event_id'] ) : 0;
		$event    = ETM_Events::get( $event_id );

		if ( ! $event ) {
			wp_send_json_error( array( 'message' => __( 'Event not found.', 'event-ticket-manager' ) ), 404 );
		}

		$selection_raw = isset( $raw['tickets'] ) && is_array( $raw['tickets'] ) ? $raw['tickets'] : array();
		$selection     = ETM_Event_Tickets::validate_selection( $event, $selection_raw );

		if ( ! empty( $selection['errors'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Please check the tickets you selected.', 'event-ticket-manager' ),
					'errors'  => $selection['errors'],
				),
				422
			);
		}

		$email         = isset( $raw['customer_email'] ) ? sanitize_email( (string) $raw['customer_email'] ) : '';
		$coupon        = $this->resolve_coupon( $raw, $event_id, $email );
		$coupon_error  = '';

		if ( is_wp_error( $coupon ) ) {
			// A bad code is reported but still returns a priced order.
			$coupon_error = $coupon->get_error_message();
			$coupon       = null;
		}

		$totals = self::calculate_totals( $event, $selection, $coupon );

		wp_send_json_success(
			array(
				'rows'             => self::breakdown_rows( $totals ),
				'total'            => etm_format_price( (float) $totals['total'], (string) $totals['currency'] ),
				'total_raw'        => (float) $totals['total'],
				'coupon_code'      => (string) $totals['coupon_code'],
				'coupon_error'     => $coupon_error,
				'discount'         => etm_format_price( (float) $totals['discount_total'], (string) $totals['currency'] ),
				'requires_payment' => self::requires_payment( $event_id, $totals ),
			)
		);
	}

	/**
	 * Whether an order still has to go through Stripe.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $totals   Calculated totals.
	 * @return bool
	 */
	public static function requires_payment( int $event_id, array $totals ): bool {
		if ( ETM_Events::is_rsvp( $event_id ) ) {
			return false;
		}

		return (float) $totals['total'] > 0;
	}

	/**
	 * Turn a hold into a confirmed booking that never needed paying for.
	 *
	 * @param string $token    Hold token.
	 * @param int    $event_id Event ID.
	 * @return object|WP_Error
	 */
	private function complete_without_payment( string $token, int $event_id ) {
		$hold = ETM_Pending_Orders::get( $token );

		if ( ! $hold ) {
			$booking = ETM_Bookings::get_by_token( $token );

			return $booking ?: new WP_Error( 'etm_hold_missing', __( 'This checkout has expired. Please start again.', 'event-ticket-manager' ) );
		}

		$status = ETM_Events::is_rsvp( $event_id )
			? ETM_Bookings::STATUS_RSVP
			: ETM_Bookings::STATUS_FREE;

		$booking = ETM_Bookings::create_from_pending( $hold, array(), $status );

		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		ETM_Pending_Orders::delete( $token );

		return $booking;
	}

	/**
	 * Validate a submitted coupon code against the event.
	 *
	 * @param array<string, mixed> $raw            Raw request data.
	 * @param int                  $event_id       Event ID.
	 * @param string               $customer_email Customer email.
	 * @return object|null|WP_Error
	 */
	private function resolve_coupon( array $raw, int $event_id, string $customer_email = '' ) {
		$code = isset( $raw['coupon'] ) ? ETM_Coupons::normalise_code( (string) $raw['coupon'] ) : '';

		if ( '' === $code ) {
			return null;
		}

		// Enforced here, not just hidden in the markup: with the field removed
		// from the page a code can still be posted directly, and a disabled
		// coupon system must not discount an order through that route.
		if ( ! ETM_Coupons::enabled() ) {
			return new WP_Error( 'etm_coupons_disabled', __( 'Coupon codes are not available for this event.', 'event-ticket-manager' ) );
		}

		return ETM_Coupons::validate( $code, $event_id, $customer_email );
	}

	/**
	 * Live availability for the ticket block, per ticket type.
	 */
	public function ajax_event_availability(): void {
		$this->verify_nonce();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$event_id = isset( $_REQUEST['event_id'] ) ? absint( $_REQUEST['event_id'] ) : 0;
		$event    = ETM_Events::get( $event_id );

		if ( ! $event ) {
			wp_send_json_error( array( 'message' => __( 'Event not found.', 'event-ticket-manager' ) ), 404 );
		}

		$event_cap = ETM_Capacity::get_remaining( $event );
		$types     = array();

		foreach ( ETM_Event_Tickets::get_for_event( $event_id, true ) as $ticket ) {
			$types[] = array(
				'id'  => (int) $ticket->id,
				'max' => ETM_Event_Tickets::max_selectable( $ticket, $event_cap ),
			);
		}

		wp_send_json_success(
			array(
				'purchasable'   => ETM_Events::is_purchasable( $event ),
				'sold_out'      => ETM_Capacity::is_sold_out( $event ),
				'event_cap'     => $event_cap,
				'ticket_types'  => $types,
			)
		);
	}

	/**
	 * Server-side price calculation. This is the only source of truth.
	 *
	 * Every variation is priced the same way, Sharing a Room included: its price
	 * times its quantity, inside the ticket subtotal, so fees and coupons reach
	 * it exactly as they reach any other ticket.
	 *
	 * @param object               $event     Event row.
	 * @param array<string, mixed> $selection Validated ticket selection.
	 * @return array{ticket_subtotal: float, total: float, currency: string, quantity: int}
	 */
	public static function calculate_totals( object $event, array $selection, ?object $coupon = null ): array {
		$ticket_subtotal = round( (float) ( $selection['subtotal'] ?? 0 ), 2 );

		// Everything downstream is measured against this: percentage fees and
		// percentage discounts both use the subtotal, never each other's output.
		$subtotal = $ticket_subtotal;

		$fees     = ETM_Fees::calculate( (int) $event->id, $subtotal );
		$discount = ETM_Coupons::discount_for( $coupon, $subtotal );

		$total = round( $subtotal + $fees['total'] - $discount, 2 );

		return array(
			'ticket_subtotal' => $ticket_subtotal,
			'subtotal'        => $subtotal,
			'fee_lines'       => $fees['lines'],
			'fees_total'      => $fees['total'],
			'coupon_code'     => $coupon ? (string) $coupon->code : '',
			'discount_total'  => $discount,
			'total'           => max( 0.0, $total ),
			'currency'        => ETM_Settings::currency(),
			'quantity'        => (int) ( $selection['quantity'] ?? 0 ),
		);
	}

	/**
	 * What a Stripe failure should say to the customer.
	 *
	 * A declined or invalid card is shown verbatim - the customer needs that to
	 * fix it. Anything else (a bad key, a malformed request, an outage on
	 * Stripe's side) is a site problem; the real message is always logged via
	 * ETM_Stripe::log() and, for a logged-in admin only, shown here too, so it
	 * is never simply lost - but a customer never sees Stripe's internals.
	 *
	 * @param WP_Error $error Error from an ETM_Stripe call.
	 * @return string
	 */
	private static function customer_facing_stripe_message( WP_Error $error ): string {
		$data = $error->get_error_data();

		if ( ! empty( $data['customer_safe'] ) ) {
			return ETM_Stripe::redact( $error->get_error_message() );
		}

		// Admins see the real reason, but Stripe quotes the offending key back in
		// its own copy, so it gets the same scrub the logs get.
		if ( current_user_can( 'manage_options' ) ) {
			return ETM_Stripe::redact( $error->get_error_message() );
		}

		return __( 'The payment could not be started. Please try again in a moment, or contact the organiser if this continues.', 'event-ticket-manager' );
	}

	/**
	 * Build the JSON body for a failed request.
	 *
	 * Always carries a machine-readable code alongside the customer-safe
	 * message, so the browser (and anyone reading a support report) can tell
	 * one failure from another without parsing prose. The underlying technical
	 * detail is attached only when WP_DEBUG is on, and is always written to the
	 * log regardless - it must never be the case that a failure leaves nothing
	 * behind but a generic sentence.
	 *
	 * @param WP_Error $error   Failure.
	 * @param string   $message Customer-safe message.
	 * @return array<string, mixed>
	 */
	private static function error_payload( WP_Error $error, string $message ): array {
		$code = $error->get_error_code();

		ETM_Stripe::log( 'Checkout failed [' . $code . ']: ' . $error->get_error_message() );

		$payload = array(
			'message' => $message,
			'code'    => (string) $code,
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$payload['debug'] = $error->get_error_message();
		}

		return $payload;
	}

	/**
	 * The breakdown rows shown to the customer, already formatted.
	 *
	 * @param array<string, mixed> $totals Calculated totals.
	 * @return array<int, array{label: string, value: string, kind: string}>
	 */
	public static function breakdown_rows( array $totals ): array {
		$currency = (string) $totals['currency'];
		$rows     = array();

		$rows[] = array(
			'label' => __( 'Ticket subtotal', 'event-ticket-manager' ),
			'value' => etm_format_price( (float) $totals['ticket_subtotal'], $currency ),
			'kind'  => 'subtotal',
		);

		foreach ( (array) $totals['fee_lines'] as $fee ) {
			$rows[] = array(
				'label' => (string) $fee['name'],
				'value' => etm_format_price( (float) $fee['amount'], $currency ),
				'kind'  => 'fee',
			);
		}

		if ( (float) $totals['discount_total'] > 0 ) {
			$rows[] = array(
				/* translators: %s: coupon code. */
				'label' => sprintf( __( 'Coupon %s', 'event-ticket-manager' ), (string) $totals['coupon_code'] ),
				'value' => '-' . etm_format_price( (float) $totals['discount_total'], $currency ),
				'kind'  => 'discount',
			);
		}

		return $rows;
	}

	/**
	 * Full order summary used by the Order Complete step.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<string, mixed>
	 */
	public static function get_order_summary( int $booking_id ): array {
		$booking = ETM_Bookings::get( $booking_id );

		if ( ! $booking ) {
			return array();
		}

		$event    = ETM_Events::get( (int) $booking->event_id );
		$currency = (string) $booking->currency;
		$tickets  = array();

		foreach ( ETM_Bookings::get_ticket_lines( $booking_id ) as $line ) {
			$tickets[] = array(
				'title'       => $line['title'],
				'quantity'    => $line['quantity'],
				'unit_price'  => etm_format_price( (float) $line['unit_price'], $currency ),
				'subtotal'    => etm_format_price( (float) $line['subtotal'], $currency ),
				'price_label' => (string) ( $line['price_label'] ?? '' ),
			);
		}

		$attendees = array();

		foreach ( ETM_Attendees::get_for_booking( $booking_id ) as $attendee ) {
			$attendees[] = array(
				'number'      => (int) $attendee->attendee_number,
				'name'        => (string) $attendee->name,
				'email'       => (string) $attendee->email,
				'ticket_type' => (string) $attendee->ticket_title_snapshot,
			);
		}

		return array(
			'is_paid'          => ETM_Bookings::is_confirmed( (string) $booking->payment_status ),
			'booking_number'   => (string) $booking->booking_number,
			'booking_date'     => etm_format_datetime( $booking->created_at ),
			'payment_status'   => etm_booking_status_label( (string) $booking->payment_status ),
			'transaction_id'   => (string) $booking->stripe_payment_intent_id,
			'event_title'      => $event ? (string) $event->title : '',
			'event_date'       => $event ? etm_format_datetime( $event->start_datetime, false ) : '',
			'event_time'       => $event ? etm_event_time_range( $event ) : '',
			'customer_name'    => (string) $booking->customer_name,
			'customer_email'   => (string) $booking->customer_email,
			'ticket_quantity'  => (int) $booking->ticket_quantity,
			'tickets'          => $tickets,
			'ticket_subtotal'  => etm_format_price( (float) $booking->ticket_subtotal, $currency ),
			'fees_total'       => etm_format_price( (float) $booking->fees_total, $currency ),
			'has_fees'         => (float) $booking->fees_total > 0,
			'coupon_code'      => (string) $booking->coupon_code,
			'discount_total'   => etm_format_price( (float) $booking->discount_total, $currency ),
			'has_discount'     => (float) $booking->discount_total > 0,
			'event_type'       => (string) $booking->event_type,
			'is_rsvp'          => ETM_Events::TYPE_RSVP === $booking->event_type,
			'roommates'        => ETM_Bookings::get_roommates( $booking ),
			'total_paid'       => etm_format_price( (float) $booking->total, $currency ),
			'attendees'        => $attendees,
		);
	}

	/**
	 * Load this visitor's hold from the token they were given.
	 *
	 * @param array<string, mixed> $raw      Raw request data.
	 * @param int                  $event_id Event the hold must belong to.
	 * @return object|null
	 */
	private function resolve_hold( array $raw, int $event_id ): ?object {
		$token = isset( $raw['token'] ) ? ETM_Pending_Orders::sanitize_token( (string) $raw['token'] ) : '';

		if ( '' === $token ) {
			return null;
		}

		$hold = ETM_Pending_Orders::get( $token );

		if ( ! $hold || ( $event_id > 0 && (int) $hold->event_id !== $event_id ) ) {
			return null;
		}

		if ( ! ETM_Pending_Orders::token_matches( (string) $hold->token, $token ) ) {
			return null;
		}

		return $hold;
	}

	/**
	 * Find an already-paid booking for a supplied token.
	 *
	 * @param array<string, mixed> $raw Raw request data.
	 * @return object|null
	 */
	private function resolve_paid_booking( array $raw ): ?object {
		$token = isset( $raw['token'] ) ? ETM_Pending_Orders::sanitize_token( (string) $raw['token'] ) : '';

		return '' === $token ? null : ETM_Bookings::get_by_token( $token );
	}

	/**
	 * Validate customer, attendee and roommate submissions.
	 *
	 * Attendee slot 0 is the purchaser, whose name and email come from the
	 * customer fields, so the form never asks for the same person twice. Every
	 * slot carries the ticket type it belongs to.
	 *
	 * @param array<string, mixed>             $raw          Raw POST data (unslashed).
	 * @param array<int, array<string, mixed>> $slots        Attendee slots from the selection.
	 * @param bool                             $sharing_room Order contains a Sharing a Room ticket.
	 * @return array<string, mixed>
	 */
	private function validate_submission( array $raw, array $slots, bool $sharing_room ): array {
		$errors = array();

		$customer_raw    = isset( $raw['customer'] ) && is_array( $raw['customer'] ) ? $raw['customer'] : array();
		$customer_result = ETM_Custom_Fields::validate( ETM_Custom_Fields::GROUP_CUSTOMER, $customer_raw );

		foreach ( $customer_result['errors'] as $key => $message ) {
			$errors[ 'customer[' . $key . ']' ] = $message;
		}

		$attendees_raw   = isset( $raw['attendees'] ) && is_array( $raw['attendees'] ) ? $raw['attendees'] : array();
		$attendee_custom = ETM_Custom_Fields::get_custom_fields( ETM_Custom_Fields::GROUP_ATTENDEE );
		$attendees       = array();

		foreach ( $slots as $index => $slot ) {
			$attendee_raw = isset( $attendees_raw[ $index ] ) && is_array( $attendees_raw[ $index ] ) ? $attendees_raw[ $index ] : array();

			if ( 0 === $index ) {
				// The purchaser is attendee 1 of the first selected ticket type.
				$attendee_raw['name']  = $customer_result['values']['customer_name'] ?? '';
				$attendee_raw['email'] = $customer_result['values']['customer_email'] ?? '';
			}

			$result = ETM_Custom_Fields::validate( ETM_Custom_Fields::GROUP_ATTENDEE, $attendee_raw );

			foreach ( $result['errors'] as $key => $message ) {
				// Purchaser name/email errors belong to the customer fields.
				if ( 0 === $index && in_array( $key, array( 'name', 'email' ), true ) ) {
					continue;
				}

				$errors[ 'attendees[' . $index . '][' . $key . ']' ] = $message;
			}

			$values = $result['values'];
			$custom = array();

			foreach ( $attendee_custom as $field ) {
				if ( array_key_exists( $field['key'], $values ) ) {
					$custom[ $field['key'] ] = $values[ $field['key'] ];
				}
			}

			$attendees[] = array(
				'name'           => (string) ( $values['name'] ?? '' ),
				'email'          => (string) ( $values['email'] ?? '' ),
				'ticket_type_id' => (int) $slot['ticket_type_id'],
				'ticket_title'   => (string) $slot['title'],
				'custom'         => $custom,
			);
		}

		// Exactly one roommate, or none. The quantity of Sharing a Room tickets
		// decides whether to ask, never how many times to ask: the purchaser is
		// already known from the customer fields above, and the roommate is the
		// one other person the room is shared with.
		$roommate = null;

		if ( $sharing_room ) {
			$roommate_raw    = isset( $raw['sharing_room'] ) && is_array( $raw['sharing_room'] ) ? $raw['sharing_room'] : array();
			$roommate_result = ETM_Custom_Fields::validate( ETM_Custom_Fields::GROUP_ROOMMATE, $roommate_raw );

			foreach ( $roommate_result['errors'] as $key => $message ) {
				$errors[ 'sharing_room[' . $key . ']' ] = $message;
			}

			$values = $roommate_result['values'];
			$custom = array();

			foreach ( ETM_Custom_Fields::get_custom_fields( ETM_Custom_Fields::GROUP_ROOMMATE ) as $field ) {
				if ( array_key_exists( $field['key'], $values ) ) {
					$custom[ $field['key'] ] = $values[ $field['key'] ];
				}
			}

			$roommate = array(
				'name'   => (string) ( $values['roommate_name'] ?? '' ),
				'email'  => (string) ( $values['roommate_email'] ?? '' ),
				'custom' => $custom,
			);
		}

		$customer_custom = array();

		foreach ( ETM_Custom_Fields::get_custom_fields( ETM_Custom_Fields::GROUP_CUSTOMER ) as $field ) {
			if ( isset( $customer_result['values'][ $field['key'] ] ) ) {
				$customer_custom[ $field['key'] ] = $customer_result['values'][ $field['key'] ];
			}
		}

		return array(
			'customer'        => $customer_result['values'],
			'customer_custom' => $customer_custom,
			'attendees'       => $attendees,
			'sharing_room'    => $roommate,
			'errors'          => $errors,
		);
	}

	/**
	 * Create the PaymentIntent for a hold, or update the one it already has.
	 *
	 * @param string               $token      Hold token.
	 * @param object               $event      Event row.
	 * @param array<string, mixed> $totals     Server calculated totals.
	 * @param array<string, mixed> $validation Validated submission.
	 * @param array<string, mixed> $selection  Validated ticket selection.
	 * @return array<string, mixed>|WP_Error
	 */
	private function prepare_payment_intent( string $token, object $event, array $totals, array $validation, array $selection ) {
		$currency = strtolower( $totals['currency'] );
		$amount   = ETM_Stripe::amount_to_stripe( $totals['total'], $currency );

		if ( $amount < 1 ) {
			return new WP_Error( 'etm_amount_too_low', __( 'This order total cannot be processed.', 'event-ticket-manager' ) );
		}

		$breakdown = array();

		foreach ( $selection['lines'] as $line ) {
			$breakdown[] = $line['title'] . ' x ' . (int) $line['quantity'];
		}

		$metadata = array(
			'event_id'      => (string) $event->id,
			'pending_token' => $token,
			// Kept short: Stripe caps metadata values at 500 characters.
			'tickets'       => substr( implode( ', ', $breakdown ), 0, 480 ),
		);

		$params = array(
			'amount'                    => $amount,
			'currency'                  => $currency,
			/* translators: %s: event title. */
			'description'               => sprintf( __( 'Tickets for %s', 'event-ticket-manager' ), $event->title ),
			'metadata'                  => $metadata,
			// Card-style methods only: a redirect would take the customer out of
			// the modal, which is exactly what this flow avoids.
			'automatic_payment_methods' => array(
				'enabled'         => 'true',
				'allow_redirects' => 'never',
			),
		);

		$email = (string) ( $validation['customer']['customer_email'] ?? '' );

		if ( is_email( $email ) ) {
			$params['receipt_email'] = $email;
		}

		$descriptor = trim( (string) ETM_Settings::stripe( 'statement_descriptor', '' ) );

		if ( '' !== $descriptor ) {
			$params['statement_descriptor_suffix'] = substr( $descriptor, 0, 22 );
		}

		$hold      = ETM_Pending_Orders::get( $token );
		$intent_id = $hold ? (string) $hold->stripe_payment_intent_id : '';

		if ( '' !== $intent_id ) {
			$existing = ETM_Stripe::retrieve_payment_intent( $intent_id );

			if ( ! is_wp_error( $existing ) ) {
				$status = (string) ( $existing['status'] ?? '' );

				if ( 'succeeded' === $status ) {
					return $existing;
				}

				if ( ETM_Stripe::intent_is_reusable( $status ) ) {
					$update = ETM_Stripe::update_payment_intent(
						$intent_id,
						array(
							'amount'      => $amount,
							'currency'    => $currency,
							'description' => $params['description'],
							'metadata'    => $metadata,
						)
					);

					if ( ! is_wp_error( $update ) ) {
						return $update;
					}
				}
			}
		}

		$intent = ETM_Stripe::create_payment_intent( $params );

		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		if ( empty( $intent['id'] ) || empty( $intent['client_secret'] ) ) {
			return new WP_Error( 'etm_stripe_intent_invalid', __( 'The payment could not be started.', 'event-ticket-manager' ) );
		}

		ETM_Pending_Orders::set_payment_intent( $token, (string) $intent['id'] );

		return $intent;
	}

	/**
	 * Simple per-IP throttle on checkout creation.
	 *
	 * @return bool True when the request may proceed.
	 */
	private function check_rate_limit(): bool {
		/**
		 * Filter the number of checkout attempts allowed per minute per IP.
		 *
		 * @param int $limit Attempts.
		 */
		$limit = (int) apply_filters( 'etm_checkout_rate_limit', 15 );

		if ( $limit <= 0 ) {
			return true;
		}

		$key   = 'etm_rl_' . md5( etm_get_client_ip() );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}
}
