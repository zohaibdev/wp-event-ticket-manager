<?php
/**
 * Seat maths and race-condition safe reservations.
 *
 * Sold seats always come from paid bookings. Unpaid checkouts have no booking
 * row at all; they hold their seats through ETM_Pending_Orders for a
 * configurable window, so a customer typing their card details cannot be
 * overbooked by someone else.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capacity calculations and locking.
 */
class ETM_Capacity {

	/**
	 * Unlimited capacity sentinel.
	 */
	public const UNLIMITED = -1;

	/**
	 * How long a pending booking holds its seats, in minutes.
	 *
	 * @return int
	 */
	public static function hold_minutes(): int {
		$minutes = (int) ETM_Settings::general( 'pending_hold_minutes', 30 );

		/**
		 * Filter the pending booking hold window.
		 *
		 * @param int $minutes Minutes.
		 */
		return (int) apply_filters( 'etm_pending_hold_minutes', max( 5, $minutes ) );
	}

	/**
	 * Seats consumed by one booking row for a given event.
	 *
	 * @param object $booking Booking row.
	 * @param object $event   Event row.
	 * @return int
	 */
	public static function seats_for_booking( object $booking, object $event ): int {
		$seats = (int) $booking->ticket_quantity;

		if ( ! empty( $event->roommate_counts_toward_capacity ) ) {
			$seats += ETM_Bookings::roommate_quantity( $booking );
		}

		return $seats;
	}

	/**
	 * Seats consumed by a prospective purchase.
	 *
	 * @param object $event             Event row.
	 * @param int    $quantity          Ticket quantity.
	 * @param int    $roommate_quantity Legacy add-on roommates, if any.
	 * @return int
	 */
	public static function seats_for_purchase( object $event, int $quantity, int $roommate_quantity = 0 ): int {
		$seats = max( 0, $quantity );

		if ( ! empty( $event->roommate_counts_toward_capacity ) ) {
			$seats += max( 0, $roommate_quantity );
		}

		return $seats;
	}

	/**
	 * Total seats already taken: paid bookings plus unexpired checkout holds.
	 *
	 * @param object   $event              Event row.
	 * @param int|null $exclude_booking_id Booking to leave out of the sum.
	 * @param string   $exclude_token      Checkout hold to leave out of the sum.
	 * @return int
	 */
	public static function get_reserved_seats( object $event, ?int $exclude_booking_id = null, string $exclude_token = '' ): int {
		return self::sum_seats( $event, true, $exclude_booking_id, $exclude_token );
	}

	/**
	 * Seats from confirmed (paid) bookings only.
	 *
	 * @param object $event Event row.
	 * @return int
	 */
	public static function get_sold_seats( object $event ): int {
		return self::sum_seats( $event, false, null, '' );
	}

