<?php
/**
 * Ticket types belonging to an event.
 *
 * An event owns any number of named ticket types ("Adult", "Child", "VIP" -
 * nothing is hardcoded), each with its own price and optional per-type limit.
 * Every price used anywhere in the purchase flow is read from these rows, never
 * from the browser.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD and selection validation for event ticket types.
 */
class ETM_Event_Tickets {

	/**
	 * An ordinary ticket: one attendee form per ticket sold.
	 */
	public const TYPE_STANDARD = 'standard';

	/**
	 * A room shared with one other person.
	 *
	 * Priced, limited and selected like any other variation. What differs is who
	 * it asks about: the purchaser is already known from the customer fields, so
	 * the only extra person is the roommate, and there is exactly one of them
	 * however many of these tickets an order contains.
	 */
	public const TYPE_SHARING_ROOM = 'sharing_room';

	/**
	 * Selectable variation types with labels.
	 *
	 * @return array<string, string>
	 */
	public static function variation_types(): array {
		return array(
			self::TYPE_STANDARD      => __( 'Standard Ticket', 'event-ticket-manager' ),
			self::TYPE_SHARING_ROOM  => __( 'Sharing a Room', 'event-ticket-manager' ),
		);
	}

	/**
	 * Normalise a stored or submitted variation type.
	 *
	 * Anything unrecognised is a standard ticket, so a bad value can never turn
	 * a variation into something the checkout treats specially.
	 *
	 * @param mixed $type Raw value.
	 * @return string
	 */
	public static function sanitize_variation_type( $type ): string {
		$type = is_scalar( $type ) ? (string) $type : '';

		return isset( self::variation_types()[ $type ] ) ? $type : self::TYPE_STANDARD;
	}

	/**
	 * Whether a ticket type is a shared room.
	 *
	 * @param object $ticket Ticket type row.
	 * @return bool
	 */
	public static function is_sharing_room( object $ticket ): bool {
		return self::TYPE_SHARING_ROOM === self::sanitize_variation_type( $ticket->variation_type ?? '' );
	}

	/**
	 * How many shared-room tickets a validated selection contains.
	 *
	 * Used only to decide whether to ask for a roommate at all; it never
	 * multiplies anything.
	 *
	 * @param array<int, array<string, mixed>> $lines Validated selection lines.
	 * @return int
	 */
	public static function sharing_room_quantity( array $lines ): int {
		$quantity = 0;

		foreach ( $lines as $line ) {
			if ( self::TYPE_SHARING_ROOM === ( $line['variation_type'] ?? self::TYPE_STANDARD ) ) {
				$quantity += (int) $line['quantity'];
			}
		}

		return $quantity;
	}

