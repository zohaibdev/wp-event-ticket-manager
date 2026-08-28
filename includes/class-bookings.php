<?php
/**
 * Booking storage, status transitions and booking numbers.
 *
 * A row exists here only once a payment has been verified. Unpaid checkouts
 * live in ETM_Pending_Orders and never reach this table.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * All read/write access to the bookings table lives here.
 */
class ETM_Bookings {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_PAID      = 'paid';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_REFUNDED  = 'refunded';

	/**
	 * No payment was required: the order came to zero after discounts.
	 */
	public const STATUS_FREE = 'free';

	/**
	 * No payment was required: the event takes registrations, not payments.
	 */
	public const STATUS_RSVP = 'rsvp';

	/**
	 * Valid payment statuses with labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_PENDING   => __( 'Pending', 'event-ticket-manager' ),
			self::STATUS_PAID      => __( 'Paid', 'event-ticket-manager' ),
			self::STATUS_FAILED    => __( 'Failed', 'event-ticket-manager' ),
			self::STATUS_CANCELLED => __( 'Cancelled', 'event-ticket-manager' ),
			self::STATUS_REFUNDED  => __( 'Refunded', 'event-ticket-manager' ),
			self::STATUS_FREE      => __( 'Free', 'event-ticket-manager' ),
			self::STATUS_RSVP      => __( 'RSVP', 'event-ticket-manager' ),
		);
	}

	/**
	 * Statuses that mean the place is taken and the booking is confirmed.
	 *
	 * Paid orders, orders that came to zero, and RSVPs all hold their seats and
	 * all count toward capacity and coupon usage.
	 *
	 * @return string[]
	 */
	public static function confirmed_statuses(): array {
		return array( self::STATUS_PAID, self::STATUS_FREE, self::STATUS_RSVP );
	}

	/**
	 * Whether a status means the booking is confirmed.
	 *
	 * @param string $status Status key.
	 * @return bool
	 */
	public static function is_confirmed( string $status ): bool {
		return in_array( $status, self::confirmed_statuses(), true );
	}

	/**
	 * Insert a booking row.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int|WP_Error Booking ID.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = array(
			'booking_number'             => null,
			'event_id'                   => (int) ( $data['event_id'] ?? 0 ),
			'customer_name'              => sanitize_text_field( (string) ( $data['customer_name'] ?? '' ) ),
			'customer_email'             => sanitize_email( (string) ( $data['customer_email'] ?? '' ) ),
			'ticket_quantity'            => max( 0, (int) ( $data['ticket_quantity'] ?? 0 ) ),
			'ticket_price'               => round( (float) ( $data['ticket_price'] ?? 0 ), 2 ),
			'ticket_subtotal'            => round( (float) ( $data['ticket_subtotal'] ?? 0 ), 2 ),
			'roommate_enabled'           => empty( $data['roommate_enabled'] ) ? 0 : 1,
			'roommate_quantity'          => max( 0, (int) ( $data['roommate_quantity'] ?? 0 ) ),
			'roommate_name'              => isset( $data['roommate_name'] ) ? sanitize_text_field( (string) $data['roommate_name'] ) : null,
			'roommate_email'             => isset( $data['roommate_email'] ) ? sanitize_email( (string) $data['roommate_email'] ) : null,
			'roommate_price'             => round( (float) ( $data['roommate_price'] ?? 0 ), 2 ),
			'fees_total'                 => round( (float) ( $data['fees_total'] ?? 0 ), 2 ),
			'coupon_code'                => isset( $data['coupon_code'] ) ? sanitize_text_field( (string) $data['coupon_code'] ) : null,
			'discount_total'             => round( (float) ( $data['discount_total'] ?? 0 ), 2 ),
			'total'                      => round( (float) ( $data['total'] ?? 0 ), 2 ),
			'currency'                   => strtoupper( sanitize_text_field( (string) ( $data['currency'] ?? ETM_Settings::currency() ) ) ),
			'event_type'                 => ETM_Events::TYPE_RSVP === ( $data['event_type'] ?? '' ) ? ETM_Events::TYPE_RSVP : ETM_Events::TYPE_TICKET,
			'payment_status'             => isset( self::statuses()[ $data['payment_status'] ?? '' ] ) ? $data['payment_status'] : self::STATUS_PENDING,
			'stripe_checkout_session_id' => isset( $data['stripe_checkout_session_id'] ) ? sanitize_text_field( (string) $data['stripe_checkout_session_id'] ) : null,
			'stripe_payment_intent_id'   => isset( $data['stripe_payment_intent_id'] ) ? sanitize_text_field( (string) $data['stripe_payment_intent_id'] ) : null,
			'created_at'                 => $now,
			'updated_at'                 => $now,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert( ETM_Database::bookings_table(), $row, self::column_formats( $row ) );

		if ( ! $inserted ) {
			return new WP_Error( 'etm_booking_insert_failed', __( 'The booking could not be created.', 'event-ticket-manager' ) );
		}

		$booking_id = (int) $wpdb->insert_id;

		/**
		 * Fires after a booking row is created.
		 *
		 * @param int   $booking_id Booking ID.
		 * @param array $row        Stored values.
		 */
		do_action( 'etm_booking_created', $booking_id, $row );

