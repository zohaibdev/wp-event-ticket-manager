<?php
/**
 * Stripe webhook endpoint.
 *
 * A booking becomes paid here, after the signature on the raw request body has
 * been verified. Delivery is idempotent: Stripe retries and duplicate events are
 * recorded and ignored, and the confirmation itself is guarded by a conditional
 * UPDATE so two concurrent deliveries cannot both send emails.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint and event handlers.
 */
class ETM_Webhooks {

	public const REST_NAMESPACE = 'event-tickets/v1';
	public const REST_ROUTE     = '/stripe';

	/**
	 * Option name prefix used to record processed Stripe event IDs.
	 */
	private const PROCESSED_PREFIX = 'etm_whk_';

	/**
	 * Register hooks.
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Public webhook URL.
	 *
	 * @return string
	 */
	public static function get_endpoint_url(): string {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/**
	 * The Stripe events this endpoint acts on.
	 *
	 * @return string[]
	 */
	public static function subscribed_events(): array {
		return array(
			'payment_intent.succeeded',
			'payment_intent.payment_failed',
			'payment_intent.canceled',
			'charge.refunded',
		);
	}

	/**
	 * Register the REST route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				// Authentication is the Stripe signature, verified in the handler.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle an incoming webhook request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$payload   = $request->get_body();
		$signature = (string) $request->get_header( 'stripe_signature' );
		$secret    = ETM_Stripe::get_webhook_secret();

		$verified = ETM_Stripe::verify_webhook_signature( $payload, $signature, $secret );

		if ( is_wp_error( $verified ) ) {
			ETM_Stripe::log( 'Webhook rejected: ' . $verified->get_error_message() );

			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 400 );
		}

		$event = json_decode( $payload, true );

		if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}

		$event_id   = sanitize_text_field( (string) $event['id'] );
		$event_type = sanitize_text_field( (string) $event['type'] );

		if ( ! $this->claim_event( $event_id ) ) {
			ETM_Stripe::log( 'Duplicate webhook ignored: ' . $event_id );

			return new WP_REST_Response( array( 'status' => 'duplicate' ), 200 );
		}

		$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();

		switch ( $event_type ) {
			case 'payment_intent.succeeded':
				self::confirm_paid_intent( $object );
				break;

			case 'payment_intent.payment_failed':
				$this->handle_payment_failed( $object );
				break;

			case 'payment_intent.canceled':
				$this->handle_payment_canceled( $object );
				break;

			case 'charge.refunded':
				$this->handle_charge_refunded( $object );
				break;

			default:
				/**
				 * Fires for webhook events the plugin does not handle itself.
				 *
				 * @param array  $event      Full event payload.
				 * @param string $event_type Event type.
				 */
				do_action( 'etm_stripe_webhook_unhandled', $event, $event_type );
				break;
		}

		/**
		 * Fires after a verified webhook event has been processed.
		 *
		 * @param array  $event      Full event payload.
		 * @param string $event_type Event type.
		 */
		do_action( 'etm_stripe_webhook_processed', $event, $event_type );

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	/**
	 * Record a Stripe event ID exactly once.
	 *
	 * add_option() fails when the option already exists, which gives an atomic
	 * claim backed by the unique index on option_name.
	 *
	 * @param string $event_id Stripe event ID.
	 * @return bool True when this process claimed the event.
	 */
	private function claim_event( string $event_id ): bool {
		$option = self::PROCESSED_PREFIX . md5( $event_id );

		return add_option( $option, time(), '', 'no' );
	}