	/**
	 * Tickets sold (excluding roommates) for admin reporting.
	 *
	 * @param int $event_id Event ID.
	 * @return int
	 */
	public static function get_sold_tickets( int $event_id ): int {
		global $wpdb;

		$bookings = ETM_Database::bookings_table();

		$statuses     = ETM_Bookings::confirmed_statuses();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(ticket_quantity), 0) FROM {$bookings} WHERE event_id = %d AND payment_status IN ({$placeholders})",
				array_merge( array( $event_id ), $statuses )
			)
		);
	}

	/**
	 * Sum seats for an event.
	 *
	 * @param object   $event              Event row.
	 * @param bool     $include_pending    Include unexpired checkout holds.
	 * @param int|null $exclude_booking_id Booking to leave out.
	 * @param string   $exclude_token      Checkout hold to leave out.
	 * @return int
	 */
	private static function sum_seats( object $event, bool $include_pending, ?int $exclude_booking_id, string $exclude_token ): int {
		global $wpdb;

		$bookings      = ETM_Database::bookings_table();
		$roommate_flag = ! empty( $event->roommate_counts_toward_capacity ) ? 1 : 0;
		// Bookings taken before the add-on gained quantities have no stored
		// roommate count, so an enabled add-on still counts as the one place it
		// held at the time. Historical rows are read this way, never rewritten.
		$seat_expr     = $roommate_flag
			? 'ticket_quantity + IF(roommate_enabled = 1, GREATEST(roommate_quantity, 1), roommate_quantity)'
			: 'ticket_quantity';

		// Paid, free and RSVP bookings all hold their places.
		$statuses     = ETM_Bookings::confirmed_statuses();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$params         = array_merge( array( (int) $event->id ), $statuses );
		$exclude_clause = '';

		if ( $exclude_booking_id ) {
			$exclude_clause = ' AND id <> %d';
			$params[]       = $exclude_booking_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$sold = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM({$seat_expr}), 0) FROM {$bookings} WHERE event_id = %d AND payment_status IN ({$placeholders}){$exclude_clause}",
				$params
			)
		);

		if ( ! $include_pending ) {
			return $sold;
		}

		return $sold + ETM_Pending_Orders::seats_held( (int) $event->id, $exclude_token );
	}

	/**
	 * Remaining seats for an event.
	 *
	 * @param object   $event              Event row.
	 * @param int|null $exclude_booking_id Booking to leave out of the sum.
	 * @param string   $exclude_token      Checkout hold to leave out of the sum.
	 * @return int Remaining seats, or self::UNLIMITED when no limit is set.
	 */
	public static function get_remaining( object $event, ?int $exclude_booking_id = null, string $exclude_token = '' ): int {
		if ( null === $event->participant_limit || '' === $event->participant_limit ) {
			return self::UNLIMITED;
		}

		$limit = (int) $event->participant_limit;

		if ( $limit <= 0 ) {
			return self::UNLIMITED;
		}

		$remaining = $limit - self::get_reserved_seats( $event, $exclude_booking_id, $exclude_token );

		return max( 0, $remaining );
	}

	/**
	 * Maximum quantity a customer may select right now.
	 *
	 * @param object $event Event row.
	 * @return int
	 */
	public static function max_selectable_quantity( object $event ): int {
		$remaining = self::get_remaining( $event );

		/**
		 * Filter the hard cap applied to the quantity stepper.
		 *
		 * @param int    $cap   Default cap for unlimited events.
		 * @param object $event Event row.
		 */
		$cap = (int) apply_filters( 'etm_max_quantity_cap', 20, $event );

		if ( self::UNLIMITED === $remaining ) {
			return $cap;
		}

		// The roommate add-on may consume some of the remaining seats, but the
		// stepper cap is based on tickets alone; the server re-checks on submit.
		return max( 0, min( $cap, $remaining ) );
	}

	/**
	 * Whether an event is sold out.
	 *
	 * @param object $event Event row.
	 * @return bool
	 */
	public static function is_sold_out( object $event ): bool {
		$remaining = self::get_remaining( $event );

		return self::UNLIMITED !== $remaining && $remaining < 1;
	}

	/**
	 * Run a callback while the event row is locked FOR UPDATE.
	 *
	 * The callback receives the freshly read event row and must return either a
	 * value (committed) or a WP_Error (rolled back).
	 *
	 * @param int      $event_id Event ID.
	 * @param callable $callback Work to perform under the lock.
	 * @return mixed|WP_Error
	 */
	public static function with_lock( int $event_id, callable $callback ) {
		global $wpdb;

		$events = ETM_Database::events_table();

		/**
		 * Filter how long to wait for the event row lock, in seconds.
		 *
		 * InnoDB's default is 50 seconds. Waiting that long is worse than
		 * useless here: the request is holding a PHP worker the whole time, and
		 * on hosts that cap request duration (WP Engine and most managed
		 * platforms) the worker is killed before the wait ends, which returns a
		 * 502 with no usable error. Worse, a request killed mid-transaction
		 * leaves its own lock held until the database reaps the connection, so
		 * the next attempt waits behind it and is killed in turn.
		 *
		 * Failing fast turns that cascade into an honest, retryable message.
		 *
		 * @param int $seconds Lock wait timeout.
		 */
		$lock_timeout = max( 1, (int) apply_filters( 'etm_lock_wait_timeout', 5 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$previous_timeout = $wpdb->get_var( 'SELECT @@SESSION.innodb_lock_wait_timeout' );

		$wpdb->query( $wpdb->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', $lock_timeout ) );
		$wpdb->query( 'START TRANSACTION' );

		$event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$events} WHERE id = %d FOR UPDATE", $event_id ) );

		$restore_timeout = static function () use ( $wpdb, $previous_timeout ) {
			if ( null !== $previous_timeout ) {
				$wpdb->query( $wpdb->prepare( 'SET SESSION innodb_lock_wait_timeout = %d', (int) $previous_timeout ) );
			}
		};

		if ( ! $event ) {
			$wpdb->query( 'ROLLBACK' );
			$restore_timeout();

			// Either the event really is gone, or the lock wait expired because
			// another checkout still holds the row. The second is transient.
			$busy = ! empty( $wpdb->last_error );

			if ( $busy ) {
				ETM_Stripe::log( 'Lock wait on event ' . $event_id . ': ' . $wpdb->last_error );

				return new WP_Error(
					'etm_event_busy',
					__( 'Another booking for this event is being completed right now. Please try again in a moment.', 'event-ticket-manager' )
				);
			}

			return new WP_Error( 'etm_event_missing', __( 'This event is no longer available.', 'event-ticket-manager' ) );
		}

		try {
			$result = $callback( $event );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			$restore_timeout();

			return new WP_Error( 'etm_lock_exception', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			$wpdb->query( 'ROLLBACK' );
			$restore_timeout();

			return $result;
		}

		$wpdb->query( 'COMMIT' );
		$restore_timeout();
		// phpcs:enable

		return $result;
	}

	/**
	 * Assert that a purchase fits within the remaining capacity.
	 *
	 * @param object   $event              Event row.
	 * @param int      $quantity           Ticket quantity.
	 * @param int      $roommate_quantity  How many roommates are being added.
	 * @param int|null $exclude_booking_id Booking to leave out of the sum.
	 * @param string   $exclude_token      Checkout hold to leave out of the sum.
	 * @return true|WP_Error
	 */
	public static function check_availability( object $event, int $quantity, int $roommate_quantity = 0, ?int $exclude_booking_id = null, string $exclude_token = '' ) {
		$remaining = self::get_remaining( $event, $exclude_booking_id, $exclude_token );

		if ( self::UNLIMITED === $remaining ) {
			return true;
		}

		$needed = self::seats_for_purchase( $event, $quantity, $roommate_quantity );

		if ( $needed > $remaining ) {
			if ( $remaining < 1 ) {
				return new WP_Error( 'etm_sold_out', __( 'This event is sold out.', 'event-ticket-manager' ) );
			}

			return new WP_Error(
				'etm_insufficient_capacity',
				sprintf(
					/* translators: %d: number of remaining places. */
					_n( 'Only %d place is still available.', 'Only %d places are still available.', $remaining, 'event-ticket-manager' ),
					$remaining
				)
			);
		}

		return true;
	}
}