		return $booking_id;
	}

	/**
	 * Update arbitrary booking columns.
	 *
	 * @param int                  $booking_id Booking ID.
	 * @param array<string, mixed> $values     Column values.
	 * @return bool
	 */
	public static function update( int $booking_id, array $values ): bool {
		global $wpdb;

		if ( $booking_id <= 0 || empty( $values ) ) {
			return false;
		}

		$values['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( ETM_Database::bookings_table(), $values, array( 'id' => $booking_id ), self::column_formats( $values ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Fetch one booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return object|null
	 */
	public static function get( int $booking_id ): ?object {
		global $wpdb;

		if ( $booking_id <= 0 ) {
			return null;
		}

		$table = ETM_Database::bookings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $booking_id ) );

		return $row ?: null;
	}

	/**
	 * Fetch a booking by a unique column value.
	 *
	 * @param string $column Column name (whitelisted).
	 * @param string $value  Value to match.
	 * @return object|null
	 */
	private static function get_by( string $column, string $value ): ?object {
		global $wpdb;

		$allowed = array( 'booking_number', 'stripe_payment_intent_id' );

		if ( ! in_array( $column, $allowed, true ) || '' === $value ) {
			return null;
		}

		$table = ETM_Database::bookings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} = %s ORDER BY id DESC LIMIT 1", $value ) );

		return $row ?: null;
	}

	/**
	 * Create the booking for a verified payment.
	 *
	 * This is the only path that writes a booking during checkout, and it runs
	 * exactly once per payment: the bookings table carries a unique index on
	 * stripe_payment_intent_id, so a concurrent webhook and browser confirmation
	 * cannot both insert. The loser reads back the winner's row.
	 *
	 * @param object               $pending Holding record.
	 * @param array<string, mixed> $intent  Verified PaymentIntent.
	 * @return object|WP_Error Booking row.
	 */
	public static function create_from_pending( object $pending, array $intent, string $status = self::STATUS_PAID ) {
		$intent_id = isset( $intent['id'] ) && is_string( $intent['id'] ) ? sanitize_text_field( $intent['id'] ) : '';

		// Paid orders must carry their payment reference; free and RSVP orders
		// have none and are keyed by their hold token instead.
		if ( self::STATUS_PAID === $status && '' === $intent_id ) {
			return new WP_Error( 'etm_missing_intent', __( 'The payment could not be matched to an order.', 'event-ticket-manager' ) );
		}

		// Whichever route arrives second reads back the first one's row.
		$existing = '' !== $intent_id ? self::get_by_payment_intent( $intent_id ) : self::get_by_token( (string) $pending->token );

		if ( $existing ) {
			return $existing;
		}

		$payload   = ETM_Pending_Orders::payload( $pending );
		$customer  = isset( $payload['customer'] ) && is_array( $payload['customer'] ) ? $payload['customer'] : array();
		$attendees = isset( $payload['attendees'] ) && is_array( $payload['attendees'] ) ? $payload['attendees'] : array();
		$totals    = isset( $payload['totals'] ) && is_array( $payload['totals'] ) ? $payload['totals'] : array();

		// At most one roommate, from a Sharing a Room ticket. It fills the
		// long-standing roommate columns so every existing view keeps working,
		// but takes no seat of its own: the shared room is itself the ticket.
		$roommate = isset( $payload['sharing_room'] ) && is_array( $payload['sharing_room'] )
			? $payload['sharing_room']
			: null;

		$lines = isset( $payload['lines'] ) && is_array( $payload['lines'] ) ? $payload['lines'] : array();

		// The legacy per-booking unit price only makes sense for a single type;
		// mixed orders read their prices from the ticket lines instead.
		$unit_price = ( 1 === count( $lines ) ) ? (float) ( $lines[0]['unit_price'] ?? 0 ) : 0.0;

		$booking_id = self::create(
			array(
				'event_id'                 => (int) $pending->event_id,
				'customer_name'            => (string) ( $customer['customer_name'] ?? '' ),
				'customer_email'           => (string) ( $customer['customer_email'] ?? '' ),
				'ticket_quantity'          => (int) ( $payload['quantity'] ?? 0 ),
				'ticket_price'             => $unit_price,
				'ticket_subtotal'          => (float) ( $totals['ticket_subtotal'] ?? 0 ),
				// The add-on flag and its price stay at zero: a shared room is
				// charged as the ticket line it is, not as an extra.
				'roommate_enabled'         => 0,
				'roommate_quantity'        => 0,
				'roommate_name'            => (string) ( $roommate['name'] ?? '' ),
				'roommate_email'           => (string) ( $roommate['email'] ?? '' ),
				'roommate_price'           => 0.0,
				'fees_total'               => (float) ( $totals['fees_total'] ?? 0 ),
				'coupon_code'              => (string) ( $totals['coupon_code'] ?? '' ),
				'discount_total'           => (float) ( $totals['discount_total'] ?? 0 ),
				'total'                    => (float) ( $totals['total'] ?? $pending->total ),
				'currency'                 => (string) $pending->currency,
				'event_type'               => (string) ( $payload['event_type'] ?? ETM_Events::TYPE_TICKET ),
				'payment_status'           => $status,
				'stripe_payment_intent_id' => '' !== $intent_id ? $intent_id : null,
			)
		);

		if ( is_wp_error( $booking_id ) ) {
			// Most likely the unique index rejected a concurrent duplicate.
			$winner = self::get_by_payment_intent( $intent_id );

			return $winner ?: $booking_id;
		}

		$booking_id = (int) $booking_id;

		self::save_ticket_lines( $booking_id, $lines );

		if ( ! empty( $totals['fee_lines'] ) ) {
			self::update_meta( $booking_id, 'fee_lines', $totals['fee_lines'] );
		}
		ETM_Attendees::save_for_booking( $booking_id, $attendees );

		// An order for nothing but a Sharing a Room ticket produces no attendee
		// slots, but the purchaser is still an attendee and still needs their
		// own code, QR and check-in status.
		ETM_Attendees::ensure_primary_for_booking( $booking_id );

		$customer_custom = isset( $payload['customer_custom'] ) && is_array( $payload['customer_custom'] )
			? $payload['customer_custom']
			: array();

		foreach ( $customer_custom as $key => $value ) {
			self::update_meta( $booking_id, self::custom_field_meta_key( 'customer', (string) $key ), $value );
		}

		// The roommate keeps the original "roommate" custom-field scope, so any
		// extra roommate question an admin added still lands where it always did.
		if ( $roommate ) {
			$custom = isset( $roommate['custom'] ) && is_array( $roommate['custom'] ) ? $roommate['custom'] : array();

			foreach ( $custom as $key => $value ) {
				self::update_meta( $booking_id, self::custom_field_meta_key( 'roommate', (string) $key ), $value );
			}

			// Marks this pair as having come from a Sharing a Room ticket rather
			// than from the add-on the plugin used to sell.
			self::update_meta(
				$booking_id,
				'sharing_room',
				array(
					'name'  => (string) ( $roommate['name'] ?? '' ),
					'email' => (string) ( $roommate['email'] ?? '' ),
				)
			);
		}

		// The browser keeps this token, so it can read back its own order.
		self::update_meta( $booking_id, 'access_token', (string) $pending->token );
		self::update_meta( $booking_id, 'ip_address', (string) ( $payload['ip_address'] ?? '' ) );
		self::update_meta( $booking_id, 'stripe_mode', (string) ( $payload['stripe_mode'] ?? ETM_Stripe::get_mode() ) );

		self::assign_booking_number( $booking_id );

		$booking = self::get( $booking_id );

		/**
		 * Fires once, when a booking first becomes paid.
		 *
		 * @param int         $booking_id Booking ID.
		 * @param object|null $booking    Booking row.
		 */
		do_action( 'etm_booking_paid', $booking_id, $booking );

		return $booking ?: new WP_Error( 'etm_booking_missing', __( 'The booking could not be read back.', 'event-ticket-manager' ) );
	}

	/**
	 * Store the ticket-type breakdown for a booking.
	 *
	 * Titles and prices are snapshotted, so editing or deleting a ticket type
	 * later never changes what a past customer was charged.
	 *
	 * @param int                              $booking_id Booking ID.
	 * @param array<int, array<string, mixed>> $lines      Validated selection lines.
	 */
	public static function save_ticket_lines( int $booking_id, array $lines ): void {
		global $wpdb;

		$table = ETM_Database::booking_tickets_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'booking_id' => $booking_id ), array( '%d' ) );

		$now = current_time( 'mysql' );

		foreach ( $lines as $line ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				$table,
				array(
					'booking_id'            => $booking_id,
					'ticket_type_id'        => absint( $line['ticket_type_id'] ?? 0 ),
					'ticket_title_snapshot' => sanitize_text_field( (string) ( $line['title'] ?? '' ) ),
					'unit_price'            => round( (float) ( $line['unit_price'] ?? 0 ), 2 ),
					'quantity'              => absint( $line['quantity'] ?? 0 ),
					'subtotal'              => round( (float) ( $line['subtotal'] ?? 0 ), 2 ),
					'created_at'            => $now,
				),
				array( '%d', '%d', '%s', '%f', '%d', '%f', '%s' )
			);
		}
	}

	/**
	 * The ticket-type breakdown for a booking.
	 *
	 * Bookings taken before ticket types existed are represented by a single
	 * synthetic line built from the columns they do have, so old orders still
	 * display correctly and are never recalculated.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_ticket_lines( int $booking_id ): array {
		global $wpdb;

		$table = ETM_Database::booking_tickets_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY id ASC", $booking_id )
		);

		if ( ! empty( $rows ) ) {
			return array_map(
				static function ( $row ) {
					// The label is display text, so it is read live from the ticket
					// type and falls back when that type no longer exists.
					$type = ETM_Event_Tickets::get( (int) $row->ticket_type_id );

					return array(
						'ticket_type_id' => (int) $row->ticket_type_id,
						'title'          => (string) $row->ticket_title_snapshot,
						'unit_price'     => (float) $row->unit_price,
						'quantity'       => (int) $row->quantity,
						'subtotal'       => (float) $row->subtotal,
						'price_label'    => $type ? ETM_Event_Tickets::price_label( $type ) : ETM_Event_Tickets::default_price_label(),
					);
				},
				$rows
			);
		}

		$booking = self::get( $booking_id );

		if ( ! $booking || (int) $booking->ticket_quantity < 1 ) {
			return array();
		}

		return array(
			array(
				'ticket_type_id' => 0,
				'title'          => __( 'Ticket', 'event-ticket-manager' ),
				'unit_price'     => (float) $booking->ticket_price,
				'quantity'       => (int) $booking->ticket_quantity,
				'subtotal'       => (float) $booking->ticket_subtotal,
				'price_label'    => ETM_Event_Tickets::default_price_label(),
			),
		);
	}

	/**
	 * Fetch a booking by the access token handed to the browser.
	 *
	 * @param string $token Access token.
	 * @return object|null
	 */
	public static function get_by_token( string $token ): ?object {
		global $wpdb;

		$token = ETM_Pending_Orders::sanitize_token( $token );

		if ( '' === $token ) {
			return null;
		}

		$meta = ETM_Database::booking_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$booking_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT booking_id FROM {$meta} WHERE meta_key = %s AND meta_value = %s ORDER BY id DESC LIMIT 1",
				'access_token',
				$token
			)
		);

		return $booking_id > 0 ? self::get( $booking_id ) : null;
	}

	/**
	 * Fetch a booking by Stripe PaymentIntent ID.
	 *
	 * @param string $payment_intent_id PaymentIntent ID.
	 * @return object|null
	 */
	public static function get_by_payment_intent( string $payment_intent_id ): ?object {
		return self::get_by( 'stripe_payment_intent_id', sanitize_text_field( $payment_intent_id ) );
	}

	/**
	 * Fetch a booking by its human readable number.
	 *
	 * @param string $booking_number Booking number.
	 * @return object|null
	 */
	public static function get_by_number( string $booking_number ): ?object {
		return self::get_by( 'booking_number', sanitize_text_field( $booking_number ) );
	}

	/**
	 * Query bookings with filters and pagination.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array{items: object[], total: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'event_id'       => 0,
				'payment_status' => '',
				'search'         => '',
				'date_from'      => '',
				'date_to'        => '',
				'orderby'        => 'created_at',
				'order'          => 'DESC',
				'per_page'       => 20,
				'page'           => 1,
			)
		);

		$table  = ETM_Database::bookings_table();
		$where  = array( '1=1' );
		$params = array();

		if ( (int) $args['event_id'] > 0 ) {
			$where[]  = 'event_id = %d';
			$params[] = (int) $args['event_id'];
		}

		if ( '' !== $args['payment_status'] && isset( self::statuses()[ $args['payment_status'] ] ) ) {
			$where[]  = 'payment_status = %s';
			$params[] = $args['payment_status'];
		}

		$search = trim( (string) $args['search'] );

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( booking_number LIKE %s OR customer_email LIKE %s OR customer_name LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== trim( (string) $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d 00:00:00', strtotime( (string) $args['date_from'] ) );
		}

		if ( '' !== trim( (string) $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = gmdate( 'Y-m-d 23:59:59', strtotime( (string) $args['date_to'] ) );
		}

		$allowed_orderby = array( 'id', 'booking_number', 'created_at', 'total', 'payment_status', 'customer_name' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $per_page );
		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$items = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Count bookings per status for the admin filter bar.
	 *
	 * @return array<string, int>
	 */
	public static function status_counts(): array {
		global $wpdb;

		$table = ETM_Database::bookings_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT payment_status, COUNT(*) AS total FROM {$table} GROUP BY payment_status" );

		$counts = array_fill_keys( array_keys( self::statuses() ), 0 );

		foreach ( $rows as $row ) {
			$counts[ $row->payment_status ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Transition a booking to paid exactly once.
	 *
	 * The UPDATE is conditional on the current status so concurrent webhook
	 * deliveries cannot both win.
	 *
	 * @param int    $booking_id        Booking ID.
	 * @param string $payment_intent_id Stripe PaymentIntent ID.
	 * @return bool True when this call performed the transition.
	 */
	public static function mark_paid( int $booking_id, string $payment_intent_id = '' ): bool {
		global $wpdb;

		$table = ETM_Database::bookings_table();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET payment_status = %s, updated_at = %s WHERE id = %d AND payment_status <> %s",
				self::STATUS_PAID,
				$now,
				$booking_id,
				self::STATUS_PAID
			)
		);

		if ( ! $updated ) {
			return false;
		}

		if ( '' !== $payment_intent_id ) {
			self::update( $booking_id, array( 'stripe_payment_intent_id' => sanitize_text_field( $payment_intent_id ) ) );
		}

		self::assign_booking_number( $booking_id );

		$booking = self::get( $booking_id );

		/**
		 * Fires once, when a booking first becomes paid.
		 *
		 * @param int         $booking_id Booking ID.
		 * @param object|null $booking    Booking row.
		 */
		do_action( 'etm_booking_paid', $booking_id, $booking );

		return true;
	}

	/**
	 * Set a booking status (non-paid transitions).
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $status     New status.
	 * @return bool
	 */
	public static function set_status( int $booking_id, string $status ): bool {
		if ( ! isset( self::statuses()[ $status ] ) ) {
			return false;
		}

		if ( self::STATUS_PAID === $status ) {
			return self::mark_paid( $booking_id );
		}

		$booking = self::get( $booking_id );

		if ( ! $booking || $booking->payment_status === $status ) {
			return false;
		}

		$updated = self::update( $booking_id, array( 'payment_status' => $status ) );

		if ( $updated ) {
			/**
			 * Fires after a booking status changes.
			 *
			 * @param int    $booking_id Booking ID.
			 * @param string $status     New status.
			 * @param string $previous   Previous status.
			 */
			do_action( 'etm_booking_status_changed', $booking_id, $status, $booking->payment_status );
		}

		return $updated;
	}

	/**
	 * Generate and store the booking number if the booking does not have one.
	 *
	 * @param int $booking_id Booking ID.
	 * @return string Booking number.
	 */
	public static function assign_booking_number( int $booking_id ): string {
		$booking = self::get( $booking_id );

		if ( ! $booking ) {
			return '';
		}

		if ( ! empty( $booking->booking_number ) ) {
			return (string) $booking->booking_number;
		}

		$number = self::generate_booking_number( $booking );

		self::update( $booking_id, array( 'booking_number' => $number ) );

		return $number;
	}

	/**
	 * Build a unique booking number such as EVT-2026-000125.
	 *
	 * @param object $booking Booking row.
	 * @return string
	 */
	public static function generate_booking_number( object $booking ): string {
		$prefix = (string) ETM_Settings::general( 'booking_number_prefix', 'EVT' );
		$year   = gmdate( 'Y', strtotime( (string) $booking->created_at ) ?: time() );
		$number = sprintf( '%s-%s-%06d', $prefix, $year, (int) $booking->id );

		/**
		 * Filter the generated booking number.
		 *
		 * @param string $number  Booking number.
		 * @param object $booking Booking row.
		 */
		$number = (string) apply_filters( 'etm_booking_number', $number, $booking );

		// Guard against a filter producing a duplicate.
		$candidate = $number;
		$suffix    = 1;

		while ( self::get_by_number( $candidate ) ) {
			$candidate = $number . '-' . $suffix;
			++$suffix;

			if ( $suffix > 50 ) {
				$candidate = $number . '-' . wp_generate_password( 6, false, false );
				break;
			}
		}

		return $candidate;
	}

	/**
	 * Expire pending bookings older than the hold window.
	 *
	 * @return int Number of bookings expired.
	 */
	public static function expire_pending(): int {
		global $wpdb;

		$table  = ETM_Database::bookings_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( ETM_Capacity::hold_minutes() * MINUTE_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE payment_status = %s AND created_at < %s LIMIT 500",
				self::STATUS_PENDING,
				$cutoff
			)
		);

		foreach ( $ids as $id ) {
			$id      = (int) $id;
			$booking = self::get( $id );

			self::set_status( $id, self::STATUS_CANCELLED );
			self::update_meta( $id, 'expired', '1' );

			/**
			 * Filter whether an expired hold also cancels its Stripe intent.
			 *
			 * @param bool $cancel Defaults to true.
			 */
			if ( ! apply_filters( 'etm_cancel_expired_intents', true ) ) {
				continue;
			}

			// Releasing the seats without voiding the intent would let a stale
			// payment succeed against a booking that no longer holds a place.
			if ( $booking && ! empty( $booking->stripe_payment_intent_id ) ) {
				ETM_Stripe::cancel_payment_intent( (string) $booking->stripe_payment_intent_id );
			}
		}

		return count( $ids );
	}

	/**
	 * Delete a booking with its attendees and meta.
	 *
	 * @param int $booking_id Booking ID.
	 * @return bool
	 */
	public static function delete( int $booking_id ): bool {
		global $wpdb;

		if ( $booking_id <= 0 ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( ETM_Database::attendees_table(), array( 'booking_id' => $booking_id ), array( '%d' ) );
		$wpdb->delete( ETM_Database::booking_tickets_table(), array( 'booking_id' => $booking_id ), array( '%d' ) );
		$wpdb->delete( ETM_Database::booking_meta_table(), array( 'booking_id' => $booking_id ), array( '%d' ) );
		$deleted = $wpdb->delete( ETM_Database::bookings_table(), array( 'id' => $booking_id ), array( '%d' ) );
		// phpcs:enable

		if ( $deleted ) {
			/**
			 * Fires after a booking is deleted.
			 *
			 * @param int $booking_id Booking ID.
			 */
			do_action( 'etm_booking_deleted', $booking_id );
		}

		return (bool) $deleted;
	}

	/**
	 * Read one booking meta value.
	 *
	 * @param int    $booking_id    Booking ID.
	 * @param string $key           Meta key.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	public static function get_meta( int $booking_id, string $key, $default_value = '' ) {
		global $wpdb;

		$table = ETM_Database::booking_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE booking_id = %d AND meta_key = %s LIMIT 1", $booking_id, $key ) );

		return null === $value ? $default_value : maybe_unserialize( $value );
	}

	/**
	 * All meta rows for a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<string, mixed>
	 */
	public static function get_all_meta( int $booking_id ): array {
		global $wpdb;

		$table = ETM_Database::booking_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$table} WHERE booking_id = %d ORDER BY id ASC", $booking_id ) );

		$meta = array();

		foreach ( $rows as $row ) {
			$meta[ $row->meta_key ] = maybe_unserialize( $row->meta_value );
		}

		return $meta;
	}

	/**
	 * Create or update a booking meta row.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $key        Meta key.
	 * @param mixed  $value      Meta value.
	 */
	public static function update_meta( int $booking_id, string $key, $value ): void {
		global $wpdb;

		$table = ETM_Database::booking_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE booking_id = %d AND meta_key = %s LIMIT 1", $booking_id, $key ) );

		$stored = maybe_serialize( $value );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( $exists ) {
			$wpdb->update( $table, array( 'meta_value' => $stored ), array( 'id' => (int) $exists ), array( '%s' ), array( '%d' ) );
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'booking_id' => $booking_id,
				'meta_key'   => $key,
				'meta_value' => $stored,
			),
			array( '%d', '%s', '%s' )
		);
		// phpcs:enable
	}

	/**
	 * Custom field values captured for a booking, keyed by group.
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_custom_field_values( int $booking_id ): array {
		$meta   = self::get_all_meta( $booking_id );
		$values = array();

		foreach ( $meta as $key => $value ) {
			if ( ! str_starts_with( $key, 'cf_' ) ) {
				continue;
			}

			$parts = explode( ':', substr( $key, 3 ), 2 );

			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$values[ $parts[0] ][ $parts[1] ] = $value;
		}

		return $values;
	}

	/**
	 * How many roommates a booking recorded.
	 *
	 * Only ever more than one for a booking taken through the add-on the plugin
	 * used to sell; a Sharing a Room ticket records exactly one. Bookings from
	 * before the add-on had quantities read back as the single roommate they
	 * were, and none of them are rewritten.
	 *
	 * @param object $booking Booking row.
	 * @return int
	 */
	public static function roommate_quantity( object $booking ): int {
		$quantity = isset( $booking->roommate_quantity ) ? (int) $booking->roommate_quantity : 0;

		if ( $quantity > 0 ) {
			return $quantity;
		}

		return empty( $booking->roommate_enabled ) ? 0 : 1;
	}

	/**
	 * The roommates on a booking, whichever era it comes from.
	 *
	 * @param object $booking Booking row.
	 * @return array<int, array{name: string, email: string}>
	 */
	public static function get_roommates( object $booking ): array {
		// A Sharing a Room ticket: exactly one, and no add-on pricing.
		$shared = self::get_meta( (int) $booking->id, 'sharing_room', null );

		if ( is_array( $shared ) ) {
			return array(
				array(
					'name'  => (string) ( $shared['name'] ?? '' ),
					'email' => (string) ( $shared['email'] ?? '' ),
				),
			);
		}

		$quantity = self::roommate_quantity( $booking );

		if ( $quantity < 1 ) {
			return array();
		}

		$stored = self::get_meta( (int) $booking->id, 'roommates', array() );
		$people = array();

		foreach ( (array) $stored as $person ) {
			if ( ! is_array( $person ) ) {
				continue;
			}

			$people[] = array(
				'name'  => (string) ( $person['name'] ?? '' ),
				'email' => (string) ( $person['email'] ?? '' ),
			);
		}

		// Older bookings only ever have the pair stored on the row itself.
		if ( empty( $people ) ) {
			$people[] = array(
				'name'  => (string) $booking->roommate_name,
				'email' => (string) $booking->roommate_email,
			);
		}

		return array_slice( $people, 0, $quantity );
	}

	/**
	 * What one roommate cost on a booking that was sold the old add-on.
	 *
	 * Zero for a Sharing a Room ticket, which is priced as a ticket line.
	 *
	 * @param object $booking Booking row.
	 * @return float
	 */
	public static function roommate_unit_price( object $booking ): float {
		$stored = self::get_meta( (int) $booking->id, 'roommate_price_per_person', null );

		if ( null !== $stored && '' !== $stored ) {
			return round( (float) $stored, 2 );
		}

		$quantity = self::roommate_quantity( $booking );

		return $quantity > 0 ? round( (float) $booking->roommate_price / $quantity, 2 ) : 0.0;
	}

	/**
	 * Build the meta key used to store a custom field value.
	 *
	 * @param string $scope Scope such as customer, roommate or attendee_1.
	 * @param string $key   Field key.
	 * @return string
	 */
	public static function custom_field_meta_key( string $scope, string $key ): string {
		return 'cf_' . $scope . ':' . $key;
	}

	/**
	 * $wpdb format specifiers for the given column set.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return string[]
	 */
	private static function column_formats( array $data ): array {
		$map = array(
			'booking_number'             => '%s',
			'event_id'                   => '%d',
			'customer_name'              => '%s',
			'customer_email'             => '%s',
			'ticket_quantity'            => '%d',
			'ticket_price'               => '%f',
			'ticket_subtotal'            => '%f',
			'roommate_enabled'           => '%d',
			'roommate_quantity'          => '%d',
			'roommate_name'              => '%s',
			'roommate_email'             => '%s',
			'roommate_price'             => '%f',
			'fees_total'                 => '%f',
			'coupon_code'                => '%s',
			'discount_total'             => '%f',
			'total'                      => '%f',
			'currency'                   => '%s',
			'event_type'                 => '%s',
			'payment_status'             => '%s',
			'stripe_checkout_session_id' => '%s',
			'stripe_payment_intent_id'   => '%s',
			'created_at'                 => '%s',
			'updated_at'                 => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $column ) {
			$formats[] = $map[ $column ] ?? '%s';
		}

		return $formats;
	}
}