	/**
	 * Remove webhook claim records older than a month.
	 *
	 * @return int Rows removed.
	 */
	public static function purge_processed_events(): int {
		global $wpdb;

		$cutoff = time() - ( 30 * DAY_IN_SECONDS );
		$prefix = self::PROCESSED_PREFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$names = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 500",
				$wpdb->esc_like( $prefix ) . '%',
				$cutoff
			)
		);

		foreach ( $names as $name ) {
			delete_option( $name );
		}

		return count( $names );
	}

	/**
	 * Resolve the booking a Stripe object belongs to.
	 *
	 * @param array<string, mixed> $object Stripe object.
	 * @return object|null
	 */
	private static function resolve_booking( array $object ): ?object {
		$booking_id = 0;

		// Orders taken before bookings were deferred still carry a booking id.
		if ( isset( $object['metadata']['booking_id'] ) ) {
			$booking_id = absint( $object['metadata']['booking_id'] );
		}

		if ( $booking_id ) {
			$booking = ETM_Bookings::get( $booking_id );

			if ( $booking ) {
				return $booking;
			}
		}

		if ( ! empty( $object['metadata']['pending_token'] ) ) {
			$booking = ETM_Bookings::get_by_token( (string) $object['metadata']['pending_token'] );

			if ( $booking ) {
				return $booking;
			}
		}

		if ( ! empty( $object['id'] ) && is_string( $object['id'] ) && str_starts_with( $object['id'], 'pi_' ) ) {
			return ETM_Bookings::get_by_payment_intent( $object['id'] );
		}

		// A charge carries its intent reference instead of its own booking link.
		if ( ! empty( $object['payment_intent'] ) && is_string( $object['payment_intent'] ) ) {
			return ETM_Bookings::get_by_payment_intent( $object['payment_intent'] );
		}

		return null;
	}

	/**
	 * Turn a succeeded PaymentIntent into a paid booking.
	 *
	 * Shared by the webhook and the in-modal confirmation call, so both routes
	 * apply the same verification. Creation is idempotent: the bookings table
	 * has a unique index on stripe_payment_intent_id, so whichever route arrives
	 * second reads back the row the first one wrote.
	 *
	 * @param array<string, mixed> $intent PaymentIntent retrieved from Stripe.
	 * @return object|false The booking, or false when nothing could be matched.
	 */
	public static function confirm_paid_intent( array $intent ) {
		if ( 'succeeded' !== (string) ( $intent['status'] ?? '' ) ) {
			return false;
		}

		$intent_id = ! empty( $intent['id'] ) && is_string( $intent['id'] ) ? $intent['id'] : '';

		if ( '' === $intent_id ) {
			return false;
		}

		// Already turned into a booking by the other route.
		$existing = ETM_Bookings::get_by_payment_intent( $intent_id );

		if ( $existing ) {
			return $existing;
		}

		$token = isset( $intent['metadata']['pending_token'] )
			? ETM_Pending_Orders::sanitize_token( (string) $intent['metadata']['pending_token'] )
			: '';

		$hold = '' !== $token ? ETM_Pending_Orders::get( $token ) : null;

		if ( ! $hold ) {
			$hold = ETM_Pending_Orders::get_by_payment_intent( $intent_id );
		}

		if ( $hold ) {
			self::verify_hold_amount( $hold, $intent );
			self::check_hold_capacity( $hold );

			$booking = ETM_Bookings::create_from_pending( $hold, $intent );

			if ( is_wp_error( $booking ) ) {
				ETM_Stripe::log( 'Could not create booking for intent ' . $intent_id . ': ' . $booking->get_error_message() );

				return false;
			}

			// The seats are now held by the booking itself.
			ETM_Pending_Orders::delete( (string) $hold->token );

			return $booking;
		}

		// Legacy path: a pending booking row created before bookings were
		// deferred until payment.
		$booking = self::resolve_booking( $intent );

		if ( ! $booking || ETM_Bookings::STATUS_PAID === $booking->payment_status ) {
			return $booking ?: false;
		}

		self::check_capacity( $booking );
		ETM_Bookings::mark_paid( (int) $booking->id, $intent_id );

		return ETM_Bookings::get( (int) $booking->id );
	}

	/**
	 * Compare what Stripe collected with the total stored on the hold.
	 *
	 * @param object               $hold   Holding record.
	 * @param array<string, mixed> $intent PaymentIntent object.
	 */
	private static function verify_hold_amount( object $hold, array $intent ): void {
		$received = $intent['amount_received'] ?? ( $intent['amount'] ?? null );

		if ( null === $received ) {
			return;
		}

		$currency = (string) ( $intent['currency'] ?? $hold->currency );
		$paid     = ETM_Stripe::amount_from_stripe( (int) $received, $currency );
		$expected = round( (float) $hold->total, 2 );

		if ( abs( $paid - $expected ) >= 0.01 ) {
			ETM_Stripe::log( 'Amount mismatch on hold ' . $hold->token . ': expected ' . $expected . ', received ' . $paid );
		}
	}

	/**
	 * Re-check capacity as a hold becomes a booking.
	 *
	 * The customer has paid, so the booking is honoured either way; the
	 * organiser is warned when the event is now over its limit.
	 *
	 * @param object $hold Holding record.
	 */
	private static function check_hold_capacity( object $hold ): void {
		$event = ETM_Events::get( (int) $hold->event_id );

		if ( ! $event ) {
			return;
		}

		$payload  = ETM_Pending_Orders::payload( $hold );
		$quantity = (int) ( $payload['quantity'] ?? 0 );

		// Holds taken through the old add-on carried a roommate count of their
		// own; a shared room is now an ordinary ticket inside the quantity.
		$roommate = max( 0, (int) ( $payload['roommate_quantity'] ?? 0 ) );

		$available = ETM_Capacity::check_availability( $event, $quantity, $roommate, null, (string) $hold->token );

		if ( is_wp_error( $available ) ) {
			ETM_Stripe::log( 'Capacity exceeded while confirming hold ' . $hold->token . ': ' . $available->get_error_message() );
		}
	}

	/**
	 * Handle a failed PaymentIntent.
	 *
	 * @param array<string, mixed> $intent PaymentIntent object.
	 */
	private function handle_payment_failed( array $intent ): void {
		$booking = self::resolve_booking( $intent );

		// With no booking row yet, a decline just leaves the hold in place so
		// the customer can retry until it expires.
		if ( ! $booking || ETM_Bookings::STATUS_PAID === $booking->payment_status ) {
			return;
		}

		$reason = (string) ( $intent['last_payment_error']['message'] ?? __( 'The payment was declined.', 'event-ticket-manager' ) );

		ETM_Bookings::set_status( (int) $booking->id, ETM_Bookings::STATUS_FAILED );
		ETM_Bookings::update_meta( (int) $booking->id, 'failure_reason', sanitize_text_field( $reason ) );
	}

	/**
	 * Handle a cancelled PaymentIntent.
	 *
	 * @param array<string, mixed> $intent PaymentIntent object.
	 */
	private function handle_payment_canceled( array $intent ): void {
		$intent_id = ! empty( $intent['id'] ) && is_string( $intent['id'] ) ? $intent['id'] : '';
		$hold      = '' !== $intent_id ? ETM_Pending_Orders::get_by_payment_intent( $intent_id ) : null;

		if ( $hold ) {
			// No booking was ever written, so releasing the hold is all it takes.
			ETM_Pending_Orders::delete( (string) $hold->token );

			return;
		}

		$booking = self::resolve_booking( $intent );

		if ( ! $booking || ETM_Bookings::STATUS_PAID === $booking->payment_status ) {
			return;
		}

		ETM_Bookings::set_status( (int) $booking->id, ETM_Bookings::STATUS_CANCELLED );
	}

	/**
	 * Reflect a refund made in the Stripe dashboard back onto the booking.
	 *
	 * @param array<string, mixed> $charge Charge object.
	 */
	private function handle_charge_refunded( array $charge ): void {
		$booking = self::resolve_booking( $charge );

		if ( ! $booking ) {
			return;
		}

		$refunded = (int) ( $charge['amount_refunded'] ?? 0 );
		$total    = (int) ( $charge['amount'] ?? 0 );

		if ( $refunded <= 0 ) {
			return;
		}

		ETM_Bookings::update_meta(
			(int) $booking->id,
			'refunded_amount',
			ETM_Stripe::amount_from_stripe( $refunded, (string) ( $charge['currency'] ?? $booking->currency ) )
		);

		// Only a full refund releases the places back to the event.
		if ( $refunded >= $total && $total > 0 ) {
			ETM_Bookings::set_status( (int) $booking->id, ETM_Bookings::STATUS_REFUNDED );
		}
	}

	/**
	 * Re-check capacity when confirming, and flag the booking if the event is
	 * now over its limit. The customer has paid, so the booking is honoured and
	 * the organiser is warned instead.
	 *
	 * @param object $booking Booking row.
	 */
	private static function check_capacity( object $booking ): void {
		$event = ETM_Events::get( (int) $booking->event_id );

		if ( ! $event ) {
			return;
		}

		$available = ETM_Capacity::check_availability(
			$event,
			(int) $booking->ticket_quantity,
			ETM_Bookings::roommate_quantity( $booking ),
			(int) $booking->id
		);

		if ( is_wp_error( $available ) ) {
			ETM_Bookings::update_meta( (int) $booking->id, 'capacity_warning', $available->get_error_message() );
			ETM_Stripe::log( 'Capacity exceeded while confirming booking ' . $booking->id . ': ' . $available->get_error_message() );
		}
	}

	/**
	 * Compare the amount Stripe collected with the stored booking total.
	 *
	 * @param object               $booking Booking row.
	 * @param array<string, mixed> $intent  PaymentIntent object.
	 */
	private static function verify_amount( object $booking, array $intent ): void {
		$received = $intent['amount_received'] ?? ( $intent['amount'] ?? null );

		if ( null === $received ) {
			return;
		}

		$currency = (string) ( $intent['currency'] ?? $booking->currency );
		$paid     = ETM_Stripe::amount_from_stripe( (int) $received, $currency );
		$expected = round( (float) $booking->total, 2 );

		if ( abs( $paid - $expected ) >= 0.01 ) {
			ETM_Bookings::update_meta(
				(int) $booking->id,
				'amount_mismatch',
				sprintf( 'expected %s, received %s', number_format( $expected, 2, '.', '' ), number_format( $paid, 2, '.', '' ) )
			);

			ETM_Stripe::log( 'Amount mismatch on booking ' . $booking->id . ': expected ' . $expected . ', received ' . $paid );
		}
	}
}
