<?php
/**
 * Event CRUD and queries.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * All read/write access to the events table lives here.
 */
class ETM_Events {

	public const STATUS_DRAFT     = 'draft';
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_CLOSED    = 'closed';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Event sells tickets and takes payment.
	 */
	public const TYPE_TICKET = 'ticket';

	/**
	 * Event takes registrations and never asks for payment.
	 */
	public const TYPE_RSVP = 'rsvp';

	/**
	 * Valid statuses with labels.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_DRAFT     => __( 'Draft', 'event-ticket-manager' ),
			self::STATUS_PUBLISHED => __( 'Published', 'event-ticket-manager' ),
			self::STATUS_CLOSED    => __( 'Closed', 'event-ticket-manager' ),
			self::STATUS_CANCELLED => __( 'Cancelled', 'event-ticket-manager' ),
		);
	}

	/**
	 * Event types with labels.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			self::TYPE_TICKET => __( 'Ticket Event', 'event-ticket-manager' ),
			self::TYPE_RSVP   => __( 'RSVP Event', 'event-ticket-manager' ),
		);
	}

	/**
	 * The type of an event, stored in event meta.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	public static function get_type( int $event_id ): string {
		$type = (string) self::get_meta( $event_id, 'event_type', self::TYPE_TICKET );

		return self::TYPE_RSVP === $type ? self::TYPE_RSVP : self::TYPE_TICKET;
	}

	/**
	 * Whether an event takes registrations rather than payments.
	 *
	 * @param int $event_id Event ID.
	 * @return bool
	 */
	public static function is_rsvp( int $event_id ): bool {
		return self::TYPE_RSVP === self::get_type( $event_id );
	}