	/**
	 * Fetch one ticket type.
	 *
	 * @param int $ticket_id Ticket type ID.
	 * @return object|null
	 */
	public static function get( int $ticket_id ): ?object {
		global $wpdb;

		if ( $ticket_id <= 0 ) {
			return null;
		}

		$table = ETM_Database::event_tickets_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $ticket_id ) );

		return $row ?: null;
	}

	/**
	 * All ticket types for an event, in display order.
	 *
	 * @param int  $event_id     Event ID.
	 * @param bool $only_enabled Skip disabled types.
	 * @return object[]
	 */
	public static function get_for_event( int $event_id, bool $only_enabled = false ): array {
		global $wpdb;

		if ( $event_id <= 0 ) {
			return array();
		}

		$table  = ETM_Database::event_tickets_table();
		$where  = 'event_id = %d';
		$params = array( $event_id );

		if ( $only_enabled ) {
			$where   .= ' AND enabled = %d';
			$params[] = 1;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY sort_order ASC, id ASC", $params )
		);
	}

	/**
	 * Insert a ticket type.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $data     Sanitised values.
	 * @return int|WP_Error
	 */
	public static function create( int $event_id, array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			ETM_Database::event_tickets_table(),
			array(
				'event_id'       => $event_id,
				'title'          => (string) $data['title'],
				'price'          => (float) $data['price'],
				'min_quantity'   => (int) $data['min_quantity'],
				'max_quantity'   => (int) $data['max_quantity'],
				'price_label'    => (string) $data['price_label'],
				'variation_type' => self::sanitize_variation_type( $data['variation_type'] ?? self::TYPE_STANDARD ),
				'sort_order'     => (int) $data['sort_order'],
				'enabled'        => empty( $data['enabled'] ) ? 0 : 1,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%s', '%f', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'etm_ticket_insert_failed', __( 'The ticket type could not be saved.', 'event-ticket-manager' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a ticket type.
	 *
	 * @param int                  $ticket_id Ticket type ID.
	 * @param array<string, mixed> $data      Sanitised values.
	 * @return bool
	 */
	public static function update( int $ticket_id, array $data ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update(
			ETM_Database::event_tickets_table(),
			array(
				'title'          => (string) $data['title'],
				'price'          => (float) $data['price'],
				'min_quantity'   => (int) $data['min_quantity'],
				'max_quantity'   => (int) $data['max_quantity'],
				'price_label'    => (string) $data['price_label'],
				'variation_type' => self::sanitize_variation_type( $data['variation_type'] ?? self::TYPE_STANDARD ),
				'sort_order'     => (int) $data['sort_order'],
				'enabled'        => empty( $data['enabled'] ) ? 0 : 1,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $ticket_id ),
			array( '%s', '%f', '%d', '%d', '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Delete a ticket type.
	 *
	 * Bookings keep their own snapshot of what was purchased, so removing a type
	 * never rewrites history.
	 *
	 * @param int $ticket_id Ticket type ID.
	 * @return bool
	 */
	public static function delete( int $ticket_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( ETM_Database::event_tickets_table(), array( 'id' => $ticket_id ), array( '%d' ) );
	}

	/**
	 * Replace an event's ticket types from a submitted repeater.
	 *
	 * Rows the admin removed are deleted, existing rows are updated in place so
	 * their IDs (and therefore historical links) survive, and new rows are added.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $rows     Raw repeater rows.
	 * @return array{errors: array<int, string>, count: int}
	 */
	public static function save_for_event( int $event_id, array $rows ): array {
		$existing = array();

		foreach ( self::get_for_event( $event_id ) as $ticket ) {
			$existing[ (int) $ticket->id ] = $ticket;
		}

		$errors = array();
		$kept   = array();
		$order  = 0;

		foreach ( array_values( $rows ) as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$title = sanitize_text_field( (string) ( $raw['title'] ?? '' ) );

			// A row with no title at all is treated as an empty repeater slot.
			if ( '' === trim( $title ) && '' === trim( (string) ( $raw['price'] ?? '' ) ) ) {
				continue;
			}

			if ( '' === trim( $title ) ) {
				$errors[ $index ] = __( 'Every ticket type needs a name.', 'event-ticket-manager' );
				continue;
			}

			$price_raw = $raw['price'] ?? '';

			if ( '' === trim( (string) $price_raw ) || ! is_numeric( $price_raw ) || (float) $price_raw < 0 ) {
				$errors[ $index ] = __( 'Ticket price must be zero or greater.', 'event-ticket-manager' );
				continue;
			}

			// 0 as a maximum means "no maximum of its own"; both bounds are the
			// admin's to set, independently, for every variation.
			$min_quantity = absint( $raw['min_quantity'] ?? 0 );
			$max_quantity = absint( $raw['max_quantity'] ?? 0 );

			if ( $max_quantity > 0 && $min_quantity > $max_quantity ) {
				$errors[ $index ] = __( 'Minimum quantity cannot be higher than maximum quantity.', 'event-ticket-manager' );
				continue;
			}

			$price_label = sanitize_text_field( (string) ( $raw['price_label'] ?? '' ) );
			$price_label = '' === trim( $price_label ) ? self::default_price_label() : $price_label;

			$values = array(
				'title'          => $title,
				'price'          => round( (float) $price_raw, 2 ),
				'min_quantity'   => $min_quantity,
				'max_quantity'   => $max_quantity,
				'price_label'    => $price_label,
				'variation_type' => self::sanitize_variation_type( $raw['variation_type'] ?? self::TYPE_STANDARD ),
				'sort_order'     => $order,
				'enabled'        => empty( $raw['enabled'] ) ? 0 : 1,
			);

			$ticket_id = isset( $raw['id'] ) ? absint( $raw['id'] ) : 0;

			if ( $ticket_id > 0 && isset( $existing[ $ticket_id ] ) ) {
				self::update( $ticket_id, $values );
				$kept[] = $ticket_id;
			} else {
				$created = self::create( $event_id, $values );

				if ( ! is_wp_error( $created ) ) {
					$kept[] = (int) $created;
				}
			}

			++$order;
		}

		foreach ( array_keys( $existing ) as $ticket_id ) {
			if ( ! in_array( $ticket_id, $kept, true ) ) {
				self::delete( $ticket_id );
			}
		}

		return array(
			'errors' => $errors,
			'count'  => count( $kept ),
		);
	}

	/**
	 * Copy an event's ticket types onto another event.
	 *
	 * @param int $from_event_id Source event.
	 * @param int $to_event_id   Target event.
	 */
	public static function duplicate_for_event( int $from_event_id, int $to_event_id ): void {
		foreach ( self::get_for_event( $from_event_id ) as $ticket ) {
			self::create(
				$to_event_id,
				array(
					'title'          => $ticket->title,
					'price'          => $ticket->price,
					'min_quantity'   => (int) $ticket->min_quantity,
					'max_quantity'   => (int) $ticket->max_quantity,
					'price_label'    => (string) $ticket->price_label,
					'variation_type' => self::sanitize_variation_type( $ticket->variation_type ?? self::TYPE_STANDARD ),
					'sort_order'     => (int) $ticket->sort_order,
					'enabled'        => (int) $ticket->enabled,
				)
			);
		}
	}

	/**
	 * Remove every ticket type for an event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function delete_for_event( int $event_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( ETM_Database::event_tickets_table(), array( 'event_id' => $event_id ), array( '%d' ) );
	}

	/**
	 * Tickets of one type already sold (paid bookings only).
	 *
	 * @param int $ticket_id Ticket type ID.
	 * @return int
	 */
	public static function sold_for_type( int $ticket_id ): int {
		global $wpdb;

		$booking_tickets = ETM_Database::booking_tickets_table();
		$bookings        = ETM_Database::bookings_table();
		$statuses        = ETM_Bookings::confirmed_statuses();
		$placeholders    = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(bt.quantity), 0)
				 FROM {$booking_tickets} bt
				 INNER JOIN {$bookings} b ON b.id = bt.booking_id
				 WHERE bt.ticket_type_id = %d AND b.payment_status IN ({$placeholders})",
				array_merge( array( $ticket_id ), $statuses )
			)
		);
	}

	/**
	 * Fallback label shown next to a ticket price.
	 *
	 * @return string
	 */
	public static function default_price_label(): string {
		return __( 'each', 'event-ticket-manager' );
	}

	/**
	 * The label the admin chose to show beside this ticket's price.
	 *
	 * Display only: it never takes part in any calculation.
	 *
	 * @param object $ticket Ticket type row.
	 * @return string
	 */
	public static function price_label( object $ticket ): string {
		$label = isset( $ticket->price_label ) ? trim( (string) $ticket->price_label ) : '';

		return '' !== $label ? $label : self::default_price_label();
	}

	/**
	 * Smallest quantity of this type an order must include.
	 *
	 * @param object $ticket Ticket type row.
	 * @return int
	 */
	public static function min_for_type( object $ticket ): int {
		return max( 0, (int) ( $ticket->min_quantity ?? 0 ) );
	}

	/**
	 * Largest quantity of this type a single order may include.
	 *
	 * A stored 0 means the admin set no maximum, in which case the order-wide
	 * safety cap applies.
	 *
	 * @param object $ticket Ticket type row.
	 * @return int
	 */
	public static function max_for_type( object $ticket ): int {
		$max = (int) ( $ticket->max_quantity ?? 0 );

		if ( $max > 0 ) {
			return $max;
		}

		/**
		 * Filter the cap used when a ticket type has no maximum of its own.
		 *
		 * @param int    $cap    Default cap.
		 * @param object $ticket Ticket type row.
		 */
		return max( 1, (int) apply_filters( 'etm_max_quantity_cap', 20, $ticket ) );
	}

	/**
	 * The most of this type a fresh visitor could add, for the stepper cap.
	 *
	 * @param object $ticket Ticket type row.
	 * @param int    $event_cap Remaining event capacity, or ETM_Capacity::UNLIMITED.
	 * @return int
	 */
	public static function max_selectable( object $ticket, int $event_cap ): int {
		$cap = self::max_for_type( $ticket );

		if ( ETM_Capacity::UNLIMITED !== $event_cap ) {
			$cap = min( $cap, $event_cap );
		}

		return max( 0, $cap );
	}

	/**
	 * Validate a submitted ticket selection against the database.
	 *
	 * Every price and title in the result comes from the ticket rows; the only
	 * thing taken from the request is which IDs and how many of each.
	 *
	 * @param object               $event         Event row.
	 * @param array<mixed, mixed>  $raw           Raw selection: ticket ID => quantity.
	 * @param string               $exclude_token Checkout hold to ignore when amending.
	 * @return array{lines: array<int, array<string, mixed>>, quantity: int, subtotal: float, errors: array<string, string>}
	 */
	public static function validate_selection( object $event, array $raw, string $exclude_token = '' ): array {
		$available = array();

		foreach ( self::get_for_event( (int) $event->id, true ) as $ticket ) {
			$available[ (int) $ticket->id ] = $ticket;
		}

		$lines    = array();
		$errors   = array();
		$quantity = 0;
		$subtotal = 0.0;

		foreach ( $raw as $ticket_id => $requested ) {
			$ticket_id = absint( $ticket_id );
			$requested = is_scalar( $requested ) ? (int) $requested : 0;

			if ( $requested <= 0 ) {
				continue;
			}

			// Unknown ID, another event's ticket, or a disabled type.
			if ( ! isset( $available[ $ticket_id ] ) ) {
				$errors[ 'tickets[' . $ticket_id . ']' ] = __( 'That ticket type is not available for this event.', 'event-ticket-manager' );
				continue;
			}

			$ticket = $available[ $ticket_id ];
			$max    = self::max_for_type( $ticket );

			if ( $requested > $max ) {
				$errors[ 'tickets[' . $ticket_id . ']' ] = sprintf(
					/* translators: 1: maximum quantity, 2: ticket type name. */
					__( 'You can select at most %1$d %2$s.', 'event-ticket-manager' ),
					$max,
					$ticket->title
				);

				continue;
			}

			$unit_price = round( (float) $ticket->price, 2 );
			$line_total = round( $unit_price * $requested, 2 );

			$lines[] = array(
				'ticket_type_id' => $ticket_id,
				'title'          => (string) $ticket->title,
				'unit_price'     => $unit_price,
				'quantity'       => $requested,
				'subtotal'       => $line_total,
				'sort_order'     => (int) $ticket->sort_order,
				// Read from the ticket row, never from the request.
				'variation_type' => self::sanitize_variation_type( $ticket->variation_type ?? self::TYPE_STANDARD ),
			);

			$quantity += $requested;
			$subtotal  = round( $subtotal + $line_total, 2 );
		}

		// Every type carries its own minimum, set by the admin. No position in the
		// list is special. The browser enforces the same bounds but is not trusted.
		foreach ( $available as $ticket ) {
			$min = self::min_for_type( $ticket );

			if ( $min < 1 ) {
				continue;
			}

			$selected = 0;

			foreach ( $lines as $line ) {
				if ( (int) $line['ticket_type_id'] === (int) $ticket->id ) {
					$selected = (int) $line['quantity'];
				}
			}

			if ( $selected < $min && ! isset( $errors[ 'tickets[' . (int) $ticket->id . ']' ] ) ) {
				$errors[ 'tickets[' . (int) $ticket->id . ']' ] = sprintf(
					/* translators: 1: minimum quantity, 2: ticket type name. */
					__( 'Please select at least %1$d %2$s.', 'event-ticket-manager' ),
					$min,
					$ticket->title
				);
			}
		}

		// Keep the admin's display order so attendee numbering is predictable.
		usort(
			$lines,
			static function ( $a, $b ) {
				return array( $a['sort_order'], $a['ticket_type_id'] ) <=> array( $b['sort_order'], $b['ticket_type_id'] );
			}
		);

		return array(
			'lines'    => $lines,
			'quantity' => $quantity,
			'subtotal' => $subtotal,
			'errors'   => $errors,
		);
	}

	/**
	 * Flatten selected lines into ordered attendee slots.
	 *
	 * Slot 0 is the purchaser, so a single ticket never asks for the same person
	 * twice. Shared rooms take no slots at all: their occupants are the
	 * purchaser and the one roommate, asked for once regardless of how many of
	 * those tickets the order contains.
	 *
	 * @param array<int, array<string, mixed>> $lines Validated selection lines.
	 * @return array<int, array{ticket_type_id: int, title: string, index: int}>
	 */
	public static function attendee_slots( array $lines ): array {
		$slots = array();

		foreach ( $lines as $line ) {
			if ( self::TYPE_SHARING_ROOM === ( $line['variation_type'] ?? self::TYPE_STANDARD ) ) {
				continue;
			}

			for ( $i = 0; $i < (int) $line['quantity']; $i++ ) {
				$slots[] = array(
					'ticket_type_id' => (int) $line['ticket_type_id'],
					'title'          => (string) $line['title'],
					'index'          => $i + 1,
				);
			}
		}

		return $slots;
	}

	/**
	 * Whether an event has at least one sellable ticket type.
	 *
	 * @param int $event_id Event ID.
	 * @return bool
	 */
	public static function event_has_tickets( int $event_id ): bool {
		return ! empty( self::get_for_event( $event_id, true ) );
	}
}
