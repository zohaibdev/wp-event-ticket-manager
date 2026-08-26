<?php
/**
 * Attendee storage for bookings.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * All read/write access to the attendees table lives here.
 */
class ETM_Attendees {

	/**
	 * Insert one attendee row.
	 *
	 * @param int                  $booking_id      Booking ID.
	 * @param int                  $attendee_number 1-based attendee index.
	 * @param array<string, mixed> $data            Attendee data (name, email).
	 * @return int|WP_Error Attendee ID.
	 */
	public static function create( int $booking_id, int $attendee_number, array $data ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			ETM_Database::attendees_table(),
			array(
				'booking_id'            => $booking_id,
				'attendee_number'       => max( 1, $attendee_number ),
				'name'                  => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'email'                 => sanitize_email( (string) ( $data['email'] ?? '' ) ),
				'ticket_type_id'        => absint( $data['ticket_type_id'] ?? 0 ),
				// Snapshot: renaming a ticket type later must not rewrite history.
				'ticket_title_snapshot' => sanitize_text_field( (string) ( $data['ticket_title'] ?? '' ) ),
				'created_at'            => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'etm_attendee_insert_failed', __( 'An attendee could not be saved.', 'event-ticket-manager' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Replace the attendee set for a booking.
	 *
	 * Core name/email go to the attendees table; any extra custom field values
	 * are stored as booking meta scoped to the attendee.
	 *
	 * @param int                              $booking_id Booking ID.
	 * @param array<int, array<string, mixed>> $attendees  Ordered attendee data.
	 * @return int Number of attendees stored.
	 */
	public static function save_for_booking( int $booking_id, array $attendees ): int {
		self::delete_for_booking( $booking_id );

		$stored = 0;

		foreach ( array_values( $attendees ) as $index => $attendee ) {
			$number = $index + 1;
			$result = self::create( $booking_id, $number, $attendee );

			if ( is_wp_error( $result ) ) {
				continue;
			}

			++$stored;

			$custom = isset( $attendee['custom'] ) && is_array( $attendee['custom'] ) ? $attendee['custom'] : array();

			foreach ( $custom as $key => $value ) {
				ETM_Bookings::update_meta(
					$booking_id,
					ETM_Bookings::custom_field_meta_key( 'attendee_' . $number, (string) $key ),
					$value
				);
			}
		}

		return $stored;
	}

	/**
	 * All attendees for a booking, ordered.
	 *
	 * @param int $booking_id Booking ID.
	 * @return object[]
	 */
	public static function get_for_booking( int $booking_id ): array {
		global $wpdb;

		if ( $booking_id <= 0 ) {
			return array();
		}

		$table = ETM_Database::attendees_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY attendee_number ASC", $booking_id )
		);
	}

	/**
	 * Remove every attendee for a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return int Rows deleted.
	 */
	public static function delete_for_booking( int $booking_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->delete( ETM_Database::attendees_table(), array( 'booking_id' => $booking_id ), array( '%d' ) );
	}

	/**
	 * Count attendees for a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return int
	 */
	public static function count_for_booking( int $booking_id ): int {
		global $wpdb;

		$table = ETM_Database::attendees_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE booking_id = %d", $booking_id ) );
	}
}