	/**
	 * Fetch a single event row.
	 *
	 * @param int $event_id Event ID.
	 * @return object|null
	 */
	public static function get( int $event_id ): ?object {
		global $wpdb;

		if ( $event_id <= 0 ) {
			return null;
		}

		$table = ETM_Database::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $event_id ) );

		return $row ?: null;
	}

	/**
	 * Query events.
	 *
	 * @param array<string, mixed> $args Query args: status, search, orderby, order, per_page, page.
	 * @return array{items: object[], total: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'search'   => '',
				'orderby'  => 'start_datetime',
				'order'    => 'DESC',
				'per_page' => 20,
				'page'     => 1,
			)
		);

		$table  = ETM_Database::events_table();
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['status'] && isset( self::statuses()[ $args['status'] ] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( '' !== trim( (string) $args['search'] ) ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
		}

		$allowed_orderby = array( 'id', 'title', 'start_datetime', 'end_datetime', 'ticket_price', 'status', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'start_datetime';
		$order           = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );
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
	 * Events available for the shortcode helper / admin dropdowns.
	 *
	 * @return object[]
	 */
	public static function get_all_for_select(): array {
		global $wpdb;

		$table = ETM_Database::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT id, title, status, start_datetime FROM {$table} ORDER BY start_datetime DESC, id DESC" );
	}

	/**
	 * Sanitise a raw event payload into column values.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function sanitize( array $data ): array {
		$errors = array();
		$values = array();

		$values['title'] = sanitize_text_field( (string) ( $data['title'] ?? '' ) );

		if ( '' === $values['title'] ) {
			$errors['title'] = __( 'Event title is required.', 'event-ticket-manager' );
		}

		$values['description'] = wp_kses_post( (string) ( $data['description'] ?? '' ) );

		$start = self::sanitize_datetime( (string) ( $data['start_datetime'] ?? '' ) );
		$end   = self::sanitize_datetime( (string) ( $data['end_datetime'] ?? '' ) );

		if ( null === $start ) {
			$errors['start_datetime'] = __( 'A valid start date and time is required.', 'event-ticket-manager' );
		}

		if ( null === $end ) {
			$errors['end_datetime'] = __( 'A valid end date and time is required.', 'event-ticket-manager' );
		}

		if ( null !== $start && null !== $end && strtotime( $end ) < strtotime( $start ) ) {
			$errors['end_datetime'] = __( 'The end date must be after the start date.', 'event-ticket-manager' );
		}

		$values['start_datetime'] = $start;
		$values['end_datetime']   = $end;

		// Prices live on the event's ticket types now. The column is kept so
		// existing data and the one-off migration still work.
		$price = $data['ticket_price'] ?? '';

		$values['ticket_price'] = ( is_numeric( $price ) && (float) $price >= 0 ) ? round( (float) $price, 2 ) : 0.0;

		$limit = $data['participant_limit'] ?? '';

		if ( '' === trim( (string) $limit ) ) {
			$values['participant_limit'] = null;
		} else {
			$values['participant_limit'] = absint( $limit );
		}

		// The roommate add-on became the Sharing a Room ticket variation, so its
		// columns are no longer written here. They are deliberately left in place
		// and untouched: events and bookings that recorded them keep them.

		$status           = (string) ( $data['status'] ?? self::STATUS_DRAFT );
		$values['status'] = isset( self::statuses()[ $status ] ) ? $status : self::STATUS_DRAFT;

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Normalise a datetime-local value into MySQL format.
	 *
	 * @param string $value Raw value.
	 * @return string|null
	 */
	public static function sanitize_datetime( string $value ): ?string {
		$value = trim( sanitize_text_field( $value ) );

		if ( '' === $value ) {
			return null;
		}

		$value     = str_replace( 'T', ' ', $value );
		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Insert an event.
	 *
	 * @param array<string, mixed> $values Column values (already sanitised).
	 * @return int|WP_Error New event ID.
	 */
	public static function create( array $values ) {
		global $wpdb;

		$now  = current_time( 'mysql' );
		$data = array_merge(
			$values,
			array(
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert( ETM_Database::events_table(), $data, self::column_formats( $data ) );

		if ( ! $inserted ) {
			return new WP_Error( 'etm_event_insert_failed', __( 'The event could not be saved.', 'event-ticket-manager' ) );
		}

		$event_id = (int) $wpdb->insert_id;

		/**
		 * Fires after an event is created.
		 *
		 * @param int   $event_id Event ID.
		 * @param array $values   Stored values.
		 */
		do_action( 'etm_event_created', $event_id, $values );

		return $event_id;
	}

	/**
	 * Update an event.
	 *
	 * @param int                  $event_id Event ID.
	 * @param array<string, mixed> $values   Column values (already sanitised).
	 * @return bool|WP_Error
	 */
	public static function update( int $event_id, array $values ) {
		global $wpdb;

		if ( $event_id <= 0 || ! self::get( $event_id ) ) {
			return new WP_Error( 'etm_event_missing', __( 'Event not found.', 'event-ticket-manager' ) );
		}

		$values['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->update( ETM_Database::events_table(), $values, array( 'id' => $event_id ), self::column_formats( $values ), array( '%d' ) );

		if ( false === $result ) {
			return new WP_Error( 'etm_event_update_failed', __( 'The event could not be updated.', 'event-ticket-manager' ) );
		}

		/**
		 * Fires after an event is updated.
		 *
		 * @param int   $event_id Event ID.
		 * @param array $values   Stored values.
		 */
		do_action( 'etm_event_updated', $event_id, $values );

		return true;
	}

	/**
	 * Change only the status of an event.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $status   New status.
	 * @return bool
	 */
	public static function set_status( int $event_id, string $status ): bool {
		if ( ! isset( self::statuses()[ $status ] ) ) {
			return false;
		}

		return true === self::update( $event_id, array( 'status' => $status ) );
	}

	/**
	 * Duplicate an event (including its meta) as a draft.
	 *
	 * @param int $event_id Source event ID.
	 * @return int|WP_Error
	 */
	public static function duplicate( int $event_id ) {
		global $wpdb;

		$event = self::get( $event_id );

		if ( ! $event ) {
			return new WP_Error( 'etm_event_missing', __( 'Event not found.', 'event-ticket-manager' ) );
		}

		$values = array(
			/* translators: %s: original event title. */
			'title'                           => sprintf( __( '%s (copy)', 'event-ticket-manager' ), $event->title ),
			'description'                     => $event->description,
			'start_datetime'                  => $event->start_datetime,
			'end_datetime'                    => $event->end_datetime,
			'ticket_price'                    => $event->ticket_price,
			'participant_limit'               => null === $event->participant_limit ? null : (int) $event->participant_limit,
			'roommate_enabled'                => (int) $event->roommate_enabled,
			'roommate_title'                  => (string) ( $event->roommate_title ?? '' ),
			'roommate_price'                  => $event->roommate_price,
			'roommate_price_label'            => (string) ( $event->roommate_price_label ?? '' ),
			'roommate_min_quantity'           => (int) ( $event->roommate_min_quantity ?? 0 ),
			'roommate_max_quantity'           => (int) ( $event->roommate_max_quantity ?? 0 ),
			'roommate_counts_toward_capacity' => (int) $event->roommate_counts_toward_capacity,
			'status'                          => self::STATUS_DRAFT,
		);

		$new_id = self::create( $values );

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		ETM_Event_Tickets::duplicate_for_event( $event_id, (int) $new_id );

		$meta_table = ETM_Database::event_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$meta_rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$meta_table} WHERE event_id = %d", $event_id ) );

		foreach ( $meta_rows as $meta ) {
			self::update_meta( $new_id, $meta->meta_key, $meta->meta_value );
		}

		return $new_id;
	}

	/**
	 * Delete an event and its meta. Bookings are preserved for accounting.
	 *
	 * @param int $event_id Event ID.
	 * @return bool
	 */
	public static function delete( int $event_id ): bool {
		global $wpdb;

		if ( $event_id <= 0 ) {
			return false;
		}

		ETM_Event_Tickets::delete_for_event( $event_id );
		ETM_Fees::delete_for_event( $event_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( ETM_Database::event_meta_table(), array( 'event_id' => $event_id ), array( '%d' ) );
		$deleted = $wpdb->delete( ETM_Database::events_table(), array( 'id' => $event_id ), array( '%d' ) );
		// phpcs:enable

		if ( $deleted ) {
			/**
			 * Fires after an event has been deleted.
			 *
			 * @param int $event_id Event ID.
			 */
			do_action( 'etm_event_deleted', $event_id );
		}

		return (bool) $deleted;
	}

	/**
	 * Read an event meta value.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $key      Meta key.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	public static function get_meta( int $event_id, string $key, $default_value = '' ) {
		global $wpdb;

		$table = ETM_Database::event_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE event_id = %d AND meta_key = %s LIMIT 1", $event_id, $key ) );

		return null === $value ? $default_value : maybe_unserialize( $value );
	}

	/**
	 * All meta for an event as an associative array.
	 *
	 * @param int $event_id Event ID.
	 * @return array<string, mixed>
	 */
	public static function get_all_meta( int $event_id ): array {
		global $wpdb;

		$table = ETM_Database::event_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$table} WHERE event_id = %d", $event_id ) );

		$meta = array();

		foreach ( $rows as $row ) {
			$meta[ $row->meta_key ] = maybe_unserialize( $row->meta_value );
		}

		return $meta;
	}

	/**
	 * Create or update an event meta row.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $key      Meta key.
	 * @param mixed  $value    Meta value.
	 */
	public static function update_meta( int $event_id, string $key, $value ): void {
		global $wpdb;

		$table = ETM_Database::event_meta_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_id = %d AND meta_key = %s LIMIT 1", $event_id, $key ) );

		$stored = maybe_serialize( $value );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( $exists ) {
			$wpdb->update( $table, array( 'meta_value' => $stored ), array( 'id' => (int) $exists ), array( '%s' ), array( '%d' ) );
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'event_id'   => $event_id,
				'meta_key'   => $key,
				'meta_value' => $stored,
			),
			array( '%d', '%s', '%s' )
		);
		// phpcs:enable
	}

	/**
	 * Whether an event is open for sales right now.
	 *
	 * @param object|null $event Event row.
	 * @return bool
	 */
	public static function is_purchasable( ?object $event ): bool {
		if ( ! $event ) {
			return false;
		}

		$purchasable = self::STATUS_PUBLISHED === $event->status;

		if ( $purchasable && ! empty( $event->end_datetime ) && '0000-00-00 00:00:00' !== $event->end_datetime ) {
			$purchasable = strtotime( $event->end_datetime ) >= current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		}

		/**
		 * Filter whether an event can be purchased.
		 *
		 * @param bool   $purchasable Result.
		 * @param object $event       Event row.
		 */
		return (bool) apply_filters( 'etm_event_is_purchasable', $purchasable, $event );
	}

	/**
	 * $wpdb format specifiers for the given column set.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return string[]
	 */
	private static function column_formats( array $data ): array {
		$map = array(
			'title'                           => '%s',
			'description'                     => '%s',
			'start_datetime'                  => '%s',
			'end_datetime'                    => '%s',
			'ticket_price'                    => '%f',
			'participant_limit'               => '%d',
			'roommate_enabled'                => '%d',
			'roommate_title'                  => '%s',
			'roommate_price'                  => '%f',
			'roommate_price_label'            => '%s',
			'roommate_min_quantity'           => '%d',
			'roommate_max_quantity'           => '%d',
			'roommate_counts_toward_capacity' => '%d',
			'status'                          => '%s',
			'created_at'                      => '%s',
			'updated_at'                      => '%s',
		);

		$formats = array();

		foreach ( array_keys( $data ) as $column ) {
			$formats[] = $map[ $column ] ?? '%s';
		}

		return $formats;
	}
}
