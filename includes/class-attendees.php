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
	 * Coming-status values. Empty string means no answer has been recorded.
	 */
	public const COMING_YES = 'yes';
	public const COMING_NO  = 'no';

	/**
	 * Characters used for security codes. Every glyph that is easily misread
	 * for another is left out - 0/O, and 1/I/L - so a code stays reliable to
	 * key in by hand when a scanner is not to hand or a QR will not read.
	 */
	private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

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

		$attendee_id = (int) $wpdb->insert_id;

		// Every attendee gets a code the moment the row exists - there is no
		// state in which a real attendee is missing one.
		self::assign_security_code( $attendee_id );

		return $attendee_id;
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
		// A code, once issued, has already been emailed and may already be
		// printed on someone's phone, and a check-in already happened. Rewriting
		// the attendee set must not silently void either, so the ticket state
		// for each seat is carried across the rewrite by attendee number.
		$preserved = array();

		foreach ( self::get_for_booking( $booking_id ) as $existing ) {
			$preserved[ (int) $existing->attendee_number ] = $existing;
		}

		self::delete_for_booking( $booking_id );

		$stored = 0;

		foreach ( array_values( $attendees ) as $index => $attendee ) {
			$number = $index + 1;
			$result = self::create( $booking_id, $number, $attendee );

			if ( is_wp_error( $result ) ) {
				continue;
			}

			if ( isset( $preserved[ $number ] ) ) {
				self::restore_ticket_state( (int) $result, $preserved[ $number ] );
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

	/**
	 * Carry a seat's issued security code and check-in state onto the row
	 * that replaced it, so rewriting a booking's attendee set never voids a
	 * ticket that is already in someone's hands.
	 *
	 * @param int    $attendee_id New attendee row ID.
	 * @param object $previous    The row it replaced.
	 */
	private static function restore_ticket_state( int $attendee_id, object $previous ): void {
		global $wpdb;

		$code = (string) ( $previous->security_code ?? '' );

		if ( '' === $code ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			ETM_Database::attendees_table(),
			array(
				'security_code'   => $code,
				'check_in_status' => (int) ( $previous->check_in_status ?? 0 ),
				'check_in_at'     => $previous->check_in_at ?? null,
				'check_in_by'     => $previous->check_in_by ?? null,
				'coming_status'   => (string) ( $previous->coming_status ?? '' ),
				'coming_at'       => $previous->coming_at ?? null,
			),
			array( 'id' => $attendee_id ),
			array( '%s', '%d', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Guarantee a booking has at least the purchaser as an attendee row.
	 *
	 * A Sharing a Room ticket takes no attendee slot of its own (the shared
	 * room is the ticket, and ETM_Event_Tickets::attendee_slots() skips it),
	 * so an order for nothing but a shared room reaches this point with no
	 * attendee rows at all. The purchaser is a real attendee regardless, and
	 * needs their own security code, QR and check-in status like anyone else.
	 *
	 * Idempotent: a booking that already has any attendee row is left alone.
	 *
	 * @param int         $booking_id Booking ID.
	 * @param object|null $booking    Booking row, when already loaded.
	 * @return int Attendee ID created, or 0 when nothing was needed.
	 */
	public static function ensure_primary_for_booking( int $booking_id, ?object $booking = null ): int {
		if ( self::count_for_booking( $booking_id ) > 0 ) {
			return 0;
		}

		$booking = $booking ?: ETM_Bookings::get( $booking_id );

		if ( ! $booking ) {
			return 0;
		}

		// The ticket the purchaser actually holds, for the type snapshot.
		$lines = ETM_Bookings::get_ticket_lines( $booking_id );
		$first = $lines[0] ?? array();

		$created = self::create(
			$booking_id,
			1,
			array(
				'name'           => (string) $booking->customer_name,
				'email'          => (string) $booking->customer_email,
				'ticket_type_id' => (int) ( $first['ticket_type_id'] ?? 0 ),
				'ticket_title'   => (string) ( $first['title'] ?? '' ),
			)
		);

		return is_wp_error( $created ) ? 0 : (int) $created;
	}

	/**
	 * Attendee-level query for the admin list screen.
	 *
	 * Joins each attendee to the booking that owns them and the event that
	 * booking is for, so one row is a whole attendee: their own name, code
	 * and check-in state alongside the booking number, payment status and
	 * total they share with everyone else on that booking.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: object[], total: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'          => '',
				'event_id'        => 0,
				'payment_status'  => '',
				'check_in_status' => '',
				'coming_status'   => '',
				'date_from'       => '',
				'date_to'         => '',
				'orderby'         => 'booking_date',
				'order'           => 'DESC',
				'per_page'        => 20,
				'page'            => 1,
			)
		);

		$attendees = ETM_Database::attendees_table();
		$bookings  = ETM_Database::bookings_table();
		$events    = ETM_Database::events_table();

		$where  = array( '1=1' );
		$params = array();

		if ( (int) $args['event_id'] > 0 ) {
			$where[]  = 'b.event_id = %d';
			$params[] = (int) $args['event_id'];
		}

		if ( '' !== $args['payment_status'] && isset( ETM_Bookings::statuses()[ $args['payment_status'] ] ) ) {
			$where[]  = 'b.payment_status = %s';
			$params[] = $args['payment_status'];
		}

		if ( '' !== (string) $args['check_in_status'] ) {
			$where[]  = 'a.check_in_status = %d';
			$params[] = (int) $args['check_in_status'] ? 1 : 0;
		}

		if ( in_array( $args['coming_status'], array( self::COMING_YES, self::COMING_NO ), true ) ) {
			$where[]  = 'a.coming_status = %s';
			$params[] = (string) $args['coming_status'];
		}

		$search = trim( (string) $args['search'] );

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( a.name LIKE %s OR a.email LIKE %s OR a.security_code LIKE %s OR b.booking_number LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== trim( (string) $args['date_from'] ) ) {
			$where[]  = 'b.created_at >= %s';
			$params[] = gmdate( 'Y-m-d 00:00:00', strtotime( (string) $args['date_from'] ) );
		}

		if ( '' !== trim( (string) $args['date_to'] ) ) {
			$where[]  = 'b.created_at <= %s';
			$params[] = gmdate( 'Y-m-d 23:59:59', strtotime( (string) $args['date_to'] ) );
		}

		// Only ever a fixed column name from this map reaches the SQL.
		$orderby_map = array(
			'attendee'        => 'a.name',
			'event'           => 'e.title',
			'booking_number'  => 'b.booking_number',
			'payment_status'  => 'b.payment_status',
			'check_in_status' => 'a.check_in_status',
			'booking_date'    => 'b.created_at',
		);

		$orderby = $orderby_map[ (string) $args['orderby'] ] ?? 'b.created_at';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = max( 0, ( (int) $args['page'] - 1 ) * $per_page );
		$where_sql = implode( ' AND ', $where );

		$from = "FROM {$attendees} a
			INNER JOIN {$bookings} b ON a.booking_id = b.id
			LEFT JOIN {$events} e ON b.event_id = e.id
			WHERE {$where_sql}";

		$count_sql = "SELECT COUNT(*) {$from}";
		$list_sql  = "SELECT a.*, b.booking_number, b.payment_status, b.total, b.currency,
			b.created_at AS booking_date, b.event_id, b.stripe_payment_intent_id,
			e.title AS event_title
			{$from}
			ORDER BY {$orderby} {$order}, a.id DESC
			LIMIT %d OFFSET %d";

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
	 * Bookings that have no attendee row at all.
	 *
	 * Only ever a Sharing a Room order taken before this release, or one from
	 * before attendees were stored separately - the migration and
	 * ensure_primary_for_booking() close the gap for everything else. The
	 * export uses this so such a booking, and the roommate recorded on it,
	 * can never silently drop out of a CSV.
	 *
	 * @return int[]
	 */
	public static function booking_ids_without_attendees(): array {
		global $wpdb;

		$bookings  = ETM_Database::bookings_table();
		$attendees = ETM_Database::attendees_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			"SELECT b.id FROM {$bookings} b
			LEFT JOIN {$attendees} a ON a.booking_id = b.id
			WHERE a.id IS NULL"
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Fetch one attendee by ID.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return object|null
	 */
	public static function get( int $attendee_id ): ?object {
		global $wpdb;

		if ( $attendee_id <= 0 ) {
			return null;
		}

		$table = ETM_Database::attendees_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $attendee_id ) );

		return $row ?: null;
	}

	/**
	 * Fetch one attendee by their security code. Used by the public
	 * verification page, so a nonexistent or malformed code is simply "not
	 * found" rather than a query error an attacker could learn from.
	 *
	 * @param string $code Security code, in any case.
	 * @return object|null
	 */
	public static function get_by_security_code( string $code ): ?object {
		global $wpdb;

		$code = self::sanitize_code( $code );

		if ( '' === $code ) {
			return null;
		}

		$table = ETM_Database::attendees_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE security_code = %s", $code ) );

		return $row ?: null;
	}

	/**
	 * Restrict a submitted code to the shape a real one can have.
	 *
	 * @param string $code Raw input.
	 * @return string
	 */
	public static function sanitize_code( string $code ): string {
		$code = strtoupper( sanitize_text_field( $code ) );
		$code = preg_replace( '/[^A-Z0-9-]/', '', $code ) ?? '';

		return substr( $code, 0, 20 );
	}

	/**
	 * A fresh code, guaranteed unique against every attendee ever stored.
	 *
	 * Format: EVT-XXXX-XXXX-XX. Nothing about it is derived from the
	 * attendee's database ID, so it never exposes internal row numbers.
	 *
	 * @return string
	 */
	public static function generate_security_code(): string {
		do {
			$code = 'EVT-' . self::random_chars( 4 ) . '-' . self::random_chars( 4 ) . '-' . self::random_chars( 2 );
		} while ( self::code_exists( $code ) );

		return $code;
	}

	/**
	 * A run of cryptographically random characters from the code alphabet.
	 *
	 * @param int $length Character count.
	 * @return string
	 */
	private static function random_chars( int $length ): string {
		$chars    = '';
		$alphabet = self::CODE_ALPHABET;
		$max      = strlen( $alphabet ) - 1;

		for ( $i = 0; $i < $length; $i++ ) {
			$chars .= $alphabet[ random_int( 0, $max ) ];
		}

		return $chars;
	}

	/**
	 * Whether a code is already in use.
	 *
	 * @param string $code Candidate code.
	 * @return bool
	 */
	private static function code_exists( string $code ): bool {
		global $wpdb;

		$table = ETM_Database::attendees_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE security_code = %s", $code ) );
	}

	/**
	 * Give an attendee a code if they do not already have one.
	 *
	 * A code, once assigned, is never replaced by this method - only
	 * regenerate_security_code() ever changes an existing one, and only on an
	 * admin's explicit request.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return string The attendee's code (existing or newly assigned), or an
	 *                empty string if the attendee does not exist.
	 */
	public static function assign_security_code( int $attendee_id ): string {
		global $wpdb;

		$table = ETM_Database::attendees_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT security_code FROM {$table} WHERE id = %d", $attendee_id ) );

		if ( null === $existing ) {
			// No such attendee.
			return '';
		}

		if ( ! empty( $existing ) ) {
			return (string) $existing;
		}

		$code = self::generate_security_code();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, array( 'security_code' => $code ), array( 'id' => $attendee_id ), array( '%s' ), array( '%d' ) );

		return $code;
	}

	/**
	 * Replace an attendee's code with a new one, on an admin's explicit
	 * request. The old code stops working immediately.
	 *
	 * @param int $attendee_id Attendee ID.
	 * @return string The new code, or an empty string if the attendee does
	 *                not exist.
	 */
	public static function regenerate_security_code( int $attendee_id ): string {
		global $wpdb;

		if ( ! self::get( $attendee_id ) ) {
			return '';
		}

		$table = ETM_Database::attendees_table();
		$code  = self::generate_security_code();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, array( 'security_code' => $code ), array( 'id' => $attendee_id ), array( '%s' ), array( '%d' ) );

		return $code;
	}

	/**
	 * The public, theme-independent verification URL for a security code.
	 *
	 * @param string $code Security code.
	 * @return string
	 */
	public static function verification_url( string $code ): string {
		return add_query_arg( 'etm_ticket', rawurlencode( $code ), home_url( '/' ) );
	}

	/**
	 * The verification URL's QR code as an SVG.
	 *
	 * The QR payload is the verification URL alone - just enough to route a
	 * scanner to the server-side check, never a name, email or payment
	 * detail.
	 *
	 * @param string $code Security code.
	 * @return string Empty string if the code will not fit a QR symbol.
	 */
	public static function qr_svg( string $code ): string {
		$matrix = ETM_QR_Encoder::encode( self::verification_url( $code ) );

		return $matrix ? ETM_QR_Encoder::to_svg( $matrix ) : '';
	}

	/**
	 * The verification URL's QR code as a PNG data URI.
	 *
	 * @param string $code Security code.
	 * @return string Empty string if the code will not fit a QR symbol.
	 */
	public static function qr_png_data_uri( string $code ): string {
		$matrix = ETM_QR_Encoder::encode( self::verification_url( $code ) );

		if ( ! $matrix ) {
			return '';
		}

		return (string) ( ETM_QR_Encoder::to_png_data_uri( $matrix ) ?? '' );
	}

	/**
	 * Set or clear check-in status.
	 *
	 * Idempotent: checking in an already-checked-in attendee just refreshes
	 * the timestamp and operator, it never creates a second record of it.
	 *
	 * @param int      $attendee_id Attendee ID.
	 * @param bool     $checked_in  True to check in, false to undo.
	 * @param int|null $user_id     WP user performing the action, if any.
	 * @return bool
	 */
	public static function set_check_in( int $attendee_id, bool $checked_in, ?int $user_id = null ): bool {
		global $wpdb;

		$table = ETM_Database::attendees_table();

		$data   = array( 'check_in_status' => $checked_in ? 1 : 0 );
		$format = array( '%d' );

		if ( $checked_in ) {
			$data['check_in_at'] = current_time( 'mysql' );
			$data['check_in_by'] = $user_id ?: null;
		} else {
			$data['check_in_at'] = null;
			$data['check_in_by'] = null;
		}

		$format[] = '%s';
		$format[] = '%d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( $table, $data, array( 'id' => $attendee_id ), $format, array( '%d' ) );
	}

	/**
	 * Record whether an attendee says they are coming.
	 *
	 * Idempotent: answering again just updates the timestamp, it never adds
	 * a second row or record for the same attendee.
	 *
	 * @param int    $attendee_id Attendee ID.
	 * @param string $status      self::COMING_YES or self::COMING_NO.
	 * @return bool
	 */
	public static function set_coming_status( int $attendee_id, string $status ): bool {
		global $wpdb;

		if ( ! in_array( $status, array( self::COMING_YES, self::COMING_NO ), true ) ) {
			return false;
		}

		$table = ETM_Database::attendees_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update(
			$table,
			array(
				'coming_status' => $status,
				'coming_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $attendee_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Human label for a check-in status.
	 *
	 * @param bool $checked_in Check-in flag.
	 * @return string
	 */
	public static function check_in_label( bool $checked_in ): string {
		return $checked_in
			? __( 'Checked In', 'event-ticket-manager' )
			: __( 'Not Checked In', 'event-ticket-manager' );
	}

	/**
	 * Human label for a coming-status value.
	 *
	 * @param string $status Stored value.
	 * @return string
	 */
	public static function coming_status_label( string $status ): string {
		$labels = array(
			self::COMING_YES => __( 'Coming', 'event-ticket-manager' ),
			self::COMING_NO  => __( 'Not Coming', 'event-ticket-manager' ),
		);

		return $labels[ $status ] ?? __( 'Not Responded', 'event-ticket-manager' );
	}
}
